<?php
/**
 * PAPLONTECH Enterprise Management System
 * Projects Module - Contract Management with Financial Tracking
 * 
 * This module handles:
 * - Automatic table creation if not exists
 * - Display all contracts in a table with search/filter/pagination
 * - Add new contract (inline form)
 * - Edit existing contract
 * - Delete contract with confirmation
 * - Real-time financial calculations
 * - Client selection with details display
 * - Auto-generate contract numbers (CON00001 format)
 * - KPI cards that update based on filtered data
 * - Status auto-update (Expired based on end_date)
 * - View Invoice modal with print functionality
 * - Full English/Swahili translation support
 * - CSRF protection for forms
 * - Permission-based access control
 * 
 * PERMISSION ENHANCED: Buttons respect user permissions (can_add, can_edit, can_delete)
 */

// Prevent direct access
if (!defined('ACCESS_ALLOWED') && basename($_SERVER['PHP_SELF']) == basename(__FILE__)) {
    // This file is included, so we're safe
}

// Get database connection from parent
global $conn;

// PERMISSION: Get user role and check module permissions
$user_role_id = $_SESSION['role_id'] ?? 0;
$module_name = 'projects';

// PERMISSION: Check if user has view permission
if (!canView($conn, $user_role_id, $module_name)) {
    echo '<div class="projectmanage-alert projectmanage-alert-danger" style="text-align: center; padding: 40px;">
            <i class="fas fa-lock" style="font-size: 48px; margin-bottom: 16px; display: block;"></i>
            <h3>Access Denied</h3>
            <p>You do not have permission to view this module.</p>
          </div>';
    return; // Stop rendering the module
}

/**
 * ============================================================================
 * DATABASE SCHEMA CREATION
 * ============================================================================
 */

// Create projects table if not exists
// Schema note: the `projects` table is created centrally in config.php
// (in dependency order). This module assumes it already exists.

/**
 * ============================================================================
 * REMOVE project_name COLUMN IF EXISTS (for backward compatibility)
 * ============================================================================
 */
$check_column = $conn->query("SHOW COLUMNS FROM projects LIKE 'project_name'");
if ($check_column && $check_column->num_rows > 0) {
    $conn->query("ALTER TABLE projects DROP COLUMN project_name");
}

/**
 * ============================================================================
 * STATUS AUTO-UPDATE RULE 1: On Page Load (PHP)
 * Rule: If end_date < CURDATE() AND status != 'Inactive' → change to 'Expired'
 * Status 'Inactive' is manually set and NEVER auto-changed
 * ============================================================================
 */
$auto_update_sql = "UPDATE projects 
                    SET status = 'Expired' 
                    WHERE end_date < CURDATE() 
                    AND status != 'Inactive'
                    AND status != 'Expired'";
$conn->query($auto_update_sql);

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
 * CHECK IF TABLE IS EMPTY AND RESET AUTO_INCREMENT IF NEEDED
 * ============================================================================
 */
$check_empty = $conn->query("SELECT COUNT(*) as count FROM projects");
$row_count = $check_empty->fetch_assoc()['count'];

if ($row_count == 0) {
    $conn->query("ALTER TABLE projects AUTO_INCREMENT = 1");
}

// Initialize variables for messages
$projects_message = '';
$projects_message_type = '';

// Initialize search/filter/pagination variables
$search = isset($_GET['search']) ? sanitize($conn, $_GET['search']) : '';
$status_filter = isset($_GET['status_filter']) ? sanitize($conn, $_GET['status_filter']) : '';
$current_page = isset($_GET['page_num']) ? (int)$_GET['page_num'] : 1;
$records_per_page = 10;
$offset = ($current_page - 1) * $records_per_page;

/**
 * ============================================================================
 * STATUS OPTIONS (Active, Expired, Inactive only)
 * ============================================================================
 */
$status_options = [
    'Active' => 'Active',
    'Expired' => 'Expired',
    'Inactive' => 'Inactive'
];

/**
 * ============================================================================
 * GENERATE NEXT CONTRACT NUMBER
 * ============================================================================
 */
function getNextContractNumber($conn) {
    $result = $conn->query("SELECT contract_number FROM projects ORDER BY id DESC LIMIT 1");
    if ($result && $result->num_rows > 0) {
        $last = $result->fetch_assoc()['contract_number'];
        $num = (int)substr($last, 3);
        $next = $num + 1;
        return 'CON' . str_pad($next, 5, '0', STR_PAD_LEFT);
    }
    return 'CON00001';
}

/**
 * ============================================================================
 * GET COMPANY SETTINGS FOR INVOICE
 * ============================================================================
 */
function getCompanySettings($conn) {
    $result = $conn->query("SELECT * FROM company_settings LIMIT 1");
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    return [
        'company_name' => 'PAPLONTECH',
        'company_address' => '',
        'company_email' => '',
        'company_phone' => '',
        'company_tin' => '',
        'currency_symbol' => 'TZS',
        'logo_url' => null
    ];
}

/**
 * ============================================================================
 * STATUS DETERMINATION HELPER (Rule 2)
 * ============================================================================
 */
function determineProjectStatus($end_date, $selected_status) {
    $today = date('Y-m-d');
    
    // RULE: If user selected 'Inactive', ALWAYS respect the user's choice
    if ($selected_status === 'Inactive') {
        return 'Inactive';
    }
    
    // RULE: If user selected 'Expired', keep as 'Expired'
    if ($selected_status === 'Expired') {
        return 'Expired';
    }
    
    // RULE: If user selected 'Active' but end_date < today → override to 'Expired'
    if ($selected_status === 'Active' && $end_date < $today) {
        return 'Expired';
    }
    
    // Default to user's selection or Active
    return $selected_status;
}

/**
 * ============================================================================
 * HANDLE FORM SUBMISSIONS WITH PERMISSION CHECKS
 * ============================================================================
 */

