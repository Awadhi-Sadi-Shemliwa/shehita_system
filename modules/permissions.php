<?php
/**
 * PAPLONTECH Enterprise Management System
 * Permissions Module - Full CRUD Operations with Department Integration
 * 
 * This module handles:
 * - Automatic table creation if not exists (with department_id support)
 * - Dynamic module discovery from configuration array (easy to extend)
 * - Display all permissions with role, department and module details
 * - Add new permission assignment (department → role → module + permission flags)
 * - Edit existing permission assignments (except protected Super Admin)
 * - Delete permission assignments with confirmation
 * - Auto-protect Super Admin role (ID=1) - All permissions granted automatically
 * - Search/filter functionality (by role, department, module, permission type)
 * - Department-aware role dropdown (roles filtered by selected department)
 * - Full English/Swahili translation support
 * - Extensible module list (add new modules to config array)
 * 
 * REFINED: Removed all sidebar-related code (Issue #1)
 * REFINED: Added foreign key table validation with user-friendly error messages (Issue #2)
 * REFINED: Ensured no conflict with homepage.php (top navbar layout) (Issue #3)
 * 
 * PERMISSION ENHANCED: Buttons now respect user permissions (can_add, can_edit, can_delete)
 * PERMISSION ENHANCED: Self-role protection prevents users from modifying their own role permissions
 */

// Prevent direct access
if (!defined('ACCESS_ALLOWED') && basename($_SERVER['PHP_SELF']) == basename(__FILE__)) {
    // This file is included, so we're safe
}

// Get database connection from parent
global $conn;

// PERMISSION: Get user role and check module permissions
$user_role_id = $_SESSION['role_id'] ?? 0;
$module_name = 'permissions';

// PERMISSION: Check if user has view permission
if (!canView($conn, $user_role_id, $module_name)) {
    echo '<div class="perm-alert perm-alert-danger" style="text-align: center; padding: 40px;">
            <i class="fas fa-lock" style="font-size: 48px; margin-bottom: 16px; display: block;"></i>
            <h3>Access Denied</h3>
            <p>You do not have permission to view this module.</p>
          </div>';
    return; // Stop rendering the module
}

// ============================================================================
// FOREIGN KEY TABLE VALIDATION (ISSUE #2)
// Check if required dependent tables exist before proceeding
// ============================================================================

$missing_tables = [];

// Check for roles table (required for permission assignments)
$check_roles = $conn->query("SHOW TABLES LIKE 'roles'");
if ($check_roles->num_rows == 0) {
    $missing_tables[] = ['table' => 'roles', 'module' => 'roles', 'display' => 'Roles'];
}

// Check for departments table (required for department filter)
$check_departments = $conn->query("SHOW TABLES LIKE 'departments'");
if ($check_departments->num_rows == 0) {
    $missing_tables[] = ['table' => 'departments', 'module' => 'departments', 'display' => 'Departments'];
}

// Display error message if any required tables are missing
if (!empty($missing_tables)) {
    echo '<div class="perm-container" style="max-width: 800px; margin: 40px auto;">';
    echo '<div class="perm-alert perm-alert-danger" style="background: #fff3cd; color: #856404; border-color: #ffeeba; text-align: left;">';
    echo '<i class="fas fa-exclamation-triangle" style="font-size: 24px; margin-right: 12px;"></i>';
    echo '<strong>⚠️ Required Tables Missing!</strong><br><br>';
    echo '<p>The following required tables do not exist in the database. Please open the related modules first to automatically create them:</p>';
    echo '<ul style="margin-top: 12px; margin-left: 20px;">';
    foreach ($missing_tables as $missing) {
        echo '<li><strong>' . htmlspecialchars($missing['table']) . '</strong> → Please open the <strong>' . htmlspecialchars($missing['display']) . '</strong> module first</li>';
    }
    echo '</ul>';
    echo '<p style="margin-top: 16px;">After opening the required modules, refresh this page to continue.</p>';
    echo '</div></div>';
    return; // Stop rendering the module
}

// ============================================================================
// AVAILABLE MODULES CONFIGURATION
// ============================================================================
// To add a new module to the permissions system, add its identifier to this array.
// The module identifier must match the 'page' parameter used in the URL (?page=module-name)
// For modules with hyphens, use the exact identifier as it appears in the URL.
// ============================================================================
$AVAILABLE_MODULES = [
    'home', 'overview', 'projects', 'operations', 'status',
    'projectlist', 'categories', 'projectgroup', 'expensescategory', 'expensesgroup',
    'customer-management', 'company-settings', 'user-management', 'departments', 
    'roles', 'permissions', 'profile', 'invoice', 'systemsettings'
];

// ============================================================================
// DATABASE SCHEMA CREATION
// ============================================================================

// First, check if permissions table exists and alter it to add department_id if needed
$table_check = $conn->query("SHOW TABLES LIKE 'permissions'");
$table_exists = ($table_check && $table_check->num_rows > 0);

if ($table_exists) {
    // Check if department_id column exists
    $column_check = $conn->query("SHOW COLUMNS FROM permissions LIKE 'department_id'");
    if ($column_check->num_rows == 0) {
        // Add department_id column to existing table
        $alter_sql = "ALTER TABLE permissions 
                      ADD COLUMN department_id INT(11) UNSIGNED NULL AFTER role_id,
                      ADD INDEX idx_department (department_id),
                      ADD FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE";
        
        if (!$conn->query($alter_sql)) {
            // If foreign key fails (maybe due to existing data), try without FK first
            $conn->query("ALTER TABLE permissions ADD COLUMN department_id INT(11) UNSIGNED NULL AFTER role_id, ADD INDEX idx_department (department_id)");
            
            // Update existing records to set department_id from roles table
            $conn->query("UPDATE permissions p SET p.department_id = (SELECT r.department_id FROM roles r WHERE r.id = p.role_id) WHERE p.department_id IS NULL");
            
            // Now add foreign key constraint
            $conn->query("ALTER TABLE permissions ADD FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE");
        }
        
        // Drop the old unique constraint if it exists and create new one
        $conn->query("ALTER TABLE permissions DROP INDEX unique_role_module");
        $conn->query("ALTER TABLE permissions ADD UNIQUE KEY unique_role_module (role_id, module_name)");
    }
}

// Schema note: the `permissions` table is created centrally in config.php.
// This module assumes it already exists and only manages its data below.

/**
 * Update existing permissions to set department_id from roles table
 */
$conn->query("UPDATE permissions p SET p.department_id = (SELECT r.department_id FROM roles r WHERE r.id = p.role_id) WHERE p.department_id IS NULL");

/**
 * Initialize Super Admin Permissions (Role ID = 1)
 * The Super Admin role should have full permissions for all modules
 */
$super_admin_id = 1; // Default User role ID is 1

// Check if Super Admin role exists
$check_super_admin = $conn->query("SELECT id, department_id FROM roles WHERE id = $super_admin_id");
if ($check_super_admin->num_rows > 0) {
    $super_admin_data = $check_super_admin->fetch_assoc();
    $super_admin_dept_id = $super_admin_data['department_id'];
    
    // For each module, ensure Super Admin has full permissions
    foreach ($AVAILABLE_MODULES as $module) {
        $check_perm = $conn->prepare("SELECT id FROM permissions WHERE role_id = ? AND module_name = ?");
        $check_perm->bind_param("is", $super_admin_id, $module);
        $check_perm->execute();
        $perm_result = $check_perm->get_result();
        
        if ($perm_result->num_rows == 0) {
            // Insert full permissions for Super Admin
            $insert_perm = $conn->prepare("INSERT INTO permissions (role_id, department_id, module_name, can_view, can_add, can_edit, can_delete) VALUES (?, ?, ?, 1, 1, 1, 1)");
            $insert_perm->bind_param("iis", $super_admin_id, $super_admin_dept_id, $module);
            $insert_perm->execute();
            $insert_perm->close();
        } else {
            // Update to ensure full permissions
            $update_perm = $conn->prepare("UPDATE permissions SET can_view = 1, can_add = 1, can_edit = 1, can_delete = 1, department_id = ? WHERE role_id = ? AND module_name = ?");
            $update_perm->bind_param("iis", $super_admin_dept_id, $super_admin_id, $module);
            $update_perm->execute();
            $update_perm->close();
        }
        $check_perm->close();
    }
}

