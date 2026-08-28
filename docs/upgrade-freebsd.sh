#!/bin/sh

set -eu

# Namingo Registry universal upgrader for FreeBSD.
# Supported installed Registry versions: 1.0.32 and later.
# Supported OS: FreeBSD 15.1-RELEASE (including patch levels).
#
# v1.0.32 predates the VERSION marker and is handled as a one-time
# bootstrap case. Starting with v1.0.33, upgrades are driven by VERSION
# plus optional docs/migrations/X.Y.Z.sh scripts, matching the Linux
# universal upgrader as closely as FreeBSD permits.

PATH=/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/sbin:/usr/local/bin
export PATH
LC_ALL=C
export LC_ALL
umask 027

REPO_SLUG="getnamingo/registry"
REPO_URL="https://github.com/${REPO_SLUG}.git"
LATEST_VERSION_URL="https://raw.githubusercontent.com/${REPO_SLUG}/main/VERSION"
ADMINER_URL="https://www.adminer.org/latest.php"

INSTALL_DIR="/opt/registry"
CP_DIR="/var/www/cp"
WHOIS_WEB_DIR="/var/www/whois"
VERSION_FILE="${INSTALL_DIR}/VERSION"
BACKUP_DIR="/opt/backup"
MIN_SUPPORTED_VERSION="1.0.32"
TARGET_FREEBSD_VERSION="15.1-RELEASE"

TARGET_OVERRIDE=""
ASSUME_YES=0

STAGING_DIR=""
MIGRATION_LIST_FILE=""
SUCCESS=0
SERVICES_STOPPED=0
SERVICES_TO_RESTART=""
ADMINER_ROUTE_NAME=""
BACKUP_STAMP=""
PHP_FPM_WAS_RUNNING=0

log() {
    printf '\n\033[1;32m[%s]\033[0m %s\n' "$(date +%H:%M:%S)" "$*"
}

warn() {
    printf '\n\033[1;33m[WARN]\033[0m %s\n' "$*" >&2
}

err() {
    printf '\n\033[1;31m[ERR]\033[0m %s\n' "$*" >&2
}

die() {
    err "$*"
    exit 1
}

usage() {
    cat <<EOF_USAGE
Usage: $0 [options]

Options:
  --target X.Y.Z   Upgrade to a specific released tag instead of the version
                   published in the repository VERSION file.
  -y, --yes        Do not ask for the normal upgrade confirmation.
  -h, --help       Show this help.

Examples:
  $0
  $0 --target 1.0.34
  $0 -y
EOF_USAGE
}

while [ "$#" -gt 0 ]; do
    case "$1" in
        --target)
            [ "$#" -ge 2 ] || die "--target requires a version."
            TARGET_OVERRIDE=$2
            shift 2
            ;;
        -y|--yes)
            ASSUME_YES=1
            shift
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            die "Unknown option: $1"
            ;;
    esac
done

require_command() {
    command -v "$1" >/dev/null 2>&1 || die "Required command not found: $1"
}

validate_version() {
    printf '%s\n' "$1" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$'
}

version_lt() {
    awk -v a="$1" -v b="$2" 'BEGIN {
        split(a, A, "."); split(b, B, ".");
        for (i = 1; i <= 3; i++) {
            if ((A[i] + 0) < (B[i] + 0)) exit 0;
            if ((A[i] + 0) > (B[i] + 0)) exit 1;
        }
        exit 1;
    }'
}

version_le() {
    awk -v a="$1" -v b="$2" 'BEGIN {
        split(a, A, "."); split(b, B, ".");
        for (i = 1; i <= 3; i++) {
            if ((A[i] + 0) < (B[i] + 0)) exit 0;
            if ((A[i] + 0) > (B[i] + 0)) exit 1;
        }
        exit 0;
    }'
}

version_gt() {
    awk -v a="$1" -v b="$2" 'BEGIN {
        split(a, A, "."); split(b, B, ".");
        for (i = 1; i <= 3; i++) {
            if ((A[i] + 0) > (B[i] + 0)) exit 0;
            if ((A[i] + 0) < (B[i] + 0)) exit 1;
        }
        exit 1;
    }'
}

prompt_yes_no() {
    local prompt_text response
    prompt_text=$1

    [ -c /dev/tty ] || die "Interactive confirmation requires /dev/tty. Re-run from a terminal."
    printf '%s' "$prompt_text" > /dev/tty
    IFS= read -r response < /dev/tty || die "Unable to read confirmation."

    case "$response" in
        y|Y|yes|YES|Yes) return 0 ;;
        *) return 1 ;;
    esac
}

php_config_value() {
    local file key
    file=$1
    key=$2

    /usr/local/bin/php -r '
        $file = $argv[1];
        $key  = $argv[2];
        $cfg  = require $file;

        if (!is_array($cfg) || !array_key_exists($key, $cfg)) {
            exit(2);
        }

        $value = $cfg[$key];

        if (is_bool($value)) {
            echo $value ? "1" : "0";
        } elseif (is_scalar($value)) {
            echo $value;
        } else {
            exit(3);
        }
    ' -- "$file" "$key"
}

service_is_running() {
    service "$1" onestatus >/dev/null 2>&1
}

was_running() {
    case " $SERVICES_TO_RESTART " in
        *" $1 "*) return 0 ;;
        *) return 1 ;;
    esac
}

start_saved_service() {
    local service_name
    service_name=$1
    was_running "$service_name" || return 0
    service "$service_name" start >/dev/null 2>&1 || return 1
}

