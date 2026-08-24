<?php
/**
 * SHEHITA Enterprise Management System
 * Logout Handler - Destroys session and redirects to login
 */

// Resolves the tenant and starts the session with that company's cookie name
// and path, which is what makes the cookie deletion below actually match.
require_once __DIR__ . '/bootstrap.php';

// Clear all session data
session_unset();

// Expire the session cookie itself, using the SAME name and path bootstrap.php
// opened it with. Without this the browser keeps a stale cookie scoped to
// /<company>/ and the next visit resurrects an empty session under it.
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        [
            'expires'  => time() - 42000,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]
    );
}

// Destroy the session
session_destroy();

// Redirect to login page (relative, so it stays inside /<company>/)
header("Location: login.php");
exit();
?>
