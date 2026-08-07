#!/bin/bash
set -e

# Ensure the script is run as root
if [[ $EUID -ne 0 ]]; then
    echo "Error: This update script must be run as root or with sudo." >&2
    exit 1
fi

# Prompt the user for confirmation
echo "This will update Namingo Registry from v1.0.29 to v1.0.30."
echo "Make sure you have a backup of the database, /var/www/cp, and /opt/registry."
read -p "Are you sure you want to proceed? (y/n): " confirm

# Check user input
if [[ "$confirm" != "y" ]]; then
    echo "Upgrade aborted."
    exit 0
fi

# Create backup directory
backup_dir="/opt/backup"
mkdir -p "$backup_dir"

# Backup directories
echo "Creating backups..."
tar -czf "$backup_dir/cp_backup_$(date +%F).tar.gz" -C / var/www/cp
tar -czf "$backup_dir/whois_backup_$(date +%F).tar.gz" -C / var/www/whois
tar -czf "$backup_dir/registry_backup_$(date +%F).tar.gz" -C / opt/registry

# Database credentials
config_file="/opt/registry/rdap/config.php"
db_driver=$(grep "'db_type'" "$config_file" | awk -F "=> '" '{print $2}' | sed "s/',//")
db_name=$(grep "'db_database'" "$config_file" | awk -F "=> '" '{print $2}' | sed "s/',//")
db_user=$(grep "'db_username'" "$config_file" | awk -F "=> '" '{print $2}' | sed "s/',//")
db_pass=$(grep "'db_password'" "$config_file" | awk -F "=> '" '{print $2}' | sed "s/',//")
db_host=$(grep "'db_host'" "$config_file" | awk -F "=> '" '{print $2}' | sed "s/',//")

# Automatic database backup is currently supported only for MariaDB/MySQL
case "$db_driver" in
    mysql|mariadb|"")
        databases=("registry" "registryAudit" "registryTransaction")

        for backup_db in "${databases[@]}"; do
            echo "Backing up database $backup_db..."
            sql_backup_file="$backup_dir/db_${backup_db}_backup_$(date +%F).sql"

            mariadb-dump -u"$db_user" -p"$db_pass" -h"$db_host" "$backup_db" > "$sql_backup_file"

            echo "Compressing database backup $backup_db..."
            tar -czf "${sql_backup_file}.tar.gz" -C "$backup_dir" "$(basename "$sql_backup_file")"
            rm "$sql_backup_file"
        done
        ;;

    *)
        echo "WARNING: Automatic database backup is not supported for database type '$db_driver'."
        echo "No database backup was created by this update script."
        ;;
esac

# Idempotent database migration
echo "Updating database schema..."

case "$db_driver" in
    mysql|mariadb|"")
        db_cmd=(mariadb -u"$db_user" -p"$db_pass" -h"$db_host" "$db_name")

        for definition in \
            "gateway VARCHAR(32) DEFAULT NULL AFTER amount" \
            "gateway_reference VARCHAR(128) DEFAULT NULL AFTER gateway"
        do
            column=${definition%% *}
            exists=$("${db_cmd[@]}" -Nse \
                "SELECT EXISTS(
                    SELECT 1 FROM information_schema.columns
                    WHERE table_schema = DATABASE()
                      AND table_name = 'payment_history'
                      AND column_name = '$column'
                )")
            [[ "$exists" == "1" ]] ||
                "${db_cmd[@]}" -e "ALTER TABLE payment_history ADD COLUMN $definition"
        done

        exists=$("${db_cmd[@]}" -Nse \
            "SELECT EXISTS(
                SELECT 1 FROM information_schema.statistics
                WHERE table_schema = DATABASE()
                  AND table_name = 'payment_history'
                  AND index_name = 'transactions_gateway_reference_unique'
            )")
        [[ "$exists" == "1" ]] ||
            "${db_cmd[@]}" -e \
                "ALTER TABLE payment_history ADD UNIQUE KEY transactions_gateway_reference_unique (gateway, gateway_reference)"
                
        validation_log_type=$("${db_cmd[@]}" -Nse \
            "SELECT DATA_TYPE
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'contact'
               AND column_name = 'validation_log'")

        [[ "$validation_log_type" == "text" ]] ||
            "${db_cmd[@]}" -e \
                "ALTER TABLE contact MODIFY COLUMN validation_log TEXT NULL"
                
        "${db_cmd[@]}" -e "
            INSERT IGNORE INTO settings (name, value)
            VALUES ('allocationTokens', NULL)"
        ;;

    pgsql)
        PGPASSWORD="$db_pass" psql -X -v ON_ERROR_STOP=1 \
            -h "$db_host" -U "$db_user" -d "$db_name" -c '
                ALTER TABLE payment_history
                    ADD COLUMN IF NOT EXISTS gateway VARCHAR(32) DEFAULT NULL;
                ALTER TABLE payment_history
                    ADD COLUMN IF NOT EXISTS gateway_reference VARCHAR(128) DEFAULT NULL;
                CREATE UNIQUE INDEX IF NOT EXISTS transactions_gateway_reference_unique
                    ON payment_history (gateway, gateway_reference);
                ALTER TABLE contact
                    ALTER COLUMN validation_log TYPE TEXT;

                INSERT INTO settings (name, value)
                VALUES ('"'"'allocationTokens'"'"', NULL)
                ON CONFLICT (name) DO NOTHING;
            '
        ;;
