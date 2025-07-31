#!/bin/sh
#
# Alpine Linux installer for Namingo Open Source Registry
#
# This script assumes it is run as root.
#

# --- Utility functions ---

prompt_for_input() {
    read -p "$1: " response
    echo "$response"
}

prompt_for_password() {
    read -s -p "$1: " password
    echo "$password"
}

# Ensure a setting is present (or appended) in a php.ini file
set_php_ini_value() {
    local ini_file=$1
    local key=$2
    local value=$3

    if grep -qE "^\s*;?\s*${key}\s*=" "$ini_file"; then
        sed -i "s|^\s*;*\s*${key}\s*=.*|${key} = ${value}|" "$ini_file"
    else
        echo "${key} = ${value}" >> "$ini_file"
    fi
}

create_openrc_service() {
    local svc_name="$1"
    local cmd_args="$2"
    local init_file="/etc/init.d/${svc_name}"
    cat > "$init_file" <<EOL
#!/sbin/openrc-run
command="/usr/bin/php"
command_args="${cmd_args}"
pidfile="/run/${svc_name}.pid"
name="${svc_name}"
EOL
    chmod +x "$init_file"
    rc-update add "${svc_name}" default
}

# --- Check that we are running on Alpine Linux ---

if [ -e /etc/os-release ]; then
    . /etc/os-release
else
    echo "Cannot detect OS. Exiting."
    exit 1
fi

if [ "$NAME" != "Alpine Linux" ]; then
    echo "This installer only supports Alpine Linux."
    exit 1
fi

# --- Minimum requirements check ---

MIN_RAM_MB=2000
MIN_DISK_GB=10

# (Assumes that the "free" and "df" commands are available.)
AVAILABLE_RAM_MB=$(free -m | awk '/^Mem:/{print $2}')
AVAILABLE_DISK_GB=$(df -BG / | awk 'NR==2 {print $4}' | sed 's/G//')

if [ "$AVAILABLE_RAM_MB" -lt "$MIN_RAM_MB" ]; then
    echo "Error: At least 2GB of RAM is required. Only ${AVAILABLE_RAM_MB}MB available."
    exit 1
fi

if [ "$AVAILABLE_DISK_GB" -lt "$MIN_DISK_GB" ]; then
    echo "Error: At least 10GB free disk space is required. Only ${AVAILABLE_DISK_GB}GB available."
    exit 1
fi

echo "System meets the minimum requirements. Proceeding with installation..."

# --- Prompt for installation details ---

REGISTRY_DOMAIN=$(prompt_for_input "Enter main domain for registry")
YOUR_IPV4_ADDRESS=$(prompt_for_input "Enter your IPv4 address")
YOUR_IPV6_ADDRESS=$(prompt_for_input "Enter your IPv6 address (leave blank if not available)")
YOUR_EMAIL=$(prompt_for_input "Enter your email for TLS")
DB_USER=$(prompt_for_input "Enter database user")
DB_PASSWORD=$(prompt_for_password "Enter database password")
echo ""  # newline after password input
PANEL_EMAIL=$(prompt_for_input "Enter panel admin email")
PANEL_PASSWORD=$(prompt_for_password "Enter panel admin password")
echo ""  # newline after password input
current_user=$(whoami)

# --- Package installation via apk ---

echo "Updating package lists and installing required packages..."
apk update

# Install common packages. (readline gnupg missing)
apk add \
  bash curl caddy gettext icu-data-full git php83-phar gnupg net-tools pv redis unzip wget whois ufw tzdata \
  php83 php83-fpm php83-common php83-curl php83-fileinfo php83-pdo php83-pdo_mysql php83-ctype nano php83-iconv php83-dom php83-gd php83-ftp php83-gmp php83-bcmath php83-mysqli \
  php83-pecl-igbinary php83-imap php83-intl php83-mbstring php83-opcache php83-pecl-redis \
  php83-soap php83-xml \
  php83-pecl-ds php83-pecl-swoole php83-pecl-uuid \
  mariadb mariadb-client

# (Optional) Create the www-data user if it does not exist.
if ! id www-data > /dev/null 2>&1; then
    adduser -D -g "www-data" www-data
fi