restart_previous_services() {
    [ "$SERVICES_STOPPED" -eq 1 ] || return 0

    warn "Attempting to restart services that were running before the upgrade."

    # Caddy first because the EPP certificate watcher depends on it.
    start_saved_service caddy || true
    start_saved_service rdap || true
    start_saved_service whois || true
    start_saved_service das || true
    start_saved_service epp || true
    start_saved_service msg_producer || true
    start_saved_service msg_worker || true
    start_saved_service namingo_certwatch || true
    start_saved_service cron || true

    SERVICES_STOPPED=0
}

cleanup() {
    rc=$?
    set +e
    trap - 0

    if [ "$SUCCESS" -eq 1 ]; then
        if [ -n "$STAGING_DIR" ] && [ -d "$STAGING_DIR" ]; then
            rm -rf "$STAGING_DIR"
        fi
    else
        restart_previous_services

        if [ -f "${INSTALL_DIR}/docs/upgrade-freebsd.sh.previous" ] \
           && [ ! -f "${INSTALL_DIR}/docs/upgrade-freebsd.sh" ]; then
            mv \
                "${INSTALL_DIR}/docs/upgrade-freebsd.sh.previous" \
                "${INSTALL_DIR}/docs/upgrade-freebsd.sh" \
                || true
        fi

        if [ -n "$STAGING_DIR" ] && [ -d "$STAGING_DIR" ]; then
            warn "Upgrade did not complete. Staging directory kept at: $STAGING_DIR"
        fi

        if [ -n "$BACKUP_STAMP" ]; then
            warn "Backups created with timestamp: $BACKUP_STAMP"
        fi
    fi

    exit "$rc"
}

trap cleanup 0
trap 'exit 1' HUP INT TERM

# ---------------------------------------------------------------------------
# Preflight
# ---------------------------------------------------------------------------

[ "$(id -u)" -eq 0 ] || die "Please run this upgrade as root."
[ "$(uname -s)" = "FreeBSD" ] || die "This upgrader runs only on FreeBSD."

if [ "$(sysctl -n security.jail.jailed 2>/dev/null || printf '0')" -ne 0 ]; then
    die "This upgrader requires a FreeBSD host or VM, not a jail."
fi

FREEBSD_VERSION=$(freebsd-version -u 2>/dev/null || uname -r)
case "$FREEBSD_VERSION" in
    "${TARGET_FREEBSD_VERSION}"|"${TARGET_FREEBSD_VERSION}-p"*) ;;
    *) die "Unsupported FreeBSD version: ${FREEBSD_VERSION}. Required: ${TARGET_FREEBSD_VERSION} (including patch levels)." ;;
esac

for cmd in \
    git \
    curl \
    tar \
    gzip \
    php \
    composer \
    awk \
    sed \
    grep \
    cp \
    find \
    sort \
    service \
    sysrc \
    realpath
 do
    require_command "$cmd"
done

[ -d "$INSTALL_DIR" ] || die "Namingo Registry was not found at $INSTALL_DIR."
[ -d "$CP_DIR" ] || die "Namingo Registry control panel was not found at $CP_DIR."
[ -d "$WHOIS_WEB_DIR" ] || die "Namingo Registry web WHOIS was not found at $WHOIS_WEB_DIR."

# These are installed by install-freebsd.sh and are required by the patched
# control panel. Do not silently fall back to unrestricted sudo commands.
for helper in \
    /usr/local/sbin/namingo-rndc-dnssec-status \
    /usr/local/sbin/namingo-dnssec-dsfromkey \
    /usr/local/sbin/namingo-keymgr \
    /usr/local/sbin/namingo-service-status
 do
    [ -x "$helper" ] || die "Required FreeBSD Namingo helper is missing: $helper"
done

[ -r /usr/local/etc/sudoers.d/namingo ] \
    || die "Required FreeBSD Namingo sudoers configuration is missing."

if service_is_running php_fpm; then
    PHP_FPM_WAS_RUNNING=1
fi

# ---------------------------------------------------------------------------
# Determine installed version
# ---------------------------------------------------------------------------

if [ -f "$VERSION_FILE" ]; then
    CURRENT_VERSION=$(tr -d '[:space:]' < "$VERSION_FILE")
else
    warn "No VERSION marker was found at:

  $VERSION_FILE

Namingo Registry v1.0.32 predates the universal upgrade system."

    if [ "$ASSUME_YES" -ne 1 ]; then
        prompt_yes_no "Confirm that this installation is v1.0.32? (y/N): " \
            || die "Unable to determine installed Namingo Registry version."
    fi

    CURRENT_VERSION="1.0.32"
    log "Treating this installation as Namingo Registry v1.0.32"
fi

validate_version "$CURRENT_VERSION" || die "Invalid installed version: $CURRENT_VERSION"

if version_lt "$CURRENT_VERSION" "$MIN_SUPPORTED_VERSION"; then
    die "Universal upgrades require v${MIN_SUPPORTED_VERSION} or later.

Upgrade this installation to v${MIN_SUPPORTED_VERSION}
using the legacy sequential scripts first."
fi

# ---------------------------------------------------------------------------
# Determine target version
# ---------------------------------------------------------------------------

if [ -n "$TARGET_OVERRIDE" ]; then
    TARGET_VERSION=$TARGET_OVERRIDE
else
    log "Checking latest Namingo Registry version"
    TARGET_VERSION=$(curl -fsSL "$LATEST_VERSION_URL" | tr -d '[:space:]')
fi

validate_version "$TARGET_VERSION" || die "Invalid target version: $TARGET_VERSION"

if version_gt "$CURRENT_VERSION" "$TARGET_VERSION"; then
    die "Installed version v${CURRENT_VERSION} is newer than target v${TARGET_VERSION}."
fi

if [ "$CURRENT_VERSION" = "$TARGET_VERSION" ]; then
    log "Namingo Registry v${CURRENT_VERSION} is already current."
    SUCCESS=1
    exit 0
