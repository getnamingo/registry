#!/bin/sh

set -eu

PHASE=${1:-}

log() {
    printf '[migration %s] %s\n' "${NAMINGO_TARGET_VERSION:-1.0.37}" "$*"
}

die() {
    printf 'Migration error: %s\n' "$*" >&2
    exit 1
}

require_command() {
    command -v "$1" >/dev/null 2>&1 \
        || die "Required command not found: $1"
}

db_sql() {
    database=${NAMINGO_DB_MAIN:-${NAMINGO_DB_NAME:-registry}}
    case "$NAMINGO_DB_DRIVER" in
        mariadb)
            require_command mariadb
            MYSQL_PWD=$NAMINGO_DB_PASSWORD mariadb \
                --host="$NAMINGO_DB_HOST" --port="$NAMINGO_DB_PORT" \
                --user="$NAMINGO_DB_USER" --database="$database"
            ;;
        pgsql)
            require_command psql
            PGPASSWORD=$NAMINGO_DB_PASSWORD psql \
                --host="$NAMINGO_DB_HOST" --port="$NAMINGO_DB_PORT" \
                --username="$NAMINGO_DB_USER" --dbname="$database" \
                -X -v ON_ERROR_STOP=1
            ;;
        *) die "Unsupported database driver: $NAMINGO_DB_DRIVER" ;;
    esac
}

upgrade_ticket_assignment() {
    log "Adding internal support-ticket assignment"
    db_sql <<'SQL'
ALTER TABLE support_tickets ADD COLUMN IF NOT EXISTS assigned_to INT DEFAULT NULL;
SQL
}

upgrade_registrar_onboarding() {
    log "Creating registrar onboarding application table"
    case "$NAMINGO_DB_DRIVER" in
        mariadb)
            db_sql <<'SQL'
CREATE TABLE IF NOT EXISTS `registrar_onboarding` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `public_token` CHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    `application_data` LONGTEXT NOT NULL,
    `agreement_html` LONGTEXT NOT NULL,
    `status` VARCHAR(24) NOT NULL DEFAULT 'pendingAgreement',
    `signature` LONGTEXT DEFAULT NULL,
    `signed_ip` VARCHAR(45) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `signed_at` DATETIME DEFAULT NULL,
    `registrar_id` INT UNSIGNED DEFAULT NULL,
    INDEX `status_idx` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
            ;;
        pgsql)
            db_sql <<'SQL'
CREATE TABLE IF NOT EXISTS registrar_onboarding (
    id BIGSERIAL PRIMARY KEY,
    public_token CHAR(48) NOT NULL UNIQUE,
    application_data TEXT NOT NULL,
    agreement_html TEXT NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'pendingAgreement',
    signature TEXT DEFAULT NULL,
    signed_ip VARCHAR(45) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    signed_at TIMESTAMP DEFAULT NULL,
    registrar_id INTEGER DEFAULT NULL
);
CREATE INDEX IF NOT EXISTS registrar_onboarding_status_idx ON registrar_onboarding (status);
SQL
            ;;
        *) die "Unsupported database driver: $NAMINGO_DB_DRIVER" ;;
    esac
}

upgrade_balance_mapping() {
    log "Installing transactional balance poll triggers"
    case "$NAMINGO_DB_DRIVER" in
        mariadb)
            db_sql <<'SQL'
DROP TRIGGER IF EXISTS `registry`.`registrar_balance_low_insert`;
DROP TRIGGER IF EXISTS `registry`.`registrar_balance_low_update`;
DELIMITER //
CREATE TRIGGER `registry`.`registrar_balance_low_insert`
AFTER INSERT ON `registry`.`registrar` FOR EACH ROW
BEGIN
    DECLARE new_threshold DECIMAL(24,2);
    SET new_threshold = IF(NEW.thresholdType = 'percent',
        ROUND(NEW.creditLimit * NEW.creditThreshold / 100, 2), NEW.creditThreshold);
    IF NEW.accountBalance <= new_threshold THEN
        INSERT INTO `registry`.`poll` (registrar_id, qdate, msg_type, msg)
        VALUES (NEW.id, UTC_TIMESTAMP(3), 'lowBalance',
            JSON_OBJECT(
                'mapping', 'balance-0.4',
                'currency', NEW.currency,
                'balanceAvailable', CAST(NEW.accountBalance + NEW.creditLimit AS CHAR),
                'creditLine', CAST(NEW.creditLimit AS CHAR),
                'cashBalance', CAST(NEW.accountBalance AS CHAR),
                'executionLimit', CAST(-NEW.creditLimit AS CHAR),
                'notificationThreshold', CAST(new_threshold AS CHAR)
            ));
    END IF;
