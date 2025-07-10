-- Enable foreign key support
PRAGMA foreign_keys = ON;

-----------------------------------------------------------
-- Table Definitions
-----------------------------------------------------------

-- launch_phases
CREATE TABLE IF NOT EXISTS launch_phases (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tld_id INTEGER DEFAULT NULL,
    phase_name VARCHAR(75) DEFAULT NULL,
    phase_type VARCHAR(50) NOT NULL,
    phase_category VARCHAR(75) NOT NULL,
    phase_description TEXT,
    start_date DATETIME NOT NULL,
    end_date DATETIME DEFAULT NULL,
    lastupdate DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (phase_name),
    FOREIGN KEY (tld_id) REFERENCES domain_tld(id)
);
CREATE INDEX IF NOT EXISTS idx_launch_phases_tld_id ON launch_phases(tld_id);

-- domain_tld
CREATE TABLE IF NOT EXISTS domain_tld (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tld VARCHAR(32) NOT NULL,
    idn_table VARCHAR(255) NOT NULL,
    secure INTEGER NOT NULL,
    launch_phase_id INTEGER DEFAULT NULL,
    UNIQUE (tld),
    FOREIGN KEY (launch_phase_id) REFERENCES launch_phases(id)
);

-- settings
CREATE TABLE IF NOT EXISTS settings (
    name VARCHAR(64) NOT NULL PRIMARY KEY,
    value VARCHAR(255) DEFAULT NULL
);

-- domain_price
CREATE TABLE IF NOT EXISTS domain_price (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tldid INTEGER NOT NULL,
    registrar_id INTEGER DEFAULT NULL,
    command VARCHAR(20) NOT NULL DEFAULT 'create' CHECK(command IN ('create','renew','transfer')),
    m0 DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    m12 DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    m24 DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    m36 DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    m48 DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    m60 DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    m72 DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    m84 DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    m96 DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    m108 DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    m120 DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    UNIQUE (tldid, command, registrar_id),
    FOREIGN KEY (tldid) REFERENCES domain_tld(id)
);

-- domain_restore_price
CREATE TABLE IF NOT EXISTS domain_restore_price (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tldid INTEGER NOT NULL,
    registrar_id INTEGER DEFAULT NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    UNIQUE (tldid, registrar_id),
    FOREIGN KEY (tldid) REFERENCES domain_tld(id)
);

-- allocation_tokens (JSON stored as TEXT)
CREATE TABLE IF NOT EXISTS allocation_tokens (
    token VARCHAR(255) NOT NULL PRIMARY KEY,
    domain_name VARCHAR(255) DEFAULT NULL,
    tokenStatus VARCHAR(100) DEFAULT NULL,
    tokenType VARCHAR(100) DEFAULT NULL,
    crdate DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lastupdate DATETIME DEFAULT NULL,
    registrars TEXT DEFAULT NULL,
    tlds TEXT DEFAULT NULL,
    eppActions TEXT DEFAULT NULL,
    reducePremium INTEGER DEFAULT NULL,
    reduceYears INTEGER DEFAULT NULL CHECK(reduceYears BETWEEN 0 AND 10)
);

-- error_log (JSON stored as TEXT)
CREATE TABLE IF NOT EXISTS error_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    channel VARCHAR(255),
    level INTEGER,
    level_name VARCHAR(10),
    message TEXT,
    context TEXT,
    extra TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- reserved_domain_names
CREATE TABLE IF NOT EXISTS reserved_domain_names (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(68) NOT NULL,
    type VARCHAR(20) NOT NULL DEFAULT 'reserved' CHECK(type IN ('reserved','restricted')),
    UNIQUE (name)
);

-- registrar
CREATE TABLE IF NOT EXISTS registrar (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(255) NOT NULL,
    iana_id INTEGER DEFAULT NULL,
    clid VARCHAR(16) NOT NULL,
    pw VARCHAR(256) NOT NULL,
    prefix CHAR(5) NOT NULL,
    email VARCHAR(255) NOT NULL,
    whois_server VARCHAR(255) NOT NULL,
    rdap_server VARCHAR(255) NOT NULL,
    url VARCHAR(255) NOT NULL,
    abuse_email VARCHAR(255) NOT NULL,
    abuse_phone VARCHAR(255) NOT NULL,
    accountBalance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    creditLimit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    creditThreshold DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    thresholdType VARCHAR(10) NOT NULL DEFAULT 'fixed' CHECK(thresholdType IN ('fixed','percent')),
    currency VARCHAR(5) NOT NULL DEFAULT 'USD',
    companyNumber VARCHAR(30) DEFAULT NULL,
    vatNumber VARCHAR(30) DEFAULT NULL,
    ssl_fingerprint CHAR(64) DEFAULT NULL,
    crdate DATETIME NOT NULL,
    lastupdate DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (clid),
    UNIQUE (prefix),
    UNIQUE (email)
);

