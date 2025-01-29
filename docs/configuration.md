# Configuration Guide

This document provides instructions for configuring Namingo, the domain registry management tool, after installation. It covers configuring the system, adding additional components, and customizing various configuration files for optimal setup.

## 1. Post-Installation Configuration

This section provides instructions for configuring your system after installing Namingo, including setting up additional components and customizing configuration files.

### 1.1. Launching WHOIS, RDAP, and DAS Servers

To start the WHOIS, RDAP, and DAS servers, use the following commands:

```bash
systemctl start whois
systemctl start rdap
systemctl start das
```

Ensure each service is properly configured before starting. You can verify the status of each server with:

```bash
systemctl status whois
systemctl status rdap
systemctl status das
```

### 1.2. Launching EPP Server

Before launching the EPP server, edit `/opt/registry/epp/config.php` to set the paths to your certificates and configure other options as needed.

To create test certificates (`cert.pem` and `key.pem`), execute the following commands:

```bash
cd /opt/registry/epp/
openssl genrsa -out key.pem 2048
openssl req -new -x509 -key key.pem -out cert.pem -days 365
```

Once configured, you can launch the EPP server in the same way as the others:

```bash
systemctl start epp
```

### 1.3. Additional Control Panel Setup

#### 1.3.1. Install Optional Dependencies

To enhance the functionality of your control panel, install optional dependencies by executing the following command:

```bash
cd /var/www/cp
composer require phpmailer/phpmailer
```

#### 1.3.2. Setting Up Redis Session Storage

To utilize Redis for session storage, you need to install the necessary packages and configure your environment accordingly. Follow these steps to set up Redis session storage:

```bash
cd /var/www/cp
composer require predis/predis pinga/session-redis
```

After installation, log out of your application if you are currently logged in. This ensures that the session starts afresh with the new configuration.

Clear your browser cookies related to the application. This step is crucial as it removes any existing session cookies that were set using the previous session storage mechanism.

Upon your next login, Redis will be used for storing session data. The new sessions will be created and managed through Redis, providing a more scalable and efficient session management system.

**Note**: Ensure that your Redis server is properly configured and running before proceeding with these steps. If in doubt, check with:

```bash
systemctl status redis-server
```

### 1.4. Setting Up the Automation System

Follow these steps to configure the automation system for your registry:

#### 1.4.1. Configuration

Move to the automation directory with the following command:

```bash
cd /opt/registry/automation
```

Open `config.php` and adjust all necessary settings to suit your system's requirements. Make sure to review and fine-tune each option for optimal performance.

#### 1.4.2. Install Optional Dependencies

Execute one of the following commands to install the optional dependencies:

```bash
composer require utopia-php/messaging
```

or

```bash
composer require phpmailer/phpmailer
```

This command will install one of the packages which are essential for the message broker script to function correctly.

#### 1.4.3. Configuring the Message Broker

You can easily configure the message broker for email delivery in ```config.php```. It is compatible with SendGrid, Mailgun API, and PHPMailer for those opting to use their own SMTP server. All necessary settings are conveniently located under the mailer_ lines within the file.