END//

CREATE TRIGGER `registry`.`registrar_balance_low_update`
AFTER UPDATE ON `registry`.`registrar` FOR EACH ROW
BEGIN
    DECLARE old_threshold DECIMAL(24,2);
    DECLARE new_threshold DECIMAL(24,2);
    SET old_threshold = IF(OLD.thresholdType = 'percent',
        ROUND(OLD.creditLimit * OLD.creditThreshold / 100, 2), OLD.creditThreshold);
    SET new_threshold = IF(NEW.thresholdType = 'percent',
        ROUND(NEW.creditLimit * NEW.creditThreshold / 100, 2), NEW.creditThreshold);
    IF OLD.accountBalance > old_threshold AND NEW.accountBalance <= new_threshold THEN
        INSERT INTO `registry`.`poll` (registrar_id, qdate, msg_type, msg)
        VALUES (NEW.id, UTC_TIMESTAMP(3), 'lowBalance',
            JSON_OBJECT(
                'mapping', 'balance-0.4',
                'currency', NEW.currency,
                'balanceAvailable', CAST(NEW.accountBalance + NEW.creditLimit AS CHAR),
                'creditLine', CAST(NEW.creditLimit AS CHAR),
                'cashBalance', CAST(NEW.accountBalance AS CHAR),
                'executionLimit', CAST(-NEW.creditLimit AS CHAR),
                'notificationThreshold', CAST(new_threshold AS CHAR)
            ));
    END IF;
END//
DELIMITER ;
SQL
            ;;
        pgsql)
            db_sql <<'SQL'
CREATE OR REPLACE FUNCTION registrar_balance_low_poll() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    old_threshold numeric;
    new_threshold numeric;
BEGIN
    new_threshold := CASE WHEN NEW.thresholdtype = 'percent'
        THEN ROUND(NEW.creditlimit * NEW.creditthreshold / 100, 2)
        ELSE NEW.creditthreshold END;

    IF TG_OP = 'UPDATE' THEN
        old_threshold := CASE WHEN OLD.thresholdtype = 'percent'
            THEN ROUND(OLD.creditlimit * OLD.creditthreshold / 100, 2)
            ELSE OLD.creditthreshold END;
        IF NOT (OLD.accountbalance > old_threshold AND NEW.accountbalance <= new_threshold) THEN
            RETURN NEW;
        END IF;
    ELSIF NEW.accountbalance > new_threshold THEN
        RETURN NEW;
    END IF;

    INSERT INTO poll (registrar_id, qdate, msg_type, msg)
    VALUES (NEW.id, timezone('UTC', clock_timestamp()), 'lowBalance',
        json_build_object(
            'mapping', 'balance-0.4',
            'currency', NEW.currency,
            'balanceAvailable', (NEW.accountbalance + NEW.creditlimit)::text,
            'creditLine', NEW.creditlimit::text,
            'cashBalance', NEW.accountbalance::text,
            'executionLimit', (-NEW.creditlimit)::text,
            'notificationThreshold', new_threshold::text
        )::text);
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS registrar_balance_low_insert ON registrar;
CREATE TRIGGER registrar_balance_low_insert
AFTER INSERT ON registrar FOR EACH ROW
EXECUTE FUNCTION registrar_balance_low_poll();

DROP TRIGGER IF EXISTS registrar_balance_low_update ON registrar;
CREATE TRIGGER registrar_balance_low_update
AFTER UPDATE OF accountbalance, creditlimit, creditthreshold, thresholdtype
ON registrar FOR EACH ROW
EXECUTE FUNCTION registrar_balance_low_poll();
SQL
            ;;
        *) die "Unsupported database driver: $NAMINGO_DB_DRIVER" ;;
    esac
}

upgrade_reporting_history() {
    log "Installing ICANN reporting history and baseline"
    case "$NAMINGO_DB_DRIVER" in
        mariadb)
            db_sql <<'SQL'
-- Namingo Registry ICANN Specification 3 event history for mariadb.
CREATE TABLE IF NOT EXISTS reporting_baseline (
  id INTEGER PRIMARY KEY,
  installed_at DATETIME(3) NOT NULL
);

