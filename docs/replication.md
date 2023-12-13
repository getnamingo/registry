# Namingo Registry Database Replication

## MariaDB

1. Configuration of MariaDB Galera Cluster

To begin, you need to configure each node (database server) in your MariaDB Galera cluster. This involves editing the configuration file located at ```/etc/mysql/mariadb.conf.d/60-galera.cnf``` on each server. Below are the steps for each node:

**Master Database Server:**

Access the configuration file: Open ```/etc/mysql/mariadb.conf.d/60-galera.cnf``` for editing.

Apply Configuration: Replace the existing content with the provided settings:

```bash
[mysqld]
binlog_format=ROW
default-storage-engine=innodb
innodb_autoinc_lock_mode=2
bind-address=0.0.0.0

# Galera Provider Configuration
wsrep_on=ON
wsrep_provider=/usr/lib/galera/libgalera_smm.so

# Galera Cluster Configuration
wsrep_cluster_name="galera_cluster"
wsrep_cluster_address="gcomm://node1-ip-address,node2-ip-address,node3-ip-address"

# Galera Synchronization Configuration
wsrep_sst_method=rsync

# Galera Node Configuration
wsrep_node_address="node1-ip-address"
wsrep_node_name="node1"
```

**Second Database Server:**

Configuration File Editing: Similar to the master server, edit ```/etc/mysql/mariadb.conf.d/60-galera.cnf```.

Update Settings: Replace the existing content with:

```bash
[mysqld]
binlog_format=ROW
default-storage-engine=innodb
innodb_autoinc_lock_mode=2
bind-address=0.0.0.0

# Galera Provider Configuration
wsrep_on=ON
wsrep_provider=/usr/lib/galera/libgalera_smm.so

# Galera Cluster Configuration
wsrep_cluster_name="galera_cluster"
wsrep_cluster_address="gcomm://node1-ip-address,node2-ip-address,node3-ip-address"

# Galera Synchronization Configuration
wsrep_sst_method=rsync

# Galera Node Configuration
wsrep_node_address="node2-ip-address"
wsrep_node_name="node2"
```

**Third Database Server:**

Edit Configuration: Again, modify ```/etc/mysql/mariadb.conf.d/60-galera.cnf``` as done for the other servers.

Implement Changes: Replace the configuration settings with:

```bash
[mysqld]
binlog_format=ROW
default-storage-engine=innodb
innodb_autoinc_lock_mode=2
bind-address=0.0.0.0

# Galera Provider Configuration
wsrep_on=ON
wsrep_provider=/usr/lib/galera/libgalera_smm.so

# Galera Cluster Configuration
wsrep_cluster_name="galera_cluster"
wsrep_cluster_address="gcomm://node1-ip-address,node2-ip-address,node3-ip-address"

# Galera Synchronization Configuration
wsrep_sst_method=rsync

# Galera Node Configuration
wsrep_node_address="node3-ip-address"
wsrep_node_name="node3"
```

2. Stopping MariaDB Services

For all three database servers, you need to halt the MariaDB service. This can be done using the following command:

```bash
systemctl stop mariadb
```

3. Initializing the Galera Cluster

Only on the master database server, you will initiate the cluster:

Start the Cluster: Execute ```galera_new_cluster``` to initialize.

Verify Cluster Status: Check the cluster's status with the command:

```bash
mysql -u root -p -e "SHOW STATUS LIKE 'wsrep_cluster_size'"
```

This should return a cluster size of 1.

4. Starting and Verifying Other Nodes

For the remaining nodes, perform the following:

**Second Node:**

Start MariaDB: Use ```systemctl start mariadb```.

Confirm Cluster Status: Execute the same status command as on the master. The cluster size should now be 2.

**Third Node:**

Service Initiation: Again, start MariaDB with ```systemctl start mariadb```.

Status Check: Verify the cluster status. The expected cluster size should be 3.

By following these steps, you will have successfully updated the replication settings for your MariaDB Galera Cluster in Namingo.

## MySQL

Overview

This document outlines the process for setting up a basic master-slave replication in MySQL. Replication enables data from one MySQL database server (the master) to be replicated to one or more MySQL database servers (the slaves).

