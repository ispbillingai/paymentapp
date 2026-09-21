<?php

/** Transactional email and one-use proof that the new merchant controls their address. */
final class EmailVerification
{
    public static function enabled(): bool
    {
        return Config::get('email_verification_enabled', false) === true;
    }

    public static function ready(): bool
    {
        return self::enabled() && function_exists('curl_init')
            && preg_match('/^xkeysib-[A-Za-z0-9_-]+$/', (string) Config::get('brevo_api_key', '')) === 1;
    }

    public static function token(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function saveToken(int $userId, string $token): void
    {
        $now = date('Y-m-d H:i:s');
        Db::run('INSERT INTO portal_email_verifications (user_id,token_hash,expires_at,sent_at) VALUES (?,?,?,?)
            ON DUPLICATE KEY UPDATE token_hash=VALUES(token_hash), expires_at=VALUES(expires_at), sent_at=VALUES(sent_at)',
            [$userId, hash('sha256', $token), date('Y-m-d H:i:s', time() + 86400), $now]);
    }

    public static function send(string $email, string $name, string $token): bool
    {
        if (!self::ready()) return false;
        // A fragment keeps the token out of web-server access logs and Referer headers.
        $url = 'https://ispbillingpay.com/verify-email#token=' . rawurlencode($token);
        $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $message = [
            'sender' => ['name' => 'ISP Billing Pay', 'email' => 'no-reply@ispbillingpay.com'],
            'to' => [['email' => $email]],
            'subject' => 'Verify your ISP Billing Pay email',
            'textContent' => "Hello {$name},\n\nVerify your email to activate your ISP Billing Pay account:\n{$url}\n\nThis link expires in 24 hours. If you did not create an account, you can ignore this email.",
            'htmlContent' => '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#193e33;line-height:1.6"><h1>Verify your email</h1><p>Hello ' . $safeName . ',</p><p>Confirm this address to activate your ISP Billing Pay account.</p><p><a style="background:#086653;color:#fff;padding:12px 18px;border-radius:8px;text-decoration:none" href="' . $safeUrl . '">Verify email</a></p><p>This link expires in 24 hours. If you did not create an account, you can ignore this email.</p></body></html>',
        ];
        $json = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) return false;
        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => ['accept: application/json', 'content-type: application/json', 'api-key: ' . Config::get('brevo_api_key')],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $data = is_string($response) ? json_decode($response, true) : null;
        if ($status === 201 && is_array($data) && !empty($data['messageId'])) return true;
        // Never log tokens, recipient addresses, API keys or provider response bodies.
        error_log('[gateway] verification email provider status ' . $status);
        return false;
    }

    public static function verify(string $token): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) return false;
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $row = Db::row('SELECT user_id FROM portal_email_verifications WHERE token_hash=? AND expires_at>? FOR UPDATE',
                [hash('sha256', $token), date('Y-m-d H:i:s')]);
            if (!$row) { $pdo->rollBack(); return false; }
            $n = Db::run("UPDATE portal_users SET status='active' WHERE id=? AND status='pending'", [(int) $row['user_id']]);
            Db::run('DELETE FROM portal_email_verifications WHERE user_id=?', [(int) $row['user_id']]);
            $pdo->commit();
            return $n === 1;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