CREATE TABLE IF NOT EXISTS reporting_domain_event (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_time DATETIME(3) NOT NULL,
  action VARCHAR(8) NOT NULL,
  domain_id BIGINT NOT NULL,
  tldid INTEGER NOT NULL,
  name VARCHAR(255) NOT NULL,
  clid INTEGER NOT NULL,
  prev_clid INTEGER NULL,
  reid INTEGER NULL,
  trstatus VARCHAR(32) NULL,
  prev_trstatus VARCHAR(32) NULL,
  crdate DATETIME(3) NOT NULL,
  rgpstatus VARCHAR(32) NULL,
  prev_rgpstatus VARCHAR(32) NULL,
  rgpres_time DATETIME(3) NULL,
  prev_rgpres_time DATETIME(3) NULL,
  rgpres_reason TEXT NULL,
  agp_request DATETIME(3) NULL,
  prev_agp_request DATETIME(3) NULL,
  agp_grant DATETIME(3) NULL,
  prev_agp_grant DATETIME(3) NULL,
  agp_exempted INTEGER NULL,
  prev_agp_exempted INTEGER NULL,
  add_period INTEGER NULL,
  renew_period INTEGER NULL,
  renewed_date DATETIME(3) NULL,
  transfer_period INTEGER NULL,
  trdate DATETIME(3) NULL,
  exdate DATETIME(3) NULL
);

CREATE INDEX IF NOT EXISTS reporting_de_asof ON reporting_domain_event(domain_id, id);
CREATE INDEX IF NOT EXISTS reporting_de_tldtime ON reporting_domain_event(tldid, event_time);
CREATE INDEX IF NOT EXISTS reporting_de_name ON reporting_domain_event(name, crdate, action, event_time);

