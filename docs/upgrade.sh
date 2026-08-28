#!/usr/bin/env bash

set -Eeuo pipefail

# Namingo Registry universal upgrader
# Supported installed versions: 1.0.32 and later.
#
# The first universal-upgrade release is v1.0.33. Existing v1.0.32
# installations predate the VERSION marker and are handled as a one-time
# bootstrap case.

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

TARGET_OVERRIDE=""
ASSUME_YES=0

STAGING_DIR=""
SUCCESS=0
SERVICES_STOPPED=0
ADMINER_ROUTE_NAME=""
declare -a SERVICES_TO_RESTART=()
declare -a MIGRATIONS=()

declare -a DATABASES=(
    "registry"
    "registryTransaction"
    "registryAudit"
)

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

while [[ $# -gt 0 ]]; do
    case "$1" in
        --target)
            [[ $# -ge 2 ]] || die "--target requires a version."
            TARGET_OVERRIDE="$2"
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

require_root() {
    [[ "${EUID:-$(id -u)}" -eq 0 ]] \
        || die "Please run this upgrade as root."
}

require_command() {
    command -v "$1" >/dev/null 2>&1 \
        || die "Required command not found: $1"
}

validate_version() {
    [[ "$1" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]
}

version_lt() {
    dpkg --compare-versions "$1" lt "$2"
}

version_le() {
    dpkg --compare-versions "$1" le "$2"
}

version_gt() {
    dpkg --compare-versions "$1" gt "$2"
}

php_config_value() {
    local file="$1"
    local key="$2"

    php -r '
        $file = $argv[1];
        $key = $argv[2];
        $cfg = require $file;

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
    ' "$file" "$key"
}

restart_previous_services() {
    local service

    [[ "$SERVICES_STOPPED" -eq 1 ]] || return 0

    warn "Attempting to restart services that were running before the upgrade."

    systemctl daemon-reload >/dev/null 2>&1 || true

    if printf '%s\n' "${SERVICES_TO_RESTART[@]}" | grep -qx caddy; then
        systemctl start caddy >/dev/null 2>&1 || true
    fi

    for service in "${SERVICES_TO_RESTART[@]}"; do
        [[ "$service" == "caddy" ]] && continue
        systemctl start "$service" >/dev/null 2>&1 || true
    done

    SERVICES_STOPPED=0
}

cleanup() {
    local rc=$?

    set +e

    if [[ "$SUCCESS" -eq 1 ]]; then
        if [[ -n "$STAGING_DIR" && -d "$STAGING_DIR" ]]; then
            rm -rf "$STAGING_DIR"
        fi
    else
        restart_previous_services

        if [[ -f "${INSTALL_DIR}/docs/upgrade.sh.previous" \
           && ! -f "${INSTALL_DIR}/docs/upgrade.sh" ]]; then
            mv \
                "${INSTALL_DIR}/docs/upgrade.sh.previous" \
                "${INSTALL_DIR}/docs/upgrade.sh" \
                || true
        fi

        if [[ -n "$STAGING_DIR" && -d "$STAGING_DIR" ]]; then
            warn "Upgrade did not complete. Staging directory kept at: $STAGING_DIR"
        fi

        if [[ -n "${BACKUP_STAMP:-}" ]]; then
            warn "Backups created with timestamp: $BACKUP_STAMP"
        fi
    fi

    return "$rc"
}

trap cleanup EXIT

require_root

[[ "$(uname -s)" == "Linux" ]] \
    || die "This universal upgrader currently supports Linux installations only."

[[ -r /etc/os-release ]] \
    || die "Unable to identify the Linux distribution."

# shellcheck disable=SC1091
. /etc/os-release

case "${ID:-}" in
    ubuntu|debian) ;;
    *) die "Unsupported Linux distribution: ${ID:-unknown}." ;;
esac

for cmd in \
    git \
    curl \
    tar \
    gzip \
    php \
    composer \
    systemctl \
    dpkg \
    awk \
    sed \
    grep \
    cp \
    find \
    sort
do
    require_command "$cmd"
done

# ---------------------------------------------------------
# Determine installed version
# ---------------------------------------------------------

[[ -d "$INSTALL_DIR" ]] \
    || die "Namingo Registry was not found at $INSTALL_DIR."

[[ -d "$CP_DIR" ]] \
    || die "Namingo Registry control panel was not found at $CP_DIR."

if [[ -f "$VERSION_FILE" ]]; then
    CURRENT_VERSION="$(tr -d '[:space:]' < "$VERSION_FILE")"
else
    warn \
"No VERSION marker was found at:

  $VERSION_FILE

Namingo Registry v1.0.32 predates the universal upgrade system."

    if [[ "$ASSUME_YES" -ne 1 ]]; then
        read -r -p \
            "Confirm that this installation is v1.0.32? (y/N): " \
            bootstrap_confirm

        [[ "$bootstrap_confirm" =~ ^[Yy]$ ]] \
            || die "Unable to determine installed Namingo Registry version."
    fi

    CURRENT_VERSION="1.0.32"
    log "Treating this installation as Namingo Registry v1.0.32"
fi

validate_version "$CURRENT_VERSION" \
    || die "Invalid installed version: $CURRENT_VERSION"

if version_lt "$CURRENT_VERSION" "$MIN_SUPPORTED_VERSION"; then
    die \
"Universal upgrades require v${MIN_SUPPORTED_VERSION} or later.

Upgrade this installation to v${MIN_SUPPORTED_VERSION}
using the legacy sequential scripts first."
fi

# ---------------------------------------------------------
# Determine target version
# ---------------------------------------------------------

if [[ -n "$TARGET_OVERRIDE" ]]; then
    TARGET_VERSION="$TARGET_OVERRIDE"
else
    log "Checking latest Namingo Registry version"

    TARGET_VERSION="$(
        curl -fsSL "$LATEST_VERSION_URL" \
        | tr -d '[:space:]'
    )"
fi

validate_version "$TARGET_VERSION" \
    || die "Invalid target version: $TARGET_VERSION"

if version_gt "$CURRENT_VERSION" "$TARGET_VERSION"; then
    die \
"Installed version v${CURRENT_VERSION} is newer than
target v${TARGET_VERSION}."
fi

if [[ "$CURRENT_VERSION" == "$TARGET_VERSION" ]]; then
    log "Namingo Registry v${CURRENT_VERSION} is already current."
    SUCCESS=1
    exit 0
fi

# ---------------------------------------------------------
# Verify and clone target release
# ---------------------------------------------------------

log "Verifying release tag v${TARGET_VERSION}"

git ls-remote \
    --exit-code \
    --tags \
    "$REPO_URL" \
    "refs/tags/v${TARGET_VERSION}" \
    >/dev/null \
    || die "Release tag v${TARGET_VERSION} does not exist. Upgrade aborted."

echo
echo "Namingo Registry upgrade"
echo
echo "  Installed: v${CURRENT_VERSION}"
echo "  Target:    v${TARGET_VERSION}"
echo

if [[ "$ASSUME_YES" -ne 1 ]]; then
    read -r -p "Create backups and continue? (y/N): " confirm

    [[ "$confirm" =~ ^[Yy]$ ]] || {
        echo "Upgrade aborted."
        SUCCESS=1
        exit 0
    }
fi

log "Preparing target release"

STAGING_DIR="$(mktemp -d /opt/namingo-registry-upgrade.XXXXXX)"

git clone \
    --quiet \
    --depth 1 \
    --branch "v${TARGET_VERSION}" \
    --single-branch \
    "$REPO_URL" \
    "$STAGING_DIR"

[[ -f "$STAGING_DIR/VERSION" ]] \
    || die "The target release does not contain a VERSION file."

CLONED_VERSION="$(tr -d '[:space:]' < "$STAGING_DIR/VERSION")"

[[ "$CLONED_VERSION" == "$TARGET_VERSION" ]] \
    || die \
"Target tag says v${TARGET_VERSION},
but its VERSION file says v${CLONED_VERSION}."

# ---------------------------------------------------------
# PHP and database configuration
# ---------------------------------------------------------

PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
export NAMINGO_PHP_VERSION="$PHP_VERSION"

RDAP_CONFIG="${INSTALL_DIR}/rdap/config.php"

[[ -f "$RDAP_CONFIG" ]] \
    || die "Database configuration not found: $RDAP_CONFIG"

DB_DRIVER_RAW="$(php_config_value "$RDAP_CONFIG" db_type)" \
    || die "Could not read db_type from $RDAP_CONFIG"

DB_HOST="$(php_config_value "$RDAP_CONFIG" db_host)" \
    || die "Could not read db_host from $RDAP_CONFIG"

DB_PORT="$(php_config_value "$RDAP_CONFIG" db_port)" \
    || die "Could not read db_port from $RDAP_CONFIG"

DB_NAME="$(php_config_value "$RDAP_CONFIG" db_database)" \
    || die "Could not read db_database from $RDAP_CONFIG"

DB_USER="$(php_config_value "$RDAP_CONFIG" db_username)" \
    || die "Could not read db_username from $RDAP_CONFIG"

DB_PASSWORD="$(php_config_value "$RDAP_CONFIG" db_password)" \
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

[[ -n "$DB_HOST" \
   && -n "$DB_PORT" \
   && -n "$DB_NAME" \
   && -n "$DB_USER" ]] \
    || die "Incomplete database settings in $RDAP_CONFIG."

# The Registry uses these three database names across its services.
# Keep the configured primary name in slot zero in case it is customized.
DATABASES[0]="$DB_NAME"

DB_CLIENT_AVAILABLE=0
DB_DUMP_AVAILABLE=0

command -v "$DB_CLIENT" >/dev/null 2>&1 \
    && DB_CLIENT_AVAILABLE=1

command -v "$DB_DUMP" >/dev/null 2>&1 \
    && DB_DUMP_AVAILABLE=1

if [[ "$DB_DUMP_AVAILABLE" -ne 1 ]]; then
    warn \
"Database backup tool '$DB_DUMP' is not available on this server.

The databases may be hosted externally, so this upgrader cannot
create database backups automatically.

Before continuing, create and verify backups of:

  ${DATABASES[0]}
  ${DATABASES[1]}
  ${DATABASES[2]}

at ${DB_HOST}:${DB_PORT}."

    read -r -p \
        "Database backups completed and verified? (y/N): " \
        db_backup_confirm

    [[ "$db_backup_confirm" =~ ^[Yy]$ ]] || {
        echo "Upgrade aborted."
        SUCCESS=1
        exit 0
    }
fi

# ---------------------------------------------------------
# Variables available to migration scripts
# ---------------------------------------------------------

export NAMINGO_FROM_VERSION="$CURRENT_VERSION"
export NAMINGO_TARGET_VERSION="$TARGET_VERSION"
export NAMINGO_INSTALL_DIR="$INSTALL_DIR"
export NAMINGO_CP_DIR="$CP_DIR"
export NAMINGO_WHOIS_WEB_DIR="$WHOIS_WEB_DIR"
export NAMINGO_STAGING_DIR="$STAGING_DIR"

export NAMINGO_DB_DRIVER="$DB_DRIVER"
export NAMINGO_DB_HOST="$DB_HOST"
export NAMINGO_DB_PORT="$DB_PORT"
export NAMINGO_DB_NAME="$DB_NAME"
export NAMINGO_DB_MAIN="$DB_NAME"
export NAMINGO_DB_TRANSACTION="${DATABASES[1]}"
export NAMINGO_DB_AUDIT="${DATABASES[2]}"
export NAMINGO_DB_USER="$DB_USER"
export NAMINGO_DB_PASSWORD="$DB_PASSWORD"

# ---------------------------------------------------------
# Test database connection
# ---------------------------------------------------------

if [[ "$DB_CLIENT_AVAILABLE" -eq 1 ]]; then
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
                -e "SELECT 1;" \
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
                --command="SELECT 1;" \
                >/dev/null
            ;;
    esac
else
    warn "Skipping automatic database connection test because '$DB_CLIENT' is unavailable."
fi

# ---------------------------------------------------------
# Backups
# ---------------------------------------------------------

BACKUP_STAMP="$(date +%Y%m%d-%H%M%S)"
mkdir -p "$BACKUP_DIR"

log "Creating filesystem backups"

tar -czf \
    "${BACKUP_DIR}/cp_backup_${BACKUP_STAMP}.tar.gz" \
    -C / \
    var/www/cp

if [[ -d "$WHOIS_WEB_DIR" ]]; then
    tar -czf \
        "${BACKUP_DIR}/whois_web_backup_${BACKUP_STAMP}.tar.gz" \
        -C / \
        var/www/whois
fi

tar -czf \
    "${BACKUP_DIR}/registry_backup_${BACKUP_STAMP}.tar.gz" \
    -C / \
    opt/registry

if [[ "$DB_DUMP_AVAILABLE" -eq 1 ]]; then
    log "Creating database backups"

    for backup_db in "${DATABASES[@]}"; do
        case "$DB_DRIVER" in
            mariadb)
                MYSQL_PWD="$DB_PASSWORD" mariadb-dump \
                    --host="$DB_HOST" \
                    --port="$DB_PORT" \
                    --user="$DB_USER" \
                    --single-transaction \
                    --quick \
                    "$backup_db" \
                    | gzip \
                    > "${BACKUP_DIR}/db_${backup_db}_backup_${BACKUP_STAMP}.sql.gz"
                ;;
            pgsql)
                PGPASSWORD="$DB_PASSWORD" pg_dump \
                    --host="$DB_HOST" \
                    --port="$DB_PORT" \
                    --username="$DB_USER" \
                    --dbname="$backup_db" \
                    --no-owner \
                    | gzip \
                    > "${BACKUP_DIR}/db_${backup_db}_backup_${BACKUP_STAMP}.sql.gz"
                ;;
        esac
    done
