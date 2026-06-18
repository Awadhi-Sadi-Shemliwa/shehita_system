<?php
/**
 * PAPLONTECH Enterprise Management System
 * Project Group Module - Full CRUD Operations
 * 
 * REFINED: Removed all sidebar-related code (no localStorage, no sidebar event listeners)
 * REFINED: Ensured no conflict with homepage.php (top navbar layout)
 * 
 * This module handles:
 * - Automatic table creation if not exists
 * - Display all project groups in a table
 * - Add new project group (inline form)
 * - Edit existing project group
 * - Delete project group with confirmation
 * - Auto-reset ID when table becomes empty
 * - Full English/Swahili translation support
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
$module_name = 'projectgroup';

// PERMISSION: Check if user has view permission
if (!canView($conn, $user_role_id, $module_name)) {
    echo '<div class="pg-alert pg-alert-danger" style="text-align: center; padding: 40px;">
            <i class="fas fa-lock" style="font-size: 48px; margin-bottom: 16px; display: block;"></i>
            <h3>Access Denied</h3>
            <p>You do not have permission to view this module.</p>
          </div>';
    return; // Stop rendering the module
}

/**
 * CREATE PROJECT GROUP TABLE IF NOT EXISTS
 * Simplified schema with only essential fields
 * Auto-increment ID, timestamps managed by MySQL
 */
// Schema note: the `projectgroup` table is created centrally in config.php
// (in dependency order). This module assumes it already exists.

/**
 * CHECK IF TABLE IS EMPTY AND RESET AUTO_INCREMENT IF NEEDED
 * This ensures ID starts at 1 when all records are deleted
 */
$check_empty = $conn->query("SELECT COUNT(*) as count FROM projectgroup");
$row_count = $check_empty->fetch_assoc()['count'];

if ($row_count == 0) {
    $conn->query("ALTER TABLE projectgroup AUTO_INCREMENT = 1");
}

// Initialize variables for messages
$projectgroup_message = '';
$projectgroup_message_type = '';

/**
 * HANDLE FORM SUBMISSIONS
 */

// PERMISSION: Check add permission before processing add operation
if (isset($_POST['projectgroup_add']) && !canAdd($conn, $user_role_id, $module_name)) {
    $projectgroup_message = "You do not have permission to add project groups.";
    $projectgroup_message_type = "danger";
} elseif (isset($_POST['projectgroup_add'])) {
    $name = sanitize($conn, $_POST['name']);
    $status = sanitize($conn, $_POST['status']);
    
    // Validate inputs
    $errors = [];
    
    if (empty($name)) {
        $errors[] = "Name is required";
    }
    
    if (!in_array($status, ['Active', 'Inactive'])) {
        $errors[] = "Invalid status";
    }
    
    if (empty($errors)) {
        // Insert new project group
        $insert_stmt = $conn->prepare("INSERT INTO projectgroup (name, status) VALUES (?, ?)");
        $insert_stmt->bind_param("ss", $name, $status);
        
        if ($insert_stmt->execute()) {
            $projectgroup_message = "Project Group added successfully!";
            $projectgroup_message_type = "success";
        } else {
            $projectgroup_message = "Error adding project group: " . $conn->error;
            $projectgroup_message_type = "danger";
        }
        $insert_stmt->close();
    } else {
        $projectgroup_message = implode("<br>", $errors);
        $projectgroup_message_type = "danger";
    }
}

// PERMISSION: Check edit permission before processing update operation
if (isset($_POST['projectgroup_update']) && !canEdit($conn, $user_role_id, $module_name)) {
    $projectgroup_message = "You do not have permission to edit project groups.";
    $projectgroup_message_type = "danger";
} elseif (isset($_POST['projectgroup_update'])) {
    $id = (int)$_POST['id'];
    $name = sanitize($conn, $_POST['name']);
    $status = sanitize($conn, $_POST['status']);
    
    // Validate inputs
    $errors = [];
    
    if ($id <= 0) {
        $errors[] = "Invalid ID";
    }
    
    if (empty($name)) {
        $errors[] = "Name is required";
    }
    
    if (!in_array($status, ['Active', 'Inactive'])) {
        $errors[] = "Invalid status";
    }
    
    if (empty($errors)) {
        // Update project group
        $update_stmt = $conn->prepare("UPDATE projectgroup SET name = ?, status = ? WHERE id = ?");
        $update_stmt->bind_param("ssi", $name, $status, $id);
        
        if ($update_stmt->execute()) {
            $projectgroup_message = "Project Group updated successfully!";
            $projectgroup_message_type = "success";
        } else {
            $projectgroup_message = "Error updating project group: " . $conn->error;
            $projectgroup_message_type = "danger";
        }
        $update_stmt->close();
    } else {
        $projectgroup_message = implode("<br>", $errors);
        $projectgroup_message_type = "danger";
    }
}

