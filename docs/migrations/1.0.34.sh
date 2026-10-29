#!/bin/sh

set -eu

PHASE=${1:-}
NAMINGO_OS=${NAMINGO_OS:-linux}

log() {
    printf '[migration %s] %s\n' "${NAMINGO_TARGET_VERSION:-1.0.34}" "$*"
}

die() {
    printf 'Migration error: %s\n' "$*" >&2
    exit 1
}

require_command() {
    command -v "$1" >/dev/null 2>&1 \
        || die "Required command not found: $1"
}

install_phpbu() {
    require_command curl
    require_command install

    tmp_file="${TMPDIR:-/tmp}/phpbu.phar.$$"
    trap 'rm -f "$tmp_file"' EXIT HUP INT TERM

    log "Installing phpBU"

    curl -fsSL \
        https://github.com/sebastianfeldmann/phpbu/releases/latest/download/phpbu.phar \
        -o "$tmp_file"

    install -m 0755 "$tmp_file" /usr/local/bin/phpbu
    rm -f "$tmp_file"
    trap - EXIT HUP INT TERM

    case "$NAMINGO_OS" in
        linux)
            php_bin=$(command -v "php${NAMINGO_PHP_VERSION}" 2>/dev/null || command -v php 2>/dev/null || true)
            ;;
        freebsd)
            php_bin=/usr/local/bin/php
            ;;
        *)
            die "Unsupported operating system: $NAMINGO_OS"
            ;;
    esac

    [ -n "${php_bin:-}" ] && [ -x "$php_bin" ] \
        || die "PHP executable not found"

    "$php_bin" /usr/local/bin/phpbu --version >/dev/null \
        || die "phpBU installation verification failed"

    log "phpBU installed successfully"
}

# Idempotent widening for ML-DSA-44 Base64 keys and unsigned DNSSEC key tags.
upgrade_dnssec_schema() {
    database=${NAMINGO_DB_MAIN:-${NAMINGO_DB_NAME:-registry}}
    case "$NAMINGO_DB_DRIVER" in
        mariadb)
            require_command mariadb
            MYSQL_PWD=$NAMINGO_DB_PASSWORD mariadb \
                --host="$NAMINGO_DB_HOST" --port="$NAMINGO_DB_PORT" \
                --user="$NAMINGO_DB_USER" --database="$database" <<'SQL'
ALTER TABLE secdns MODIFY COLUMN pubkey TEXT DEFAULT NULL;
SQL
            ;;
        pgsql)
            require_command psql
            PGPASSWORD=$NAMINGO_DB_PASSWORD psql \
                --host="$NAMINGO_DB_HOST" --port="$NAMINGO_DB_PORT" \
                --username="$NAMINGO_DB_USER" --dbname="$database" \
                -v ON_ERROR_STOP=1 <<'SQL'
ALTER TABLE secdns ALTER COLUMN pubkey TYPE text;
ALTER TABLE secdns ALTER COLUMN keytag TYPE integer;
SQL
            ;;
        *) die "Unsupported database driver: $NAMINGO_DB_DRIVER" ;;
    esac
}

case "$PHASE" in
    pre)
        install_phpbu
        ;;

    post)
        upgrade_dnssec_schema
        # Synchronize existing MariaDB audit tables after the schema change.
        sh "$(dirname "$0")/audit-sync.sh" secdns
        ;;

    *)
        echo "Usage: $0 {pre|post}" >&2
        exit 2
        ;;
esac
