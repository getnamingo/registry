#!/bin/bash

# Prompt the user for confirmation
echo "This will update Namingo Registry from v1.0.2 to v1.0.3."
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
echo "Cloning v1.0.3 from the repository..."
git clone --branch v1.0.3 --single-branch https://github.com/getnamingo/registry /opt/registry103

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
copy_files "/opt/registry103/automation" "/opt/registry/automation"
copy_files "/opt/registry103/cp" "/var/www/cp"
copy_files "/opt/registry103/whois/web" "/var/www/whois"
#copy_files "/opt/registry103/das" "/opt/registry/das"
#copy_files "/opt/registry103/whois/port43" "/opt/registry/whois/port43"
#copy_files "/opt/registry103/rdap" "/opt/registry/rdap"
copy_files "/opt/registry103/epp" "/opt/registry/epp"
copy_files "/opt/registry103/docs" "/opt/registry/docs"

# Path to the config.php file
config_file="/var/www/whois/config.php"

# Use sed to find the line with 'ignore_captcha' and add a comma after 'true' or 'false'
sed -i "/'ignore_captcha'/ s/\(true\|false\)\s*$/\1,/" "$config_file"

# Append the new lines after 'ignore_captcha' line
sed -i "/'ignore_captcha'/a\    'registry_name' => 'Domain Registry LLC',\n    'registry_url' => 'https://example.com',\n    'branding' => false," "$config_file"

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
#composer_update "/opt/registry/das"
#composer_update "/opt/registry/whois/port43"
#composer_update "/opt/registry/rdap"
composer_update "/opt/registry/epp"

# Start services
echo "Starting services..."
systemctl start epp
systemctl start whois
systemctl start rdap
systemctl start das
systemctl start caddy

# Check if services started successfully
if [[ $? -eq 0 ]]; then
    echo "Services started successfully. Deleting /opt/registry103..."
    rm -rf /opt/registry103
else
    echo "There was an issue starting the services. /opt/registry103 will not be deleted."
fi

echo "Upgrade to v1.0.3 completed successfully."