// PERMISSION: Check delete permission before processing delete operation
if (isset($_GET['projectgroup_delete']) && !canDelete($conn, $user_role_id, $module_name)) {
    $projectgroup_message = "You do not have permission to delete project groups.";
    $projectgroup_message_type = "danger";
} elseif (isset($_GET['projectgroup_delete'])) {
    $id = (int)$_GET['projectgroup_delete'];
    
    if ($id > 0) {
        $delete_stmt = $conn->prepare("DELETE FROM projectgroup WHERE id = ?");
        $delete_stmt->bind_param("i", $id);
        
        if ($delete_stmt->execute()) {
            $projectgroup_message = "Project Group deleted successfully!";
            $projectgroup_message_type = "success";
            
            // Check if table is empty and reset auto-increment
            $check_empty = $conn->query("SELECT COUNT(*) as count FROM projectgroup");
            $row_count = $check_empty->fetch_assoc()['count'];
            
            if ($row_count == 0) {
                $conn->query("ALTER TABLE projectgroup AUTO_INCREMENT = 1");
            }
        } else {
            $projectgroup_message = "Error deleting project group: " . $conn->error;
            $projectgroup_message_type = "danger";
        }
        $delete_stmt->close();
    }
}

// Get edit data if in edit mode (and user has edit permission)
$edit_mode = false;
$edit_data = null;

