<?php
/**
 * PAPLONTECH Enterprise Management System
 * Logout Handler - Destroys session and redirects to login
 */

session_start();

// Clear all session data
session_unset();

// Destroy the session
session_destroy();

// Redirect to login page
header("Location: login.php");
exit();
?>