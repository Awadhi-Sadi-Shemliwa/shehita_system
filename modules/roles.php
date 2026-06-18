<?php
/**
 * SHEHITA Enterprise Management System
 * Roles Module - Full CRUD Operations with Department Association
 * 
 * REFINED: Removed all sidebar-related code (already clean - Issue #1)
 * REFINED: Added foreign key table validation with user-friendly error messages (Issue #2)
 * REFINED: Ensured no conflict with homepage.php (top navbar layout) (Issue #3)
 * 
 * This module handles:
 * - Automatic table creation if not exists
 * - Foreign key table validation with graceful error handling
 * - Automatic creation of default "Super Admin" department and role
 * - Display all roles with department names
 * - Add new role (with department selection)
 * - Edit existing role (except protected default role)
 * - Delete role with confirmation (except protected default role)
 * - Protected default role (ID=1) cannot be edited or deleted
 * - Auto-reset ID when table becomes empty
 * - Search/filter functionality
 * - Full English/Swahili translation support
 * 
 * PERMISSION ENHANCED: Buttons now respect user permissions (can_view, can_add, can_edit, can_delete)
 */

// Prevent direct access
if (!defined('ACCESS_ALLOWED') && basename($_SERVER['PHP_SELF']) == basename(__FILE__)) {
    // This file is included, so we're safe
}

// Get database connection from parent
global $conn;

// PERMISSION: Get user role and check module permissions
$user_role_id = $_SESSION['role_id'] ?? 0;
$module_name = 'roles';

// PERMISSION: Check if user has view permission
if (!canView($conn, $user_role_id, $module_name)) {
    echo '<div class="roles-alert roles-alert-danger" style="text-align: center; padding: 40px;">
            <i class="fas fa-lock" style="font-size: 48px; margin-bottom: 16px; display: block;"></i>
            <h3>Access Denied</h3>
            <p>You do not have permission to view this module.</p>
          </div>';
    return; // Stop rendering the module
}

/**
 * ============================================================================
 * FOREIGN KEY TABLE VALIDATION (ISSUE #2)
 * Check if required dependent tables exist before proceeding
 * ============================================================================
 */

$missing_tables = [];

// Check for departments table (required for role department association)
$check_depts = $conn->query("SHOW TABLES LIKE 'departments'");
if ($check_depts->num_rows == 0) {
    $missing_tables[] = ['table' => 'departments', 'module' => 'departments', 'display' => 'Departments'];
}

