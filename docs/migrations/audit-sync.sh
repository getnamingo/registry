#!/bin/sh

set -eu

# Refresh MariaDB PhpAudit tables/triggers without discarding audit history.
# Usage: audit-sync.sh secdns|all
# "all" also backfills the v1.0.34 secdns change on existing v1.0.34 installs.
[ "${NAMINGO_DB_DRIVER:-}" = "mariadb" ] || exit 0
mode=${1:-}
case "$mode" in secdns|all) ;; *) echo "Usage: $0 secdns|all" >&2; exit 2 ;; esac

install_dir=${NAMINGO_INSTALL_DIR:-/opt/registry}
config="$install_dir/automation/audit.json"
audit_bin="$install_dir/automation/vendor/bin/audit"
[ -f "$config" ] || exit 0

command -v php >/dev/null 2>&1 || { echo "PHP required for audit sync" >&2; exit 1; }
command -v mariadb >/dev/null 2>&1 || { echo "MariaDB client required for audit sync" >&2; exit 1; }

# The shipped config contains placeholder credentials. The supplementary
# credentials-file mechanism is also supported by php-audit.
if php -r '
    $c = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    $d = $c["database"] ?? [];
    if (empty($d["credentials"]) &&
        (($d["user"] ?? "") === "your_username" ||
         ($d["password"] ?? "") === "your_password")) exit(42);
' "$config"; then
    :
else
    status=$?
    if [ "$status" -eq 42 ]; then
        echo "[audit sync] Audit config not activated; skipping"
        exit 0
    fi
    echo "Invalid audit.json configuration; refusing to skip active auditing" >&2
    exit "$status"
fi

[ -f "$audit_bin" ] || { echo "Configured audit requires $audit_bin" >&2; exit 1; }

# Only synchronize a deployment with active PhpAudit triggers. Audit tables can
# remain after auditing is disabled; never reactivate auditing merely because
# historical tables exist.
audit_schema=$(php -r '
    $c = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    echo $c["database"]["audit_schema"] ?? "registryAudit";
' "$config")
data_schema=$(php -r '
    $c = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    echo $c["database"]["data_schema"] ?? "registry";
' "$config")
case "$audit_schema" in *[!a-zA-Z0-9_]*|"") echo "Invalid audit schema name" >&2; exit 1 ;; esac
database=${NAMINGO_DB_MAIN:-${NAMINGO_DB_NAME:-registry}}
[ "$data_schema" = "$database" ] || {
    echo "Audit data_schema does not match Registry database; skipping" >&2
    exit 1
}
mariadb_query() {
    MYSQL_PWD=$NAMINGO_DB_PASSWORD mariadb \
        --host="$NAMINGO_DB_HOST" --port="$NAMINGO_DB_PORT" \
        --user="$NAMINGO_DB_USER" --database="$database" \
        --batch --skip-column-names --silent "$@"
}

active=$(mariadb_query -e "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND LOCATE('$audit_schema', ACTION_STATEMENT) > 0;")
if [ "$active" -eq 0 ]; then
    echo "[audit sync] No active audit triggers; skipping"
    exit 0
fi

# Widen only existing audit columns; PhpAudit adds any missing columns itself.
audit_column_type() {
    mariadb_query -e "SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$audit_schema' AND TABLE_NAME='$1' AND COLUMN_NAME='$2';"
}
audit_sql() {
    MYSQL_PWD=$NAMINGO_DB_PASSWORD mariadb \
        --host="$NAMINGO_DB_HOST" --port="$NAMINGO_DB_PORT" \
        --user="$NAMINGO_DB_USER" --database="$audit_schema" \
        --batch --silent -e "$1"
}

echo "[audit sync] Synchronizing existing MariaDB audit columns"
case "$(audit_column_type secdns pubkey)" in
    ""|text|mediumtext|longtext) ;;
    *) audit_sql 'ALTER TABLE `secdns` MODIFY COLUMN `pubkey` TEXT DEFAULT NULL;' ;;
esac

if [ "$mode" = "all" ]; then
    case "$(audit_column_type domain_tld idn_table)" in
        ""|text|mediumtext|longtext) ;;
        *) audit_sql 'ALTER TABLE `domain_tld` MODIFY COLUMN `idn_table` TEXT NOT NULL;' ;;
    esac
    poll_type=$(mariadb_query -e "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$audit_schema' AND TABLE_NAME='poll' AND COLUMN_NAME='msg_type';")
    case "$poll_type" in
        ""|*"\x27maintenance\x27"*) ;;
        *) audit_sql "ALTER TABLE \`poll\` MODIFY COLUMN \`msg_type\` ENUM('lowBalance','domainTransfer','contactTransfer','maintenance') DEFAULT NULL;" ;;
    esac

    # An existing operator's audit.json may predate RFC 9167. Add only the
    # missing table configuration, without changing explicit false/null flags.
    php -r '
        $file = $argv[1];
        $c = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if (!isset($c["tables"]) || !is_array($c["tables"])) {
            throw new RuntimeException("Invalid audit.json tables section");
        }
        if (!array_key_exists("maintenance", $c["tables"])) {
            $c["tables"]["maintenance"] = ["audit" => true, "skip" => null];
            $tmp = tempnam(dirname($file), ".audit-");
            if ($tmp === false) throw new RuntimeException("Cannot create audit.json temp file");
            try {
                $json = json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
                if (file_put_contents($tmp, $json) === false) throw new RuntimeException("Cannot write audit.json");
                chmod($tmp, fileperms($file) & 0777);
                if (!rename($tmp, $file)) throw new RuntimeException("Cannot replace audit.json");
            } finally {
                if (file_exists($tmp)) unlink($tmp);
            }
        }
    ' "$config"
fi

# PhpAudit maintains trigger definitions and creates missing audit tables.
# It does NOT automatically widen existing audit columns (handled above).
"$audit_bin" -v audit "$config"
