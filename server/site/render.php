<?php

/** Public pages are deliberately independent of database availability. */
function renderPublicSite(string $path, string $method): void
{
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        header('Allow: GET, HEAD');
        fail('method_not_allowed', 'This page accepts GET and HEAD requests.', 405);
    }
    $site = __DIR__;
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    $countryLookup = $path === '/' || $path === '/assets/localization.js' ? ' https://get.geojs.io' : '';
    header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; font-src 'self'; connect-src 'self'" . $countryLookup . "; base-uri 'none'; frame-ancestors 'none'; form-action 'none'; object-src 'none'");
    header('Cache-Control: no-cache');
    $assets = [
        '/assets/site.css' => ['_style.css', 'text/css; charset=utf-8'],
        '/assets/site.js' => ['site.js', 'text/javascript; charset=utf-8'],
        '/assets/localization.js' => ['localization.js', 'text/javascript; charset=utf-8'],
        '/assets/signup.js' => ['signup.js', 'text/javascript; charset=utf-8'],
        '/assets/portal.js' => ['portal.js', 'text/javascript; charset=utf-8'],
        '/assets/dashboard.js' => ['dashboard.js', 'text/javascript; charset=utf-8'],
        '/assets/logo-mark.svg' => ['logo-mark.svg', 'image/svg+xml'],
        '/assets/logo.svg' => ['logo.svg', 'image/svg+xml'],
        '/favicon.svg' => ['favicon.svg', 'image/svg+xml'],
    ];
    if (isset($assets[$path])) {
        [$file, $type] = $assets[$path];
        header('Content-Type: ' . $type);
        header('Cache-Control: public, max-age=3600');
        if ($method !== 'HEAD') readfile($site . '/' . $file);
        return;
    }
    if ($path === '/download') {
        $apk = dirname(__DIR__, 2) . '/dist/PaymentBridge.apk';
        if (!is_file($apk)) fail('not_found', 'The app is not available right now. Contact contact@ispbillingpay.com for help.', 404);
        header('Content-Type: application/vnd.android.package-archive');
        header('Content-Disposition: attachment; filename="PaymentBridge.apk"');
        header('Content-Length: ' . filesize($apk));
        header('Cache-Control: no-cache');
        if ($method !== 'HEAD') readfile($apk);
        return;
    }
    $pages = [
        '/' => ['home', 'Direct payments. Connected customers.', 'Connect direct mobile money payments to your ISP billing system with a dedicated Android listener, payment matching and signed webhooks.'],
        '/signup' => ['signup', 'Create a merchant account', 'Register your ISP Billing Pay merchant account, choose a payment channel, verify your webhook and receive secure API credentials.'],
        '/login' => ['login', 'Merchant sign in', 'Sign in to your ISP Billing Pay merchant workspace.'],
        '/dashboard' => ['dashboard', 'Merchant workspace', 'Review gateway payments, channels, devices and integration health.'],
        '/docs' => ['docs', 'Developer documentation', 'Integrate ISP Billing Pay: merchant registration, listener devices, payment intents, signed webhooks and claims.'],
        '/partners' => ['partners', 'Build the next connection', 'Explore integration opportunities for ISP billing platforms, internet providers and local deployment teams.'],
        '/security' => ['security', 'Security and trust', 'Understand payment matching, merchant isolation, device credentials and signed webhook delivery at ISP Billing Pay.'],
        '/support' => ['support', 'Help for your next connection', 'Find setup and payment troubleshooting guides or contact the ISP Billing Pay team.'],
        '/app' => ['app', 'Your dedicated payment listener', 'Set up the Android listener that forwards supported mobile money receipts to ISP Billing Pay.'],
        '/privacy' => ['privacy', 'Data and privacy', 'Understand how the gateway processes payment receipt data, device details and integration records.'],
        '/terms' => ['terms', 'Service boundaries', 'Understand the responsibilities of the gateway, billing platform, receiving account and mobile money provider.'],
    ];
    if ($path === '/robots.txt') {
        header('Content-Type: text/plain; charset=utf-8');
        if ($method !== 'HEAD') echo "User-agent: *\nAllow: /\nDisallow: /v1/\nSitemap: https://ispbillingpay.com/sitemap.xml\n";
        return;
    }
    if ($path === '/sitemap.xml') {
        header('Content-Type: application/xml; charset=utf-8');
        if ($method !== 'HEAD') {
            echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
            foreach (array_keys($pages) as $url) echo '<url><loc>https://ispbillingpay.com' . $url . '</loc></url>';
            echo '</urlset>';
        }
        return;
    }
    $found = isset($pages[$path]);
    [$name, $title, $description] = $pages[$path] ?? ['404', 'Page not found', 'Find your way back to ISP Billing Pay.'];
    if (!$found) {
        http_response_code(404);
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
    }
    header('Content-Type: text/html; charset=utf-8');
    if ($method === 'HEAD') return;
    $escape = static fn($value) => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $contact = getenv('GATEWAY_CONTACT_EMAIL') ?: 'contact@ispbillingpay.com';
    if (!filter_var($contact, FILTER_VALIDATE_EMAIL)) $contact = 'contact@ispbillingpay.com';
    $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $escape($title) . ' | ISP Billing Pay</title><meta name="description" content="' . $escape($description) . '"><meta name="theme-color" content="#0f4037"><link rel="icon" type="image/svg+xml" href="/favicon.svg?v=' . filemtime($site . '/favicon.svg') . '"><link rel="mask-icon" href="/assets/logo-mark.svg" color="#086653"><link rel="stylesheet" href="/assets/site.css?v=' . filemtime($site . '/_style.css') . '"><script src="/assets/site.js?v=' . filemtime($site . '/site.js') . '" defer></script>';
    if ($found) $html .= '<link rel="canonical" href="https://ispbillingpay.com' . $escape($path) . '"><meta property="og:type" content="website"><meta property="og:title" content="' . $escape($title) . ' | ISP Billing Pay"><meta property="og:description" content="' . $escape($description) . '"><meta property="og:url" content="https://ispbillingpay.com' . $escape($path) . '">';
    if ($name === 'home') $html .= '<script src="/assets/localization.js?v=' . filemtime($site . '/localization.js') . '" defer></script>';
    $html .= '</head><body id="top" data-page="' . $name . '">';
    $standalone = in_array($name, ['login', 'dashboard'], true);
    if (!$standalone) $html .= file_get_contents($site . '/_header.html');
    $html .= $found ? file_get_contents($site . '/' . $name . '.html') : '<main id="main-content" class="wrap page-shell error-page"><p class="eyebrow">404 · A MISSED CONNECTION</p><h1>Let’s get you<br>back on track.</h1><p>This page could not be found. Visit the homepage or find the endpoint you need in the developer documentation.</p><div class="button-row"><a class="button button-dark" href="/">Back to home ↗</a><a class="button button-outline" href="/docs">Read the docs</a></div></main>';
    if (!$standalone) $html .= file_get_contents($site . '/_footer.html');
    $html .= '</body></html>';
    echo str_replace(['{{YEAR}}', 'contact@ispbillingpay.com'], [date('Y'), $escape($contact)], $html);
}
