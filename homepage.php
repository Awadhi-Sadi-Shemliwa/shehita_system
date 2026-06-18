<?php
/**
 * SHEHITA Enterprise Management System
 * Main Layout with Top Horizontal Navbar and Dynamic Content Loading
 * 
 * ENHANCED: Top navigation bar with dropdown menus
 * ENHANCED: Responsive design - hamburger menu on mobile
 * ENHANCED: Professional UI refinements with logo and user avatar
 * ENHANCED: Automatic session timeout / idle logout after 30 minutes inactivity
 * REFACTORED: Language switching moved to System Settings module
 * REFINED: Added unique homepage_translations prefix for language switching (Issue fix)
 */

session_start();

// ============================================================
// SESSION TIMEOUT CHECK - Server-side validation
// ============================================================
$session_timeout = 1800; // 30 minutes in seconds

// Check if last activity timestamp exists in session
if (isset($_SESSION['last_activity'])) {
    $inactive_time = time() - $_SESSION['last_activity'];
    
    // If session has been inactive for more than timeout period
    if ($inactive_time > $session_timeout) {
        // Clear all session data
        session_unset();
        session_destroy();
        
        // Redirect to login page with timeout parameter
        header("Location: login.php?timeout=1");
        exit();
    }
}

// Update last activity timestamp on every page load
$_SESSION['last_activity'] = time();

// Check if user is logged in
if (!isset($_SESSION['email'])) {
    header("Location: login.php");
    exit();
}

// Include database configuration
$conn = require_once 'config.php';

// Get current page from URL, default to 'home'
$current_page = isset($_GET['page']) ? preg_replace('/[^a-z0-9-]/i', '', $_GET['page']) : 'home';

// PERMISSION: Check if user has permission to view the requested module
$user_role_id = $_SESSION['role_id'] ?? 0;
$can_view_current_page = canView($conn, $user_role_id, $current_page);

// Define all valid modules
$valid_modules = [
    'home', 'overview', 'projects', 'operations', 'status',
    'projectlist', 'categories', 'projectgroup',
    'expensescategory', 'expensesgroup',
    'customer-management', 'company-settings', 'user-management', 'departments', 'permissions',
    'roles', 'profile', 'invoice', 'systemsettings'
];

// Check if requested module exists in modules folder
$module_path = 'modules/' . $current_page . '.php';
$module_exists = file_exists($module_path) && in_array($current_page, $valid_modules);

// Helper function to check if current page is in a dropdown group
function isInGroup($page, $group_pages) {
    return in_array($page, $group_pages);
}

// PERMISSION: Function to check if a menu item should be shown
function canShowMenuItem($conn, $role_id, $module_name) {
    // Super Admin always sees all
    if ($role_id == 1) return true;
    
    // Check if user has view permission for this module
    return canView($conn, $role_id, $module_name);
}

// Get role name and department name for display if not already set in session
if (!isset($_SESSION['role_name']) && isset($_SESSION['role_id'])) {
    $_SESSION['role_name'] = getUserRoleName($conn, $_SESSION['role_id']);
}
if (!isset($_SESSION['department_name']) && isset($_SESSION['department_id'])) {
    $_SESSION['department_name'] = getDepartmentName($conn, $_SESSION['department_id']);
}

// Get user profile image for avatar display
$user_profile_image = null;
$user_name = $_SESSION['name'] ?? '';
$user_id = $_SESSION['user_id'] ?? 0;
if ($user_id > 0) {
    $avatar_query = $conn->prepare("SELECT profile_image FROM users WHERE id = ?");
    $avatar_query->bind_param("i", $user_id);
    $avatar_query->execute();
    $avatar_result = $avatar_query->get_result();
    if ($avatar_result->num_rows > 0) {
        $user_profile_image = $avatar_result->fetch_assoc()['profile_image'];
    }
    $avatar_query->close();
}

// Fallback display values
$display_role = isset($_SESSION['role_name']) ? $_SESSION['role_name'] : (isset($_SESSION['role_id']) ? 'User' : 'User');
$display_department = isset($_SESSION['department_name']) ? $_SESSION['department_name'] : (isset($_SESSION['department_id']) ? 'General' : 'General');

// Determine logo path - dynamic based on current directory
$logo_path = '';
$possible_logo_paths = [
    'uploads/systemlogo/Shehita_Logo.png',
    'uploads/systemlogo/Shehita_Logo.jpg',
    'uploads/systemlogo/Shehita_Logo.jpeg',
    'uploads/systemlogo/logo.png',
    'uploads/systemlogo/logo.jpg'
];

foreach ($possible_logo_paths as $path) {
    if (file_exists($path)) {
        $logo_path = $path;
        break;
    }
    // Also check with ../ prefix
    if (file_exists('../' . $path)) {
        $logo_path = '../' . $path;
        break;
    }
}

