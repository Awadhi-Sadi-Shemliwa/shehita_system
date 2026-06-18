<?php
/**
 * SHEHITA Enterprise Management System
 * User Management Module - Full CRUD Operations with Advanced Features
 * 
 * This module handles:
 * - Display all users with search/filter/pagination
 * - Add new user with profile image upload (auto-deletes old image)
 * - Edit existing user (with password update option)
 * - Delete user with confirmation (protected against self-deletion)
 * - Dynamic role dropdown based on department selection
 * - Full English/Swahili translation support
 * - CSRF protection for all forms
 * - Protection for default admin account (ID=1)
 * - Profile image upload, preview modal, and avatar display
 * - Graceful foreign key table error handling
 * 
 * PERMISSION ENHANCED: Buttons now respect user permissions (can_view, can_add, can_edit, can_delete)
 * 
 * NOTE: Database tables (departments, roles, users) are created in config.php
 *       Default department (Administrator, ID=1) and role (Super Admin, ID=1)
 *       are created by config.php and departments.php/roles.php modules.
 * 
 * REFINED: Removed all sidebar-related code (no localStorage, no sidebar event listeners)
 * REFINED: Added foreign key table validation with user-friendly error messages
 * REFINED: Ensured no conflict with homepage.php (top navbar layout)
 * REFINED: Image upload now deletes previous image file from server
 */

// Prevent direct access
if (!defined('ACCESS_ALLOWED') && basename($_SERVER['PHP_SELF']) == basename(__FILE__)) {
    // This file is included, so we're safe
}

// Get database connection from parent
global $conn;

// ============================================================================
// FOREIGN KEY TABLE VALIDATION (ISSUE #2)
// Check if required dependent tables exist before proceeding
// ============================================================================

$missing_tables = [];

// Check for departments table
$check_depts = $conn->query("SHOW TABLES LIKE 'departments'");
if ($check_depts->num_rows == 0) {
    $missing_tables[] = ['table' => 'departments', 'module' => 'departments', 'display' => 'Departments'];
}

// Check for roles table
$check_roles = $conn->query("SHOW TABLES LIKE 'roles'");
if ($check_roles->num_rows == 0) {
    $missing_tables[] = ['table' => 'roles', 'module' => 'roles', 'display' => 'Roles'];
}

// Check for users table (should exist, but just in case)
$check_users = $conn->query("SHOW TABLES LIKE 'users'");
if ($check_users->num_rows == 0) {
    $missing_tables[] = ['table' => 'users', 'module' => null, 'display' => 'Users (auto-created by config)'];
}

// Display error message if any required tables are missing
if (!empty($missing_tables)) {
    echo '<div class="user-alert user-alert-warning" style="background: #fff3cd; color: #856404; border-color: #ffeeba; text-align: left; max-width: 800px; margin: 40px auto;">
            <i class="fas fa-exclamation-triangle" style="font-size: 24px; margin-right: 12px;"></i>
            <strong>⚠️ Required Tables Missing!</strong><br><br>
            <p>The following required tables do not exist in the database. Please open the related modules first to automatically create them:</p>
            <ul style="margin-top: 12px; margin-left: 20px;">';
    foreach ($missing_tables as $missing) {
        if ($missing['module']) {
            echo '<li><strong>' . htmlspecialchars($missing['table']) . '</strong> → Please open the <strong>' . htmlspecialchars($missing['display']) . '</strong> module first</li>';
        } else {
            echo '<li><strong>' . htmlspecialchars($missing['table']) . '</strong> → Please ensure the database is properly initialized (run config.php or re-login)</li>';
        }
    }
    echo '</ul>
            <p style="margin-top: 16px;">After opening the required modules, refresh this page to continue.</p>
          </div>';
    return; // Stop rendering the module
}

// PERMISSION: Get user role and check module permissions
$user_role_id = $_SESSION['role_id'] ?? 0;
$module_name = 'user-management';

// PERMISSION: Check if user has view permission
if (!canView($conn, $user_role_id, $module_name)) {
    echo '<div class="user-alert user-alert-danger" style="text-align: center; padding: 40px;">
            <i class="fas fa-lock" style="font-size: 48px; margin-bottom: 16px; display: block;"></i>
            <h3>Access Denied</h3>
            <p>You do not have permission to view this module.</p>
          </div>';
    return; // Stop rendering the module
}

/**
 * ============================================================================
 * ENSURE USERS TABLE EXISTS (create only if missing, schema handled by config.php)
 * ============================================================================
 */

// Schema note: the `users` table is created centrally in config.php.
// This module assumes it already exists; the column-migration block below
// keeps older installations in sync.

/**
 * ENSURE ALL REQUIRED COLUMNS EXIST (schema migration for existing installations)
 * Add missing columns if table exists but schema is incomplete
 */
$required_columns = [
    'phone' => "VARCHAR(20) DEFAULT NULL",
    'address' => "TEXT DEFAULT NULL",
    'profile_image' => "VARCHAR(255) DEFAULT NULL",
    'status' => "ENUM('Active', 'Inactive') DEFAULT 'Inactive'",
    'department_id' => "INT(11) UNSIGNED DEFAULT NULL",
    'role_id' => "INT(11) UNSIGNED DEFAULT NULL",
    'security_question' => "TEXT DEFAULT NULL",
    'security_answer' => "VARCHAR(255) DEFAULT NULL"
];

foreach ($required_columns as $column => $definition) {
    $check_column = $conn->query("SHOW COLUMNS FROM users LIKE '$column'");
    if ($check_column->num_rows == 0) {
        $alter_sql = "ALTER TABLE users ADD COLUMN $column $definition";
        $conn->query($alter_sql);
    }
}

/**
 * ============================================================================
 * ENSURE DEFAULT ADMIN ACCOUNT EXISTS (ID=1)
 * This is created by config.php, but we ensure it exists for safety
 * ============================================================================
 */
// The default admin is created by config.php using the ADMIN_DEFAULT_EMAIL
// environment variable. We look it up by that same email and only ensure its
// id/department/role are correct — we never seed a weak hard-coded password here.
$admin_email = getenv('ADMIN_DEFAULT_EMAIL') ?: 'admin@paplontech.com';

$stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
$stmt->bind_param("s", $admin_email);
$stmt->execute();
$check_admin = $stmt->get_result();
if ($check_admin->num_rows > 0) {
    // Ensure admin has correct id (1), department (Administrator) and role (Super Admin)
    $admin = $check_admin->fetch_assoc();
    if ($admin['id'] != 1) {
        $upd = $conn->prepare("UPDATE users SET id = 1 WHERE email = ?");
        $upd->bind_param("s", $admin_email);
        $upd->execute();
        $upd->close();
        $conn->query("ALTER TABLE users AUTO_INCREMENT = 2");
    }
    $conn->query("UPDATE users SET department_id = 1, role_id = 1 WHERE id = 1 AND (department_id != 1 OR role_id != 1)");
}
$stmt->close();

/**
 * ============================================================================
 * ENSURE ALL USERS HAVE VALID DEPARTMENT AND ROLE
 * Set default department (Administrator ID=1) and role (Super Admin ID=1) for any null values
 * ============================================================================
 */
$conn->query("UPDATE users SET department_id = 1 WHERE department_id IS NULL AND id != 1");
$conn->query("UPDATE users SET role_id = 1 WHERE role_id IS NULL AND id != 1");

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
 * CREATE UPLOADS DIRECTORY IF NOT EXISTS
 * ============================================================================
 */
$upload_dir = __DIR__ . '/../uploads/profiles/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

/**
 * ============================================================================
 * VARIABLE INITIALIZATION
 * ============================================================================
 */
$user_message = '';
$user_message_type = '';
$edit_mode = false;
$edit_data = null;

// Pagination variables
$current_page = isset($_GET['user_page']) ? (int)$_GET['user_page'] : 1;
$records_per_page = 10;
$offset = ($current_page - 1) * $records_per_page;

// Search/filter variables
$search = isset($_GET['search']) ? sanitize($conn, $_GET['search']) : '';
$status_filter = isset($_GET['status_filter']) ? sanitize($conn, $_GET['status_filter']) : '';
$department_filter = isset($_GET['department_filter']) ? (int)$_GET['department_filter'] : '';
$role_filter = isset($_GET['role_filter']) ? (int)$_GET['role_filter'] : '';

// Get current logged-in user ID for protection
$current_user_id = $_SESSION['user_id'] ?? 0;

/**
 * ============================================================================
 * FETCH DEPARTMENTS AND ROLES FOR DROPDOWNS
 * Only fetch active departments and roles
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

// Fetch all active roles for dynamic filtering
$all_roles = [];
$roles_query = "SELECT id, name, department_id FROM roles WHERE status = 'Active' ORDER BY department_id, name ASC";
$roles_result = $conn->query($roles_query);
if ($roles_result && $roles_result->num_rows > 0) {
    while ($row = $roles_result->fetch_assoc()) {
        $all_roles[] = $row;
    }
}

/**
 * ============================================================================
 * PROTECTION CHECKS - Prevent editing/deleting self through direct URLs
 * Also protect default admin account (ID=1)
 * ============================================================================
 */
if (isset($_GET['user_edit']) && (int)$_GET['user_edit'] == $current_user_id) {
    $user_message = "You cannot edit your own account through this module. Use Profile section instead.";
    $user_message_type = "danger";
    header("Location: ?page=user-management");
    exit();
}

