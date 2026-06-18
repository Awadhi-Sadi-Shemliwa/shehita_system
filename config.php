<?php
/**
 * SHEHITA Enterprise Management System
 * Configuration File - Database connection and helper functions
 * 
 * ENHANCED: Added permission helper functions for role-based access control
 */

// Environment-aware error reporting.
// Set APP_ENV=production on the server so internal errors are logged but
// NEVER shown to visitors. APP_ENV=development restores on-screen errors.
$appEnv = getenv('APP_ENV') !== false ? getenv('APP_ENV') : 'production';
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('display_errors', $appEnv === 'development' ? '1' : '0');

// Database configuration.
// Credentials MUST be supplied via environment variables — nothing sensitive
// is hard-coded in source control.
$host     = getenv('DB_HOST')     ?: '';
$username = getenv('DB_USER')     ?: '';
$password = getenv('DB_PASSWORD') ?: '';
$database = getenv('DB_NAME')     ?: '';

if ($host === '' || $username === '' || $database === '') {
    error_log('config.php: missing required DB env vars (DB_HOST/DB_USER/DB_NAME).');
    http_response_code(500);
    die('Service temporarily unavailable.');
}

// Create connection. Handle errors ourselves so driver details never reach the browser.
mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli($host, $username, $password);

// Check connection — never leak DB host/credentials to the client.
if ($conn->connect_error) {
    error_log('config.php: database connection failed: ' . $conn->connect_error);
    http_response_code(500);
    die('Service temporarily unavailable. Please try again later.');
}

// Create database if it doesn't exist
$conn->query("CREATE DATABASE IF NOT EXISTS $database");
$conn->select_db($database);
$conn->set_charset("utf8mb4");

/**
 * Create tables if they don't exist
 */