else
    log "Using manually confirmed database backups"
fi

# ---------------------------------------------------------
# Discover required migrations
# ---------------------------------------------------------

MIGRATION_DIR="${STAGING_DIR}/docs/migrations"

if [[ -d "$MIGRATION_DIR" ]]; then
    while IFS= read -r migration; do
        name="$(basename "$migration" .sh)"

        # EXAMPLE.sh and helper files are deliberately ignored.
        validate_version "$name" || continue

        # Run only: current < migration <= target.
        if version_gt "$name" "$CURRENT_VERSION" \
           && version_le "$name" "$TARGET_VERSION"; then
            MIGRATIONS+=("$migration")
        fi
    done < <(
        find "$MIGRATION_DIR" \
            -maxdepth 1 \
            -type f \
            -name '*.sh' \
            -print \
        | sort -V
    )
fi

if [[ "$DB_CLIENT_AVAILABLE" -ne 1 ]]; then
    for migration in "${MIGRATIONS[@]}"; do
        if grep -Eq 'NAMINGO_DB_(PASSWORD|HOST|PORT|USER)|(^|[[:space:]])(mariadb|psql)([[:space:]\\]|$)' "$migration"; then
            die \
"Migration $(basename "$migration") appears to require a database client.

Install '$DB_CLIENT' on this server and run the upgrade again."
        fi
    done