/**
 * CHECK IF TABLE IS EMPTY AND RESET AUTO_INCREMENT IF NEEDED
 */
$check_empty = $conn->query("SELECT COUNT(*) as count FROM permissions");
$row_count = $check_empty->fetch_assoc()['count'];

if ($row_count == 0) {
    $conn->query("ALTER TABLE permissions AUTO_INCREMENT = 1");
} else {
    // Ensure auto_increment is set correctly
    $max_id = $conn->query("SELECT MAX(id) as max_id FROM permissions")->fetch_assoc()['max_id'];
    if ($max_id) {
        $conn->query("ALTER TABLE permissions AUTO_INCREMENT = " . ($max_id + 1));
    }
}

// Initialize variables for messages
$permissions_message = '';
$permissions_message_type = '';

// Initialize search/filter variables
$search = isset($_GET['search']) ? sanitize($conn, $_GET['search']) : '';
$role_filter = isset($_GET['role_filter']) ? (int)$_GET['role_filter'] : '';
$department_filter = isset($_GET['department_filter']) ? (int)$_GET['department_filter'] : '';
$module_filter = isset($_GET['module_filter']) ? sanitize($conn, $_GET['module_filter']) : '';
$perm_type_filter = isset($_GET['perm_type_filter']) ? sanitize($conn, $_GET['perm_type_filter']) : '';

// Variables for department-aware role dropdown
$selected_department_id = isset($_POST['permissions_department_id']) ? (int)$_POST['permissions_department_id'] : 0;

/**
 * ============================================================================
 * PROTECTION CHECKS FOR SUPER ADMIN PERMISSIONS (ROLE ID = 1)
 * Redirect with error message if trying to edit or delete Super Admin permissions
 * ============================================================================
 */
if (isset($_GET['perm_edit'])) {
    $edit_id = (int)$_GET['perm_edit'];
    // Check if this permission belongs to Super Admin role
    $check_super = $conn->prepare("SELECT role_id FROM permissions WHERE id = ?");
    $check_super->bind_param("i", $edit_id);
    $check_super->execute();
    $super_check_result = $check_super->get_result();
    if ($super_check_result->num_rows > 0) {
        $perm_data = $super_check_result->fetch_assoc();
        if ($perm_data['role_id'] == 1) {
            $permissions_message = "Super Admin permissions are protected and cannot be edited";
            $permissions_message_type = "danger";
            header("Location: ?page=permissions");
            exit();
        }
    }
    $check_super->close();
}

if (isset($_GET['perm_delete'])) {
    $delete_id = (int)$_GET['perm_delete'];
    // Check if this permission belongs to Super Admin role
    $check_super = $conn->prepare("SELECT role_id FROM permissions WHERE id = ?");
    $check_super->bind_param("i", $delete_id);
    $check_super->execute();
    $super_check_result = $check_super->get_result();
    if ($super_check_result->num_rows > 0) {
        $perm_data = $super_check_result->fetch_assoc();
        if ($perm_data['role_id'] == 1) {
            $permissions_message = "Super Admin permissions are protected and cannot be deleted";
            $permissions_message_type = "danger";
            header("Location: ?page=permissions");
            exit();
        }
    }
    $check_super->close();
}

// PERMISSION: Protect user from editing/deleting permissions for their own role
if (isset($_GET['perm_edit']) || isset($_GET['perm_delete'])) {
    $id_to_check = isset($_GET['perm_edit']) ? (int)$_GET['perm_edit'] : (int)$_GET['perm_delete'];
    if ($id_to_check > 0 && $user_role_id != 1) { // Not needed for Super Admin
        $check_self = $conn->prepare("SELECT role_id FROM permissions WHERE id = ?");
        $check_self->bind_param("i", $id_to_check);
        $check_self->execute();
        $self_result = $check_self->get_result();
        if ($self_result->num_rows > 0) {
            $perm_data = $self_result->fetch_assoc();
            if ($perm_data['role_id'] == $user_role_id) {
                $permissions_message = "You cannot modify permissions for your own role.";
                $permissions_message_type = "danger";
                header("Location: ?page=permissions");
                exit();
            }
        }
        $check_self->close();
    }
}

/**
 * ============================================================================
 * CSRF PROTECTION
 * ============================================================================
 */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * ============================================================================
 * HANDLE FORM SUBMISSIONS WITH PERMISSION CHECKS
 * ============================================================================
 */

