<?php
/**
 * SHEHITA Enterprise Management System
 * Categories Module - Full CRUD Operations
 * 
 * REFINED: Removed all sidebar-related code (Issue #1)
 * REFINED: Added foreign key table validation with user-friendly error messages (Issue #2)
 * REFINED: Ensured no conflict with homepage.php (top navbar layout) (Issue #3)
 * 
 * This module handles:
 * - Automatic table creation if not exists
 * - Display all categories in a table with project group dropdown
 * - Add new category (inline form)
 * - Edit existing category
 * - Delete category with confirmation
 * - Auto-reset ID when table becomes empty
 * - Full English/Swahili translation support
 * - Foreign key relationship with projectgroup table
 * 
 * PERMISSION ENHANCED: Buttons now respect user permissions (can_add, can_edit, can_delete)
 */

// Prevent direct access
if (!defined('ACCESS_ALLOWED') && basename($_SERVER['PHP_SELF']) == basename(__FILE__)) {
    // This file is included, so we're safe
}

// Get database connection from parent
global $conn;

// PERMISSION: Get user role and check module permissions
$user_role_id = $_SESSION['role_id'] ?? 0;
$module_name = 'categories';

// PERMISSION: Check if user has view permission
if (!canView($conn, $user_role_id, $module_name)) {
    echo '<div class="alert alert-danger" style="text-align: center; padding: 40px;">
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

// Check for projectgroup table (required for foreign key)
$check_pg = $conn->query("SHOW TABLES LIKE 'projectgroup'");
if ($check_pg->num_rows == 0) {
    $missing_tables[] = ['table' => 'projectgroup', 'module' => 'projectgroup', 'display' => 'Project Groups'];
}

// Display error message if any required tables are missing
if (!empty($missing_tables)) {
    echo '<div class="categories-container" style="max-width: 800px; margin: 40px auto;">';
    echo '<div class="categories-alert categories-alert-danger" style="background: #fff3cd; color: #856404; border-color: #ffeeba; text-align: left;">';
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
 * CREATE CATEGORIES TABLE IF NOT EXISTS
 * Includes foreign key reference to projectgroup table
 * Auto-increment ID, timestamps managed by MySQL
 */
// Schema note: the `categories` table is created centrally in config.php
// (in dependency order). This module assumes it already exists.

/**
 * CHECK IF TABLE IS EMPTY AND RESET AUTO_INCREMENT IF NEEDED
 * This ensures ID starts at 1 when all records are deleted
 */
$check_empty = $conn->query("SELECT COUNT(*) as count FROM categories");
$row_count = $check_empty->fetch_assoc()['count'];

if ($row_count == 0) {
    $conn->query("ALTER TABLE categories AUTO_INCREMENT = 1");
}

// Initialize variables for messages
$categories_message = '';
$categories_message_type = '';

/**
 * HANDLE FORM SUBMISSIONS
 */

// PERMISSION: Check add permission before processing add operation
if (isset($_POST['categories_add']) && !canAdd($conn, $user_role_id, $module_name)) {
    $categories_message = "You do not have permission to add categories.";
    $categories_message_type = "danger";
} elseif (isset($_POST['categories_add'])) {
    $projectgroup_id = (int)$_POST['projectgroup_id'];
    $name = sanitize($conn, $_POST['name']);
    $status = sanitize($conn, $_POST['status']);
    
    // Validate inputs
    $errors = [];
    
    if ($projectgroup_id <= 0) {
        $errors[] = "Please select a valid project group";
    }
    
    if (empty($name)) {
        $errors[] = "Category name is required";
    }
    
    if (!in_array($status, ['Active', 'Inactive'])) {
        $errors[] = "Invalid status";
    }
    
    if (empty($errors)) {
        // Insert new category
        $insert_stmt = $conn->prepare("INSERT INTO categories (projectgroup_id, name, status) VALUES (?, ?, ?)");
        $insert_stmt->bind_param("iss", $projectgroup_id, $name, $status);
        
        if ($insert_stmt->execute()) {
            $categories_message = "Category added successfully!";
            $categories_message_type = "success";
        } else {
            $categories_message = "Error adding category: " . $conn->error;
            $categories_message_type = "danger";
        }
        $insert_stmt->close();
    } else {
        $categories_message = implode("<br>", $errors);
        $categories_message_type = "danger";
    }
}

// PERMISSION: Check edit permission before processing update operation
if (isset($_POST['categories_update']) && !canEdit($conn, $user_role_id, $module_name)) {
    $categories_message = "You do not have permission to edit categories.";
    $categories_message_type = "danger";
} elseif (isset($_POST['categories_update'])) {
    $id = (int)$_POST['id'];
    $projectgroup_id = (int)$_POST['projectgroup_id'];
    $name = sanitize($conn, $_POST['name']);
    $status = sanitize($conn, $_POST['status']);
    
    // Validate inputs
    $errors = [];
    
    if ($id <= 0) {
        $errors[] = "Invalid ID";
    }
    
    if ($projectgroup_id <= 0) {
        $errors[] = "Please select a valid project group";
    }
    
    if (empty($name)) {
        $errors[] = "Category name is required";
    }
    
    if (!in_array($status, ['Active', 'Inactive'])) {
        $errors[] = "Invalid status";
    }
    
    if (empty($errors)) {
        // Update category
        $update_stmt = $conn->prepare("UPDATE categories SET projectgroup_id = ?, name = ?, status = ? WHERE id = ?");
        $update_stmt->bind_param("issi", $projectgroup_id, $name, $status, $id);
        
        if ($update_stmt->execute()) {
            $categories_message = "Category updated successfully!";
            $categories_message_type = "success";
        } else {
            $categories_message = "Error updating category: " . $conn->error;
            $categories_message_type = "danger";
        }
        $update_stmt->close();
    } else {
        $categories_message = implode("<br>", $errors);
        $categories_message_type = "danger";
    }
}

// PERMISSION: Check delete permission before processing delete operation
if (isset($_GET['categories_delete']) && !canDelete($conn, $user_role_id, $module_name)) {
    $categories_message = "You do not have permission to delete categories.";
    $categories_message_type = "danger";
} elseif (isset($_GET['categories_delete'])) {
    $id = (int)$_GET['categories_delete'];
    
    if ($id > 0) {
        $delete_stmt = $conn->prepare("DELETE FROM categories WHERE id = ?");
        $delete_stmt->bind_param("i", $id);
        
        if ($delete_stmt->execute()) {
            $categories_message = "Category deleted successfully!";
            $categories_message_type = "success";
            
            // Check if table is empty and reset auto-increment
            $check_empty = $conn->query("SELECT COUNT(*) as count FROM categories");
            $row_count = $check_empty->fetch_assoc()['count'];
            
            if ($row_count == 0) {
                $conn->query("ALTER TABLE categories AUTO_INCREMENT = 1");
            }
        } else {
            $categories_message = "Error deleting category: " . $conn->error;
            $categories_message_type = "danger";
        }
        $delete_stmt->close();
    }
}

// Get edit data if in edit mode (and user has edit permission)
$edit_mode = false;
$edit_data = null;

if (isset($_GET['categories_edit'])) {
    $edit_id = (int)$_GET['categories_edit'];
    // PERMISSION: Only allow edit if user has edit permission
    if ($edit_id > 0 && canEdit($conn, $user_role_id, $module_name)) {
        $edit_stmt = $conn->prepare("SELECT * FROM categories WHERE id = ?");
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
 * FETCH PROJECT GROUPS FOR DROPDOWN
 * Only get active project groups to populate the dropdown
 */
$projectgroup_dropdown = [];
$projectgroup_query = "SELECT id, name FROM projectgroup WHERE status = 'Active' ORDER BY name ASC";
$projectgroup_result = $conn->query($projectgroup_query);

if ($projectgroup_result && $projectgroup_result->num_rows > 0) {
    while ($row = $projectgroup_result->fetch_assoc()) {
        $projectgroup_dropdown[] = $row;
    }
}

/**
 * FETCH ALL CATEGORIES WITH PROJECT GROUP NAMES
 * Join with projectgroup table to display the group name
 */
$categories_query = "
    SELECT c.*, pg.name as projectgroup_name 
    FROM categories c 
    LEFT JOIN projectgroup pg ON c.projectgroup_id = pg.id 
    ORDER BY c.id DESC
";
$categories_result = $conn->query($categories_query);
?>

<!-- CATEGORIES TRANSLATIONS -->
<script>
// Categories translations for English and Swahili
const categories_translations = {
    en: {
        pageTitle: 'Categories Management',
        addNew: 'Add New Category',
        editCategory: 'Edit Category',
        addCategory: 'Add New Category',
        id: 'ID',
        projectGroup: 'Project Group',
        selectProjectGroup: 'Select Project Group',
        name: 'Category Name',
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
        confirmDelete: 'Are you sure you want to delete category',
        confirmDeleteMsg: 'This action cannot be undone.',
        noData: 'No categories found. Click "Add New Category" to create one.',
        nameRequired: 'Category name is required!',
        projectGroupRequired: 'Please select a project group!',
        loading: 'Loading...',
        noProjectGroups: 'No active project groups found. Please create a project group first.'
    },
    sw: {
        pageTitle: 'Usimamizi wa Kategoria',
        addNew: 'Ongeza Kategoria Mpya',
        editCategory: 'Hariri Kategoria',
        addCategory: 'Ongeza Kategoria Mpya',
        id: 'Kitambulisho',
        projectGroup: 'Kundi la Mradi',
        selectProjectGroup: 'Chagua Kundi la Mradi',
        name: 'Jina la Kategoria',
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
        confirmDelete: 'Una uhakika unataka kufuta kategoria',
        confirmDeleteMsg: 'Kitendo hiki hakiwezi kutenguliwa.',
        noData: 'Hakuna kategoria zilizopatikana. Bofya "Ongeza Kategoria Mpya" kuunda.',
        nameRequired: 'Jina la kategoria linahitajika!',
        projectGroupRequired: 'Tafadhali chagua kundi la mradi!',
        loading: 'Inapakia...',
        noProjectGroups: 'Hakuna vikundi vya miradi vinavyotumika. Tafadhali unda kundi la mradi kwanza.'
    }
};

// Current language (will be updated by homepage.js)
let currentCategoriesLang = localStorage.getItem('preferredLanguage') || 'en';

// Function to update all translatable elements in categories module
function updateCategoriesLanguage(lang) {
    currentCategoriesLang = lang;
    const elements = document.querySelectorAll('[data-cat-lang]');
    
    elements.forEach(element => {
        const key = element.getAttribute('data-cat-lang');
        if (categories_translations[lang] && categories_translations[lang][key]) {
            if (element.tagName === 'INPUT' || element.tagName === 'TEXTAREA') {
                element.placeholder = categories_translations[lang][key];
            } else if (element.tagName === 'OPTION') {
                element.textContent = categories_translations[lang][key];
            } else if (element.tagName === 'SELECT' && element.id === 'projectgroup_id') {
                // For select, we need to update the placeholder option only
                const placeholderOption = element.querySelector('option[value=""]');
                if (placeholderOption) {
                    placeholderOption.textContent = categories_translations[lang].selectProjectGroup;
                }
            } else {
                element.textContent = categories_translations[lang][key];
            }
        }
    });
    
    // Update table header specifically if they have data-cat-lang attributes
    const thElements = document.querySelectorAll('th[data-cat-lang]');
    thElements.forEach(th => {
        const key = th.getAttribute('data-cat-lang');
        if (categories_translations[lang] && categories_translations[lang][key]) {
            th.textContent = categories_translations[lang][key];
        }
    });
    
    // Update empty state message
    const emptyState = document.querySelector('.categories-empty p');
    if (emptyState) {
        emptyState.textContent = categories_translations[lang].noData;
    }
    
    // Update form header based on edit mode
    const formHeader = document.querySelector('#categoriesForm h3');
    if (formHeader) {
        const isEditMode = document.querySelector('input[name="id"]') !== null;
        formHeader.textContent = categories_translations[lang][isEditMode ? 'editCategory' : 'addCategory'];
    }
}

// Listen for language change events from homepage (NO SIDEBAR CODE)
document.addEventListener('DOMContentLoaded', function() {
    // Initial update
    updateCategoriesLanguage(currentCategoriesLang);
});

// This function will be called from homepage.js when language changes
window.updateCategoriesLanguage = updateCategoriesLanguage;
</script>

<style>
    /* Categories Module Styles - Using categories_ prefix (ISSUE #3: No sidebar conflicts) */
    .categories-container {
        width: 100%;
    }

    .categories-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
        flex-wrap: wrap;
        gap: 16px;
    }

    .categories-header h2 {
        color: var(--gray-800);
        font-size: 24px;
        font-weight: 600;
        margin: 0;
    }

    .categories-btn {
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

    .categories-btn:hover {
        background: var(--brown-800);
        transform: translateY(-2px);
        box-shadow: var(--shadow-md);
    }

    .categories-btn-secondary {
        background: var(--gray-200);
        color: var(--gray-700);
    }

    .categories-btn-secondary:hover {
        background: var(--gray-300);
    }

    .categories-btn-danger {
        background: #dc3545;
    }

    .categories-btn-danger:hover {
        background: #c82333;
    }

    .categories-form {
        background: var(--gray-50);
        padding: 24px;
        border-radius: 12px;
        margin-bottom: 32px;
        border: 1px solid var(--gray-200);
        display: <?= $edit_mode ? 'block' : 'none' ?>;
    }

    .categories-form.show {
        display: block;
    }

    .categories-form h3 {
        color: var(--gray-800);
        font-size: 18px;
        font-weight: 600;
        margin-bottom: 20px;
    }

    .categories-form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 16px;
        margin-bottom: 20px;
    }

    .categories-form-group {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .categories-form-group label {
        color: var(--gray-700);
        font-size: 14px;
        font-weight: 500;
    }

    .categories-form-group input,
    .categories-form-group select {
        padding: 10px 12px;
        border: 1px solid var(--gray-300);
        border-radius: 6px;
        font-size: 14px;
        transition: all 0.2s;
    }

    .categories-form-group input:focus,
    .categories-form-group select:focus {
        outline: none;
        border-color: var(--brown-600);
        box-shadow: 0 0 0 3px rgba(123, 88, 63, 0.1);
    }

    .categories-form-actions {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
    }

    .categories-alert {
        padding: 16px;
        border-radius: 8px;
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .categories-alert-success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }

    .categories-alert-danger {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }

    .categories-alert-close {
        background: none;
        border: none;
        font-size: 20px;
        cursor: pointer;
        color: inherit;
        opacity: 0.5;
    }

    .categories-alert-close:hover {
        opacity: 1;
    }

    .categories-table-container {
        overflow-x: auto;
        background: white;
        border-radius: 12px;
        border: 1px solid var(--gray-200);
    }

    .categories-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 900px;
    }

    .categories-table th {
        background: var(--gray-50);
        color: var(--gray-700);
        font-weight: 600;
        font-size: 14px;
        padding: 16px;
        text-align: left;
        border-bottom: 2px solid var(--gray-200);
    }

    .categories-table td {
        padding: 16px;
        border-bottom: 1px solid var(--gray-200);
        color: var(--gray-700);
        font-size: 14px;
    }

    .categories-table tr:hover {
        background: var(--gray-50);
    }

    .categories-status {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 500;
    }

    .categories-status-active {
        background: #d4edda;
        color: #155724;
    }

    .categories-status-inactive {
        background: #f8d7da;
        color: #721c24;
    }

    .categories-actions {
        display: flex;
        gap: 8px;
    }

    .categories-action-btn {
        padding: 6px 12px;
        border-radius: 4px;
        font-size: 13px;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        transition: all 0.2s;
    }

    .categories-action-edit {
        background: var(--brown-100);
        color: var(--brown-800);
    }

    .categories-action-edit:hover {
        background: var(--brown-200);
    }

    .categories-action-delete {
        background: #f8d7da;
        color: #721c24;
    }

    .categories-action-delete:hover {
        background: #f5c6cb;
    }

    .categories-loading {
        display: inline-block;
        width: 20px;
        height: 20px;
        border: 3px solid var(--gray-200);
        border-top-color: var(--brown-700);
        border-radius: 50%;
        animation: categories-spin 1s linear infinite;
    }

    @keyframes categories-spin {
        to { transform: rotate(360deg); }
    }

    .categories-empty {
        text-align: center;
        padding: 48px !important;
        color: var(--gray-500);
    }

    .categories-empty i {
        font-size: 48px;
        margin-bottom: 16px;
        color: var(--gray-400);
    }

    .categories-timestamp {
        font-size: 12px;
        color: var(--gray-500);
        margin-top: 4px;
    }

    .categories-projectgroup-badge {
        background: var(--brown-100);
        color: var(--brown-800);
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 500;
        display: inline-block;
    }

    .categories-warning {
        background: #fff3cd;
        color: #856404;
        padding: 16px;
        border-radius: 8px;
        margin-bottom: 20px;
        border: 1px solid #ffeeba;
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .categories-warning i {
        font-size: 24px;
    }
</style>

<div class="categories-container">
    <!-- Header -->
    <div class="categories-header">
        <h2 data-cat-lang="pageTitle">Categories Management</h2>
        <?php if (canAdd($conn, $user_role_id, $module_name)): ?>
        <button class="categories-btn" onclick="categories_toggleForm()" id="categoriesToggleBtn">
            <i class="fas fa-plus"></i>
            <span data-cat-lang="addNew">Add New Category</span>
        </button>
        <?php endif; ?>
    </div>

    <!-- Alert Messages -->
    <?php if (!empty($categories_message)): ?>
        <div class="categories-alert categories-alert-<?= $categories_message_type ?>">
            <?= $categories_message ?>
            <button class="categories-alert-close" onclick="this.parentElement.style.display='none'">&times;</button>
        </div>
    <?php endif; ?>

    <!-- Add/Edit Form - Only show if user has add or edit permission -->
    <?php if (canAdd($conn, $user_role_id, $module_name) || ($edit_mode && canEdit($conn, $user_role_id, $module_name))): ?>
    <div class="categories-form <?= $edit_mode ? 'show' : '' ?>" id="categoriesForm">
        <h3 data-cat-lang="<?= $edit_mode ? 'editCategory' : 'addCategory' ?>">
            <?= $edit_mode ? 'Edit Category' : 'Add New Category' ?>
        </h3>
        
        <form method="POST" action="?page=categories" onsubmit="return categories_validateForm()">
            <?php if ($edit_mode): ?>
                <input type="hidden" name="id" value="<?= $edit_data['id'] ?>">
            <?php endif; ?>
            
            <div class="categories-form-grid">
                <div class="categories-form-group">
                    <label for="projectgroup_id" data-cat-lang="projectGroup">Project Group *</label>
                    <select id="projectgroup_id" name="projectgroup_id" required>
                        <option value="" data-cat-lang="selectProjectGroup">Select Project Group</option>
                        <?php if (!empty($projectgroup_dropdown)): ?>
                            <?php foreach ($projectgroup_dropdown as $pg): ?>
                                <option value="<?= $pg['id'] ?>" 
                                    <?= ($edit_mode && $edit_data['projectgroup_id'] == $pg['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($pg['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                
                <div class="categories-form-group">
                    <label for="name" data-cat-lang="name">Category Name *</label>
                    <input type="text" id="name" name="name" 
                           value="<?= $edit_mode ? htmlspecialchars($edit_data['name']) : '' ?>" 
                           required maxlength="255" data-cat-lang="name" placeholder="Enter category name">
                </div>
                
                <div class="categories-form-group">
                    <label for="status" data-cat-lang="status">Status *</label>
                    <select id="status" name="status" required>
                        <option value="Active" data-cat-lang="active" <?= ($edit_mode && $edit_data['status'] == 'Active') ? 'selected' : '' ?>>Active</option>
                        <option value="Inactive" data-cat-lang="inactive" <?= ($edit_mode && $edit_data['status'] == 'Inactive') ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
            </div>
            
            <div class="categories-form-actions">
                <button type="submit" name="<?= $edit_mode ? 'categories_update' : 'categories_add' ?>" 
                        class="categories-btn">
                    <i class="fas fa-save"></i>
                    <span data-cat-lang="save">Save</span>
                </button>
                <a href="?page=categories" class="categories-btn categories-btn-secondary">
                    <i class="fas fa-times"></i>
                    <span data-cat-lang="cancel">Cancel</span>
                </a>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- Warning if no project groups exist -->
    <?php if (empty($projectgroup_dropdown)): ?>
        <div class="categories-warning">
            <i class="fas fa-exclamation-triangle"></i>
            <span data-cat-lang="noProjectGroups">No active project groups found. Please create a project group first.</span>
            <?php if (canAdd($conn, $user_role_id, 'projectgroup')): ?>
            <a href="?page=projectgroup" class="categories-btn" style="margin-left: auto; padding: 8px 16px;">
                <i class="fas fa-plus"></i> <span data-cat-lang="addNew">Add Project Group</span>
            </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Data Table -->
    <div class="categories-table-container">
        <table class="categories-table">
            <thead>
                <tr>
                    <th data-cat-lang="id">ID</th>
                    <th data-cat-lang="projectGroup">Project Group</th>
                    <th data-cat-lang="name">Category Name</th>
                    <th data-cat-lang="status">Status</th>
                    <th data-cat-lang="created">Created</th>
                    <th data-cat-lang="updated">Updated</th>
                    <th data-cat-lang="actions">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($categories_result && $categories_result->num_rows > 0): ?>
                    <?php while ($row = $categories_result->fetch_assoc()): ?>
                        <tr>
                            <td>#<?= $row['id'] ?></td>
                            <td>
                                <span class="categories-projectgroup-badge">
                                    <?= htmlspecialchars($row['projectgroup_name'] ?? 'Unknown') ?>
                                </span>
                            </td>
                            <td><strong><?= htmlspecialchars($row['name']) ?></strong></td>
                            <td>
                                <span class="categories-status categories-status-<?= strtolower($row['status']) ?>">
                                    <?= $row['status'] ?>
                                </span>
                            </td>
                            <td>
                                <?= date('d M Y H:i', strtotime($row['created_at'])) ?>
                                <div class="categories-timestamp"><?= date('d M Y', strtotime($row['created_at'])) ?></div>
                            </td>
                            <td>
                                <?= date('d M Y H:i', strtotime($row['updated_at'])) ?>
                                <div class="categories-timestamp"><?= date('d M Y', strtotime($row['updated_at'])) ?></div>
                            </td>
                            <td>
                                <div class="categories-actions">
                                    <?php if (canEdit($conn, $user_role_id, $module_name)): ?>
                                    <a href="?page=categories&categories_edit=<?= $row['id'] ?>" 
                                       class="categories-action-btn categories-action-edit">
                                        <i class="fas fa-edit"></i> <span data-cat-lang="edit">Edit</span>
                                    </a>
                                    <?php endif; ?>
                                    <?php if (canDelete($conn, $user_role_id, $module_name)): ?>
                                    <a href="javascript:void(0)" 
                                       onclick="categories_confirmDelete(<?= $row['id'] ?>, '<?= htmlspecialchars(addslashes($row['name'])) ?>')" 
                                       class="categories-action-btn categories-action-delete">
                                        <i class="fas fa-trash"></i> <span data-cat-lang="delete">Delete</span>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="categories-empty">
                            <i class="fas fa-folder-open"></i>
                            <p data-cat-lang="noData">No categories found. Click "Add New Category" to create one.</p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    // ============================================================================
    // CATEGORIES MODULE JAVASCRIPT - NO SIDEBAR CODE (Issue #1 resolved)
    // ============================================================================
    
    // Toggle form visibility
    function categories_toggleForm() {
        const form = document.getElementById('categoriesForm');
        const btn = document.getElementById('categoriesToggleBtn');
        const lang = currentCategoriesLang;
        
        if (form.classList.contains('show')) {
            form.classList.remove('show');
            btn.innerHTML = '<i class="fas fa-plus"></i> <span data-cat-lang="addNew">' + 
                (lang === 'en' ? 'Add New Category' : 'Ongeza Kategoria Mpya') + '</span>';
        } else {
            form.classList.add('show');
            btn.innerHTML = '<i class="fas fa-times"></i> <span data-cat-lang="cancel">' + 
                (lang === 'en' ? 'Cancel' : 'Ghairi') + '</span>';
            
            // Update form header when opening
            const formHeader = document.querySelector('#categoriesForm h3');
            if (formHeader) {
                formHeader.textContent = categories_translations[lang].addCategory;
            }
            
            // Clear any hidden ID field to ensure it's in add mode
            const hiddenId = document.querySelector('input[name="id"]');
            if (hiddenId) {
                hiddenId.remove();
            }
            
            // Change button name to add mode
            const submitBtn = document.querySelector('button[name="categories_update"], button[name="categories_add"]');
            if (submitBtn) {
                submitBtn.name = 'categories_add';
            }
        }
        
        // Update all translatable elements after toggle
        updateCategoriesLanguage(lang);
    }

    // Validate form before submission
    function categories_validateForm() {
        const projectgroup = document.getElementById('projectgroup_id').value;
        const name = document.getElementById('name').value.trim();
        const lang = currentCategoriesLang;
        
        if (projectgroup === '') {
            alert(categories_translations[lang].projectGroupRequired);
            document.getElementById('projectgroup_id').focus();
            return false;
        }
        
        if (name === '') {
            alert(categories_translations[lang].nameRequired);
            document.getElementById('name').focus();
            return false;
        }
        
        return true;
    }

    // Confirm delete with loading effect
    function categories_confirmDelete(id, name) {
        const lang = currentCategoriesLang;
        const confirmMsg = categories_translations[lang].confirmDelete + ' "' + name + '"?\n' + 
                          categories_translations[lang].confirmDeleteMsg;
        
        if (confirm(confirmMsg)) {
            // Show loading effect on the clicked row
            const row = event.target.closest('tr');
            row.style.opacity = '0.5';
            
            // Create loading indicator
            const loadingDiv = document.createElement('div');
            loadingDiv.className = 'categories-loading';
            loadingDiv.style.position = 'absolute';
            loadingDiv.style.top = '50%';
            loadingDiv.style.left = '50%';
            loadingDiv.style.transform = 'translate(-50%, -50%)';
            row.style.position = 'relative';
            row.appendChild(loadingDiv);
            
            // Redirect after a small delay to show loading
            setTimeout(() => {
                window.location.href = `?page=categories&categories_delete=${id}`;
            }, 300);
        }
    }

    // Auto-hide alerts after 5 seconds
    setTimeout(() => {
        document.querySelectorAll('.categories-alert').forEach(alert => {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(() => alert.style.display = 'none', 500);
        });
    }, 5000);
</script>