CREATE TABLE IF NOT EXISTS reporting_host_event (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_time DATETIME(3) NOT NULL,
  action VARCHAR(8) NOT NULL,
  host_id BIGINT NOT NULL,
  name VARCHAR(255) NOT NULL,
  clid INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS reporting_he_asof ON reporting_host_event(host_id, id);

CREATE TABLE IF NOT EXISTS reporting_map_event (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_time DATETIME(3) NOT NULL,
  action VARCHAR(8) NOT NULL,
  domain_id BIGINT NOT NULL,
  host_id BIGINT NOT NULL
);
CREATE INDEX IF NOT EXISTS reporting_me_asof ON reporting_map_event(domain_id, host_id, id);

CREATE TABLE IF NOT EXISTS reporting_pending_create_event (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_time DATETIME(3) NOT NULL,
  action VARCHAR(8) NOT NULL,
  domain_id BIGINT NOT NULL
);
CREATE INDEX IF NOT EXISTS reporting_pce_asof ON reporting_pending_create_event(domain_id, id);


DELIMITER //

CREATE TRIGGER IF NOT EXISTS reporting_domain_ainsert AFTER INSERT ON domain FOR EACH ROW
BEGIN
  INSERT INTO reporting_domain_event (event_time, action, domain_id, tldid, name, clid, prev_clid, reid, trstatus, prev_trstatus, crdate, rgpstatus, prev_rgpstatus, rgpres_time, prev_rgpres_time, rgpres_reason, agp_request, prev_agp_request, agp_grant, prev_agp_grant, agp_exempted, prev_agp_exempted, add_period, renew_period, renewed_date, transfer_period, trdate, exdate) VALUES (UTC_TIMESTAMP(3), 'insert', NEW.`id`, NEW.`tldid`, NEW.`name`, NEW.`clid`, NULL, NEW.`reid`, NEW.`trstatus`, NULL, NEW.`crdate`, NEW.`rgpstatus`, NULL, NEW.`rgpresTime`, NULL, NEW.`rgpresReason`, NEW.`agp_request`, NULL, NEW.`agp_grant`, NULL, NEW.`agp_exempted`, NULL, NEW.`addPeriod`, NEW.`renewPeriod`, NEW.`renewedDate`, NEW.`transferPeriod`, NEW.`trdate`, NEW.`exdate`);
END//

CREATE TRIGGER IF NOT EXISTS reporting_domain_aupdate AFTER UPDATE ON domain FOR EACH ROW
BEGIN
  INSERT INTO reporting_domain_event (event_time, action, domain_id, tldid, name, clid, prev_clid, reid, trstatus, prev_trstatus, crdate, rgpstatus, prev_rgpstatus, rgpres_time, prev_rgpres_time, rgpres_reason, agp_request, prev_agp_request, agp_grant, prev_agp_grant, agp_exempted, prev_agp_exempted, add_period, renew_period, renewed_date, transfer_period, trdate, exdate) VALUES (UTC_TIMESTAMP(3), 'update', NEW.`id`, NEW.`tldid`, NEW.`name`, NEW.`clid`, OLD.`clid`, NEW.`reid`, NEW.`trstatus`, OLD.`trstatus`, NEW.`crdate`, NEW.`rgpstatus`, OLD.`rgpstatus`, NEW.`rgpresTime`, OLD.`rgpresTime`, NEW.`rgpresReason`, NEW.`agp_request`, OLD.`agp_request`, NEW.`agp_grant`, OLD.`agp_grant`, NEW.`agp_exempted`, OLD.`agp_exempted`, NEW.`addPeriod`, NEW.`renewPeriod`, NEW.`renewedDate`, NEW.`transferPeriod`, NEW.`trdate`, NEW.`exdate`);
END//

CREATE TRIGGER IF NOT EXISTS reporting_domain_adelete AFTER DELETE ON domain FOR EACH ROW
BEGIN
  INSERT INTO reporting_domain_event (event_time, action, domain_id, tldid, name, clid, prev_clid, reid, trstatus, prev_trstatus, crdate, rgpstatus, prev_rgpstatus, rgpres_time, prev_rgpres_time, rgpres_reason, agp_request, prev_agp_request, agp_grant, prev_agp_grant, agp_exempted, prev_agp_exempted, add_period, renew_period, renewed_date, transfer_period, trdate, exdate) VALUES (UTC_TIMESTAMP(3), 'delete', OLD.`id`, OLD.`tldid`, OLD.`name`, OLD.`clid`, OLD.`clid`, OLD.`reid`, OLD.`trstatus`, OLD.`trstatus`, OLD.`crdate`, OLD.`rgpstatus`, OLD.`rgpstatus`, OLD.`rgpresTime`, OLD.`rgpresTime`, OLD.`rgpresReason`, OLD.`agp_request`, OLD.`agp_request`, OLD.`agp_grant`, OLD.`agp_grant`, OLD.`agp_exempted`, OLD.`agp_exempted`, OLD.`addPeriod`, OLD.`renewPeriod`, OLD.`renewedDate`, OLD.`transferPeriod`, OLD.`trdate`, OLD.`exdate`);
END//

CREATE TRIGGER IF NOT EXISTS reporting_host_ainsert AFTER INSERT ON host FOR EACH ROW
BEGIN
  INSERT INTO reporting_host_event (event_time, action, host_id, name, clid)
  VALUES (UTC_TIMESTAMP(3), 'insert', NEW.`id`, NEW.`name`, NEW.`clid`);
END//

CREATE TRIGGER IF NOT EXISTS reporting_host_aupdate AFTER UPDATE ON host FOR EACH ROW
BEGIN
  INSERT INTO reporting_host_event (event_time, action, host_id, name, clid)
  VALUES (UTC_TIMESTAMP(3), 'update', NEW.`id`, NEW.`name`, NEW.`clid`);
END//

CREATE TRIGGER IF NOT EXISTS reporting_host_adelete AFTER DELETE ON host FOR EACH ROW
BEGIN
  INSERT INTO reporting_host_event (event_time, action, host_id, name, clid)
  VALUES (UTC_TIMESTAMP(3), 'delete', OLD.`id`, OLD.`name`, OLD.`clid`);
END//

CREATE TRIGGER IF NOT EXISTS reporting_map_ainsert AFTER INSERT ON domain_host_map FOR EACH ROW
BEGIN
  INSERT INTO reporting_map_event (event_time, action, domain_id, host_id)
  VALUES (UTC_TIMESTAMP(3), 'insert', NEW.`domain_id`, NEW.`host_id`);
END//

CREATE TRIGGER IF NOT EXISTS reporting_map_adelete AFTER DELETE ON domain_host_map FOR EACH ROW
BEGIN
  INSERT INTO reporting_map_event (event_time, action, domain_id, host_id)
  VALUES (UTC_TIMESTAMP(3), 'delete', OLD.`domain_id`, OLD.`host_id`);
END//

CREATE TRIGGER IF NOT EXISTS reporting_pending_create_ainsert AFTER INSERT ON domain_status FOR EACH ROW
BEGIN
  IF NEW.status = 'pendingCreate' THEN
    INSERT INTO reporting_pending_create_event (event_time, action, domain_id)
    VALUES (UTC_TIMESTAMP(3), 'insert', NEW.domain_id);
  END IF;
END//

CREATE TRIGGER IF NOT EXISTS reporting_pending_create_adelete AFTER DELETE ON domain_status FOR EACH ROW
BEGIN
  IF OLD.status = 'pendingCreate' THEN
    INSERT INTO reporting_pending_create_event (event_time, action, domain_id)
    VALUES (UTC_TIMESTAMP(3), 'delete', OLD.domain_id);
  END IF;
END//

DELIMITER ;

INSERT INTO reporting_domain_event (event_time, action, domain_id, tldid, name, clid, prev_clid, reid, trstatus, prev_trstatus, crdate, rgpstatus, prev_rgpstatus, rgpres_time, prev_rgpres_time, rgpres_reason, agp_request, prev_agp_request, agp_grant, prev_agp_grant, agp_exempted, prev_agp_exempted, add_period, renew_period, renewed_date, transfer_period, trdate, exdate) SELECT UTC_TIMESTAMP(3), 'insert', d.`id`, d.`tldid`, d.`name`, d.`clid`, NULL, d.`reid`, d.`trstatus`, NULL, d.`crdate`, d.`rgpstatus`, NULL, d.`rgpresTime`, NULL, d.`rgpresReason`, d.`agp_request`, NULL, d.`agp_grant`, NULL, d.`agp_exempted`, NULL, d.`addPeriod`, d.`renewPeriod`, d.`renewedDate`, d.`transferPeriod`, d.`trdate`, d.`exdate` FROM domain d WHERE NOT EXISTS (SELECT 1 FROM reporting_baseline WHERE id = 1);

INSERT INTO reporting_host_event (event_time,action,host_id,name,clid) SELECT UTC_TIMESTAMP(3), 'insert', h.id, h.name, h.clid FROM host h WHERE NOT EXISTS (SELECT 1 FROM reporting_baseline WHERE id = 1);

INSERT INTO reporting_map_event (event_time,action,domain_id,host_id) SELECT UTC_TIMESTAMP(3), 'insert', m.domain_id, m.host_id FROM domain_host_map m WHERE NOT EXISTS (SELECT 1 FROM reporting_baseline WHERE id = 1);

INSERT INTO reporting_pending_create_event (event_time,action,domain_id) SELECT UTC_TIMESTAMP(3), 'insert', s.domain_id FROM domain_status s WHERE s.status = 'pendingCreate' AND NOT EXISTS (SELECT 1 FROM reporting_baseline WHERE id = 1);

INSERT IGNORE INTO reporting_baseline (id, installed_at) VALUES (1, UTC_TIMESTAMP(3));
SQL
            ;;
        pgsql)
            db_sql <<'SQL'
