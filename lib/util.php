<?php
/**
 * SHEHITA EMS — Small helpers shared by tenant pages and platform pages.
 *
 * Lives apart from bootstrap.php because bootstrap.php REQUIRES a tenant, while
 * the platform pages (/signup, /admin) deliberately have none.
 */

/**
 * CSRF token for the current session.
 *
 * Used by the pages added for multi-tenancy and billing (signup, billing,
 * admin). The pre-existing login form has no CSRF token; that is a known gap
 * recorded separately, not something this helper silently changes.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_check(?string $token): bool
{
    return !empty($_SESSION['csrf_token'])
        && is_string($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Client IP, honouring the Nginx reverse proxy that sits in front of the
 * container in production (the app itself only ever sees 127.0.0.1 otherwise).
 */
function client_ip(): string
{
    foreach (['HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            return trim(explode(',', (string)$_SERVER[$key])[0]);
        }
    }

    return '0.0.0.0';
}

/**
 * Render a standalone message page and stop.
 *
 * Used for "company portal not found" and "account closed", which happen before
 * any tenant — and therefore any tenant theme — has been resolved. Styling is
 * inline and self-contained for exactly that reason.
 */
function render_notice_page(int $httpCode, string $heading, string $bodyHtml): void
{
    http_response_code($httpCode);
    $safeHeading = htmlspecialchars($heading);

    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$safeHeading} — SHEHITA</title>
<style>
  *{margin:0;padding:0;box-sizing:border-box}
  body{font-family:'Segoe UI',system-ui,sans-serif;min-height:100vh;display:flex;
       align-items:center;justify-content:center;padding:24px;
       background:linear-gradient(135deg,#3e2b1f 0%,#7b583f 50%,#b89b7e 100%);color:#1e293b}
  .card{background:#fff;border-radius:16px;padding:40px;max-width:480px;width:100%;
        box-shadow:0 20px 25px -5px rgb(0 0 0 / .2);text-align:center}
  h1{font-size:1.4rem;margin-bottom:12px;color:#3e2b1f}
  p{color:#475569;line-height:1.6}
  a{color:#7b583f;font-weight:600}
</style>
</head>
<body>
  <div class="card">
    <h1>{$safeHeading}</h1>
    <p>{$bodyHtml}</p>
  </div>
</body>
</html>
HTML;
    exit();
}

/**
 * Start a session for a PLATFORM page (/signup, /admin), which has no tenant.
 *
 * Scoped with its own name and cookie path so it can never be confused with,
 * or clobber, a company's session on the same domain.
 */
function platform_session_start(string $name, string $path = '/'): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    $isHttps = (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? 'off') !== 'off');

    session_name($name);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => $path,
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $isHttps,
    ]);
    session_start();
}

/**
 * Shared environment setup for platform entry points.
 */
function platform_boot(): void
{
    date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Africa/Dar_es_Salaam');

    $appEnv = getenv('APP_ENV') !== false ? getenv('APP_ENV') : 'production';
    error_reporting(E_ALL);
    ini_set('log_errors', '1');
    ini_set('display_errors', $appEnv === 'development' ? '1' : '0');
}
