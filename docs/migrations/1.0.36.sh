#!/bin/sh

set -eu

PHASE=${1:-}

log() {
    printf '[migration %s] %s\n' "${NAMINGO_TARGET_VERSION:-1.0.36}" "$*"
}

die() {
    printf 'Migration error: %s\n' "$*" >&2
    exit 1
}

require_command() {
    command -v "$1" >/dev/null 2>&1 \
        || die "Required command not found: $1"
}

db_sql() {
    database=${NAMINGO_DB_MAIN:-${NAMINGO_DB_NAME:-registry}}
    case "$NAMINGO_DB_DRIVER" in
        mariadb)
            require_command mariadb
            MYSQL_PWD=$NAMINGO_DB_PASSWORD mariadb \
                --host="$NAMINGO_DB_HOST" --port="$NAMINGO_DB_PORT" \
                --user="$NAMINGO_DB_USER" --database="$database"
            ;;
        pgsql)
            require_command psql
            PGPASSWORD=$NAMINGO_DB_PASSWORD psql \
                --host="$NAMINGO_DB_HOST" --port="$NAMINGO_DB_PORT" \
                --username="$NAMINGO_DB_USER" --dbname="$database" \
                -X -v ON_ERROR_STOP=1
            ;;
        *) die "Unsupported database driver: $NAMINGO_DB_DRIVER" ;;
    esac
}

upgrade_schema() {
    log "Enabling launchFinal poll messages and registrar API key IDs"
    case "$NAMINGO_DB_DRIVER" in
        mariadb)
            db_sql <<'SQL'
ALTER TABLE poll MODIFY COLUMN msg_type
    ENUM('lowBalance','domainTransfer','contactTransfer','maintenance','launchFinal') DEFAULT NULL;
ALTER TABLE registrar ADD COLUMN IF NOT EXISTS api_key_id CHAR(64) DEFAULT NULL;
SQL
            ;;
        pgsql)
            db_sql <<'SQL'
BEGIN;
ALTER TABLE poll DROP CONSTRAINT IF EXISTS poll_msg_type_check;
ALTER TABLE poll ADD CONSTRAINT poll_msg_type_check CHECK
    (msg_type IN ('lowBalance','domainTransfer','contactTransfer','maintenance','launchFinal'));
ALTER TABLE registrar ADD COLUMN IF NOT EXISTS api_key_id CHAR(64) DEFAULT NULL;
COMMIT;
SQL
            ;;
        *) die "Unsupported database driver: $NAMINGO_DB_DRIVER" ;;
    esac
}

case "$PHASE" in
    pre)
        ;;
    post)
        upgrade_schema
        sh "$(dirname "$0")/audit-sync.sh" all
        ;;
    *)
        echo "Usage: $0 {pre|post}" >&2
        exit 2
        ;;
esac