-- Namingo Registry ICANN Specification 3 event history for postgres.
CREATE TABLE IF NOT EXISTS reporting_baseline (
  id INTEGER PRIMARY KEY,
  installed_at TIMESTAMP(3) WITHOUT TIME ZONE NOT NULL
);

CREATE TABLE IF NOT EXISTS reporting_domain_event (
  id BIGSERIAL PRIMARY KEY,
  event_time TIMESTAMP(3) WITHOUT TIME ZONE NOT NULL,
  action VARCHAR(8) NOT NULL,
  domain_id BIGINT NOT NULL,
  tldid INTEGER NOT NULL,
  name VARCHAR(255) NOT NULL,
  clid INTEGER NOT NULL,
  prev_clid INTEGER NULL,
  reid INTEGER NULL,
  trstatus VARCHAR(32) NULL,
  prev_trstatus VARCHAR(32) NULL,
  crdate TIMESTAMP(3) WITHOUT TIME ZONE NOT NULL,
  rgpstatus VARCHAR(32) NULL,
  prev_rgpstatus VARCHAR(32) NULL,
  rgpres_time TIMESTAMP(3) WITHOUT TIME ZONE NULL,
  prev_rgpres_time TIMESTAMP(3) WITHOUT TIME ZONE NULL,
  rgpres_reason TEXT NULL,
  agp_request TIMESTAMP(3) WITHOUT TIME ZONE NULL,
  prev_agp_request TIMESTAMP(3) WITHOUT TIME ZONE NULL,
  agp_grant TIMESTAMP(3) WITHOUT TIME ZONE NULL,
  prev_agp_grant TIMESTAMP(3) WITHOUT TIME ZONE NULL,
  agp_exempted BOOLEAN NULL,
  prev_agp_exempted BOOLEAN NULL,
  add_period INTEGER NULL,
  renew_period INTEGER NULL,
  renewed_date TIMESTAMP(3) WITHOUT TIME ZONE NULL,
  transfer_period INTEGER NULL,
  trdate TIMESTAMP(3) WITHOUT TIME ZONE NULL,
  exdate TIMESTAMP(3) WITHOUT TIME ZONE NULL
);

CREATE INDEX IF NOT EXISTS reporting_de_asof ON reporting_domain_event(domain_id, id);
CREATE INDEX IF NOT EXISTS reporting_de_tldtime ON reporting_domain_event(tldid, event_time);
CREATE INDEX IF NOT EXISTS reporting_de_name ON reporting_domain_event(name, crdate, action, event_time);

