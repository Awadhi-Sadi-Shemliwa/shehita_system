<?php
/**
 * PAPLONTECH Enterprise Management System
 * Home Dashboard Module - Main landing page with key metrics
 * 
 * ENHANCED: Added unassigned projects notification badge
 * ENHANCED: Logo container styling matches company-settings.php
 * REFACTORED: Removed Quick Links section for cleaner dashboard
 * REFACTORED: Removed all sidebar-related code (Issue #1)
 * REFACTORED: Added foreign key table validation (Issue #2)
 * REFACTORED: Ensured no conflict with homepage.php (Issue #3)
 * FIXED: All CSS now properly prefixed with 'home-' to prevent navbar conflicts
 * 
 * This module provides:
 * - Welcome section with user info and company logo
 * - Dashboard statistics cards with unassigned projects notification
 * - Real-time data from database with AJAX auto-refresh
 * - Full English/Swahili translation support
 * - Permission-based access control
 * 
 * @package PAPLONTECH_EMS
 * @version 1.3
 */

// Prevent direct access
if (!defined('ACCESS_ALLOWED') && basename($_SERVER['PHP_SELF']) == basename(__FILE__)) {
    return;
}

// Get database connection from parent
global $conn;

// ============================================================================
// ISSUE #2: FOREIGN KEY TABLE VALIDATION
// Check if required tables exist before proceeding
// ============================================================================

$home_missing_tables = [];

// Check for projects table
$check_projects = $conn->query("SHOW TABLES LIKE 'projects'");
if ($check_projects->num_rows == 0) {
    $home_missing_tables[] = ['table' => 'projects', 'module' => 'projects', 'display' => 'Projects'];
}

// Check for operations table
$check_operations = $conn->query("SHOW TABLES LIKE 'operations'");
if ($check_operations->num_rows == 0) {
    $home_missing_tables[] = ['table' => 'operations', 'module' => 'operations', 'display' => 'Operations'];
}

// Check for customers table
$check_customers = $conn->query("SHOW TABLES LIKE 'customers'");
if ($check_customers->num_rows == 0) {
    $home_missing_tables[] = ['table' => 'customers', 'module' => 'customer-management', 'display' => 'Customer Management'];
}

// Check for company_settings table
$check_company = $conn->query("SHOW TABLES LIKE 'company_settings'");
if ($check_company->num_rows == 0) {
    $home_missing_tables[] = ['table' => 'company_settings', 'module' => 'company-settings', 'display' => 'Company Settings'];
}

// Display error message if any required tables are missing
if (!empty($home_missing_tables)) {
    echo '<div class="home-missing-tables" style="max-width: 800px; margin: 40px auto; padding: 30px; background: #fff3cd; border: 1px solid #ffeeba; border-radius: 12px; text-align: left;">';
    echo '<div style="display: flex; align-items: center; gap: 16px; margin-bottom: 20px;">';
    echo '<i class="fas fa-exclamation-triangle" style="font-size: 32px; color: #856404;"></i>';
    echo '<strong style="font-size: 18px; color: #856404;">⚠️ Required Tables Missing!</strong>';
    echo '</div>';
    echo '<p style="margin-bottom: 16px; color: #856404;">The following required tables do not exist in the database. Please open the related modules first to automatically create them:</p>';
    echo '<ul style="margin: 12px 0 16px 20px; color: #856404;">';
    foreach ($home_missing_tables as $missing) {
        echo '<li style="margin-bottom: 8px;"><strong>' . htmlspecialchars($missing['table']) . '</strong> → Please open the <strong>' . htmlspecialchars($missing['display']) . '</strong> module first</li>';
    }
    echo '</ul>';
    echo '<p style="margin-top: 16px; color: #856404;">After opening the required modules, refresh this page to continue.</p>';
    echo '</div>';
    return; // Stop rendering the module
}

// ============================================================================
// PERMISSION CHECK
// ============================================================================
$user_role_id = $_SESSION['role_id'] ?? 0;
$module_name = 'home';

if (!canView($conn, $user_role_id, $module_name)) {
    echo '<div class="home-access-denied">
            <i class="fas fa-lock"></i>
            <h3>Access Denied</h3>
            <p>You do not have permission to view this module.</p>
          </div>';
    return;
}

// ============================================================================
// AJAX REQUEST HANDLING
// ============================================================================
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    
    header('Content-Type: application/json');
    
    if (isset($_GET['action'])) {
        switch ($_GET['action']) {
            case 'get_dashboard_stats':
                // Return JSON stats including unassigned projects
                $stats = home_getDashboardStats($conn, $user_role_id);
                echo json_encode($stats);
                break;
                
            case 'get_company_logo':
                // Return JSON logo URL
                $logo = home_getCompanyLogoUrl($conn);
                echo json_encode(['logo_url' => $logo]);
                break;
                
            case 'get_welcome_data':
                // Return welcome data
                $data = home_getWelcomeData();
                echo json_encode($data);
                break;
        }
    }
    exit;
}

// ============================================================================
// HELPER FUNCTIONS (namespaced with home_ prefix)
// ============================================================================