if (isset($_GET['user_delete']) && (int)$_GET['user_delete'] == $current_user_id) {
    $user_message = "You cannot delete your own account.";
    $user_message_type = "danger";
    header("Location: ?page=user-management");
    exit();
}

if (isset($_GET['user_delete']) && (int)$_GET['user_delete'] == 1) {
    $user_message = "The default admin account cannot be deleted.";
    $user_message_type = "danger";
    header("Location: ?page=user-management");
    exit();
}

if (isset($_GET['user_edit']) && (int)$_GET['user_edit'] == 1) {
    $user_message = "The default admin account cannot be edited.";
    $user_message_type = "danger";
    header("Location: ?page=user-management");
    exit();
}

/**
 * ============================================================================
 * HELPER FUNCTION: Delete old profile image file from server (ISSUE #4)
 * Uses both absolute and relative path checks
 * ============================================================================
 */
function deleteUserProfileImageFile($relative_path) {
    if (empty($relative_path)) {
        return false;
    }
    
    // Try multiple path variations to ensure file deletion
    $paths_to_check = [
        $relative_path,                           // Direct relative path
        __DIR__ . '/../' . ltrim($relative_path, '/'), // Absolute from module directory
        dirname(__DIR__) . '/' . ltrim($relative_path, '/') // Alternative absolute
    ];
    
    $deleted = false;
    foreach ($paths_to_check as $path) {
        if (file_exists($path) && is_file($path)) {
            if (@unlink($path)) {
                $deleted = true;
            }
        }
    }
    return $deleted;
}

/**
 * ============================================================================
 * HELPER FUNCTION: Handle profile image upload (ISSUE #4 - deletes old image on success)
 * ============================================================================
 */
function handleUserProfileImageUpload($existing_image = null) {
    $upload_dir = __DIR__ . '/../uploads/profiles/';
    
    // Create directory if it doesn't exist
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    // Check if file was uploaded
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['profile_image'];
        $file_tmp = $file['tmp_name'];
        $file_size = $file['size'];
        $file_error = $file['error'];
        
        // Get file extension
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
        
        // Validate file
        if ($file_error === UPLOAD_ERR_OK) {
            if ($file_size > 2 * 1024 * 1024) {
                return ['error' => 'Profile image must be less than 2MB'];
            }
            
            if (!in_array($file_ext, $allowed_extensions)) {
                return ['error' => 'Only JPG, PNG, and GIF files are allowed'];
            }
            
            // Validate MIME type
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $file_tmp);
            finfo_close($finfo);
            
            $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif'];
            if (!in_array($mime_type, $allowed_mimes)) {
                return ['error' => 'Invalid image file type'];
            }
            
            // Generate unique filename
            $new_filename = time() . '_' . uniqid() . '.' . $file_ext;
            $upload_path = $upload_dir . $new_filename;
            
            // Move uploaded file
            if (move_uploaded_file($file_tmp, $upload_path)) {
                // Delete old image if exists (after successful upload)
                if ($existing_image) {
                    deleteUserProfileImageFile($existing_image);
                }
                
                // Return relative path for database storage
                return ['path' => 'uploads/profiles/' . $new_filename];
            } else {
                return ['error' => 'Failed to upload profile image'];
            }
        }
    }
    
    // No new image uploaded, keep existing
    return ['path' => $existing_image];
}

/**
 * ============================================================================
 * HANDLE FORM SUBMISSIONS WITH PERMISSION CHECKS
 * ============================================================================
 */

