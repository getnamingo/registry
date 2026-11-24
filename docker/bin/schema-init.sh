#!/usr/bin/env bash
set -Eeuo pipefail

host=${NAMINGO_DB_HOST:-database}
port=${NAMINGO_DB_PORT:-3306}
user=${NAMINGO_DB_USER:-namingo}

root_password_file=${NAMINGO_DB_ROOT_PASSWORD_FILE:-/run/secrets/db_root_password}
db_password_file=${NAMINGO_DB_PASSWORD_FILE:-/run/secrets/db_password}
schema_file=${NAMINGO_DB_SCHEMA_FILE:-/opt/registry/database/registry.mariadb.sql}

die() {
    printf 'Error: %s\n' "$*" >&2
    exit 1
}

[[ "$user" =~ ^[A-Za-z0-9_]+$ ]] || die "Invalid database username."
[[ ${#user} -le 32 ]] || die "Database username is too long."

[[ -r "$root_password_file" ]] || die "Root password secret is not readable."
[[ -r "$db_password_file" ]] || die "Database password secret is not readable."
[[ -r "$schema_file" ]] || die "Database schema file is not readable."

root_password=$(tr -d '\r\n' < "$root_password_file")
db_password=$(tr -d '\r\n' < "$db_password_file")

# Escape single quotes for SQL strings.
db_password_sql=${db_password//\'/\'\'}

mysql=(
    mariadb
    --protocol=tcp
    --host="$host"
    --port="$port"
    --user=root
    "--password=$root_password"
)

echo "Checking MariaDB..."

ready=false

for attempt in {1..30}; do
    if "${mysql[@]}" -e "SELECT 1" >/dev/null 2>&1; then
        ready=true
        break
    fi

    sleep 2
done

[[ "$ready" == true ]] || die "MariaDB did not become available."

echo "Ensuring Namingo databases and grants..."

"${mysql[@]}" <<SQL
CREATE DATABASE IF NOT EXISTS registry
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE DATABASE IF NOT EXISTS registryTransaction
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE DATABASE IF NOT EXISTS registryAudit
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS '${user}'@'%'
    IDENTIFIED BY '${db_password_sql}';

ALTER USER '${user}'@'%'
    IDENTIFIED BY '${db_password_sql}';

GRANT ALL PRIVILEGES ON registry.* TO '${user}'@'%';
GRANT ALL PRIVILEGES ON registryTransaction.* TO '${user}'@'%';
GRANT ALL PRIVILEGES ON registryAudit.* TO '${user}'@'%';

FLUSH PRIVILEGES;
SQL

schema_exists=$(
    "${mysql[@]}" -Nse "
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = 'registry'
          AND table_name = 'users';
    "
)

# Also run for pre-existing volumes: Docker upgrades can skip a versioned
# migration when the installed version already matches the image.
ensure_onboarding_schema() {
    "${mysql[@]}" registry <<'SQL'
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
}

if [[ "$schema_exists" == "1" ]]; then
    ensure_onboarding_schema
    echo "Namingo database schema already exists."
    exit 0
fi

echo "Namingo schema is missing. Importing database schema..."

"${mysql[@]}" < "$schema_file"

schema_exists=$(
    "${mysql[@]}" -Nse "
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = 'registry'
          AND table_name = 'users';
    "
)

[[ "$schema_exists" == "1" ]] \
    || die "Schema import completed but registry.users is still missing."

ensure_onboarding_schema

echo "Namingo database schema initialized successfully."
