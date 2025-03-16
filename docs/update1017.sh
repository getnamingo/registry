#!/bin/bash

# Ensure the script is run as root
if [[ $EUID -ne 0 ]]; then
    echo "Error: This update script must be run as root or with sudo." >&2
    exit 1
fi

# Prompt the user for confirmation
echo "This will update Namingo Registry from v1.0.16 to v1.0.17."
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
echo "Cloning v1.0.17 from the repository..."
git clone --branch v1.0.17 --single-branch https://github.com/getnamingo/registry /opt/registry1017

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
copy_files "/opt/registry1017/automation" "/opt/registry/automation"
copy_files "/opt/registry1017/cp" "/var/www/cp"
copy_files "/opt/registry1017/whois/web" "/var/www/whois"
copy_files "/opt/registry1017/das" "/opt/registry/das"
copy_files "/opt/registry1017/whois/port43" "/opt/registry/whois/port43"
copy_files "/opt/registry1017/rdap" "/opt/registry/rdap"
copy_files "/opt/registry1017/epp" "/opt/registry/epp"
copy_files "/opt/registry1017/docs" "/opt/registry/docs"

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

CADDYFILE="/etc/caddy/Caddyfile"
BACKUP="/etc/caddy/Caddyfile.bak"

# Backup the original file
cp "$CADDYFILE" "$BACKUP"
echo "Backup saved to $BACKUP"

# --------------------------------------------------------------------
# 1. For the rdap block: insert the new log block after "header -Server"
#    and before the following "header * {" line.
# --------------------------------------------------------------------
perl -0777 -pi -e 's/(^\s*rdap\.[^\s]+\s*\{.*?header\s+-Server.*?\n)(\s*header\s+\*\s*\{)/$1        log {\n            output file \/var\/log\/namingo\/web-rdap.log {\n                roll_size 10MB\n                roll_keep 5\n                roll_keep_days 14\n            }\n            format json\n        }\n$2/sm' "$CADDYFILE"

# --------------------------------------------------------------------
# 2. For the whois block: insert the new log block after "header -Server"
#    and before the following "header * {" line.
# --------------------------------------------------------------------
perl -0777 -pi -e 's/(^\s*whois\.[^\s]+\s*\{.*?header\s+-Server.*?\n)(\s*header\s+\*\s*\{)/$1        log {\n            output file \/var\/log\/namingo\/web-whois.log {\n                roll_size 10MB\n                roll_keep 5\n                roll_keep_days 14\n            }\n            format json\n        }\n$2/sm' "$CADDYFILE"

# --------------------------------------------------------------------
# 3. For the cp block: replace the log block that outputs to caddy.log
#    with a new block that outputs to web-cp.log.
# --------------------------------------------------------------------
perl -0777 -pi -e 's/(^\s*cp\.[^\s]+\s*\{.*?log\s*\{\s*\n\s*output\s+file\s+\/var\/log\/namingo\/)caddy\.log(\s*\n\s*\})/$1web-cp.log {\n                roll_size 10MB\n                roll_keep 5\n                roll_keep_days 14\n            }\n            format json\n        }/sm' "$CADDYFILE"

# --------------------------------------------------------------------
# 4. Create the new log files and set their ownership to caddy:caddy
# --------------------------------------------------------------------
for logfile in web-cp.log web-whois.log web-rdap.log; do
    touch /var/log/namingo/"$logfile"
    chown caddy:caddy /var/log/namingo/"$logfile"
    echo "Created and updated ownership for /var/log/namingo/$logfile"
done

wget "http://www.adminer.org/latest.php" -O /usr/share/adminer/latest.php

echo 'www-data ALL=(ALL) NOPASSWD: /usr/sbin/rndc' > /etc/sudoers.d/namingo-rndc
chmod 440 /etc/sudoers.d/namingo-rndc

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
    echo "Services started successfully. Deleting /opt/registry1017..."
    rm -rf /opt/registry1017
else
    echo "There was an issue starting the services. /opt/registry1017 will not be deleted."
fi

echo "Upgrade to v1.0.17 completed successfully."
echo ""
echo "Please double-check /etc/caddy/Caddyfile to ensure that the following log blocks have been added:"
echo ""
echo "For rdap.namingo.org:"
echo "    log {"
echo "        output file /var/log/namingo/web-rdap.log {"
echo "            roll_size 10MB"
echo "            roll_keep 5"
echo "            roll_keep_days 14"
echo "        }"
echo "        format json"
echo "    }"
echo ""
echo "For whois.namingo.org:"
echo "    log {"
echo "        output file /var/log/namingo/web-whois.log {"
echo "            roll_size 10MB"
echo "            roll_keep 5"
echo "            roll_keep_days 14"
echo "        }"
echo "        format json"
echo "    }"
echo ""
echo "For cp.namingo.org:"
echo "    log {"
echo "        output file /var/log/namingo/web-cp.log {"
echo "            roll_size 10MB"
echo "            roll_keep 5"
echo "            roll_keep_days 14"
echo "        }"
echo "        format json"
echo "    }"