CREATE TABLE IF NOT EXISTS reporting_host_event (
  id BIGSERIAL PRIMARY KEY,
  event_time TIMESTAMP(3) WITHOUT TIME ZONE NOT NULL,
  action VARCHAR(8) NOT NULL,
  host_id BIGINT NOT NULL,
  name VARCHAR(255) NOT NULL,
  clid INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS reporting_he_asof ON reporting_host_event(host_id, id);

CREATE TABLE IF NOT EXISTS reporting_map_event (
  id BIGSERIAL PRIMARY KEY,
  event_time TIMESTAMP(3) WITHOUT TIME ZONE NOT NULL,
  action VARCHAR(8) NOT NULL,
  domain_id BIGINT NOT NULL,
  host_id BIGINT NOT NULL
);
CREATE INDEX IF NOT EXISTS reporting_me_asof ON reporting_map_event(domain_id, host_id, id);

CREATE TABLE IF NOT EXISTS reporting_pending_create_event (
  id BIGSERIAL PRIMARY KEY,
  event_time TIMESTAMP(3) WITHOUT TIME ZONE NOT NULL,
  action VARCHAR(8) NOT NULL,
  domain_id BIGINT NOT NULL
);
CREATE INDEX IF NOT EXISTS reporting_pce_asof ON reporting_pending_create_event(domain_id, id);


CREATE OR REPLACE FUNCTION reporting_domain_capture() RETURNS trigger AS $$
BEGIN
  IF TG_OP = 'INSERT' THEN
    INSERT INTO reporting_domain_event (event_time, action, domain_id, tldid, name, clid, prev_clid, reid, trstatus, prev_trstatus, crdate, rgpstatus, prev_rgpstatus, rgpres_time, prev_rgpres_time, rgpres_reason, agp_request, prev_agp_request, agp_grant, prev_agp_grant, agp_exempted, prev_agp_exempted, add_period, renew_period, renewed_date, transfer_period, trdate, exdate) VALUES ((clock_timestamp() AT TIME ZONE 'UTC'), 'insert', NEW."id", NEW."tldid", NEW."name", NEW."clid", NULL, NEW."reid", NEW."trstatus", NULL, NEW."crdate", NEW."rgpstatus", NULL, NEW."rgprestime", NULL, NEW."rgpresreason", NEW."agp_request", NULL, NEW."agp_grant", NULL, NEW."agp_exempted", NULL, NEW."addperiod", NEW."renewperiod", NEW."reneweddate", NEW."transferperiod", NEW."trdate", NEW."exdate");
    RETURN NEW;
  ELSIF TG_OP = 'UPDATE' THEN
    INSERT INTO reporting_domain_event (event_time, action, domain_id, tldid, name, clid, prev_clid, reid, trstatus, prev_trstatus, crdate, rgpstatus, prev_rgpstatus, rgpres_time, prev_rgpres_time, rgpres_reason, agp_request, prev_agp_request, agp_grant, prev_agp_grant, agp_exempted, prev_agp_exempted, add_period, renew_period, renewed_date, transfer_period, trdate, exdate) VALUES ((clock_timestamp() AT TIME ZONE 'UTC'), 'update', NEW."id", NEW."tldid", NEW."name", NEW."clid", OLD."clid", NEW."reid", NEW."trstatus", OLD."trstatus", NEW."crdate", NEW."rgpstatus", OLD."rgpstatus", NEW."rgprestime", OLD."rgprestime", NEW."rgpresreason", NEW."agp_request", OLD."agp_request", NEW."agp_grant", OLD."agp_grant", NEW."agp_exempted", OLD."agp_exempted", NEW."addperiod", NEW."renewperiod", NEW."reneweddate", NEW."transferperiod", NEW."trdate", NEW."exdate");
    RETURN NEW;
  ELSE
    INSERT INTO reporting_domain_event (event_time, action, domain_id, tldid, name, clid, prev_clid, reid, trstatus, prev_trstatus, crdate, rgpstatus, prev_rgpstatus, rgpres_time, prev_rgpres_time, rgpres_reason, agp_request, prev_agp_request, agp_grant, prev_agp_grant, agp_exempted, prev_agp_exempted, add_period, renew_period, renewed_date, transfer_period, trdate, exdate) VALUES ((clock_timestamp() AT TIME ZONE 'UTC'), 'delete', OLD."id", OLD."tldid", OLD."name", OLD."clid", OLD."clid", OLD."reid", OLD."trstatus", OLD."trstatus", OLD."crdate", OLD."rgpstatus", OLD."rgpstatus", OLD."rgprestime", OLD."rgprestime", OLD."rgpresreason", OLD."agp_request", OLD."agp_request", OLD."agp_grant", OLD."agp_grant", OLD."agp_exempted", OLD."agp_exempted", OLD."addperiod", OLD."renewperiod", OLD."reneweddate", OLD."transferperiod", OLD."trdate", OLD."exdate");
    RETURN OLD;
  END IF;
END;
$$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS reporting_domain_capture_trg ON domain;
CREATE TRIGGER reporting_domain_capture_trg AFTER INSERT OR UPDATE OR DELETE ON domain
FOR EACH ROW EXECUTE FUNCTION reporting_domain_capture();


CREATE OR REPLACE FUNCTION reporting_host_capture() RETURNS trigger AS $$
BEGIN
  IF TG_OP = 'INSERT' THEN
    INSERT INTO reporting_host_event (event_time, action, host_id, name, clid) VALUES ((clock_timestamp() AT TIME ZONE 'UTC'), 'insert', NEW."id", NEW."name", NEW."clid");
    RETURN NEW;
  ELSIF TG_OP = 'UPDATE' THEN
    INSERT INTO reporting_host_event (event_time, action, host_id, name, clid) VALUES ((clock_timestamp() AT TIME ZONE 'UTC'), 'update', NEW."id", NEW."name", NEW."clid");
    RETURN NEW;
  ELSE
    INSERT INTO reporting_host_event (event_time, action, host_id, name, clid) VALUES ((clock_timestamp() AT TIME ZONE 'UTC'), 'delete', OLD."id", OLD."name", OLD."clid");
    RETURN OLD;
  END IF;
END;
$$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS reporting_host_capture_trg ON host;
CREATE TRIGGER reporting_host_capture_trg AFTER INSERT OR UPDATE OR DELETE ON host
FOR EACH ROW EXECUTE FUNCTION reporting_host_capture();


CREATE OR REPLACE FUNCTION reporting_map_capture() RETURNS trigger AS $$
BEGIN
  IF TG_OP = 'INSERT' THEN
    INSERT INTO reporting_map_event (event_time, action, domain_id, host_id) VALUES ((clock_timestamp() AT TIME ZONE 'UTC'), 'insert', NEW."domain_id", NEW."host_id");
    RETURN NEW;
  ELSE
    INSERT INTO reporting_map_event (event_time, action, domain_id, host_id) VALUES ((clock_timestamp() AT TIME ZONE 'UTC'), 'delete', OLD."domain_id", OLD."host_id");
    RETURN OLD;
  END IF;
END;
$$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS reporting_map_capture_trg ON domain_host_map;
CREATE TRIGGER reporting_map_capture_trg AFTER INSERT OR DELETE ON domain_host_map
FOR EACH ROW EXECUTE FUNCTION reporting_map_capture();


CREATE OR REPLACE FUNCTION reporting_pending_create_capture() RETURNS trigger AS $$
BEGIN
  IF TG_OP = 'INSERT' THEN
    IF NEW.status = 'pendingCreate' THEN
      INSERT INTO reporting_pending_create_event (event_time, action, domain_id)
      VALUES ((clock_timestamp() AT TIME ZONE 'UTC'), 'insert', NEW.domain_id);
    END IF;
    RETURN NEW;
  END IF;
  IF OLD.status = 'pendingCreate' THEN
    INSERT INTO reporting_pending_create_event (event_time, action, domain_id)
    VALUES ((clock_timestamp() AT TIME ZONE 'UTC'), 'delete', OLD.domain_id);
  END IF;
  RETURN OLD;
END;
$$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS reporting_pending_create_capture_trg ON domain_status;
CREATE TRIGGER reporting_pending_create_capture_trg AFTER INSERT OR DELETE ON domain_status
FOR EACH ROW EXECUTE FUNCTION reporting_pending_create_capture();


INSERT INTO reporting_domain_event (event_time, action, domain_id, tldid, name, clid, prev_clid, reid, trstatus, prev_trstatus, crdate, rgpstatus, prev_rgpstatus, rgpres_time, prev_rgpres_time, rgpres_reason, agp_request, prev_agp_request, agp_grant, prev_agp_grant, agp_exempted, prev_agp_exempted, add_period, renew_period, renewed_date, transfer_period, trdate, exdate) SELECT (clock_timestamp() AT TIME ZONE 'UTC'), 'insert', d."id", d."tldid", d."name", d."clid", NULL, d."reid", d."trstatus", NULL, d."crdate", d."rgpstatus", NULL, d."rgprestime", NULL, d."rgpresreason", d."agp_request", NULL, d."agp_grant", NULL, d."agp_exempted", NULL, d."addperiod", d."renewperiod", d."reneweddate", d."transferperiod", d."trdate", d."exdate" FROM domain d WHERE NOT EXISTS (SELECT 1 FROM reporting_baseline WHERE id = 1);

INSERT INTO reporting_host_event (event_time,action,host_id,name,clid) SELECT (clock_timestamp() AT TIME ZONE 'UTC'), 'insert', h.id, h.name, h.clid FROM host h WHERE NOT EXISTS (SELECT 1 FROM reporting_baseline WHERE id = 1);

INSERT INTO reporting_map_event (event_time,action,domain_id,host_id) SELECT (clock_timestamp() AT TIME ZONE 'UTC'), 'insert', m.domain_id, m.host_id FROM domain_host_map m WHERE NOT EXISTS (SELECT 1 FROM reporting_baseline WHERE id = 1);

INSERT INTO reporting_pending_create_event (event_time,action,domain_id) SELECT (clock_timestamp() AT TIME ZONE 'UTC'), 'insert', s.domain_id FROM domain_status s WHERE s.status = 'pendingCreate' AND NOT EXISTS (SELECT 1 FROM reporting_baseline WHERE id = 1);

INSERT INTO reporting_baseline (id, installed_at) VALUES (1, (clock_timestamp() AT TIME ZONE 'UTC')) ON CONFLICT (id) DO NOTHING;
SQL
            ;;
        *) die "Unsupported database driver: $NAMINGO_DB_DRIVER" ;;
    esac
}