// Departments table
$conn->query("CREATE TABLE IF NOT EXISTS departments (
    id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    status ENUM('Active', 'Inactive') DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Roles table
$conn->query("CREATE TABLE IF NOT EXISTS roles (
    id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    department_id INT(11) UNSIGNED NOT NULL,
    status ENUM('Active', 'Inactive') DEFAULT 'Active',
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE RESTRICT,
    UNIQUE KEY unique_role_per_department (name, department_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Users table with security_question and security_answer columns
$conn->query("CREATE TABLE IF NOT EXISTS users (
    id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    profile_image VARCHAR(255) DEFAULT NULL,
    department_id INT(11) UNSIGNED DEFAULT NULL,
    role_id INT(11) UNSIGNED DEFAULT NULL,
    status ENUM('Active', 'Inactive') DEFAULT 'Inactive',
    security_question TEXT DEFAULT NULL,
    security_answer VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Permissions table (ensure it exists with all columns)
$conn->query("CREATE TABLE IF NOT EXISTS permissions (
    id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_id INT(11) UNSIGNED NOT NULL,
    department_id INT(11) UNSIGNED NULL,
    module_name VARCHAR(100) NOT NULL,
    can_view TINYINT(1) DEFAULT 0,
    can_add TINYINT(1) DEFAULT 0,
    can_edit TINYINT(1) DEFAULT 0,
    can_delete TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
    UNIQUE KEY unique_role_module (role_id, module_name),
    INDEX idx_module (module_name),
    INDEX idx_role (role_id),
    INDEX idx_department (department_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/**
 * ============================================================================
 * CENTRALIZED SCHEMA - Business / feature tables
 * ----------------------------------------------------------------------------
 * All tables are created here, at bootstrap, in strict foreign-key dependency
 * order (parents before children). Previously each module created its own table
 * on first visit, which made fresh deployments fragile: opening a child module
 * (e.g. projects) before its parent (customers) caused the CREATE TABLE to fail.
 * Creating everything centrally removes that ordering dependency.
 *
 * Dependency order:
 *   departments -> roles -> users -> permissions   (created above)
 *   projectgroup -> categories
 *   customers
 *   company_settings, system_settings (standalone)
 *   projects (-> customers), invoices (-> customers)
 *   operations (-> projectgroup, categories)
 * ============================================================================
 */

// Project groups (parent of categories)
$conn->query("CREATE TABLE IF NOT EXISTS projectgroup (
    id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    status ENUM('Active', 'Inactive') DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci AUTO_INCREMENT=1");

// Categories (belong to a project group)
$conn->query("CREATE TABLE IF NOT EXISTS categories (
    id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    projectgroup_id INT(11) UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    status ENUM('Active', 'Inactive') DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_projectgroup (projectgroup_id),
    INDEX idx_status (status),
    FOREIGN KEY (projectgroup_id) REFERENCES projectgroup(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci AUTO_INCREMENT=1");

// Customers / clients (parent of projects and invoices)
$conn->query("CREATE TABLE IF NOT EXISTS customers (
    id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_name VARCHAR(255) NOT NULL,
    contact_person VARCHAR(255) DEFAULT NULL,
    tin_number VARCHAR(50) DEFAULT NULL,
    vrn_number VARCHAR(50) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    email VARCHAR(255) DEFAULT NULL,
    type_of_business ENUM('Individual', 'Sole Proprietorship', 'Partnership', 'Limited Company', 'Corporation', 'Non-Profit', 'Government', 'Other') DEFAULT 'Individual',
    status ENUM('Active', 'Inactive') DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_customer_name (customer_name),
    INDEX idx_status (status),
    INDEX idx_email (email),
    INDEX idx_type (type_of_business),
    INDEX idx_vrn_number (vrn_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci AUTO_INCREMENT=1");

// Company settings (single-row configuration table)
$conn->query("CREATE TABLE IF NOT EXISTS company_settings (
    id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_name VARCHAR(255) NOT NULL,
    company_address TEXT NOT NULL,
    company_email VARCHAR(255) NOT NULL,
    company_phone VARCHAR(50) NOT NULL,
    company_tin VARCHAR(100) NOT NULL,
    vrn_number VARCHAR(50) DEFAULT NULL,
    currency_symbol VARCHAR(5) NOT NULL DEFAULT 'TZS',
    logo_url VARCHAR(500) DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci AUTO_INCREMENT=1");

// System settings (global key/value configuration)
$conn->query("CREATE TABLE IF NOT EXISTS system_settings (
    id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT,
    setting_type ENUM('text', 'textarea', 'number', 'toggle', 'select', 'email') DEFAULT 'text',
    display_name VARCHAR(255) NOT NULL,
    description TEXT,
    options JSON NULL,
    sort_order INT(11) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_key (setting_key),
    INDEX idx_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

// Projects / contracts (child of customers)
$conn->query("CREATE TABLE IF NOT EXISTS projects (
    id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contract_number VARCHAR(50) NOT NULL UNIQUE,
    client_id INT(11) UNSIGNED NOT NULL,
    effective_date DATE NOT NULL,
    end_date DATE NOT NULL,
    contract_value DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    tax DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    commission DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    cost_of_project DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    staff_cost DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    overhead_cost DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    number_of_staff_allocated INT(11) NOT NULL DEFAULT 0,
    target_profit DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    actual_profit DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    profit_difference DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    status ENUM('Active', 'Expired', 'Inactive') DEFAULT 'Active',
    created_by VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_client (client_id),
    INDEX idx_status (status),
    INDEX idx_contract_number (contract_number),
    INDEX idx_dates (effective_date, end_date),
    FOREIGN KEY (client_id) REFERENCES customers(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci AUTO_INCREMENT=1");

// Invoices (child of customers)
$conn->query("CREATE TABLE IF NOT EXISTS invoices (
    id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_number VARCHAR(50) NOT NULL UNIQUE,
    customer_id INT(11) UNSIGNED NOT NULL,
    invoice_date DATE NOT NULL,
    due_date DATE NOT NULL,
    particulars TEXT NOT NULL,
    quantity INT(11) NOT NULL DEFAULT 1,
    rate DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    subtotal DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    vat DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    bank_name VARCHAR(255) NOT NULL,
    account_number VARCHAR(100) NOT NULL,
    account_name VARCHAR(255) DEFAULT NULL,
    status ENUM('Paid', 'Unpaid', 'Partially Paid') DEFAULT 'Unpaid',
    created_by VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_invoice_number (invoice_number),
    INDEX idx_customer (customer_id),
    INDEX idx_status (status),
    INDEX idx_dates (invoice_date, due_date),
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci AUTO_INCREMENT=1");

// Operations (child of projectgroup + categories)
$conn->query("CREATE TABLE IF NOT EXISTS operations (
    id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id VARCHAR(20) NOT NULL UNIQUE,
    contract_number VARCHAR(50) NOT NULL,
    project_group_id INT(11) UNSIGNED NOT NULL,
    category_id INT(11) UNSIGNED NOT NULL,
    duration_type ENUM('Non Recurring', 'Recurring - Monthly', 'Recurring - Quarterly', 'Recurring - Semi Annually', 'Recurring - Annually') DEFAULT 'Non Recurring',
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    status ENUM('Active', 'Completed', 'Inactive') DEFAULT 'Active',
    invoice_data JSON NULL,
    assigned_staff JSON DEFAULT NULL,
    created_by VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_invoice_id (invoice_id),
    INDEX idx_contract (contract_number),
    INDEX idx_status (status),
    INDEX idx_dates (start_date, end_date),
    FOREIGN KEY (project_group_id) REFERENCES projectgroup(id) ON DELETE RESTRICT,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci AUTO_INCREMENT=1");

/**
 * Ensure default department (ID=1) exists
 * Changed from "User" to "Administrator" to match the protected department in departments.php
 */
$result = $conn->query("SELECT id FROM departments WHERE name = 'Administrator'");
if ($result->num_rows == 0) {
    $stmt = $conn->prepare("INSERT INTO departments (name, description, status) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $name, $desc, $status);
    $name = 'Administrator';
    $desc = 'Default system department for administrator roles';
    $status = 'Active';
    $stmt->execute();
    $stmt->close();
    
    // Ensure ID = 1
    $conn->query("UPDATE departments SET id = 1 WHERE name = 'Administrator'");
    $conn->query("ALTER TABLE departments AUTO_INCREMENT = 2");
}

/**
 * Ensure default role (ID=1) exists
 * CHANGED: Default role name from "User" to "Super Admin"
 */
$result = $conn->query("SELECT id FROM roles WHERE name = 'Super Admin' AND department_id = 1");
if ($result->num_rows == 0) {
    $stmt = $conn->prepare("INSERT INTO roles (name, department_id, status, description) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("siss", $name, $dept_id, $status, $desc);
    $name = 'Super Admin';
    $dept_id = 1;
    $status = 'Active';
    $desc = 'Default system role with full administrative privileges';
    $stmt->execute();
    $stmt->close();
    
    // Ensure ID = 1
    $conn->query("UPDATE roles SET id = 1 WHERE name = 'Super Admin' AND department_id = 1");
    $conn->query("ALTER TABLE roles AUTO_INCREMENT = 2");
}

/**
 * Ensure default admin account exists.
 * The initial email/password come from environment variables. If no password
 * is provided, a strong random one is generated and written to the error log
 * so the operator can retrieve it once — it is never hard-coded in source.
 */
$adminEmail = getenv('ADMIN_DEFAULT_EMAIL') ?: 'admin@paplontech.com';

$stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
$stmt->bind_param("s", $adminEmail);
$stmt->execute();
$adminExists = $stmt->get_result()->num_rows > 0;
$stmt->close();

if (!$adminExists) {
    $adminPlainPassword = getenv('ADMIN_DEFAULT_PASSWORD');
    if ($adminPlainPassword === false || $adminPlainPassword === '') {
        $adminPlainPassword = bin2hex(random_bytes(9)); // 18-char random password
        error_log("config.php: created initial admin '{$adminEmail}' with generated password: {$adminPlainPassword}");
    }

    $name            = 'System Administrator';
    $dept_id         = 1;
    $role_id         = 1;
    $status          = 'Active';
    $hashed_password = password_hash($adminPlainPassword, PASSWORD_DEFAULT);

    $stmt = $conn->prepare("INSERT INTO users (name, email, password, department_id, role_id, status) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssiis", $name, $adminEmail, $hashed_password, $dept_id, $role_id, $status);
    $stmt->execute();
    $stmt->close();

    // Ensure ID = 1
    $stmt = $conn->prepare("UPDATE users SET id = 1 WHERE email = ?");
    $stmt->bind_param("s", $adminEmail);
    $stmt->execute();
    $stmt->close();
    $conn->query("ALTER TABLE users AUTO_INCREMENT = 2");
}

/**
 * PERMISSION: Helper Functions for Role-Based Access Control
 */

/**
 * Check if user has permission for a specific action on a module
 * SUPER ADMIN (role_id = 1) automatically has all permissions
 * 
 * @param mysqli $conn Database connection
 * @param int $role_id User's role ID
 * @param string $module_name Module identifier (matches page parameter)
 * @param string $action Action to check (view, add, edit, delete)
 * @return bool True if user has permission, false otherwise
 */
function hasPermission($conn, $role_id, $module_name, $action) {
    // SUPER ADMIN: Role ID 1 has full access to everything
    if ($role_id == 1) {
        return true;
    }
    
    // Check session first for performance
    if (isset($_SESSION['permissions'][$module_name][$action])) {
        return $_SESSION['permissions'][$module_name][$action] == 1;
    }
    
    // Fallback to database query
    $stmt = $conn->prepare("SELECT can_$action FROM permissions WHERE role_id = ? AND module_name = ?");
    $stmt->bind_param("is", $role_id, $module_name);
    $stmt->execute();
    $result = $stmt->get_result();
    $has_permission = ($result->num_rows > 0) ? (bool)$result->fetch_assoc()["can_$action"] : false;
    $stmt->close();
    return $has_permission;
}

/**
 * Load all permissions for a role into session
 * This should be called immediately after successful login
 * 
 * @param mysqli $conn Database connection
 * @param int $role_id User's role ID
 */
function loadUserPermissions($conn, $role_id) {
    $permissions = [];
    
    // SUPER ADMIN: Load all possible modules with full permissions
    if ($role_id == 1) {
        // Define all system modules
        $all_modules = [
            'home', 'overview', 'projects', 'operations', 'status',
            'projectlist', 'categories', 'projectgroup',
            'expensescategory', 'expensesgroup',
            'customer-management', 'company-settings', 'user-management', 
            'departments', 'permissions', 'roles', 'profile','invoice','systemsettings'
        ];
        
        // Grant full permissions for all modules to Super Admin
        foreach ($all_modules as $module) {
            $permissions[$module] = [
                'can_view' => 1,
                'can_add' => 1,
                'can_edit' => 1,
                'can_delete' => 1
            ];
        }
    } else {
        // Load permissions from database for non-admin roles
        $stmt = $conn->prepare("SELECT module_name, can_view, can_add, can_edit, can_delete FROM permissions WHERE role_id = ?");
        $stmt->bind_param("i", $role_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $permissions[$row['module_name']] = [
                'can_view' => (int)$row['can_view'],
                'can_add' => (int)$row['can_add'],
                'can_edit' => (int)$row['can_edit'],
                'can_delete' => (int)$row['can_delete']
            ];
        }
        $stmt->close();
    }
    
    $_SESSION['permissions'] = $permissions;
}

/**
 * Check if user can view a module (convenience function)
 */
function canView($conn, $role_id, $module_name) {
    return hasPermission($conn, $role_id, $module_name, 'view');
}

/**
 * Check if user can add records to a module (convenience function)
 */
function canAdd($conn, $role_id, $module_name) {
    return hasPermission($conn, $role_id, $module_name, 'add');
}

/**
 * Check if user can edit records in a module (convenience function)
 */
function canEdit($conn, $role_id, $module_name) {
    return hasPermission($conn, $role_id, $module_name, 'edit');
}

/**
 * Check if user can delete records from a module (convenience function)
 */
function canDelete($conn, $role_id, $module_name) {
    return hasPermission($conn, $role_id, $module_name, 'delete');
}

/**
 * Helper Functions
 */
function sanitize($conn, $data) {
    return htmlspecialchars(trim($data));
}

function getUserRoleName($conn, $role_id) {
    if (!$role_id) return 'Super Admin';
    $result = $conn->query("SELECT name FROM roles WHERE id = $role_id");
    return ($result && $result->num_rows > 0) ? $result->fetch_assoc()['name'] : 'Super Admin';
}

function getDepartmentName($conn, $dept_id) {
    if (!$dept_id) return 'Administrator';
    $result = $conn->query("SELECT name FROM departments WHERE id = $dept_id");
    return ($result && $result->num_rows > 0) ? $result->fetch_assoc()['name'] : 'Administrator';
}

// Return connection
return $conn;
?>