-- registrar_whitelist
CREATE TABLE IF NOT EXISTS registrar_whitelist (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    registrar_id INTEGER NOT NULL,
    addr VARCHAR(45) NOT NULL,
    UNIQUE (registrar_id, addr),
    FOREIGN KEY (registrar_id) REFERENCES registrar(id)
);
CREATE INDEX IF NOT EXISTS idx_addr ON registrar_whitelist(addr);

-- registrar_contact
CREATE TABLE IF NOT EXISTS registrar_contact (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    registrar_id INTEGER NOT NULL,
    type VARCHAR(10) NOT NULL DEFAULT 'admin' CHECK(type IN ('owner','admin','billing','tech','abuse')),
    title VARCHAR(255) DEFAULT NULL,
    first_name VARCHAR(255) NOT NULL,
    middle_name VARCHAR(255) DEFAULT NULL,
    last_name VARCHAR(255) NOT NULL,
    org VARCHAR(255) DEFAULT NULL,
    street1 VARCHAR(255) DEFAULT NULL,
    street2 VARCHAR(255) DEFAULT NULL,
    street3 VARCHAR(255) DEFAULT NULL,
    city VARCHAR(255) NOT NULL,
    sp VARCHAR(255) DEFAULT NULL,
    pc VARCHAR(16) DEFAULT NULL,
    cc CHAR(2) NOT NULL,
    voice VARCHAR(17) DEFAULT NULL,
    fax VARCHAR(17) DEFAULT NULL,
    email VARCHAR(255) NOT NULL,
    UNIQUE (registrar_id, type),
    FOREIGN KEY (registrar_id) REFERENCES registrar(id)
);

-- registrar_ote
CREATE TABLE IF NOT EXISTS registrar_ote (
    registrar_id INTEGER NOT NULL,
    command VARCHAR(75) NOT NULL,
    result INTEGER NOT NULL,
    UNIQUE (registrar_id, command, result)
);

-- poll
CREATE TABLE IF NOT EXISTS poll (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    registrar_id INTEGER NOT NULL,
    qdate DATETIME NOT NULL,
    msg TEXT DEFAULT NULL,
    msg_type VARCHAR(50) DEFAULT NULL CHECK(msg_type IN ('lowBalance','domainTransfer','contactTransfer')),
    obj_name_or_id VARCHAR(68),
    obj_trStatus VARCHAR(50) DEFAULT NULL CHECK(obj_trStatus IN ('clientApproved','clientCancelled','clientRejected','pending','serverApproved','serverCancelled')),
    obj_reID VARCHAR(255),
    obj_reDate DATETIME,
    obj_acID VARCHAR(255),
    obj_acDate DATETIME,
    obj_exDate DATETIME DEFAULT NULL,
    registrarName VARCHAR(255),
    creditLimit DECIMAL(12,2) DEFAULT 0.00,
    creditThreshold DECIMAL(12,2) DEFAULT 0.00,
    creditThresholdType VARCHAR(10) CHECK(creditThresholdType IN ('FIXED','PERCENT')),
    availableCredit DECIMAL(12,2) DEFAULT 0.00,
    FOREIGN KEY (registrar_id) REFERENCES registrar(id)
);

-- payment_history
CREATE TABLE IF NOT EXISTS payment_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    registrar_id INTEGER NOT NULL,
    date DATETIME NOT NULL,
    description TEXT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    FOREIGN KEY (registrar_id) REFERENCES registrar(id)
);

-- statement
CREATE TABLE IF NOT EXISTS statement (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    registrar_id INTEGER NOT NULL,
    date DATETIME NOT NULL,
    command VARCHAR(20) NOT NULL DEFAULT 'create' CHECK(command IN ('create','renew','transfer','restore','autoRenew')),
    domain_name VARCHAR(68) NOT NULL,
    length_in_months INTEGER NOT NULL,
    fromS DATETIME NOT NULL,
    toS DATETIME NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    FOREIGN KEY (registrar_id) REFERENCES registrar(id)
);

-- invoices
CREATE TABLE IF NOT EXISTS invoices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    registrar_id INTEGER,
    invoice_number VARCHAR(25) DEFAULT NULL,
    billing_contact_id INTEGER,
    issue_date DATETIME,
    due_date DATETIME DEFAULT NULL,
    total_amount DECIMAL(10,2),
    payment_status VARCHAR(20) NOT NULL DEFAULT 'unpaid' CHECK(payment_status IN ('unpaid','paid','overdue','cancelled')),
    notes TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (registrar_id) REFERENCES registrar(id),
    FOREIGN KEY (billing_contact_id) REFERENCES registrar_contact(id)
);

