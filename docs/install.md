# Installation & Usage

## 1. Install the required packages:

```bash
apt install -y curl software-properties-common ufw
add-apt-repository ppa:ondrej/php
apt install -y debian-keyring debian-archive-keyring apt-transport-https
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' -o caddy-stable.gpg.key
gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg caddy-stable.gpg.key
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | tee /etc/apt/sources.list.d/caddy-stable.list
apt update && apt upgrade
apt install -y bzip2 caddy composer gettext git gnupg2 net-tools php8.2 php8.2-cli php8.2-common php8.2-curl php8.2-ds php8.2-fpm php8.2-gd php8.2-gmp php8.2-gnupg php8.2-igbinary php8.2-imap php8.2-intl php8.2-mbstring php8.2-opcache php8.2-readline php8.2-redis php8.2-soap php8.2-swoole php8.2-uuid php8.2-xml pv redis unzip wget whois
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

## 6. Configure Caddy webserver:

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
    header -Server
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

### Configuring the Message Broker

You can easily configure the message broker for email delivery in ```config.php```. It is compatible with SendGrid, Mailgun API, and PHPMailer for those opting to use their own SMTP server. All necessary settings are conveniently located under the mailer_ lines within the file.

For establishing your own mail server, Mox, available at [GitHub](https://github.com/mjl-/mox), provides a comprehensive solution. Install Mox following its GitHub instructions, then enter the required details in the ```config.php``` file.

To run the messagebroker.php script, execute the following command: ```/usr/bin/php /opt/registry/automation/messagebroker.php &```. This will start the script and place it in the background, allowing it to run independently of your current terminal session.

### Setting Up an Audit Trail Database for Namingo

To create an audit trail database for Namingo, start by editing the configuration file located at `/opt/registry/automation/audit.json` with the correct database details. This includes specifying the database connection parameters such as host, username, and password. Once your configuration is set up, run the command:

```bash
/opt/registry/automation/vendor/bin/audit -v audit /opt/registry/automation/audit.json
```

This will initialize and configure the audit trail functionality. This process ensures that all necessary tables and structures are set up in the registryAudit database, enabling comprehensive auditing of Namingo's operations.

**Currently, the audit trail setup for Namingo is supported only with MySQL or MariaDB databases. If you're using PostgreSQL, you'll need to utilize an external tool for audit logging, such as [pgAudit](https://minervadb.com/index.php/pgaudit-open-source-postgresql-audit-logging/), which provides detailed audit logging capabilities tailored for PostgreSQL environments.**

### Setup Backup

To ensure the safety and availability of your data in Namingo, it's crucial to set up and verify automated backups. Begin by editing the ```backup.json``` file in the automation directory, where you'll input your database details. Ensure that the details for the database are accurately entered in two specified locations within the ```backup.json``` file.

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

Configure all options in ```config.php```.

- Copy ```docs/das.service``` to ```/etc/systemd/system/```. Change only User and Group lines to your user and group.

```bash
systemctl daemon-reload
systemctl start das.service
systemctl enable das.service
```

After that you can manage DAS via systemctl as any other service.

## 14. Setup Hidden Master DNS with BIND:

Although Namingo is equipped with BIND by default for this purpose, you can opt for NSD, or Knot DNS if you are more comfortable with those systems.

### Install BIND9 and its utilities with:

```bash
apt install bind9 bind9-utils bind9-doc
```

### Create Zone Directory:

```bash
mkdir /etc/bind/zones
mkdir /etc/bind/keys
```

### Generate a TSIG key:

Generate a TSIG key which will be used to authenticate DNS updates between the master and slave servers. **Note: replace ```test``` with your TLD.**

```bash
cd /etc/bind
tsig-keygen -a HMAC-SHA256 test.key
```

The output will be in the format that can be directly included in your BIND configuration files. It looks something like this:

```bash
key "test.key" {
    algorithm hmac-sha256;
    secret "base64-encoded-secret==";
};
```

Copy this output for use in the configuration files of both the master and slave DNS servers. (```/etc/bind/named.conf.local```)

### Configure the Named Configuration File:

Edit the named.conf.local file:

```bash
nano /etc/bind/named.conf.local
```

Add the following zone definition:

```bash
zone "test." {
    type master;
    file "/etc/bind/zones/test.zone";
    auto-dnssec maintain;
    key-directory "/etc/bind/keys";
    inline-signing yes;
    allow-transfer { key "test.key"; };
    also-notify { <slave-server-IP>; };
};
```

Replace ```<slave-server-IP>``` with the actual IP address of your slave server. Replace ```test``` with your TLD.

Initially, you will need to generate the DNSSEC ZSK and KSK manually:

```bash
dnssec-keygen -a Ed25519 -b 2048 -n ZONE test.
dnssec-keygen -a Ed25519 -b 4096 -n ZONE -f KSK test.
```

After generating the keys, place them in the specified key-directory.

Use rndc to tell BIND to load and use the new keys:

```bash
chown bind:bind /etc/bind/keys/*
chmod 640 /etc/bind/keys/*
chown -R bind:bind /etc/bind/zones
chmod 640 /etc/bind/zones/*
systemctl restart bind9
rndc loadkeys test.
```

Configure and start the ```write-zone.php``` automation script.

### Check BIND9 Configuration:

```bash
named-checkconf
named-checkzone test /etc/bind/zones/test.zone
```

### Restart BIND9 Service:

```bash
systemctl restart bind9
```

### Verify Zone Loading:

Check the BIND9 logs to ensure that the .test zone is loaded without errors:

```bash
grep named /var/log/syslog
```

### 14.1 Regular DNS Server Setup:

Before editing the configuration files, you need to copy the TSIG key from your hidden master server. The TSIG key configuration should look like this:

```bash
key "test.key" {
    algorithm hmac-sha256;
    secret "base64-encoded-secret==";
};
```

#### Installation of BIND9:

```bash
apt update
apt install bind9 bind9-utils bind9-doc
```

#### Add the TSIG key to the BIND Configuration:

Create a directory to store zone files:

```bash
mkdir /var/cache/bind/zones
```

Edit the `named.conf.local` file:

```bash
nano /etc/bind/named.conf.local
```

First, define the TSIG key at the top of the file:

```bash
key "test.key" {
    algorithm hmac-sha256;
    secret "base64-encoded-secret=="; // Replace with your actual base64-encoded key
};
```

Then, add the slave zone configuration:

```bash
zone "test." {
    type slave;
    file "/var/cache/bind/zones/test.zone";
    masters { 192.0.2.1 key "test.key"; }; // IP of the hidden master and TSIG key reference
    allow-query { any; }; // Allow queries from all IPs
    allow-transfer { none; }; // Disable zone transfers (AXFR) to others
};
```

Make sure to replace `192.0.2.1` with the IP address of your hidden master server and `base64-encoded-secret==` with the actual secret from your TSIG key.

#### Adjusting Permissions and Ownership:

Ensure BIND has permission to write to the zone file and that the files are owned by the BIND user:

```bash
chown bind:bind /var/cache/bind/zones
chmod 755 /var/cache/bind/zones
```

#### Restart BIND9 Service:

After making these changes, restart the BIND9 service to apply them:

```bash
systemctl restart bind9
```

#### Verify Configuration and Zone Transfer:

```bash
named-checkconf
grep 'transfer of "test."' /var/log/syslog
```

## 15. Setup Monitoring:

For effective monitoring of your registry system, we highly recommend utilizing Prometheus.

```bash
wget https://github.com/prometheus/prometheus/releases/download/v2.48.1/prometheus-2.48.1.linux-amd64.tar.gz
tar xvfz prometheus-2.48.1.linux-amd64.tar.gz
cp prometheus-2.48.1.linux-amd64/prometheus /usr/local/bin/
cp prometheus-2.48.1.linux-amd64/promtool /usr/local/bin/
useradd --no-create-home --shell /bin/false prometheus
mkdir /etc/prometheus
mkdir /var/lib/prometheus
cp -r prometheus-2.48.1.linux-amd64/consoles /etc/prometheus
cp -r prometheus-2.48.1.linux-amd64/console_libraries /etc/prometheus
chown -R prometheus:prometheus /etc/prometheus
chown -R prometheus:prometheus /var/lib/prometheus
```

Place the following in the ```/etc/prometheus/prometheus.yml``` and customize as needed:

```
# Global settings and defaults.
global:
  scrape_interval: 15s  # By default, scrape targets every 15 seconds.
  evaluation_interval: 15s  # Evaluate rules every 15 seconds.

# Alertmanager configuration (commented out by default).
# alerting:
#   alertmanagers:
#   - static_configs:
#     - targets:
#       - localhost:9093

# Load and evaluate rules in this file.
# rule_files:
#   - "first_rules.yml"
#   - "second_rules.yml"

# Scrape configuration for running Prometheus on the same machine.
scrape_configs:
  # The job name is added as a label `job=<job_name>` to any timeseries scraped from this config.
  - job_name: 'prometheus'
    # metrics_path defaults to '/metrics'
    # scheme defaults to 'http'.
    static_configs:
      - targets: ['localhost:9090']

  # Example job for scraping an HTTP service.
  - job_name: 'http_service'
    static_configs:
      - targets: ['<your_http_service>:80']

  # Example job for scraping an HTTPS service.
  - job_name: 'https_service'
    static_configs:
      - targets: ['<your_https_service>:443']

  # Example job for scraping a DNS server.
  - job_name: 'dns_monitoring'
    static_configs:
      - targets: ['<your_dns_server>:53']

# Add additional jobs as needed for your services.
```

Run the monitoring tool using:

```bash
prometheus --config.file=/etc/prometheus/prometheus.yml
```

The tool will be available at ```http://<your_server_ip>:9090```

## 16. Recommended Help Desk Solution:

If you're in need of an effective help desk solution to complement your experience with Namingo, we recommend considering [FreeScout](https://freescout.net/), an AGPL-3.0 licensed, free and open-source software. FreeScout is known for its user-friendly interface and robust features, making it an excellent choice for managing customer queries and support tickets.

### Please Note:

- FreeScout is an independent software and is not a part of Namingo. It is licensed under the AGPL-3.0, which is different from Namingo's MIT license.
- The recommendation to use FreeScout is entirely optional and for the convenience of Namingo users. Namingo functions independently of FreeScout and does not require FreeScout for its operation.
- Ensure to comply with the AGPL-3.0 license terms if you choose to use FreeScout alongside Namingo.