fi

run_migrations() {
    local phase="$1"
    local migration
    local version

    if [[ "${#MIGRATIONS[@]}" -eq 0 ]]; then
        log "No ${phase}-upgrade migrations are required"
        return 0
    fi

    for migration in "${MIGRATIONS[@]}"; do
        version="$(basename "$migration" .sh)"
        log "Running ${phase} migration for v${version}"
        bash "$migration" "$phase"
    done
}

# PRE migrations may install packages or perform preparation work while the
# running installation is still intact.
run_migrations pre

# ---------------------------------------------------------
# Stop active Registry services
# ---------------------------------------------------------

log "Stopping active Registry services"
SERVICES_STOPPED=1

# Stop cron as well: Registry's minute-by-minute automation dispatcher must
# not run while application files or database schemas are being changed.
for service in \
    epp \
    whois \
    das \
    rdap \
    msg_producer \
    msg_worker \
    caddy \
    cron
do
    if systemctl is-active --quiet "$service"; then
        SERVICES_TO_RESTART+=("$service")
        systemctl stop "$service"
    fi
done

# ---------------------------------------------------------
# Copy application files while preserving live configuration
# ---------------------------------------------------------

copy_tree_preserving() {
    local src="$1"
    local dst="$2"
    local preserve_rel="${3:-}"
    local saved_file=""

    [[ -d "$src" ]] || {
        warn "Target release has no source directory: $src"
        return 0
    }

    mkdir -p "$dst"

    if [[ -n "$preserve_rel" && -e "$dst/$preserve_rel" ]]; then
        mkdir -p "${STAGING_DIR}/.preserved/$(dirname "$preserve_rel")"
        saved_file="${STAGING_DIR}/.preserved/preserve-$RANDOM-$(basename "$preserve_rel")"
        cp -a "$dst/$preserve_rel" "$saved_file"
    fi

    # Do not delete destination-only files. Local/custom files, generated
    # runtime files and EPP certificate symlinks are therefore retained.
    cp -a "$src/." "$dst/"

    if [[ -n "$saved_file" && -e "$saved_file" ]]; then
        mkdir -p "$(dirname "$dst/$preserve_rel")"
        cp -a "$saved_file" "$dst/$preserve_rel"
    fi
}

