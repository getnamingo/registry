#!/bin/bash

# Function to prompt for user input
prompt_for_input() {
    read -p "$1: " response
    echo $response
}

# Function to edit or add a configuration line in php.ini
edit_php_ini() {
    local file=$1
    local setting=$2
    local value=$3
    if grep -q "^;\?\s*${setting}\s*=" "$file"; then
        sed -i "s/^\(;?\s*${setting}\s*=\).*/\1 ${value}/" "$file"
    else
        echo "${setting} = ${value}" >> "$file"
    fi
}

# Check the Linux distribution and version
if [[ -e /etc/os-release ]]; then
    . /etc/os-release
    OS=$NAME
    VER=$VERSION_ID
fi

# Proceed if it's Ubuntu 22.04 or Debian 12
if [[ ("$OS" == "Ubuntu" && "$VER" == "22.04") || ("$OS" == "Debian GNU/Linux" && "$VER" == "12") ]]; then
    # Prompt for details
    REGISTRY_DOMAIN=$(prompt_for_input "Enter main domain for registry")
    YOUR_IPV4_ADDRESS=$(prompt_for_input "Enter your IPv4 address")
    YOUR_IPV6_ADDRESS=$(prompt_for_input "Enter your IPv6 address (leave blank if not available)")
    YOUR_EMAIL=$(prompt_for_input "Enter your email for TLS")
    DB_TYPE=$(prompt_for_input "Enter preferred database type (MariaDB/PostgreSQL)")
    DB_USER=$(prompt_for_input "Enter database user")
    DB_PASSWORD=$(prompt_for_input "Enter database password")
    PANEL_USER=$(prompt_for_input "Enter panel user")
    PANEL_PASSWORD=$(prompt_for_input "Enter panel password")

    # Step 1 - Components Installation
    echo "Installing required packages..."
    apt install -y curl software-properties-common ufw
    echo "Adding PHP repository..."
    add-apt-repository ppa:ondrej/php
    apt install -y debian-keyring debian-archive-keyring apt-transport-https
    echo "Setting up Caddy repository..."
    curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' -o caddy-stable.gpg.key
    gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg caddy-stable.gpg.key
    curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | tee /etc/apt/sources.list.d/caddy-stable.list
    echo "Updating package lists and upgrading packages..."
    apt update && apt upgrade
    echo "Installing additional required packages..."
    apt install -y bzip2 caddy composer gettext git gnupg2 net-tools php8.2 php8.2-cli php8.2-common php8.2-curl php8.2-ds php8.2-fpm php8.2-gd php8.2-gmp php8.2-gnupg php8.2-igbinary php8.2-imap php8.2-intl php8.2-mbstring php8.2-opcache php8.2-readline php8.2-redis php8.2-soap php8.2-swoole php8.2-uuid php8.2-xml pv redis unzip wget whois
    
    # Set timezone to UTC if it's not already
    currentTimezone=$(timedatectl status | grep "Time zone" | awk '{print $3}')
    if [ "$currentTimezone" != "UTC" ]; then
        echo "Setting timezone to UTC..."
        timedatectl set-timezone UTC
    fi

    # Edit php.ini files
    phpIniCli='/etc/php/8.2/cli/php.ini'
    phpIniFpm='/etc/php/8.2/fpm/php.ini'

    echo "Updating PHP configuration..."
    for file in "$phpIniCli" "$phpIniFpm"; do
        edit_php_ini "$file" "opcache.enable" "1"
        edit_php_ini "$file" "opcache.enable_cli" "1"
        edit_php_ini "$file" "opcache.jit_buffer_size" "100M"
        edit_php_ini "$file" "opcache.jit" "1255"
        edit_php_ini "$file" "session.cookie_secure" "1"
        edit_php_ini "$file" "session.cookie_httponly" "1"
        edit_php_ini "$file" "session.cookie_samesite" "\"Strict\""
        edit_php_ini "$file" "session.cookie_domain" "example.com"
        edit_php_ini "$file" "memory_limit" "512M"
    done

    # Restart PHP-FPM service
    echo "Restarting PHP 8.2-FPM service..."
    systemctl restart php8.2-fpm
    echo "PHP configuration update complete!"
    
    if [ "$DB_TYPE" == "MariaDB" ]; then
        echo "Setting up MariaDB..."
        curl -o /etc/apt/keyrings/mariadb-keyring.pgp 'https://mariadb.org/mariadb_release_signing_key.pgp'
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
        apt-get update
        apt install -y mariadb-client mariadb-server php8.2-mysql
        echo "Please follow the prompts for secure installation of MariaDB."
        mysql_secure_installation
        
        # Import SQL file into MariaDB, which includes database creation
        echo "Importing SQL file into MariaDB..."
        mysql -u root < /opt/registry/database/registry.mariadb.sql
        echo "SQL import completed."

        # Create user and grant privileges
        echo "Creating user $DB_USER and setting privileges..."
        mysql -u root -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD';"
        mysql -u root -e "GRANT ALL PRIVILEGES ON registry.* TO '$DB_USER'@'localhost';"
        mysql -u root -e "GRANT ALL PRIVILEGES ON registryTransaction.* TO '$DB_USER'@'localhost';"
        mysql -u root -e "GRANT ALL PRIVILEGES ON registryAudit.* TO '$DB_USER'@'localhost';"
        mysql -u root -e "FLUSH PRIVILEGES;"

    elif [ "$DB_TYPE" == "PostgreSQL" ]; then
        echo "Setting up PostgreSQL..."
        sh -c 'echo "deb http://apt.postgresql.org/pub/repos/apt $(lsb_release -cs)-pgdg main" > /etc/apt/sources.list.d/pgdg.list'
        wget -qO- https://www.postgresql.org/media/keys/ACCC4CF8.asc | tee /etc/apt/trusted.gpg.d/pgdg.asc &>/dev/null
        apt update
        apt install -y postgresql postgresql-client php8.2-pgsql
        psql --version
        echo "Configuring PostgreSQL..."
        sudo -u postgres psql -c "ALTER USER postgres PASSWORD '$DB_PASSWORD';"
        sudo -u postgres psql -c "CREATE DATABASE registry;"
    fi
    
    mkdir /usr/share/adminer
    wget "http://www.adminer.org/latest.php" -O /usr/share/adminer/latest.php
    ln -s /usr/share/adminer/latest.php /usr/share/adminer/adminer.php
    
    git clone https://github.com/getnamingo/registry /opt/registry
    mkdir -p /var/log/namingo
    chown -R www-data:www-data /var/log/namingo
    
    echo "Setting up firewall rules..."
    ufw allow 43/tcp
    ufw allow 80/tcp
    ufw allow 80/udp
    ufw allow 443/tcp
    ufw allow 443/udp
    ufw allow 700/tcp
    ufw allow 700/udp
    ufw allow 53/tcp
    ufw allow 53/udp

    # Enable the firewall
    echo "Enabling the firewall..."
    ufw enable
    
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
        encode gzip
        file_server
        tls $YOUR_EMAIL
        header * {
            Referrer-Policy "no-referrer"
            Strict-Transport-Security max-age=31536000;
            X-Content-Type-Options nosniff
            X-Frame-Options DENY
            X-XSS-Protection "1; mode=block"
            Content-Security-Policy "default-src 'none'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; img-src https:; font-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'none'; form-action 'self'; worker-src 'none'; frame-src 'none';"
            Feature-Policy "accelerometer 'none'; ambient-light-sensor 'none'; autoplay 'none'; camera 'none'; encrypted-media 'none'; fullscreen 'self'; geolocation 'none'; gyroscope 'none'; magnetometer 'none'; microphone 'none'; midi 'none'; payment 'none'; picture-in-picture 'self'; speaker 'none'; usb 'none'; vr 'none';"
            Permissions-Policy: accelerometer=(), ambient-light-sensor=(), autoplay=(), camera=(), encrypted-media=(), fullscreen=(self), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), midi=(), payment=(), picture-in-picture=(self), speaker=(), usb=(), vr=();
        }
    }

    whois.$REGISTRY_DOMAIN {
        $BIND_LINE
        root * /var/www/whois
        encode gzip
        php_fastcgi unix//run/php/php8.2-fpm.sock
        file_server
        tls $YOUR_EMAIL
        header * {
            Referrer-Policy "no-referrer"
            Strict-Transport-Security max-age=31536000;
            X-Content-Type-Options nosniff
            X-Frame-Options DENY
            X-XSS-Protection "1; mode=block"
            Content-Security-Policy: default-src 'none'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; img-src https:; font-src 'self'; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; script-src 'none'; form-action 'self'; worker-src 'none'; frame-src 'none';
            Feature-Policy "accelerometer 'none'; ambient-light-sensor 'none'; autoplay 'none'; camera 'none'; encrypted-media 'none'; fullscreen 'self'; geolocation 'none'; gyroscope 'none'; magnetometer 'none'; microphone 'none'; midi 'none'; payment 'none'; picture-in-picture 'self'; speaker 'none'; usb 'none'; vr 'none';"
            Permissions-Policy: accelerometer=(), ambient-light-sensor=(), autoplay=(), camera=(), encrypted-media=(), fullscreen=(self), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), midi=(), payment=(), picture-in-picture=(self), speaker=(), usb=(), vr=();
        }
    }

    cp.$REGISTRY_DOMAIN {
        $BIND_LINE
        root * /var/www/cp/public
        php_fastcgi unix//run/php/php8.2-fpm.sock
        encode gzip
        file_server
        tls $YOUR_EMAIL
        log {
            output file /var/log/caddy/access.log
            format console
        }
        log {
            output file /var/log/caddy/error.log
            level ERROR
        }
        # Adminer Configuration
        route /adminer.php* {
            root * /usr/share/adminer
            php_fastcgi unix//run/php/php8.2-fpm.sock
        }
        header * {
            Referrer-Policy "same-origin"
            Strict-Transport-Security max-age=31536000;
            X-Content-Type-Options nosniff
            X-Frame-Options DENY
            X-XSS-Protection "1; mode=block"
            Content-Security-Policy: default-src 'none'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; img-src https:; font-src 'self'; style-src 'self' 'unsafe-inline' https://rsms.me; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/; form-action 'self'; worker-src 'none'; frame-src 'none';
            Feature-Policy "accelerometer 'none'; ambient-light-sensor 'none'; autoplay 'none'; camera 'none'; encrypted-media 'none'; fullscreen 'self'; geolocation 'none'; gyroscope 'none'; magnetometer 'none'; microphone 'none'; midi 'none'; payment 'none'; picture-in-picture 'self'; speaker 'none'; usb 'none'; vr 'none';"
            Permissions-Policy: accelerometer=(), ambient-light-sensor=(), autoplay=(), camera=(), encrypted-media=(), fullscreen=(self), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), midi=(), payment=(), picture-in-picture=(self), speaker=(), usb=(), vr=();
        }
    }
    EOF
    
    systemctl enable caddy
    systemctl restart caddy
    
    cp -r /opt/registry/cp /var/www/
    
    # Rename env-sample to .env
    echo "Control Panel Setup..."
    mv /var/www/cp/env-sample /var/www/cp/.env

    # Update .env file with the actual values
    echo "Updating configuration..."
    sed -i "s|https://cp.example.com|https://cp.$REGISTRY_DOMAIN|g" /var/www/cp/.env
    sed -i "s|example.com|$REGISTRY_DOMAIN|g" /var/www/cp/.env
    sed -i "s/DB_USERNAME=root/DB_USERNAME=$DB_USER/g" /var/www/cp/.env
    sed -i "s/DB_PASSWORD=/DB_PASSWORD=$DB_PASSWORD/g" /var/www/cp/.env

    cd /var/www/cp
    composer install
    echo "Control Panel configured."
    
    create_admin_user.php
    
    mkdir -p /var/www/whois
    cd /opt/registry/whois/web
    cp -r * /var/www/whois
    cd /var/www/whois
    composer require gregwar/captcha

    echo "Installation complete!"

    # You can add more commands here based on your requirements
else
    echo "Unsupported Linux distribution or version"
fi
