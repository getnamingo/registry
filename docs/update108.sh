#!/bin/bash

# Prompt the user for confirmation
echo "This will update Namingo Registry from v1.0.7 to v1.0.8."
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

# Clear cache
echo "Clearing cache..."
php /var/www/cp/bin/clear_cache.php

# Clone the new version of the repository
echo "Cloning v1.0.8 from the repository..."
git clone --branch v1.0.8 --single-branch https://github.com/getnamingo/registry /opt/registry108

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
copy_files "/opt/registry108/automation" "/opt/registry/automation"
copy_files "/opt/registry108/cp" "/var/www/cp"
copy_files "/opt/registry108/whois/web" "/var/www/whois"
copy_files "/opt/registry108/das" "/opt/registry/das"
copy_files "/opt/registry108/whois/port43" "/opt/registry/whois/port43"
copy_files "/opt/registry108/rdap" "/opt/registry/rdap"
copy_files "/opt/registry108/epp" "/opt/registry/epp"
copy_files "/opt/registry108/docs" "/opt/registry/docs"

# Run composer update in copied directories (excluding docs)
echo "Running composer update..."

composer_update() {
    dir=$1
    if [[ -d "$dir" ]]; then
        echo "Updating composer in $dir..."
        cd "$dir" && composer update
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

# Update /var/www/cp/.env
ENV_FILE="/var/www/cp/.env"
if [ -f "$ENV_FILE" ]; then
    sed -i '/^MINIMUM_DATA/a \
LANG=en_US\nUI_LANG=us\n' "$ENV_FILE"
fi

# Update /opt/registry/automation/config.php
CONFIG_FILE="/opt/registry/automation/config.php"
if [ -f "$CONFIG_FILE" ]; then
    # Add the 'dns_serial' row below 'dns_soa'
    sed -i "/'dns_soa'/a\\
'dns_serial' => 1, // change to 2 for YYYYMMDDXX format, and 3 for Cloudflare-like serial" "$CONFIG_FILE"

    # Remove only the blank line directly after 'dns_serial'
    sed -i "/'dns_serial' => 1, \/\/ change to 2 for YYYYMMDDXX format, and 3 for Cloudflare-like serial/{n;/^$/d}" "$CONFIG_FILE"
fi

CONFIG_FILE="/opt/registry/automation/config.php"
if [ -f "$CONFIG_FILE" ]; then
    # Add 'minimum_data' with an empty line after it
    sed -i "/'minimum_data'/a\\
\\
// Domain lifecycle settings\\
'autoRenewEnabled' => false,\\
\\
// Lifecycle periods (in days)\\
'gracePeriodDays' => 30,\\
'autoRenewPeriodDays' => 45,\\
'addPeriodDays' => 5,\\
'renewPeriodDays' => 5,\\
'transferPeriodDays' => 5,\\
'redemptionPeriodDays' => 30,\\
'pendingDeletePeriodDays' => 5,\\
\\
// Lifecycle phases (enable/disable)\\
'enableAutoRenew' => false,\\
'enableGracePeriod' => true,\\
'enableRedemptionPeriod' => true,\\
'enablePendingDelete' => true,\\
\\
// Drop settings\\
'dropStrategy' => 'random', // Options: 'fixed', 'random'\\
'dropTime' => '02:00:00',    // Time of day to perform drops if 'fixed' strategy is used" "$CONFIG_FILE"

    # Remove any extra blank lines added by accident after the block
    sed -i '/^$/N;/^\n$/D' "$CONFIG_FILE"
fi

# Start services
echo "Starting services..."
systemctl start epp
systemctl start whois
systemctl start rdap
systemctl start das
systemctl start caddy

# Check if services started successfully
if [[ $? -eq 0 ]]; then
    echo "Services started successfully. Deleting /opt/registry108..."
    rm -rf /opt/registry108
else
    echo "There was an issue starting the services. /opt/registry108 will not be deleted."
fi

echo "Upgrade to v1.0.8 completed successfully."