copy_optional_component() {
    local src="$1"
    local dst="$2"
    local preserve_rel="${3:-config.php}"

    [[ -d "$dst" ]] || {
        log "Optional component not installed; skipping $dst"
        return 0
    }

    copy_tree_preserving "$src" "$dst" "$preserve_rel"
}

log "Updating Namingo Registry files"

# upgrade.sh itself may be executing from /opt/registry. Rename the previous
# copy before replacing docs so the running inode is never truncated.
if [[ -f "${INSTALL_DIR}/docs/upgrade.sh" ]]; then
    mv \
        "${INSTALL_DIR}/docs/upgrade.sh" \
        "${INSTALL_DIR}/docs/upgrade.sh.previous"
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

if [[ -d "${STAGING_DIR}/tests" ]]; then
    copy_tree_preserving "${STAGING_DIR}/tests" "${INSTALL_DIR}/tests"
fi

if [[ -f "${STAGING_DIR}/namingo" ]]; then
    cp -a "${STAGING_DIR}/namingo" "${INSTALL_DIR}/namingo"
    chmod 755 "${INSTALL_DIR}/namingo"
fi

if [[ -f "${INSTALL_DIR}/docs/upgrade.sh" ]]; then
    chmod 755 "${INSTALL_DIR}/docs/upgrade.sh"
