# Namingo Registry Data Encryption

To ensure GDPR compliance and uphold the highest standards of data privacy, this guide outlines essential strategies for encrypting sensitive data within the Namingo registry. Our focus is to provide a clear and effective approach to database encryption, safeguarding privacy and ensuring the integrity of our users' data.

## MariaDB

### 1. Create Encryption Keys

This process involves creating a directory for the encryption keys, generating the keys, and setting the appropriate permissions.

```bash
mkdir -p /etc/mysql/encryption
echo "1;"$(openssl rand -hex 32) > /etc/mysql/encryption/keyfile
openssl rand -hex 128 > /etc/mysql/encryption/keyfile.key
openssl enc -aes-256-cbc -md sha1 -pass file:/etc/mysql/encryption/keyfile.key -in /etc/mysql/encryption/keyfile -out /etc/mysql/encryption/keyfile.enc
rm -f /etc/mysql/encryption/keyfile
chown -R mysql:mysql /etc/mysql
chmod -R 500 /etc/mysql
```

### 2. Update MariaDB Configuration

Edit ```/etc/my.cnf.d/server.cnf``` and add the following under ```[mariadb]```:

```bash
plugin_load_add = file_key_management
file_key_management_filename = /etc/mysql/encryption/keyfile.enc
file_key_management_filekey = FILE:/etc/mysql/encryption/keyfile.key
file_key_management_encryption_algorithm = AES_CTR

innodb_encrypt_tables = FORCE
innodb_encrypt_log = ON
innodb_encrypt_temporary_tables = ON

encrypt_tmp_disk_tables = ON
encrypt_tmp_files = ON
encrypt_binlog = ON
aria_encrypt_tables = ON

innodb_encryption_threads = 4
innodb_encryption_rotation_iops = 2000
```

### 3. Restart MariaDB

```bash
systemctl restart mariadb
```

### 4. Removing TDE

To disable TDE, execute the following command in the MariaDB command tool:

```bash
SET GLOBAL innodb_encrypt_tables = OFF;
```

### Disclaimer

- This guide provides basic TDE implementation.

- It's recommended not to store the encryption key on the same server. Consider using external key management solutions like Hashicorp Vault Encryption Key Plugin or AWS Key Management Encryption Plugin for enhanced security.

- Always ensure you have backups before making significant changes to your database configuration.

## MySQL

### 1. Create Encryption Keys

This process involves creating a directory for the encryption keys, generating the keys, and setting the appropriate permissions.

```bash
mkdir -p /var/lib/mysql-keyring
openssl rand -hex 32 > /var/lib/mysql-keyring/keyfile
chown -R mysql:mysql /var/lib/mysql-keyring
chmod -R 500 /var/lib/mysql-keyring
```

### 2. Update MySQL Configuration

You need to edit the MySQL configuration file, typically located at ```/etc/mysql/mysql.conf.d/mysqld.cnf```, to enable encryption and specify the keyring file path.

```bash
[mysqld]
early-plugin-load=keyring_file.so
keyring_file_data=/var/lib/mysql-keyring/keyfile

# Enable InnoDB Table Encryption
innodb_encrypt_tables=ON
innodb_encrypt_log=ON
innodb_encryption_threads=4

# Additional optional settings
encrypt_tmp_disk_tables=ON
encrypt_binlog=ON
```

### 3. Restart MySQL

After updating the configuration, restart MySQL to apply the changes.

```bash
systemctl restart mysql
```

### 4. Removing TDE

To disable TDE, execute the following command in the MySQL command tool:

```bash
SET GLOBAL innodb_encrypt_tables = OFF;
```

### Disclaimer

- This is a basic guide for implementing TDE in MySQL.

- Storing the encryption key on the same server as the database is not recommended for high security. Consider using external key management solutions like the AWS Key Management Service or other external keyring plugins for better security practices.

- Always ensure you have backups before making significant changes to your database configuration.

## PostgreSQL

eCryptfs encrypts files at the file system level, securing the data stored by PostgreSQL. This method provides encryption at rest, meaning the data is encrypted when stored on disk.

### 1. Install eCryptfs

On a Linux-based system, you can usually install eCryptfs through the package manager. For example, on Ubuntu, you can use ```sudo apt-get install ecryptfs-utils```.

### 2. Setup Encrypted Directory

Set up an encrypted directory where PostgreSQL will store its data files. This involves creating a new directory and mounting it with eCryptfs.

You can use the ```ecryptfs-setup-private``` script to set up a private directory quickly.

### 3. Configure PostgreSQL to Use Encrypted Directory

Change the data directory of PostgreSQL to point to the encrypted directory. This usually involves updating the ```data_directory``` setting in the PostgreSQL configuration file (```postgresql.conf```).

### 4. Migrate Existing Data (if necessary)

If you're encrypting an existing PostgreSQL installation, you'll need to migrate the data to the encrypted directory. This can be done by copying the existing data files to the new directory.

### 5. Restart PostgreSQL

After changing the data directory, restart the PostgreSQL server.