// PERMISSION: Check add permission before processing add operation
if (isset($_POST['projects_add']) && !canAdd($conn, $user_role_id, $module_name)) {
    $projects_message = "You do not have permission to add projects.";
    $projects_message_type = "danger";
} elseif (isset($_POST['projects_add'])) {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $projects_message = "Invalid form submission. Please try again.";
        $projects_message_type = "danger";
    } else {
        $contract_number = getNextContractNumber($conn);
        $client_id = (int)$_POST['client_id'];
        $effective_date = sanitize($conn, $_POST['effective_date']);
        $end_date = sanitize($conn, $_POST['end_date']);
        $contract_value = (float)$_POST['contract_value'];
        $tax = (float)$_POST['tax'];
        $commission = (float)$_POST['commission'];
        $cost_of_project = (float)$_POST['cost_of_project'];
        $staff_cost = (float)$_POST['staff_cost'];
        $overhead_cost = (float)$_POST['overhead_cost'];
        $number_of_staff_allocated = (int)$_POST['number_of_staff_allocated'];
        $selected_status = sanitize($conn, $_POST['status']);
        $created_by = $_SESSION['name'];
        
        // Determine final status based on date and user selection (Rule 2)
        $status = determineProjectStatus($end_date, $selected_status);
        
        // Calculate financials
        $target_profit = $contract_value * 0.30;
        $total_costs = $tax + $commission + $cost_of_project + $staff_cost + $overhead_cost;
        $actual_profit = $contract_value - $total_costs;
        $profit_difference = $actual_profit - $target_profit;
        
        // Validate inputs
        $errors = [];
        
        if ($client_id <= 0) {
            $errors[] = "Please select a valid client";
        }
        
        if (empty($effective_date)) {
            $errors[] = "Effective date is required";
        }
        
        if (empty($end_date)) {
            $errors[] = "End date is required";
        }
        
        if ($end_date < $effective_date) {
            $errors[] = "End date must be on or after effective date";
        }
        
        if ($contract_value <= 0) {
            $errors[] = "Contract value must be greater than 0";
        }
        
        if ($tax < 0 || $commission < 0 || $cost_of_project < 0 || $staff_cost < 0 || $overhead_cost < 0) {
            $errors[] = "Cost values cannot be negative";
        }
        
        if ($number_of_staff_allocated < 0) {
            $errors[] = "Number of staff allocated cannot be negative";
        }
        
        if (!array_key_exists($selected_status, $status_options)) {
            $errors[] = "Invalid status";
        }
        
        if (empty($errors)) {
            // Insert new project
            $insert_stmt = $conn->prepare("INSERT INTO projects (
                contract_number, client_id, effective_date, end_date,
                contract_value, tax, commission, cost_of_project, staff_cost, overhead_cost,
                number_of_staff_allocated, target_profit, actual_profit, profit_difference,
                status, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $insert_stmt->bind_param("sissddddddidddss", 
                $contract_number, $client_id, $effective_date, $end_date,
                $contract_value, $tax, $commission, $cost_of_project, $staff_cost, $overhead_cost,
                $number_of_staff_allocated, $target_profit, $actual_profit, $profit_difference,
                $status, $created_by
            );
            
            if ($insert_stmt->execute()) {
                $projects_message = "Contract added successfully!";
                $projects_message_type = "success";
            } else {
                $projects_message = "Error adding contract: " . $conn->error;
                $projects_message_type = "danger";
            }
            $insert_stmt->close();
        } else {
            $projects_message = implode("<br>", $errors);
            $projects_message_type = "danger";
        }
    }
    
    // Generate new CSRF token
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// PERMISSION: Check edit permission before processing update operation
if (isset($_POST['projects_update']) && !canEdit($conn, $user_role_id, $module_name)) {
    $projects_message = "You do not have permission to edit projects.";
    $projects_message_type = "danger";
} elseif (isset($_POST['projects_update'])) {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $projects_message = "Invalid form submission. Please try again.";
        $projects_message_type = "danger";
    } else {
        $id = (int)$_POST['id'];
        $client_id = (int)$_POST['client_id'];
        $effective_date = sanitize($conn, $_POST['effective_date']);
        $end_date = sanitize($conn, $_POST['end_date']);
        $contract_value = (float)$_POST['contract_value'];
        $tax = (float)$_POST['tax'];
        $commission = (float)$_POST['commission'];
        $cost_of_project = (float)$_POST['cost_of_project'];
        $staff_cost = (float)$_POST['staff_cost'];
        $overhead_cost = (float)$_POST['overhead_cost'];
        $number_of_staff_allocated = (int)$_POST['number_of_staff_allocated'];
        $selected_status = sanitize($conn, $_POST['status']);
        
        // Determine final status based on date and user selection (Rule 2)
        $status = determineProjectStatus($end_date, $selected_status);
        
        // Calculate financials
        $target_profit = $contract_value * 0.30;
        $total_costs = $tax + $commission + $cost_of_project + $staff_cost + $overhead_cost;
        $actual_profit = $contract_value - $total_costs;
        $profit_difference = $actual_profit - $target_profit;
        
        // Validate inputs
        $errors = [];
        
        if ($id <= 0) {
            $errors[] = "Invalid ID";
        }
        
        if ($client_id <= 0) {
            $errors[] = "Please select a valid client";
        }
        
        if (empty($effective_date)) {
            $errors[] = "Effective date is required";
        }
        
        if (empty($end_date)) {
            $errors[] = "End date is required";
        }
        
        if ($end_date < $effective_date) {
            $errors[] = "End date must be on or after effective date";
        }
        
        if ($contract_value <= 0) {
            $errors[] = "Contract value must be greater than 0";
        }
        
        if ($tax < 0 || $commission < 0 || $cost_of_project < 0 || $staff_cost < 0 || $overhead_cost < 0) {
            $errors[] = "Cost values cannot be negative";
        }
        
        if ($number_of_staff_allocated < 0) {
            $errors[] = "Number of staff allocated cannot be negative";
        }
        
        if (!array_key_exists($selected_status, $status_options)) {
            $errors[] = "Invalid status";
        }
        
        if (empty($errors)) {
            // Update project (preserve contract_number and created_by)
            $update_stmt = $conn->prepare("UPDATE projects SET 
                client_id = ?, effective_date = ?, end_date = ?,
                contract_value = ?, tax = ?, commission = ?, cost_of_project = ?, 
                staff_cost = ?, overhead_cost = ?, number_of_staff_allocated = ?,
                target_profit = ?, actual_profit = ?, profit_difference = ?, status = ?
                WHERE id = ?");
            
            $update_stmt->bind_param("issddddddidddsi", 
                $client_id, $effective_date, $end_date,
                $contract_value, $tax, $commission, $cost_of_project, 
                $staff_cost, $overhead_cost, $number_of_staff_allocated,
                $target_profit, $actual_profit, $profit_difference, $status, $id
            );
            
            if ($update_stmt->execute()) {
                $projects_message = "Contract updated successfully!";
                $projects_message_type = "success";
            } else {
                $projects_message = "Error updating contract: " . $conn->error;
                $projects_message_type = "danger";
            }
            $update_stmt->close();
        } else {
            $projects_message = implode("<br>", $errors);
            $projects_message_type = "danger";
        }
    }
    
    // Generate new CSRF token
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// PERMISSION: Check delete permission before processing delete operation
if (isset($_GET['projects_delete']) && !canDelete($conn, $user_role_id, $module_name)) {
    $projects_message = "You do not have permission to delete projects.";
    $projects_message_type = "danger";
} elseif (isset($_GET['projects_delete'])) {
    $id = (int)$_GET['projects_delete'];
    
    if ($id > 0) {
        $delete_stmt = $conn->prepare("DELETE FROM projects WHERE id = ?");
        $delete_stmt->bind_param("i", $id);
        
        if ($delete_stmt->execute()) {
            $projects_message = "Contract deleted successfully!";
            $projects_message_type = "success";
            
            // Check if table is empty and reset auto-increment
            $check_empty = $conn->query("SELECT COUNT(*) as count FROM projects");
            $row_count = $check_empty->fetch_assoc()['count'];
            
            if ($row_count == 0) {
                $conn->query("ALTER TABLE projects AUTO_INCREMENT = 1");
            }
        } else {
            $projects_message = "Error deleting contract: " . $conn->error;
            $projects_message_type = "danger";
        }
        $delete_stmt->close();
    }
}

// Get edit data if in edit mode (and user has edit permission)
$edit_mode = false;
$edit_data = null;

if (isset($_GET['projects_edit'])) {
    $edit_id = (int)$_GET['projects_edit'];
    // PERMISSION: Only allow edit if user has edit permission
    if ($edit_id > 0 && canEdit($conn, $user_role_id, $module_name)) {
        $edit_stmt = $conn->prepare("SELECT * FROM projects WHERE id = ?");
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
 * FETCH ACTIVE CLIENTS FOR DROPDOWN
 * ============================================================================
 */
$clients = [];
$clients_query = "SELECT id, customer_name, contact_person, tin_number, address, email, type_of_business 
                  FROM customers WHERE status = 'Active' ORDER BY customer_name ASC";
$clients_result = $conn->query($clients_query);
if ($clients_result && $clients_result->num_rows > 0) {
    while ($row = $clients_result->fetch_assoc()) {
        $clients[] = $row;
    }
}

/**
 * ============================================================================
 * BUILD QUERY WITH SEARCH AND FILTER
 * ============================================================================
 */
$where_clause = "";
$params = [];
$types = "";

if (!empty($search)) {
    $where_clause .= " (p.contract_number LIKE ? OR c.customer_name LIKE ?) ";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ss";
}

if (!empty($status_filter)) {
    if (!empty($where_clause)) {
        $where_clause .= " AND ";
    }
    $where_clause .= " p.status = ? ";
    $params[] = $status_filter;
    $types .= "s";
}

if (!empty($where_clause)) {
    $where_clause = "WHERE " . $where_clause;
}

// Count total records for pagination
$count_query = "SELECT COUNT(*) as total FROM projects p 
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

// Fetch projects with pagination
$projects_query = "SELECT p.*, c.customer_name, c.contact_person, c.tin_number, c.address, c.email, c.type_of_business
                   FROM projects p 
                   LEFT JOIN customers c ON p.client_id = c.id 
                   $where_clause 
                   ORDER BY p.id DESC 
                   LIMIT ? OFFSET ?";
$params[] = $records_per_page;
$params[] = $offset;
$types .= "ii";

$projects_stmt = $conn->prepare($projects_query);
$projects_stmt->bind_param($types, ...$params);
$projects_stmt->execute();
$projects_result = $projects_stmt->get_result();

// Store results in array for JSON output (used by KPI JavaScript)
$projects_data = [];
if ($projects_result && $projects_result->num_rows > 0) {
    while ($row = $projects_result->fetch_assoc()) {
        $projects_data[] = $row;
    }
    // Reset pointer for table display
    $projects_stmt->execute();
    $projects_result = $projects_stmt->get_result();
}

/**
 * ============================================================================
 * FETCH COMPANY SETTINGS FOR INVOICE
 * ============================================================================
 */
$company_settings = getCompanySettings($conn);
?>

<!-- PROJECTS TRANSLATIONS -->
<script>
// Projects translations for English and Swahili
const projects_translations = {
    en: {
        pageTitle: 'Contracts Management',
        addNew: 'Add New Contract',
        editProject: 'Edit Contract',
        addProject: 'Add New Contract',
        id: 'ID',
        contractNumber: 'Contract Number',
        clientName: 'Client Name',
        effectiveDate: 'Effective Date',
        endDate: 'End Date',
        contractValue: 'Contract Value',
        actualProfit: 'Actual Profit',
        status: 'Status',
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
        confirmDelete: 'Are you sure you want to delete contract',
        confirmDeleteMsg: 'This action cannot be undone.',
        noData: 'No contracts found. Click "Add New Contract" to create one.',
        
        // KPI Cards
        activeProjects: 'Active Contracts',
        expiredProjects: 'Expired Contracts',
        totalProfit: 'Total Profit',
        totalContractValue: 'Total Contract Value',
        totalStaffAllocated: 'Total Staff Allocated',
        
        // Form Labels
        selectClient: 'Select Client',
        clientDetails: 'Client Details',
        clientID: 'Client ID',
        contactPerson: 'Contact Person',
        tinNumber: 'TIN Number',
        address: 'Address',
        email: 'Email',
        typeOfBusiness: 'Type of Business',
        tax: 'Tax',
        commission: 'Commission',
        costOfProject: 'Cost of Project',
        staffCost: 'Staff Cost',
        overheadCost: 'Overhead Cost',
        numberOfStaffAllocated: 'Number of Staff Allocated',
        targetProfit: 'Target Profit (30%)',
        totalCosts: 'Total Costs',
        profitDifference: 'Profit Difference',
        
        // Financial Summary Card
        financialSummary: 'Financial Summary',
        
        // Status Options
        active: 'Active',
        expired: 'Expired',
        inactive: 'Inactive',
        
        // Validation Messages
        selectClientRequired: 'Please select a client!',
        effectiveDateRequired: 'Effective date is required!',
        endDateRequired: 'End date is required!',
        endDateInvalid: 'End date must be on or after effective date!',
        contractValueRequired: 'Contract value must be greater than 0!',
        negativeValueError: 'Values cannot be negative!',
        
        // Search/Filter
        search: 'Search',
        filter: 'Filter',
        allStatus: 'All Status',
        clear: 'Clear',
        searchPlaceholder: 'Search by contract # or client...',
        
        // Pagination
        page: 'Page',
        of: 'of',
        previous: 'Previous',
        next: 'Next',
        totalRecords: 'Total Contracts',
        records: 'records',
        
        // Loading
        loading: 'Loading...',
        
        // Invoice Modal Labels
        invoiceTitle: 'CONTRACT INVOICE',
        contractInformation: 'CONTRACT INFORMATION',
        clientInformation: 'CLIENT DETAILS',
        financialBreakdown: 'FINANCIAL BREAKDOWN',
        less: 'Less:',
        totalCostsLabel: 'Total Costs',
        targetProfitLabel: 'Target Profit (30%)',
        actualProfitLabel: 'Actual Profit',
        profitDifferenceLabel: 'Profit Difference',
        staffAllocatedLabel: 'Staff Allocated',
        generatedBy: 'Generated by',
        datePrinted: 'Date Printed',
        staffMembers: 'staff members'
    },
    sw: {
        pageTitle: 'Usimamizi wa Mikataba',
        addNew: 'Ongeza Mkataba Mpya',
        editProject: 'Hariri Mkataba',
        addProject: 'Ongeza Mkataba Mpya',
        id: 'Kitambulisho',
        contractNumber: 'Namba ya Mkataba',
        clientName: 'Jina la Mteja',
        effectiveDate: 'Tarehe ya Kuanza',
        endDate: 'Tarehe ya Mwisho',
        contractValue: 'Thamani ya Mkataba',
        actualProfit: 'Faida Halisi',
        status: 'Hali',
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
        confirmDelete: 'Una uhakika unataka kufuta mkataba',
        confirmDeleteMsg: 'Kitendo hiki hakiwezi kutenguliwa.',
        noData: 'Hakuna mikataba iliyopatikana. Bofya "Ongeza Mkataba Mpya" kuunda.',
        
        // KPI Cards
        activeProjects: 'Mikataba Inayoendelea',
        expiredProjects: 'Mikataba Iliyoisha',
        totalProfit: 'Jumla ya Faida',
        totalContractValue: 'Jumla ya Thamani ya Mikataba',
        totalStaffAllocated: 'Jumla ya Wafanyakazi Waliopangiwa',
        
        // Form Labels
        selectClient: 'Chagua Mteja',
        clientDetails: 'Taarifa za Mteja',
        clientID: 'Kitambulisho cha Mteja',
        contactPerson: 'Mtu wa Kuwasiliana',
        tinNumber: 'Namba ya TIN',
        address: 'Anwani',
        email: 'Barua pepe',
        typeOfBusiness: 'Aina ya Biashara',
        tax: 'Kodi',
        commission: 'Kamisheni',
        costOfProject: 'Gharama ya Mradi',
        staffCost: 'Gharama za Wafanyakazi',
        overheadCost: 'Gharama za Uendeshaji',
        numberOfStaffAllocated: 'Idadi ya Wafanyakazi Waliopangiwa',
        targetProfit: 'Faida Inayolengwa (30%)',
        totalCosts: 'Jumla ya Gharama',
        profitDifference: 'Tofauti ya Faida',
        
        // Financial Summary Card
        financialSummary: 'Muhtasari wa Kifedha',
        
        // Status Options
        active: 'Inaendelea',
        expired: 'Imeisha',
        inactive: 'Haifanyi Kazi',
        
        // Validation Messages
        selectClientRequired: 'Tafadhali chagua mteja!',
        effectiveDateRequired: 'Tarehe ya kuanza inahitajika!',
        endDateRequired: 'Tarehe ya mwisho inahitajika!',
        endDateInvalid: 'Tarehe ya mwisho lazima iwe sawa au baada ya tarehe ya kuanza!',
        contractValueRequired: 'Thamani ya mkataba lazima iwe kubwa kuliko 0!',
        negativeValueError: 'Thamani haziwezi kuwa chini ya sifuri!',
        
        // Search/Filter
        search: 'Tafuta',
        filter: 'Chuja',
        allStatus: 'Hali Zote',
        clear: 'Futa',
        searchPlaceholder: 'Tafuta kwa namba ya mkataba au mteja...',
        
        // Pagination
        page: 'Ukurasa',
        of: 'kati ya',
        previous: 'Iliyotangulia',
        next: 'Ijayo',
        totalRecords: 'Jumla ya Mikataba',
        records: 'rekodi',
        
        // Loading
        loading: 'Inapakia...',
        
        // Invoice Modal Labels
        invoiceTitle: 'ANKARA YA MKATABA',
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
        staffMembers: 'wafanyakazi'
    }
};

// Current language (will be updated by homepage.js)
let currentProjectsLang = localStorage.getItem('preferredLanguage') || 'en';

// Function to update all translatable elements in projects module
function updateProjectsLanguage(lang) {
    currentProjectsLang = lang;
    const elements = document.querySelectorAll('[data-proj-lang]');
    
    elements.forEach(element => {
        const key = element.getAttribute('data-proj-lang');
        if (projects_translations[lang] && projects_translations[lang][key]) {
            if (element.tagName === 'INPUT' || element.tagName === 'TEXTAREA') {
                if (element.getAttribute('type') !== 'submit' && element.getAttribute('type') !== 'button') {
                    element.placeholder = projects_translations[lang][key];
                }
            } else if (element.tagName === 'OPTION') {
                element.textContent = projects_translations[lang][key];
            } else {
                element.textContent = projects_translations[lang][key];
            }
        }
    });
    
    // Update table header
    const thElements = document.querySelectorAll('th[data-proj-lang]');
    thElements.forEach(th => {
        const key = th.getAttribute('data-proj-lang');
        if (projects_translations[lang] && projects_translations[lang][key]) {
            th.textContent = projects_translations[lang][key];
        }
    });
    
    // Update KPI card labels
    const kpiLabels = document.querySelectorAll('.projectmanage-kpi-label');
    kpiLabels.forEach(label => {
        const key = label.getAttribute('data-proj-lang');
        if (key && projects_translations[lang] && projects_translations[lang][key]) {
            label.textContent = projects_translations[lang][key];
        }
    });
    
    // Update empty state message
    const emptyState = document.querySelector('.projectmanage-empty p');
    if (emptyState) {
        emptyState.textContent = projects_translations[lang].noData;
    }
    
    // Update form header
    const formHeader = document.querySelector('#projectmanage_form h3');
    if (formHeader) {
        const isEditMode = document.querySelector('input[name="id"]') !== null;
        formHeader.textContent = projects_translations[lang][isEditMode ? 'editProject' : 'addProject'];
    }
    
    // Update search input placeholder
    const searchInput = document.querySelector('input[name="search"]');
    if (searchInput) {
        searchInput.placeholder = projects_translations[lang].searchPlaceholder;
    }
    
    // Update total records text
    const totalRecordsSpan = document.querySelector('#totalRecords');
    if (totalRecordsSpan) {
        totalRecordsSpan.textContent = projects_translations[lang].totalRecords;
    }
    
    // Update pagination buttons
    const prevBtn = document.querySelector('.projectmanage-prev-btn');
    const nextBtn = document.querySelector('.projectmanage-next-btn');
    if (prevBtn && prevBtn.tagName === 'A') prevBtn.innerHTML = `<i class="fas fa-chevron-left"></i> ${projects_translations[lang].previous}`;
    if (nextBtn && nextBtn.tagName === 'A') nextBtn.innerHTML = `${projects_translations[lang].next} <i class="fas fa-chevron-right"></i>`;
    
    // Update financial summary labels
    const summaryLabels = document.querySelectorAll('[data-proj-summary]');
    summaryLabels.forEach(label => {
        const key = label.getAttribute('data-proj-summary');
        if (projects_translations[lang] && projects_translations[lang][key]) {
            label.textContent = projects_translations[lang][key];
        }
    });
    
    // Update modal labels
    const modalLabels = document.querySelectorAll('[data-modal-lang]');
    modalLabels.forEach(label => {
        const key = label.getAttribute('data-modal-lang');
        if (projects_translations[lang] && projects_translations[lang][key]) {
            label.textContent = projects_translations[lang][key];
        }
    });
}

// Listen for language change events from homepage
document.addEventListener('DOMContentLoaded', function() {
    updateProjectsLanguage(currentProjectsLang);
});

// This function will be called from homepage.js when language changes
window.updateProjectsLanguage = updateProjectsLanguage;
</script>

<style>
    /* Projects Module Styles - Using projectmanage_ prefix */
    .projectmanage-container {
        width: 100%;
    }

    /* KPI Cards Row */
    .projectmanage-kpi-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 32px;
    }

    .projectmanage-kpi-card {
        background: white;
        border-radius: 16px;
        padding: 20px;
        box-shadow: var(--shadow-md);
        border: 1px solid var(--gray-200);
        transition: all 0.2s;
        position: relative;
        overflow: hidden;
    }

    .projectmanage-kpi-card:hover {
        transform: translateY(-4px);
        box-shadow: var(--shadow-lg);
    }

    .projectmanage-kpi-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 4px;
        height: 100%;
    }

    .projectmanage-kpi-card-green::before {
        background: #28a745;
    }

    .projectmanage-kpi-card-red::before {
        background: #dc3545;
    }

    .projectmanage-kpi-card-brown::before {
        background: var(--brown-700);
    }

    .projectmanage-kpi-card-teal::before {
        background: #20c997;
    }

    .projectmanage-kpi-icon {
        font-size: 32px;
        margin-bottom: 12px;
    }

    .projectmanage-kpi-icon-green {
        color: #28a745;
    }

    .projectmanage-kpi-icon-red {
        color: #dc3545;
    }

    .projectmanage-kpi-icon-brown {
        color: var(--brown-700);
    }

    .projectmanage-kpi-icon-teal {
        color: #20c997;
    }

    .projectmanage-kpi-value {
        font-size: 28px;
        font-weight: 700;
        color: var(--gray-800);
        margin-bottom: 8px;
    }

    .projectmanage-kpi-label {
        font-size: 14px;
        color: var(--gray-500);
        font-weight: 500;
    }

    /* Header Styles */
    .projectmanage-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
        flex-wrap: wrap;
        gap: 16px;
    }

    .projectmanage-header h2 {
        color: var(--gray-800);
        font-size: 24px;
        font-weight: 600;
        margin: 0;
    }

    .projectmanage-btn {
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

    .projectmanage-btn:hover {
        background: var(--brown-800);
        transform: translateY(-2px);
        box-shadow: var(--shadow-md);
    }

    .projectmanage-btn-secondary {
        background: var(--gray-200);
        color: var(--gray-700);
    }

    .projectmanage-btn-secondary:hover {
        background: var(--gray-300);
    }

    /* Form Styles */
    .projectmanage-form {
        background: white;
        border-radius: 20px;
        padding: 32px;
        margin-bottom: 32px;
        border: 1px solid var(--gray-200);
        box-shadow: var(--shadow-lg);
        display: none;
    }

    .projectmanage-form.show {
        display: block;
        animation: projectmanage-fadeIn 0.3s ease-out;
    }

    @keyframes projectmanage-fadeIn {
        from {
            opacity: 0;
            transform: translateY(10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .projectmanage-form h3 {
        color: var(--gray-800);
        font-size: 20px;
        font-weight: 600;
        margin-bottom: 24px;
        padding-bottom: 16px;
        border-bottom: 2px solid var(--gray-200);
    }

    .projectmanage-form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 20px;
        margin-bottom: 24px;
    }

    .projectmanage-form-group {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .projectmanage-form-group-full {
        grid-column: 1 / -1;
    }

    .projectmanage-form-group label {
        color: var(--gray-700);
        font-size: 14px;
        font-weight: 500;
    }

    .projectmanage-form-group label .required {
        color: #dc3545;
        margin-left: 4px;
    }

    .projectmanage-form-group input,
    .projectmanage-form-group select,
    .projectmanage-form-group textarea {
        padding: 10px 12px;
        border: 1px solid var(--gray-300);
        border-radius: 8px;
        font-size: 14px;
        transition: all 0.2s;
        font-family: inherit;
    }

    .projectmanage-form-group textarea {
        resize: vertical;
        min-height: 80px;
    }

    .projectmanage-form-group input:focus,
    .projectmanage-form-group select:focus,
    .projectmanage-form-group textarea:focus {
        outline: none;
        border-color: var(--brown-600);
        box-shadow: 0 0 0 3px rgba(123, 88, 63, 0.1);
    }

    /* Client Details Card */
    .projectmanage-client-details {
        background: linear-gradient(135deg, #faf7f2 0%, #f5efe8 100%);
        border-radius: 12px;
        padding: 20px;
        margin-top: 16px;
        border: 1px solid var(--gray-200);
    }

    .projectmanage-client-details h4 {
        color: var(--brown-800);
        font-size: 16px;
        font-weight: 600;
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .projectmanage-client-details-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 12px;
    }

    .projectmanage-client-detail-item {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .projectmanage-client-detail-label {
        font-size: 12px;
        color: var(--gray-500);
        font-weight: 500;
    }

    .projectmanage-client-detail-value {
        font-size: 14px;
        color: var(--gray-800);
        font-weight: 500;
    }

    /* Financial Summary Card */
    .projectmanage-financial-summary {
        background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
        border-radius: 12px;
        padding: 20px;
        margin-top: 24px;
        border: 1px solid #a5d6a7;
    }

    .projectmanage-financial-summary h4 {
        color: #2e7d32;
        font-size: 16px;
        font-weight: 600;
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .projectmanage-financial-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 16px;
    }

    .projectmanage-financial-item {
        background: white;
        border-radius: 8px;
        padding: 12px;
        text-align: center;
    }

    .projectmanage-financial-label {
        font-size: 12px;
        color: var(--gray-500);
        margin-bottom: 4px;
    }

    .projectmanage-financial-value {
        font-size: 18px;
        font-weight: 700;
    }

    .projectmanage-financial-value-positive {
        color: #28a745;
    }

    .projectmanage-financial-value-negative {
        color: #dc3545;
    }

    /* Form Actions */
    .projectmanage-form-actions {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
        margin-top: 24px;
        padding-top: 20px;
        border-top: 1px solid var(--gray-200);
    }

    /* Alert Styles */
    .projectmanage-alert {
        padding: 16px;
        border-radius: 8px;
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .projectmanage-alert-success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }

    .projectmanage-alert-danger {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }

    .projectmanage-alert-close {
        background: none;
        border: none;
        font-size: 20px;
        cursor: pointer;
        color: inherit;
        opacity: 0.5;
    }

    .projectmanage-alert-close:hover {
        opacity: 1;
    }

    /* Search and Filter Bar */
    .projectmanage-search-bar {
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

    .projectmanage-search-group {
        flex: 1;
        min-width: 200px;
    }

    .projectmanage-search-group label {
        display: block;
        color: var(--gray-700);
        font-size: 13px;
        font-weight: 500;
        margin-bottom: 6px;
    }

    .projectmanage-search-group input,
    .projectmanage-search-group select {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid var(--gray-300);
        border-radius: 6px;
        font-size: 14px;
    }

    .projectmanage-search-group input:focus,
    .projectmanage-search-group select:focus {
        outline: none;
        border-color: var(--brown-600);
        box-shadow: 0 0 0 3px rgba(123, 88, 63, 0.1);
    }

    .projectmanage-search-actions {
        display: flex;
        gap: 8px;
    }

    .projectmanage-search-btn {
        padding: 10px 20px;
        background: var(--brown-700);
        color: white;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-weight: 500;
        transition: all 0.2s;
    }

    .projectmanage-search-btn:hover {
        background: var(--brown-800);
    }

    .projectmanage-clear-btn {
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

    .projectmanage-clear-btn:hover {
        background: var(--gray-300);
    }

    /* Stats Bar */
    .projectmanage-stats {
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

    .projectmanage-stats-info {
        display: flex;
        align-items: center;
        gap: 8px;
        color: var(--gray-700);
        font-size: 14px;
    }

    .projectmanage-stats-info i {
        color: var(--brown-600);
    }

    .projectmanage-stats-count {
        font-weight: 700;
        color: var(--brown-700);
        font-size: 18px;
    }

    /* Table Styles */
    .projectmanage-table-container {
        overflow-x: auto;
        background: white;
        border-radius: 12px;
        border: 1px solid var(--gray-200);
        margin-bottom: 20px;
    }

    .projectmanage-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 1100px;
    }

    .projectmanage-table th {
        background: var(--gray-50);
        color: var(--gray-700);
        font-weight: 600;
        font-size: 14px;
        padding: 16px;
        text-align: left;
        border-bottom: 2px solid var(--gray-200);
    }

    .projectmanage-table td {
        padding: 16px;
        border-bottom: 1px solid var(--gray-200);
        color: var(--gray-700);
        font-size: 14px;
        vertical-align: middle;
    }

    .projectmanage-table tr:hover {
        background: var(--gray-50);
    }

    /* Status Badges */
    .projectmanage-status {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 500;
    }

    .projectmanage-status-active {
        background: #d4edda;
        color: #155724;
    }

    .projectmanage-status-expired {
        background: #f8d7da;
        color: #721c24;
    }

    .projectmanage-status-inactive {
        background: #fff3cd;
        color: #856404;
    }

    /* Action Buttons */
    .projectmanage-actions {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    .projectmanage-action-btn {
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

    .projectmanage-action-edit {
        background: var(--brown-100);
        color: var(--brown-800);
    }

    .projectmanage-action-edit:hover {
        background: var(--brown-200);
    }

    .projectmanage-action-delete {
        background: #f8d7da;
        color: #721c24;
    }

    .projectmanage-action-delete:hover {
        background: #f5c6cb;
    }

    .projectmanage-action-view {
        background: #d1ecf1;
        color: #0c5460;
    }

    .projectmanage-action-view:hover {
        background: #bee5eb;
    }

    /* Loading Indicator */
    .projectmanage-loading {
        display: inline-block;
        width: 20px;
        height: 20px;
        border: 3px solid var(--gray-200);
        border-top-color: var(--brown-700);
        border-radius: 50%;
        animation: projectmanage-spin 1s linear infinite;
    }

    @keyframes projectmanage-spin {
        to { transform: rotate(360deg); }
    }

    /* Empty State */
    .projectmanage-empty {
        text-align: center;
        padding: 48px !important;
        color: var(--gray-500);
    }

    .projectmanage-empty i {
        font-size: 48px;
        margin-bottom: 16px;
        color: var(--gray-400);
    }

    .projectmanage-timestamp {
        font-size: 12px;
        color: var(--gray-500);
        margin-top: 4px;
    }

    /* Pagination */
    .projectmanage-pagination {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 12px;
        margin-top: 20px;
    }

    .projectmanage-pagination a,
    .projectmanage-pagination span {
        padding: 8px 16px;
        border-radius: 6px;
        text-decoration: none;
        font-size: 14px;
        font-weight: 500;
        transition: all 0.2s;
    }

    .projectmanage-pagination a {
        background: white;
        border: 1px solid var(--gray-300);
        color: var(--gray-700);
    }

    .projectmanage-pagination a:hover {
        background: var(--brown-100);
        border-color: var(--brown-600);
        color: var(--brown-800);
    }

    .projectmanage-pagination .active {
        background: var(--brown-700);
        border-color: var(--brown-700);
        color: white;
    }

    .projectmanage-pagination .disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    /* Money Input */
    .projectmanage-money-input {
        text-align: right;
    }

    /* ============================================================================
       MODAL STYLES - For Invoice View
       ============================================================================ */
    .projectmanage-modal-overlay {
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

    .projectmanage-modal-overlay.active {
        display: flex;
    }

    .projectmanage-modal {
        background: white;
        border-radius: 16px;
        width: 100%;
        max-width: 900px;
        max-height: 90vh;
        overflow: hidden;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        animation: projectmanage-modalFadeIn 0.2s ease-out;
        display: flex;
        flex-direction: column;
    }

    @keyframes projectmanage-modalFadeIn {
        from {
            opacity: 0;
            transform: scale(0.95);
        }
        to {
            opacity: 1;
            transform: scale(1);
        }
    }

    .projectmanage-modal-header {
        padding: 20px 24px;
        border-bottom: 2px solid var(--gray-200);
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: linear-gradient(135deg, #faf7f2 0%, #f5efe8 100%);
    }

    .projectmanage-modal-title {
        font-family: 'Montserrat', sans-serif;
        font-weight: 700;
        font-size: 1.3rem;
        color: var(--brown-800);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .projectmanage-modal-close {
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

    .projectmanage-modal-close:hover {
        background: rgba(139, 90, 43, 0.1);
        color: #dc3545;
    }

    .projectmanage-modal-body {
        padding: 24px;
        overflow-y: auto;
        flex: 1;
    }

    .projectmanage-modal-footer {
        padding: 16px 24px;
        border-top: 1px solid var(--gray-200);
        display: flex;
        justify-content: flex-end;
        gap: 12px;
        background: #faf7f5;
    }

    /* Invoice Content inside Modal */
    .projectmanage-modal-invoice {
        background: white;
        font-family: 'Inter', sans-serif;
    }

    .projectmanage-modal-invoice-header {
        text-align: center;
        padding: 20px;
        border-bottom: 2px solid var(--brown-700);
        margin-bottom: 20px;
    }

    .projectmanage-modal-invoice-logo {
        max-width: 80px;
        max-height: 80px;
        margin-bottom: 15px;
    }

    .projectmanage-modal-invoice-company-name {
        font-size: 22px;
        font-weight: 700;
        color: var(--brown-800);
        margin-bottom: 5px;
    }

    .projectmanage-modal-invoice-company-details {
        font-size: 11px;
        color: var(--gray-600);
        line-height: 1.5;
    }

    .projectmanage-modal-invoice-title {
        text-align: center;
        font-size: 18px;
        font-weight: 700;
        color: var(--brown-800);
        margin: 20px 0;
        padding: 10px;
        background: var(--brown-100);
        letter-spacing: 2px;
    }

    .projectmanage-modal-invoice-section {
        margin-bottom: 20px;
        border: 1px solid var(--gray-200);
        border-radius: 8px;
        overflow: hidden;
    }

    .projectmanage-modal-invoice-section-title {
        background: var(--gray-100);
        padding: 10px 15px;
        font-weight: 700;
        color: var(--brown-800);
        border-bottom: 1px solid var(--gray-200);
        font-size: 14px;
    }

    .projectmanage-modal-invoice-section-content {
        padding: 15px;
    }

    .projectmanage-modal-invoice-row {
        display: flex;
        justify-content: space-between;
        padding: 6px 0;
        border-bottom: 1px dashed var(--gray-200);
        font-size: 13px;
    }

    .projectmanage-modal-invoice-row:last-child {
        border-bottom: none;
    }

    .projectmanage-modal-invoice-label {
        font-weight: 600;
        color: var(--gray-700);
        width: 40%;
    }

    .projectmanage-modal-invoice-value {
        color: var(--gray-800);
        width: 60%;
        text-align: right;
    }

    .projectmanage-modal-invoice-financial-item {
        display: flex;
        justify-content: space-between;
        padding: 6px 0;
        font-size: 13px;
    }

    .projectmanage-modal-invoice-financial-label {
        font-weight: 500;
        color: var(--gray-700);
    }

    .projectmanage-modal-invoice-financial-value {
        font-weight: 600;
        color: var(--gray-800);
    }

    .projectmanage-modal-invoice-total-row {
        border-top: 2px solid var(--gray-300);
        margin-top: 8px;
        padding-top: 10px;
        font-weight: 700;
        font-size: 14px;
    }

    .projectmanage-modal-invoice-profit-positive {
        color: #28a745;
    }

    .projectmanage-modal-invoice-profit-negative {
        color: #dc3545;
    }

    .projectmanage-modal-invoice-staff {
        background: var(--brown-100);
        padding: 12px;
        border-radius: 8px;
        text-align: center;
        margin-top: 15px;
        font-size: 13px;
    }

    .projectmanage-modal-invoice-footer {
        margin-top: 20px;
        padding-top: 15px;
        border-top: 1px solid var(--gray-200);
        text-align: center;
        font-size: 10px;
        color: var(--gray-500);
    }

    /* Print styles for modal invoice - Enhanced for one-page output */
    @media print {
        body * {
            visibility: hidden;
        }
        .projectmanage-modal-body,
        .projectmanage-modal-body * {
            visibility: visible;
        }
        .projectmanage-modal-body {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            padding: 0;
            margin: 0;
        }
        .projectmanage-modal-header,
        .projectmanage-modal-footer {
            display: none;
        }
        @page {
            size: A4 landscape;
            margin: 0.5in;
        }
    }

    /* Responsive */
    @media (max-width: 768px) {
        .projectmanage-kpi-row {
            grid-template-columns: repeat(2, 1fr);
        }
        
        .projectmanage-search-bar {
            flex-direction: column;
        }
        
        .projectmanage-search-actions {
            width: 100%;
        }
        
        .projectmanage-search-btn,
        .projectmanage-clear-btn {
            flex: 1;
            text-align: center;
            justify-content: center;
        }
        
        .projectmanage-form-grid {
            grid-template-columns: 1fr;
        }
        
        .projectmanage-pagination {
            flex-wrap: wrap;
        }
        
        .projectmanage-client-details-grid {
            grid-template-columns: 1fr;
        }
        
        .projectmanage-financial-grid {
            grid-template-columns: 1fr;
        }
        
        .projectmanage-modal {
            max-width: 95%;
            max-height: 95vh;
        }
        
        .projectmanage-modal-body {
            padding: 16px;
        }
    }
    
    @media (max-width: 480px) {
        .projectmanage-kpi-row {
            grid-template-columns: 1fr;
        }
        
        .projectmanage-kpi-value {
            font-size: 22px;
        }
    }
</style>

<div class="projectmanage-container">
    <!-- Header -->
    <div class="projectmanage-header">
        <h2 data-proj-lang="pageTitle">Contracts Management</h2>
        <?php if (canAdd($conn, $user_role_id, $module_name)): ?>
        <button class="projectmanage-btn" onclick="projectmanage_toggleForm()" id="projectmanage_toggleBtn">
            <i class="fas fa-plus"></i>
            <span data-proj-lang="addNew">Add New Contract</span>
        </button>
        <?php endif; ?>
    </div>

    <!-- Alert Messages -->
    <?php if (!empty($projects_message)): ?>
        <div class="projectmanage-alert projectmanage-alert-<?= $projects_message_type ?>">
            <?= $projects_message ?>
            <button class="projectmanage-alert-close" onclick="this.parentElement.style.display='none'">&times;</button>
        </div>
    <?php endif; ?>

    <!-- Add/Edit Form -->
    <?php if (canAdd($conn, $user_role_id, $module_name) || ($edit_mode && canEdit($conn, $user_role_id, $module_name))): ?>
    <div class="projectmanage-form <?= $edit_mode ? 'show' : '' ?>" id="projectmanage_form">
        <h3 data-proj-lang="<?= $edit_mode ? 'editProject' : 'addProject' ?>">
            <?= $edit_mode ? 'Edit Contract' : 'Add New Contract' ?>
        </h3>
        
        <form method="POST" action="?page=projects" id="projectmanage_mainForm" onsubmit="return projectmanage_validateForm()">
            <?php if ($edit_mode): ?>
                <input type="hidden" name="id" value="<?= $edit_data['id'] ?>">
            <?php endif; ?>
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            
            <div class="projectmanage-form-grid">
                <!-- Client Selection -->
                <div class="projectmanage-form-group">
                    <label for="projectmanage_client_id" data-proj-lang="selectClient">Select Client <span class="required">*</span></label>
                    <select id="projectmanage_client_id" name="client_id" required onchange="projectmanage_loadClientDetails(this.value)">
                        <option value="" data-proj-lang="selectClient">Select Client</option>
                        <?php foreach ($clients as $client): ?>
                            <option value="<?= $client['id'] ?>" 
                                <?= ($edit_mode && $edit_data['client_id'] == $client['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($client['customer_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Effective Date -->
                <div class="projectmanage-form-group">
                    <label for="projectmanage_effective_date" data-proj-lang="effectiveDate">Effective Date <span class="required">*</span></label>
                    <input type="date" id="projectmanage_effective_date" name="effective_date" 
                           value="<?= $edit_mode ? $edit_data['effective_date'] : '' ?>" required>
                </div>
                
                <!-- End Date -->
                <div class="projectmanage-form-group">
                    <label for="projectmanage_end_date" data-proj-lang="endDate">End Date <span class="required">*</span></label>
                    <input type="date" id="projectmanage_end_date" name="end_date" 
                           value="<?= $edit_mode ? $edit_data['end_date'] : '' ?>" required>
                </div>
                
                <!-- Contract Value -->
                <div class="projectmanage-form-group">
                    <label for="projectmanage_contract_value" data-proj-lang="contractValue">Contract Value <span class="required">*</span></label>
                    <input type="number" step="0.01" id="projectmanage_contract_value" name="contract_value" 
                           value="<?= $edit_mode ? $edit_data['contract_value'] : '0.00' ?>" 
                           class="projectmanage-money-input" required oninput="projectmanage_updateFinancialSummary()">
                </div>
                
                <!-- Tax -->
                <div class="projectmanage-form-group">
                    <label for="projectmanage_tax" data-proj-lang="tax">Tax</label>
                    <input type="number" step="0.01" id="projectmanage_tax" name="tax" 
                           value="<?= $edit_mode ? $edit_data['tax'] : '0.00' ?>" 
                           class="projectmanage-money-input" oninput="projectmanage_updateFinancialSummary()">
                </div>
                
                <!-- Commission -->
                <div class="projectmanage-form-group">
                    <label for="projectmanage_commission" data-proj-lang="commission">Commission</label>
                    <input type="number" step="0.01" id="projectmanage_commission" name="commission" 
                           value="<?= $edit_mode ? $edit_data['commission'] : '0.00' ?>" 
                           class="projectmanage-money-input" oninput="projectmanage_updateFinancialSummary()">
                </div>
                
                <!-- Cost of Project -->
                <div class="projectmanage-form-group">
                    <label for="projectmanage_cost_of_project" data-proj-lang="costOfProject">Cost of Project</label>
                    <input type="number" step="0.01" id="projectmanage_cost_of_project" name="cost_of_project" 
                           value="<?= $edit_mode ? $edit_data['cost_of_project'] : '0.00' ?>" 
                           class="projectmanage-money-input" oninput="projectmanage_updateFinancialSummary()">
                </div>
                
                <!-- Staff Cost -->
                <div class="projectmanage-form-group">
                    <label for="projectmanage_staff_cost" data-proj-lang="staffCost">Staff Cost</label>
                    <input type="number" step="0.01" id="projectmanage_staff_cost" name="staff_cost" 
                           value="<?= $edit_mode ? $edit_data['staff_cost'] : '0.00' ?>" 
                           class="projectmanage-money-input" oninput="projectmanage_updateFinancialSummary()">
                </div>
                
                <!-- Overhead Cost -->
                <div class="projectmanage-form-group">
                    <label for="projectmanage_overhead_cost" data-proj-lang="overheadCost">Overhead Cost</label>
                    <input type="number" step="0.01" id="projectmanage_overhead_cost" name="overhead_cost" 
                           value="<?= $edit_mode ? $edit_data['overhead_cost'] : '0.00' ?>" 
                           class="projectmanage-money-input" oninput="projectmanage_updateFinancialSummary()">
                </div>
                
                <!-- Number of Staff Allocated -->
                <div class="projectmanage-form-group">
                    <label for="projectmanage_number_of_staff_allocated" data-proj-lang="numberOfStaffAllocated">Number of Staff Allocated</label>
                    <input type="number" id="projectmanage_number_of_staff_allocated" name="number_of_staff_allocated" 
                           value="<?= $edit_mode ? $edit_data['number_of_staff_allocated'] : '0' ?>">
                </div>
                
                <!-- Status -->
                <div class="projectmanage-form-group">
                    <label for="projectmanage_status" data-proj-lang="status">Status</label>
                    <select id="projectmanage_status" name="status">
                        <?php foreach ($status_options as $value => $label): ?>
                            <option value="<?= $value ?>" data-proj-lang="<?= strtolower($value) ?>"
                                <?= ($edit_mode && $edit_data['status'] == $value) ? 'selected' : '' ?>>
                                <?= $label ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <!-- Client Details Card -->
            <div id="projectmanage_clientDetails" class="projectmanage-client-details" style="display: none;">
                <h4><i class="fas fa-building"></i> <span data-proj-lang="clientDetails">Client Details</span></h4>
                <div class="projectmanage-client-details-grid" id="projectmanage_clientDetailsGrid">
                    <!-- Populated by JavaScript -->
                </div>
            </div>
            
            <!-- Financial Summary Card -->
            <div class="projectmanage-financial-summary">
                <h4><i class="fas fa-chart-line"></i> <span data-proj-summary="financialSummary">Financial Summary</span></h4>
                <div class="projectmanage-financial-grid">
                    <div class="projectmanage-financial-item">
                        <div class="projectmanage-financial-label" data-proj-summary="targetProfit">Target Profit (30%)</div>
                        <div class="projectmanage-financial-value" id="projectmanage_targetProfitDisplay">0.00</div>
                    </div>
                    <div class="projectmanage-financial-item">
                        <div class="projectmanage-financial-label" data-proj-summary="totalCosts">Total Costs</div>
                        <div class="projectmanage-financial-value" id="projectmanage_totalCostsDisplay">0.00</div>
                    </div>
                    <div class="projectmanage-financial-item">
                        <div class="projectmanage-financial-label" data-proj-summary="actualProfit">Actual Profit</div>
                        <div class="projectmanage-financial-value" id="projectmanage_actualProfitDisplay">0.00</div>
                    </div>
                    <div class="projectmanage-financial-item">
                        <div class="projectmanage-financial-label" data-proj-summary="profitDifference">Profit Difference</div>
                        <div class="projectmanage-financial-value" id="projectmanage_profitDifferenceDisplay">0.00</div>
                    </div>
                </div>
            </div>
            
            <!-- Hidden fields for calculated values -->
            <input type="hidden" name="target_profit" id="projectmanage_target_profit" value="0">
            <input type="hidden" name="actual_profit" id="projectmanage_actual_profit" value="0">
            <input type="hidden" name="profit_difference" id="projectmanage_profit_difference" value="0">
            
            <div class="projectmanage-form-actions">
                <button type="submit" name="<?= $edit_mode ? 'projects_update' : 'projects_add' ?>" class="projectmanage-btn" id="projectmanage_saveBtn">
                    <i class="fas fa-save"></i>
                    <span data-proj-lang="save">Save</span>
                </button>
                <a href="?page=projects" class="projectmanage-btn projectmanage-btn-secondary">
                    <i class="fas fa-times"></i>
                    <span data-proj-lang="cancel">Cancel</span>
                </a>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- KPI Cards Row (hidden when form is shown) -->
    <div id="projectmanage_kpiRow" class="projectmanage-kpi-row" style="<?= $edit_mode ? 'display: none;' : '' ?>">
        <div class="projectmanage-kpi-card projectmanage-kpi-card-green">
            <div class="projectmanage-kpi-icon projectmanage-kpi-icon-green">
                <i class="fas fa-play-circle"></i>
            </div>
            <div class="projectmanage-kpi-value" id="projectmanage_activeCount">0</div>
            <div class="projectmanage-kpi-label" data-proj-lang="activeProjects">Active Contracts</div>
        </div>
        
        <div class="projectmanage-kpi-card projectmanage-kpi-card-red">
            <div class="projectmanage-kpi-icon projectmanage-kpi-icon-red">
                <i class="fas fa-stop-circle"></i>
            </div>
            <div class="projectmanage-kpi-value" id="projectmanage_expiredCount">0</div>
            <div class="projectmanage-kpi-label" data-proj-lang="expiredProjects">Expired Contracts</div>
        </div>
        
        <div class="projectmanage-kpi-card projectmanage-kpi-card-green">
            <div class="projectmanage-kpi-icon projectmanage-kpi-icon-green">
                <i class="fas fa-chart-line"></i>
            </div>
            <div class="projectmanage-kpi-value" id="projectmanage_totalProfit">0.00</div>
            <div class="projectmanage-kpi-label" data-proj-lang="totalProfit">Total Profit</div>
        </div>
        
        <div class="projectmanage-kpi-card projectmanage-kpi-card-brown">
            <div class="projectmanage-kpi-icon projectmanage-kpi-icon-brown">
                <i class="fas fa-file-invoice-dollar"></i>
            </div>
            <div class="projectmanage-kpi-value" id="projectmanage_totalContractValue">0.00</div>
            <div class="projectmanage-kpi-label" data-proj-lang="totalContractValue">Total Contract Value</div>
        </div>
        
        <div class="projectmanage-kpi-card projectmanage-kpi-card-teal">
            <div class="projectmanage-kpi-icon projectmanage-kpi-icon-teal">
                <i class="fas fa-users"></i>
            </div>
            <div class="projectmanage-kpi-value" id="projectmanage_totalStaff">0</div>
            <div class="projectmanage-kpi-label" data-proj-lang="totalStaffAllocated">Total Staff Allocated</div>
        </div>
    </div>

    <!-- Search and Filter Bar (hidden when form is shown) -->
    <div id="projectmanage_searchBar" class="projectmanage-search-bar" style="<?= $edit_mode ? 'display: none;' : '' ?>">
        <form method="GET" action="?page=projects" id="projectmanage_searchForm" style="display: contents;">
            <input type="hidden" name="page" value="projects">
            
            <div class="projectmanage-search-group">
                <label for="search" data-proj-lang="search">Search</label>
                <input type="text" id="search" name="search" 
                       value="<?= htmlspecialchars($search) ?>" 
                       data-proj-lang="searchPlaceholder" placeholder="Search by contract # or client...">
            </div>
            
            <div class="projectmanage-search-group">
                <label for="status_filter" data-proj-lang="filter">Status</label>
                <select id="status_filter" name="status_filter">
                    <option value="" data-proj-lang="allStatus">All Status</option>
                    <option value="Active" <?= $status_filter == 'Active' ? 'selected' : '' ?> data-proj-lang="active">Active</option>
                    <option value="Expired" <?= $status_filter == 'Expired' ? 'selected' : '' ?> data-proj-lang="expired">Expired</option>
                    <option value="Inactive" <?= $status_filter == 'Inactive' ? 'selected' : '' ?> data-proj-lang="inactive">Inactive</option>
                </select>
            </div>
            
            <div class="projectmanage-search-actions">
                <button type="submit" class="projectmanage-search-btn">
                    <i class="fas fa-search"></i> <span data-proj-lang="search">Search</span>
                </button>
                <a href="?page=projects" class="projectmanage-clear-btn">
                    <i class="fas fa-times"></i> <span data-proj-lang="clear">Clear</span>
                </a>
            </div>
        </form>
    </div>

    <!-- Stats Bar (hidden when form is shown) -->
    <div id="projectmanage_statsBar" class="projectmanage-stats" style="<?= $edit_mode ? 'display: none;' : '' ?>">
        <div class="projectmanage-stats-info">
            <i class="fas fa-file-contract"></i>
            <span id="totalRecords" data-proj-lang="totalRecords">Total Contracts</span>
            <span>:</span>
            <span class="projectmanage-stats-count" id="projectmanage_totalRecordsCount"><?= $total_records ?></span>
            <span data-proj-lang="records">records</span>
        </div>
    </div>

    <!-- Data Table (hidden when form is shown) -->
    <div id="projectmanage_tableContainer" class="projectmanage-table-container" style="<?= $edit_mode ? 'display: none;' : '' ?>">
        <table class="projectmanage-table" id="projectmanage_dataTable">
            <thead>
                <tr>
                    <th data-proj-lang="contractNumber">Contract Number</th>
                    <th data-proj-lang="clientName">Client Name</th>
                    <th data-proj-lang="effectiveDate">Effective Date</th>
                    <th data-proj-lang="endDate">End Date</th>
                    <th data-proj-lang="contractValue">Contract Value</th>
                    <th data-proj-lang="actualProfit">Actual Profit</th>
                    <th data-proj-lang="status">Status</th>
                    <th data-proj-lang="createdBy">Created By</th>
                    <th data-proj-lang="created">Created</th>
                    <th data-proj-lang="actions">Actions</th>
                </tr>
            </thead>
            <tbody id="projectmanage_tableBody">
                <?php if ($projects_result && $projects_result->num_rows > 0): ?>
                    <?php while ($row = $projects_result->fetch_assoc()): ?>
                        <tr data-contract='<?= json_encode([
                            'id' => $row['id'],
                            'contract_number' => $row['contract_number'],
                            'client_name' => $row['customer_name'] ?? 'N/A',
                            'effective_date' => $row['effective_date'],
                            'end_date' => $row['end_date'],
                            'contract_value' => $row['contract_value'],
                            'tax' => $row['tax'],
                            'commission' => $row['commission'],
                            'cost_of_project' => $row['cost_of_project'],
                            'staff_cost' => $row['staff_cost'],
                            'overhead_cost' => $row['overhead_cost'],
                            'number_of_staff_allocated' => $row['number_of_staff_allocated'],
                            'target_profit' => $row['target_profit'],
                            'actual_profit' => $row['actual_profit'],
                            'profit_difference' => $row['profit_difference'],
                            'status' => $row['status'],
                            'created_by' => $row['created_by'],
                            'created_at' => $row['created_at'],
                            'client' => [
                                'customer_name' => $row['customer_name'] ?? 'N/A',
                                'contact_person' => $row['contact_person'] ?? 'N/A',
                                'tin_number' => $row['tin_number'] ?? 'N/A',
                                'address' => $row['address'] ?? 'N/A',
                                'email' => $row['email'] ?? 'N/A',
                                'type_of_business' => $row['type_of_business'] ?? 'N/A'
                            ]
                        ]) ?>'>
                            <td><strong><?= htmlspecialchars($row['contract_number']) ?></strong></td>
                            <td><?= htmlspecialchars($row['customer_name'] ?? 'N/A') ?></td>
                            <td><?= date('d M Y', strtotime($row['effective_date'])) ?></td>
                            <td><?= date('d M Y', strtotime($row['end_date'])) ?></td>
                            <td class="projectmanage-money"><?= number_format($row['contract_value'], 2) ?></td>
                            <td class="projectmanage-money <?= $row['actual_profit'] >= 0 ? 'projectmanage-financial-value-positive' : 'projectmanage-financial-value-negative' ?>">
                                <?= number_format($row['actual_profit'], 2) ?>
                            </td>
                            <td>
                                <span class="projectmanage-status projectmanage-status-<?= strtolower($row['status']) ?>">
                                    <?= $row['status'] ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($row['created_by']) ?></td>
                            <td>
                                <?= date('d M Y', strtotime($row['created_at'])) ?>
                                <div class="projectmanage-timestamp"><?= date('H:i', strtotime($row['created_at'])) ?></div>
                            </td>
                            <td>
                                <div class="projectmanage-actions">
                                    <?php if (canEdit($conn, $user_role_id, $module_name)): ?>
                                    <a href="?page=projects&projects_edit=<?= $row['id'] ?>" 
                                       class="projectmanage-action-btn projectmanage-action-edit">
                                        <i class="fas fa-edit"></i> <span data-proj-lang="edit">Edit</span>
                                    </a>
                                    <?php endif; ?>
                                    <?php if (canDelete($conn, $user_role_id, $module_name)): ?>
                                    <a href="javascript:void(0)" 
                                       onclick="projectmanage_confirmDelete(<?= $row['id'] ?>, '<?= htmlspecialchars(addslashes($row['contract_number'])) ?>')" 
                                       class="projectmanage-action-btn projectmanage-action-delete">
                                        <i class="fas fa-trash"></i> <span data-proj-lang="delete">Delete</span>
                                    </a>
                                    <?php endif; ?>
                                    <button onclick="projectmanage_openInvoiceModal(this)" 
                                            data-contract='<?= json_encode([
                                                'id' => $row['id'],
                                                'contract_number' => $row['contract_number'],
                                                'client_name' => $row['customer_name'] ?? 'N/A',
                                                'effective_date' => $row['effective_date'],
                                                'end_date' => $row['end_date'],
                                                'contract_value' => $row['contract_value'],
                                                'tax' => $row['tax'],
                                                'commission' => $row['commission'],
                                                'cost_of_project' => $row['cost_of_project'],
                                                'staff_cost' => $row['staff_cost'],
                                                'overhead_cost' => $row['overhead_cost'],
                                                'number_of_staff_allocated' => $row['number_of_staff_allocated'],
                                                'target_profit' => $row['target_profit'],
                                                'actual_profit' => $row['actual_profit'],
                                                'profit_difference' => $row['profit_difference'],
                                                'status' => $row['status'],
                                                'created_by' => $row['created_by'],
                                                'created_at' => $row['created_at'],
                                                'client' => [
                                                    'customer_name' => $row['customer_name'] ?? 'N/A',
                                                    'contact_person' => $row['contact_person'] ?? 'N/A',
                                                    'tin_number' => $row['tin_number'] ?? 'N/A',
                                                    'address' => $row['address'] ?? 'N/A',
                                                    'email' => $row['email'] ?? 'N/A',
                                                    'type_of_business' => $row['type_of_business'] ?? 'N/A'
                                                ]
                                            ]) ?>'
                                            class="projectmanage-action-btn projectmanage-action-view">
                                        <i class="fas fa-eye"></i> <span data-proj-lang="viewInvoice">View Invoice</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr class="projectmanage-empty-row">
                        <td colspan="10" class="projectmanage-empty">
                            <i class="fas fa-folder-open"></i>
                            <p data-proj-lang="noData">No contracts found. Click "Add New Contract" to create one.</p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Pagination (hidden when form is shown) -->
    <?php if ($total_pages > 1): ?>
    <div id="projectmanage_pagination" class="projectmanage-pagination" style="<?= $edit_mode ? 'display: none;' : '' ?>">
        <?php if ($current_page > 1): ?>
            <a href="?page=projects&page_num=<?= $current_page - 1 ?>&search=<?= urlencode($search) ?>&status_filter=<?= urlencode($status_filter) ?>" class="projectmanage-prev-btn">
                <i class="fas fa-chevron-left"></i> <span data-proj-lang="previous">Previous</span>
            </a>
        <?php else: ?>
            <span class="disabled"><i class="fas fa-chevron-left"></i> <span data-proj-lang="previous">Previous</span></span>
        <?php endif; ?>
        
        <span><span data-proj-lang="page">Page</span> <?= $current_page ?> <span data-proj-lang="of">of</span> <?= $total_pages ?></span>
        
        <?php if ($current_page < $total_pages): ?>
            <a href="?page=projects&page_num=<?= $current_page + 1 ?>&search=<?= urlencode($search) ?>&status_filter=<?= urlencode($status_filter) ?>" class="projectmanage-next-btn">
                <span data-proj-lang="next">Next</span> <i class="fas fa-chevron-right"></i>
            </a>
        <?php else: ?>
            <span class="disabled"><span data-proj-lang="next">Next</span> <i class="fas fa-chevron-right"></i></span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Invoice Modal -->
<div id="projectmanage_invoiceModal" class="projectmanage-modal-overlay">
    <div class="projectmanage-modal">
        <div class="projectmanage-modal-header">
            <h3 class="projectmanage-modal-title">
                <i class="fas fa-file-invoice"></i> <span data-modal-lang="invoiceTitle">CONTRACT INVOICE</span>
            </h3>
            <button type="button" class="projectmanage-modal-close" onclick="projectmanage_closeInvoiceModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="projectmanage-modal-body" id="projectmanage_invoiceModalBody">
            <!-- Invoice content will be dynamically inserted here -->
        </div>
        <div class="projectmanage-modal-footer">
            <button type="button" class="projectmanage-btn projectmanage-btn-secondary" onclick="projectmanage_closeInvoiceModal()">
                <i class="fas fa-times"></i> <span data-proj-lang="close">Close</span>
            </button>
            <button type="button" class="projectmanage-btn" onclick="projectmanage_printInvoiceFromModal()">
                <i class="fas fa-print"></i> <span data-proj-lang="print">Print</span>
            </button>
        </div>
    </div>
</div>

<script>
    // ============================================================================
    // STORE PROJECTS DATA FOR KPI CALCULATIONS
    // ============================================================================
    let projectmanage_currentProjectsData = <?php echo json_encode($projects_data); ?>;
    
    // ============================================================================
    // UPDATE KPI CARDS BASED ON FILTERED DATA (Real-time from table)
    // ============================================================================
    function projectmanage_updateKPIFromTable() {
        const tableBody = document.getElementById('projectmanage_tableBody');
        if (!tableBody) return;
        
        const rows = tableBody.querySelectorAll('tr:not(.projectmanage-empty-row)');
        let activeCount = 0;
        let expiredCount = 0;
        let totalProfit = 0;
        let totalContractValue = 0;
        let totalStaff = 0;
        
        rows.forEach(row => {
            // Get status from the status span
            const statusSpan = row.querySelector('.projectmanage-status');
            const status = statusSpan ? statusSpan.textContent.trim() : '';
            
            // Get money values from cells
            const cells = row.querySelectorAll('td');
            if (cells.length >= 6) {
                // Contract value (index 4)
                const contractValueText = cells[4]?.textContent.replace(/,/g, '') || '0';
                const contractValue = parseFloat(contractValueText) || 0;
                totalContractValue += contractValue;
                
                // Actual profit (index 5)
                const profitText = cells[5]?.textContent.replace(/,/g, '') || '0';
                const profit = parseFloat(profitText) || 0;
                totalProfit += profit;
            }
            
            // Get staff allocated from data attribute
            const contractData = row.getAttribute('data-contract');
            if (contractData) {
                try {
                    const data = JSON.parse(contractData);
                    totalStaff += data.number_of_staff_allocated || 0;
                } catch(e) {}
            }
            
            // Count by status
            if (status === 'Active') activeCount++;
            if (status === 'Expired') expiredCount++;
        });
        
        // Update KPI displays
        document.getElementById('projectmanage_activeCount').textContent = activeCount.toLocaleString();
        document.getElementById('projectmanage_expiredCount').textContent = expiredCount.toLocaleString();
        document.getElementById('projectmanage_totalProfit').textContent = totalProfit.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        document.getElementById('projectmanage_totalContractValue').textContent = totalContractValue.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        document.getElementById('projectmanage_totalStaff').textContent = totalStaff.toLocaleString();
        
        // Update total records count
        const totalRecordsSpan = document.getElementById('projectmanage_totalRecordsCount');
        if (totalRecordsSpan) {
            totalRecordsSpan.textContent = rows.length;
        }
    }
    
    // ============================================================================
    // OBSERVE TABLE CHANGES FOR KPI UPDATES
    // ============================================================================
    function projectmanage_observeTableChanges() {
        const tableBody = document.getElementById('projectmanage_tableBody');
        if (!tableBody) return;
        
        const observer = new MutationObserver(function(mutations) {
            projectmanage_updateKPIFromTable();
        });
        
        observer.observe(tableBody, { childList: true, subtree: true, characterData: true, attributes: true });
        projectmanage_updateKPIFromTable();
    }
    
    // ============================================================================
    // CLIENT DETAILS - Load and display client information
    // ============================================================================
    const projectmanage_clientsData = <?php 
        $clients_json = [];
        foreach ($clients as $client) {
            $clients_json[] = [
                'id' => $client['id'],
                'customer_name' => $client['customer_name'],
                'contact_person' => $client['contact_person'] ?? 'N/A',
                'tin_number' => $client['tin_number'] ?? 'N/A',
                'address' => $client['address'] ?? 'N/A',
                'email' => $client['email'] ?? 'N/A',
                'type_of_business' => $client['type_of_business'] ?? 'N/A'
            ];
        }
        echo json_encode($clients_json);
    ?>;
    
    function projectmanage_loadClientDetails(clientId) {
        const detailsDiv = document.getElementById('projectmanage_clientDetails');
        const detailsGrid = document.getElementById('projectmanage_clientDetailsGrid');
        
        if (!clientId || clientId === '') {
            detailsDiv.style.display = 'none';
            return;
        }
        
        const client = projectmanage_clientsData.find(c => c.id == clientId);
        if (client) {
            const lang = currentProjectsLang;
            detailsGrid.innerHTML = `
                <div class="projectmanage-client-detail-item">
                    <div class="projectmanage-client-detail-label" data-proj-lang="clientID">Client ID</div>
                    <div class="projectmanage-client-detail-value">#${client.id}</div>
                </div>
                <div class="projectmanage-client-detail-item">
                    <div class="projectmanage-client-detail-label" data-proj-lang="contactPerson">Contact Person</div>
                    <div class="projectmanage-client-detail-value">${escapeHtml(client.contact_person)}</div>
                </div>
                <div class="projectmanage-client-detail-item">
                    <div class="projectmanage-client-detail-label" data-proj-lang="tinNumber">TIN Number</div>
                    <div class="projectmanage-client-detail-value">${escapeHtml(client.tin_number)}</div>
                </div>
                <div class="projectmanage-client-detail-item">
                    <div class="projectmanage-client-detail-label" data-proj-lang="address">Address</div>
                    <div class="projectmanage-client-detail-value">${escapeHtml(client.address)}</div>
                </div>
                <div class="projectmanage-client-detail-item">
                    <div class="projectmanage-client-detail-label" data-proj-lang="email">Email</div>
                    <div class="projectmanage-client-detail-value">${escapeHtml(client.email)}</div>
                </div>
                <div class="projectmanage-client-detail-item">
                    <div class="projectmanage-client-detail-label" data-proj-lang="typeOfBusiness">Type of Business</div>
                    <div class="projectmanage-client-detail-value">${escapeHtml(client.type_of_business)}</div>
                </div>
            `;
            detailsDiv.style.display = 'block';
            
            // Update translations for client details
            const clientDetailLabels = detailsGrid.querySelectorAll('[data-proj-lang]');
            clientDetailLabels.forEach(el => {
                const key = el.getAttribute('data-proj-lang');
                if (projects_translations[lang] && projects_translations[lang][key]) {
                    el.textContent = projects_translations[lang][key];
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
    // FINANCIAL CALCULATIONS - Real-time updates
    // ============================================================================
    function projectmanage_formatMoney(value) {
        return new Intl.NumberFormat('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(value);
    }
    
    function projectmanage_updateFinancialSummary() {
        const contractValue = parseFloat(document.getElementById('projectmanage_contract_value').value) || 0;
        const tax = parseFloat(document.getElementById('projectmanage_tax').value) || 0;
        const commission = parseFloat(document.getElementById('projectmanage_commission').value) || 0;
        const costOfProject = parseFloat(document.getElementById('projectmanage_cost_of_project').value) || 0;
        const staffCost = parseFloat(document.getElementById('projectmanage_staff_cost').value) || 0;
        const overheadCost = parseFloat(document.getElementById('projectmanage_overhead_cost').value) || 0;
        
        const targetProfit = contractValue * 0.30;
        const totalCosts = tax + commission + costOfProject + staffCost + overheadCost;
        const actualProfit = contractValue - totalCosts;
        const profitDifference = actualProfit - targetProfit;
        
        document.getElementById('projectmanage_targetProfitDisplay').textContent = projectmanage_formatMoney(targetProfit);
        document.getElementById('projectmanage_totalCostsDisplay').textContent = projectmanage_formatMoney(totalCosts);
        
        const actualProfitDisplay = document.getElementById('projectmanage_actualProfitDisplay');
        actualProfitDisplay.textContent = projectmanage_formatMoney(actualProfit);
        actualProfitDisplay.className = 'projectmanage-financial-value ' + 
            (actualProfit >= 0 ? 'projectmanage-financial-value-positive' : 'projectmanage-financial-value-negative');
        
        const profitDiffDisplay = document.getElementById('projectmanage_profitDifferenceDisplay');
        profitDiffDisplay.textContent = projectmanage_formatMoney(profitDifference);
        profitDiffDisplay.className = 'projectmanage-financial-value ' + 
            (profitDifference >= 0 ? 'projectmanage-financial-value-positive' : 'projectmanage-financial-value-negative');
        
        document.getElementById('projectmanage_target_profit').value = targetProfit.toFixed(2);
        document.getElementById('projectmanage_actual_profit').value = actualProfit.toFixed(2);
        document.getElementById('projectmanage_profit_difference').value = profitDifference.toFixed(2);
    }
    
    // ============================================================================
    // FORM TOGGLE
    // ============================================================================
    function projectmanage_toggleForm() {
        const form = document.getElementById('projectmanage_form');
        const kpiRow = document.getElementById('projectmanage_kpiRow');
        const searchBar = document.getElementById('projectmanage_searchBar');
        const statsBar = document.getElementById('projectmanage_statsBar');
        const tableContainer = document.getElementById('projectmanage_tableContainer');
        const pagination = document.getElementById('projectmanage_pagination');
        const toggleBtn = document.getElementById('projectmanage_toggleBtn');
        const lang = currentProjectsLang;
        
        if (form.classList.contains('show')) {
            form.classList.remove('show');
            if (kpiRow) kpiRow.style.display = '';
            if (searchBar) searchBar.style.display = '';
            if (statsBar) statsBar.style.display = '';
            if (tableContainer) tableContainer.style.display = '';
            if (pagination) pagination.style.display = '';
            toggleBtn.innerHTML = '<i class="fas fa-plus"></i> <span data-proj-lang="addNew">' + 
                (lang === 'en' ? 'Add New Contract' : 'Ongeza Mkataba Mpya') + '</span>';
        } else {
            form.classList.add('show');
            if (kpiRow) kpiRow.style.display = 'none';
            if (searchBar) searchBar.style.display = 'none';
            if (statsBar) statsBar.style.display = 'none';
            if (tableContainer) tableContainer.style.display = 'none';
            if (pagination) pagination.style.display = 'none';
            toggleBtn.innerHTML = '<i class="fas fa-times"></i> <span data-proj-lang="cancel">' + 
                (lang === 'en' ? 'Cancel' : 'Ghairi') + '</span>';
            
            const hiddenId = document.querySelector('input[name="id"]');
            if (hiddenId) hiddenId.remove();
            
            document.getElementById('projectmanage_client_id').value = '';
            document.getElementById('projectmanage_effective_date').value = '';
            document.getElementById('projectmanage_end_date').value = '';
            document.getElementById('projectmanage_contract_value').value = '0.00';
            document.getElementById('projectmanage_tax').value = '0.00';
            document.getElementById('projectmanage_commission').value = '0.00';
            document.getElementById('projectmanage_cost_of_project').value = '0.00';
            document.getElementById('projectmanage_staff_cost').value = '0.00';
            document.getElementById('projectmanage_overhead_cost').value = '0.00';
            document.getElementById('projectmanage_number_of_staff_allocated').value = '0';
            document.getElementById('projectmanage_status').value = 'Active';
            
            document.getElementById('projectmanage_clientDetails').style.display = 'none';
            projectmanage_updateFinancialSummary();
            
            const submitBtn = document.querySelector('button[name="projects_update"], button[name="projects_add"]');
            if (submitBtn) submitBtn.name = 'projects_add';
            
            const formHeader = document.querySelector('#projectmanage_form h3');
            if (formHeader) formHeader.textContent = projects_translations[lang].addProject;
        }
        
        updateProjectsLanguage(lang);
    }
    
    // ============================================================================
    // FORM VALIDATION
    // ============================================================================
    function projectmanage_validateForm() {
        const clientId = document.getElementById('projectmanage_client_id').value;
        const effectiveDate = document.getElementById('projectmanage_effective_date').value;
        const endDate = document.getElementById('projectmanage_end_date').value;
        const contractValue = parseFloat(document.getElementById('projectmanage_contract_value').value);
        const tax = parseFloat(document.getElementById('projectmanage_tax').value) || 0;
        const commission = parseFloat(document.getElementById('projectmanage_commission').value) || 0;
        const costOfProject = parseFloat(document.getElementById('projectmanage_cost_of_project').value) || 0;
        const staffCost = parseFloat(document.getElementById('projectmanage_staff_cost').value) || 0;
        const overheadCost = parseFloat(document.getElementById('projectmanage_overhead_cost').value) || 0;
        const staffAllocated = parseInt(document.getElementById('projectmanage_number_of_staff_allocated').value) || 0;
        
        const lang = currentProjectsLang;
        
        if (!clientId || clientId === '') {
            alert(projects_translations[lang].selectClientRequired);
            document.getElementById('projectmanage_client_id').focus();
            return false;
        }
        
        if (effectiveDate === '') {
            alert(projects_translations[lang].effectiveDateRequired);
            document.getElementById('projectmanage_effective_date').focus();
            return false;
        }
        
        if (endDate === '') {
            alert(projects_translations[lang].endDateRequired);
            document.getElementById('projectmanage_end_date').focus();
            return false;
        }
        
        if (endDate < effectiveDate) {
            alert(projects_translations[lang].endDateInvalid);
            document.getElementById('projectmanage_end_date').focus();
            return false;
        }
        
        if (contractValue <= 0) {
            alert(projects_translations[lang].contractValueRequired);
            document.getElementById('projectmanage_contract_value').focus();
            return false;
        }
        
        if (tax < 0 || commission < 0 || costOfProject < 0 || staffCost < 0 || overheadCost < 0 || staffAllocated < 0) {
            alert(projects_translations[lang].negativeValueError);
            return false;
        }
        
        return true;
    }
    
    // ============================================================================
    // DELETE CONFIRMATION
    // ============================================================================
    function projectmanage_confirmDelete(id, name) {
        const lang = currentProjectsLang;
        const confirmMsg = projects_translations[lang].confirmDelete + ' "' + name + '"?\n' + 
                          projects_translations[lang].confirmDeleteMsg;
        
        if (confirm(confirmMsg)) {
            const row = event.target.closest('tr');
            row.style.opacity = '0.5';
            const loadingDiv = document.createElement('div');
            loadingDiv.className = 'projectmanage-loading';
            loadingDiv.style.position = 'absolute';
            loadingDiv.style.top = '50%';
            loadingDiv.style.left = '50%';
            loadingDiv.style.transform = 'translate(-50%, -50%)';
            row.style.position = 'relative';
            row.appendChild(loadingDiv);
            
            setTimeout(() => {
                window.location.href = `?page=projects&projects_delete=${id}`;
            }, 300);
        }
    }
    
    // ============================================================================
    // INVOICE MODAL FUNCTIONS
    // ============================================================================
    const projectmanage_companySettings = <?php echo json_encode([
        'company_name' => $company_settings['company_name'] ?? 'PAPLONTECH',
        'company_address' => $company_settings['company_address'] ?? '',
        'company_email' => $company_settings['company_email'] ?? '',
        'company_phone' => $company_settings['company_phone'] ?? '',
        'company_tin' => $company_settings['company_tin'] ?? '',
        'currency_symbol' => $company_settings['currency_symbol'] ?? 'TZS',
        'logo_url' => $company_settings['logo_url'] ?? null
    ]); ?>;
    
    // FIXED: Format money function with NaN protection
    function projectmanage_formatMoneyForInvoice(value) {
        // Convert to number, fallback to 0 if invalid
        let numValue = parseFloat(value);
        if (isNaN(numValue)) {
            numValue = 0;
        }
        return projectmanage_companySettings.currency_symbol + ' ' + 
            new Intl.NumberFormat('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }).format(numValue);
    }
    
    function projectmanage_openInvoiceModal(button) {
        const contractDataRaw = button.getAttribute('data-contract');
        if (!contractDataRaw) return;
        
        try {
            const contract = JSON.parse(contractDataRaw);
            const lang = currentProjectsLang;
            const t = projects_translations[lang];
            const now = new Date();
            const datePrinted = now.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) + 
                               ' ' + now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
            
            // FIXED: Parse all financial values as numbers with fallback to 0 to prevent NaN
            const contractValue = parseFloat(contract.contract_value) || 0;
            const tax = parseFloat(contract.tax) || 0;
            const commission = parseFloat(contract.commission) || 0;
            const costOfProject = parseFloat(contract.cost_of_project) || 0;
            const staffCost = parseFloat(contract.staff_cost) || 0;
            const overheadCost = parseFloat(contract.overhead_cost) || 0;
            const targetProfit = parseFloat(contract.target_profit) || 0;
            const actualProfit = parseFloat(contract.actual_profit) || 0;
            const profitDifference = parseFloat(contract.profit_difference) || 0;
            const numberOfStaffAllocated = parseInt(contract.number_of_staff_allocated) || 0;
            
            // Calculate total costs using parsed numbers
            const totalCosts = tax + commission + costOfProject + staffCost + overheadCost;
            
            const invoiceHtml = `
                <div class="projectmanage-modal-invoice">
                    <div class="projectmanage-modal-invoice-header">
                        ${projectmanage_companySettings.logo_url ? `<img src="${projectmanage_companySettings.logo_url}" alt="Logo" class="projectmanage-modal-invoice-logo">` : ''}
                        <div class="projectmanage-modal-invoice-company-name">${escapeHtml(projectmanage_companySettings.company_name)}</div>
                        <div class="projectmanage-modal-invoice-company-details">
                            ${escapeHtml(projectmanage_companySettings.company_address)}<br>
                            ${escapeHtml(projectmanage_companySettings.company_email)} | ${escapeHtml(projectmanage_companySettings.company_phone)} | TIN: ${escapeHtml(projectmanage_companySettings.company_tin)}
                        </div>
                    </div>
                    
                    <div class="projectmanage-modal-invoice-title">${t.invoiceTitle || 'CONTRACT INVOICE'}</div>
                    
                    <div class="projectmanage-modal-invoice-section">
                        <div class="projectmanage-modal-invoice-section-title">${t.contractInformation || 'CONTRACT INFORMATION'}</div>
                        <div class="projectmanage-modal-invoice-section-content">
                            <div class="projectmanage-modal-invoice-row">
                                <span class="projectmanage-modal-invoice-label">Contract Number:</span>
                                <span class="projectmanage-modal-invoice-value">${escapeHtml(contract.contract_number)}</span>
                            </div>
                            <div class="projectmanage-modal-invoice-row">
                                <span class="projectmanage-modal-invoice-label">Client:</span>
                                <span class="projectmanage-modal-invoice-value">${escapeHtml(contract.client_name)}</span>
                            </div>
                            <div class="projectmanage-modal-invoice-row">
                                <span class="projectmanage-modal-invoice-label">Effective Date:</span>
                                <span class="projectmanage-modal-invoice-value">${contract.effective_date}</span>
                            </div>
                            <div class="projectmanage-modal-invoice-row">
                                <span class="projectmanage-modal-invoice-label">End Date:</span>
                                <span class="projectmanage-modal-invoice-value">${contract.end_date}</span>
                            </div>
                            <div class="projectmanage-modal-invoice-row">
                                <span class="projectmanage-modal-invoice-label">Status:</span>
                                <span class="projectmanage-modal-invoice-value">${contract.status}</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="projectmanage-modal-invoice-section">
                        <div class="projectmanage-modal-invoice-section-title">${t.clientInformation || 'CLIENT DETAILS'}</div>
                        <div class="projectmanage-modal-invoice-section-content">
                            <div class="projectmanage-modal-invoice-row">
                                <span class="projectmanage-modal-invoice-label">Client Name:</span>
                                <span class="projectmanage-modal-invoice-value">${escapeHtml(contract.client.customer_name)}</span>
                            </div>
                            <div class="projectmanage-modal-invoice-row">
                                <span class="projectmanage-modal-invoice-label">Contact Person:</span>
                                <span class="projectmanage-modal-invoice-value">${escapeHtml(contract.client.contact_person)}</span>
                            </div>
                            <div class="projectmanage-modal-invoice-row">
                                <span class="projectmanage-modal-invoice-label">TIN Number:</span>
                                <span class="projectmanage-modal-invoice-value">${escapeHtml(contract.client.tin_number)}</span>
                            </div>
                            <div class="projectmanage-modal-invoice-row">
                                <span class="projectmanage-modal-invoice-label">Address:</span>
                                <span class="projectmanage-modal-invoice-value">${escapeHtml(contract.client.address)}</span>
                            </div>
                            <div class="projectmanage-modal-invoice-row">
                                <span class="projectmanage-modal-invoice-label">Email:</span>
                                <span class="projectmanage-modal-invoice-value">${escapeHtml(contract.client.email)}</span>
                            </div>
                            <div class="projectmanage-modal-invoice-row">
                                <span class="projectmanage-modal-invoice-label">Type of Business:</span>
                                <span class="projectmanage-modal-invoice-value">${escapeHtml(contract.client.type_of_business)}</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="projectmanage-modal-invoice-section">
                        <div class="projectmanage-modal-invoice-section-title">${t.financialBreakdown || 'FINANCIAL BREAKDOWN'}</div>
                        <div class="projectmanage-modal-invoice-section-content">
                            <div class="projectmanage-modal-invoice-financial-item">
                                <span class="projectmanage-modal-invoice-financial-label">Contract Value:</span>
                                <span class="projectmanage-modal-invoice-financial-value">${projectmanage_formatMoneyForInvoice(contractValue)}</span>
                            </div>
                            <div class="projectmanage-modal-invoice-financial-item">
                                <span class="projectmanage-modal-invoice-financial-label">${t.less || 'Less:'} Tax:</span>
                                <span class="projectmanage-modal-invoice-financial-value">- ${projectmanage_formatMoneyForInvoice(tax)}</span>
                            </div>
                            <div class="projectmanage-modal-invoice-financial-item">
                                <span class="projectmanage-modal-invoice-financial-label">${t.less || 'Less:'} Commission:</span>
                                <span class="projectmanage-modal-invoice-financial-value">- ${projectmanage_formatMoneyForInvoice(commission)}</span>
                            </div>
                            <div class="projectmanage-modal-invoice-financial-item">
                                <span class="projectmanage-modal-invoice-financial-label">${t.less || 'Less:'} Cost of Project:</span>
                                <span class="projectmanage-modal-invoice-financial-value">- ${projectmanage_formatMoneyForInvoice(costOfProject)}</span>
                            </div>
                            <div class="projectmanage-modal-invoice-financial-item">
                                <span class="projectmanage-modal-invoice-financial-label">${t.less || 'Less:'} Staff Cost:</span>
                                <span class="projectmanage-modal-invoice-financial-value">- ${projectmanage_formatMoneyForInvoice(staffCost)}</span>
                            </div>
                            <div class="projectmanage-modal-invoice-financial-item">
                                <span class="projectmanage-modal-invoice-financial-label">${t.less || 'Less:'} Overhead Cost:</span>
                                <span class="projectmanage-modal-invoice-financial-value">- ${projectmanage_formatMoneyForInvoice(overheadCost)}</span>
                            </div>
                            
                            <div class="projectmanage-modal-invoice-financial-item projectmanage-modal-invoice-total-row">
                                <span class="projectmanage-modal-invoice-financial-label">${t.totalCostsLabel || 'Total Costs'}:</span>
                                <span class="projectmanage-modal-invoice-financial-value">${projectmanage_formatMoneyForInvoice(totalCosts)}</span>
                            </div>
                            
                            <div class="projectmanage-modal-invoice-financial-item projectmanage-modal-invoice-total-row">
                                <span class="projectmanage-modal-invoice-financial-label">${t.actualProfitLabel || 'Actual Profit'}:</span>
                                <span class="projectmanage-modal-invoice-financial-value ${actualProfit >= 0 ? 'projectmanage-modal-invoice-profit-positive' : 'projectmanage-modal-invoice-profit-negative'}">
                                    ${projectmanage_formatMoneyForInvoice(actualProfit)} ${actualProfit >= 0 ? '▲' : '▼'}
                                </span>
                            </div>
                            
                            <div class="projectmanage-modal-invoice-financial-item">
                                <span class="projectmanage-modal-invoice-financial-label">${t.targetProfitLabel || 'Target Profit (30%)'}:</span>
                                <span class="projectmanage-modal-invoice-financial-value">${projectmanage_formatMoneyForInvoice(targetProfit)}</span>
                            </div>
                            
                            <div class="projectmanage-modal-invoice-financial-item">
                                <span class="projectmanage-modal-invoice-financial-label">${t.profitDifferenceLabel || 'Profit Difference'}:</span>
                                <span class="projectmanage-modal-invoice-financial-value ${profitDifference >= 0 ? 'projectmanage-modal-invoice-profit-positive' : 'projectmanage-modal-invoice-profit-negative'}">
                                    ${projectmanage_formatMoneyForInvoice(profitDifference)} ${profitDifference >= 0 ? '▲' : '▼'}
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="projectmanage-modal-invoice-staff">
                        <strong>${t.staffAllocatedLabel || 'Staff Allocated'}:</strong> ${numberOfStaffAllocated} ${t.staffMembers || 'staff members'}
                    </div>
                    
                    <div class="projectmanage-modal-invoice-footer">
                        ${t.generatedBy || 'Generated by'}: ${escapeHtml(contract.created_by)}<br>
                        ${t.datePrinted || 'Date Printed'}: ${datePrinted}
                    </div>
                </div>
            `;
            
            document.getElementById('projectmanage_invoiceModalBody').innerHTML = invoiceHtml;
            document.getElementById('projectmanage_invoiceModal').classList.add('active');
            
            // Update modal translations
            const modalLabels = document.querySelectorAll('[data-modal-lang]');
            modalLabels.forEach(el => {
                const key = el.getAttribute('data-modal-lang');
                if (projects_translations[lang] && projects_translations[lang][key]) {
                    el.textContent = projects_translations[lang][key];
                }
            });
            
        } catch (e) {
            console.error('Error loading invoice:', e);
            alert('Error loading invoice. Please try again.');
        }
    }
    
    function projectmanage_closeInvoiceModal() {
        document.getElementById('projectmanage_invoiceModal').classList.remove('active');
        document.getElementById('projectmanage_invoiceModalBody').innerHTML = '';
    }
    
    // ============================================================================
    // FIXED PRINT FUNCTION - Matches modal exactly, fits on one page
    // ============================================================================
    function projectmanage_printInvoiceFromModal() {
        const modalBody = document.getElementById('projectmanage_invoiceModalBody');
        if (!modalBody) return;
        
        // Get the invoice HTML content (already has all data and translations)
        const invoiceContent = modalBody.innerHTML;
        if (!invoiceContent) return;
        
        // Get company settings and language for the print document
        const lang = currentProjectsLang;
        const t = projects_translations[lang];
        
        // Create a new window for printing
        const printWindow = window.open('', '_blank');
        if (!printWindow) {
            // Fallback: use browser print on the modal content
            alert('Please allow pop-ups to print the invoice.');
            return;
        }
        
        // Build complete HTML document with all necessary styles for print
        printWindow.document.write(`
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>${t.invoiceTitle || 'CONTRACT INVOICE'}</title>
                <style>
                    /* RESET & BASE STYLES */
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
                    
                    /* INVOICE CONTAINER - Optimized for one-page print */
                    .print-invoice-container {
                        max-width: 1000px;
                        margin: 0 auto;
                        background: white;
                    }
                    
                    /* Exact same classes as modal invoice */
                    .projectmanage-modal-invoice {
                        background: white;
                        font-family: 'Inter', sans-serif;
                    }
                    
                    .projectmanage-modal-invoice-header {
                        text-align: center;
                        padding: 15px 20px;
                        border-bottom: 2px solid #3e2b1f;
                        margin-bottom: 15px;
                    }
                    
                    .projectmanage-modal-invoice-logo {
                        max-width: 70px;
                        max-height: 70px;
                        margin-bottom: 10px;
                    }
                    
                    .projectmanage-modal-invoice-company-name {
                        font-size: 20px;
                        font-weight: 700;
                        color: #3e2b1f;
                        margin-bottom: 4px;
                    }
                    
                    .projectmanage-modal-invoice-company-details {
                        font-size: 10px;
                        color: #64748b;
                        line-height: 1.4;
                    }
                    
                    .projectmanage-modal-invoice-title {
                        text-align: center;
                        font-size: 16px;
                        font-weight: 700;
                        color: #3e2b1f;
                        margin: 15px 0;
                        padding: 8px;
                        background: #f0e9e2;
                        letter-spacing: 1px;
                    }
                    
                    .projectmanage-modal-invoice-section {
                        margin-bottom: 15px;
                        border: 1px solid #e2e8f0;
                        border-radius: 8px;
                        overflow: hidden;
                    }
                    
                    .projectmanage-modal-invoice-section-title {
                        background: #f1f5f9;
                        padding: 8px 12px;
                        font-weight: 700;
                        color: #3e2b1f;
                        border-bottom: 1px solid #e2e8f0;
                        font-size: 12px;
                    }
                    
                    .projectmanage-modal-invoice-section-content {
                        padding: 10px 12px;
                    }
                    
                    .projectmanage-modal-invoice-row {
                        display: flex;
                        justify-content: space-between;
                        padding: 4px 0;
                        border-bottom: 1px dashed #e2e8f0;
                        font-size: 11px;
                    }
                    
                    .projectmanage-modal-invoice-row:last-child {
                        border-bottom: none;
                    }
                    
                    .projectmanage-modal-invoice-label {
                        font-weight: 600;
                        color: #475569;
                        width: 40%;
                    }
                    
                    .projectmanage-modal-invoice-value {
                        color: #1e293b;
                        width: 60%;
                        text-align: right;
                    }
                    
                    .projectmanage-modal-invoice-financial-item {
                        display: flex;
                        justify-content: space-between;
                        padding: 4px 0;
                        font-size: 11px;
                    }
                    
                    .projectmanage-modal-invoice-financial-label {
                        font-weight: 500;
                        color: #475569;
                    }
                    
                    .projectmanage-modal-invoice-financial-value {
                        font-weight: 600;
                        color: #1e293b;
                    }
                    
                    .projectmanage-modal-invoice-total-row {
                        border-top: 2px solid #cbd5e1;
                        margin-top: 6px;
                        padding-top: 8px;
                        font-weight: 700;
                        font-size: 12px;
                    }
                    
                    .projectmanage-modal-invoice-profit-positive {
                        color: #28a745;
                    }
                    
                    .projectmanage-modal-invoice-profit-negative {
                        color: #dc3545;
                    }
                    
                    .projectmanage-modal-invoice-staff {
                        background: #f0e9e2;
                        padding: 10px;
                        border-radius: 8px;
                        text-align: center;
                        margin-top: 12px;
                        font-size: 11px;
                    }
                    
                    .projectmanage-modal-invoice-footer {
                        margin-top: 15px;
                        padding-top: 10px;
                        border-top: 1px solid #e2e8f0;
                        text-align: center;
                        font-size: 9px;
                        color: #64748b;
                    }
                    
                    /* PRINT-SPECIFIC MEDIA QUERY - Ensures one-page fit */
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
                        /* Slight reduction in spacing for print only */
                        .projectmanage-modal-invoice-header {
                            padding: 10px 15px;
                            margin-bottom: 10px;
                        }
                        .projectmanage-modal-invoice-title {
                            margin: 10px 0;
                            padding: 6px;
                            font-size: 14px;
                        }
                        .projectmanage-modal-invoice-section {
                            margin-bottom: 10px;
                        }
                        .projectmanage-modal-invoice-section-content {
                            padding: 8px 10px;
                        }
                        .projectmanage-modal-invoice-row,
                        .projectmanage-modal-invoice-financial-item {
                            padding: 3px 0;
                            font-size: 10px;
                        }
                        .projectmanage-modal-invoice-total-row {
                            margin-top: 4px;
                            padding-top: 6px;
                        }
                        .projectmanage-modal-invoice-staff {
                            padding: 8px;
                            margin-top: 10px;
                            font-size: 10px;
                        }
                        .projectmanage-modal-invoice-footer {
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
                    // Auto-trigger print and close after printing
                    window.onload = function() {
                        window.print();
                        window.onafterprint = function() {
                            window.close();
                        };
                        // Fallback close after 3 seconds if afterprint not supported
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
    document.getElementById('projectmanage_invoiceModal').addEventListener('click', function(e) {
        if (e.target === this) {
            projectmanage_closeInvoiceModal();
        }
    });
    
    // ============================================================================
    // AUTO-HIDE ALERTS
    // ============================================================================
    setTimeout(() => {
        document.querySelectorAll('.projectmanage-alert').forEach(alert => {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(() => alert.style.display = 'none', 500);
        });
    }, 5000);
    
    // ============================================================================
    // INITIALIZE ON PAGE LOAD
    // ============================================================================
    document.addEventListener('DOMContentLoaded', function() {
        <?php if ($edit_mode): ?>
            const editClientId = <?= json_encode($edit_data['client_id'] ?? '') ?>;
            if (editClientId) projectmanage_loadClientDetails(editClientId);
            projectmanage_updateFinancialSummary();
        <?php endif; ?>
        
        projectmanage_observeTableChanges();
        
        const financialInputs = [
            'projectmanage_contract_value', 'projectmanage_tax', 'projectmanage_commission',
            'projectmanage_cost_of_project', 'projectmanage_staff_cost', 'projectmanage_overhead_cost'
        ];
        
        financialInputs.forEach(id => {
            const input = document.getElementById(id);
            if (input) {
                input.addEventListener('input', projectmanage_updateFinancialSummary);
                input.addEventListener('change', projectmanage_updateFinancialSummary);
            }
        });
        
        const searchForm = document.getElementById('projectmanage_searchForm');
        if (searchForm) {
            const submitBtn = searchForm.querySelector('button[type="submit"]');
            const clearBtn = searchForm.querySelector('.projectmanage-clear-btn');
            
            if (submitBtn) {
                submitBtn.addEventListener('click', function(e) {
                    setTimeout(() => {
                        setTimeout(() => projectmanage_updateKPIFromTable(), 100);
                    }, 50);
                });
            }
            
            if (clearBtn) {
                clearBtn.addEventListener('click', function(e) {
                    setTimeout(() => {
                        setTimeout(() => projectmanage_updateKPIFromTable(), 100);
                    }, 50);
                });
            }
        }
    });
</script>