esac

# Stop services
echo "Stopping services..."
systemctl stop caddy
systemctl stop epp
systemctl is-active --quiet whois.service && systemctl stop whois.service
systemctl is-active --quiet das.service   && systemctl stop das.service
systemctl stop rdap
systemctl stop msg_producer
systemctl stop msg_worker

# Add payment gateway configuration
grep -q '^ENABLED_GATEWAYS=' /var/www/cp/.env || cat >> /var/www/cp/.env <<'EOF'

ENABLED_GATEWAYS=stripe

LIQPAY_PUBLIC_KEY='liqpay-public-key'
LIQPAY_PRIVATE_KEY='liqpay-private-key'

PLATA_TOKEN='plata-token'
EOF

# Clear cache
echo "Clearing cache..."
php /var/www/cp/bin/clear_cache.php

# Clone the new version of the repository
echo "Cloning v1.0.30 from the repository..."
git clone --branch v1.0.30 --single-branch https://github.com/getnamingo/registry /opt/registry1030

# Copy files from the new version to the appropriate directories
echo "Copying files..."

# Function to copy files and maintain directory structure
copy_files() {
    src_dir=$1
    dest_dir=$2

    if [[ -d "$src_dir" ]]; then
        echo "Copying from $src_dir to $dest_dir..."
        cp -R "$src_dir/." "$dest_dir/"
    else
        echo "Source directory $src_dir does not exist. Skipping..."
    fi
}

# Copy specific directories
copy_files "/opt/registry1030/automation" "/opt/registry/automation"
copy_files "/opt/registry1030/cp" "/var/www/cp"
copy_files "/opt/registry1030/whois/web" "/var/www/whois"
[ -d "/opt/registry/das" ] && copy_files "/opt/registry1030/das" "/opt/registry/das"
[ -d "/opt/registry/whois/port43" ] && copy_files "/opt/registry1030/whois/port43" "/opt/registry/whois/port43"
copy_files "/opt/registry1030/rdap" "/opt/registry/rdap"
copy_files "/opt/registry1030/epp" "/opt/registry/epp"
copy_files "/opt/registry1030/docs" "/opt/registry/docs"

# Run composer update in copied directories (excluding docs)
echo "Running composer update..."

composer_update() {
    dir=$1
    if [[ -d "$dir" ]]; then
        echo "Updating composer in $dir..."
        cd "$dir" || exit
        COMPOSER_ALLOW_SUPERUSER=1 composer update --no-interaction --quiet
    else
        echo "Directory $dir does not exist. Skipping composer update..."
    fi
}

# Check the Linux distribution and version
if [[ -e /etc/os-release ]]; then
    . /etc/os-release
    OS=$NAME
    VER=$VERSION_ID
fi

# Upgrade PHP
apt update && dpkg-query -W -f='${binary:Package} ${db:Status-Status}\n' 'php8.3*' 2>/dev/null | awk '$2=="installed"{gsub(/8\.3/,"8.5",$1); print $1}' | xargs -r apt install -y
update-alternatives --set php /usr/bin/php8.5
grep -RIlZ 'php8\.3-fpm\.sock' /etc/caddy | xargs -0r sed -i 's/php8\.3-fpm\.sock/php8.5-fpm.sock/g' && caddy validate --config /etc/caddy/Caddyfile

echo "Restarting PHP and Caddy services..."
systemctl restart php8.5-fpm caddy

wget "http://www.adminer.org/latest.php" -O /usr/share/adminer/latest.php

# Update composer in relevant directories
composer_update "/opt/registry/automation"
composer_update "/var/www/cp"
[ -d "/opt/registry/das" ] && composer_update "/opt/registry/das"
[ -d "/opt/registry/whois/port43" ] && composer_update "/opt/registry/whois/port43"
composer_update "/opt/registry/rdap"
composer_update "/opt/registry/epp"

# Reload cache
echo "Reloading cache..."
php /var/www/cp/bin/file_cache.php

# Start services
echo "Starting services..."
systemctl start epp
systemctl cat whois.service >/dev/null 2>&1 && systemctl start whois.service
systemctl cat das.service   >/dev/null 2>&1 && systemctl start das.service
systemctl start rdap
systemctl start caddy
systemctl start msg_producer
systemctl start msg_worker

# Check if services started successfully
if [[ $? -eq 0 ]]; then
    echo "Services started successfully. Deleting /opt/registry1030..."
    rm -rf /opt/registry1030
else
    echo "There was an issue starting the services. /opt/registry1030 will not be deleted."
fi

echo "Upgrade to v1.0.30 completed successfully."