CREATE TABLE IF NOT EXISTS merchants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(40) NOT NULL,
    name VARCHAR(120) NOT NULL,
    country VARCHAR(40) NOT NULL DEFAULT '',
    dial_code VARCHAR(5) NOT NULL DEFAULT '',
    currency VARCHAR(5) NOT NULL DEFAULT '',
    -- NULL until the merchant sets one. It has to be NULL rather than empty: the
    -- address is unique, and only NULL may repeat, so more than one merchant can
    -- exist before its webhook does.
    webhook_url VARCHAR(255) DEFAULT NULL,
    webhook_secret VARCHAR(80) NOT NULL DEFAULT '',
    -- The zone this merchant reads times in. Set from the dashboard or from a
    -- listener phone; both write the same value, so every screen agrees.
    timezone VARCHAR(64) NOT NULL DEFAULT '',
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
    KEY idx_key_merchant (merchant_id, status)
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
    KEY idx_dev_merchant (merchant_id),
    KEY idx_dev_active (merchant_id, status, id)
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
    KEY idx_int_match (merchant_id, payer_key, status, amount, currency, expires_at),
    KEY idx_int_reuse (merchant_id, reference, payer_key, status, amount, expires_at)
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
    KEY idx_pay_feed (merchant_id, id),
    KEY idx_pay_payer (merchant_id, payer_key, status, kind, reversed, amount, currency, received_at),
    KEY idx_pay_trxid (merchant_id, trx_id, kind, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every message a listener reports, whether or not it became a payment.
-- Without this a message the gateway could not read left no trace at all, so
-- nobody could see what a network actually sends. It is also what makes a
-- customer's "I paid and nothing happened" answerable.
CREATE TABLE IF NOT EXISTS device_messages (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    merchant_id INT NOT NULL,
    device_id INT NOT NULL DEFAULT 0,
    sender VARCHAR(100) NOT NULL DEFAULT '',
    body TEXT,
    sms_time DATETIME NULL,
    received_at DATETIME NOT NULL,
    -- recorded, duplicate, reversal, unknown_sender, not_a_payment, ignored
    outcome VARCHAR(20) NOT NULL DEFAULT '',
    payment_id INT NOT NULL DEFAULT 0,
    -- what the gateway managed to read out of it, for comparing against the text
    read_trx VARCHAR(64) NOT NULL DEFAULT '',
    read_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    read_currency VARCHAR(5) NOT NULL DEFAULT '',
    read_name VARCHAR(100) NOT NULL DEFAULT '',
    read_msisdn VARCHAR(20) NOT NULL DEFAULT '',
    KEY idx_msg_feed (merchant_id, id),
    KEY idx_msg_outcome (merchant_id, outcome, id),
    KEY idx_msg_age (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per unbroken stretch of a listener reporting in. The phone reports
-- every five minutes; a gap longer than GAP_MINUTES closes a row and the next
-- report opens another, so the spaces between rows are exactly the outages.
CREATE TABLE IF NOT EXISTS device_uptime (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    merchant_id INT NOT NULL,
    device_id INT NOT NULL,
    from_at DATETIME NOT NULL,
    to_at DATETIME NOT NULL,
    reports INT NOT NULL DEFAULT 1,
    KEY idx_uptime_device (device_id, id),
    KEY idx_uptime_merchant (merchant_id, to_at),
    KEY idx_uptime_age (to_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    merchant_id INT NOT NULL,
    payer_key VARCHAR(12) NOT NULL,
    reference VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL DEFAULT '',
    last_seen DATETIME NOT NULL,
    UNIQUE KEY uq_payer (merchant_id, payer_key, reference),
    KEY idx_payer_recent (merchant_id, payer_key, last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS claims (
    id INT AUTO_INCREMENT PRIMARY KEY,
    merchant_id INT NOT NULL,
    ip VARCHAR(45) NOT NULL DEFAULT '',
    trx_id VARCHAR(64) NOT NULL DEFAULT '',
    ok TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    KEY idx_claim_ip (merchant_id, ip, ok, created_at)
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
    KEY idx_wh_due (status, next_attempt_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS signups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_signup_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS portal_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    merchant_id INT NULL,
    email VARCHAR(190) NOT NULL,
    phone VARCHAR(20) NOT NULL DEFAULT '',
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'merchant',
    status VARCHAR(12) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    last_login_at DATETIME NULL,
    UNIQUE KEY uq_portal_email (email),
    KEY idx_portal_merchant (merchant_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS portal_sessions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    -- While a platform owner is looking at one merchant's workspace, that choice
    -- belongs to this browser session alone and ends when the session does.
    acting_merchant_id INT DEFAULT NULL,
    UNIQUE KEY uq_portal_token (token_hash),
    KEY idx_portal_session_user (user_id, expires_at),
    KEY idx_portal_session_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
