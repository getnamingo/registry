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


# Execute SQL against the primary Registry DB. POST runs with services stopped.
# All statements must be safe to rerun after a partially completed upgrade.
db_sql() {
    database=${NAMINGO_DB_MAIN:-${NAMINGO_DB_NAME:-registry}}
    case "$NAMINGO_DB_DRIVER" in
        mariadb)
            require_command mariadb
            MYSQL_PWD=$NAMINGO_DB_PASSWORD mariadb --host="$NAMINGO_DB_HOST" --port="$NAMINGO_DB_PORT" --user="$NAMINGO_DB_USER" --database="$database"
            ;;
        pgsql)
            require_command psql
            PGPASSWORD=$NAMINGO_DB_PASSWORD psql --host="$NAMINGO_DB_HOST" --port="$NAMINGO_DB_PORT" --username="$NAMINGO_DB_USER" --dbname="$database" -X -v ON_ERROR_STOP=1
            ;;
        *) die "Unsupported database driver: $NAMINGO_DB_DRIVER" ;;
    esac
}

# RFC 9167: persistent maintenance notices and registrar poll notifications.
upgrade_maintenance_schema() {
    log "Enabling RFC 9167 maintenance and poll message support"
    case "$NAMINGO_DB_DRIVER" in
        mariadb)
            db_sql <<'SQL'
CREATE TABLE IF NOT EXISTS `maintenance` (
    `id` VARCHAR(64) NOT NULL PRIMARY KEY,
    `item_json` LONGTEXT NOT NULL,
    `deleted` BOOLEAN NOT NULL DEFAULT FALSE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `poll` MODIFY COLUMN `msg_type`
    ENUM('lowBalance','domainTransfer','contactTransfer','maintenance') DEFAULT NULL;
SQL
            ;;
        pgsql)
            db_sql <<'SQL'
BEGIN;
CREATE TABLE IF NOT EXISTS maintenance (
    id VARCHAR(64) PRIMARY KEY,
    item_json TEXT NOT NULL,
    deleted BOOLEAN NOT NULL DEFAULT FALSE
);
ALTER TABLE poll DROP CONSTRAINT IF EXISTS poll_msg_type_check;
ALTER TABLE poll ADD CONSTRAINT poll_msg_type_check CHECK
    (msg_type IN ('lowBalance','domainTransfer','contactTransfer','maintenance'));
COMMIT;
SQL
            ;;
        *) die "Unsupported database driver: $NAMINGO_DB_DRIVER" ;;
    esac
}

# RFC 8495: case-sensitive tokens, expiry/use timestamps and domain lookup.
# Existing NULL expiry values remain NULL; operators must review old tokens.
# Do not infer the TLD of legacy label-only domain_name values.
upgrade_allocation_tokens_schema() {
    log "Updating RFC 8495 allocation token schema"
    case "$NAMINGO_DB_DRIVER" in
        mariadb)
            db_sql <<'SQL'
ALTER TABLE `allocation_tokens`
    MODIFY COLUMN `token` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    ADD COLUMN IF NOT EXISTS `expires_at` DATETIME(3) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `used_at` DATETIME(3) DEFAULT NULL,
    ADD INDEX IF NOT EXISTS `allocation_tokens_domain_name_idx` (`domain_name`);
UPDATE `allocation_tokens` SET `domain_name` = LOWER(`domain_name`)
WHERE `domain_name` IS NOT NULL AND `domain_name` LIKE '%.%';
-- Only print a count: tokens are secrets and must not enter upgrade logs.
SELECT COUNT(*) AS label_only_domain_names_requiring_review
FROM `allocation_tokens`
WHERE `domain_name` IS NOT NULL AND `domain_name` NOT LIKE '%.%';
SQL
            ;;
        pgsql)
            db_sql <<'SQL'
BEGIN;
ALTER TABLE allocation_tokens
    ADD COLUMN IF NOT EXISTS expires_at TIMESTAMP(3),
    ADD COLUMN IF NOT EXISTS used_at TIMESTAMP(3);
CREATE INDEX IF NOT EXISTS allocation_tokens_domain_name_idx
    ON allocation_tokens (domain_name);
UPDATE allocation_tokens SET domain_name = LOWER(domain_name)
WHERE domain_name IS NOT NULL AND domain_name LIKE '%.%';
-- Only print a count: tokens are secrets and must not enter upgrade logs.
SELECT COUNT(*) AS label_only_domain_names_requiring_review
FROM allocation_tokens
WHERE domain_name IS NOT NULL AND domain_name NOT LIKE '%.%';
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
        upgrade_idn_table_schema
        upgrade_maintenance_schema
        upgrade_allocation_tokens_schema
        # Include the v1.0.34 secdns audit change for already-upgraded installs.
        sh "$(dirname "$0")/audit-sync.sh" all
        ;;

    *)
        echo "Usage: $0 {pre|post}" >&2
        exit 2
        ;;
esac
