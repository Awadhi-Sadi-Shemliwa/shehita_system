<?php
/**
 * SHEHITA Enterprise Management System
 * Root index
 *
 * Two cases:
 *   /                    -> no company in the URL. bootstrap.php sends the
 *                           visitor to /signup.php to create one.
 *   /<company>/          -> .htaccess already routes that straight to
 *                           login.php, so this file is not normally reached
 *                           with a tenant; if it is, fall through to login.
 */

require_once __DIR__ . '/bootstrap.php';

header("Location: login.php");
exit();
?>
