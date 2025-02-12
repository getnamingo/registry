#!/bin/bash

# Function to prompt for user input
prompt_for_input() {
    read -p "$1: " response
    echo $response
}

prompt_for_password() {
    read -sp "$1: " password
    echo $password
}

# Function to ensure a setting is present, uncommented, and correctly set
set_php_ini_value() {
    local ini_file=$1
    local key=$2
    local value=$3

    if grep -qE "^\s*;?\s*${key}\s*=" "$ini_file"; then
        sed -i "s/^\s*;?\s*${key}\s*=.*/${key} = ${value}/" "$ini_file"
    else
        echo "${key} = ${value}" >> "$ini_file"
    fi
}

# Check the Linux distribution and version
if [[ -e /etc/os-release ]]; then
    . /etc/os-release
    OS=$NAME
    VER=$VERSION_ID
fi

# Ensure the script is run as root
if [[ $EUID -ne 0 ]]; then
    echo "Error: This installer must be run as root or with sudo." >&2
    exit 1
fi

# Minimum requirements
MIN_RAM_MB=2048
MIN_DISK_GB=10

# Get the available RAM in MB
AVAILABLE_RAM_MB=$(free -m | awk '/^Mem:/{print $2}')

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

# Proceed if it's Ubuntu or Debian
if [[ ("$OS" == "Ubuntu" && "$VER" == "22.04") || ("$OS" == "Ubuntu" && "$VER" == "24.04") || ("$OS" == "Debian GNU/Linux" && "$VER" == "12") ]]; then
    # Prompt for details
    REGISTRY_DOMAIN=$(prompt_for_input "Enter main domain for registry")
    YOUR_IPV4_ADDRESS=$(prompt_for_input "Enter your IPv4 address")
    YOUR_IPV6_ADDRESS=$(prompt_for_input "Enter your IPv6 address (leave blank if not available)")
    YOUR_EMAIL=$(prompt_for_input "Enter your email for TLS")
    #DB_TYPE=$(prompt_for_input "Enter preferred database type (MariaDB/PostgreSQL)")
    DB_USER=$(prompt_for_input "Enter database user")
    DB_PASSWORD=$(prompt_for_password "Enter database password")
    echo ""  # Add a newline after the password input
    PANEL_EMAIL=$(prompt_for_input "Enter panel admin email")
    PANEL_PASSWORD=$(prompt_for_password "Enter panel admin password")
    echo ""  # Add a newline after the password input
    current_user=$(whoami)

    # Step 1 - Components Installation
    echo "Installing required packages..."
    
    # Common Caddy setup
    curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' -o caddy-stable.gpg.key
    gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg caddy-stable.gpg.key
    curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | sudo tee /etc/apt/sources.list.d/caddy-stable.list
    
    echo "Updating package lists..."
    apt update -y

    # Install common packages for all versions
    apt install -y apt-transport-https curl debian-archive-keyring debian-keyring software-properties-common ufw bzip2 caddy gettext git gnupg2 net-tools pv redis unzip wget whois

    # PHP version and repository setup based on the OS version
    if [[ "$OS" == "Ubuntu" && "$VER" == "22.04" ]]; then
        gpg --no-default-keyring --keyring /usr/share/keyrings/ondrej-php.gpg --keyserver keyserver.ubuntu.com --recv-keys 4F4EA0AAE5267A6C
        echo "deb [signed-by=/usr/share/keyrings/ondrej-php.gpg] http://ppa.launchpad.net/ondrej/php/ubuntu jammy main" | sudo tee /etc/apt/sources.list.d/ondrej-php.list
        PHP_VERSION="php8.2"
        DB_COMMAND="mysql"
        SECURE_INSTALL_CMD="mysql_secure_installation"
    elif [[ "$OS" == "Ubuntu" && "$VER" == "24.04" ]]; then
        gpg --no-default-keyring --keyring /usr/share/keyrings/ondrej-php.gpg --keyserver keyserver.ubuntu.com --recv-keys 4F4EA0AAE5267A6C
        echo "deb [signed-by=/usr/share/keyrings/ondrej-php.gpg] http://ppa.launchpad.net/ondrej/php/ubuntu noble main" | sudo tee /etc/apt/sources.list.d/ondrej-php.list
        PHP_VERSION="php8.3"
        DB_COMMAND="mariadb"
        SECURE_INSTALL_CMD="mariadb-secure-installation"
    else
        apt install -y ca-certificates cron gnupg lsb-release
        curl -sSLo /usr/share/keyrings/deb.sury.org-php.gpg https://packages.sury.org/php/apt.gpg
        echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" | sudo tee /etc/apt/sources.list.d/php.list
        PHP_VERSION="php8.2"
        DB_COMMAND="mysql"
        SECURE_INSTALL_CMD="mysql_secure_installation"
    fi

    echo "Updating package lists..."
    apt update -y

    echo "Installing PHP and required extensions..."
    apt install -y ${PHP_VERSION} ${PHP_VERSION}-bcmath ${PHP_VERSION}-cli ${PHP_VERSION}-common ${PHP_VERSION}-curl ${PHP_VERSION}-ds ${PHP_VERSION}-fpm ${PHP_VERSION}-gd ${PHP_VERSION}-gmp ${PHP_VERSION}-gnupg ${PHP_VERSION}-igbinary ${PHP_VERSION}-imap ${PHP_VERSION}-intl ${PHP_VERSION}-mbstring ${PHP_VERSION}-opcache ${PHP_VERSION}-readline ${PHP_VERSION}-redis ${PHP_VERSION}-soap ${PHP_VERSION}-swoole ${PHP_VERSION}-uuid ${PHP_VERSION}-xml
    
    # Set timezone to UTC if it's not already
    currentTimezone=$(timedatectl status | grep "Time zone" | awk '{print $3}')
    if [ "$currentTimezone" != "UTC" ]; then
        echo "Setting timezone to UTC..."
        timedatectl set-timezone UTC
    fi

    # Determine PHP configuration files based on OS and version
    if [[ "$OS" == "Ubuntu" && "$VER" == "24.04" ]]; then
        phpIniCli='/etc/php/8.3/cli/php.ini'
        phpIniFpm='/etc/php/8.3/fpm/php.ini'
        phpIniOpcache='/etc/php/8.3/mods-available/opcache.ini'
    else
        phpIniCli='/etc/php/8.2/cli/php.ini'
        phpIniFpm='/etc/php/8.2/fpm/php.ini'
        phpIniOpcache='/etc/php/8.2/mods-available/opcache.ini'
    fi

    # Update php.ini files
    set_php_ini_value "$phpIniCli" "opcache.enable" "1"
    set_php_ini_value "$phpIniCli" "opcache.enable_cli" "1"
    set_php_ini_value "$phpIniCli" "opcache.jit_buffer_size" "100M"
    set_php_ini_value "$phpIniCli" "opcache.jit" "1255"
    set_php_ini_value "$phpIniCli" "session.cookie_secure" "1"
    set_php_ini_value "$phpIniCli" "session.cookie_httponly" "1"
    set_php_ini_value "$phpIniCli" "session.cookie_samesite" "\"Strict\""
    set_php_ini_value "$phpIniCli" "session.cookie_domain" "\"$REGISTRY_DOMAIN,cp.$REGISTRY_DOMAIN,whois.$REGISTRY_DOMAIN\""
    set_php_ini_value "$phpIniCli" "memory_limit" "2G"

    # Repeat the same settings for php-fpm
    set_php_ini_value "$phpIniFpm" "opcache.enable" "1"
    set_php_ini_value "$phpIniFpm" "opcache.enable_cli" "1"
    set_php_ini_value "$phpIniFpm" "opcache.jit_buffer_size" "100M"
    set_php_ini_value "$phpIniFpm" "opcache.jit" "1255"
    set_php_ini_value "$phpIniFpm" "session.cookie_secure" "1"
    set_php_ini_value "$phpIniFpm" "session.cookie_httponly" "1"
    set_php_ini_value "$phpIniFpm" "session.cookie_samesite" "\"Strict\""
    set_php_ini_value "$phpIniFpm" "session.cookie_domain" "\"$REGISTRY_DOMAIN,cp.$REGISTRY_DOMAIN,whois.$REGISTRY_DOMAIN\""
    set_php_ini_value "$phpIniFpm" "memory_limit" "2G"

    # Update opcache.ini
    set_php_ini_value "$phpIniOpcache" "opcache.jit" "1255"
    set_php_ini_value "$phpIniOpcache" "opcache.jit_buffer_size" "100M"

    # Restart PHP-FPM service
    echo "Restarting PHP FPM service..."
    systemctl restart ${PHP_VERSION}-fpm
    echo "PHP configuration update complete!"

    #if [ "$DB_TYPE" == "MariaDB" ]; then
        echo "Setting up MariaDB..."
        curl -o /etc/apt/keyrings/mariadb-keyring.pgp 'https://mariadb.org/mariadb_release_signing_key.pgp'
        
        # Check for Ubuntu 22.04
        if [[ "$OS" == "Ubuntu" && "$VER" == "22.04" ]]; then
            cat > /etc/apt/sources.list.d/mariadb.sources << EOF
    # MariaDB 10.11 repository list - created 2023-12-02 22:16 UTC
    # https://mariadb.org/download/
    X-Repolib-Name: MariaDB
    Types: deb
    # URIs: https://deb.mariadb.org/10.11/ubuntu
    URIs: https://mirrors.chroot.ro/mariadb/repo/10.11/ubuntu
    Suites: jammy
    Components: main main/debug
    Signed-By: /etc/apt/keyrings/mariadb-keyring.pgp
