#!/bin/bash

# Prompt the user for confirmation
echo "This will update Namingo Registry from v1.0.10 to v1.0.11."
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
echo "Cloning v1.0.11 from the repository..."
git clone --branch v1.0.11 --single-branch https://github.com/getnamingo/registry /opt/registry1011

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
copy_files "/opt/registry1011/automation" "/opt/registry/automation"
copy_files "/opt/registry1011/cp" "/var/www/cp"
copy_files "/opt/registry1011/whois/web" "/var/www/whois"
copy_files "/opt/registry1011/das" "/opt/registry/das"
copy_files "/opt/registry1011/whois/port43" "/opt/registry/whois/port43"
copy_files "/opt/registry1011/rdap" "/opt/registry/rdap"
copy_files "/opt/registry1011/epp" "/opt/registry/epp"
copy_files "/opt/registry1011/docs" "/opt/registry/docs"

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

# SQL Query
sql_query="
ALTER TABLE \`rde_escrow_deposits\`
    MODIFY COLUMN \`deposit_id\` VARCHAR(255) DEFAULT NULL,
    DROP INDEX \`deposit_id\`,
    ADD UNIQUE KEY \`deposit_id_deposit_type\` (\`deposit_id\`, \`deposit_type\`),
    MODIFY COLUMN \`deposit_type\` ENUM('Full', 'Incremental', 'Differential', 'BRDA') NOT NULL,
    MODIFY COLUMN \`notes\` TEXT DEFAULT NULL,
    MODIFY COLUMN \`verification_notes\` TEXT DEFAULT NULL;
"

# Execute the SQL Query
mysql -h "$db_host" -u "$db_user" -p"$db_pass" "$db_name" -e "$sql_query"

# Check for errors
if [ $? -eq 0 ]; then
    echo "Database upgrade 1 completed successfully."
else
    echo "Database upgrade 1 failed."
    exit 1
fi

sql_query="
-- 1) Fix the domain_tld foreign key reference to launch_phases
ALTER TABLE \`domain_tld\`
    DROP FOREIGN KEY \`domain_tld_ibfk_1\`,
    ADD CONSTRAINT \`domain_tld_ibfk_1\`
        FOREIGN KEY (\`launch_phase_id\`)
        REFERENCES \`launch_phases\`(\`id\`)
        ON DELETE RESTRICT;

-- 2) Add the composite index on (domain_name, tld_id) in premium_domain_pricing
ALTER TABLE \`premium_domain_pricing\`
    ADD KEY \`idx_domainname_tldid\` (\`domain_name\`, \`tld_id\`);

-- 3) Add a single-column index idx_addr on registrar_whitelist
ALTER TABLE \`registrar_whitelist\`
    ADD KEY \`idx_addr\` (\`addr\`);
"

# Execute the SQL Query
mysql -h "$db_host" -u "$db_user" -p"$db_pass" "$db_name" -e "$sql_query"

# Check for errors
if [ $? -eq 0 ]; then
    echo "Database upgrade 2 completed successfully."
else
    echo "Database upgrade 2 failed."
    exit 1
fi

# Path to the .env file
ENV_FILE="/var/www/cp/.env"

# Check if the line already exists
if ! grep -q "NICKY_API_KEY=" "$ENV_FILE"; then
  # Insert the new line after NOW_API_KEY
  sed -i "/^NOW_API_KEY=/a NICKY_API_KEY='nicky-api-key'" "$ENV_FILE"
  echo "NICKY_API_KEY added to the .env file."
else
  echo "NICKY_API_KEY already exists in the .env file."
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
    echo "Services started successfully. Deleting /opt/registry1011..."
    rm -rf /opt/registry1011
else
    echo "There was an issue starting the services. /opt/registry1011 will not be deleted."
fi

echo "Upgrade to v1.0.11 completed successfully."
