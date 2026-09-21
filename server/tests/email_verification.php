<?php
/** One-use verification behavior against a throwaway gateway_test_* MySQL database. */
date_default_timezone_set('UTC');
require __DIR__ . '/mysql_bootstrap.php';
class Config { public static function get($key, $default = null) { return $key === 'email_verification_enabled' ? true : ($key === 'brevo_api_key' ? 'xkeysib-test' : $default); } }
class Db {
    public static function pdo() { return TestDb::pdo(); }
    public static function row($sql, array $args = []) { $s = self::pdo()->prepare($sql); $s->execute($args); return $s->fetch() ?: null; }
    public static function run($sql, array $args = []) { $s = self::pdo()->prepare($sql); $s->execute($args); return $s->rowCount(); }
    public static function lastId() { return (int) self::pdo()->lastInsertId(); }
}
require dirname(__DIR__) . '/src/EmailVerification.php';
require dirname(__DIR__) . '/src/Gateway.php';
$schema = file_get_contents(dirname(__DIR__) . '/schema.sql');
foreach (['merchants', 'api_keys', 'portal_users', 'portal_email_verifications'] as $table) {
    if (!preg_match('/CREATE TABLE IF NOT EXISTS ' . $table . ' \([\s\S]*?\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;/', $schema, $m)) throw new RuntimeException('Missing ' . $table . ' schema');
    Db::pdo()->exec($m[0]);
}
$merchant = Gateway::createMerchant('New ISP', 'uganda', '256', 'UGX', '', false);
$merchantId = (int) $merchant['merchant']['id'];
Db::run("UPDATE merchants SET status='pending' WHERE id=?", [$merchantId]);
$key = Gateway::issueApiKey($merchantId);
Db::run("INSERT INTO portal_users (merchant_id,email,password_hash,status,created_at) VALUES (?,'new@example.test','hash','pending',NOW())", [$merchantId]);
$id = (int) Db::pdo()->lastInsertId();
function check($condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
check(EmailVerification::ready(), 'Configured email delivery should be ready');
$first = EmailVerification::token();
check(preg_match('/^[a-f0-9]{64}$/', $first) === 1, 'Token shape');
EmailVerification::saveToken($id, $first);
check(Db::row('SELECT status FROM portal_users WHERE id=?', [$id])['status'] === 'pending', 'Issuing a link must not activate an account');
check(Gateway::merchantByApiKey($key) === null, 'Unverified merchant key reached the live API');
$second = EmailVerification::token();
EmailVerification::saveToken($id, $second);
check(!EmailVerification::verify($first), 'Rotated link accepted');
check(!EmailVerification::verify('garbage'), 'Malformed link accepted');
check(EmailVerification::verify($second), 'Latest valid link not accepted');
check(!EmailVerification::verify($second), 'One-use link accepted twice');
check(Db::row('SELECT status FROM portal_users WHERE id=?', [$id])['status'] === 'active', 'Verified user is still pending');
check(Db::row('SELECT status FROM merchants WHERE id=?', [$merchantId])['status'] === 'active', 'Verified merchant is still pending');
check(Gateway::merchantByApiKey($key) !== null, 'Verified merchant key did not activate');
check(Db::row('SELECT user_id FROM portal_email_verifications WHERE user_id=?', [$id]) === null, 'Used link was retained');
Db::run("INSERT INTO portal_users (email,password_hash,status,created_at) VALUES ('expired@example.test','hash','pending',NOW())");
$expiredId = (int) Db::pdo()->lastInsertId();
$expired = EmailVerification::token();
EmailVerification::saveToken($expiredId, $expired);
Db::run('UPDATE portal_email_verifications SET expires_at=? WHERE user_id=?', [date('Y-m-d H:i:s', time()-1), $expiredId]);
check(!EmailVerification::verify($expired), 'Expired link accepted');
check(Db::row('SELECT status FROM portal_users WHERE id=?', [$expiredId])['status'] === 'pending', 'Expired link activated user');
echo "Email verification checks passed on disposable MySQL database.\n";
