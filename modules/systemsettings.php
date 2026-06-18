<?php
/**
 * SHEHITA Enterprise Management System
 * System Settings Module - Global Configuration Management
 * 
 * REFINED: Removed all sidebar-related code (Issue #1)
 * REFINED: Ensured no conflict with homepage.php (top navbar layout) (Issue #3)
 * 
 * This module handles:
 * - Automatic table creation if not exists
 * - Display all system settings in a professional card layout
 * - Edit settings inline with validation
 * - Save updates to database with CSRF protection
 * - Reset to default values
 * - Full English/Swahili translation support
 * - Permission-based access control
 * 
 * PERMISSION ENHANCED: Only users with edit permission can modify settings
 * View permission is required to see the page
 */

// Prevent direct access
if (!defined('ACCESS_ALLOWED') && basename($_SERVER['PHP_SELF']) == basename(__FILE__)) {
    // This file is included, so we're safe
}

// Get database connection from parent
global $conn;

// PERMISSION: Get user role and check module permissions
$user_role_id = $_SESSION['role_id'] ?? 0;
$module_name = 'systemsettings';

// PERMISSION: Check if user has view permission
if (!canView($conn, $user_role_id, $module_name)) {
    echo '<div class="sys-alert sys-alert-danger" style="text-align: center; padding: 40px;">
            <i class="fas fa-lock" style="font-size: 48px; margin-bottom: 16px; display: block;"></i>
            <h3>Access Denied</h3>
            <p>You do not have permission to view this module.</p>
          </div>';
    return; // Stop rendering the module
}

/**
 * ============================================================================
 * DATABASE SCHEMA CREATION
 * ============================================================================
 */

// Create system_settings table if not exists
// Schema note: the `system_settings` table is created centrally in config.php
// (in dependency order). This module assumes it already exists.

/**
 * ============================================================================
 * DEFAULT SETTINGS INSERTION
 * ============================================================================
 */

// Define default settings
$default_settings = [
    // General Settings
    ['app_name', 'SHEHITA EMS', 'text', 'Application Name', 'System name displayed in browser title and headers', null, 10],
    ['default_language', 'en', 'select', 'Default Language', 'Default language for new users and guests', json_encode(['en' => 'English', 'sw' => 'Swahili']), 20],
    ['timezone', 'Africa/Dar_es_Salaam', 'select', 'Timezone', 'System timezone for date/time display', json_encode([
        'Africa/Dar_es_Salaam' => 'Africa/Dar_es_Salaam (EAT)',
        'Africa/Nairobi' => 'Africa/Nairobi (EAT)',
        'Africa/Kampala' => 'Africa/Kampala (EAT)',
        'UTC' => 'UTC'
    ]), 30],
    ['date_format', 'd M Y', 'select', 'Date Format', 'Format for displaying dates throughout the system', json_encode([
        'd M Y' => '15 Jan 2024',
        'Y-m-d' => '2024-01-15',
        'm/d/Y' => '01/15/2024',
        'd/m/Y' => '15/01/2024'
    ]), 40],
    ['items_per_page', '10', 'number', 'Items Per Page', 'Default number of records per page in tables', null, 50],
    
    // Security Settings
    ['enable_session_timeout', '1', 'toggle', 'Enable Session Timeout', 'Automatically log out inactive users', null, 110],
    ['session_timeout_minutes', '30', 'number', 'Session Timeout Minutes', 'Minutes of inactivity before auto-logout', null, 120],
    
    // Maintenance Settings
    ['maintenance_mode', '0', 'toggle', 'Maintenance Mode', 'Put the system in maintenance mode (only admins can access)', null, 210],
    ['maintenance_message', 'System under maintenance. Please check back later.', 'textarea', 'Maintenance Message', 'Message shown to users during maintenance', null, 220],
    
    // Branding Settings
    ['company_logo_path', 'uploads/systemlogo/logo.png', 'text', 'Company Logo Path', 'Path to the company logo image (relative to root)', null, 310],
    ['favicon_path', 'uploads/systemlogo/favicon.ico', 'text', 'Favicon Path', 'Path to the favicon image', null, 320],
    
    // Email Settings
    ['email_from_address', 'noreply@example.com', 'email', 'Email From Address', 'Default sender email address for system emails', null, 410],
    ['email_from_name', 'SHEHITA EMS', 'text', 'Email From Name', 'Default sender name for system emails', null, 420]
];