-- contact
CREATE TABLE IF NOT EXISTS contact (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    identifier VARCHAR(255) NOT NULL,
    voice VARCHAR(17) DEFAULT NULL,
    voice_x INTEGER DEFAULT NULL,
    fax VARCHAR(17) DEFAULT NULL,
    fax_x INTEGER DEFAULT NULL,
    email VARCHAR(255) NOT NULL,
    nin VARCHAR(255) DEFAULT NULL,
    nin_type VARCHAR(20) DEFAULT NULL CHECK(nin_type IN ('personal','business')),
    clid INTEGER NOT NULL,
    crid INTEGER NOT NULL,
    crdate DATETIME NOT NULL,
    upid INTEGER DEFAULT NULL,
    lastupdate DATETIME DEFAULT NULL,
    trdate DATETIME DEFAULT NULL,
    trstatus VARCHAR(50) DEFAULT NULL CHECK(trstatus IN ('clientApproved','clientCancelled','clientRejected','pending','serverApproved','serverCancelled')),
    reid INTEGER DEFAULT NULL,
    redate DATETIME DEFAULT NULL,
    acid INTEGER DEFAULT NULL,
    acdate DATETIME DEFAULT NULL,
    disclose_voice VARCHAR(2) NOT NULL DEFAULT '1' CHECK(disclose_voice IN ('0','1')),
    disclose_fax VARCHAR(2) NOT NULL DEFAULT '1' CHECK(disclose_fax IN ('0','1')),
    disclose_email VARCHAR(2) NOT NULL DEFAULT '1' CHECK(disclose_email IN ('0','1')),
    validation VARCHAR(2) DEFAULT NULL CHECK(validation IN ('0','1','2','3','4')),
    validation_stamp DATETIME DEFAULT NULL,
    validation_log VARCHAR(255) DEFAULT NULL,
    UNIQUE (identifier),
    FOREIGN KEY (clid) REFERENCES registrar(id),
    FOREIGN KEY (crid) REFERENCES registrar(id),
    FOREIGN KEY (upid) REFERENCES registrar(id)
);

-- contact_postalInfo
CREATE TABLE IF NOT EXISTS contact_postalInfo (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    contact_id INTEGER NOT NULL,
    type VARCHAR(10) NOT NULL DEFAULT 'int' CHECK(type IN ('int','loc')),
    name VARCHAR(255) NOT NULL,
    org VARCHAR(255) DEFAULT NULL,
    street1 VARCHAR(255) DEFAULT NULL,
    street2 VARCHAR(255) DEFAULT NULL,
    street3 VARCHAR(255) DEFAULT NULL,
    city VARCHAR(255) NOT NULL,
    sp VARCHAR(255) DEFAULT NULL,
    pc VARCHAR(16) DEFAULT NULL,
    cc CHAR(2) NOT NULL,
    disclose_name_int VARCHAR(2) NOT NULL DEFAULT '1' CHECK(disclose_name_int IN ('0','1')),
    disclose_name_loc VARCHAR(2) NOT NULL DEFAULT '1' CHECK(disclose_name_loc IN ('0','1')),
    disclose_org_int VARCHAR(2) NOT NULL DEFAULT '1' CHECK(disclose_org_int IN ('0','1')),
    disclose_org_loc VARCHAR(2) NOT NULL DEFAULT '1' CHECK(disclose_org_loc IN ('0','1')),
    disclose_addr_int VARCHAR(2) NOT NULL DEFAULT '1' CHECK(disclose_addr_int IN ('0','1')),
    disclose_addr_loc VARCHAR(2) NOT NULL DEFAULT '1' CHECK(disclose_addr_loc IN ('0','1')),
    UNIQUE (contact_id, type),
    FOREIGN KEY (contact_id) REFERENCES contact(id)
);

-- contact_authInfo
CREATE TABLE IF NOT EXISTS contact_authInfo (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    contact_id INTEGER NOT NULL,
    authtype VARCHAR(5) NOT NULL DEFAULT 'pw' CHECK(authtype IN ('pw','ext')),
    authinfo VARCHAR(64) NOT NULL,
    UNIQUE (contact_id),
    FOREIGN KEY (contact_id) REFERENCES contact(id)
);

-- contact_status
CREATE TABLE IF NOT EXISTS contact_status (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    contact_id INTEGER NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'ok' CHECK(status IN (
      'clientDeleteProhibited','clientTransferProhibited','clientUpdateProhibited',
      'linked','ok','pendingCreate','pendingDelete','pendingTransfer','pendingUpdate',
      'serverDeleteProhibited','serverTransferProhibited','serverUpdateProhibited')),
    UNIQUE (contact_id, status),
    FOREIGN KEY (contact_id) REFERENCES contact(id)
);

