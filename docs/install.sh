#!/bin/bash

set -euo pipefail

# Function to prompt for user input
prompt_for_input() {
    local response
    read -r -p "$1: " response
    echo "$response"
}

prompt_for_password() {
    local password
    read -r -s -p "$1: " password
    echo >&2
    printf '%s' "$password"
}

generate_db_username() {
    printf 'nmg_%s' "$(openssl rand -hex 4)"
}

generate_password() {
    openssl rand -base64 24 | tr -d '\n' | tr '+/' '-_'
}

escape_sed_replacement() {
    printf '%s' "$1" | sed 's/[&|\\]/\\&/g'
}

escape_php_single_quoted() {
    printf '%s' "$1" | sed "s/'/'\\\\''/g"
}

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
if [[ -r /etc/os-release ]]; then
    . /etc/os-release
    OS_ID="$ID"
    VER="$VERSION_ID"
else
    echo "Error: /etc/os-release not found."
    exit 1
fi

case "${OS_ID}:${VER}" in
    ubuntu:22.04)
        OS_NAME="Ubuntu"
        DISTRO_CODENAME="jammy"
        PHP_REPO_TYPE="ondrej"
        MARIADB_DISTRO="ubuntu"
        MARIADB_SUITE="jammy"
        MARIADB_COMPONENTS="main main/debug"
        ;;
    ubuntu:24.04)
        OS_NAME="Ubuntu"
        DISTRO_CODENAME="noble"
        PHP_REPO_TYPE="ondrej"
        MARIADB_DISTRO="ubuntu"
        MARIADB_SUITE="noble"
        MARIADB_COMPONENTS="main main/debug"
        ;;
    ubuntu:26.04)
        OS_NAME="Ubuntu"
        DISTRO_CODENAME="resolute"
        PHP_REPO_TYPE="sury"
        MARIADB_DISTRO="ubuntu"
        MARIADB_SUITE="resolute"
        MARIADB_COMPONENTS="main main/debug"
        ;;
    debian:12)
        OS_NAME="Debian"
        DISTRO_CODENAME="bookworm"
        PHP_REPO_TYPE="sury"
        MARIADB_DISTRO="debian"
        MARIADB_SUITE="bookworm"
        MARIADB_COMPONENTS="main"
        ;;
    debian:13)
        OS_NAME="Debian"
        DISTRO_CODENAME="trixie"
        PHP_REPO_TYPE="sury"
        MARIADB_DISTRO="debian"
        MARIADB_SUITE="trixie"
        MARIADB_COMPONENTS="main"
        ;;
    *)
        echo "Unsupported Linux distribution or version: ${OS_ID} ${VER}"
        exit 1
        ;;
esac

# Ensure the script is run as root
if [[ $EUID -ne 0 ]]; then
    echo "Error: This installer must be run as root or with sudo." >&2
    exit 1
fi

# Minimum requirements
MIN_RAM_MB=2000
MIN_DISK_GB=10

# Get the available RAM in MB
AVAILABLE_RAM_MB=$(free -m | awk '/^Mem:/{print $2}')
PHP_MEMORY_MB=$(( AVAILABLE_RAM_MB / 2 ))
PHP_MEMORY_LIMIT="${PHP_MEMORY_MB}M"

# Get the available disk space in GB for the root partition
AVAILABLE_DISK_GB=$(df -BG / | awk 'NR==2 {print $4}' | sed 's/G//')

# Check RAM
if [ "$AVAILABLE_RAM_MB" -lt "$MIN_RAM_MB" ]; then
    echo "Error: At least 2GB of RAM is required. Only ${AVAILABLE_RAM_MB}MB is available."
    exit 1
fi

# Check disk space
if [ "$AVAILABLE_DISK_GB" -lt "$MIN_DISK_GB" ]; then
    echo "Error: At least 10GB of free disk space is required. Only ${AVAILABLE_DISK_GB}GB is available."
    exit 1
fi

echo "System meets the minimum requirements. Proceeding with installation..."

