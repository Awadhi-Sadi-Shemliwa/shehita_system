<?php
/**
 * SHEHITA Enterprise Management System
 * Heartbeat endpoint for session timeout functionality
 * Updates session last_activity timestamp via AJAX
 */

// Multi-tenant bootstrap: resolves which company this URL belongs to, opens a
// session scoped to that company, and defines TENANT_DB for config.php.
// This REPLACES session_start() -- the session must be scoped before it starts.
require_once __DIR__ . '/bootstrap.php';

// Only respond to POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit();
}

// Check if user is logged in
if (isset($_SESSION['email'])) {
    // Update last activity timestamp
    $_SESSION['last_activity'] = time();
    
    // Handle logout action
    if (isset($_POST['action']) && $_POST['action'] === 'logout') {
        // Optional: Additional cleanup before logout
        // The actual logout will be handled by logout.php
    }
    
    // Return success response
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'last_activity' => $_SESSION['last_activity']]);
} else {
    // User not logged in
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
}
?>