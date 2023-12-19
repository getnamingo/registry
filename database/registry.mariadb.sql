SET FOREIGN_KEY_CHECKS=0;

CREATE DATABASE IF NOT EXISTS `registry`;

CREATE TABLE IF NOT EXISTS `registry`.`launch_phases` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `tld_id` int(10) unsigned DEFAULT NULL,
    `phase_name` VARCHAR(75) DEFAULT NULL,
    `phase_type` VARCHAR(50) NOT NULL,
    `phase_category` VARCHAR(75) NOT NULL,
    `phase_description` TEXT,
    `start_date` DATETIME(3) NOT NULL,
    `end_date` DATETIME(3) DEFAULT NULL,
    `lastupdate` TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP,
    KEY `tld_id` (`tld_id`),
    FOREIGN KEY (`tld_id`) REFERENCES `domain_tld`(`id`),
    UNIQUE(`phase_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='launch phases';

CREATE TABLE IF NOT EXISTS `registry`.`domain_tld` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `tld` varchar(32) NOT NULL,
    `idn_table` varchar(255) NOT NULL,
    `secure` TINYINT UNSIGNED NOT NULL,
    `launch_phase_id` INT DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tld` (`tld`),
    FOREIGN KEY (`launch_phase_id`) REFERENCES `launch_phase`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='domain tld';

CREATE TABLE IF NOT EXISTS `registry`.`settings` (
    `name` varchar(64) NOT NULL,
    `value` varchar(255) DEFAULT NULL,
    PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='registry settings';

CREATE TABLE IF NOT EXISTS `registry`.`domain_price` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `tldid` int(10) unsigned NOT NULL,
    `command` enum('create','renew','transfer') NOT NULL default 'create',
    `m0` decimal(10,2) NOT NULL default '0.00',
    `m12` decimal(10,2) NOT NULL default '0.00',
    `m24` decimal(10,2) NOT NULL default '0.00',
    `m36` decimal(10,2) NOT NULL default '0.00',
    `m48` decimal(10,2) NOT NULL default '0.00',
    `m60` decimal(10,2) NOT NULL default '0.00',
    `m72` decimal(10,2) NOT NULL default '0.00',
    `m84` decimal(10,2) NOT NULL default '0.00',
    `m96` decimal(10,2) NOT NULL default '0.00',
    `m108` decimal(10,2) NOT NULL default '0.00',
    `m120` decimal(10,2) NOT NULL default '0.00',
    PRIMARY KEY  (`id`),
    UNIQUE KEY `unique_record` (`tldid`,`command`),
    CONSTRAINT `domain_price_ibfk_1` FOREIGN KEY (`tldid`) REFERENCES `domain_tld` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='domain price';

CREATE TABLE IF NOT EXISTS `registry`.`domain_restore_price` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `tldid` int(10) unsigned NOT NULL,
    `price` decimal(10,2) NOT NULL default '0.00',
    PRIMARY KEY  (`id`),
    UNIQUE KEY `tldid` (`tldid`),
    CONSTRAINT `domain_restore_price_ibfk_1` FOREIGN KEY (`tldid`) REFERENCES `domain_tld` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='domain restore price';

CREATE TABLE IF NOT EXISTS `registry`.`allocation_tokens` (
    token VARCHAR(255) NOT NULL,
    domain_name VARCHAR(255) DEFAULT NULL,
    tokenStatus VARCHAR(100) DEFAULT NULL,
    tokenType VARCHAR(100) DEFAULT NULL,
    createDateTime TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lastUpdate DATETIME(3) DEFAULT NULL,
    registrars JSON DEFAULT NULL,
    tlds JSON DEFAULT NULL,
    eppActions JSON DEFAULT NULL,
    reducePremium TINYINT(1) NOT NULL,
    reduceYears INT NOT NULL CHECK (reduceYears BETWEEN 0 AND 10),
    PRIMARY KEY (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='allocation tokens';

CREATE TABLE IF NOT EXISTS `registry`.`error_log` (
    `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `registrar_id` INT(10) unsigned NOT NULL,
    `log` TEXT NOT NULL,
    `date` TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `error_log_ibfk_1` FOREIGN KEY (`registrar_id`) REFERENCES `registrar` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='registry error log';

CREATE TABLE IF NOT EXISTS `registry`.`reserved_domain_names` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `name` varchar(68) NOT NULL,
    `type` enum('reserved','restricted') NOT NULL default 'reserved',
    PRIMARY KEY (`id`),
    UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='reserved domain names';

CREATE TABLE IF NOT EXISTS `registry`.`registrar` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `name` varchar(255) NOT NULL,
    `iana_id` int(5) DEFAULT NULL,
    `clid` varchar(16) NOT NULL,
    `pw` varchar(256) NOT NULL,
    `prefix` char(2) NOT NULL,
    `email` varchar(255) NOT NULL,
    `whois_server` varchar(255) NOT NULL,
    `rdap_server` varchar(255) NOT NULL,
    `url` varchar(255) NOT NULL,
    `abuse_email` varchar(255) NOT NULL,
    `abuse_phone` varchar(255) NOT NULL,
    `accountBalance` decimal(12,2) NOT NULL default '0.00',
    `creditLimit` decimal(12,2) NOT NULL default '0.00',
    `creditThreshold` decimal(12,2) NOT NULL default '0.00',
    `thresholdType` enum('fixed','percent') NOT NULL default 'fixed',
    `currency` varchar(5) NOT NULL default 'USD',
    `vat_number` varchar(30) DEFAULT NULL,
    `crdate` datetime(3) NOT NULL,
    `lastupdate` TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `clid` (`clid`),
    UNIQUE KEY `prefix` (`prefix`),
    UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='registrar';

CREATE TABLE IF NOT EXISTS `registry`.`registrar_whitelist` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `registrar_id` int(10) unsigned NOT NULL,
    `addr` varchar(45) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniquekey` (`registrar_id`,`addr`),
    CONSTRAINT `registrar_whitelist_ibfk_1` FOREIGN KEY (`registrar_id`) REFERENCES `registrar` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='registrar whitelist';

CREATE TABLE IF NOT EXISTS `registry`.`registrar_contact` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `registrar_id` int(10) unsigned NOT NULL,
    `type` enum('owner','admin','billing','tech','abuse') NOT NULL default 'admin',
    `title` varchar(255) default NULL,
    `first_name` varchar(255) NOT NULL,
    `middle_name` varchar(255) default NULL,
    `last_name` varchar(255) NOT NULL,
    `org` varchar(255) default NULL,
    `street1` varchar(255) default NULL,
    `street2` varchar(255) default NULL,
    `street3` varchar(255) default NULL,
    `city` varchar(255) NOT NULL,
    `sp` varchar(255) default NULL,
    `pc` varchar(16) default NULL,
    `cc` char(2) NOT NULL,
    `voice` varchar(17) default NULL,
    `fax` varchar(17) default NULL,
    `email` varchar(255) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniquekey` (`registrar_id`,`type`),
    CONSTRAINT `registrar_contact_ibfk_1` FOREIGN KEY (`registrar_id`) REFERENCES `registrar` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='registrar data';

CREATE TABLE IF NOT EXISTS `registry`.`registrar_ote` (
    `registrar_id` int(11) unsigned NOT NULL,
    `command` varchar(75) NOT NULL,
    `result` int(10) unsigned NOT NULL,
    UNIQUE KEY `test` (`registrar_id`,`command`,`result`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='automated registrar OTE';

CREATE TABLE IF NOT EXISTS `registry`.`poll` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `registrar_id` int(10) unsigned NOT NULL,
    `qdate` datetime(3) NOT NULL,
    `msg` text default NULL,
    `msg_type` enum('lowBalance','domainTransfer','contactTransfer') default NULL,
    `obj_name_or_id` varchar(68),
    `obj_trStatus` enum('clientApproved','clientCancelled','clientRejected','pending','serverApproved','serverCancelled') default NULL,
    `obj_reID` varchar(255),
    `obj_reDate` datetime(3),
    `obj_acID` varchar(255),
    `obj_acDate` datetime(3),
    `obj_exDate` datetime(3) default NULL,
    `registrarName` varchar(255),
    `creditLimit` decimal(12,2) default '0.00',
    `creditThreshold` decimal(12,2) default '0.00',
    `creditThresholdType` enum('FIXED','PERCENT'),
    `availableCredit` decimal(12,2) default '0.00',
    PRIMARY KEY (`id`),
    CONSTRAINT `poll_ibfk_1` FOREIGN KEY (`registrar_id`) REFERENCES `registrar` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='poll';

CREATE TABLE IF NOT EXISTS `registry`.`payment_history` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `registrar_id` int(10) unsigned NOT NULL,
    `date` datetime(3) NOT NULL,
    `description` text NOT NULL,
    `amount` decimal(12,2) NOT NULL,
    PRIMARY KEY (`id`),
    CONSTRAINT `payment_history_ibfk_1` FOREIGN KEY (`registrar_id`) REFERENCES `registrar` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='payment history';

CREATE TABLE IF NOT EXISTS `registry`.`statement` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `registrar_id` int(10) unsigned NOT NULL,
    `date` datetime(3) NOT NULL,
    `command` enum('create','renew','transfer','restore','autoRenew') NOT NULL default 'create',
    `domain_name` varchar(68) NOT NULL,
    `length_in_months` tinyint(3) unsigned NOT NULL,
    `fromS` datetime(3) NOT NULL,
    `toS` datetime(3) NOT NULL,
    `amount` decimal(12,2) NOT NULL,
    PRIMARY KEY (`id`),
    CONSTRAINT `statement_ibfk_1` FOREIGN KEY (`registrar_id`) REFERENCES `registrar` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='financial statement';

CREATE TABLE IF NOT EXISTS `registry`.`invoices` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `registrar_id` INT(10) UNSIGNED,
    `invoice_number` varchar(25) default NULL,
    `billing_contact_id` INT(10) UNSIGNED,
    `issue_date` DATETIME(3),
    `due_date` DATETIME(3) default NULL,
    `total_amount` DECIMAL(10,2),
    `payment_status` ENUM('unpaid', 'paid', 'overdue', 'cancelled') DEFAULT 'unpaid',
    `notes` TEXT default NULL,
    `created_at` DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    FOREIGN KEY (registrar_id) REFERENCES registrar(id),
    FOREIGN KEY (billing_contact_id) REFERENCES registrar_contact(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='invoices';

CREATE TABLE IF NOT EXISTS `registry`.`contact` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `identifier` varchar(255) NOT NULL,
    `voice` varchar(17) default NULL,
    `voice_x` int(10) default NULL,
    `fax` varchar(17) default NULL,
    `fax_x` int(10) default NULL,
    `email` varchar(255) NOT NULL,
    `nin` varchar(255) default NULL,
    `nin_type` enum('personal','business') default NULL,
    `clid` int(10) unsigned NOT NULL,
    `crid` int(10) unsigned NOT NULL,
    `crdate` datetime(3) NOT NULL,
    `upid` int(10) unsigned default NULL,
    `lastupdate` datetime(3) default NULL,
    `trdate` datetime(3) default NULL,
    `trstatus` enum('clientApproved','clientCancelled','clientRejected','pending','serverApproved','serverCancelled') default NULL,
    `reid` int(10) unsigned default NULL,
    `redate` datetime(3) default NULL,
    `acid` int(10) unsigned default NULL,
    `acdate` datetime(3) default NULL,
    `disclose_voice` enum('0','1') NOT NULL default '1',
    `disclose_fax` enum('0','1') NOT NULL default '1',
    `disclose_email` enum('0','1') NOT NULL default '1',
    PRIMARY KEY (`id`),
    UNIQUE KEY `identifier` (`identifier`),
    CONSTRAINT `contact_ibfk_1` FOREIGN KEY (`clid`) REFERENCES `registrar` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `contact_ibfk_2` FOREIGN KEY (`crid`) REFERENCES `registrar` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `contact_ibfk_3` FOREIGN KEY (`upid`) REFERENCES `registrar` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='contact';

CREATE TABLE IF NOT EXISTS `registry`.`contact_postalInfo` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `contact_id` int(10) unsigned NOT NULL,
    `type` enum('int','loc') NOT NULL default 'int',
    `name` varchar(255) NOT NULL,
    `org` varchar(255) default NULL,
    `street1` varchar(255) default NULL,
    `street2` varchar(255) default NULL,
    `street3` varchar(255) default NULL,
    `city` varchar(255) NOT NULL,
    `sp` varchar(255) default NULL,
    `pc` varchar(16) default NULL,
    `cc` char(2) NOT NULL,
    `disclose_name_int` enum('0','1') NOT NULL default '1',
    `disclose_name_loc` enum('0','1') NOT NULL default '1',
    `disclose_org_int` enum('0','1') NOT NULL default '1',
    `disclose_org_loc` enum('0','1') NOT NULL default '1',
    `disclose_addr_int` enum('0','1') NOT NULL default '1',
    `disclose_addr_loc` enum('0','1') NOT NULL default '1',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniquekey` (`contact_id`,`type`),
    CONSTRAINT `contact_postalInfo_ibfk_1` FOREIGN KEY (`contact_id`) REFERENCES `contact` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='contact:postalInfo';

CREATE TABLE IF NOT EXISTS `registry`.`contact_authInfo` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `contact_id` int(10) unsigned NOT NULL,
    `authtype` enum('pw','ext') NOT NULL default 'pw',
    `authinfo` varchar(64) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `contact_id` (`contact_id`),
    CONSTRAINT `contact_authInfo_ibfk_1` FOREIGN KEY (`contact_id`) REFERENCES `contact` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='contact:authInfo';

CREATE TABLE IF NOT EXISTS `registry`.`contact_status` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `contact_id` int(10) unsigned NOT NULL,
    `status` enum('clientDeleteProhibited','clientTransferProhibited','clientUpdateProhibited','linked','ok','pendingCreate','pendingDelete','pendingTransfer','pendingUpdate','serverDeleteProhibited','serverTransferProhibited','serverUpdateProhibited') NOT NULL default 'ok',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniquekey` (`contact_id`,`status`),
    CONSTRAINT `contact_status_ibfk_1` FOREIGN KEY (`contact_id`) REFERENCES `contact` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='contact:status';

CREATE TABLE IF NOT EXISTS `registry`.`domain` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `name` varchar(68) NOT NULL,
    `tldid` int(10) unsigned NOT NULL,
    `registrant` int(10) unsigned default NULL,
    `crdate` datetime(3) NOT NULL,
    `exdate` datetime(3) NOT NULL,
    `lastupdate` datetime(3) default NULL,
    `clid` int(10) unsigned NOT NULL,
    `crid` int(10) unsigned NOT NULL,
    `upid` int(10) unsigned default NULL,
    `trdate` datetime(3) default NULL,
    `trstatus` enum('clientApproved','clientCancelled','clientRejected','pending','serverApproved','serverCancelled') default NULL,
    `reid` int(10) unsigned default NULL,
    `redate` datetime(3) default NULL,
    `acid` int(10) unsigned default NULL,
    `acdate` datetime(3) default NULL,
    `transfer_exdate` datetime(3) default NULL,
    `idnlang` varchar(16) default NULL,
    `delTime` datetime(3) default NULL,
    `resTime` datetime(3) default NULL,
    `rgpstatus` enum('addPeriod','autoRenewPeriod','renewPeriod','transferPeriod','pendingDelete','pendingRestore','redemptionPeriod') default NULL,
    `rgppostData` text default NULL,
    `rgpdelTime` datetime(3) default NULL,
    `rgpresTime` datetime(3) default NULL,
    `rgpresReason` text default NULL,
    `rgpstatement1` text default NULL,
    `rgpstatement2` text default NULL,
    `rgpother` text default NULL,
    `addPeriod` tinyint(3) unsigned default NULL,
    `autoRenewPeriod` tinyint(3) unsigned default NULL,
    `renewPeriod` tinyint(3) unsigned default NULL,
    `transferPeriod` tinyint(3) unsigned default NULL,
    `renewedDate` datetime(3) default NULL,
    `agp_exempted` tinyint(1) DEFAULT 0,
    `agp_request` datetime(3) default NULL,
    `agp_grant` datetime(3) default NULL,
    `agp_reason` text default NULL,
    `agp_status` varchar(30) default NULL,
    `tm_notice_accepted` datetime(3) default NULL,
    `tm_notice_expires` datetime(3) default NULL,
    `tm_notice_id` varchar(150) default NULL,
    `tm_notice_validator` varchar(30) default NULL,
    `tm_smd_id` text default NULL,
    `tm_phase` TEXT NOT NULL DEFAULT 'NONE',
    PRIMARY KEY (`id`),
    UNIQUE KEY `name` (`name`),
    CONSTRAINT `domain_ibfk_1` FOREIGN KEY (`clid`) REFERENCES `registrar` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `domain_ibfk_2` FOREIGN KEY (`crid`) REFERENCES `registrar` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `domain_ibfk_3` FOREIGN KEY (`upid`) REFERENCES `registrar` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `domain_ibfk_4` FOREIGN KEY (`registrant`) REFERENCES `contact` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `domain_ibfk_5` FOREIGN KEY (`reid`) REFERENCES `registrar` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `domain_ibfk_6` FOREIGN KEY (`acid`) REFERENCES `registrar` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `domain_ibfk_7` FOREIGN KEY (`tldid`) REFERENCES `domain_tld` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='domain';

CREATE TABLE IF NOT EXISTS `registry`.`application` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `name` varchar(68) NOT NULL,
    `tldid` int(10) unsigned NOT NULL,
    `registrant` int(10) unsigned default NULL,
    `crdate` datetime(3) NOT NULL,
    `exdate` datetime(3) default NULL,
    `lastupdate` datetime(3) default NULL,
    `clid` int(10) unsigned NOT NULL,
    `crid` int(10) unsigned NOT NULL,
    `upid` int(10) unsigned default NULL,
    `trdate` datetime(3) default NULL,
    `trstatus` enum('clientApproved','clientCancelled','clientRejected','pending','serverApproved','serverCancelled') default NULL,
    `reid` int(10) unsigned default NULL,
    `redate` datetime(3) default NULL,
    `acid` int(10) unsigned default NULL,
    `acdate` datetime(3) default NULL,
    `transfer_exdate` datetime(3) default NULL,
    `idnlang` varchar(16) default NULL,
    `delTime` datetime(3) default NULL,
    `application_id` varchar(36) default NULL,
    `authtype` enum('pw','ext') NOT NULL default 'pw',
    `authinfo` varchar(64) NOT NULL,
    `phase_name` VARCHAR(75) DEFAULT NULL,
    `phase_type` VARCHAR(50) NOT NULL,
    `smd` text default NULL,
    `tm_notice_accepted` datetime(3) default NULL,
    `tm_notice_expires` datetime(3) default NULL,
    `tm_notice_id` varchar(150) default NULL,
    `tm_notice_validator` varchar(30) default NULL,
    `tm_smd_id` text default NULL,
    `tm_phase` TEXT NOT NULL DEFAULT 'NONE',
    PRIMARY KEY (`id`),
    CONSTRAINT `application_ibfk_1` FOREIGN KEY (`clid`) REFERENCES `registrar` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `application_ibfk_2` FOREIGN KEY (`crid`) REFERENCES `registrar` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `application_ibfk_3` FOREIGN KEY (`upid`) REFERENCES `registrar` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `application_ibfk_4` FOREIGN KEY (`registrant`) REFERENCES `contact` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `application_ibfk_5` FOREIGN KEY (`reid`) REFERENCES `registrar` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `application_ibfk_6` FOREIGN KEY (`acid`) REFERENCES `registrar` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `application_ibfk_7` FOREIGN KEY (`tldid`) REFERENCES `domain_tld` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='application';

CREATE TABLE IF NOT EXISTS `registry`.`domain_contact_map` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `domain_id` int(10) unsigned NOT NULL,
    `contact_id` int(10) unsigned NOT NULL,
    `type` enum('admin','billing','tech') NOT NULL default 'admin',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniquekey` (`domain_id`,`contact_id`,`type`),
    CONSTRAINT `domain_contact_map_ibfk_1` FOREIGN KEY (`domain_id`) REFERENCES `domain` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `domain_contact_map_ibfk_2` FOREIGN KEY (`contact_id`) REFERENCES `contact` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='contact map for domains';

CREATE TABLE IF NOT EXISTS `registry`.`application_contact_map` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `domain_id` int(10) unsigned NOT NULL,
    `contact_id` int(10) unsigned NOT NULL,
    `type` enum('admin','billing','tech') NOT NULL default 'admin',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniquekey` (`domain_id`,`contact_id`,`type`),
    CONSTRAINT `application_contact_map_ibfk_1` FOREIGN KEY (`domain_id`) REFERENCES `application` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `application_contact_map_ibfk_2` FOREIGN KEY (`contact_id`) REFERENCES `contact` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='contact map for applications';

CREATE TABLE IF NOT EXISTS `registry`.`domain_authInfo` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `domain_id` int(10) unsigned NOT NULL,
    `authtype` enum('pw','ext') NOT NULL default 'pw',
    `authinfo` varchar(64) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `domain_id` (`domain_id`),
    CONSTRAINT `domain_authInfo_ibfk_1` FOREIGN KEY (`domain_id`) REFERENCES `domain` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='domain:authInfo';

CREATE TABLE IF NOT EXISTS `registry`.`domain_status` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `domain_id` int(10) unsigned NOT NULL,
    `status` enum('clientDeleteProhibited','clientHold','clientRenewProhibited','clientTransferProhibited','clientUpdateProhibited','inactive','ok','pendingCreate','pendingDelete','pendingRenew','pendingTransfer','pendingUpdate','serverDeleteProhibited','serverHold','serverRenewProhibited','serverTransferProhibited','serverUpdateProhibited') NOT NULL default 'ok',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniquekey` (`domain_id`,`status`),
    CONSTRAINT `domain_status_ibfk_1` FOREIGN KEY (`domain_id`) REFERENCES `domain` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='domain:status';

CREATE TABLE IF NOT EXISTS `registry`.`application_status` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `domain_id` int(10) unsigned NOT NULL,
    `status` enum('pendingValidation','validated','invalid','pendingAllocation','allocated','rejected','custom') NOT NULL default 'pendingValidation',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniquekey` (`domain_id`,`status`),
    CONSTRAINT `application_status_ibfk_1` FOREIGN KEY (`domain_id`) REFERENCES `application` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='application:status';

CREATE TABLE IF NOT EXISTS `registry`.`secdns` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `domain_id` int(10) unsigned NOT NULL,
    `maxsiglife` int(10) unsigned default '604800',
    `interface` enum('dsData','keyData') NOT NULL default 'dsData',
    `keytag` smallint(5) unsigned NOT NULL,
    `alg` tinyint(3) unsigned NOT NULL default '5',
    `digesttype` tinyint(3) unsigned NOT NULL default '1',
    `digest` varchar(64) NOT NULL,
    `flags` smallint(5) unsigned default NULL,
    `protocol` smallint(5) unsigned default NULL,
    `keydata_alg` tinyint(3) unsigned default NULL,
    `pubkey` varchar(255) default NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniquekey` (`domain_id`,`digest`),
    CONSTRAINT `secdns_ibfk_1` FOREIGN KEY (`domain_id`) REFERENCES `domain` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='secDNS';

CREATE TABLE IF NOT EXISTS `registry`.`host` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `name` varchar(255) NOT NULL,
    `domain_id` int(10) unsigned default NULL,
    `clid` int(10) unsigned NOT NULL,
    `crid` int(10) unsigned NOT NULL,
    `crdate` datetime(3) NOT NULL,
    `upid` int(10) unsigned default NULL,
    `lastupdate` datetime(3) default NULL,
    `trdate` datetime(3) default NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `name` (`name`),
    CONSTRAINT `host_ibfk_1` FOREIGN KEY (`clid`) REFERENCES `registrar` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `host_ibfk_2` FOREIGN KEY (`crid`) REFERENCES `registrar` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `host_ibfk_3` FOREIGN KEY (`upid`) REFERENCES `registrar` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `host_ibfk_4` FOREIGN KEY (`domain_id`) REFERENCES `domain` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='host';

CREATE TABLE IF NOT EXISTS `registry`.`domain_host_map` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `domain_id` int(10) unsigned NOT NULL,
    `host_id` int(10) unsigned NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `domain_host_map_id` (`domain_id`,`host_id`),
    CONSTRAINT `domain_host_map_ibfk_1` FOREIGN KEY (`domain_id`) REFERENCES `domain` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `domain_host_map_ibfk_2` FOREIGN KEY (`host_id`) REFERENCES `host` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='host map for domains';

CREATE TABLE IF NOT EXISTS `registry`.`application_host_map` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `domain_id` int(10) unsigned NOT NULL,
    `host_id` int(10) unsigned NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `application_host_map_id` (`domain_id`,`host_id`),
    CONSTRAINT `application_host_map_ibfk_1` FOREIGN KEY (`domain_id`) REFERENCES `application` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `application_host_map_ibfk_2` FOREIGN KEY (`host_id`) REFERENCES `host` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='host map for applications';

CREATE TABLE IF NOT EXISTS `registry`.`host_addr` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `host_id` int(10) unsigned NOT NULL,
    `addr` varchar(45) NOT NULL,
    `ip` enum('v4','v6') NOT NULL default 'v4',
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique` (`host_id`,`addr`,`ip`),
    CONSTRAINT `host_addr_ibfk_1` FOREIGN KEY (`host_id`) REFERENCES `host` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='host_addr';

CREATE TABLE IF NOT EXISTS `registry`.`host_status` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `host_id` int(10) unsigned NOT NULL,
    `status` enum('clientDeleteProhibited','clientUpdateProhibited','linked','ok','pendingCreate','pendingDelete','pendingTransfer','pendingUpdate','serverDeleteProhibited','serverUpdateProhibited') NOT NULL default 'ok',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniquekey` (`host_id`,`status`),
    CONSTRAINT `host_status_ibfk_1` FOREIGN KEY (`host_id`) REFERENCES `host` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='host:status';

CREATE TABLE IF NOT EXISTS `registry`.`domain_auto_approve_transfer` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `name` varchar(68) NOT NULL,
    `registrant` int(10) unsigned default NULL,
    `crdate` datetime(3) NOT NULL,
    `exdate` datetime(3) NOT NULL,
    `lastupdate` datetime(3) default NULL,
    `clid` int(10) unsigned NOT NULL,
    `crid` int(10) unsigned NOT NULL,
    `upid` int(10) unsigned default NULL,
    `trdate` datetime(3) default NULL,
    `trstatus` enum('clientApproved','clientCancelled','clientRejected','pending','serverApproved','serverCancelled') default NULL,
    `reid` int(10) unsigned default NULL,
    `redate` datetime(3) default NULL,
    `acid` int(10) unsigned default NULL,
    `acdate` datetime(3) default NULL,
    `transfer_exdate` datetime(3) default NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='domain_auto_approve_transfer';

CREATE TABLE IF NOT EXISTS `registry`.`contact_auto_approve_transfer` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `identifier` varchar(255) NOT NULL,
    `voice` varchar(17) default NULL,
    `voice_x` int(10) default NULL,
    `fax` varchar(17) default NULL,
    `fax_x` int(10) default NULL,
    `email` varchar(255) NOT NULL,
    `nin` varchar(255) default NULL,
    `nin_type` enum('personal','business') default NULL,
    `clid` int(10) unsigned NOT NULL,
    `crid` int(10) unsigned NOT NULL,
    `crdate` datetime(3) NOT NULL,
    `upid` int(10) unsigned default NULL,
    `lastupdate` datetime(3) default NULL,
    `trdate` datetime(3) default NULL,
    `trstatus` enum('clientApproved','clientCancelled','clientRejected','pending','serverApproved','serverCancelled') default NULL,
    `reid` int(10) unsigned default NULL,
    `redate` datetime(3) default NULL,
    `acid` int(10) unsigned default NULL,
    `acdate` datetime(3) default NULL,
    `disclose_voice` enum('0','1') NOT NULL default '1',
    `disclose_fax` enum('0','1') NOT NULL default '1',
    `disclose_email` enum('0','1') NOT NULL default '1',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='contact_auto_approve_transfer';

CREATE TABLE IF NOT EXISTS `registry`.`statistics` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `date` date NOT NULL,
    `total_domains` int(10) unsigned NOT NULL DEFAULT '0',
    `created_domains` int(10) unsigned NOT NULL DEFAULT '0',
    `renewed_domains` int(10) unsigned NOT NULL DEFAULT '0',
    `transfered_domains` int(10) unsigned NOT NULL DEFAULT '0',
    `deleted_domains` int(10) unsigned NOT NULL DEFAULT '0',
    `restored_domains` int(10) unsigned NOT NULL DEFAULT '0',
    PRIMARY KEY (`id`),
    UNIQUE KEY `date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Statistics';

CREATE TABLE IF NOT EXISTS `registry`.`users` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `email` varchar(249) COLLATE utf8mb4_unicode_ci NOT NULL,
    `password` varchar(255) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
    `username` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `status` tinyint(2) unsigned NOT NULL DEFAULT '0',
    `verified` tinyint(1) unsigned NOT NULL DEFAULT '0',
    `resettable` tinyint(1) unsigned NOT NULL DEFAULT '1',
    `roles_mask` int(10) unsigned NOT NULL DEFAULT '0',
    `registered` int(10) unsigned NOT NULL,
    `last_login` int(10) unsigned DEFAULT NULL,
    `force_logout` mediumint(7) unsigned NOT NULL DEFAULT '0',
    `tfa_secret` VARCHAR(32),
    `tfa_enabled` TINYINT DEFAULT 0,
    `auth_method` ENUM('password', '2fa', 'webauthn') DEFAULT 'password',
    `backup_codes` TEXT,
    PRIMARY KEY (`id`),
    UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Panel Users';

CREATE TABLE IF NOT EXISTS `registry`.`users_confirmations` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `user_id` int(10) unsigned NOT NULL,
    `email` varchar(249) COLLATE utf8mb4_unicode_ci NOT NULL,
    `selector` varchar(16) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
    `token` varchar(255) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
    `expires` int(10) unsigned NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `selector` (`selector`),
    KEY `email_expires` (`email`,`expires`),
    KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Panel Users Confirmations';

CREATE TABLE IF NOT EXISTS `registry`.`users_remembered` (
    `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    `user` int(10) unsigned NOT NULL,
    `selector` varchar(24) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
    `token` varchar(255) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
    `expires` int(10) unsigned NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `selector` (`selector`),
    KEY `user` (`user`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Panel Users Remember';

CREATE TABLE IF NOT EXISTS `registry`.`users_resets` (
    `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    `user` int(10) unsigned NOT NULL,
    `selector` varchar(20) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
    `token` varchar(255) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
    `expires` int(10) unsigned NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `selector` (`selector`),
    KEY `user_expires` (`user`,`expires`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Panel Users Reset';

CREATE TABLE IF NOT EXISTS `registry`.`users_throttling` (
    `bucket` varchar(44) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
    `tokens` float unsigned NOT NULL,
    `replenished_at` int(10) unsigned NOT NULL,
    `expires_at` int(10) unsigned NOT NULL,
    PRIMARY KEY (`bucket`),
    KEY `expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Panel Users Flags';

CREATE TABLE IF NOT EXISTS `registry`.`users_webauthn` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `credential_id` VARBINARY(255) NOT NULL,
    `public_key` TEXT NOT NULL,
    `attestation_object` BLOB,
    `sign_count` BIGINT NOT NULL,
    `user_agent` VARCHAR(512),
    `created_at` DATETIME(3) DEFAULT CURRENT_TIMESTAMP,
    `last_used_at` DATETIME(3) DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Panel Users WebAuthn Data';

CREATE TABLE IF NOT EXISTS `registry`.`registrar_users` (
    `registrar_id` int(10) unsigned NOT NULL,
    `user_id` int(10) unsigned NOT NULL,
    PRIMARY KEY (`registrar_id`, `user_id`),
    FOREIGN KEY (`registrar_id`) REFERENCES `registrar`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Linking Registrars with Panel Users';

CREATE TABLE IF NOT EXISTS `registry`.`urs_actions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `domain_name` VARCHAR(255) NOT NULL,
    `urs_provider` VARCHAR(255) NOT NULL,
    `action_date` DATE NOT NULL,
    `status` VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='URS Actions';

CREATE TABLE IF NOT EXISTS `registry`.`rde_escrow_deposits` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `deposit_id` VARCHAR(255) UNIQUE,  -- Unique deposit identifier
    `deposit_date` DATE NOT NULL,
    `revision` INT UNSIGNED NOT NULL DEFAULT 1,
    `file_name` VARCHAR(255) NOT NULL,
    `file_format` ENUM('XML', 'CSV') NOT NULL,  -- Format of the data file
    `file_size` BIGINT UNSIGNED,
    `checksum` VARCHAR(64),
    `encryption_method` VARCHAR(255),  -- Details about how the file is encrypted
    `deposit_type` ENUM('Full', 'Incremental', 'Differential') NOT NULL,
    `status` ENUM('Deposited', 'Retrieved', 'Failed') NOT NULL DEFAULT 'Deposited',
    `receiver` VARCHAR(255),  -- Escrow agent or receiver of the deposit
    `notes` TEXT,
    `verification_status` ENUM('Verified', 'Failed', 'Pending') DEFAULT 'Pending',  -- Status after the escrow agent verifies the deposit
    `verification_notes` TEXT  -- Notes or remarks from the verification process
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Escrow Deposits';

CREATE TABLE IF NOT EXISTS `registry`.`icann_reports` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `report_date` DATE NOT NULL,
    `type` VARCHAR(255) NOT NULL,
    `file_name` VARCHAR(255),
    `submitted_date` DATE,
    `status` ENUM('Pending', 'Submitted', 'Accepted', 'Rejected') NOT NULL DEFAULT 'Pending',
    `notes` TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ICANN Reporting';

CREATE TABLE IF NOT EXISTS `registry`.`promotion_pricing` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `tld_id` int(10) unsigned DEFAULT NULL,
    `promo_name` varchar(255) NOT NULL,
    `start_date` datetime(3) NOT NULL,
    `end_date` datetime(3) NOT NULL,
    `discount_percentage` decimal(5,2) DEFAULT NULL,
    `discount_amount` decimal(10,2) DEFAULT NULL,
    `description` text DEFAULT NULL,
    `conditions` text DEFAULT NULL,
    `promo_type` ENUM('full', 'registration', 'renewal', 'transfer') NOT NULL,
    `years_of_promotion` int(2) DEFAULT NULL,
    `max_count` int(10) unsigned DEFAULT NULL,
    `registrar_ids` json DEFAULT NULL,
    `status` ENUM('active', 'expired', 'upcoming') NOT NULL,
    `minimum_purchase` decimal(10,2) DEFAULT NULL,
    `target_segment` varchar(255) DEFAULT NULL,
    `region_specific` varchar(255) DEFAULT NULL,
    `created_by` varchar(255) DEFAULT NULL,
    `created_at` datetime(3) DEFAULT NULL,
    `updated_by` varchar(255) DEFAULT NULL,
    `updated_at` datetime(3) DEFAULT NULL,
    KEY `tld_id` (`tld_id`),
    FOREIGN KEY (`tld_id`) REFERENCES `domain_tld`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Promotions';

CREATE TABLE IF NOT EXISTS `registry`.`premium_domain_categories` (
    `category_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `category_name` varchar(255) NOT NULL,
    `category_price` decimal(10,2) NOT NULL,
    PRIMARY KEY (`category_id`),
    UNIQUE KEY `category_name` (`category_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Premium Domains Categories';

CREATE TABLE IF NOT EXISTS `registry`.`premium_domain_pricing` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `domain_name` varchar(255) NOT NULL,
    `tld_id` int(10) unsigned NOT NULL,
    `category_id` int(10) unsigned DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `tld_id` (`tld_id`),
    KEY `category_id` (`category_id`),
    CONSTRAINT `premium_domain_pricing_ibfk_1` FOREIGN KEY (`tld_id`) REFERENCES `domain_tld` (`id`),
    CONSTRAINT `premium_domain_pricing_ibfk_2` FOREIGN KEY (`category_id`) REFERENCES `premium_domain_categories` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Premium Domains';

CREATE TABLE IF NOT EXISTS `registry`.`ticket_categories` (
    `id` INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `description` TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Ticket Categories';

CREATE TABLE IF NOT EXISTS `registry`.`support_tickets` (
    `id` INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(11) UNSIGNED NOT NULL, 
    `category_id` INT(11) UNSIGNED NOT NULL,
    `subject` VARCHAR(255) NOT NULL,
    `message` TEXT NOT NULL,
    `status` ENUM('Open', 'In Progress', 'Resolved', 'Closed') DEFAULT 'Open',
    `priority` ENUM('Low', 'Medium', 'High', 'Critical') DEFAULT 'Medium',
    `reported_domain` VARCHAR(255) DEFAULT NULL,
    `nature_of_abuse` TEXT DEFAULT NULL,
    `evidence` TEXT DEFAULT NULL,
    `relevant_urls` TEXT DEFAULT NULL,
    `date_of_incident` DATE DEFAULT NULL,
    `date_created` datetime(3) DEFAULT CURRENT_TIMESTAMP,
    `last_updated` datetime(3) DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (category_id) REFERENCES ticket_categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Support Tickets';

CREATE TABLE IF NOT EXISTS `registry`.`ticket_responses` (
    `id` INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ticket_id` INT(11) UNSIGNED NOT NULL,
    `responder_id` INT(11) UNSIGNED NOT NULL,
    `response` TEXT NOT NULL,
    `date_created` datetime(3) DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ticket_id) REFERENCES support_tickets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Ticket Responses';

CREATE TABLE IF NOT EXISTS `registry`.`tmch_claims` (
    `id` INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `domain_label` VARCHAR(100) NOT NULL,
    `claim_key` VARCHAR(200) NOT NULL,
    `insert_time` datetime(3) NOT NULL,
    UNIQUE KEY `tmch_claims_1` (`domain_label`,`claim_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='TMCH Claims';

CREATE TABLE IF NOT EXISTS `registry`.`tmch_revocation` (
    `id` INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `smd_id` VARCHAR(100) NOT NULL,
    `revocation_time` datetime(3) NOT NULL,
    UNIQUE KEY `tmch_revocation_1` (`smd_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='TMCH Revocation';

CREATE TABLE IF NOT EXISTS `registry`.`tmch_crl` (
    `id` INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `content` TEXT NOT NULL,
    `url` VARCHAR(255) NOT NULL,
    `update_timestamp` datetime(3) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='TMCH Crl';

INSERT INTO `registry`.`domain_tld` VALUES('1','.TEST','/^(?!-)(?!.*--)[A-Z0-9-]{1,63}(?<!-)(\.(?!-)(?!.*--)[A-Z0-9-]{1,63}(?<!-))*$/i','0');
INSERT INTO `registry`.`domain_tld` VALUES('2','.COM.TEST','/^(?!-)(?!.*--)[A-Z0-9-]{1,63}(?<!-)(\.(?!-)(?!.*--)[A-Z0-9-]{1,63}(?<!-))*$/i','0');

INSERT INTO `registry`.`domain_price` VALUES('1','1','create','0.00','5.00','10.00','15.00','20.00','25.00','30.00','35.00','40.00','45.00','50.00');
INSERT INTO `registry`.`domain_price` VALUES('2','1','renew','0.00','5.00','10.00','15.00','20.00','25.00','30.00','35.00','40.00','45.00','50.00');
INSERT INTO `registry`.`domain_price` VALUES('3','1','transfer','0.00','5.00','10.00','15.00','20.00','25.00','30.00','35.00','40.00','45.00','50.00');

INSERT INTO `registry`.`domain_price` VALUES('4','2','create','0.00','5.00','10.00','15.00','20.00','25.00','30.00','35.00','40.00','45.00','50.00');
INSERT INTO `registry`.`domain_price` VALUES('5','2','renew','0.00','5.00','10.00','15.00','20.00','25.00','30.00','35.00','40.00','45.00','50.00');
INSERT INTO `registry`.`domain_price` VALUES('6','2','transfer','0.00','5.00','10.00','15.00','20.00','25.00','30.00','35.00','40.00','45.00','50.00');

INSERT INTO `registry`.`domain_restore_price` VALUES('1','1','50.00');
INSERT INTO `registry`.`domain_restore_price` VALUES('2','2','50.00');

INSERT INTO `registry`.`registrar` (`name`,`clid`,`pw`,`prefix`,`email`,`whois_server`,`rdap_server`,`url`,`abuse_email`,`abuse_phone`,`accountBalance`,`creditLimit`,`creditThreshold`,`thresholdType`,`crdate`,`lastupdate`) VALUES('LeoNet LLC','leonet','$argon2id$v=19$m=131072,t=6,p=4$M0ViOHhzTWFtQW5YSGZ2MA$g2pKb+PEYtfs4QwLmf2iUtPM4+7evuqYQFp6yqGZmQg','LN','info@leonet.test','whois.leonet.test','rdap.leonet.test','https://www.leonet.test','abuse@leonet.test','+380.325050','100000.00','100000.00','500.00','fixed',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP);
INSERT INTO `registry`.`registrar` (`name`,`clid`,`pw`,`prefix`,`email`,`whois_server`,`rdap_server`,`url`,`abuse_email`,`abuse_phone`,`accountBalance`,`creditLimit`,`creditThreshold`,`thresholdType`,`crdate`,`lastupdate`) VALUES('Nord Registrar AB','nordregistrar','$argon2id$v=19$m=131072,t=6,p=4$MU9Eei5UMjA0M2cxYjd3bg$2yBHTWVVY4xQlMGhnhol9MRbVyVQg8qkcZ6cpdeID1U','NR','info@nordregistrar.test','whois.nordregistrar.test','rdap.nordregistrar.test','https://www.nordregistrar.test','abuse@nordregistrar.test','+46.80203','100000.00','100000.00','500.00','fixed',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP);

INSERT INTO `registry`.`ticket_categories` (name, description) VALUES 
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

INSERT INTO `registry`.`settings` (`name`, `value`) VALUES
('dns-tcp-queries-received',    '0'),
('dns-tcp-queries-responded',    '0'),
('dns-udp-queries-received',    '0'),
('dns-udp-queries-responded',    '0'),
('searchable-whois-queries',    '0'),
('web-whois-queries',    '0'),
('whois-43-queries',    '0'),
('company_name',    'Example Registry LLC'),
('address',    '123 Example Street, Example City'),
('address2',    '48000'),
('cc',    'Ukraine'),
('vat_number',    '0'),
('phone',    '+123456789'),
('handle',    'RXX'),
('email',    'contact@example.com'),
('launch_phases',    'on'),
('whois_server',    'whois.example.com'),
('rdap_server',    'https://rdap.example.com');

CREATE DATABASE IF NOT EXISTS `registryTransaction`;

CREATE TABLE IF NOT EXISTS `registryTransaction`.`transaction_identifier` (
    `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    `registrar_id` int(10) unsigned NOT NULL,
    `clTRID` varchar(64),
    `clTRIDframe` text,
    `cldate` datetime(3),
    `clmicrosecond` int(6),
    `cmd` enum('login','logout','check','info','poll','transfer','create','delete','renew','update') default NULL,
    `obj_type` enum('domain','host','contact') default NULL,
    `obj_id` text default NULL,
    `code` smallint(4) unsigned default NULL,
    `msg` varchar(255) default NULL,
    `svTRID` varchar(64),
    `svTRIDframe` text,
    `svdate` datetime(3),
    `svmicrosecond` int(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `clTRID` (`clTRID`),
    UNIQUE KEY `svTRID` (`svTRID`),
    CONSTRAINT `transaction_identifier_ibfk_1` FOREIGN KEY (`registrar_id`) REFERENCES `registry`.`registrar` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='transaction identifier';

GRANT ALL ON `registryTransaction`.* TO 'registry'@'localhost';
GRANT SELECT ON `registryTransaction`.* TO 'registry-select'@'localhost';

FLUSH PRIVILEGES;