-- domain
CREATE TABLE IF NOT EXISTS domain (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(68) NOT NULL,
    tldid INTEGER NOT NULL,
    registrant INTEGER DEFAULT NULL,
    crdate DATETIME NOT NULL,
    exdate DATETIME NOT NULL,
    lastupdate DATETIME DEFAULT NULL,
    clid INTEGER NOT NULL,
    crid INTEGER NOT NULL,
    upid INTEGER DEFAULT NULL,
    trdate DATETIME DEFAULT NULL,
    trstatus VARCHAR(50) DEFAULT NULL CHECK(trstatus IN ('clientApproved','clientCancelled','clientRejected','pending','serverApproved','serverCancelled')),
    reid INTEGER DEFAULT NULL,
    redate DATETIME DEFAULT NULL,
    acid INTEGER DEFAULT NULL,
    acdate DATETIME DEFAULT NULL,
    transfer_exdate DATETIME DEFAULT NULL,
    idnlang VARCHAR(16) DEFAULT NULL,
    delTime DATETIME DEFAULT NULL,
    resTime DATETIME DEFAULT NULL,
    rgpstatus VARCHAR(50) DEFAULT NULL CHECK(rgpstatus IN (
      'addPeriod','autoRenewPeriod','renewPeriod','transferPeriod',
      'pendingDelete','pendingRestore','redemptionPeriod')),
    rgppostData TEXT DEFAULT NULL,
    rgpdelTime DATETIME DEFAULT NULL,
    rgpresTime DATETIME DEFAULT NULL,
    rgpresReason TEXT DEFAULT NULL,
    rgpstatement1 TEXT DEFAULT NULL,
    rgpstatement2 TEXT DEFAULT NULL,
    rgpother TEXT DEFAULT NULL,
    addPeriod INTEGER DEFAULT NULL,
    autoRenewPeriod INTEGER DEFAULT NULL,
    renewPeriod INTEGER DEFAULT NULL,
    transferPeriod INTEGER DEFAULT NULL,
    renewedDate DATETIME DEFAULT NULL,
    agp_exempted INTEGER DEFAULT 0,
    agp_request DATETIME DEFAULT NULL,
    agp_grant DATETIME DEFAULT NULL,
    agp_reason TEXT DEFAULT NULL,
    agp_status VARCHAR(30) DEFAULT NULL,
    tm_notice_accepted DATETIME DEFAULT NULL,
    tm_notice_expires DATETIME DEFAULT NULL,
    tm_notice_id VARCHAR(150) DEFAULT NULL,
    tm_notice_validator VARCHAR(30) DEFAULT NULL,
    tm_smd_id TEXT DEFAULT NULL,
    tm_phase TEXT NOT NULL DEFAULT 'NONE',
    phase_name VARCHAR(75) DEFAULT NULL,
    UNIQUE (name),
    FOREIGN KEY (clid) REFERENCES registrar(id),
    FOREIGN KEY (crid) REFERENCES registrar(id),
    FOREIGN KEY (upid) REFERENCES registrar(id),
    FOREIGN KEY (registrant) REFERENCES contact(id),
    FOREIGN KEY (reid) REFERENCES registrar(id),
    FOREIGN KEY (acid) REFERENCES registrar(id),
    FOREIGN KEY (tldid) REFERENCES domain_tld(id)
);
CREATE INDEX idx_domain_crdate ON domain (crdate);
CREATE INDEX idx_domain_exdate ON domain (exdate);
CREATE INDEX idx_support_tickets_date_created ON support_tickets (date_created);

-- application
CREATE TABLE IF NOT EXISTS application (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(68) NOT NULL,
    tldid INTEGER NOT NULL,
    registrant INTEGER DEFAULT NULL,
    crdate DATETIME NOT NULL,
    exdate DATETIME DEFAULT NULL,
    lastupdate DATETIME DEFAULT NULL,
    clid INTEGER NOT NULL,
    crid INTEGER NOT NULL,
    upid INTEGER DEFAULT NULL,
    trdate DATETIME DEFAULT NULL,
    trstatus VARCHAR(50) DEFAULT NULL CHECK(trstatus IN ('clientApproved','clientCancelled','clientRejected','pending','serverApproved','serverCancelled')),
    reid INTEGER DEFAULT NULL,
    redate DATETIME DEFAULT NULL,
    acid INTEGER DEFAULT NULL,
    acdate DATETIME DEFAULT NULL,
    transfer_exdate DATETIME DEFAULT NULL,
    idnlang VARCHAR(16) DEFAULT NULL,
    delTime DATETIME DEFAULT NULL,
    application_id VARCHAR(36) DEFAULT NULL,
    authtype VARCHAR(5) NOT NULL DEFAULT 'pw' CHECK(authtype IN ('pw','ext')),
    authinfo VARCHAR(64) NOT NULL,
    phase_name VARCHAR(75) DEFAULT NULL,
    phase_type VARCHAR(50) NOT NULL,
    smd TEXT DEFAULT NULL,
    tm_notice_accepted DATETIME DEFAULT NULL,
    tm_notice_expires DATETIME DEFAULT NULL,
    tm_notice_id VARCHAR(150) DEFAULT NULL,
    tm_notice_validator VARCHAR(30) DEFAULT NULL,
    tm_smd_id TEXT DEFAULT NULL,
    tm_phase TEXT NOT NULL DEFAULT 'NONE',
    FOREIGN KEY (clid) REFERENCES registrar(id),
    FOREIGN KEY (crid) REFERENCES registrar(id),
    FOREIGN KEY (upid) REFERENCES registrar(id),
    FOREIGN KEY (registrant) REFERENCES contact(id),
    FOREIGN KEY (reid) REFERENCES registrar(id),
    FOREIGN KEY (acid) REFERENCES registrar(id),
    FOREIGN KEY (tldid) REFERENCES domain_tld(id)
);

