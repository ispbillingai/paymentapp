<?php
/** Create or update an owner account without placing its password in source control. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/Db.php';
$email = strtolower(trim((string) ($argv[1] ?? '')));
$phone = preg_replace('/[^0-9+]/', '', (string) ($argv[2] ?? ''));
$password = (string) ($argv[3] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12 || strlen($password) > 128) {
    fwrite(STDERR, "Usage: php create-portal-user.php email phone password\nPassword must contain 12 to 128 characters.\n"); exit(2);
}
$hash = password_hash($password, PASSWORD_DEFAULT);
$existing = Db::row("SELECT id FROM portal_users WHERE email=?", [$email]);
if ($existing) {
    Db::run("UPDATE portal_users SET phone=?,password_hash=?,role='owner',status='active' WHERE id=?", [substr($phone,0,20),$hash,(int)$existing['id']]);
    Db::run("DELETE FROM portal_sessions WHERE user_id=?", [(int)$existing['id']]);
    echo "Owner account updated. Existing sessions were revoked.\n";
} else {
    Db::run("INSERT INTO portal_users (merchant_id,email,phone,password_hash,role,status,created_at) VALUES (NULL,?,?,?,'owner','active',?)", [$email,substr($phone,0,20),$hash,date('Y-m-d H:i:s')]);
    echo "Owner account created.\n";
}
