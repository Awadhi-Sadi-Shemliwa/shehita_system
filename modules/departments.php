<?php
/**
 * SHEHITA Enterprise Management System
 * Departments Module - Full CRUD Operations
 * 
 * This module handles:
 * - Automatic table creation if not exists
 * - Display all departments in a table
 * - Add new department (inline form)
 * - Edit existing department
 * - Delete department with confirmation
 * - Auto-reset ID when table becomes empty
 * - Search/filter functionality
 * - Full English/Swahili translation support
 * - PROTECTED DEFAULT DEPARTMENT (ID=1) - Cannot be edited or deleted
 * 
 * PERMISSION ENHANCED: Buttons now respect user permissions (can_add, can_edit, can_delete)
 * 
 * NOTE: This module is designed to work with homepage.php which provides:
 * - Top horizontal navigation bar (no sidebar)
 * - Session timeout functionality
 * - Language switching via System Settings module
 */

// Prevent direct access
if (!defined('ACCESS_ALLOWED') && basename($_SERVER['PHP_SELF']) == basename(__FILE__)) {
    // This file is included, so we're safe
}

// Get database connection from parent
global $conn;

// PERMISSION: Get user role and check module permissions
$user_role_id = $_SESSION['role_id'] ?? 0;
$module_name = 'departments';

// PERMISSION: Check if user has view permission
if (!canView($conn, $user_role_id, $module_name)) {
    echo '<div class="dept-alert dept-alert-danger" style="text-align: center; padding: 40px;">
            <i class="fas fa-lock" style="font-size: 48px; margin-bottom: 16px; display: block;"></i>
            <h3>Access Denied</h3>
            <p>You do not have permission to view this module.</p>
          </div>';
    return; // Stop rendering the module
}

/**
 * CREATE DEPARTMENTS TABLE IF NOT EXISTS
 */
// Schema note: the `departments` table is created centrally in config.php.
// This module assumes it already exists and only manages its data below.

/**
 * CREATE DEFAULT ADMINISTRATOR DEPARTMENT IF NOT EXISTS
 * This department is required for the roles module and is protected
 */
$check_admin_dept = $conn->query("SELECT id FROM departments WHERE name = 'Administrator'");
if ($check_admin_dept->num_rows == 0) {
    $default_name = 'Administrator';
    $default_description = 'Default system department for administrator roles';
    $default_status = 'Active';
    
    $insert_default = $conn->prepare("INSERT INTO departments (name, description, status) VALUES (?, ?, ?)");
    $insert_default->bind_param("sss", $default_name, $default_description, $default_status);
    $insert_default->execute();
    $insert_default->close();
    
    // Ensure it gets ID = 1 by resetting auto increment if this was the first record
    $check_count = $conn->query("SELECT COUNT(*) as count FROM departments");
    $count = $check_count->fetch_assoc()['count'];
    if ($count == 1) {
        $conn->query("ALTER TABLE departments AUTO_INCREMENT = 2");
    }
} else {
    // Check if the Administrator department has ID=1, if not, update it
    $admin_dept = $check_admin_dept->fetch_assoc();
    if ($admin_dept['id'] != 1) {
        // If Administrator department exists but has a different ID, we need to handle carefully
        // For simplicity, we'll ensure the Administrator department is ID=1
        $conn->query("UPDATE departments SET id = 1 WHERE name = 'Administrator'");
        $conn->query("ALTER TABLE departments AUTO_INCREMENT = " . ($conn->insert_id + 1));
    }
}

/**
 * CHECK IF TABLE IS EMPTY AND RESET AUTO_INCREMENT IF NEEDED
 * (But skip ID 1 as it's reserved for Administrator department)
 */
$check_empty = $conn->query("SELECT COUNT(*) as count FROM departments");
$row_count = $check_empty->fetch_assoc()['count'];

if ($row_count == 0) {
    $conn->query("ALTER TABLE departments AUTO_INCREMENT = 1");
} else {
    // Ensure auto_increment is set correctly if Administrator department exists
    $max_id = $conn->query("SELECT MAX(id) as max_id FROM departments")->fetch_assoc()['max_id'];
    $conn->query("ALTER TABLE departments AUTO_INCREMENT = " . ($max_id + 1));
}

// Initialize variables for messages
$departments_message = '';
$departments_message_type = '';

// Initialize search/filter variables
$search = isset($_GET['search']) ? sanitize($conn, $_GET['search']) : '';
$status_filter = isset($_GET['status_filter']) ? sanitize($conn, $_GET['status_filter']) : '';