EOF
    elif [[ "$OS" == "Ubuntu" && "$VER" == "24.04" ]]; then
        cat > /etc/apt/sources.list.d/mariadb.list << EOF
    # MariaDB 11.4 repository list - created 2024-07-23 18:24 UTC
    # https://mariadb.org/download/
    deb [signed-by=/etc/apt/keyrings/mariadb-keyring.pgp] https://fastmirror.pp.ua/mariadb/repo/11.4/ubuntu noble main
EOF
        else
            cat > /etc/apt/sources.list.d/mariadb.sources << EOF
    # MariaDB 10.11 repository list - created 2024-01-05 12:23 UTC
    # https://mariadb.org/download/
    X-Repolib-Name: MariaDB
    Types: deb
    # deb.mariadb.org is a dynamic mirror if your preferred mirror goes offline. See https://mariadb.org/mirrorbits/ for details.
    # URIs: https://deb.mariadb.org/10.11/debian
    URIs: https://mirrors.chroot.ro/mariadb/repo/10.11/debian
    Suites: bookworm
    Components: main
    Signed-By: /etc/apt/keyrings/mariadb-keyring.pgp
EOF
        fi

        apt update

        # Install MariaDB and PHP MySQL module
        apt install -y mariadb-client mariadb-server ${PHP_VERSION}-mysql

        # Secure installation of MariaDB
        echo "Please follow the prompts for secure installation of MariaDB."
        $SECURE_INSTALL_CMD

        # Create user and grant privileges
        echo "Creating user $DB_USER and setting privileges..."
        ${DB_COMMAND} -u root -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD';"
        ${DB_COMMAND} -u root -e "GRANT ALL PRIVILEGES ON registry.* TO '$DB_USER'@'localhost';"
        ${DB_COMMAND} -u root -e "GRANT ALL PRIVILEGES ON registryTransaction.* TO '$DB_USER'@'localhost';"
        ${DB_COMMAND} -u root -e "GRANT ALL PRIVILEGES ON registryAudit.* TO '$DB_USER'@'localhost';"
        ${DB_COMMAND} -u root -e "FLUSH PRIVILEGES;"

    #elif [ "$DB_TYPE" == "PostgreSQL" ]; then
    #    echo "Setting up PostgreSQL..."
    #    sh -c 'echo "deb http://apt.postgresql.org/pub/repos/apt $(lsb_release -cs)-pgdg main" > /etc/apt/sources.list.d/pgdg.list'
    #    wget -qO- https://www.postgresql.org/media/keys/ACCC4CF8.asc | tee /etc/apt/trusted.gpg.d/pgdg.asc &>/dev/null
    #    apt update
    #    apt install -y postgresql postgresql-client php8.2-pgsql
    #    psql --version
    #    echo "Configuring PostgreSQL..."
    #    sudo -u postgres psql -c "ALTER USER postgres PASSWORD '$DB_PASSWORD';"
    #    sudo -u postgres psql -c "CREATE DATABASE registry;"
    #    sudo -u postgres psql -c "CREATE DATABASE registryTransaction;"
    #    sudo -u postgres psql -c "CREATE DATABASE registryAudit;"
        
    #    echo "Importing SQL files into PostgreSQL..."
    #    sudo -u postgres psql -U postgres -d registry -f /opt/registry/database/registry.postgres.sql
    #    sudo -u postgres psql -U postgres -d registrytransaction -f /opt/registry/database/registryTransaction.postgres.sql
    #    echo "SQL import completed."
    #fi
    
    mkdir /usr/share/adminer
    wget "http://www.adminer.org/latest.php" -O /usr/share/adminer/latest.php
    ln -s /usr/share/adminer/latest.php /usr/share/adminer/adminer.php

    git clone --branch v1.0.14 --single-branch https://github.com/getnamingo/registry /opt/registry
    mkdir -p /var/log/namingo
    chown -R www-data:www-data /var/log/namingo
    
    echo "Setting up firewall rules..."
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

    # Enable the firewall
    echo "Enabling the firewall..."
    ufw --force enable
    
    # Function to generate bind line
    generate_bind_line() {
        local ipv4=$1
        local ipv6=$2
        local bind_line="bind $ipv4"
        if [ ! -z "$ipv6" ]; then
            bind_line="$bind_line $ipv6"
        fi
        echo $bind_line
    }

    # Prepare bind line
    BIND_LINE=$(generate_bind_line $YOUR_IPV4_ADDRESS $YOUR_IPV6_ADDRESS)

    # Update Caddyfile
    cat > /etc/caddy/Caddyfile << EOF
    rdap.$REGISTRY_DOMAIN {
        $BIND_LINE
        reverse_proxy localhost:7500
        encode zstd gzip
        file_server
        tls $YOUR_EMAIL
        header -Server
        header * {
            Referrer-Policy "no-referrer"
            Strict-Transport-Security max-age=31536000;
            X-Content-Type-Options nosniff
            X-Frame-Options DENY
            X-XSS-Protection "1; mode=block"
            Content-Security-Policy "default-src 'none'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; img-src https:; font-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'none'; form-action 'self'; worker-src 'none'; frame-src 'none';"
            Feature-Policy "accelerometer 'none'; autoplay 'none'; camera 'none'; encrypted-media 'none'; fullscreen 'self'; geolocation 'none'; gyroscope 'none'; magnetometer 'none'; microphone 'none'; midi 'none'; payment 'none'; picture-in-picture 'self'; usb 'none';"
            Permissions-Policy: accelerometer=(), autoplay=(), camera=(), encrypted-media=(), fullscreen=(self), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), midi=(), payment=(), picture-in-picture=(self), usb=();
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
        tls $YOUR_EMAIL
        header -Server
        header * {
            Referrer-Policy "no-referrer"
            Strict-Transport-Security max-age=31536000;
            X-Content-Type-Options nosniff
            X-Frame-Options DENY
            X-XSS-Protection "1; mode=block"
            Content-Security-Policy: default-src 'none'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; img-src https:; font-src 'self'; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; script-src 'none'; form-action 'self'; worker-src 'none'; frame-src 'none';
            Feature-Policy "accelerometer 'none'; autoplay 'none'; camera 'none'; encrypted-media 'none'; fullscreen 'self'; geolocation 'none'; gyroscope 'none'; magnetometer 'none'; microphone 'none'; midi 'none'; payment 'none'; picture-in-picture 'self'; usb 'none';"
            Permissions-Policy: accelerometer=(), autoplay=(), camera=(), encrypted-media=(), fullscreen=(self), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), midi=(), payment=(), picture-in-picture=(self), usb=();
        }
    }

    cp.$REGISTRY_DOMAIN {
        $BIND_LINE
        root * /var/www/cp/public
        php_fastcgi unix//run/php/${PHP_VERSION}-fpm.sock
        encode zstd gzip
        file_server
        tls $YOUR_EMAIL
        header -Server
        log {
            output file /var/log/namingo/caddy.log
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
            Content-Security-Policy: default-src 'none'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; img-src https:; font-src 'self'; style-src 'self' 'unsafe-inline' https://rsms.me; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/; form-action 'self'; worker-src 'none'; frame-src 'none';
            Feature-Policy "accelerometer 'none'; autoplay 'none'; camera 'none'; encrypted-media 'none'; fullscreen 'self'; geolocation 'none'; gyroscope 'none'; magnetometer 'none'; microphone 'none'; midi 'none'; payment 'none'; picture-in-picture 'self'; usb 'none';"
            Permissions-Policy: accelerometer=(), autoplay=(), camera=(), encrypted-media=(), fullscreen=(self), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), midi=(), payment=(), picture-in-picture=(self), usb=();
        }
    }
