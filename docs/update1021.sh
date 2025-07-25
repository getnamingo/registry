#!/bin/bash

# Ensure the script is run as root
if [[ $EUID -ne 0 ]]; then
    echo "Error: This update script must be run as root or with sudo." >&2
    exit 1
fi

# Prompt the user for confirmation
echo "This will update Namingo Registry from v1.0.20 to v1.0.21."
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
echo "Cloning v1.0.21 from the repository..."
git clone --branch v1.0.21 --single-branch https://github.com/getnamingo/registry /opt/registry1021

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
copy_files "/opt/registry1021/automation" "/opt/registry/automation"
copy_files "/opt/registry1021/cp" "/var/www/cp"
copy_files "/opt/registry1021/whois/web" "/var/www/whois"
copy_files "/opt/registry1021/das" "/opt/registry/das"
copy_files "/opt/registry1021/whois/port43" "/opt/registry/whois/port43"
copy_files "/opt/registry1021/rdap" "/opt/registry/rdap"
copy_files "/opt/registry1021/epp" "/opt/registry/epp"
copy_files "/opt/registry1021/docs" "/opt/registry/docs"

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

# Update indexes
echo "Dropping old UNIQUE KEY deposit_id_deposit_type..."
mysql -u$DB_USER -p$DB_PASS $DB_NAME -e "ALTER TABLE rde_escrow_deposits DROP INDEX deposit_id_deposit_type;"

echo "Creating new UNIQUE KEY deposit_id_deposit_type (deposit_id, deposit_type, file_name)..."
mysql -u$DB_USER -p$DB_PASS $DB_NAME -e "ALTER TABLE rde_escrow_deposits ADD UNIQUE KEY deposit_id_deposit_type (deposit_id, deposit_type, file_name);"

echo "Copying owner contacts as tech where missing..."
mysql -u$DB_USER -p$DB_PASS $DB_NAME <<EOF
INSERT INTO registrar_contact (registrar_id, type, title, first_name, middle_name, last_name, org, street1, street2, street3, city, sp, pc, cc, voice, fax, email)
SELECT registrar_id, 'tech', title, first_name, middle_name, last_name, org, street1, street2, street3, city, sp, pc, cc, voice, fax, email
FROM registrar_contact rc
WHERE type = 'owner'
AND NOT EXISTS (
    SELECT 1 FROM registrar_contact
    WHERE registrar_id = rc.registrar_id AND type = 'tech'
);
EOF

# Add ssl_fingerprint column
echo "Adding ssl_fingerprint column to registrar table..."
mysql -u$DB_USER -p$DB_PASS $DB_NAME -e "ALTER TABLE registrar ADD COLUMN ssl_fingerprint CHAR(64) DEFAULT NULL AFTER vatNumber;"

echo "Database structure updated successfully."

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

echo "Downloading ICANN TMCH certificate data."
curl -o /etc/ssl/certs/tmch.pem https://ca.icann.org/tmch.crt
curl -o /etc/ssl/certs/tmch_pilot.pem https://ca.icann.org/tmch_pilot.crt
chmod 644 /etc/ssl/certs/tmch.pem /etc/ssl/certs/tmch_pilot.pem

echo "Updating EPP server configuration."
CADDYFILE="/etc/caddy/Caddyfile"
CBACKUP="/etc/caddy/Caddyfile.bak.$(date +%F-%H%M%S)"

# Step 0: Backup original Caddyfile
cp "$CADDYFILE" "$CBACKUP"
echo "Caddy backup saved to $CBACKUP"

rdap_line=$(grep -E '^\s*rdap\.[^ ]+\s*\{' "$CADDYFILE")
bind_line=$(grep -A 3 "$rdap_line" "$CADDYFILE" | grep -E '^\s*bind\s')

base_domain=$(echo "$rdap_line" | sed -E "s/^\s*rdap\.([^ ]+)\s*\{/\1/")

bind_values=$(echo "$bind_line" | sed -E 's/^\s*bind\s+//')

cat <<EOF >> "$CADDYFILE"

epp.$base_domain {
    bind $bind_values
    redir https://cp.$base_domain{uri}
}
EOF

echo "Added EPP block for epp.$base_domain with bind: $bind_values"

systemctl reload caddy

sleep 5

ln -sf /var/lib/caddy/.local/share/caddy/certificates/acme-v02.api.letsencrypt.org-directory/epp.$base_domain/epp.$base_domain.crt /opt/registry/epp/epp.crt
ln -sf /var/lib/caddy/.local/share/caddy/certificates/acme-v02.api.letsencrypt.org-directory/epp.$base_domain/epp.$base_domain.key /opt/registry/epp/epp.key

CONFIG_FILE="/opt/registry/epp/config.php"
NEW_CERT="/opt/registry/epp/epp.crt"
NEW_KEY="/opt/registry/epp/epp.key"

sed -i \
  -e "s|^\(\s*'ssl_cert'\s*=>\s*\).*|\\1'$NEW_CERT',|" \
  -e "s|^\(\s*'ssl_key'\s*=>\s*\).*|\\1'$NEW_KEY',|" \
  "$CONFIG_FILE"

SERVICE_SRC="/opt/registry/docs/namingo-epp-reload.service"
PATH_SRC="/opt/registry/docs/namingo-epp-reload.path"
SERVICE_DEST="/etc/systemd/system/namingo-epp-reload.service"
PATH_DEST="/etc/systemd/system/namingo-epp-reload.path"

if [[ ! -f "$SERVICE_SRC" || ! -f "$PATH_SRC" ]]; then
  echo "Error: Required files not found in /opt/registry/docs/"
  exit 1
fi

echo "Copying systemd service and path files..."
cp "$SERVICE_SRC" "$SERVICE_DEST"
cp "$PATH_SRC" "$PATH_DEST"

echo "Reloading systemd daemon..."
systemctl daemon-reexec
systemctl daemon-reload

echo "Enabling and starting namingo-epp-reload.path..."
systemctl enable --now namingo-epp-reload.path

CONFIG_FILE="/opt/registry/automation/config.php"

if [[ ! -f "$CONFIG_FILE" ]]; then
    echo "Error: File $CONFIG_FILE not found."
fi

awk '
/'\''escrow_privateKey'\''[[:space:]]*=>/ {
    print
    print "    '\''escrow_signing_fingerprint'\'' => '\''REPLACE_WITH_YOUR_40_CHAR_KEY_FINGERPRINT'\'',"
    next
}
{ print }
' "$CONFIG_FILE" > "${CONFIG_FILE}.tmp" && mv "${CONFIG_FILE}.tmp" "$CONFIG_FILE"

echo "Automation config modified successfully."

sed -i '/^TEST_TLDS/ a\\nPASSWORD_EXPIRATION_SKIP_USERS=admin,superadmin' /var/www/cp/.env

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
    echo "Services started successfully. Deleting /opt/registry1021..."
    rm -rf /opt/registry1021
else
    echo "There was an issue starting the services. /opt/registry1021 will not be deleted."
fi

echo "Upgrade to v1.0.21 completed successfully."