# Prompt for details
REGISTRY_DOMAIN=$(prompt_for_input "Enter main domain for registry")
YOUR_IPV4_ADDRESS=$(prompt_for_input "Enter your IPv4 address")
YOUR_IPV6_ADDRESS=$(prompt_for_input "Enter your IPv6 address (leave blank if not available)")
WHOIS_SERVER_CHOICE=$(prompt_for_input "Install the optional WHOIS/DAS servers (TCP ports 43/1043)? [Y/n]")
if [[ "${WHOIS_SERVER_CHOICE:-y}" =~ ^[Nn]([Oo])?$ ]]; then
    INSTALL_WHOIS_SERVER=false
else
    INSTALL_WHOIS_SERVER=true
fi
DB_USER=$(generate_db_username)
DB_PASSWORD=$(generate_password)
DB_PASSWORD_ESCAPED=$(printf '%s' "$DB_PASSWORD" | sed 's/[&|]/\\&/g')
DB_PASSWORD_SQL_ESCAPED=$(printf '%s' "$DB_PASSWORD" | sed "s/'/''/g")

echo "Generated database username: $DB_USER"
echo "Generated database password: $DB_PASSWORD"
echo ""
DB_TYPE=$(prompt_for_input "Enter database type [M = MariaDB, P = PostgreSQL]")

case "${DB_TYPE^^}" in
    M)
        DB_TYPE="mariadb"
        DB_DRIVER="mysql"
        DB_PORT="3306"
        ;;
    P)
        DB_TYPE="pgsql"
        DB_DRIVER="pgsql"
        DB_PORT="5432"
        ;;
    *)
        echo "Invalid database type. Use M or P."
        exit 1
        ;;
esac
PANEL_EMAIL=$(prompt_for_input "Enter panel admin email")
PANEL_PASSWORD=$(prompt_for_password "Enter panel admin password")
echo ""
current_user=$(whoami)

# Install required packages
echo "Installing required packages..."
apt update -y

# Install common packages
apt install -y apt-transport-https bind9-dnsutils bzip2 ca-certificates cron curl debian-archive-keyring debian-keyring gettext git gnupg ufw net-tools openssl pv redis unzip wget whois

# PHP setup
if [[ "$PHP_REPO_TYPE" == "ondrej" ]]; then
    apt install -y software-properties-common    
    add-apt-repository -y ppa:ondrej/php
elif [[ "$PHP_REPO_TYPE" == "sury" ]]; then
    curl -sSLo /usr/share/keyrings/deb.sury.org-php.gpg https://packages.sury.org/php/apt.gpg
    echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ ${DISTRO_CODENAME} main" \
        > /etc/apt/sources.list.d/php.list
fi

# Caddy setup
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | tee /etc/apt/sources.list.d/caddy-stable.list

if [ "$DB_TYPE" == "mariadb" ]; then
mkdir -p /etc/apt/keyrings
curl -o /etc/apt/keyrings/mariadb-keyring.asc 'https://mariadb.org/mariadb_release_signing_key.pgp'
cat > /etc/apt/sources.list.d/mariadb.sources <<EOF
X-Repolib-Name: MariaDB
Types: deb
URIs: https://deb.mariadb.org/11.8/${MARIADB_DISTRO}
Suites: ${MARIADB_SUITE}
Components: ${MARIADB_COMPONENTS}
Signed-By: /etc/apt/keyrings/mariadb-keyring.asc
EOF
elif [ "$DB_TYPE" == "pgsql" ]; then
install -d /usr/share/postgresql-common/pgdg
curl -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc --fail https://www.postgresql.org/media/keys/ACCC4CF8.asc
cat > /etc/apt/sources.list.d/pgdg.sources <<EOF
Types: deb deb-src
URIs: https://apt.postgresql.org/pub/repos/apt
Suites: ${MARIADB_SUITE}-pgdg
Architectures: amd64
Components: main
Signed-By: /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc
EOF
fi

echo "Updating package lists..."
apt update -y

