<?php

/**
 * The signed-in workspace navigation. Every entry is a real page, so a section is
 * opened rather than scrolled to. The stylesheet collapses these labels to their
 * first character on narrow screens, so each one begins with its own glyph.
 */
function portalNav(string $current): string
{
    $groups = [
        [
            ['/dashboard', '⌂', 'Overview'],
            ['/dashboard/payments', '↙', 'Payments'],
            ['/dashboard/devices', '▣', 'Listener devices'],
            ['/dashboard/developers', '⌘', 'Developers'],
            ['/dashboard/merchants', '◈', 'Merchants', 'owner'],
            ['/dashboard/account', '☰', 'Account'],
        ],
        [
            ['/sandbox', '◇', 'Developer sandbox'],
            ['/resources', '▤', 'Resources'],
            ['/docs', '↗', 'Documentation'],
        ],
    ];
    $html = '<aside class="portal-nav"><a class="brand" href="/"><img class="brand-mark" src="/assets/logo-mark.svg" width="34" height="34" alt=""><span>ISP Billing<span class="brand-pay">Pay</span></span></a>';
    foreach ($groups as $index => $group) {
        $html .= '<nav' . ($index ? ' class="portal-nav-aux"' : '') . '>';
        foreach ($group as $item) {
            [$href, $glyph, $label] = $item;
            $role = $item[3] ?? '';
            // An entry for one role only stays hidden until the workspace confirms the role.
            $attributes = $role !== '' ? ' data-role="' . $role . '" hidden' : '';
            $attributes .= $href === $current ? ' class="active" aria-current="page"' : '';
            $html .= '<a' . $attributes . ' href="' . $href . '">' . $glyph . ' ' . $label . '</a>';
        }
        $html .= '</nav>';
    }
    return $html . '<button id="portal-logout" type="button">Sign out</button></aside>';
}

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
        '/assets/sandbox.js' => ['sandbox.js', 'text/javascript; charset=utf-8'],
        '/assets/signup.js' => ['signup.js', 'text/javascript; charset=utf-8'],
        '/assets/portal.js' => ['portal.js', 'text/javascript; charset=utf-8'],
        '/assets/workspace.js' => ['workspace.js', 'text/javascript; charset=utf-8'],
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
    /**
     * What the newest build is, so the app can update itself instead of the owner
     * having to find a download. The checksum is computed from the file being
     * served, and the app refuses anything that does not match it, so a truncated
     * or altered download never reaches the installer. Android additionally
     * refuses an update that is not signed with the same key as the installed app.
     */
    if ($path === '/app/version.json') {
        $apk = dirname(__DIR__, 2) . '/dist/PaymentBridge.apk';
        $release = dirname(__DIR__, 2) . '/dist/release.json';
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: public, max-age=300');
        if (!is_file($apk) || !is_file($release)) {
            http_response_code(404);
            if ($method !== 'HEAD') echo json_encode(['error' => ['code' => 'no_build', 'message' => 'No build is published right now.']]);
            return;
        }
        $info = json_decode((string) file_get_contents($release), true);
        if (!is_array($info)) {
            http_response_code(500);
            if ($method !== 'HEAD') echo json_encode(['error' => ['code' => 'bad_release', 'message' => 'The published build details could not be read.']]);
            return;
        }
        if ($method === 'HEAD') return;
        echo json_encode([
            'version_code' => (int) ($info['version_code'] ?? 0),
            'version_name' => (string) ($info['version_name'] ?? ''),
            'notes' => (string) ($info['notes'] ?? ''),
            'url' => 'https://ispbillingpay.com/download',
            'size' => filesize($apk),
            'sha256' => hash_file('sha256', $apk),
            'published_at' => gmdate('c', filemtime($apk)),
        ]);
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
        '/sandbox' => ['sandbox', 'Developer sandbox', 'Create a developer account and test payments with isolated test keys.'],
        '/signup' => ['signup', 'Create a merchant account', 'Register your ISP Billing Pay merchant account, choose a payment channel, verify your webhook and receive secure API credentials.'],
        '/login' => ['login', 'Merchant sign in', 'Sign in to your ISP Billing Pay merchant workspace.'],
        '/dashboard' => ['dashboard', 'Merchant workspace', 'Review gateway payments, channels, devices and integration health.'],
        '/dashboard/payments' => ['dashboard-payments', 'Payments', 'Search, filter and export every payment recorded against your merchant account.'],
        '/dashboard/devices' => ['dashboard-devices', 'Listener devices', 'Add a listener phone, rotate its key and watch whether it is reporting.'],
        '/dashboard/developers' => ['dashboard-developers', 'Developers', 'Create and revoke live API keys, set your webhook address and review deliveries.'],
        '/dashboard/account' => ['dashboard-account', 'Account', 'Your sign-in details, merchant profile and receiving account.'],
        '/dashboard/merchants' => ['dashboard-merchants', 'Merchants', 'Add a merchant account and review the businesses connected to the gateway.'],
        '/docs' => ['docs', 'Developer documentation', 'Integrate ISP Billing Pay: merchant registration, listener devices, payment intents, signed webhooks and claims.'],
        '/partners' => ['partners', 'Build the next connection', 'Explore integration opportunities for ISP billing platforms, internet providers and local deployment teams.'],
        '/security' => ['security', 'Security and trust', 'Understand payment matching, merchant isolation, device credentials and signed webhook delivery at ISP Billing Pay.'],
        '/support' => ['support', 'Help for your next connection', 'Find setup and payment troubleshooting guides or contact the ISP Billing Pay team.'],
        '/app' => ['app', 'Your dedicated payment listener', 'Set up the Android listener that forwards supported mobile money receipts to ISP Billing Pay.'],
        '/privacy' => ['privacy', 'Data and privacy', 'Understand how the gateway processes payment receipt data, device details and integration records.'],
        '/terms' => ['terms', 'Service boundaries', 'Understand the responsibilities of the gateway, billing platform, receiving account and mobile money provider.'],
    ];
    $pages = array_merge($pages, require $site . '/resource-routes.php');
    if ($path === '/robots.txt') {
        header('Content-Type: text/plain; charset=utf-8');
        if ($method !== 'HEAD') echo "User-agent: *\nAllow: /\nDisallow: /v1/\nSitemap: https://ispbillingpay.com/sitemap.xml\n";
        return;
    }
    if ($path === '/sitemap.xml') {
        header('Content-Type: application/xml; charset=utf-8');
        if ($method !== 'HEAD') {
            echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
            $private = static fn($url) => $url === '/login' || $url === '/sandbox' || strpos($url, '/dashboard') === 0;
            foreach (array_filter(array_keys($pages), static fn($url) => !$private($url)) as $url) echo '<url><loc>https://ispbillingpay.com' . $url . '</loc></url>';
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
    $isPortal = strpos($name, 'dashboard') === 0;
    if ($isPortal || in_array($name, ['login', 'sandbox'], true)) { header('Cache-Control: no-store'); header('X-Robots-Tag: noindex'); }
    header('Content-Type: text/html; charset=utf-8');
    if ($method === 'HEAD') return;
    $escape = static fn($value) => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $contact = getenv('GATEWAY_CONTACT_EMAIL') ?: 'contact@ispbillingpay.com';
    if (!filter_var($contact, FILTER_VALIDATE_EMAIL)) $contact = 'contact@ispbillingpay.com';
    $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $escape($title) . ' | ISP Billing Pay</title><meta name="description" content="' . $escape($description) . '"><meta name="theme-color" content="#0f4037"><link rel="icon" type="image/svg+xml" href="/favicon.svg?v=' . filemtime($site . '/favicon.svg') . '"><link rel="mask-icon" href="/assets/logo-mark.svg" color="#086653"><link rel="stylesheet" href="/assets/site.css?v=' . filemtime($site . '/_style.css') . '"><script src="/assets/site.js?v=' . filemtime($site . '/site.js') . '" defer></script>';
    if ($found) $html .= '<link rel="canonical" href="https://ispbillingpay.com' . $escape($path) . '"><meta property="og:type" content="website"><meta property="og:title" content="' . $escape($title) . ' | ISP Billing Pay"><meta property="og:description" content="' . $escape($description) . '"><meta property="og:url" content="https://ispbillingpay.com' . $escape($path) . '">';
    if ($name === 'home') $html .= '<script src="/assets/localization.js?v=' . filemtime($site . '/localization.js') . '" defer></script>';
    // One script serves every workspace page and picks its work from data-page.
    if ($isPortal) $html .= '<script src="/assets/workspace.js?v=' . filemtime($site . '/workspace.js') . '" defer></script>';
    $html .= '</head><body id="top" data-page="' . $name . '">';
    $standalone = $isPortal || $name === 'login';
    if (!$standalone) $html .= file_get_contents($site . '/_header.html');
    // A workspace page is only its own content; the shell and navigation are shared.
    if ($isPortal) $html .= '<main id="main-content" class="portal-shell">' . portalNav($path) . file_get_contents($site . '/' . $name . '.html') . '</main>';
    else $html .= $found ? file_get_contents($site . '/' . $name . '.html') : '<main id="main-content" class="wrap page-shell error-page"><p class="eyebrow">404 · A MISSED CONNECTION</p><h1>Let’s get you<br>back on track.</h1><p>This page could not be found. Visit the homepage or find the endpoint you need in the developer documentation.</p><div class="button-row"><a class="button button-dark" href="/">Back to home ↗</a><a class="button button-outline" href="/docs">Read the docs</a></div></main>';
    if (!$standalone) $html .= file_get_contents($site . '/_footer.html');
    $html .= '</body></html>';
    echo str_replace(['{{YEAR}}', 'contact@ispbillingpay.com'], [date('Y'), $escape($contact)], $html);
}