fi

log "Verifying release tag v${TARGET_VERSION}"
git ls-remote \
    --exit-code \
    --tags \
    "$REPO_URL" \
    "refs/tags/v${TARGET_VERSION}" \
    >/dev/null \
    || die "Release tag v${TARGET_VERSION} does not exist. Upgrade aborted."

printf '\nNamingo Registry FreeBSD upgrade\n\n'
printf '  Installed: v%s\n' "$CURRENT_VERSION"
printf '  Target:    v%s\n' "$TARGET_VERSION"
printf '  FreeBSD:   %s\n\n' "$FREEBSD_VERSION"

if [ "$ASSUME_YES" -ne 1 ]; then
    if ! prompt_yes_no "Create backups and continue? (y/N): "; then
        printf 'Upgrade aborted.\n'
        SUCCESS=1
        exit 0
    fi
fi

# ---------------------------------------------------------------------------
# Clone target release
# ---------------------------------------------------------------------------

log "Preparing target release"
STAGING_DIR=$(mktemp -d /opt/namingo-registry-upgrade.XXXXXX)
MIGRATION_LIST_FILE="${STAGING_DIR}/.namingo-migrations"

git clone \
    --quiet \
    --depth 1 \
    --branch "v${TARGET_VERSION}" \
    --single-branch \
    "$REPO_URL" \
    "$STAGING_DIR"

[ -f "$STAGING_DIR/VERSION" ] || die "The target release does not contain a VERSION file."
CLONED_VERSION=$(tr -d '[:space:]' < "$STAGING_DIR/VERSION")

[ "$CLONED_VERSION" = "$TARGET_VERSION" ] \
    || die "Target tag says v${TARGET_VERSION}, but its VERSION file says v${CLONED_VERSION}."

# ---------------------------------------------------------------------------
# PHP and database configuration
# ---------------------------------------------------------------------------

PHP_VERSION=$(/usr/local/bin/php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
RDAP_CONFIG="${INSTALL_DIR}/rdap/config.php"

[ -f "$RDAP_CONFIG" ] || die "Database configuration not found: $RDAP_CONFIG"

DB_DRIVER_RAW=$(php_config_value "$RDAP_CONFIG" db_type) \
    || die "Could not read db_type from $RDAP_CONFIG"
DB_HOST=$(php_config_value "$RDAP_CONFIG" db_host) \
    || die "Could not read db_host from $RDAP_CONFIG"
DB_PORT=$(php_config_value "$RDAP_CONFIG" db_port) \
    || die "Could not read db_port from $RDAP_CONFIG"
DB_NAME=$(php_config_value "$RDAP_CONFIG" db_database) \
    || die "Could not read db_database from $RDAP_CONFIG"
DB_USER=$(php_config_value "$RDAP_CONFIG" db_username) \
    || die "Could not read db_username from $RDAP_CONFIG"
DB_PASSWORD=$(php_config_value "$RDAP_CONFIG" db_password) \
    || die "Could not read db_password from $RDAP_CONFIG"

case "$DB_DRIVER_RAW" in
    mysql|mariadb)
        DB_DRIVER="mariadb"
        DB_CLIENT="mariadb"
        DB_DUMP="mariadb-dump"
        ;;
    pgsql|postgres|postgresql)
        DB_DRIVER="pgsql"
        DB_CLIENT="psql"
        DB_DUMP="pg_dump"
        ;;
    *)
        die "Unsupported database type in $RDAP_CONFIG: $DB_DRIVER_RAW"
        ;;
esac

[ -n "$DB_HOST" ] && [ -n "$DB_PORT" ] && [ -n "$DB_NAME" ] && [ -n "$DB_USER" ] \
    || die "Incomplete database settings in $RDAP_CONFIG."

DATABASES="$DB_NAME registryTransaction registryAudit"
DB_CLIENT_AVAILABLE=0
DB_DUMP_AVAILABLE=0
command -v "$DB_CLIENT" >/dev/null 2>&1 && DB_CLIENT_AVAILABLE=1
command -v "$DB_DUMP" >/dev/null 2>&1 && DB_DUMP_AVAILABLE=1

if [ "$DB_DUMP_AVAILABLE" -ne 1 ]; then
    warn "Database backup tool '$DB_DUMP' is not available on this server.

Before continuing, create and verify backups of:

  $DB_NAME
  registryTransaction
  registryAudit

at ${DB_HOST}:${DB_PORT}."

    prompt_yes_no "Database backups completed and verified? (y/N): " || {
        printf 'Upgrade aborted.\n'
        SUCCESS=1
        exit 0
    }
fi

# Variables intentionally match the Linux universal upgrader. FreeBSD adds a
# few OS hints so one migration file can branch cleanly when necessary.
export NAMINGO_FROM_VERSION="$CURRENT_VERSION"
export NAMINGO_TARGET_VERSION="$TARGET_VERSION"
export NAMINGO_INSTALL_DIR="$INSTALL_DIR"
export NAMINGO_CP_DIR="$CP_DIR"
export NAMINGO_WHOIS_WEB_DIR="$WHOIS_WEB_DIR"
export NAMINGO_STAGING_DIR="$STAGING_DIR"
export NAMINGO_PHP_VERSION="$PHP_VERSION"

export NAMINGO_DB_DRIVER="$DB_DRIVER"
export NAMINGO_DB_HOST="$DB_HOST"
export NAMINGO_DB_PORT="$DB_PORT"
export NAMINGO_DB_NAME="$DB_NAME"
export NAMINGO_DB_MAIN="$DB_NAME"
export NAMINGO_DB_TRANSACTION="registryTransaction"
export NAMINGO_DB_AUDIT="registryAudit"
export NAMINGO_DB_USER="$DB_USER"
export NAMINGO_DB_PASSWORD="$DB_PASSWORD"