For establishing your own mail server, both [Mox](https://github.com/mjl-/mox) and [Stalwart](https://stalw.art/) offer comprehensive solutions. You can install Mox by following its GitHub instructions, or Stalwart by referring to its official site. Once installed, enter the required details in the ```config.php``` file to complete the setup.

To run the Message Broker, execute the following commands:

```bash
/usr/bin/php /opt/registry/automation/msg_producer.php &
/usr/bin/php /opt/registry/automation/msg_worker.php &
```

This will start the system and place it in the background, allowing it to run independently of your current terminal session.

#### 1.4.4. Setting Up an Audit Trail Database for Namingo

To create an audit trail database for Namingo, start by editing the configuration file located at `/opt/registry/automation/audit.json` with the correct database details. This includes specifying the database connection parameters such as host, username, and password. Once your configuration is set up, run the command:

```bash
/opt/registry/automation/vendor/bin/audit -v audit /opt/registry/automation/audit.json
```

This will initialize and configure the audit trail functionality. This process ensures that all necessary tables and structures are set up in the registryAudit database, enabling comprehensive auditing of Namingo's operations.

**Currently, the audit trail setup for Namingo is supported only with MySQL or MariaDB databases. If you're using PostgreSQL, you'll need to utilize an external tool for audit logging, such as [pgAudit](https://minervadb.com/index.php/pgaudit-open-source-postgresql-audit-logging/), which provides detailed audit logging capabilities tailored for PostgreSQL environments.**

#### 1.4.5. Setup Backup

To set up backups in Namingo:

1. Rename `/opt/registry/automation/backup.json.dist` and `/opt/registry/automation/backup-upload.json.dist` to `backup.json` and `backup-upload.json`, respectively. Edit both files to include the correct database and other required details.

2. Enable the backup functionality in `cron.php` or `cron_config.php` and make sure you follow the instructions in section **1.4.7. Running the Automation System** to activate the automation system on your server.

#### 1.4.6. RDE (Registry data escrow) configuration

**1.4.6.1. Generate the Key Pair**: Create a configuration file, say key-config, with the following content:

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

**1.4.6.2. Exporting Your Keys**:

Public key:

```bash
gpg2 --armor --export your.email@example.com > publickey.asc
```

Replace `your-email@example.com` with the email address you used when generating the key.

Private key:

```bash
gpg2 --armor --export-secret-keys your.email@example.com > privatekey.asc
```

**1.4.6.3. Secure Your Private Key**: Always keep your private key secure. Do not share it. If someone gains access to your private key, they can impersonate you in cryptographic operations.

**1.4.6.4. Use in RDE deposit generation**: Please send the exported `publickey.asc` to your RDE provider, and also place the path to `privatekey.asc` in the escrow.php system as required.

#### 1.4.7. Running the Automation System

1. After successfully configuring all the components of the automation system as outlined in the previous sections, you can proceed to initiate the system.

2. Create the configuration file at `/opt/registry/automation/cron_config.php` with the specified structure, and adjust the values to suit your requirements. Note: If you are managing a gTLD, all services must be enabled for proper operation.

```php
<?php
return [
    'accounting' => false,  // Enable or disable accounting
    'backup' => false,      // Enable or disable backup
    'backup_upload' => false, // Enable or disable backup upload
    'gtld_mode' => false,   // Enable or disable gTLD mode
    'spec11' => false,      // Enable or disable Spec 11 checks
    'dnssec' => false,     // Enable or disable DNSSEC
];
```

3. Add the following cron job to the system crontab using ```crontab -e```:

```bash
* * * * * /usr/bin/php /opt/registry/automation/cron.php 1>> /dev/null 2>&1
```

#### 1.4.8. Customizing the Control Panel Logo and Pages

**1.4.8.1. Customizing the Logo**:
Upload your custom logo as `logo.svg` to `/var/www/cp/public/static/`. If `logo.svg` is not present, the default `logo.default.svg` will be used automatically.

**1.4.8.2. Customizing the Documentation Page**:
To customize the documentation, copy `docs.twig` to `docs.custom.twig` using the command `cp /var/www/cp/resources/views/admin/support/docs.twig /var/www/cp/resources/views/admin/support/docs.custom.twig`. Edit `docs.custom.twig` as needed. The system will use `docs.custom.twig` if it exists; otherwise, it defaults to `docs.twig`.

**1.4.8.3. Customizing the Media Kit Page**:
To customize the media kit page, copy `mediakit.twig` to `mediakit.custom.twig` using `cp /var/www/cp/resources/views/admin/support/mediakit.twig /var/www/cp/resources/views/admin/support/mediakit.custom.twig`. Edit `mediakit.custom.twig` to apply your changes. The system will prioritize `mediakit.custom.twig` over the default file.

**1.4.8.4. Customizing the Landing Page**:
To customize the landing page, copy `index.twig` to `index.custom.twig` using `cp /var/www/cp/resources/views/index.twig /var/www/cp/resources/views/index.custom.twig`. Edit `index.custom.twig` to apply your changes. The system will prioritize `index.custom.twig` over the default file.

#### 1.4.9. Changing the Default Control Panel Language

To change the default language of the control panel, you must edit the `/var/www/cp/.env` file and replace the language values (`LANG`/`UI_LANG`) with your desired settings.

For the `LANG` variable, the supported values are `en_US`, `uk_UA`, `es_ES`, `pt_PT`, `jp_JP`, `ar_SA`, and `fr_FR`. For the `UI_LANG` variable, use `us`, `ua`, `es`, `pt`, `jp`, `ar`, or `fr`.

To apply your changes, save the file, refresh the control panel, and clear the cache using the following command: `php /var/www/cp/bin/clear_cache.php` The new language settings will take effect immediately.

#### 1.4.10. WebAuthn Authentication

To enable WebAuthn authentication in the Control Panel, follow these steps:

1. Edit the environment configuration file located at: `/var/www/cp/.env`

2. Find or add the following line:

```bash
WEB_AUTHN_ENABLED=true
```

3. Save the changes and reload the server (Caddy) using the following command:

```bash
sudo systemctl reload caddy
```

#### 1.4.11. Zone generator custom records

Each TLD can have its own custom records file, located in `/opt/registry/automation/`. For example, for the TLD `example`, create the file `/opt/registry/automation/example.php`.

The content of a custom records file should be:

```php
<?php
return [
    // A record
    [
        'name' => '@',          // The name of the record (e.g., @ for the root domain or a subdomain)
        'type' => 'A',          // Record type (A, AAAA, TXT, etc.)
        'parameters' => ['192.0.2.1'], // Parameters required for the record type
    ],
    // AAAA record
    [
        'name' => 'www',
        'type' => 'AAAA',
        'parameters' => ['2001:db8::1'],
    ],
    // TXT record
    [
        'name' => '@',
        'type' => 'TXT',
        'parameters' => ['"v=spf1 include:example.com ~all"'],
    ],
    // MX record
    [
        'name' => '@',
        'type' => 'MX',
        'parameters' => [10, 'mail.example.com.'], // Priority and mail server
    ],
];
```

## 2. Recommended Components and Integrations

This section outlines recommended components to enhance the functionality and reliability of your Namingo setup. These include essential services like DNS servers, monitoring tools, and other integrations that can help maintain a robust registry environment.

### 2.1. Setup Hidden Master DNS with BIND

#### Install BIND9 and its utilities:

```bash
apt install bind9 bind9-utils bind9-doc
```

#### Generate a TSIG key:

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

#### Configure the Named Configuration File (Please Choose One):

1. Without DNSSEC:

Edit the named.conf.local file:

```bash
nano /etc/bind/named.conf.local
```

Add the following zone definition:

```bash
zone "test." {
    type master;
    file "/var/lib/bind/test.zone";
    allow-transfer { key "test.key"; };
    also-notify { <slave-server-IP>; };
};
```

Replace ```<slave-server-IP>``` with the actual IP address of your slave server. Replace ```test``` with your TLD.

Use rndc to reload BIND:

```bash
systemctl restart bind9
```

Configure the `Zone Writer` in Registry Automation and run it manually the first time.

```bash
php /opt/registry/automation/write-zone.php
```

2. Using DNSSEC with BIND9:

Edit the named.conf.local file:

```bash
nano /etc/bind/named.conf.local
```

Add the following DNSSEC policy:

```bash
dnssec-policy "namingo-policy" {
    keys {
        ksk lifetime P1Y algorithm ed25519;
        zsk lifetime P2M algorithm ed25519;
    };
    max-zone-ttl 86400;
    dnskey-ttl 3600;
    zone-propagation-delay 3600;
    parent-propagation-delay 7200;
    parent-ds-ttl 86400;
};
```

Add the following zone definition:

```bash
zone "test." {
    type master;
    file "/var/lib/bind/test.zone";
    dnssec-policy "namingo-policy";
    key-directory "/var/lib/bind";
    inline-signing yes;
    allow-transfer { key "test.key"; };
    also-notify { <slave-server-IP>; };
};
```

Replace ```<slave-server-IP>``` with the actual IP address of your slave server. Replace ```test``` with your TLD.

Initially, you will need to generate the DNSSEC ZSK and KSK manually:

```bash
dnssec-keygen -a Ed25519 -n ZONE test.
dnssec-keygen -a Ed25519 -n ZONE -f KSK test.
```

After generating the keys, place them in ```/var/lib/bind```. Run ```dnssec-dsfromkey Ktest.EXAMPLE.key``` on the KSK key you just generated, and the DS record must be submitted to IANA once setup is complete.

Use rndc to tell BIND to load and use the new keys:

```bash
chown -R bind:bind /var/lib/bind
systemctl restart bind9
rndc loadkeys test.
```

Configure the `Zone Writer` in Registry Automation and run it manually the first time.

```bash
php /opt/registry/automation/write-zone.php
```

**NB! Enable DNSSEC in the TLD management page from the control panel. Mode must be BIND9.** Then upload the DS record to IANA or the parent registry from the Control Panel TLD page.

3. Using DNSSEC with OpenDNSSEC:

Edit the named.conf.local file:

```bash
nano /etc/bind/named.conf.local
```

Add the following zone definition:

```bash
zone "test." {
    type master;
    file "/var/lib/bind/test.zone.signed";
    allow-transfer { key "test.key"; };
    also-notify { <slave-server-IP>; };
};
```

Replace ```<slave-server-IP>``` with the actual IP address of your slave server. Replace ```test``` with your TLD.

Install OpenDNSSEC:

```bash
apt install opendnssec opendnssec-enforcer-sqlite3 opendnssec-signer softhsm2
mkdir -p /var/lib/softhsm/tokens
chown -R opendnssec:opendnssec /var/lib/softhsm/tokens
softhsm2-util --init-token --slot 0 --label OpenDNSSEC --pin 1234 --so-pin 1234
```

Update files in `/etc/opendnssec` to match your registry policy. As minimum, please enable at least Signer Threads in `/etc/opendnssec/conf.xml`, but we recommend to fully review [all the files](https://wiki.opendnssec.org/configuration/confxml/). Then run the following commands:

```bash
chown -R opendnssec:opendnssec /etc/opendnssec
ods-enforcer-db-setup
ods-enforcer policy import
rm /etc/opendnssec/prevent-startup
chown opendnssec:opendnssec /var/lib/bind/test.zone
chmod 644 /var/lib/bind/test.zone
ods-enforcer zone add -z test -p default -i /var/lib/bind/test.zone
ods-control start
```

Edit again the named.conf.local file:

```bash
nano /etc/bind/named.conf.local
```

Replace the value for `file` with the following filename:

```bash
...
    file "/var/lib/opendnssec/signed/test.zone.signed";
...
};
```

Use rndc to reload BIND:

```bash
systemctl restart bind9
```

Configure the `Zone Writer` in Registry Automation and run it manually the first time.

```bash
php /opt/registry/automation/write-zone.php
```

#### Check BIND9 Configuration:

```bash
named-checkconf
named-checkzone test /var/lib/bind/test.zone
```

#### Restart BIND9 Service:

```bash
systemctl restart bind9
```

#### Verify Zone Loading:

Check the BIND9 logs to ensure that the .test zone is loaded without errors:

```bash
grep named /var/log/syslog
```

### 2.2. Setup Hidden Master DNS with Knot DNS and DNSSEC

#### Install Knot DNS and its utilities:

```bash
apt install knot knot-dnsutils
```

#### Generate a TSIG key:

Generate a TSIG key which will be used to authenticate DNS updates between the master and slave servers. **Note: replace ```test``` with your TLD.**

```bash
cd /etc/knot
knotc conf-gen-key test.key hmac-sha256
```

The output will be in the format that can be directly included in your configuration files. It looks something like this:

```bash
key:
  - id: "test.key"
    algorithm: hmac-sha256
    secret: "base64-encoded-secret=="
```

Copy this output for use in the configuration files of both the master and slave DNS servers. (```/etc/knot/knot.conf```)

#### Configure DNSSEC Policy:

Add the DNSSEC policy to `/etc/knot/knot.conf`:

```bash
nano /etc/knot/knot.conf
```

Add the following DNSSEC policy:

```bash
policy:
  - id: "namingo-policy"
    description: "Default DNSSEC policy for TLD"
    algorithm: ed25519
    ksk-lifetime: 1y
    zsk-lifetime: 2m
    max-zone-ttl: 86400
    rrsig-lifetime: 14d
    rrsig-refresh: 7d
    dnskey-ttl: 3600
```

#### Add your zone:

Add the zone to `/etc/knot/knot.conf`:

```bash
zone:
  - domain: "test."
    file: "/etc/knot/zones/test.zone"
    dnssec-policy: "namingo-policy"
    key-directory: "/etc/knot/keys"
    storage: "/etc/knot/zones"
    notify: <slave-server-IP>
    acl:
      - id: "test.key"
        address: <slave-server-IP>
        key: "test.key"
```

Replace ```<slave-server-IP>``` with the actual IP address of your slave server. Replace ```test``` with your TLD.

Generate the necessary DNSSEC keys for your zone using keymgr:

```bash
keymgr policy:generate test.
```

This will create the required keys in `/etc/knot/keys`. Ensure the directory permissions are secure:

```bash
chown -R knot:knot /etc/knot/keys
chmod -R 700 /etc/knot/keys
```

Reload Knot DNS and enable DNSSEC signing for the zone:

```bash
knotc reload
knotc signzone test.
```

Generate the DS record for the parent zone using `keymgr`:

```bash
keymgr ds test.
```

Configure the `Zone Writer` in Registry Automation and run it manually the first time.

```bash
php /opt/registry/automation/write-zone.php
```

**NB! Enable DNSSEC in the TLD management page from the control panel. Mode must be KnotDNS.** Then upload the DS record to IANA or the parent registry from the Control Panel TLD page.

### 2.3. Regular DNS Server Setup

Before editing the configuration files, you need to copy the TSIG key from your hidden master server. The TSIG key configuration should look like this:

```bash
key "test.key" {
    algorithm hmac-sha256;
    secret "base64-encoded-secret==";
};
```

#### Installation of BIND9

```bash
apt update
apt install bind9 bind9-utils bind9-doc
```

#### Add the TSIG key to the BIND Configuration

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

#### Adjusting Permissions and Ownership

Ensure BIND has permission to write to the zone file and that the files are owned by the BIND user:

```bash
chown bind:bind /var/cache/bind/zones
chmod 755 /var/cache/bind/zones
```

#### Restart BIND9 Service

After making these changes, restart the BIND9 service to apply them:

```bash
systemctl restart bind9
```

#### Verify Configuration and Zone Transfer

```bash
named-checkconf
grep 'transfer of "test."' /var/log/syslog
```

### 2.4. Setup Monitoring

For effective monitoring of your registry system, we highly recommend utilizing Prometheus.

```bash
apt update
apt install prometheus prometheus-node-exporter prometheus-mysqld-exporter
```

Edit the Prometheus configuration file: ```/etc/prometheus/prometheus.yml```, customize and replace the contents with:

```bash
global:
  scrape_interval: 15s
  evaluation_interval: 15s

scrape_configs:
  - job_name: 'prometheus'
    static_configs:
      - targets: ['localhost:9090']

  - job_name: 'node'
    static_configs:
      - targets: ['localhost:9100']

  - job_name: 'mariadb'
    metrics_path: /metrics
    static_configs:
      - targets: ['localhost:9104']

  - job_name: 'epp_server'
    static_configs:
      - targets: ['epp.example.org:700']  # EPP Server

  - job_name: 'whois_server'
    static_configs:
      - targets: ['whois.example.org:43']  # WHOIS Server

  - job_name: 'das_server'
    static_configs:
      - targets: ['das.example.org:1043']  # DAS Server

  - job_name: 'rdap_server'
    static_configs:
      - targets:
          - 'das.example.org:80'
          - 'das.example.org:443'
          - 'das.example.org:7500'

  - job_name: 'control_panel'
    static_configs:
      - targets:
          - 'cp.example.org:80'
          - 'cp.example.org:443'
```

Set ownership for the configuration file:

```bash
chown prometheus:prometheus /etc/prometheus/prometheus.yml
```

Update the Node Exporter service file:

```bash
nano /lib/systemd/system/prometheus-node-exporter.service
```

Edit the MySQL Exporter configuration:

```bash
nano /etc/default/prometheus-mysqld-exporter
```

Update the `DATA_SOURCE_NAME`:

```bash
DATA_SOURCE_NAME='exporter:password@(localhost:3306)/'
```

Create the MySQL user:

```sql
CREATE USER 'exporter'@'localhost' IDENTIFIED BY 'password';
GRANT PROCESS, REPLICATION CLIENT, SELECT ON *.* TO 'exporter'@'localhost';
FLUSH PRIVILEGES;
```

Enable and start all services:

```bash
systemctl enable prometheus
systemctl start prometheus

systemctl enable prometheus-node-exporter
systemctl start prometheus-node-exporter

systemctl enable prometheus-mysqld-exporter
systemctl start prometheus-mysqld-exporter
```

Open Prometheus in your browser:

```bash
http://<your_server_ip>:9090
```

Check **Status > Targets** to ensure all targets are up.

### 2.5. Recommended Help Desk Solution

If you're in need of an effective help desk solution to complement your experience with Namingo, we recommend considering [FreeScout](https://freescout.net/), an AGPL-3.0 licensed, free and open-source software. FreeScout is known for its user-friendly interface and robust features, making it an excellent choice for managing customer queries and support tickets.

#### Please Note:

- FreeScout is an independent software and is not a part of Namingo. It is licensed under the AGPL-3.0, which is different from Namingo's MIT license.
- The recommendation to use FreeScout is entirely optional and for the convenience of Namingo users. Namingo functions independently of FreeScout and does not require FreeScout for its operation.
- Ensure to comply with the AGPL-3.0 license terms if you choose to use FreeScout alongside Namingo.

### 2.6. Scaling Your Database with ProxySQL

To enhance the scalability and performance of your database, consider integrating [ProxySQL](https://proxysql.com/) into your architecture. ProxySQL is a high-performance, open-source proxy designed for MySQL, MariaDB, and other database systems, providing features like query caching, load balancing, query routing, and failover support. By acting as an intermediary between your application and the database, ProxySQL enables efficient distribution of queries across multiple database nodes, reducing latency and improving overall reliability, making it an excellent choice for scaling your database infrastructure.

## 3. Security Hardening

### 3.1. Create the namingo user

```bash
adduser namingo
usermod -aG sudo namingo
```

### 3.2. Set Up Services

```bash
su namingo
sudo nano /etc/systemd/system/{whois.service,epp.service,rdap.service}
```

Modify:

```bash
[Service]
User=namingo
Group=namingo
```

Reload and restart:

```bash
sudo chown -R namingo:namingo /opt/registry /etc/caddy
sudo systemctl daemon-reload
sudo systemctl restart whois epp rdap
```

### 3.3. SSH Hardening

1. Disable Root Login:

```bash
sudo nano /etc/ssh/sshd_config
```

Set:

```bash
PermitRootLogin no
```

2. Change SSH Port:

```bash
Port 2222
```

3. Use Key-Based Authentication:

- Generate a key pair:

```bash
ssh-keygen -t rsa -b 4096
```

- Add your public key to the `namingo` user:

```bash
su - namingo
mkdir -p ~/.ssh
chmod 700 ~/.ssh
echo "your-public-key" > ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys
```

4. Firewall Setup:

```bash
sudo ufw allow 2222/tcp       # New SSH Port
sudo ufw enable
```

5. Restart SSH:

```bash
sudo systemctl restart ssh
```

### 3.4. Other Server Hardening

```bash
sudo apt update && sudo apt upgrade -y
sudo systemctl list-units --type=service --state=running
sudo systemctl disable <service> # Disable unnecessary ones
sudo apt install fail2ban
sudo systemctl enable fail2ban --now
sudo apt install unattended-upgrades
sudo dpkg-reconfigure --priority=low unattended-upgrades
```

- Configure Swap (if necessary):

```bash
sudo fallocate -l 1G /swapfile
sudo chmod 600 /swapfile
sudo mkswap /swapfile
sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
```

### 3.5. Adminer Security settings

To enhance the security of your Adminer installation, we recommend the following settings for Caddy, Apache2, and Nginx:

1. **Rename Adminer File:** Change `adminer.php` to `dbtool.php` to make it less predictable.

2. **Restrict Access by IP:** Only allow access from specific IP addresses.

Below are example configurations for each web server:

#### Caddy

```bash
# Adminer Configuration
route /dbtool.php* {
    root * /usr/share/adminer
    php_fastcgi unix//run/php/php8.2-fpm.sock

    # Define the allowed IP address
    @allowed {
        remote_ip your.ip.address.here
    }

    # Route for allowed IP addresses
    handle @allowed {
        file_server
    }

    # Respond with 403 for any IP address not allowed
    respond "Access Denied" 403
}
```

#### Apache .htaccess

```bash
<Files "dbtool.php">
    Order Deny,Allow
    Deny from all
    Allow from your.ip.address.here
</Files>
```

#### Nginx

```bash
location /dbtool.php {
    allow your.ip.address.here;
    deny all;
}
```

## 4. In-Depth Configuration File Overview

In this section, we provide a detailed overview of each configuration file used in the Namingo domain registry platform. Understanding these files is essential for customizing and optimizing your system according to your specific needs. We will walk you through the purpose of each file, key settings, and recommended configurations to ensure smooth operation and integration with other components of your setup.

### Automation Configuration (`/opt/registry/automation/config.php`)

This configuration file is essential for setting up the automation scripts for the registry tool.

```php
<?php

return [
    // Database Configuration
    'db_type' => 'mysql', // Type of the database (e.g., 'mysql', 'pgsql')
    'db_host' => 'localhost', // Database server host
    'db_port' => 3306, // Database server port
    'db_database' => 'registry', // Name of the database
    'db_username' => 'your_username', // Database username
    'db_password' => 'your_password', // Database password
    
    // Escrow Configuration
    'escrow_deposit_path' => '/opt/escrow', // Path for escrow deposits
    'escrow_deleteXML' => false, // Whether to delete XML files after processing
    'escrow_RDEupload' => false, // Enable/disable RDE upload
    'escrow_BRDAupload' => false, // Enable/disable BRDA upload
    'escrow_BRDAday' => 'Tuesday', // Day for BRDA uploads
    'escrow_keyPath' => '/opt/escrow/escrowKey.asc', // Path to the escrow key
    'escrow_keyPath_brda' => '/opt/escrow/icann-brda-gpg.pub', // Path to the BRDA escrow key
    'escrow_privateKey' => '/opt/escrow/privatekey.asc', // Path to the private key for escrow
    'escrow_sftp_host' => 'your.sftp.server.com', // Host for escrow SFTP server
    'escrow_sftp_username' => 'your_username', // Username for escrow SFTP server
    'escrow_sftp_password' => 'your_password', // Password for escrow SFTP server
    'escrow_sftp_remotepath' => '/path/on/sftp/server/', // Remote path on the escrow SFTP server
    'brda_sftp_host' => 'your.sftp.server.com', // Host for BRDA SFTP server
    'brda_sftp_username' => 'your_username', // Username for BRDA SFTP server
    'brda_sftp_password' => 'your_password', // Password for BRDA SFTP server
    'brda_sftp_remotepath' => '/path/on/sftp/server/', // Remote path on the BRDA SFTP server
    'escrow_report_url' => 'https://ry-api.icann.org/report/', // URL for escrow reporting
    'escrow_report_username' => 'your_username', // Username for escrow reporting
    'escrow_report_password' => 'your_password', // Password for escrow reporting
    'roid' => 'XX', // ROID value in escrow

    // Reporting Configuration
    'reporting_path' => '/opt/reporting', // Path for reporting
    'reporting_upload' => false, // Enable/disable reporting upload
    'reporting_username' => 'your_username', // Username for reporting
    'reporting_password' => 'your_password', // Password for reporting
    
    // Zone Writer Configuration
    'dns_server' => 'bind', // DNS server type (e.g., 'bind', 'nsd')
    'ns' => [
        'ns1' => 'ns1.namingo.org', // Primary name server
        'ns2' => 'ns2.namingo.org', // Secondary name server
        // ... more name servers as needed ...
    ],
    'dns_soa' => 'hostmaster.example.com', // SOA email address
    'dns_serial' => 1, // change to 2 for YYYYMMDDXX format, and 3 for Cloudflare-like serial
    'zone_mode' => 'default', // How the BIND zone is generated, 'nice' is also available

    // URS Configuration
    'urs_imap_host' => '{your_imap_server:993/imap/ssl}INBOX', // IMAP host for URS
    'urs_imap_username' => 'your_username', // IMAP username for URS
    'urs_imap_password' => 'your_password', // IMAP password for URS
    
    // Message Broker Configuration
    'mailer' => 'phpmailer', // Mailer type ('phpmailer', 'sendgrid', 'mailgun')
    'mailer_api_key' => 'YOUR_API_KEY', // API key for sendgrid/mailgun
    'mailer_domain' => 'example.com', // Domain for sendgrid/mailgun
    'mailer_from' => 'from@example.com', // From email address for mailer
    'mailer_smtp_host' => 'smtp.example.com', // SMTP host for mailer
    'mailer_smtp_username' => 'your_email@example.com', // SMTP username for mailer
    'mailer_smtp_password' => 'your_password', // SMTP password for mailer
    'mailer_smtp_port' => 587, // SMTP port for mailer
    
    'mailer_sms' => 'twilio', // SMS provider ('twilio', 'telesign', 'plivo', 'vonage', 'clickatell')
    'mailer_sms_account' => 'YOUR_ACCOUNT_SID/USERNAME', // Account SID/username for SMS
    'mailer_sms_auth' => 'YOUR_AUTH_TOKEN/PASSWORD', // Auth token/password for SMS
    
    // TMCH Configuration
    'tmch_path' => '/tmp/', // Path for TMCH files
    'tmch_smdrl_user' => 'your_username', // Username for TMCH SMDRL
    'tmch_smdrl_pass' => 'your_password', // Password for TMCH SMDRL
    'tmch_dnl_user' => 'your_username', // Username for TMCH DNL
    'tmch_dnl_pass' => 'your_password', // Password for TMCH DNL
    
    // LORDN Configuration
    'lordn_user' => 'your_username', // Username for LORDN
    'lordn_pass' => 'your_password', // Password for LORDN
    
    // Minimum Data Set
    'minimum_data' => false,

    // Domain lifecycle settings
    'autoRenewEnabled' => false,

    // Lifecycle periods (in days)
    'gracePeriodDays' => 30,
    'autoRenewPeriodDays' => 45,
    'addPeriodDays' => 5,
    'renewPeriodDays' => 5,
    'transferPeriodDays' => 5,
    'redemptionPeriodDays' => 30,
    'pendingDeletePeriodDays' => 5,

    // Lifecycle phases (enable/disable)
    'enableAutoRenew' => false,
    'enableGracePeriod' => true,
    'enableRedemptionPeriod' => true,
    'enablePendingDelete' => true,

    // Drop settings
    'dropStrategy' => 'random', // Options: 'fixed', 'random'
    'dropTime' => '02:00:00',    // Time of day to perform drops if 'fixed' strategy is used
];
```

### Control Panel Configuration (`/var/www/cp/.env`)

This file configures the environment for the control panel of Namingo.

```plaintext
APP_NAME='CP'
APP_ENV=public
APP_URL=https://cp.example.com
APP_DOMAIN=example.com
APP_ROOT=/var/www/cp
MINIMUM_DATA=false

LANG=en_US
UI_LANG=us

DB_DRIVER=mysql # Type of the database (e.g., 'mysql', 'pgsql')
DB_HOST=localhost # Database server host
DB_DATABASE=registry # Name of the database
DB_USERNAME=root # Database username
DB_PASSWORD= # Database password
DB_PORT=3306 # Database server port

# Mailer settings (Driver = smtp, utopia or msg [for local message broker]; Api Provder = sendgrid or mailgun)
MAIL_DRIVER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=username
MAIL_PASSWORD=password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS='example@domain.com'
MAIL_FROM_NAME='Example'
MAIL_API_KEY='test-api-key'
MAIL_API_PROVIDER='sendgrid'

STRIPE_SECRET_KEY='stripe-secret-key'
STRIPE_PUBLISHABLE_KEY='stripe-publishable-key'

ADYEN_API_KEY='adyen-api-key'
ADYEN_MERCHANT_ID='adyen-merchant-id'
ADYEN_THEME_ID='adyen-theme-id'
ADYEN_BASE_URI='https://checkout-test.adyen.com/v70/'
ADYEN_BASIC_AUTH_USER='adyen-basic-auth-user'
ADYEN_BASIC_AUTH_PASS='adyen-basic-auth-pass'
ADYEN_HMAC_KEY='adyen-hmac-key'

NOW_API_KEY='now-api-key'

NICKY_API_KEY='nicky-api-key'

TEST_TLDS=.test,.com.test
```

### DAS Server Configuration (`/opt/registry/das/config.php`)

Configurations for the Domain Availability Service (DAS) server.

```php
<?php

return [
    'db_type' => 'mysql', // Type of the database (e.g., 'mysql', 'pgsql')
    'db_host' => 'localhost', // Database server host
    'db_port' => 3306, // Database server port
    'db_database' => 'registry', // Name of the database
    'db_username' => 'your_username', // Database username
    'db_password' => 'your_password' // Database password
    'das_ipv4' => '0.0.0.0',
    'das_ipv6' => '::', // Set to false if no IPv6 support
    'rately' => false, // Enable rate limit
    'limit' => 1000, // Request limit per period below
    'period' => 60, // 60 Seconds
];
```

### EPP Server Configuration (`/opt/registry/epp/config.php`)

Settings for the Extensible Provisioning Protocol (EPP) server.

```php
<?php

return [
    'db_type' => 'mysql', // Type of the database (e.g., 'mysql', 'pgsql')
    'db_host' => 'localhost', // Database server host
    'db_port' => 3306, // Database server port
    'db_database' => 'registry', // Name of the database
    'db_username' => 'your_username', // Database username
    'db_password' => 'your_password', // Database password
    'epp_host' => '0.0.0.0', // IP that the server will bind to, leave as is if no specific need
    'epp_port' => 700, // Port that the server will use
    'epp_pid' => '/var/run/epp.pid', // PID file of the server (do not change)
    'epp_greeting' => 'Namingo EPP Server 1.0', // EPP server prefix
    'epp_prefix' => 'namingo', // EPP server prefix
    'ssl_cert' => '', // Path to the SSL certificate that will be used by the server
    'ssl_key' => '', // Path to the SSL keyfile that will be used by the server
    'test_tlds' => '.test,.com.test', // Test TLDs for debugging purposes
    'rately' => false, // Enable rate limit
    'limit' => 1000, // Request limit per period below
    'period' => 60, // 60 Seconds
    'minimum_data' => false, // Set to true to enable minimum data set support
];
```

### RDAP Server Configuration (`/opt/registry/rdap/config.php`)

Configuration for the Registration Data Access Protocol (RDAP) server.

```php
<?php

return [
    'db_type' => 'mysql', // Type of the database (e.g., 'mysql', 'pgsql')
    'db_host' => 'localhost', // Database server host
    'db_port' => 3306, // Database server port
    'db_database' => 'registry', // Name of the database
    'db_username' => 'your_username', // Database username
    'db_password' => 'your_password', // Database password
    'roid' => 'XX', // Registry Object ID
    'minimum_data' => false, // Set to true to enable minimum data set support
    'registry_url' => 'https://example.com/rdap-terms', // URL of registry website
    'rdap_url' => 'https://rdap.example.com', // URL of RDAP server
    'rately' => false, // Enable rate limit
    'limit' => 1000, // Request limit per period below
    'period' => 60, // 60 Seconds
];
```

### WHOIS Server Configuration (`/opt/registry/whois/port43/config.php`)

Settings for the WHOIS server running on port 43.

```php
<?php

return [
    'db_type' => 'mysql', // Type of the database (e.g., 'mysql', 'pgsql')
    'db_host' => 'localhost', // Database server host
    'db_port' => 3306, // Database server port
    'db_database' => 'registry', // Name of the database
    'db_username' => 'your_username', // Database username
    'db_password' => 'your_password', // Database password
    'whois_ipv4' => '0.0.0.0',
    'whois_ipv6' => '::', // Set to false if no IPv6 support
    'privacy' => false, // Toggle for privacy mode
    'minimum_data' => false, // Set to true to enable minimum data set support
    'roid' => 'XX', // Registry Object ID
    'rately' => false, // Enable rate limit
    'limit' => 1000, // Request limit per period below
    'period' => 60, // 60 Seconds
];
```

In conclusion, this detailed configuration guide aims to streamline the setup process of the Namingo system for users of all expertise levels. The guide meticulously details each configuration file, providing clear explanations and guidance for customization to suit your specific needs. This approach ensures that you can configure Namingo with confidence, optimizing it for your registry management requirements. We are committed to making the configuration process as straightforward as possible, and we welcome any questions or requests for further assistance. Your successful deployment and efficient management of Namingo is our top priority.

After finalizing the configuration of Namingo, the next step is to consult the [Initial Operation Guide](iog.md). This guide provides comprehensive details on configuring your registry, adding registrars, and much more, to ensure a smooth start with your system.