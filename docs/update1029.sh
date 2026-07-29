#!/bin/bash

# Ensure the script is run as root
if [[ $EUID -ne 0 ]]; then
    echo "Error: This update script must be run as root or with sudo." >&2
    exit 1
fi

# Prompt the user for confirmation
echo "This will update Namingo Registry from v1.0.28 to v1.0.29."
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
config_file="/opt/registry/whois/port43/config.php"
db_user=$(grep "'db_username'" "$config_file" | awk -F "=> '" '{print $2}' | sed "s/',//")
db_pass=$(grep "'db_password'" "$config_file" | awk -F "=> '" '{print $2}' | sed "s/',//")
db_host=$(grep "'db_host'" "$config_file" | awk -F "=> '" '{print $2}' | sed "s/',//")

# List of databases to back up
databases=("registry" "registryAudit" "registryTransaction")

# Backup specific databases
for db_name in "${databases[@]}"; do
    echo "Backing up database $db_name..."
    sql_backup_file="$backup_dir/db_${db_name}_backup_$(date +%F).sql"
    mariadb-dump -u"$db_user" -p"$db_pass" -h"$db_host" "$db_name" > "$sql_backup_file"
    
    # Compress the SQL backup file
    echo "Compressing database backup $db_name..."
    tar -czf "${sql_backup_file}.tar.gz" -C "$backup_dir" "$(basename "$sql_backup_file")"
    
    # Remove the uncompressed SQL file
    rm "$sql_backup_file"
done

# Stop services
echo "Stopping services..."
systemctl stop caddy
systemctl stop epp
systemctl stop whois
systemctl stop rdap
systemctl stop das
systemctl stop msg_producer
systemctl stop msg_worker

# Apply database changes required by v1.0.29
echo "Updating users_webauthn database structure..."

# Widen credential_id
mariadb -u"$db_user" -p"$db_pass" -h"$db_host" -e "
    ALTER TABLE \`registry\`.\`users_webauthn\`
    MODIFY COLUMN \`credential_id\` VARBINARY(1364) NOT NULL;
"

if [[ $? -ne 0 ]]; then
    echo "Error: Failed to modify users_webauthn. Upgrade aborted." >&2
    exit 1
fi

# Add the unique index only when it does not already exist
credential_index_exists=$(
    mariadb -N -s -u"$db_user" -p"$db_pass" -h"$db_host" -e "
        SELECT COUNT(*)
        FROM information_schema.statistics
        WHERE table_schema = 'registry'
          AND table_name = 'users_webauthn'
          AND index_name = 'credential_id';
    "
)

if [[ "$credential_index_exists" -eq 0 ]]; then
    echo "Adding unique credential_id index..."

    mariadb -u"$db_user" -p"$db_pass" -h"$db_host" -e "
        ALTER TABLE \`registry\`.\`users_webauthn\`
        ADD UNIQUE KEY \`credential_id\` (\`credential_id\`);
    "

    if [[ $? -ne 0 ]]; then
        echo "Error: Failed to add unique credential_id index. Upgrade aborted." >&2
        exit 1
    fi
else
    echo "Unique credential_id index already exists. Skipping..."
fi

# Clear cache
echo "Clearing cache..."
php /var/www/cp/bin/clear_cache.php

# Clone the new version of the repository
echo "Cloning v1.0.29 from the repository..."
git clone --branch v1.0.29 --single-branch https://github.com/getnamingo/registry /opt/registry1029

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
copy_files "/opt/registry1029/automation" "/opt/registry/automation"
copy_files "/opt/registry1029/cp" "/var/www/cp"
copy_files "/opt/registry1029/whois/web" "/var/www/whois"
copy_files "/opt/registry1029/das" "/opt/registry/das"
copy_files "/opt/registry1029/whois/port43" "/opt/registry/whois/port43"
copy_files "/opt/registry1029/rdap" "/opt/registry/rdap"
copy_files "/opt/registry1029/epp" "/opt/registry/epp"
copy_files "/opt/registry1029/docs" "/opt/registry/docs"

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

# Determine PHP configuration files based on OS and version
if [[ "$OS" == "Ubuntu" && "$VER" == "24.04" ]]; then
    PHP_VERSION="php8.3"
else
    PHP_VERSION="php8.2"
fi

# Restart PHP-FPM service
echo "Restarting PHP FPM service..."
systemctl restart ${PHP_VERSION}-fpm

wget "http://www.adminer.org/latest.php" -O /usr/share/adminer/latest.php

# Update composer in relevant directories
composer_update "/opt/registry/automation"
composer_update "/var/www/cp"
composer_update "/opt/registry/das"
composer_update "/opt/registry/whois/port43"
composer_update "/opt/registry/rdap"
composer_update "/opt/registry/epp"

# Reload cache
echo "Reloading cache..."
php /var/www/cp/bin/file_cache.php

# Start services
echo "Starting services..."
systemctl start epp
systemctl start whois
systemctl start rdap
systemctl start das
systemctl start caddy
systemctl start msg_producer
systemctl start msg_worker

# Check if services started successfully
if [[ $? -eq 0 ]]; then
    echo "Services started successfully. Deleting /opt/registry1029..."
    rm -rf /opt/registry1029
else
    echo "There was an issue starting the services. /opt/registry1029 will not be deleted."
fi

echo "Upgrade to v1.0.29 completed successfully."