EOF

    systemctl enable caddy
    systemctl restart caddy
    
    echo "Installing Control Panel."
    mkdir -p /var/www
    cp -r /opt/registry/cp /var/www
    mv /var/www/cp/env-sample /var/www/cp/.env

    # Update .env file with the actual values
    echo "Updating configuration..."
    sed -i "s|https://cp.example.com|https://cp.$REGISTRY_DOMAIN|g" /var/www/cp/.env
    sed -i "s|example.com|$REGISTRY_DOMAIN|g" /var/www/cp/.env
    sed -i "s/DB_USERNAME=root/DB_USERNAME=$DB_USER/g" /var/www/cp/.env
    sed -i "s/DB_PASSWORD=/DB_PASSWORD=$DB_PASSWORD/g" /var/www/cp/.env
    
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
    $DB_COMMAND -u "$DB_USER" -p"$DB_PASSWORD" < /opt/registry/database/registry.mariadb.sql
    echo "SQL import completed."

    echo "Installing Web WHOIS."
    mkdir -p /var/www/whois
    cd /opt/registry/whois/web
    cp -r * /var/www/whois
    cd /var/www/whois
    COMPOSER_ALLOW_SUPERUSER=1 composer require gregwar/captcha --no-interaction --quiet
    mv /var/www/whois/config.php.dist /var/www/whois/config.php
    sed -i "s|'whois_url' => '.*'|'whois_url' => 'whois.${REGISTRY_DOMAIN}'|" /var/www/whois/config.php
    sed -i "s|'rdap_url' => '.*'|'rdap_url' => 'rdap.${REGISTRY_DOMAIN}'|" /var/www/whois/config.php

    echo "Installing WHOIS Server."
    cd /opt/registry/whois/port43
    COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --quiet
    mv /opt/registry/whois/port43/config.php.dist /opt/registry/whois/port43/config.php
    sed -i "s|'db_username' => 'your_username'|'db_username' => '$DB_USER'|g" /opt/registry/whois/port43/config.php
    sed -i "s|'db_password' => 'your_password'|'db_password' => '$DB_PASSWORD'|g" /opt/registry/whois/port43/config.php
    sed -i "s/User=root/User=$current_user/" /opt/registry/docs/whois.service
    sed -i "s/Group=root/Group=$current_user/" /opt/registry/docs/whois.service
    cp /opt/registry/docs/whois.service /etc/systemd/system/
    systemctl daemon-reload
    systemctl enable whois.service

    echo "Installing RDAP Server."
    cd /opt/registry/rdap
    COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --quiet
    mv /opt/registry/rdap/config.php.dist /opt/registry/rdap/config.php
    sed -i "s|'db_username' => 'your_username'|'db_username' => '$DB_USER'|g" /opt/registry/rdap/config.php
    sed -i "s|'db_password' => 'your_password'|'db_password' => '$DB_PASSWORD'|g" /opt/registry/rdap/config.php
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

    echo "Installing DAS Server."
    cd /opt/registry/das
    COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --quiet
    mv /opt/registry/das/config.php.dist /opt/registry/das/config.php
    sed -i "s|'db_username' => 'your_username'|'db_username' => '$DB_USER'|g" /opt/registry/das/config.php
    sed -i "s|'db_password' => 'your_password'|'db_password' => '$DB_PASSWORD'|g" /opt/registry/das/config.php
    sed -i "s/User=root/User=$current_user/" /opt/registry/docs/das.service
    sed -i "s/Group=root/Group=$current_user/" /opt/registry/docs/das.service
    cp /opt/registry/docs/das.service /etc/systemd/system/
    systemctl daemon-reload
    systemctl enable das.service

    echo "Installing Message Broker."
    cp /opt/registry/docs/msg_producer.service /etc/systemd/system/
    cp /opt/registry/docs/msg_worker.service /etc/systemd/system/
    systemctl daemon-reload
    systemctl enable msg_producer
    systemctl enable msg_worker

    echo "Enabling Redis."
    systemctl enable redis
    systemctl start redis

    echo "Configuring control panel admin."
    sed -i "s|\$email = 'admin@example.com';|\$email = '$PANEL_EMAIL';|g" /var/www/cp/bin/create_admin_user.php
    sed -i "s|\$newPW = 'admin_password';|\$newPW = '$PANEL_PASSWORD';|g" /var/www/cp/bin/create_admin_user.php
    php /var/www/cp/bin/create_admin_user.php

    echo "Downloading initial data."
    php /var/www/cp/bin/file_cache.php
    echo "Setting up cache."
    chown www-data:www-data /var/www/cp/cache

    echo -e "Installation complete!\n"
    echo -e "Next steps:\n"
    echo -e "1. Configure each component by editing their respective configuration files."
    echo -e "2. Once configuration is complete, start each service with the following command:\n   systemctl start SERVICE_NAME.service\n   Replace 'SERVICE_NAME' with the specific service (whois, rdap, epp, das) as needed."
    echo -e "3. To initiate the automation system, please refer to the configuration manual.\n"
    echo -e "For more detailed information, please consult the accompanying documentation or support resources."
else
    echo "Unsupported Linux distribution or version"
fi
