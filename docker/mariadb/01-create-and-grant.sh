#!/bin/sh
set -eu

case "${MARIADB_USER:-}" in
    ''|*[!A-Za-z0-9_]*)
        echo "MARIADB_USER may contain only letters, digits, and underscores." >&2
        exit 1
        ;;
    root|mysql)
        echo "MARIADB_USER uses a reserved MariaDB account name." >&2
        exit 1
        ;;
esac

if [ "${#MARIADB_USER}" -gt 32 ]; then
    echo "MARIADB_USER may not exceed 32 characters." >&2
    exit 1
fi

root_password=$(cat "${MARIADB_ROOT_PASSWORD_FILE:?}")

mariadb --protocol=socket -uroot --password="$root_password" <<SQL
CREATE DATABASE IF NOT EXISTS registry
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS registryTransaction
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS registryAudit
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON registry.* TO '${MARIADB_USER}'@'%';
GRANT ALL PRIVILEGES ON registryTransaction.* TO '${MARIADB_USER}'@'%';
GRANT ALL PRIVILEGES ON registryAudit.* TO '${MARIADB_USER}'@'%';
FLUSH PRIVILEGES;
SQL