/**
 * PROTECTION CHECKS FOR DEFAULT DEPARTMENT (ID = 1)
 * Redirect with error message if trying to edit or delete the protected department
 */
if (isset($_GET['dept_edit']) && (int)$_GET['dept_edit'] == 1) {
    $departments_message = "The default Administrator department cannot be edited";
    $departments_message_type = "danger";
    // Clear the edit parameter by redirecting
    header("Location: ?page=departments");
    exit();
}

if (isset($_GET['dept_delete']) && (int)$_GET['dept_delete'] == 1) {
    $departments_message = "The default Administrator department cannot be deleted";
    $departments_message_type = "danger";
    // Clear the delete parameter by redirecting
    header("Location: ?page=departments");
    exit();
}

/**
 * HANDLE FORM SUBMISSIONS
 */

// PERMISSION: Check add permission before processing add operation
if (isset($_POST['dept_add']) && !canAdd($conn, $user_role_id, $module_name)) {
    $departments_message = "You do not have permission to add departments.";
    $departments_message_type = "danger";
} elseif (isset($_POST['dept_add'])) {
    $name = sanitize($conn, $_POST['name']);
    $description = sanitize($conn, $_POST['description']);
    $status = sanitize($conn, $_POST['status']);
    
    // Validate inputs
    $errors = [];
    
    if (empty($name)) {
        $errors[] = "Department name is required";
    }
    
    if (!in_array($status, ['Active', 'Inactive'])) {
        $errors[] = "Invalid status";
    }
    
    if (empty($errors)) {
        // Insert new department
        $insert_stmt = $conn->prepare("INSERT INTO departments (name, description, status) VALUES (?, ?, ?)");
        $insert_stmt->bind_param("sss", $name, $description, $status);
        
        if ($insert_stmt->execute()) {
            $departments_message = "Department added successfully!";
            $departments_message_type = "success";
        } else {
            $departments_message = "Error adding department: " . $conn->error;
            $departments_message_type = "danger";
        }
        $insert_stmt->close();
    } else {
        $departments_message = implode("<br>", $errors);
        $departments_message_type = "danger";
    }
}

// PERMISSION: Check edit permission before processing update operation
if (isset($_POST['dept_update']) && !canEdit($conn, $user_role_id, $module_name)) {
    $departments_message = "You do not have permission to edit departments.";
    $departments_message_type = "danger";
} elseif (isset($_POST['dept_update'])) {
    $id = (int)$_POST['id'];
    $name = sanitize($conn, $_POST['name']);
    $description = sanitize($conn, $_POST['description']);
    $status = sanitize($conn, $_POST['status']);
    
    // PROTECTION CHECK: Cannot update default department (ID = 1)
    if ($id == 1) {
        $departments_message = "The default Administrator department cannot be edited";
        $departments_message_type = "danger";
    } else {
        // Validate inputs
        $errors = [];
        
        if ($id <= 0) {
            $errors[] = "Invalid ID";
        }
        
        if (empty($name)) {
            $errors[] = "Department name is required";
        }
        
        if (!in_array($status, ['Active', 'Inactive'])) {
            $errors[] = "Invalid status";
        }
        
        if (empty($errors)) {
            // Update department
            $update_stmt = $conn->prepare("UPDATE departments SET name = ?, description = ?, status = ? WHERE id = ?");
            $update_stmt->bind_param("sssi", $name, $description, $status, $id);
            
            if ($update_stmt->execute()) {
                $departments_message = "Department updated successfully!";
                $departments_message_type = "success";
            } else {
                $departments_message = "Error updating department: " . $conn->error;
                $departments_message_type = "danger";
            }
            $update_stmt->close();
        } else {
            $departments_message = implode("<br>", $errors);
            $departments_message_type = "danger";
        }
    }
}

// PERMISSION: Check delete permission before processing delete operation
if (isset($_GET['dept_delete']) && !canDelete($conn, $user_role_id, $module_name)) {
    $departments_message = "You do not have permission to delete departments.";
    $departments_message_type = "danger";
} elseif (isset($_GET['dept_delete'])) {
    $id = (int)$_GET['dept_delete'];
    
    // PROTECTION CHECK: Cannot delete default department (ID = 1)
    if ($id == 1) {
        $departments_message = "The default Administrator department cannot be deleted";
        $departments_message_type = "danger";
    } elseif ($id > 0) {
        $delete_stmt = $conn->prepare("DELETE FROM departments WHERE id = ?");
        $delete_stmt->bind_param("i", $id);
        
        if ($delete_stmt->execute()) {
            $departments_message = "Department deleted successfully!";
            $departments_message_type = "success";
            
            // Check if table is empty and reset auto-increment
            $check_empty = $conn->query("SELECT COUNT(*) as count FROM departments");
            $row_count = $check_empty->fetch_assoc()['count'];
            
            if ($row_count == 0) {
                $conn->query("ALTER TABLE departments AUTO_INCREMENT = 1");
            }
        } else {
            $departments_message = "Error deleting department: " . $conn->error;
            $departments_message_type = "danger";
        }
        $delete_stmt->close();
    }
}

