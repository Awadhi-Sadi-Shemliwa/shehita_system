<?php
/**
 * SHEHITA Enterprise Management System
 * Operations Module - Operations & Quality Management
 * 
 * REFINED: Removed all sidebar-related code (no localStorage, no sidebar event listeners)
 * REFINED: Added foreign key table validation with user-friendly error messages
 * REFINED: Ensured no conflict with homepage.php (top navbar layout)
 * 
 * This module handles:
 * - Automatic table creation if not exists
 * - Foreign key table validation with graceful error handling
 * - Contract selection with dynamic client details display
 * - Dependent dropdowns (Project Group → Category)
 * - Staff assignment inline editable grid
 * - Invoice modal with print functionality
 * - KPI cards that update based on filtered data
 * - Status auto-update based on dates
 * - Full CRUD operations with permission checks
 * - Full English/Swahili translation support
 * 
 * PERMISSION ENHANCED: Buttons respect user permissions (can_add, can_edit, can_delete)
 * 
 * STATUS VALUES: Only Active, Completed, Inactive
 */

// Prevent direct access
if (!defined('ACCESS_ALLOWED') && basename($_SERVER['PHP_SELF']) == basename(__FILE__)) {
    // This file is included, so we're safe
}

// Get database connection from parent
global $conn;

// PERMISSION: Get user role and check module permissions
$user_role_id = $_SESSION['role_id'] ?? 0;
$module_name = 'operations';

