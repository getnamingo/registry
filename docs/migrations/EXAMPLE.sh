#!/bin/sh

set -eu

# Namingo Registry universal migration template.
#
# This file is deliberately ignored by upgrade.sh and upgrade-freebsd.sh
# because its name is not a semantic version (X.Y.Z.sh).
#
# For a real release:
#
#   cp EXAMPLE.sh 1.0.34.sh
#
# Then remove the example helpers/actions that are not required and add the
# release-specific migration steps.
#
# A migration is called twice:
#
#   ./1.0.34.sh pre
#   ./1.0.34.sh post
#
# PRE runs before Registry services are stopped and before release files are
# copied. Use it for package installation and other preparation work.
#
# POST runs after the target release files and Composer dependencies are in
# place, while Registry services are still stopped. Use it for database schema
# changes, system integration changes, and removal of obsolete files.
#
# IMPORTANT:
#   - Migrations MUST be idempotent. A failed upgrade may run them again.
#   - Do not restart core Registry services here. The upgrader owns their
#     stop/start lifecycle and health checks.
#   - Prefer POSIX /bin/sh so the same migration works on Linux and FreeBSD.
#   - On FreeBSD, POST runs before the upgrader reapplies its FreeBSD-specific
#     compatibility overlay to the freshly copied Registry source.
#
# Common environment variables supplied by both upgraders:
#
#   NAMINGO_FROM_VERSION
#   NAMINGO_TARGET_VERSION
#   NAMINGO_INSTALL_DIR
#   NAMINGO_CP_DIR
#   NAMINGO_WHOIS_WEB_DIR
#   NAMINGO_STAGING_DIR
#   NAMINGO_PHP_VERSION
#
#   NAMINGO_DB_DRIVER          mariadb | pgsql
#   NAMINGO_DB_HOST
#   NAMINGO_DB_PORT
#   NAMINGO_DB_USER
#   NAMINGO_DB_PASSWORD
#   NAMINGO_DB_NAME            primary Registry DB (compatibility alias)
#   NAMINGO_DB_MAIN            registry
#   NAMINGO_DB_TRANSACTION     registryTransaction
#   NAMINGO_DB_AUDIT           registryAudit
#
# FreeBSD additionally exports NAMINGO_OS=freebsd. The Linux upgrader does not
# currently need to export it, so this template deliberately defaults to linux.

PHASE=${1:-}
NAMINGO_OS=${NAMINGO_OS:-linux}

log() {
    printf '[migration %s] %s\n' "${NAMINGO_TARGET_VERSION:-unknown}" "$*"
}

die() {
    printf 'Migration error: %s\n' "$*" >&2
    exit 1
}

require_command() {
    command -v "$1" >/dev/null 2>&1 \
        || die "Required command not found: $1"
}

case "$NAMINGO_OS" in
    linux|freebsd) ;;
    *) die "Unsupported operating system: $NAMINGO_OS" ;;
esac

# ---------------------------------------------------------------------------
# Cross-platform package helper
#
# Example:
#
#   PHP_COMPACT=$(printf '%s' "$NAMINGO_PHP_VERSION" | tr -d '.')
#   install_package \
#       "php${NAMINGO_PHP_VERSION}-apcu" \
#       "php${PHP_COMPACT}-pecl-APCu"
#
# Pass the Debian/Ubuntu package first and the FreeBSD package second.
# ---------------------------------------------------------------------------

install_package() {
    linux_package=$1
    freebsd_package=$2

    case "$NAMINGO_OS" in
        linux)
            require_command dpkg-query
            require_command apt-get

            if dpkg-query -W -f='${Status}' "$linux_package" 2>/dev/null \
                | grep -q 'ok installed'
            then
                log "$linux_package is already installed"
                return 0
            fi

            log "Installing $linux_package"
            apt-get update
            DEBIAN_FRONTEND=noninteractive \
                apt-get install -y --no-install-recommends "$linux_package"
            ;;

        freebsd)
            require_command pkg

            if pkg info -e "$freebsd_package" >/dev/null 2>&1; then
                log "$freebsd_package is already installed"
                return 0
            fi

            log "Installing $freebsd_package"
            env ASSUME_ALWAYS_YES=yes pkg install -y "$freebsd_package"
            ;;
    esac
}