fi

# ---------------------------------------------------------
# Composer
# ---------------------------------------------------------

composer_sync() {
    local dir="$1"
    local release_dir="$2"

    [[ -f "$dir/composer.json" ]] || return 0

    log "Updating Composer dependencies in $dir"

    (
        cd "$dir"

        if [[ -f "$release_dir/composer.lock" ]]; then
            # If the release ships a lock file, the copied lock is the
            # dependency contract and must be installed exactly.
            COMPOSER_ALLOW_SUPERUSER=1 \
                composer install \
                --no-interaction \
                --quiet \
                --no-progress
        else
            # Registry releases currently do not consistently ship lock files.
            # Keep the legacy updater behaviour and resolve the target
            # composer.json instead of trusting a lock generated by an older
            # installation.
            COMPOSER_ALLOW_SUPERUSER=1 \
                composer update \
                --no-interaction \
                --quiet \
                --no-progress
        fi
    )
}

composer_sync "${INSTALL_DIR}/automation" "${STAGING_DIR}/automation"
composer_sync "$CP_DIR" "${STAGING_DIR}/cp"
[[ ! -d "${INSTALL_DIR}/whois/port43" ]] || composer_sync "${INSTALL_DIR}/whois/port43" "${STAGING_DIR}/whois/port43"
[[ ! -d "${INSTALL_DIR}/das" ]] || composer_sync "${INSTALL_DIR}/das" "${STAGING_DIR}/das"
composer_sync "${INSTALL_DIR}/rdap" "${STAGING_DIR}/rdap"
composer_sync "${INSTALL_DIR}/epp" "${STAGING_DIR}/epp"

# ---------------------------------------------------------
# Adminer
# ---------------------------------------------------------

