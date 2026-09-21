<?php
/** Explicit, repeatable upgrade for an existing gateway database. */
require dirname(__DIR__) . '/src/Db.php';
$pdo = Db::pdo(false);
$column = $pdo->query("SHOW COLUMNS FROM portal_users LIKE 'status'")->fetch();
if (!$column) throw new RuntimeException('portal_users.status is missing. Check the gateway database.');
$pdo->exec("CREATE TABLE IF NOT EXISTS portal_email_verifications (
    user_id INT NOT NULL PRIMARY KEY,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    sent_at DATETIME NOT NULL,
    UNIQUE KEY uq_email_token (token_hash),
    KEY idx_email_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "Email verification table ready. Existing accounts were not changed.\n";