# --- Set timezone to UTC ---
echo "Setting timezone to UTC..."
ln -sf /usr/share/zoneinfo/UTC /etc/localtime
echo "UTC" > /etc/timezone

# --- PHP configuration ---
phpIniCli="/etc/php83/php.ini"
phpIniFpm="/etc/php83/php.ini"
phpIniOpcache="/etc/php83/conf.d/10_opcache.ini"

# Update PHP configuration
for ini in "$phpIniCli" "$phpIniFpm"; do
    set_php_ini_value "$ini" "opcache.enable" "1"
    set_php_ini_value "$ini" "opcache.enable_cli" "1"
    set_php_ini_value "$ini" "opcache.jit_buffer_size" "100M"
    set_php_ini_value "$ini" "opcache.jit" "1255"
    set_php_ini_value "$ini" "session.cookie_secure" "1"
    set_php_ini_value "$ini" "session.cookie_httponly" "1"
    set_php_ini_value "$ini" "session.cookie_samesite" "\"Strict\""
    set_php_ini_value "$ini" "session.cookie_domain" "\"${REGISTRY_DOMAIN},cp.${REGISTRY_DOMAIN},whois.${REGISTRY_DOMAIN}\""
    set_php_ini_value "$ini" "memory_limit" "2G"
done

# Update opcache configuration
if [ -f "$phpIniOpcache" ]; then
    set_php_ini_value "$phpIniOpcache" "opcache.jit" "1255"
    set_php_ini_value "$phpIniOpcache" "opcache.jit_buffer_size" "100M"
fi

# Restart PHP-FPM and add to default runlevel
echo "Restarting PHP-FPM..."
rc-service php-fpm83 restart
rc-update add php-fpm83 default

# --- MariaDB setup ---
echo "Setting up MariaDB..."
# Initialize the MariaDB data directory
if [ ! -d /var/lib/mysql/mysql ]; then
    mariadb-install-db --user=mysql --basedir=/usr --datadir=/var/lib/mysql
fi

# Start MariaDB and add to default runlevel
rc-service mariadb start
rc-update add mariadb default

echo "Please follow the prompts for secure installation of MariaDB."
#mysql_secure_installation

# Create database user and grant privileges
DB_COMMAND="mariadb"
$DB_COMMAND -u root -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD';"
$DB_COMMAND -u root -e "GRANT ALL PRIVILEGES ON registry.* TO '$DB_USER'@'localhost';"
$DB_COMMAND -u root -e "GRANT ALL PRIVILEGES ON registryTransaction.* TO '$DB_USER'@'localhost';"
$DB_COMMAND -u root -e "GRANT ALL PRIVILEGES ON registryAudit.* TO '$DB_USER'@'localhost';"
$DB_COMMAND -u root -e "FLUSH PRIVILEGES;"

# --- Adminer installation ---
echo "Installing Adminer..."
mkdir -p /usr/share/adminer
wget "https://www.adminer.org/latest.php" -O /usr/share/adminer/latest.php
ln -sf /usr/share/adminer/latest.php /usr/share/adminer/adminer.php

# --- Clone registry source code ---
echo "Cloning registry source code..."
git clone --branch v1.0.21 --single-branch https://github.com/getnamingo/registry /opt/registry

# --- Firewall configuration using ufw ---
echo "Configuring firewall rules..."
ufw allow 22/tcp
ufw allow 22/udp
ufw allow 43/tcp
ufw allow 80/tcp
ufw allow 80/udp
ufw allow 443/tcp
ufw allow 443/udp
ufw allow 700/tcp
ufw allow 700/udp
ufw allow 1043/tcp
ufw allow 1043/udp
ufw allow 53/tcp
ufw allow 53/udp

echo "Enabling firewall..."
ufw --force enable

# --- Helper: Generate bind line for Caddy ---
generate_bind_line() {
    local ipv4=$1
    local ipv6=$2
    local bind_line="bind ${ipv4}"
    if [ -n "$ipv6" ]; then
        bind_line="${bind_line} ${ipv6}"
    fi
    echo "$bind_line"
}

BIND_LINE=$(generate_bind_line "$YOUR_IPV4_ADDRESS" "$YOUR_IPV6_ADDRESS")