-- domain_contact_map
CREATE TABLE IF NOT EXISTS domain_contact_map (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    domain_id INTEGER NOT NULL,
    contact_id INTEGER NOT NULL,
    type VARCHAR(10) NOT NULL DEFAULT 'admin' CHECK(type IN ('admin','billing','tech')),
    UNIQUE (domain_id, contact_id, type),
    FOREIGN KEY (domain_id) REFERENCES domain(id),
    FOREIGN KEY (contact_id) REFERENCES contact(id)
);

-- application_contact_map
CREATE TABLE IF NOT EXISTS application_contact_map (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    domain_id INTEGER NOT NULL,
    contact_id INTEGER NOT NULL,
    type VARCHAR(10) NOT NULL DEFAULT 'admin' CHECK(type IN ('admin','billing','tech')),
    UNIQUE (domain_id, contact_id, type),
    FOREIGN KEY (domain_id) REFERENCES application(id),
    FOREIGN KEY (contact_id) REFERENCES contact(id)
);

-- domain_authInfo
CREATE TABLE IF NOT EXISTS domain_authInfo (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    domain_id INTEGER NOT NULL,
    authtype VARCHAR(5) NOT NULL DEFAULT 'pw' CHECK(authtype IN ('pw','ext')),
    authinfo VARCHAR(64) NOT NULL,
    UNIQUE (domain_id),
    FOREIGN KEY (domain_id) REFERENCES domain(id)
);

-- domain_status
CREATE TABLE IF NOT EXISTS domain_status (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    domain_id INTEGER NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'ok' CHECK(status IN (
      'clientDeleteProhibited','clientHold','clientRenewProhibited','clientTransferProhibited',
      'clientUpdateProhibited','inactive','ok','pendingCreate','pendingDelete','pendingRenew',
      'pendingTransfer','pendingUpdate','serverDeleteProhibited','serverHold','serverRenewProhibited',
      'serverTransferProhibited','serverUpdateProhibited')),
    UNIQUE (domain_id, status),
    FOREIGN KEY (domain_id) REFERENCES domain(id)
);

-- application_status
CREATE TABLE IF NOT EXISTS application_status (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    domain_id INTEGER NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'pendingValidation' CHECK(status IN ('pendingValidation','validated','invalid','pendingAllocation','allocated','rejected','custom')),
    UNIQUE (domain_id, status),
    FOREIGN KEY (domain_id) REFERENCES application(id)
);

-- secdns
CREATE TABLE IF NOT EXISTS secdns (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    domain_id INTEGER NOT NULL,
    maxsiglife INTEGER DEFAULT 604800,
    interface VARCHAR(10) NOT NULL DEFAULT 'dsData' CHECK(interface IN ('dsData','keyData')),
    keytag INTEGER NOT NULL,
    alg INTEGER NOT NULL DEFAULT 5,
    digesttype INTEGER NOT NULL DEFAULT 1,
    digest VARCHAR(64) NOT NULL,
    flags INTEGER DEFAULT NULL,
    protocol INTEGER DEFAULT NULL,
    keydata_alg INTEGER DEFAULT NULL,
    pubkey VARCHAR(255) DEFAULT NULL,
    UNIQUE (domain_id, digest),
    FOREIGN KEY (domain_id) REFERENCES domain(id)
);

-- host
CREATE TABLE IF NOT EXISTS host (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(255) NOT NULL,
    domain_id INTEGER DEFAULT NULL,
    clid INTEGER NOT NULL,
    crid INTEGER NOT NULL,
    crdate DATETIME NOT NULL,
    upid INTEGER DEFAULT NULL,
    lastupdate DATETIME DEFAULT NULL,
    trdate DATETIME DEFAULT NULL,
    UNIQUE (name),
    FOREIGN KEY (clid) REFERENCES registrar(id),
    FOREIGN KEY (crid) REFERENCES registrar(id),
    FOREIGN KEY (upid) REFERENCES registrar(id),
    FOREIGN KEY (domain_id) REFERENCES domain(id)
);

-- domain_host_map
CREATE TABLE IF NOT EXISTS domain_host_map (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    domain_id INTEGER NOT NULL,
    host_id INTEGER NOT NULL,
    UNIQUE (domain_id, host_id),
    FOREIGN KEY (domain_id) REFERENCES domain(id),
    FOREIGN KEY (host_id) REFERENCES host(id)
);

-- application_host_map
CREATE TABLE IF NOT EXISTS application_host_map (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    domain_id INTEGER NOT NULL,
    host_id INTEGER NOT NULL,
    UNIQUE (domain_id, host_id),
    FOREIGN KEY (domain_id) REFERENCES application(id),
    FOREIGN KEY (host_id) REFERENCES host(id)
);