Prerequisites

- Two or more MySQL servers (version 5.7 or later recommended).

- Network connectivity between the master and slave servers.

- Identical MySQL version on all servers.

- Unique server IDs for each server in the my.cnf or my.ini file.

1. Configure the Master Server

On the master server, edit the MySQL configuration file (typically ```my.cnf``` or ```my.ini```):

```bash
[mysqld]
server-id=1
log_bin=mysql-bin
binlog_do_db=registry
binlog_do_db=registryTransactions
binlog_do_db=registryAudit
```

- ```server-id```: Unique identifier for the master server.

Restart MySQL to apply these changes.

2. Create a Replication User on the Master

Log in to the MySQL master server and create a dedicated user for replication:

```bash
CREATE USER 'replicator'@'%' IDENTIFIED BY 'password';
GRANT REPLICATION SLAVE ON *.* TO 'replicator'@'%';
```

Replace ```'password'``` with a secure password.

3. Obtain Master Status Information

Execute the following command on the master:

```bash
SHOW MASTER STATUS;
```

Note down the File and Position values, as these will be needed when configuring the slave server.

4. Configure the Slave Server

On the slave server, edit the MySQL configuration:

```bash
[mysqld]
server-id=2
relay_log=relay-log
```

- ```server-id```: Unique identifier for the slave server.

Restart MySQL on the slave server.

5. Set up the Slave to Replicate the Master

On the slave server, run the following command:

```bash
CHANGE MASTER TO
MASTER_HOST='master_ip_address',
MASTER_USER='replicator',
MASTER_PASSWORD='password',
MASTER_LOG_FILE='recorded_log_file_name',
MASTER_LOG_POS=recorded_log_position;
```

Replace ```'master_ip_address'```, ```'recorded_log_file_name'```, and ```'recorded_log_position'``` with the actual master server IP address, and the file and position values noted earlier.

6. Start the Slave

Finally, start the replication on the slave:

```bash
START SLAVE;
```

7. Verify Replication

Check the slave status:

```bash
SHOW SLAVE STATUS\G
```

Look for ```Slave_IO_Running``` and ```Slave_SQL_Running``` both being set to Yes.

## PostgreSQL

1. Configure the Primary Server

On the primary server, edit the PostgreSQL configuration file (```postgresql.conf```):

```bash
# Enable WAL (Write-Ahead Logging) archiving
wal_level = replica
archive_mode = on
archive_command = 'cp %p /path_to_wal_archive/%f'

# Set the maximum number of WAL senders
max_wal_senders = 3

# Set the connection timeout for replication connections
wal_sender_timeout = 60s
```

- ```wal_level```: Set to replica to enable enough information for the standby server.

- ```archive_mode``` and ```archive_command```: Configures archiving of WAL files.

- ```max_wal_senders```: The maximum number of concurrent replication connections.

- ```wal_sender_timeout```: The timeout for replication connections.

2. Allow Replication Connections

In ```pg_hba.conf```, add a line to allow connections from the standby server:

```bash
host replication replicator standby_ip_address/32 md5
```

Replace ```standby_ip_address``` with the actual IP address of the standby server.

replicator is the replication user.

3. Create a Replication User

On the primary server, create a user for replication:

```bash
CREATE ROLE replicator REPLICATION LOGIN PASSWORD 'password';
```

4. Initialize the Standby Server

Stop the PostgreSQL service on the standby server.

Use ```pg_basebackup``` to make a base backup of the primary server:

```bash
pg_basebackup -h primary_ip_address -D /var/lib/postgresql/10/main -U replicator -P --wal-method=fetch
```

5. Configure the Standby Server

On the standby server, create a recovery configuration file (```recovery.conf```):

```bash
standby_mode = 'on'
primary_conninfo = 'host=primary_ip_address port=5432 user=replicator password=password'
```

6. Start the Standby Server

Start PostgreSQL on the standby server. It will now start replicating from the primary server.

7. Verify Replication

Check the replication status on the primary server:

```bash
SELECT * FROM pg_stat_replication;
```