PHP_VERSION="php8.5"
PHP_SHORT="8.5"

echo "Installing packages..."
apt install -y caddy ${PHP_VERSION} ${PHP_VERSION}-bcmath ${PHP_VERSION}-cli ${PHP_VERSION}-common ${PHP_VERSION}-curl ${PHP_VERSION}-ds ${PHP_VERSION}-fpm ${PHP_VERSION}-gd ${PHP_VERSION}-gmp ${PHP_VERSION}-gnupg ${PHP_VERSION}-igbinary ${PHP_VERSION}-imap ${PHP_VERSION}-intl ${PHP_VERSION}-mbstring ${PHP_VERSION}-protobuf ${PHP_VERSION}-readline ${PHP_VERSION}-redis ${PHP_VERSION}-soap ${PHP_VERSION}-swoole ${PHP_VERSION}-uuid ${PHP_VERSION}-xml ${PHP_VERSION}-zip

if [ "$DB_TYPE" == "mariadb" ]; then
    apt install -y mariadb-client mariadb-server ${PHP_VERSION}-mysql

elif [ "$DB_TYPE" == "pgsql" ]; then
    apt install -y postgresql postgresql-client ${PHP_VERSION}-pgsql
fi

# Set timezone to UTC if it's not already
currentTimezone=$(timedatectl status | grep "Time zone" | awk '{print $3}')
if [ "$currentTimezone" != "UTC" ]; then
    echo "Setting timezone to UTC..."
    timedatectl set-timezone UTC
fi

phpIniCli="/etc/php/${PHP_SHORT}/cli/php.ini"
phpIniFpm="/etc/php/${PHP_SHORT}/fpm/php.ini"

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
set_php_ini_value "$phpIniFpm" "session.cookie_domain" ""
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

if [ "$DB_TYPE" == "mariadb" ]; then
    echo "Applying MariaDB hardening..."
    mariadb -u root -e "DELETE FROM mysql.user WHERE User='';"
    mariadb -u root -e "DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');"
    mariadb -u root -e "DROP DATABASE IF EXISTS test;"
    mariadb -u root -e "DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';"
    mariadb -u root -e "FLUSH PRIVILEGES;"

    # Create user and grant privileges
    echo "Creating user $DB_USER and setting privileges..."
    mariadb -u root -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD_SQL_ESCAPED';"
    mariadb -u root -e "GRANT ALL PRIVILEGES ON registry.* TO '$DB_USER'@'localhost';"
    mariadb -u root -e "GRANT ALL PRIVILEGES ON registryTransaction.* TO '$DB_USER'@'localhost';"
    mariadb -u root -e "GRANT ALL PRIVILEGES ON registryAudit.* TO '$DB_USER'@'localhost';"
    mariadb -u root -e "FLUSH PRIVILEGES;"
elif [ "$DB_TYPE" == "pgsql" ]; then
    echo "Configuring PostgreSQL..."

    systemctl enable --now postgresql

    echo "Creating PostgreSQL user $DB_USER..."

    if ! runuser -u postgres -- psql -tAc "SELECT 1 FROM pg_roles WHERE rolname='$DB_USER'" | grep -q 1; then
        runuser -u postgres -- psql -c "CREATE ROLE \"$DB_USER\" LOGIN PASSWORD '$DB_PASSWORD_SQL_ESCAPED';"
    else
        runuser -u postgres -- psql -c "ALTER ROLE \"$DB_USER\" WITH PASSWORD '$DB_PASSWORD_SQL_ESCAPED';"
    fi

    echo "Creating PostgreSQL databases..."

    if ! runuser -u postgres -- psql -tAc "SELECT 1 FROM pg_database WHERE datname='registry'" | grep -q 1; then
        runuser -u postgres -- createdb -O "$DB_USER" registry
    fi

    if ! runuser -u postgres -- psql -tAc "SELECT 1 FROM pg_database WHERE datname='registryTransaction'" | grep -q 1; then
        runuser -u postgres -- createdb -O "$DB_USER" registryTransaction
    fi

    if ! runuser -u postgres -- psql -tAc "SELECT 1 FROM pg_database WHERE datname='registryAudit'" | grep -q 1; then
        runuser -u postgres -- createdb -O "$DB_USER" registryAudit
    fi
