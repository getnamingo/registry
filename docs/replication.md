# Namingo Registry Database Replication

## 1. Configuration of MariaDB Galera Cluster

To begin, configure each node (database server) in your MariaDB Galera cluster. This involves editing the configuration file located at `/etc/mysql/mariadb.conf.d/60-galera.cnf` on each server.

> Note: Galera is a multi-primary cluster. There is no master. All nodes are equal. One node is only used to bootstrap the cluster initially.

---

### Bootstrap Node (First Node Only)

Open `/etc/mysql/mariadb.conf.d/60-galera.cnf` and apply:

```bash
[mysqld]
binlog_format=ROW
default-storage-engine=innodb
innodb_autoinc_lock_mode=2

bind-address=0.0.0.0

# Performance (recommended)
innodb_flush_log_at_trx_commit=2
sync_binlog=0

# Galera Provider Configuration
wsrep_on=ON
wsrep_provider=/usr/lib/galera/libgalera_smm.so

# Galera Cluster Configuration
wsrep_cluster_name="galera_cluster"
wsrep_cluster_address="gcomm://node1-ip-address,node2-ip-address,node3-ip-address"

# Galera Node Configuration
wsrep_node_address="node1-ip-address"
wsrep_node_name="node1"

# State Snapshot Transfer (modern method)
wsrep_sst_method=mariabackup
wsrep_sst_auth="sstuser:password"

# Optional (recommended for stability)
wsrep_provider_options="gcache.size=1G"
```

### Second Node

Edit `/etc/mysql/mariadb.conf.d/60-galera.cnf`:

```bash
[mysqld]
binlog_format=ROW
default-storage-engine=innodb
innodb_autoinc_lock_mode=2

bind-address=0.0.0.0

innodb_flush_log_at_trx_commit=2
sync_binlog=0

wsrep_on=ON
wsrep_provider=/usr/lib/galera/libgalera_smm.so

wsrep_cluster_name="galera_cluster"
wsrep_cluster_address="gcomm://node1-ip-address,node2-ip-address,node3-ip-address"

wsrep_node_address="node2-ip-address"
wsrep_node_name="node2"

wsrep_sst_method=mariabackup
wsrep_sst_auth="sstuser:password"

wsrep_provider_options="gcache.size=1G"
```

### Third Node

Edit `/etc/mysql/mariadb.conf.d/60-galera.cnf`:

```bash
[mysqld]
binlog_format=ROW
default-storage-engine=innodb
innodb_autoinc_lock_mode=2

bind-address=0.0.0.0

innodb_flush_log_at_trx_commit=2
sync_binlog=0

wsrep_on=ON
wsrep_provider=/usr/lib/galera/libgalera_smm.so

wsrep_cluster_name="galera_cluster"
wsrep_cluster_address="gcomm://node1-ip-address,node2-ip-address,node3-ip-address"

wsrep_node_address="node3-ip-address"
wsrep_node_name="node3"

wsrep_sst_method=mariabackup
wsrep_sst_auth="sstuser:password"

wsrep_provider_options="gcache.size=1G"
```

## 2. Create SST User (Required for mariabackup)

Run on any node before starting the cluster:

```sql
CREATE USER 'sstuser'@'%' IDENTIFIED BY 'password';
GRANT RELOAD, LOCK TABLES, PROCESS, REPLICATION CLIENT ON *.* TO 'sstuser'@'%';
FLUSH PRIVILEGES;
```

## 3. Cluster Startup Sequence

### Step 1: Stop MariaDB on all nodes

```bash
systemctl stop mariadb
```

### Step 2: Bootstrap the first node

On the **bootstrap node only**, start the cluster:

```bash
galera_new_cluster
```

This both **starts MariaDB** and **initializes the cluster**.

Verify cluster status:

```bash
mysql -u root -p -e "SHOW STATUS LIKE 'wsrep_cluster_size'"
```

Expected result:

```bash
wsrep_cluster_size = 1
```

### Step 3: Start remaining nodes

#### Second Node

```bash
systemctl start mariadb
```

Check cluster size:

```bash
wsrep_cluster_size = 2
```

#### Third Node

```bash
systemctl start mariadb
```

Check cluster size:

```bash
wsrep_cluster_size = 3
```

By following these steps, you will have successfully updated the replication settings for your MariaDB Galera Cluster in Namingo.