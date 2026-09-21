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
        return self::deliver(self::message($email, $name, $url, 'verify'));
    }

    /** A safe preview for an existing account: no unusable activation link. */
    public static function sendPreview(string $email): bool
    {
        if (!self::ready()) return false;
        return self::deliver(self::message($email, 'Francis', 'https://ispbillingpay.com/', 'preview'));
    }

    /** Credential alert contains no API key, signing secret or password. */
    public static function sendKeyCreated(string $email): bool
    {
        if (!self::ready()) return false;
        return self::deliver(self::message($email, 'there', 'https://ispbillingpay.com/dashboard/developers', 'key'));
    }

    private static function message(string $email, string $name, string $url, string $kind): array
    {
        $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if ($kind === 'preview') {
            $eyebrow = 'EMAIL DESIGN PREVIEW';
            $headline = 'A better welcome starts here.';
            $intro = 'This is a preview of the email new merchants receive when they create an ISP Billing Pay account.';
            $button = 'Explore ISP Billing Pay';
            $note = 'This is a design preview. Your existing account needs no action.';
            $closing = 'You received this preview because you requested an email design test.';
            $preheader = 'A preview of the new ISP Billing Pay account email.';
            $subject = 'A preview of your new ISP Billing Pay email';
        } elseif ($kind === 'key') {
            $eyebrow = 'ACCOUNT SECURITY';
            $headline = 'A new API key was created.';
            $intro = 'Someone signed in to your ISP Billing Pay workspace and created a new API key. The key itself is never included in email.';
            $button = 'Review API keys';
            $note = 'If this was you, no action is needed. Store the key shown in your dashboard securely.';
            $closing = 'Do not recognize this activity? Sign in, revoke the key, change your password, and contact support.';
            $preheader = 'A new API key was created for your ISP Billing Pay workspace.';
            $subject = 'New API key created for your ISP Billing Pay account';
        } else {
            $eyebrow = 'SECURE ACCOUNT ACCESS';
            $headline = 'Verify to go live.';
            $intro = 'Confirm your email address to activate live payments. You can sign in and set up API keys while you wait.';
            $button = 'Verify my email';
            $note = 'For your security, this link expires in 24 hours and works only once.';
            $closing = 'Did not create an account? You can safely ignore this message.';
            $preheader = 'Confirm your address to activate live payments.';
            $subject = 'Confirm your ISP Billing Pay account';
        }
        $html = <<<'HTML'
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ISP Billing Pay</title></head>
<body style="margin:0;padding:0;background:#f3f6f2;font-family:Arial,Helvetica,sans-serif;color:#183e35;">
<div style="display:none;font-size:1px;line-height:1px;color:#f3f6f2;max-height:0;max-width:0;opacity:0;overflow:hidden;">{{preheader}}</div>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f3f6f2;"><tr><td align="center" style="padding:28px 14px 36px;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:600px;">
<tr><td style="padding:0 4px 20px;"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td style="width:42px;height:42px;background:#116c57;border-radius:12px;color:#ffffff;text-align:center;font-size:27px;font-weight:800;line-height:42px;">P</td><td style="padding-left:12px;font-size:19px;line-height:22px;font-weight:800;letter-spacing:-.5px;color:#173c33;">ISP Billing <span style="color:#087b5c;">Pay</span><br><span style="font-size:9px;line-height:12px;font-weight:700;letter-spacing:2px;color:#617a70;">PAYMENT INFRASTRUCTURE</span></td></tr></table></td></tr>
<tr><td style="background:#173f34;border-radius:18px 18px 0 0;padding:34px 38px 32px;color:#ffffff;">
<div style="font-size:11px;font-weight:700;letter-spacing:2px;color:#bfebbb;">{{eyebrow}}</div>
<h1 style="margin:16px 0 12px;font-size:34px;line-height:1.12;letter-spacing:-1.4px;font-weight:700;color:#ffffff;">{{headline}}</h1>
<p style="margin:0;color:#d9e9df;font-size:15px;line-height:1.65;">A secure place for every payment, connection, and customer.</p>
</td></tr>
<tr><td style="background:#ffffff;padding:35px 38px 32px;border:1px solid #e2eae2;border-top:0;">
<p style="margin:0 0 14px;font-size:16px;line-height:1.5;font-weight:700;color:#173f34;">Hello {{name}},</p>
<p style="margin:0 0 26px;font-size:15px;line-height:1.7;color:#4a6258;">{{intro}}</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td style="background:#087b5c;border-radius:9px;"><a href="{{url}}" style="display:inline-block;padding:15px 23px;font-size:15px;line-height:20px;font-weight:700;text-decoration:none;color:#ffffff;">{{button}} &nbsp;→</a></td></tr></table>
<p style="margin:16px 0 0;font-size:13px;line-height:1.6;color:#6b8075;">{{note}}</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:30px;background:#f2f7ee;border-radius:10px;"><tr><td style="padding:18px 20px;"><p style="margin:0 0 5px;font-size:11px;font-weight:700;letter-spacing:1.4px;color:#267257;">BUILT FOR TRUST</p><p style="margin:0;font-size:13px;line-height:1.6;color:#496559;">Your payment operations stay in your control. We will never ask for your password by email.</p></td></tr></table>
</td></tr>
<tr><td style="background:#ffffff;border:1px solid #e2eae2;border-top:0;border-radius:0 0 18px 18px;padding:18px 38px;"><p style="margin:0;font-size:12px;line-height:1.6;color:#708277;">{{closing}}</p></td></tr>
<tr><td style="padding:24px 8px 0;text-align:center;"><p style="margin:0 0 8px;font-size:12px;line-height:1.6;color:#6b8075;">ISP Billing Pay · Direct payments, connected customers.</p><p style="margin:0;font-size:12px;"><a href="https://ispbillingpay.com/support" style="color:#176d53;text-decoration:underline;">Help centre</a> &nbsp;·&nbsp; <a href="https://ispbillingpay.com/security" style="color:#176d53;text-decoration:underline;">Security</a></p></td></tr>
</table></td></tr></table></body></html>
HTML;
        $html = strtr($html, [
            '{{preheader}}' => htmlspecialchars($preheader, ENT_QUOTES, 'UTF-8'),
            '{{eyebrow}}' => $eyebrow,
            '{{headline}}' => $headline,
            '{{name}}' => $safeName,
            '{{intro}}' => $intro,
            '{{url}}' => $safeUrl,
            '{{button}}' => $button,
            '{{note}}' => $note,
            '{{closing}}' => $closing,
        ]);
        $plain = "Hello {$name},\n\n{$intro}\n\n{$button}: {$url}\n\n{$note}\n\n{$closing}\n\nHelp: https://ispbillingpay.com/support";
        return [
            'sender' => ['name' => 'ISP Billing Pay', 'email' => 'no-reply@ispbillingpay.com'],
            'to' => [['email' => $email]],
            'subject' => $subject,
            'textContent' => $plain,
            'htmlContent' => $html,
        ];
    }

    private static function deliver(array $message): bool
    {
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
            $user = Db::row("SELECT merchant_id FROM portal_users WHERE id=? AND status='pending' FOR UPDATE", [(int) $row['user_id']]);
            if (!$user) { $pdo->rollBack(); return false; }
            $n = Db::run("UPDATE portal_users SET status='active' WHERE id=? AND status='pending'", [(int) $row['user_id']]);
            if ($user['merchant_id']) Db::run("UPDATE merchants SET status='active' WHERE id=? AND status='pending'", [(int) $user['merchant_id']]);
            Db::run('DELETE FROM portal_email_verifications WHERE user_id=?', [(int) $row['user_id']]);
            $pdo->commit();
            return $n === 1;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