# --- Caddy configuration ---
echo "Configuring Caddy..."
cat > /etc/caddy/Caddyfile <<EOF
rdap.${REGISTRY_DOMAIN} {
    ${BIND_LINE}
    reverse_proxy localhost:7500
    encode zstd gzip
    file_server
    tls ${YOUR_EMAIL}
    header -Server
    log {
        output file /var/log/namingo/web-rdap.log {
            roll_size 10MB
            roll_keep 5
        }
        format json
    }
    header * {
        Referrer-Policy "no-referrer"
        Strict-Transport-Security "max-age=31536000;"
        X-Content-Type-Options "nosniff"
        X-Frame-Options "DENY"
        X-XSS-Protection "1; mode=block"
        Content-Security-Policy "default-src 'none'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; img-src https:; font-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'none'; form-action 'self'; worker-src 'none'; frame-src 'none';"
        Feature-Policy "accelerometer 'none'; autoplay 'none'; camera 'none'; encrypted-media 'none'; fullscreen 'self'; geolocation 'none'; gyroscope 'none'; magnetometer 'none'; microphone 'none'; midi 'none'; payment 'none'; picture-in-picture 'self'; usb 'none';"
        Permissions-Policy "accelerometer=(), autoplay=(), camera=(), encrypted-media=(), fullscreen=(self), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), midi=(), payment=(), picture-in-picture=(self), usb=()"
        # CORS Headers
        Access-Control-Allow-Origin *
        Access-Control-Allow-Methods "GET, OPTIONS"
        Access-Control-Allow-Headers "Content-Type"
    }
}

whois.${REGISTRY_DOMAIN} {
    ${BIND_LINE}
    root * /var/www/whois
    encode zstd gzip
    php_fastcgi 127.0.0.1:9000
    file_server
    tls ${YOUR_EMAIL}
    header -Server
    log {
        output file /var/log/namingo/web-whois.log {
            roll_size 10MB
            roll_keep 5
        }
        format json
    }
    header * {
        Referrer-Policy "no-referrer"
        Strict-Transport-Security "max-age=31536000;"
        X-Content-Type-Options "nosniff"
        X-Frame-Options "DENY"
        X-XSS-Protection "1; mode=block"
        Content-Security-Policy "default-src 'none'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; img-src https:; font-src 'self'; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; script-src 'none'; form-action 'self'; worker-src 'none'; frame-src 'none';"
        Feature-Policy "accelerometer 'none'; autoplay 'none'; camera 'none'; encrypted-media 'none'; fullscreen 'self'; geolocation 'none'; gyroscope 'none'; magnetometer 'none'; microphone 'none'; midi 'none'; payment 'none'; picture-in-picture 'self'; usb 'none';"
        Permissions-Policy "accelerometer=(), autoplay=(), camera=(), encrypted-media=(), fullscreen=(self), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), midi=(), payment=(), picture-in-picture=(self), usb=()"
    }
}

cp.${REGISTRY_DOMAIN} {
    ${BIND_LINE}
    root * /var/www/cp/public
    php_fastcgi 127.0.0.1:9000
    encode zstd gzip
    file_server
    tls ${YOUR_EMAIL}
    header -Server
    log {
        output file /var/log/namingo/web-cp.log {
            roll_size 10MB
            roll_keep 5
        }
        format json
    }
    # Adminer Configuration
    route /adminer.php* {
        root * /usr/share/adminer
        php_fastcgi 127.0.0.1:9000
    }
    header * {
        Referrer-Policy "same-origin"
        Strict-Transport-Security "max-age=31536000;"
        X-Content-Type-Options "nosniff"
        X-Frame-Options "DENY"
        X-XSS-Protection "1; mode=block"
        Content-Security-Policy "default-src 'none'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; img-src https:; font-src 'self'; style-src 'self' 'unsafe-inline' https://rsms.me; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/; form-action 'self'; worker-src 'none'; frame-src 'none';"
        Feature-Policy "accelerometer 'none'; autoplay 'none'; camera 'none'; encrypted-media 'none'; fullscreen 'self'; geolocation 'none'; gyroscope 'none'; magnetometer 'none'; microphone 'none'; midi 'none'; payment 'none'; picture-in-picture 'self'; usb 'none';"
        Permissions-Policy "accelerometer=(), autoplay=(), camera=(), encrypted-media=(), fullscreen=(self), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), midi=(), payment=(), picture-in-picture=(self), usb=()"
    }
}

