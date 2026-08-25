<?php
/**
 * SHEHITA Enterprise Management System
 * Configuration File - Database connection and helper functions
 *
 * MULTI-TENANT: this no longer connects to one fixed database. It connects to
 * the database belonging to the company resolved by bootstrap.php (TENANT_DB).
 * Because every module reads `global $conn`, that single change scopes the
 * entire application to one company with no changes inside modules/.
 *
 * The table definitions that used to live inline here now live in
 * lib/schema.php, so that signup.php can build a brand-new company's database
 * from the same single definition.
 *
 * Usage stays exactly as before:  $conn = require_once 'config.php';
 */

require_once __DIR__ . '/lib/schema.php';

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

// Which database? Normally the tenant resolved by bootstrap.php. DB_NAME is
// honoured only as a fallback for scripts that run outside a web request
// (cron, one-off maintenance) and for single-tenant installs mid-migration.
$database = defined('TENANT_DB') ? TENANT_DB : (getenv('DB_NAME') ?: '');

if ($host === '' || $username === '' || $database === '') {
    error_log('config.php: missing required DB env vars (DB_HOST/DB_USER) or no tenant resolved.');
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
$conn->query("CREATE DATABASE IF NOT EXISTS `" . str_replace('`', '', $database) . "`");
$conn->select_db($database);
$conn->set_charset("utf8mb4");

/**
 * Create/verify this company's tables.
 *
 * Cheap after the first run: ensure_tenant_schema() records the applied schema
 * version in the tenant database and skips the DDL pass when it matches, so the
 * steady-state cost is one SELECT rather than the twelve CREATE TABLE
 * statements this file used to issue on every single request.
 *
 * Bumping TENANT_SCHEMA_VERSION in lib/schema.php re-runs the DDL for every
 * company on their next request — which is how schema changes roll out across
 * all tenant databases.
 */
ensure_tenant_schema($conn);

/**
 * PERMISSION: Helper Functions for Role-Based Access Control
 *
 * NOTE ON SCOPE: role_id = 1 is the Super Admin *of this one company*. It grants
 * nothing at the platform level — SHEHITA's own staff authenticate separately
 * against platform_admins in the control database (see admin/).
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

    // Only these four actions exist. $action is interpolated into the column name
    // below, so anything else must never reach the query.
    if (!in_array($action, ['view', 'add', 'edit', 'delete'], true)) {
        return false;
    }

    // Serve from the session cache, but only while it matches the version
    // recorded in the database.
    //
    // The cache used to be dead code: loadUserPermissions() writes keys as
    // 'can_view'/'can_add'/..., while this looked up 'view'/'add'/... So every
    // check missed and fell through to a query -- roughly nineteen of them per
    // page load, because the navigation menu tests each module.
    //
    // Simply correcting the key would have been worse than leaving it broken:
    // the cache is only written at login, so an administrator changing a role's
    // permissions would have had no effect until every affected user signed out.
    // The version check is what makes caching safe -- permissions_bump_version()
    // is called on every write in modules/permissions.php, which invalidates
    // every user's cache on their next request.
    $version = permissions_current_version($conn);

    if (isset($_SESSION['permissions_version'], $_SESSION['permissions'])
        && $_SESSION['permissions_version'] === $version
    ) {
        return !empty($_SESSION['permissions'][$module_name]["can_$action"]);
    }

    // Cache missing or stale: rebuild it once, then answer from it.
    loadUserPermissions($conn, $role_id);

    return !empty($_SESSION['permissions'][$module_name]["can_$action"]);
}

/**
 * The current permissions version for this company.
 *
 * Read once per request. Any change to the permissions table bumps it, which is
 * how a stale session cache is detected without the app having to reach into
 * other users' sessions.
 */
function permissions_current_version($conn, $forceReload = false) {
    static $version = null;
    if ($version !== null && !$forceReload) {
        return $version;
    }

    // @ because schema_meta legitimately does not exist on a database that has
    // not been through lib/schema.php yet.
    $result = @$conn->query("SELECT meta_value FROM schema_meta WHERE meta_key = 'permissions_version'");
    if ($result && $result->num_rows > 0) {
        return $version = (string)$result->fetch_assoc()['meta_value'];
    }

    return $version = '0';
}

/**
 * Invalidate every user's cached permissions for this company.
 * Call after ANY write to the permissions table.
 */
function permissions_bump_version($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS schema_meta (
        meta_key VARCHAR(32) NOT NULL PRIMARY KEY,
        meta_value VARCHAR(64) NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $new = uniqid('', true);
    $stmt = $conn->prepare(
        "REPLACE INTO schema_meta (meta_key, meta_value) VALUES ('permissions_version', ?)"
    );
    $stmt->bind_param("s", $new);
    $stmt->execute();
    $stmt->close();

    // Drop this request's cached copies so the change is visible immediately to
    // the administrator who just made it -- including the per-request static,
    // which would otherwise still hold the pre-bump value and cause the session
    // cache to be stamped with a version that is already stale.
    permissions_current_version($conn, true);
    unset($_SESSION['permissions'], $_SESSION['permissions_version']);
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
    $_SESSION['permissions_version'] = permissions_current_version($conn);
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

/**
 * Display name for a role.
 *
 * A missing or unknown role reads as "No role assigned", NOT as "Super Admin".
 * The old fallback claimed the highest privilege in the system for precisely the
 * users who have none, so a role-less account showed "Super Admin" on its own
 * profile and in the user list — exactly the wrong thing to tell an
 * administrator auditing who can do what. Permissions were never actually
 * granted by this function, but the label made the real state invisible.
 */
function getUserRoleName($conn, $role_id) {
    if (!$role_id) return 'No role assigned';
    $role_id = (int)$role_id;
    $result = $conn->query("SELECT name FROM roles WHERE id = $role_id");
    return ($result && $result->num_rows > 0) ? $result->fetch_assoc()['name'] : 'No role assigned';
}

function getDepartmentName($conn, $dept_id) {
    if (!$dept_id) return 'No department';
    $dept_id = (int)$dept_id;
    $result = $conn->query("SELECT name FROM departments WHERE id = $dept_id");
    return ($result && $result->num_rows > 0) ? $result->fetch_assoc()['name'] : 'No department';
}

// Return connection
return $conn;
?>