// Display error message if any required tables are missing
if (!empty($missing_tables)) {
    echo '<div class="roles-container" style="max-width: 800px; margin: 40px auto;">';
    echo '<div class="roles-alert roles-alert-danger" style="background: #fff3cd; color: #856404; border-color: #ffeeba; text-align: left;">';
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

/**
 * ============================================================================
 * DATABASE SCHEMA CREATION
 * ============================================================================
 */

// First, ensure Administrator department exists (ID=1 is protected)
$check_admin_dept = $conn->query("SELECT id FROM departments WHERE name = 'Administrator'");
$admin_department_id = null;

if ($check_admin_dept->num_rows == 0) {
    // Create Administrator department
    $admin_dept_name = 'Administrator';
    $admin_dept_desc = 'Default department for administrator roles';
    $admin_dept_status = 'Active';
    
    $insert_dept = $conn->prepare("INSERT INTO departments (name, description, status) VALUES (?, ?, ?)");
    $insert_dept->bind_param("sss", $admin_dept_name, $admin_dept_desc, $admin_dept_status);
    
    if ($insert_dept->execute()) {
        $admin_department_id = $conn->insert_id;
    }
    $insert_dept->close();
} else {
    $admin_dept = $check_admin_dept->fetch_assoc();
    $admin_department_id = $admin_dept['id'];
}

// Schema note: the `roles` table is created centrally in config.php.
// This module assumes it already exists and only manages its data below.

/**
 * CREATE DEFAULT SUPER ADMIN ROLE IF NOT EXISTS
 * This role is protected and cannot be edited or deleted
 * CHANGED: Role name from "User" to "Super Admin"
 */
if ($admin_department_id) {
    $check_default_role = $conn->query("SELECT id FROM roles WHERE name = 'Super Admin' AND department_id = $admin_department_id");
    
    if ($check_default_role->num_rows == 0) {
        $default_role_name = 'Super Admin';
        $default_role_desc = 'Default role with full administrative privileges - System protected';
        $default_role_status = 'Active';
        
        $insert_default_role = $conn->prepare("INSERT INTO roles (name, department_id, status, description) VALUES (?, ?, ?, ?)");
        $insert_default_role->bind_param("siss", $default_role_name, $admin_department_id, $default_role_status, $default_role_desc);
        
        if ($insert_default_role->execute()) {
            // Ensure this role gets ID = 1 by resetting auto increment if it's the first record
            $check_count = $conn->query("SELECT COUNT(*) as count FROM roles");
            $count = $check_count->fetch_assoc()['count'];
            if ($count == 1) {
                $conn->query("ALTER TABLE roles AUTO_INCREMENT = 2");
            }
        }
        $insert_default_role->close();
    } else {
        // Ensure the default role has ID = 1
        $default_role = $check_default_role->fetch_assoc();
        if ($default_role['id'] != 1) {
            // Update the role to have ID = 1
            $conn->query("UPDATE roles SET id = 1 WHERE name = 'Super Admin' AND department_id = $admin_department_id");
            $conn->query("ALTER TABLE roles AUTO_INCREMENT = " . ($conn->insert_id + 1));
        }
    }
}

/**
 * CHECK IF TABLE IS EMPTY AND RESET AUTO_INCREMENT IF NEEDED
 */
$check_empty = $conn->query("SELECT COUNT(*) as count FROM roles");
$row_count = $check_empty->fetch_assoc()['count'];

if ($row_count == 0) {
    $conn->query("ALTER TABLE roles AUTO_INCREMENT = 1");
} else {
    // Ensure auto_increment is set correctly
    $max_id = $conn->query("SELECT MAX(id) as max_id FROM roles")->fetch_assoc()['max_id'];
    if ($max_id) {
        $conn->query("ALTER TABLE roles AUTO_INCREMENT = " . ($max_id + 1));
    }
}

// Initialize variables for messages
$roles_message = '';
$roles_message_type = '';

// Initialize search/filter variables
$search = isset($_GET['search']) ? sanitize($conn, $_GET['search']) : '';
$status_filter = isset($_GET['status_filter']) ? sanitize($conn, $_GET['status_filter']) : '';
$department_filter = isset($_GET['department_filter']) ? (int)$_GET['department_filter'] : '';

/**
 * ============================================================================
 * PROTECTION CHECKS FOR DEFAULT ROLE (ID = 1)
 * Redirect with error message if trying to edit or delete the protected role
 * ============================================================================
 */
if (isset($_GET['roles_edit']) && (int)$_GET['roles_edit'] == 1) {
    $roles_message = "The default Super Admin role cannot be edited";
    $roles_message_type = "danger";
    header("Location: ?page=roles");
    exit();
}

if (isset($_GET['roles_delete']) && (int)$_GET['roles_delete'] == 1) {
    $roles_message = "The default Super Admin role cannot be deleted";
    $roles_message_type = "danger";
    header("Location: ?page=roles");
    exit();
}

/**
 * ============================================================================
 * HANDLE FORM SUBMISSIONS WITH PERMISSION CHECKS
 * ============================================================================
 */

// PERMISSION: Check add permission before processing add operation
if (isset($_POST['roles_add']) && !canAdd($conn, $user_role_id, $module_name)) {
    $roles_message = "You do not have permission to add roles.";
    $roles_message_type = "danger";
} elseif (isset($_POST['roles_add'])) {
    $name = sanitize($conn, $_POST['name']);
    $department_id = (int)$_POST['department_id'];
    $status = sanitize($conn, $_POST['status']);
    $description = sanitize($conn, $_POST['description']);
    
    // Validate inputs
    $errors = [];
    
    if (empty($name)) {
        $errors[] = "Role name is required";
    } elseif (strlen($name) > 100) {
        $errors[] = "Role name must not exceed 100 characters";
    }
    
    if ($department_id <= 0) {
        $errors[] = "Please select a department";
    } else {
        // Verify department exists
        $dept_check = $conn->query("SELECT id FROM departments WHERE id = $department_id AND status = 'Active'");
        if ($dept_check->num_rows == 0) {
            $errors[] = "Selected department not found or inactive";
        }
    }
    
    if (!in_array($status, ['Active', 'Inactive'])) {
        $errors[] = "Invalid status";
    }
    
    if (strlen($description) > 500) {
        $errors[] = "Description must not exceed 500 characters";
    }
    
    // Check for duplicate role name within same department
    if (empty($errors)) {
        $check_duplicate = $conn->prepare("SELECT id FROM roles WHERE name = ? AND department_id = ?");
        $check_duplicate->bind_param("si", $name, $department_id);
        $check_duplicate->execute();
        $dup_result = $check_duplicate->get_result();
        
        if ($dup_result->num_rows > 0) {
            $errors[] = "A role with this name already exists in the selected department";
        }
        $check_duplicate->close();
    }
    
    if (empty($errors)) {
        // Insert new role
        $insert_stmt = $conn->prepare("INSERT INTO roles (name, department_id, status, description) VALUES (?, ?, ?, ?)");
        $insert_stmt->bind_param("siss", $name, $department_id, $status, $description);
        
        if ($insert_stmt->execute()) {
            $roles_message = "Role added successfully!";
            $roles_message_type = "success";
        } else {
            $roles_message = "Error adding role: " . $conn->error;
            $roles_message_type = "danger";
        }
        $insert_stmt->close();
    } else {
        $roles_message = implode("<br>", $errors);
        $roles_message_type = "danger";
    }
}

// PERMISSION: Check edit permission before processing update operation
if (isset($_POST['roles_update']) && !canEdit($conn, $user_role_id, $module_name)) {
    $roles_message = "You do not have permission to edit roles.";
    $roles_message_type = "danger";
} elseif (isset($_POST['roles_update'])) {
    $id = (int)$_POST['id'];
    $name = sanitize($conn, $_POST['name']);
    $department_id = (int)$_POST['department_id'];
    $status = sanitize($conn, $_POST['status']);
    $description = sanitize($conn, $_POST['description']);
    
    // PROTECTION CHECK: Cannot update default role (ID = 1)
    if ($id == 1) {
        $roles_message = "The default Super Admin role cannot be edited";
        $roles_message_type = "danger";
    } else {
        // Validate inputs
        $errors = [];
        
        if ($id <= 0) {
            $errors[] = "Invalid ID";
        }
        
        if (empty($name)) {
            $errors[] = "Role name is required";
        } elseif (strlen($name) > 100) {
            $errors[] = "Role name must not exceed 100 characters";
        }
        
        if ($department_id <= 0) {
            $errors[] = "Please select a department";
        } else {
            // Verify department exists
            $dept_check = $conn->query("SELECT id FROM departments WHERE id = $department_id AND status = 'Active'");
            if ($dept_check->num_rows == 0) {
                $errors[] = "Selected department not found or inactive";
            }
        }
        
        if (!in_array($status, ['Active', 'Inactive'])) {
            $errors[] = "Invalid status";
        }
        
        if (strlen($description) > 500) {
            $errors[] = "Description must not exceed 500 characters";
        }
        
        // Check for duplicate role name within same department (excluding current role)
        if (empty($errors)) {
            $check_duplicate = $conn->prepare("SELECT id FROM roles WHERE name = ? AND department_id = ? AND id != ?");
            $check_duplicate->bind_param("sii", $name, $department_id, $id);
            $check_duplicate->execute();
            $dup_result = $check_duplicate->get_result();
            
            if ($dup_result->num_rows > 0) {
                $errors[] = "A role with this name already exists in the selected department";
            }
            $check_duplicate->close();
        }
        
        if (empty($errors)) {
            // Update role
            $update_stmt = $conn->prepare("UPDATE roles SET name = ?, department_id = ?, status = ?, description = ? WHERE id = ?");
            $update_stmt->bind_param("sissi", $name, $department_id, $status, $description, $id);
            
            if ($update_stmt->execute()) {
                $roles_message = "Role updated successfully!";
                $roles_message_type = "success";
            } else {
                $roles_message = "Error updating role: " . $conn->error;
                $roles_message_type = "danger";
            }
            $update_stmt->close();
        } else {
            $roles_message = implode("<br>", $errors);
            $roles_message_type = "danger";
        }
    }
}

// PERMISSION: Check delete permission before processing delete operation
if (isset($_GET['roles_delete']) && !canDelete($conn, $user_role_id, $module_name)) {
    $roles_message = "You do not have permission to delete roles.";
    $roles_message_type = "danger";
} elseif (isset($_GET['roles_delete'])) {
    $id = (int)$_GET['roles_delete'];
    
    // PROTECTION CHECK: Cannot delete default role (ID = 1)
    if ($id == 1) {
        $roles_message = "The default Super Admin role cannot be deleted";
        $roles_message_type = "danger";
    } elseif ($id > 0) {
        $delete_stmt = $conn->prepare("DELETE FROM roles WHERE id = ?");
        $delete_stmt->bind_param("i", $id);
        
        if ($delete_stmt->execute()) {
            $roles_message = "Role deleted successfully!";
            $roles_message_type = "success";
            
            // Check if table is empty and reset auto-increment
            $check_empty = $conn->query("SELECT COUNT(*) as count FROM roles");
            $row_count = $check_empty->fetch_assoc()['count'];
            
            if ($row_count == 0) {
                $conn->query("ALTER TABLE roles AUTO_INCREMENT = 1");
            }
        } else {
            $roles_message = "Error deleting role: " . $conn->error;
            $roles_message_type = "danger";
        }
        $delete_stmt->close();
    }
}

// Get edit data if in edit mode (protected role already handled above)
// PERMISSION: Only allow edit if user has edit permission
$edit_mode = false;
$edit_data = null;

if (isset($_GET['roles_edit'])) {
    $edit_id = (int)$_GET['roles_edit'];
    // PERMISSION: Only allow edit if user has edit permission and it's not the protected role
    if ($edit_id > 0 && $edit_id != 1 && canEdit($conn, $user_role_id, $module_name)) {
        $edit_stmt = $conn->prepare("SELECT * FROM roles WHERE id = ?");
        $edit_stmt->bind_param("i", $edit_id);
        $edit_stmt->execute();
        $edit_result = $edit_stmt->get_result();
        
        if ($edit_result->num_rows > 0) {
            $edit_data = $edit_result->fetch_assoc();
            $edit_mode = true;
        }
        $edit_stmt->close();
    }
}

/**
 * ============================================================================
 * FETCH DEPARTMENTS FOR DROPDOWN
 * Only get active departments for the dropdown
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
 * FETCH ALL ROLES WITH SEARCH AND FILTER
 * Join with departments table to get department names
 * ============================================================================
 */
$where_clause = "";
$params = [];
$types = "";

if (!empty($search)) {
    $where_clause .= " (r.name LIKE ? OR r.description LIKE ?) ";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ss";
}

if (!empty($status_filter)) {
    if (!empty($where_clause)) {
        $where_clause .= " AND ";
    }
    $where_clause .= " r.status = ? ";
    $params[] = $status_filter;
    $types .= "s";
}

if (!empty($department_filter)) {
    if (!empty($where_clause)) {
        $where_clause .= " AND ";
    }
    $where_clause .= " r.department_id = ? ";
    $params[] = $department_filter;
    $types .= "i";
}

if (!empty($where_clause)) {
    $where_clause = "WHERE " . $where_clause;
}

$roles_query = "SELECT r.*, d.name as department_name 
                FROM roles r 
                LEFT JOIN departments d ON r.department_id = d.id 
                $where_clause 
                ORDER BY r.id DESC";

// Prepare and execute the query with parameters if needed
if (!empty($params)) {
    $stmt = $conn->prepare($roles_query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $roles_result = $stmt->get_result();
} else {
    $roles_result = $conn->query($roles_query);
}

// Get total count for display
$total_count = $roles_result->num_rows;

// Get department stats for filter dropdown
$departments_stats = [];
$dept_stats_query = "SELECT d.id, d.name, COUNT(r.id) as role_count 
                     FROM departments d 
                     LEFT JOIN roles r ON d.id = r.department_id 
                     WHERE d.status = 'Active'
                     GROUP BY d.id 
                     ORDER BY d.name ASC";
$dept_stats_result = $conn->query($dept_stats_query);
if ($dept_stats_result && $dept_stats_result->num_rows > 0) {
    while ($row = $dept_stats_result->fetch_assoc()) {
        $departments_stats[] = $row;
    }
}
?>

<!-- ROLES TRANSLATIONS -->
<script>
// Roles translations for English and Swahili
// CHANGED: Updated default role references from "User" to "Super Admin"
const roles_translations = {
    en: {
        pageTitle: 'Role Management',
        addNew: 'Add New Role',
        editRole: 'Edit Role',
        addRole: 'Add New Role',
        id: 'ID',
        name: 'Role Name',
        department: 'Department',
        description: 'Description',
        status: 'Status',
        active: 'Active',
        inactive: 'Inactive',
        actions: 'Actions',
        save: 'Save',
        cancel: 'Cancel',
        edit: 'Edit',
        delete: 'Delete',
        created: 'Created',
        updated: 'Updated',
        confirmDelete: 'Are you sure you want to delete role',
        confirmDeleteMsg: 'This action cannot be undone.',
        noData: 'No roles found. Click "Add New Role" to create one.',
        nameRequired: 'Role name is required!',
        departmentRequired: 'Please select a department!',
        loading: 'Loading...',
        search: 'Search',
        filter: 'Filter',
        allStatus: 'All Status',
        allDepartments: 'All Departments',
        clear: 'Clear',
        totalRecords: 'Total Roles',
        records: 'records',
        descriptionPlaceholder: 'Enter role description (optional)',
        namePlaceholder: 'Enter role name',
        protectedRole: 'System Default',
        protectedTooltip: 'System default role - Cannot be modified or deleted',
        cannotEditDefault: 'The default Super Admin role cannot be edited',
        cannotDeleteDefault: 'The default Super Admin role cannot be deleted',
        rolesByDepartment: 'Roles by Department',
        departmentStats: 'Department Stats',
        selectDepartment: 'Select Department'
    },
    sw: {
        pageTitle: 'Usimamizi wa Majukumu',
        addNew: 'Ongeza Jukumu Jipya',
        editRole: 'Hariri Jukumu',
        addRole: 'Ongeza Jukumu Jipya',
        id: 'Kitambulisho',
        name: 'Jina la Jukumu',
        department: 'Idara',
        description: 'Maelezo',
        status: 'Hali',
        active: 'Inatumika',
        inactive: 'Haifanyi Kazi',
        actions: 'Vitendo',
        save: 'Hifadhi',
        cancel: 'Ghairi',
        edit: 'Hariri',
        delete: 'Futa',
        created: 'Imeundwa',
        updated: 'Imesasishwa',
        confirmDelete: 'Una uhakika unataka kufuta jukumu',
        confirmDeleteMsg: 'Kitendo hiki hakiwezi kutenguliwa.',
        noData: 'Hakuna majukumu yaliyopatikana. Bofya "Ongeza Jukumu Jipya" kuunda.',
        nameRequired: 'Jina la jukumu linahitajika!',
        departmentRequired: 'Tafadhali chagua idara!',
        loading: 'Inapakia...',
        search: 'Tafuta',
        filter: 'Chuja',
        allStatus: 'Hali Zote',
        allDepartments: 'Idara Zote',
        clear: 'Futa',
        totalRecords: 'Jumla ya Majukumu',
        records: 'rekodi',
        descriptionPlaceholder: 'Weka maelezo ya jukumu (si lazima)',
        namePlaceholder: 'Weka jina la jukumu',
        protectedRole: 'Mfumo Msingi',
        protectedTooltip: 'Jukumu la msingi la mfumo - Haliwezi kubadilishwa au kufutwa',
        cannotEditDefault: 'Jukumu la msingi la Super Admin haliwezi kubadilishwa',
        cannotDeleteDefault: 'Jukumu la msingi la Super Admin haliwezi kufutwa',
        rolesByDepartment: 'Majukumu kwa Idara',
        departmentStats: 'Takwimu za Idara',
        selectDepartment: 'Chagua Idara'
    }
};

// Current language (will be updated by homepage.js)
let currentRolesLang = localStorage.getItem('preferredLanguage') || 'en';

// Function to update all translatable elements in roles module
function updateRolesLanguage(lang) {
    currentRolesLang = lang;
    const elements = document.querySelectorAll('[data-role-lang]');
    
    elements.forEach(element => {
        const key = element.getAttribute('data-role-lang');
        if (roles_translations[lang] && roles_translations[lang][key]) {
            if (element.tagName === 'INPUT' || element.tagName === 'TEXTAREA') {
                element.placeholder = roles_translations[lang][key];
            } else if (element.tagName === 'OPTION') {
                element.textContent = roles_translations[lang][key];
            } else {
                element.textContent = roles_translations[lang][key];
            }
        }
    });
    
    // Update table header specifically if they have data-role-lang attributes
    const thElements = document.querySelectorAll('th[data-role-lang]');
    thElements.forEach(th => {
        const key = th.getAttribute('data-role-lang');
        if (roles_translations[lang] && roles_translations[lang][key]) {
            th.textContent = roles_translations[lang][key];
        }
    });
    
    // Update empty state message
    const emptyState = document.querySelector('.roles-empty p');
    if (emptyState) {
        emptyState.textContent = roles_translations[lang].noData;
    }
    
    // Update form header based on edit mode
    const formHeader = document.querySelector('#rolesForm h3');
    if (formHeader) {
        const isEditMode = document.querySelector('input[name="id"]') !== null;
        formHeader.textContent = roles_translations[lang][isEditMode ? 'editRole' : 'addRole'];
    }
    
    // Update search input placeholder
    const searchInput = document.querySelector('input[name="search"]');
    if (searchInput) {
        searchInput.placeholder = roles_translations[lang].search;
    }
    
    // Update total records text
    const totalRecordsSpan = document.querySelector('#totalRecords');
    if (totalRecordsSpan) {
        totalRecordsSpan.textContent = roles_translations[lang].totalRecords;
    }
    
    // Update protected row tooltips
    document.querySelectorAll('.protected-row .protected-tooltip').forEach(el => {
        el.setAttribute('title', roles_translations[lang].protectedTooltip);
    });
}

// Listen for language change events from homepage
document.addEventListener('DOMContentLoaded', function() {
    // Initial update
    updateRolesLanguage(currentRolesLang);
});

// This function will be called from homepage.js when language changes
window.updateRolesLanguage = updateRolesLanguage;
</script>

<style>
    /* Roles Module Styles - Using roles_ prefix (ISSUE #3: No sidebar conflicts) */
    .roles-container {
        width: 100%;
    }

    .roles-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
        flex-wrap: wrap;
        gap: 16px;
    }

    .roles-header h2 {
        color: var(--gray-800);
        font-size: 24px;
        font-weight: 600;
        margin: 0;
    }

    .roles-btn {
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

    .roles-btn:hover {
        background: var(--brown-800);
        transform: translateY(-2px);
        box-shadow: var(--shadow-md);
    }

    .roles-btn-secondary {
        background: var(--gray-200);
        color: var(--gray-700);
    }

    .roles-btn-secondary:hover {
        background: var(--gray-300);
    }

    .roles-btn-danger {
        background: #dc3545;
    }

    .roles-btn-danger:hover {
        background: #c82333;
    }

    .roles-form {
        background: var(--gray-50);
        padding: 24px;
        border-radius: 12px;
        margin-bottom: 32px;
        border: 1px solid var(--gray-200);
        display: <?= $edit_mode ? 'block' : 'none' ?>;
    }

    .roles-form.show {
        display: block;
    }

    .roles-form h3 {
        color: var(--gray-800);
        font-size: 18px;
        font-weight: 600;
        margin-bottom: 20px;
    }

    .roles-form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 16px;
        margin-bottom: 20px;
    }

    .roles-form-group {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .roles-form-group-full {
        grid-column: 1 / -1;
    }

    .roles-form-group label {
        color: var(--gray-700);
        font-size: 14px;
        font-weight: 500;
    }

    .roles-form-group input,
    .roles-form-group select,
    .roles-form-group textarea {
        padding: 10px 12px;
        border: 1px solid var(--gray-300);
        border-radius: 6px;
        font-size: 14px;
        transition: all 0.2s;
        font-family: inherit;
    }

    .roles-form-group textarea {
        resize: vertical;
        min-height: 80px;
    }

    .roles-form-group input:focus,
    .roles-form-group select:focus,
    .roles-form-group textarea:focus {
        outline: none;
        border-color: var(--brown-600);
        box-shadow: 0 0 0 3px rgba(123, 88, 63, 0.1);
    }

    .roles-form-actions {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
    }

    .roles-alert {
        padding: 16px;
        border-radius: 8px;
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .roles-alert-success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }

    .roles-alert-danger {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }

    .roles-alert-close {
        background: none;
        border: none;
        font-size: 20px;
        cursor: pointer;
        color: inherit;
        opacity: 0.5;
    }

    .roles-alert-close:hover {
        opacity: 1;
    }

    /* Search and Filter Bar */
    .roles-search-bar {
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

    .roles-search-group {
        flex: 1;
        min-width: 180px;
    }

    .roles-search-group label {
        display: block;
        color: var(--gray-700);
        font-size: 13px;
        font-weight: 500;
        margin-bottom: 6px;
    }

    .roles-search-group input,
    .roles-search-group select {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid var(--gray-300);
        border-radius: 6px;
        font-size: 14px;
    }

    .roles-search-group input:focus,
    .roles-search-group select:focus {
        outline: none;
        border-color: var(--brown-600);
        box-shadow: 0 0 0 3px rgba(123, 88, 63, 0.1);
    }

    .roles-search-actions {
        display: flex;
        gap: 8px;
    }

    .roles-search-btn {
        padding: 10px 20px;
        background: var(--brown-700);
        color: white;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-weight: 500;
        transition: all 0.2s;
    }

    .roles-search-btn:hover {
        background: var(--brown-800);
    }

    .roles-clear-btn {
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

    .roles-clear-btn:hover {
        background: var(--gray-300);
    }

    /* Stats Bar */
    .roles-stats {
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

    .roles-stats-info {
        display: flex;
        align-items: center;
        gap: 8px;
        color: var(--gray-700);
        font-size: 14px;
    }

    .roles-stats-info i {
        color: var(--brown-600);
    }

    .roles-stats-count {
        font-weight: 700;
        color: var(--brown-700);
        font-size: 18px;
    }

    /* Department Stats Cards */
    .roles-dept-stats {
        background: white;
        padding: 20px;
        border-radius: 12px;
        margin-bottom: 24px;
        border: 1px solid var(--gray-200);
    }

    .roles-dept-stats h4 {
        color: var(--gray-800);
        font-size: 16px;
        font-weight: 600;
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .roles-dept-stats-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
    }

    .roles-dept-card {
        background: var(--gray-50);
        padding: 10px 16px;
        border-radius: 8px;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        border: 1px solid var(--gray-200);
    }

    .roles-dept-card .dept-name {
        font-weight: 500;
        color: var(--gray-700);
    }

    .roles-dept-card .dept-count {
        background: var(--brown-100);
        color: var(--brown-800);
        padding: 2px 8px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
    }

    .roles-table-container {
        overflow-x: auto;
        background: white;
        border-radius: 12px;
        border: 1px solid var(--gray-200);
    }

    .roles-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 800px;
    }

    .roles-table th {
        background: var(--gray-50);
        color: var(--gray-700);
        font-weight: 600;
        font-size: 14px;
        padding: 16px;
        text-align: left;
        border-bottom: 2px solid var(--gray-200);
    }

    .roles-table td {
        padding: 16px;
        border-bottom: 1px solid var(--gray-200);
        color: var(--gray-700);
        font-size: 14px;
    }

    .roles-table tr:hover {
        background: var(--gray-50);
    }
    
    /* Protected row styling */
    .roles-table tr.protected-row {
        background-color: #fef9e6;
        border-left: 3px solid var(--brown-600);
    }
    
    .roles-table tr.protected-row:hover {
        background-color: #fef5e0;
    }
    
    .roles-protected-badge {
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
    
    .roles-protected-badge i {
        font-size: 10px;
    }

    .roles-status {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 500;
    }

    .roles-status-active {
        background: #d4edda;
        color: #155724;
    }

    .roles-status-inactive {
        background: #f8d7da;
        color: #721c24;
    }

    .roles-actions {
        display: flex;
        gap: 8px;
    }

    .roles-action-btn {
        padding: 6px 12px;
        border-radius: 4px;
        font-size: 13px;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        transition: all 0.2s;
    }
    
    .roles-action-btn.disabled,
    .roles-action-btn.disabled:hover {
        opacity: 0.5;
        cursor: not-allowed;
        pointer-events: none;
        background: var(--gray-200);
        color: var(--gray-500);
    }

    .roles-action-edit {
        background: var(--brown-100);
        color: var(--brown-800);
    }

    .roles-action-edit:hover {
        background: var(--brown-200);
    }

    .roles-action-delete {
        background: #f8d7da;
        color: #721c24;
    }

    .roles-action-delete:hover {
        background: #f5c6cb;
    }

    .roles-loading {
        display: inline-block;
        width: 20px;
        height: 20px;
        border: 3px solid var(--gray-200);
        border-top-color: var(--brown-700);
        border-radius: 50%;
        animation: roles-spin 1s linear infinite;
    }

    @keyframes roles-spin {
        to { transform: rotate(360deg); }
    }

    .roles-empty {
        text-align: center;
        padding: 48px !important;
        color: var(--gray-500);
    }

    .roles-empty i {
        font-size: 48px;
        margin-bottom: 16px;
        color: var(--gray-400);
    }

    .roles-timestamp {
        font-size: 12px;
        color: var(--gray-500);
        margin-top: 4px;
    }

    .roles-description-preview {
        max-width: 250px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        color: var(--gray-600);
    }

    .roles-department-badge {
        background: var(--brown-100);
        color: var(--brown-800);
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 500;
        display: inline-block;
    }

    @media (max-width: 768px) {
        .roles-search-bar {
            flex-direction: column;
        }
        
        .roles-search-actions {
            width: 100%;
        }
        
        .roles-search-btn,
        .roles-clear-btn {
            flex: 1;
            text-align: center;
            justify-content: center;
        }
        
        .roles-dept-stats-grid {
            justify-content: center;
        }
    }
</style>

<div class="roles-container">
    <!-- Header -->
    <div class="roles-header">
        <h2 data-role-lang="pageTitle">Role Management</h2>
        <?php if (canAdd($conn, $user_role_id, $module_name)): ?>
        <button class="roles-btn" onclick="roles_toggleForm()" id="rolesToggleBtn">
            <i class="fas fa-plus"></i>
            <span data-role-lang="addNew">Add New Role</span>
        </button>
        <?php endif; ?>
    </div>

    <!-- Alert Messages -->
    <?php if (!empty($roles_message)): ?>
        <div class="roles-alert roles-alert-<?= $roles_message_type ?>">
            <?= $roles_message ?>
            <button class="roles-alert-close" onclick="this.parentElement.style.display='none'">&times;</button>
        </div>
    <?php endif; ?>

    <!-- Add/Edit Form - Only show if user has add or edit permission -->
    <?php if (canAdd($conn, $user_role_id, $module_name) || ($edit_mode && canEdit($conn, $user_role_id, $module_name))): ?>
    <div class="roles-form <?= $edit_mode ? 'show' : '' ?>" id="rolesForm">
        <h3 data-role-lang="<?= $edit_mode ? 'editRole' : 'addRole' ?>">
            <?= $edit_mode ? 'Edit Role' : 'Add New Role' ?>
        </h3>
        
        <form method="POST" action="?page=roles" onsubmit="return roles_validateForm()">
            <?php if ($edit_mode): ?>
                <input type="hidden" name="id" value="<?= $edit_data['id'] ?>">
            <?php endif; ?>
            
            <div class="roles-form-grid">
                <div class="roles-form-group">
                    <label for="name" data-role-lang="name">Role Name *</label>
                    <input type="text" id="name" name="name" 
                           value="<?= $edit_mode ? htmlspecialchars($edit_data['name']) : '' ?>" 
                           required maxlength="100" data-role-lang="namePlaceholder" 
                           placeholder="Enter role name">
                </div>
                
                <div class="roles-form-group">
                    <label for="department_id" data-role-lang="department">Department *</label>
                    <select id="department_id" name="department_id" required>
                        <option value="" data-role-lang="selectDepartment">Select Department</option>
                        <?php if (!empty($departments_dropdown)): ?>
                            <?php foreach ($departments_dropdown as $dept): ?>
                                <option value="<?= $dept['id'] ?>" 
                                    <?= ($edit_mode && $edit_data['department_id'] == $dept['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($dept['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                
                <div class="roles-form-group">
                    <label for="status" data-role-lang="status">Status *</label>
                    <select id="status" name="status" required>
                        <option value="Active" data-role-lang="active" <?= ($edit_mode && $edit_data['status'] == 'Active') ? 'selected' : '' ?>>Active</option>
                        <option value="Inactive" data-role-lang="inactive" <?= ($edit_mode && $edit_data['status'] == 'Inactive') ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                
                <div class="roles-form-group roles-form-group-full">
                    <label for="description" data-role-lang="description">Description</label>
                    <textarea id="description" name="description" 
                              data-role-lang="descriptionPlaceholder" 
                              placeholder="Enter role description (optional)"><?= $edit_mode ? htmlspecialchars($edit_data['description']) : '' ?></textarea>
                </div>
            </div>
            
            <div class="roles-form-actions">
                <button type="submit" name="<?= $edit_mode ? 'roles_update' : 'roles_add' ?>" 
                        class="roles-btn">
                    <i class="fas fa-save"></i>
                    <span data-role-lang="save">Save</span>
                </button>
                <a href="?page=roles" class="roles-btn roles-btn-secondary">
                    <i class="fas fa-times"></i>
                    <span data-role-lang="cancel">Cancel</span>
                </a>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- Department Stats (Optional) -->
    <?php if (!empty($departments_stats)): ?>
    <div class="roles-dept-stats">
        <h4>
            <i class="fas fa-chart-pie"></i>
            <span data-role-lang="rolesByDepartment">Roles by Department</span>
        </h4>
        <div class="roles-dept-stats-grid">
            <?php foreach ($departments_stats as $dept): ?>
                <div class="roles-dept-card">
                    <span class="dept-name"><?= htmlspecialchars($dept['name']) ?></span>
                    <span class="dept-count"><?= $dept['role_count'] ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Search and Filter Bar -->
    <div class="roles-search-bar">
        <form method="GET" action="?page=roles" style="display: contents;">
            <input type="hidden" name="page" value="roles">
            
            <div class="roles-search-group">
                <label for="search" data-role-lang="search">Search</label>
                <input type="text" id="search" name="search" 
                       value="<?= htmlspecialchars($search) ?>" 
                       placeholder="Search by name or description">
            </div>
            
            <div class="roles-search-group">
                <label for="department_filter" data-role-lang="department">Department</label>
                <select id="department_filter" name="department_filter">
                    <option value="" data-role-lang="allDepartments">All Departments</option>
                    <?php foreach ($departments_dropdown as $dept): ?>
                        <option value="<?= $dept['id'] ?>" <?= $department_filter == $dept['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($dept['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="roles-search-group">
                <label for="status_filter" data-role-lang="filter">Filter</label>
                <select id="status_filter" name="status_filter">
                    <option value="" data-role-lang="allStatus">All Status</option>
                    <option value="Active" <?= $status_filter == 'Active' ? 'selected' : '' ?> data-role-lang="active">Active</option>
                    <option value="Inactive" <?= $status_filter == 'Inactive' ? 'selected' : '' ?> data-role-lang="inactive">Inactive</option>
                </select>
            </div>
            
            <div class="roles-search-actions">
                <button type="submit" class="roles-search-btn">
                    <i class="fas fa-search"></i> <span data-role-lang="search">Search</span>
                </button>
                <a href="?page=roles" class="roles-clear-btn">
                    <i class="fas fa-times"></i> <span data-role-lang="clear">Clear</span>
                </a>
            </div>
        </form>
    </div>

    <!-- Stats Bar -->
    <div class="roles-stats">
        <div class="roles-stats-info">
            <i class="fas fa-users"></i>
            <span id="totalRecords" data-role-lang="totalRecords">Total Roles</span>
            <span>:</span>
            <span class="roles-stats-count"><?= $total_count ?></span>
            <span data-role-lang="records">records</span>
        </div>
    </div>

    <!-- Data Table -->
    <div class="roles-table-container">
        <table class="roles-table">
            <thead>
                <tr>
                    <th data-role-lang="id">ID</th>
                    <th data-role-lang="name">Role Name</th>
                    <th data-role-lang="department">Department</th>
                    <th data-role-lang="description">Description</th>
                    <th data-role-lang="status">Status</th>
                    <th data-role-lang="created">Created</th>
                    <th data-role-lang="updated">Updated</th>
                    <th data-role-lang="actions">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($roles_result && $roles_result->num_rows > 0): ?>
                    <?php while ($row = $roles_result->fetch_assoc()): ?>
                        <?php $is_protected = ($row['id'] == 1); ?>
                        <tr class="<?= $is_protected ? 'protected-row' : '' ?>">
                            <td>#<?= $row['id'] ?></td>
                            <td>
                                <strong><?= htmlspecialchars($row['name']) ?></strong>
                                <?php if ($is_protected): ?>
                                    <span class="roles-protected-badge protected-tooltip" 
                                          title="System default role - Cannot be modified or deleted"
                                          data-role-lang="protectedTooltip">
                                        <i class="fas fa-lock"></i> 
                                        <span data-role-lang="protectedRole">System Default</span>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="roles-department-badge">
                                    <?= htmlspecialchars($row['department_name'] ?? 'Unknown') ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($row['description'])): ?>
                                    <div class="roles-description-preview" title="<?= htmlspecialchars($row['description']) ?>">
                                        <?= htmlspecialchars($row['description']) ?>
                                    </div>
                                <?php else: ?>
                                    <span style="color: var(--gray-400);">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="roles-status roles-status-<?= strtolower($row['status']) ?>">
                                    <?= $row['status'] ?>
                                </span>
                            </td>
                            <td>
                                <?= date('d M Y H:i', strtotime($row['created_at'])) ?>
                                <div class="roles-timestamp"><?= date('d M Y', strtotime($row['created_at'])) ?></div>
                            </td>
                            <td>
                                <?= date('d M Y H:i', strtotime($row['updated_at'])) ?>
                                <div class="roles-timestamp"><?= date('d M Y', strtotime($row['updated_at'])) ?></div>
                            </td>
                            <td>
                                <div class="roles-actions">
                                    <?php if ($is_protected): ?>
                                        <a href="javascript:void(0)" 
                                           class="roles-action-btn roles-action-edit disabled protected-tooltip"
                                           title="System default role - Cannot be modified or deleted">
                                            <i class="fas fa-edit"></i> <span data-role-lang="edit">Edit</span>
                                        </a>
                                        <a href="javascript:void(0)" 
                                           class="roles-action-btn roles-action-delete disabled protected-tooltip"
                                           title="System default role - Cannot be modified or deleted">
                                            <i class="fas fa-trash"></i> <span data-role-lang="delete">Delete</span>
                                        </a>
                                    <?php else: ?>
                                        <?php if (canEdit($conn, $user_role_id, $module_name)): ?>
                                        <a href="?page=roles&roles_edit=<?= $row['id'] ?>" 
                                           class="roles-action-btn roles-action-edit">
                                            <i class="fas fa-edit"></i> <span data-role-lang="edit">Edit</span>
                                        </a>
                                        <?php endif; ?>
                                        <?php if (canDelete($conn, $user_role_id, $module_name)): ?>
                                        <a href="javascript:void(0)" 
                                           onclick="roles_confirmDelete(<?= $row['id'] ?>, '<?= htmlspecialchars(addslashes($row['name'])) ?>')" 
                                           class="roles-action-btn roles-action-delete">
                                            <i class="fas fa-trash"></i> <span data-role-lang="delete">Delete</span>
                                        </a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" class="roles-empty">
                            <i class="fas fa-users"></i>
                            <p data-role-lang="noData">No roles found. Click "Add New Role" to create one.</p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    // Toggle form visibility
    function roles_toggleForm() {
        const form = document.getElementById('rolesForm');
        const btn = document.getElementById('rolesToggleBtn');
        const lang = currentRolesLang;
        
        if (form.classList.contains('show')) {
            form.classList.remove('show');
            btn.innerHTML = '<i class="fas fa-plus"></i> <span data-role-lang="addNew">' + 
                (lang === 'en' ? 'Add New Role' : 'Ongeza Jukumu Jipya') + '</span>';
        } else {
            form.classList.add('show');
            btn.innerHTML = '<i class="fas fa-times"></i> <span data-role-lang="cancel">' + 
                (lang === 'en' ? 'Cancel' : 'Ghairi') + '</span>';
            
            // Update form header when opening
            const formHeader = document.querySelector('#rolesForm h3');
            if (formHeader) {
                formHeader.textContent = roles_translations[lang].addRole;
            }
            
            // Clear any hidden ID field to ensure it's in add mode
            const hiddenId = document.querySelector('input[name="id"]');
            if (hiddenId) {
                hiddenId.remove();
            }
            
            // Clear form fields
            const nameInput = document.getElementById('name');
            const descriptionInput = document.getElementById('description');
            const statusSelect = document.getElementById('status');
            const deptSelect = document.getElementById('department_id');
            
            if (nameInput) nameInput.value = '';
            if (descriptionInput) descriptionInput.value = '';
            if (statusSelect) statusSelect.value = 'Active';
            if (deptSelect) deptSelect.value = '';
            
            // Change button name to add mode
            const submitBtn = document.querySelector('button[name="roles_update"], button[name="roles_add"]');
            if (submitBtn) {
                submitBtn.name = 'roles_add';
            }
        }
        
        // Update all translatable elements after toggle
        updateRolesLanguage(lang);
    }

    // Validate form before submission
    function roles_validateForm() {
        const name = document.getElementById('name').value.trim();
        const department_id = document.getElementById('department_id').value;
        const lang = currentRolesLang;
        
        if (name === '') {
            alert(roles_translations[lang].nameRequired);
            document.getElementById('name').focus();
            return false;
        }
        
        if (department_id === '') {
            alert(roles_translations[lang].departmentRequired);
            document.getElementById('department_id').focus();
            return false;
        }
        
        return true;
    }

    // Confirm delete with loading effect (protected against deleting ID 1)
    function roles_confirmDelete(id, name) {
        // Check if trying to delete protected role
        if (id == 1) {
            const lang = currentRolesLang;
            alert(roles_translations[lang].cannotDeleteDefault);
            return false;
        }
        
        const lang = currentRolesLang;
        const confirmMsg = roles_translations[lang].confirmDelete + ' "' + name + '"?\n' + 
                          roles_translations[lang].confirmDeleteMsg;
        
        if (confirm(confirmMsg)) {
            // Show loading effect on the clicked row
            const row = event.target.closest('tr');
            if (row) {
                row.style.opacity = '0.5';
                
                // Create loading indicator
                const loadingDiv = document.createElement('div');
                loadingDiv.className = 'roles-loading';
                loadingDiv.style.position = 'absolute';
                loadingDiv.style.top = '50%';
                loadingDiv.style.left = '50%';
                loadingDiv.style.transform = 'translate(-50%, -50%)';
                row.style.position = 'relative';
                row.appendChild(loadingDiv);
            }
            
            // Redirect after a small delay to show loading
            setTimeout(() => {
                window.location.href = `?page=roles&roles_delete=${id}`;
            }, 300);
        }
    }

    // Auto-hide alerts after 5 seconds
    setTimeout(() => {
        document.querySelectorAll('.roles-alert').forEach(alert => {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(() => alert.style.display = 'none', 500);
        });
    }, 5000);
</script>