// If no logo found, use default text fallback
$logo_exists = !empty($logo_path);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SHEHITA EMS | <?= ucfirst(str_replace('-', ' ', $current_page)) ?></title>
    <?php if ($logo_exists): ?>
    <link rel="icon" type="image/png" href="<?= htmlspecialchars($logo_path) ?>">
    <?php endif; ?>

    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts - Enhanced typography -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Montserrat:wght@400;500;600;700;800&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        /* ============================================
           RESET & BASE STYLES
           ============================================ */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --brown-900: #3e2b1f;
            --brown-800: #5c3e2d;
            --brown-700: #7b583f;
            --brown-600: #9b7a5a;
            --brown-500: #b89b7e;
            --brown-400: #d4bfa8;
            --brown-300: #e8d9cc;
            --brown-200: #f0e8df;
            --brown-100: #f7f2ec;
            
            --gray-50: #f8fafc;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e1;
            --gray-400: #94a3b8;
            --gray-500: #64748b;
            --gray-600: #475569;
            --gray-700: #334155;
            --gray-800: #1e293b;
            --gray-900: #0f172a;
            
            --shadow-xs: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-sm: 0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
            --shadow-xl: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
            
            --warning-bg: #fff3e0;
            --warning-border: #ffb74d;
            --warning-text: #e65100;
            
            /* Navbar specific variables */
            --navbar-height: 70px;
            --navbar-bg: linear-gradient(135deg, #2a1f18 0%, #1e1610 100%);
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f5f7fb 0%, #f0f2f6 100%);
            color: var(--gray-800);
            overflow-x: hidden;
            padding-top: var(--navbar-height);
        }

        /* ============================================
           TOP NAVBAR STYLES
           ============================================ */
        .top-navbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: var(--navbar-bg);
            box-shadow: var(--shadow-lg);
            z-index: 1000;
            height: var(--navbar-height);
        }

        .navbar-container {
            max-width: 100%;
            margin: 0 auto;
            padding: 0 24px;
            height: 100%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
        }

        /* Logo Area */
        .navbar-logo {
            display: flex;
            align-items: center;
            text-decoration: none;
            transition: all 0.2s ease;
            flex-shrink: 0;
        }

        .navbar-logo:hover {
            transform: translateY(-2px);
        }

        /* Logo White Background Wrapper - ENHANCED VISIBILITY */
        .navbar-logo-wrapper {
            background: white;
            padding: 2px 6px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .navbar-logo-wrapper:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        .navbar-logo-img {
            max-height: 45px;
            width: auto;
            object-fit: contain;
            display: block;
        }

        .navbar-logo-text {
            font-family: 'Montserrat', sans-serif;
            font-size: 20px;
            font-weight: 800;
            background: linear-gradient(135deg, #d4bfa8 0%, #b89b7e 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -0.5px;
        }

        .navbar-logo-sub {
            font-size: 10px;
            color: var(--brown-500);
            display: block;
            margin-top: 2px;
            font-weight: 500;
        }

        /* Desktop Navigation Menu - Improved for laptop screens */
        .nav-menu {
            display: flex;
            align-items: center;
            gap: 4px;
            list-style: none;
            margin: 0;
            padding: 0;
            flex-wrap: nowrap;
            overflow-x: visible;
        }

        .nav-item {
            position: relative;
            flex-shrink: 0;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            color: rgba(255, 255, 255, 0.85);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            border-radius: 10px;
            transition: all 0.2s ease;
            cursor: pointer;
            white-space: nowrap;
        }

        .nav-link i {
            font-size: 16px;
            color: rgba(255, 255, 255, 0.6);
            transition: color 0.2s;
        }

        .nav-link:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }

        .nav-link:hover i {
            color: var(--brown-400);
        }

        .nav-link.active {
            background: linear-gradient(90deg, rgba(123, 88, 63, 0.4) 0%, rgba(123, 88, 63, 0.15) 100%);
            color: white;
            border-left: 2px solid var(--brown-500);
        }

        .nav-link.active i {
            color: var(--brown-400);
        }

        /* Dropdown Menu */
        .dropdown-menu {
            position: absolute;
            top: calc(100% + 8px);
            left: 0;
            min-width: 220px;
            background: white;
            border-radius: 12px;
            box-shadow: var(--shadow-lg);
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.25s ease;
            z-index: 100;
            list-style: none;
            padding: 8px 0;
            margin: 0;
            border: 1px solid var(--gray-200);
        }

        .nav-item.dropdown:hover .dropdown-menu {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 18px;
            color: var(--gray-700);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .dropdown-item i {
            width: 20px;
            font-size: 14px;
            color: var(--brown-600);
        }

        .dropdown-item:hover {
            background: var(--brown-100);
            color: var(--brown-800);
            padding-left: 22px;
        }

        .dropdown-item.active {
            background: var(--brown-100);
            color: var(--brown-800);
            border-left: 3px solid var(--brown-600);
        }

        .dropdown-divider {
            height: 1px;
            background: var(--gray-200);
            margin: 6px 0;
        }

        /* Right Section (User) */
        .navbar-right {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-shrink: 0;
        }

        /* User Dropdown */
        .user-dropdown {
            position: relative;
        }

        .user-trigger {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 6px 12px 6px 8px;
            background: rgba(255, 255, 255, 0.08);
            border-radius: 50px;
            cursor: pointer;
            transition: all 0.2s ease;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .user-trigger:hover {
            background: rgba(255, 255, 255, 0.15);
        }

        .user-avatar {
            width: 38px;
            height: 38px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--brown-700), var(--brown-600));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 16px;
            overflow: hidden;
        }

        .user-avatar img {
            width: 100%;
            height: 100%;
            border-radius: 12px;
            object-fit: cover;
        }

        .user-info {
            text-align: left;
        }

        .user-name {
            font-weight: 700;
            color: white;
            font-size: 13px;
            line-height: 1.3;
        }

        .user-role {
            font-size: 11px;
            color: rgba(255, 255, 255, 0.6);
            text-transform: capitalize;
        }

        .user-dropdown-menu {
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            min-width: 200px;
            background: white;
            border-radius: 12px;
            box-shadow: var(--shadow-lg);
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.25s ease;
            list-style: none;
            padding: 8px 0;
            margin: 0;
            border: 1px solid var(--gray-200);
        }

        .user-dropdown:hover .user-dropdown-menu {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        /* Mobile Toggle Button */
        .mobile-toggle {
            display: none;
            background: rgba(255, 255, 255, 0.1);
            border: none;
            width: 44px;
            height: 44px;
            border-radius: 12px;
            cursor: pointer;
            color: white;
            font-size: 20px;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .mobile-toggle:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        /* Mobile Navigation Overlay */
        .mobile-nav-overlay {
            position: fixed;
            top: var(--navbar-height);
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            z-index: 999;
            display: none;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .mobile-nav-overlay.active {
            display: block;
            opacity: 1;
        }

        .mobile-nav-menu {
            position: fixed;
            top: var(--navbar-height);
            left: -280px;
            width: 280px;
            height: calc(100% - var(--navbar-height));
            background: linear-gradient(180deg, #2a1f18 0%, #1e1610 100%);
            z-index: 1000;
            overflow-y: auto;
            transition: left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            padding: 16px;
        }

        .mobile-nav-menu.open {
            left: 0;
        }

        .mobile-nav-item {
            list-style: none;
            margin-bottom: 4px;
        }

        .mobile-nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            color: rgba(255, 255, 255, 0.85);
            text-decoration: none;
            font-size: 15px;
            font-weight: 500;
            border-radius: 12px;
            transition: all 0.2s;
        }

        .mobile-nav-link i {
            width: 24px;
            color: rgba(255, 255, 255, 0.6);
        }

        .mobile-nav-link:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }

        .mobile-nav-link.active {
            background: rgba(123, 88, 63, 0.3);
            color: white;
        }

        .mobile-dropdown-toggle {
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
        }

        .mobile-dropdown-toggle .dropdown-arrow {
            transition: transform 0.3s;
        }

        .mobile-dropdown-toggle.open .dropdown-arrow {
            transform: rotate(180deg);
        }

        .mobile-dropdown-menu {
            padding-left: 40px;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
        }

        .mobile-dropdown-menu.open {
            max-height: 500px;
        }

        .mobile-dropdown-item {
            display: block;
            padding: 10px 16px;
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            font-size: 13px;
            border-radius: 10px;
            margin: 2px 0;
        }

        .mobile-dropdown-item:hover {
            background: rgba(255, 255, 255, 0.08);
            color: white;
        }

        .mobile-dropdown-item.active {
            background: rgba(123, 88, 63, 0.3);
            color: white;
        }

        /* Main Content Area */
        .main-content {
            min-height: calc(100vh - var(--navbar-height));
            padding: 24px 32px;
        }

        .module-wrapper {
            background: white;
            border-radius: 24px;
            padding: 28px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-200);
            transition: box-shadow 0.3s ease;
        }

        .module-wrapper:hover {
            box-shadow: var(--shadow-lg);
        }

        /* Permission Denied & Development Message */
        .permission-denied, .development-message {
            background: white;
            border-radius: 24px;
            padding: 60px 40px;
            text-align: center;
            box-shadow: var(--shadow-xl);
            max-width: 600px;
            margin: 40px auto;
            border: 1px solid var(--gray-200);
        }

        .permission-denied i, .development-message i {
            font-size: 64px;
            margin-bottom: 24px;
            padding: 20px;
            border-radius: 50%;
        }

        .permission-denied i {
            color: #dc3545;
            background: #f8d7da;
        }

        .development-message i {
            color: var(--brown-600);
            background: var(--brown-100);
        }

        .permission-denied h2, .development-message h2 {
            font-size: 24px;
            font-weight: 700;
            color: var(--gray-800);
            margin-bottom: 12px;
        }

        .permission-denied p, .development-message p {
            color: var(--gray-600);
            font-size: 16px;
            line-height: 1.7;
        }

        /* Timeout Modal */
        .timeout-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(8px);
            z-index: 10000;
            justify-content: center;
            align-items: center;
            animation: fadeIn 0.3s ease;
        }

        .timeout-modal.active {
            display: flex;
        }

        .timeout-modal-content {
            background: white;
            border-radius: 24px;
            max-width: 480px;
            width: 90%;
            padding: 32px;
            text-align: center;
            box-shadow: var(--shadow-xl);
            animation: slideUp 0.3s ease;
        }

        .timeout-modal-icon {
            font-size: 64px;
            color: var(--warning-text);
            margin-bottom: 20px;
        }

        .timeout-modal h3 {
            font-size: 24px;
            font-weight: 700;
            color: var(--gray-800);
            margin-bottom: 12px;
        }

        .timeout-modal p {
            color: var(--gray-600);
            font-size: 16px;
            line-height: 1.6;
            margin-bottom: 8px;
        }

        .timeout-timer {
            font-size: 48px;
            font-weight: 800;
            color: var(--brown-700);
            margin: 20px 0;
            font-family: 'Montserrat', monospace;
        }

        .timeout-modal-buttons {
            display: flex;
            gap: 16px;
            justify-content: center;
            margin-top: 24px;
        }

        .timeout-btn {
            padding: 12px 28px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            border: none;
            font-family: inherit;
        }

        .timeout-btn-stay {
            background: var(--brown-700);
            color: white;
        }

        .timeout-btn-stay:hover {
            background: var(--brown-800);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .timeout-btn-logout {
            background: var(--gray-200);
            color: var(--gray-700);
        }

        .timeout-btn-logout:hover {
            background: var(--gray-300);
        }

        /* Session Toast */
        .session-toast {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: var(--warning-bg);
            border-left: 4px solid var(--warning-border);
            padding: 16px 20px;
            border-radius: 12px;
            box-shadow: var(--shadow-lg);
            display: flex;
            align-items: center;
            gap: 12px;
            z-index: 10001;
            transform: translateX(400px);
            transition: transform 0.3s ease;
            max-width: 380px;
        }

        .session-toast.show {
            transform: translateX(0);
        }

        .session-toast i {
            font-size: 24px;
            color: var(--warning-text);
        }

        .session-toast-content {
            flex: 1;
        }

        .session-toast-title {
            font-weight: 700;
            color: var(--warning-text);
            margin-bottom: 4px;
        }

        .session-toast-message {
            font-size: 13px;
            color: var(--gray-600);
        }

        .session-toast-close {
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            color: var(--gray-500);
            padding: 0 4px;
        }

        .session-toast-close:hover {
            color: var(--gray-700);
        }

        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Responsive Styles */
        @media (max-width: 1200px) {
            .nav-link {
                padding: 10px 12px;
                font-size: 13px;
            }
            
            .nav-link i {
                font-size: 14px;
            }
        }

        @media (max-width: 1024px) {
            .nav-menu {
                display: none;
            }
            
            .mobile-toggle {
                display: flex;
            }
            
            .navbar-container {
                padding: 0 16px;
            }
        }

        @media (max-width: 768px) {
            :root {
                --navbar-height: 60px;
            }
            
            .main-content {
                padding: 16px;
            }
            
            .module-wrapper {
                padding: 16px;
            }
            
            .user-info {
                display: none;
            }
            
            .user-trigger {
                padding: 6px;
            }
            
            .timeout-modal-content {
                margin: 20px;
                padding: 24px;
            }
            
            .session-toast {
                bottom: 20px;
                right: 20px;
                left: 20px;
                max-width: calc(100% - 40px);
            }
        }
    </style>
</head>
<body>
    <!-- Timeout Modal -->
    <div class="timeout-modal" id="timeoutModal">
        <div class="timeout-modal-content">
            <div class="timeout-modal-icon">
                <i class="fas fa-hourglass-half"></i>
            </div>
            <h3>Session Expiring Soon</h3>
            <p>Your session will expire due to inactivity.</p>
            <div class="timeout-timer" id="timeoutTimer">01:00</div>
            <p>Click "Stay Logged In" to continue working.</p>
            <div class="timeout-modal-buttons">
                <button class="timeout-btn timeout-btn-stay" id="stayLoggedInBtn">
                    <i class="fas fa-clock"></i> Stay Logged In
                </button>
                <button class="timeout-btn timeout-btn-logout" id="logoutNowBtn">
                    <i class="fas fa-sign-out-alt"></i> Logout Now
                </button>
            </div>
        </div>
    </div>

    <!-- Session Toast -->
    <div class="session-toast" id="sessionToast">
        <i class="fas fa-clock"></i>
        <div class="session-toast-content">
            <div class="session-toast-title">Session Expiring</div>
            <div class="session-toast-message" id="toastMessage">Your session will expire in 1 minute</div>
        </div>
        <button class="session-toast-close" onclick="closeSessionToast()">&times;</button>
    </div>

    <!-- Top Navigation Bar -->
    <nav class="top-navbar">
        <div class="navbar-container">
            <!-- Logo Area with White Background Wrapper -->
            <a href="?page=home" class="navbar-logo">
                <?php if ($logo_exists): ?>
                    <div class="navbar-logo-wrapper">
                        <img src="<?= htmlspecialchars($logo_path) ?>" alt="SHEHITA EMS" class="navbar-logo-img">
                    </div>
                <?php else: ?>
                    <div>
                        <div class="navbar-logo-text">SHEHITA EMS</div>
                        <div class="navbar-logo-sub">Enterprise Management System</div>
                    </div>
                <?php endif; ?>
            </a>

            <!-- Desktop Navigation Menu -->
            <ul class="nav-menu">
                <?php
                $user_role_id = $_SESSION['role_id'] ?? 0;
                
                // Helper to render nav item
                function renderNavItem($conn, $role_id, $page, $icon, $label, $current_page, $lang_key) {
                    if (canShowMenuItem($conn, $role_id, $page)) {
                        $active_class = ($current_page == $page) ? 'active' : '';
                        echo '<li class="nav-item">';
                        echo '<a href="?page=' . $page . '" class="nav-link ' . $active_class . '" data-homepage-lang="' . $lang_key . '">';
                        echo '<i class="' . $icon . '"></i>';
                        echo '<span>' . $label . '</span>';
                        echo '</a>';
                        echo '</li>';
                    }
                }
                
                // Helper to render dropdown
                function renderDropdown($conn, $role_id, $items, $icon, $label, $current_page, $lang_key) {
                    $has_visible = false;
                    foreach ($items as $item) {
                        if (canShowMenuItem($conn, $role_id, $item['page'])) {
                            $has_visible = true;
                            break;
                        }
                    }
                    if (!$has_visible) return;
                    
                    $is_active = false;
                    foreach ($items as $item) {
                        if ($current_page == $item['page']) {
                            $is_active = true;
                            break;
                        }
                    }
                    
                    echo '<li class="nav-item dropdown">';
                    echo '<a href="#" class="nav-link ' . ($is_active ? 'active' : '') . '">';
                    echo '<i class="' . $icon . '"></i>';
                    echo '<span data-homepage-lang="' . $lang_key . '">' . $label . '</span>';
                    echo '<i class="fas fa-chevron-down" style="font-size: 12px; margin-left: 4px;"></i>';
                    echo '</a>';
                    echo '<ul class="dropdown-menu">';
                    
                    foreach ($items as $item) {
                        if (canShowMenuItem($conn, $role_id, $item['page'])) {
                            $active_class = ($current_page == $item['page']) ? 'active' : '';
                            echo '<li>';
                            echo '<a href="?page=' . $item['page'] . '" class="dropdown-item ' . $active_class . '" data-homepage-lang="' . $item['lang_key'] . '">';
                            echo '<i class="' . $item['icon'] . '"></i>';
                            echo '<span>' . $item['label'] . '</span>';
                            echo '</a>';
                            echo '</li>';
                        }
                    }
                    
                    echo '</ul>';
                    echo '</li>';
                }
                
                // Define menu items
                $single_menu_items = [
                    ['page' => 'home', 'icon' => 'fas fa-home', 'label' => 'Home', 'lang_key' => 'home'],
                    ['page' => 'overview', 'icon' => 'fas fa-chart-pie', 'label' => 'Overview', 'lang_key' => 'overview'],
                    ['page' => 'projects', 'icon' => 'fas fa-file-contract', 'label' => 'Projects', 'lang_key' => 'projects'],
                    ['page' => 'operations', 'icon' => 'fas fa-clipboard-check', 'label' => 'Operations', 'lang_key' => 'operations'],
                    ['page' => 'status', 'icon' => 'fas fa-tasks', 'label' => 'Status', 'lang_key' => 'status'],
                    ['page' => 'invoice', 'icon' => 'fas fa-file-invoice-dollar', 'label' => 'Invoices', 'lang_key' => 'invoices']
                ];
                
                $projects_dropdown_items = [
                    ['page' => 'projectlist', 'icon' => 'fas fa-list', 'label' => 'Project List', 'lang_key' => 'projectList'],
                    ['page' => 'categories', 'icon' => 'fas fa-tags', 'label' => 'Categories', 'lang_key' => 'categories'],
                    ['page' => 'projectgroup', 'icon' => 'fas fa-layer-group', 'label' => 'Groups', 'lang_key' => 'groups']
                ];
                
                $expenses_dropdown_items = [
                    ['page' => 'expensescategory', 'icon' => 'fas fa-folder', 'label' => 'Categories', 'lang_key' => 'expensesCategories'],
                    ['page' => 'expensesgroup', 'icon' => 'fas fa-object-group', 'label' => 'Groups', 'lang_key' => 'expensesGroups']
                ];
                
                $settings_dropdown_items = [
                    ['page' => 'customer-management', 'icon' => 'fas fa-users', 'label' => 'Customer Management', 'lang_key' => 'customerManagement'],
                    ['page' => 'company-settings', 'icon' => 'fas fa-building', 'label' => 'Company Settings', 'lang_key' => 'companySettings'],
                    ['page' => 'user-management', 'icon' => 'fas fa-user-lock', 'label' => 'User Management', 'lang_key' => 'userManagement'],
                    ['page' => 'departments', 'icon' => 'fas fa-sitemap', 'label' => 'Departments', 'lang_key' => 'departments'],
                    ['page' => 'roles', 'icon' => 'fas fa-user-tag', 'label' => 'Roles', 'lang_key' => 'roles'],
                    ['page' => 'permissions', 'icon' => 'fas fa-shield-alt', 'label' => 'Permissions', 'lang_key' => 'permissions'],
                    ['page' => 'systemsettings', 'icon' => 'fas fa-globe', 'label' => 'System Settings', 'lang_key' => 'systemSettings']
                ];
                
                $user_dropdown_items = [
                    ['page' => 'profile', 'icon' => 'fas fa-user-circle', 'label' => 'Profile', 'lang_key' => 'profile']
                ];
                
                // Render single menu items
                foreach ($single_menu_items as $item) {
                    renderNavItem($conn, $user_role_id, $item['page'], $item['icon'], $item['label'], $current_page, $item['lang_key']);
                }
                
                // Render dropdown menus
                renderDropdown($conn, $user_role_id, $projects_dropdown_items, 'fas fa-boxes', 'Projects', $current_page, 'projectsMenu');
                renderDropdown($conn, $user_role_id, $expenses_dropdown_items, 'fas fa-money-bill-wave', 'Expenses', $current_page, 'expensesMenu');
                renderDropdown($conn, $user_role_id, $settings_dropdown_items, 'fas fa-cog', 'Settings', $current_page, 'settings');
                ?>
            </ul>

            <!-- Right Section -->
            <div class="navbar-right">
                <!-- User Dropdown -->
                <div class="user-dropdown">
                    <div class="user-trigger">
                        <div class="user-avatar">
                            <?php 
                            $avatar_image_exists = false;
                            $avatar_image_path = null;
                            
                            if (!empty($user_profile_image)) {
                                if (file_exists($user_profile_image)) {
                                    $avatar_image_path = $user_profile_image;
                                    $avatar_image_exists = true;
                                } elseif (file_exists('../' . $user_profile_image)) {
                                    $avatar_image_path = '../' . $user_profile_image;
                                    $avatar_image_exists = true;
                                } elseif (file_exists('uploads/profiles/' . basename($user_profile_image))) {
                                    $avatar_image_path = 'uploads/profiles/' . basename($user_profile_image);
                                    $avatar_image_exists = true;
                                }
                            }
                            
                            if ($avatar_image_exists && $avatar_image_path):
                            ?>
                                <img src="<?= htmlspecialchars($avatar_image_path) ?>" alt="<?= htmlspecialchars($_SESSION['name']) ?>">
                            <?php else: ?>
                                <?= strtoupper(substr($_SESSION['name'], 0, 1)) ?>
                            <?php endif; ?>
                        </div>
                        <div class="user-info">
                            <div class="user-name"><?= htmlspecialchars($_SESSION['name']) ?></div>
                            <div class="user-role"><?= ucfirst(htmlspecialchars($display_role)) ?></div>
                        </div>
                        <i class="fas fa-chevron-down" style="font-size: 12px; color: rgba(255,255,255,0.6);"></i>
                    </div>
                    <ul class="user-dropdown-menu">
                        <?php foreach ($user_dropdown_items as $item): ?>
                            <?php if (canShowMenuItem($conn, $user_role_id, $item['page'])): ?>
                                <li>
                                    <a href="?page=<?= $item['page'] ?>" class="dropdown-item" data-homepage-lang="<?= $item['lang_key'] ?>">
                                        <i class="<?= $item['icon'] ?>"></i>
                                        <span><?= $item['label'] ?></span>
                                    </a>
                                </li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a href="logout.php" class="dropdown-item" data-homepage-lang="logout">
                                <i class="fas fa-sign-out-alt"></i>
                                <span>Logout</span>
                            </a>
                        </li>
                    </ul>
                </div>

                <!-- Mobile Toggle Button -->
                <button class="mobile-toggle" id="mobileToggle">
                    <i class="fas fa-bars"></i>
                </button>
            </div>
        </div>
    </nav>

    <!-- Mobile Navigation Overlay -->
    <div class="mobile-nav-overlay" id="mobileOverlay"></div>

    <!-- Mobile Navigation Menu -->
    <div class="mobile-nav-menu" id="mobileNavMenu">
        <?php
        // Helper for mobile nav items
        function renderMobileNavItem($conn, $role_id, $page, $icon, $label, $current_page, $lang_key) {
            if (canShowMenuItem($conn, $role_id, $page)) {
                $active_class = ($current_page == $page) ? 'active' : '';
                echo '<li class="mobile-nav-item">';
                echo '<a href="?page=' . $page . '" class="mobile-nav-link ' . $active_class . '" data-homepage-lang="' . $lang_key . '">';
                echo '<i class="' . $icon . '"></i>';
                echo '<span>' . $label . '</span>';
                echo '</a>';
                echo '</li>';
            }
        }
        
        function renderMobileDropdown($conn, $role_id, $items, $icon, $label, $current_page, $lang_key, $dropdown_id) {
            $has_visible = false;
            foreach ($items as $item) {
                if (canShowMenuItem($conn, $role_id, $item['page'])) {
                    $has_visible = true;
                    break;
                }
            }
            if (!$has_visible) return;
            
            $is_active = false;
            foreach ($items as $item) {
                if ($current_page == $item['page']) {
                    $is_active = true;
                    break;
                }
            }
            
            $open_class = $is_active ? 'open' : '';
            echo '<li class="mobile-nav-item">';
            echo '<div class="mobile-nav-link mobile-dropdown-toggle ' . $open_class . '" data-dropdown="' . $dropdown_id . '">';
            echo '<div style="display: flex; align-items: center; gap: 12px;">';
            echo '<i class="' . $icon . '"></i>';
            echo '<span data-homepage-lang="' . $lang_key . '">' . $label . '</span>';
            echo '</div>';
            echo '<i class="fas fa-chevron-down dropdown-arrow"></i>';
            echo '</div>';
            echo '<ul class="mobile-dropdown-menu" id="mobileDropdown_' . $dropdown_id . '">';
            
            foreach ($items as $item) {
                if (canShowMenuItem($conn, $role_id, $item['page'])) {
                    $active_class = ($current_page == $item['page']) ? 'active' : '';
                    echo '<li>';
                    echo '<a href="?page=' . $item['page'] . '" class="mobile-dropdown-item ' . $active_class . '" data-homepage-lang="' . $item['lang_key'] . '">';
                    echo '<i class="' . $item['icon'] . '" style="margin-right: 8px;"></i>';
                    echo $item['label'];
                    echo '</a>';
                    echo '</li>';
                }
            }
            
            echo '</ul>';
            echo '</li>';
        }
        ?>
        
        <ul style="list-style: none; padding: 0; margin: 0;">
            <?php
            // Render single menu items for mobile
            foreach ($single_menu_items as $item) {
                renderMobileNavItem($conn, $user_role_id, $item['page'], $item['icon'], $item['label'], $current_page, $item['lang_key']);
            }
            
            // Render dropdowns for mobile
            renderMobileDropdown($conn, $user_role_id, $projects_dropdown_items, 'fas fa-boxes', 'Projects', $current_page, 'projectsMenu', 'projects');
            renderMobileDropdown($conn, $user_role_id, $expenses_dropdown_items, 'fas fa-money-bill-wave', 'Expenses', $current_page, 'expensesMenu', 'expenses');
            renderMobileDropdown($conn, $user_role_id, $settings_dropdown_items, 'fas fa-cog', 'Settings', $current_page, 'settings', 'settings');
            
            // User dropdown items in mobile
            echo '<li class="mobile-nav-item"><hr style="margin: 12px 0; border-color: rgba(255,255,255,0.1);"></li>';
            foreach ($user_dropdown_items as $item) {
                if (canShowMenuItem($conn, $user_role_id, $item['page'])) {
                    $active_class = ($current_page == $item['page']) ? 'active' : '';
                    echo '<li class="mobile-nav-item">';
                    echo '<a href="?page=' . $item['page'] . '" class="mobile-nav-link ' . $active_class . '" data-homepage-lang="' . $item['lang_key'] . '">';
                    echo '<i class="' . $item['icon'] . '"></i>';
                    echo '<span>' . $item['label'] . '</span>';
                    echo '</a>';
                    echo '</li>';
                }
            }
            ?>
            <li class="mobile-nav-item">
                <a href="logout.php" class="mobile-nav-link" data-homepage-lang="logout">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </li>
        </ul>
    </div>

    <!-- Main Content -->
    <main class="main-content">
        <?php if (!$can_view_current_page): ?>
            <div class="permission-denied">
                <i class="fas fa-lock"></i>
                <h2>Access Denied</h2>
                <p>You do not have permission to access this module.</p>
                <p style="margin-top: 16px; font-size: 14px;">Please contact your administrator if you believe this is an error.</p>
            </div>
            
        <?php elseif ($module_exists): ?>
            <div class="module-wrapper">
                <?php include $module_path; ?>
            </div>
            
        <?php else: ?>
            <div class="development-message">
                <i class="fas fa-cogs"></i>
                <h2>Module Under Development</h2>
                <p>The <strong><?= ucfirst(str_replace('-', ' ', $current_page)) ?></strong> module is currently being developed.</p>
                <p class="mt-3" style="font-size: 14px;">Check back soon for updates!</p>
            </div>
        <?php endif; ?>
    </main>

    <script>
        // ============================================================
        // GLOBAL HOMEPAGE TRANSLATIONS OBJECT (ENGLISH + SWAHILI)
        // ============================================================
        // NOTE: This object is used by the System Settings module.
        // Do not remove - required for language switching functionality.
        // ============================================================
        const homepage_translations = {
            en: {
                home: 'Home',
                overview: 'Overview',
                projects: 'Projects',
                operations: 'Operations',
                status: 'Status',
                projectsMenu: 'Projects',
                projectList: 'Project List',
                categories: 'Categories',
                groups: 'Groups',
                expensesMenu: 'Expenses',
                expensesCategories: 'Categories',
                expensesGroups: 'Groups',
                settings: 'Settings',
                customerManagement: 'Customer Management',
                companySettings: 'Company Settings',
                userManagement: 'User Management',
                departments: 'Departments',
                roles: 'Roles',
                permissions: 'Permissions',
                systemSettings: 'System Settings',
                user: 'User',
                profile: 'Profile',
                invoices: 'Invoices',
                logout: 'Logout'
            },
            sw: {
                home: 'Nyumbani',
                overview: 'Muhtasari',
                projects: 'Miradi',
                operations: 'Uendeshaji',
                status: 'Hali',
                projectsMenu: 'Miradi',
                projectList: 'Orodha ya Miradi',
                categories: 'Kategoria',
                groups: 'Vikundi',
                expensesMenu: 'Matumizi',
                expensesCategories: 'Kategoria',
                expensesGroups: 'Vikundi',
                settings: 'Mipangilio',
                customerManagement: 'Usimamizi wa Wateja',
                companySettings: 'Mipangilio ya Kampuni',
                userManagement: 'Usimamizi wa Watumiaji',
                departments: 'Idara',
                roles: 'Majukumu',
                permissions: 'Ruhusa',
                systemSettings: 'Mipangilio ya Mfumo',
                user: 'Mtumiaji',
                profile: 'Wasifu',
                invoices: 'Ankara',
                logout: 'Toka'
            }
        };

        // ============================================================
        // HOMEPAGE MODULE LANGUAGE UPDATE FUNCTION
        // Called by System Settings module when language changes
        // ============================================================
        function updateHomepageLanguage(lang) {
            // Update all elements with data-homepage-lang attribute
            const elements = document.querySelectorAll('[data-homepage-lang]');
            elements.forEach(element => {
                const key = element.getAttribute('data-homepage-lang');
                if (homepage_translations[lang] && homepage_translations[lang][key]) {
                    if (element.tagName === 'INPUT' || element.tagName === 'TEXTAREA') {
                        if (element.placeholder !== undefined) {
                            element.placeholder = homepage_translations[lang][key];
                        }
                    } else {
                        element.textContent = homepage_translations[lang][key];
                    }
                }
            });
        }

        // ============================================================
        // MOBILE NAVIGATION FUNCTIONS
        // ============================================================
        const mobileToggle = document.getElementById('mobileToggle');
        const mobileOverlay = document.getElementById('mobileOverlay');
        const mobileNavMenu = document.getElementById('mobileNavMenu');
        
        function openMobileMenu() {
            mobileNavMenu.classList.add('open');
            mobileOverlay.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        
        function closeMobileMenu() {
            mobileNavMenu.classList.remove('open');
            mobileOverlay.classList.remove('active');
            document.body.style.overflow = '';
        }
        
        if (mobileToggle) {
            mobileToggle.addEventListener('click', openMobileMenu);
        }
        
        if (mobileOverlay) {
            mobileOverlay.addEventListener('click', closeMobileMenu);
        }
        
        // Mobile dropdown toggles
        const mobileDropdownToggles = document.querySelectorAll('.mobile-dropdown-toggle');
        mobileDropdownToggles.forEach(toggle => {
            toggle.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const dropdownId = this.getAttribute('data-dropdown');
                const dropdownMenu = document.getElementById('mobileDropdown_' + dropdownId);
                const isOpen = dropdownMenu.classList.contains('open');
                
                if (isOpen) {
                    dropdownMenu.classList.remove('open');
                    this.classList.remove('open');
                } else {
                    dropdownMenu.classList.add('open');
                    this.classList.add('open');
                }
            });
        });
        
        // Close mobile menu when clicking a link (except dropdown toggles)
        document.querySelectorAll('.mobile-nav-link:not(.mobile-dropdown-toggle)').forEach(link => {
            link.addEventListener('click', function(e) {
                if (!this.classList.contains('mobile-dropdown-toggle')) {
                    setTimeout(closeMobileMenu, 200);
                }
            });
        });

        // ============================================================
        // SESSION TIMEOUT FUNCTIONALITY
        // ============================================================
        const SESSION_TIMEOUT_MINUTES = 30;
        const WARNING_MINUTES = 1;
        const SESSION_TIMEOUT_MS = SESSION_TIMEOUT_MINUTES * 60 * 1000;
        const WARNING_MS = WARNING_MINUTES * 60 * 1000;
        
        const timeoutModal = document.getElementById('timeoutModal');
        const timeoutTimer = document.getElementById('timeoutTimer');
        const stayLoggedInBtn = document.getElementById('stayLoggedInBtn');
        const logoutNowBtn = document.getElementById('logoutNowBtn');
        const sessionToast = document.getElementById('sessionToast');
        const toastMessage = document.getElementById('toastMessage');
        
        let inactivityTimer = null;
        let warningTimer = null;
        let countdownTimer = null;
        let warningShown = false;
        let isWarningActive = false;
        
        function resetInactivityTimer() {
            if (inactivityTimer) clearTimeout(inactivityTimer);
            if (warningTimer) clearTimeout(warningTimer);
            if (countdownTimer) clearInterval(countdownTimer);
            
            hideWarning();
            warningShown = false;
            isWarningActive = false;
            
            inactivityTimer = setTimeout(logoutUser, SESSION_TIMEOUT_MS);
            warningTimer = setTimeout(showWarning, SESSION_TIMEOUT_MS - WARNING_MS);
            sendHeartbeat();
        }
        
        function showWarning() {
            if (warningShown) return;
            warningShown = true;
            isWarningActive = true;
            showSessionToast();
            setTimeout(() => {
                if (isWarningActive) {
                    timeoutModal.classList.add('active');
                    startCountdown(WARNING_MS / 1000);
                }
            }, 500);
        }
        
        function startCountdown(seconds) {
            let remainingSeconds = seconds;
            updateTimerDisplay(remainingSeconds);
            countdownTimer = setInterval(() => {
                remainingSeconds--;
                updateTimerDisplay(remainingSeconds);
                if (remainingSeconds <= 0) {
                    clearInterval(countdownTimer);
                    logoutUser();
                }
            }, 1000);
        }
        
        function updateTimerDisplay(seconds) {
            const minutes = Math.floor(seconds / 60);
            const secs = seconds % 60;
            const formattedTime = `${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
            if (timeoutTimer) timeoutTimer.textContent = formattedTime;
            if (toastMessage) toastMessage.textContent = `Your session will expire in ${minutes} minute${minutes !== 1 ? 's' : ''}`;
        }
        
        function showSessionToast() {
            if (sessionToast) {
                sessionToast.classList.add('show');
                setTimeout(() => {
                    if (sessionToast.classList.contains('show') && timeoutModal.classList.contains('active')) {
                    } else {
                        closeSessionToast();
                    }
                }, 10000);
            }
        }
        
        function closeSessionToast() {
            if (sessionToast) sessionToast.classList.remove('show');
        }
        
        function hideWarning() {
            if (timeoutModal) timeoutModal.classList.remove('active');
            if (countdownTimer) clearInterval(countdownTimer);
            closeSessionToast();
            isWarningActive = false;
        }
        
        function stayLoggedIn() {
            sendHeartbeat(true);
            resetInactivityTimer();
            hideWarning();
        }
        
        function logoutUser() {
            if (inactivityTimer) clearTimeout(inactivityTimer);
            if (warningTimer) clearTimeout(warningTimer);
            if (countdownTimer) clearInterval(countdownTimer);
            sendHeartbeatLogout();
            window.location.href = 'logout.php';
        }
        
        function sendHeartbeat(force = false) {
            fetch('heartbeat.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                credentials: 'same-origin'
            }).catch(error => {
                console.debug('Heartbeat failed:', error);
            });
        }
        
        function sendHeartbeatLogout() {
            if (navigator.sendBeacon) {
                navigator.sendBeacon('heartbeat.php', 'action=logout');
            } else {
                fetch('heartbeat.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=logout',
                    keepalive: true
                }).catch(() => {});
            }
        }
        
        const activityEvents = ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart', 'click', 'keydown'];
        activityEvents.forEach(event => {
            document.addEventListener(event, resetInactivityTimer);
        });
        
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) resetInactivityTimer();
        });
        window.addEventListener('focus', resetInactivityTimer);
        
        if (stayLoggedInBtn) stayLoggedInBtn.addEventListener('click', stayLoggedIn);
        if (logoutNowBtn) logoutNowBtn.addEventListener('click', logoutUser);
        
        resetInactivityTimer();
        
        // Close mobile menu on window resize (if switching from mobile to desktop)
        window.addEventListener('resize', function() {
            if (window.innerWidth > 1024 && mobileNavMenu.classList.contains('open')) {
                closeMobileMenu();
            }
        });
        
        // Set active state on current page in mobile menu (handled by PHP class)
    </script>
</body>
</html>