update_adminer() {
    local caddy_file="/etc/caddy/Caddyfile"
    local adminer_root="/usr/share/adminer"
    local route_name=""
    local adminer_file=""
    local resolved=""
    local tmp_file=""

    [[ -d "$adminer_root" ]] || {
        warn "Adminer directory not found; skipping Adminer update."
        return 0
    }

    if [[ -r "$caddy_file" ]]; then
        route_name="$(
            sed -nE \
                's#^[[:space:]]*route[[:space:]]+/((adminer-[0-9A-Fa-f]+|adminer)\.php)\*?[[:space:]]*\{.*#\1#p' \
                "$caddy_file" \
            | head -n1
        )"
    fi

    if [[ -n "$route_name" ]]; then
        ADMINER_ROUTE_NAME="$route_name"
        adminer_file="${adminer_root}/${route_name}"
    elif [[ -e "${adminer_root}/latest.php" ]]; then
        adminer_file="${adminer_root}/latest.php"
    elif [[ -e "${adminer_root}/adminer.php" ]]; then
        adminer_file="${adminer_root}/adminer.php"
    else
        warn "Unable to locate the installed Adminer file; skipping Adminer update."
        return 0
    fi

    if [[ -L "$adminer_file" ]]; then
        resolved="$(readlink -f "$adminer_file" || true)"
        [[ -n "$resolved" ]] || {
            warn "Unable to resolve Adminer symlink: $adminer_file"
            return 0
        }
        adminer_file="$resolved"
    fi

    log "Updating Adminer ($(basename "$adminer_file"))"

    tmp_file="${adminer_file}.upgrade.$$"
    curl -fsSL "$ADMINER_URL" -o "$tmp_file"
    chmod 0644 "$tmp_file"
    mv -f "$tmp_file" "$adminer_file"
}

update_adminer

# ---------------------------------------------------------
# Clear CP cache without invoking clear_cache.php, which also restarts FPM.
# ---------------------------------------------------------

if [[ -d "$CP_DIR/cache" ]]; then
    log "Clearing control panel cache"
    find "$CP_DIR/cache" -mindepth 1 -maxdepth 1 \
        ! -name '.gitkeep' \
        -exec rm -rf -- {} +
fi

# ---------------------------------------------------------
# POST migrations: schemas, systemd changes, cleanup, etc.
# ---------------------------------------------------------

run_migrations post

# ---------------------------------------------------------
# Reload and restart services
# ---------------------------------------------------------

systemctl daemon-reload

PHP_FPM_SERVICE="php${PHP_VERSION}-fpm"

if systemctl is-active --quiet "$PHP_FPM_SERVICE"; then
    log "Restarting ${PHP_FPM_SERVICE}"
    systemctl restart "$PHP_FPM_SERVICE"
fi

log "Starting services"

# Restart Caddy first if it was previously running, then the remaining
# Registry services in their original set.
if printf '%s\n' "${SERVICES_TO_RESTART[@]}" | grep -qx caddy; then
    systemctl start caddy
fi

for service in "${SERVICES_TO_RESTART[@]}"; do
    [[ "$service" == "caddy" ]] && continue
    systemctl start "$service"
done

SERVICES_STOPPED=0

# ---------------------------------------------------------
# Health checks
# ---------------------------------------------------------

log "Verifying services"

for service in "${SERVICES_TO_RESTART[@]}"; do
    systemctl is-active --quiet "$service" \
        || die "Service failed health check: $service"
done

# ---------------------------------------------------------
# Mark upgrade successful
# ---------------------------------------------------------

# VERSION changes only after backups, files, Composer, migrations and service
# health checks have all completed successfully.
printf '%s\n' "$TARGET_VERSION" > "$VERSION_FILE"

rm -f "${INSTALL_DIR}/docs/upgrade.sh.previous"

SUCCESS=1

echo
echo "============================================================"
echo " Namingo Registry upgrade complete"
echo "============================================================"
echo
echo " Previous version:  v${CURRENT_VERSION}"
echo " Installed version: v${TARGET_VERSION}"
echo " Backup timestamp:  ${BACKUP_STAMP}"
if [[ -n "$ADMINER_ROUTE_NAME" ]]; then
    echo " Adminer route:     /${ADMINER_ROUTE_NAME}"
fi
echo
echo "Backups:"
echo "  ${BACKUP_DIR}/cp_backup_${BACKUP_STAMP}.tar.gz"

if [[ -d "$WHOIS_WEB_DIR" ]]; then
    echo "  ${BACKUP_DIR}/whois_web_backup_${BACKUP_STAMP}.tar.gz"
fi

echo "  ${BACKUP_DIR}/registry_backup_${BACKUP_STAMP}.tar.gz"

if [[ "$DB_DUMP_AVAILABLE" -eq 1 ]]; then
    for backup_db in "${DATABASES[@]}"; do
        echo "  ${BACKUP_DIR}/db_${backup_db}_backup_${BACKUP_STAMP}.sql.gz"
    done
else
    echo "  Database backups: manual/external (confirmed)"
fi

echo