export NAMINGO_OS="freebsd"
export NAMINGO_FREEBSD_VERSION="$FREEBSD_VERSION"
export NAMINGO_PKG_MANAGER="pkg"
export NAMINGO_SERVICE_MANAGER="rc.d"

if [ "$DB_CLIENT_AVAILABLE" -eq 1 ]; then
    log "Testing database connection"
    case "$DB_DRIVER" in
        mariadb)
            MYSQL_PWD="$DB_PASSWORD" mariadb \
                --host="$DB_HOST" \
                --port="$DB_PORT" \
                --user="$DB_USER" \
                --database="$DB_NAME" \
                --batch \
                --skip-column-names \
                -e 'SELECT 1;' \
                >/dev/null
            ;;
        pgsql)
            PGPASSWORD="$DB_PASSWORD" psql \
                --host="$DB_HOST" \
                --port="$DB_PORT" \
                --username="$DB_USER" \
                --dbname="$DB_NAME" \
                --no-align \
                --tuples-only \
                --command='SELECT 1;' \
                >/dev/null
            ;;
    esac
else
    warn "Skipping automatic database connection test because '$DB_CLIENT' is unavailable."
fi

# ---------------------------------------------------------------------------
# Backups
# ---------------------------------------------------------------------------

BACKUP_STAMP=$(date +%Y%m%d-%H%M%S)
mkdir -p "$BACKUP_DIR"

log "Creating filesystem backups"
tar -czf "${BACKUP_DIR}/cp_backup_${BACKUP_STAMP}.tar.gz" -C / var/www/cp

tar -czf \
    "${BACKUP_DIR}/whois_web_backup_${BACKUP_STAMP}.tar.gz" \
    -C / \
    var/www/whois

tar -czf \
    "${BACKUP_DIR}/registry_backup_${BACKUP_STAMP}.tar.gz" \
    -C / \
    opt/registry

if [ "$DB_DUMP_AVAILABLE" -eq 1 ]; then
    log "Creating database backups"

    for backup_db in $DATABASES; do
        dump_file="${BACKUP_DIR}/db_${backup_db}_backup_${BACKUP_STAMP}.sql"

        case "$DB_DRIVER" in
            mariadb)
                MYSQL_PWD="$DB_PASSWORD" mariadb-dump \
                    --host="$DB_HOST" \
                    --port="$DB_PORT" \
                    --user="$DB_USER" \
                    --single-transaction \
                    --quick \
                    "$backup_db" \
                    > "$dump_file"
                ;;
            pgsql)
                PGPASSWORD="$DB_PASSWORD" pg_dump \
                    --host="$DB_HOST" \
                    --port="$DB_PORT" \
                    --username="$DB_USER" \
                    --dbname="$backup_db" \
                    --no-owner \
                    > "$dump_file"
                ;;
        esac

        gzip -f "$dump_file"
    done
else
    log "Using manually confirmed database backups"
fi

# ---------------------------------------------------------------------------
# Discover migrations: current < migration <= target
# ---------------------------------------------------------------------------

: > "$MIGRATION_LIST_FILE"
MIGRATION_DIR="${STAGING_DIR}/docs/migrations"

if [ -d "$MIGRATION_DIR" ]; then
    migration_candidates="${STAGING_DIR}/.namingo-migration-candidates"
    : > "$migration_candidates"

    for migration in "$MIGRATION_DIR"/*.sh; do
        [ -f "$migration" ] || continue
        migration_name=${migration##*/}
        migration_name=${migration_name%.sh}
        validate_version "$migration_name" || continue

        if version_gt "$migration_name" "$CURRENT_VERSION" \
           && version_le "$migration_name" "$TARGET_VERSION"; then
            printf '%s|%s\n' "$migration_name" "$migration" >> "$migration_candidates"
        fi
    done

    if [ -s "$migration_candidates" ]; then
        sort -t. -k1,1n -k2,2n -k3,3n "$migration_candidates" \
            | cut -d'|' -f2- \
            > "$MIGRATION_LIST_FILE"
    fi
fi

run_migration_script() {
    local migration phase first_line
    migration=$1
    phase=$2
    first_line=$(head -n 1 "$migration" 2>/dev/null || true)

    case "$first_line" in
        *bash*)
            if command -v bash >/dev/null 2>&1; then
                bash "$migration" "$phase"
            elif [ -x /usr/local/bin/bash ]; then
                /usr/local/bin/bash "$migration" "$phase"
            else
                die "Migration $(basename "$migration") requires bash. Prefer POSIX /bin/sh migrations for Linux/FreeBSD portability, or install the FreeBSD bash package."
            fi
            ;;
        *)
            /bin/sh "$migration" "$phase"
            ;;
    esac
}

