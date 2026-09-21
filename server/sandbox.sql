CREATE TABLE IF NOT EXISTS sandbox_accounts (
 user_id INT PRIMARY KEY, public_id VARCHAR(40) NOT NULL, created_at DATETIME NOT NULL,
 UNIQUE KEY uq_sandbox_public (public_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS sandbox_keys (
 id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, key_hash CHAR(64) NOT NULL,
 hint VARCHAR(20) NOT NULL, status VARCHAR(10) NOT NULL, created_at DATETIME NOT NULL,
 UNIQUE KEY uq_sandbox_key (key_hash), KEY idx_sandbox_key_user (user_id,status,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS sandbox_intents (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, public_id VARCHAR(40) NOT NULL, user_id INT NOT NULL,
 amount DECIMAL(18,2) NOT NULL, currency CHAR(3) NOT NULL, reference VARCHAR(100) NOT NULL,
 status VARCHAR(12) NOT NULL, idempotency_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_hash CHAR(64) NOT NULL, created_at DATETIME NOT NULL,
 UNIQUE KEY uq_sandbox_intent (public_id), UNIQUE KEY uq_sandbox_idempotency (user_id,idempotency_key),
 KEY idx_sandbox_intents (user_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS sandbox_events (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, public_id VARCHAR(40) NOT NULL, user_id INT NOT NULL,
 intent_id VARCHAR(40) NOT NULL, type VARCHAR(40) NOT NULL, payload TEXT NOT NULL, created_at DATETIME NOT NULL,
 UNIQUE KEY uq_sandbox_event (public_id), KEY idx_sandbox_events (user_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
