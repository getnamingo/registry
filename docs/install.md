# Installation & Usage

## 1. Install the required packages:

```bash
add-apt-repository ppa:ondrej/php
apt install -y debian-keyring debian-archive-keyring apt-transport-https
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' -o caddy-stable.gpg.key
gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg caddy-stable.gpg.key
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | tee /etc/apt/sources.list.d/caddy-stable.list
apt update && apt upgrade
apt install -y bzip2 caddy composer curl gettext git gnupg2 net-tools php8.2 php8.2-bcmath php8.2-cli php8.2-common php8.2-curl php8.2-ds php8.2-fpm php8.2-gd php8.2-gmp php8.2-gnupg php8.2-imap php8.2-intl php8.2-mbstring php8.2-opcache php8.2-readline php8.2-redis php8.2-soap php8.2-swoole php8.2-xml pv redis unzip wget whois
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

After configuring PHP, restart the service to apply changes:

```bash
systemctl restart php8.2-fpm
```

## 2. Database installation (please choose one):

### 2a. Install and configure MariaDB:

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

### 2b. Install and configure PostgreSQL:

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

Import the provided database file for your database type.

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

## 6. Configure Caddy webserver:

Edit ```/etc/caddy/Caddyfile``` and place the following content:

```
rdap.example.com {
    bind YOUR_IPV4_ADDRESS YOUR_IPV6_ADDRESS
    reverse_proxy localhost:7500
    encode gzip
    file_server
    tls your-email@example.com
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

whois.example.com {
    bind YOUR_IPV4_ADDRESS YOUR_IPV6_ADDRESS
    root * /var/www/whois
    encode gzip
    php_fastcgi unix//run/php/php8.2-fpm.sock
    file_server
    tls your-email@example.com
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

cp.example.com {
    bind NEW_IPV4_ADDRESS NEW_IPV6_ADDRESS
    root * /var/www/cp/public
    php_fastcgi unix//run/php/php8.2-fpm.sock
    encode gzip
    file_server
    tls your-email@example.com
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
```

Activate and reload Caddy:

```bash
systemctl enable caddy
systemctl restart caddy
```

## 7. Control Panel Setup:

Use a file management tool or command line to copy the entire ```registry/cp/``` directory and place it into the web server's root directory, typically ```/var/www/```. The target path should be ```/var/www/cp/```.

```bash
cp -r /path/to/registry/cp /var/www/
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

## 8. Setup Web WHOIS:

```bash
mkdir -p /var/www/whois
cd /path/to/registry/whois/web
cp -r * /var/www/whois
```

Change your working directory to ```/var/www/whois/``` using a command line interface. This can be done with the command ```cd /var/www/whois/```.

Once in the correct directory, run the following command to install necessary dependencies:

```bash
composer require gregwar/captcha
```

This command will install the **gregwar/captcha** package, which is required for the WHOIS web interface functionality.

## 9. Setup WHOIS:

```bash
cd /opt/registry/whois/port43
composer install
mv config.php.dist config.php
```

Configure all options in ```config.php``` and run ```php start_whois.php &```

## 10. Setup RDAP:

```bash
cd /opt/registry/rdap
composer install
mv config.php.dist config.php
```

Configure all options in ```config.php``` and run ```php start_rdap.php &```

## 11. Setup EPP:

```bash
cd /opt/registry/epp
composer install
mv config.php.dist config.php
```

Configure all options in ```config.php``` and run ```php start_epp.php &```

To create test certificates (cert.pem and key.pem):

```bash
openssl genrsa -out key.pem 2048
openssl req -new -x509 -key key.pem -out cert.pem -days 365
```

## 12. Setup Automation Scripts:

```bash
cd /opt/registry/automation
composer install
mv config.php.dist config.php
```

Configure all options in ```config.php```.

### Install Optional Dependencies:

Execute one of the following commands to install the optional dependencies:

```bash
composer require utopia-php/messaging
```

or

```bash
composer require phpmailer/phpmailer
```

This command will install one of the packages which are essential for the message broker script to function correctly.

### Configuring the Crontab for Automation Scripts

To set up automated tasks for Namingo, open the example crontab file located at ```/opt/registry/automation/crontab.example```. Review the contents and copy the relevant lines into your system's crontab file. Remember to adjust the paths and timings as necessary to suit your environment.

### Running the `messagebroker.php` Script in the Background

To run the messagebroker.php script as a background process, execute the following command: ```/usr/bin/php /opt/registry/automation/messagebroker.php &```. This will start the script and place it in the background, allowing it to run independently of your current terminal session.

### Setting Up an Audit Trail Database for Namingo

To create an audit trail database for Namingo, start by editing the configuration file located at `/opt/registry/automation/audit.json` with the correct database details. This includes specifying the database connection parameters such as host, username, and password. Once your configuration is set up, create a new database named `registryAudit`. After the database is created, run the command:

```bash
/opt/registry/automation/vendor/bin/audit -v audit /opt/registry/automation/audit.json
```

This will initialize and configure the audit trail functionality. This process ensures that all necessary tables and structures are set up in the registryAudit database, enabling comprehensive auditing of Namingo's operations.

**Currently, the audit trail setup for Namingo is supported only with MySQL or MariaDB databases. If you're using PostgreSQL, you'll need to utilize an external tool for audit logging, such as [pgAudit](https://minervadb.com/index.php/pgaudit-open-source-postgresql-audit-logging/), which provides detailed audit logging capabilities tailored for PostgreSQL environments.**

### Setup Backup

To ensure the safety and availability of your data in Namingo, it's crucial to set up and verify automated backups. Begin by editing the ```backup.json``` file in the automation directory, where you'll input your database details and specify the SFTP server information for offsite backup storage. Ensure that the details for the database and the SFTP server, including server address, credentials, and port, are accurately entered in two specified locations within the ```backup.json``` file.

Additionally, check that the cronjob for PHPBU is correctly scheduled on your server, as this automates the backup process. You can verify this by reviewing your server's cronjob list. These steps are vital to maintain regular, secure backups of your system, safeguarding against data loss and ensuring business continuity. 

### RDE (Registry data escrow) configuration:

#### Generate the Key Pair:

Create a configuration file, say key-config, with the following content:

```yaml
%echo Generating a default key
Key-Type: RSA
Key-Length: 2048
Subkey-Type: RSA
Subkey-Length: 2048
Name-Real: Your Name
Name-Comment: Your Comment
Name-Email: your.email@example.com
Expire-Date: 0
%no-protection
%commit
%echo done
```

Replace "Your Name", "Your Comment", and "your.email@example.com" with your details.

Use the following command to generate the key:

```bash
gpg2 --batch --generate-key key-config
```

Your GPG key pair will now be generated.

#### Exporting Your Keys:

Public key:

```bash
gpg2 --armor --export your.email@example.com > publickey.asc
```

Replace `your-email@example.com` with the email address you used when generating the key.

Private key:

```bash
gpg2 --armor --export-secret-keys your.email@example.com > privatekey.asc
```

#### Secure Your Private Key:

Always keep your private key secure. Do not share it. If someone gains access to your private key, they can impersonate you in cryptographic operations.

#### Use in RDE deposit generation:

Please send the exported `publickey.asc` to your RDE provider, and also place the path to `privatekey.asc` in the escrow.php system as required.

## 13. Setup DAS:

```bash
cd /opt/registry/das
composer install
mv config.php.dist config.php
```

Configure all options in ```config.php``` and run ```php start_das.php &```