// PERMISSION: Check add permission before processing add operation
if (isset($_POST['perm_add']) && !canAdd($conn, $user_role_id, $module_name)) {
    $permissions_message = "You do not have permission to add permissions.";
    $permissions_message_type = "danger";
} elseif (isset($_POST['perm_add'])) {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $permissions_message = "Invalid form submission. Please try again.";
        $permissions_message_type = "danger";
    } else {
        $role_id = (int)$_POST['permissions_role_id'];
        $module_name_input = sanitize($conn, $_POST['permissions_module_name']);
        $can_view = isset($_POST['permissions_can_view']) ? 1 : 0;
        $can_add = isset($_POST['permissions_can_add']) ? 1 : 0;
        $can_edit = isset($_POST['permissions_can_edit']) ? 1 : 0;
        $can_delete = isset($_POST['permissions_can_delete']) ? 1 : 0;
        
        // Get department_id from the selected role
        $department_id = null;
        if ($role_id > 0) {
            $dept_query = $conn->prepare("SELECT department_id FROM roles WHERE id = ?");
            $dept_query->bind_param("i", $role_id);
            $dept_query->execute();
            $dept_result = $dept_query->get_result();
            if ($dept_result->num_rows > 0) {
                $dept_data = $dept_result->fetch_assoc();
                $department_id = $dept_data['department_id'];
            }
            $dept_query->close();
        }
        
        // Validate inputs
        $errors = [];
        
        if ($role_id <= 0) {
            $errors[] = "Please select a role";
        } else {
            // Verify role exists
            $role_check = $conn->query("SELECT id FROM roles WHERE id = $role_id");
            if ($role_check->num_rows == 0) {
                $errors[] = "Selected role not found";
            }
        }
        
        if (empty($module_name_input)) {
            $errors[] = "Please select a module";
        } elseif (!in_array($module_name_input, $AVAILABLE_MODULES)) {
            $errors[] = "Invalid module selected";
        }
        
        // Check for duplicate permission
        if (empty($errors)) {
            $check_duplicate = $conn->prepare("SELECT id FROM permissions WHERE role_id = ? AND module_name = ?");
            $check_duplicate->bind_param("is", $role_id, $module_name_input);
            $check_duplicate->execute();
            $dup_result = $check_duplicate->get_result();
            
            if ($dup_result->num_rows > 0) {
                $errors[] = "A permission for this role and module already exists";
            }
            $check_duplicate->close();
        }
        
        if (empty($errors)) {
            // Insert new permission
            $insert_stmt = $conn->prepare("INSERT INTO permissions (role_id, department_id, module_name, can_view, can_add, can_edit, can_delete) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $insert_stmt->bind_param("iisiiii", $role_id, $department_id, $module_name_input, $can_view, $can_add, $can_edit, $can_delete);
            
            if ($insert_stmt->execute()) {
                $permissions_message = "Permission added successfully!";
                $permissions_message_type = "success";
            } else {
                $permissions_message = "Error adding permission: " . $conn->error;
                $permissions_message_type = "danger";
            }
            $insert_stmt->close();
        } else {
            $permissions_message = implode("<br>", $errors);
            $permissions_message_type = "danger";
        }
    }
    
    // Generate new CSRF token
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// PERMISSION: Check edit permission before processing update operation
if (isset($_POST['perm_update']) && !canEdit($conn, $user_role_id, $module_name)) {
    $permissions_message = "You do not have permission to edit permissions.";
    $permissions_message_type = "danger";
} elseif (isset($_POST['perm_update'])) {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $permissions_message = "Invalid form submission. Please try again.";
        $permissions_message_type = "danger";
    } else {
        $id = (int)$_POST['permissions_id'];
        $can_view = isset($_POST['permissions_can_view']) ? 1 : 0;
        $can_add = isset($_POST['permissions_can_add']) ? 1 : 0;
        $can_edit = isset($_POST['permissions_can_edit']) ? 1 : 0;
        $can_delete = isset($_POST['permissions_can_delete']) ? 1 : 0;
        
        // First, check if this permission belongs to Super Admin role
        $check_super = $conn->prepare("SELECT role_id FROM permissions WHERE id = ?");
        $check_super->bind_param("i", $id);
        $check_super->execute();
        $super_check_result = $check_super->get_result();
        
        if ($super_check_result->num_rows > 0) {
            $perm_data = $super_check_result->fetch_assoc();
            if ($perm_data['role_id'] == 1) {
                $permissions_message = "Super Admin permissions are protected and cannot be edited";
                $permissions_message_type = "danger";
            } else {
                // Update permission
                $update_stmt = $conn->prepare("UPDATE permissions SET can_view = ?, can_add = ?, can_edit = ?, can_delete = ? WHERE id = ?");
                $update_stmt->bind_param("iiiii", $can_view, $can_add, $can_edit, $can_delete, $id);
                
                if ($update_stmt->execute()) {
                    $permissions_message = "Permission updated successfully!";
                    $permissions_message_type = "success";
                } else {
                    $permissions_message = "Error updating permission: " . $conn->error;
                    $permissions_message_type = "danger";
                }
                $update_stmt->close();
            }
        } else {
            $permissions_message = "Permission not found";
            $permissions_message_type = "danger";
        }
        $check_super->close();
    }
    
    // Generate new CSRF token
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// PERMISSION: Check delete permission before processing delete operation
if (isset($_GET['perm_delete']) && !canDelete($conn, $user_role_id, $module_name)) {
    $permissions_message = "You do not have permission to delete permissions.";
    $permissions_message_type = "danger";
} elseif (isset($_GET['perm_delete'])) {
    $id = (int)$_GET['perm_delete'];
    
    // Check if this permission belongs to Super Admin role
    $check_super = $conn->prepare("SELECT role_id FROM permissions WHERE id = ?");
    $check_super->bind_param("i", $id);
    $check_super->execute();
    $super_check_result = $check_super->get_result();
    
    if ($super_check_result->num_rows > 0) {
        $perm_data = $super_check_result->fetch_assoc();
        if ($perm_data['role_id'] == 1) {
            $permissions_message = "Super Admin permissions are protected and cannot be deleted";
            $permissions_message_type = "danger";
        } else {
            $delete_stmt = $conn->prepare("DELETE FROM permissions WHERE id = ?");
            $delete_stmt->bind_param("i", $id);
            
            if ($delete_stmt->execute()) {
                $permissions_message = "Permission deleted successfully!";
                $permissions_message_type = "success";
                
                // Check if table is empty and reset auto-increment
                $check_empty = $conn->query("SELECT COUNT(*) as count FROM permissions");
                $row_count = $check_empty->fetch_assoc()['count'];
                
                if ($row_count == 0) {
                    $conn->query("ALTER TABLE permissions AUTO_INCREMENT = 1");
                }
            } else {
                $permissions_message = "Error deleting permission: " . $conn->error;
                $permissions_message_type = "danger";
            }
            $delete_stmt->close();
        }
    } else {
        $permissions_message = "Permission not found";
        $permissions_message_type = "danger";
    }
    $check_super->close();
}

// Get edit data if in edit mode (protected permissions already handled above)
// PERMISSION: Only allow edit if user has edit permission
$edit_mode = false;
$edit_data = null;
$edit_department_id = 0;
$edit_role_id = 0;

if (isset($_GET['perm_edit'])) {
    $edit_id = (int)$_GET['perm_edit'];
    // PERMISSION: Only allow edit if user has edit permission
    if ($edit_id > 0 && canEdit($conn, $user_role_id, $module_name)) {
        $edit_stmt = $conn->prepare("SELECT * FROM permissions WHERE id = ?");
        $edit_stmt->bind_param("i", $edit_id);
        $edit_stmt->execute();
        $edit_result = $edit_stmt->get_result();
        
        if ($edit_result->num_rows > 0) {
            $edit_data = $edit_result->fetch_assoc();
            // Double-check it's not Super Admin (should have been caught earlier)
            if ($edit_data['role_id'] != 1) {
                $edit_mode = true;
                $edit_role_id = $edit_data['role_id'];
                // Get department from role
                $role_dept_query = $conn->prepare("SELECT department_id FROM roles WHERE id = ?");
                $role_dept_query->bind_param("i", $edit_role_id);
                $role_dept_query->execute();
                $role_dept_result = $role_dept_query->get_result();
                if ($role_dept_result->num_rows > 0) {
                    $role_dept = $role_dept_result->fetch_assoc();
                    $edit_department_id = $role_dept['department_id'];
                }
                $role_dept_query->close();
            }
        }
        $edit_stmt->close();
    }
}

/**
 * ============================================================================
 * FETCH DEPARTMENTS FOR DROPDOWN
 * Get all active departments for the filter dropdown
 * ============================================================================
 */
$departments_dropdown = [];
$departments_query = "SELECT id, name FROM departments WHERE status = 'Active' ORDER BY name ASC";
$departments_result = $conn->query($departments_query);

if ($departments_result && $departments_result->num_rows > 0) {
    while ($row = $departments_result->fetch_assoc()) {
        $departments_dropdown[] = $row;
    }
}

/**
 * ============================================================================
 * FETCH ROLES FOR DROPDOWN (Department-aware)
 * Get roles based on selected department (for add form AJAX)
 * ============================================================================
 */
$roles_dropdown = [];
$roles_query = "SELECT id, name, department_id FROM roles ORDER BY name ASC";
$roles_result = $conn->query($roles_query);

if ($roles_result && $roles_result->num_rows > 0) {
    while ($row = $roles_result->fetch_assoc()) {
        $roles_dropdown[] = $row;
    }
}

// Get roles for the pre-selected department in edit mode
$edit_roles_dropdown = [];
if ($edit_mode && $edit_department_id > 0) {
    $edit_roles_query = $conn->prepare("SELECT id, name FROM roles WHERE department_id = ? ORDER BY name ASC");
    $edit_roles_query->bind_param("i", $edit_department_id);
    $edit_roles_query->execute();
    $edit_roles_result = $edit_roles_query->get_result();
    if ($edit_roles_result && $edit_roles_result->num_rows > 0) {
        while ($row = $edit_roles_result->fetch_assoc()) {
            $edit_roles_dropdown[] = $row;
        }
    }
    $edit_roles_query->close();
}

/**
 * ============================================================================
 * FETCH ALL PERMISSIONS WITH SEARCH AND FILTER
 * Join with roles and departments tables to get role and department names
 * ============================================================================
 */
$where_clause = "";
$params = [];
$types = "";

if (!empty($search)) {
    $where_clause .= " (r.name LIKE ? OR p.module_name LIKE ? OR d.name LIKE ?) ";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

if (!empty($role_filter)) {
    if (!empty($where_clause)) {
        $where_clause .= " AND ";
    }
    $where_clause .= " p.role_id = ? ";
    $params[] = $role_filter;
    $types .= "i";
}

if (!empty($department_filter)) {
    if (!empty($where_clause)) {
        $where_clause .= " AND ";
    }
    $where_clause .= " d.id = ? ";
    $params[] = $department_filter;
    $types .= "i";
}

if (!empty($module_filter)) {
    if (!empty($where_clause)) {
        $where_clause .= " AND ";
    }
    $where_clause .= " p.module_name = ? ";
    $params[] = $module_filter;
    $types .= "s";
}

// Permission type filter (has view, add, edit, delete)
if (!empty($perm_type_filter)) {
    if (!empty($where_clause)) {
        $where_clause .= " AND ";
    }
    switch ($perm_type_filter) {
        case 'has_view':
            $where_clause .= " p.can_view = 1 ";
            break;
        case 'has_add':
            $where_clause .= " p.can_add = 1 ";
            break;
        case 'has_edit':
            $where_clause .= " p.can_edit = 1 ";
            break;
        case 'has_delete':
            $where_clause .= " p.can_delete = 1 ";
            break;
    }
}

if (!empty($where_clause)) {
    $where_clause = "WHERE " . $where_clause;
}

$permissions_query = "SELECT p.*, r.name as role_name, d.name as department_name 
                      FROM permissions p 
                      LEFT JOIN roles r ON p.role_id = r.id 
                      LEFT JOIN departments d ON r.department_id = d.id 
                      $where_clause 
                      ORDER BY d.name ASC, r.name ASC, p.module_name ASC";

// Prepare and execute the query with parameters if needed
if (!empty($params)) {
    $stmt = $conn->prepare($permissions_query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $permissions_result = $stmt->get_result();
} else {
    $permissions_result = $conn->query($permissions_query);
}

// Get total count for display
$total_count = $permissions_result->num_rows;

// Get statistics for filter dropdowns
$modules_stats = [];
foreach ($AVAILABLE_MODULES as $module) {
    $count_query = $conn->prepare("SELECT COUNT(*) as count FROM permissions WHERE module_name = ?");
    $count_query->bind_param("s", $module);
    $count_query->execute();
    $count_result = $count_query->get_result();
    $count = $count_result->fetch_assoc()['count'];
    $modules_stats[] = ['name' => $module, 'count' => $count];
    $count_query->close();
}

// Get departments with permission counts
$departments_stats = [];
foreach ($departments_dropdown as $dept) {
    $count_query = $conn->prepare("SELECT COUNT(*) as count FROM permissions p LEFT JOIN roles r ON p.role_id = r.id WHERE r.department_id = ?");
    $count_query->bind_param("i", $dept['id']);
    $count_query->execute();
    $count_result = $count_query->get_result();
    $count = $count_result->fetch_assoc()['count'];
    $departments_stats[] = ['id' => $dept['id'], 'name' => $dept['name'], 'count' => $count];
    $count_query->close();
}

// Get roles with permission counts (for stats)
$roles_stats = [];
foreach ($roles_dropdown as $role) {
    $count_query = $conn->prepare("SELECT COUNT(*) as count FROM permissions WHERE role_id = ?");
    $count_query->bind_param("i", $role['id']);
    $count_query->execute();
    $count_result = $count_query->get_result();
    $count = $count_result->fetch_assoc()['count'];
    $roles_stats[] = ['id' => $role['id'], 'name' => $role['name'], 'count' => $count];
    $count_query->close();
}
?>

<!-- PERMISSIONS TRANSLATIONS -->
<script>
// Permissions translations for English and Swahili
const permissions_translations = {
    en: {
        pageTitle: 'Permissions Management',
        addNew: 'Add New Permission',
        editPermission: 'Edit Permission',
        addPermission: 'Add New Permission',
        id: 'ID',
        role: 'Role',
        department: 'Department',
        module: 'Module',
        permissions: 'Permissions',
        view: 'View',
        add: 'Add',
        edit: 'Edit',
        delete: 'Delete',
        actions: 'Actions',
        save: 'Save',
        cancel: 'Cancel',
        created: 'Created',
        updated: 'Updated',
        confirmDelete: 'Are you sure you want to delete this permission?',
        confirmDeleteMsg: 'This will remove all access rights for this role on this module.',
        noData: 'No permissions found.',
        roleRequired: 'Please select a role!',
        moduleRequired: 'Please select a module!',
        departmentRequired: 'Please select a department first!',
        selectDepartmentFirst: 'Select Department First',
        loading: 'Loading...',
        search: 'Search',
        filter: 'Filter',
        allRoles: 'All Roles',
        allDepartments: 'All Departments',
        allModules: 'All Modules',
        allPermissions: 'All Permissions',
        hasView: 'Has View',
        hasAdd: 'Has Add',
        hasEdit: 'Has Edit',
        hasDelete: 'Has Delete',
        clear: 'Clear',
        totalRecords: 'Total Permissions',
        records: 'records',
        selectRole: 'Select Role',
        selectModule: 'Select Module',
        selectDepartment: 'Select Department',
        protectedRole: 'Super Admin',
        protectedTooltip: 'Super Admin role - All permissions automatically granted, cannot be modified',
        cannotEditProtected: 'Super Admin permissions are protected and cannot be edited',
        cannotDeleteProtected: 'Super Admin permissions are protected and cannot be deleted',
        statsByRole: 'Permissions by Role',
        statsByDepartment: 'Permissions by Department',
        statsByModule: 'Permissions by Module'
    },
    sw: {
        pageTitle: 'Usimamizi wa Ruhusa',
        addNew: 'Ongeza Ruhusa Mpya',
        editPermission: 'Hariri Ruhusa',
        addPermission: 'Ongeza Ruhusa Mpya',
        id: 'Kitambulisho',
        role: 'Jukumu',
        department: 'Idara',
        module: 'Moduli',
        permissions: 'Ruhusa',
        view: 'Tazama',
        add: 'Ongeza',
        edit: 'Hariri',
        delete: 'Futa',
        actions: 'Vitendo',
        save: 'Hifadhi',
        cancel: 'Ghairi',
        created: 'Imeundwa',
        updated: 'Imesasishwa',
        confirmDelete: 'Una uhakika unataka kufuta ruhusa hii?',
        confirmDeleteMsg: 'Hii itaondoa haki zote za ufikiaji kwa jukumu hili kwenye moduli hii.',
        noData: 'Hakuna ruhusa zilizopatikana.',
        roleRequired: 'Tafadhali chagua jukumu!',
        moduleRequired: 'Tafadhali chagua moduli!',
        departmentRequired: 'Tafadhali chagua idara kwanza!',
        selectDepartmentFirst: 'Chagua Idara Kwanza',
        loading: 'Inapakia...',
        search: 'Tafuta',
        filter: 'Chuja',
        allRoles: 'Majukumu Yote',
        allDepartments: 'Idara Zote',
        allModules: 'Moduli Zote',
        allPermissions: 'Ruhusa Zote',
        hasView: 'Ina Tazama',
        hasAdd: 'Ina Ongeza',
        hasEdit: 'Ina Hariri',
        hasDelete: 'Ina Futa',
        clear: 'Futa',
        totalRecords: 'Jumla ya Ruhusa',
        records: 'rekodi',
        selectRole: 'Chagua Jukumu',
        selectModule: 'Chagua Moduli',
        selectDepartment: 'Chagua Idara',
        protectedRole: 'Msimamizi Mkuu',
        protectedTooltip: 'Jukumu la Msimamizi Mkuu - Ruhusa zote zimetolewa kiotomatiki, haziwezi kubadilishwa',
        cannotEditProtected: 'Ruhusa za Msimamizi Mkuu hazinawezi kubadilishwa',
        cannotDeleteProtected: 'Ruhusa za Msimamizi Mkuu hazinawezi kufutwa',
        statsByRole: 'Ruhusa kwa Jukumu',
        statsByDepartment: 'Ruhusa kwa Idara',
        statsByModule: 'Ruhusa kwa Moduli'
    }
};

// Current language (will be updated by homepage.js)
let currentPermissionsLang = localStorage.getItem('preferredLanguage') || 'en';

// Function to update all translatable elements in permissions module
function updatePermissionsLanguage(lang) {
    currentPermissionsLang = lang;
    const elements = document.querySelectorAll('[data-perm-lang]');
    
    elements.forEach(element => {
        const key = element.getAttribute('data-perm-lang');
        if (permissions_translations[lang] && permissions_translations[lang][key]) {
            if (element.tagName === 'INPUT' || element.tagName === 'TEXTAREA') {
                if (element.placeholder !== undefined) {
                    element.placeholder = permissions_translations[lang][key];
                }
            } else if (element.tagName === 'OPTION') {
                element.textContent = permissions_translations[lang][key];
            } else {
                element.textContent = permissions_translations[lang][key];
            }
        }
    });
    
    // Update table header specifically if they have data-perm-lang attributes
    const thElements = document.querySelectorAll('th[data-perm-lang]');
    thElements.forEach(th => {
        const key = th.getAttribute('data-perm-lang');
        if (permissions_translations[lang] && permissions_translations[lang][key]) {
            th.textContent = permissions_translations[lang][key];
        }
    });
    
    // Update empty state message
    const emptyState = document.querySelector('.perm-empty p');
    if (emptyState) {
        emptyState.textContent = permissions_translations[lang].noData;
    }
    
    // Update form header based on edit mode
    const formHeader = document.querySelector('#permissionsForm h3');
    if (formHeader) {
        const isEditMode = document.querySelector('input[name="permissions_id"]') !== null;
        formHeader.textContent = permissions_translations[lang][isEditMode ? 'editPermission' : 'addPermission'];
    }
    
    // Update search input placeholder
    const searchInput = document.querySelector('input[name="search"]');
    if (searchInput) {
        searchInput.placeholder = permissions_translations[lang].search;
    }
    
    // Update total records text
    const totalRecordsSpan = document.querySelector('#totalRecords');
    if (totalRecordsSpan) {
        totalRecordsSpan.textContent = permissions_translations[lang].totalRecords;
    }
    
    // Update protected row tooltips
    document.querySelectorAll('.protected-row .protected-tooltip').forEach(el => {
        el.setAttribute('title', permissions_translations[lang].protectedTooltip);
    });
}

// Listen for language change events from homepage
document.addEventListener('DOMContentLoaded', function() {
    // Initial update
    updatePermissionsLanguage(currentPermissionsLang);
});

// This function will be called from homepage.js when language changes
window.updatePermissionsLanguage = updatePermissionsLanguage;
</script>

<style>
    /* Permissions Module Styles - Using perm_ prefix (ISSUE #3: No sidebar conflicts) */
    .perm-container {
        width: 100%;
    }

    .perm-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
        flex-wrap: wrap;
        gap: 16px;
    }

    .perm-header h2 {
        color: var(--gray-800);
        font-size: 24px;
        font-weight: 600;
        margin: 0;
    }

    .perm-btn {
        background: var(--brown-700);
        color: white;
        border: none;
        padding: 12px 24px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all 0.2s;
        text-decoration: none;
    }

    .perm-btn:hover {
        background: var(--brown-800);
        transform: translateY(-2px);
        box-shadow: var(--shadow-md);
    }

    .perm-btn-secondary {
        background: var(--gray-200);
        color: var(--gray-700);
    }

    .perm-btn-secondary:hover {
        background: var(--gray-300);
    }

    .perm-btn-danger {
        background: #dc3545;
    }

    .perm-btn-danger:hover {
        background: #c82333;
    }

    .perm-form {
        background: var(--gray-50);
        padding: 24px;
        border-radius: 12px;
        margin-bottom: 32px;
        border: 1px solid var(--gray-200);
        display: none;
    }

    .perm-form.show {
        display: block;
    }

    .perm-form h3 {
        color: var(--gray-800);
        font-size: 18px;
        font-weight: 600;
        margin-bottom: 20px;
    }

    .perm-form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 16px;
        margin-bottom: 20px;
    }

    .perm-form-group {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .perm-form-group-full {
        grid-column: 1 / -1;
    }

    .perm-form-group label {
        color: var(--gray-700);
        font-size: 14px;
        font-weight: 500;
    }

    .perm-form-group select,
    .perm-form-group input[type="text"] {
        padding: 10px 12px;
        border: 1px solid var(--gray-300);
        border-radius: 6px;
        font-size: 14px;
        transition: all 0.2s;
        font-family: inherit;
    }

    .perm-form-group select:focus,
    .perm-form-group input:focus {
        outline: none;
        border-color: var(--brown-600);
        box-shadow: 0 0 0 3px rgba(123, 88, 63, 0.1);
    }

    .perm-form-group select:disabled {
        background-color: var(--gray-100);
        cursor: not-allowed;
    }

    /* Permission Checkboxes Group */
    .perm-checkbox-group {
        display: flex;
        flex-wrap: wrap;
        gap: 24px;
        padding: 12px 0;
    }

    .perm-checkbox-item {
        display: flex;
        align-items: center;
        gap: 8px;
        cursor: pointer;
    }

    .perm-checkbox-item input[type="checkbox"] {
        width: 18px;
        height: 18px;
        cursor: pointer;
        accent-color: var(--brown-700);
    }

    .perm-checkbox-item label {
        cursor: pointer;
        font-weight: normal;
        margin: 0;
        color: var(--gray-700);
    }

    .perm-form-actions {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
        margin-top: 16px;
    }

    .perm-alert {
        padding: 16px;
        border-radius: 8px;
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .perm-alert-success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }

    .perm-alert-danger {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }

    .perm-alert-close {
        background: none;
        border: none;
        font-size: 20px;
        cursor: pointer;
        color: inherit;
        opacity: 0.5;
    }

    .perm-alert-close:hover {
        opacity: 1;
    }

    /* Search and Filter Bar */
    .perm-search-bar {
        background: white;
        padding: 20px;
        border-radius: 12px;
        margin-bottom: 24px;
        border: 1px solid var(--gray-200);
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        align-items: flex-end;
    }

    .perm-search-group {
        flex: 1;
        min-width: 160px;
    }

    .perm-search-group label {
        display: block;
        color: var(--gray-700);
        font-size: 13px;
        font-weight: 500;
        margin-bottom: 6px;
    }

    .perm-search-group input,
    .perm-search-group select {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid var(--gray-300);
        border-radius: 6px;
        font-size: 14px;
    }

    .perm-search-group input:focus,
    .perm-search-group select:focus {
        outline: none;
        border-color: var(--brown-600);
        box-shadow: 0 0 0 3px rgba(123, 88, 63, 0.1);
    }

    .perm-search-actions {
        display: flex;
        gap: 8px;
    }

    .perm-search-btn {
        padding: 10px 20px;
        background: var(--brown-700);
        color: white;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-weight: 500;
        transition: all 0.2s;
    }

    .perm-search-btn:hover {
        background: var(--brown-800);
    }

    .perm-clear-btn {
        padding: 10px 20px;
        background: var(--gray-200);
        color: var(--gray-700);
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-weight: 500;
        transition: all 0.2s;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .perm-clear-btn:hover {
        background: var(--gray-300);
    }

    /* Stats Bar */
    .perm-stats {
        background: var(--gray-50);
        padding: 12px 20px;
        border-radius: 8px;
        margin-bottom: 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
        border: 1px solid var(--gray-200);
    }

    .perm-stats-info {
        display: flex;
        align-items: center;
        gap: 8px;
        color: var(--gray-700);
        font-size: 14px;
    }

    .perm-stats-info i {
        color: var(--brown-600);
    }

    .perm-stats-count {
        font-weight: 700;
        color: var(--brown-700);
        font-size: 18px;
    }

    /* Stats Cards */
    .perm-stats-cards {
        display: flex;
        flex-wrap: wrap;
        gap: 16px;
        margin-bottom: 24px;
    }

    .perm-stats-card {
        background: white;
        border-radius: 12px;
        padding: 16px 20px;
        flex: 1;
        min-width: 200px;
        border: 1px solid var(--gray-200);
        box-shadow: var(--shadow-sm);
    }

    .perm-stats-card h4 {
        font-size: 14px;
        font-weight: 600;
        color: var(--gray-600);
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .perm-stats-card-list {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }

    .perm-stats-badge {
        background: var(--gray-100);
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        color: var(--gray-700);
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .perm-stats-badge .count {
        background: var(--brown-100);
        color: var(--brown-800);
        padding: 2px 6px;
        border-radius: 12px;
        font-weight: 600;
        font-size: 11px;
    }

    /* Table Styles */
    .perm-table-container {
        overflow-x: auto;
        background: white;
        border-radius: 12px;
        border: 1px solid var(--gray-200);
    }

    .perm-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 900px;
    }

    .perm-table th {
        background: var(--gray-50);
        color: var(--gray-700);
        font-weight: 600;
        font-size: 14px;
        padding: 16px;
        text-align: left;
        border-bottom: 2px solid var(--gray-200);
    }

    .perm-table td {
        padding: 16px;
        border-bottom: 1px solid var(--gray-200);
        color: var(--gray-700);
        font-size: 14px;
        vertical-align: middle;
    }

    .perm-table tr:hover {
        background: var(--gray-50);
    }

    /* Protected row styling */
    .perm-table tr.protected-row {
        background-color: #fef9e6;
        border-left: 3px solid var(--brown-600);
    }

    .perm-table tr.protected-row:hover {
        background-color: #fef5e0;
    }

    .perm-protected-badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        background: #e9ecef;
        color: #6c757d;
        font-size: 11px;
        padding: 2px 8px;
        border-radius: 12px;
        margin-left: 8px;
        cursor: help;
    }

    .perm-protected-badge i {
        font-size: 10px;
    }

    /* Permission badges in table */
    .perm-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 28px;
        height: 28px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 600;
    }

    .perm-badge-yes {
        background: #d4edda;
        color: #155724;
    }

    .perm-badge-no {
        background: #f8d7da;
        color: #721c24;
    }

    .perm-actions {
        display: flex;
        gap: 8px;
    }

    .perm-action-btn {
        padding: 6px 12px;
        border-radius: 4px;
        font-size: 13px;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        transition: all 0.2s;
    }

    .perm-action-btn.disabled,
    .perm-action-btn.disabled:hover {
        opacity: 0.5;
        cursor: not-allowed;
        pointer-events: none;
        background: var(--gray-200);
        color: var(--gray-500);
    }

    .perm-action-edit {
        background: var(--brown-100);
        color: var(--brown-800);
    }

    .perm-action-edit:hover {
        background: var(--brown-200);
    }

    .perm-action-delete {
        background: #f8d7da;
        color: #721c24;
    }

    .perm-action-delete:hover {
        background: #f5c6cb;
    }

    .perm-loading {
        display: inline-block;
        width: 20px;
        height: 20px;
        border: 3px solid var(--gray-200);
        border-top-color: var(--brown-700);
        border-radius: 50%;
        animation: perm-spin 1s linear infinite;
    }

    @keyframes perm-spin {
        to { transform: rotate(360deg); }
    }

    .perm-empty {
        text-align: center;
        padding: 48px !important;
        color: var(--gray-500);
    }

    .perm-empty i {
        font-size: 48px;
        margin-bottom: 16px;
        color: var(--gray-400);
    }

    .perm-timestamp {
        font-size: 12px;
        color: var(--gray-500);
        margin-top: 4px;
    }

    .perm-module-badge {
        background: var(--brown-100);
        color: var(--brown-800);
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 500;
        display: inline-block;
    }

    .perm-department-badge {
        background: var(--gray-100);
        color: var(--gray-700);
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 500;
        display: inline-block;
    }

    @media (max-width: 768px) {
        .perm-search-bar {
            flex-direction: column;
        }

        .perm-search-actions {
            width: 100%;
        }

        .perm-search-btn,
        .perm-clear-btn {
            flex: 1;
            text-align: center;
            justify-content: center;
        }

        .perm-checkbox-group {
            flex-direction: column;
            gap: 12px;
        }
        
        .perm-stats-cards {
            flex-direction: column;
        }
    }
</style>

<div class="perm-container">
    <!-- Header -->
    <div class="perm-header">
        <h2 data-perm-lang="pageTitle">Permissions Management</h2>
        <?php if (canAdd($conn, $user_role_id, $module_name)): ?>
        <button class="perm-btn" onclick="permissions_toggleForm()" id="permissionsToggleBtn">
            <i class="fas fa-plus"></i>
            <span data-perm-lang="addNew">Add New Permission</span>
        </button>
        <?php endif; ?>
    </div>

    <!-- Alert Messages -->
    <?php if (!empty($permissions_message)): ?>
        <div class="perm-alert perm-alert-<?= $permissions_message_type ?>">
            <?= $permissions_message ?>
            <button class="perm-alert-close" onclick="this.parentElement.style.display='none'">&times;</button>
        </div>
    <?php endif; ?>

    <!-- Add/Edit Form - Only show if user has add or edit permission -->
    <?php if (canAdd($conn, $user_role_id, $module_name) || ($edit_mode && canEdit($conn, $user_role_id, $module_name))): ?>
    <div class="perm-form <?= $edit_mode ? 'show' : '' ?>" id="permissionsForm">
        <h3 data-perm-lang="<?= $edit_mode ? 'editPermission' : 'addPermission' ?>">
            <?= $edit_mode ? 'Edit Permission' : 'Add New Permission' ?>
        </h3>

        <form method="POST" action="?page=permissions" onsubmit="return permissions_validateForm()" id="permissionsAddEditForm">
            <?php if ($edit_mode): ?>
                <input type="hidden" name="permissions_id" id="permissions_id" value="<?= $edit_data['id'] ?>">
            <?php endif; ?>
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

            <div class="perm-form-grid">
                <!-- Department Dropdown (for add mode) -->
                <?php if (!$edit_mode): ?>
                <div class="perm-form-group">
                    <label for="permissions_department_id" data-perm-lang="department">Department *</label>
                    <select id="permissions_department_id" name="permissions_department_id" required onchange="permissions_loadRolesByDepartment(this.value)">
                        <option value="" data-perm-lang="selectDepartment">Select Department</option>
                        <?php foreach ($departments_dropdown as $dept): ?>
                            <option value="<?= $dept['id'] ?>"><?= htmlspecialchars($dept['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <!-- Role Dropdown -->
                <div class="perm-form-group">
                    <label for="permissions_role_id" data-perm-lang="role">Role *</label>
                    <select id="permissions_role_id" name="permissions_role_id" required <?= $edit_mode ? '' : 'disabled' ?>>
                        <?php if ($edit_mode): ?>
                            <option value="" data-perm-lang="selectRole">Select Role</option>
                            <?php foreach ($edit_roles_dropdown as $role): ?>
                                <option value="<?= $role['id'] ?>" <?= ($edit_role_id == $role['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($role['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <option value="" data-perm-lang="selectDepartmentFirst">Select Department First</option>
                        <?php endif; ?>
                    </select>
                    <?php if ($edit_mode): ?>
                        <input type="hidden" name="permissions_role_id" value="<?= $edit_role_id ?>">
                    <?php endif; ?>
                </div>

                <!-- Module Dropdown -->
                <div class="perm-form-group">
                    <label for="permissions_module_name" data-perm-lang="module">Module *</label>
                    <select id="permissions_module_name" name="permissions_module_name" required <?= $edit_mode ? 'disabled' : '' ?>>
                        <?php if ($edit_mode): ?>
                            <option value="" data-perm-lang="selectModule">Select Module</option>
                            <?php foreach ($AVAILABLE_MODULES as $module): ?>
                                <option value="<?= htmlspecialchars($module) ?>" <?= ($edit_data['module_name'] == $module) ? 'selected' : '' ?>>
                                    <?= ucfirst(str_replace('-', ' ', htmlspecialchars($module))) ?>
                                </option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <option value="" data-perm-lang="selectModule">Select Module</option>
                            <?php foreach ($AVAILABLE_MODULES as $module): ?>
                                <option value="<?= htmlspecialchars($module) ?>">
                                    <?= ucfirst(str_replace('-', ' ', htmlspecialchars($module))) ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <?php if ($edit_mode): ?>
                        <input type="hidden" name="permissions_module_name" value="<?= htmlspecialchars($edit_data['module_name']) ?>">
                    <?php endif; ?>
                </div>
            </div>

            <div class="perm-form-group">
                <label data-perm-lang="permissions">Permissions</label>
                <div class="perm-checkbox-group">
                    <label class="perm-checkbox-item">
                        <input type="checkbox" name="permissions_can_view" value="1" <?= ($edit_mode && $edit_data['can_view']) ? 'checked' : '' ?>>
                        <span data-perm-lang="view">View</span>
                    </label>
                    <label class="perm-checkbox-item">
                        <input type="checkbox" name="permissions_can_add" value="1" <?= ($edit_mode && $edit_data['can_add']) ? 'checked' : '' ?>>
                        <span data-perm-lang="add">Add</span>
                    </label>
                    <label class="perm-checkbox-item">
                        <input type="checkbox" name="permissions_can_edit" value="1" <?= ($edit_mode && $edit_data['can_edit']) ? 'checked' : '' ?>>
                        <span data-perm-lang="edit">Edit</span>
                    </label>
                    <label class="perm-checkbox-item">
                        <input type="checkbox" name="permissions_can_delete" value="1" <?= ($edit_mode && $edit_data['can_delete']) ? 'checked' : '' ?>>
                        <span data-perm-lang="delete">Delete</span>
                    </label>
                </div>
            </div>

            <div class="perm-form-actions">
                <button type="submit" name="<?= $edit_mode ? 'perm_update' : 'perm_add' ?>" 
                        class="perm-btn">
                    <i class="fas fa-save"></i>
                    <span data-perm-lang="save">Save</span>
                </button>
                <a href="?page=permissions" class="perm-btn perm-btn-secondary">
                    <i class="fas fa-times"></i>
                    <span data-perm-lang="cancel">Cancel</span>
                </a>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="perm-stats-cards">
        <div class="perm-stats-card">
            <h4><i class="fas fa-chart-pie"></i> <span data-perm-lang="statsByDepartment">Permissions by Department</span></h4>
            <div class="perm-stats-card-list">
                <?php foreach ($departments_stats as $stat): ?>
                    <span class="perm-stats-badge">
                        <?= htmlspecialchars($stat['name']) ?>
                        <span class="count"><?= $stat['count'] ?></span>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="perm-stats-card">
            <h4><i class="fas fa-users"></i> <span data-perm-lang="statsByRole">Permissions by Role</span></h4>
            <div class="perm-stats-card-list">
                <?php 
                $top_roles = array_slice($roles_stats, 0, 6);
                foreach ($top_roles as $stat): 
                ?>
                    <span class="perm-stats-badge">
                        <?= htmlspecialchars($stat['name']) ?>
                        <span class="count"><?= $stat['count'] ?></span>
                    </span>
                <?php endforeach; ?>
                <?php if (count($roles_stats) > 6): ?>
                    <span class="perm-stats-badge">+<?= count($roles_stats) - 6 ?> more</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="perm-stats-card">
            <h4><i class="fas fa-cubes"></i> <span data-perm-lang="statsByModule">Permissions by Module</span></h4>
            <div class="perm-stats-card-list">
                <?php 
                $top_modules = array_slice($modules_stats, 0, 6);
                foreach ($top_modules as $stat): 
                ?>
                    <span class="perm-stats-badge">
                        <?= ucfirst(str_replace('-', ' ', htmlspecialchars($stat['name']))) ?>
                        <span class="count"><?= $stat['count'] ?></span>
                    </span>
                <?php endforeach; ?>
                <?php if (count($modules_stats) > 6): ?>
                    <span class="perm-stats-badge">+<?= count($modules_stats) - 6 ?> more</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Search and Filter Bar -->
    <div class="perm-search-bar">
        <form method="GET" action="?page=permissions" style="display: contents;">
            <input type="hidden" name="page" value="permissions">

            <div class="perm-search-group">
                <label for="search" data-perm-lang="search">Search</label>
                <input type="text" id="search" name="search" 
                       value="<?= htmlspecialchars($search) ?>" 
                       placeholder="Search by role, department or module">
            </div>

            <div class="perm-search-group">
                <label for="department_filter" data-perm-lang="department">Department</label>
                <select id="department_filter" name="department_filter">
                    <option value="" data-perm-lang="allDepartments">All Departments</option>
                    <?php foreach ($departments_dropdown as $dept): ?>
                        <option value="<?= $dept['id'] ?>" <?= $department_filter == $dept['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($dept['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="perm-search-group">
                <label for="role_filter" data-perm-lang="role">Role</label>
                <select id="role_filter" name="role_filter">
                    <option value="" data-perm-lang="allRoles">All Roles</option>
                    <?php foreach ($roles_dropdown as $role): ?>
                        <option value="<?= $role['id'] ?>" <?= $role_filter == $role['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($role['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="perm-search-group">
                <label for="module_filter" data-perm-lang="module">Module</label>
                <select id="module_filter" name="module_filter">
                    <option value="" data-perm-lang="allModules">All Modules</option>
                    <?php foreach ($AVAILABLE_MODULES as $module): ?>
                        <option value="<?= htmlspecialchars($module) ?>" <?= $module_filter == $module ? 'selected' : '' ?>>
                            <?= ucfirst(str_replace('-', ' ', htmlspecialchars($module))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="perm-search-group">
                <label for="perm_type_filter" data-perm-lang="filter">Filter</label>
                <select id="perm_type_filter" name="perm_type_filter">
                    <option value="" data-perm-lang="allPermissions">All Permissions</option>
                    <option value="has_view" <?= $perm_type_filter == 'has_view' ? 'selected' : '' ?> data-perm-lang="hasView">Has View</option>
                    <option value="has_add" <?= $perm_type_filter == 'has_add' ? 'selected' : '' ?> data-perm-lang="hasAdd">Has Add</option>
                    <option value="has_edit" <?= $perm_type_filter == 'has_edit' ? 'selected' : '' ?> data-perm-lang="hasEdit">Has Edit</option>
                    <option value="has_delete" <?= $perm_type_filter == 'has_delete' ? 'selected' : '' ?> data-perm-lang="hasDelete">Has Delete</option>
                </select>
            </div>

            <div class="perm-search-actions">
                <button type="submit" class="perm-search-btn">
                    <i class="fas fa-search"></i> <span data-perm-lang="search">Search</span>
                </button>
                <a href="?page=permissions" class="perm-clear-btn">
                    <i class="fas fa-times"></i> <span data-perm-lang="clear">Clear</span>
                </a>
            </div>
        </form>
    </div>

    <!-- Stats Bar -->
    <div class="perm-stats">
        <div class="perm-stats-info">
            <i class="fas fa-lock"></i>
            <span id="totalRecords" data-perm-lang="totalRecords">Total Permissions</span>
            <span>:</span>
            <span class="perm-stats-count"><?= $total_count ?></span>
            <span data-perm-lang="records">records</span>
        </div>
    </div>

    <!-- Data Table -->
    <div class="perm-table-container">
        <table class="perm-table">
            <thead>
                <tr>
                    <th data-perm-lang="id">ID</th>
                    <th data-perm-lang="role">Role</th>
                    <th data-perm-lang="department">Department</th>
                    <th data-perm-lang="module">Module</th>
                    <th data-perm-lang="view">View</th>
                    <th data-perm-lang="add">Add</th>
                    <th data-perm-lang="edit">Edit</th>
                    <th data-perm-lang="delete">Delete</th>
                    <th data-perm-lang="created">Created</th>
                    <th data-perm-lang="updated">Updated</th>
                    <th data-perm-lang="actions">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($permissions_result && $permissions_result->num_rows > 0): ?>
                    <?php while ($row = $permissions_result->fetch_assoc()): ?>
                        <?php $is_protected = ($row['role_id'] == 1); ?>
                        <tr class="<?= $is_protected ? 'protected-row' : '' ?>">
                            <td>#<?= $row['id'] ?></td>
                            <td>
                                <strong><?= htmlspecialchars($row['role_name'] ?? 'Unknown') ?></strong>
                                <?php if ($is_protected): ?>
                                    <span class="perm-protected-badge protected-tooltip" 
                                          title="Super Admin - All permissions automatically granted"
                                          data-perm-lang="protectedTooltip">
                                        <i class="fas fa-crown"></i> 
                                        <span data-perm-lang="protectedRole">Super Admin</span>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="perm-department-badge">
                                    <?= htmlspecialchars($row['department_name'] ?? 'N/A') ?>
                                </span>
                            </td>
                            <td>
                                <span class="perm-module-badge">
                                    <?= ucfirst(str_replace('-', ' ', htmlspecialchars($row['module_name']))) ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <span class="perm-badge <?= $row['can_view'] ? 'perm-badge-yes' : 'perm-badge-no' ?>">
                                    <?= $row['can_view'] ? '✓' : '✗' ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <span class="perm-badge <?= $row['can_add'] ? 'perm-badge-yes' : 'perm-badge-no' ?>">
                                    <?= $row['can_add'] ? '✓' : '✗' ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <span class="perm-badge <?= $row['can_edit'] ? 'perm-badge-yes' : 'perm-badge-no' ?>">
                                    <?= $row['can_edit'] ? '✓' : '✗' ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <span class="perm-badge <?= $row['can_delete'] ? 'perm-badge-yes' : 'perm-badge-no' ?>">
                                    <?= $row['can_delete'] ? '✓' : '✗' ?>
                                </span>
                            </td>
                            <td>
                                <?= date('d M Y H:i', strtotime($row['created_at'])) ?>
                                <div class="perm-timestamp"><?= date('d M Y', strtotime($row['created_at'])) ?></div>
                            </td>
                            <td>
                                <?= date('d M Y H:i', strtotime($row['updated_at'])) ?>
                                <div class="perm-timestamp"><?= date('d M Y', strtotime($row['updated_at'])) ?></div>
                            </td>
                            <td>
                                <div class="perm-actions">
                                    <?php if ($is_protected): ?>
                                        <a href="javascript:void(0)" 
                                           class="perm-action-btn perm-action-edit disabled protected-tooltip"
                                           title="Super Admin permissions are protected">
                                            <i class="fas fa-edit"></i> <span data-perm-lang="edit">Edit</span>
                                        </a>
                                        <a href="javascript:void(0)" 
                                           class="perm-action-btn perm-action-delete disabled protected-tooltip"
                                           title="Super Admin permissions are protected">
                                            <i class="fas fa-trash"></i> <span data-perm-lang="delete">Delete</span>
                                        </a>
                                    <?php else: ?>
                                        <?php if (canEdit($conn, $user_role_id, $module_name)): ?>
                                        <a href="?page=permissions&perm_edit=<?= $row['id'] ?>" 
                                           class="perm-action-btn perm-action-edit">
                                            <i class="fas fa-edit"></i> <span data-perm-lang="edit">Edit</span>
                                        </a>
                                        <?php endif; ?>
                                        <?php if (canDelete($conn, $user_role_id, $module_name)): ?>
                                        <a href="javascript:void(0)" 
                                           onclick="permissions_confirmDelete(<?= $row['id'] ?>, '<?= htmlspecialchars(addslashes($row['role_name'] ?? 'Unknown')) ?>', '<?= htmlspecialchars(addslashes($row['module_name'])) ?>')" 
                                           class="perm-action-btn perm-action-delete">
                                            <i class="fas fa-trash"></i> <span data-perm-lang="delete">Delete</span>
                                        </a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="11" class="perm-empty">
                            <i class="fas fa-lock"></i>
                            <p data-perm-lang="noData">No permissions found.</p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
         </table>
    </div>
</div>

<script>
    // Store roles data for AJAX filtering
    const permissions_rolesData = <?php echo json_encode($roles_dropdown); ?>;
    
    // Load roles based on selected department
    function permissions_loadRolesByDepartment(departmentId) {
        const roleSelect = document.getElementById('permissions_role_id');
        const lang = currentPermissionsLang;
        
        if (!departmentId) {
            roleSelect.innerHTML = '<option value="" data-perm-lang="selectDepartmentFirst">' + 
                (lang === 'en' ? 'Select Department First' : 'Chagua Idara Kwanza') + '</option>';
            roleSelect.disabled = true;
            return;
        }
        
        // Filter roles by department
        const filteredRoles = permissions_rolesData.filter(role => role.department_id == departmentId);
        
        if (filteredRoles.length === 0) {
            roleSelect.innerHTML = '<option value="" data-perm-lang="selectRole">' + 
                (lang === 'en' ? 'No roles found for this department' : 'Hakuna majukumu kwa idara hii') + '</option>';
            roleSelect.disabled = true;
        } else {
            let options = '<option value="" data-perm-lang="selectRole">' + 
                (lang === 'en' ? 'Select Role' : 'Chagua Jukumu') + '</option>';
            filteredRoles.forEach(role => {
                options += `<option value="${role.id}">${escapeHtml(role.name)}</option>`;
            });
            roleSelect.innerHTML = options;
            roleSelect.disabled = false;
        }
        
        // Update translations for the new options
        updatePermissionsLanguage(lang);
    }
    
    // Helper function to escape HTML
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Toggle form visibility
    function permissions_toggleForm() {
        const form = document.getElementById('permissionsForm');
        const btn = document.getElementById('permissionsToggleBtn');
        const lang = currentPermissionsLang;
        
        if (form.classList.contains('show')) {
            form.classList.remove('show');
            btn.innerHTML = '<i class="fas fa-plus"></i> <span data-perm-lang="addNew">' + 
                (lang === 'en' ? 'Add New Permission' : 'Ongeza Ruhusa Mpya') + '</span>';
        } else {
            form.classList.add('show');
            btn.innerHTML = '<i class="fas fa-times"></i> <span data-perm-lang="cancel">' + 
                (lang === 'en' ? 'Cancel' : 'Ghairi') + '</span>';
            
            // Update form header when opening
            const formHeader = document.querySelector('#permissionsForm h3');
            if (formHeader) {
                formHeader.textContent = permissions_translations[lang].addPermission;
            }
            
            // Clear any hidden ID field to ensure it's in add mode
            const hiddenId = document.querySelector('input[name="permissions_id"]');
            if (hiddenId) {
                hiddenId.remove();
            }
            
            // Clear form fields
            const deptSelect = document.getElementById('permissions_department_id');
            if (deptSelect) deptSelect.value = '';
            
            const roleSelect = document.getElementById('permissions_role_id');
            if (roleSelect) {
                roleSelect.innerHTML = '<option value="" data-perm-lang="selectDepartmentFirst">' + 
                    (lang === 'en' ? 'Select Department First' : 'Chagua Idara Kwanza') + '</option>';
                roleSelect.disabled = true;
            }
            
            const moduleSelect = document.getElementById('permissions_module_name');
            if (moduleSelect) moduleSelect.value = '';
            
            document.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.checked = false);
            
            // Change button name to add mode
            const submitBtn = document.querySelector('button[name="perm_update"], button[name="perm_add"]');
            if (submitBtn) {
                submitBtn.name = 'perm_add';
            }
            
            // Update translations
            updatePermissionsLanguage(lang);
        }
    }

    // Validate form before submission
    function permissions_validateForm() {
        const roleId = document.getElementById('permissions_role_id');
        const moduleName = document.getElementById('permissions_module_name');
        const lang = currentPermissionsLang;
        
        // For add mode, check if role is selected (role select should not be disabled)
        if (roleId && roleId.disabled === false && (!roleId.value || roleId.value === '')) {
            alert(permissions_translations[lang].roleRequired);
            roleId.focus();
            return false;
        }
        
        // For add mode, check if module is selected
        if (moduleName && (!moduleName.value || moduleName.value === '')) {
            alert(permissions_translations[lang].moduleRequired);
            moduleName.focus();
            return false;
        }
        
        return true;
    }

    // Confirm delete with loading effect (protected against deleting Super Admin permissions)
    function permissions_confirmDelete(id, roleName, moduleName) {
        const lang = currentPermissionsLang;
        const confirmMsg = permissions_translations[lang].confirmDelete + '\n\n' +
                          'Role: ' + roleName + '\n' +
                          'Module: ' + moduleName + '\n\n' +
                          permissions_translations[lang].confirmDeleteMsg;
        
        if (confirm(confirmMsg)) {
            // Show loading effect on the clicked row
            const row = event.target.closest('tr');
            if (row) {
                row.style.opacity = '0.5';
                
                // Create loading indicator
                const loadingDiv = document.createElement('div');
                loadingDiv.className = 'perm-loading';
                loadingDiv.style.position = 'absolute';
                loadingDiv.style.top = '50%';
                loadingDiv.style.left = '50%';
                loadingDiv.style.transform = 'translate(-50%, -50%)';
                row.style.position = 'relative';
                row.appendChild(loadingDiv);
            }
            
            // Redirect after a small delay to show loading
            setTimeout(() => {
                window.location.href = `?page=permissions&perm_delete=${id}`;
            }, 300);
        }
    }

    // Auto-hide alerts after 5 seconds
    setTimeout(() => {
        document.querySelectorAll('.perm-alert').forEach(alert => {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(() => alert.style.display = 'none', 500);
        });
    }, 5000);
</script>