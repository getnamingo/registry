#!/bin/sh

set -eu

PHASE=${1:-}

log() {
    printf '[migration %s] %s\n' "${NAMINGO_TARGET_VERSION:-1.0.37}" "$*"
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

upgrade_ticket_assignment() {
    log "Adding internal support-ticket assignment"
    db_sql <<'SQL'
ALTER TABLE support_tickets ADD COLUMN IF NOT EXISTS assigned_to INT DEFAULT NULL;
SQL
}

upgrade_registrar_onboarding() {
    log "Creating registrar onboarding application table"
    case "$NAMINGO_DB_DRIVER" in
        mariadb)
            db_sql <<'SQL'
CREATE TABLE IF NOT EXISTS `registrar_onboarding` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `public_token` CHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    `application_data` LONGTEXT NOT NULL,
    `agreement_html` LONGTEXT NOT NULL,
    `status` VARCHAR(24) NOT NULL DEFAULT 'pendingAgreement',
    `signature` LONGTEXT DEFAULT NULL,
    `signed_ip` VARCHAR(45) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `signed_at` DATETIME DEFAULT NULL,
    `registrar_id` INT UNSIGNED DEFAULT NULL,
    INDEX `status_idx` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
            ;;
        pgsql)
            db_sql <<'SQL'
CREATE TABLE IF NOT EXISTS registrar_onboarding (
    id BIGSERIAL PRIMARY KEY,
    public_token CHAR(48) NOT NULL UNIQUE,
    application_data TEXT NOT NULL,
    agreement_html TEXT NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'pendingAgreement',
    signature TEXT DEFAULT NULL,
    signed_ip VARCHAR(45) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    signed_at TIMESTAMP DEFAULT NULL,
    registrar_id INTEGER DEFAULT NULL
);
CREATE INDEX IF NOT EXISTS registrar_onboarding_status_idx ON registrar_onboarding (status);
SQL
            ;;
        *) die "Unsupported database driver: $NAMINGO_DB_DRIVER" ;;
    esac
}

upgrade_balance_mapping() {
    log "Installing transactional balance poll triggers"
    case "$NAMINGO_DB_DRIVER" in
        mariadb)
            db_sql <<'SQL'
DROP TRIGGER IF EXISTS `registry`.`registrar_balance_low_insert`;
DROP TRIGGER IF EXISTS `registry`.`registrar_balance_low_update`;
DELIMITER //
CREATE TRIGGER `registry`.`registrar_balance_low_insert`
AFTER INSERT ON `registry`.`registrar` FOR EACH ROW
BEGIN
    DECLARE new_threshold DECIMAL(24,2);
    SET new_threshold = IF(NEW.thresholdType = 'percent',
        ROUND(NEW.creditLimit * NEW.creditThreshold / 100, 2), NEW.creditThreshold);
    IF NEW.accountBalance <= new_threshold THEN
        INSERT INTO `registry`.`poll` (registrar_id, qdate, msg_type, msg)
        VALUES (NEW.id, UTC_TIMESTAMP(3), 'lowBalance',
            JSON_OBJECT(
                'mapping', 'balance-0.4',
                'currency', NEW.currency,
                'balanceAvailable', CAST(NEW.accountBalance + NEW.creditLimit AS CHAR),
                'creditLine', CAST(NEW.creditLimit AS CHAR),
                'cashBalance', CAST(NEW.accountBalance AS CHAR),
                'executionLimit', CAST(-NEW.creditLimit AS CHAR),
                'notificationThreshold', CAST(new_threshold AS CHAR)
            ));
    END IF;
END//

CREATE TRIGGER `registry`.`registrar_balance_low_update`
AFTER UPDATE ON `registry`.`registrar` FOR EACH ROW
BEGIN
    DECLARE old_threshold DECIMAL(24,2);
    DECLARE new_threshold DECIMAL(24,2);
    SET old_threshold = IF(OLD.thresholdType = 'percent',
        ROUND(OLD.creditLimit * OLD.creditThreshold / 100, 2), OLD.creditThreshold);
    SET new_threshold = IF(NEW.thresholdType = 'percent',
        ROUND(NEW.creditLimit * NEW.creditThreshold / 100, 2), NEW.creditThreshold);
    IF OLD.accountBalance > old_threshold AND NEW.accountBalance <= new_threshold THEN
        INSERT INTO `registry`.`poll` (registrar_id, qdate, msg_type, msg)
        VALUES (NEW.id, UTC_TIMESTAMP(3), 'lowBalance',
            JSON_OBJECT(
                'mapping', 'balance-0.4',
                'currency', NEW.currency,
                'balanceAvailable', CAST(NEW.accountBalance + NEW.creditLimit AS CHAR),
                'creditLine', CAST(NEW.creditLimit AS CHAR),
                'cashBalance', CAST(NEW.accountBalance AS CHAR),
                'executionLimit', CAST(-NEW.creditLimit AS CHAR),
                'notificationThreshold', CAST(new_threshold AS CHAR)
            ));
    END IF;
END//
DELIMITER ;
SQL
            ;;
        pgsql)
            db_sql <<'SQL'
