CREATE TABLE transaction_identifier (
    id BIGSERIAL PRIMARY KEY,
    registrar_id INT NOT NULL,
    clTRID VARCHAR(64),
    clTRIDframe TEXT,
    cldate TIMESTAMP(3) WITHOUT TIME ZONE,
    clmicrosecond INT,
    cmd VARCHAR(10) CHECK (cmd IN ('login','logout','check','info','poll','transfer','create','delete','renew','update')),
    obj_type VARCHAR(10) CHECK (obj_type IN ('domain','host','contact')),
    obj_id TEXT,
    code SMALLINT,
    msg VARCHAR(255),
    svTRID VARCHAR(64),
    svTRIDframe TEXT,
    svdate TIMESTAMP(3) WITHOUT TIME ZONE,
    svmicrosecond INT,
    CONSTRAINT unique_clTRID UNIQUE (clTRID),
    CONSTRAINT unique_svTRID UNIQUE (svTRID)
);