// Get edit data if in edit mode (protected department already handled above)
$edit_mode = false;
$edit_data = null;

if (isset($_GET['dept_edit'])) {
    $edit_id = (int)$_GET['dept_edit'];
    // PERMISSION: Only allow edit if user has edit permission
    if ($edit_id > 0 && $edit_id != 1 && canEdit($conn, $user_role_id, $module_name)) {
        $edit_stmt = $conn->prepare("SELECT * FROM departments WHERE id = ?");
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
 * FETCH ALL DEPARTMENTS WITH SEARCH AND FILTER
 */
$where_clause = "";
$params = [];
$types = "";

if (!empty($search)) {
    $where_clause .= " (name LIKE ? OR description LIKE ?) ";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ss";
}

if (!empty($status_filter)) {
    if (!empty($where_clause)) {
        $where_clause .= " AND ";
    }
    $where_clause .= " status = ? ";
    $params[] = $status_filter;
    $types .= "s";
}

if (!empty($where_clause)) {
    $where_clause = "WHERE " . $where_clause;
}

$departments_query = "SELECT * FROM departments $where_clause ORDER BY id DESC";

// Prepare and execute the query with parameters if needed
if (!empty($params)) {
    $stmt = $conn->prepare($departments_query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $departments_result = $stmt->get_result();
} else {
    $departments_result = $conn->query($departments_query);
}

// Get total count for display
$total_count = $departments_result->num_rows;
?>

<!-- DEPARTMENTS TRANSLATIONS -->
<script>
// Departments translations for English and Swahili
const dept_translations = {
    en: {
        pageTitle: 'Departments Management',
        addNew: 'Add New Department',
        editDepartment: 'Edit Department',
        addDepartment: 'Add New Department',
        id: 'ID',
        name: 'Department Name',
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
        confirmDelete: 'Are you sure you want to delete department',
        confirmDeleteMsg: 'This action cannot be undone.',
        noData: 'No departments found.',
        nameRequired: 'Department name is required!',
        loading: 'Loading...',
        search: 'Search',
        filter: 'Filter',
        allStatus: 'All Status',
        clear: 'Clear',
        totalRecords: 'Total Departments',
        records: 'records',
        descriptionPlaceholder: 'Enter department description (optional)',
        namePlaceholder: 'Enter department name',
        protectedRole: 'System Default',
        protectedTooltip: 'System default - Cannot be modified or deleted',
        cannotEditDefault: 'The default Administrator department cannot be edited',
        cannotDeleteDefault: 'The default Administrator department cannot be deleted'
    },
    sw: {
        pageTitle: 'Usimamizi wa Idara',
        addNew: 'Ongeza Idara Mpya',
        editDepartment: 'Hariri Idara',
        addDepartment: 'Ongeza Idara Mpya',
        id: 'Kitambulisho',
        name: 'Jina la Idara',
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
        confirmDelete: 'Una uhakika unataka kufuta idara',
        confirmDeleteMsg: 'Kitendo hiki hakiwezi kutenguliwa.',
        noData: 'Hakuna idara zilizopatikana.',
        nameRequired: 'Jina la idara linahitajika!',
        loading: 'Inapakia...',
        search: 'Tafuta',
        filter: 'Chuja',
        allStatus: 'Hali Zote',
        clear: 'Futa',
        totalRecords: 'Jumla ya Idara',
        records: 'rekodi',
        descriptionPlaceholder: 'Weka maelezo ya idara (si lazima)',
        namePlaceholder: 'Weka jina la idara',
        protectedRole: 'Mfumo Msingi',
        protectedTooltip: 'Mfumo wa msingi - Haiwezi kubadilishwa au kufutwa',
        cannotEditDefault: 'Idara ya msingi ya Administrator haiwezi kubadilishwa',
        cannotDeleteDefault: 'Idara ya msingi ya Administrator haiwezi kufutwa'
    }
};

// Current language (will be updated by System Settings module)
let currentDeptLang = localStorage.getItem('preferredLanguage') || 'en';

// Function to update all translatable elements in departments module
function updateDeptLanguage(lang) {
    currentDeptLang = lang;
    const elements = document.querySelectorAll('[data-dept-lang]');
    
    elements.forEach(element => {
        const key = element.getAttribute('data-dept-lang');
        if (dept_translations[lang] && dept_translations[lang][key]) {
            if (element.tagName === 'INPUT' || element.tagName === 'TEXTAREA') {
                if (element.getAttribute('type') !== 'submit' && element.getAttribute('type') !== 'button') {
                    element.placeholder = dept_translations[lang][key];
                } else {
                    element.textContent = dept_translations[lang][key];
                }
            } else if (element.tagName === 'OPTION') {
                element.textContent = dept_translations[lang][key];
            } else {
                element.textContent = dept_translations[lang][key];
            }
        }
    });
    
    // Update table headers specifically
    const thElements = document.querySelectorAll('th[data-dept-lang]');
    thElements.forEach(th => {
        const key = th.getAttribute('data-dept-lang');
        if (dept_translations[lang] && dept_translations[lang][key]) {
            th.textContent = dept_translations[lang][key];
        }
    });
    
    // Update empty state message
    const emptyState = document.querySelector('.dept-empty p');
    if (emptyState) {
        emptyState.textContent = dept_translations[lang].noData;
    }
    
    // Update form header based on edit mode
    const formHeader = document.querySelector('#deptForm h3');
    if (formHeader) {
        const isEditMode = document.querySelector('input[name="id"]') !== null;
        formHeader.textContent = dept_translations[lang][isEditMode ? 'editDepartment' : 'addDepartment'];
    }
    
    // Update search input placeholder
    const searchInput = document.querySelector('input[name="search"]');
    if (searchInput) {
        searchInput.placeholder = dept_translations[lang].search;
    }
    
    // Update total records text
    const totalRecordsSpan = document.querySelector('#deptTotalRecords');
    if (totalRecordsSpan) {
        totalRecordsSpan.textContent = dept_translations[lang].totalRecords;
    }
    
    // Update protected row tooltips
    document.querySelectorAll('.dept-protected-row .dept-protected-tooltip').forEach(el => {
        el.setAttribute('title', dept_translations[lang].protectedTooltip);
    });
    
    // Update toggle button text
    const toggleBtn = document.getElementById('deptToggleBtn');
    if (toggleBtn && !document.getElementById('deptForm').classList.contains('show')) {
        const addSpan = toggleBtn.querySelector('span');
        if (addSpan) {
            addSpan.textContent = dept_translations[lang].addNew;
        }
    }
}

// Toggle form visibility - NO SIDEBAR REFERENCES
function dept_toggleForm() {
    const form = document.getElementById('deptForm');
    const btn = document.getElementById('deptToggleBtn');
    const lang = currentDeptLang;
    
    if (form.classList.contains('show')) {
        form.classList.remove('show');
        btn.innerHTML = '<i class="fas fa-plus"></i> <span data-dept-lang="addNew">' + 
            (lang === 'en' ? 'Add New Department' : 'Ongeza Idara Mpya') + '</span>';
    } else {
        form.classList.add('show');
        btn.innerHTML = '<i class="fas fa-times"></i> <span data-dept-lang="cancel">' + 
            (lang === 'en' ? 'Cancel' : 'Ghairi') + '</span>';
        
        // Update form header when opening
        const formHeader = document.querySelector('#deptForm h3');
        if (formHeader) {
            formHeader.textContent = dept_translations[lang].addDepartment;
        }
        
        // Clear any hidden ID field to ensure it's in add mode
        const hiddenId = document.querySelector('input[name="id"]');
        if (hiddenId) {
            hiddenId.remove();
        }
        
        // Clear form fields
        const nameInput = document.getElementById('dept_name');
        const descInput = document.getElementById('dept_description');
        const statusSelect = document.getElementById('dept_status');
        
        if (nameInput) nameInput.value = '';
        if (descInput) descInput.value = '';
        if (statusSelect) statusSelect.value = 'Active';
        
        // Change button name to add mode
        const submitBtn = document.querySelector('button[name="dept_update"], button[name="dept_add"]');
        if (submitBtn) {
            submitBtn.name = 'dept_add';
        }
    }
    
    // Update all translatable elements after toggle
    updateDeptLanguage(lang);
}

// Validate form before submission
function dept_validateForm() {
    const name = document.getElementById('dept_name').value.trim();
    const lang = currentDeptLang;
    
    if (name === '') {
        alert(dept_translations[lang].nameRequired);
        document.getElementById('dept_name').focus();
        return false;
    }
    
    return true;
}

// Confirm delete with loading effect (protected against deleting ID 1)
function dept_confirmDelete(id, name) {
    // Check if trying to delete protected department
    if (id == 1) {
        const lang = currentDeptLang;
        alert(dept_translations[lang].cannotDeleteDefault);
        return false;
    }
    
    const lang = currentDeptLang;
    const confirmMsg = dept_translations[lang].confirmDelete + ' "' + name + '"?\n' + 
                      dept_translations[lang].confirmDeleteMsg;
    
    if (confirm(confirmMsg)) {
        // Show loading effect on the clicked row
        const row = event.target.closest('tr');
        if (row) {
            row.style.opacity = '0.5';
            
            // Create loading indicator
            const loadingDiv = document.createElement('div');
            loadingDiv.className = 'dept-loading';
            loadingDiv.style.position = 'absolute';
            loadingDiv.style.top = '50%';
            loadingDiv.style.left = '50%';
            loadingDiv.style.transform = 'translate(-50%, -50%)';
            row.style.position = 'relative';
            row.appendChild(loadingDiv);
        }
        
        // Redirect after a small delay to show loading
        setTimeout(() => {
            window.location.href = `?page=departments&dept_delete=${id}`;
        }, 300);
    }
}

// Auto-hide alerts after 5 seconds
function dept_autoHideAlerts() {
    setTimeout(() => {
        document.querySelectorAll('.dept-alert').forEach(alert => {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(() => {
                if (alert.style.display !== 'none') {
                    alert.style.display = 'none';
                }
            }, 500);
        });
    }, 5000);
}

// Initialize module when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    // Initial language update
    updateDeptLanguage(currentDeptLang);
    
    // Auto-hide alerts
    dept_autoHideAlerts();
    
    // Set up department change listener for any dependent dropdowns (if needed)
    // This is kept for future extensibility but doesn't reference sidebar
});

// Make functions globally available for System Settings module
window.updateDeptLanguage = updateDeptLanguage;
window.dept_toggleForm = dept_toggleForm;
window.dept_validateForm = dept_validateForm;
window.dept_confirmDelete = dept_confirmDelete;
</script>

<style>
    /* Departments Module Styles - All prefixed with 'dept-' to avoid conflicts */
    .dept-container {
        width: 100%;
    }

    .dept-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
        flex-wrap: wrap;
        gap: 16px;
    }

    .dept-header h2 {
        color: var(--gray-800);
        font-size: 24px;
        font-weight: 600;
        margin: 0;
    }

    .dept-btn {
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

    .dept-btn:hover {
        background: var(--brown-800);
        transform: translateY(-2px);
        box-shadow: var(--shadow-md);
    }

    .dept-btn-secondary {
        background: var(--gray-200);
        color: var(--gray-700);
    }

    .dept-btn-secondary:hover {
        background: var(--gray-300);
    }

    .dept-btn-danger {
        background: #dc3545;
    }

    .dept-btn-danger:hover {
        background: #c82333;
    }

    .dept-form {
        background: var(--gray-50);
        padding: 24px;
        border-radius: 12px;
        margin-bottom: 32px;
        border: 1px solid var(--gray-200);
        display: <?= $edit_mode ? 'block' : 'none' ?>;
    }

    .dept-form.show {
        display: block;
    }

    .dept-form h3 {
        color: var(--gray-800);
        font-size: 18px;
        font-weight: 600;
        margin-bottom: 20px;
    }

    .dept-form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 16px;
        margin-bottom: 20px;
    }

    .dept-form-group {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .dept-form-group-full {
        grid-column: 1 / -1;
    }

    .dept-form-group label {
        color: var(--gray-700);
        font-size: 14px;
        font-weight: 500;
    }

    .dept-form-group input,
    .dept-form-group select,
    .dept-form-group textarea {
        padding: 10px 12px;
        border: 1px solid var(--gray-300);
        border-radius: 6px;
        font-size: 14px;
        transition: all 0.2s;
        font-family: inherit;
    }

    .dept-form-group textarea {
        resize: vertical;
        min-height: 80px;
    }

    .dept-form-group input:focus,
    .dept-form-group select:focus,
    .dept-form-group textarea:focus {
        outline: none;
        border-color: var(--brown-600);
        box-shadow: 0 0 0 3px rgba(123, 88, 63, 0.1);
    }

    .dept-form-actions {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
    }

    .dept-alert {
        padding: 16px;
        border-radius: 8px;
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .dept-alert-success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }

    .dept-alert-danger {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }

    .dept-alert-close {
        background: none;
        border: none;
        font-size: 20px;
        cursor: pointer;
        color: inherit;
        opacity: 0.5;
    }

    .dept-alert-close:hover {
        opacity: 1;
    }

    /* Search and Filter Bar */
    .dept-search-bar {
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

    .dept-search-group {
        flex: 1;
        min-width: 200px;
    }

    .dept-search-group label {
        display: block;
        color: var(--gray-700);
        font-size: 13px;
        font-weight: 500;
        margin-bottom: 6px;
    }

    .dept-search-group input,
    .dept-search-group select {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid var(--gray-300);
        border-radius: 6px;
        font-size: 14px;
    }

    .dept-search-group input:focus,
    .dept-search-group select:focus {
        outline: none;
        border-color: var(--brown-600);
        box-shadow: 0 0 0 3px rgba(123, 88, 63, 0.1);
    }

    .dept-search-actions {
        display: flex;
        gap: 8px;
    }

    .dept-search-btn {
        padding: 10px 20px;
        background: var(--brown-700);
        color: white;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-weight: 500;
        transition: all 0.2s;
    }

    .dept-search-btn:hover {
        background: var(--brown-800);
    }

    .dept-clear-btn {
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

    .dept-clear-btn:hover {
        background: var(--gray-300);
    }

    /* Stats Bar */
    .dept-stats {
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

    .dept-stats-info {
        display: flex;
        align-items: center;
        gap: 8px;
        color: var(--gray-700);
        font-size: 14px;
    }

    .dept-stats-info i {
        color: var(--brown-600);
    }

    .dept-stats-count {
        font-weight: 700;
        color: var(--brown-700);
        font-size: 18px;
    }

    .dept-table-container {
        overflow-x: auto;
        background: white;
        border-radius: 12px;
        border: 1px solid var(--gray-200);
    }

    .dept-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 800px;
    }

    .dept-table th {
        background: var(--gray-50);
        color: var(--gray-700);
        font-weight: 600;
        font-size: 14px;
        padding: 16px;
        text-align: left;
        border-bottom: 2px solid var(--gray-200);
    }

    .dept-table td {
        padding: 16px;
        border-bottom: 1px solid var(--gray-200);
        color: var(--gray-700);
        font-size: 14px;
    }

    .dept-table tr:hover {
        background: var(--gray-50);
    }
    
    /* Protected row styling */
    .dept-table tr.dept-protected-row {
        background-color: #fef9e6;
        border-left: 3px solid var(--brown-600);
    }
    
    .dept-table tr.dept-protected-row:hover {
        background-color: #fef5e0;
    }
    
    .dept-protected-badge {
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
    
    .dept-protected-badge i {
        font-size: 10px;
    }

    .dept-status {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 500;
    }

    .dept-status-active {
        background: #d4edda;
        color: #155724;
    }

    .dept-status-inactive {
        background: #f8d7da;
        color: #721c24;
    }

    .dept-actions {
        display: flex;
        gap: 8px;
    }

    .dept-action-btn {
        padding: 6px 12px;
        border-radius: 4px;
        font-size: 13px;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        transition: all 0.2s;
    }
    
    .dept-action-btn.disabled,
    .dept-action-btn.disabled:hover {
        opacity: 0.5;
        cursor: not-allowed;
        pointer-events: none;
        background: var(--gray-200);
        color: var(--gray-500);
    }

    .dept-action-edit {
        background: var(--brown-100);
        color: var(--brown-800);
    }

    .dept-action-edit:hover {
        background: var(--brown-200);
    }

    .dept-action-delete {
        background: #f8d7da;
        color: #721c24;
    }

    .dept-action-delete:hover {
        background: #f5c6cb;
    }

    .dept-loading {
        display: inline-block;
        width: 20px;
        height: 20px;
        border: 3px solid var(--gray-200);
        border-top-color: var(--brown-700);
        border-radius: 50%;
        animation: dept-spin 1s linear infinite;
    }

    @keyframes dept-spin {
        to { transform: rotate(360deg); }
    }

    .dept-empty {
        text-align: center;
        padding: 48px !important;
        color: var(--gray-500);
    }

    .dept-empty i {
        font-size: 48px;
        margin-bottom: 16px;
        color: var(--gray-400);
    }

    .dept-timestamp {
        font-size: 12px;
        color: var(--gray-500);
        margin-top: 4px;
    }

    .dept-description-preview {
        max-width: 250px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        color: var(--gray-600);
    }

    /* Responsive Styles */
    @media (max-width: 768px) {
        .dept-search-bar {
            flex-direction: column;
        }
        
        .dept-search-actions {
            width: 100%;
        }
        
        .dept-search-btn,
        .dept-clear-btn {
            flex: 1;
            text-align: center;
            justify-content: center;
        }
    }
</style>

<div class="dept-container">
    <!-- Header -->
    <div class="dept-header">
        <h2 data-dept-lang="pageTitle">Departments Management</h2>
        <?php if (canAdd($conn, $user_role_id, $module_name)): ?>
        <button class="dept-btn" onclick="dept_toggleForm()" id="deptToggleBtn">
            <i class="fas fa-plus"></i>
            <span data-dept-lang="addNew">Add New Department</span>
        </button>
        <?php endif; ?>
    </div>

    <!-- Alert Messages -->
    <?php if (!empty($departments_message)): ?>
        <div class="dept-alert dept-alert-<?= $departments_message_type ?>">
            <?= $departments_message ?>
            <button class="dept-alert-close" onclick="this.parentElement.style.display='none'">&times;</button>
        </div>
    <?php endif; ?>

    <!-- Add/Edit Form - Only show if user has add or edit permission -->
    <?php if (canAdd($conn, $user_role_id, $module_name) || ($edit_mode && canEdit($conn, $user_role_id, $module_name))): ?>
    <div class="dept-form <?= $edit_mode ? 'show' : '' ?>" id="deptForm">
        <h3 data-dept-lang="<?= $edit_mode ? 'editDepartment' : 'addDepartment' ?>">
            <?= $edit_mode ? 'Edit Department' : 'Add New Department' ?>
        </h3>
        
        <form method="POST" action="?page=departments" onsubmit="return dept_validateForm()">
            <?php if ($edit_mode): ?>
                <input type="hidden" name="id" value="<?= $edit_data['id'] ?>">
            <?php endif; ?>
            
            <div class="dept-form-grid">
                <div class="dept-form-group">
                    <label for="dept_name" data-dept-lang="name">Department Name *</label>
                    <input type="text" id="dept_name" name="name" 
                           value="<?= $edit_mode ? htmlspecialchars($edit_data['name']) : '' ?>" 
                           required maxlength="255" data-dept-lang="namePlaceholder" 
                           placeholder="Enter department name">
                </div>
                
                <div class="dept-form-group">
                    <label for="dept_status" data-dept-lang="status">Status *</label>
                    <select id="dept_status" name="status" required>
                        <option value="Active" data-dept-lang="active" <?= ($edit_mode && $edit_data['status'] == 'Active') ? 'selected' : '' ?>>Active</option>
                        <option value="Inactive" data-dept-lang="inactive" <?= ($edit_mode && $edit_data['status'] == 'Inactive') ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                
                <div class="dept-form-group dept-form-group-full">
                    <label for="dept_description" data-dept-lang="description">Description</label>
                    <textarea id="dept_description" name="description" 
                              data-dept-lang="descriptionPlaceholder" 
                              placeholder="Enter department description (optional)"><?= $edit_mode ? htmlspecialchars($edit_data['description']) : '' ?></textarea>
                </div>
            </div>
            
            <div class="dept-form-actions">
                <button type="submit" name="<?= $edit_mode ? 'dept_update' : 'dept_add' ?>" 
                        class="dept-btn">
                    <i class="fas fa-save"></i>
                    <span data-dept-lang="save">Save</span>
                </button>
                <a href="?page=departments" class="dept-btn dept-btn-secondary">
                    <i class="fas fa-times"></i>
                    <span data-dept-lang="cancel">Cancel</span>
                </a>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- Search and Filter Bar -->
    <div class="dept-search-bar">
        <form method="GET" action="?page=departments" style="display: contents;">
            <input type="hidden" name="page" value="departments">
            
            <div class="dept-search-group">
                <label for="dept_search" data-dept-lang="search">Search</label>
                <input type="text" id="dept_search" name="search" 
                       value="<?= htmlspecialchars($search) ?>" 
                       placeholder="Search by name or description">
            </div>
            
            <div class="dept-search-group">
                <label for="dept_status_filter" data-dept-lang="filter">Filter</label>
                <select id="dept_status_filter" name="status_filter">
                    <option value="" data-dept-lang="allStatus">All Status</option>
                    <option value="Active" <?= $status_filter == 'Active' ? 'selected' : '' ?> data-dept-lang="active">Active</option>
                    <option value="Inactive" <?= $status_filter == 'Inactive' ? 'selected' : '' ?> data-dept-lang="inactive">Inactive</option>
                </select>
            </div>
            
            <div class="dept-search-actions">
                <button type="submit" class="dept-search-btn">
                    <i class="fas fa-search"></i> <span data-dept-lang="search">Search</span>
                </button>
                <a href="?page=departments" class="dept-clear-btn">
                    <i class="fas fa-times"></i> <span data-dept-lang="clear">Clear</span>
                </a>
            </div>
        </form>
    </div>

    <!-- Stats Bar -->
    <div class="dept-stats">
        <div class="dept-stats-info">
            <i class="fas fa-building"></i>
            <span id="deptTotalRecords" data-dept-lang="totalRecords">Total Departments</span>
            <span>:</span>
            <span class="dept-stats-count"><?= $total_count ?></span>
            <span data-dept-lang="records">records</span>
        </div>
    </div>

    <!-- Data Table -->
    <div class="dept-table-container">
        <table class="dept-table">
            <thead>
                <tr>
                    <th data-dept-lang="id">ID</th>
                    <th data-dept-lang="name">Department Name</th>
                    <th data-dept-lang="description">Description</th>
                    <th data-dept-lang="status">Status</th>
                    <th data-dept-lang="created">Created</th>
                    <th data-dept-lang="updated">Updated</th>
                    <th data-dept-lang="actions">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($departments_result && $departments_result->num_rows > 0): ?>
                    <?php while ($row = $departments_result->fetch_assoc()): ?>
                        <?php $is_protected = ($row['id'] == 1); ?>
                        <tr class="<?= $is_protected ? 'dept-protected-row' : '' ?>">
                            <td>#<?= $row['id'] ?></td>
                            <td>
                                <strong><?= htmlspecialchars($row['name']) ?></strong>
                                <?php if ($is_protected): ?>
                                    <span class="dept-protected-badge dept-protected-tooltip" 
                                          title="System default - Cannot be modified or deleted"
                                          data-dept-lang="protectedTooltip">
                                        <i class="fas fa-lock"></i> 
                                        <span data-dept-lang="protectedRole">System Default</span>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($row['description'])): ?>
                                    <div class="dept-description-preview" title="<?= htmlspecialchars($row['description']) ?>">
                                        <?= htmlspecialchars($row['description']) ?>
                                    </div>
                                <?php else: ?>
                                    <span style="color: var(--gray-400);">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="dept-status dept-status-<?= strtolower($row['status']) ?>">
                                    <?= $row['status'] ?>
                                </span>
                            </td>
                            <td>
                                <?= date('d M Y H:i', strtotime($row['created_at'])) ?>
                                <div class="dept-timestamp"><?= date('d M Y', strtotime($row['created_at'])) ?></div>
                            </td>
                            <td>
                                <?= date('d M Y H:i', strtotime($row['updated_at'])) ?>
                                <div class="dept-timestamp"><?= date('d M Y', strtotime($row['updated_at'])) ?></div>
                            </td>
                            <td>
                                <div class="dept-actions">
                                    <?php if ($is_protected): ?>
                                        <a href="javascript:void(0)" 
                                           class="dept-action-btn dept-action-edit disabled dept-protected-tooltip"
                                           title="System default - Cannot be modified or deleted">
                                            <i class="fas fa-edit"></i> <span data-dept-lang="edit">Edit</span>
                                        </a>
                                        <a href="javascript:void(0)" 
                                           class="dept-action-btn dept-action-delete disabled dept-protected-tooltip"
                                           title="System default - Cannot be modified or deleted">
                                            <i class="fas fa-trash"></i> <span data-dept-lang="delete">Delete</span>
                                        </a>
                                    <?php else: ?>
                                        <?php if (canEdit($conn, $user_role_id, $module_name)): ?>
                                        <a href="?page=departments&dept_edit=<?= $row['id'] ?>" 
                                           class="dept-action-btn dept-action-edit">
                                            <i class="fas fa-edit"></i> <span data-dept-lang="edit">Edit</span>
                                        </a>
                                        <?php endif; ?>
                                        <?php if (canDelete($conn, $user_role_id, $module_name)): ?>
                                        <a href="javascript:void(0)" 
                                           onclick="dept_confirmDelete(<?= $row['id'] ?>, '<?= htmlspecialchars(addslashes($row['name'])) ?>')" 
                                           class="dept-action-btn dept-action-delete">
                                            <i class="fas fa-trash"></i> <span data-dept-lang="delete">Delete</span>
                                        </a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="dept-empty">
                            <i class="fas fa-building"></i>
                            <p data-dept-lang="noData">No departments found.</p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>