fi

mkdir -p /usr/share/adminer
wget "https://www.adminer.org/latest.php" -O /usr/share/adminer/latest.php
ln -sf /usr/share/adminer/latest.php /usr/share/adminer/adminer.php

if [[ ! -d /opt/registry/.git ]]; then
    git clone --branch v1.0.32 --single-branch https://github.com/getnamingo/registry /opt/registry
fi

echo "Setting up firewall rules..."
ufw allow 22/tcp
if $INSTALL_WHOIS_SERVER; then
    ufw allow 43/tcp
    ufw allow 1043/tcp
fi
ufw allow 80/tcp
ufw allow 443/tcp
ufw allow 443/udp
ufw allow 700/tcp
ufw allow 53/tcp
ufw allow 53/udp

# Enable the firewall
echo "Enabling the firewall..."
ufw --force enable

# Function to generate bind line
generate_bind_line() {
    local ipv4=$1
    local ipv6=$2
    local bind_line="bind $ipv4"
    if [[ -n "$ipv6" ]]; then
        bind_line="$bind_line $ipv6"
    fi
    echo "$bind_line"
}

BIND_LINE=$(generate_bind_line "$YOUR_IPV4_ADDRESS" "$YOUR_IPV6_ADDRESS")

# Update Caddyfile
cat > /etc/caddy/Caddyfile << EOF
    rdap.$REGISTRY_DOMAIN {
        $BIND_LINE
        reverse_proxy localhost:7500
        encode zstd gzip
        file_server
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
            Strict-Transport-Security max-age=31536000;
            X-Content-Type-Options nosniff
            X-Frame-Options DENY
            X-XSS-Protection "1; mode=block"
            Content-Security-Policy "default-src 'none'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; img-src https:; font-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'none'; form-action 'self'; worker-src 'none'; frame-src 'none';"
            Permissions-Policy "accelerometer=(), autoplay=(), camera=(), encrypted-media=(), fullscreen=(self), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), midi=(), payment=(), picture-in-picture=(self), usb=()"
            # CORS Headers
            Access-Control-Allow-Origin *
            Access-Control-Allow-Methods "GET, OPTIONS"
            Access-Control-Allow-Headers "Content-Type"
        }
    }

    whois.$REGISTRY_DOMAIN {
        $BIND_LINE
        root * /var/www/whois
        encode zstd gzip
        php_fastcgi unix//run/php/${PHP_VERSION}-fpm.sock
        file_server
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
            Strict-Transport-Security max-age=31536000;
            X-Content-Type-Options nosniff
            X-Frame-Options DENY
            X-XSS-Protection "1; mode=block"
            Content-Security-Policy "default-src 'none'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; img-src https:; font-src 'self'; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; connect-src 'self' https:; form-action 'self'; worker-src 'self' blob:; frame-src 'none';"
            Permissions-Policy "accelerometer=(), autoplay=(), camera=(), encrypted-media=(), fullscreen=(self), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), midi=(), payment=(), picture-in-picture=(self), usb=()"
        }
    }

    cp.$REGISTRY_DOMAIN {
        $BIND_LINE
        root * /var/www/cp/public
        php_fastcgi unix//run/php/${PHP_VERSION}-fpm.sock
        encode zstd gzip
        file_server
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
            php_fastcgi unix//run/php/${PHP_VERSION}-fpm.sock
        }
        header * {
            Referrer-Policy "same-origin"
            Strict-Transport-Security max-age=31536000;
            X-Content-Type-Options nosniff
            X-Frame-Options DENY
            X-XSS-Protection "1; mode=block"
            Content-Security-Policy "default-src 'none'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; connect-src 'self' https://*.revolut.com; img-src https: data:; font-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline' https://*.revolut.com; form-action 'self'; worker-src 'none'; frame-src https://*.revolut.com;"
            Permissions-Policy "accelerometer=(), autoplay=(), camera=(), encrypted-media=(), fullscreen=(self), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), midi=(), payment=(), picture-in-picture=(self), usb=()"
        }
    }

    epp.$REGISTRY_DOMAIN {
        $BIND_LINE
        redir https://cp.$REGISTRY_DOMAIN{uri}
    }
