CREATE TABLE IF NOT EXISTS merchants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(40) NOT NULL,
    name VARCHAR(120) NOT NULL,
    country VARCHAR(40) NOT NULL DEFAULT '',
    dial_code VARCHAR(5) NOT NULL DEFAULT '',
    currency VARCHAR(5) NOT NULL DEFAULT '',
    webhook_url VARCHAR(255) NOT NULL DEFAULT '',
    webhook_secret VARCHAR(80) NOT NULL DEFAULT '',
    status VARCHAR(10) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_mer_public (public_id),
    UNIQUE KEY uq_mer_webhook (webhook_url)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS api_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    merchant_id INT NOT NULL,
    key_hash CHAR(64) NOT NULL,
    hint VARCHAR(16) NOT NULL DEFAULT '',
    status VARCHAR(10) NOT NULL DEFAULT 'active',
    last_used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_key_hash (key_hash),
    KEY idx_key_merchant (merchant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(40) NOT NULL,
    merchant_id INT NOT NULL,
    label VARCHAR(80) NOT NULL DEFAULT '',
    provider VARCHAR(30) NOT NULL DEFAULT '',
    receiving_number VARCHAR(20) NOT NULL DEFAULT '',
    receiving_key VARCHAR(20) NOT NULL DEFAULT '',
    receiving_name VARCHAR(100) NOT NULL DEFAULT '',
    extra_senders VARCHAR(255) NOT NULL DEFAULT '',
    key_hash CHAR(64) NOT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'active',
    last_seen DATETIME NULL,
    last_ip VARCHAR(45) NOT NULL DEFAULT '',
    app_version VARCHAR(30) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_dev_public (public_id),
    UNIQUE KEY uq_dev_key (key_hash),
    KEY idx_dev_merchant (merchant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS intents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(40) NOT NULL,
    merchant_id INT NOT NULL,
    amount DECIMAL(18,2) NOT NULL,
    currency VARCHAR(5) NOT NULL DEFAULT '',
    payer_msisdn VARCHAR(20) NOT NULL DEFAULT '',
    payer_key VARCHAR(12) NOT NULL DEFAULT '',
    payer_name VARCHAR(100) NOT NULL DEFAULT '',
    reference VARCHAR(100) NOT NULL DEFAULT '',
    metadata TEXT,
    status VARCHAR(10) NOT NULL DEFAULT 'waiting',
    payment_id INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    UNIQUE KEY uq_int_public (public_id),
    KEY idx_int_match (merchant_id, payer_key, status, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(40) NOT NULL,
    merchant_id INT NOT NULL,
    device_id INT NOT NULL DEFAULT 0,
    source VARCHAR(20) NOT NULL DEFAULT 'direct_number',
    provider VARCHAR(30) NOT NULL DEFAULT '',
    receiving_key VARCHAR(20) NOT NULL DEFAULT '',
    trx_id VARCHAR(64) NOT NULL,
    kind VARCHAR(10) NOT NULL DEFAULT 'credit',
    amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    currency VARCHAR(5) NOT NULL DEFAULT '',
    payer_msisdn VARCHAR(20) NOT NULL DEFAULT '',
    payer_key VARCHAR(12) NOT NULL DEFAULT '',
    payer_name VARCHAR(100) NOT NULL DEFAULT '',
    sender VARCHAR(40) NOT NULL DEFAULT '',
    raw_message TEXT,
    parser VARCHAR(30) NOT NULL DEFAULT '',
    sms_time DATETIME NULL,
    received_at DATETIME NOT NULL,
    status VARCHAR(12) NOT NULL DEFAULT 'unmatched',
    hold_reason VARCHAR(40) NOT NULL DEFAULT '',
    match_rule VARCHAR(15) NOT NULL DEFAULT '',
    name_check VARCHAR(10) NOT NULL DEFAULT '',
    intent_id INT NOT NULL DEFAULT 0,
    reference VARCHAR(100) NOT NULL DEFAULT '',
    matched_at DATETIME NULL,
    reversed TINYINT(1) NOT NULL DEFAULT 0,
    reversed_at DATETIME NULL,
    UNIQUE KEY uq_pay_public (public_id),
    UNIQUE KEY uq_pay_trx (merchant_id, provider, receiving_key, trx_id),
    KEY idx_pay_list (merchant_id, status, id),
    KEY idx_pay_payer (merchant_id, payer_key),
    KEY idx_pay_trxid (merchant_id, trx_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    merchant_id INT NOT NULL,
    payer_key VARCHAR(12) NOT NULL,
    reference VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL DEFAULT '',
    last_seen DATETIME NOT NULL,
    UNIQUE KEY uq_payer (merchant_id, payer_key, reference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS claims (
    id INT AUTO_INCREMENT PRIMARY KEY,
    merchant_id INT NOT NULL,
    ip VARCHAR(45) NOT NULL DEFAULT '',
    trx_id VARCHAR(64) NOT NULL DEFAULT '',
    ok TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    KEY idx_claim_ip (merchant_id, ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS webhook_deliveries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id VARCHAR(40) NOT NULL,
    merchant_id INT NOT NULL,
    type VARCHAR(40) NOT NULL,
    payload MEDIUMTEXT NOT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'pending',
    attempts INT NOT NULL DEFAULT 0,
    last_code INT NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_wh_event (event_id),
    KEY idx_wh_due (status, next_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS signups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_signup_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
