CREATE TABLE launch_phases (
    "id" SERIAL PRIMARY KEY,
    "tld_id" INT CHECK ("tld_id" >= 0),
    "phase_name" VARCHAR(75) DEFAULT NULL,
    "phase_type" VARCHAR(50) NOT NULL,
    "phase_category" VARCHAR(75) NOT NULL,
    "phase_description" TEXT,
    "start_date" TIMESTAMP(3) NOT NULL,
    "end_date" TIMESTAMP(3) DEFAULT NULL,
    "lastupdate"   timestamp(3),
    UNIQUE(phase_name)
);

 CREATE OR REPLACE FUNCTION update_phases() RETURNS trigger AS '
BEGIN
    NEW.lastupdate := CURRENT_TIMESTAMP;
    RETURN NEW;
END;
' LANGUAGE 'plpgsql';

CREATE TRIGGER add_current_date_to_launch_phases BEFORE UPDATE ON launch_phases FOR EACH ROW EXECUTE PROCEDURE
update_phases();

CREATE TABLE domain_tld (
     "id" SERIAL PRIMARY KEY,
     "tld"   varchar(32) NOT NULL,
     "idn_table"   varchar(255) NOT NULL,
     "secure"   SMALLINT NOT NULL,
     "launch_phase_id" INTEGER DEFAULT NULL,
     unique ("tld") 
);

CREATE TABLE settings (
     "name" varchar(64) NOT NULL,
     "value" varchar(255) default NULL,
     PRIMARY KEY ("name")
);

CREATE TABLE domain_price (
     "id"  SERIAL PRIMARY KEY,
     "tldid" int CHECK ("tldid" >= 0) NOT NULL,
     "command" varchar CHECK ("command" IN ( 'create','renew','transfer' )) NOT NULL default 'create',
     "m0"   decimal(10,2) NOT NULL default '0.00',
     "m12"   decimal(10,2) NOT NULL default '0.00',
     "m24"   decimal(10,2) NOT NULL default '0.00',
     "m36"   decimal(10,2) NOT NULL default '0.00',
     "m48"   decimal(10,2) NOT NULL default '0.00',
     "m60"   decimal(10,2) NOT NULL default '0.00',
     "m72"   decimal(10,2) NOT NULL default '0.00',
     "m84"   decimal(10,2) NOT NULL default '0.00',
     "m96"   decimal(10,2) NOT NULL default '0.00',
     "m108"   decimal(10,2) NOT NULL default '0.00',
     "m120"   decimal(10,2) NOT NULL default '0.00',
     unique ("tldid", "command") 
);

CREATE TABLE domain_restore_price (
     "id" SERIAL PRIMARY KEY,
     "tldid" int CHECK ("tldid" >= 0) NOT NULL,
     "price"   decimal(10,2) NOT NULL default '0.00',
     unique ("tldid") 
);

CREATE TABLE allocation_tokens (
     "token" VARCHAR(255) NOT NULL,
     "domain_name" VARCHAR(255),
     "tokenStatus" VARCHAR(100),
     "tokenType" VARCHAR(100),
     "createDateTime" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
     "lastUpdate" TIMESTAMP(3),
     "registrars" JSON,
     "tlds" JSON,
     "eppActions" JSON,
     "reducePremium" BOOLEAN NOT NULL,
     "reduceYears" INT NOT NULL CHECK ("reduceYears" BETWEEN 0 AND 10),
    PRIMARY KEY (token)
);