-- host_addr
CREATE TABLE IF NOT EXISTS host_addr (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    host_id INTEGER NOT NULL,
    addr VARCHAR(45) NOT NULL,
    ip VARCHAR(5) NOT NULL DEFAULT 'v4' CHECK(ip IN ('v4','v6')),
    UNIQUE (host_id, addr, ip),
    FOREIGN KEY (host_id) REFERENCES host(id)
);

-- host_status
CREATE TABLE IF NOT EXISTS host_status (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    host_id INTEGER NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'ok' CHECK(status IN (
      'clientDeleteProhibited','clientUpdateProhibited','linked','ok','pendingCreate',
      'pendingDelete','pendingTransfer','pendingUpdate','serverDeleteProhibited','serverUpdateProhibited')),
    UNIQUE (host_id, status),
    FOREIGN KEY (host_id) REFERENCES host(id)
);

-- domain_auto_approve_transfer
CREATE TABLE IF NOT EXISTS domain_auto_approve_transfer (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(68) NOT NULL,
    registrant INTEGER DEFAULT NULL,
    crdate DATETIME NOT NULL,
    exdate DATETIME NOT NULL,
    lastupdate DATETIME DEFAULT NULL,
    clid INTEGER NOT NULL,
    crid INTEGER NOT NULL,
    upid INTEGER DEFAULT NULL,
    trdate DATETIME DEFAULT NULL,
    trstatus VARCHAR(50) DEFAULT NULL CHECK(trstatus IN ('clientApproved','clientCancelled','clientRejected','pending','serverApproved','serverCancelled')),
    reid INTEGER DEFAULT NULL,
    redate DATETIME DEFAULT NULL,
    acid INTEGER DEFAULT NULL,
    acdate DATETIME DEFAULT NULL
);

-- contact_auto_approve_transfer
CREATE TABLE IF NOT EXISTS contact_auto_approve_transfer (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    identifier VARCHAR(255) NOT NULL,
    voice VARCHAR(17) DEFAULT NULL,
    voice_x INTEGER DEFAULT NULL,
    fax VARCHAR(17) DEFAULT NULL,
    fax_x INTEGER DEFAULT NULL,
    email VARCHAR(255) NOT NULL,
    nin VARCHAR(255) DEFAULT NULL,
    nin_type VARCHAR(20) DEFAULT NULL CHECK(nin_type IN ('personal','business')),
    clid INTEGER NOT NULL,
    crid INTEGER NOT NULL,
    crdate DATETIME NOT NULL,
    upid INTEGER DEFAULT NULL,
    lastupdate DATETIME DEFAULT NULL,
    trdate DATETIME DEFAULT NULL,
    trstatus VARCHAR(50) DEFAULT NULL CHECK(trstatus IN ('clientApproved','clientCancelled','clientRejected','pending','serverApproved','serverCancelled')),
    reid INTEGER DEFAULT NULL,
    redate DATETIME DEFAULT NULL,
    acid INTEGER DEFAULT NULL,
    acdate DATETIME DEFAULT NULL,
    disclose_voice VARCHAR(2) NOT NULL DEFAULT '1' CHECK(disclose_voice IN ('0','1')),
    disclose_fax VARCHAR(2) NOT NULL DEFAULT '1' CHECK(disclose_fax IN ('0','1')),
    disclose_email VARCHAR(2) NOT NULL DEFAULT '1' CHECK(disclose_email IN ('0','1'))
);

-- statistics
CREATE TABLE IF NOT EXISTS statistics (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    date DATE NOT NULL,
    total_domains INTEGER NOT NULL DEFAULT 0,
    created_domains INTEGER NOT NULL DEFAULT 0,
    renewed_domains INTEGER NOT NULL DEFAULT 0,
    transfered_domains INTEGER NOT NULL DEFAULT 0,
    deleted_domains INTEGER NOT NULL DEFAULT 0,
    restored_domains INTEGER NOT NULL DEFAULT 0,
    UNIQUE (date)
);

-- users
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email VARCHAR(249) NOT NULL,
    password VARCHAR(255) NOT NULL,
    username VARCHAR(100) DEFAULT NULL,
    status INTEGER NOT NULL DEFAULT 0,
    verified INTEGER NOT NULL DEFAULT 0,
    resettable INTEGER NOT NULL DEFAULT 1,
    roles_mask INTEGER NOT NULL DEFAULT 0,
    registered INTEGER NOT NULL,
    last_login INTEGER DEFAULT NULL,
    force_logout INTEGER NOT NULL DEFAULT 0,
    tfa_secret VARCHAR(32),
    tfa_enabled INTEGER DEFAULT 0,
    auth_method VARCHAR(10) NOT NULL DEFAULT 'password' CHECK(auth_method IN ('password','2fa','webauthn')),
    backup_codes TEXT,
    password_last_updated DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (email)
);