// PERMISSION: Check add permission before processing add operation
if (isset($_POST['user_add']) && !canAdd($conn, $user_role_id, $module_name)) {
    $user_message = "You do not have permission to add users.";
    $user_message_type = "danger";
} elseif (isset($_POST['user_add'])) {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $user_message = "Invalid form submission. Please try again.";
        $user_message_type = "danger";
    } else {
        // Get form data
        $name = sanitize($conn, $_POST['name']);
        $email = sanitize($conn, $_POST['email']);
        $phone = sanitize($conn, $_POST['phone']);
        $address = sanitize($conn, $_POST['address']);
        $department_id = (int)$_POST['department_id'];
        $role_id = (int)$_POST['role_id'];
        $status = sanitize($conn, $_POST['status']);
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Validate inputs
        $errors = [];
        
        // Name validation
        if (empty($name)) {
            $errors[] = "Full name is required";
        } elseif (strlen($name) < 2) {
            $errors[] = "Name must be at least 2 characters";
        } elseif (strlen($name) > 100) {
            $errors[] = "Name must not exceed 100 characters";
        }
        
        // Email validation
        if (empty($email)) {
            $errors[] = "Email address is required";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Please enter a valid email address";
        }
        
        // Phone validation
        if (!empty($phone) && !preg_match('/^[0-9+\-\s]{10,20}$/', $phone)) {
            $errors[] = "Please enter a valid phone number (10-20 digits, +, -, space allowed)";
        }
        
        // Department validation
        if ($department_id <= 0) {
            $errors[] = "Please select a department";
        } else {
            $dept_check = $conn->query("SELECT id FROM departments WHERE id = $department_id AND status = 'Active'");
            if ($dept_check->num_rows == 0) {
                $errors[] = "Selected department not found or inactive";
            }
        }
        
        // Role validation - ensure role belongs to selected department
        if ($role_id <= 0) {
            $errors[] = "Please select a role";
        } else {
            $role_check = $conn->prepare("SELECT id FROM roles WHERE id = ? AND department_id = ? AND status = 'Active'");
            $role_check->bind_param("ii", $role_id, $department_id);
            $role_check->execute();
            $role_result = $role_check->get_result();
            if ($role_result->num_rows == 0) {
                $errors[] = "Selected role not found or does not belong to the selected department";
            }
            $role_check->close();
        }
        
        // Password validation
        if (empty($password)) {
            $errors[] = "Password is required for new users";
        } elseif (strlen($password) < 6) {
            $errors[] = "Password must be at least 6 characters";
        }
        
        if ($password !== $confirm_password) {
            $errors[] = "Passwords do not match";
        }
        
        // Status validation
        if (!in_array($status, ['Active', 'Inactive'])) {
            $errors[] = "Invalid status";
        }
        
        // Check if email already exists
        if (empty($errors)) {
            $email_check = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $email_check->bind_param("s", $email);
            $email_check->execute();
            $email_result = $email_check->get_result();
            if ($email_result->num_rows > 0) {
                $errors[] = "Email address is already registered";
            }
            $email_check->close();
        }
        
        // Handle profile image upload (no existing image for new user)
        $profile_image_path = null;
        if (empty($errors)) {
            $upload_result = handleUserProfileImageUpload();
            if (isset($upload_result['error'])) {
                $errors[] = $upload_result['error'];
            } else {
                $profile_image_path = $upload_result['path'];
            }
        }
        
        // Insert user if no errors
        if (empty($errors)) {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            $insert_stmt = $conn->prepare("INSERT INTO users (name, email, password, phone, address, department_id, role_id, status, profile_image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $insert_stmt->bind_param("sssssiiss", $name, $email, $hashed_password, $phone, $address, $department_id, $role_id, $status, $profile_image_path);
            
            if ($insert_stmt->execute()) {
                $user_message = "User added successfully!";
                $user_message_type = "success";
            } else {
                $user_message = "Error adding user: " . $conn->error;
                $user_message_type = "danger";
            }
            $insert_stmt->close();
        } else {
            $user_message = implode("<br>", $errors);
            $user_message_type = "danger";
        }
    }
    
    // Generate new CSRF token
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// PERMISSION: Check edit permission before processing update operation
if (isset($_POST['user_update']) && !canEdit($conn, $user_role_id, $module_name)) {
    $user_message = "You do not have permission to edit users.";
    $user_message_type = "danger";
} elseif (isset($_POST['user_update'])) {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $user_message = "Invalid form submission. Please try again.";
        $user_message_type = "danger";
    } else {
        $id = (int)$_POST['id'];
        $name = sanitize($conn, $_POST['name']);
        $email = sanitize($conn, $_POST['email']);
        $phone = sanitize($conn, $_POST['phone']);
        $address = sanitize($conn, $_POST['address']);
        $department_id = (int)$_POST['department_id'];
        $role_id = (int)$_POST['role_id'];
        $status = sanitize($conn, $_POST['status']);
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Get existing profile image before update (for deletion if replaced)
        $existing_image_query = $conn->prepare("SELECT profile_image FROM users WHERE id = ?");
        $existing_image_query->bind_param("i", $id);
        $existing_image_query->execute();
        $existing_image_result = $existing_image_query->get_result();
        $existing_image = $existing_image_result->num_rows > 0 ? $existing_image_result->fetch_assoc()['profile_image'] : null;
        $existing_image_query->close();
        
        // Validate inputs
        $errors = [];
        
        // PROTECTION: Cannot modify admin account
        if ($id == 1) {
            $errors[] = "The default admin account cannot be edited.";
        }
        
        // PROTECTION: Cannot change role/department of self
        if ($id == $current_user_id) {
            $errors[] = "You cannot modify your own account through this module. Use Profile section instead.";
        }
        
        // Name validation
        if (empty($name)) {
            $errors[] = "Full name is required";
        } elseif (strlen($name) < 2) {
            $errors[] = "Name must be at least 2 characters";
        } elseif (strlen($name) > 100) {
            $errors[] = "Name must not exceed 100 characters";
        }
        
        // Email validation
        if (empty($email)) {
            $errors[] = "Email address is required";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Please enter a valid email address";
        }
        
        // Phone validation
        if (!empty($phone) && !preg_match('/^[0-9+\-\s]{10,20}$/', $phone)) {
            $errors[] = "Please enter a valid phone number (10-20 digits, +, -, space allowed)";
        }
        
        // Department validation
        if ($department_id <= 0) {
            $errors[] = "Please select a department";
        } else {
            $dept_check = $conn->query("SELECT id FROM departments WHERE id = $department_id AND status = 'Active'");
            if ($dept_check->num_rows == 0) {
                $errors[] = "Selected department not found or inactive";
            }
        }
        
        // Role validation - ensure role belongs to selected department
        if ($role_id <= 0) {
            $errors[] = "Please select a role";
        } else {
            $role_check = $conn->prepare("SELECT id FROM roles WHERE id = ? AND department_id = ? AND status = 'Active'");
            $role_check->bind_param("ii", $role_id, $department_id);
            $role_check->execute();
            $role_result = $role_check->get_result();
            if ($role_result->num_rows == 0) {
                $errors[] = "Selected role not found or does not belong to the selected department";
            }
            $role_check->close();
        }
        
        // Password validation (optional for update)
        $update_password = false;
        if (!empty($password)) {
            if (strlen($password) < 6) {
                $errors[] = "Password must be at least 6 characters";
            }
            if ($password !== $confirm_password) {
                $errors[] = "Passwords do not match";
            }
            $update_password = true;
        }
        
        // Status validation
        if (!in_array($status, ['Active', 'Inactive'])) {
            $errors[] = "Invalid status";
        }
        
        // Check if email already exists (excluding current user)
        if (empty($errors)) {
            $email_check = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $email_check->bind_param("si", $email, $id);
            $email_check->execute();
            $email_result = $email_check->get_result();
            if ($email_result->num_rows > 0) {
                $errors[] = "Email address is already registered to another user";
            }
            $email_check->close();
        }
        
        // Handle profile image upload (pass existing image for deletion if replaced) - ISSUE #4
        $profile_image_path = null;
        if (empty($errors)) {
            $upload_result = handleUserProfileImageUpload($existing_image);
            if (isset($upload_result['error'])) {
                $errors[] = $upload_result['error'];
            } else {
                $profile_image_path = $upload_result['path'];
            }
        }
        
        // Update user if no errors
        if (empty($errors)) {
            if ($update_password) {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                if ($profile_image_path) {
                    $update_stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, password = ?, phone = ?, address = ?, department_id = ?, role_id = ?, status = ?, profile_image = ? WHERE id = ?");
                    $update_stmt->bind_param("sssssiissi", $name, $email, $hashed_password, $phone, $address, $department_id, $role_id, $status, $profile_image_path, $id);
                } else {
                    $update_stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, password = ?, phone = ?, address = ?, department_id = ?, role_id = ?, status = ? WHERE id = ?");
                    $update_stmt->bind_param("sssssiisi", $name, $email, $hashed_password, $phone, $address, $department_id, $role_id, $status, $id);
                }
            } else {
                if ($profile_image_path) {
                    $update_stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, phone = ?, address = ?, department_id = ?, role_id = ?, status = ?, profile_image = ? WHERE id = ?");
                    $update_stmt->bind_param("ssssiissi", $name, $email, $phone, $address, $department_id, $role_id, $status, $profile_image_path, $id);
                } else {
                    $update_stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, phone = ?, address = ?, department_id = ?, role_id = ?, status = ? WHERE id = ?");
                    $update_stmt->bind_param("sssssisi", $name, $email, $phone, $address, $department_id, $role_id, $status, $id);
                }
            }
            
            if ($update_stmt->execute()) {
                $user_message = "User updated successfully!";
                $user_message_type = "success";
            } else {
                $user_message = "Error updating user: " . $conn->error;
                $user_message_type = "danger";
            }
            $update_stmt->close();
        } else {
            $user_message = implode("<br>", $errors);
            $user_message_type = "danger";
        }
    }
    
    // Generate new CSRF token
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// PERMISSION: Check delete permission before processing delete operation
if (isset($_GET['user_delete']) && !canDelete($conn, $user_role_id, $module_name)) {
    $user_message = "You do not have permission to delete users.";
    $user_message_type = "danger";
} elseif (isset($_GET['user_delete'])) {
    $id = (int)$_GET['user_delete'];
    
    // Protection checks
    if ($id == $current_user_id) {
        $user_message = "You cannot delete your own account.";
        $user_message_type = "danger";
    } elseif ($id == 1) {
        $user_message = "The default admin account cannot be deleted.";
        $user_message_type = "danger";
    } elseif ($id > 0) {
        // Check if this is the last admin account (role_id = 1 = Super Admin)
        $admin_check = $conn->query("SELECT COUNT(*) as admin_count FROM users WHERE role_id = 1 AND status = 'Active'");
        $admin_count = $admin_check->fetch_assoc()['admin_count'];
        
        $user_check = $conn->prepare("SELECT role_id, profile_image FROM users WHERE id = ?");
        $user_check->bind_param("i", $id);
        $user_check->execute();
        $user_result = $user_check->get_result();
        $user_data = $user_result->fetch_assoc();
        $user_role_id_check = $user_data['role_id'] ?? 0;
        $profile_image = $user_data['profile_image'] ?? null;
        $user_check->close();
        
        if ($user_role_id_check == 1 && $admin_count <= 1) {
            $user_message = "Cannot delete the last active Super Admin account.";
            $user_message_type = "danger";
        } else {
            // Delete profile image file if exists (ISSUE #4)
            if ($profile_image) {
                deleteUserProfileImageFile($profile_image);
            }
            
            $delete_stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
            $delete_stmt->bind_param("i", $id);
            
            if ($delete_stmt->execute()) {
                $user_message = "User deleted successfully!";
                $user_message_type = "success";
                
                // Check if table is empty and reset auto-increment
                $check_empty = $conn->query("SELECT COUNT(*) as count FROM users");
                $row_count = $check_empty->fetch_assoc()['count'];
                if ($row_count == 0) {
                    $conn->query("ALTER TABLE users AUTO_INCREMENT = 1");
                }
            } else {
                $user_message = "Error deleting user: " . $conn->error;
                $user_message_type = "danger";
            }
            $delete_stmt->close();
        }
    }
}

// Get edit data if in edit mode (protected admin already handled above)
// PERMISSION: Only allow edit if user has edit permission
if (isset($_GET['user_edit'])) {
    $edit_id = (int)$_GET['user_edit'];
    // PERMISSION: Only allow edit if user has edit permission and it's not protected
    if ($edit_id > 0 && $edit_id != $current_user_id && $edit_id != 1 && canEdit($conn, $user_role_id, $module_name)) {
        $edit_stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
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
 * BUILD QUERY WITH SEARCH/FILTER/PAGINATION
 * ============================================================================
 */
$where_clause = "";
$params = [];
$types = "";

if (!empty($search)) {
    $where_clause .= " (u.name LIKE ? OR u.email LIKE ?) ";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ss";
}

if (!empty($status_filter)) {
    if (!empty($where_clause)) {
        $where_clause .= " AND ";
    }
    $where_clause .= " u.status = ? ";
    $params[] = $status_filter;
    $types .= "s";
}

if (!empty($department_filter)) {
    if (!empty($where_clause)) {
        $where_clause .= " AND ";
    }
    $where_clause .= " u.department_id = ? ";
    $params[] = $department_filter;
    $types .= "i";
}

if (!empty($role_filter)) {
    if (!empty($where_clause)) {
        $where_clause .= " AND ";
    }
    $where_clause .= " u.role_id = ? ";
    $params[] = $role_filter;
    $types .= "i";
}

if (!empty($where_clause)) {
    $where_clause = "WHERE " . $where_clause;
}

// Count total records for pagination
$count_query = "SELECT COUNT(*) as total FROM users u $where_clause";
if (!empty($params)) {
    $count_stmt = $conn->prepare($count_query);
    $count_stmt->bind_param($types, ...$params);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total_records = $count_result->fetch_assoc()['total'];
    $count_stmt->close();
} else {
    $total_result = $conn->query($count_query);
    $total_records = $total_result->fetch_assoc()['total'];
}

$total_pages = ceil($total_records / $records_per_page);

// Ensure current page is valid
if ($current_page > $total_pages && $total_pages > 0) {
    $current_page = $total_pages;
    $offset = ($current_page - 1) * $records_per_page;
}

// Fetch users with joins
$users_query = "SELECT u.*, d.name as department_name, r.name as role_name 
                FROM users u 
                LEFT JOIN departments d ON u.department_id = d.id 
                LEFT JOIN roles r ON u.role_id = r.id 
                $where_clause 
                ORDER BY u.id DESC 
                LIMIT ? OFFSET ?";
$params[] = $records_per_page;
$params[] = $offset;
$types .= "ii";

$users_stmt = $conn->prepare($users_query);
$users_stmt->bind_param($types, ...$params);
$users_stmt->execute();
$users_result = $users_stmt->get_result();
?>

<!-- USER MANAGEMENT TRANSLATIONS -->
<script>
// User Management translations for English and Swahili
const user_translations = {
    en: {
        pageTitle: 'User Management',
        addNew: 'Add New User',
        editUser: 'Edit User',
        addUser: 'Add New User',
        id: 'ID',
        name: 'Full Name',
        email: 'Email',
        phone: 'Phone',
        department: 'Department',
        role: 'Role',
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
        confirmDelete: 'Are you sure you want to delete user',
        confirmDeleteMsg: 'This action cannot be undone.',
        noData: 'No users found.',
        nameRequired: 'Full name is required!',
        emailRequired: 'Email address is required!',
        emailInvalid: 'Please enter a valid email address!',
        departmentRequired: 'Please select a department!',
        roleRequired: 'Please select a role!',
        passwordRequired: 'Password is required for new users!',
        passwordLength: 'Password must be at least 6 characters!',
        passwordMatch: 'Passwords do not match!',
        loading: 'Loading...',
        search: 'Search',
        filter: 'Filter',
        allStatus: 'All Status',
        allDepartments: 'All Departments',
        allRoles: 'All Roles',
        clear: 'Clear',
        totalRecords: 'Total Users',
        records: 'records',
        namePlaceholder: 'Enter full name',
        emailPlaceholder: 'Enter email address',
        phonePlaceholder: 'Enter phone number',
        addressPlaceholder: 'Enter address (optional)',
        selectDepartment: 'Select Department',
        selectRole: 'Select Role',
        passwordPlaceholder: 'Enter password (min. 6 characters)',
        confirmPasswordPlaceholder: 'Confirm password',
        profileImage: 'Profile Image',
        chooseImage: 'Choose Image',
        imagePreview: 'Image Preview',
        noImage: 'No image selected',
        imageHint: 'Optional. Max 2MB. Allowed: JPG, PNG, GIF',
        address: 'Address',
        page: 'Page',
        of: 'of',
        previous: 'Previous',
        next: 'Next',
        profileImageCurrent: 'Current Image',
        changeImage: 'Change Image',
        keepCurrent: 'Keep current image',
        leavePasswordBlank: 'Leave blank to keep current password',
        avatar: 'Avatar',
        previewTitle: 'Profile Image',
        close: 'Close',
        warningTitle: 'Required Tables Missing'
    },
    sw: {
        pageTitle: 'Usimamizi wa Watumiaji',
        addNew: 'Ongeza Mtumiaji Mpya',
        editUser: 'Hariri Mtumiaji',
        addUser: 'Ongeza Mtumiaji Mpya',
        id: 'Kitambulisho',
        name: 'Jina Kamili',
        email: 'Barua pepe',
        phone: 'Namba ya Simu',
        department: 'Idara',
        role: 'Jukumu',
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
        confirmDelete: 'Una uhakika unataka kufuta mtumiaji',
        confirmDeleteMsg: 'Kitendo hiki hakiwezi kutenguliwa.',
        noData: 'Hakuna watumiaji waliopatikana.',
        nameRequired: 'Jina kamili linahitajika!',
        emailRequired: 'Barua pepe inahitajika!',
        emailInvalid: 'Tafadhali ingiza barua pepe halali!',
        departmentRequired: 'Tafadhali chagua idara!',
        roleRequired: 'Tafadhali chagua jukumu!',
        passwordRequired: 'Nenosiri linahitajika kwa watumiaji wapya!',
        passwordLength: 'Nenosiri lazima liwe na angalau herufi 6!',
        passwordMatch: 'Manenosiri hayalingani!',
        loading: 'Inapakia...',
        search: 'Tafuta',
        filter: 'Chuja',
        allStatus: 'Hali Zote',
        allDepartments: 'Idara Zote',
        allRoles: 'Majukumu Yote',
        clear: 'Futa',
        totalRecords: 'Jumla ya Watumiaji',
        records: 'rekodi',
        namePlaceholder: 'Weka jina kamili',
        emailPlaceholder: 'Weka barua pepe',
        phonePlaceholder: 'Weka namba ya simu',
        addressPlaceholder: 'Weka anwani (si lazima)',
        selectDepartment: 'Chagua Idara',
        selectRole: 'Chagua Jukumu',
        passwordPlaceholder: 'Weka nenosiri (angalau herufi 6)',
        confirmPasswordPlaceholder: 'Thibitisha nenosiri',
        profileImage: 'Picha ya Wasifu',
        chooseImage: 'Chagua Picha',
        imagePreview: 'Onesho la Picha',
        noImage: 'Hakuna picha iliyochaguliwa',
        imageHint: 'Si lazima. Upeo 2MB. Kuruhusiwa: JPG, PNG, GIF',
        address: 'Anwani',
        page: 'Ukurasa',
        of: 'kati ya',
        previous: 'Iliyotangulia',
        next: 'Ijayo',
        profileImageCurrent: 'Picha ya Sasa',
        changeImage: 'Badilisha Picha',
        keepCurrent: 'Weka picha ya sasa',
        leavePasswordBlank: 'Acha wazi kuweka nenosiri la sasa',
        avatar: 'Picha',
        previewTitle: 'Picha ya Wasifu',
        close: 'Funga',
        warningTitle: 'Jedwali Muhimu Halipo'
    }
};

// Current language
let currentUserLang = localStorage.getItem('preferredLanguage') || 'en';

// Function to update all translatable elements
function updateUserManagementLanguage(lang) {
    currentUserLang = lang;
    const elements = document.querySelectorAll('[data-user-lang]');
    
    elements.forEach(element => {
        const key = element.getAttribute('data-user-lang');
        if (user_translations[lang] && user_translations[lang][key]) {
            if (element.tagName === 'INPUT' || element.tagName === 'TEXTAREA') {
                if (element.getAttribute('type') !== 'submit' && element.getAttribute('type') !== 'button') {
                    element.placeholder = user_translations[lang][key];
                } else {
                    element.textContent = user_translations[lang][key];
                }
            } else if (element.tagName === 'OPTION') {
                element.textContent = user_translations[lang][key];
            } else if (element.tagName === 'BUTTON' && element.getAttribute('type') !== 'submit') {
                // Skip submit buttons, they have separate handling
            } else {
                element.textContent = user_translations[lang][key];
            }
        }
    });
    
    // Update table headers
    const thElements = document.querySelectorAll('th[data-user-lang]');
    thElements.forEach(th => {
        const key = th.getAttribute('data-user-lang');
        if (user_translations[lang] && user_translations[lang][key]) {
            th.textContent = user_translations[lang][key];
        }
    });
    
    // Update empty state message
    const emptyState = document.querySelector('.user-empty p');
    if (emptyState) {
        emptyState.textContent = user_translations[lang].noData;
    }
    
    // Update form header
    const formHeader = document.querySelector('#userForm h3');
    if (formHeader) {
        const isEditMode = document.querySelector('input[name="id"]') !== null;
        formHeader.textContent = user_translations[lang][isEditMode ? 'editUser' : 'addUser'];
    }
    
    // Update search input placeholder
    const searchInput = document.querySelector('input[name="search"]');
    if (searchInput) {
        searchInput.placeholder = user_translations[lang].search;
    }
    
    // Update total records text
    const totalRecordsSpan = document.querySelector('#totalRecords');
    if (totalRecordsSpan) {
        totalRecordsSpan.textContent = user_translations[lang].totalRecords;
    }
    
    // Update pagination buttons
    const prevBtn = document.querySelector('.user-prev-btn');
    const nextBtn = document.querySelector('.user-next-btn');
    if (prevBtn && prevBtn.tagName === 'A') prevBtn.innerHTML = `<i class="fas fa-chevron-left"></i> ${user_translations[lang].previous}`;
    if (nextBtn && nextBtn.tagName === 'A') nextBtn.innerHTML = `${user_translations[lang].next} <i class="fas fa-chevron-right"></i>`;
    
    // Update form buttons
    const saveBtn = document.querySelector('button[type="submit"]');
    if (saveBtn) {
        const saveSpan = saveBtn.querySelector('span');
        if (saveSpan) saveSpan.textContent = user_translations[lang].save;
    }
    
    // Update modal close button
    const closeBtn = document.querySelector('.image-preview-close');
    if (closeBtn) {
        closeBtn.setAttribute('aria-label', user_translations[lang].close);
    }
    
    const modalTitle = document.getElementById('previewModalTitle');
    if (modalTitle && modalTitle.textContent === 'Profile Image') {
        modalTitle.textContent = user_translations[lang].previewTitle;
    }
}

// Dynamic role dropdown based on department selection
function updateRoleDropdown() {
    const departmentSelect = document.getElementById('department_id');
    const roleSelect = document.getElementById('role_id');
    const selectedDept = departmentSelect.value;
    
    // Clear current options
    roleSelect.innerHTML = '';
    
    // Add default option
    const defaultOption = document.createElement('option');
    defaultOption.value = '';
    defaultOption.textContent = user_translations[currentUserLang].selectRole;
    roleSelect.appendChild(defaultOption);
    
    // Filter roles based on selected department
    const roles = <?= json_encode($all_roles) ?>;
    const filteredRoles = roles.filter(role => role.department_id == selectedDept);
    
    if (filteredRoles.length > 0) {
        filteredRoles.forEach(role => {
            const option = document.createElement('option');
            option.value = role.id;
            option.textContent = role.name;
            roleSelect.appendChild(option);
        });
    }
    
    // If in edit mode, set the selected role
    <?php if ($edit_mode && isset($edit_data['role_id'])): ?>
    if (selectedDept == <?= $edit_data['department_id'] ?>) {
        roleSelect.value = <?= $edit_data['role_id'] ?>;
    }
    <?php endif; ?>
}

// Preview profile image before upload
function previewProfileImageUpload(input) {
    const previewDiv = document.getElementById('image-preview');
    const previewImg = document.getElementById('preview-img');
    const fileNameSpan = document.getElementById('file-name');
    
    if (input.files && input.files[0]) {
        const file = input.files[0];
        fileNameSpan.textContent = file.name;
        
        const reader = new FileReader();
        reader.onload = function(e) {
            previewImg.src = e.target.result;
            previewDiv.style.display = 'block';
        }
        reader.readAsDataURL(file);
    } else {
        fileNameSpan.textContent = '';
        previewDiv.style.display = 'none';
        previewImg.src = '#';
    }
}

// Preview modal for profile image (click on avatar)
function previewProfileImage(imagePath, userName) {
    const modal = document.getElementById('imagePreviewModal');
    const modalImg = document.getElementById('previewModalImg');
    const modalTitle = document.getElementById('previewModalTitle');
    const lang = currentUserLang;
    
    if (imagePath && imagePath !== '' && imagePath !== 'null') {
        // Ensure path is correct - if it starts with 'uploads/', it's relative to root
        let imgSrc = imagePath;
        if (!imagePath.startsWith('uploads/') && !imagePath.startsWith('/')) {
            imgSrc = '../' + imagePath;
        }
        modalImg.src = imgSrc;
    } else {
        // Use a data URI placeholder for users without image
        modalImg.src = 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"%3E%3Ccircle cx="50" cy="50" r="50" fill="%237b583f"/%3E%3Ctext x="50" y="67" text-anchor="middle" fill="white" font-size="40" font-family="Arial"%3E' + 
            (userName ? userName.charAt(0).toUpperCase() : '?') + 
            '%3C/text%3E%3C/svg%3E';
    }
    
    modalTitle.textContent = (userName ? userName : user_translations[lang].previewTitle);
    modal.classList.add('active');
}

function closePreviewModal() {
    document.getElementById('imagePreviewModal').classList.remove('active');
    const modalImg = document.getElementById('previewModalImg');
    if (modalImg) {
        modalImg.src = '#';
    }
}

// Toggle form visibility
function toggleUserForm() {
    const form = document.getElementById('userForm');
    const btn = document.getElementById('userToggleBtn');
    const lang = currentUserLang;
    
    if (form.classList.contains('show')) {
        form.classList.remove('show');
        btn.innerHTML = '<i class="fas fa-plus"></i> <span data-user-lang="addNew">' + 
            (lang === 'en' ? 'Add New User' : 'Ongeza Mtumiaji Mpya') + '</span>';
    } else {
        form.classList.add('show');
        btn.innerHTML = '<i class="fas fa-times"></i> <span data-user-lang="cancel">' + 
            (lang === 'en' ? 'Cancel' : 'Ghairi') + '</span>';
        
        // Reset form fields
        document.getElementById('name').value = '';
        document.getElementById('email').value = '';
        document.getElementById('phone').value = '';
        document.getElementById('address').value = '';
        document.getElementById('status').value = 'Active';
        document.getElementById('password').value = '';
        document.getElementById('confirm_password').value = '';
        document.getElementById('profile_image').value = '';
        document.getElementById('image-preview').style.display = 'none';
        
        // Reset department and role dropdowns
        const deptSelect = document.getElementById('department_id');
        if (deptSelect.options.length > 0) {
            deptSelect.selectedIndex = 0;
            updateRoleDropdown();
        }
        
        // Change button name to add mode
        const submitBtn = document.querySelector('button[name="user_update"], button[name="user_add"]');
        if (submitBtn) {
            submitBtn.name = 'user_add';
        }
        
        // Update form header
        const formHeader = document.querySelector('#userForm h3');
        if (formHeader) {
            formHeader.textContent = user_translations[lang].addUser;
        }
        
        // Remove hidden ID field if exists
        const hiddenId = document.querySelector('input[name="id"]');
        if (hiddenId) {
            hiddenId.remove();
        }
        
        // Show password fields for new user
        const passwordSection = document.querySelector('.password-section');
        if (passwordSection) {
            passwordSection.style.display = 'block';
        }
        const passwordInput = document.getElementById('password');
        const confirmPasswordInput = document.getElementById('confirm_password');
        if (passwordInput) passwordInput.required = true;
        if (confirmPasswordInput) confirmPasswordInput.required = true;
        
        // Update password hint text
        const passwordHint = document.querySelector('.password-hint');
        if (passwordHint) {
            passwordHint.textContent = '';
        }
    }
    
    // Update all translatable elements after toggle
    updateUserManagementLanguage(lang);
}

// Validate form before submission
function validateUserForm() {
    const name = document.getElementById('name').value.trim();
    const email = document.getElementById('email').value.trim();
    const department = document.getElementById('department_id').value;
    const role = document.getElementById('role_id').value;
    const password = document.getElementById('password').value;
    const confirmPassword = document.getElementById('confirm_password').value;
    const isEditMode = document.querySelector('input[name="id"]') !== null;
    const lang = currentUserLang;
    
    if (name === '') {
        alert(user_translations[lang].nameRequired);
        document.getElementById('name').focus();
        return false;
    }
    
    if (email === '') {
        alert(user_translations[lang].emailRequired);
        document.getElementById('email').focus();
        return false;
    }
    
    const emailPattern = /^[^\s@]+@([^\s@]+\.)+[^\s@]+$/;
    if (!emailPattern.test(email)) {
        alert(user_translations[lang].emailInvalid);
        document.getElementById('email').focus();
        return false;
    }
    
    if (department === '') {
        alert(user_translations[lang].departmentRequired);
        document.getElementById('department_id').focus();
        return false;
    }
    
    if (role === '') {
        alert(user_translations[lang].roleRequired);
        document.getElementById('role_id').focus();
        return false;
    }
    
    // Password validation for new users or when password field is filled
    if (!isEditMode) {
        if (password === '') {
            alert(user_translations[lang].passwordRequired);
            document.getElementById('password').focus();
            return false;
        }
        if (password.length < 6) {
            alert(user_translations[lang].passwordLength);
            document.getElementById('password').focus();
            return false;
        }
        if (password !== confirmPassword) {
            alert(user_translations[lang].passwordMatch);
            document.getElementById('confirm_password').focus();
            return false;
        }
    } else if (password !== '') {
        if (password.length < 6) {
            alert(user_translations[lang].passwordLength);
            document.getElementById('password').focus();
            return false;
        }
        if (password !== confirmPassword) {
            alert(user_translations[lang].passwordMatch);
            document.getElementById('confirm_password').focus();
            return false;
        }
    }
    
    // File size validation
    const profileImage = document.getElementById('profile_image');
    if (profileImage.files.length > 0) {
        const file = profileImage.files[0];
        const fileSize = file.size;
        const maxSize = 2 * 1024 * 1024;
        
        if (fileSize > maxSize) {
            alert('Image must be less than 2MB');
            return false;
        }
        
        const allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
        const fileExt = file.name.split('.').pop().toLowerCase();
        if (!allowedExtensions.includes(fileExt)) {
            alert('Only JPG, PNG, and GIF files are allowed');
            return false;
        }
    }
    
    return true;
}

// Confirm delete with loading effect
function confirmDeleteUser(id, name) {
    const lang = currentUserLang;
    const confirmMsg = user_translations[lang].confirmDelete + ' "' + name + '"?\n' + 
                      user_translations[lang].confirmDeleteMsg;
    
    if (confirm(confirmMsg)) {
        const row = event.target.closest('tr');
        row.style.opacity = '0.5';
        
        const loadingDiv = document.createElement('div');
        loadingDiv.className = 'user-loading';
        loadingDiv.style.position = 'absolute';
        loadingDiv.style.top = '50%';
        loadingDiv.style.left = '50%';
        loadingDiv.style.transform = 'translate(-50%, -50%)';
        row.style.position = 'relative';
        row.appendChild(loadingDiv);
        
        setTimeout(() => {
            window.location.href = `?page=user-management&user_delete=${id}`;
        }, 300);
    }
}

// Document ready - NO SIDEBAR CODE (ISSUE #1 & #3)
document.addEventListener('DOMContentLoaded', function() {
    updateUserManagementLanguage(currentUserLang);
    
    // Set up department change listener
    const deptSelect = document.getElementById('department_id');
    if (deptSelect) {
        deptSelect.addEventListener('change', updateRoleDropdown);
    }
    
    // Set up profile image preview
    const imageInput = document.getElementById('profile_image');
    if (imageInput) {
        imageInput.addEventListener('change', function() {
            previewProfileImageUpload(this);
        });
    }
    
    // Auto-hide alerts after 5 seconds
    setTimeout(() => {
        document.querySelectorAll('.user-alert').forEach(alert => {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(() => alert.style.display = 'none', 500);
        });
    }, 5000);
    
    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closePreviewModal();
        }
    });
    
    // Close modal when clicking outside content
    const modal = document.getElementById('imagePreviewModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                closePreviewModal();
            }
        });
    }
});