run_migrations() {
    local phase migration migration_version
    phase=$1

    if [ ! -s "$MIGRATION_LIST_FILE" ]; then
        log "No ${phase}-upgrade migrations are required"
        return 0
    fi

    while IFS= read -r migration; do
        [ -n "$migration" ] || continue
        migration_version=${migration##*/}
        migration_version=${migration_version%.sh}
        log "Running ${phase} migration for v${migration_version}"
        run_migration_script "$migration" "$phase"
    done < "$MIGRATION_LIST_FILE"
}

run_migrations pre

# ---------------------------------------------------------------------------
# Stop active Registry services
# ---------------------------------------------------------------------------

log "Stopping active Registry services"
SERVICES_STOPPED=1

# Stop cron and the EPP certificate watcher first. The watcher can otherwise
# start EPP again while files or schemas are being changed.
for service_name in \
    cron \
    namingo_certwatch \
    epp \
    whois \
    das \
    rdap \
    msg_producer \
    msg_worker \
    caddy
 do
    if service_is_running "$service_name"; then
        SERVICES_TO_RESTART="$SERVICES_TO_RESTART $service_name"
        service "$service_name" stop
    fi
done

# ---------------------------------------------------------------------------
# Copy release files while preserving live configuration
# ---------------------------------------------------------------------------

PRESERVE_COUNTER=0

copy_tree_preserving() {
    local src dst preserve_rel saved_file
    src=$1
    dst=$2
    preserve_rel=${3:-}
    saved_file=""

    [ -d "$src" ] || {
        warn "Target release has no source directory: $src"
        return 0
    }

    mkdir -p "$dst"

    if [ -n "$preserve_rel" ] && [ -e "$dst/$preserve_rel" ]; then
        PRESERVE_COUNTER=$((PRESERVE_COUNTER + 1))
        saved_file="${STAGING_DIR}/.preserved-${PRESERVE_COUNTER}"
        cp -Rp "$dst/$preserve_rel" "$saved_file"
    fi

    # Do not remove destination-only files. This retains local files, the
    # generated FreeBSD compatibility class and EPP certificate symlinks.
    cp -Rp "$src/." "$dst/"

    if [ -n "$saved_file" ] && [ -e "$saved_file" ]; then
        mkdir -p "$(dirname "$dst/$preserve_rel")"
        rm -rf "$dst/$preserve_rel"
        cp -Rp "$saved_file" "$dst/$preserve_rel"
    fi
}

copy_optional_component() {
    local src dst preserve_rel
    src=$1
    dst=$2
    preserve_rel=${3:-config.php}

    [ -d "$dst" ] || {
        log "Optional component not installed; skipping $dst"
        return 0
    }

    copy_tree_preserving "$src" "$dst" "$preserve_rel"
}

log "Updating Namingo Registry files"

if [ -f "${INSTALL_DIR}/docs/upgrade-freebsd.sh" ]; then
    mv \
        "${INSTALL_DIR}/docs/upgrade-freebsd.sh" \
        "${INSTALL_DIR}/docs/upgrade-freebsd.sh.previous"
fi

copy_tree_preserving "${STAGING_DIR}/automation" "${INSTALL_DIR}/automation" "config.php"
copy_tree_preserving "${STAGING_DIR}/cp" "$CP_DIR" ".env"
copy_tree_preserving "${STAGING_DIR}/whois/web" "$WHOIS_WEB_DIR" "config.php"
copy_optional_component "${STAGING_DIR}/whois/port43" "${INSTALL_DIR}/whois/port43" "config.php"
copy_optional_component "${STAGING_DIR}/das" "${INSTALL_DIR}/das" "config.php"
copy_tree_preserving "${STAGING_DIR}/rdap" "${INSTALL_DIR}/rdap" "config.php"
copy_tree_preserving "${STAGING_DIR}/epp" "${INSTALL_DIR}/epp" "config.php"
copy_tree_preserving "${STAGING_DIR}/database" "${INSTALL_DIR}/database"
copy_tree_preserving "${STAGING_DIR}/docs" "${INSTALL_DIR}/docs"

if [ -d "${STAGING_DIR}/tests" ]; then
    copy_tree_preserving "${STAGING_DIR}/tests" "${INSTALL_DIR}/tests"
fi

if [ -f "${STAGING_DIR}/namingo" ]; then
    cp -p "${STAGING_DIR}/namingo" "${INSTALL_DIR}/namingo"
    chmod 0755 "${INSTALL_DIR}/namingo"
fi

[ ! -f "${INSTALL_DIR}/docs/upgrade-freebsd.sh" ] \
    || chmod 0755 "${INSTALL_DIR}/docs/upgrade-freebsd.sh"

# ---------------------------------------------------------------------------
# Composer
# ---------------------------------------------------------------------------

composer_sync() {
    local dir release_dir
    dir=$1
    release_dir=$2

    [ -f "$dir/composer.json" ] || return 0

    log "Updating Composer dependencies in $dir"

    (
        cd "$dir"
        if [ -f "$release_dir/composer.lock" ]; then
            COMPOSER_ALLOW_SUPERUSER=1 composer install \
                --no-interaction \
                --quiet \
                --no-progress
        else
            # Registry releases do not consistently ship locks. Do not trust
            # a lock generated by the previous installed release.
            COMPOSER_ALLOW_SUPERUSER=1 composer update \
                --no-interaction \
                --quiet \
                --no-progress
        fi
    )
}

composer_sync "${INSTALL_DIR}/automation" "${STAGING_DIR}/automation"
composer_sync "$CP_DIR" "${STAGING_DIR}/cp"
[ ! -d "${INSTALL_DIR}/whois/port43" ] \
    || composer_sync "${INSTALL_DIR}/whois/port43" "${STAGING_DIR}/whois/port43"
[ ! -d "${INSTALL_DIR}/das" ] \
    || composer_sync "${INSTALL_DIR}/das" "${STAGING_DIR}/das"
composer_sync "${INSTALL_DIR}/rdap" "${STAGING_DIR}/rdap"
composer_sync "${INSTALL_DIR}/epp" "${STAGING_DIR}/epp"

# ---------------------------------------------------------------------------
# POST migrations run against the target release before FreeBSD compatibility
# patches are overlaid. This keeps shared Linux/FreeBSD migrations operating
# on the release's canonical source layout.
# ---------------------------------------------------------------------------

run_migrations post

# ---------------------------------------------------------------------------
# Reapply FreeBSD source compatibility
# ---------------------------------------------------------------------------

compat_replace() {
    local file old_text new_text description
    file=$1
    old_text=$2
    new_text=$3
    description=$4

    [ -f "$file" ] || die "FreeBSD compatibility target is missing: $file"

    /usr/local/bin/php -r '
        $file = $argv[1];
        $old = $argv[2];
        $new = $argv[3];
        $description = $argv[4];
        $contents = file_get_contents($file);
        if ($contents === false) {
            fwrite(STDERR, "Unable to read {$file}.\n");
            exit(2);
        }
        if (str_contains($contents, $new)) {
            exit(0);
        }
        if (!str_contains($contents, $old)) {
            fwrite(STDERR, "FreeBSD compatibility patch no longer matches {$file}: {$description}.\n");
            exit(3);
        }
        $contents = str_replace($old, $new, $contents);
        if (file_put_contents($file, $contents) === false) {
            fwrite(STDERR, "Unable to update {$file}.\n");
            exit(4);
        }
    ' -- "$file" "$old_text" "$new_text" "$description" \
        || die "Unable to apply FreeBSD compatibility: $description"
}

apply_freebsd_compatibility() {
    log "Reapplying FreeBSD compatibility"

    EPP_SERVER_OLD="\$server = new Server(
    \$c['epp_host'],
    \$c['epp_port'],
    SWOOLE_PROCESS,
    ((\$c['epp_host'] === '::') ? SWOOLE_SOCK_TCP6 : SWOOLE_SOCK_TCP) | SWOOLE_SSL
);"
    EPP_SERVER_NEW="${EPP_SERVER_OLD}
if ((\$c['epp_ipv6'] ?? false) !== false) {
    \$server->addListener(\$c['epp_ipv6'], \$c['epp_port'], SWOOLE_SOCK_TCP6 | SWOOLE_SSL);
}"

    compat_replace \
        "${INSTALL_DIR}/epp/start_epp.php" \
        "$EPP_SERVER_OLD" \
        "$EPP_SERVER_NEW" \
        "separate FreeBSD IPv6 EPP listener"

    compat_replace \
        "${INSTALL_DIR}/epp/start_epp.php" \
        "'tcp_defer_accept' => true" \
        "'tcp_defer_accept' => false" \
        "disable tcp_defer_accept for server-first EPP"

    compat_replace \
        "${INSTALL_DIR}/epp/start_epp.php" \
        "'/etc/ssl/certs/ca-certificates.crt'" \
        "'/etc/ssl/cert.pem'" \
        "FreeBSD CA bundle path"

    compat_replace \
        "${INSTALL_DIR}/automation/msg_producer.php" \
        "'daemonize'  => true" \
        "'daemonize'  => false" \
        "run message producer under daemon(8) supervision"

    compat_replace \
        "${INSTALL_DIR}/automation/write-zone.php" \
        "'/var/lib/bind'" \
        "'/usr/local/etc/namedb/primary'" \
        "BIND zone path"

    compat_replace \
        "${INSTALL_DIR}/automation/write-zone.php" \
        "'/var/lib/knot/zones'" \
        "'/var/db/knot/zones'" \
        "Knot zone path"

    compat_replace \
        "${INSTALL_DIR}/automation/write-zone.php" \
        "'/var/lib/cascade/zones'" \
        "'/var/db/cascade/zones'" \
        "Cascade zone path"

    SYSTEM_CONTROLLER="${CP_DIR}/app/Controllers/SystemController.php"

    compat_replace \
        "$SYSTEM_CONTROLLER" \
        "file_exists('/usr/sbin/rndc')" \
        "file_exists('/usr/local/sbin/rndc')" \
        "FreeBSD rndc executable path"

    compat_replace \
        "$SYSTEM_CONTROLLER" \
        "sudo rndc dnssec -status" \
        "/usr/local/bin/sudo -n /usr/local/sbin/namingo-rndc-dnssec-status" \
        "restricted rndc helper"

    compat_replace \
        "$SYSTEM_CONTROLLER" \
        "dnssec-dsfromkey -2 /var/lib/bind" \
        "/usr/local/bin/sudo -n /usr/local/sbin/namingo-dnssec-dsfromkey /usr/local/etc/namedb/primary" \
        "restricted dnssec-dsfromkey helper"

    compat_replace \
        "$SYSTEM_CONTROLLER" \
        "file_exists('/usr/sbin/knotc')" \
        "file_exists('/usr/local/sbin/knotc')" \
        "FreeBSD knotc executable path"

    compat_replace \
        "$SYSTEM_CONTROLLER" \
        "sudo -n keymgr" \
        "/usr/local/bin/sudo -n /usr/local/sbin/namingo-keymgr" \
        "restricted keymgr helper"

    REPORTS_CONTROLLER="${CP_DIR}/app/Controllers/ReportsController.php"

    compat_replace \
        "$REPORTS_CONTROLLER" \
        'use Utopia\System\System;' \
        'use App\Lib\FreeBSDSystem as System;' \
        "FreeBSD system metrics implementation"

    compat_replace \
        "$REPORTS_CONTROLLER" \
        "\$output = @shell_exec(\"service \$serviceName status\");" \
        "\$output = []; \$status = 1; @exec(\"/usr/local/bin/sudo -n /usr/local/sbin/namingo-service-status \" . escapeshellarg(\$serviceName) . \" 2>&1\", \$output, \$status);" \
        "restricted rc.d service status helper"

    compat_replace \
        "$REPORTS_CONTROLLER" \
        "return (\$output && strpos(\$output, 'active (running)') !== false) ? 'Running' : 'Stopped';" \
        "return \$status === 0 ? 'Running' : 'Stopped';" \
        "FreeBSD service status result"

    CLEAR_CACHE_SCRIPT="${CP_DIR}/bin/clear_cache.php"

    compat_replace \
        "$CLEAR_CACHE_SCRIPT" \
        "sudo systemctl restart php{\$version}-fpm" \
        "/usr/local/bin/sudo -n /usr/sbin/service php_fpm restart" \
        "FreeBSD PHP-FPM cache restart"

    compat_replace \
        "$CLEAR_CACHE_SCRIPT" \
        "sudo systemctl restart php8.5-fpm" \
        "/usr/local/bin/sudo -n /usr/sbin/service php_fpm restart" \
        "FreeBSD PHP 8.5 cache restart"

    compat_replace \
        "$CLEAR_CACHE_SCRIPT" \
        "sudo systemctl restart php8.3-fpm" \
        "/usr/local/bin/sudo -n /usr/sbin/service php_fpm restart" \
        "FreeBSD PHP 8.3 cache restart"

    compat_replace \
        "$CLEAR_CACHE_SCRIPT" \
        "systemctl output" \
        "service output" \
        "FreeBSD cache-helper diagnostic label"

    # This class is generated by install-freebsd.sh rather than shipped in the
    # generic Registry source. Recreate it on every upgrade so the CP patch is
    # self-contained even if the local copy was removed accidentally.
    mkdir -p "${CP_DIR}/app/Lib"
    cat > "${CP_DIR}/app/Lib/FreeBSDSystem.php" <<'EOF_FREEBSD_SYSTEM'
<?php

namespace App\Lib;

use RuntimeException;

final class FreeBSDSystem
{
    public static function getCPUCores(): int
    {
        return max(1, (int) trim((string) shell_exec('/sbin/sysctl -n hw.ncpu')));
    }

    public static function getCPUUsage(int $duration = 1): float
    {
        $start = self::cpuTimes();
        sleep(max(1, $duration));
        $end = self::cpuTimes();

        $totalDelta = array_sum($end) - array_sum($start);
        $idleDelta = $end[4] - $start[4];
        if ($totalDelta <= 0) {
            return 0.0;
        }

        return max(0.0, min(100.0, (($totalDelta - $idleDelta) / $totalDelta) * 100));
    }

    public static function getMemoryTotal(): int
    {
        return (int) (((int) trim((string) shell_exec('/sbin/sysctl -n hw.physmem'))) / 1024 / 1024);
    }

    public static function getMemoryFree(): int
    {
        $pages = (int) trim((string) shell_exec('/sbin/sysctl -n vm.stats.vm.v_free_count'));
        $pageSize = (int) trim((string) shell_exec('/sbin/sysctl -n hw.pagesize'));
        return (int) (($pages * $pageSize) / 1024 / 1024);
    }

    public static function getDiskTotal(string $directory = __DIR__): int
    {
        $bytes = disk_total_space($directory);
        if ($bytes === false) {
            throw new RuntimeException('Unable to get disk space.');
        }
        return (int) ($bytes / 1024 / 1024);
    }

    public static function getDiskFree(string $directory = __DIR__): int
    {
        $bytes = disk_free_space($directory);
        if ($bytes === false) {
            throw new RuntimeException('Unable to get free disk space.');
        }
        return (int) ($bytes / 1024 / 1024);
    }

    /** @return array<int, int> */
    private static function cpuTimes(): array
    {
        $raw = trim((string) shell_exec('/sbin/sysctl -n kern.cp_time'));
        $values = preg_split('/\s+/', $raw);
        if ($values === false || count($values) < 5) {
            throw new RuntimeException('Unable to read FreeBSD CPU statistics.');
        }
        return array_map('intval', array_slice($values, 0, 5));
    }
}
EOF_FREEBSD_SYSTEM

    chmod 0644 "${CP_DIR}/app/Lib/FreeBSDSystem.php"
}