/**
 * Get dashboard statistics with permission awareness
 * ENHANCED: Added unassigned_projects count (active projects not linked to any operation)
 * 
 * @param mysqli $conn Database connection
 * @param int $user_role_id User's role ID
 * @return array Dashboard stats
 */
function home_getDashboardStats($conn, $user_role_id) {
    $stats = [
        'active_projects' => 0,
        'unassigned_projects' => 0,
        'active_operations' => 0,
        'total_customers' => 0,
        'company_status' => 'Not configured',
        'company_status_class' => 'danger',
        'company_name' => '',
        'company_address' => '',
        'company_phone' => '',
        'company_email' => ''
    ];
    
    // Super Admin sees all data
    if ($user_role_id == 1) {
        // Active Projects Count
        $result = $conn->query("SELECT COUNT(*) as count FROM projects WHERE status = 'Active'");
        if ($result) {
            $stats['active_projects'] = (int)$result->fetch_assoc()['count'];
        }
        
        // Unassigned Projects Count
        $unassigned_query = "
            SELECT COUNT(*) as count 
            FROM projects p 
            WHERE p.status = 'Active' 
            AND p.contract_number NOT IN (
                SELECT DISTINCT contract_number 
                FROM operations 
                WHERE contract_number IS NOT NULL
            )
        ";
        $unassigned_result = $conn->query($unassigned_query);
        if ($unassigned_result) {
            $stats['unassigned_projects'] = (int)$unassigned_result->fetch_assoc()['count'];
        }
        
        // Active Operations Count
        $result = $conn->query("SELECT COUNT(*) as count FROM operations WHERE status = 'Active'");
        if ($result) {
            $stats['active_operations'] = (int)$result->fetch_assoc()['count'];
        }
        
        // Total Active Customers Count
        $result = $conn->query("SELECT COUNT(*) as count FROM customers WHERE status = 'Active'");
        if ($result) {
            $stats['total_customers'] = (int)$result->fetch_assoc()['count'];
        }
    } else {
        // Non-admin users: apply permission-based filtering
        $result = $conn->query("SELECT COUNT(*) as count FROM projects WHERE status = 'Active'");
        if ($result) {
            $stats['active_projects'] = (int)$result->fetch_assoc()['count'];
        }
        
        $unassigned_query = "
            SELECT COUNT(*) as count 
            FROM projects p 
            WHERE p.status = 'Active' 
            AND p.contract_number NOT IN (
                SELECT DISTINCT contract_number 
                FROM operations 
                WHERE contract_number IS NOT NULL
            )
        ";
        $unassigned_result = $conn->query($unassigned_query);
        if ($unassigned_result) {
            $stats['unassigned_projects'] = (int)$unassigned_result->fetch_assoc()['count'];
        }
        
        $result = $conn->query("SELECT COUNT(*) as count FROM operations WHERE status = 'Active'");
        if ($result) {
            $stats['active_operations'] = (int)$result->fetch_assoc()['count'];
        }
        
        $result = $conn->query("SELECT COUNT(*) as count FROM customers WHERE status = 'Active'");
        if ($result) {
            $stats['total_customers'] = (int)$result->fetch_assoc()['count'];
        }
    }
    
    // Company Status
    $result = $conn->query("SELECT company_name, company_address, company_phone, company_email, logo_url FROM company_settings LIMIT 1");
    if ($result && $result->num_rows > 0) {
        $company = $result->fetch_assoc();
        $stats['company_name'] = $company['company_name'] ?? '';
        $stats['company_address'] = $company['company_address'] ?? '';
        $stats['company_phone'] = $company['company_phone'] ?? '';
        $stats['company_email'] = $company['company_email'] ?? '';
        
        // Check if company is configured (has at least name and email)
        if (!empty($stats['company_name']) && !empty($stats['company_email'])) {
            $stats['company_status'] = 'Configured';
            $stats['company_status_class'] = 'success';
        } else {
            $stats['company_status'] = 'Not configured';
            $stats['company_status_class'] = 'warning';
        }
    }
    
    return $stats;
}

/**
 * Get company logo URL
 * 
 * @param mysqli $conn Database connection
 * @return string Logo URL or empty string
 */
function home_getCompanyLogoUrl($conn) {
    $result = $conn->query("SELECT logo_url FROM company_settings LIMIT 1");
    if ($result && $result->num_rows > 0) {
        $logo = $result->fetch_assoc()['logo_url'];
        if (!empty($logo) && file_exists($logo)) {
            return $logo;
        }
        // Check with ../ prefix for relative paths
        if (!empty($logo) && file_exists('../' . $logo)) {
            return '../' . $logo;
        }
    }
    return '';
}

/**
 * Get welcome data from session
 * 
 * @return array Welcome data
 */
function home_getWelcomeData() {
    return [
        'user_name' => $_SESSION['name'] ?? 'User',
        'user_role' => $_SESSION['role_name'] ?? (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1 ? 'Super Admin' : 'User'),
        'current_datetime' => date('l, F j, Y - g:i A'),
        'current_date' => date('F j, Y'),
        'current_time' => date('g:i A')
    ];
}