// Make functions globally available
window.updateUserManagementLanguage = updateUserManagementLanguage;
window.updateRoleDropdown = updateRoleDropdown;
window.toggleUserForm = toggleUserForm;
window.validateUserForm = validateUserForm;
window.confirmDeleteUser = confirmDeleteUser;
window.previewProfileImage = previewProfileImage;
window.closePreviewModal = closePreviewModal;
</script>

<style>
    /* User Management Module Styles - All prefixed with .user- to avoid conflicts (ISSUE #3) */
    .user-container {
        width: 100%;
    }

    .user-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
        flex-wrap: wrap;
        gap: 16px;
    }

    .user-header h2 {
        color: var(--gray-800);
        font-size: 24px;
        font-weight: 600;
        margin: 0;
    }

    .user-btn {
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

    .user-btn:hover {
        background: var(--brown-800);
        transform: translateY(-2px);
        box-shadow: var(--shadow-md);
    }

    .user-btn-secondary {
        background: var(--gray-200);
        color: var(--gray-700);
    }

    .user-btn-secondary:hover {
        background: var(--gray-300);
    }

    .user-btn-danger {
        background: #dc3545;
    }

    .user-btn-danger:hover {
        background: #c82333;
    }

    .user-form {
        background: var(--gray-50);
        padding: 24px;
        border-radius: 12px;
        margin-bottom: 32px;
        border: 1px solid var(--gray-200);
        display: <?= $edit_mode ? 'block' : 'none' ?>;
    }

    .user-form.show {
        display: block;
    }

    .user-form h3 {
        color: var(--gray-800);
        font-size: 18px;
        font-weight: 600;
        margin-bottom: 20px;
    }

    .user-form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 20px;
        margin-bottom: 20px;
    }

    .user-form-group {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .user-form-group-full {
        grid-column: 1 / -1;
    }

    .user-form-group label {
        color: var(--gray-700);
        font-size: 14px;
        font-weight: 500;
    }

    .user-form-group label .required {
        color: #dc3545;
        margin-left: 4px;
    }

    .user-form-group input,
    .user-form-group select,
    .user-form-group textarea {
        padding: 10px 12px;
        border: 1px solid var(--gray-300);
        border-radius: 6px;
        font-size: 14px;
        transition: all 0.2s;
        font-family: inherit;
    }

    .user-form-group textarea {
        resize: vertical;
        min-height: 80px;
    }

    .user-form-group input:focus,
    .user-form-group select:focus,
    .user-form-group textarea:focus {
        outline: none;
        border-color: var(--brown-600);
        box-shadow: 0 0 0 3px rgba(123, 88, 63, 0.1);
    }

    .user-form-actions {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
        margin-top: 20px;
    }

    .user-alert {
        padding: 16px;
        border-radius: 8px;
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .user-alert-success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }

    .user-alert-danger {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }

    .user-alert-warning {
        background: #fff3cd;
        color: #856404;
        border: 1px solid #ffeeba;
    }

    .user-alert-close {
        background: none;
        border: none;
        font-size: 20px;
        cursor: pointer;
        color: inherit;
        opacity: 0.5;
    }

    .user-alert-close:hover {
        opacity: 1;
    }

    /* Search and Filter Bar */
    .user-search-bar {
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

    .user-search-group {
        flex: 1;
        min-width: 150px;
    }

    .user-search-group label {
        display: block;
        color: var(--gray-700);
        font-size: 13px;
        font-weight: 500;
        margin-bottom: 6px;
    }

    .user-search-group input,
    .user-search-group select {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid var(--gray-300);
        border-radius: 6px;
        font-size: 14px;
    }

    .user-search-group input:focus,
    .user-search-group select:focus {
        outline: none;
        border-color: var(--brown-600);
        box-shadow: 0 0 0 3px rgba(123, 88, 63, 0.1);
    }

    .user-search-actions {
        display: flex;
        gap: 8px;
    }

    .user-search-btn {
        padding: 10px 20px;
        background: var(--brown-700);
        color: white;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-weight: 500;
        transition: all 0.2s;
    }

    .user-search-btn:hover {
        background: var(--brown-800);
    }

    .user-clear-btn {
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

    .user-clear-btn:hover {
        background: var(--gray-300);
    }

    /* Stats Bar */
    .user-stats {
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

    .user-stats-info {
        display: flex;
        align-items: center;
        gap: 8px;
        color: var(--gray-700);
        font-size: 14px;
    }

    .user-stats-info i {
        color: var(--brown-600);
    }

    .user-stats-count {
        font-weight: 700;
        color: var(--brown-700);
        font-size: 18px;
    }

    /* Table Styles */
    .user-table-container {
        overflow-x: auto;
        background: white;
        border-radius: 12px;
        border: 1px solid var(--gray-200);
        margin-bottom: 20px;
    }

    .user-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 1000px;
    }

    .user-table th {
        background: var(--gray-50);
        color: var(--gray-700);
        font-weight: 600;
        font-size: 14px;
        padding: 16px;
        text-align: left;
        border-bottom: 2px solid var(--gray-200);
    }

    .user-table td {
        padding: 16px;
        border-bottom: 1px solid var(--gray-200);
        color: var(--gray-700);
        font-size: 14px;
        vertical-align: middle;
    }

    .user-table tr:hover {
        background: var(--gray-50);
    }

    /* Avatar Styles */
    .avatar-cell {
        text-align: center;
        width: 60px;
    }

    .user-avatar-img {
        width: 44px;
        height: 44px;
        border-radius: 50%;
        object-fit: cover;
        cursor: pointer;
        transition: transform 0.2s, box-shadow 0.2s;
        border: 2px solid transparent;
    }

    .user-avatar-img:hover {
        transform: scale(1.1);
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        border-color: var(--brown-600);
    }

    .user-avatar-initials {
        width: 44px;
        height: 44px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--brown-700), var(--brown-800));
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 18px;
        cursor: pointer;
        transition: transform 0.2s, box-shadow 0.2s;
        margin: 0 auto;
    }

    .user-avatar-initials:hover {
        transform: scale(1.1);
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }

    /* Image Preview Modal */
    .image-preview-modal {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.85);
        backdrop-filter: blur(8px);
        display: none;
        justify-content: center;
        align-items: center;
        z-index: 10000;
    }

    .image-preview-modal.active {
        display: flex;
        animation: modalFadeIn 0.2s ease-out;
    }

    @keyframes modalFadeIn {
        from {
            opacity: 0;
        }
        to {
            opacity: 1;
        }
    }

    .image-preview-content {
        max-width: 90%;
        max-height: 90%;
        background: white;
        border-radius: 20px;
        overflow: hidden;
        box-shadow: 0 25px 50px rgba(0,0,0,0.3);
        animation: modalContentScale 0.2s ease-out;
    }

    @keyframes modalContentScale {
        from {
            transform: scale(0.95);
            opacity: 0;
        }
        to {
            transform: scale(1);
            opacity: 1;
        }
    }

    .image-preview-header {
        padding: 16px 24px;
        background: linear-gradient(135deg, var(--brown-800), var(--brown-700));
        color: white;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .image-preview-header h3 {
        margin: 0;
        font-size: 18px;
        font-weight: 600;
    }

    .image-preview-close {
        background: none;
        border: none;
        color: white;
        font-size: 28px;
        cursor: pointer;
        padding: 0;
        width: 36px;
        height: 36px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        transition: all 0.2s;
    }

    .image-preview-close:hover {
        background: rgba(255,255,255,0.2);
        transform: rotate(90deg);
    }

    .image-preview-body {
        padding: 24px;
        text-align: center;
        background: #faf9f8;
    }

    .image-preview-body img {
        max-width: 100%;
        max-height: 70vh;
        border-radius: 12px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.2);
    }

    /* Edit Mode Current Image Display */
    .current-profile-image {
        display: flex;
        align-items: center;
        gap: 16px;
        padding: 12px 16px;
        background: var(--gray-100);
        border-radius: 12px;
        margin-top: 8px;
        border: 1px solid var(--gray-200);
    }

    .current-profile-image img {
        width: 60px;
        height: 60px;
        border-radius: 12px;
        object-fit: cover;
        border: 2px solid var(--brown-300);
        cursor: pointer;
    }

    .current-profile-image span {
        font-size: 13px;
        color: var(--gray-600);
    }

    /* Image Preview (before upload) */
    .image-preview {
        margin-top: 12px;
        max-width: 80px;
        display: none;
    }

    .image-preview img {
        width: 100%;
        border-radius: 8px;
        border: 2px solid var(--gray-200);
    }

    .file-name {
        font-size: 12px;
        color: var(--gray-500);
        margin-top: 5px;
    }

    /* Status Badge */
    .user-status {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 500;
    }

    .user-status-active {
        background: #d4edda;
        color: #155724;
    }

    .user-status-inactive {
        background: #f8d7da;
        color: #721c24;
    }

    /* Department and Role Badges */
    .user-department-badge,
    .user-role-badge {
        background: var(--gray-100);
        color: var(--gray-700);
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 500;
        display: inline-block;
    }

    .user-department-badge {
        background: var(--brown-100);
        color: var(--brown-800);
    }

    /* Action Buttons */
    .user-actions {
        display: flex;
        gap: 8px;
    }

    .user-action-btn {
        padding: 6px 12px;
        border-radius: 4px;
        font-size: 13px;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        transition: all 0.2s;
    }

    .user-action-edit {
        background: var(--brown-100);
        color: var(--brown-800);
    }

    .user-action-edit:hover {
        background: var(--brown-200);
    }

    .user-action-delete {
        background: #f8d7da;
        color: #721c24;
    }

    .user-action-delete:hover {
        background: #f5c6cb;
    }

    .disabled {
        opacity: 0.5;
        cursor: not-allowed;
        pointer-events: none;
    }

    /* Timestamp */
    .user-timestamp {
        font-size: 11px;
        color: var(--gray-500);
        margin-top: 4px;
    }

    /* Pagination */
    .user-pagination {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 12px;
        margin-top: 20px;
    }

    .user-pagination a,
    .user-pagination span {
        padding: 8px 16px;
        border-radius: 6px;
        text-decoration: none;
        font-size: 14px;
        font-weight: 500;
        transition: all 0.2s;
    }

    .user-pagination a {
        background: white;
        border: 1px solid var(--gray-300);
        color: var(--gray-700);
    }

    .user-pagination a:hover {
        background: var(--brown-100);
        border-color: var(--brown-600);
        color: var(--brown-800);
    }

    .user-pagination .active {
        background: var(--brown-700);
        border-color: var(--brown-700);
        color: white;
    }

    .user-pagination .disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    /* Loading Animation */
    .user-loading {
        display: inline-block;
        width: 20px;
        height: 20px;
        border: 3px solid var(--gray-200);
        border-top-color: var(--brown-700);
        border-radius: 50%;
        animation: user-spin 1s linear infinite;
    }

    @keyframes user-spin {
        to { transform: rotate(360deg); }
    }

    /* Empty State */
    .user-empty {
        text-align: center;
        padding: 48px !important;
        color: var(--gray-500);
    }

    .user-empty i {
        font-size: 48px;
        margin-bottom: 16px;
        color: var(--gray-400);
    }

    /* Password Hint */
    .password-hint {
        font-size: 11px;
        color: var(--gray-500);
        margin-top: 4px;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .user-search-bar {
            flex-direction: column;
        }
        
        .user-search-actions {
            width: 100%;
        }
        
        .user-search-btn,
        .user-clear-btn {
            flex: 1;
            text-align: center;
            justify-content: center;
        }
        
        .user-form-grid {
            grid-template-columns: 1fr;
        }
        
        .user-pagination {
            flex-wrap: wrap;
        }
        
        .avatar-cell {
            width: 50px;
        }
        
        .user-avatar-img,
        .user-avatar-initials {
            width: 36px;
            height: 36px;
            font-size: 14px;
        }
    }