# ---------------------------------------------------------------------------
# Cross-database SQL helper
#
# Usage:
#
#   db_sql "$NAMINGO_DB_MAIN" <<'SQL'
#   ... SQL valid for the active database ...
#   SQL
#
# For SQL whose syntax differs between MariaDB and PostgreSQL, branch on
# NAMINGO_DB_DRIVER as shown in example_schema_change() below.
# ---------------------------------------------------------------------------

db_sql() {
    database=$1

    case "$NAMINGO_DB_DRIVER" in
        mariadb)
            require_command mariadb
            MYSQL_PWD=$NAMINGO_DB_PASSWORD \
                mariadb \
                    --host="$NAMINGO_DB_HOST" \
                    --port="$NAMINGO_DB_PORT" \
                    --user="$NAMINGO_DB_USER" \
                    --database="$database"
            ;;

        pgsql)
            require_command psql
            PGPASSWORD=$NAMINGO_DB_PASSWORD \
                psql \
                    --host="$NAMINGO_DB_HOST" \
                    --port="$NAMINGO_DB_PORT" \
                    --username="$NAMINGO_DB_USER" \
                    --dbname="$database" \
                    -v ON_ERROR_STOP=1
            ;;

        *)
            die "Unsupported database driver: $NAMINGO_DB_DRIVER"
            ;;
    esac
}

# ---------------------------------------------------------------------------
# EXAMPLE: package introduced by a release
#
# This function is NOT called below. Call it from the PRE phase only when the
# release really requires APCu, or replace it with the package you need.
# ---------------------------------------------------------------------------

example_install_apcu() {
    php_compact=$(printf '%s' "$NAMINGO_PHP_VERSION" | tr -d '.')

    install_package \
        "php${NAMINGO_PHP_VERSION}-apcu" \
        "php${php_compact}-pecl-APCu"
}

# ---------------------------------------------------------------------------
# EXAMPLE: idempotent schema change
#
# This function is NOT called below. Replace example_table/example_flag with
# the real schema change, then call it from POST.
# ---------------------------------------------------------------------------

example_schema_change() {
    case "$NAMINGO_DB_DRIVER" in
        mariadb)
            db_sql "$NAMINGO_DB_MAIN" <<'SQL'
ALTER TABLE `example_table`
    ADD COLUMN IF NOT EXISTS `example_flag`
    TINYINT(1) NOT NULL DEFAULT 0;
SQL
            ;;

        pgsql)
            db_sql "$NAMINGO_DB_MAIN" <<'SQL'
ALTER TABLE example_table
    ADD COLUMN IF NOT EXISTS example_flag boolean NOT NULL DEFAULT false;
SQL
            ;;
    esac

    # The same helper can target Registry's other databases when required:
    #
    #   NAMINGO_DB_TRANSACTION
    #   NAMINGO_DB_AUDIT
}

# ---------------------------------------------------------------------------
# EXAMPLE: operating-system-specific integration
#
# Keep OS branching small and only where paths/package/service formats really
# differ. This function is NOT called below.
# ---------------------------------------------------------------------------

example_os_specific_change() {
    case "$NAMINGO_OS" in
        linux)
            # Example only:
            # install -m 0644 \
            #     "$NAMINGO_STAGING_DIR/docs/example.service" \
            #     /etc/systemd/system/example.service
            ;;

        freebsd)
            # Example only:
            # install -m 0555 \
            #     "$NAMINGO_STAGING_DIR/docs/example.rc" \
            #     /usr/local/etc/rc.d/example
            ;;
    esac
}

case "$PHASE" in
    pre)
        # Preparation examples:
        #
        # example_install_apcu
        ;;

    post)
        # Schema/system examples:
        #
        # example_schema_change
        # example_os_specific_change

        # Remove obsolete files explicitly and idempotently when necessary.
        # Never mirror-delete the whole installation tree.
        #
        # rm -f "${NAMINGO_INSTALL_DIR}/automation/old-script.php"
        ;;

    *)
        echo "Usage: $0 {pre|post}" >&2
        exit 2
        ;;
esac