apply_freebsd_compatibility

# ---------------------------------------------------------------------------
# Restore FreeBSD ownership and permissions after copy + Composer
# ---------------------------------------------------------------------------

restore_freebsd_permissions() {
    log "Restoring FreeBSD application permissions"

    pw usershow www >/dev/null 2>&1 || die "Expected FreeBSD www user is missing."
    pw usershow caddy >/dev/null 2>&1 || die "Expected FreeBSD caddy user is missing."
    pw groupshow namingo-web >/dev/null 2>&1 || die "Expected namingo-web group is missing."

    chown -R root:www "$CP_DIR" "$WHOIS_WEB_DIR"
    chmod -R g+rX,o-rwx "$CP_DIR" "$WHOIS_WEB_DIR"

    chown root:namingo-web "$CP_DIR"
    chown -R root:namingo-web "$CP_DIR/public" "$WHOIS_WEB_DIR"

    install -d -o www -g www -m 0750 "$CP_DIR/cache"
    chown -R www:www "$CP_DIR/cache"

    chown root:www "$CP_DIR/.env" "$WHOIS_WEB_DIR/config.php"
    chmod 0640 "$CP_DIR/.env" "$WHOIS_WEB_DIR/config.php"

    for component_config in \
        "${INSTALL_DIR}/epp/config.php" \
        "${INSTALL_DIR}/rdap/config.php" \
        "${INSTALL_DIR}/automation/config.php" \
        "${INSTALL_DIR}/whois/port43/config.php" \
        "${INSTALL_DIR}/das/config.php"
    do
        [ -f "$component_config" ] || continue
        chown root:wheel "$component_config"
        chmod 0600 "$component_config"
    done

    if ! /usr/bin/su -m caddy -c \
        "test -x '$CP_DIR' && test -r '$CP_DIR/public/index.php' && test -r '$WHOIS_WEB_DIR/index.php' && ! test -r '$CP_DIR/.env' && ! test -r '$WHOIS_WEB_DIR/config.php'"; then
        die "Caddy web-root permissions are not isolated as expected."
    fi

    if ! /usr/bin/su -m www -c \
        "test -r '$CP_DIR/.env' && test -r '$CP_DIR/public/index.php' && test -r '$WHOIS_WEB_DIR/config.php'"; then
        die "PHP-FPM web-root permissions are not configured correctly."
    fi
}

