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
        upgrade_zone_writer_config
        ;;
    *)
        echo "Usage: $0 {pre|post}" >&2
        exit 2
        ;;
esac
