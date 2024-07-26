# Namingo Upgrade Guide (v1.0.0-RC4 to v1.0.0-RC5)

## Introduction

This guide will walk you through the steps to upgrade your registry system. The process involves backing up your current setup, cloning the latest code, making database changes, and updating configuration files.
**This upgrade guide is a work in progress, and some instructions might be generic or unclear, so please tune them for your specific situation.**

## Step 1: Backup Your Current Setup

1. Backup Web and Registry Directories:

```bash
cp -r /var/www /var/www_backup
cp -r /opt/registry /opt/registry_backup
```

2. Backup Database:

```bash
mysqldump -u your_username -p your_database_name > database_backup.sql
```

## Step 2: Clone the Latest Code

1. Clone the Repository:

```bash
git clone https://github.com/getnamingo/registry /opt/upgrade
```

## Step 3: Make Database Changes

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

**Warning: If you have already activated the database audit feature, you will need to update the respective audit table to reflect these changes as well.**

## Step 4: Update Configuration Files

1. Add `minimal_data` Setting in `config.php`/`.env` Files:
Open your configuration files and add the following setting:

```php
// In config.php
'minimal_data' => false, // or true based on your requirement

// In .env
MINIMAL_DATA=false // or true based on your requirement
```

2. Add `zone_mode` Setting in `config.php`/`.env` Files:

```php
// In config.php
'zone_mode' => 'nice', // or 'default' based on your requirement

// In .env
ZONE_MODE=nice // or default based on your requirement
```

3. Change records `whois_ipv4`/`whois_ipv6`/`epp_host_ipv6`/`das_ipv4`/`das_ipv6` in `config.php` Files for WHOIS/DAS/EPP components to match the new version.

## Step 5: Replace Old Files with New Files

1. Make sure to preserve the old `.env` and `config.php` files.

2. Replace Files in `/opt/registry` and `/var/www`:

```bash
cp -r /opt/upgrade/* /opt/registry/
cp -r /opt/upgrade/web/* /var/www/
```

## Step 6: Delete Panel Cache

1. Delete All Folders in `/var/www/cp/cache`:

```bash
find /var/www/cp/cache/* -type d -exec rm -rf {} +
```

## Step 7: Restart Services

```bash
systemctl restart caddy
systemctl restart epp
systemctl restart whois
systemctl restart rdap
systemctl restart das
```

## Conclusion

Following these steps will ensure that your registry system is upgraded successfully. If you encounter any issues, please refer to the documentation or contact support for assistance.