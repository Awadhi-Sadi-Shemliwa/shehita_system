<?php
/**
 * PAPLONTECH Enterprise Management System
 * Profile Module - User profile management for logged-in users
 * 
 * This module handles:
 * - View current user profile information
 * - Update profile details (name, email, phone, address)
 * - Change profile image (upload, preview, delete)
 * - Change password with current password verification
 * - Update security question and answer
 * - Full English/Swahili translation support
 * - CSRF protection for all forms
 * 
 * SECURITY: Users can only edit their OWN profile
 * PERMISSION: All logged-in users can access this module
 */

// Prevent direct access
if (!defined('ACCESS_ALLOWED') && basename($_SERVER['PHP_SELF']) == basename(__FILE__)) {
    // This file is included, so we're safe
}

// Get database connection from parent
global $conn;

// ============================================================================
// FOREIGN KEY TABLE ERROR HANDLING
// Check if required tables exist before proceeding
// ============================================================================
$missing_tables = [];

$check_users = $conn->query("SHOW TABLES LIKE 'users'");
if ($check_users->num_rows == 0) {
    $missing_tables[] = ['table' => 'users', 'module' => null, 'display' => 'Users (auto-created by config)'];
}

$check_departments = $conn->query("SHOW TABLES LIKE 'departments'");
if ($check_departments->num_rows == 0) {
    $missing_tables[] = ['table' => 'departments', 'module' => 'departments', 'display' => 'Departments'];
}

$check_roles = $conn->query("SHOW TABLES LIKE 'roles'");
if ($check_roles->num_rows == 0) {
    $missing_tables[] = ['table' => 'roles', 'module' => 'roles', 'display' => 'Roles'];
}

if (!empty($missing_tables)) {
    echo '<div class="profile-alert profile-alert-danger" style="background: #fff3cd; color: #856404; border-color: #ffeeba; text-align: left;">';
    echo '<i class="fas fa-exclamation-triangle" style="font-size: 24px; margin-right: 12px;"></i>';
    echo '<strong>⚠️ Required Tables Missing!</strong><br><br>';
    echo '<p>The following required tables do not exist in the database. Please open the related modules first:</p>';
    echo '<ul style="margin-top: 12px; margin-left: 20px;">';
    foreach ($missing_tables as $missing) {
        if ($missing['module']) {
            echo '<li><strong>' . htmlspecialchars($missing['table']) . '</strong> → Please open the <strong>' . htmlspecialchars($missing['display']) . '</strong> module first</li>';
        } else {
            echo '<li><strong>' . htmlspecialchars($missing['table']) . '</strong> → Please ensure the database is properly initialized (run config.php or re-login)</li>';
        }
    }
    echo '</ul>';
    echo '<p style="margin-top: 16px;">After opening the required modules, refresh this page to continue.</p>';
    echo '</div>';
    return; // Stop rendering the module
}

// PERMISSION: Get user role and check module permissions
$user_role_id = $_SESSION['role_id'] ?? 0;
$module_name = 'profile';

// PERMISSION: Check if user has view permission
if (!canView($conn, $user_role_id, $module_name)) {
    echo '<div class="alert alert-danger" style="text-align: center; padding: 40px;">
            <i class="fas fa-lock" style="font-size: 48px; margin-bottom: 16px; display: block;"></i>
            <h3>Access Denied</h3>
            <p>You do not have permission to view this module.</p>
          </div>';
    return; // Stop rendering the module
}

// Get current logged-in user ID
$current_user_id = $_SESSION['user_id'] ?? 0;