// Get initial stats for page load
$home_stats = home_getDashboardStats($conn, $user_role_id);

// Get company logo
$home_logo_url = home_getCompanyLogoUrl($conn);
$home_logo_exists = !empty($home_logo_url);

// Get user welcome data
$home_welcome_data = home_getWelcomeData();
?>

<!-- ============================================================================
     HOME MODULE TRANSLATIONS
     ============================================================================ -->
<script>
// Home module translations for English and Swahili
const home_translations = {
    en: {
        welcomeBack: 'Welcome Back',
        dashboardTitle: 'Dashboard',
        dashboardSubtitle: 'Welcome to your management dashboard',
        activeProjects: 'Active Projects',
        unassignedProjects: 'Unassigned Projects',
        activeOperations: 'Active Operations',
        totalCustomers: 'Total Customers',
        companyStatus: 'Company Status',
        configured: 'Configured',
        notConfigured: 'Not configured',
        viewDetails: 'View Details',
        companyInfo: 'Company Information',
        dateTime: 'Date & Time',
        role: 'Role',
        statistics: 'Statistics',
        refreshData: 'Refresh Data',
        lastUpdated: 'Last updated',
        justNow: 'just now',
        secondsAgo: 'seconds ago',
        minuteAgo: 'minute ago',
        minutesAgo: 'minutes ago',
        loading: 'Loading...',
        errorLoading: 'Error loading data'
    },
    sw: {
        welcomeBack: 'Karibu Tena',
        dashboardTitle: 'Dashibodi',
        dashboardSubtitle: 'Karibu kwenye dashibodi yako ya usimamizi',
        activeProjects: 'Miradi Inayoendelea',
        unassignedProjects: 'Miradi Isiyopangiwa',
        activeOperations: 'Uendeshaji Unaendelea',
        totalCustomers: 'Jumla ya Wateja',
        companyStatus: 'Hali ya Kampuni',
        configured: 'Imesanidiwa',
        notConfigured: 'Haijasanidiwa',
        viewDetails: 'Angalia Maelezo',
        companyInfo: 'Taarifa za Kampuni',
        dateTime: 'Tarehe na Saa',
        role: 'Nafasi',
        statistics: 'Takwimu',
        refreshData: 'Onesha Upya Data',
        lastUpdated: 'Ilisasishwa mwisho',
        justNow: 'sasa hivi',
        secondsAgo: 'sekunde zilizopita',
        minuteAgo: 'dakika iliyopita',
        minutesAgo: 'dakika zilizopita',
        loading: 'Inapakia...',
        errorLoading: 'Hitilafu katika kupakia data'
    }
};

// Current language
let homeCurrentLang = localStorage.getItem('preferredLanguage') || 'en';

// Function to update all translatable elements
function home_updateLanguage(lang) {
    homeCurrentLang = lang;
    const elements = document.querySelectorAll('[data-home-lang]');
    
    elements.forEach(element => {
        const key = element.getAttribute('data-home-lang');
        if (home_translations[lang] && home_translations[lang][key]) {
            if (element.tagName === 'INPUT' || element.tagName === 'TEXTAREA') {
                if (element.placeholder !== undefined) {
                    element.placeholder = home_translations[lang][key];
                }
            } else {
                element.textContent = home_translations[lang][key];
            }
        }
    });
}

// This function will be called from homepage.js when language changes
window.updateHomeLanguage = home_updateLanguage;

// Document ready - NO SIDEBAR CODE (Issue #1 resolved)
document.addEventListener('DOMContentLoaded', function() {
    home_updateLanguage(homeCurrentLang);
});
</script>