-- users_audit
CREATE TABLE IF NOT EXISTS users_audit (
    user_id INTEGER NOT NULL,
    user_event VARCHAR(255) NOT NULL,
    user_resource VARCHAR(255) DEFAULT NULL,
    user_agent VARCHAR(255) NOT NULL,
    user_ip VARCHAR(45) NOT NULL,
    user_location VARCHAR(45) DEFAULT NULL,
    event_time DATETIME NOT NULL,
    user_data TEXT DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
CREATE INDEX IF NOT EXISTS idx_users_audit_user_id ON users_audit(user_id);
CREATE INDEX IF NOT EXISTS idx_users_audit_user_event ON users_audit(user_event);
CREATE INDEX IF NOT EXISTS idx_users_audit_user_ip ON users_audit(user_ip);

-- users_confirmations
CREATE TABLE IF NOT EXISTS users_confirmations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    email VARCHAR(249) NOT NULL,
    selector VARCHAR(16) NOT NULL,
    token VARCHAR(255) NOT NULL,
    expires INTEGER NOT NULL,
    UNIQUE (selector),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
CREATE INDEX IF NOT EXISTS idx_email_expires ON users_confirmations(email, expires);
CREATE INDEX IF NOT EXISTS idx_users_confirmations_user_id ON users_confirmations(user_id);

-- users_remembered
CREATE TABLE IF NOT EXISTS users_remembered (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    selector VARCHAR(24) NOT NULL,
    token VARCHAR(255) NOT NULL,
    expires INTEGER NOT NULL,
    UNIQUE (selector),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
CREATE INDEX IF NOT EXISTS idx_users_remembered_user_id ON users_remembered(user_id);

-- users_resets
CREATE TABLE IF NOT EXISTS users_resets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    selector VARCHAR(20) NOT NULL,
    token VARCHAR(255) NOT NULL,
    expires INTEGER NOT NULL,
    UNIQUE (selector),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
CREATE INDEX IF NOT EXISTS idx_user_expires ON users_resets(user_id, expires);

-- users_throttling
CREATE TABLE IF NOT EXISTS users_throttling (
    bucket VARCHAR(44) NOT NULL PRIMARY KEY,
    tokens REAL NOT NULL,
    replenished_at INTEGER NOT NULL,
    expires_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_users_throttling_expires_at ON users_throttling(expires_at);

-- users_webauthn
CREATE TABLE IF NOT EXISTS users_webauthn (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    credential_id BLOB NOT NULL,
    public_key TEXT NOT NULL,
    attestation_object BLOB,
    sign_count INTEGER NOT NULL,
    user_agent VARCHAR(512),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- registrar_users
CREATE TABLE IF NOT EXISTS registrar_users (
    registrar_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    PRIMARY KEY (registrar_id, user_id),
    FOREIGN KEY (registrar_id) REFERENCES registrar(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- urs_actions
CREATE TABLE IF NOT EXISTS urs_actions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    domain_name VARCHAR(255) NOT NULL,
    urs_provider VARCHAR(255) NOT NULL,
    action_date DATE NOT NULL,
    status VARCHAR(255) NOT NULL
);

-- rde_escrow_deposits
CREATE TABLE IF NOT EXISTS rde_escrow_deposits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    deposit_id VARCHAR(255) DEFAULT NULL,
    deposit_date DATE NOT NULL,
    revision INTEGER NOT NULL DEFAULT 1,
    file_name VARCHAR(255) NOT NULL,
    file_format VARCHAR(10) NOT NULL CHECK(file_format IN ('XML','CSV')),
    file_size INTEGER,
    checksum VARCHAR(64),
    encryption_method VARCHAR(255),
    deposit_type VARCHAR(20) NOT NULL CHECK(deposit_type IN ('Full','Incremental','Differential','BRDA')),
    status VARCHAR(20) NOT NULL DEFAULT 'Deposited' CHECK(status IN ('Deposited','Retrieved','Failed')),
    receiver VARCHAR(255),
    notes TEXT DEFAULT NULL,
    verification_status VARCHAR(20) DEFAULT 'Pending' CHECK(verification_status IN ('Verified','Failed','Pending')),
    verification_notes TEXT DEFAULT NULL,
    UNIQUE (deposit_id, deposit_type, file_name)
);

-- icann_reports
CREATE TABLE IF NOT EXISTS icann_reports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    report_date DATE NOT NULL,
    type VARCHAR(255) NOT NULL,
    file_name VARCHAR(255),
    submitted_date DATE,
    status VARCHAR(20) NOT NULL DEFAULT 'Pending' CHECK(status IN ('Pending','Submitted','Accepted','Rejected')),
    notes TEXT
);

-- promotion_pricing
CREATE TABLE IF NOT EXISTS promotion_pricing (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tld_id INTEGER DEFAULT NULL,
    promo_name VARCHAR(255) NOT NULL,
    start_date DATETIME NOT NULL,
    end_date DATETIME NOT NULL,
    discount_percentage DECIMAL(5,2) DEFAULT NULL,
    discount_amount DECIMAL(10,2) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    conditions TEXT DEFAULT NULL,
    promo_type VARCHAR(20) NOT NULL CHECK(promo_type IN ('full','registration','renewal','transfer')),
    years_of_promotion INTEGER DEFAULT NULL,
    max_count INTEGER DEFAULT NULL,
    registrar_ids TEXT DEFAULT NULL,
    status VARCHAR(20) NOT NULL CHECK(status IN ('active','expired','upcoming')),
    minimum_purchase DECIMAL(10,2) DEFAULT NULL,
    target_segment VARCHAR(255) DEFAULT NULL,
    region_specific VARCHAR(255) DEFAULT NULL,
    created_by VARCHAR(255) DEFAULT NULL,
    created_at DATETIME DEFAULT NULL,
    updated_by VARCHAR(255) DEFAULT NULL,
    updated_at DATETIME DEFAULT NULL,
    FOREIGN KEY (tld_id) REFERENCES domain_tld(id)
);
CREATE INDEX IF NOT EXISTS idx_promotion_pricing_tld_id ON promotion_pricing(tld_id);

-- premium_domain_categories
CREATE TABLE IF NOT EXISTS premium_domain_categories (
    category_id INTEGER PRIMARY KEY AUTOINCREMENT,
    category_name VARCHAR(255) NOT NULL,
    category_price DECIMAL(10,2) NOT NULL,
    UNIQUE (category_name)
);

-- premium_domain_pricing
CREATE TABLE IF NOT EXISTS premium_domain_pricing (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    domain_name VARCHAR(255) NOT NULL,
    tld_id INTEGER NOT NULL,
    category_id INTEGER DEFAULT NULL,
    FOREIGN KEY (tld_id) REFERENCES domain_tld(id),
    FOREIGN KEY (category_id) REFERENCES premium_domain_categories(category_id)
);
CREATE INDEX IF NOT EXISTS idx_domainname_tldid ON premium_domain_pricing(domain_name, tld_id);

-- ticket_categories
CREATE TABLE IF NOT EXISTS ticket_categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(255) NOT NULL,
    description TEXT
);

-- support_tickets
CREATE TABLE IF NOT EXISTS support_tickets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    category_id INTEGER NOT NULL,
    subject VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Open' CHECK(status IN ('Open','In Progress','Resolved','Closed')),
    priority VARCHAR(20) NOT NULL DEFAULT 'Medium' CHECK(priority IN ('Low','Medium','High','Critical')),
    reported_domain VARCHAR(255) DEFAULT NULL,
    nature_of_abuse TEXT DEFAULT NULL,
    evidence TEXT DEFAULT NULL,
    relevant_urls TEXT DEFAULT NULL,
    date_of_incident DATE DEFAULT NULL,
    date_created DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_updated DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (category_id) REFERENCES ticket_categories(id)
);

-- ticket_responses
CREATE TABLE IF NOT EXISTS ticket_responses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ticket_id INTEGER NOT NULL,
    responder_id INTEGER NOT NULL,
    response TEXT NOT NULL,
    date_created DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ticket_id) REFERENCES support_tickets(id)
);