epp.${REGISTRY_DOMAIN} {
    ${BIND_LINE}
    redir https://cp.${REGISTRY_DOMAIN}{uri}
}
EOF

# Create log directory and adjust permissions
mkdir -p /var/log/namingo
chown -R caddy:caddy /var/log/namingo
touch /var/log/namingo/web-cp.log
chown caddy:caddy /var/log/namingo/web-cp.log
touch /var/log/namingo/web-whois.log
chown caddy:caddy /var/log/namingo/web-whois.log
touch /var/log/namingo/web-rdap.log
chown caddy:caddy /var/log/namingo/web-rdap.log

# Restart Caddy and add to default runlevel
rc-service caddy restart
rc-update add caddy default

sleep 5

ln -sf /var/lib/caddy/.local/share/caddy/certificates/acme-v02.api.letsencrypt.org-directory/epp.${REGISTRY_DOMAIN}/epp.${REGISTRY_DOMAIN}.crt /opt/registry/epp/epp.crt
ln -sf /var/lib/caddy/.local/share/caddy/certificates/acme-v02.api.letsencrypt.org-directory/epp.${REGISTRY_DOMAIN}/epp.${REGISTRY_DOMAIN}.key /opt/registry/epp/epp.key

# --- Install Control Panel ---
echo "Installing Control Panel..."
mkdir -p /var/www
cp -r /opt/registry/cp /var/www
mv /var/www/cp/env-sample /var/www/cp/.env

echo "Updating control panel configuration..."
sed -i "s|https://cp.example.com|https://cp.${REGISTRY_DOMAIN}|g" /var/www/cp/.env
sed -i "s|example.com|${REGISTRY_DOMAIN}|g" /var/www/cp/.env
sed -i "s/DB_USERNAME=root/DB_USERNAME=${DB_USER}/g" /var/www/cp/.env
sed -i "s/DB_PASSWORD=/DB_PASSWORD=${DB_PASSWORD}/g" /var/www/cp/.env

# --- Install Composer ---
echo "Installing Composer..."
curl -sS https://getcomposer.org/installer -o composer-setup.php
EXPECTED_SIGNATURE="$(wget -q -O - https://composer.github.io/installer.sig)"
ACTUAL_SIGNATURE="$(php -r "echo hash_file('sha384', 'composer-setup.php');")"

if [ "$EXPECTED_SIGNATURE" != "$ACTUAL_SIGNATURE" ]; then
    >&2 echo 'ERROR: Invalid installer signature'
    rm composer-setup.php
    exit 1
fi

php composer-setup.php --quiet
rm composer-setup.php
mv composer.phar /usr/local/bin/composer
echo "Composer installed."

# Install PHP dependencies for the control panel
cd /var/www/cp
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --quiet

# --- Import the registry database ---
echo "Importing registry database..."
$DB_COMMAND -u "${DB_USER}" -p"${DB_PASSWORD}" < /opt/registry/database/registry.mariadb.sql
echo "SQL import completed."

# --- Install Web WHOIS ---
echo "Installing Web WHOIS..."
mkdir -p /var/www/whois
cd /opt/registry/whois/web
cp -r * /var/www/whois
cd /var/www/whois
COMPOSER_ALLOW_SUPERUSER=1 composer require gregwar/captcha --no-interaction --quiet
mv /var/www/whois/config.php.dist /var/www/whois/config.php
sed -i "s|'whois_url' => '.*'|'whois_url' => 'whois.${REGISTRY_DOMAIN}'|" /var/www/whois/config.php
sed -i "s|'rdap_url' => '.*'|'rdap_url' => 'rdap.${REGISTRY_DOMAIN}'|" /var/www/whois/config.php