</style>

<div class="user-container">
    <!-- Header -->
    <div class="user-header">
        <h2 data-user-lang="pageTitle">User Management</h2>
        <?php if (canAdd($conn, $user_role_id, $module_name)): ?>
        <button class="user-btn" onclick="toggleUserForm()" id="userToggleBtn">
            <i class="fas fa-plus"></i>
            <span data-user-lang="addNew">Add New User</span>
        </button>
        <?php endif; ?>
    </div>

    <!-- Alert Messages -->
    <?php if (!empty($user_message)): ?>
        <div class="user-alert user-alert-<?= $user_message_type ?>">
            <?= $user_message ?>
            <button class="user-alert-close" onclick="this.parentElement.style.display='none'">&times;</button>
        </div>
    <?php endif; ?>

    <!-- Add/Edit Form - Only show if user has add or edit permission -->
    <?php if (canAdd($conn, $user_role_id, $module_name) || ($edit_mode && canEdit($conn, $user_role_id, $module_name))): ?>
    <div class="user-form <?= $edit_mode ? 'show' : '' ?>" id="userForm">
        <h3 data-user-lang="<?= $edit_mode ? 'editUser' : 'addUser' ?>">
            <?= $edit_mode ? 'Edit User' : 'Add New User' ?>
        </h3>
        
        <form method="POST" action="?page=user-management" enctype="multipart/form-data" onsubmit="return validateUserForm()">
            <?php if ($edit_mode): ?>
                <input type="hidden" name="id" value="<?= $edit_data['id'] ?>">
            <?php endif; ?>
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            
            <div class="user-form-grid">
                <div class="user-form-group">
                    <label for="name" data-user-lang="name">Full Name <span class="required">*</span></label>
                    <input type="text" id="name" name="name" 
                           value="<?= $edit_mode ? htmlspecialchars($edit_data['name']) : '' ?>" 
                           data-user-lang="namePlaceholder" placeholder="Enter full name" required>
                </div>
                
                <div class="user-form-group">
                    <label for="email" data-user-lang="email">Email <span class="required">*</span></label>
                    <input type="email" id="email" name="email" 
                           value="<?= $edit_mode ? htmlspecialchars($edit_data['email']) : '' ?>" 
                           data-user-lang="emailPlaceholder" placeholder="Enter email address" required>
                </div>
                
                <div class="user-form-group">
                    <label for="phone" data-user-lang="phone">Phone</label>
                    <input type="tel" id="phone" name="phone" 
                           value="<?= $edit_mode ? htmlspecialchars($edit_data['phone']) : '' ?>" 
                           data-user-lang="phonePlaceholder" placeholder="Enter phone number">
                </div>
                
                <div class="user-form-group">
                    <label for="department_id" data-user-lang="department">Department <span class="required">*</span></label>
                    <select id="department_id" name="department_id" required>
                        <option value="" data-user-lang="selectDepartment">Select Department</option>
                        <?php foreach ($departments_dropdown as $dept): ?>
                            <option value="<?= $dept['id'] ?>" 
                                <?= ($edit_mode && $edit_data['department_id'] == $dept['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($dept['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="user-form-group">
                    <label for="role_id" data-user-lang="role">Role <span class="required">*</span></label>
                    <select id="role_id" name="role_id" required>
                        <option value="" data-user-lang="selectRole">Select Role</option>
                        <?php if ($edit_mode): ?>
                            <?php foreach ($all_roles as $role): ?>
                                <?php if ($role['department_id'] == $edit_data['department_id']): ?>
                                    <option value="<?= $role['id'] ?>" <?= ($edit_data['role_id'] == $role['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($role['name']) ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                
                <div class="user-form-group">
                    <label for="status" data-user-lang="status">Status <span class="required">*</span></label>
                    <select id="status" name="status" required>
                        <option value="Active" data-user-lang="active" <?= ($edit_mode && $edit_data['status'] == 'Active') ? 'selected' : '' ?>>Active</option>
                        <option value="Inactive" data-user-lang="inactive" <?= ($edit_mode && $edit_data['status'] == 'Inactive') ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                
                <div class="user-form-group user-form-group-full">
                    <label for="address" data-user-lang="address">Address</label>
                    <textarea id="address" name="address" data-user-lang="addressPlaceholder" placeholder="Enter address (optional)"><?= $edit_mode ? htmlspecialchars($edit_data['address']) : '' ?></textarea>
                </div>
                
                <div class="user-form-group">
                    <label for="profile_image" data-user-lang="profileImage">Profile Image</label>
                    <input type="file" id="profile_image" name="profile_image" accept="image/jpeg,image/png,image/gif">
                    <div class="file-name" id="file-name"></div>
                    <div class="image-preview" id="image-preview">
                        <img id="preview-img" src="#" alt="Preview">
                    </div>
                    
                    <!-- Display current image in edit mode -->
                    <?php if ($edit_mode && !empty($edit_data['profile_image'])): ?>
                        <div class="current-profile-image">
                            <?php 
                                $current_img_path = $edit_data['profile_image'];
                                if (!file_exists($current_img_path) && file_exists('../' . $current_img_path)) {
                                    $current_img_path = '../' . $current_img_path;
                                }
                            ?>
                            <img src="<?= htmlspecialchars($current_img_path) ?>" alt="Current Profile Image" 
                                 onclick="previewProfileImage('<?= htmlspecialchars($edit_data['profile_image']) ?>', '<?= htmlspecialchars($edit_data['name']) ?>')" style="cursor: pointer;">
                            <span data-user-lang="keepCurrent">Keep current image</span>
                        </div>
                    <?php endif; ?>
                    
                    <span class="password-hint" data-user-lang="imageHint">Optional. Max 2MB. Allowed: JPG, PNG, GIF</span>
                </div>
                
                <div class="user-form-group password-section">
                    <label for="password" data-user-lang="<?= $edit_mode ? 'password' : 'passwordPlaceholder' ?>">
                        <?= $edit_mode ? 'Password (leave blank to keep current)' : 'Password' ?>
                        <?php if (!$edit_mode): ?><span class="required">*</span><?php endif; ?>
                    </label>
                    <input type="password" id="password" name="password" 
                           data-user-lang="passwordPlaceholder" 
                           placeholder="<?= $edit_mode ? 'Enter new password (optional)' : 'Enter password (min. 6 characters)' ?>"
                           <?= !$edit_mode ? 'required' : '' ?>>
                    <?php if ($edit_mode): ?>
                        <span class="password-hint" data-user-lang="leavePasswordBlank">Leave blank to keep current password</span>
                    <?php endif; ?>
                </div>
                
                <div class="user-form-group password-section">
                    <label for="confirm_password" data-user-lang="confirmPasswordPlaceholder">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" 
                           data-user-lang="confirmPasswordPlaceholder" 
                           placeholder="Confirm password"
                           <?= !$edit_mode ? 'required' : '' ?>>
                </div>
            </div>
            
            <div class="user-form-actions">
                <button type="submit" name="<?= $edit_mode ? 'user_update' : 'user_add' ?>" class="user-btn">
                    <i class="fas fa-save"></i>
                    <span data-user-lang="save">Save</span>
                </button>
                <a href="?page=user-management" class="user-btn user-btn-secondary">
                    <i class="fas fa-times"></i>
                    <span data-user-lang="cancel">Cancel</span>
                </a>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- Search and Filter Bar -->
    <div class="user-search-bar">
        <form method="GET" action="?page=user-management" style="display: contents;">
            <input type="hidden" name="page" value="user-management">
            
            <div class="user-search-group">
                <label for="search" data-user-lang="search">Search</label>
                <input type="text" id="search" name="search" 
                       value="<?= htmlspecialchars($search) ?>" 
                       placeholder="Search by name or email">
            </div>
            
            <div class="user-search-group">
                <label for="status_filter" data-user-lang="filter">Status</label>
                <select id="status_filter" name="status_filter">
                    <option value="" data-user-lang="allStatus">All Status</option>
                    <option value="Active" <?= $status_filter == 'Active' ? 'selected' : '' ?> data-user-lang="active">Active</option>
                    <option value="Inactive" <?= $status_filter == 'Inactive' ? 'selected' : '' ?> data-user-lang="inactive">Inactive</option>
                </select>
            </div>
            
            <div class="user-search-group">
                <label for="department_filter" data-user-lang="department">Department</label>
                <select id="department_filter" name="department_filter">
                    <option value="" data-user-lang="allDepartments">All Departments</option>
                    <?php foreach ($departments_dropdown as $dept): ?>
                        <option value="<?= $dept['id'] ?>" <?= $department_filter == $dept['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($dept['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="user-search-group">
                <label for="role_filter" data-user-lang="role">Role</label>
                <select id="role_filter" name="role_filter">
                    <option value="" data-user-lang="allRoles">All Roles</option>
                    <?php foreach ($all_roles as $role): ?>
                        <option value="<?= $role['id'] ?>" <?= $role_filter == $role['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($role['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="user-search-actions">
                <button type="submit" class="user-search-btn">
                    <i class="fas fa-search"></i> <span data-user-lang="search">Search</span>
                </button>
                <a href="?page=user-management" class="user-clear-btn">
                    <i class="fas fa-times"></i> <span data-user-lang="clear">Clear</span>
                </a>
            </div>
        </form>
    </div>

    <!-- Stats Bar -->
    <div class="user-stats">
        <div class="user-stats-info">
            <i class="fas fa-users"></i>
            <span id="totalRecords" data-user-lang="totalRecords">Total Users</span>
            <span>:</span>
            <span class="user-stats-count"><?= $total_records ?></span>
            <span data-user-lang="records">records</span>
        </div>
    </div>

    <!-- Data Table -->
    <div class="user-table-container">
        <table class="user-table">
            <thead>
                <tr>
                    <th data-user-lang="id">ID</th>
                    <th data-user-lang="avatar">Avatar</th>
                    <th data-user-lang="name">Full Name</th>
                    <th data-user-lang="email">Email</th>
                    <th data-user-lang="department">Department</th>
                    <th data-user-lang="role">Role</th>
                    <th data-user-lang="status">Status</th>
                    <th data-user-lang="created">Created</th>
                    <th data-user-lang="actions">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($users_result && $users_result->num_rows > 0): ?>
                    <?php while ($row = $users_result->fetch_assoc()): ?>
                        <tr>
                            <td>#<?= $row['id'] ?></td>
                            <td class="avatar-cell">
                                <?php 
                                    $profile_img = $row['profile_image'];
                                    $img_exists = !empty($profile_img) && file_exists($profile_img);
                                    if (!$img_exists && !empty($profile_img) && file_exists('../' . $profile_img)) {
                                        $profile_img = '../' . $profile_img;
                                        $img_exists = true;
                                    }
                                ?>
                                <?php if ($img_exists): ?>
                                    <img src="<?= htmlspecialchars($profile_img) ?>" 
                                         class="user-avatar-img" 
                                         alt="<?= htmlspecialchars($row['name']) ?>"
                                         onclick="previewProfileImage('<?= htmlspecialchars($row['profile_image']) ?>', '<?= htmlspecialchars(addslashes($row['name'])) ?>')">
                                <?php else: ?>
                                    <div class="user-avatar-initials" 
                                         onclick="previewProfileImage(null, '<?= htmlspecialchars(addslashes($row['name'])) ?>')">
                                        <?= strtoupper(substr($row['name'], 0, 1)) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><strong><?= htmlspecialchars($row['name']) ?></strong></td>
                            <td><?= htmlspecialchars($row['email']) ?></td>
                            <td>
                                <span class="user-department-badge">
                                    <?= htmlspecialchars($row['department_name'] ?? 'N/A') ?>
                                </span>
                            </td>
                            <td>
                                <span class="user-role-badge">
                                    <?= htmlspecialchars($row['role_name'] ?? 'N/A') ?>
                                </span>
                            </td>
                            <td>
                                <span class="user-status user-status-<?= strtolower($row['status']) ?>">
                                    <?= $row['status'] ?>
                                </span>
                            </td>
                            <td>
                                <?= date('d M Y', strtotime($row['created_at'])) ?>
                                <div class="user-timestamp"><?= date('H:i', strtotime($row['created_at'])) ?></div>
                            </td>
                            <td>
                                <div class="user-actions">
                                    <?php if ($row['id'] != $current_user_id && $row['id'] != 1 && canEdit($conn, $user_role_id, $module_name)): ?>
                                        <a href="?page=user-management&user_edit=<?= $row['id'] ?>" 
                                           class="user-action-btn user-action-edit">
                                            <i class="fas fa-edit"></i> <span data-user-lang="edit">Edit</span>
                                        </a>
                                    <?php endif; ?>
                                    
                                    <?php if ($row['id'] != $current_user_id && $row['id'] != 1 && canDelete($conn, $user_role_id, $module_name)): ?>
                                        <a href="javascript:void(0)" 
                                           onclick="confirmDeleteUser(<?= $row['id'] ?>, '<?= htmlspecialchars(addslashes($row['name'])) ?>')" 
                                           class="user-action-btn user-action-delete">
                                            <i class="fas fa-trash"></i> <span data-user-lang="delete">Delete</span>
                                        </a>
                                    <?php endif; ?>
                                    
                                    <?php if ($row['id'] == 1): ?>
                                        <span class="user-action-btn disabled" style="opacity: 0.5; cursor: not-allowed;" title="Default admin account">
                                            <i class="fas fa-lock"></i> Protected
                                        </span>
                                    <?php endif; ?>
                                    
                                    <?php if ($row['id'] == $current_user_id && $row['id'] != 1): ?>
                                        <span class="user-action-btn disabled" style="opacity: 0.5; cursor: not-allowed;" title="Cannot modify own account here">
                                            <i class="fas fa-user"></i> Current
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="9" class="user-empty">
                            <i class="fas fa-users"></i>
                            <p data-user-lang="noData">No users found.</p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="user-pagination">
        <?php if ($current_page > 1): ?>
            <a href="?page=user-management&user_page=<?= $current_page - 1 ?>&search=<?= urlencode($search) ?>&status_filter=<?= urlencode($status_filter) ?>&department_filter=<?= $department_filter ?>&role_filter=<?= $role_filter ?>" class="user-prev-btn">
                <i class="fas fa-chevron-left"></i> <span data-user-lang="previous">Previous</span>
            </a>
        <?php else: ?>
            <span class="disabled"><i class="fas fa-chevron-left"></i> <span data-user-lang="previous">Previous</span></span>
        <?php endif; ?>
        
        <span><span data-user-lang="page">Page</span> <?= $current_page ?> <span data-user-lang="of">of</span> <?= $total_pages ?></span>
        
        <?php if ($current_page < $total_pages): ?>
            <a href="?page=user-management&user_page=<?= $current_page + 1 ?>&search=<?= urlencode($search) ?>&status_filter=<?= urlencode($status_filter) ?>&department_filter=<?= $department_filter ?>&role_filter=<?= $role_filter ?>" class="user-next-btn">
                <span data-user-lang="next">Next</span> <i class="fas fa-chevron-right"></i>
            </a>
        <?php else: ?>
            <span class="disabled"><span data-user-lang="next">Next</span> <i class="fas fa-chevron-right"></i></span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Image Preview Modal -->
<div id="imagePreviewModal" class="image-preview-modal">
    <div class="image-preview-content">
        <div class="image-preview-header">
            <h3 id="previewModalTitle" data-user-lang="previewTitle">Profile Image</h3>
            <button class="image-preview-close" onclick="closePreviewModal()" aria-label="Close">&times;</button>
        </div>
        <div class="image-preview-body">
            <img id="previewModalImg" src="#" alt="Profile Image Preview">
        </div>
    </div>
</div>

<script>
// Ensure role dropdown is properly initialized
document.addEventListener('DOMContentLoaded', function() {
    // If in edit mode, make sure role dropdown is properly populated
    <?php if ($edit_mode): ?>
    setTimeout(function() {
        updateRoleDropdown();
    }, 100);
    <?php endif; ?>
});
</script>