restore_freebsd_permissions

# ---------------------------------------------------------------------------
# Adminer: detect randomized or legacy route and update the actual file
# ---------------------------------------------------------------------------

update_adminer() {
    local caddy_file adminer_root route_name adminer_file resolved tmp_file candidate
    caddy_file="/usr/local/etc/caddy/Caddyfile"
    adminer_root="/usr/local/share/adminer"
    route_name=""
    adminer_file=""

    [ -d "$adminer_root" ] || {
        warn "Adminer directory not found; skipping Adminer update."
        return 0
    }

    if [ -r "$caddy_file" ]; then
        route_name=$(sed -nE \
            's#^[[:space:]]*route[[:space:]]+/((adminer-[0-9A-Fa-f]+|adminer)\.php)\*?[[:space:]]*\{.*#\1#p' \
            "$caddy_file" \
            | head -n 1)
    fi

    if [ -n "$route_name" ]; then
        ADMINER_ROUTE_NAME=$route_name
        adminer_file="${adminer_root}/${route_name}"
    else
        for candidate in "$adminer_root"/adminer-*.php; do
            if [ -e "$candidate" ] || [ -L "$candidate" ]; then
                adminer_file=$candidate
                ADMINER_ROUTE_NAME=${candidate##*/}
                break
            fi
        done

        if [ -z "$adminer_file" ] && { [ -e "${adminer_root}/adminer.php" ] || [ -L "${adminer_root}/adminer.php" ]; }; then
            adminer_file="${adminer_root}/adminer.php"
            ADMINER_ROUTE_NAME="adminer.php"
        fi
    fi

    [ -n "$adminer_file" ] || {
        warn "Unable to locate the installed Adminer file; skipping Adminer update."
        return 0
    }

    if [ -L "$adminer_file" ]; then
        resolved=$(realpath "$adminer_file" 2>/dev/null || true)
        [ -n "$resolved" ] || {
            warn "Unable to resolve Adminer symlink: $adminer_file"
            return 0
        }
        adminer_file=$resolved
    fi

    log "Updating Adminer ($(basename "$adminer_file"))"
    tmp_file="${adminer_file}.upgrade.$$"
    curl -fsSL "$ADMINER_URL" -o "$tmp_file"
    chmod 0644 "$tmp_file"
    mv -f "$tmp_file" "$adminer_file"
}

update_adminer

# ---------------------------------------------------------------------------
# Clear Registry control-panel cache
# ---------------------------------------------------------------------------

if [ -f "$CP_DIR/bin/clear_cache.php" ]; then
    log "Clearing control panel cache"
    /usr/local/bin/php "$CP_DIR/bin/clear_cache.php"
else
    warn "Control-panel cache helper not found: $CP_DIR/bin/clear_cache.php"
fi

# The cache helper attempts its own PHP-FPM restart, but deliberately exits 0
# even if that restart fails. Guarantee the restart independently when FPM was
# running before the upgrade.
if [ "$PHP_FPM_WAS_RUNNING" -eq 1 ]; then
    log "Restarting php_fpm"
    service php_fpm restart
fi

# ---------------------------------------------------------------------------
# Restart services and health-check the exact services that were previously up
# ---------------------------------------------------------------------------

log "Starting services"
start_saved_service caddy
start_saved_service rdap
start_saved_service whois
start_saved_service das
start_saved_service epp
start_saved_service msg_producer
start_saved_service msg_worker
start_saved_service namingo_certwatch
start_saved_service cron

log "Verifying services"
for service_name in $SERVICES_TO_RESTART; do
    service_is_running "$service_name" \
        || die "Service failed health check: $service_name"
done

SERVICES_STOPPED=0

# ---------------------------------------------------------------------------
# Mark upgrade successful only after every prior step has succeeded
# ---------------------------------------------------------------------------

printf '%s\n' "$TARGET_VERSION" > "$VERSION_FILE"
chmod 0644 "$VERSION_FILE"
rm -f "${INSTALL_DIR}/docs/upgrade-freebsd.sh.previous"

SUCCESS=1

printf '\n============================================================\n'
printf ' Namingo Registry FreeBSD upgrade complete\n'
printf '============================================================\n\n'
printf ' Previous version:  v%s\n' "$CURRENT_VERSION"
printf ' Installed version: v%s\n' "$TARGET_VERSION"
printf ' Backup timestamp:  %s\n' "$BACKUP_STAMP"
if [ -n "$ADMINER_ROUTE_NAME" ]; then
    printf ' Adminer route:     /%s\n' "$ADMINER_ROUTE_NAME"
fi
printf '\nBackups:\n'
printf '  %s/cp_backup_%s.tar.gz\n' "$BACKUP_DIR" "$BACKUP_STAMP"
printf '  %s/whois_web_backup_%s.tar.gz\n' "$BACKUP_DIR" "$BACKUP_STAMP"
printf '  %s/registry_backup_%s.tar.gz\n' "$BACKUP_DIR" "$BACKUP_STAMP"

if [ "$DB_DUMP_AVAILABLE" -eq 1 ]; then
    for backup_db in $DATABASES; do
        printf '  %s/db_%s_backup_%s.sql.gz\n' "$BACKUP_DIR" "$backup_db" "$BACKUP_STAMP"
    done
else
    printf '  Database backups: manual/external (confirmed)\n'
fi

printf '\n'