upgrade_zone_writer_config() {
    if [ "${NAMINGO_DEPLOYMENT:-}" = docker ]; then
        log "Skipping host-only DNS configuration migration in Docker"
        return 0
    fi

    config_file="${NAMINGO_INSTALL_DIR:-/opt/registry}/automation/config.php"
    [ -f "$config_file" ] || die "Automation configuration not found: $config_file"

    php_bin=$(command -v "php${NAMINGO_PHP_VERSION:-8.3}" 2>/dev/null || command -v php 2>/dev/null || true)
    [ -n "$php_bin" ] || die "PHP executable not found"

    log "Adding optional per-zone DNS configuration"
    "$php_bin" /dev/stdin "$config_file" <<'PHP'
<?php
$path = $argv[1];
$config = file_get_contents($path);
if ($config === false) {
    throw new RuntimeException('Could not read automation configuration');
}
if (preg_match('/^[\t ]*[\'"]dns_zones[\'"][\t ]*=>/m', $config)) {
    echo "dns_zones already configured; leaving it unchanged\n";
    exit(0);
}

$block = <<<'CONFIG'
    // Optional per-zone overrides, keyed by lowercase zone name without a leading/trailing dot.
    // Omitted settings retain the global NS/SOA settings and existing TTL/timer defaults.
    'dns_zones' => [
        // 'example' => [
        //     'ns' => ['ns1' => 'ns1.example.net', 'ns2' => 'ns2.example.net'],
        //     'soa' => [
        //         'mname' => 'primary.example.net', // Defaults to this zone's ns1 (or first NS)
        //         'rname' => 'hostmaster.example.net', // DNS mailbox notation, not user@host
        //         'refresh' => 900,
        //         'retry' => 1800,
        //         'expire' => 3600000,
        //         'minimum' => 3600,
        //     ],
        //     'ttl' => 3600,
        // ],
    ],
CONFIG;

$updated = preg_replace_callback(
    '/^([\t ]*[\'"]dns_reload[\'"][\t ]*=>[\t ]*(?:true|false)[\t ]*,[^\r\n]*)(\r?\n)(?:[\t ]*\r?\n)*/mi',
    static fn (array $match): string => $match[1] . $match[2]
        . str_replace("\n", $match[2], $block) . $match[2] . $match[2],
    $config,
    1,
    $count
);
if ($updated === null || $count !== 1) {
    throw new RuntimeException('Could not locate dns_reload in automation configuration');
}
// Check syntax before modifying the installed configuration.
token_get_all($updated, TOKEN_PARSE);
if (file_put_contents($path, $updated, LOCK_EX) !== strlen($updated)) {
    throw new RuntimeException('Could not update automation configuration');
}
PHP
}

case "$PHASE" in
    pre)
        ;;
    post)
        upgrade_ticket_assignment
        upgrade_registrar_onboarding
        upgrade_balance_mapping
        upgrade_reporting_history
        upgrade_zone_writer_config
        ;;
    reporting)
        upgrade_reporting_history
        ;;
    *)
        echo "Usage: $0 {pre|post}" >&2
        exit 2
        ;;
esac