EOF

mkdir -p /var/log/namingo
chown -R www-data:www-data /var/log/namingo
touch /var/log/namingo/web-cp.log
chown caddy:caddy /var/log/namingo/web-cp.log
touch /var/log/namingo/web-whois.log
chown caddy:caddy /var/log/namingo/web-whois.log
touch /var/log/namingo/web-rdap.log
chown caddy:caddy /var/log/namingo/web-rdap.log

systemctl enable caddy
systemctl restart caddy

sleep 5

ln -sf /var/lib/caddy/.local/share/caddy/certificates/acme-v02.api.letsencrypt.org-directory/epp.$REGISTRY_DOMAIN/epp.$REGISTRY_DOMAIN.crt /opt/registry/epp/epp.crt
ln -sf /var/lib/caddy/.local/share/caddy/certificates/acme-v02.api.letsencrypt.org-directory/epp.$REGISTRY_DOMAIN/epp.$REGISTRY_DOMAIN.key /opt/registry/epp/epp.key

echo "Installing Control Panel."
mkdir -p /var/www
cp -r /opt/registry/cp /var/www
mv /var/www/cp/env-sample /var/www/cp/.env

# Update .env file with the actual values
echo "Updating configuration..."
sed -i "s|https://cp.example.com|https://cp.$REGISTRY_DOMAIN|g" /var/www/cp/.env
sed -i "s|example.com|$REGISTRY_DOMAIN|g" /var/www/cp/.env
sed -i "s/DB_USERNAME=root/DB_USERNAME=$DB_USER/g" /var/www/cp/.env
sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=$DB_PASSWORD_ESCAPED|" /var/www/cp/.env
sed -i "s|^DB_DRIVER=.*|DB_DRIVER=$DB_DRIVER|" /var/www/cp/.env
sed -i "s|^DB_PORT=.*|DB_PORT=$DB_PORT|" /var/www/cp/.env

curl -sS https://getcomposer.org/installer -o composer-setup.php
EXPECTED_SIGNATURE="$(wget -q -O - https://composer.github.io/installer.sig)"
ACTUAL_SIGNATURE="$(php -r "echo hash_file('sha384', 'composer-setup.php');")"

if [ "$EXPECTED_SIGNATURE" != "$ACTUAL_SIGNATURE" ]
    then
    >&2 echo 'ERROR: Invalid installer signature'
    rm composer-setup.php
    exit 1
fi

echo 'Composer installer verified'
php composer-setup.php --quiet
rm composer-setup.php
mv composer.phar /usr/local/bin/composer
echo 'Composer installed'

cd /var/www/cp
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --quiet

# Importing the database
echo "Importing database."
if [ "$DB_TYPE" == "mariadb" ]; then
    mariadb -u "$DB_USER" -p"$DB_PASSWORD" < /opt/registry/database/registry.mariadb.sql
elif [ "$DB_TYPE" == "pgsql" ]; then
    PGPASSWORD="$DB_PASSWORD" psql -h localhost -U "$DB_USER" -d registry -f /opt/registry/database/registry.postgres.sql
    PGPASSWORD="$DB_PASSWORD" psql -h localhost -U "$DB_USER" -d registryTransaction -f /opt/registry/database/registryTransaction.postgres.sql
fi
echo "SQL import completed."