CREATE OR REPLACE FUNCTION registrar_balance_low_poll() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    old_threshold numeric;
    new_threshold numeric;
BEGIN
    new_threshold := CASE WHEN NEW.thresholdtype = 'percent'
        THEN ROUND(NEW.creditlimit * NEW.creditthreshold / 100, 2)
        ELSE NEW.creditthreshold END;

    IF TG_OP = 'UPDATE' THEN
        old_threshold := CASE WHEN OLD.thresholdtype = 'percent'
            THEN ROUND(OLD.creditlimit * OLD.creditthreshold / 100, 2)
            ELSE OLD.creditthreshold END;
        IF NOT (OLD.accountbalance > old_threshold AND NEW.accountbalance <= new_threshold) THEN
            RETURN NEW;
        END IF;
    ELSIF NEW.accountbalance > new_threshold THEN
        RETURN NEW;
    END IF;

    INSERT INTO poll (registrar_id, qdate, msg_type, msg)
    VALUES (NEW.id, timezone('UTC', clock_timestamp()), 'lowBalance',
        json_build_object(
            'mapping', 'balance-0.4',
            'currency', NEW.currency,
            'balanceAvailable', (NEW.accountbalance + NEW.creditlimit)::text,
            'creditLine', NEW.creditlimit::text,
            'cashBalance', NEW.accountbalance::text,
            'executionLimit', (-NEW.creditlimit)::text,
            'notificationThreshold', new_threshold::text
        )::text);
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS registrar_balance_low_insert ON registrar;
CREATE TRIGGER registrar_balance_low_insert
AFTER INSERT ON registrar FOR EACH ROW
EXECUTE FUNCTION registrar_balance_low_poll();

DROP TRIGGER IF EXISTS registrar_balance_low_update ON registrar;
CREATE TRIGGER registrar_balance_low_update
AFTER UPDATE OF accountbalance, creditlimit, creditthreshold, thresholdtype
ON registrar FOR EACH ROW
EXECUTE FUNCTION registrar_balance_low_poll();
SQL
            ;;
        *) die "Unsupported database driver: $NAMINGO_DB_DRIVER" ;;
    esac
}

upgrade_zone_writer_config() {
    if [ "${NAMINGO_DEPLOYMENT:-}" = docker ]; then
        log "Skipping host-only DNS configuration migration in Docker"
        return 0
    fi

    config_file="${NAMINGO_INSTALL_DIR:-/opt/registry}/automation/config.php"
    [ -f "$config_file" ] || die "Automation configuration not found: $config_file"

    php_bin=$(command -v "php${NAMINGO_PHP_VERSION:-8.3}" 2>/dev/null || command -v php 2>/dev/null || true)
    [ -n "$php_bin" ] || die "PHP executable not found"

    log "Adding optional per-zone DNS configuration"
    "$php_bin" /dev/stdin "$config_file" <<'PHP'
<?php
$path = $argv[1];
$config = file_get_contents($path);
if ($config === false) {
    throw new RuntimeException('Could not read automation configuration');
}
if (preg_match('/^[\t ]*[\'"]dns_zones[\'"][\t ]*=>/m', $config)) {
    echo "dns_zones already configured; leaving it unchanged\n";
    exit(0);
}

$block = <<<'CONFIG'
    // Optional per-zone overrides, keyed by lowercase zone name without a leading/trailing dot.
    // Omitted settings retain the global NS/SOA settings and existing TTL/timer defaults.
    'dns_zones' => [
        // 'example' => [
        //     'ns' => ['ns1' => 'ns1.example.net', 'ns2' => 'ns2.example.net'],
        //     'soa' => [
        //         'mname' => 'primary.example.net', // Defaults to this zone's ns1 (or first NS)
        //         'rname' => 'hostmaster.example.net', // DNS mailbox notation, not user@host
        //         'refresh' => 900,
        //         'retry' => 1800,
        //         'expire' => 3600000,
        //         'minimum' => 3600,
        //     ],
        //     'ttl' => 3600,
        // ],
    ],
CONFIG;

$updated = preg_replace_callback(
    '/^([\t ]*[\'"]dns_reload[\'"][\t ]*=>[\t ]*(?:true|false)[\t ]*,[^\r\n]*)(\r?\n)(?:[\t ]*\r?\n)*/mi',
    static fn (array $match): string => $match[1] . $match[2]
        . str_replace("\n", $match[2], $block) . $match[2] . $match[2],
    $config,
    1,
    $count
);
if ($updated === null || $count !== 1) {
    throw new RuntimeException('Could not locate dns_reload in automation configuration');
}
// Check syntax before modifying the installed configuration.
token_get_all($updated, TOKEN_PARSE);
if (file_put_contents($path, $updated, LOCK_EX) !== strlen($updated)) {
    throw new RuntimeException('Could not update automation configuration');
}
PHP
}

case "$PHASE" in
    pre)
        ;;
    post)
        upgrade_ticket_assignment
        upgrade_registrar_onboarding
        upgrade_balance_mapping
        upgrade_zone_writer_config
        ;;
    *)
        echo "Usage: $0 {pre|post}" >&2
        exit 2
        ;;
esac
