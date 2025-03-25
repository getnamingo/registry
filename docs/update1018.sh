#!/bin/bash

# Ensure the script is run as root
if [[ $EUID -ne 0 ]]; then
    echo "Error: This update script must be run as root or with sudo." >&2
    exit 1
fi

# Prompt the user for confirmation
echo "This will update Namingo Registry from v1.0.17 to v1.0.18."
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
    mysqldump -u"$db_user" -p"$db_pass" -h"$db_host" "$db_name" > "$sql_backup_file"
    
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

# Clear cache
echo "Clearing cache..."
php /var/www/cp/bin/clear_cache.php

# Clone the new version of the repository
echo "Cloning v1.0.18 from the repository..."
git clone --branch v1.0.18 --single-branch https://github.com/getnamingo/registry /opt/registry1018

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
copy_files "/opt/registry1018/automation" "/opt/registry/automation"
copy_files "/opt/registry1018/cp" "/var/www/cp"
copy_files "/opt/registry1018/whois/web" "/var/www/whois"
copy_files "/opt/registry1018/das" "/opt/registry/das"
copy_files "/opt/registry1018/whois/port43" "/opt/registry/whois/port43"
copy_files "/opt/registry1018/rdap" "/opt/registry/rdap"
copy_files "/opt/registry1018/epp" "/opt/registry/epp"
copy_files "/opt/registry1018/docs" "/opt/registry/docs"

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

# Update composer in relevant directories
composer_update "/opt/registry/automation"
composer_update "/var/www/cp"
composer_update "/opt/registry/das"
composer_update "/opt/registry/whois/port43"
composer_update "/opt/registry/rdap"
composer_update "/opt/registry/epp"

# Function to ensure a setting is present, uncommented, and correctly set
set_php_ini_value() {
    local ini_file=$1
    local key=$2
    local value=$3

    # Escape slashes for sed compatibility
    local escaped_value
    escaped_value=$(printf '%s\n' "$value" | sed 's/[\/&]/\\&/g')

    if grep -Eq "^\s*[;#]?\s*${key}\s*=" "$ini_file"; then
        # Update the existing line, uncomment it and set correct value
        sed -i -E "s|^\s*[;#]?\s*(${key})\s*=.*|\1 = ${escaped_value}|" "$ini_file"
    else
        # Add new line if key doesn't exist
        echo "${key} = ${value}" >> "$ini_file"
    fi
}

# Check the Linux distribution and version
if [[ -e /etc/os-release ]]; then
    . /etc/os-release
    OS=$NAME
    VER=$VERSION_ID
fi

# Get the available RAM in MB
AVAILABLE_RAM_MB=$(free -m | awk '/^Mem:/{print $2}')
PHP_MEMORY_MB=$(( AVAILABLE_RAM_MB / 2 ))
PHP_MEMORY_LIMIT="${PHP_MEMORY_MB}M"

REGISTRY_DOMAIN=$(grep -E '^APP_DOMAIN=' /var/www/cp/.env | cut -d '=' -f2- | tr -d '"' | tr -d "'")

# Determine PHP configuration files based on OS and version
if [[ "$OS" == "Ubuntu" && "$VER" == "24.04" ]]; then
    phpIniCli='/etc/php/8.3/cli/php.ini'
    phpIniFpm='/etc/php/8.3/fpm/php.ini'
	PHP_VERSION="php8.3"
else
    phpIniCli='/etc/php/8.2/cli/php.ini'
    phpIniFpm='/etc/php/8.2/fpm/php.ini'
	PHP_VERSION="php8.2"
fi

# Update php.ini files
set_php_ini_value "$phpIniCli" "opcache.enable" "1"
set_php_ini_value "$phpIniCli" "opcache.enable_cli" "1"
set_php_ini_value "$phpIniCli" "opcache.jit_buffer_size" "100M"
set_php_ini_value "$phpIniCli" "opcache.jit" "1255"
set_php_ini_value "$phpIniCli" "memory_limit" "$PHP_MEMORY_LIMIT"
set_php_ini_value "$phpIniCli" "opcache.memory_consumption" "128"
set_php_ini_value "$phpIniCli" "opcache.interned_strings_buffer" "16"
set_php_ini_value "$phpIniCli" "opcache.max_accelerated_files" "10000"
set_php_ini_value "$phpIniCli" "opcache.validate_timestamps" "0"
set_php_ini_value "$phpIniCli" "expose_php" "0"

# Repeat the same settings for php-fpm
set_php_ini_value "$phpIniFpm" "opcache.enable" "1"
set_php_ini_value "$phpIniFpm" "opcache.enable_cli" "1"
set_php_ini_value "$phpIniFpm" "opcache.jit_buffer_size" "100M"
set_php_ini_value "$phpIniFpm" "opcache.jit" "1255"
set_php_ini_value "$phpIniFpm" "session.cookie_secure" "1"
set_php_ini_value "$phpIniFpm" "session.cookie_httponly" "1"
set_php_ini_value "$phpIniFpm" "session.cookie_samesite" "\"Strict\""
set_php_ini_value "$phpIniFpm" "session.cookie_domain" "\".$REGISTRY_DOMAIN\""
set_php_ini_value "$phpIniFpm" "memory_limit" "$PHP_MEMORY_LIMIT"
set_php_ini_value "$phpIniFpm" "opcache.memory_consumption" "128"
set_php_ini_value "$phpIniFpm" "opcache.interned_strings_buffer" "16"
set_php_ini_value "$phpIniFpm" "opcache.max_accelerated_files" "10000"
set_php_ini_value "$phpIniFpm" "opcache.validate_timestamps" "0"
set_php_ini_value "$phpIniFpm" "expose_php" "0"

# Restart PHP-FPM service
echo "Restarting PHP FPM service..."
systemctl restart ${PHP_VERSION}-fpm
echo "PHP configuration update complete!"

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
    echo "Services started successfully. Deleting /opt/registry1018..."
    rm -rf /opt/registry1018
else
    echo "There was an issue starting the services. /opt/registry1018 will not be deleted."
fi

echo "Upgrade to v1.0.18 completed successfully."