echo "Installing Web WHOIS."
mkdir -p /var/www/whois
cd /opt/registry/whois/web
cp -r * /var/www/whois
cd /var/www/whois
COMPOSER_ALLOW_SUPERUSER=1 composer require altcha-org/altcha:^2.1 --no-interaction --quiet
mv /var/www/whois/config.php.dist /var/www/whois/config.php
ALTCHA_HMAC_SECRET="$(openssl rand -hex 32)"
sed -i "s|'whois_url' => '.*'|'whois_url' => 'whois.${REGISTRY_DOMAIN}'|" /var/www/whois/config.php
sed -i "s|'rdap_url' => '.*'|'rdap_url' => 'rdap.${REGISTRY_DOMAIN}'|" /var/www/whois/config.php
sed -i "s|'altcha_hmac_secret' => '.*'|'altcha_hmac_secret' => '${ALTCHA_HMAC_SECRET}'|" /var/www/whois/config.php

if $INSTALL_WHOIS_SERVER; then
    echo "Installing WHOIS Server."
    cd /opt/registry/whois/port43
    COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --quiet
    mv /opt/registry/whois/port43/config.php.dist /opt/registry/whois/port43/config.php
    sed -i "s|'db_username' => 'your_username'|'db_username' => '$DB_USER'|g" /opt/registry/whois/port43/config.php
    sed -i "s|'db_password' => 'your_password'|'db_password' => '$DB_PASSWORD'|g" /opt/registry/whois/port43/config.php
    sed -i "s|'db_type' => 'mysql'|'db_type' => '$DB_DRIVER'|" /opt/registry/whois/port43/config.php
    sed -i "s|'db_port' => 3306|'db_port' => $DB_PORT|" /opt/registry/whois/port43/config.php
    sed -i "s/User=root/User=$current_user/" /opt/registry/docs/whois.service
    sed -i "s/Group=root/Group=$current_user/" /opt/registry/docs/whois.service
    cp /opt/registry/docs/whois.service /etc/systemd/system/
    systemctl daemon-reload
    systemctl enable whois.service

    echo "Installing DAS Server."
    cd /opt/registry/das
    COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --quiet
    mv /opt/registry/das/config.php.dist /opt/registry/das/config.php
    sed -i "s|'db_username' => 'your_username'|'db_username' => '$DB_USER'|g" /opt/registry/das/config.php
    sed -i "s|'db_password' => 'your_password'|'db_password' => '$DB_PASSWORD'|g" /opt/registry/das/config.php
    sed -i "s|'db_type' => 'mysql'|'db_type' => '$DB_DRIVER'|" /opt/registry/das/config.php
    sed -i "s|'db_port' => 3306|'db_port' => $DB_PORT|" /opt/registry/das/config.php
    sed -i "s/User=root/User=$current_user/" /opt/registry/docs/das.service
    sed -i "s/Group=root/Group=$current_user/" /opt/registry/docs/das.service
    cp /opt/registry/docs/das.service /etc/systemd/system/
    systemctl daemon-reload
    systemctl enable das.service
else
    echo "Skipping WHOIS/DAS Server installation."
    sed -i "s|'disable_whois' => false|'disable_whois' => true|" /var/www/whois/config.php
fi

echo "Installing RDAP Server."
cd /opt/registry/rdap
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --quiet
mv /opt/registry/rdap/config.php.dist /opt/registry/rdap/config.php
sed -i "s|'db_username' => 'your_username'|'db_username' => '$DB_USER'|g" /opt/registry/rdap/config.php
sed -i "s|'db_password' => 'your_password'|'db_password' => '$DB_PASSWORD'|g" /opt/registry/rdap/config.php
sed -i "s|'db_type' => 'mysql'|'db_type' => '$DB_DRIVER'|" /opt/registry/rdap/config.php
sed -i "s|'db_port' => 3306|'db_port' => $DB_PORT|" /opt/registry/rdap/config.php
sed -i "s/User=root/User=$current_user/" /opt/registry/docs/rdap.service
sed -i "s/Group=root/Group=$current_user/" /opt/registry/docs/rdap.service
cp /opt/registry/docs/rdap.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable rdap.service

