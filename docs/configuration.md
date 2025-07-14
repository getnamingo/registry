# Namingo Registry: Configuration Guide

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

Before launching the EPP server, edit `/opt/registry/epp/config.php` to set the paths to your certificates and configure other options as needed. Add `'disable_60days' => true,` if you wish to disable the 60-day transfer lock.

Once configured, you can launch the EPP server in the same way as the others:

```bash
systemctl start epp
```

### 1.3. Optional Control Panel Configuration

Features You May Want to Enable or Customize:

#### 1.3.1. Customizing the Logo and Pages

**1.3.1.1. Customizing the Logo**:
Upload your custom logo as `logo.svg` to `/var/www/cp/public/static/`. If `logo.svg` is not present, the default `logo.default.svg` will be used automatically.

**1.3.1.2. Customizing the Documentation Page**:
To customize the documentation, copy `docs.twig` to `docs.custom.twig` using the command `cp /var/www/cp/resources/views/admin/support/docs.twig /var/www/cp/resources/views/admin/support/docs.custom.twig`. Edit `docs.custom.twig` as needed. The system will use `docs.custom.twig` if it exists; otherwise, it defaults to `docs.twig`.

**1.3.1.3. Customizing the Media Kit Page**:
To customize the media kit page, copy `mediakit.twig` to `mediakit.custom.twig` using `cp /var/www/cp/resources/views/admin/support/mediakit.twig /var/www/cp/resources/views/admin/support/mediakit.custom.twig`. Edit `mediakit.custom.twig` to apply your changes. The system will prioritize `mediakit.custom.twig` over the default file.

**1.3.1.4. Customizing the Landing Page**:
To customize the landing page, copy `index.twig` to `index.custom.twig` using `cp /var/www/cp/resources/views/index.twig /var/www/cp/resources/views/index.custom.twig`. Edit `index.custom.twig` to apply your changes. The system will prioritize `index.custom.twig` over the default file.

#### 1.3.2. Changing the Default Language

To change the default language of the control panel, you must edit the `/var/www/cp/.env` file and replace the language values (`LANG`/`UI_LANG`) with your desired settings.

For the `LANG` variable, the supported values are `en_US`, `uk_UA`, `es_ES`, `pt_PT`, `jp_JP`, `ar_SA`, and `fr_FR`. For the `UI_LANG` variable, use `us`, `ua`, `es`, `pt`, `jp`, `ar`, or `fr`.

To apply your changes, save the file, refresh the control panel, and clear the cache using the following command: `php /var/www/cp/bin/clear_cache.php` The new language settings will take effect immediately.

#### 1.3.3. WebAuthn Authentication

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

#### 1.3.4. Password Policy Documentation

**Default Password Strength**
- The default password strength requirement is **3**.
- Password strength is measured on a scale from **0 (weak) to 4 (strong)**.
- To modify the required strength, update the `.env` file.

**Example:**
```sh
PASSWORD_STRENGTH=4
```

This will require stronger passwords.

**Password Expiration**
- By default, passwords expire after **90** days.
- Users will be required to reset their password after this period.
- To change the expiration period, modify the `.env` file.

**Example:**
```sh
PASSWORD_EXPIRATION_DAYS=180
```

This will extend the password expiration to **180** days.

If you wish to **exclude specific accounts from password expiration**, open `/var/www/cp/.env` and **edit the existing** `PASSWORD_EXPIRATION_SKIP_USERS` line (or add it if missing):

```bash
PASSWORD_EXPIRATION_SKIP_USERS=admin,superadmin
```

Add usernames separated by commas. These accounts will **not be subject to password expiration**.

**How to Apply Changes**
- Edit the `.env` file located at `/var/www/cp/.env`
- Save the file and restart Caddy if necessary.

#### 1.3.5. Setting Up Redis Session Storage

To utilize Redis for session storage, you need to install the necessary packages and configure your environment accordingly. Follow these steps to set up Redis session storage:

```bash
cd /var/www/cp
composer require pinga/session-redis
```

After installation, log out of your application if you are currently logged in. This ensures that the session starts afresh with the new configuration.