-- tmch_claims
CREATE TABLE IF NOT EXISTS tmch_claims (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    domain_label VARCHAR(100) NOT NULL,
    claim_key VARCHAR(200) NOT NULL,
    insert_time DATETIME NOT NULL,
    UNIQUE (domain_label, claim_key)
);

-- tmch_revocation
CREATE TABLE IF NOT EXISTS tmch_revocation (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    smd_id VARCHAR(100) NOT NULL,
    revocation_time DATETIME NOT NULL,
    UNIQUE (smd_id)
);

-- tmch_crl
CREATE TABLE IF NOT EXISTS tmch_crl (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    content TEXT NOT NULL,
    url VARCHAR(255) NOT NULL,
    update_timestamp DATETIME NOT NULL
);

-- transaction_identifier
CREATE TABLE IF NOT EXISTS transaction_identifier (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    registrar_id INTEGER NOT NULL,
    clTRID VARCHAR(64),
    clTRIDframe TEXT,
    cldate DATETIME,
    clmicrosecond INTEGER,
    cmd VARCHAR(20) DEFAULT NULL CHECK(cmd IN ('login','logout','check','info','poll','transfer','create','delete','renew','update')),
    obj_type VARCHAR(20) DEFAULT NULL CHECK(obj_type IN ('domain','host','contact')),
    obj_id TEXT DEFAULT NULL,
    code INTEGER,
    msg VARCHAR(255) DEFAULT NULL,
    svTRID VARCHAR(64),
    svTRIDframe TEXT,
    svdate DATETIME,
    svmicrosecond INTEGER,
    UNIQUE (clTRID),
    UNIQUE (svTRID),
    FOREIGN KEY (registrar_id) REFERENCES registrar(id)
);