<!-- ============================================================================
     HOME MODULE STYLES - All prefixed with home- to avoid conflicts (Issue #3)
     ============================================================================ -->
<style>
    /* Home Module Styles - Using home- prefix for isolation */
    .home-container {
        width: 100%;
        animation: home-fadeIn 0.4s ease-out;
    }
    
    @keyframes home-fadeIn {
        from {
            opacity: 0;
            transform: translateY(10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    /* Welcome Hero Section */
    .home-welcome-section {
        background: linear-gradient(135deg, #8b5a2b 0%, #5d3e1e 100%);
        border-radius: 24px;
        padding: 32px 40px;
        margin-bottom: 32px;
        color: white;
        position: relative;
        overflow: hidden;
        box-shadow: var(--shadow-lg);
    }
    
    .home-welcome-section::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -20%;
        width: 300px;
        height: 300px;
        background: rgba(255, 255, 255, 0.05);
        border-radius: 50%;
        pointer-events: none;
    }
    
    .home-welcome-section::after {
        content: '';
        position: absolute;
        bottom: -30%;
        left: -10%;
        width: 200px;
        height: 200px;
        background: rgba(255, 255, 255, 0.03);
        border-radius: 50%;
        pointer-events: none;
    }
    
    .home-welcome-content {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 24px;
        position: relative;
        z-index: 1;
    }
    
    .home-welcome-text h1 {
        font-family: 'Montserrat', sans-serif;
        font-size: 28px;
        font-weight: 700;
        margin-bottom: 8px;
    }
    
    .home-welcome-text p {
        font-size: 16px;
        opacity: 0.9;
        margin-bottom: 16px;
    }
    
    .home-user-badge {
        display: inline-flex;
        align-items: center;
        gap: 12px;
        background: rgba(255, 255, 255, 0.15);
        padding: 8px 20px;
        border-radius: 40px;
        backdrop-filter: blur(4px);
    }
    
    .home-user-badge i {
        font-size: 18px;
    }
    
    .home-user-badge span {
        font-weight: 500;
    }
    
    .home-role-badge {
        background: rgba(255, 255, 255, 0.2);
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 500;
    }
    
    /* Logo Container - Styled to match company-settings.php */
    .home-welcome-logo {
        background: white;
        border-radius: 12px;
        padding: 12px 20px;
        text-align: center;
        transition: all 0.3s ease;
        box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05);
        border: 1px solid #e2e8f0;
        min-width: 180px;
        max-width: 240px;
    }
    
    .home-welcome-logo:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(0, 0, 0, 0.1);
    }
    
    .home-welcome-logo img {
        max-width: 320px;
        max-height: 120px;
        width: auto;
        height: auto;
        object-fit: contain;
        display: block;
        margin: 0 auto;
    }
    
    .home-welcome-logo-placeholder {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 8px;
        color: var(--brown-700);
    }
    
    .home-welcome-logo-placeholder i {
        font-size: 48px;
        color: var(--brown-500);
    }
    
    .home-welcome-logo-placeholder span {
        font-size: 12px;
        font-weight: 500;
    }
    
    /* Stats Grid */
    .home-stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 24px;
        margin-bottom: 32px;
    }
    
    .home-stat-card {
        background: white;
        border-radius: 20px;
        padding: 24px;
        box-shadow: var(--shadow-md);
        border: 1px solid var(--gray-200);
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
    }
    
    .home-stat-card:hover {
        transform: translateY(-4px);
        box-shadow: var(--shadow-lg);
    }
    
    .home-stat-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 4px;
        height: 100%;
    }
    
    .home-stat-card-primary::before {
        background: #8b5a2b;
    }
    
    .home-stat-card-success::before {
        background: #28a745;
    }
    
    .home-stat-card-info::before {
        background: #17a2b8;
    }
    
    .home-stat-card-warning::before {
        background: #ffc107;
    }
    
    /* Notification Badge Styles */
    .home-stat-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 16px;
    }
    
    .home-notification-badge {
        background: #dc3545;
        color: white;
        border-radius: 20px;
        padding: 4px 12px;
        font-size: 12px;
        font-weight: 600;
        min-width: 28px;
        text-align: center;
        box-shadow: 0 1px 3px rgba(0,0,0,0.2);
        animation: home-pulse 2s infinite;
    }
    
    @keyframes home-pulse {
        0% {
            transform: scale(1);
            opacity: 1;
        }
        50% {
            transform: scale(1.05);
            opacity: 0.9;
        }
        100% {
            transform: scale(1);
            opacity: 1;
        }
    }
    
    .home-stat-icon {
        width: 48px;
        height: 48px;
        border-radius: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
    }
    
    .home-stat-icon-primary {
        background: rgba(139, 90, 43, 0.1);
        color: #8b5a2b;
    }
    
    .home-stat-icon-success {
        background: rgba(40, 167, 69, 0.1);
        color: #28a745;
    }
    
    .home-stat-icon-info {
        background: rgba(23, 162, 184, 0.1);
        color: #17a2b8;
    }
    
    .home-stat-icon-warning {
        background: rgba(255, 193, 7, 0.1);
        color: #ffc107;
    }
    
    .home-stat-value {
        font-size: 32px;
        font-weight: 700;
        color: var(--gray-800);
        margin-bottom: 4px;
    }
    
    .home-stat-label {
        font-size: 14px;
        color: var(--gray-500);
        font-weight: 500;
    }
    
    .home-stat-footer {
        margin-top: 16px;
        padding-top: 12px;
        border-top: 1px solid var(--gray-200);
    }
    
    .home-stat-link {
        color: #8b5a2b;
        text-decoration: none;
        font-size: 13px;
        font-weight: 500;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: all 0.2s;
    }
    
    .home-stat-link:hover {
        gap: 10px;
        color: #5d3e1e;
    }
    
    /* Status Badge */
    .home-status-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 500;
    }
    
    .home-status-success {
        background: #d4edda;
        color: #155724;
    }
    
    .home-status-warning {
        background: #fff3cd;
        color: #856404;
    }
    
    .home-status-danger {
        background: #f8d7da;
        color: #721c24;
    }
    
    /* Company Information Card - Full width */
    .home-company-card {
        background: white;
        border-radius: 20px;
        padding: 24px;
        box-shadow: var(--shadow-md);
        border: 1px solid var(--gray-200);
        transition: all 0.2s;
        max-width: 100%;
        margin: 0 auto;
    }
    
    .home-company-card:hover {
        box-shadow: var(--shadow-lg);
    }
    
    .home-company-header {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 20px;
        padding-bottom: 16px;
        border-bottom: 2px solid var(--gray-200);
    }
    
    .home-company-header i {
        font-size: 24px;
        color: #8b5a2b;
    }
    
    .home-company-header h3 {
        font-size: 18px;
        font-weight: 600;
        color: var(--gray-800);
        margin: 0;
    }
    
    .home-company-content {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }
    
    .home-company-row {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 8px 0;
        border-bottom: 1px dashed var(--gray-200);
    }
    
    .home-company-row:last-child {
        border-bottom: none;
    }
    
    .home-company-row i {
        width: 24px;
        color: var(--gray-500);
        font-size: 14px;
    }
    
    .home-company-label {
        flex: 1;
        font-size: 14px;
        color: var(--gray-600);
    }
    
    .home-company-value {
        font-weight: 500;
        color: var(--gray-800);
        font-size: 14px;
    }
    
    /* Refresh Button */
    .home-refresh-btn {
        position: fixed;
        bottom: 30px;
        right: 30px;
        width: 48px;
        height: 48px;
        border-radius: 50%;
        background: #8b5a2b;
        color: white;
        border: none;
        cursor: pointer;
        box-shadow: var(--shadow-lg);
        transition: all 0.2s;
        z-index: 100;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .home-refresh-btn:hover {
        background: #5d3e1e;
        transform: scale(1.05);
    }
    
    .home-refresh-btn.loading {
        animation: home-spin 1s linear infinite;
    }
    
    @keyframes home-spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }
    
    /* Last Updated Text */
    .home-last-updated {
        text-align: right;
        font-size: 12px;
        color: var(--gray-500);
        margin-top: 24px;
        padding-top: 16px;
        border-top: 1px solid var(--gray-200);
    }
    
    /* Access Denied */
    .home-access-denied {
        text-align: center;
        padding: 60px 40px;
        background: white;
        border-radius: 24px;
        box-shadow: var(--shadow-xl);
    }
    
    .home-access-denied i {
        font-size: 64px;
        color: #dc3545;
        margin-bottom: 20px;
    }
    
    .home-access-denied h3 {
        font-size: 24px;
        color: var(--gray-800);
        margin-bottom: 12px;
    }
    
    .home-access-denied p {
        color: var(--gray-600);
    }
    
    /* Missing Tables Error */
    .home-missing-tables {
        animation: home-fadeIn 0.3s ease-out;
    }
    
    .home-missing-tables ul {
        list-style: disc;
    }
    
    .home-missing-tables li {
        margin: 8px 0;
    }
    
    /* Loading States */
    .home-loading {
        display: inline-block;
        width: 20px;
        height: 20px;
        border: 2px solid var(--gray-300);
        border-top-color: #8b5a2b;
        border-radius: 50%;
        animation: home-spin 0.8s linear infinite;
    }
    
    .home-stat-value-loading {
        height: 36px;
        width: 80px;
        background: linear-gradient(90deg, var(--gray-200) 25%, var(--gray-100) 50%, var(--gray-200) 75%);
        background-size: 200% 100%;
        animation: home-shimmer 1.5s infinite;
        border-radius: 8px;
    }
    
    @keyframes home-shimmer {
        0% { background-position: 200% 0; }
        100% { background-position: -200% 0; }
    }
    
    /* Responsive */
    @media (max-width: 768px) {
        .home-welcome-section {
            padding: 24px 20px;
        }
        
        .home-welcome-content {
            flex-direction: column;
            text-align: center;
        }
        
        .home-welcome-text h1 {
            font-size: 24px;
        }
        
        .home-welcome-logo {
            min-width: auto;
            max-width: 100%;
            padding: 16px 20px;
        }
        
        .home-welcome-logo img {
            max-height: 55px;
        }
        
        .home-stats-grid {
            grid-template-columns: 1fr;
            gap: 16px;
        }
        
        .home-refresh-btn {
            bottom: 20px;
            right: 20px;
            width: 40px;
            height: 40px;
        }
    }
    
    @media (max-width: 480px) {
        .home-welcome-text h1 {
            font-size: 20px;
        }
        
        .home-stat-value {
            font-size: 28px;
        }
        
        .home-company-header h3 {
            font-size: 16px;
        }
        
        .home-welcome-logo img {
            max-height: 45px;
        }
    }