CREATE TABLE error_log (
    "id" SERIAL PRIMARY KEY,
    "registrar_id" int CHECK ("registrar_id" >= 0) NOT NULL,
    "log" TEXT NOT NULL,
    "date" TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE reserved_domain_names (
     "id" SERIAL PRIMARY KEY,
     "name"   varchar(68) NOT NULL,
     "type" varchar CHECK ("type" IN ( 'reserved','restricted' )) NOT NULL default 'reserved',
     unique ("name") 
);

CREATE TABLE registrar (
     "id" SERIAL PRIMARY KEY,
     "name"   varchar(255) NOT NULL,
     "iana_id"   int DEFAULT NULL,
     "clid"   varchar(16) NOT NULL,
     "pw"   varchar(256) NOT NULL,
     "prefix"   char(2) NOT NULL,
     "email"   varchar(255) NOT NULL,
     "whois_server"   varchar(255) NOT NULL,
     "rdap_server"   varchar(255) NOT NULL,
     "url"   varchar(255) NOT NULL,
     "abuse_email"   varchar(255) NOT NULL,
     "abuse_phone"   varchar(255) NOT NULL,
     "accountbalance"   decimal(12,2) NOT NULL default '0.00',
     "creditlimit"   decimal(12,2) NOT NULL default '0.00',
     "creditthreshold"   decimal(12,2) NOT NULL default '0.00',
     "thresholdtype" varchar CHECK ("thresholdtype" IN ( 'fixed','percent' )) NOT NULL default 'fixed',
     "currency"   varchar(5) NOT NULL default 'USD',
     "vat_number" VARCHAR(30) DEFAULT NULL,
     "crdate"   timestamp(3) without time zone NOT NULL,
     "lastupdate"   timestamp(3),
     unique ("clid"),
     unique ("prefix"),
     unique ("email") 
);

 CREATE OR REPLACE FUNCTION update_registrar() RETURNS trigger AS '
BEGIN
    NEW.lastupdate := CURRENT_TIMESTAMP;
    RETURN NEW;
END;
' LANGUAGE 'plpgsql';

-- before INSERT is handled by 'default CURRENT_TIMESTAMP'
CREATE TRIGGER add_current_date_to_registrar BEFORE UPDATE ON registrar FOR EACH ROW EXECUTE PROCEDURE
update_registrar();

CREATE TABLE registrar_whitelist (
     "id" SERIAL PRIMARY KEY,
     "registrar_id" int CHECK ("registrar_id" >= 0) NOT NULL,
     "addr"   varchar(45) NOT NULL,
     unique ("registrar_id", "addr") 
);

CREATE TABLE registrar_contact (
     "id" SERIAL PRIMARY KEY,
     "registrar_id" int CHECK ("registrar_id" >= 0) NOT NULL,
     "type" varchar CHECK ("type" IN ( 'owner','admin','billing','tech','abuse' )) NOT NULL default 'admin',
     "title"   varchar(255) default NULL,
     "first_name"   varchar(255) NOT NULL,
     "middle_name"   varchar(255) default NULL,
     "last_name"   varchar(255) NOT NULL,
     "org"   varchar(255) default NULL,
     "street1"   varchar(255) default NULL,
     "street2"   varchar(255) default NULL,
     "street3"   varchar(255) default NULL,
     "city"   varchar(255) NOT NULL,
     "sp"   varchar(255) default NULL,
     "pc"   varchar(16) default NULL,
     "cc"   char(2) NOT NULL,
     "voice"   varchar(17) default NULL,
     "fax"   varchar(17) default NULL,
     "email"   varchar(255) NOT NULL,
     unique ("registrar_id", "type") 
);

CREATE TABLE registrar_ote (
     "registrar_id" integer NOT NULL,
     "command" varchar(75) NOT NULL,
     "result" int NOT NULL,
     CONSTRAINT test UNIQUE ("registrar_id", "command", "result")
);

CREATE TABLE poll (
     "id" SERIAL PRIMARY KEY,
     "registrar_id" int CHECK ("registrar_id" >= 0) NOT NULL,
     "qdate"   timestamp(3) without time zone NOT NULL,
     "msg"   text default NULL,
     "msg_type" varchar CHECK ("msg_type" IN ( 'lowBalance','domainTransfer','contactTransfer' )) default NULL,
     "obj_name_or_id"   varchar(68),
     "obj_trstatus" varchar CHECK ("obj_trstatus" IN ( 'clientApproved','clientCancelled','clientRejected','pending','serverApproved','serverCancelled' )) default NULL,
     "obj_reid"   varchar(255),
     "obj_redate"   timestamp(3) without time zone,
     "obj_acid"   varchar(255),
     "obj_acdate"   timestamp(3) without time zone,
     "obj_exdate"   timestamp(3) without time zone default NULL,
     "registrarname"   varchar(255),
     "creditlimit"   decimal(12,2) default '0.00',
     "creditthreshold"   decimal(12,2) default '0.00',
     "creditthresholdtype" varchar CHECK ("creditthresholdtype" IN ( 'FIXED','PERCENT' )),
     "availablecredit"   decimal(12,2) default '0.00'
);

CREATE TABLE payment_history (
     "id" SERIAL PRIMARY KEY,
     "registrar_id" int CHECK ("registrar_id" >= 0) NOT NULL,
     "date"   timestamp(3) without time zone NOT NULL,
     "description"   text NOT NULL,
     "amount"   decimal(12,2) NOT NULL
);

CREATE TABLE statement (
     "id" SERIAL PRIMARY KEY,
     "registrar_id" int CHECK ("registrar_id" >= 0) NOT NULL,
     "date"   timestamp(3) without time zone NOT NULL,
     "command" varchar CHECK ("command" IN ( 'create','renew','transfer','restore','autoRenew' )) NOT NULL default 'create',
     "domain_name" varchar(68) NOT NULL,
     "length_in_months"  smallint CHECK ("length_in_months" >= 0) NOT NULL,
     "fromS"   timestamp(3) without time zone NOT NULL,
     "toS"   timestamp(3) without time zone NOT NULL,
     "amount"   decimal(12,2) NOT NULL
);

CREATE TABLE invoices (
     "id" SERIAL PRIMARY KEY,
     "registrar_id" INT,
     "invoice_number" varchar(25) DEFAULT NULL,
     "billing_contact_id" INT,
     "issue_date" TIMESTAMP(3),
     "due_date" TIMESTAMP(3) DEFAULT NULL,
     "total_amount" NUMERIC(10,2),
     "payment_status" VARCHAR(10) DEFAULT 'unpaid' CHECK (payment_status IN ('unpaid', 'paid', 'overdue', 'cancelled')),
     "notes" TEXT DEFAULT NULL,
     "created_at" TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP,
     "updated_at" TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE contact (
     "id" SERIAL PRIMARY KEY,
     "identifier"   varchar(255) NOT NULL,
     "voice"   varchar(17) default NULL,
     "voice_x"   int default NULL,
     "fax"   varchar(17) default NULL,
     "fax_x"   int default NULL,
     "email"   varchar(255) NOT NULL,
     "nin"   varchar(255) default NULL,
     "nin_type" varchar CHECK ("nin_type" IN ( 'personal','business' )) default NULL,
     "clid" int CHECK ("clid" >= 0) NOT NULL,
     "crid" int CHECK ("crid" >= 0) NOT NULL,
     "crdate"   timestamp(3) without time zone NOT NULL,
     "upid" int CHECK ("upid" >= 0) default NULL,
     "lastupdate"   timestamp(3) without time zone default NULL,
     "trdate"   timestamp(3) without time zone default NULL,
     "trstatus" varchar CHECK ("trstatus" IN ( 'clientApproved','clientCancelled','clientRejected','pending','serverApproved','serverCancelled' )) default NULL,
     "reid" int CHECK ("reid" >= 0) default NULL,
     "redate"   timestamp(3) without time zone default NULL,
     "acid" int CHECK ("acid" >= 0) default NULL,
     "acdate"   timestamp(3) without time zone default NULL,
     "disclose_voice" varchar CHECK ("disclose_voice" IN ( '0','1' )) NOT NULL default '1',
     "disclose_fax" varchar CHECK ("disclose_fax" IN ( '0','1' )) NOT NULL default '1',
     "disclose_email" varchar CHECK ("disclose_email" IN ( '0','1' )) NOT NULL default '1',
     unique ("identifier") 
);

CREATE TABLE contact_postalinfo (
     "id" SERIAL PRIMARY KEY,
     "contact_id" int CHECK ("contact_id" >= 0) NOT NULL,
     "type" varchar CHECK ("type" IN ( 'int','loc' )) NOT NULL default 'int',
     "name"   varchar(255) NOT NULL,
     "org"   varchar(255) default NULL,
     "street1"   varchar(255) default NULL,
     "street2"   varchar(255) default NULL,
     "street3"   varchar(255) default NULL,
     "city"   varchar(255) NOT NULL,
     "sp"   varchar(255) default NULL,
     "pc"   varchar(16) default NULL,
     "cc"   char(2) NOT NULL,
     "disclose_name_int" varchar CHECK ("disclose_name_int" IN ( '0','1' )) NOT NULL default '1',
     "disclose_name_loc" varchar CHECK ("disclose_name_loc" IN ( '0','1' )) NOT NULL default '1',
     "disclose_org_int" varchar CHECK ("disclose_org_int" IN ( '0','1' )) NOT NULL default '1',
     "disclose_org_loc" varchar CHECK ("disclose_org_loc" IN ( '0','1' )) NOT NULL default '1',
     "disclose_addr_int" varchar CHECK ("disclose_addr_int" IN ( '0','1' )) NOT NULL default '1',
     "disclose_addr_loc" varchar CHECK ("disclose_addr_loc" IN ( '0','1' )) NOT NULL default '1',
     unique ("contact_id", "type") 
);

CREATE TABLE contact_authinfo (
     "id" SERIAL PRIMARY KEY,
     "contact_id" int CHECK ("contact_id" >= 0) NOT NULL,
     "authtype" varchar CHECK ("authtype" IN ( 'pw','ext' )) NOT NULL default 'pw',
     "authinfo"   varchar(64) NOT NULL,
     unique ("contact_id") 
);

CREATE TABLE contact_status (
     "id" SERIAL PRIMARY KEY,
     "contact_id" int CHECK ("contact_id" >= 0) NOT NULL,
     "status" varchar CHECK ("status" IN ( 'clientDeleteProhibited','clientTransferProhibited','clientUpdateProhibited','linked','ok','pendingCreate','pendingDelete','pendingTransfer','pendingUpdate','serverDeleteProhibited','serverTransferProhibited','serverUpdateProhibited' )) NOT NULL default 'ok',
     unique ("contact_id", "status") 
);

CREATE TABLE domain (
     "id" SERIAL PRIMARY KEY,
     "name"   varchar(68) NOT NULL,
     "tldid" int CHECK ("tldid" >= 0) NOT NULL,
     "registrant" int CHECK ("registrant" >= 0) default NULL,
     "crdate"   timestamp(3) without time zone NOT NULL,
     "exdate"   timestamp(3) without time zone NOT NULL,
     "lastupdate"   timestamp(3) without time zone default NULL,
     "clid" int CHECK ("clid" >= 0) NOT NULL,
     "crid" int CHECK ("crid" >= 0) NOT NULL,
     "upid" int CHECK ("upid" >= 0) default NULL,
     "trdate"   timestamp(3) without time zone default NULL,
     "trstatus" varchar CHECK ("trstatus" IN ( 'clientApproved','clientCancelled','clientRejected','pending','serverApproved','serverCancelled' )) default NULL,
     "reid" int CHECK ("reid" >= 0) default NULL,
     "redate"   timestamp(3) without time zone default NULL,
     "acid" int CHECK ("acid" >= 0) default NULL,
     "acdate"   timestamp(3) without time zone default NULL,
     "transfer_exdate"   timestamp(3) without time zone default NULL,
     "idnlang"   varchar(16) default NULL,
     "deltime"   timestamp(3) without time zone default NULL,
     "restime"   timestamp(3) without time zone default NULL,
     "rgpstatus" varchar CHECK ("rgpstatus" IN ( 'addPeriod','autoRenewPeriod','renewPeriod','transferPeriod','pendingDelete','pendingRestore','redemptionPeriod' )) default NULL,
     "rgppostdata"   text default NULL,
     "rgpdeltime"   timestamp(3) without time zone default NULL,
     "rgprestime"   timestamp(3) without time zone default NULL,
     "rgpresreason"   text default NULL,
     "rgpstatement1"   text default NULL,
     "rgpstatement2"   text default NULL,
     "rgpother"   text default NULL,
     "addperiod"  smallint CHECK ("addperiod" >= 0) default NULL,
     "autorenewperiod"  smallint CHECK ("autorenewperiod" >= 0) default NULL,
     "renewperiod"  smallint CHECK ("renewperiod" >= 0) default NULL,
     "transferperiod"  smallint CHECK ("transferperiod" >= 0) default NULL,
     "reneweddate"   timestamp(3) without time zone default NULL,
     "agp_exempted" BOOLEAN DEFAULT FALSE,
     "agp_request" TIMESTAMP(3) DEFAULT NULL,
     "agp_grant" TIMESTAMP(3) DEFAULT NULL,
     "agp_reason" TEXT DEFAULT NULL,
     "agp_status" VARCHAR(30) DEFAULT NULL,
     "tm_notice_accepted" TIMESTAMP(3) DEFAULT NULL,
     "tm_notice_expires" TIMESTAMP(3) DEFAULT NULL,
     "tm_notice_id" VARCHAR(150) DEFAULT NULL,
     "tm_notice_validator" VARCHAR(30) DEFAULT NULL,
     "tm_smd_id" TEXT DEFAULT NULL,
     "tm_phase" text DEFAULT 'NONE'::text NOT NULL,
     unique ("name") 
);

CREATE TABLE application (
     "id" SERIAL PRIMARY KEY,
     "name"   varchar(68) NOT NULL,
     "tldid" int CHECK ("tldid" >= 0) NOT NULL,
     "registrant" int CHECK ("registrant" >= 0) default NULL,
     "crdate"   timestamp(3) without time zone NOT NULL,
     "exdate"   timestamp(3) without time zone default NULL,
     "lastupdate"   timestamp(3) without time zone default NULL,
     "clid" int CHECK ("clid" >= 0) NOT NULL,
     "crid" int CHECK ("crid" >= 0) NOT NULL,
     "upid" int CHECK ("upid" >= 0) default NULL,
     "trdate"   timestamp(3) without time zone default NULL,
     "trstatus" varchar CHECK ("trstatus" IN ( 'clientApproved','clientCancelled','clientRejected','pending','serverApproved','serverCancelled' )) default NULL,
     "reid" int CHECK ("reid" >= 0) default NULL,
     "redate"   timestamp(3) without time zone default NULL,
     "acid" int CHECK ("acid" >= 0) default NULL,
     "acdate"   timestamp(3) without time zone default NULL,
     "transfer_exdate"   timestamp(3) without time zone default NULL,
     "idnlang"   varchar(16) default NULL,
     "deltime"   timestamp(3) without time zone default NULL,
     "application_id"   varchar(36) default NULL,
     "authtype" varchar CHECK ("authtype" IN ( 'pw','ext' )) NOT NULL default 'pw',
     "authinfo" varchar(64) NOT NULL,
     "phase_name" VARCHAR(75) DEFAULT NULL,
     "phase_type" VARCHAR(50) NOT NULL,
     "smd" TEXT DEFAULT NULL,
     "tm_notice_accepted" TIMESTAMP(3) DEFAULT NULL,
     "tm_notice_expires" TIMESTAMP(3) DEFAULT NULL,
     "tm_notice_id" VARCHAR(150) DEFAULT NULL,
     "tm_notice_validator" VARCHAR(30) DEFAULT NULL,
     "tm_smd_id" TEXT DEFAULT NULL,
     "tm_phase" text DEFAULT 'NONE'::text NOT NULL
);

CREATE TABLE domain_contact_map (
     "id" SERIAL PRIMARY KEY,
     "domain_id" int CHECK ("domain_id" >= 0) NOT NULL,
     "contact_id" int CHECK ("contact_id" >= 0) NOT NULL,
     "type" varchar CHECK ("type" IN ( 'admin','billing','tech' )) NOT NULL default 'admin',
     unique ("domain_id", "contact_id", "type") 
);

CREATE TABLE application_contact_map (
     "id" SERIAL PRIMARY KEY,
     "domain_id" int CHECK ("domain_id" >= 0) NOT NULL,
     "contact_id" int CHECK ("contact_id" >= 0) NOT NULL,
     "type" varchar CHECK ("type" IN ( 'admin','billing','tech' )) NOT NULL default 'admin',
     unique ("domain_id", "contact_id", "type") 
);

CREATE TABLE domain_authinfo (
     "id" SERIAL PRIMARY KEY,
     "domain_id" int CHECK ("domain_id" >= 0) NOT NULL,
     "authtype" varchar CHECK ("authtype" IN ( 'pw','ext' )) NOT NULL default 'pw',
     "authinfo"   varchar(64) NOT NULL,
     unique ("domain_id") 
);

CREATE TABLE domain_status (
     "id" SERIAL PRIMARY KEY,
     "domain_id" int CHECK ("domain_id" >= 0) NOT NULL,
     "status" varchar CHECK ("status" IN ( 'clientDeleteProhibited','clientHold','clientRenewProhibited','clientTransferProhibited','clientUpdateProhibited','inactive','ok','pendingCreate','pendingDelete','pendingRenew','pendingTransfer','pendingUpdate','serverDeleteProhibited','serverHold','serverRenewProhibited','serverTransferProhibited','serverUpdateProhibited' )) NOT NULL default 'ok',
     unique ("domain_id", "status") 
);

CREATE TABLE application_status (
     "id" SERIAL PRIMARY KEY,
     "domain_id" int CHECK ("domain_id" >= 0) NOT NULL,
     "status" varchar CHECK ("status" IN ( 'pendingValidation','validated','invalid','pendingAllocation','allocated','rejected','custom' )) NOT NULL default 'pendingValidation',
     unique ("domain_id", "status") 
);

CREATE TABLE secdns (
     "id" SERIAL PRIMARY KEY,
     "domain_id" int CHECK ("domain_id" >= 0) NOT NULL,
     "maxsiglife" int CHECK ("maxsiglife" >= 0) default '604800',
     "interface" varchar CHECK ("interface" IN ( 'dsData','keyData' )) NOT NULL default 'dsData',
     "keytag" smallint CHECK ("keytag" >= 0) NOT NULL,
     "alg"  smallint CHECK ("alg" >= 0) NOT NULL default '5',
     "digesttype"  smallint CHECK ("digesttype" >= 0) NOT NULL default '1',
     "digest"   varchar(64) NOT NULL,
     "flags" smallint CHECK ("flags" >= 0) default NULL,
     "protocol" smallint CHECK ("protocol" >= 0) default NULL,
     "keydata_alg"  smallint CHECK ("keydata_alg" >= 0) default NULL,
     "pubkey"   varchar(255) default NULL,
     unique ("domain_id", "digest") 
);

CREATE TABLE host (
     "id" SERIAL PRIMARY KEY,
     "name"   varchar(255) NOT NULL,
     "domain_id" int CHECK ("domain_id" >= 0) default NULL,
     "clid" int CHECK ("clid" >= 0) NOT NULL,
     "crid" int CHECK ("crid" >= 0) NOT NULL,
     "crdate"   timestamp(3) without time zone NOT NULL,
     "upid" int CHECK ("upid" >= 0) default NULL,
     "lastupdate"   timestamp(3) without time zone default NULL,
     "trdate"   timestamp(3) without time zone default NULL,
     unique ("name") 
);

CREATE TABLE domain_host_map (
     "id" SERIAL PRIMARY KEY,
     "domain_id" int CHECK ("domain_id" >= 0) NOT NULL,
     "host_id" int CHECK ("host_id" >= 0) NOT NULL,
     unique ("domain_id", "host_id") 
);

CREATE TABLE application_host_map (
     "id" SERIAL PRIMARY KEY,
     "domain_id" int CHECK ("domain_id" >= 0) NOT NULL,
     "host_id" int CHECK ("host_id" >= 0) NOT NULL,
     unique ("domain_id", "host_id") 
);

CREATE TABLE host_addr (
     "id" SERIAL PRIMARY KEY,
     "host_id" int CHECK ("host_id" >= 0) NOT NULL,
     "addr"   varchar(45) NOT NULL,
     "ip" varchar CHECK ("ip" IN ( 'v4','v6' )) NOT NULL default 'v4',
     unique ("host_id", "addr", "ip") 
);

CREATE TABLE host_status (
     "id" SERIAL PRIMARY KEY,
     "host_id" int CHECK ("host_id" >= 0) NOT NULL,
     "status" varchar CHECK ("status" IN ( 'clientDeleteProhibited','clientUpdateProhibited','linked','ok','pendingCreate','pendingDelete','pendingTransfer','pendingUpdate','serverDeleteProhibited','serverUpdateProhibited' )) NOT NULL default 'ok',
     unique ("host_id", "status") 
);

CREATE TABLE domain_auto_approve_transfer (
     "id" SERIAL PRIMARY KEY,
     "name"   varchar(68) NOT NULL,
     "registrant" int CHECK ("registrant" >= 0) default NULL,
     "crdate"   timestamp(3) without time zone NOT NULL,
     "exdate"   timestamp(3) without time zone NOT NULL,
     "lastupdate"   timestamp(3) without time zone default NULL,
     "clid" int CHECK ("clid" >= 0) NOT NULL,
     "crid" int CHECK ("crid" >= 0) NOT NULL,
     "upid" int CHECK ("upid" >= 0) default NULL,
     "trdate"   timestamp(3) without time zone default NULL,
     "trstatus" varchar CHECK ("trstatus" IN ( 'clientApproved','clientCancelled','clientRejected','pending','serverApproved','serverCancelled' )) default NULL,
     "reid" int CHECK ("reid" >= 0) default NULL,
     "redate"   timestamp(3) without time zone default NULL,
     "acid" int CHECK ("acid" >= 0) default NULL,
     "acdate"   timestamp(3) without time zone default NULL,
     "transfer_exdate"   timestamp(3) without time zone default NULL
);

CREATE TABLE contact_auto_approve_transfer (
     "id" SERIAL PRIMARY KEY,
     "identifier"   varchar(255) NOT NULL,
     "voice"   varchar(17) default NULL,
     "voice_x"   int default NULL,
     "fax"   varchar(17) default NULL,
     "fax_x"   int default NULL,
     "email"   varchar(255) NOT NULL,
     "nin"   varchar(255) default NULL,
     "nin_type" varchar CHECK ("nin_type" IN ( 'personal','business' )) default NULL,
     "clid" int CHECK ("clid" >= 0) NOT NULL,
     "crid" int CHECK ("crid" >= 0) NOT NULL,
     "crdate"   timestamp(3) without time zone NOT NULL,
     "upid" int CHECK ("upid" >= 0) default NULL,
     "lastupdate"   timestamp(3) without time zone default NULL,
     "trdate"   timestamp(3) without time zone default NULL,
     "trstatus" varchar CHECK ("trstatus" IN ( 'clientApproved','clientCancelled','clientRejected','pending','serverApproved','serverCancelled' )) default NULL,
     "reid" int CHECK ("reid" >= 0) default NULL,
     "redate"   timestamp(3) without time zone default NULL,
     "acid" int CHECK ("acid" >= 0) default NULL,
     "acdate"   timestamp(3) without time zone default NULL,
     "disclose_voice" varchar CHECK ("disclose_voice" IN ( '0','1' )) NOT NULL default '1',
     "disclose_fax" varchar CHECK ("disclose_fax" IN ( '0','1' )) NOT NULL default '1',
     "disclose_email" varchar CHECK ("disclose_email" IN ( '0','1' )) NOT NULL default '1'
);

CREATE TABLE statistics (
     "id" SERIAL PRIMARY KEY,
     "date"   date NOT NULL,
     "total_domains" int CHECK ("total_domains" >= 0) NOT NULL DEFAULT '0',
     "created_domains" int CHECK ("created_domains" >= 0) NOT NULL DEFAULT '0',
     "renewed_domains" int CHECK ("renewed_domains" >= 0) NOT NULL DEFAULT '0',
     "transfered_domains" int CHECK ("transfered_domains" >= 0) NOT NULL DEFAULT '0',
     "deleted_domains" int CHECK ("deleted_domains" >= 0) NOT NULL DEFAULT '0',
     "restored_domains" int CHECK ("restored_domains" >= 0) NOT NULL DEFAULT '0',
     unique ("date") 
);

CREATE TABLE IF NOT EXISTS users (
    "id" SERIAL PRIMARY KEY CHECK ("id" >= 0),
    "email" VARCHAR(249) UNIQUE NOT NULL,
    "password" VARCHAR(255) NOT NULL,
    "username" VARCHAR(100) DEFAULT NULL,
    "status" SMALLINT NOT NULL DEFAULT '0' CHECK ("status" >= 0),
    "verified" SMALLINT NOT NULL DEFAULT '0' CHECK ("verified" >= 0),
    "resettable" SMALLINT NOT NULL DEFAULT '1' CHECK ("resettable" >= 0),
    "roles_mask" INTEGER NOT NULL DEFAULT '0' CHECK ("roles_mask" >= 0),
    "registered" INTEGER NOT NULL CHECK ("registered" >= 0),
    "last_login" INTEGER DEFAULT NULL CHECK ("last_login" >= 0),
    "force_logout" INTEGER NOT NULL DEFAULT '0' CHECK ("force_logout" >= 0),
    "tfa_secret" VARCHAR(32),
    "tfa_enabled" BOOLEAN DEFAULT false,
    "auth_method" VARCHAR(255) DEFAULT 'password',
    "backup_codes" TEXT
);

CREATE TABLE IF NOT EXISTS users_audit (
    "user_id" INT NOT NULL,
    "user_event" VARCHAR(255) NOT NULL,
    "user_resource" VARCHAR(255) DEFAULT NULL,
    "user_agent" VARCHAR(255) NOT NULL,
    "user_ip" VARCHAR(45) NOT NULL,
    "user_location" VARCHAR(45) DEFAULT NULL,
    "event_time" TIMESTAMP(3) NOT NULL,
    "user_data" JSONB DEFAULT NULL
);
CREATE INDEX idx_user_event ON users_audit (user_event);
CREATE INDEX idx_user_ip ON users_audit (user_ip);

CREATE TABLE IF NOT EXISTS users_confirmations (
    "id" SERIAL PRIMARY KEY CHECK ("id" >= 0),
    "user_id" INTEGER NOT NULL CHECK ("user_id" >= 0),
    "email" VARCHAR(249) NOT NULL,
    "selector" VARCHAR(16) UNIQUE NOT NULL,
    "token" VARCHAR(255) NOT NULL,
    "expires" INTEGER NOT NULL CHECK ("expires" >= 0)
);
CREATE INDEX IF NOT EXISTS "email_expires" ON users_confirmations ("email", "expires");
CREATE INDEX IF NOT EXISTS "user_id" ON users_confirmations ("user_id");

CREATE TABLE IF NOT EXISTS users_remembered (
    "id" BIGSERIAL PRIMARY KEY CHECK ("id" >= 0),
    "user_id" INTEGER NOT NULL CHECK ("user_id" >= 0),
    "selector" VARCHAR(24) UNIQUE NOT NULL,
    "token" VARCHAR(255) NOT NULL,
    "expires" INTEGER NOT NULL CHECK ("expires" >= 0)
);
CREATE INDEX IF NOT EXISTS "re_user_id" ON users_remembered ("user_id");

CREATE TABLE IF NOT EXISTS users_resets (
    "id" BIGSERIAL PRIMARY KEY CHECK ("id" >= 0),
    "user_id" INTEGER NOT NULL CHECK ("user_id" >= 0),
    "selector" VARCHAR(20) UNIQUE NOT NULL,
    "token" VARCHAR(255) NOT NULL,
    "expires" INTEGER NOT NULL CHECK ("expires" >= 0)
);
CREATE INDEX IF NOT EXISTS "user_expires" ON users_resets ("user_id", "expires");

CREATE TABLE IF NOT EXISTS users_throttling (
    "bucket" VARCHAR(44) PRIMARY KEY,
    "tokens" REAL NOT NULL CHECK ("tokens" >= 0),
    "replenished_at" INTEGER NOT NULL CHECK ("replenished_at" >= 0),
    "expires_at" INTEGER NOT NULL CHECK ("expires_at" >= 0)
);
CREATE INDEX IF NOT EXISTS "expires_at" ON users_throttling ("expires_at");

CREATE TABLE IF NOT EXISTS users_webauthn (
    "id" SERIAL PRIMARY KEY,
    "user_id" INTEGER NOT NULL,
    "credential_id" BYTEA NOT NULL,
    "public_key" TEXT NOT NULL,
    "attestation_object" BYTEA,
    "sign_count" BIGINT NOT NULL,
    "user_agent" TEXT,
    "created_at" TIMESTAMP(3) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    "last_used_at" TIMESTAMP(3) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS registrar_users (
     "registrar_id" int NOT NULL PRIMARY KEY,
     "user_id" int NOT NULL
);

CREATE TABLE urs_actions (
     "id" SERIAL PRIMARY KEY,
     "domain_name"   VARCHAR(255) NOT NULL,
     "urs_provider"   VARCHAR(255) NOT NULL,
     "action_date"   DATE NOT NULL,
     "status"   VARCHAR(255) NOT NULL
);

CREATE TYPE file_format_enum AS ENUM ('XML', 'CSV');
CREATE TYPE deposit_type_enum AS ENUM ('Full', 'Incremental', 'Differential');
CREATE TYPE status_enum AS ENUM ('Deposited', 'Retrieved', 'Failed');
CREATE TYPE verification_status_enum AS ENUM ('Verified', 'Failed', 'Pending');

CREATE TABLE rde_escrow_deposits (
    "id" SERIAL PRIMARY KEY,
    "deposit_id" VARCHAR(255) UNIQUE,  -- Unique deposit identifier
    "deposit_date" DATE NOT NULL,
    "revision" INTEGER NOT NULL DEFAULT 1,
    "file_name" VARCHAR(255) NOT NULL,
    "file_format" file_format_enum NOT NULL,  -- Format of the data file
    "file_size" BIGINT CHECK ("file_size" >= 0),
    "checksum" VARCHAR(64),
    "encryption_method" VARCHAR(255),  -- Details about how the file is encrypted
    "deposit_type" deposit_type_enum NOT NULL,
    "status" status_enum NOT NULL DEFAULT 'Deposited',
    "receiver" VARCHAR(255),  -- Escrow agent or receiver of the deposit
    "notes" TEXT,
    "verification_status" verification_status_enum DEFAULT 'Pending',
    "verification_notes" TEXT  -- Notes or remarks from the verification process
);

CREATE TYPE report_status_enum AS ENUM ('Pending', 'Submitted', 'Accepted', 'Rejected');

CREATE TABLE icann_reports (
    "id" serial8 PRIMARY KEY,
    "report_date" DATE NOT NULL,
    "type" VARCHAR(255) NOT NULL,
    "file_name" VARCHAR(255),
    "submitted_date" DATE,
    "status" report_status_enum NOT NULL DEFAULT 'Pending',
    "notes" TEXT
);

CREATE TABLE promotion_pricing (
    "id" SERIAL PRIMARY KEY,
    "tld_id" INT CHECK ("tld_id" >= 0),
    "promo_name" varchar(255) NOT NULL,
    "start_date" timestamp(3) NOT NULL,
    "end_date" timestamp(3) NOT NULL,
    "discount_percentage" numeric(5,2),
    "discount_amount" numeric(10,2),
    "description" text,
    "conditions" text,
    "promo_type" VARCHAR CHECK (promo_type IN ('full', 'registration', 'renewal', 'transfer')) NOT NULL,
    "years_of_promotion" integer,
    "max_count" integer,
    "registrar_ids" jsonb,
    "status" VARCHAR CHECK (status IN ('active', 'expired', 'upcoming')) NOT NULL,
    "minimum_purchase" numeric(10,2),
    "target_segment" varchar(255),
    "region_specific" varchar(255),
    "created_by" varchar(255),
    "created_at" timestamp(3) without time zone,
    "updated_by" varchar(255),
    "updated_at" timestamp(3) without time zone
);

CREATE INDEX idx_promotion_pricing_tld_id ON promotion_pricing (tld_id);

CREATE TABLE premium_domain_categories (
    "category_id" serial8 PRIMARY KEY,
    "category_name" VARCHAR(255) NOT NULL,
    "category_price" NUMERIC(10, 2) NOT NULL,
    UNIQUE (category_name)
);

CREATE TABLE premium_domain_pricing (
    "id" serial8 PRIMARY KEY,
    "domain_name" VARCHAR(255) NOT NULL,
    "tld_id" INT CHECK ("tld_id" >= 0) NOT NULL,
    "category_id" INT
);

-- Create custom types for status and priority
CREATE TYPE ticket_status AS ENUM ('Open', 'In Progress', 'Resolved', 'Closed');
CREATE TYPE ticket_priority AS ENUM ('Low', 'Medium', 'High', 'Critical');

CREATE TABLE ticket_categories (
    "id" SERIAL PRIMARY KEY,
    "name" VARCHAR(255) NOT NULL,
    "description" TEXT
);

CREATE TABLE support_tickets (
    "id" SERIAL PRIMARY KEY,
    "user_id" INTEGER NOT NULL, 
    "category_id" INTEGER NOT NULL,
    "subject" VARCHAR(255) NOT NULL,
    "message" TEXT NOT NULL,
    "status" ticket_status DEFAULT 'Open',
    "priority" ticket_priority DEFAULT 'Medium',
    "reported_domain" VARCHAR(255) DEFAULT NULL,
    "nature_of_abuse" TEXT DEFAULT NULL,
    "evidence" TEXT DEFAULT NULL,
    "relevant_urls" TEXT DEFAULT NULL,
    "date_of_incident" DATE DEFAULT NULL,
    "date_created" TIMESTAMP(3) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    "last_updated" TIMESTAMP(3) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE ticket_responses (
    "id" SERIAL PRIMARY KEY,
    "ticket_id" INTEGER NOT NULL,
    "responder_id" INTEGER NOT NULL,
    "response" TEXT NOT NULL,
    "date_created" TIMESTAMP(3) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE tmch_claims (
    "id" SERIAL PRIMARY KEY,
    "domain_label" VARCHAR(100) NOT NULL,
    "claim_key" VARCHAR(200) NOT NULL,
    "insert_time" TIMESTAMP(3) NOT NULL,
    CONSTRAINT tmch_claims_unique UNIQUE (claim_key, domain_label)
);

CREATE TABLE tmch_revocation (
    "id" SERIAL PRIMARY KEY,
    "smd_id" VARCHAR(100) NOT NULL,
    "revocation_time" TIMESTAMP(3) NOT NULL,
    CONSTRAINT tmch_revocation_unique UNIQUE (smd_id)
);

CREATE TABLE tmch_crl (
    "id" SERIAL PRIMARY KEY,
    "content" TEXT NOT NULL,
    "url" VARCHAR(255) NOT NULL,
    "update_timestamp" TIMESTAMP(3) NOT NULL
);

INSERT INTO domain_tld VALUES('1','.TEST','/^(?!-)(?!.*--)[A-Z0-9-]{1,63}(?<!-)(\.(?!-)(?!.*--)[A-Z0-9-]{1,63}(?<!-))*$/i','0',NULL);
INSERT INTO domain_tld VALUES('2','.COM.TEST','/^(?!-)(?!.*--)[A-Z0-9-]{1,63}(?<!-)(\.(?!-)(?!.*--)[A-Z0-9-]{1,63}(?<!-))*$/i','0',NULL);

INSERT INTO domain_price VALUES (E'1',E'1',E'create',E'0.00',E'5.00',E'10.00',E'15.00',E'20.00',E'25.00',E'30.00',E'35.00',E'40.00',E'45.00',E'50.00');
INSERT INTO domain_price VALUES (E'2',E'1',E'renew',E'0.00',E'5.00',E'10.00',E'15.00',E'20.00',E'25.00',E'30.00',E'35.00',E'40.00',E'45.00',E'50.00');
INSERT INTO domain_price VALUES (E'3',E'1',E'transfer',E'0.00',E'5.00',E'10.00',E'15.00',E'20.00',E'25.00',E'30.00',E'35.00',E'40.00',E'45.00',E'50.00');
INSERT INTO domain_price VALUES (E'4',E'2',E'create',E'0.00',E'5.00',E'10.00',E'15.00',E'20.00',E'25.00',E'30.00',E'35.00',E'40.00',E'45.00',E'50.00');
INSERT INTO domain_price VALUES (E'5',E'2',E'renew',E'0.00',E'5.00',E'10.00',E'15.00',E'20.00',E'25.00',E'30.00',E'35.00',E'40.00',E'45.00',E'50.00');
INSERT INTO domain_price VALUES (E'6',E'2',E'transfer',E'0.00',E'5.00',E'10.00',E'15.00',E'20.00',E'25.00',E'30.00',E'35.00',E'40.00',E'45.00',E'50.00');

INSERT INTO domain_restore_price VALUES (E'1',E'1',E'50.00');
INSERT INTO domain_restore_price VALUES (E'2',E'2',E'50.00');

INSERT INTO registrar ("name", "clid", "pw", "prefix", "email", "whois_server", "rdap_server", "url", "abuse_email", "abuse_phone", "accountbalance", "creditlimit", "creditthreshold", "thresholdtype", "crdate", "lastupdate") VALUES (E'LeoNet LLC',E'leonet',E'$argon2id$v=19$m=131072,t=6,p=4$M0ViOHhzTWFtQW5YSGZ2MA$g2pKb+PEYtfs4QwLmf2iUtPM4+7evuqYQFp6yqGZmQg',E'LN',E'info@leonet.test',E'whois.leonet.test',E'rdap.leonet.test',E'https://www.leonet.test',E'abuse@leonet.test',E'+380.325050',E'100000.00',E'100000.00',E'500.00',E'fixed',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP);
INSERT INTO registrar ("name", "clid", "pw", "prefix", "email", "whois_server", "rdap_server", "url", "abuse_email", "abuse_phone", "accountbalance", "creditlimit", "creditthreshold", "thresholdtype", "crdate", "lastupdate") VALUES (E'Nord Registrar AB',E'nordregistrar',E'$argon2id$v=19$m=131072,t=6,p=4$MU9Eei5UMjA0M2cxYjd3bg$2yBHTWVVY4xQlMGhnhol9MRbVyVQg8qkcZ6cpdeID1U',E'NR',E'info@nordregistrar.test',E'whois.nordregistrar.test',E'rdap.nordregistrar.test',E'https://www.nordregistrar.test',E'abuse@nordregistrar.test',E'+46.80203',E'100000.00',E'100000.00',E'500.00',E'fixed',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP);

INSERT INTO registrar_whitelist ("registrar_id", "addr") VALUES
('1',    '1.2.3.4');
INSERT INTO registrar_whitelist ("registrar_id", "addr") VALUES
('2',    '5.6.7.8');

INSERT INTO registrar_contact (id, registrar_id, type, title, first_name, middle_name, last_name, org, street1, street2, street3, city, sp, pc, cc, voice, fax, email) VALUES
('1',    '1',    'owner',    NULL,    'Test',    NULL,    'Name',    '',    '',    NULL,    NULL,    'Lviv',    '',    '',    'ua',    '',    NULL,    'test@namingo.org'),
('2',    '1',    'billing',    NULL,    'Test',    NULL,    'Name',    '',    '',    NULL,    NULL,    'Lviv',    '',    '',    'ua',    '',    NULL,    'test@namingo.org'),
('3',    '1',    'abuse',    NULL,    'Test',    NULL,    'Name',    '',    '',    NULL,    NULL,    'Lviv',    '',    '',    'ua',    '',    NULL,    'test@namingo.org'),
('4',    '2',    'owner',    NULL,    'Test',    NULL,    'Name',    '',    '',    NULL,    NULL,    'Lviv',    '',    '',    'ua',    '',    NULL,    'test@namingo.org'),
('5',    '2',    'billing',    NULL,    'Test',    NULL,    'Name',    '',    '',    NULL,    NULL,    'Lviv',    '',    '',    'ua',    '',    NULL,    'test@namingo.org'),
('6',    '2',    'abuse',    NULL,    'Test',    NULL,    'Name',    '',    '',    NULL,    NULL,    'Lviv',    '',    '',    'ua',    '',    NULL,    'test@namingo.org');

INSERT INTO ticket_categories (name, description) VALUES 
('Domain Transfer', 'Issues related to domain transfers between registrars'),
('Registration Errors', 'Errors or issues encountered during domain registration'),
('Billing & Payments', 'Questions or issues related to invoicing, payments, or account balances'),
('Technical Support', 'Technical problems or platform-related inquiries'),
('WHOIS Updates', 'Issues related to updating or querying WHOIS data'),
('Policy Violations', 'Reports of domains violating policies or terms of service'),
('EPP Command Errors', 'Issues related to EPP command failures or errors'),
('Abuse Notifications', 'Reports of domain abusive practices as per ICANN guidelines'),
('General Inquiry', 'General questions or feedback about services, platform or any non-specific topic'),
('Registrar Application', 'Queries or issues related to new registrar applications or onboarding'),
('RDAP Updates', 'Issues or queries related to the Registration Data Access Protocol (RDAP) updates'),
('URS Cases', 'Reports of URS cases');

INSERT INTO settings (name, value) VALUES
('dns-tcp-queries-received', '0'),
('dns-tcp-queries-responded', '0'),
('dns-udp-queries-received', '0'),
('dns-udp-queries-responded', '0'),
('searchable-whois-queries', '0'),
('web-whois-queries', '0'),
('whois-43-queries', '0'),
('company_name', 'Example Registry LLC'),
('address', '123 Example Street, Example City'),
('address2', '48000'),
('cc', 'Ukraine'),
('vat_number', '0'),
('phone', '+123456789'),
('handle', 'RXX'),
('email', 'contact@example.com'),
('launch_phases', 'on'),
('whois_server', 'whois.example.com'),
('rdap_server', 'https://rdap.example.com'),
('currency', 'USD');
 
ALTER TABLE domain_tld ADD FOREIGN KEY (launch_phase_id) REFERENCES launch_phases(id);
ALTER TABLE launch_phases ADD FOREIGN KEY (tld_id) REFERENCES domain_tld(id);
ALTER TABLE error_log ADD FOREIGN KEY (registrar_id) REFERENCES registrar(id);
ALTER TABLE invoices ADD FOREIGN KEY (registrar_id) REFERENCES registrar(id);
ALTER TABLE invoices ADD FOREIGN KEY (billing_contact_id) REFERENCES registrar_contact(id);
ALTER TABLE users_webauthn ADD FOREIGN KEY (user_id) REFERENCES users(id);
ALTER TABLE domain_price ADD FOREIGN KEY ("tldid") REFERENCES domain_tld ("id");
ALTER TABLE domain_restore_price ADD FOREIGN KEY ("tldid") REFERENCES domain_tld ("id");
ALTER TABLE registrar_whitelist ADD FOREIGN KEY ("registrar_id") REFERENCES registrar ("id");
ALTER TABLE registrar_users ADD FOREIGN KEY (registrar_id) REFERENCES registrar(id) ON DELETE CASCADE;
ALTER TABLE registrar_users ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
ALTER TABLE registrar_contact ADD FOREIGN KEY ("registrar_id") REFERENCES registrar ("id");
ALTER TABLE poll ADD FOREIGN KEY ("registrar_id") REFERENCES registrar ("id");
ALTER TABLE payment_history ADD FOREIGN KEY ("registrar_id") REFERENCES registrar ("id");
ALTER TABLE statement ADD FOREIGN KEY ("registrar_id") REFERENCES registrar ("id");
ALTER TABLE contact ADD FOREIGN KEY ("clid") REFERENCES registrar ("id");
ALTER TABLE contact ADD FOREIGN KEY ("crid") REFERENCES registrar ("id");
ALTER TABLE contact ADD FOREIGN KEY ("upid") REFERENCES registrar ("id");
ALTER TABLE contact_postalinfo ADD FOREIGN KEY ("contact_id") REFERENCES contact ("id");
ALTER TABLE contact_authinfo ADD FOREIGN KEY ("contact_id") REFERENCES contact ("id");
ALTER TABLE contact_status ADD FOREIGN KEY ("contact_id") REFERENCES contact ("id");
ALTER TABLE domain ADD FOREIGN KEY ("clid") REFERENCES registrar ("id");
ALTER TABLE domain ADD FOREIGN KEY ("crid") REFERENCES registrar ("id");
ALTER TABLE domain ADD FOREIGN KEY ("upid") REFERENCES registrar ("id");
ALTER TABLE domain ADD FOREIGN KEY ("registrant") REFERENCES contact ("id");
ALTER TABLE domain ADD FOREIGN KEY ("reid") REFERENCES registrar ("id");
ALTER TABLE domain ADD FOREIGN KEY ("acid") REFERENCES registrar ("id");
ALTER TABLE domain ADD FOREIGN KEY ("tldid") REFERENCES domain_tld ("id");
ALTER TABLE domain_contact_map ADD FOREIGN KEY ("domain_id") REFERENCES domain ("id");
ALTER TABLE domain_contact_map ADD FOREIGN KEY ("contact_id") REFERENCES contact ("id");
ALTER TABLE application_contact_map ADD FOREIGN KEY ("domain_id") REFERENCES application ("id");
ALTER TABLE application_contact_map ADD FOREIGN KEY ("contact_id") REFERENCES contact ("id");
ALTER TABLE domain_authinfo ADD FOREIGN KEY ("domain_id") REFERENCES domain ("id");
ALTER TABLE domain_status ADD FOREIGN KEY ("domain_id") REFERENCES domain ("id");
ALTER TABLE application_status ADD FOREIGN KEY ("domain_id") REFERENCES application ("id");
ALTER TABLE secdns ADD FOREIGN KEY ("domain_id") REFERENCES domain ("id");
ALTER TABLE host ADD FOREIGN KEY ("clid") REFERENCES registrar ("id");
ALTER TABLE host ADD FOREIGN KEY ("crid") REFERENCES registrar ("id");
ALTER TABLE host ADD FOREIGN KEY ("upid") REFERENCES registrar ("id");
ALTER TABLE host ADD FOREIGN KEY ("domain_id") REFERENCES domain ("id");
ALTER TABLE domain_host_map ADD FOREIGN KEY ("domain_id") REFERENCES domain ("id");
ALTER TABLE domain_host_map ADD FOREIGN KEY ("host_id") REFERENCES host ("id");
ALTER TABLE application_host_map ADD FOREIGN KEY ("domain_id") REFERENCES application ("id");
ALTER TABLE application_host_map ADD FOREIGN KEY ("host_id") REFERENCES host ("id");
ALTER TABLE host_addr ADD FOREIGN KEY ("host_id") REFERENCES host ("id");
ALTER TABLE host_status ADD FOREIGN KEY ("host_id") REFERENCES host ("id");
ALTER TABLE promotion_pricing ADD FOREIGN KEY ("tld_id") REFERENCES domain_tld("id");    
ALTER TABLE premium_domain_pricing ADD FOREIGN KEY ("tld_id") REFERENCES domain_tld("id");
ALTER TABLE premium_domain_pricing ADD FOREIGN KEY ("category_id") REFERENCES premium_domain_categories("category_id");
ALTER TABLE support_tickets ADD FOREIGN KEY ("user_id") REFERENCES users(id);
ALTER TABLE support_tickets ADD FOREIGN KEY ("category_id") REFERENCES ticket_categories(id);
ALTER TABLE ticket_responses ADD FOREIGN KEY ("ticket_id") REFERENCES support_tickets(id);