#!/bin/sh

set -eu

PHASE=${1:-}

log() {
    printf '[migration %s] %s\n' "${NAMINGO_TARGET_VERSION:-1.0.35}" "$*"
}

die() {
    printf 'Migration error: %s\n' "$*" >&2
    exit 1
}

require_command() {
    command -v "$1" >/dev/null 2>&1 \
        || die "Required command not found: $1"
}

# Idempotent widening of domain_tld.idn_table so longer IDN tables, such as the
# Devanagari table, fit into the column (it was limited to 255 characters).
upgrade_idn_table_schema() {
    database=${NAMINGO_DB_MAIN:-${NAMINGO_DB_NAME:-registry}}
    log "Widening domain_tld.idn_table"
    case "$NAMINGO_DB_DRIVER" in
        mariadb)
            require_command mariadb
            MYSQL_PWD=$NAMINGO_DB_PASSWORD mariadb \
                --host="$NAMINGO_DB_HOST" --port="$NAMINGO_DB_PORT" \
                --user="$NAMINGO_DB_USER" --database="$database" <<'SQL'
ALTER TABLE domain_tld MODIFY COLUMN idn_table TEXT NOT NULL;
SQL
            ;;
        pgsql)
            require_command psql
            PGPASSWORD=$NAMINGO_DB_PASSWORD psql \
                --host="$NAMINGO_DB_HOST" --port="$NAMINGO_DB_PORT" \
                --username="$NAMINGO_DB_USER" --dbname="$database" \
                -v ON_ERROR_STOP=1 <<'SQL'
ALTER TABLE domain_tld ALTER COLUMN idn_table TYPE text;
SQL
            ;;
        *) die "Unsupported database driver: $NAMINGO_DB_DRIVER" ;;
    esac
}

case "$PHASE" in
    pre)
        ;;

    post)
        upgrade_idn_table_schema
        ;;

    *)
        echo "Usage: $0 {pre|post}" >&2
        exit 2
        ;;
esac