</style>

<!-- ============================================================================
     HOME MODULE HTML
     ============================================================================ -->
<div class="home-container">
    <!-- Welcome Hero Section -->
    <div class="home-welcome-section">
        <div class="home-welcome-content">
            <div class="home-welcome-text">
                <h1 data-home-lang="welcomeBack">Welcome Back</h1>
                <p><strong><?= htmlspecialchars($home_welcome_data['user_name']) ?></strong></p>
                <div class="home-user-badge">
                    <i class="fas fa-user-circle"></i>
                    <span data-home-lang="role">Role</span>
                    <span>:</span>
                    <span class="home-role-badge"><?= htmlspecialchars($home_welcome_data['user_role']) ?></span>
                </div>
                <div class="home-user-badge" style="margin-top: 8px;">
                    <i class="fas fa-calendar-alt"></i>
                    <span><?= htmlspecialchars($home_welcome_data['current_datetime']) ?></span>
                </div>
            </div>
            <div class="home-welcome-logo">
                <?php if ($home_logo_exists): ?>
                    <img src="<?= htmlspecialchars($home_logo_url) ?>" alt="Company Logo" id="home_companyLogo">
                <?php else: ?>
                    <div class="home-welcome-logo-placeholder">
                        <i class="fas fa-building"></i>
                        <span data-home-lang="companyInfo">Company Information</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Statistics Cards Grid -->
    <div class="home-stats-grid" id="home_statsGrid">
        <!-- Active Projects Card with Unassigned Notification Badge -->
        <div class="home-stat-card home-stat-card-primary">
            <div class="home-stat-header">
                <div class="home-stat-icon home-stat-icon-primary">
                    <i class="fas fa-project-diagram"></i>
                </div>
                <div class="home-notification-badge" id="home_unassignedBadge" style="display: <?= $home_stats['unassigned_projects'] > 0 ? 'flex' : 'none' ?>;">
                    <?= $home_stats['unassigned_projects'] ?>
                </div>
            </div>
            <div class="home-stat-value" id="home_activeProjects"><?= number_format($home_stats['active_projects']) ?></div>
            <div class="home-stat-label" data-home-lang="activeProjects">Active Projects</div>
            <div class="home-stat-footer">
                <a href="?page=projects&status_filter=Active" class="home-stat-link" data-home-lang="viewDetails">
                    View Details <i class="fas fa-arrow-right"></i>
                </a>
            </div>
        </div>
        
        <!-- Active Operations Card -->
        <div class="home-stat-card home-stat-card-success">
            <div class="home-stat-header">
                <div class="home-stat-icon home-stat-icon-success">
                    <i class="fas fa-tasks"></i>
                </div>
            </div>
            <div class="home-stat-value" id="home_activeOperations"><?= number_format($home_stats['active_operations']) ?></div>
            <div class="home-stat-label" data-home-lang="activeOperations">Active Operations</div>
            <div class="home-stat-footer">
                <a href="?page=operations&status_filter=Active" class="home-stat-link" data-home-lang="viewDetails">
                    View Details <i class="fas fa-arrow-right"></i>
                </a>
            </div>
        </div>
        
        <!-- Total Customers Card -->
        <div class="home-stat-card home-stat-card-info">
            <div class="home-stat-header">
                <div class="home-stat-icon home-stat-icon-info">
                    <i class="fas fa-users"></i>
                </div>
            </div>
            <div class="home-stat-value" id="home_totalCustomers"><?= number_format($home_stats['total_customers']) ?></div>
            <div class="home-stat-label" data-home-lang="totalCustomers">Total Customers</div>
            <div class="home-stat-footer">
                <a href="?page=customer-management" class="home-stat-link" data-home-lang="viewDetails">
                    View Details <i class="fas fa-arrow-right"></i>
                </a>
            </div>
        </div>
        
        <!-- Company Status Card -->
        <div class="home-stat-card home-stat-card-warning">
            <div class="home-stat-header">
                <div class="home-stat-icon home-stat-icon-warning">
                    <i class="fas fa-building"></i>
                </div>
            </div>
            <div class="home-stat-value">
                <span class="home-status-badge home-status-<?= $home_stats['company_status_class'] ?>" id="home_companyStatusBadge">
                    <i class="fas fa-<?= $home_stats['company_status_class'] == 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i>
                    <span data-home-lang="<?= $home_stats['company_status'] == 'Configured' ? 'configured' : 'notConfigured' ?>">
                        <?= $home_stats['company_status'] ?>
                    </span>
                </span>
            </div>
            <div class="home-stat-label" data-home-lang="companyStatus">Company Status</div>
            <div class="home-stat-footer">
                <a href="?page=company-settings" class="home-stat-link">
                    Configure <i class="fas fa-cog"></i>
                </a>
            </div>
        </div>
    </div>
    
    <!-- Company Information Card - Full width -->
    <div class="home-company-card">
        <div class="home-company-header">
            <i class="fas fa-building"></i>
            <h3 data-home-lang="companyInfo">Company Information</h3>
        </div>
        <div class="home-company-content" id="home_companyInfo">
            <div class="home-company-row">
                <i class="fas fa-building"></i>
                <span class="home-company-label">Company Name:</span>
                <span class="home-company-value" id="home_companyName"><?= htmlspecialchars($home_stats['company_name'] ?: '—') ?></span>
            </div>
            <div class="home-company-row">
                <i class="fas fa-map-marker-alt"></i>
                <span class="home-company-label">Address:</span>
                <span class="home-company-value" id="home_companyAddress"><?= htmlspecialchars($home_stats['company_address'] ?: '—') ?></span>
            </div>
            <div class="home-company-row">
                <i class="fas fa-phone"></i>
                <span class="home-company-label">Phone:</span>
                <span class="home-company-value" id="home_companyPhone"><?= htmlspecialchars($home_stats['company_phone'] ?: '—') ?></span>
            </div>
            <div class="home-company-row">
                <i class="fas fa-envelope"></i>
                <span class="home-company-label">Email:</span>
                <span class="home-company-value" id="home_companyEmail"><?= htmlspecialchars($home_stats['company_email'] ?: '—') ?></span>
            </div>
        </div>
    </div>
    
    <!-- Last Updated -->
    <div class="home-last-updated">
        <i class="fas fa-clock"></i>
        <span data-home-lang="lastUpdated">Last updated</span>
        <span id="home_lastUpdated"><?= date('H:i:s') ?></span>
    </div>