// PERMISSION: Check if user has view permission
if (!canView($conn, $user_role_id, $module_name)) {
    echo '<div class="operations-alert operations-alert-danger" style="text-align: center; padding: 40px;">
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

// Check for projectgroup table
$check_pg = $conn->query("SHOW TABLES LIKE 'projectgroup'");
if ($check_pg->num_rows == 0) {
    $missing_tables[] = ['table' => 'projectgroup', 'module' => 'projectgroup', 'display' => 'Project Groups'];
}

// Check for categories table
$check_cat = $conn->query("SHOW TABLES LIKE 'categories'");
if ($check_cat->num_rows == 0) {
    $missing_tables[] = ['table' => 'categories', 'module' => 'categories', 'display' => 'Categories'];
}

// Check for customers table (via projects join)
$check_cust = $conn->query("SHOW TABLES LIKE 'customers'");
if ($check_cust->num_rows == 0) {
    $missing_tables[] = ['table' => 'customers', 'module' => 'customer-management', 'display' => 'Customer Management'];
}

// Check for projects table
$check_proj = $conn->query("SHOW TABLES LIKE 'projects'");
if ($check_proj->num_rows == 0) {
    $missing_tables[] = ['table' => 'projects', 'module' => 'projects', 'display' => 'Projects'];
}

// Display error message if any required tables are missing
if (!empty($missing_tables)) {
    echo '<div class="operations-container" style="max-width: 800px; margin: 40px auto;">';
    echo '<div class="operations-alert operations-alert-danger" style="background: #fff3cd; color: #856404; border-color: #ffeeba; text-align: left;">';
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

// Create operations table if not exists (status ENUM: Active, Completed, Inactive only)
// Schema note: the `operations` table is created centrally in config.php
// (in dependency order). This module assumes it already exists.

/**
 * ============================================================================
 * CHECK IF TABLE IS EMPTY AND RESET AUTO_INCREMENT IF NEEDED
 * ============================================================================
 */
$check_empty = $conn->query("SELECT COUNT(*) as count FROM operations");
$row_count = $check_empty->fetch_assoc()['count'];

if ($row_count == 0) {
    $conn->query("ALTER TABLE operations AUTO_INCREMENT = 1");
}

/**
 * ============================================================================
 * GENERATE NEXT INVOICE ID
 * Format: OPS-00001, OPS-00002, etc.
 * ============================================================================
 */
function getNextInvoiceId($conn) {
    $result = $conn->query("SELECT invoice_id FROM operations ORDER BY id DESC LIMIT 1");
    if ($result && $result->num_rows > 0) {
        $last = $result->fetch_assoc()['invoice_id'];
        $num = (int)substr($last, 4);
        $next = $num + 1;
        return 'OPS-' . str_pad($next, 5, '0', STR_PAD_LEFT);
    }
    return 'OPS-00001';
}

/**
 * ============================================================================
 * STATUS AUTO-UPDATE RULE
 * Rule: 
 * - If end_date < CURDATE() AND status != 'Inactive' → change to 'Completed'
 * - If start_date <= CURDATE() <= end_date → status = 'Active'
 * - If start_date > CURDATE() → status = 'Active' (future dated operations are Active)
 * Status 'Inactive' is manually set and NEVER auto-changed
 * ============================================================================
 */
function autoUpdateOperationStatus($conn) {
    // Update to Completed if end_date is in the past and not Inactive
    $auto_update_sql = "UPDATE operations 
                        SET status = 'Completed' 
                        WHERE end_date < CURDATE() 
                        AND status != 'Inactive'
                        AND status != 'Completed'";
    $conn->query($auto_update_sql);
    
    // Update to Active if start_date <= today <= end_date and not Inactive/Completed
    $auto_update_sql = "UPDATE operations 
                        SET status = 'Active' 
                        WHERE start_date <= CURDATE() 
                        AND end_date >= CURDATE()
                        AND status != 'Inactive'
                        AND status != 'Completed'
                        AND status != 'Active'";
    $conn->query($auto_update_sql);
    
    // Update to Active if start_date > today (future dated operations)
    $auto_update_sql = "UPDATE operations 
                        SET status = 'Active' 
                        WHERE start_date > CURDATE()
                        AND status != 'Inactive'
                        AND status != 'Completed'
                        AND status != 'Active'";
    $conn->query($auto_update_sql);
}

// Run status auto-update on page load
autoUpdateOperationStatus($conn);

/**
 * ============================================================================
 * CSRF PROTECTION
 * ============================================================================
 */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Initialize variables for messages
$operations_message = '';
$operations_message_type = '';

// Initialize search/filter/pagination variables
$search = isset($_GET['search']) ? sanitize($conn, $_GET['search']) : '';
$status_filter = isset($_GET['status_filter']) ? sanitize($conn, $_GET['status_filter']) : '';
$current_page = isset($_GET['page_num']) ? (int)$_GET['page_num'] : 1;
$records_per_page = 10;
$offset = ($current_page - 1) * $records_per_page;

/**
 * ============================================================================
 * STATUS OPTIONS (Active, Completed, Inactive only)
 * ============================================================================
 */
$status_options = [
    'Active' => 'Active',
    'Completed' => 'Completed',
    'Inactive' => 'Inactive'
];

/**
 * ============================================================================
 * DURATION TYPE OPTIONS
 * ============================================================================
 */
$duration_options = [
    'Non Recurring' => 'Non Recurring',
    'Recurring - Monthly' => 'Recurring - Monthly',
    'Recurring - Quarterly' => 'Recurring - Quarterly',
    'Recurring - Semi Annually' => 'Recurring - Semi Annually',
    'Recurring - Annually' => 'Recurring - Annually'
];

/**
 * ============================================================================
 * STATUS DETERMINATION HELPER
 * ============================================================================
 */
function determineOperationStatus($start_date, $end_date, $selected_status) {
    $today = date('Y-m-d');
    
    // RULE: If user selected 'Inactive', ALWAYS respect the user's choice
    if ($selected_status === 'Inactive') {
        return 'Inactive';
    }
    
    // RULE: If end_date < today → override to 'Completed'
    if ($end_date < $today) {
        return 'Completed';
    }
    
    // RULE: If start_date <= today <= end_date → override to 'Active'
    if ($start_date <= $today && $end_date >= $today) {
        return 'Active';
    }
    
    // RULE: If start_date > today → override to 'Active' (future dated)
    if ($start_date > $today) {
        return 'Active';
    }
    
    // Default to user's selection
    return $selected_status;
}

/**
 * ============================================================================
 * HANDLE FORM SUBMISSIONS WITH PERMISSION CHECKS
 * ============================================================================
 */

// PERMISSION: Check add permission before processing add operation
if (isset($_POST['operations_add']) && !canAdd($conn, $user_role_id, $module_name)) {
    $operations_message = "You do not have permission to add operations.";
    $operations_message_type = "danger";
} elseif (isset($_POST['operations_add'])) {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $operations_message = "Invalid form submission. Please try again.";
        $operations_message_type = "danger";
    } else {
        $invoice_id = getNextInvoiceId($conn);
        $contract_number = sanitize($conn, $_POST['contract_number']);
        $project_group_id = (int)$_POST['project_group_id'];
        $category_id = (int)$_POST['category_id'];
        $duration_type = sanitize($conn, $_POST['duration_type']);
        $start_date = sanitize($conn, $_POST['start_date']);
        $end_date = sanitize($conn, $_POST['end_date']);
        $selected_status = sanitize($conn, $_POST['status']);
        $assigned_staff = isset($_POST['assigned_staff']) ? $_POST['assigned_staff'] : '[]';
        $created_by = $_SESSION['name'];
        
        // Determine final status based on dates and user selection
        $status = determineOperationStatus($start_date, $end_date, $selected_status);
        
        // Validate inputs
        $errors = [];
        
        if (empty($contract_number)) {
            $errors[] = "Please select a contract";
        }
        
        if ($project_group_id <= 0) {
            $errors[] = "Please select a project group";
        }
        
        if ($category_id <= 0) {
            $errors[] = "Please select a category";
        }
        
        if (!array_key_exists($duration_type, $duration_options)) {
            $errors[] = "Invalid duration type";
        }
        
        if (empty($start_date)) {
            $errors[] = "Start date is required";
        }
        
        if (empty($end_date)) {
            $errors[] = "End date is required";
        }
        
        if ($end_date < $start_date) {
            $errors[] = "End date must be on or after start date";
        }
        
        if (!array_key_exists($selected_status, $status_options)) {
            $errors[] = "Invalid status";
        }
        
        // Validate assigned_staff JSON
        $staff_json = json_decode($assigned_staff, true);
        if ($staff_json === null) {
            $errors[] = "Invalid staff data format";
        }
        
        if (empty($errors)) {
            // Insert new operation
            $insert_stmt = $conn->prepare("INSERT INTO operations (
                invoice_id, contract_number, project_group_id, category_id,
                duration_type, start_date, end_date, status, assigned_staff, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $insert_stmt->bind_param("ssiissssss", 
                $invoice_id, $contract_number, $project_group_id, $category_id,
                $duration_type, $start_date, $end_date, $status, $assigned_staff, $created_by
            );
            
            if ($insert_stmt->execute()) {
                $operations_message = "Operation added successfully! Invoice ID: " . $invoice_id;
                $operations_message_type = "success";
            } else {
                $operations_message = "Error adding operation: " . $conn->error;
                $operations_message_type = "danger";
            }
            $insert_stmt->close();
        } else {
            $operations_message = implode("<br>", $errors);
            $operations_message_type = "danger";
        }
    }
    
    // Generate new CSRF token
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// PERMISSION: Check edit permission before processing update operation
if (isset($_POST['operations_update']) && !canEdit($conn, $user_role_id, $module_name)) {
    $operations_message = "You do not have permission to edit operations.";
    $operations_message_type = "danger";
} elseif (isset($_POST['operations_update'])) {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $operations_message = "Invalid form submission. Please try again.";
        $operations_message_type = "danger";
    } else {
        $id = (int)$_POST['id'];
        $contract_number = sanitize($conn, $_POST['contract_number']);
        $project_group_id = (int)$_POST['project_group_id'];
        $category_id = (int)$_POST['category_id'];
        $duration_type = sanitize($conn, $_POST['duration_type']);
        $start_date = sanitize($conn, $_POST['start_date']);
        $end_date = sanitize($conn, $_POST['end_date']);
        $selected_status = sanitize($conn, $_POST['status']);
        $assigned_staff = isset($_POST['assigned_staff']) ? $_POST['assigned_staff'] : '[]';
        
        // Determine final status based on dates and user selection
        $status = determineOperationStatus($start_date, $end_date, $selected_status);
        
        // Validate inputs
        $errors = [];
        
        if ($id <= 0) {
            $errors[] = "Invalid ID";
        }
        
        if (empty($contract_number)) {
            $errors[] = "Please select a contract";
        }
        
        if ($project_group_id <= 0) {
            $errors[] = "Please select a project group";
        }
        
        if ($category_id <= 0) {
            $errors[] = "Please select a category";
        }
        
        if (!array_key_exists($duration_type, $duration_options)) {
            $errors[] = "Invalid duration type";
        }
        
        if (empty($start_date)) {
            $errors[] = "Start date is required";
        }
        
        if (empty($end_date)) {
            $errors[] = "End date is required";
        }
        
        if ($end_date < $start_date) {
            $errors[] = "End date must be on or after start date";
        }
        
        if (!array_key_exists($selected_status, $status_options)) {
            $errors[] = "Invalid status";
        }
        
        // Validate assigned_staff JSON
        $staff_json = json_decode($assigned_staff, true);
        if ($staff_json === null) {
            $errors[] = "Invalid staff data format";
        }
        
        if (empty($errors)) {
            // Update operation (preserve invoice_id and created_by)
            $update_stmt = $conn->prepare("UPDATE operations SET 
                contract_number = ?, project_group_id = ?, category_id = ?,
                duration_type = ?, start_date = ?, end_date = ?, status = ?, assigned_staff = ?
                WHERE id = ?");
            
            $update_stmt->bind_param("siisssssi", 
                $contract_number, $project_group_id, $category_id,
                $duration_type, $start_date, $end_date, $status, $assigned_staff, $id
            );
            
            if ($update_stmt->execute()) {
                $operations_message = "Operation updated successfully!";
                $operations_message_type = "success";
            } else {
                $operations_message = "Error updating operation: " . $conn->error;
                $operations_message_type = "danger";
            }
            $update_stmt->close();
        } else {
            $operations_message = implode("<br>", $errors);
            $operations_message_type = "danger";
        }
    }
    
    // Generate new CSRF token
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// PERMISSION: Check delete permission before processing delete operation
if (isset($_GET['operations_delete']) && !canDelete($conn, $user_role_id, $module_name)) {
    $operations_message = "You do not have permission to delete operations.";
    $operations_message_type = "danger";
} elseif (isset($_GET['operations_delete'])) {
    $id = (int)$_GET['operations_delete'];
    
    if ($id > 0) {
        $delete_stmt = $conn->prepare("DELETE FROM operations WHERE id = ?");
        $delete_stmt->bind_param("i", $id);
        
        if ($delete_stmt->execute()) {
            $operations_message = "Operation deleted successfully!";
            $operations_message_type = "success";
            
            // Check if table is empty and reset auto-increment
            $check_empty = $conn->query("SELECT COUNT(*) as count FROM operations");
            $row_count = $check_empty->fetch_assoc()['count'];
            
            if ($row_count == 0) {
                $conn->query("ALTER TABLE operations AUTO_INCREMENT = 1");
            }
        } else {
            $operations_message = "Error deleting operation: " . $conn->error;
            $operations_message_type = "danger";
        }
        $delete_stmt->close();
    }
}

// Get edit data if in edit mode (and user has edit permission)
$edit_mode = false;
$edit_data = null;

if (isset($_GET['operations_edit'])) {
    $edit_id = (int)$_GET['operations_edit'];
    // PERMISSION: Only allow edit if user has edit permission
    if ($edit_id > 0 && canEdit($conn, $user_role_id, $module_name)) {
        $edit_stmt = $conn->prepare("SELECT * FROM operations WHERE id = ?");
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
 * FETCH ACTIVE CONTRACTS FOR DROPDOWN
 * ============================================================================
 */
$contracts = [];
$contracts_query = "SELECT p.contract_number, p.client_id, p.contract_value, p.tax, p.commission, 
                           p.cost_of_project, p.staff_cost, p.overhead_cost, p.target_profit, 
                           p.actual_profit, p.profit_difference, p.status as contract_status,
                           c.customer_name, c.contact_person, c.tin_number, c.vrn_number, c.address, c.email, c.type_of_business
                    FROM projects p 
                    LEFT JOIN customers c ON p.client_id = c.id 
                    WHERE p.status = 'Active' 
                    ORDER BY p.contract_number ASC";
$contracts_result = $conn->query($contracts_query);
if ($contracts_result && $contracts_result->num_rows > 0) {
    while ($row = $contracts_result->fetch_assoc()) {
        $contracts[] = $row;
    }
}

/**
 * ============================================================================
 * FETCH PROJECT GROUPS FOR DROPDOWN
 * ============================================================================
 */
$project_groups = [];
$pg_query = "SELECT id, name FROM projectgroup WHERE status = 'Active' ORDER BY name ASC";
$pg_result = $conn->query($pg_query);
if ($pg_result && $pg_result->num_rows > 0) {
    while ($row = $pg_result->fetch_assoc()) {
        $project_groups[] = $row;
    }
}

/**
 * ============================================================================
 * FETCH ALL CATEGORIES FOR CASCADING DROPDOWN (GROUPED BY PROJECT GROUP)
 * ============================================================================
 */
$categories_data = [];
$cat_query = "SELECT id, name, projectgroup_id FROM categories WHERE status = 'Active' ORDER BY name ASC";
$cat_result = $conn->query($cat_query);
if ($cat_result && $cat_result->num_rows > 0) {
    while ($row = $cat_result->fetch_assoc()) {
        if (!isset($categories_data[$row['projectgroup_id']])) {
            $categories_data[$row['projectgroup_id']] = [];
        }
        $categories_data[$row['projectgroup_id']][] = $row;
    }
}

/**
 * ============================================================================
 * FETCH COMPANY SETTINGS FOR INVOICE (INCLUDING VRN)
 * ============================================================================
 */
function getCompanySettings($conn) {
    $result = $conn->query("SELECT * FROM company_settings LIMIT 1");
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    return [
        'company_name' => 'SHEHITA EMS',
        'company_address' => '',
        'company_email' => '',
        'company_phone' => '',
        'company_tin' => '',
        'vrn_number' => '', // Added VRN field
        'currency_symbol' => 'TZS',
        'logo_url' => null
    ];
}

$company_settings = getCompanySettings($conn);

/**
 * ============================================================================
 * BUILD QUERY WITH SEARCH AND FILTER
 * ============================================================================
 */
$where_clause = "";
$params = [];
$types = "";

if (!empty($search)) {
    $where_clause .= " (o.contract_number LIKE ? OR c.customer_name LIKE ? OR o.invoice_id LIKE ?) ";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

if (!empty($status_filter)) {
    if (!empty($where_clause)) {
        $where_clause .= " AND ";
    }
    $where_clause .= " o.status = ? ";
    $params[] = $status_filter;
    $types .= "s";
}

if (!empty($where_clause)) {
    $where_clause = "WHERE " . $where_clause;
}

// Count total records for pagination
$count_query = "SELECT COUNT(*) as total FROM operations o 
                LEFT JOIN projects p ON o.contract_number = p.contract_number
                LEFT JOIN customers c ON p.client_id = c.id 
                $where_clause";
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

// Fetch operations with pagination
$operations_query = "SELECT o.*, c.customer_name, c.contact_person, c.tin_number, c.vrn_number, c.address, c.email, c.type_of_business,
                            p.contract_value, p.tax, p.commission, p.cost_of_project, p.staff_cost, p.overhead_cost,
                            p.target_profit, p.actual_profit, p.profit_difference,
                            pg.name as project_group_name, cat.name as category_name
                     FROM operations o 
                     LEFT JOIN projects p ON o.contract_number = p.contract_number
                     LEFT JOIN customers c ON p.client_id = c.id 
                     LEFT JOIN projectgroup pg ON o.project_group_id = pg.id
                     LEFT JOIN categories cat ON o.category_id = cat.id
                     $where_clause 
                     ORDER BY o.id DESC 
                     LIMIT ? OFFSET ?";
$params[] = $records_per_page;
$params[] = $offset;
$types .= "ii";

$operations_stmt = $conn->prepare($operations_query);
$operations_stmt->bind_param($types, ...$params);
$operations_stmt->execute();
$operations_result = $operations_stmt->get_result();

// Store results in array for KPI calculations
$operations_data = [];
if ($operations_result && $operations_result->num_rows > 0) {
    while ($row = $operations_result->fetch_assoc()) {
        $operations_data[] = $row;
    }
    // Reset pointer for table display
    $operations_stmt->execute();
    $operations_result = $operations_stmt->get_result();
}

?>

<!-- OPERATIONS TRANSLATIONS -->
<script>
// Operations translations for English and Swahili (Updated: Only Active, Completed, Inactive)
const operations_translations = {
    en: {
        pageTitle: 'Operations Management',
        addNew: 'Add New Operation',
        editOperation: 'Edit Operation',
        addOperation: 'Add New Operation',
        id: 'ID',
        invoiceId: 'Invoice ID',
        contractNumber: 'Contract Number',
        selectContract: 'Select Contract',
        projectGroup: 'Project Group',
        selectProjectGroup: 'Select Project Group',
        category: 'Category',
        selectCategory: 'Select Category',
        durationType: 'Duration Type',
        startDate: 'Start Date',
        endDate: 'End Date',
        status: 'Status',
        active: 'Active',
        completed: 'Completed',
        inactive: 'Inactive',
        actions: 'Actions',
        save: 'Save',
        cancel: 'Cancel',
        edit: 'Edit',
        delete: 'Delete',
        viewInvoice: 'View Invoice',
        print: 'Print',
        close: 'Close',
        created: 'Created',
        createdBy: 'Created By',
        confirmDelete: 'Are you sure you want to delete operation',
        confirmDeleteMsg: 'This action cannot be undone.',
        noData: 'No operations found. Click "Add New Operation" to create one.',
        
        // KPI Cards
        activeOperations: 'Active Operations',
        expiredOperations: 'Completed Operations',
        totalProfit: 'Total Profit',
        totalContractValue: 'Total Contract Value',
        totalStaffAllocated: 'Total Staff Allocated',
        
        // Form Labels
        contractDetails: 'Contract Details',
        clientDetails: 'Client Details',
        clientName: 'Client Name',
        contactPerson: 'Contact Person',
        tinNumber: 'TIN Number',
        vrnNumber: 'VRN Number',
        address: 'Address',
        email: 'Email',
        typeOfBusiness: 'Type of Business',
        contractValue: 'Contract Value',
        tax: 'Tax',
        commission: 'Commission',
        costOfProject: 'Cost of Project',
        staffCost: 'Staff Cost',
        overheadCost: 'Overhead Cost',
        targetProfit: 'Target Profit (30%)',
        actualProfit: 'Actual Profit',
        profitDifference: 'Profit Difference',
        totalCosts: 'Total Costs',
        
        // Staff Assignment Table
        staffAssignment: 'Staff Assignment',
        staffName: 'Staff Name',
        staffEmail: 'Staff Email',
        phoneNumber: 'Phone Number',
        role: 'Role',
        addRow: 'Add Row',
        remove: 'Remove',
        
        // Financial Summary Card
        financialSummary: 'Financial Summary',
        
        // Validation Messages
        selectContractRequired: 'Please select a contract!',
        selectProjectGroupRequired: 'Please select a project group!',
        selectCategoryRequired: 'Please select a category!',
        startDateRequired: 'Start date is required!',
        endDateRequired: 'End date is required!',
        endDateInvalid: 'End date must be on or after start date!',
        
        // Search/Filter
        search: 'Search',
        filter: 'Filter',
        allStatus: 'All Status',
        clear: 'Clear',
        searchPlaceholder: 'Search by invoice #, contract # or client...',
        
        // Pagination
        page: 'Page',
        of: 'of',
        previous: 'Previous',
        next: 'Next',
        totalRecords: 'Total Operations',
        records: 'records',
        
        // Loading
        loading: 'Loading...',
        
        // Invoice Modal Labels
        invoiceTitle: 'OPERATION INVOICE',
        operationInformation: 'OPERATION INFORMATION',
        contractInformation: 'CONTRACT INFORMATION',
        clientInformation: 'CLIENT DETAILS',
        financialBreakdown: 'FINANCIAL BREAKDOWN',
        less: 'Less:',
        totalCostsLabel: 'Total Costs',
        targetProfitLabel: 'Target Profit (30%)',
        actualProfitLabel: 'Actual Profit',
        profitDifferenceLabel: 'Profit Difference',
        staffAllocatedLabel: 'Staff Assigned',
        generatedBy: 'Generated by',
        datePrinted: 'Date Printed',
        staffMembers: 'staff members',
        projectGroupLabel: 'Project Group',
        categoryLabel: 'Category',
        durationTypeLabel: 'Duration Type',
        companyVRN: 'Company VRN',
        clientVRN: 'Client VRN'
    },
    sw: {
        pageTitle: 'Usimamizi wa Uendeshaji',
        addNew: 'Ongeza Operesheni Mpya',
        editOperation: 'Hariri Operesheni',
        addOperation: 'Ongeza Operesheni Mpya',
        id: 'Kitambulisho',
        invoiceId: 'Namba ya Ankara',
        contractNumber: 'Namba ya Mkataba',
        selectContract: 'Chagua Mkataba',
        projectGroup: 'Kundi la Mradi',
        selectProjectGroup: 'Chagua Kundi la Mradi',
        category: 'Kategoria',
        selectCategory: 'Chagua Kategoria',
        durationType: 'Aina ya Muda',
        startDate: 'Tarehe ya Kuanza',
        endDate: 'Tarehe ya Mwisho',
        status: 'Hali',
        active: 'Inaendelea',
        completed: 'Imekamilika',
        inactive: 'Haifanyi Kazi',
        actions: 'Vitendo',
        save: 'Hifadhi',
        cancel: 'Ghairi',
        edit: 'Hariri',
        delete: 'Futa',
        viewInvoice: 'Angalia Ankara',
        print: 'Chapisha',
        close: 'Funga',
        created: 'Imeundwa',
        createdBy: 'Imeundwa na',
        confirmDelete: 'Una uhakika unataka kufuta operesheni',
        confirmDeleteMsg: 'Kitendo hiki hakiwezi kutenguliwa.',
        noData: 'Hakuna operesheni zilizopatikana. Bofya "Ongeza Operesheni Mpya" kuunda.',
        
        // KPI Cards
        activeOperations: 'Operesheni Zinazoendelea',
        expiredOperations: 'Operesheni Zilizokamilika',
        totalProfit: 'Jumla ya Faida',
        totalContractValue: 'Jumla ya Thamani ya Mikataba',
        totalStaffAllocated: 'Jumla ya Wafanyakazi Waliopangiwa',
        
        // Form Labels
        contractDetails: 'Taarifa za Mkataba',
        clientDetails: 'Taarifa za Mteja',
        clientName: 'Jina la Mteja',
        contactPerson: 'Mtu wa Kuwasiliana',
        tinNumber: 'Namba ya TIN',
        vrnNumber: 'Namba ya VRN',
        address: 'Anwani',
        email: 'Barua pepe',
        typeOfBusiness: 'Aina ya Biashara',
        contractValue: 'Thamani ya Mkataba',
        tax: 'Kodi',
        commission: 'Kamisheni',
        costOfProject: 'Gharama ya Mradi',
        staffCost: 'Gharama za Wafanyakazi',
        overheadCost: 'Gharama za Uendeshaji',
        targetProfit: 'Faida Inayolengwa (30%)',
        actualProfit: 'Faida Halisi',
        profitDifference: 'Tofauti ya Faida',
        totalCosts: 'Jumla ya Gharama',
        
        // Staff Assignment Table
        staffAssignment: 'Ugawaji wa Wafanyakazi',
        staffName: 'Jina la Mfanyakazi',
        staffEmail: 'Barua pepe ya Mfanyakazi',
        phoneNumber: 'Namba ya Simu',
        role: 'Nafasi',
        addRow: 'Ongeza Safu',
        remove: 'Ondoa',
        
        // Financial Summary Card
        financialSummary: 'Muhtasari wa Kifedha',
        
        // Validation Messages
        selectContractRequired: 'Tafadhali chagua mkataba!',
        selectProjectGroupRequired: 'Tafadhali chagua kundi la mradi!',
        selectCategoryRequired: 'Tafadhali chagua kategoria!',
        startDateRequired: 'Tarehe ya kuanza inahitajika!',
        endDateRequired: 'Tarehe ya mwisho inahitajika!',
        endDateInvalid: 'Tarehe ya mwisho lazima iwe sawa au baada ya tarehe ya kuanza!',
        
        // Search/Filter
        search: 'Tafuta',
        filter: 'Chuja',
        allStatus: 'Hali Zote',
        clear: 'Futa',
        searchPlaceholder: 'Tafuta kwa namba ya ankara, mkataba au mteja...',
        
        // Pagination
        page: 'Ukurasa',
        of: 'kati ya',
        previous: 'Iliyotangulia',
        next: 'Ijayo',
        totalRecords: 'Jumla ya Operesheni',
        records: 'rekodi',
        
        // Loading
        loading: 'Inapakia...',
        
        // Invoice Modal Labels
        invoiceTitle: 'ANKARA YA OPERESHENI',
        operationInformation: 'TAARIFA ZA OPERESHENI',
        contractInformation: 'TAARIFA ZA MKATABA',
        clientInformation: 'TAARIFA ZA MTEJA',
        financialBreakdown: 'MUHTASARI WA KIFEDHA',
        less: 'Ondoa:',
        totalCostsLabel: 'Jumla ya Gharama',
        targetProfitLabel: 'Faida Inayolengwa (30%)',
        actualProfitLabel: 'Faida Halisi',
        profitDifferenceLabel: 'Tofauti ya Faida',
        staffAllocatedLabel: 'Wafanyakazi Waliopangiwa',
        generatedBy: 'Imetolewa na',
        datePrinted: 'Tarehe ya Kuchapishwa',
        staffMembers: 'wafanyakazi',
        projectGroupLabel: 'Kundi la Mradi',
        categoryLabel: 'Kategoria',
        durationTypeLabel: 'Aina ya Muda',
        companyVRN: 'VRN ya Kampuni',
        clientVRN: 'VRN ya Mteja'
    }
};

// Current language (will be updated by homepage.js)
let currentOperationsLang = localStorage.getItem('preferredLanguage') || 'en';

// Function to update all translatable elements in operations module
function updateOperationsLanguage(lang) {
    currentOperationsLang = lang;
    const elements = document.querySelectorAll('[data-ops-lang]');
    
    elements.forEach(element => {
        const key = element.getAttribute('data-ops-lang');
        if (operations_translations[lang] && operations_translations[lang][key]) {
            if (element.tagName === 'INPUT' || element.tagName === 'TEXTAREA') {
                if (element.getAttribute('type') !== 'submit' && element.getAttribute('type') !== 'button') {
                    element.placeholder = operations_translations[lang][key];
                }
            } else if (element.tagName === 'OPTION') {
                element.textContent = operations_translations[lang][key];
            } else {
                element.textContent = operations_translations[lang][key];
            }
        }
    });
    
    // Update table header
    const thElements = document.querySelectorAll('th[data-ops-lang]');
    thElements.forEach(th => {
        const key = th.getAttribute('data-ops-lang');
        if (operations_translations[lang] && operations_translations[lang][key]) {
            th.textContent = operations_translations[lang][key];
        }
    });
    
    // Update KPI card labels
    const kpiLabels = document.querySelectorAll('.operations-kpi-label');
    kpiLabels.forEach(label => {
        const key = label.getAttribute('data-ops-lang');
        if (key && operations_translations[lang] && operations_translations[lang][key]) {
            label.textContent = operations_translations[lang][key];
        }
    });
    
    // Update empty state message
    const emptyState = document.querySelector('.operations-empty p');
    if (emptyState) {
        emptyState.textContent = operations_translations[lang].noData;
    }
    
    // Update form header
    const formHeader = document.querySelector('#operations_form h3');
    if (formHeader) {
        const isEditMode = document.querySelector('input[name="id"]') !== null;
        formHeader.textContent = operations_translations[lang][isEditMode ? 'editOperation' : 'addOperation'];
    }
    
    // Update search input placeholder
    const searchInput = document.querySelector('input[name="search"]');
    if (searchInput) {
        searchInput.placeholder = operations_translations[lang].searchPlaceholder;
    }
    
    // Update total records text
    const totalRecordsSpan = document.querySelector('#totalRecords');
    if (totalRecordsSpan) {
        totalRecordsSpan.textContent = operations_translations[lang].totalRecords;
    }
    
    // Update pagination buttons
    const prevBtn = document.querySelector('.operations-prev-btn');
    const nextBtn = document.querySelector('.operations-next-btn');
    if (prevBtn && prevBtn.tagName === 'A') prevBtn.innerHTML = `<i class="fas fa-chevron-left"></i> ${operations_translations[lang].previous}`;
    if (nextBtn && nextBtn.tagName === 'A') nextBtn.innerHTML = `${operations_translations[lang].next} <i class="fas fa-chevron-right"></i>`;
    
    // Update financial summary labels
    const summaryLabels = document.querySelectorAll('[data-ops-summary]');
    summaryLabels.forEach(label => {
        const key = label.getAttribute('data-ops-summary');
        if (operations_translations[lang] && operations_translations[lang][key]) {
            label.textContent = operations_translations[lang][key];
        }
    });
    
    // Update modal labels
    const modalLabels = document.querySelectorAll('[data-modal-lang]');
    modalLabels.forEach(label => {
        const key = label.getAttribute('data-modal-lang');
        if (operations_translations[lang] && operations_translations[lang][key]) {
            label.textContent = operations_translations[lang][key];
        }
    });
    
    // Update status select options in the filter dropdown
    const statusFilter = document.getElementById('status_filter');
    if (statusFilter) {
        const options = statusFilter.querySelectorAll('option');
        options.forEach(option => {
            const value = option.value;
            if (value === 'Active') {
                option.textContent = operations_translations[lang].active;
            } else if (value === 'Completed') {
                option.textContent = operations_translations[lang].completed;
            } else if (value === 'Inactive') {
                option.textContent = operations_translations[lang].inactive;
            } else if (value === '') {
                option.textContent = operations_translations[lang].allStatus;
            }
        });
    }
    
    // Update status select options in the form
    const statusSelect = document.getElementById('operations_status');
    if (statusSelect) {
        const options = statusSelect.querySelectorAll('option');
        options.forEach(option => {
            const value = option.value;
            if (value === 'Active') {
                option.textContent = operations_translations[lang].active;
            } else if (value === 'Completed') {
                option.textContent = operations_translations[lang].completed;
            } else if (value === 'Inactive') {
                option.textContent = operations_translations[lang].inactive;
            }
        });
    }
}

// Listen for language change events from homepage
document.addEventListener('DOMContentLoaded', function() {
    updateOperationsLanguage(currentOperationsLang);
});

// This function will be called from homepage.js when language changes
window.updateOperationsLanguage = updateOperationsLanguage;
</script>

<style>
    /* Operations Module Styles - Using operations_ prefix (ISSUE #3: No sidebar conflicts) */
    .operations-container {
        width: 100%;
    }

    /* KPI Cards Row */
    .operations-kpi-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 32px;
    }

    .operations-kpi-card {
        background: white;
        border-radius: 16px;
        padding: 20px;
        box-shadow: var(--shadow-md);
        border: 1px solid var(--gray-200);
        transition: all 0.2s;
        position: relative;
        overflow: hidden;
    }

    .operations-kpi-card:hover {
        transform: translateY(-4px);
        box-shadow: var(--shadow-lg);
    }

    .operations-kpi-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 4px;
        height: 100%;
    }

    .operations-kpi-card-green::before {
        background: #28a745;
    }

    .operations-kpi-card-red::before {
        background: #dc3545;
    }

    .operations-kpi-card-brown::before {
        background: var(--brown-700);
    }

    .operations-kpi-card-teal::before {
        background: #20c997;
    }

    .operations-kpi-card-blue::before {
        background: #007bff;
    }

    .operations-kpi-icon {
        font-size: 32px;
        margin-bottom: 12px;
    }

    .operations-kpi-icon-green {
        color: #28a745;
    }

    .operations-kpi-icon-red {
        color: #dc3545;
    }

    .operations-kpi-icon-brown {
        color: var(--brown-700);
    }

    .operations-kpi-icon-teal {
        color: #20c997;
    }

    .operations-kpi-icon-blue {
        color: #007bff;
    }

    .operations-kpi-value {
        font-size: 28px;
        font-weight: 700;
        color: var(--gray-800);
        margin-bottom: 8px;
    }

    .operations-kpi-label {
        font-size: 14px;
        color: var(--gray-500);
        font-weight: 500;
    }

    /* Header Styles */
    .operations-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
        flex-wrap: wrap;
        gap: 16px;
    }

    .operations-header h2 {
        color: var(--gray-800);
        font-size: 24px;
        font-weight: 600;
        margin: 0;
    }

    .operations-btn {
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

    .operations-btn:hover {
        background: var(--brown-800);
        transform: translateY(-2px);
        box-shadow: var(--shadow-md);
    }

    .operations-btn-secondary {
        background: var(--gray-200);
        color: var(--gray-700);
    }

    .operations-btn-secondary:hover {
        background: var(--gray-300);
    }

    .operations-btn-danger {
        background: #dc3545;
    }

    .operations-btn-danger:hover {
        background: #c82333;
    }

    /* Form Styles */
    .operations-form {
        background: white;
        border-radius: 20px;
        padding: 32px;
        margin-bottom: 32px;
        border: 1px solid var(--gray-200);
        box-shadow: var(--shadow-lg);
        display: none;
    }

    .operations-form.show {
        display: block;
        animation: operations-fadeIn 0.3s ease-out;
    }

    @keyframes operations-fadeIn {
        from {
            opacity: 0;
            transform: translateY(10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .operations-form h3 {
        color: var(--gray-800);
        font-size: 20px;
        font-weight: 600;
        margin-bottom: 24px;
        padding-bottom: 16px;
        border-bottom: 2px solid var(--gray-200);
    }

    .operations-form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 20px;
        margin-bottom: 24px;
    }

    .operations-form-group {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .operations-form-group-full {
        grid-column: 1 / -1;
    }

    .operations-form-group label {
        color: var(--gray-700);
        font-size: 14px;
        font-weight: 500;
    }

    .operations-form-group label .required {
        color: #dc3545;
        margin-left: 4px;
    }

    .operations-form-group input,
    .operations-form-group select,
    .operations-form-group textarea {
        padding: 10px 12px;
        border: 1px solid var(--gray-300);
        border-radius: 8px;
        font-size: 14px;
        transition: all 0.2s;
        font-family: inherit;
    }

    .operations-form-group input:focus,
    .operations-form-group select:focus,
    .operations-form-group textarea:focus {
        outline: none;
        border-color: var(--brown-600);
        box-shadow: 0 0 0 3px rgba(123, 88, 63, 0.1);
    }

    /* Contract Details Card */
    .operations-contract-details {
        background: linear-gradient(135deg, #faf7f2 0%, #f5efe8 100%);
        border-radius: 12px;
        padding: 20px;
        margin-top: 16px;
        margin-bottom: 24px;
        border: 1px solid var(--gray-200);
        display: none;
    }

    .operations-contract-details h4 {
        color: var(--brown-800);
        font-size: 16px;
        font-weight: 600;
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .operations-contract-details-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 12px;
    }

    .operations-contract-detail-item {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .operations-contract-detail-label {
        font-size: 12px;
        color: var(--gray-500);
        font-weight: 500;
    }

    .operations-contract-detail-value {
        font-size: 14px;
        color: var(--gray-800);
        font-weight: 500;
    }

    /* Staff Assignment Table */
    .operations-staff-section {
        margin-top: 24px;
        margin-bottom: 24px;
        border: 1px solid var(--gray-200);
        border-radius: 12px;
        overflow: hidden;
    }

    .operations-staff-header {
        background: var(--gray-100);
        padding: 12px 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 1px solid var(--gray-200);
    }

    .operations-staff-header h4 {
        color: var(--gray-800);
        font-size: 16px;
        font-weight: 600;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .operations-add-row-btn {
        background: var(--brown-700);
        color: white;
        border: none;
        padding: 8px 16px;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 500;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: all 0.2s;
    }

    .operations-add-row-btn:hover {
        background: var(--brown-800);
    }

    .operations-staff-table-container {
        overflow-x: auto;
    }

    .operations-staff-table {
        width: 100%;
        border-collapse: collapse;
    }

    .operations-staff-table th {
        background: var(--gray-50);
        color: var(--gray-700);
        font-weight: 600;
        font-size: 13px;
        padding: 12px;
        text-align: left;
        border-bottom: 1px solid var(--gray-200);
    }

    .operations-staff-table td {
        padding: 10px 12px;
        border-bottom: 1px solid var(--gray-200);
        vertical-align: middle;
    }

    .operations-staff-table input {
        width: 100%;
        padding: 8px 10px;
        border: 1px solid var(--gray-300);
        border-radius: 6px;
        font-size: 13px;
    }

    .operations-staff-table input:focus {
        outline: none;
        border-color: var(--brown-600);
        box-shadow: 0 0 0 2px rgba(123, 88, 63, 0.1);
    }

    .operations-remove-row-btn {
        background: #f8d7da;
        color: #721c24;
        border: none;
        padding: 6px 10px;
        border-radius: 4px;
        cursor: pointer;
        transition: all 0.2s;
    }

    .operations-remove-row-btn:hover {
        background: #f5c6cb;
    }

    /* Financial Summary Card */
    .operations-financial-summary {
        background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
        border-radius: 12px;
        padding: 20px;
        margin-top: 24px;
        border: 1px solid #a5d6a7;
    }

    .operations-financial-summary h4 {
        color: #2e7d32;
        font-size: 16px;
        font-weight: 600;
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .operations-financial-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 16px;
    }

    .operations-financial-item {
        background: white;
        border-radius: 8px;
        padding: 12px;
        text-align: center;
    }

    .operations-financial-label {
        font-size: 12px;
        color: var(--gray-500);
        margin-bottom: 4px;
    }

    .operations-financial-value {
        font-size: 18px;
        font-weight: 700;
    }

    .operations-financial-value-positive {
        color: #28a745;
    }

    .operations-financial-value-negative {
        color: #dc3545;
    }

    /* Form Actions */
    .operations-form-actions {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
        margin-top: 24px;
        padding-top: 20px;
        border-top: 1px solid var(--gray-200);
    }

    /* Alert Styles */
    .operations-alert {
        padding: 16px;
        border-radius: 8px;
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .operations-alert-success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }

    .operations-alert-danger {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }

    .operations-alert-close {
        background: none;
        border: none;
        font-size: 20px;
        cursor: pointer;
        color: inherit;
        opacity: 0.5;
    }

    .operations-alert-close:hover {
        opacity: 1;
    }

    /* Search and Filter Bar */
    .operations-search-bar {
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

    .operations-search-group {
        flex: 1;
        min-width: 200px;
    }

    .operations-search-group label {
        display: block;
        color: var(--gray-700);
        font-size: 13px;
        font-weight: 500;
        margin-bottom: 6px;
    }

    .operations-search-group input,
    .operations-search-group select {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid var(--gray-300);
        border-radius: 6px;
        font-size: 14px;
    }

    .operations-search-group input:focus,
    .operations-search-group select:focus {
        outline: none;
        border-color: var(--brown-600);
        box-shadow: 0 0 0 3px rgba(123, 88, 63, 0.1);
    }

    .operations-search-actions {
        display: flex;
        gap: 8px;
    }

    .operations-search-btn {
        padding: 10px 20px;
        background: var(--brown-700);
        color: white;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-weight: 500;
        transition: all 0.2s;
    }

    .operations-search-btn:hover {
        background: var(--brown-800);
    }

    .operations-clear-btn {
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

    .operations-clear-btn:hover {
        background: var(--gray-300);
    }

    /* Stats Bar */
    .operations-stats {
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

    .operations-stats-info {
        display: flex;
        align-items: center;
        gap: 8px;
        color: var(--gray-700);
        font-size: 14px;
    }

    .operations-stats-info i {
        color: var(--brown-600);
    }

    .operations-stats-count {
        font-weight: 700;
        color: var(--brown-700);
        font-size: 18px;
    }

    /* Table Styles */
    .operations-table-container {
        overflow-x: auto;
        background: white;
        border-radius: 12px;
        border: 1px solid var(--gray-200);
        margin-bottom: 20px;
    }

    .operations-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 1100px;
    }

    .operations-table th {
        background: var(--gray-50);
        color: var(--gray-700);
        font-weight: 600;
        font-size: 14px;
        padding: 16px;
        text-align: left;
        border-bottom: 2px solid var(--gray-200);
    }

    .operations-table td {
        padding: 16px;
        border-bottom: 1px solid var(--gray-200);
        color: var(--gray-700);
        font-size: 14px;
        vertical-align: middle;
    }

    .operations-table tr:hover {
        background: var(--gray-50);
    }

    /* Status Badges - Only Active, Completed, Inactive */
    .operations-status {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 500;
    }

    .operations-status-active {
        background: #d4edda;
        color: #155724;
    }

    .operations-status-completed {
        background: #d1ecf1;
        color: #0c5460;
    }

    .operations-status-inactive {
        background: #f5c6cb;
        color: #721c24;
    }

    /* Action Buttons */
    .operations-actions {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    .operations-action-btn {
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

    .operations-action-edit {
        background: var(--brown-100);
        color: var(--brown-800);
    }

    .operations-action-edit:hover {
        background: var(--brown-200);
    }

    .operations-action-delete {
        background: #f8d7da;
        color: #721c24;
    }

    .operations-action-delete:hover {
        background: #f5c6cb;
    }

    .operations-action-view {
        background: #d1ecf1;
        color: #0c5460;
    }

    .operations-action-view:hover {
        background: #bee5eb;
    }

    /* Loading Indicator */
    .operations-loading {
        display: inline-block;
        width: 20px;
        height: 20px;
        border: 3px solid var(--gray-200);
        border-top-color: var(--brown-700);
        border-radius: 50%;
        animation: operations-spin 1s linear infinite;
    }

    @keyframes operations-spin {
        to { transform: rotate(360deg); }
    }

    /* Empty State */
    .operations-empty {
        text-align: center;
        padding: 48px !important;
        color: var(--gray-500);
    }

    .operations-empty i {
        font-size: 48px;
        margin-bottom: 16px;
        color: var(--gray-400);
    }

    .operations-timestamp {
        font-size: 12px;
        color: var(--gray-500);
        margin-top: 4px;
    }

    /* Pagination */
    .operations-pagination {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 12px;
        margin-top: 20px;
    }

    .operations-pagination a,
    .operations-pagination span {
        padding: 8px 16px;
        border-radius: 6px;
        text-decoration: none;
        font-size: 14px;
        font-weight: 500;
        transition: all 0.2s;
    }

    .operations-pagination a {
        background: white;
        border: 1px solid var(--gray-300);
        color: var(--gray-700);
    }

    .operations-pagination a:hover {
        background: var(--brown-100);
        border-color: var(--brown-600);
        color: var(--brown-800);
    }

    .operations-pagination .active {
        background: var(--brown-700);
        border-color: var(--brown-700);
        color: white;
    }

    .operations-pagination .disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    /* Modal Styles - For Invoice View */
    .operations-modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.7);
        backdrop-filter: blur(4px);
        display: none;
        justify-content: center;
        align-items: center;
        z-index: 10000;
        padding: 20px;
    }

    .operations-modal-overlay.active {
        display: flex;
    }

    .operations-modal {
        background: white;
        border-radius: 16px;
        width: 100%;
        max-width: 900px;
        max-height: 90vh;
        overflow: hidden;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        animation: operations-modalFadeIn 0.2s ease-out;
        display: flex;
        flex-direction: column;
    }

    @keyframes operations-modalFadeIn {
        from {
            opacity: 0;
            transform: scale(0.95);
        }
        to {
            opacity: 1;
            transform: scale(1);
        }
    }

    .operations-modal-header {
        padding: 20px 24px;
        border-bottom: 2px solid var(--gray-200);
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: linear-gradient(135deg, #faf7f2 0%, #f5efe8 100%);
    }

    .operations-modal-title {
        font-family: 'Montserrat', sans-serif;
        font-weight: 700;
        font-size: 1.3rem;
        color: var(--brown-800);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .operations-modal-close {
        background: none;
        border: none;
        font-size: 1.2rem;
        color: var(--gray-500);
        cursor: pointer;
        width: 36px;
        height: 36px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s;
    }

    .operations-modal-close:hover {
        background: rgba(139, 90, 43, 0.1);
        color: #dc3545;
    }

    .operations-modal-body {
        padding: 24px;
        overflow-y: auto;
        flex: 1;
    }

    .operations-modal-footer {
        padding: 16px 24px;
        border-top: 1px solid var(--gray-200);
        display: flex;
        justify-content: flex-end;
        gap: 12px;
        background: #faf7f5;
    }

    /* Invoice Content inside Modal */
    .operations-modal-invoice {
        background: white;
        font-family: 'Inter', sans-serif;
    }

    .operations-modal-invoice-header {
        text-align: center;
        padding: 20px;
        border-bottom: 2px solid var(--brown-700);
        margin-bottom: 20px;
    }

    .operations-modal-invoice-logo {
        max-width: 80px;
        max-height: 80px;
        margin-bottom: 15px;
    }

    .operations-modal-invoice-company-name {
        font-size: 22px;
        font-weight: 700;
        color: var(--brown-800);
        margin-bottom: 5px;
    }

    .operations-modal-invoice-company-details {
        font-size: 11px;
        color: var(--gray-600);
        line-height: 1.5;
    }

    .operations-modal-invoice-title {
        text-align: center;
        font-size: 18px;
        font-weight: 700;
        color: var(--brown-800);
        margin: 20px 0;
        padding: 10px;
        background: var(--brown-100);
        letter-spacing: 2px;
    }

    .operations-modal-invoice-section {
        margin-bottom: 20px;
        border: 1px solid var(--gray-200);
        border-radius: 8px;
        overflow: hidden;
    }

    .operations-modal-invoice-section-title {
        background: var(--gray-100);
        padding: 10px 15px;
        font-weight: 700;
        color: var(--brown-800);
        border-bottom: 1px solid var(--gray-200);
        font-size: 14px;
    }

    .operations-modal-invoice-section-content {
        padding: 15px;
    }

    .operations-modal-invoice-row {
        display: flex;
        justify-content: space-between;
        padding: 6px 0;
        border-bottom: 1px dashed var(--gray-200);
        font-size: 13px;
    }

    .operations-modal-invoice-row:last-child {
        border-bottom: none;
    }

    .operations-modal-invoice-label {
        font-weight: 600;
        color: var(--gray-700);
        width: 40%;
    }

    .operations-modal-invoice-value {
        color: var(--gray-800);
        width: 60%;
        text-align: right;
    }

    .operations-modal-invoice-financial-item {
        display: flex;
        justify-content: space-between;
        padding: 6px 0;
        font-size: 13px;
    }

    .operations-modal-invoice-financial-label {
        font-weight: 500;
        color: var(--gray-700);
    }

    .operations-modal-invoice-financial-value {
        font-weight: 600;
        color: var(--gray-800);
    }

    .operations-modal-invoice-total-row {
        border-top: 2px solid var(--gray-300);
        margin-top: 8px;
        padding-top: 10px;
        font-weight: 700;
        font-size: 14px;
    }

    .operations-modal-invoice-profit-positive {
        color: #28a745;
    }

    .operations-modal-invoice-profit-negative {
        color: #dc3545;
    }

    .operations-modal-invoice-staff {
        background: var(--brown-100);
        padding: 12px;
        border-radius: 8px;
        text-align: center;
        margin-top: 15px;
        font-size: 13px;
    }

    .operations-modal-invoice-footer {
        margin-top: 20px;
        padding-top: 15px;
        border-top: 1px solid var(--gray-200);
        text-align: center;
        font-size: 10px;
        color: var(--gray-500);
    }

    /* Staff Table inside Invoice */
    .operations-modal-invoice-staff-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 10px;
        font-size: 11px;
    }

    .operations-modal-invoice-staff-table th {
        background: var(--gray-100);
        padding: 8px;
        text-align: left;
        border-bottom: 1px solid var(--gray-200);
    }

    .operations-modal-invoice-staff-table td {
        padding: 6px 8px;
        border-bottom: 1px solid var(--gray-200);
    }

    /* Print styles for modal invoice - Enhanced for one-page output */
    @media print {
        body * {
            visibility: hidden;
        }
        .operations-modal-body,
        .operations-modal-body * {
            visibility: visible;
        }
        .operations-modal-body {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            padding: 0;
            margin: 0;
        }
        .operations-modal-header,
        .operations-modal-footer {
            display: none;
        }
        @page {
            size: A4 landscape;
            margin: 0.5in;
        }
    }

    /* Responsive */
    @media (max-width: 768px) {
        .operations-kpi-row {
            grid-template-columns: repeat(2, 1fr);
        }
        
        .operations-search-bar {
            flex-direction: column;
        }
        
        .operations-search-actions {
            width: 100%;
        }
        
        .operations-search-btn,
        .operations-clear-btn {
            flex: 1;
            text-align: center;
            justify-content: center;
        }
        
        .operations-form-grid {
            grid-template-columns: 1fr;
        }
        
        .operations-pagination {
            flex-wrap: wrap;
        }
        
        .operations-contract-details-grid,
        .operations-financial-grid {
            grid-template-columns: 1fr;
        }
        
        .operations-modal {
            max-width: 95%;
            max-height: 95vh;
        }
        
        .operations-modal-body {
            padding: 16px;
        }
        
        .operations-staff-table th,
        .operations-staff-table td {
            padding: 8px;
        }
    }
    
    @media (max-width: 480px) {
        .operations-kpi-row {
            grid-template-columns: 1fr;
        }
        
        .operations-kpi-value {
            font-size: 22px;
        }
    }
</style>

<div class="operations-container">
    <!-- Header -->
    <div class="operations-header">
        <h2 data-ops-lang="pageTitle">Operations Management</h2>
        <?php if (canAdd($conn, $user_role_id, $module_name)): ?>
        <button class="operations-btn" onclick="operations_toggleForm()" id="operations_toggleBtn">
            <i class="fas fa-plus"></i>
            <span data-ops-lang="addNew">Add New Operation</span>
        </button>
        <?php endif; ?>
    </div>

    <!-- Alert Messages -->
    <?php if (!empty($operations_message)): ?>
        <div class="operations-alert operations-alert-<?= $operations_message_type ?>">
            <?= $operations_message ?>
            <button class="operations-alert-close" onclick="this.parentElement.style.display='none'">&times;</button>
        </div>
    <?php endif; ?>

    <!-- Add/Edit Form -->
    <?php if (canAdd($conn, $user_role_id, $module_name) || ($edit_mode && canEdit($conn, $user_role_id, $module_name))): ?>
    <div class="operations-form <?= $edit_mode ? 'show' : '' ?>" id="operations_form">
        <h3 data-ops-lang="<?= $edit_mode ? 'editOperation' : 'addOperation' ?>">
            <?= $edit_mode ? 'Edit Operation' : 'Add New Operation' ?>
        </h3>
        
        <form method="POST" action="?page=operations" id="operations_mainForm" onsubmit="return operations_validateForm()">
            <?php if ($edit_mode): ?>
                <input type="hidden" name="id" value="<?= $edit_data['id'] ?>">
            <?php endif; ?>
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="assigned_staff" id="operations_assigned_staff" value="">
            
            <div class="operations-form-grid">
                <!-- Contract Selection -->
                <div class="operations-form-group">
                    <label for="operations_contract_number" data-ops-lang="selectContract">Select Contract <span class="required">*</span></label>
                    <select id="operations_contract_number" name="contract_number" required onchange="operations_loadContractDetails(this.value)">
                        <option value="" data-ops-lang="selectContract">Select Contract</option>
                        <?php foreach ($contracts as $contract): ?>
                            <option value="<?= htmlspecialchars($contract['contract_number']) ?>" 
                                <?= ($edit_mode && $edit_data['contract_number'] == $contract['contract_number']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($contract['contract_number']) ?> - <?= htmlspecialchars($contract['customer_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Project Group -->
                <div class="operations-form-group">
                    <label for="operations_project_group_id" data-ops-lang="projectGroup">Project Group <span class="required">*</span></label>
                    <select id="operations_project_group_id" name="project_group_id" required onchange="operations_loadCategories()">
                        <option value="" data-ops-lang="selectProjectGroup">Select Project Group</option>
                        <?php foreach ($project_groups as $pg): ?>
                            <option value="<?= $pg['id'] ?>" 
                                <?= ($edit_mode && $edit_data['project_group_id'] == $pg['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($pg['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Category -->
                <div class="operations-form-group">
                    <label for="operations_category_id" data-ops-lang="category">Category <span class="required">*</span></label>
                    <select id="operations_category_id" name="category_id" required>
                        <option value="" data-ops-lang="selectCategory">Select Category</option>
                        <?php if ($edit_mode && isset($edit_data['category_id'])): ?>
                            <option value="<?= $edit_data['category_id'] ?>" selected><?= htmlspecialchars($edit_data['category_name'] ?? '') ?></option>
                        <?php endif; ?>
                    </select>
                </div>
                
                <!-- Duration Type -->
                <div class="operations-form-group">
                    <label for="operations_duration_type" data-ops-lang="durationType">Duration Type</label>
                    <select id="operations_duration_type" name="duration_type">
                        <?php foreach ($duration_options as $value => $label): ?>
                            <option value="<?= $value ?>" 
                                <?= ($edit_mode && $edit_data['duration_type'] == $value) ? 'selected' : '' ?>>
                                <?= $label ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Start Date -->
                <div class="operations-form-group">
                    <label for="operations_start_date" data-ops-lang="startDate">Start Date <span class="required">*</span></label>
                    <input type="date" id="operations_start_date" name="start_date" 
                           value="<?= $edit_mode ? $edit_data['start_date'] : '' ?>" required>
                </div>
                
                <!-- End Date -->
                <div class="operations-form-group">
                    <label for="operations_end_date" data-ops-lang="endDate">End Date <span class="required">*</span></label>
                    <input type="date" id="operations_end_date" name="end_date" 
                           value="<?= $edit_mode ? $edit_data['end_date'] : '' ?>" required>
                </div>
                
                <!-- Status (Only Active, Completed, Inactive) -->
                <div class="operations-form-group">
                    <label for="operations_status" data-ops-lang="status">Status</label>
                    <select id="operations_status" name="status">
                        <?php foreach ($status_options as $value => $label): ?>
                            <option value="<?= $value ?>" data-ops-lang="<?= strtolower($value) ?>"
                                <?= ($edit_mode && $edit_data['status'] == $value) ? 'selected' : '' ?>>
                                <?= $label ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <!-- Contract Details Card -->
            <div id="operations_contractDetails" class="operations-contract-details" style="display: none;">
                <h4><i class="fas fa-file-contract"></i> <span data-ops-lang="contractDetails">Contract Details</span></h4>
                <div class="operations-contract-details-grid" id="operations_contractDetailsGrid">
                    <!-- Populated by JavaScript -->
                </div>
            </div>
            
            <!-- Staff Assignment Table -->
            <div class="operations-staff-section">
                <div class="operations-staff-header">
                    <h4><i class="fas fa-users"></i> <span data-ops-lang="staffAssignment">Staff Assignment</span></h4>
                    <button type="button" class="operations-add-row-btn" onclick="operations_addStaffRow()">
                        <i class="fas fa-plus"></i> <span data-ops-lang="addRow">Add Row</span>
                    </button>
                </div>
                <div class="operations-staff-table-container">
                    <table class="operations-staff-table" id="operations_staffTable">
                        <thead>
                            <tr>
                                <th style="width: 40px;">#</th>
                                <th data-ops-lang="staffName">Staff Name</th>
                                <th data-ops-lang="staffEmail">Staff Email</th>
                                <th data-ops-lang="phoneNumber">Phone Number</th>
                                <th data-ops-lang="role">Role</th>
                                <th style="width: 60px;" data-ops-lang="remove">Remove</th>
                            </tr>
                        </thead>
                        <tbody id="operations_staffTableBody">
                            <!-- 10 empty rows by default -->
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Financial Summary Card -->
            <div class="operations-financial-summary">
                <h4><i class="fas fa-chart-line"></i> <span data-ops-summary="financialSummary">Financial Summary</span></h4>
                <div class="operations-financial-grid" id="operations_financialGrid">
                    <div class="operations-financial-item">
                        <div class="operations-financial-label" data-ops-summary="contractValue">Contract Value</div>
                        <div class="operations-financial-value" id="operations_contractValueDisplay">0.00</div>
                    </div>
                    <div class="operations-financial-item">
                        <div class="operations-financial-label" data-ops-summary="totalCosts">Total Costs</div>
                        <div class="operations-financial-value" id="operations_totalCostsDisplay">0.00</div>
                    </div>
                    <div class="operations-financial-item">
                        <div class="operations-financial-label" data-ops-summary="actualProfit">Actual Profit</div>
                        <div class="operations-financial-value" id="operations_actualProfitDisplay">0.00</div>
                    </div>
                    <div class="operations-financial-item">
                        <div class="operations-financial-label" data-ops-summary="targetProfit">Target Profit (30%)</div>
                        <div class="operations-financial-value" id="operations_targetProfitDisplay">0.00</div>
                    </div>
                </div>
            </div>
            
            <div class="operations-form-actions">
                <button type="submit" name="<?= $edit_mode ? 'operations_update' : 'operations_add' ?>" class="operations-btn" id="operations_saveBtn">
                    <i class="fas fa-save"></i>
                    <span data-ops-lang="save">Save</span>
                </button>
                <a href="?page=operations" class="operations-btn operations-btn-secondary">
                    <i class="fas fa-times"></i>
                    <span data-ops-lang="cancel">Cancel</span>
                </a>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- KPI Cards Row -->
    <div id="operations_kpiRow" class="operations-kpi-row" style="<?= $edit_mode ? 'display: none;' : '' ?>">
        <div class="operations-kpi-card operations-kpi-card-green">
            <div class="operations-kpi-icon operations-kpi-icon-green">
                <i class="fas fa-play-circle"></i>
            </div>
            <div class="operations-kpi-value" id="operations_activeCount">0</div>
            <div class="operations-kpi-label" data-ops-lang="activeOperations">Active Operations</div>
        </div>
        
        <div class="operations-kpi-card operations-kpi-card-red">
            <div class="operations-kpi-icon operations-kpi-icon-red">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="operations-kpi-value" id="operations_completedCount">0</div>
            <div class="operations-kpi-label" data-ops-lang="expiredOperations">Completed Operations</div>
        </div>
        
        <div class="operations-kpi-card operations-kpi-card-green">
            <div class="operations-kpi-icon operations-kpi-icon-green">
                <i class="fas fa-chart-line"></i>
            </div>
            <div class="operations-kpi-value" id="operations_totalProfit">0.00</div>
            <div class="operations-kpi-label" data-ops-lang="totalProfit">Total Profit</div>
        </div>
        
        <div class="operations-kpi-card operations-kpi-card-brown">
            <div class="operations-kpi-icon operations-kpi-icon-brown">
                <i class="fas fa-file-invoice-dollar"></i>
            </div>
            <div class="operations-kpi-value" id="operations_totalContractValue">0.00</div>
            <div class="operations-kpi-label" data-ops-lang="totalContractValue">Total Contract Value</div>
        </div>
        
        <div class="operations-kpi-card operations-kpi-card-teal">
            <div class="operations-kpi-icon operations-kpi-icon-teal">
                <i class="fas fa-users"></i>
            </div>
            <div class="operations-kpi-value" id="operations_totalStaff">0</div>
            <div class="operations-kpi-label" data-ops-lang="totalStaffAllocated">Total Staff Allocated</div>
        </div>
    </div>

    <!-- Search and Filter Bar -->
    <div id="operations_searchBar" class="operations-search-bar" style="<?= $edit_mode ? 'display: none;' : '' ?>">
        <form method="GET" action="?page=operations" id="operations_searchForm" style="display: contents;">
            <input type="hidden" name="page" value="operations">
            
            <div class="operations-search-group">
                <label for="search" data-ops-lang="search">Search</label>
                <input type="text" id="search" name="search" 
                       value="<?= htmlspecialchars($search) ?>" 
                       data-ops-lang="searchPlaceholder" placeholder="Search by invoice #, contract # or client...">
            </div>
            
            <div class="operations-search-group">
                <label for="status_filter" data-ops-lang="filter">Status</label>
                <select id="status_filter" name="status_filter">
                    <option value="" data-ops-lang="allStatus">All Status</option>
                    <option value="Active" <?= $status_filter == 'Active' ? 'selected' : '' ?> data-ops-lang="active">Active</option>
                    <option value="Completed" <?= $status_filter == 'Completed' ? 'selected' : '' ?> data-ops-lang="completed">Completed</option>
                    <option value="Inactive" <?= $status_filter == 'Inactive' ? 'selected' : '' ?> data-ops-lang="inactive">Inactive</option>
                </select>
            </div>
            
            <div class="operations-search-actions">
                <button type="submit" class="operations-search-btn">
                    <i class="fas fa-search"></i> <span data-ops-lang="search">Search</span>
                </button>
                <a href="?page=operations" class="operations-clear-btn">
                    <i class="fas fa-times"></i> <span data-ops-lang="clear">Clear</span>
                </a>
            </div>
        </form>
    </div>

    <!-- Stats Bar -->
    <div id="operations_statsBar" class="operations-stats" style="<?= $edit_mode ? 'display: none;' : '' ?>">
        <div class="operations-stats-info">
            <i class="fas fa-tasks"></i>
            <span id="totalRecords" data-ops-lang="totalRecords">Total Operations</span>
            <span>:</span>
            <span class="operations-stats-count" id="operations_totalRecordsCount"><?= $total_records ?></span>
            <span data-ops-lang="records">records</span>
        </div>
    </div>

    <!-- Data Table -->
    <div id="operations_tableContainer" class="operations-table-container" style="<?= $edit_mode ? 'display: none;' : '' ?>">
        <table class="operations-table" id="operations_dataTable">
            <thead>
                <tr>
                    <th data-ops-lang="invoiceId">Invoice ID</th>
                    <th data-ops-lang="contractNumber">Contract Number</th>
                    <th data-ops-lang="clientName">Client Name</th>
                    <th data-ops-lang="category">Project Type</th>
                    <th data-ops-lang="startDate">Start Date</th>
                    <th data-ops-lang="endDate">End Date</th>
                    <th data-ops-lang="status">Status</th>
                    <th data-ops-lang="createdBy">Created By</th>
                    <th data-ops-lang="created">Created</th>
                    <th data-ops-lang="actions">Actions</th>
                </tr>
            </thead>
            <tbody id="operations_tableBody">
                <?php if ($operations_result && $operations_result->num_rows > 0): ?>
                    <?php while ($row = $operations_result->fetch_assoc()): ?>
                        <tr data-operation='<?= json_encode([
                            'id' => $row['id'],
                            'invoice_id' => $row['invoice_id'],
                            'contract_number' => $row['contract_number'],
                            'client_name' => $row['customer_name'] ?? 'N/A',
                            'project_group_name' => $row['project_group_name'] ?? 'N/A',
                            'category_name' => $row['category_name'] ?? 'N/A',
                            'duration_type' => $row['duration_type'],
                            'start_date' => $row['start_date'],
                            'end_date' => $row['end_date'],
                            'status' => $row['status'],
                            'assigned_staff' => $row['assigned_staff'],
                            'created_by' => $row['created_by'],
                            'created_at' => $row['created_at'],
                            'contract_value' => $row['contract_value'] ?? 0,
                            'tax' => $row['tax'] ?? 0,
                            'commission' => $row['commission'] ?? 0,
                            'cost_of_project' => $row['cost_of_project'] ?? 0,
                            'staff_cost' => $row['staff_cost'] ?? 0,
                            'overhead_cost' => $row['overhead_cost'] ?? 0,
                            'target_profit' => $row['target_profit'] ?? 0,
                            'actual_profit' => $row['actual_profit'] ?? 0,
                            'profit_difference' => $row['profit_difference'] ?? 0,
                            'client' => [
                                'customer_name' => $row['customer_name'] ?? 'N/A',
                                'contact_person' => $row['contact_person'] ?? 'N/A',
                                'tin_number' => $row['tin_number'] ?? 'N/A',
                                'vrn_number' => $row['vrn_number'] ?? 'N/A',
                                'address' => $row['address'] ?? 'N/A',
                                'email' => $row['email'] ?? 'N/A',
                                'type_of_business' => $row['type_of_business'] ?? 'N/A'
                            ]
                        ]) ?>'>
                            <td><strong><?= htmlspecialchars($row['invoice_id']) ?></strong></td>
                            <td><?= htmlspecialchars($row['contract_number']) ?></td>
                            <td><?= htmlspecialchars($row['customer_name'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($row['category_name'] ?? 'N/A') ?></td>
                            <td><?= date('d M Y', strtotime($row['start_date'])) ?></td>
                            <td><?= date('d M Y', strtotime($row['end_date'])) ?></td>
                            <td>
                                <span class="operations-status operations-status-<?= strtolower($row['status']) ?>">
                                    <?= $row['status'] ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($row['created_by']) ?></td>
                            <td>
                                <?= date('d M Y', strtotime($row['created_at'])) ?>
                                <div class="operations-timestamp"><?= date('H:i', strtotime($row['created_at'])) ?></div>
                            </td>
                            <td>
                                <div class="operations-actions">
                                    <?php if (canEdit($conn, $user_role_id, $module_name)): ?>
                                    <a href="?page=operations&operations_edit=<?= $row['id'] ?>" 
                                       class="operations-action-btn operations-action-edit">
                                        <i class="fas fa-edit"></i> <span data-ops-lang="edit">Edit</span>
                                    </a>
                                    <?php endif; ?>
                                    <?php if (canDelete($conn, $user_role_id, $module_name)): ?>
                                    <a href="javascript:void(0)" 
                                       onclick="operations_confirmDelete(<?= $row['id'] ?>, '<?= htmlspecialchars(addslashes($row['invoice_id'])) ?>')" 
                                       class="operations-action-btn operations-action-delete">
                                        <i class="fas fa-trash"></i> <span data-ops-lang="delete">Delete</span>
                                    </a>
                                    <?php endif; ?>
                                    <button onclick="operations_openInvoiceModal(this)" 
                                            data-operation='<?= json_encode([
                                                'id' => $row['id'],
                                                'invoice_id' => $row['invoice_id'],
                                                'contract_number' => $row['contract_number'],
                                                'client_name' => $row['customer_name'] ?? 'N/A',
                                                'project_group_name' => $row['project_group_name'] ?? 'N/A',
                                                'category_name' => $row['category_name'] ?? 'N/A',
                                                'duration_type' => $row['duration_type'],
                                                'start_date' => $row['start_date'],
                                                'end_date' => $row['end_date'],
                                                'status' => $row['status'],
                                                'assigned_staff' => $row['assigned_staff'],
                                                'created_by' => $row['created_by'],
                                                'created_at' => $row['created_at'],
                                                'contract_value' => $row['contract_value'] ?? 0,
                                                'tax' => $row['tax'] ?? 0,
                                                'commission' => $row['commission'] ?? 0,
                                                'cost_of_project' => $row['cost_of_project'] ?? 0,
                                                'staff_cost' => $row['staff_cost'] ?? 0,
                                                'overhead_cost' => $row['overhead_cost'] ?? 0,
                                                'target_profit' => $row['target_profit'] ?? 0,
                                                'actual_profit' => $row['actual_profit'] ?? 0,
                                                'profit_difference' => $row['profit_difference'] ?? 0,
                                                'client' => [
                                                    'customer_name' => $row['customer_name'] ?? 'N/A',
                                                    'contact_person' => $row['contact_person'] ?? 'N/A',
                                                    'tin_number' => $row['tin_number'] ?? 'N/A',
                                                    'vrn_number' => $row['vrn_number'] ?? 'N/A',
                                                    'address' => $row['address'] ?? 'N/A',
                                                    'email' => $row['email'] ?? 'N/A',
                                                    'type_of_business' => $row['type_of_business'] ?? 'N/A'
                                                ]
                                            ]) ?>'
                                            class="operations-action-btn operations-action-view">
                                        <i class="fas fa-eye"></i> <span data-ops-lang="viewInvoice">View Invoice</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr class="operations-empty-row">
                        <td colspan="10" class="operations-empty">
                            <i class="fas fa-tasks"></i>
                            <p data-ops-lang="noData">No operations found. Click "Add New Operation" to create one.</p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div id="operations_pagination" class="operations-pagination" style="<?= $edit_mode ? 'display: none;' : '' ?>">
        <?php if ($current_page > 1): ?>
            <a href="?page=operations&page_num=<?= $current_page - 1 ?>&search=<?= urlencode($search) ?>&status_filter=<?= urlencode($status_filter) ?>" class="operations-prev-btn">
                <i class="fas fa-chevron-left"></i> <span data-ops-lang="previous">Previous</span>
            </a>
        <?php else: ?>
            <span class="disabled"><i class="fas fa-chevron-left"></i> <span data-ops-lang="previous">Previous</span></span>
        <?php endif; ?>
        
        <span><span data-ops-lang="page">Page</span> <?= $current_page ?> <span data-ops-lang="of">of</span> <?= $total_pages ?></span>
        
        <?php if ($current_page < $total_pages): ?>
            <a href="?page=operations&page_num=<?= $current_page + 1 ?>&search=<?= urlencode($search) ?>&status_filter=<?= urlencode($status_filter) ?>" class="operations-next-btn">
                <span data-ops-lang="next">Next</span> <i class="fas fa-chevron-right"></i>
            </a>
        <?php else: ?>
            <span class="disabled"><span data-ops-lang="next">Next</span> <i class="fas fa-chevron-right"></i></span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Invoice Modal -->
<div id="operations_invoiceModal" class="operations-modal-overlay">
    <div class="operations-modal">
        <div class="operations-modal-header">
            <h3 class="operations-modal-title">
                <i class="fas fa-file-invoice"></i> <span data-modal-lang="invoiceTitle">OPERATION INVOICE</span>
            </h3>
            <button type="button" class="operations-modal-close" onclick="operations_closeInvoiceModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="operations-modal-body" id="operations_invoiceModalBody">
            <!-- Invoice content will be dynamically inserted here -->
        </div>
        <div class="operations-modal-footer">
            <button type="button" class="operations-btn operations-btn-secondary" onclick="operations_closeInvoiceModal()">
                <i class="fas fa-times"></i> <span data-ops-lang="close">Close</span>
            </button>
            <button type="button" class="operations-btn" onclick="operations_printInvoiceFromModal()">
                <i class="fas fa-print"></i> <span data-ops-lang="print">Print</span>
            </button>
        </div>
    </div>
</div>

<script>
    // ============================================================================
    // CONTRACT DATA FOR LOADING
    // ============================================================================
    const operations_contractsData = <?php echo json_encode($contracts); ?>;
    
    // ============================================================================
    // CATEGORIES DATA FOR CASCADING DROPDOWN (Grouped by Project Group)
    // ============================================================================
    const operations_categoriesData = <?php echo json_encode($categories_data); ?>;
    
    // ============================================================================
    // STAFF TABLE MANAGEMENT
    // ============================================================================
    let operations_staffRows = [];
    
    function operations_initStaffTable() {
        const tbody = document.getElementById('operations_staffTableBody');
        if (!tbody) return;
        
        // Clear existing rows
        tbody.innerHTML = '';
        
        // Create 10 empty rows by default
        for (let i = 0; i < 10; i++) {
            operations_addStaffRow(false);
        }
        
        // Load existing staff data if in edit mode
        <?php if ($edit_mode && isset($edit_data['assigned_staff']) && $edit_data['assigned_staff']): ?>
            const existingStaff = <?php echo $edit_data['assigned_staff'] ?: '[]'; ?>;
            if (existingStaff && existingStaff.length > 0) {
                // Clear default rows first
                tbody.innerHTML = '';
                operations_staffRows = [];
                // Add rows from existing data
                existingStaff.forEach(staff => {
                    operations_addStaffRow(false);
                    const lastRow = tbody.lastElementChild;
                    if (lastRow) {
                        const inputs = lastRow.querySelectorAll('input');
                        if (inputs[0]) inputs[0].value = staff.name || '';
                        if (inputs[1]) inputs[1].value = staff.email || '';
                        if (inputs[2]) inputs[2].value = staff.phone || '';
                        if (inputs[3]) inputs[3].value = staff.role || '';
                    }
                });
            }
        <?php endif; ?>
    }
    
    function operations_addStaffRow(updateHidden = true) {
        const tbody = document.getElementById('operations_staffTableBody');
        if (!tbody) return;
        
        const rowCount = tbody.children.length + 1;
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${rowCount}</td>
            <td><input type="text" class="operations-staff-name" placeholder="Staff Name"></td>
            <td><input type="email" class="operations-staff-email" placeholder="staff@example.com"></td>
            <td><input type="tel" class="operations-staff-phone" placeholder="+255 XXX XXX XXX"></td>
            <td><input type="text" class="operations-staff-role" placeholder="Role"></td>
            <td><button type="button" class="operations-remove-row-btn" onclick="operations_removeStaffRow(this)"><i class="fas fa-trash"></i></button></td>
        `;
        tbody.appendChild(tr);
        
        if (updateHidden) {
            operations_updateStaffHiddenField();
        }
    }
    
    function operations_removeStaffRow(button) {
        const row = button.closest('tr');
        if (row) {
            row.remove();
            operations_updateStaffHiddenField();
            operations_renumberStaffRows();
        }
    }
    
    function operations_renumberStaffRows() {
        const tbody = document.getElementById('operations_staffTableBody');
        if (!tbody) return;
        const rows = tbody.children;
        for (let i = 0; i < rows.length; i++) {
            const firstCell = rows[i].cells[0];
            if (firstCell) {
                firstCell.textContent = i + 1;
            }
        }
    }
    
    function operations_updateStaffHiddenField() {
        const tbody = document.getElementById('operations_staffTableBody');
        if (!tbody) return;
        
        const staffData = [];
        const rows = tbody.children;
        
        for (let i = 0; i < rows.length; i++) {
            const row = rows[i];
            const nameInput = row.querySelector('.operations-staff-name');
            const emailInput = row.querySelector('.operations-staff-email');
            const phoneInput = row.querySelector('.operations-staff-phone');
            const roleInput = row.querySelector('.operations-staff-role');
            
            const name = nameInput ? nameInput.value.trim() : '';
            const email = emailInput ? emailInput.value.trim() : '';
            const phone = phoneInput ? phoneInput.value.trim() : '';
            const role = roleInput ? roleInput.value.trim() : '';
            
            // Only add if at least name is provided
            if (name !== '') {
                staffData.push({
                    name: name,
                    email: email,
                    phone: phone,
                    role: role
                });
            }
        }
        
        document.getElementById('operations_assigned_staff').value = JSON.stringify(staffData);
    }
    
    // Listen for staff input changes
    document.addEventListener('DOMContentLoaded', function() {
        operations_initStaffTable();
        
        // Add event delegation for staff input changes
        const staffTableBody = document.getElementById('operations_staffTableBody');
        if (staffTableBody) {
            staffTableBody.addEventListener('input', function() {
                operations_updateStaffHiddenField();
            });
        }
    });
    
    // ============================================================================
    // CONTRACT DETAILS - Load and display contract information
    // ============================================================================
    function operations_loadContractDetails(contractNumber) {
        const detailsDiv = document.getElementById('operations_contractDetails');
        const detailsGrid = document.getElementById('operations_contractDetailsGrid');
        const financialGrid = document.getElementById('operations_financialGrid');
        
        if (!contractNumber || contractNumber === '') {
            detailsDiv.style.display = 'none';
            return;
        }
        
        const contract = operations_contractsData.find(c => c.contract_number === contractNumber);
        if (contract) {
            const lang = currentOperationsLang;
            
            // Format currency
            const formatMoney = (value) => {
                const num = parseFloat(value) || 0;
                return (contract.currency_symbol || 'TZS') + ' ' + num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            };
            
            detailsGrid.innerHTML = `
                <div class="operations-contract-detail-item">
                    <div class="operations-contract-detail-label" data-ops-lang="clientName">Client Name</div>
                    <div class="operations-contract-detail-value">${escapeHtml(contract.customer_name)}</div>
                </div>
                <div class="operations-contract-detail-item">
                    <div class="operations-contract-detail-label" data-ops-lang="contactPerson">Contact Person</div>
                    <div class="operations-contract-detail-value">${escapeHtml(contract.contact_person || 'N/A')}</div>
                </div>
                <div class="operations-contract-detail-item">
                    <div class="operations-contract-detail-label" data-ops-lang="tinNumber">TIN Number</div>
                    <div class="operations-contract-detail-value">${escapeHtml(contract.tin_number || 'N/A')}</div>
                </div>
                <div class="operations-contract-detail-item">
                    <div class="operations-contract-detail-label" data-ops-lang="vrnNumber">VRN Number</div>
                    <div class="operations-contract-detail-value">${escapeHtml(contract.vrn_number || '—')}</div>
                </div>
                <div class="operations-contract-detail-item">
                    <div class="operations-contract-detail-label" data-ops-lang="address">Address</div>
                    <div class="operations-contract-detail-value">${escapeHtml(contract.address || 'N/A')}</div>
                </div>
                <div class="operations-contract-detail-item">
                    <div class="operations-contract-detail-label" data-ops-lang="email">Email</div>
                    <div class="operations-contract-detail-value">${escapeHtml(contract.email || 'N/A')}</div>
                </div>
                <div class="operations-contract-detail-item">
                    <div class="operations-contract-detail-label" data-ops-lang="typeOfBusiness">Type of Business</div>
                    <div class="operations-contract-detail-value">${escapeHtml(contract.type_of_business || 'N/A')}</div>
                </div>
            `;
            
            // Update financial summary
            const contractValue = parseFloat(contract.contract_value) || 0;
            const tax = parseFloat(contract.tax) || 0;
            const commission = parseFloat(contract.commission) || 0;
            const costOfProject = parseFloat(contract.cost_of_project) || 0;
            const staffCost = parseFloat(contract.staff_cost) || 0;
            const overheadCost = parseFloat(contract.overhead_cost) || 0;
            const targetProfit = parseFloat(contract.target_profit) || 0;
            const actualProfit = parseFloat(contract.actual_profit) || 0;
            
            const totalCosts = tax + commission + costOfProject + staffCost + overheadCost;
            
            document.getElementById('operations_contractValueDisplay').innerHTML = formatMoney(contractValue);
            document.getElementById('operations_totalCostsDisplay').innerHTML = formatMoney(totalCosts);
            
            const actualProfitDisplay = document.getElementById('operations_actualProfitDisplay');
            actualProfitDisplay.innerHTML = formatMoney(actualProfit);
            actualProfitDisplay.className = 'operations-financial-value ' + 
                (actualProfit >= 0 ? 'operations-financial-value-positive' : 'operations-financial-value-negative');
            
            document.getElementById('operations_targetProfitDisplay').innerHTML = formatMoney(targetProfit);
            
            detailsDiv.style.display = 'block';
            
            // Update translations for contract details
            const detailLabels = detailsGrid.querySelectorAll('[data-ops-lang]');
            detailLabels.forEach(el => {
                const key = el.getAttribute('data-ops-lang');
                if (operations_translations[lang] && operations_translations[lang][key]) {
                    el.textContent = operations_translations[lang][key];
                }
            });
        } else {
            detailsDiv.style.display = 'none';
        }
    }
    
    function escapeHtml(str) {
        if (!str) return 'N/A';
        return String(str).replace(/[&<>]/g, function(m) {
            if (m === '&') return '&amp;';
            if (m === '<') return '&lt;';
            if (m === '>') return '&gt;';
            return m;
        });
    }
    
    // ============================================================================
    // CASCADING DROPDOWN: LOAD CATEGORIES BASED ON SELECTED PROJECT GROUP
    // ============================================================================
    function operations_loadCategories() {
        const groupId = document.getElementById('operations_project_group_id').value;
        const categorySelect = document.getElementById('operations_category_id');
        const lang = currentOperationsLang;
        
        // Clear current options
        categorySelect.innerHTML = '<option value="" data-ops-lang="selectCategory">Select Category</option>';
        
        if (groupId && operations_categoriesData[groupId]) {
            // Populate categories from pre-loaded data
            operations_categoriesData[groupId].forEach(category => {
                const option = document.createElement('option');
                option.value = category.id;
                option.textContent = category.name;
                categorySelect.appendChild(option);
            });
        }
        
        // Update placeholder text for the empty option
        const placeholderOption = categorySelect.querySelector('option[value=""]');
        if (placeholderOption) {
            placeholderOption.textContent = operations_translations[lang].selectCategory;
        }
        
        // For edit mode, try to select the existing category
        <?php if ($edit_mode && isset($edit_data['category_id'])): ?>
            const existingCatId = <?= json_encode($edit_data['category_id']) ?>;
            if (existingCatId && categorySelect.querySelector(`option[value="${existingCatId}"]`)) {
                categorySelect.value = existingCatId;
            }
        <?php endif; ?>
    }
    
    // ============================================================================
    // FORM TOGGLE
    // ============================================================================
    function operations_toggleForm() {
        const form = document.getElementById('operations_form');
        const kpiRow = document.getElementById('operations_kpiRow');
        const searchBar = document.getElementById('operations_searchBar');
        const statsBar = document.getElementById('operations_statsBar');
        const tableContainer = document.getElementById('operations_tableContainer');
        const pagination = document.getElementById('operations_pagination');
        const toggleBtn = document.getElementById('operations_toggleBtn');
        const lang = currentOperationsLang;
        
        if (form.classList.contains('show')) {
            form.classList.remove('show');
            if (kpiRow) kpiRow.style.display = '';
            if (searchBar) searchBar.style.display = '';
            if (statsBar) statsBar.style.display = '';
            if (tableContainer) tableContainer.style.display = '';
            if (pagination) pagination.style.display = '';
            toggleBtn.innerHTML = '<i class="fas fa-plus"></i> <span data-ops-lang="addNew">' + 
                (lang === 'en' ? 'Add New Operation' : 'Ongeza Operesheni Mpya') + '</span>';
        } else {
            form.classList.add('show');
            if (kpiRow) kpiRow.style.display = 'none';
            if (searchBar) searchBar.style.display = 'none';
            if (statsBar) statsBar.style.display = 'none';
            if (tableContainer) tableContainer.style.display = 'none';
            if (pagination) pagination.style.display = 'none';
            toggleBtn.innerHTML = '<i class="fas fa-times"></i> <span data-ops-lang="cancel">' + 
                (lang === 'en' ? 'Cancel' : 'Ghairi') + '</span>';
            
            const hiddenId = document.querySelector('input[name="id"]');
            if (hiddenId) hiddenId.remove();
            
            document.getElementById('operations_contract_number').value = '';
            document.getElementById('operations_project_group_id').value = '';
            document.getElementById('operations_category_id').innerHTML = '<option value="" data-ops-lang="selectCategory">Select Category</option>';
            document.getElementById('operations_duration_type').value = 'Non Recurring';
            document.getElementById('operations_start_date').value = '';
            document.getElementById('operations_end_date').value = '';
            document.getElementById('operations_status').value = 'Active';
            
            document.getElementById('operations_contractDetails').style.display = 'none';
            
            // Reset staff table
            operations_initStaffTable();
            
            const submitBtn = document.querySelector('button[name="operations_update"], button[name="operations_add"]');
            if (submitBtn) submitBtn.name = 'operations_add';
            
            const formHeader = document.querySelector('#operations_form h3');
            if (formHeader) formHeader.textContent = operations_translations[lang].addOperation;
        }
        
        updateOperationsLanguage(lang);
    }
    
    // ============================================================================
    // FORM VALIDATION
    // ============================================================================
    function operations_validateForm() {
        const contractNumber = document.getElementById('operations_contract_number').value;
        const projectGroupId = document.getElementById('operations_project_group_id').value;
        const categoryId = document.getElementById('operations_category_id').value;
        const startDate = document.getElementById('operations_start_date').value;
        const endDate = document.getElementById('operations_end_date').value;
        
        const lang = currentOperationsLang;
        
        if (!contractNumber || contractNumber === '') {
            alert(operations_translations[lang].selectContractRequired);
            document.getElementById('operations_contract_number').focus();
            return false;
        }
        
        if (!projectGroupId || projectGroupId === '') {
            alert(operations_translations[lang].selectProjectGroupRequired);
            document.getElementById('operations_project_group_id').focus();
            return false;
        }
        
        if (!categoryId || categoryId === '') {
            alert(operations_translations[lang].selectCategoryRequired);
            document.getElementById('operations_category_id').focus();
            return false;
        }
        
        if (startDate === '') {
            alert(operations_translations[lang].startDateRequired);
            document.getElementById('operations_start_date').focus();
            return false;
        }
        
        if (endDate === '') {
            alert(operations_translations[lang].endDateRequired);
            document.getElementById('operations_end_date').focus();
            return false;
        }
        
        if (endDate < startDate) {
            alert(operations_translations[lang].endDateInvalid);
            document.getElementById('operations_end_date').focus();
            return false;
        }
        
        // Update staff hidden field before submit
        operations_updateStaffHiddenField();
        
        return true;
    }
    
    // ============================================================================
    // DELETE CONFIRMATION
    // ============================================================================
    function operations_confirmDelete(id, invoiceId) {
        const lang = currentOperationsLang;
        const confirmMsg = operations_translations[lang].confirmDelete + ' "' + invoiceId + '"?\n' + 
                          operations_translations[lang].confirmDeleteMsg;
        
        if (confirm(confirmMsg)) {
            const row = event.target.closest('tr');
            row.style.opacity = '0.5';
            const loadingDiv = document.createElement('div');
            loadingDiv.className = 'operations-loading';
            loadingDiv.style.position = 'absolute';
            loadingDiv.style.top = '50%';
            loadingDiv.style.left = '50%';
            loadingDiv.style.transform = 'translate(-50%, -50%)';
            row.style.position = 'relative';
            row.appendChild(loadingDiv);
            
            setTimeout(() => {
                window.location.href = `?page=operations&operations_delete=${id}`;
            }, 300);
        }
    }
    
    // ============================================================================
    // KPI UPDATE FROM TABLE
    // ============================================================================
    function operations_updateKPIFromTable() {
        const tableBody = document.getElementById('operations_tableBody');
        if (!tableBody) return;
        
        const rows = tableBody.querySelectorAll('tr:not(.operations-empty-row)');
        let activeCount = 0;
        let completedCount = 0;
        let totalProfit = 0;
        let totalContractValue = 0;
        let totalStaff = 0;
        
        rows.forEach(row => {
            const statusSpan = row.querySelector('.operations-status');
            const status = statusSpan ? statusSpan.textContent.trim() : '';
            
            const cells = row.querySelectorAll('td');
            if (cells.length >= 5) {
                // Get contract value from data attribute
                const operationData = row.getAttribute('data-operation');
                if (operationData) {
                    try {
                        const data = JSON.parse(operationData);
                        totalContractValue += parseFloat(data.contract_value) || 0;
                        totalProfit += parseFloat(data.actual_profit) || 0;
                        
                        // Calculate staff count from assigned_staff
                        if (data.assigned_staff) {
                            const staff = JSON.parse(data.assigned_staff);
                            totalStaff += staff.length;
                        }
                    } catch(e) {}
                }
            }
            
            if (status === 'Active') activeCount++;
            if (status === 'Completed') completedCount++;
        });
        
        document.getElementById('operations_activeCount').textContent = activeCount.toLocaleString();
        document.getElementById('operations_completedCount').textContent = completedCount.toLocaleString();
        document.getElementById('operations_totalProfit').textContent = totalProfit.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        document.getElementById('operations_totalContractValue').textContent = totalContractValue.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        document.getElementById('operations_totalStaff').textContent = totalStaff.toLocaleString();
        
        const totalRecordsSpan = document.getElementById('operations_totalRecordsCount');
        if (totalRecordsSpan) {
            totalRecordsSpan.textContent = rows.length;
        }
    }
    
    function operations_observeTableChanges() {
        const tableBody = document.getElementById('operations_tableBody');
        if (!tableBody) return;
        
        const observer = new MutationObserver(function() {
            operations_updateKPIFromTable();
        });
        
        observer.observe(tableBody, { childList: true, subtree: true, characterData: true, attributes: true });
        operations_updateKPIFromTable();
    }
    
    // ============================================================================
    // INVOICE MODAL FUNCTIONS (UPDATED to include Company VRN and Client VRN)
    // ============================================================================
    const operations_companySettings = <?php echo json_encode([
        'company_name' => $company_settings['company_name'] ?? 'SHEHITA EMS',
        'company_address' => $company_settings['company_address'] ?? '',
        'company_email' => $company_settings['company_email'] ?? '',
        'company_phone' => $company_settings['company_phone'] ?? '',
        'company_tin' => $company_settings['company_tin'] ?? '',
        'vrn_number' => $company_settings['vrn_number'] ?? '',
        'currency_symbol' => $company_settings['currency_symbol'] ?? 'TZS',
        'logo_url' => $company_settings['logo_url'] ?? null
    ]); ?>;
    
    function operations_formatMoneyForInvoice(value) {
        let numValue = parseFloat(value);
        if (isNaN(numValue)) {
            numValue = 0;
        }
        return operations_companySettings.currency_symbol + ' ' + 
            new Intl.NumberFormat('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }).format(numValue);
    }
    
    function operations_openInvoiceModal(button) {
        const operationDataRaw = button.getAttribute('data-operation');
        if (!operationDataRaw) return;
        
        try {
            const operation = JSON.parse(operationDataRaw);
            const lang = currentOperationsLang;
            const t = operations_translations[lang];
            const now = new Date();
            const datePrinted = now.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) + 
                               ' ' + now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
            
            // Parse financial values
            const contractValue = parseFloat(operation.contract_value) || 0;
            const tax = parseFloat(operation.tax) || 0;
            const commission = parseFloat(operation.commission) || 0;
            const costOfProject = parseFloat(operation.cost_of_project) || 0;
            const staffCost = parseFloat(operation.staff_cost) || 0;
            const overheadCost = parseFloat(operation.overhead_cost) || 0;
            const targetProfit = parseFloat(operation.target_profit) || 0;
            const actualProfit = parseFloat(operation.actual_profit) || 0;
            const profitDifference = parseFloat(operation.profit_difference) || 0;
            
            const totalCosts = tax + commission + costOfProject + staffCost + overheadCost;
            
            // Parse assigned staff
            let staffHtml = '';
            let assignedStaff = [];
            if (operation.assigned_staff) {
                try {
                    assignedStaff = JSON.parse(operation.assigned_staff);
                } catch(e) {}
            }
            
            if (assignedStaff.length > 0) {
                staffHtml = `
                    <table class="operations-modal-invoice-staff-table">
                        <thead>
                            <tr><th>#</th><th>Staff Name</th><th>Email</th><th>Phone</th><th>Role</th></tr>
                        </thead>
                        <tbody>
                            ${assignedStaff.map((staff, idx) => `
                                <tr>
                                    <td>${idx + 1}</td>
                                    <td>${escapeHtml(staff.name)}</td>
                                    <td>${escapeHtml(staff.email)}</td>
                                    <td>${escapeHtml(staff.phone)}</td>
                                    <td>${escapeHtml(staff.role)}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                `;
            } else {
                staffHtml = '<p style="text-align: center; color: #666;">No staff assigned</p>';
            }
            
            // Build company details string with VRN
            let companyDetailsString = '';
            if (operations_companySettings.company_address) companyDetailsString += escapeHtml(operations_companySettings.company_address) + '<br>';
            if (operations_companySettings.company_email) companyDetailsString += escapeHtml(operations_companySettings.company_email) + ' | ';
            if (operations_companySettings.company_phone) companyDetailsString += escapeHtml(operations_companySettings.company_phone);
            if (companyDetailsString.endsWith(' | ')) companyDetailsString = companyDetailsString.slice(0, -3);
            companyDetailsString += '<br>';
            if (operations_companySettings.company_tin) companyDetailsString += 'TIN: ' + escapeHtml(operations_companySettings.company_tin);
            if (operations_companySettings.vrn_number) companyDetailsString += ' | VRN: ' + escapeHtml(operations_companySettings.vrn_number);
            
            const invoiceHtml = `
                <div class="operations-modal-invoice">
                    <div class="operations-modal-invoice-header">
                        ${operations_companySettings.logo_url ? `<img src="${operations_companySettings.logo_url}" alt="Logo" class="operations-modal-invoice-logo">` : ''}
                        <div class="operations-modal-invoice-company-name">${escapeHtml(operations_companySettings.company_name)}</div>
                        <div class="operations-modal-invoice-company-details">
                            ${companyDetailsString}
                        </div>
                    </div>
                    
                    <div class="operations-modal-invoice-title">${t.invoiceTitle || 'OPERATION INVOICE'}</div>
                    
                    <div class="operations-modal-invoice-section">
                        <div class="operations-modal-invoice-section-title">${t.operationInformation || 'OPERATION INFORMATION'}</div>
                        <div class="operations-modal-invoice-section-content">
                            <div class="operations-modal-invoice-row">
                                <span class="operations-modal-invoice-label">Invoice ID:</span>
                                <span class="operations-modal-invoice-value">${escapeHtml(operation.invoice_id)}</span>
                            </div>
                            <div class="operations-modal-invoice-row">
                                <span class="operations-modal-invoice-label">${t.projectGroupLabel || 'Project Group'}:</span>
                                <span class="operations-modal-invoice-value">${escapeHtml(operation.project_group_name)}</span>
                            </div>
                            <div class="operations-modal-invoice-row">
                                <span class="operations-modal-invoice-label">${t.categoryLabel || 'Category'}:</span>
                                <span class="operations-modal-invoice-value">${escapeHtml(operation.category_name)}</span>
                            </div>
                            <div class="operations-modal-invoice-row">
                                <span class="operations-modal-invoice-label">${t.durationTypeLabel || 'Duration Type'}:</span>
                                <span class="operations-modal-invoice-value">${escapeHtml(operation.duration_type)}</span>
                            </div>
                            <div class="operations-modal-invoice-row">
                                <span class="operations-modal-invoice-label">${t.startDate || 'Start Date'}:</span>
                                <span class="operations-modal-invoice-value">${operation.start_date}</span>
                            </div>
                            <div class="operations-modal-invoice-row">
                                <span class="operations-modal-invoice-label">${t.endDate || 'End Date'}:</span>
                                <span class="operations-modal-invoice-value">${operation.end_date}</span>
                            </div>
                            <div class="operations-modal-invoice-row">
                                <span class="operations-modal-invoice-label">${t.status || 'Status'}:</span>
                                <span class="operations-modal-invoice-value">${operation.status}</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="operations-modal-invoice-section">
                        <div class="operations-modal-invoice-section-title">${t.contractInformation || 'CONTRACT INFORMATION'}</div>
                        <div class="operations-modal-invoice-section-content">
                            <div class="operations-modal-invoice-row">
                                <span class="operations-modal-invoice-label">Contract Number:</span>
                                <span class="operations-modal-invoice-value">${escapeHtml(operation.contract_number)}</span>
                            </div>
                            <div class="operations-modal-invoice-row">
                                <span class="operations-modal-invoice-label">${t.clientName || 'Client Name'}:</span>
                                <span class="operations-modal-invoice-value">${escapeHtml(operation.client_name)}</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="operations-modal-invoice-section">
                        <div class="operations-modal-invoice-section-title">${t.clientInformation || 'CLIENT DETAILS'}</div>
                        <div class="operations-modal-invoice-section-content">
                            <div class="operations-modal-invoice-row">
                                <span class="operations-modal-invoice-label">Client Name:</span>
                                <span class="operations-modal-invoice-value">${escapeHtml(operation.client.customer_name)}</span>
                            </div>
                            <div class="operations-modal-invoice-row">
                                <span class="operations-modal-invoice-label">Contact Person:</span>
                                <span class="operations-modal-invoice-value">${escapeHtml(operation.client.contact_person)}</span>
                            </div>
                            <div class="operations-modal-invoice-row">
                                <span class="operations-modal-invoice-label">TIN Number:</span>
                                <span class="operations-modal-invoice-value">${escapeHtml(operation.client.tin_number)}</span>
                            </div>
                            <div class="operations-modal-invoice-row">
                                <span class="operations-modal-invoice-label" data-modal-lang="clientVRN">Client VRN:</span>
                                <span class="operations-modal-invoice-value">${escapeHtml(operation.client.vrn_number || '—')}</span>
                            </div>
                            <div class="operations-modal-invoice-row">
                                <span class="operations-modal-invoice-label">Address:</span>
                                <span class="operations-modal-invoice-value">${escapeHtml(operation.client.address)}</span>
                            </div>
                            <div class="operations-modal-invoice-row">
                                <span class="operations-modal-invoice-label">Email:</span>
                                <span class="operations-modal-invoice-value">${escapeHtml(operation.client.email)}</span>
                            </div>
                            <div class="operations-modal-invoice-row">
                                <span class="operations-modal-invoice-label">Type of Business:</span>
                                <span class="operations-modal-invoice-value">${escapeHtml(operation.client.type_of_business)}</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="operations-modal-invoice-section">
                        <div class="operations-modal-invoice-section-title">${t.financialBreakdown || 'FINANCIAL BREAKDOWN'}</div>
                        <div class="operations-modal-invoice-section-content">
                            <div class="operations-modal-invoice-financial-item">
                                <span class="operations-modal-invoice-financial-label">Contract Value:</span>
                                <span class="operations-modal-invoice-financial-value">${operations_formatMoneyForInvoice(contractValue)}</span>
                            </div>
                            <div class="operations-modal-invoice-financial-item">
                                <span class="operations-modal-invoice-financial-label">${t.less || 'Less:'} Tax:</span>
                                <span class="operations-modal-invoice-financial-value">- ${operations_formatMoneyForInvoice(tax)}</span>
                            </div>
                            <div class="operations-modal-invoice-financial-item">
                                <span class="operations-modal-invoice-financial-label">${t.less || 'Less:'} Commission:</span>
                                <span class="operations-modal-invoice-financial-value">- ${operations_formatMoneyForInvoice(commission)}</span>
                            </div>
                            <div class="operations-modal-invoice-financial-item">
                                <span class="operations-modal-invoice-financial-label">${t.less || 'Less:'} Cost of Project:</span>
                                <span class="operations-modal-invoice-financial-value">- ${operations_formatMoneyForInvoice(costOfProject)}</span>
                            </div>
                            <div class="operations-modal-invoice-financial-item">
                                <span class="operations-modal-invoice-financial-label">${t.less || 'Less:'} Staff Cost:</span>
                                <span class="operations-modal-invoice-financial-value">- ${operations_formatMoneyForInvoice(staffCost)}</span>
                            </div>
                            <div class="operations-modal-invoice-financial-item">
                                <span class="operations-modal-invoice-financial-label">${t.less || 'Less:'} Overhead Cost:</span>
                                <span class="operations-modal-invoice-financial-value">- ${operations_formatMoneyForInvoice(overheadCost)}</span>
                            </div>
                            
                            <div class="operations-modal-invoice-financial-item operations-modal-invoice-total-row">
                                <span class="operations-modal-invoice-financial-label">${t.totalCostsLabel || 'Total Costs'}:</span>
                                <span class="operations-modal-invoice-financial-value">${operations_formatMoneyForInvoice(totalCosts)}</span>
                            </div>
                            
                            <div class="operations-modal-invoice-financial-item operations-modal-invoice-total-row">
                                <span class="operations-modal-invoice-financial-label">${t.actualProfitLabel || 'Actual Profit'}:</span>
                                <span class="operations-modal-invoice-financial-value ${actualProfit >= 0 ? 'operations-modal-invoice-profit-positive' : 'operations-modal-invoice-profit-negative'}">
                                    ${operations_formatMoneyForInvoice(actualProfit)} ${actualProfit >= 0 ? '▲' : '▼'}
                                </span>
                            </div>
                            
                            <div class="operations-modal-invoice-financial-item">
                                <span class="operations-modal-invoice-financial-label">${t.targetProfitLabel || 'Target Profit (30%)'}:</span>
                                <span class="operations-modal-invoice-financial-value">${operations_formatMoneyForInvoice(targetProfit)}</span>
                            </div>
                            
                            <div class="operations-modal-invoice-financial-item">
                                <span class="operations-modal-invoice-financial-label">${t.profitDifferenceLabel || 'Profit Difference'}:</span>
                                <span class="operations-modal-invoice-financial-value ${profitDifference >= 0 ? 'operations-modal-invoice-profit-positive' : 'operations-modal-invoice-profit-negative'}">
                                    ${operations_formatMoneyForInvoice(profitDifference)} ${profitDifference >= 0 ? '▲' : '▼'}
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="operations-modal-invoice-staff">
                        <strong>${t.staffAllocatedLabel || 'Staff Assigned'}:</strong><br>
                        ${staffHtml}
                    </div>
                    
                    <div class="operations-modal-invoice-footer">
                        ${t.generatedBy || 'Generated by'}: ${escapeHtml(operation.created_by)}<br>
                        ${t.datePrinted || 'Date Printed'}: ${datePrinted}
                    </div>
                </div>
            `;
            
            document.getElementById('operations_invoiceModalBody').innerHTML = invoiceHtml;
            document.getElementById('operations_invoiceModal').classList.add('active');
            
            // Update modal translations
            const modalLabels = document.querySelectorAll('[data-modal-lang]');
            modalLabels.forEach(el => {
                const key = el.getAttribute('data-modal-lang');
                if (operations_translations[lang] && operations_translations[lang][key]) {
                    el.textContent = operations_translations[lang][key];
                }
            });
            
        } catch (e) {
            console.error('Error loading invoice:', e);
            alert('Error loading invoice. Please try again.');
        }
    }
    
    function operations_closeInvoiceModal() {
        document.getElementById('operations_invoiceModal').classList.remove('active');
        document.getElementById('operations_invoiceModalBody').innerHTML = '';
    }
    
    // ============================================================================
    // PRINT FUNCTION - Matches modal exactly, fits on one page
    // ============================================================================
    function operations_printInvoiceFromModal() {
        const modalBody = document.getElementById('operations_invoiceModalBody');
        if (!modalBody) return;
        
        const invoiceContent = modalBody.innerHTML;
        if (!invoiceContent) return;
        
        const lang = currentOperationsLang;
        const t = operations_translations[lang];
        
        const printWindow = window.open('', '_blank');
        if (!printWindow) {
            alert('Please allow pop-ups to print the invoice.');
            return;
        }
        
        printWindow.document.write(`
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>${t.invoiceTitle || 'OPERATION INVOICE'}</title>
                <style>
                    * {
                        margin: 0;
                        padding: 0;
                        box-sizing: border-box;
                    }
                    
                    body {
                        font-family: 'Inter', 'Segoe UI', Arial, sans-serif;
                        background: white;
                        margin: 0;
                        padding: 20px;
                        color: #1e293b;
                    }
                    
                    .print-invoice-container {
                        max-width: 1000px;
                        margin: 0 auto;
                        background: white;
                    }
                    
                    .operations-modal-invoice {
                        background: white;
                        font-family: 'Inter', sans-serif;
                    }
                    
                    .operations-modal-invoice-header {
                        text-align: center;
                        padding: 15px 20px;
                        border-bottom: 2px solid #3e2b1f;
                        margin-bottom: 15px;
                    }
                    
                    .operations-modal-invoice-logo {
                        max-width: 70px;
                        max-height: 70px;
                        margin-bottom: 10px;
                    }
                    
                    .operations-modal-invoice-company-name {
                        font-size: 20px;
                        font-weight: 700;
                        color: #3e2b1f;
                        margin-bottom: 4px;
                    }
                    
                    .operations-modal-invoice-company-details {
                        font-size: 10px;
                        color: #64748b;
                        line-height: 1.4;
                    }
                    
                    .operations-modal-invoice-title {
                        text-align: center;
                        font-size: 16px;
                        font-weight: 700;
                        color: #3e2b1f;
                        margin: 15px 0;
                        padding: 8px;
                        background: #f0e9e2;
                        letter-spacing: 1px;
                    }
                    
                    .operations-modal-invoice-section {
                        margin-bottom: 15px;
                        border: 1px solid #e2e8f0;
                        border-radius: 8px;
                        overflow: hidden;
                    }
                    
                    .operations-modal-invoice-section-title {
                        background: #f1f5f9;
                        padding: 8px 12px;
                        font-weight: 700;
                        color: #3e2b1f;
                        border-bottom: 1px solid #e2e8f0;
                        font-size: 12px;
                    }
                    
                    .operations-modal-invoice-section-content {
                        padding: 10px 12px;
                    }
                    
                    .operations-modal-invoice-row {
                        display: flex;
                        justify-content: space-between;
                        padding: 4px 0;
                        border-bottom: 1px dashed #e2e8f0;
                        font-size: 11px;
                    }
                    
                    .operations-modal-invoice-row:last-child {
                        border-bottom: none;
                    }
                    
                    .operations-modal-invoice-label {
                        font-weight: 600;
                        color: #475569;
                        width: 40%;
                    }
                    
                    .operations-modal-invoice-value {
                        color: #1e293b;
                        width: 60%;
                        text-align: right;
                    }
                    
                    .operations-modal-invoice-financial-item {
                        display: flex;
                        justify-content: space-between;
                        padding: 4px 0;
                        font-size: 11px;
                    }
                    
                    .operations-modal-invoice-financial-label {
                        font-weight: 500;
                        color: #475569;
                    }
                    
                    .operations-modal-invoice-financial-value {
                        font-weight: 600;
                        color: #1e293b;
                    }
                    
                    .operations-modal-invoice-total-row {
                        border-top: 2px solid #cbd5e1;
                        margin-top: 6px;
                        padding-top: 8px;
                        font-weight: 700;
                        font-size: 12px;
                    }
                    
                    .operations-modal-invoice-profit-positive {
                        color: #28a745;
                    }
                    
                    .operations-modal-invoice-profit-negative {
                        color: #dc3545;
                    }
                    
                    .operations-modal-invoice-staff {
                        background: #f0e9e2;
                        padding: 10px;
                        border-radius: 8px;
                        text-align: center;
                        margin-top: 12px;
                        font-size: 11px;
                    }
                    
                    .operations-modal-invoice-staff-table {
                        width: 100%;
                        border-collapse: collapse;
                        margin-top: 8px;
                        font-size: 10px;
                    }
                    
                    .operations-modal-invoice-staff-table th {
                        background: #e2e8f0;
                        padding: 6px;
                        text-align: left;
                    }
                    
                    .operations-modal-invoice-staff-table td {
                        padding: 4px 6px;
                        border-bottom: 1px solid #e2e8f0;
                    }
                    
                    .operations-modal-invoice-footer {
                        margin-top: 15px;
                        padding-top: 10px;
                        border-top: 1px solid #e2e8f0;
                        text-align: center;
                        font-size: 9px;
                        color: #64748b;
                    }
                    
                    @media print {
                        body {
                            margin: 0;
                            padding: 0;
                        }
                        .print-invoice-container {
                            max-width: 100%;
                            margin: 0;
                        }
                        @page {
                            size: A4 landscape;
                            margin: 0.4in;
                        }
                        .operations-modal-invoice-header {
                            padding: 10px 15px;
                            margin-bottom: 10px;
                        }
                        .operations-modal-invoice-title {
                            margin: 10px 0;
                            padding: 6px;
                            font-size: 14px;
                        }
                        .operations-modal-invoice-section {
                            margin-bottom: 10px;
                        }
                        .operations-modal-invoice-section-content {
                            padding: 8px 10px;
                        }
                        .operations-modal-invoice-row,
                        .operations-modal-invoice-financial-item {
                            padding: 3px 0;
                            font-size: 10px;
                        }
                        .operations-modal-invoice-total-row {
                            margin-top: 4px;
                            padding-top: 6px;
                        }
                        .operations-modal-invoice-staff {
                            padding: 8px;
                            margin-top: 10px;
                            font-size: 10px;
                        }
                        .operations-modal-invoice-footer {
                            margin-top: 10px;
                            padding-top: 8px;
                            font-size: 8px;
                        }
                    }
                </style>
            </head>
            <body>
                <div class="print-invoice-container">
                    ${invoiceContent}
                </div>
                <script>
                    window.onload = function() {
                        window.print();
                        window.onafterprint = function() {
                            window.close();
                        };
                        setTimeout(function() {
                            window.close();
                        }, 3000);
                    };
                <\/script>
            </body>
            </html>
        `);
        printWindow.document.close();
    }
    
    // Close modal when clicking overlay
    document.getElementById('operations_invoiceModal').addEventListener('click', function(e) {
        if (e.target === this) {
            operations_closeInvoiceModal();
        }
    });
    
    // ============================================================================
    // AUTO-HIDE ALERTS
    // ============================================================================
    setTimeout(() => {
        document.querySelectorAll('.operations-alert').forEach(alert => {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(() => alert.style.display = 'none', 500);
        });
    }, 5000);
    
    // ============================================================================
    // INITIALIZE ON PAGE LOAD (No sidebar code - ISSUE #1 resolved)
    // ============================================================================
    document.addEventListener('DOMContentLoaded', function() {
        <?php if ($edit_mode): ?>
            const editContractNumber = <?= json_encode($edit_data['contract_number'] ?? '') ?>;
            if (editContractNumber) operations_loadContractDetails(editContractNumber);
            const editProjectGroupId = <?= json_encode($edit_data['project_group_id'] ?? '') ?>;
            if (editProjectGroupId) {
                setTimeout(function() {
                    operations_loadCategories();
                }, 100);
            }
        <?php endif; ?>
        
        operations_observeTableChanges();
        
        const searchForm = document.getElementById('operations_searchForm');
        if (searchForm) {
            const submitBtn = searchForm.querySelector('button[type="submit"]');
            const clearBtn = searchForm.querySelector('.operations-clear-btn');
            
            if (submitBtn) {
                submitBtn.addEventListener('click', function(e) {
                    setTimeout(() => {
                        setTimeout(() => operations_updateKPIFromTable(), 100);
                    }, 50);
                });
            }
            
            if (clearBtn) {
                clearBtn.addEventListener('click', function(e) {
                    setTimeout(() => {
                        setTimeout(() => operations_updateKPIFromTable(), 100);
                    }, 50);
                });
            }
        }
    });
</script>