Clear your browser cookies related to the application. This step is crucial as it removes any existing session cookies that were set using the previous session storage mechanism.

Upon your next login, Redis will be used for storing session data. The new sessions will be created and managed through Redis, providing a more scalable and efficient session management system.

### 1.4. Setting Up the Automation System

Follow these steps to configure the automation system for your registry:

#### 1.4.1. Configuration

Move to the automation directory with the following command:

```bash
cd /opt/registry/automation
```

Open `config.php` and adjust all necessary settings to suit your system's requirements. Make sure to review and fine-tune each option for optimal performance.

#### 1.4.2. Configuring the Message Broker

You can easily configure the message broker for email delivery in ```config.php```. It is compatible with SendGrid, Mailgun API, and PHPMailer for those opting to use their own SMTP server. All necessary settings are conveniently located under the mailer_ lines within the file.

For establishing your own mail server, both [Mox](https://github.com/mjl-/mox) and [Stalwart](https://stalw.art/) offer comprehensive solutions. You can install Mox by following its GitHub instructions, or Stalwart by referring to its official site. Once installed, enter the required details in the ```config.php``` file to complete the setup.

To run the Message Broker, execute the following commands:

```bash
systemctl start msg_producer
systemctl start msg_worker
```

#### 1.4.3. Setting Up an Audit Trail Database for Namingo

To create an audit trail database for Namingo, start by editing the configuration file located at `/opt/registry/automation/audit.json` with the correct database details. This includes specifying the database connection parameters such as host, username, and password. Once your configuration is set up, run the command:

```bash
/opt/registry/automation/vendor/bin/audit -v audit /opt/registry/automation/audit.json
```

This will initialize and configure the audit trail functionality. This process ensures that all necessary tables and structures are set up in the registryAudit database, enabling comprehensive auditing of Namingo's operations.

**Currently, the audit trail setup for Namingo is supported only with MySQL or MariaDB databases. If you're using PostgreSQL, you'll need to utilize an external tool for audit logging, such as [pgAudit](https://minervadb.com/index.php/pgaudit-open-source-postgresql-audit-logging/), which provides detailed audit logging capabilities tailored for PostgreSQL environments.**

#### 1.4.4. Setup Backup

The default backup system in Namingo is based on `phpbu`, which is well-suited for **small to medium databases** and multi-purpose PHP-driven automation. It supports database backups, file system snapshots, remote uploads (e.g., SFTP), and more, using customizable JSON configuration files.

**Step-by-Step Setup:**

1. Rename `/opt/registry/automation/backup.json.dist` and `/opt/registry/automation/backup-upload.json.dist` to `backup.json` and `backup-upload.json`, respectively. 

2. Edit both files to include the correct database and other required details. If using SFTP for uploads with just username and password, make sure you check `backup_upload.php` for which values you need to set to `null` in `backup-upload.json`.

3. Enable the backup functionality in `cron.php` or `cron_config.php`.

4. Follow the instructions in section **1.4.8. Running the Automation System** to activate the automation system on your server.

##### 1.4.4.1. Using mariabackup for Large MariaDB Databases

For large or high-performance MariaDB deployments, you can replace the default `mysqldump`-based backup with `mariabackup`, which performs **physical (binary) backups** without downtime.

**Step-by-Step Setup:**

1. Install `mariabackup` (usually part of the MariaDB-server package or as `mariadb-backup`).

2. Modify `backup.json` to use a `preExec` shell command that runs:

```bash
mariabackup --backup --target-dir=/opt/registry/backups/mariadb --user=... --password=...
```

3. (Optional) Add a `postExec` command to compress the backup or prepare it for upload.

#### 1.4.5. Setting Up Exchange Rate Download

To enable exchange rate updates, follow these steps:

1. Edit `config.php`, modify the following settings and save the file.

```php
return [
    // Exchange Rate Configuration
    'exchange_rate_api_key' => "", // Your exchangerate.host API key
    'exchange_rate_base_currency' => "USD", // Base currency
    'exchange_rate_currencies' => ["EUR", "GBP", "JPY", "CAD", "AUD"], // Target currencies
];

```

2. Enable Exchange Rate Generation

Ensure your `cron.php` or `cron_config.php` executes the exchange rate update script by setting `exchange_rates` to `true`.

If this is not enabled, you will need to manually edit `/var/www/cp/resources/exchange_rates.json` to provide exchange rates.

#### 1.4.6. Zone generator custom records

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

#### 1.4.7. Extra Scheduled Notification Scripts

In `/opt/registry/tests/`, you will find three notification scripts:

- `recent-domains.php`: Notifies about all domains registered in the last **week**.
- `expiring-domains.php`: Sends notifications for domains expiring in **30, 7, and 1 days.**
- `balance-notify.php`: Alerts registrars with **low or zero balance.**

Some registries may wish to use these scripts and run them automatically. Each script includes comments at the beginning that explain the recommended cron job schedule.

#### 1.4.8. Running the Automation System

1. After successfully configuring all the components of the automation system as outlined in the previous sections, you can proceed to initiate the system.

2. Create the configuration file at `/opt/registry/automation/cron_config.php` with the specified structure, and adjust the values to suit your requirements.

```php
<?php
return [
    'accounting' => false,  // Enable or disable accounting
    'backup' => false,      // Enable or disable backup
    'backup_upload' => false, // Enable or disable backup upload
    'gtld_mode' => false,   // Enable or disable gTLD mode
    'spec11' => false,      // Enable or disable Spec 11 checks
    'exchange_rates' => false,     // Enable or disable exchange rate download
    'cds_scanner' => false,     // Enable or disable CDS/CDNSKEY scanning and DS publishing to the zone
];
```

3. Add the following cron job to the system crontab using ```crontab -e```:

```bash
* * * * * /usr/bin/php /opt/registry/automation/cron.php 1>> /dev/null 2>&1
```

## 2. Recommended Components and Integrations

This section outlines recommended components to enhance the functionality and reliability of your Namingo setup.

### 2.1. Setup Monitoring

#### 2.1.1. Option 1: Prometheus

```bash
apt update
apt install prometheus prometheus-node-exporter prometheus-mysqld-exporter prometheus-blackbox-exporter prometheus-redis-exporter
```

Edit the Prometheus configuration file: `/etc/prometheus/prometheus.yml` and replace the `rule_files:` and `scrape_configs:` sections with with the following, while editing the hostnames with your own:

```bash
rule_files:
  - "/etc/prometheus/alert.rules"

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
    metrics_path: /probe
    params:
      module: [tcp_connect]
    static_configs:
      - targets: ['epp.namingo.org:700']
    relabel_configs:
      - source_labels: [__address__]
        target_label: __param_target
      - source_labels: [__param_target]
        target_label: instance
      - target_label: __address__
        replacement: localhost:9115  # Blackbox Exporter

  - job_name: 'whois_server'
    metrics_path: /probe
    params:
      module: [tcp_connect]
    static_configs:
      - targets: ['whois.namingo.org:43']
    relabel_configs:
      - source_labels: [__address__]
        target_label: __param_target
      - source_labels: [__param_target]
        target_label: instance
      - target_label: __address__
        replacement: localhost:9115  # Blackbox Exporter

  - job_name: 'das_server'
    metrics_path: /probe
    params:
      module: [tcp_connect]
    static_configs:
      - targets: ['das.namingo.org:1043']
    relabel_configs:
      - source_labels: [__address__]
        target_label: __param_target
      - source_labels: [__param_target]
        target_label: instance
      - target_label: __address__
        replacement: localhost:9115  # Blackbox Exporter

  - job_name: 'rdap_server'
    metrics_path: /probe
    params:
      module: [tcp_connect]
    static_configs:
      - targets: ['localhost:7500']
    relabel_configs:
      - source_labels: [__address__]
        target_label: __param_target
      - source_labels: [__param_target]
        target_label: instance
      - target_label: __address__
        replacement: localhost:9115  # Blackbox Exporter

  - job_name: 'control_panel'
    static_configs:
      - targets: ['localhost:2019']
      
  - job_name: 'redis'
    static_configs:
      - targets: ['localhost:9121']
```

Set ownership for the configuration file:

```bash
chown prometheus:prometheus /etc/prometheus/prometheus.yml
```

Review the Node Exporter service file:

```bash
nano /lib/systemd/system/prometheus-node-exporter.service
```

Edit the MySQL Exporter configuration, modify the `ExecStart` line to explicitly use the MariaDB config file:

```bash
nano /lib/systemd/system/prometheus-mysqld-exporter.service
```

```ini
ExecStart=/usr/bin/prometheus-mysqld-exporter --config.my-cnf=/etc/mysql/exporter.cnf --web.listen-address=:9104
Restart=always
```

Create the MySQL user:

```sql
CREATE USER 'exporter'@'localhost' IDENTIFIED BY 'yourpassword';
GRANT PROCESS, REPLICATION CLIENT, SELECT ON *.* TO 'exporter'@'localhost';
FLUSH PRIVILEGES;
```

Create a MariaDB config file:

```bash
nano /etc/mysql/exporter.cnf
```

Add the following content (replace `yourpassword` with your real password):

```bash
[client]
user=exporter
password=yourpassword
host=localhost
```

To prevent other users from reading the credentials:

```bash
chmod 600 /etc/mysql/exporter.cnf
chown prometheus:prometheus /etc/mysql/exporter.cnf
```

Add the following on top of the `/etc/caddy/Caddyfile` file, before any other blocks:

```bash
{
    servers {
        metrics
    }
}
```

Create alerts for all services:

```bash
nano /etc/prometheus/alert.rules
```

Paste the following:

```bash
groups:
  - name: all_services
    rules:

      # Alert if Prometheus itself is down
      - alert: PrometheusDown
        expr: up{job="prometheus"} == 0
        for: 1m
        labels:
          severity: critical
        annotations:
          summary: "Prometheus is down"
          description: "Prometheus instance on port 9090 is unreachable for 1 minute."

      # Alert if Node Exporter (System Metrics) is down
      - alert: NodeExporterDown
        expr: up{job="node"} == 0
        for: 1m
        labels:
          severity: critical
        annotations:
          summary: "Node Exporter is down"
          description: "The system monitoring agent on port 9100 is unreachable for 1 minute."

      # Alert if MariaDB Exporter is down
      - alert: MariaDBDown
        expr: up{job="mariadb"} == 0
        for: 1m
        labels:
          severity: critical
        annotations:
          summary: "MariaDB is down"
          description: "The MariaDB exporter on port 9104 is unreachable for 1 minute."

      # Alert if EPP Server is down
      - alert: EPPServerDown
        expr: probe_success{job="epp_server"} == 0
        for: 1m
        labels:
          severity: critical
        annotations:
          summary: "EPP Server is down"
          description: "The EPP server on port 700 is unreachable for 1 minute."

      # Alert if WHOIS Server is down
      - alert: WhoisServerDown
        expr: probe_success{job="whois_server"} == 0
        for: 1m
        labels:
          severity: warning
        annotations:
          summary: "WHOIS Server is down"
          description: "The WHOIS server on port 43 is unreachable for 1 minute."

      # Alert if DAS Server is down
      - alert: DASSserverDown
        expr: probe_success{job="das_server"} == 0
        for: 1m
        labels:
          severity: warning
        annotations:
          summary: "DAS Server is down"
          description: "The DAS server on port 1043 is unreachable for 1 minute."

      # Alert if RDAP Server is down
      - alert: RDAPServerDown
        expr: probe_success{job="rdap_server"} == 0
        for: 1m
        labels:
          severity: critical
        annotations:
          summary: "RDAP Server is down"
          description: "The RDAP server on port 7500 is unreachable for 1 minute."

      # Alert if Control Panel is down
      - alert: ControlPanelDown
        expr: up{job="control_panel"} == 0
        for: 1m
        labels:
          severity: critical
        annotations:
          summary: "Control Panel is down"
          description: "The Caddy control panel monitoring endpoint is unreachable for 1 minute."

      # Alert if Redis Exporter is down
      - alert: RedisDown
        expr: up{job="redis"} == 0
        for: 1m
        labels:
          severity: critical
        annotations:
          summary: "Redis is down"
          description: "Redis exporter on port 9121 is unreachable for 1 minute."
```

Enable and start all services:

```bash
systemctl restart caddy
systemctl daemon-reload

systemctl enable prometheus-node-exporter
systemctl start prometheus-node-exporter

systemctl enable prometheus-mysqld-exporter
systemctl start prometheus-mysqld-exporter

systemctl enable prometheus-blackbox-exporter
systemctl start prometheus-blackbox-exporter

systemctl enable prometheus-redis-exporter
systemctl start prometheus-redis-exporter

systemctl enable prometheus
systemctl start prometheus
```

Open Prometheus in your browser: http://your-server-ip:9090

Check **Status > Targets** to ensure all targets are up.

**Optional: Install Grafana**

```bash
apt-get install -y adduser libfontconfig1 musl
wget https://dl.grafana.com/oss/release/grafana_11.5.1_amd64.deb
dpkg -i grafana_11.5.1_amd64.deb
systemctl daemon-reload
systemctl enable grafana-server
systemctl start grafana-server
```

Open Grafana in your browser: http://your-server-ip:3000

***Add Prometheus as a Data Source***

1. Click Configuration (gear icon) → Data Sources → Add Data Source.

2. Select Prometheus.

3. Set URL to: `http://localhost:9090`

4. Click Save & Test. It should return "Data source is working".

***Import Ready-Made Dashboards***

1. Go to Grafana UI → Dashboards → Import.

2. Paste the Dashboard ID from Grafana.com, for example:

- Prometheus Node Exporter Full: 1860
- Redis Exporter: 763
- MySQL/MariaDB: 7362
- Blackbox Exporter (TCP Probes for EPP, WHOIS, DAS, RDAP): 7587 or 13659
- Prometheus Self-Monitoring: 3662
- Caddy Web Server Monitoring: 13460

3. Click Load, select Prometheus as the data source, and click Import.

***Set Up Alerts in Grafana***

If you want notifications via email, Slack, Telegram, or other tools, you can configure Alerting in Grafana.

1. Go to "Alerting" → "Contact Points" → "Add Contact Point".

2. Choose a notification method (Slack, email, etc.).

3. Create alert rules (e.g., "Alert if Redis is down for 1 minute").

#### 2.1.2. Option 2: Netdata

```bash
wget https://my-netdata.io/kickstart.sh -O install.sh && chmod +x install.sh && ./install.sh
```

Open Netdata in your browser: http://your-server-ip:19999

### 2.2. Recommended Help Desk Solutions

To enhance your customer support experience with Namingo, consider using one of these open-source help desk solutions:

| Solution | License | Key Features |
|----------|---------|--------------|
| [FreeScout](https://freescout.net/) | AGPL-3.0 | Lightweight, email-based help desk with ticketing and multi-channel support. |
| [Chatwoot](https://github.com/chatwoot/chatwoot) | MIT | Omnichannel support platform for email, WhatsApp, social media, and live chat. |

**Note:** These solutions are independent of Namingo. FreeScout is licensed under AGPL-3.0, while Chatwoot uses MIT. If using FreeScout, ensure compliance with AGPL-3.0 licensing.

### 2.3. Scaling Your Database with ProxySQL

To enhance the scalability and performance of your database, consider integrating [ProxySQL](https://proxysql.com/) into your architecture. ProxySQL is a high-performance, open-source proxy designed for MySQL, MariaDB, and other database systems, providing features like query caching, load balancing, query routing, and failover support. By acting as an intermediary between your application and the database, ProxySQL enables efficient distribution of queries across multiple database nodes, reducing latency and improving overall reliability, making it an excellent choice for scaling your database infrastructure.

#### 2.3.1. Install ProxySQL:

```bash
apt update
apt install proxysql
```

#### 2.3.2. Configure ProxySQL:

1. Access the Admin Interface:

- ProxySQL's admin interface listens on port 6032. Connect using the MySQL client:

```bash
mysql -u admin -padmin -h 127.0.0.1 -P6032 --prompt 'ProxySQL Admin> '
```

The default username and password are both `admin`. For security reasons, it's advisable to change these credentials.

2. Add Backend MySQL Servers:

Define your MySQL servers in the `mysql_servers` table. For example, to add a server:

```sql
INSERT INTO mysql_servers (hostgroup_id, hostname, port) VALUES (1, 'your_db_server_ip', 3306);
```

Replace `'your_db_server_ip'` with the IP address of your MySQL server. The `hostgroup_id` is used to group servers; you can assign it based on your architecture.

3. Configure Users:

Specify the MySQL users that ProxySQL will use to connect to the backend servers:

```sql
INSERT INTO mysql_users (username, password, default_hostgroup) VALUES ('your_db_user', 'your_db_password', 1);
```

Replace `'your_db_user'` and `'your_db_password'` with your MySQL user's credentials. Ensure this user has the necessary permissions on the backend MySQL servers.

4. Load Configuration to Runtime and Save to Disk:

After making configuration changes, load them into runtime and save to disk:

```sql
LOAD MYSQL SERVERS TO RUNTIME;
SAVE MYSQL SERVERS TO DISK;

LOAD MYSQL USERS TO RUNTIME;
SAVE MYSQL USERS TO DISK;
```

This ensures that your changes take effect immediately and persist after a restart.

#### 2.3.3. Update Namingo Configuration:

Point all Namingo configuration files to ProxySQL instead of directly connecting to the MySQL servers:

- Update Namingo's database host to the IP address of the ProxySQL server and set the port to 6033 (ProxySQL's default port for MySQL client connections).

Ensure that Namingo uses the same database user credentials configured in ProxySQL.

#### 2.3.4. Test the Configuration:

1. Verify Connections:

Ensure that Namingo can connect to the database through ProxySQL and that queries are being distributed across the backend servers as intended.

2. Monitor Performance:

Use ProxySQL's statistics tables to monitor query performance and load distribution.

### 2.4. Recommended Call Center Solution

To enhance your voice-based customer support, we recommend:

**[Bland.com](https://bland.com)**  
Bland is a modern AI-powered call center platform that allows businesses to deploy ultra-realistic voice agents capable of speaking any language and operating 24/7. It’s built for developers and enterprises looking to automate phone interactions like scheduling, CRM updates, and customer support—at scale.

Key features include:
- AI phone agents with human-like speech
- Fully self-hosted, end-to-end infrastructure
- Customizable conversation flows ("Pathways")
- API-driven integration with CRMs, schedulers, and more
- Enterprise-grade security (SOC2, HIPAA, PCI DSS)

> **Note:** Bland.com is a commercial service and not affiliated with Namingo. While not open-source, it provides advanced AI voice automation capabilities and is ideal for companies seeking scalable, programmable phone support solutions.

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
    
    // IANA Email for Submission Logs
    'iana_email' => 'admin@example.com', // Email address to be used for IANA submission

    // Registry Admin Email
    'admin_email' => 'admin@example.com', // Receives system notifications

    // Exchange Rate Configuration
    'exchange_rate_api_key' => "", // Your exchangerate.host API key
    'exchange_rate_base_currency' => "USD",
    'exchange_rate_currencies' => ["EUR", "GBP", "JPY", "CAD", "AUD"], // Configurable list
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
    // 'disable_60days' => true, // Disable 60-day transfer lock for domains and contacts
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

### Web WHOIS/RDAP Client Configuration (`/var/www/whois/config.php`)

```php
<?php

return [
    'whois_url' => 'whois.example.com',
    'rdap_url' => 'rdap.example.com',
    'ignore_captcha' => true,
    'registry_name' => 'Domain Registry LLC',
    'registry_url' => 'https://example.com',
    'branding' => false,
    'ignore_case_captcha' => false,
];
```

---

For additional setup steps beyond the core configuration, refer to the following guides:

- [DNS Setup Guide](dns.md) – for configuring your hidden master and public DNS servers.
- [gTLD-Specific Setup](gtld.md) – required if you are operating a gTLD under ICANN policies.

> 📘 These guides provide essential configurations for DNS integration and gTLD compliance.

Once you’ve completed the configuration, continue with the operational setup by following this guide:

- [First Steps Guide](iog.md) – continue here to delete test data, configure your registry, add TLDs and registrars, and run your first tests.