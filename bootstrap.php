<?php
/**
 * SHEHITA EMS — Request bootstrap
 *
 * Included as the FIRST line of every entry point (index, login, logout,
 * homepage, forgotpassword, heartbeat, aboutus). It replaces those files'
 * own session_start(), because the session has to be scoped to a tenant
 * before it is started.
 *
 * What it establishes, in order:
 *   1. Which company (tenant) this request belongs to, from the URL.
 *   2. A session isolated to that company.
 *   3. TENANT_SLUG / TENANT_ID / TENANT_DB constants + the $TENANT array.
 *   4. $SUBSCRIPTION — where that company stands on trial/payment.
 *
 * config.php then connects to TENANT_DB, so every module's existing
 * `global $conn` query is automatically scoped to the right company with no
 * changes to the modules themselves.
 *
 * URL shape:  https://system.shehita.co.tz/<slug>/login.php
 * .htaccess rewrites that to /login.php and passes <slug> through the
 * REDIRECT_TENANT server variable -- set by Apache, so a client cannot forge
 * it with a query string.
 */

declare(strict_types=1);

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Africa/Dar_es_Salaam');

$appEnv = getenv('APP_ENV') !== false ? getenv('APP_ENV') : 'production';
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('display_errors', $appEnv === 'development' ? '1' : '0');

require_once __DIR__ . '/lib/util.php';
require_once __DIR__ . '/lib/control_db.php';
require_once __DIR__ . '/lib/subscription.php';
require_once __DIR__ . '/lib/theme.php';

// ---------------------------------------------------------------------------
// 1. Which tenant?
// ---------------------------------------------------------------------------

/**
 * Read the slug Apache handed us.
 *
 * REDIRECT_TENANT is set by the RewriteRule in .htaccess. Apache prefixes
 * REDIRECT_ on each internal redirect, so a rule that fires twice yields
 * REDIRECT_REDIRECT_TENANT; check the common depths.
 */
function bootstrap_detect_slug(): string
{
    foreach (['REDIRECT_TENANT', 'REDIRECT_REDIRECT_TENANT', 'TENANT'] as $key) {
        if (!empty($_SERVER[$key])) {
            $candidate = strtolower((string)$_SERVER[$key]);
            if (preg_match('/^[a-z0-9][a-z0-9-]{1,38}$/', $candidate)) {
                return $candidate;
            }
        }
    }

    // Fallback for servers without mod_rewrite (e.g. `php -S` during local
    // debugging): read the first path segment directly.
    $uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    $part = strtolower(trim(explode('/', trim($uri, '/'))[0] ?? ''));

    if ($part !== ''
        && preg_match('/^[a-z0-9][a-z0-9-]{1,38}$/', $part)
        && !in_array($part, RESERVED_SLUGS, true)
        && !str_ends_with($part, '.php')
    ) {
        return $part;
    }

    return '';
}

$slug = bootstrap_detect_slug();

if ($slug === '') {
    // The bare domain is the platform, not a company. Send visitors to signup.
    header('Location: ' . platform_url('signup.php'));
    exit();
}

$control = control_db();
$TENANT  = tenant_by_slug($control, $slug);

if ($TENANT === null) {
    render_notice_page(
        404,
        'Company portal not found',
        'We could not find a company at this address. Please check the link, or '
        . '<a href="' . htmlspecialchars(platform_url('signup.php')) . '">create a new company account</a>.'
    );
}

if ($TENANT['status'] === 'Deleted') {
    render_notice_page(
        410,
        'This account has been closed',
        'The ' . htmlspecialchars((string)$TENANT['company_name']) . ' portal is no longer active. '
        . 'If you believe this is a mistake, please contact SHEHITA.'
    );
}

// ---------------------------------------------------------------------------
// 2. A session scoped to this tenant
// ---------------------------------------------------------------------------

// Both a distinct session NAME and a scoped cookie PATH. Either alone would
// mostly work; together they guarantee that signing into /acmetraders/ can
// never be seen -- or clobbered -- at /arc/ in the same browser.
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? 'off') !== 'off');

    session_name('SHEMS_' . strtoupper(str_replace('-', '_', $slug)));
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/' . $slug . '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $isHttps,
    ]);
    session_start();
}

// ---------------------------------------------------------------------------
// 3. Constants the rest of the app reads
// ---------------------------------------------------------------------------

define('TENANT_SLUG', $slug);
define('TENANT_ID', (int)$TENANT['id']);
define('TENANT_DB', (string)$TENANT['db_name']);
define('TENANT_NAME', (string)$TENANT['company_name']);

// ---------------------------------------------------------------------------
// 4. Subscription state (pure read -- the gate itself lives in homepage.php)
// ---------------------------------------------------------------------------

$SUBSCRIPTION = subscription_resolve($TENANT);

// csrf_token(), csrf_check(), client_ip() and render_notice_page() live in
// lib/util.php, shared with the platform pages (/signup, /admin) which have no tenant.