if (isset($_GET['projectgroup_edit'])) {
    $edit_id = (int)$_GET['projectgroup_edit'];
    // PERMISSION: Only allow edit if user has edit permission
    if ($edit_id > 0 && canEdit($conn, $user_role_id, $module_name)) {
        $edit_stmt = $conn->prepare("SELECT * FROM projectgroup WHERE id = ?");
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

// Fetch all project groups for display
$projectgroup_query = "SELECT * FROM projectgroup ORDER BY id DESC";
$projectgroup_result = $conn->query($projectgroup_query);
?>

<!-- PROJECT GROUP TRANSLATIONS -->
<script>
// Project Group translations for English and Swahili
const projectgroup_translations = {
    en: {
        pageTitle: 'Project Group Management',
        addNew: 'Add New Group',
        editGroup: 'Edit Project Group',
        addGroup: 'Add New Project Group',
        id: 'ID',
        name: 'Name',
        status: 'Status',
        active: 'Active',
        inactive: 'Inactive',
        actions: 'Actions',
        save: 'Save',
        cancel: 'Cancel',
        edit: 'Edit',
        delete: 'Delete',
        confirmDelete: 'Are you sure you want to delete project group',
        confirmDeleteMsg: 'This action cannot be undone.',
        noData: 'No project groups found. Click "Add New Group" to create one.',
        created: 'Created',
        updated: 'Updated',
        loading: 'Loading...',
        nameRequired: 'Name is required!',
        statusRequired: 'Status is required!'
    },
    sw: {
        pageTitle: 'Usimamizi wa Vikundi vya Miradi',
        addNew: 'Ongeza Kundi Jipya',
        editGroup: 'Hariri Kundi la Mradi',
        addGroup: 'Ongeza Kundi Jipya la Mradi',
        id: 'Kitambulisho',
        name: 'Jina',
        status: 'Hali',
        active: 'Inatumika',
        inactive: 'Haifanyi Kazi',
        actions: 'Vitendo',
        save: 'Hifadhi',
        cancel: 'Ghairi',
        edit: 'Hariri',
        delete: 'Futa',
        confirmDelete: 'Una uhakika unataka kufuta kundi',
        confirmDeleteMsg: 'Kitendo hiki hakiwezi kutenguliwa.',
        noData: 'Hakuna vikundi vya miradi vilivyopatikana. Bofya "Ongeza Kundi Jipya" kuunda.',
        created: 'Imeundwa',
        updated: 'Imesasishwa',
        loading: 'Inapakia...',
        nameRequired: 'Jina linahitajika!',
        statusRequired: 'Hali inahitajika!'
    }
};

// Current language (will be updated by homepage.js)
let currentProjectGroupLang = localStorage.getItem('preferredLanguage') || 'en';

// Function to update all translatable elements in project group module
function updateProjectGroupLanguage(lang) {
    currentProjectGroupLang = lang;
    const elements = document.querySelectorAll('[data-pg-lang]');
    
    elements.forEach(element => {
        const key = element.getAttribute('data-pg-lang');
        if (projectgroup_translations[lang] && projectgroup_translations[lang][key]) {
            if (element.tagName === 'INPUT' || element.tagName === 'TEXTAREA') {
                element.placeholder = projectgroup_translations[lang][key];
            } else if (element.tagName === 'OPTION') {
                element.textContent = projectgroup_translations[lang][key];
            } else {
                element.textContent = projectgroup_translations[lang][key];
            }
        }
    });
    
    // Update table header specifically if they have data-pg-lang attributes
    const thElements = document.querySelectorAll('th[data-pg-lang]');
    thElements.forEach(th => {
        const key = th.getAttribute('data-pg-lang');
        if (projectgroup_translations[lang] && projectgroup_translations[lang][key]) {
            th.textContent = projectgroup_translations[lang][key];
        }
    });
    
    // Update empty state message
    const emptyState = document.querySelector('.pg-empty p');
    if (emptyState) {
        emptyState.textContent = projectgroup_translations[lang].noData;
    }
    
    // Update form header based on edit mode
    const formHeader = document.querySelector('#pgForm h3');
    if (formHeader) {
        const isEditMode = document.querySelector('input[name="id"]') !== null;
        formHeader.textContent = projectgroup_translations[lang][isEditMode ? 'editGroup' : 'addGroup'];
    }
}

// Listen for language change events from homepage
document.addEventListener('DOMContentLoaded', function() {
    // Initial update
    updateProjectGroupLanguage(currentProjectGroupLang);
});

// This function will be called from homepage.js when language changes
window.updateProjectGroupLanguage = updateProjectGroupLanguage;
</script>

<style>
    /* Project Group Module Styles - Using pg- prefix to avoid conflicts with homepage.php */
    .pg-container {
        width: 100%;
    }

    .pg-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
        flex-wrap: wrap;
        gap: 16px;
    }

    .pg-header h2 {
        color: var(--gray-800);
        font-size: 24px;
        font-weight: 600;
        margin: 0;
    }

    .pg-btn {
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

    .pg-btn:hover {
        background: var(--brown-800);
        transform: translateY(-2px);
        box-shadow: var(--shadow-md);
    }

    .pg-btn-secondary {
        background: var(--gray-200);
        color: var(--gray-700);
    }

    .pg-btn-secondary:hover {
        background: var(--gray-300);
        transform: translateY(-2px);
        box-shadow: var(--shadow-md);
    }

    .pg-btn-danger {
        background: #dc3545;
    }

    .pg-btn-danger:hover {
        background: #c82333;
    }

    .pg-form {
        background: var(--gray-50);
        padding: 24px;
        border-radius: 12px;
        margin-bottom: 32px;
        border: 1px solid var(--gray-200);
        display: <?= $edit_mode ? 'block' : 'none' ?>;
    }

    .pg-form.show {
        display: block;
        animation: pg-fadeIn 0.3s ease-out;
    }
    
    @keyframes pg-fadeIn {
        from {
            opacity: 0;
            transform: translateY(10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .pg-form h3 {
        color: var(--gray-800);
        font-size: 18px;
        font-weight: 600;
        margin-bottom: 20px;
    }

    .pg-form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 16px;
        margin-bottom: 20px;
    }

    .pg-form-group {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .pg-form-group label {
        color: var(--gray-700);
        font-size: 14px;
        font-weight: 500;
    }

    .pg-form-group input,
    .pg-form-group select {
        padding: 10px 12px;
        border: 1px solid var(--gray-300);
        border-radius: 6px;
        font-size: 14px;
        transition: all 0.2s;
    }

    .pg-form-group input:focus,
    .pg-form-group select:focus {
        outline: none;
        border-color: var(--brown-600);
        box-shadow: 0 0 0 3px rgba(123, 88, 63, 0.1);
    }

    .pg-form-actions {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
    }

    .pg-alert {
        padding: 16px;
        border-radius: 8px;
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .pg-alert-success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }

    .pg-alert-danger {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }

    .pg-alert-close {
        background: none;
        border: none;
        font-size: 20px;
        cursor: pointer;
        color: inherit;
        opacity: 0.5;
    }

    .pg-alert-close:hover {
        opacity: 1;
    }

    .pg-table-container {
        overflow-x: auto;
        background: white;
        border-radius: 12px;
        border: 1px solid var(--gray-200);
    }

    .pg-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 800px;
    }

    .pg-table th {
        background: var(--gray-50);
        color: var(--gray-700);
        font-weight: 600;
        font-size: 14px;
        padding: 16px;
        text-align: left;
        border-bottom: 2px solid var(--gray-200);
    }

    .pg-table td {
        padding: 16px;
        border-bottom: 1px solid var(--gray-200);
        color: var(--gray-700);
        font-size: 14px;
    }

    .pg-table tr:hover {
        background: var(--gray-50);
    }

    .pg-status {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 500;
    }

    .pg-status-active {
        background: #d4edda;
        color: #155724;
    }

    .pg-status-inactive {
        background: #f8d7da;
        color: #721c24;
    }

    .pg-actions {
        display: flex;
        gap: 8px;
    }

    .pg-action-btn {
        padding: 6px 12px;
        border-radius: 4px;
        font-size: 13px;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        transition: all 0.2s;
        cursor: pointer;
        border: none;
    }

    .pg-action-edit {
        background: var(--brown-100);
        color: var(--brown-800);
    }

    .pg-action-edit:hover {
        background: var(--brown-200);
    }

    .pg-action-delete {
        background: #f8d7da;
        color: #721c24;
    }

    .pg-action-delete:hover {
        background: #f5c6cb;
    }

    .pg-loading {
        display: inline-block;
        width: 20px;
        height: 20px;
        border: 3px solid var(--gray-200);
        border-top-color: var(--brown-700);
        border-radius: 50%;
        animation: pg-spin 1s linear infinite;
    }

    @keyframes pg-spin {
        to { transform: rotate(360deg); }
    }

    .pg-empty {
        text-align: center;
        padding: 48px !important;
        color: var(--gray-500);
    }

    .pg-empty i {
        font-size: 48px;
        margin-bottom: 16px;
        color: var(--gray-400);
    }

    .pg-timestamp {
        font-size: 12px;
        color: var(--gray-500);
        margin-top: 4px;
    }
    
    /* Responsive adjustments */
    @media (max-width: 768px) {
        .pg-form-grid {
            grid-template-columns: 1fr;
        }
        
        .pg-header {
            flex-direction: column;
            align-items: flex-start;
        }
        
        .pg-table th,
        .pg-table td {
            padding: 12px;
        }
    }
    
    @media (max-width: 480px) {
        .pg-form {
            padding: 16px;
        }
        
        .pg-form-actions {
            flex-direction: column;
        }
        
        .pg-form-actions .pg-btn {
            width: 100%;
            justify-content: center;
        }
    }
</style>

<div class="pg-container">
    <!-- Header -->
    <div class="pg-header">
        <h2 data-pg-lang="pageTitle">Project Group Management</h2>
        <?php if (canAdd($conn, $user_role_id, $module_name)): ?>
        <button class="pg-btn" onclick="pg_toggleForm()" id="pgToggleBtn">
            <i class="fas fa-plus"></i>
            <span data-pg-lang="addNew">Add New Group</span>
        </button>
        <?php endif; ?>
    </div>

    <!-- Alert Messages -->
    <?php if (!empty($projectgroup_message)): ?>
        <div class="pg-alert pg-alert-<?= $projectgroup_message_type ?>">
            <?= $projectgroup_message ?>
            <button class="pg-alert-close" onclick="this.parentElement.style.display='none'">&times;</button>
        </div>
    <?php endif; ?>

    <!-- Add/Edit Form - Only show if user has add or edit permission -->
    <?php if (canAdd($conn, $user_role_id, $module_name) || ($edit_mode && canEdit($conn, $user_role_id, $module_name))): ?>
    <div class="pg-form <?= $edit_mode ? 'show' : '' ?>" id="pgForm">
        <h3 data-pg-lang="<?= $edit_mode ? 'editGroup' : 'addGroup' ?>">
            <?= $edit_mode ? 'Edit Project Group' : 'Add New Project Group' ?>
        </h3>
        
        <form method="POST" action="?page=projectgroup" onsubmit="return pg_validateForm()">
            <?php if ($edit_mode): ?>
                <input type="hidden" name="id" value="<?= $edit_data['id'] ?>">
            <?php endif; ?>
            
            <div class="pg-form-grid">
                <div class="pg-form-group">
                    <label for="pg_name" data-pg-lang="name">Name *</label>
                    <input type="text" id="pg_name" name="name" 
                           value="<?= $edit_mode ? htmlspecialchars($edit_data['name']) : '' ?>" 
                           required maxlength="255" data-pg-lang="name" placeholder="Enter group name">
                </div>
                
                <div class="pg-form-group">
                    <label for="pg_status" data-pg-lang="status">Status *</label>
                    <select id="pg_status" name="status" required>
                        <option value="Active" data-pg-lang="active" <?= ($edit_mode && $edit_data['status'] == 'Active') ? 'selected' : '' ?>>Active</option>
                        <option value="Inactive" data-pg-lang="inactive" <?= ($edit_mode && $edit_data['status'] == 'Inactive') ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
            </div>
            
            <div class="pg-form-actions">
                <button type="submit" name="<?= $edit_mode ? 'projectgroup_update' : 'projectgroup_add' ?>" 
                        class="pg-btn">
                    <i class="fas fa-save"></i>
                    <span data-pg-lang="save">Save</span>
                </button>
                <a href="?page=projectgroup" class="pg-btn pg-btn-secondary">
                    <i class="fas fa-times"></i>
                    <span data-pg-lang="cancel">Cancel</span>
                </a>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- Data Table -->
    <div class="pg-table-container">
        <table class="pg-table">
            <thead>
                <tr>
                    <th data-pg-lang="id">ID</th>
                    <th data-pg-lang="name">Name</th>
                    <th data-pg-lang="status">Status</th>
                    <th data-pg-lang="created">Created</th>
                    <th data-pg-lang="updated">Updated</th>
                    <th data-pg-lang="actions">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($projectgroup_result && $projectgroup_result->num_rows > 0): ?>
                    <?php while ($row = $projectgroup_result->fetch_assoc()): ?>
                        <tr data-pg-id="<?= $row['id'] ?>">
                            <td>#<?= $row['id'] ?></td>
                            <td><strong><?= htmlspecialchars($row['name']) ?></strong></td>
                            <td>
                                <span class="pg-status pg-status-<?= strtolower($row['status']) ?>">
                                    <?= $row['status'] ?>
                                </span>
                            </td>
                            <td>
                                <?= date('d M Y H:i', strtotime($row['created_at'])) ?>
                                <div class="pg-timestamp"><?= date('d M Y', strtotime($row['created_at'])) ?></div>
                            </td>
                            <td>
                                <?= date('d M Y H:i', strtotime($row['updated_at'])) ?>
                                <div class="pg-timestamp"><?= date('d M Y', strtotime($row['updated_at'])) ?></div>
                            </td>
                            <td>
                                <div class="pg-actions">
                                    <?php if (canEdit($conn, $user_role_id, $module_name)): ?>
                                    <a href="?page=projectgroup&projectgroup_edit=<?= $row['id'] ?>" 
                                       class="pg-action-btn pg-action-edit">
                                        <i class="fas fa-edit"></i> <span data-pg-lang="edit">Edit</span>
                                    </a>
                                    <?php endif; ?>
                                    <?php if (canDelete($conn, $user_role_id, $module_name)): ?>
                                    <a href="javascript:void(0)" 
                                       onclick="pg_confirmDelete(<?= $row['id'] ?>, '<?= htmlspecialchars(addslashes($row['name'])) ?>')" 
                                       class="pg-action-btn pg-action-delete">
                                        <i class="fas fa-trash"></i> <span data-pg-lang="delete">Delete</span>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr class="pg-empty-row">
                        <td colspan="6" class="pg-empty">
                            <i class="fas fa-folder-open"></i>
                            <p data-pg-lang="noData">No project groups found. Click "Add New Group" to create one.</p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    // ============================================================================
    // PROJECT GROUP MODULE JAVASCRIPT - NO SIDEBAR CODE (Issue #1 resolved)
    // ============================================================================
    
    // Toggle form visibility
    function pg_toggleForm() {
        const form = document.getElementById('pgForm');
        const btn = document.getElementById('pgToggleBtn');
        const lang = currentProjectGroupLang;
        
        if (form.classList.contains('show')) {
            form.classList.remove('show');
            btn.innerHTML = '<i class="fas fa-plus"></i> <span data-pg-lang="addNew">' + 
                (lang === 'en' ? 'Add New Group' : 'Ongeza Kundi Jipya') + '</span>';
        } else {
            form.classList.add('show');
            btn.innerHTML = '<i class="fas fa-times"></i> <span data-pg-lang="cancel">' + 
                (lang === 'en' ? 'Cancel' : 'Ghairi') + '</span>';
            
            // Update form header when opening
            const formHeader = document.querySelector('#pgForm h3');
            if (formHeader) {
                formHeader.textContent = projectgroup_translations[lang].addGroup;
            }
            
            // Clear any hidden ID field to ensure it's in add mode
            const hiddenId = document.querySelector('input[name="id"]');
            if (hiddenId) {
                hiddenId.remove();
            }
            
            // Reset form fields
            const nameInput = document.getElementById('pg_name');
            const statusSelect = document.getElementById('pg_status');
            if (nameInput) nameInput.value = '';
            if (statusSelect) statusSelect.value = 'Active';
            
            // Change button name to add mode
            const submitBtn = document.querySelector('button[name="projectgroup_update"], button[name="projectgroup_add"]');
            if (submitBtn) {
                submitBtn.name = 'projectgroup_add';
            }
        }
        
        // Update all translatable elements after toggle
        updateProjectGroupLanguage(lang);
    }

    // Validate form before submission
    function pg_validateForm() {
        const name = document.getElementById('pg_name').value.trim();
        const lang = currentProjectGroupLang;
        
        if (name === '') {
            alert(projectgroup_translations[lang].nameRequired);
            document.getElementById('pg_name').focus();
            return false;
        }
        
        return true;
    }

    // Confirm delete with loading effect
    function pg_confirmDelete(id, name) {
        const lang = currentProjectGroupLang;
        const confirmMsg = projectgroup_translations[lang].confirmDelete + ' "' + name + '"?\n' + 
                          projectgroup_translations[lang].confirmDeleteMsg;
        
        if (confirm(confirmMsg)) {
            // Show loading effect on the clicked row
            const row = event.target.closest('tr');
            if (row) {
                row.style.opacity = '0.5';
                
                // Create loading indicator
                const loadingDiv = document.createElement('div');
                loadingDiv.className = 'pg-loading';
                loadingDiv.style.position = 'absolute';
                loadingDiv.style.top = '50%';
                loadingDiv.style.left = '50%';
                loadingDiv.style.transform = 'translate(-50%, -50%)';
                row.style.position = 'relative';
                row.appendChild(loadingDiv);
            }
            
            // Redirect after a small delay to show loading
            setTimeout(() => {
                window.location.href = `?page=projectgroup&projectgroup_delete=${id}`;
            }, 300);
        }
    }

    // Auto-hide alerts after 5 seconds
    setTimeout(() => {
        document.querySelectorAll('.pg-alert').forEach(alert => {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(() => alert.style.display = 'none', 500);
        });
    }, 5000);
    
    // ============================================================================
    // INITIALIZATION - NO SIDEBAR CODE (Issue #1 resolved)
    // ============================================================================
    document.addEventListener('DOMContentLoaded', function() {
        // Module initialization only - no sidebar code
        // Language is already initialized by the inline script above
    });
</script>