// Insert default settings if they don't exist
foreach ($default_settings as $setting) {
    list($key, $value, $type, $display_name, $description, $options, $sort_order) = $setting;
    
    $check_stmt = $conn->prepare("SELECT id FROM system_settings WHERE setting_key = ?");
    $check_stmt->bind_param("s", $key);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows == 0) {
        $insert_stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_type, display_name, description, options, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $insert_stmt->bind_param("ssssssi", $key, $value, $type, $display_name, $description, $options, $sort_order);
        $insert_stmt->execute();
        $insert_stmt->close();
    }
    $check_stmt->close();
}

/**
 * ============================================================================
 * CSRF PROTECTION
 * ============================================================================
 */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Initialize variables for messages
$settings_message = '';
$settings_message_type = '';

/**
 * ============================================================================
 * HANDLE FORM SUBMISSIONS
 * ============================================================================
 */

// PERMISSION: Check edit permission before processing update operation
if (isset($_POST['sys_update']) && !canEdit($conn, $user_role_id, $module_name)) {
    $settings_message = "You do not have permission to update system settings.";
    $settings_message_type = "danger";
} elseif (isset($_POST['sys_update'])) {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $settings_message = "Invalid form submission. Please try again.";
        $settings_message_type = "danger";
    } else {
        $errors = [];
        $success_count = 0;
        
        // Get all setting keys from database
        $settings_query = $conn->query("SELECT setting_key, setting_type FROM system_settings");
        
        if ($settings_query && $settings_query->num_rows > 0) {
            while ($setting = $settings_query->fetch_assoc()) {
                $key = $setting['setting_key'];
                $type = $setting['setting_type'];
                $value = isset($_POST['setting_' . $key]) ? trim($_POST['setting_' . $key]) : '';
                
                // Validate based on type
                if ($type === 'number') {
                    if (!is_numeric($value) && $value !== '') {
                        $errors[] = "{$key} must be a valid number";
                        continue;
                    }
                    $value = $value === '' ? '0' : $value;
                } elseif ($type === 'email' && !empty($value)) {
                    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        $errors[] = "{$key} must be a valid email address";
                        continue;
                    }
                } elseif ($type === 'toggle') {
                    $value = isset($_POST['setting_' . $key]) ? '1' : '0';
                }
                
                // Update the setting
                $update_stmt = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
                $update_stmt->bind_param("ss", $value, $key);
                
                if ($update_stmt->execute()) {
                    $success_count++;
                }
                $update_stmt->close();
            }
        }
        
        if (empty($errors)) {
            // Apply timezone setting immediately
            $timezone_query = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'timezone'");
            if ($timezone_query && $timezone_query->num_rows > 0) {
                $timezone = $timezone_query->fetch_assoc()['setting_value'];
                if (function_exists('date_default_timezone_set')) {
                    @date_default_timezone_set($timezone);
                }
            }
            
            $settings_message = "Settings saved successfully!";
            $settings_message_type = "success";
        } else {
            $settings_message = implode("<br>", $errors);
            $settings_message_type = "danger";
        }
    }
    
    // Generate new CSRF token
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// PERMISSION: Check edit permission before processing reset operation
if (isset($_POST['sys_reset']) && !canEdit($conn, $user_role_id, $module_name)) {
    $settings_message = "You do not have permission to reset system settings.";
    $settings_message_type = "danger";
} elseif (isset($_POST['sys_reset'])) {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $settings_message = "Invalid form submission. Please try again.";
        $settings_message_type = "danger";
    } else {
        // Reset all settings to defaults
        foreach ($default_settings as $setting) {
            list($key, $value, $type, $display_name, $description, $options, $sort_order) = $setting;
            $reset_stmt = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
            $reset_stmt->bind_param("ss", $value, $key);
            $reset_stmt->execute();
            $reset_stmt->close();
        }
        
        $settings_message = "Settings reset to defaults successfully!";
        $settings_message_type = "success";
        
        // Generate new CSRF token
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

/**
 * ============================================================================
 * FETCH ALL SETTINGS GROUPED BY CATEGORY
 * ============================================================================
 */
$settings_query = $conn->query("SELECT * FROM system_settings ORDER BY sort_order ASC");
$settings_data = [];
if ($settings_query && $settings_query->num_rows > 0) {
    while ($row = $settings_query->fetch_assoc()) {
        $settings_data[] = $row;
    }
}

// Group settings by category based on sort_order ranges
$categories = [
    'general' => ['name' => 'General Settings', 'min_sort' => 0, 'max_sort' => 99, 'icon' => 'fas fa-cog'],
    'security' => ['name' => 'Security Settings', 'min_sort' => 100, 'max_sort' => 199, 'icon' => 'fas fa-shield-alt'],
    'maintenance' => ['name' => 'Maintenance Settings', 'min_sort' => 200, 'max_sort' => 299, 'icon' => 'fas fa-tools'],
    'branding' => ['name' => 'Branding Settings', 'min_sort' => 300, 'max_sort' => 399, 'icon' => 'fas fa-palette'],
    'email' => ['name' => 'Email Settings', 'min_sort' => 400, 'max_sort' => 499, 'icon' => 'fas fa-envelope']
];

// Organize settings by category
$grouped_settings = [];
foreach ($categories as $cat_key => $category) {
    $grouped_settings[$cat_key] = [
        'name' => $category['name'],
        'icon' => $category['icon'],
        'settings' => []
    ];
}

foreach ($settings_data as $setting) {
    foreach ($categories as $cat_key => $category) {
        if ($setting['sort_order'] >= $category['min_sort'] && $setting['sort_order'] <= $category['max_sort']) {
            $grouped_settings[$cat_key]['settings'][] = $setting;
            break;
        }
    }
}
?>

<!-- SYSTEM SETTINGS TRANSLATIONS -->
<script>
// System Settings translations for English and Swahili
const system_translations = {
    en: {
        pageTitle: 'System Settings',
        pageSubtitle: 'Configure global system preferences',
        saveSettings: 'Save Settings',
        resetDefaults: 'Reset to Defaults',
        resetConfirm: 'Are you sure you want to reset all settings to default values? This action cannot be undone.',
        saveSuccess: 'Settings saved successfully!',
        saveError: 'Error saving settings. Please try again.',
        resetSuccess: 'Settings reset to defaults successfully!',
        generalSettings: 'General Settings',
        securitySettings: 'Security Settings',
        maintenanceSettings: 'Maintenance Settings',
        brandingSettings: 'Branding Settings',
        emailSettings: 'Email Settings',
        settingValue: 'Value',
        settingDescription: 'Description',
        on: 'On',
        off: 'Off',
        enabled: 'Enabled',
        disabled: 'Disabled',
        loading: 'Loading...',
        noSettings: 'No settings found.',
        lastUpdated: 'Last Updated',
        // Category labels
        general: 'General',
        security: 'Security',
        maintenance: 'Maintenance',
        branding: 'Branding',
        email: 'Email',
        // Specific setting labels
        app_name: 'Application Name',
        default_language: 'Default Language',
        timezone: 'Timezone',
        date_format: 'Date Format',
        items_per_page: 'Items Per Page',
        enable_session_timeout: 'Enable Session Timeout',
        session_timeout_minutes: 'Session Timeout Minutes',
        maintenance_mode: 'Maintenance Mode',
        maintenance_message: 'Maintenance Message',
        company_logo_path: 'Company Logo Path',
        favicon_path: 'Favicon Path',
        email_from_address: 'Email From Address',
        email_from_name: 'Email From Name'
    },
    sw: {
        pageTitle: 'Mipangilio ya Mfumo',
        pageSubtitle: 'Sanidi mapendeleo ya mfumo kwa ujumla',
        saveSettings: 'Hifadhi Mipangilio',
        resetDefaults: 'Rudisha Chaguo-msingi',
        resetConfirm: 'Una uhakika unataka kurudisha mipangilio yote kwenye chaguo-msingi? Kitendo hiki hakiwezi kutenguliwa.',
        saveSuccess: 'Mipangilio imehifadhiwa kikamilifu!',
        saveError: 'Hitilafu katika kuhifadhi mipangilio. Tafadhali jaribu tena.',
        resetSuccess: 'Mipangilio imerudishwa kwenye chaguo-msingi kikamilifu!',
        generalSettings: 'Mipangilio ya Jumla',
        securitySettings: 'Mipangilio ya Usalama',
        maintenanceSettings: 'Mipangilio ya Matengenezo',
        brandingSettings: 'Mipangilio ya Chapa',
        emailSettings: 'Mipangilio ya Barua Pepe',
        settingValue: 'Thamani',
        settingDescription: 'Maelezo',
        on: 'Imewashwa',
        off: 'Imezimwa',
        enabled: 'Imewezeshwa',
        disabled: 'Imeyazimwa',
        loading: 'Inapakia...',
        noSettings: 'Hakuna mipangilio iliyopatikana.',
        lastUpdated: 'Ilisasishwa Mwisho',
        // Category labels
        general: 'Jumla',
        security: 'Usalama',
        maintenance: 'Matengenezo',
        branding: 'Chapa',
        email: 'Barua Pepe',
        // Specific setting labels
        app_name: 'Jina la Programu',
        default_language: 'Lugha Chaguo-msingi',
        timezone: 'Saa za Eneo',
        date_format: 'Muundo wa Tarehe',
        items_per_page: 'Vipande kwa Ukurasa',
        enable_session_timeout: 'Washa Muda wa Kukaa',
        session_timeout_minutes: 'Dakika za Muda wa Kukaa',
        maintenance_mode: 'Hali ya Matengenezo',
        maintenance_message: 'Ujumbe wa Matengenezo',
        company_logo_path: 'Njia ya Nembo ya Kampuni',
        favicon_path: 'Njia ya Favicon',
        email_from_address: 'Anwani ya Mtumaji Barua Pepe',
        email_from_name: 'Jina la Mtumaji Barua Pepe'
    }
};

// Current language (will be updated by homepage.js)
let currentSysLang = localStorage.getItem('preferredLanguage') || 'en';

// Function to update all translatable elements in system settings module
function updateSystemLanguage(lang) {
    currentSysLang = lang;
    const elements = document.querySelectorAll('[data-sys-lang]');
    
    elements.forEach(element => {
        const key = element.getAttribute('data-sys-lang');
        if (system_translations[lang] && system_translations[lang][key]) {
            if (element.tagName === 'INPUT' || element.tagName === 'TEXTAREA') {
                if (element.getAttribute('type') !== 'submit' && element.getAttribute('type') !== 'button') {
                    element.placeholder = system_translations[lang][key];
                }
            } else if (element.tagName === 'OPTION') {
                element.textContent = system_translations[lang][key];
            } else if (element.tagName === 'BUTTON') {
                const span = element.querySelector('span');
                if (span) {
                    span.textContent = system_translations[lang][key];
                }
            } else {
                element.textContent = system_translations[lang][key];
            }
        }
    });
    
    // Update category headers
    document.querySelectorAll('.sys-category-header span:last-child').forEach(header => {
        const key = header.getAttribute('data-category');
        if (system_translations[lang] && system_translations[lang][key]) {
            header.textContent = system_translations[lang][key];
        }
    });
}

// Listen for language change events from homepage
document.addEventListener('DOMContentLoaded', function() {
    updateSystemLanguage(currentSysLang);
});

// This function will be called from homepage.js when language changes
window.updateSystemLanguage = updateSystemLanguage;
</script>

<style>
    /* System Settings Module Styles - Using sys_ prefix (ISSUE #3: No sidebar conflicts) */
    .sys-container {
        width: 100%;
        animation: sys-fadeIn 0.3s ease-out;
    }
    
    @keyframes sys-fadeIn {
        from {
            opacity: 0;
            transform: translateY(10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    /* Header Styles */
    .sys-header {
        margin-bottom: 28px;
    }
    
    .sys-header h2 {
        color: var(--gray-800);
        font-size: 24px;
        font-weight: 600;
        margin: 0;
        font-family: 'Montserrat', sans-serif;
    }
    
    .sys-header p {
        color: var(--gray-500);
        font-size: 14px;
        margin-top: 8px;
    }
    
    /* Alert Styles */
    .sys-alert {
        padding: 16px 20px;
        border-radius: 12px;
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        animation: sys-slideDown 0.3s ease-out;
    }
    
    @keyframes sys-slideDown {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    .sys-alert-success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }
    
    .sys-alert-danger {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }
    
    .sys-alert-close {
        background: none;
        border: none;
        font-size: 20px;
        cursor: pointer;
        color: inherit;
        opacity: 0.5;
        transition: opacity 0.2s;
    }
    
    .sys-alert-close:hover {
        opacity: 1;
    }
    
    /* Action Bar */
    .sys-action-bar {
        display: flex;
        justify-content: flex-end;
        gap: 16px;
        margin-bottom: 24px;
        flex-wrap: wrap;
    }
    
    /* Buttons */
    .sys-btn {
        padding: 12px 24px;
        border-radius: 10px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
        border: none;
        font-family: inherit;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    
    .sys-btn-primary {
        background: linear-gradient(135deg, var(--brown-700), var(--brown-800));
        color: white;
    }
    
    .sys-btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-md);
        filter: brightness(1.05);
    }
    
    .sys-btn-secondary {
        background: var(--gray-200);
        color: var(--gray-700);
    }
    
    .sys-btn-secondary:hover {
        background: var(--gray-300);
        transform: translateY(-2px);
    }
    
    .sys-btn-danger {
        background: #dc3545;
        color: white;
    }
    
    .sys-btn-danger:hover {
        background: #c82333;
        transform: translateY(-2px);
        box-shadow: var(--shadow-md);
    }
    
    .sys-btn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
        transform: none;
    }
    
    /* Settings Grid */
    .sys-settings-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
        gap: 24px;
    }
    
    /* Category Card */
    .sys-category-card {
        background: white;
        border-radius: 20px;
        border: 1px solid var(--gray-200);
        overflow: hidden;
        transition: all 0.2s ease;
        box-shadow: var(--shadow-sm);
    }
    
    .sys-category-card:hover {
        box-shadow: var(--shadow-md);
    }
    
    .sys-category-header {
        background: linear-gradient(135deg, #faf7f2 0%, #f5efe8 100%);
        padding: 18px 24px;
        border-bottom: 2px solid var(--gray-200);
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .sys-category-header i {
        font-size: 22px;
        color: var(--brown-700);
    }
    
    .sys-category-header h3 {
        font-size: 18px;
        font-weight: 600;
        color: var(--gray-800);
        margin: 0;
    }
    
    /* Setting Item */
    .sys-setting-item {
        padding: 20px 24px;
        border-bottom: 1px solid var(--gray-100);
        transition: background 0.2s;
    }
    
    .sys-setting-item:last-child {
        border-bottom: none;
    }
    
    .sys-setting-item:hover {
        background: var(--gray-50);
    }
    
    .sys-setting-label {
        font-weight: 600;
        color: var(--gray-800);
        margin-bottom: 6px;
        font-size: 14px;
    }
    
    .sys-setting-description {
        font-size: 12px;
        color: var(--gray-500);
        margin-bottom: 12px;
        line-height: 1.5;
    }
    
    /* Form Controls */
    .sys-setting-control {
        width: 100%;
    }
    
    .sys-setting-control input,
    .sys-setting-control select,
    .sys-setting-control textarea {
        width: 100%;
        padding: 10px 14px;
        border: 1px solid var(--gray-300);
        border-radius: 8px;
        font-size: 14px;
        font-family: inherit;
        transition: all 0.2s;
        background: white;
    }
    
    .sys-setting-control input:focus,
    .sys-setting-control select:focus,
    .sys-setting-control textarea:focus {
        outline: none;
        border-color: var(--brown-600);
        box-shadow: 0 0 0 3px rgba(123, 88, 63, 0.1);
    }
    
    .sys-setting-control textarea {
        resize: vertical;
        min-height: 80px;
    }
    
    /* Toggle Switch */
    .sys-toggle {
        position: relative;
        display: inline-block;
        width: 52px;
        height: 28px;
    }
    
    .sys-toggle input {
        opacity: 0;
        width: 0;
        height: 0;
    }
    
    .sys-toggle-slider {
        position: absolute;
        cursor: pointer;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: var(--gray-300);
        transition: 0.3s;
        border-radius: 34px;
    }
    
    .sys-toggle-slider:before {
        position: absolute;
        content: "";
        height: 22px;
        width: 22px;
        left: 3px;
        bottom: 3px;
        background-color: white;
        transition: 0.3s;
        border-radius: 50%;
    }
    
    .sys-toggle input:checked + .sys-toggle-slider {
        background-color: var(--brown-700);
    }
    
    .sys-toggle input:checked + .sys-toggle-slider:before {
        transform: translateX(24px);
    }
    
    .sys-toggle-label {
        display: inline-flex;
        align-items: center;
        gap: 12px;
        cursor: pointer;
    }
    
    .sys-toggle-text {
        font-size: 14px;
        color: var(--gray-700);
    }
    
    /* Footer */
    .sys-footer {
        margin-top: 24px;
        padding-top: 16px;
        border-top: 1px solid var(--gray-200);
        text-align: right;
        font-size: 12px;
        color: var(--gray-500);
    }
    
    /* Loading State */
    .sys-loading {
        display: inline-block;
        width: 20px;
        height: 20px;
        border: 3px solid var(--gray-200);
        border-top-color: var(--brown-700);
        border-radius: 50%;
        animation: sys-spin 1s linear infinite;
    }
    
    @keyframes sys-spin {
        to { transform: rotate(360deg); }
    }
    
    /* Responsive */
    @media (max-width: 1024px) {
        .sys-settings-grid {
            grid-template-columns: 1fr;
        }
    }
    
    @media (max-width: 768px) {
        .sys-header h2 {
            font-size: 20px;
        }
        
        .sys-action-bar {
            justify-content: stretch;
        }
        
        .sys-action-bar .sys-btn {
            flex: 1;
            justify-content: center;
        }
        
        .sys-setting-item {
            padding: 16px;
        }
        
        .sys-category-header {
            padding: 14px 20px;
        }
    }
    
    @media (max-width: 480px) {
        .sys-setting-label {
            font-size: 13px;
        }
        
        .sys-setting-control input,
        .sys-setting-control select,
        .sys-setting-control textarea {
            padding: 8px 12px;
            font-size: 13px;
        }
    }
</style>

<div class="sys-container">
    <!-- Header -->
    <div class="sys-header">
        <h2 data-sys-lang="pageTitle">System Settings</h2>
        <p data-sys-lang="pageSubtitle">Configure global system preferences</p>
    </div>
    
    <!-- Alert Messages -->
    <?php if (!empty($settings_message)): ?>
        <div class="sys-alert sys-alert-<?= $settings_message_type ?>">
            <?= $settings_message ?>
            <button class="sys-alert-close" onclick="this.parentElement.style.display='none'">&times;</button>
        </div>
    <?php endif; ?>
    
    <!-- Settings Form -->
    <?php if (canEdit($conn, $user_role_id, $module_name)): ?>
    <form method="POST" action="?page=systemsettings" id="sysSettingsForm">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        
        <div class="sys-action-bar">
            <button type="submit" name="sys_update" class="sys-btn sys-btn-primary" id="sysSaveBtn">
                <i class="fas fa-save"></i> <span data-sys-lang="saveSettings">Save Settings</span>
            </button>
            <button type="button" class="sys-btn sys-btn-danger" id="sysResetBtn">
                <i class="fas fa-undo-alt"></i> <span data-sys-lang="resetDefaults">Reset to Defaults</span>
            </button>
        </div>
        
        <div class="sys-settings-grid">
            <?php foreach ($grouped_settings as $cat_key => $category): ?>
                <?php if (!empty($category['settings'])): ?>
                    <div class="sys-category-card">
                        <div class="sys-category-header">
                            <i class="<?= $category['icon'] ?>"></i>
                            <h3><span data-category="<?= $cat_key ?>"><?= $category['name'] ?></span></h3>
                        </div>
                        <div class="sys-category-body">
                            <?php foreach ($category['settings'] as $setting): ?>
                                <div class="sys-setting-item">
                                    <div class="sys-setting-label" data-sys-lang="<?= $setting['setting_key'] ?>">
                                        <?= htmlspecialchars($setting['display_name']) ?>
                                    </div>
                                    <?php if (!empty($setting['description'])): ?>
                                        <div class="sys-setting-description"><?= htmlspecialchars($setting['description']) ?></div>
                                    <?php endif; ?>
                                    <div class="sys-setting-control">
                                        <?php if ($setting['setting_type'] == 'toggle'): ?>
                                            <label class="sys-toggle-label">
                                                <div class="sys-toggle">
                                                    <input type="checkbox" name="setting_<?= $setting['setting_key'] ?>" value="1" <?= $setting['setting_value'] == '1' ? 'checked' : '' ?>>
                                                    <span class="sys-toggle-slider"></span>
                                                </div>
                                                <span class="sys-toggle-text" data-sys-lang="<?= $setting['setting_value'] == '1' ? 'on' : 'off' ?>">
                                                    <?= $setting['setting_value'] == '1' ? 'On' : 'Off' ?>
                                                </span>
                                            </label>
                                        <?php elseif ($setting['setting_type'] == 'select'): ?>
                                            <?php 
                                                $options = json_decode($setting['options'], true);
                                                $current_value = $setting['setting_value'];
                                            ?>
                                            <select name="setting_<?= $setting['setting_key'] ?>" id="sys_select_<?= $setting['setting_key'] ?>">
                                                <?php if (is_array($options)): ?>
                                                    <?php foreach ($options as $opt_value => $opt_label): ?>
                                                        <option value="<?= htmlspecialchars($opt_value) ?>" <?= $current_value == $opt_value ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($opt_label) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </select>
                                        <?php elseif ($setting['setting_type'] == 'textarea'): ?>
                                            <textarea name="setting_<?= $setting['setting_key'] ?>" rows="3"><?= htmlspecialchars($setting['setting_value']) ?></textarea>
                                        <?php else: ?>
                                            <input type="<?= $setting['setting_type'] == 'email' ? 'email' : ($setting['setting_type'] == 'number' ? 'number' : 'text') ?>" 
                                                   name="setting_<?= $setting['setting_key'] ?>" 
                                                   value="<?= htmlspecialchars($setting['setting_value']) ?>"
                                                   step="<?= $setting['setting_type'] == 'number' ? '1' : '' ?>">
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        
        <div class="sys-footer">
            <i class="fas fa-clock"></i> 
            <span data-sys-lang="lastUpdated">Last Updated</span>: 
            <?= date('d M Y H:i:s') ?>
        </div>
    </form>
    <?php else: ?>
        <!-- Read-only view for users without edit permission -->
        <div class="sys-settings-grid">
            <?php foreach ($grouped_settings as $cat_key => $category): ?>
                <?php if (!empty($category['settings'])): ?>
                    <div class="sys-category-card">
                        <div class="sys-category-header">
                            <i class="<?= $category['icon'] ?>"></i>
                            <h3><span data-category="<?= $cat_key ?>"><?= $category['name'] ?></span></h3>
                        </div>
                        <div class="sys-category-body">
                            <?php foreach ($category['settings'] as $setting): ?>
                                <div class="sys-setting-item">
                                    <div class="sys-setting-label" data-sys-lang="<?= $setting['setting_key'] ?>">
                                        <?= htmlspecialchars($setting['display_name']) ?>
                                    </div>
                                    <?php if (!empty($setting['description'])): ?>
                                        <div class="sys-setting-description"><?= htmlspecialchars($setting['description']) ?></div>
                                    <?php endif; ?>
                                    <div class="sys-setting-control">
                                        <?php if ($setting['setting_type'] == 'toggle'): ?>
                                            <span class="sys-badge <?= $setting['setting_value'] == '1' ? 'sys-badge-success' : 'sys-badge-secondary' ?>">
                                                <?= $setting['setting_value'] == '1' ? 'Enabled' : 'Disabled' ?>
                                            </span>
                                        <?php elseif ($setting['setting_type'] == 'select'): ?>
                                            <?php 
                                                $options = json_decode($setting['options'], true);
                                                $current_value = $setting['setting_value'];
                                                $display_value = is_array($options) && isset($options[$current_value]) ? $options[$current_value] : $current_value;
                                            ?>
                                            <div class="sys-readonly-value"><?= htmlspecialchars($display_value) ?></div>
                                        <?php elseif ($setting['setting_type'] == 'textarea'): ?>
                                            <div class="sys-readonly-textarea"><?= nl2br(htmlspecialchars($setting['setting_value'])) ?></div>
                                        <?php else: ?>
                                            <div class="sys-readonly-value"><?= htmlspecialchars($setting['setting_value']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Module JavaScript -->
<script>
    // Update toggle text when toggle changes
    document.querySelectorAll('.sys-toggle input').forEach(toggle => {
        toggle.addEventListener('change', function() {
            const label = this.closest('.sys-setting-item').querySelector('.sys-toggle-text');
            const lang = currentSysLang;
            if (this.checked) {
                label.textContent = system_translations[lang].on || 'On';
                label.setAttribute('data-sys-lang', 'on');
            } else {
                label.textContent = system_translations[lang].off || 'Off';
                label.setAttribute('data-sys-lang', 'off');
            }
        });
    });
    
    // Reset button confirmation
    const resetBtn = document.getElementById('sysResetBtn');
    if (resetBtn) {
        resetBtn.addEventListener('click', function(e) {
            e.preventDefault();
            const lang = currentSysLang;
            const confirmMsg = system_translations[lang].resetConfirm || 'Are you sure you want to reset all settings to default values? This action cannot be undone.';
            if (confirm(confirmMsg)) {
                // Create a form to submit reset
                const form = document.getElementById('sysSettingsForm');
                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = 'sys_reset';
                hiddenInput.value = '1';
                form.appendChild(hiddenInput);
                form.submit();
            }
        });
    }
    
    // Auto-hide alerts after 5 seconds
    setTimeout(() => {
        document.querySelectorAll('.sys-alert').forEach(alert => {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(() => alert.style.display = 'none', 500);
        });
    }, 5000);
    
    // Add some extra styles for read-only view
    const style = document.createElement('style');
    style.textContent = `
        .sys-badge {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        .sys-badge-success {
            background: #d4edda;
            color: #155724;
        }
        .sys-badge-secondary {
            background: var(--gray-200);
            color: var(--gray-700);
        }
        .sys-readonly-value {
            padding: 10px 14px;
            background: var(--gray-50);
            border-radius: 8px;
            font-size: 14px;
            color: var(--gray-800);
            border: 1px solid var(--gray-200);
        }
        .sys-readonly-textarea {
            padding: 10px 14px;
            background: var(--gray-50);
            border-radius: 8px;
            font-size: 14px;
            color: var(--gray-800);
            border: 1px solid var(--gray-200);
            min-height: 80px;
            white-space: pre-wrap;
        }
    `;
    document.head.appendChild(style);
</script>