echo "Installing WHOIS Server..."
cd /opt/registry/whois/port43
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --quiet
mv /opt/registry/whois/port43/config.php.dist /opt/registry/whois/port43/config.php
sed -i "s|'db_username' => 'your_username'|'db_username' => '$DB_USER'|g" /opt/registry/whois/port43/config.php
sed -i "s|'db_password' => 'your_password'|'db_password' => '$DB_PASSWORD'|g" /opt/registry/whois/port43/config.php
create_openrc_service "whois" "/opt/registry/whois/port43/start_whois.php"

echo "Installing RDAP Server..."
cd /opt/registry/rdap
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --quiet
mv /opt/registry/rdap/config.php.dist /opt/registry/rdap/config.php
sed -i "s|'db_username' => 'your_username'|'db_username' => '$DB_USER'|g" /opt/registry/rdap/config.php
sed -i "s|'db_password' => 'your_password'|'db_password' => '$DB_PASSWORD'|g" /opt/registry/rdap/config.php
create_openrc_service "rdap" "/opt/registry/rdap/start_rdap.php"

echo "Installing EPP Server..."
cd /opt/registry/epp
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --quiet
mv /opt/registry/epp/config.php.dist /opt/registry/epp/config.php
sed -i "s|'db_username' => 'your_username'|'db_username' => '$DB_USER'|g" /opt/registry/epp/config.php
sed -i "s|'db_password' => 'your_password'|'db_password' => '$DB_PASSWORD'|g" /opt/registry/epp/config.php
create_openrc_service "epp" "/opt/registry/epp/start_epp.php"

echo "Installing DAS Server..."
cd /opt/registry/das
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --quiet
mv /opt/registry/das/config.php.dist /opt/registry/das/config.php
sed -i "s|'db_username' => 'your_username'|'db_username' => '$DB_USER'|g" /opt/registry/das/config.php
sed -i "s|'db_password' => 'your_password'|'db_password' => '$DB_PASSWORD'|g" /opt/registry/das/config.php
create_openrc_service "das" "/opt/registry/das/start_das.php"

echo "Installing Automation Scripts..."
cd /opt/registry/automation
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --quiet
mv /opt/registry/automation/config.php.dist /opt/registry/automation/config.php
sed -i "s|'db_username' => 'your_username'|'db_username' => '$DB_USER'|g" /opt/registry/automation/config.php
sed -i "s|'db_password' => 'your_password'|'db_password' => '$DB_PASSWORD'|g" /opt/registry/automation/config.php

create_openrc_service "msg_producer" "/opt/registry/automation/msg_producer.php"
create_openrc_service "msg_worker" "/opt/registry/automation/msg_worker.php"

rc-update add redis default
rc-service redis start

# --- Configure control panel admin ---
echo "Configuring control panel admin..."
sed -i "s|\$email = 'admin@example.com';|\$email = '${PANEL_EMAIL}';|g" /var/www/cp/bin/create_admin_user.php
sed -i "s|\$newPW = 'admin_password';|\$newPW = '${PANEL_PASSWORD}';|g" /var/www/cp/bin/create_admin_user.php
php /var/www/cp/bin/create_admin_user.php

echo "Downloading initial data and setting up cache..."
php /var/www/cp/bin/file_cache.php
chown caddy:caddy /var/www/cp/cache

echo "Downloading ICANN TMCH certificate data."
curl -o /etc/ssl/certs/tmch.pem https://ca.icann.org/tmch.crt
curl -o /etc/ssl/certs/tmch_pilot.pem https://ca.icann.org/tmch_pilot.crt
chmod 644 /etc/ssl/certs/tmch.pem /etc/ssl/certs/tmch_pilot.pem

echo -e "Installation complete!\n"
echo -e "Next steps:\n"
echo -e "1. Configure each component by editing their respective configuration files."
echo -e "2. Once configuration is complete, start each service with the following command:\n   rc-service SERVICE_NAME start\n   Replace 'SERVICE_NAME' with the specific service (whois, rdap, epp, das) as needed."
echo -e "3. To initiate the automation system, please refer to the configuration manual.\n"
echo -e "For more detailed information, please consult the accompanying documentation or support resources."

echo -e "⚠️ Notice: Automatic certificate monitoring and EPP reload via systemd is NOT supported on Alpine Linux."
echo -e "Please remember to manually reload the EPP service every 3 months after certificate renewal:"
echo -e "    systemctl reload namingo-epp"