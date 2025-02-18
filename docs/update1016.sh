#!/bin/bash

# Ensure the script is run as root
if [[ $EUID -ne 0 ]]; then
    echo "Error: This update script must be run as root or with sudo." >&2
    exit 1
fi

# Prompt the user for confirmation
echo "This will update Namingo Registry from v1.0.15 to v1.0.16."
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

apt install -y php8.3-zip

# Clone the new version of the repository
echo "Cloning v1.0.16 from the repository..."
git clone --branch v1.0.16 --single-branch https://github.com/getnamingo/registry /opt/registry1016

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
copy_files "/opt/registry1016/automation" "/opt/registry/automation"
copy_files "/opt/registry1016/cp" "/var/www/cp"
copy_files "/opt/registry1016/whois/web" "/var/www/whois"
copy_files "/opt/registry1016/das" "/opt/registry/das"
copy_files "/opt/registry1016/whois/port43" "/opt/registry/whois/port43"
copy_files "/opt/registry1016/rdap" "/opt/registry/rdap"
copy_files "/opt/registry1016/epp" "/opt/registry/epp"
copy_files "/opt/registry1016/docs" "/opt/registry/docs"

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

CONFIG_FILE="/opt/registry/rdap/config.php"

# Extract database credentials from the config file
DB_NAME=$(grep "'db_database'" "$CONFIG_FILE" | awk -F "=> " '{print $2}' | tr -d "',")
DB_USER=$(grep "'db_username'" "$CONFIG_FILE" | awk -F "=> " '{print $2}' | tr -d "',")
DB_PASS=$(grep "'db_password'" "$CONFIG_FILE" | awk -F "=> " '{print $2}' | tr -d "',")

echo "Starting error_log table upgrade..."

# Drop old table (if it exists)
echo "Dropping old error_log table..."
mysql -u$DB_USER -p$DB_PASS $DB_NAME -e "DROP TABLE IF EXISTS error_log;" 2>/dev/null

if [ $? -ne 0 ]; then
    echo "Warning: Failed to drop old error_log table. Continuing..."
fi

# Create new table
echo "Creating new error_log table..."
mysql -u$DB_USER -p$DB_PASS $DB_NAME <<EOF
CREATE TABLE error_log (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, 
    channel VARCHAR(255), 
    level INT(3),
    level_name VARCHAR(10),
    message TEXT,
    context JSON,
    extra JSON,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='registry error log';
EOF

if [ $? -ne 0 ]; then
    echo "Warning: Failed to create new error_log table."
else
    echo "New error_log table created successfully."
fi

# Modify column prefix from CHAR(2) to CHAR(5) in registrar table
echo "Updating prefix column in registrar table..."
mysql -u$DB_USER -p$DB_PASS $DB_NAME -e "ALTER TABLE registrar MODIFY prefix CHAR(5) NOT NULL;"

if [ $? -ne 0 ]; then
    echo "Warning: Failed to update prefix column in registrar table."
else
    echo "Prefix column updated successfully."
fi

CONFIG_FILE="/opt/registry/automation/config.php"

# Define the content to insert
INSERT_CONTENT="\n    // Registry Admin Email\n    'admin_email' => 'admin@example.com', // Receives system notifications\n\n    // Exchange Rate Configuration\n    'exchange_rate_api_key' => \"\", // Your exchangerate.host API key\n    'exchange_rate_base_currency' => 'USD',\n    'exchange_rate_currencies' => [\"EUR\", \"GBP\", \"JPY\", \"CAD\", \"AUD\"], // Configurable list\n"

# Check if 'admin_email' exists and insert only if it does not exist
if ! grep -q "'admin_email' => 'admin@example.com'" "$CONFIG_FILE"; then
    sed -i "/'iana_email' =>.*,/a\\$INSERT_CONTENT" "$CONFIG_FILE"
    echo "Configuration updated successfully."
else
    echo "'admin_email' already exists. No changes made."
fi

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
    echo "Services started successfully. Deleting /opt/registry1016..."
    rm -rf /opt/registry1016
else
    echo "There was an issue starting the services. /opt/registry1016 will not be deleted."
fi

echo "Upgrade to v1.0.16 completed successfully."
echo "Make sure you review and edit /opt/registry/automation/config.php"