</div>

<!-- Refresh Button -->
<button class="home-refresh-btn" id="home_refreshBtn" title="Refresh Dashboard Data">
    <i class="fas fa-sync-alt"></i>
</button>

<!-- ============================================================================
     HOME MODULE JAVASCRIPT - NO SIDEBAR CODE (Issue #1 resolved)
     ============================================================================ -->
<script>
    // ============================================================================
    // DASHBOARD STATS MANAGEMENT - All functions namespaced with home_
    // ============================================================================
    
    let home_lastFetchTime = Date.now();
    let home_autoRefreshInterval = null;
    let home_isLoading = false;
    
    /**
     * Load dashboard statistics via AJAX
     * ENHANCED: Now includes unassigned_projects count for notification badge
     */
    async function home_loadDashboardStats() {
        if (home_isLoading) return;
        
        home_isLoading = true;
        const refreshBtn = document.getElementById('home_refreshBtn');
        
        // Show loading state on stats
        const statsElements = {
            activeProjects: document.getElementById('home_activeProjects'),
            activeOperations: document.getElementById('home_activeOperations'),
            totalCustomers: document.getElementById('home_totalCustomers'),
            unassignedBadge: document.getElementById('home_unassignedBadge')
        };
        
        // Store original values
        const originalValues = {
            activeProjects: statsElements.activeProjects?.innerHTML,
            activeOperations: statsElements.activeOperations?.innerHTML,
            totalCustomers: statsElements.totalCustomers?.innerHTML
        };
        
        // Add loading class
        if (refreshBtn) refreshBtn.classList.add('loading');
        
        try {
            const response = await fetch('?page=home&action=get_dashboard_stats', {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            
            if (!response.ok) throw new Error('Network response was not ok');
            
            const data = await response.json();
            
            // Update stats with animation
            if (statsElements.activeProjects) {
                home_animateNumber(statsElements.activeProjects, 
                    parseInt(originalValues.activeProjects?.replace(/,/g, '') || 0), 
                    data.active_projects);
            }
            
            if (statsElements.activeOperations) {
                home_animateNumber(statsElements.activeOperations,
                    parseInt(originalValues.activeOperations?.replace(/,/g, '') || 0),
                    data.active_operations);
            }
            
            if (statsElements.totalCustomers) {
                home_animateNumber(statsElements.totalCustomers,
                    parseInt(originalValues.totalCustomers?.replace(/,/g, '') || 0),
                    data.total_customers);
            }
            
            // Update Unassigned Projects Notification Badge
            if (statsElements.unassignedBadge) {
                const unassignedCount = data.unassigned_projects || 0;
                if (unassignedCount > 0) {
                    statsElements.unassignedBadge.style.display = 'flex';
                    statsElements.unassignedBadge.innerHTML = unassignedCount;
                } else {
                    statsElements.unassignedBadge.style.display = 'none';
                }
            }
            
            // Update company status badge
            const statusBadge = document.getElementById('home_companyStatusBadge');
            if (statusBadge) {
                const isConfigured = data.company_status === 'Configured';
                statusBadge.className = `home-status-badge home-status-${data.company_status_class}`;
                statusBadge.innerHTML = `<i class="fas fa-${isConfigured ? 'check-circle' : 'exclamation-triangle'}"></i> 
                                         <span data-home-lang="${isConfigured ? 'configured' : 'notConfigured'}">${data.company_status}</span>`;
                
                // Update translation for the new span
                const newSpan = statusBadge.querySelector('span');
                if (newSpan && home_translations[homeCurrentLang]) {
                    newSpan.textContent = home_translations[homeCurrentLang][isConfigured ? 'configured' : 'notConfigured'];
                }
            }
            
            // Update company info
            if (data.company_name) document.getElementById('home_companyName').innerHTML = home_escapeHtml(data.company_name);
            if (data.company_address) document.getElementById('home_companyAddress').innerHTML = home_escapeHtml(data.company_address) || '—';
            if (data.company_phone) document.getElementById('home_companyPhone').innerHTML = home_escapeHtml(data.company_phone) || '—';
            if (data.company_email) document.getElementById('home_companyEmail').innerHTML = home_escapeHtml(data.company_email) || '—';
            
            // Update last updated time
            home_lastFetchTime = Date.now();
            const lastUpdatedSpan = document.getElementById('home_lastUpdated');
            if (lastUpdatedSpan) {
                lastUpdatedSpan.innerHTML = new Date().toLocaleTimeString();
            }
            
        } catch (error) {
            console.error('Error loading dashboard stats:', error);
        } finally {
            home_isLoading = false;
            if (refreshBtn) refreshBtn.classList.remove('loading');
        }
    }
    
    /**
     * Load company logo via AJAX
     */
    async function home_loadCompanyLogo() {
        try {
            const response = await fetch('?page=home&action=get_company_logo', {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            
            if (!response.ok) throw new Error('Network response was not ok');
            
            const data = await response.json();
            const logoContainer = document.querySelector('.home-welcome-logo');
            
            if (data.logo_url && logoContainer) {
                logoContainer.innerHTML = `<img src="${home_escapeHtml(data.logo_url)}" alt="Company Logo" id="home_companyLogo" style="max-width: 180px; max-height: 70px; width: auto; height: auto; object-fit: contain; display: block; margin: 0 auto;">`;
            } else if (logoContainer) {
                logoContainer.innerHTML = `<div class="home-welcome-logo-placeholder">
                    <i class="fas fa-building"></i>
                    <span data-home-lang="companyInfo">Company Information</span>
                </div>`;
                // Update translation for placeholder
                const placeholderSpan = logoContainer.querySelector('span');
                if (placeholderSpan && home_translations[homeCurrentLang]) {
                    placeholderSpan.textContent = home_translations[homeCurrentLang].companyInfo;
                }
            }
        } catch (error) {
            console.error('Error loading company logo:', error);
        }
    }
    
    /**
     * Animate number counting
     */
    function home_animateNumber(element, start, end) {
        if (!element) return;
        
        const duration = 500;
        const startTime = performance.now();
        
        function update(currentTime) {
            const elapsed = currentTime - startTime;
            const progress = Math.min(elapsed / duration, 1);
            const current = Math.floor(start + (end - start) * progress);
            element.innerHTML = current.toLocaleString();
            
            if (progress < 1) {
                requestAnimationFrame(update);
            }
        }
        
        requestAnimationFrame(update);
    }
    
    /**
     * Escape HTML for XSS prevention
     */
    function home_escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }
    
    /**
     * Start auto-refresh interval (every 30 seconds)
     */
    function home_startAutoRefresh() {
        if (home_autoRefreshInterval) {
            clearInterval(home_autoRefreshInterval);
        }
        home_autoRefreshInterval = setInterval(() => {
            home_loadDashboardStats();
        }, 30000);
    }
    
    /**
     * Stop auto-refresh interval
     */
    function home_stopAutoRefresh() {
        if (home_autoRefreshInterval) {
            clearInterval(home_autoRefreshInterval);
            home_autoRefreshInterval = null;
        }
    }
    
    // ============================================================================
    // OVERRIDE UPDATE HOME LANGUAGE FUNCTION
    // ============================================================================
    const originalUpdateHomeLanguage = window.updateHomeLanguage || function() {};
    
    window.updateHomeLanguage = function(lang) {
        originalUpdateHomeLanguage(lang);
        homeCurrentLang = lang;
        
        // Update all elements with data-home-lang attribute
        const elements = document.querySelectorAll('[data-home-lang]');
        elements.forEach(element => {
            const key = element.getAttribute('data-home-lang');
            if (home_translations[lang] && home_translations[lang][key]) {
                if (element.tagName === 'INPUT' || element.tagName === 'TEXTAREA') {
                    if (element.placeholder !== undefined) {
                        element.placeholder = home_translations[lang][key];
                    }
                } else {
                    element.textContent = home_translations[lang][key];
                }
            }
        });
        
        // Update company status badge text
        const statusBadge = document.getElementById('home_companyStatusBadge');
        if (statusBadge) {
            const badgeSpan = statusBadge.querySelector('span');
            if (badgeSpan) {
                const isConfigured = badgeSpan.textContent === 'Configured' || badgeSpan.textContent === 'Imesanidiwa';
                badgeSpan.textContent = home_translations[lang][isConfigured ? 'configured' : 'notConfigured'];
            }
        }
    };
    
    // ============================================================================
    // INITIALIZATION - NO SIDEBAR CODE (Issue #1 resolved)
    // ============================================================================
    document.addEventListener('DOMContentLoaded', function() {
        // Set initial language
        home_updateLanguage(homeCurrentLang);
        
        // Setup refresh button
        const refreshBtn = document.getElementById('home_refreshBtn');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', function() {
                home_loadDashboardStats();
                home_loadCompanyLogo();
            });
        }
        
        // Start auto-refresh
        home_startAutoRefresh();
        
        // Load initial data via AJAX (ensure fresh data)
        setTimeout(() => {
            home_loadDashboardStats();
            home_loadCompanyLogo();
        }, 500);
    });
    
    // Clean up interval on page unload
    window.addEventListener('beforeunload', function() {
        home_stopAutoRefresh();
    });
</script>