if ($current_user_id <= 0) {
    echo '<div class="alert alert-danger" style="text-align: center; padding: 40px;">
            <i class="fas fa-exclamation-triangle" style="font-size: 48px; margin-bottom: 16px; display: block;"></i>
            <h3>Session Error</h3>
            <p>User session not found. Please log in again.</p>
          </div>';
    return;
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
 * CSRF PROTECTION
 * ============================================================================
 */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * ============================================================================
 * VARIABLE INITIALIZATION
 * ============================================================================
 */
$profile_message = '';
$profile_message_type = '';
$active_tab = isset($_GET['tab']) ? sanitize($conn, $_GET['tab']) : 'profile';

// Valid tabs
$valid_tabs = ['profile', 'security'];
if (!in_array($active_tab, $valid_tabs)) {
    $active_tab = 'profile';
}

/**
 * ============================================================================
 * HELPER FUNCTION: Delete old profile image file from server
 * Uses both absolute and relative path checks
 * ============================================================================
 */
function deleteProfileImageFile($relative_path) {
    if (empty($relative_path)) {
        return false;
    }
    
    // Try multiple path variations
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
 * HELPER FUNCTION: Get user data by ID
 * ============================================================================
 */
function getUserById($conn, $user_id) {
    $stmt = $conn->prepare("SELECT u.*, d.name as department_name, r.name as role_name 
                            FROM users u 
                            LEFT JOIN departments d ON u.department_id = d.id 
                            LEFT JOIN roles r ON u.role_id = r.id 
                            WHERE u.id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    return $user;
}

/**
 * ============================================================================
 * HELPER FUNCTION: Handle profile image upload (deletes old image on success)
 * ============================================================================
 */
function handleProfileImageUpload($existing_image = null) {
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
                    deleteProfileImageFile($existing_image);
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
 * HANDLE FORM SUBMISSIONS
 * ============================================================================
 */

// ==================== PROFILE UPDATE ====================
if (isset($_POST['update_profile'])) {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $profile_message = "Invalid form submission. Please try again.";
        $profile_message_type = "danger";
    } else {
        // Get form data
        $name = sanitize($conn, $_POST['name']);
        $email = sanitize($conn, $_POST['email']);
        $phone = sanitize($conn, $_POST['phone']);
        $address = sanitize($conn, $_POST['address']);
        
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
        
        // Phone validation (optional)
        if (!empty($phone) && !preg_match('/^[0-9+\-\s]{10,20}$/', $phone)) {
            $errors[] = "Please enter a valid phone number (10-20 digits, +, -, space allowed)";
        }
        
        // Check if email already exists (excluding current user)
        if (empty($errors)) {
            $email_check = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $email_check->bind_param("si", $email, $current_user_id);
            $email_check->execute();
            $email_result = $email_check->get_result();
            if ($email_result->num_rows > 0) {
                $errors[] = "Email address is already registered to another user";
            }
            $email_check->close();
        }
        
        // Handle profile image upload
        if (empty($errors)) {
            // Get current user's profile image
            $current_user = getUserById($conn, $current_user_id);
            $existing_image = $current_user['profile_image'] ?? null;
            
            $upload_result = handleProfileImageUpload($existing_image);
            if (isset($upload_result['error'])) {
                $errors[] = $upload_result['error'];
            }
            $profile_image_path = $upload_result['path'];
        }
        
        // Update user if no errors
        if (empty($errors)) {
            if (isset($profile_image_path)) {
                $update_stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, phone = ?, address = ?, profile_image = ? WHERE id = ?");
                $update_stmt->bind_param("sssssi", $name, $email, $phone, $address, $profile_image_path, $current_user_id);
            } else {
                $update_stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, phone = ?, address = ? WHERE id = ?");
                $update_stmt->bind_param("ssssi", $name, $email, $phone, $address, $current_user_id);
            }
            
            if ($update_stmt->execute()) {
                // Update session variables
                $_SESSION['name'] = $name;
                $_SESSION['email'] = $email;
                
                $profile_message = "Profile updated successfully!";
                $profile_message_type = "success";
            } else {
                $profile_message = "Error updating profile: " . $conn->error;
                $profile_message_type = "danger";
            }
            $update_stmt->close();
        } else {
            $profile_message = implode("<br>", $errors);
            $profile_message_type = "danger";
        }
    }
    
    // Generate new CSRF token
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ==================== DELETE PROFILE IMAGE ====================
if (isset($_POST['delete_image'])) {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $profile_message = "Invalid form submission. Please try again.";
        $profile_message_type = "danger";
    } else {
        // Get current user's profile image
        $current_user = getUserById($conn, $current_user_id);
        $existing_image = $current_user['profile_image'] ?? null;
        
        if ($existing_image) {
            // Delete the file from server
            $deleted = deleteProfileImageFile($existing_image);
            
            $update_stmt = $conn->prepare("UPDATE users SET profile_image = NULL WHERE id = ?");
            $update_stmt->bind_param("i", $current_user_id);
            
            if ($update_stmt->execute()) {
                $profile_message = $deleted ? "Profile image deleted successfully!" : "Profile image removed from database (file may have been missing).";
                $profile_message_type = "success";
            } else {
                $profile_message = "Error deleting profile image: " . $conn->error;
                $profile_message_type = "danger";
            }
            $update_stmt->close();
        } else {
            $profile_message = "No profile image to delete.";
            $profile_message_type = "warning";
        }
    }
    
    // Generate new CSRF token
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ==================== PASSWORD CHANGE ====================
if (isset($_POST['change_password'])) {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $profile_message = "Invalid form submission. Please try again.";
        $profile_message_type = "danger";
    } else {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        $errors = [];
        
        // Get current user's hashed password
        $password_query = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $password_query->bind_param("i", $current_user_id);
        $password_query->execute();
        $password_result = $password_query->get_result();
        $user_data = $password_result->fetch_assoc();
        $password_query->close();
        
        // Verify current password
        if (empty($current_password)) {
            $errors[] = "Current password is required";
        } elseif (!password_verify($current_password, $user_data['password'])) {
            $errors[] = "Current password is incorrect";
        }
        
        // Validate new password
        if (empty($new_password)) {
            $errors[] = "New password is required";
        } elseif (strlen($new_password) < 6) {
            $errors[] = "New password must be at least 6 characters";
        }
        
        // Check if passwords match
        if ($new_password !== $confirm_password) {
            $errors[] = "New passwords do not match";
        }
        
        // Check if new password is different from current
        if (empty($errors) && password_verify($new_password, $user_data['password'])) {
            $errors[] = "New password must be different from your current password";
        }
        
        if (empty($errors)) {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $update_stmt->bind_param("si", $hashed_password, $current_user_id);
            
            if ($update_stmt->execute()) {
                $profile_message = "Password changed successfully!";
                $profile_message_type = "success";
            } else {
                $profile_message = "Error changing password: " . $conn->error;
                $profile_message_type = "danger";
            }
            $update_stmt->close();
        } else {
            $profile_message = implode("<br>", $errors);
            $profile_message_type = "danger";
        }
    }
    
    // Generate new CSRF token
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ==================== SECURITY QUESTION UPDATE ====================
if (isset($_POST['update_security'])) {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $profile_message = "Invalid form submission. Please try again.";
        $profile_message_type = "danger";
    } else {
        $security_question = sanitize($conn, $_POST['security_question']);
        $security_answer = $_POST['security_answer'];
        
        $errors = [];
        
        if (empty($security_question)) {
            $errors[] = "Security question is required";
        }
        
        if (empty($security_answer)) {
            $errors[] = "Security answer is required";
        } elseif (strlen($security_answer) < 2) {
            $errors[] = "Security answer must be at least 2 characters";
        }
        
        if (empty($errors)) {
            // Hash the security answer for storage
            $hashed_answer = password_hash($security_answer, PASSWORD_DEFAULT);
            
            $update_stmt = $conn->prepare("UPDATE users SET security_question = ?, security_answer = ? WHERE id = ?");
            $update_stmt->bind_param("ssi", $security_question, $hashed_answer, $current_user_id);
            
            if ($update_stmt->execute()) {
                $profile_message = "Security question updated successfully!";
                $profile_message_type = "success";
            } else {
                $profile_message = "Error updating security question: " . $conn->error;
                $profile_message_type = "danger";
            }
            $update_stmt->close();
        } else {
            $profile_message = implode("<br>", $errors);
            $profile_message_type = "danger";
        }
    }
    
    // Generate new CSRF token
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * ============================================================================
 * FETCH CURRENT USER DATA
 * ============================================================================
 */
$user = getUserById($conn, $current_user_id);

if (!$user) {
    echo '<div class="alert alert-danger" style="text-align: center; padding: 40px;">
            <i class="fas fa-exclamation-triangle" style="font-size: 48px; margin-bottom: 16px; display: block;"></i>
            <h3>User Not Found</h3>
            <p>Unable to load user profile. Please contact support.</p>
          </div>';
    return;
}

// Get user's role name and department name
$role_name = getUserRoleName($conn, $user['role_id']);
$department_name = getDepartmentName($conn, $user['department_id']);

// Predefined security questions for dropdown
$security_questions = [
    "What is your mother's maiden name?",
    "What was the name of your first pet?",
    "What was the name of your first school?",
    "What is your favorite book?",
    "What is your favorite movie?",
    "What city were you born in?",
    "What is your father's middle name?",
    "What was the make of your first car?"
];
?>

<!-- PROFILE MODULE TRANSLATIONS -->
<script>
// Profile translations for English and Swahili
const profile_translations = {
    en: {
        pageTitle: 'My Profile',
        profileTab: 'Profile Information',
        securityTab: 'Security Settings',
        personalInfo: 'Personal Information',
        accountInfo: 'Account Information',
        changePassword: 'Change Password',
        securityQuestion: 'Security Question',
        fullName: 'Full Name',
        emailAddress: 'Email Address',
        phoneNumber: 'Phone Number',
        address: 'Address',
        department: 'Department',
        role: 'Role',
        status: 'Status',
        active: 'Active',
        inactive: 'Inactive',
        memberSince: 'Member Since',
        lastUpdated: 'Last Updated',
        profileImage: 'Profile Image',
        changeImage: 'Change Image',
        deleteImage: 'Delete Image',
        currentImage: 'Current Image',
        noImage: 'No profile image',
        imageHint: 'Max 2MB. Allowed: JPG, PNG, GIF',
        chooseFile: 'Choose File',
        updateProfile: 'Update Profile',
        currentPassword: 'Current Password',
        newPassword: 'New Password',
        confirmNewPassword: 'Confirm New Password',
        confirmPasswordHint: 'Minimum 6 characters',
        changePasswordBtn: 'Change Password',
        selectQuestion: 'Select a security question',
        securityAnswer: 'Your Answer',
        securityAnswerHint: 'This will be used to verify your identity if you forget your password',
        updateSecurity: 'Update Security Question',
        save: 'Save Changes',
        cancel: 'Cancel',
        previewTitle: 'Profile Image',
        close: 'Close',
        imagePreview: 'Image Preview',
        edit: 'Edit',
        view: 'View',
        loading: 'Loading...',
        confirmDeleteImage: 'Are you sure you want to delete your profile image?',
        required: 'Required',
        optional: 'Optional',
        passwordMismatch: 'Passwords do not match',
        passwordTooShort: 'Password must be at least 6 characters',
        invalidEmail: 'Please enter a valid email address',
        nameRequired: 'Full name is required',
        currentPasswordRequired: 'Current password is required',
        newPasswordRequired: 'New password is required',
        securityQuestionRequired: 'Please select a security question',
        securityAnswerRequired: 'Please provide an answer to your security question'
    },
    sw: {
        pageTitle: 'Wasifu Wangu',
        profileTab: 'Taarifa za Wasifu',
        securityTab: 'Mipangilio ya Usalama',
        personalInfo: 'Taarifa Binafsi',
        accountInfo: 'Taarifa za Akaunti',
        changePassword: 'Badilisha Nenosiri',
        securityQuestion: 'Swali la Usalama',
        fullName: 'Jina Kamili',
        emailAddress: 'Barua pepe',
        phoneNumber: 'Namba ya Simu',
        address: 'Anwani',
        department: 'Idara',
        role: 'Jukumu',
        status: 'Hali',
        active: 'Inatumika',
        inactive: 'Haifanyi Kazi',
        memberSince: 'Imejiunga Tangu',
        lastUpdated: 'Ilisasishwa Mwisho',
        profileImage: 'Picha ya Wasifu',
        changeImage: 'Badilisha Picha',
        deleteImage: 'Futa Picha',
        currentImage: 'Picha ya Sasa',
        noImage: 'Hakuna picha ya wasifu',
        imageHint: 'Upeo 2MB. Kuruhusiwa: JPG, PNG, GIF',
        chooseFile: 'Chagua Faili',
        updateProfile: 'Sasisha Wasifu',
        currentPassword: 'Nenosiri la Sasa',
        newPassword: 'Nenosiri Jipya',
        confirmNewPassword: 'Thibitisha Nenosiri Jipya',
        confirmPasswordHint: 'Angalau herufi 6',
        changePasswordBtn: 'Badilisha Nenosiri',
        selectQuestion: 'Chagua swali la usalama',
        securityAnswer: 'Jibu Lako',
        securityAnswerHint: 'Hili litatumika kuthibitisha utambulisho wako ukisahau nenosiri',
        updateSecurity: 'Sasisha Swali la Usalama',
        save: 'Hifadhi Mabadiliko',
        cancel: 'Ghairi',
        previewTitle: 'Picha ya Wasifu',
        close: 'Funga',
        imagePreview: 'Onesho la Picha',
        edit: 'Hariri',
        view: 'Tazama',
        loading: 'Inapakia...',
        confirmDeleteImage: 'Una uhakika unataka kufuta picha yako ya wasifu?',
        required: 'Inahitajika',
        optional: 'Si lazima',
        passwordMismatch: 'Manenosiri hayalingani',
        passwordTooShort: 'Nenosiri lazima liwe na angalau herufi 6',
        invalidEmail: 'Tafadhali ingiza barua pepe halali',
        nameRequired: 'Jina kamili linahitajika',
        currentPasswordRequired: 'Nenosiri la sasa linahitajika',
        newPasswordRequired: 'Nenosiri jipya linahitajika',
        securityQuestionRequired: 'Tafadhali chagua swali la usalama',
        securityAnswerRequired: 'Tafadhali toa jibu kwa swali lako la usalama'
    }
};

// Current language
let currentProfileLang = localStorage.getItem('preferredLanguage') || 'en';

// Function to update all translatable elements
function updateProfileLanguage(lang) {
    currentProfileLang = lang;
    const elements = document.querySelectorAll('[data-profile-lang]');
    
    elements.forEach(element => {
        const key = element.getAttribute('data-profile-lang');
        if (profile_translations[lang] && profile_translations[lang][key]) {
            if (element.tagName === 'INPUT' || element.tagName === 'TEXTAREA') {
                if (element.getAttribute('type') !== 'submit' && element.getAttribute('type') !== 'button') {
                    element.placeholder = profile_translations[lang][key];
                } else {
                    element.textContent = profile_translations[lang][key];
                }
            } else if (element.tagName === 'OPTION') {
                element.textContent = profile_translations[lang][key];
            } else if (element.tagName === 'BUTTON') {
                // Handle button text
                if (!element.classList.contains('no-translate')) {
                    const span = element.querySelector('span');
                    if (span) {
                        span.textContent = profile_translations[lang][key];
                    } else if (element.textContent.trim() !== '') {
                        element.textContent = profile_translations[lang][key];
                    }
                }
            } else {
                element.textContent = profile_translations[lang][key];
            }
        }
    });
    
    // Update tab buttons
    const profileTabBtn = document.getElementById('tabProfileBtn');
    const securityTabBtn = document.getElementById('tabSecurityBtn');
    if (profileTabBtn) profileTabBtn.textContent = profile_translations[lang].profileTab;
    if (securityTabBtn) securityTabBtn.textContent = profile_translations[lang].securityTab;
    
    // Update modal title
    const modalTitle = document.getElementById('previewModalTitle');
    if (modalTitle) modalTitle.textContent = profile_translations[lang].previewTitle;
}

// Preview profile image in modal
function previewProfileImage(imagePath, userName) {
    const modal = document.getElementById('imagePreviewModal');
    const modalImg = document.getElementById('previewModalImg');
    const modalTitle = document.getElementById('previewModalTitle');
    const lang = currentProfileLang;
    
    if (imagePath && imagePath !== '' && imagePath !== 'null') {
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
    
    modalTitle.textContent = userName ? userName : profile_translations[lang].previewTitle;
    modal.classList.add('active');
}

function closePreviewModal() {
    document.getElementById('imagePreviewModal').classList.remove('active');
    const modalImg = document.getElementById('previewModalImg');
    if (modalImg) {
        modalImg.src = '#';
    }
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

// Confirm delete image
function confirmDeleteImage() {
    const lang = currentProfileLang;
    if (confirm(profile_translations[lang].confirmDeleteImage)) {
        return true;
    }
    return false;
}

// Switch tabs
function switchProfileTab(tab) {
    const profileTab = document.getElementById('profileTab');
    const securityTab = document.getElementById('securityTab');
    const profileBtn = document.getElementById('tabProfileBtn');
    const securityBtn = document.getElementById('tabSecurityBtn');
    const lang = currentProfileLang;
    
    if (tab === 'profile') {
        profileTab.style.display = 'block';
        securityTab.style.display = 'none';
        profileBtn.classList.add('active');
        securityBtn.classList.remove('active');
    } else {
        profileTab.style.display = 'none';
        securityTab.style.display = 'block';
        profileBtn.classList.remove('active');
        securityBtn.classList.add('active');
    }
    
    // Update URL without reload
    const url = new URL(window.location.href);
    url.searchParams.set('tab', tab);
    window.history.pushState({}, '', url);
}

// Validate profile form
function validateProfileForm() {
    const name = document.getElementById('profile_name').value.trim();
    const email = document.getElementById('profile_email').value.trim();
    const lang = currentProfileLang;
    
    if (name === '') {
        alert(profile_translations[lang].nameRequired);
        document.getElementById('profile_name').focus();
        return false;
    }
    
    if (email === '') {
        alert(profile_translations[lang].emailRequired);
        document.getElementById('profile_email').focus();
        return false;
    }
    
    const emailPattern = /^[^\s@]+@([^\s@]+\.)+[^\s@]+$/;
    if (!emailPattern.test(email)) {
        alert(profile_translations[lang].invalidEmail);
        document.getElementById('profile_email').focus();
        return false;
    }
    
    // File size validation
    const profileImage = document.getElementById('profile_image');
    if (profileImage && profileImage.files.length > 0) {
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

// Validate password form
function validatePasswordForm() {
    const currentPassword = document.getElementById('current_password').value;
    const newPassword = document.getElementById('new_password').value;
    const confirmPassword = document.getElementById('confirm_password').value;
    const lang = currentProfileLang;
    
    if (currentPassword === '') {
        alert(profile_translations[lang].currentPasswordRequired);
        document.getElementById('current_password').focus();
        return false;
    }
    
    if (newPassword === '') {
        alert(profile_translations[lang].newPasswordRequired);
        document.getElementById('new_password').focus();
        return false;
    }
    
    if (newPassword.length < 6) {
        alert(profile_translations[lang].passwordTooShort);
        document.getElementById('new_password').focus();
        return false;
    }
    
    if (newPassword !== confirmPassword) {
        alert(profile_translations[lang].passwordMismatch);
        document.getElementById('confirm_password').focus();
        return false;
    }
    
    return true;
}

// Validate security form
function validateSecurityForm() {
    const securityQuestion = document.getElementById('security_question').value;
    const securityAnswer = document.getElementById('security_answer').value.trim();
    const lang = currentProfileLang;
    
    if (securityQuestion === '') {
        alert(profile_translations[lang].securityQuestionRequired);
        document.getElementById('security_question').focus();
        return false;
    }
    
    if (securityAnswer === '') {
        alert(profile_translations[lang].securityAnswerRequired);
        document.getElementById('security_answer').focus();
        return false;
    }
    
    return true;
}

// Document ready
document.addEventListener('DOMContentLoaded', function() {
    updateProfileLanguage(currentProfileLang);
    
    // Set up profile image preview
    const imageInput = document.getElementById('profile_image');
    if (imageInput) {
        imageInput.addEventListener('change', function() {
            previewProfileImageUpload(this);
        });
    }
    
    // Auto-hide alerts after 5 seconds
    setTimeout(() => {
        document.querySelectorAll('.profile-alert').forEach(alert => {
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
    
    // Set active tab based on URL parameter
    const urlParams = new URLSearchParams(window.location.search);
    const tab = urlParams.get('tab');
    if (tab === 'security') {
        switchProfileTab('security');
    } else {
        switchProfileTab('profile');
    }
});

// Make functions globally available
window.updateProfileLanguage = updateProfileLanguage;
window.previewProfileImage = previewProfileImage;
window.closePreviewModal = closePreviewModal;
window.confirmDeleteImage = confirmDeleteImage;
window.switchProfileTab = switchProfileTab;
window.validateProfileForm = validateProfileForm;
window.validatePasswordForm = validatePasswordForm;
window.validateSecurityForm = validateSecurityForm;
</script>

<style>
    /* Profile Module Styles - All prefixed with .profile- to avoid conflicts */
    .profile-container {
        width: 100%;
        max-width: 1200px;
        margin: 0 auto;
    }

    /* Profile Header */
    .profile-header {
        display: flex;
        align-items: center;
        gap: 24px;
        background: white;
        padding: 24px;
        border-radius: 16px;
        margin-bottom: 24px;
        border: 1px solid var(--gray-200);
        flex-wrap: wrap;
    }

    .profile-avatar-large {
        position: relative;
    }

    .profile-avatar-img {
        width: 100px;
        height: 100px;
        border-radius: 50%;
        object-fit: cover;
        cursor: pointer;
        transition: transform 0.2s, box-shadow 0.2s;
        border: 3px solid var(--brown-600);
    }

    .profile-avatar-img:hover {
        transform: scale(1.05);
        box-shadow: 0 8px 20px rgba(0,0,0,0.15);
    }

    .profile-avatar-initials {
        width: 100px;
        height: 100px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--brown-700), var(--brown-800));
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 40px;
        cursor: pointer;
        transition: transform 0.2s, box-shadow 0.2s;
        border: 3px solid var(--brown-600);
    }

    .profile-avatar-initials:hover {
        transform: scale(1.05);
        box-shadow: 0 8px 20px rgba(0,0,0,0.15);
    }

    .profile-info h2 {
        font-size: 24px;
        font-weight: 700;
        color: var(--gray-800);
        margin: 0 0 8px 0;
    }

    .profile-info .profile-role {
        color: var(--brown-700);
        font-weight: 500;
        margin-bottom: 4px;
    }

    .profile-info .profile-dept {
        color: var(--gray-500);
        font-size: 14px;
    }

    /* Tabs */
    .profile-tabs {
        display: flex;
        gap: 8px;
        background: white;
        padding: 8px 16px;
        border-radius: 12px;
        margin-bottom: 24px;
        border: 1px solid var(--gray-200);
    }

    .profile-tab-btn {
        padding: 12px 24px;
        background: transparent;
        border: none;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 500;
        color: var(--gray-600);
        cursor: pointer;
        transition: all 0.2s;
    }

    .profile-tab-btn:hover {
        background: var(--gray-100);
        color: var(--brown-700);
    }

    .profile-tab-btn.active {
        background: var(--brown-700);
        color: white;
    }

    /* Profile Cards */
    .profile-card {
        background: white;
        border-radius: 16px;
        border: 1px solid var(--gray-200);
        overflow: hidden;
        margin-bottom: 24px;
    }

    .profile-card-header {
        padding: 20px 24px;
        border-bottom: 1px solid var(--gray-200);
        background: var(--gray-50);
    }

    .profile-card-header h3 {
        margin: 0;
        font-size: 18px;
        font-weight: 600;
        color: var(--gray-800);
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .profile-card-header h3 i {
        color: var(--brown-600);
    }

    .profile-card-body {
        padding: 24px;
    }

    /* Form Styles */
    .profile-form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 20px;
    }

    .profile-form-group {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .profile-form-group-full {
        grid-column: 1 / -1;
    }

    .profile-form-group label {
        color: var(--gray-700);
        font-size: 14px;
        font-weight: 500;
    }

    .profile-form-group label .required {
        color: #dc3545;
        margin-left: 4px;
    }

    .profile-form-group input,
    .profile-form-group select,
    .profile-form-group textarea {
        padding: 10px 12px;
        border: 1px solid var(--gray-300);
        border-radius: 6px;
        font-size: 14px;
        transition: all 0.2s;
        font-family: inherit;
    }

    .profile-form-group textarea {
        resize: vertical;
        min-height: 80px;
    }

    .profile-form-group input:focus,
    .profile-form-group select:focus,
    .profile-form-group textarea:focus {
        outline: none;
        border-color: var(--brown-600);
        box-shadow: 0 0 0 3px rgba(123, 88, 63, 0.1);
    }

    .profile-form-group input:disabled,
    .profile-form-group select:disabled {
        background-color: var(--gray-100);
        cursor: not-allowed;
    }

    /* Read-only info row */
    .profile-info-row {
        display: flex;
        padding: 12px 0;
        border-bottom: 1px solid var(--gray-200);
    }

    .profile-info-label {
        width: 140px;
        font-weight: 500;
        color: var(--gray-600);
    }

    .profile-info-value {
        flex: 1;
        color: var(--gray-800);
    }

    /* Image Upload */
    .current-profile-image {
        display: flex;
        align-items: center;
        gap: 16px;
        padding: 12px 16px;
        background: var(--gray-100);
        border-radius: 12px;
        margin-bottom: 16px;
        border: 1px solid var(--gray-200);
        flex-wrap: wrap;
    }

    .current-profile-image img {
        width: 60px;
        height: 60px;
        border-radius: 12px;
        object-fit: cover;
        cursor: pointer;
        border: 2px solid var(--brown-300);
    }

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

    .image-hint {
        font-size: 11px;
        color: var(--gray-500);
        margin-top: 4px;
    }

    /* Buttons */
    .profile-btn {
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

    .profile-btn:hover {
        background: var(--brown-800);
        transform: translateY(-2px);
        box-shadow: var(--shadow-md);
    }

    .profile-btn-secondary {
        background: var(--gray-200);
        color: var(--gray-700);
    }

    .profile-btn-secondary:hover {
        background: var(--gray-300);
    }

    .profile-btn-danger {
        background: #dc3545;
    }

    .profile-btn-danger:hover {
        background: #c82333;
    }

    .profile-form-actions {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
        margin-top: 24px;
    }

    /* Alerts */
    .profile-alert {
        padding: 16px;
        border-radius: 8px;
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .profile-alert-success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }

    .profile-alert-danger {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }

    .profile-alert-warning {
        background: #fff3cd;
        color: #856404;
        border: 1px solid #ffeeba;
    }

    .profile-alert-close {
        background: none;
        border: none;
        font-size: 20px;
        cursor: pointer;
        color: inherit;
        opacity: 0.5;
    }

    .profile-alert-close:hover {
        opacity: 1;
    }

    /* Status Badge */
    .profile-status {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 500;
    }

    .profile-status-active {
        background: #d4edda;
        color: #155724;
    }

    .profile-status-inactive {
        background: #f8d7da;
        color: #721c24;
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
        from { opacity: 0; }
        to { opacity: 1; }
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

    /* Responsive */
    @media (max-width: 768px) {
        .profile-header {
            flex-direction: column;
            text-align: center;
        }
        
        .profile-info-row {
            flex-direction: column;
            gap: 4px;
        }
        
        .profile-info-label {
            width: 100%;
        }
        
        .profile-form-grid {
            grid-template-columns: 1fr;
        }
        
        .profile-tabs {
            justify-content: center;
        }
        
        .profile-tab-btn {
            padding: 8px 16px;
            font-size: 13px;
        }
        
        .profile-form-actions {
            flex-direction: column;
        }
        
        .profile-form-actions button,
        .profile-form-actions a {
            width: 100%;
            justify-content: center;
        }
    }
</style>

<div class="profile-container">
    <!-- Alert Messages -->
    <?php if (!empty($profile_message)): ?>
        <div class="profile-alert profile-alert-<?= $profile_message_type ?>">
            <?= $profile_message ?>
            <button class="profile-alert-close" onclick="this.parentElement.style.display='none'">&times;</button>
        </div>
    <?php endif; ?>

    <!-- Profile Header -->
    <div class="profile-header">
        <div class="profile-avatar-large">
            <?php 
                $profile_img = $user['profile_image'];
                $img_exists = !empty($profile_img) && file_exists($profile_img);
                if (!$img_exists && !empty($profile_img) && file_exists('../' . $profile_img)) {
                    $profile_img = '../' . $profile_img;
                    $img_exists = true;
                }
            ?>
            <?php if ($img_exists): ?>
                <img src="<?= htmlspecialchars($profile_img) ?>" 
                     class="profile-avatar-img" 
                     alt="<?= htmlspecialchars($user['name']) ?>"
                     onclick="previewProfileImage('<?= htmlspecialchars($user['profile_image']) ?>', '<?= htmlspecialchars(addslashes($user['name'])) ?>')">
            <?php else: ?>
                <div class="profile-avatar-initials" 
                     onclick="previewProfileImage(null, '<?= htmlspecialchars(addslashes($user['name'])) ?>')">
                    <?= strtoupper(substr($user['name'], 0, 1)) ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="profile-info">
            <h2><?= htmlspecialchars($user['name']) ?></h2>
            <div class="profile-role"><?= htmlspecialchars($role_name) ?></div>
            <div class="profile-dept"><?= htmlspecialchars($department_name) ?></div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="profile-tabs">
        <button class="profile-tab-btn active" id="tabProfileBtn" onclick="switchProfileTab('profile')" data-profile-lang="profileTab">Profile Information</button>
        <button class="profile-tab-btn" id="tabSecurityBtn" onclick="switchProfileTab('security')" data-profile-lang="securityTab">Security Settings</button>
    </div>

    <!-- Profile Tab -->
    <div id="profileTab">
        <!-- Personal Information Card -->
        <div class="profile-card">
            <div class="profile-card-header">
                <h3><i class="fas fa-user-circle"></i> <span data-profile-lang="personalInfo">Personal Information</span></h3>
            </div>
            <div class="profile-card-body">
                <form method="POST" action="?page=profile" enctype="multipart/form-data" onsubmit="return validateProfileForm()">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    
                    <div class="profile-form-grid">
                        <div class="profile-form-group">
                            <label for="profile_name" data-profile-lang="fullName">Full Name <span class="required">*</span></label>
                            <input type="text" id="profile_name" name="name" 
                                   value="<?= htmlspecialchars($user['name']) ?>" 
                                   data-profile-lang="fullName" 
                                   placeholder="Enter full name" required>
                        </div>
                        
                        <div class="profile-form-group">
                            <label for="profile_email" data-profile-lang="emailAddress">Email Address <span class="required">*</span></label>
                            <input type="email" id="profile_email" name="email" 
                                   value="<?= htmlspecialchars($user['email']) ?>" 
                                   data-profile-lang="emailAddress" 
                                   placeholder="Enter email address" required>
                        </div>
                        
                        <div class="profile-form-group">
                            <label for="profile_phone" data-profile-lang="phoneNumber">Phone Number</label>
                            <input type="tel" id="profile_phone" name="phone" 
                                   value="<?= htmlspecialchars($user['phone']) ?>" 
                                   data-profile-lang="phoneNumber" 
                                   placeholder="Enter phone number">
                        </div>
                        
                        <div class="profile-form-group profile-form-group-full">
                            <label for="profile_address" data-profile-lang="address">Address</label>
                            <textarea id="profile_address" name="address" 
                                      data-profile-lang="address" 
                                      placeholder="Enter your address"><?= htmlspecialchars($user['address']) ?></textarea>
                        </div>
                        
                        <div class="profile-form-group">
                            <label for="profile_image" data-profile-lang="profileImage">Profile Image</label>
                            <input type="file" id="profile_image" name="profile_image" accept="image/jpeg,image/png,image/gif">
                            <div class="file-name" id="file-name"></div>
                            <div class="image-preview" id="image-preview">
                                <img id="preview-img" src="#" alt="Preview">
                            </div>
                            <div class="image-hint" data-profile-lang="imageHint">Max 2MB. Allowed: JPG, PNG, GIF</div>
                            
                            <?php if (!empty($user['profile_image'])): ?>
                                <div class="current-profile-image">
                                    <img src="<?= htmlspecialchars($profile_img) ?>" alt="Current Profile Image" 
                                         onclick="previewProfileImage('<?= htmlspecialchars($user['profile_image']) ?>', '<?= htmlspecialchars(addslashes($user['name'])) ?>')" 
                                         style="cursor: pointer;">
                                    <button type="submit" name="delete_image" class="profile-btn profile-btn-danger" style="padding: 8px 16px;" onclick="return confirmDeleteImage()">
                                        <i class="fas fa-trash"></i> <span data-profile-lang="deleteImage">Delete Image</span>
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="profile-form-actions">
                        <button type="submit" name="update_profile" class="profile-btn">
                            <i class="fas fa-save"></i> <span data-profile-lang="updateProfile">Update Profile</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Account Information Card (Read-only) -->
        <div class="profile-card">
            <div class="profile-card-header">
                <h3><i class="fas fa-building"></i> <span data-profile-lang="accountInfo">Account Information</span></h3>
            </div>
            <div class="profile-card-body">
                <div class="profile-info-row">
                    <div class="profile-info-label" data-profile-lang="department">Department:</div>
                    <div class="profile-info-value"><?= htmlspecialchars($department_name) ?></div>
                </div>
                <div class="profile-info-row">
                    <div class="profile-info-label" data-profile-lang="role">Role:</div>
                    <div class="profile-info-value"><?= htmlspecialchars($role_name) ?></div>
                </div>
                <div class="profile-info-row">
                    <div class="profile-info-label" data-profile-lang="status">Status:</div>
                    <div class="profile-info-value">
                        <span class="profile-status profile-status-<?= strtolower($user['status']) ?>">
                            <?= $user['status'] ?>
                        </span>
                    </div>
                </div>
                <div class="profile-info-row">
                    <div class="profile-info-label" data-profile-lang="memberSince">Member Since:</div>
                    <div class="profile-info-value"><?= date('d M Y, h:i A', strtotime($user['created_at'])) ?></div>
                </div>
                <div class="profile-info-row">
                    <div class="profile-info-label" data-profile-lang="lastUpdated">Last Updated:</div>
                    <div class="profile-info-value"><?= date('d M Y, h:i A', strtotime($user['updated_at'])) ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Security Tab -->
    <div id="securityTab" style="display: none;">
        <!-- Change Password Card -->
        <div class="profile-card">
            <div class="profile-card-header">
                <h3><i class="fas fa-key"></i> <span data-profile-lang="changePassword">Change Password</span></h3>
            </div>
            <div class="profile-card-body">
                <form method="POST" action="?page=profile&tab=security" onsubmit="return validatePasswordForm()">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    
                    <div class="profile-form-grid">
                        <div class="profile-form-group">
                            <label for="current_password" data-profile-lang="currentPassword">Current Password <span class="required">*</span></label>
                            <input type="password" id="current_password" name="current_password" 
                                   data-profile-lang="currentPassword" 
                                   placeholder="Enter current password" required>
                        </div>
                        
                        <div class="profile-form-group">
                            <label for="new_password" data-profile-lang="newPassword">New Password <span class="required">*</span></label>
                            <input type="password" id="new_password" name="new_password" 
                                   data-profile-lang="newPassword" 
                                   placeholder="Enter new password" required>
                            <span class="image-hint" data-profile-lang="confirmPasswordHint">Minimum 6 characters</span>
                        </div>
                        
                        <div class="profile-form-group">
                            <label for="confirm_password" data-profile-lang="confirmNewPassword">Confirm New Password <span class="required">*</span></label>
                            <input type="password" id="confirm_password" name="confirm_password" 
                                   data-profile-lang="confirmNewPassword" 
                                   placeholder="Confirm new password" required>
                        </div>
                    </div>
                    
                    <div class="profile-form-actions">
                        <button type="submit" name="change_password" class="profile-btn">
                            <i class="fas fa-key"></i> <span data-profile-lang="changePasswordBtn">Change Password</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Security Question Card -->
        <div class="profile-card">
            <div class="profile-card-header">
                <h3><i class="fas fa-shield-alt"></i> <span data-profile-lang="securityQuestion">Security Question</span></h3>
            </div>
            <div class="profile-card-body">
                <form method="POST" action="?page=profile&tab=security" onsubmit="return validateSecurityForm()">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    
                    <div class="profile-form-grid">
                        <div class="profile-form-group profile-form-group-full">
                            <label for="security_question" data-profile-lang="securityQuestion">Security Question <span class="required">*</span></label>
                            <select id="security_question" name="security_question" required>
                                <option value="" data-profile-lang="selectQuestion">Select a security question</option>
                                <?php foreach ($security_questions as $question): ?>
                                    <option value="<?= htmlspecialchars($question) ?>" 
                                        <?= ($user['security_question'] == $question) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($question) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="profile-form-group profile-form-group-full">
                            <label for="security_answer" data-profile-lang="securityAnswer">Your Answer <span class="required">*</span></label>
                            <input type="text" id="security_answer" name="security_answer" 
                                   value="<?= !empty($user['security_answer']) ? '••••••••' : '' ?>"
                                   data-profile-lang="securityAnswer" 
                                   placeholder="Enter your answer" required>
                            <span class="image-hint" data-profile-lang="securityAnswerHint">This will be used to verify your identity if you forget your password</span>
                        </div>
                    </div>
                    
                    <div class="profile-form-actions">
                        <button type="submit" name="update_security" class="profile-btn">
                            <i class="fas fa-save"></i> <span data-profile-lang="updateSecurity">Update Security Question</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Image Preview Modal -->
<div id="imagePreviewModal" class="image-preview-modal">
    <div class="image-preview-content">
        <div class="image-preview-header">
            <h3 id="previewModalTitle" data-profile-lang="previewTitle">Profile Image</h3>
            <button class="image-preview-close" onclick="closePreviewModal()" aria-label="Close">&times;</button>
        </div>
        <div class="image-preview-body">
            <img id="previewModalImg" src="#" alt="Profile Image Preview">
        </div>
    </div>
</div>