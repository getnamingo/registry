# Namingo Update Guide

## v1.0.0 to v1.0.13

You must run update scripts in order, without skipping versions. For example, if you're on 1.0.10 and the latest is 1.0.12, first update to 1.0.11, then to 1.0.12.

- v1.0.12 to v1.0.13 - download and run the [update1013.sh](docs/update1013.sh) script.

- v1.0.11 to v1.0.12 - download and run the [update1012.sh](docs/update1012.sh) script.

- v1.0.10 to v1.0.11 - download and run the [update1011.sh](docs/update1011.sh) script.

- v1.0.9 to v1.0.10 - download and run the [update1010.sh](docs/update1010.sh) script.

- v1.0.8 to v1.0.9 - download and run the [update109.sh](docs/update109.sh) script.

- v1.0.7 to v1.0.8 - download and run the [update108.sh](docs/update108.sh) script.

- v1.0.6 to v1.0.7 - download and run the [update107.sh](docs/update107.sh) script.

- v1.0.5 to v1.0.6 - download and run the [update106.sh](docs/update106.sh) script.

- v1.0.4 to v1.0.5 - download and run the [update105.sh](docs/update105.sh) script.

- v1.0.3 to v1.0.4 - download and run the [update104.sh](docs/update104.sh) script.

- v1.0.2 to v1.0.3 - download and run the [update103.sh](docs/update103.sh) script.

- v1.0.1 to v1.0.2 - download and run the [update102.sh](docs/update102.sh) script.

- v1.0.0 to v1.0.1 - download and run the [update101.sh](docs/update101.sh) script.

## v1.0.0-RC4 to v1.0.0

This guide will walk you through the steps to update your registry system. The process involves backing up your current setup, cloning the latest code, making database changes, and updating configuration files.
**This update guide is a work in progress, and some instructions might be generic or unclear, so please tune them for your specific situation.**

### Step 1: Backup Your Current Setup

1. Backup Web and Registry Directories:

```bash
cp -r /var/www /var/www_backup
cp -r /opt/registry /opt/registry_backup
```

2. Backup Database:

```bash
mysqldump -u your_username -p your_database_name > database_backup.sql
```

### Step 2: Clone the Latest Code

1. Clone the Repository:

```bash
git clone https://github.com/getnamingo/registry /opt/update
```

### Step 3a: Make Database Changes (from v1.0.0-RC4)

1. Access MySQL Terminal:

```bash
mysql -u your_username -p
```

2. Update `registrar` Table:

```sql
USE registry;

ALTER TABLE registrar 
CHANGE COLUMN `vat_number` `vatNumber` varchar(30) DEFAULT NULL,
ADD COLUMN `companyNumber` varchar(30) DEFAULT NULL BEFORE `vatNumber`;

UPDATE settings SET value = NULL WHERE name = 'launch_phases';
```

### Step 3b: Make Database Changes (from v1.0.0-RC5)

1. Access MySQL Terminal:

```bash
mysql -u your_username -p
```

2. Update `registrar` Table:

```sql
USE registry;

ALTER TABLE `domain_price`
ADD `registrar_id` int(10) unsigned NULL AFTER `tldid`;

ALTER TABLE `domain_price`
ADD UNIQUE `tldid_command_registrar_id` (`tldid`, `command`, `registrar_id`),
DROP INDEX `unique_record`;

ALTER TABLE `domain_restore_price`
ADD `registrar_id` int(10) unsigned NULL AFTER `tldid`;

ALTER TABLE `domain_restore_price`
ADD UNIQUE `tldid_registrar_id` (`tldid`, `registrar_id`),
DROP INDEX `tldid`;
```

**Warning: If you have already activated the database audit feature, you will need to update the respective audit table to reflect these changes as well.**

### Step 4: Update Configuration Files

1. Add `minimum_data` Setting in `config.php`/`.env` Files:
Open your configuration files and add the following setting:

```php
// In config.php
'minimum_data' => false, // or true based on your requirement

// In .env
MINIMUM_DATA=false // or true based on your requirement
```

2. Add `zone_mode` Setting in `config.php`/`.env` Files:

```php
// In config.php
'zone_mode' => 'nice', // or 'default' based on your requirement

// In .env
ZONE_MODE=nice // or default based on your requirement
```

3. Change records `whois_ipv4`/`whois_ipv6`/`epp_host_ipv6`/`das_ipv4`/`das_ipv6` in `config.php` Files for WHOIS/DAS/EPP components to match the new version.

### Step 5: Replace Old Files with New Files

1. Make sure to preserve the old `.env` and `config.php` files.

2. Replace Files in `/opt/registry` and `/var/www`:

```bash
cp -r /opt/update/* /opt/registry/
cp -r /opt/update/cp/* /var/www/
cp -r /opt/update/whois/* /var/www/
```

### Step 6: Delete Panel Cache and Update Composer

1. Delete All Folders in `/var/www/cp/cache`:

```bash
find /var/www/cp/cache/* -type d -exec rm -rf {} +
```

2. Run `composer update` in each component directory:

```bash
/opt/registry/epp
/opt/registry/das
/opt/registry/rdap
/opt/registry/whois/port43
/var/www/cp
/var/www/whois
```

### Step 7: Restart Services

```bash
systemctl restart caddy
systemctl restart epp
systemctl restart whois
systemctl restart rdap
systemctl restart das
```