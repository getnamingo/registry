# Installation (Deprecated)

Welcome to the Installation Guide for the Namingo domain registry platform. Note: The manual installation process is now deprecated. We highly recommend using the automated installer available at [https://namingo.org](https://namingo.org) for a streamlined and hassle-free setup experience.

After completing the installation, please refer to the [Configuration Guide](configuration.md) to tailor the system to your specific requirements. Once configured, visit the [Initial Operation Guide](iog.md) for detailed instructions on how to set up your registry, add registrars, and perform other essential operational tasks.

***To upgrade from v1.0.0-RC4 or v1.0.0-RC5, please see our [upgrade guide](upgrade.md)***

## 1. Install the required packages:

```bash
apt install -y curl software-properties-common ufw
add-apt-repository ppa:ondrej/php
apt install -y debian-keyring debian-archive-keyring apt-transport-https
apt update
apt install -y bzip2 composer gettext git gnupg2 net-tools php8.2 php8.2-cli php8.2-common php8.2-curl php8.2-ds php8.2-fpm php8.2-gd php8.2-gmp php8.2-gnupg php8.2-igbinary php8.2-imap php8.2-intl php8.2-mbstring php8.2-opcache php8.2-readline php8.2-redis php8.2-soap php8.2-swoole php8.2-uuid php8.2-xml pv redis unzip wget whois
```

Then install the webserver you prefer:

### 1a. Install Caddy webserver:

```bash
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' -o caddy-stable.gpg.key
gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg caddy-stable.gpg.key
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | tee /etc/apt/sources.list.d/caddy-stable.list
apt update
apt install -y caddy
```

### 1b. Install Nginx webserver:

```bash
add-apt-repository ppa:ondrej/nginx-mainline
apt update
apt install -y nginx python3-certbot-nginx
```

### 1c. Install Apache2 webserver:

```bash
add-apt-repository ppa:ondrej/apache2
apt update
apt install -y apache2 python3-certbot-apache
```

### Configure time:

Make sure your server is set to UTC:

```bash
timedatectl status
```

If your server is not set to UTC, you can change it using the ```timedatectl``` command:

```bash
timedatectl set-timezone UTC
timedatectl status
```

### Configure PHP:

Edit the PHP Configuration Files:

```bash
nano /etc/php/8.2/cli/php.ini
nano /etc/php/8.2/fpm/php.ini
```

Locate or add these lines in ```php.ini```, also replace ```example.com``` with your registry domain name:

```bash
opcache.enable=1
opcache.enable_cli=1
opcache.jit_buffer_size=100M
opcache.jit=1255

session.cookie_secure = 1
session.cookie_httponly = 1
session.cookie_samesite = "Strict"
session.cookie_domain = example.com
```

In ```/etc/php/8.2/fpm/php.ini``` make one additional change.

If you have about 10000 domains, use:

```bash
memory_limit = 512M
```

If you have 50000 or more domains, use:

```bash
memory_limit = -1
```

In ```/etc/php/8.2/mods-available/opcache.ini``` make one additional change:

```bash
opcache.jit=1255
opcache.jit_buffer_size=100M
```

After configuring PHP, restart the service to apply changes:

```bash
systemctl restart php8.2-fpm
```

## 2. Database installation (please choose one):

### 2a. Install and configure MariaDB: (please use this for v1.0)

```bash
curl -o /etc/apt/keyrings/mariadb-keyring.pgp 'https://mariadb.org/mariadb_release_signing_key.pgp'
```

Place the following in ```/etc/apt/sources.list.d/mariadb.sources```:

```bash
# MariaDB 10.11 repository list - created 2023-12-02 22:16 UTC
# https://mariadb.org/download/
X-Repolib-Name: MariaDB
Types: deb
# deb.mariadb.org is a dynamic mirror if your preferred mirror goes offline. See https://mariadb.org/mirrorbits/ for details.
# URIs: https://deb.mariadb.org/10.11/ubuntu
URIs: https://mirrors.chroot.ro/mariadb/repo/10.11/ubuntu
Suites: jammy
Components: main main/debug
Signed-By: /etc/apt/keyrings/mariadb-keyring.pgp
```

```bash
apt-get update
apt install -y mariadb-client mariadb-server php8.2-mysql
mysql_secure_installation
```

[Tune your MariaDB](https://github.com/major/MySQLTuner-perl)

### 2b. Install and configure PostgreSQL: (beta!)

```bash
sh -c 'echo "deb http://apt.postgresql.org/pub/repos/apt $(lsb_release -cs)-pgdg main" > /etc/apt/sources.list.d/pgdg.list'
wget -qO- https://www.postgresql.org/media/keys/ACCC4CF8.asc | tee /etc/apt/trusted.gpg.d/pgdg.asc &>/dev/null
apt update
apt install -y postgresql postgresql-client php8.2-pgsql
psql --version
```

Now you need to update PostgreSQL Admin User Password:

```bash
sudo -u postgres psql
postgres=#
postgres=# ALTER USER postgres PASSWORD 'demoPassword';
postgres=# CREATE DATABASE registry;
postgres=# CREATE DATABASE registryTransaction;
postgres=# CREATE DATABASE registryAudit;
postgres=# \q
```

[Tune your PostgreSQL](https://pgtune.leopard.in.ua/)

### 2c. Database Replication Setup:

For those considering implementing replication in their Namingo installation, it is highly recommended for enhancing data availability and reliability. We have prepared a detailed guide to walk you through the replication setup process. Please refer to our comprehensive guide for setting up and managing replication by following the link: [Replication Setup Guide](replication.md).

### 2d. Database Encryption Setup:

To ensure the security and confidentiality of your data within the Namingo system, implementing database encryption is a crucial step. Database encryption helps protect sensitive information from unauthorized access and breaches. We have compiled an in-depth guide that covers the essentials of database encryption, including key management, best practices, and step-by-step instructions for secure implementation. For a thorough understanding and to begin securing your data, please refer to our detailed guide: [Database Encryption Guide](encryption.md). This resource is designed to equip you with the knowledge and tools necessary for effectively encrypting your database in the Namingo environment.

## 3. Install Adminer:

```bash
mkdir /usr/share/adminer
wget "http://www.adminer.org/latest.php" -O /usr/share/adminer/latest.php
ln -s /usr/share/adminer/latest.php /usr/share/adminer/adminer.php
```

## 4. Download Namingo:

First, clone the Namingo registry repository into the `/opt/registry` directory:

```bash
git clone https://github.com/getnamingo/registry /opt/registry
```

Next, create the directory for Namingo logs. This directory will be used to store log files generated by the Namingo registry:

```bash
mkdir -p /var/log/namingo
chown -R www-data:www-data /var/log/namingo
```

## 5. Configuring UFW Firewall:

To securely set up the UFW (Uncomplicated Firewall) for your registry, follow these commands:

```bash
ufw allow 80/tcp
ufw allow 80/udp
ufw allow 443/tcp
ufw allow 443/udp
ufw allow 700/tcp
ufw allow 700/udp
ufw allow 43/tcp
ufw allow 43/udp
ufw allow 53/tcp
ufw allow 53/udp
```

## 6. Configure webserver:

### 6a. Caddy:

Edit ```/etc/caddy/Caddyfile``` and place the following content:

```
rdap.example.com {
    bind YOUR_IPV4_ADDRESS YOUR_IPV6_ADDRESS
    reverse_proxy localhost:7500
    encode gzip
    file_server
    tls your-email@example.com
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
    }
}

whois.example.com {
    bind YOUR_IPV4_ADDRESS YOUR_IPV6_ADDRESS
    root * /var/www/whois
    encode gzip
    php_fastcgi unix//run/php/php8.2-fpm.sock
    file_server
    tls your-email@example.com
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

cp.example.com {
    bind NEW_IPV4_ADDRESS NEW_IPV6_ADDRESS
    root * /var/www/cp/public
    php_fastcgi unix//run/php/php8.2-fpm.sock
    encode gzip
    file_server
    tls your-email@example.com
    header -Server
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
        Feature-Policy "accelerometer 'none'; autoplay 'none'; camera 'none'; encrypted-media 'none'; fullscreen 'self'; geolocation 'none'; gyroscope 'none'; magnetometer 'none'; microphone 'none'; midi 'none'; payment 'none'; picture-in-picture 'self'; usb 'none';"
        Permissions-Policy: accelerometer=(), autoplay=(), camera=(), encrypted-media=(), fullscreen=(self), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), midi=(), payment=(), picture-in-picture=(self), usb=();
    }
}
```

Activate and reload Caddy:

```bash
systemctl enable caddy
systemctl restart caddy
```

### 6b. Nginx:

Move configuration files and create symbolic links:

```bash
mv /opt/registry/docs/nginx/cp.conf /etc/nginx/sites-available/
ln -s /etc/nginx/sites-available/cp.conf /etc/nginx/sites-enabled/

mv /opt/registry/docs/nginx/whois.conf /etc/nginx/sites-available/
ln -s /etc/nginx/sites-available/whois.conf /etc/nginx/sites-enabled/

mv /opt/registry/docs/nginx/rdap.conf /etc/nginx/sites-available/
ln -s /etc/nginx/sites-available/rdap.conf /etc/nginx/sites-enabled/

rm /etc/nginx/sites-enabled/default
```

Edit all 3 files that you just moved in `/etc/nginx/sites-available`, and replace `server_name` with the correct hostname for the service; also replace `YOUR_IPV4_ADDRESS` and/or `YOUR_IPV6_ADDRESS` accordingly.

Generate the required SSL certificates:

```bash
systemctl stop nginx
certbot --nginx -d whois.example.com -d rdap.example.com -d cp.example.com
```

Activate and reload Nginx:

```bash
systemctl enable nginx
systemctl restart nginx
```

### 6c. Apache2:

Move configuration files and create symbolic links:

```bash
mv /opt/registry/docs/apache2/cp.conf /etc/apache2/sites-available/
ln -s /etc/apache2/sites-available/cp.conf /etc/apache2/sites-enabled/

mv /opt/registry/docs/apache2/whois.conf /etc/apache2/sites-available/
ln -s /etc/apache2/sites-available/whois.conf /etc/apache2/sites-enabled/

mv /opt/registry/docs/apache2/rdap.conf /etc/apache2/sites-available/
ln -s /etc/apache2/sites-available/rdap.conf /etc/apache2/sites-enabled/

rm /etc/apache2/sites-enabled/000-default.conf
```

Edit all 3 files that you just moved in `/etc/apache2/sites-available`, and replace `server_name` with the correct hostname for the service.

Generate the required SSL certificates:

```bash
a2enmod headers proxy proxy_http proxy_fcgi setenvif rewrite
systemctl restart apache2
systemctl stop apache2
certbot --apache -d whois.example.com -d rdap.example.com -d cp.example.com
```

Activate and reload Apache2:

```bash
systemctl enable apache2
systemctl restart apache2
```

_________________

**And now is the right time to import the provided database file(s) for your database type using Adminer.**

## 7. Control Panel Setup:

Use a file management tool or command line to copy the entire ```registry/cp/``` directory and place it into the web server's root directory, typically ```/var/www/```. The target path should be ```/var/www/cp/```.

```bash
cp -r /opt/registry/cp /var/www
```

### Configure Environment File:

Open your command line interface and navigate to the ```cp``` (control panel) directory.

Locate the file named ```env-sample``` (```/var/www/cp/env-sample```) in the control panel (```cp```) directory.

Rename this file to ```.env``` and update the settings within this file to suit your specific environment and application needs.

### Install Dependencies:

Run the following command to install the required dependencies:

```bash
composer install
```

This command will install the dependencies defined in your ```composer.json``` file, ensuring that your control panel has all the necessary components to operate effectively.

### Creating an Admin User:

1. Navigate to the 'bin' Directory: Change to the 'bin' subdirectory where the admin user creation script is located. (```create_admin_user.php```)

2. Update Admin User Details: Open the script and enter the desired details for the admin user, such as email, username, and password.

3. Execute the Script: Run the script to create the admin user in your system.

4. Verify Admin Access: Attempt to log in with the new admin credentials to ensure they are functioning correctly.

5. Remove the Script: Once verified, delete the script to maintain system security.

### Download TLD List:

To get the starting list of TLDs (Top-Level Domains) from ICANN and cache it for quick access later, please run the following command:

```bash
php /var/www/cp/bin/file_cache.php
```

### Setup Cache Directory:

To setup the correct owner of the panel cache directory, please run the following command:

```bash
chown www-data:www-data /var/www/cp/cache
```

## 8. Setup Web Lookup:

```bash
mkdir -p /var/www/whois
cd /opt/registry/whois/web
cp -r * /var/www/whois
cd /var/www/whois/
composer require gregwar/captcha
mv config.php.dist config.php
```

- Configure all options in ```config.php```.

## 9. Setup WHOIS:

```bash
cd /opt/registry/whois/port43
composer install
mv config.php.dist config.php
```

- Configure all options in ```config.php```.

- Copy ```docs/whois.service``` to ```/etc/systemd/system/```. Change only User and Group lines to your user and group.

```bash
systemctl daemon-reload
systemctl start whois.service
systemctl enable whois.service
```

After that you can manage WHOIS via systemctl as any other service.

## 10. Setup RDAP:

```bash
cd /opt/registry/rdap
composer install
mv config.php.dist config.php
```

- Configure all options in ```config.php```.

- Copy ```docs/rdap.service``` to ```/etc/systemd/system/```. Change only User and Group lines to your user and group.

```bash
systemctl daemon-reload
systemctl start rdap.service
systemctl enable rdap.service
```

After that you can manage RDAP via systemctl as any other service.

## 11. Setup EPP:

```bash
cd /opt/registry/epp
composer install
mv config.php.dist config.php
```

Configure all options in ```config.php```.

To create test certificates (cert.pem and key.pem):

```bash
openssl genrsa -out key.pem 2048
openssl req -new -x509 -key key.pem -out cert.pem -days 365
```

- Copy ```docs/epp.service``` to ```/etc/systemd/system/```. Change only User and Group lines to your user and group.

```bash
systemctl daemon-reload
systemctl start epp.service
systemctl enable epp.service
```

After that you can manage EPP via systemctl as any other service.

## 12. Setup Automation Scripts:

```bash
cd /opt/registry/automation
composer install
mv config.php.dist config.php
```

Configure all options in ```config.php```.

## 13. Setup DAS:

```bash
cd /opt/registry/das
composer install
mv config.php.dist config.php
```

Configure all options in ```config.php```.

- Copy ```docs/das.service``` to ```/etc/systemd/system/```. Change only User and Group lines to your user and group.

```bash
systemctl daemon-reload
systemctl start das.service
systemctl enable das.service
```

After that you can manage DAS via systemctl as any other service.