echo "Installing EPP Server."
cd /opt/registry/epp
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --quiet
mv /opt/registry/epp/config.php.dist /opt/registry/epp/config.php
sed -i "s|'db_username' => 'your_username'|'db_username' => '$DB_USER'|g" /opt/registry/epp/config.php
sed -i "s|'db_password' => 'your_password'|'db_password' => '$DB_PASSWORD'|g" /opt/registry/epp/config.php
sed -i "s|'db_type' => 'mysql'|'db_type' => '$DB_DRIVER'|" /opt/registry/epp/config.php
sed -i "s|'db_port' => 3306|'db_port' => $DB_PORT|" /opt/registry/epp/config.php
sed -i "s/User=root/User=$current_user/" /opt/registry/docs/epp.service
sed -i "s/Group=root/Group=$current_user/" /opt/registry/docs/epp.service
cp /opt/registry/docs/epp.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable epp.service

echo "Installing Automation Scripts."
cd /opt/registry/automation
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --quiet
mv /opt/registry/automation/config.php.dist /opt/registry/automation/config.php
sed -i "s|'db_username' => 'your_username'|'db_username' => '$DB_USER'|g" /opt/registry/automation/config.php
sed -i "s|'db_password' => 'your_password'|'db_password' => '$DB_PASSWORD'|g" /opt/registry/automation/config.php
sed -i "s|'db_type' => 'mysql'|'db_type' => '$DB_DRIVER'|" /opt/registry/automation/config.php
sed -i "s|'db_port' => 3306|'db_port' => $DB_PORT|" /opt/registry/automation/config.php

echo "Installing Message Broker."
cp /opt/registry/docs/msg_producer.service /etc/systemd/system/
cp /opt/registry/docs/msg_worker.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable msg_producer
systemctl enable msg_worker

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

echo "Enabling Redis."
systemctl daemon-reload
systemctl enable redis-server
systemctl start redis-server

echo "Configuring control panel admin."

PANEL_EMAIL="$PANEL_EMAIL" \
PANEL_PASSWORD="$PANEL_PASSWORD" \
PANEL_USERNAME="admin" \
php /var/www/cp/bin/create_admin_user.php

echo "Setting up cache."
chown www-data:www-data /var/www/cp/cache

echo "Downloading ICANN TMCH certificate data."
curl -o /etc/ssl/certs/tmch.pem https://ca.icann.org/tmch.crt
curl -o /etc/ssl/certs/tmch_pilot.pem https://ca.icann.org/tmch_pilot.crt
chmod 644 /etc/ssl/certs/tmch.pem /etc/ssl/certs/tmch_pilot.pem

echo -e "\nNamingo Registry installation completed successfully!\n"

echo -e "Access points:"
echo -e " - Control Panel:     https://cp.$REGISTRY_DOMAIN"
echo -e " - RDAP:              https://rdap.$REGISTRY_DOMAIN"
echo -e " - WHOIS (web):       https://whois.$REGISTRY_DOMAIN"
if $INSTALL_WHOIS_SERVER; then
    echo -e " - WHOIS (port 43):   whois.$REGISTRY_DOMAIN:43"
else
    echo -e " - WHOIS (port 43):   not installed (web WHOIS uses RDAP only)"
fi
echo -e " - EPP endpoint:  epp.$REGISTRY_DOMAIN:700\n"

echo -e "Next steps:"
echo -e "1. Review and adjust configuration files in /opt/registry as needed."
echo -e "2. Start core services:"
if $INSTALL_WHOIS_SERVER; then
    echo -e "   systemctl start whois.service"
    echo -e "   systemctl start das.service\n"
fi
echo -e "   systemctl start rdap.service"
echo -e "   systemctl start epp.service"

echo -e "3. Verify services are running:"
if $INSTALL_WHOIS_SERVER; then
    echo -e "   systemctl status whois rdap epp das\n"
else
    echo -e "   systemctl status rdap epp\n"
fi

echo -e "4. Complete any additional configuration described in the Namingo documentation.\n"

echo -e "Your registry environment is now ready."