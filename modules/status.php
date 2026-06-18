<?php
/**
 * SHEHITA Enterprise Management System
 * Status Module - Project Status Tracking for Active Operations
 * 
 * REFINED: Removed all sidebar-related code (no localStorage, no sidebar event listeners)
 * REFINED: Added foreign key table validation with user-friendly error messages
 * REFINED: Ensured no conflict with homepage.php (top navbar layout)
 * 
 * This module handles:
 * - Display only Active operations from operations table
 * - Milestone tracking with dropdown selection
 * - Status action tracking with dropdown selection
 * - Notes management with resizable textarea
 * - Edit/Save functionality for milestones, status action, and notes
 * - View invoice modal with complete project information
 * - KPI cards that update based on filtered data
 * - Search and filter functionality
 * - Pagination (10 records per page)
 * - Full English/Swahili translation support
 * - Permission-based access control
 * 
 * FIXED: Save/Cancel buttons now always visible in Actions column
 * FIXED: Added scroll hint for horizontal table scrolling
 * FIXED: Improved responsive behavior on all screen sizes
 * 
 * PERMISSION ENHANCED: Buttons respect user permissions (can_edit)
 */

// Prevent direct access
if (!defined('ACCESS_ALLOWED') && basename($_SERVER['PHP_SELF']) == basename(__FILE__)) {
    // This file is included, so we're safe
}

// Get database connection from parent
global $conn;

// PERMISSION: Get user role and check module permissions
$user_role_id = $_SESSION['role_id'] ?? 0;
$module_name = 'status';

// PERMISSION: Check if user has view permission
if (!canView($conn, $user_role_id, $module_name)) {
    echo '<div class="status-alert status-alert-danger" style="text-align: center; padding: 40px;">
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

// Check for operations table
$check_operations = $conn->query("SHOW TABLES LIKE 'operations'");
if ($check_operations->num_rows == 0) {
    $missing_tables[] = ['table' => 'operations', 'module' => 'operations', 'display' => 'Operations'];
}

// Check for projects table (referenced by operations)
$check_projects = $conn->query("SHOW TABLES LIKE 'projects'");
if ($check_projects->num_rows == 0) {
    $missing_tables[] = ['table' => 'projects', 'module' => 'projects', 'display' => 'Projects'];
}

// Check for customers table (referenced by projects)
$check_customers = $conn->query("SHOW TABLES LIKE 'customers'");
if ($check_customers->num_rows == 0) {
    $missing_tables[] = ['table' => 'customers', 'module' => 'customer-management', 'display' => 'Customer Management'];
}

// Check for projectgroup table (referenced by operations)
$check_projectgroup = $conn->query("SHOW TABLES LIKE 'projectgroup'");
if ($check_projectgroup->num_rows == 0) {
    $missing_tables[] = ['table' => 'projectgroup', 'module' => 'projectgroup', 'display' => 'Project Groups'];
}

// Check for categories table (referenced by operations)
$check_categories = $conn->query("SHOW TABLES LIKE 'categories'");
if ($check_categories->num_rows == 0) {
    $missing_tables[] = ['table' => 'categories', 'module' => 'categories', 'display' => 'Categories'];
}

// Display error message if any required tables are missing
if (!empty($missing_tables)) {
    echo '<div class="status-container" style="max-width: 800px; margin: 40px auto;">';
    echo '<div class="status-alert status-alert-danger" style="background: #fff3cd; color: #856404; border-color: #ffeeba; text-align: left;">';
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
 * DATABASE SCHEMA UPDATE - ADD NEW COLUMNS IF NOT EXISTS
 * ============================================================================
 */

// Add milestones column if not exists
$check_milestones = $conn->query("SHOW COLUMNS FROM operations LIKE 'milestones'");
if (!$check_milestones || $check_milestones->num_rows == 0) {
    $conn->query("ALTER TABLE operations ADD COLUMN milestones TEXT DEFAULT NULL");
}

// Add status_action column if not exists
$check_status_action = $conn->query("SHOW COLUMNS FROM operations LIKE 'status_action'");
if (!$check_status_action || $check_status_action->num_rows == 0) {
    $conn->query("ALTER TABLE operations ADD COLUMN status_action TEXT DEFAULT NULL");
}

// Add notes column if not exists
$check_notes = $conn->query("SHOW COLUMNS FROM operations LIKE 'notes'");
if (!$check_notes || $check_notes->num_rows == 0) {
    $conn->query("ALTER TABLE operations ADD COLUMN notes TEXT DEFAULT NULL");
}

// Add status_last_updated column if not exists
$check_last_updated = $conn->query("SHOW COLUMNS FROM operations LIKE 'status_last_updated'");
if (!$check_last_updated || $check_last_updated->num_rows == 0) {
    $conn->query("ALTER TABLE operations ADD COLUMN status_last_updated TIMESTAMP NULL DEFAULT NULL");
}

/**
 * ============================================================================
 * CSRF PROTECTION
 * ============================================================================
 */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Initialize variables for messages
$status_message = '';
$status_message_type = '';

/**
 * ============================================================================
 * MILESTONES DROPDOWN OPTIONS
 * ============================================================================
 */
$milestones_options = [
    'Pending' => 'Pending',
    'Data request document shared with the client' => 'Data request document shared with the client',
    'Data shared by the client' => 'Data shared by the client',
    'Numbers running is complete' => 'Numbers running is complete',
    'Results shared with the client' => 'Results shared with the client',
    'PD model is complete' => 'PD model is complete',
    'Final report has been shared with the client' => 'Final report has been shared with the client',
    'Still waiting for payment from the client' => 'Still waiting for payment from the client',
    'Data have been received' => 'Data have been received',
    'Pricing report done' => 'Pricing report done',
    'Appraisal Draft Report have been shared' => 'Appraisal Draft Report have been shared'
];

/**
 * ============================================================================
 * STATUS ACTION DROPDOWN OPTIONS
 * ============================================================================
 */
$status_action_options = [
    'Pending' => 'Pending',
    'Reminder was issued still no response from the client' => 'Reminder was issued still no response from the client',
    'Awaiting data' => 'Awaiting data',
    'FCR Internal review is ongoing' => 'FCR Internal review is ongoing',
    'Data checks and reconciliation is ongoing' => 'Data checks and reconciliation is ongoing',
    'Internal review of results is ongoing' => 'Internal review of results is ongoing',
    'Awaiting for feedback from the client' => 'Awaiting for feedback from the client',
    'Preparation of TL Report is starting' => 'Preparation of TL Report is starting',
    'ECL modelling is ongoing' => 'ECL modelling is ongoing',
    'No further action' => 'No further action',
    'Email Sent to Client' => 'Email Sent to Client'
];

/**
 * ============================================================================
 * HANDLE EDIT/SAVE SUBMISSION
 * ============================================================================
 */

// PERMISSION: Check edit permission before processing update operation
if (isset($_POST['status_update']) && !canEdit($conn, $user_role_id, $module_name)) {
    $status_message = "You do not have permission to edit status records.";
    $status_message_type = "danger";
} elseif (isset($_POST['status_update'])) {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $status_message = "Invalid form submission. Please try again.";
        $status_message_type = "danger";
    } else {
        $id = (int)$_POST['id'];
        $milestones = sanitize($conn, $_POST['milestones']);
        $status_action = sanitize($conn, $_POST['status_action']);
        $notes = sanitize($conn, $_POST['notes']);
        $current_timestamp = date('Y-m-d H:i:s');
        
        // Validate inputs
        $errors = [];
        
        if ($id <= 0) {
            $errors[] = "Invalid ID";
        }
        
        if (empty($errors)) {
            // Update only milestones, status_action, and notes fields
            $update_stmt = $conn->prepare("UPDATE operations SET 
                milestones = ?, status_action = ?, notes = ?, status_last_updated = ?
                WHERE id = ?");
            
            $update_stmt->bind_param("ssssi", $milestones, $status_action, $notes, $current_timestamp, $id);
            
            if ($update_stmt->execute()) {
                $status_message = "Status updated successfully!";
                $status_message_type = "success";
            } else {
                $status_message = "Error updating status: " . $conn->error;
                $status_message_type = "danger";
            }
            $update_stmt->close();
        } else {
            $status_message = implode("<br>", $errors);
            $status_message_type = "danger";
        }
    }
    
    // Generate new CSRF token
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * ============================================================================
 * GET COMPANY SETTINGS FOR INVOICE
 * ============================================================================
 */
function getStatusCompanySettings($conn) {
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
        'currency_symbol' => 'TZS',
        'logo_url' => null
    ];
}

/**
 * ============================================================================
 * FETCH COMPANY SETTINGS
 * ============================================================================
 */
$company_settings = getStatusCompanySettings($conn);

/**
 * ============================================================================
 * INITIALIZE SEARCH/FILTER/PAGINATION VARIABLES
 * ============================================================================
 */
$search = isset($_GET['search']) ? sanitize($conn, $_GET['search']) : '';
$milestone_filter = isset($_GET['milestone_filter']) ? sanitize($conn, $_GET['milestone_filter']) : '';
$status_action_filter = isset($_GET['status_action_filter']) ? sanitize($conn, $_GET['status_action_filter']) : '';
$current_page = isset($_GET['page_num']) ? (int)$_GET['page_num'] : 1;
$records_per_page = 10;
$offset = ($current_page - 1) * $records_per_page;

/**
 * ============================================================================
 * BUILD QUERY WITH SEARCH AND FILTERS (ONLY ACTIVE OPERATIONS)
 * ============================================================================
 */
$where_clause = "o.status = 'Active'";
$params = [];
$types = "";

if (!empty($search)) {
    $where_clause .= " AND (o.invoice_id LIKE ? OR o.contract_number LIKE ? OR c.customer_name LIKE ?) ";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

if (!empty($milestone_filter)) {
    $where_clause .= " AND o.milestones = ? ";
    $params[] = $milestone_filter;
    $types .= "s";
}

if (!empty($status_action_filter)) {
    $where_clause .= " AND o.status_action = ? ";
    $params[] = $status_action_filter;
    $types .= "s";
}

// Count total records for pagination
$count_query = "SELECT COUNT(*) as total FROM operations o 
                LEFT JOIN projects p ON o.contract_number = p.contract_number
                LEFT JOIN customers c ON p.client_id = c.id 
                WHERE $where_clause";

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

// Fetch active operations with pagination
$status_query = "SELECT o.*, c.customer_name, c.contact_person, c.tin_number, c.address, c.email, c.type_of_business,
                        pg.name as project_group_name, cat.name as category_name,
                        p.contract_value, p.tax, p.commission, p.cost_of_project, p.staff_cost, p.overhead_cost,
                        p.target_profit, p.actual_profit, p.profit_difference
                 FROM operations o 
                 LEFT JOIN projects p ON o.contract_number = p.contract_number
                 LEFT JOIN customers c ON p.client_id = c.id 
                 LEFT JOIN projectgroup pg ON o.project_group_id = pg.id
                 LEFT JOIN categories cat ON o.category_id = cat.id
                 WHERE $where_clause 
                 ORDER BY o.id DESC 
                 LIMIT ? OFFSET ?";

$params[] = $records_per_page;
$params[] = $offset;
$types .= "ii";

$status_stmt = $conn->prepare($status_query);
if (!empty($params)) {
    $status_stmt->bind_param($types, ...$params);
}
$status_stmt->execute();
$status_result = $status_stmt->get_result();

// Store results in array for KPI calculations
$status_data = [];
if ($status_result && $status_result->num_rows > 0) {
    while ($row = $status_result->fetch_assoc()) {
        $status_data[] = $row;
    }
    // Reset pointer for table display
    $status_stmt->execute();
    $status_result = $status_stmt->get_result();
}

/**
 * ============================================================================
 * KPI CALCULATIONS (using filtered data)
 * ============================================================================
 */

// Re-fetch all filtered records for KPI calculation (without pagination)
$kpi_query = "SELECT o.milestones, o.status_action 
              FROM operations o 
              LEFT JOIN projects p ON o.contract_number = p.contract_number
              LEFT JOIN customers c ON p.client_id = c.id 
              WHERE $where_clause";

$kpi_params = array_slice($params, 0, -2); // Remove pagination params
$kpi_types = substr($types, 0, -2);

$kpi_result = null;
if (!empty($kpi_params)) {
    $kpi_stmt = $conn->prepare($kpi_query);
    $kpi_stmt->bind_param($kpi_types, ...$kpi_params);
    $kpi_stmt->execute();
    $kpi_result = $kpi_stmt->get_result();
} else {
    $kpi_result = $conn->query($kpi_query);
}

$active_projects = 0;
$pending_milestones = 0;
$completed_milestones = 0;
$waiting_actions = 0;

if ($kpi_result && $kpi_result->num_rows > 0) {
    while ($row = $kpi_result->fetch_assoc()) {
        $active_projects++;
        
        // Count pending milestones
        if ($row['milestones'] == 'Pending') {
            $pending_milestones++;
        }
        
        // Count completed milestones (contains 'complete' or 'done')
        $milestone = strtolower($row['milestones'] ?? '');
        if (strpos($milestone, 'complete') !== false || strpos($milestone, 'done') !== false) {
            $completed_milestones++;
        }
        
        // Count waiting actions (contains 'waiting' or 'awaiting')
        $action = strtolower($row['status_action'] ?? '');
        if (strpos($action, 'waiting') !== false || strpos($action, 'awaiting') !== false) {
            $waiting_actions++;
        }
    }
}

if (isset($kpi_stmt)) {
    $kpi_stmt->close();
}

?>

<!-- STATUS TRANSLATIONS -->
<script>
// Status translations for English and Swahili
const status_translations = {
    en: {
        pageTitle: 'Project Status Tracking',
        subtitle: 'Active Projects Monitoring',
        activeProjects: 'Active Projects',
        pendingMilestones: 'Pending Milestones',
        completedMilestones: 'Completed Milestones',
        waitingActions: 'Waiting Actions',
        invoiceId: 'Invoice ID',
        contractNumber: 'Contract Number',
        clientName: 'Client Name',
        projectType: 'Project Type',
        milestones: 'Milestones',
        statusAction: 'Status Action',
        timeLines: 'Time Lines',
        staffAssigned: 'Staff Assigned',
        notes: 'Notes',
        preparedBy: 'Prepared By',
        statusLastUpdated: 'Last Updated',
        actions: 'Actions',
        edit: 'Edit',
        save: 'Save',
        cancel: 'Cancel',
        view: 'View',
        search: 'Search',
        filter: 'Filter',
        allMilestones: 'All Milestones',
        allStatusActions: 'All Status Actions',
        clearFilters: 'Clear Filters',
        refresh: 'Refresh Data',
        page: 'Page',
        of: 'of',
        previous: 'Previous',
        next: 'Next',
        totalRecords: 'Total Active Projects',
        records: 'records',
        noData: 'No active projects found matching your filters.',
        loading: 'Loading...',
        close: 'Close',
        print: 'Print',
        scrollHint: 'Scroll horizontally to see all columns →',
        
        // Modal Labels
        invoiceTitle: 'PROJECT STATUS REPORT',
        operationInformation: 'PROJECT INFORMATION',
        contractInformation: 'CONTRACT INFORMATION',
        clientInformation: 'CLIENT DETAILS',
        projectTimeline: 'PROJECT TIMELINE',
        milestonesLabel: 'Milestones',
        statusActionLabel: 'Status Action',
        notesLabel: 'Notes',
        staffAllocatedLabel: 'Staff Assigned',
        generatedBy: 'Generated by',
        datePrinted: 'Date Printed',
        staffMembers: 'staff members',
        projectGroupLabel: 'Project Group',
        categoryLabel: 'Category',
        durationTypeLabel: 'Duration Type',
        startDate: 'Start Date',
        endDate: 'End Date',
        status: 'Status',
        
        // Success/Error Messages
        updateSuccess: 'Status updated successfully!',
        updateError: 'Error updating status. Please try again.',
        permissionDenied: 'You do not have permission to edit status records.',
        
        // Placeholders
        searchPlaceholder: 'Search by invoice #, contract # or client...',
        notesPlaceholder: 'Enter additional notes...'
    },
    sw: {
        pageTitle: 'Ufuatiliaji wa Hali ya Mradi',
        subtitle: 'Ufuatiliaji wa Miradi Inayoendelea',
        activeProjects: 'Miradi Inayoendelea',
        pendingMilestones: 'Hatua Zinazosubiri',
        completedMilestones: 'Hatua Zilizokamilika',
        waitingActions: 'Vitendo Vinavyosubiri',
        invoiceId: 'Namba ya Ankara',
        contractNumber: 'Namba ya Mkataba',
        clientName: 'Jina la Mteja',
        projectType: 'Aina ya Mradi',
        milestones: 'Hatua',
        statusAction: 'Kitendo cha Hali',
        timeLines: 'Muda',
        staffAssigned: 'Wafanyakazi Waliopangiwa',
        notes: 'Maelezo',
        preparedBy: 'Imeandaliwa na',
        statusLastUpdated: 'Ilisasishwa Mwisho',
        actions: 'Vitendo',
        edit: 'Hariri',
        save: 'Hifadhi',
        cancel: 'Ghairi',
        view: 'Angalia',
        search: 'Tafuta',
        filter: 'Chuja',
        allMilestones: 'Hatua Zote',
        allStatusActions: 'Vitendo Vyote vya Hali',
        clearFilters: 'Futa Vichujio',
        refresh: 'Onesha Upya Data',
        page: 'Ukurasa',
        of: 'kati ya',
        previous: 'Iliyotangulia',
        next: 'Ijayo',
        totalRecords: 'Jumla ya Miradi Inayoendelea',
        records: 'rekodi',
        noData: 'Hakuna miradi inayoendelea inayolingana na vichujio vyako.',
        loading: 'Inapakia...',
        close: 'Funga',
        print: 'Chapisha',
        scrollHint: 'Sogeza upande ili kuona safu zote →',
        
        // Modal Labels
        invoiceTitle: 'RIPOTI YA HALI YA MRADI',
        operationInformation: 'TAARIFA ZA MRADI',
        contractInformation: 'TAARIFA ZA MKATABA',
        clientInformation: 'TAARIFA ZA MTEJA',
        projectTimeline: 'MUDA WA MRADI',
        milestonesLabel: 'Hatua',
        statusActionLabel: 'Kitendo cha Hali',
        notesLabel: 'Maelezo',
        staffAllocatedLabel: 'Wafanyakazi Waliopangiwa',
        generatedBy: 'Imetolewa na',
        datePrinted: 'Tarehe ya Kuchapishwa',
        staffMembers: 'wafanyakazi',
        projectGroupLabel: 'Kundi la Mradi',
        categoryLabel: 'Kategoria',
        durationTypeLabel: 'Aina ya Muda',
        startDate: 'Tarehe ya Kuanza',
        endDate: 'Tarehe ya Mwisho',
        status: 'Hali',
        
        // Success/Error Messages
        updateSuccess: 'Hali imesasishwa kikamilifu!',
        updateError: 'Hitilafu katika kusasisha hali. Tafadhali jaribu tena.',
        permissionDenied: 'Huna ruhusa ya kuhariri rekodi za hali.',
        
        // Placeholders
        searchPlaceholder: 'Tafuta kwa namba ya ankara, mkataba au mteja...',
        notesPlaceholder: 'Weka maelezo ya ziada...'
    }
};

// Current language
let currentStatusLang = localStorage.getItem('preferredLanguage') || 'en';

// Function to update all translatable elements in status module
function updateStatusLanguage(lang) {
    currentStatusLang = lang;
    const elements = document.querySelectorAll('[data-status-lang]');
    
    elements.forEach(element => {
        const key = element.getAttribute('data-status-lang');
        if (status_translations[lang] && status_translations[lang][key]) {
            if (element.tagName === 'INPUT' || element.tagName === 'TEXTAREA') {
                if (element.getAttribute('type') !== 'submit' && element.getAttribute('type') !== 'button') {
                    element.placeholder = status_translations[lang][key];
                }
            } else if (element.tagName === 'OPTION') {
                element.textContent = status_translations[lang][key];
            } else {
                element.textContent = status_translations[lang][key];
            }
        }
    });
    
    // Update table header
    const thElements = document.querySelectorAll('th[data-status-lang]');
    thElements.forEach(th => {
        const key = th.getAttribute('data-status-lang');
        if (status_translations[lang] && status_translations[lang][key]) {
            th.textContent = status_translations[lang][key];
        }
    });
    
    // Update KPI card labels
    const kpiLabels = document.querySelectorAll('.status-kpi-label');
    kpiLabels.forEach(label => {
        const key = label.getAttribute('data-status-lang');
        if (key && status_translations[lang] && status_translations[lang][key]) {
            label.textContent = status_translations[lang][key];
        }
    });
    
    // Update empty state message
    const emptyState = document.querySelector('.status-empty p');
    if (emptyState) {
        emptyState.textContent = status_translations[lang].noData;
    }
    
    // Update search input placeholder
    const searchInput = document.querySelector('input[name="search"]');
    if (searchInput) {
        searchInput.placeholder = status_translations[lang].searchPlaceholder;
    }
    
    // Update total records text
    const totalRecordsSpan = document.querySelector('#totalRecords');
    if (totalRecordsSpan) {
        totalRecordsSpan.textContent = status_translations[lang].totalRecords;
    }
    
    // Update scroll hint text
    const scrollHintSpan = document.querySelector('#status_scrollHint span');
    if (scrollHintSpan) {
        scrollHintSpan.textContent = status_translations[lang].scrollHint;
    }
    
    // Update pagination buttons
    const prevBtn = document.querySelector('.status-prev-btn');
    const nextBtn = document.querySelector('.status-next-btn');
    if (prevBtn && prevBtn.tagName === 'A') prevBtn.innerHTML = `<i class="fas fa-chevron-left"></i> ${status_translations[lang].previous}`;
    if (nextBtn && nextBtn.tagName === 'A') nextBtn.innerHTML = `${status_translations[lang].next} <i class="fas fa-chevron-right"></i>`;
    
    // Update filter dropdowns
    const milestoneFilter = document.getElementById('status_milestone_filter');
    if (milestoneFilter) {
        const placeholderOption = milestoneFilter.querySelector('option[value=""]');
        if (placeholderOption) {
            placeholderOption.textContent = status_translations[lang].allMilestones;
        }
    }
    
    const statusActionFilter = document.getElementById('status_status_action_filter');
    if (statusActionFilter) {
        const placeholderOption = statusActionFilter.querySelector('option[value=""]');
        if (placeholderOption) {
            placeholderOption.textContent = status_translations[lang].allStatusActions;
        }
    }
    
    // Update modal labels
    const modalLabels = document.querySelectorAll('[data-modal-lang]');
    modalLabels.forEach(label => {
        const key = label.getAttribute('data-modal-lang');
        if (status_translations[lang] && status_translations[lang][key]) {
            label.textContent = status_translations[lang][key];
        }
    });
}

// This function will be called from homepage.js when language changes
window.updateStatusLanguage = updateStatusLanguage;

// Document ready - NO SIDEBAR CODE (Issue #1 resolved)
document.addEventListener('DOMContentLoaded', function() {
    updateStatusLanguage(currentStatusLang);
});
</script>

<style>
    /* Status Module Styles - Using status_ prefix (ISSUE #3: No sidebar conflicts) */
    /* NO SIDEBAR-RELATED CSS - All styles are self-contained */
    
    .status-container {
        width: 100%;
    }

    /* KPI Cards Row */
    .status-kpi-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 32px;
    }

    .status-kpi-card {
        background: white;
        border-radius: 16px;
        padding: 20px;
        box-shadow: var(--shadow-md);
        border: 1px solid var(--gray-200);
        transition: all 0.2s;
        position: relative;
        overflow: hidden;
    }

    .status-kpi-card:hover {
        transform: translateY(-4px);
        box-shadow: var(--shadow-lg);
    }

    .status-kpi-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 4px;
        height: 100%;
    }

    .status-kpi-card-blue::before {
        background: #007bff;
    }

    .status-kpi-card-orange::before {
        background: #fd7e14;
    }

    .status-kpi-card-green::before {
        background: #28a745;
    }

    .status-kpi-card-purple::before {
        background: #6f42c1;
    }

    .status-kpi-icon {
        font-size: 32px;
        margin-bottom: 12px;
    }

    .status-kpi-icon-blue {
        color: #007bff;
    }

    .status-kpi-icon-orange {
        color: #fd7e14;
    }

    .status-kpi-icon-green {
        color: #28a745;
    }

    .status-kpi-icon-purple {
        color: #6f42c1;
    }

    .status-kpi-value {
        font-size: 28px;
        font-weight: 700;
        color: var(--gray-800);
        margin-bottom: 8px;
    }

    .status-kpi-label {
        font-size: 14px;
        color: var(--gray-500);
        font-weight: 500;
    }

    /* Header Styles */
    .status-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
        flex-wrap: wrap;
        gap: 16px;
    }

    .status-header h2 {
        color: var(--gray-800);
        font-size: 24px;
        font-weight: 600;
        margin: 0;
    }

    .status-header p {
        color: var(--gray-500);
        font-size: 14px;
        margin: 4px 0 0;
    }

    /* Alert Styles */
    .status-alert {
        padding: 16px;
        border-radius: 8px;
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .status-alert-success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }

    .status-alert-danger {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }

    .status-alert-close {
        background: none;
        border: none;
        font-size: 20px;
        cursor: pointer;
        color: inherit;
        opacity: 0.5;
    }

    .status-alert-close:hover {
        opacity: 1;
    }

    /* Search and Filter Bar */
    .status-search-bar {
        background: white;
        padding: 20px;
        border-radius: 12px;
        margin-bottom: 24px;
        border: 1px solid var(--gray-200);
    }

    .status-filter-row {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        align-items: flex-end;
    }

    .status-search-group {
        flex: 1;
        min-width: 200px;
    }

    .status-search-group label {
        display: block;
        color: var(--gray-700);
        font-size: 13px;
        font-weight: 500;
        margin-bottom: 6px;
    }

    .status-search-group input,
    .status-search-group select {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid var(--gray-300);
        border-radius: 6px;
        font-size: 14px;
    }

    .status-search-group input:focus,
    .status-search-group select:focus {
        outline: none;
        border-color: var(--brown-600);
        box-shadow: 0 0 0 3px rgba(123, 88, 63, 0.1);
    }

    .status-filter-actions {
        display: flex;
        gap: 8px;
    }

    .status-btn {
        padding: 10px 20px;
        background: var(--brown-700);
        color: white;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-weight: 500;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .status-btn:hover {
        background: var(--brown-800);
    }

    .status-btn-secondary {
        background: var(--gray-200);
        color: var(--gray-700);
    }

    .status-btn-secondary:hover {
        background: var(--gray-300);
    }

    .status-clear-btn {
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

    .status-clear-btn:hover {
        background: var(--gray-300);
    }

    /* Stats Bar */
    .status-stats {
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

    .status-stats-info {
        display: flex;
        align-items: center;
        gap: 8px;
        color: var(--gray-700);
        font-size: 14px;
    }

    .status-stats-info i {
        color: var(--brown-600);
    }

    .status-stats-count {
        font-weight: 700;
        color: var(--brown-700);
        font-size: 18px;
    }

    /* ============================================================================
       TABLE STYLES - IMPROVED FOR BETTER VISIBILITY OF ACTIONS COLUMN
       ============================================================================ */
    
    /* Table Container with smooth scrolling */
    .status-table-container {
        overflow-x: auto;
        background: white;
        border-radius: 12px;
        border: 1px solid var(--gray-200);
        margin-bottom: 16px;
        position: relative;
        /* Smooth scrolling for better UX */
        scroll-behavior: smooth;
    }
    
    /* Custom scrollbar styling - makes it more visible */
    .status-table-container::-webkit-scrollbar {
        height: 10px;
        background: var(--gray-200);
        border-radius: 5px;
    }
    
    .status-table-container::-webkit-scrollbar-track {
        background: var(--gray-100);
        border-radius: 5px;
    }
    
    .status-table-container::-webkit-scrollbar-thumb {
        background: var(--brown-600);
        border-radius: 5px;
        cursor: pointer;
    }
    
    .status-table-container::-webkit-scrollbar-thumb:hover {
        background: var(--brown-700);
    }
    
    /* Firefox scrollbar styling */
    .status-table-container {
        scrollbar-width: thin;
        scrollbar-color: var(--brown-600) var(--gray-200);
    }

    /* Table - Reduced min-width to better fit common screen sizes */
    .status-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 1200px; /* Reduced from 1400px for better fit */
    }

    .status-table th {
        background: var(--gray-50);
        color: var(--gray-700);
        font-weight: 600;
        font-size: 13px;
        padding: 14px 10px;
        text-align: left;
        border-bottom: 2px solid var(--gray-200);
        white-space: nowrap;
    }

    .status-table td {
        padding: 12px 10px;
        border-bottom: 1px solid var(--gray-200);
        color: var(--gray-700);
        font-size: 13px;
        vertical-align: middle;
    }

    /* ============================================================================
       FIX: ACTIONS COLUMN - STICKY AND ALWAYS VISIBLE
       ============================================================================ */
    
    /* Make Actions column sticky - ensures it's always visible when scrolling horizontally */
    .status-table th:last-child,
    .status-table td:last-child {
        position: sticky;
        right: 0;
        background: white;
        box-shadow: -2px 0 5px rgba(0, 0, 0, 0.1);
        z-index: 15;
    }
    
    /* Ensure sticky column has proper background on hover */
    .status-table tr:hover td:last-child {
        background: var(--gray-50);
    }
    
    /* Ensure sticky column header has proper background */
    .status-table th:last-child {
        background: var(--gray-50);
        z-index: 20;
    }

    /* ============================================================================
       FIX: ACTION BUTTONS - NO WRAPPING, ALWAYS VISIBLE
       ============================================================================ */
    
    /* Action buttons container - prevent wrapping */
    .status-actions {
        display: flex;
        gap: 6px;
        flex-wrap: nowrap;
        white-space: nowrap;
        align-items: center;
    }
    
    /* Action buttons - compact but readable */
    .status-action-btn {
        padding: 5px 8px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 500;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        transition: all 0.2s;
        cursor: pointer;
        border: none;
        white-space: nowrap;
    }

    /* Individual button colors */
    .status-action-edit {
        background: var(--brown-100);
        color: var(--brown-800);
    }

    .status-action-edit:hover {
        background: var(--brown-200);
    }

    .status-action-save {
        background: #28a745;
        color: white;
    }

    .status-action-save:hover {
        background: #218838;
    }

    .status-action-cancel {
        background: #6c757d;
        color: white;
    }

    .status-action-cancel:hover {
        background: #5a6268;
    }

    .status-action-view {
        background: #17a2b8;
        color: white;
    }

    .status-action-view:hover {
        background: #138496;
    }

    /* Disabled button state (during loading) */
    .status-action-btn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }

    /* ============================================================================
       EDIT MODE STYLES
       ============================================================================ */
    
    /* Dropdown and textarea in edit mode */
    .status-edit-select {
        width: 100%;
        min-width: 150px;
        padding: 8px 10px;
        border: 1px solid var(--brown-600);
        border-radius: 6px;
        font-size: 13px;
        background: white;
    }

    .status-edit-textarea {
        width: 100%;
        min-width: 150px;
        padding: 8px 10px;
        border: 1px solid var(--brown-600);
        border-radius: 6px;
        font-size: 13px;
        font-family: inherit;
        resize: both;
        min-height: 60px;
    }

    .status-edit-textarea:focus,
    .status-edit-select:focus {
        outline: none;
        border-color: var(--brown-700);
        box-shadow: 0 0 0 2px rgba(123, 88, 63, 0.1);
    }

    /* Staff List Display */
    .status-staff-list {
        max-width: 180px;
        font-size: 12px;
        line-height: 1.4;
    }

    .status-staff-list span {
        display: inline-block;
        background: var(--gray-100);
        padding: 2px 6px;
        border-radius: 4px;
        margin: 2px;
        font-size: 11px;
    }

    /* Notes Display */
    .status-notes-display {
        max-width: 180px;
        font-size: 12px;
        line-height: 1.4;
        white-space: pre-wrap;
        word-break: break-word;
    }

    /* Status Badge */
    .status-badge {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 500;
    }

    .status-badge-active {
        background: #d4edda;
        color: #155724;
    }

    /* Timestamp */
    .status-timestamp {
        font-size: 11px;
        color: var(--gray-500);
    }

    /* ============================================================================
       SCROLL HINT - SHOWS WHEN TABLE OVERFLOWS
       ============================================================================ */
    
    .status-scroll-hint {
        text-align: center;
        padding: 8px 12px;
        background: linear-gradient(90deg, transparent, var(--gray-100), transparent);
        font-size: 11px;
        color: var(--gray-500);
        margin-bottom: 20px;
        pointer-events: none;
        opacity: 0;
        transition: opacity 0.3s ease;
        border-radius: 20px;
        display: inline-block;
        width: auto;
        margin-left: auto;
        margin-right: auto;
    }
    
    .status-scroll-hint i {
        margin-right: 6px;
        color: var(--brown-600);
    }
    
    .status-scroll-hint.visible {
        opacity: 1;
    }

    /* Pagination */
    .status-pagination {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 12px;
        margin-top: 20px;
    }

    .status-pagination a,
    .status-pagination span {
        padding: 8px 16px;
        border-radius: 6px;
        text-decoration: none;
        font-size: 14px;
        font-weight: 500;
        transition: all 0.2s;
    }

    .status-pagination a {
        background: white;
        border: 1px solid var(--gray-300);
        color: var(--gray-700);
    }

    .status-pagination a:hover {
        background: var(--brown-100);
        border-color: var(--brown-600);
        color: var(--brown-800);
    }

    .status-pagination .disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    /* Empty State */
    .status-empty {
        text-align: center;
        padding: 48px !important;
        color: var(--gray-500);
    }

    .status-empty i {
        font-size: 48px;
        margin-bottom: 16px;
        color: var(--gray-400);
    }

    /* Loading Indicator */
    .status-loading {
        display: inline-block;
        width: 20px;
        height: 20px;
        border: 3px solid var(--gray-200);
        border-top-color: var(--brown-700);
        border-radius: 50%;
        animation: status-spin 1s linear infinite;
    }

    @keyframes status-spin {
        to { transform: rotate(360deg); }
    }

    /* ============================================================================
       MODAL STYLES - For Invoice/Report View
       ============================================================================ */
    .status-modal-overlay {
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

    .status-modal-overlay.active {
        display: flex;
    }

    .status-modal {
        background: white;
        border-radius: 16px;
        width: 100%;
        max-width: 900px;
        max-height: 90vh;
        overflow: hidden;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        animation: status-modalFadeIn 0.2s ease-out;
        display: flex;
        flex-direction: column;
    }

    @keyframes status-modalFadeIn {
        from {
            opacity: 0;
            transform: scale(0.95);
        }
        to {
            opacity: 1;
            transform: scale(1);
        }
    }

    .status-modal-header {
        padding: 20px 24px;
        border-bottom: 2px solid var(--gray-200);
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: linear-gradient(135deg, #faf7f2 0%, #f5efe8 100%);
    }

    .status-modal-title {
        font-family: 'Montserrat', sans-serif;
        font-weight: 700;
        font-size: 1.3rem;
        color: var(--brown-800);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .status-modal-close {
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

    .status-modal-close:hover {
        background: rgba(139, 90, 43, 0.1);
        color: #dc3545;
    }

    .status-modal-body {
        padding: 24px;
        overflow-y: auto;
        flex: 1;
    }

    .status-modal-footer {
        padding: 16px 24px;
        border-top: 1px solid var(--gray-200);
        display: flex;
        justify-content: flex-end;
        gap: 12px;
        background: #faf7f5;
    }

    /* Invoice Content inside Modal */
    .status-modal-invoice {
        background: white;
        font-family: 'Inter', sans-serif;
    }

    .status-modal-invoice-header {
        text-align: center;
        padding: 20px;
        border-bottom: 2px solid var(--brown-700);
        margin-bottom: 20px;
    }

    .status-modal-invoice-logo {
        max-width: 80px;
        max-height: 80px;
        margin-bottom: 15px;
    }

    .status-modal-invoice-company-name {
        font-size: 22px;
        font-weight: 700;
        color: var(--brown-800);
        margin-bottom: 5px;
    }

    .status-modal-invoice-company-details {
        font-size: 11px;
        color: var(--gray-600);
        line-height: 1.5;
    }

    .status-modal-invoice-title {
        text-align: center;
        font-size: 18px;
        font-weight: 700;
        color: var(--brown-800);
        margin: 20px 0;
        padding: 10px;
        background: var(--brown-100);
        letter-spacing: 2px;
    }

    .status-modal-invoice-section {
        margin-bottom: 20px;
        border: 1px solid var(--gray-200);
        border-radius: 8px;
        overflow: hidden;
    }

    .status-modal-invoice-section-title {
        background: var(--gray-100);
        padding: 10px 15px;
        font-weight: 700;
        color: var(--brown-800);
        border-bottom: 1px solid var(--gray-200);
        font-size: 14px;
    }

    .status-modal-invoice-section-content {
        padding: 15px;
    }

    .status-modal-invoice-row {
        display: flex;
        justify-content: space-between;
        padding: 6px 0;
        border-bottom: 1px dashed var(--gray-200);
        font-size: 13px;
    }

    .status-modal-invoice-row:last-child {
        border-bottom: none;
    }

    .status-modal-invoice-label {
        font-weight: 600;
        color: var(--gray-700);
        width: 40%;
    }

    .status-modal-invoice-value {
        color: var(--gray-800);
        width: 60%;
        text-align: right;
    }

    .status-modal-invoice-staff-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 10px;
        font-size: 12px;
    }

    .status-modal-invoice-staff-table th {
        background: var(--gray-100);
        padding: 8px;
        text-align: left;
        border-bottom: 1px solid var(--gray-200);
    }

    .status-modal-invoice-staff-table td {
        padding: 6px 8px;
        border-bottom: 1px solid var(--gray-200);
    }

    .status-modal-invoice-footer {
        margin-top: 20px;
        padding-top: 15px;
        border-top: 1px solid var(--gray-200);
        text-align: center;
        font-size: 10px;
        color: var(--gray-500);
    }

    /* Print styles */
    @media print {
        body * {
            visibility: hidden;
        }
        .status-modal-body,
        .status-modal-body * {
            visibility: visible;
        }
        .status-modal-body {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            padding: 0;
            margin: 0;
        }
        .status-modal-header,
        .status-modal-footer {
            display: none;
        }
        @page {
            size: A4 landscape;
            margin: 0.5in;
        }
    }

    /* Responsive */
    @media (max-width: 1024px) {
        .status-kpi-row {
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }
        
        .status-kpi-value {
            font-size: 22px;
        }
    }

    @media (max-width: 768px) {
        .status-filter-row {
            flex-direction: column;
        }
        
        .status-search-group {
            width: 100%;
        }
        
        .status-filter-actions {
            width: 100%;
            justify-content: center;
        }
        
        .status-kpi-row {
            grid-template-columns: 1fr;
        }
        
        .status-modal {
            max-width: 95%;
            max-height: 95vh;
        }
        
        .status-modal-body {
            padding: 16px;
        }
        
        /* On mobile, make action buttons slightly larger for easier tapping */
        .status-action-btn {
            padding: 6px 10px;
            font-size: 12px;
        }
        
        /* Show scroll hint more prominently on mobile */
        .status-scroll-hint {
            font-size: 12px;
            padding: 10px;
        }
    }
    
    /* Small screens - ensure sticky column works */
    @media (max-width: 640px) {
        .status-table th:last-child,
        .status-table td:last-child {
            box-shadow: -3px 0 8px rgba(0, 0, 0, 0.15);
        }
    }
</style>

<div class="status-container">
    <!-- Header -->
    <div class="status-header">
        <div>
            <h2 data-status-lang="pageTitle">Project Status Tracking</h2>
            <p data-status-lang="subtitle">Active Projects Monitoring</p>
        </div>
    </div>

    <!-- Alert Messages -->
    <?php if (!empty($status_message)): ?>
        <div class="status-alert status-alert-<?= $status_message_type ?>">
            <?= $status_message ?>
            <button class="status-alert-close" onclick="this.parentElement.style.display='none'">&times;</button>
        </div>
    <?php endif; ?>

    <!-- KPI Cards Row -->
    <div class="status-kpi-row">
        <div class="status-kpi-card status-kpi-card-blue">
            <div class="status-kpi-icon status-kpi-icon-blue">
                <i class="fas fa-play-circle"></i>
            </div>
            <div class="status-kpi-value" id="status_activeCount"><?= number_format($active_projects) ?></div>
            <div class="status-kpi-label" data-status-lang="activeProjects">Active Projects</div>
        </div>
        
        <div class="status-kpi-card status-kpi-card-orange">
            <div class="status-kpi-icon status-kpi-icon-orange">
                <i class="fas fa-hourglass-half"></i>
            </div>
            <div class="status-kpi-value" id="status_pendingMilestones"><?= number_format($pending_milestones) ?></div>
            <div class="status-kpi-label" data-status-lang="pendingMilestones">Pending Milestones</div>
        </div>
        
        <div class="status-kpi-card status-kpi-card-green">
            <div class="status-kpi-icon status-kpi-icon-green">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="status-kpi-value" id="status_completedMilestones"><?= number_format($completed_milestones) ?></div>
            <div class="status-kpi-label" data-status-lang="completedMilestones">Completed Milestones</div>
        </div>
        
        <div class="status-kpi-card status-kpi-card-purple">
            <div class="status-kpi-icon status-kpi-icon-purple">
                <i class="fas fa-clock"></i>
            </div>
            <div class="status-kpi-value" id="status_waitingActions"><?= number_format($waiting_actions) ?></div>
            <div class="status-kpi-label" data-status-lang="waitingActions">Waiting Actions</div>
        </div>
    </div>

    <!-- Search and Filter Bar -->
    <div class="status-search-bar">
        <form method="GET" action="?page=status" id="status_filterForm">
            <input type="hidden" name="page" value="status">
            
            <div class="status-filter-row">
                <div class="status-search-group">
                    <label for="search" data-status-lang="search">Search</label>
                    <input type="text" id="search" name="search" 
                           value="<?= htmlspecialchars($search) ?>" 
                           data-status-lang="searchPlaceholder" placeholder="Search by invoice #, contract # or client...">
                </div>
                
                <div class="status-search-group">
                    <label for="milestone_filter" data-status-lang="milestones">Milestones</label>
                    <select id="status_milestone_filter" name="milestone_filter">
                        <option value="" data-status-lang="allMilestones">All Milestones</option>
                        <?php foreach ($milestones_options as $value => $label): ?>
                            <option value="<?= htmlspecialchars($value) ?>" <?= $milestone_filter == $value ? 'selected' : '' ?>>
                                <?= htmlspecialchars($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="status-search-group">
                    <label for="status_action_filter" data-status-lang="statusAction">Status Action</label>
                    <select id="status_status_action_filter" name="status_action_filter">
                        <option value="" data-status-lang="allStatusActions">All Status Actions</option>
                        <?php foreach ($status_action_options as $value => $label): ?>
                            <option value="<?= htmlspecialchars($value) ?>" <?= $status_action_filter == $value ? 'selected' : '' ?>>
                                <?= htmlspecialchars($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="status-filter-actions">
                    <button type="submit" class="status-btn">
                        <i class="fas fa-search"></i> <span data-status-lang="filter">Filter</span>
                    </button>
                    <a href="?page=status" class="status-clear-btn">
                        <i class="fas fa-times"></i> <span data-status-lang="clearFilters">Clear Filters</span>
                    </a>
                    <button type="button" id="status_refreshBtn" class="status-btn status-btn-secondary">
                        <i class="fas fa-sync-alt"></i> <span data-status-lang="refresh">Refresh Data</span>
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Stats Bar -->
    <div class="status-stats">
        <div class="status-stats-info">
            <i class="fas fa-tasks"></i>
            <span id="totalRecords" data-status-lang="totalRecords">Total Active Projects</span>
            <span>:</span>
            <span class="status-stats-count" id="status_totalRecordsCount"><?= $total_records ?></span>
            <span data-status-lang="records">records</span>
        </div>
    </div>

    <!-- Scroll Hint (appears when table overflows) -->
    <div id="status_scrollHint" class="status-scroll-hint">
        <i class="fas fa-arrows-alt-h"></i> <span data-status-lang="scrollHint">Scroll horizontally to see all columns →</span>
    </div>

    <!-- Data Table -->
    <div class="status-table-container">
        <table class="status-table" id="status_dataTable">
            <thead>
                <tr>
                    <th data-status-lang="invoiceId">Invoice ID</th>
                    <th data-status-lang="contractNumber">Contract Number</th>
                    <th data-status-lang="clientName">Client Name</th>
                    <th data-status-lang="projectType">Project Type</th>
                    <th data-status-lang="milestones">Milestones</th>
                    <th data-status-lang="statusAction">Status Action</th>
                    <th data-status-lang="timeLines">Time Lines</th>
                    <th data-status-lang="staffAssigned">Staff Assigned</th>
                    <th data-status-lang="notes">Notes</th>
                    <th data-status-lang="preparedBy">Prepared By</th>
                    <th data-status-lang="statusLastUpdated">Last Updated</th>
                    <th data-status-lang="actions">Actions</th>
                </tr>
            </thead>
            <tbody id="status_tableBody">
                <?php if ($status_result && $status_result->num_rows > 0): ?>
                    <?php while ($row = $status_result->fetch_assoc()): ?>
                        <?php
                            // Parse assigned staff JSON
                            $staff_names = [];
                            $assigned_staff = $row['assigned_staff'];
                            if (!empty($assigned_staff)) {
                                $staff_data = json_decode($assigned_staff, true);
                                if (is_array($staff_data)) {
                                    foreach ($staff_data as $staff) {
                                        if (!empty($staff['name'])) {
                                            $staff_names[] = htmlspecialchars($staff['name']);
                                        }
                                    }
                                }
                            }
                            $staff_list = !empty($staff_names) ? implode(', ', $staff_names) : 'No staff assigned';
                            
                            // Format timeline
                            $timeline = date('d M Y', strtotime($row['start_date'])) . ' to ' . date('d M Y', strtotime($row['end_date']));
                            
                            // Format last updated
                            $last_updated = !empty($row['status_last_updated']) ? date('d M Y H:i', strtotime($row['status_last_updated'])) : 'Never';
                        ?>
                        <tr id="status_row_<?= $row['id'] ?>" data-id="<?= $row['id'] ?>"
                            data-milestones="<?= htmlspecialchars($row['milestones'] ?? '') ?>"
                            data-status-action="<?= htmlspecialchars($row['status_action'] ?? '') ?>"
                            data-notes="<?= htmlspecialchars($row['notes'] ?? '') ?>">
                            <td><strong><?= htmlspecialchars($row['invoice_id']) ?></strong></td>
                            <td><?= htmlspecialchars($row['contract_number']) ?></td>
                            <td><?= htmlspecialchars($row['customer_name'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($row['category_name'] ?? 'N/A') ?></td>
                            <td class="status-milestones-cell">
                                <span class="status-milestones-display"><?= htmlspecialchars($row['milestones'] ?? 'Not set') ?></span>
                            </td>
                            <td class="status-status-action-cell">
                                <span class="status-status-action-display"><?= htmlspecialchars($row['status_action'] ?? 'Not set') ?></span>
                            </td>
                            <td><?= $timeline ?></td>
                            <td><div class="status-staff-list"><?= $staff_list ?></div></td>
                            <td class="status-notes-cell">
                                <div class="status-notes-display"><?= nl2br(htmlspecialchars($row['notes'] ?? '')) ?: '<em>No notes</em>' ?></div>
                            </td>
                            <td><?= htmlspecialchars($row['created_by']) ?></td>
                            <td class="status-last-updated-cell">
                                <?= $last_updated ?>
                            </td>
                            <td>
                                <div class="status-actions">
                                    <?php if (canEdit($conn, $user_role_id, $module_name)): ?>
                                    <button onclick="status_editRow(<?= $row['id'] ?>)" class="status-action-btn status-action-edit">
                                        <i class="fas fa-edit"></i> <span data-status-lang="edit">Edit</span>
                                    </button>
                                    <?php endif; ?>
                                    <button onclick="status_openModal(<?= $row['id'] ?>)" class="status-action-btn status-action-view">
                                        <i class="fas fa-eye"></i> <span data-status-lang="view">View</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr class="status-empty-row">
                        <td colspan="12" class="status-empty">
                            <i class="fas fa-tasks"></i>
                            <p data-status-lang="noData">No active projects found matching your filters.</p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="status-pagination">
        <?php if ($current_page > 1): ?>
            <a href="?page=status&page_num=<?= $current_page - 1 ?>&search=<?= urlencode($search) ?>&milestone_filter=<?= urlencode($milestone_filter) ?>&status_action_filter=<?= urlencode($status_action_filter) ?>" class="status-prev-btn">
                <i class="fas fa-chevron-left"></i> <span data-status-lang="previous">Previous</span>
            </a>
        <?php else: ?>
            <span class="disabled"><i class="fas fa-chevron-left"></i> <span data-status-lang="previous">Previous</span></span>
        <?php endif; ?>
        
        <span><span data-status-lang="page">Page</span> <?= $current_page ?> <span data-status-lang="of">of</span> <?= $total_pages ?></span>
        
        <?php if ($current_page < $total_pages): ?>
            <a href="?page=status&page_num=<?= $current_page + 1 ?>&search=<?= urlencode($search) ?>&milestone_filter=<?= urlencode($milestone_filter) ?>&status_action_filter=<?= urlencode($status_action_filter) ?>" class="status-next-btn">
                <span data-status-lang="next">Next</span> <i class="fas fa-chevron-right"></i>
            </a>
        <?php else: ?>
            <span class="disabled"><span data-status-lang="next">Next</span> <i class="fas fa-chevron-right"></i></span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Invoice/Report Modal -->
<div id="status_modal" class="status-modal-overlay">
    <div class="status-modal">
        <div class="status-modal-header">
            <h3 class="status-modal-title">
                <i class="fas fa-file-alt"></i> <span data-modal-lang="invoiceTitle">PROJECT STATUS REPORT</span>
            </h3>
            <button type="button" class="status-modal-close" onclick="status_closeModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="status-modal-body" id="status_modalBody">
            <!-- Modal content will be dynamically inserted here -->
        </div>
        <div class="status-modal-footer">
            <button type="button" class="status-btn status-btn-secondary" onclick="status_closeModal()">
                <i class="fas fa-times"></i> <span data-status-lang="close">Close</span>
            </button>
            <button type="button" class="status-btn" onclick="status_printModal()">
                <i class="fas fa-print"></i> <span data-status-lang="print">Print</span>
            </button>
        </div>
    </div>
</div>

<script>
    // ============================================================================
    // STATUS MODULE JAVASCRIPT - NO SIDEBAR CODE (Issue #1 resolved)
    // ============================================================================
    
    // Milestones and Status Action options from PHP
    const status_milestonesOptions = <?php echo json_encode($milestones_options); ?>;
    const status_statusActionOptions = <?php echo json_encode($status_action_options); ?>;
    const status_companySettings = <?php echo json_encode($company_settings); ?>;
    const status_csrfToken = '<?php echo $_SESSION['csrf_token']; ?>';
    
    // Store original row data for cancel functionality
    let status_originalRowData = {};
    
    /**
     * Edit row - Convert to edit mode with visible Save/Cancel buttons
     */
    function status_editRow(rowId) {
        const row = document.getElementById(`status_row_${rowId}`);
        if (!row) return;
        
        // Store original values
        const milestonesCell = row.querySelector('.status-milestones-cell');
        const statusActionCell = row.querySelector('.status-status-action-cell');
        const notesCell = row.querySelector('.status-notes-cell');
        
        const originalMilestones = milestonesCell.querySelector('.status-milestones-display').textContent;
        const originalStatusAction = statusActionCell.querySelector('.status-status-action-display').textContent;
        const originalNotes = notesCell.querySelector('.status-notes-display').textContent;
        
        status_originalRowData[rowId] = {
            milestones: originalMilestones,
            statusAction: originalStatusAction,
            notes: originalNotes
        };
        
        // Create dropdown for milestones
        const milestonesSelect = document.createElement('select');
        milestonesSelect.className = 'status-edit-select';
        milestonesSelect.id = `status_milestones_${rowId}`;
        
        for (const [value, label] of Object.entries(status_milestonesOptions)) {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = label;
            if (value === originalMilestones) {
                option.selected = true;
            }
            milestonesSelect.appendChild(option);
        }
        
        // Create dropdown for status action
        const statusActionSelect = document.createElement('select');
        statusActionSelect.className = 'status-edit-select';
        statusActionSelect.id = `status_status_action_${rowId}`;
        
        for (const [value, label] of Object.entries(status_statusActionOptions)) {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = label;
            if (value === originalStatusAction) {
                option.selected = true;
            }
            statusActionSelect.appendChild(option);
        }
        
        // Create textarea for notes (resizable both directions)
        const notesTextarea = document.createElement('textarea');
        notesTextarea.className = 'status-edit-textarea';
        notesTextarea.id = `status_notes_${rowId}`;
        notesTextarea.rows = 3;
        notesTextarea.value = (originalNotes !== '<em>No notes</em>' && originalNotes !== 'No notes') ? originalNotes : '';
        notesTextarea.placeholder = status_translations[currentStatusLang].notesPlaceholder || 'Enter additional notes...';
        
        // Replace content with edit controls
        milestonesCell.innerHTML = '';
        milestonesCell.appendChild(milestonesSelect);
        
        statusActionCell.innerHTML = '';
        statusActionCell.appendChild(statusActionSelect);
        
        notesCell.innerHTML = '';
        notesCell.appendChild(notesTextarea);
        
        // ============================================================
        // FIX: Update actions column with clearly visible Save/Cancel buttons
        // ============================================================
        const actionsCell = row.querySelector('td:last-child .status-actions');
        actionsCell.innerHTML = `
            <button onclick="status_saveEdit(${rowId})" class="status-action-btn status-action-save">
                <i class="fas fa-save"></i> <span data-status-lang="save">Save</span>
            </button>
            <button onclick="status_cancelEdit(${rowId})" class="status-action-btn status-action-cancel">
                <i class="fas fa-times"></i> <span data-status-lang="cancel">Cancel</span>
            </button>
            <button onclick="status_openModal(${rowId})" class="status-action-btn status-action-view">
                <i class="fas fa-eye"></i> <span data-status-lang="view">View</span>
            </button>
        `;
        
        // Update language for new elements
        updateStatusLanguage(currentStatusLang);
        
        // Scroll the actions column into view if needed (ensures buttons are visible)
        setTimeout(() => {
            actionsCell.closest('td')?.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'end' });
        }, 100);
    }
    
    /**
     * Cancel edit - Restore original values
     */
    function status_cancelEdit(rowId) {
        const row = document.getElementById(`status_row_${rowId}`);
        if (!row) return;
        
        const original = status_originalRowData[rowId];
        if (!original) return;
        
        // Restore milestones display
        const milestonesCell = row.querySelector('.status-milestones-cell');
        milestonesCell.innerHTML = `<span class="status-milestones-display">${escapeHtml(original.milestones)}</span>`;
        
        // Restore status action display
        const statusActionCell = row.querySelector('.status-status-action-cell');
        statusActionCell.innerHTML = `<span class="status-status-action-display">${escapeHtml(original.statusAction)}</span>`;
        
        // Restore notes display
        const notesCell = row.querySelector('.status-notes-cell');
        const notesDisplay = original.notes !== '<em>No notes</em>' && original.notes !== 'No notes' && original.notes ? original.notes : '';
        notesCell.innerHTML = `<div class="status-notes-display">${notesDisplay ? escapeHtml(notesDisplay).replace(/\n/g, '<br>') : '<em>No notes</em>'}</div>`;
        
        // Restore actions
        const actionsCell = row.querySelector('td:last-child .status-actions');
        actionsCell.innerHTML = `
            <?php if (canEdit($conn, $user_role_id, $module_name)): ?>
            <button onclick="status_editRow(${rowId})" class="status-action-btn status-action-edit">
                <i class="fas fa-edit"></i> <span data-status-lang="edit">Edit</span>
            </button>
            <?php endif; ?>
            <button onclick="status_openModal(${rowId})" class="status-action-btn status-action-view">
                <i class="fas fa-eye"></i> <span data-status-lang="view">View</span>
            </button>
        `;
        
        delete status_originalRowData[rowId];
        
        // Update language
        updateStatusLanguage(currentStatusLang);
    }
    
    /**
     * Save edit - Submit changes via AJAX
     */
    function status_saveEdit(rowId) {
        const milestones = document.getElementById(`status_milestones_${rowId}`).value;
        const statusAction = document.getElementById(`status_status_action_${rowId}`).value;
        const notes = document.getElementById(`status_notes_${rowId}`).value;
        
        // Create form data
        const formData = new FormData();
        formData.append('status_update', '1');
        formData.append('id', rowId);
        formData.append('milestones', milestones);
        formData.append('status_action', statusAction);
        formData.append('notes', notes);
        formData.append('csrf_token', status_csrfToken);
        
        // Show loading state on save button
        const saveBtn = document.querySelector(`#status_row_${rowId} .status-action-save`);
        if (saveBtn) {
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<div class="status-loading" style="width: 16px; height: 16px;"></div>';
        }
        
        // Send AJAX request
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(() => {
            // Reload the page to reflect changes
            window.location.reload();
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while saving. Please try again.');
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.innerHTML = '<i class="fas fa-save"></i> <span data-status-lang="save">Save</span>';
            }
        });
    }
    
    /**
     * Escape HTML to prevent XSS
     */
    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&<>]/g, function(m) {
            if (m === '&') return '&amp;';
            if (m === '<') return '&lt;';
            if (m === '>') return '&gt;';
            return m;
        });
    }
    
    /**
     * Open modal with project details
     */
    function status_openModal(rowId) {
        // Fetch row data from table
        const row = document.getElementById(`status_row_${rowId}`);
        if (!row) return;
        
        const cells = row.querySelectorAll('td');
        const invoiceId = cells[0]?.textContent.trim() || '';
        const contractNumber = cells[1]?.textContent.trim() || '';
        const clientName = cells[2]?.textContent.trim() || '';
        const projectType = cells[3]?.textContent.trim() || '';
        const milestones = cells[4]?.querySelector('.status-milestones-display')?.textContent.trim() || 'Not set';
        const statusAction = cells[5]?.querySelector('.status-status-action-display')?.textContent.trim() || 'Not set';
        const timeline = cells[6]?.textContent.trim() || '';
        const staffList = cells[7]?.textContent.trim() || 'No staff assigned';
        const notes = cells[8]?.querySelector('.status-notes-display')?.textContent.trim() || '';
        const preparedBy = cells[9]?.textContent.trim() || '';
        const lastUpdated = cells[10]?.textContent.trim() || 'Never';
        
        const lang = currentStatusLang;
        const t = status_translations[lang];
        const now = new Date();
        const datePrinted = now.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) + 
                           ' ' + now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
        
        // Parse staff list for modal display
        const staffNames = staffList !== 'No staff assigned' ? staffList.split(', ').map(n => ({ name: n })) : [];
        
        let staffHtml = '';
        if (staffNames.length > 0) {
            staffHtml = `
                <table class="status-modal-invoice-staff-table">
                    <thead>
                        <tr><th>#</th><th>Staff Name</th></tr>
                    </thead>
                    <tbody>
                        ${staffNames.map((staff, idx) => `
                            <tr>
                                <td>${idx + 1}</td>
                                <td>${escapeHtml(staff.name)}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            `;
        } else {
            staffHtml = '<p style="text-align: center; color: #666;">No staff assigned</p>';
        }
        
        const modalHtml = `
            <div class="status-modal-invoice">
                <div class="status-modal-invoice-header">
                    ${status_companySettings.logo_url ? `<img src="${status_companySettings.logo_url}" alt="Logo" class="status-modal-invoice-logo">` : ''}
                    <div class="status-modal-invoice-company-name">${escapeHtml(status_companySettings.company_name)}</div>
                    <div class="status-modal-invoice-company-details">
                        ${escapeHtml(status_companySettings.company_address)}<br>
                        ${escapeHtml(status_companySettings.company_email)} | ${escapeHtml(status_companySettings.company_phone)} | TIN: ${escapeHtml(status_companySettings.company_tin)}
                    </div>
                </div>
                
                <div class="status-modal-invoice-title">${t.invoiceTitle || 'PROJECT STATUS REPORT'}</div>
                
                <div class="status-modal-invoice-section">
                    <div class="status-modal-invoice-section-title">${t.operationInformation || 'PROJECT INFORMATION'}</div>
                    <div class="status-modal-invoice-section-content">
                        <div class="status-modal-invoice-row">
                            <span class="status-modal-invoice-label">Invoice ID:</span>
                            <span class="status-modal-invoice-value">${escapeHtml(invoiceId)}</span>
                        </div>
                        <div class="status-modal-invoice-row">
                            <span class="status-modal-invoice-label">Contract Number:</span>
                            <span class="status-modal-invoice-value">${escapeHtml(contractNumber)}</span>
                        </div>
                        <div class="status-modal-invoice-row">
                            <span class="status-modal-invoice-label">${t.projectType || 'Project Type'}:</span>
                            <span class="status-modal-invoice-value">${escapeHtml(projectType)}</span>
                        </div>
                        <div class="status-modal-invoice-row">
                            <span class="status-modal-invoice-label">${t.milestonesLabel || 'Milestones'}:</span>
                            <span class="status-modal-invoice-value">${escapeHtml(milestones)}</span>
                        </div>
                        <div class="status-modal-invoice-row">
                            <span class="status-modal-invoice-label">${t.statusActionLabel || 'Status Action'}:</span>
                            <span class="status-modal-invoice-value">${escapeHtml(statusAction)}</span>
                        </div>
                    </div>
                </div>
                
                <div class="status-modal-invoice-section">
                    <div class="status-modal-invoice-section-title">${t.clientInformation || 'CLIENT DETAILS'}</div>
                    <div class="status-modal-invoice-section-content">
                        <div class="status-modal-invoice-row">
                            <span class="status-modal-invoice-label">Client Name:</span>
                            <span class="status-modal-invoice-value">${escapeHtml(clientName)}</span>
                        </div>
                    </div>
                </div>
                
                <div class="status-modal-invoice-section">
                    <div class="status-modal-invoice-section-title">${t.projectTimeline || 'PROJECT TIMELINE'}</div>
                    <div class="status-modal-invoice-section-content">
                        <div class="status-modal-invoice-row">
                            <span class="status-modal-invoice-label">Timeline:</span>
                            <span class="status-modal-invoice-value">${escapeHtml(timeline)}</span>
                        </div>
                    </div>
                </div>
                
                <div class="status-modal-invoice-section">
                    <div class="status-modal-invoice-section-title">${t.notesLabel || 'Notes'}</div>
                    <div class="status-modal-invoice-section-content">
                        <div class="status-modal-invoice-row">
                            <span class="status-modal-invoice-value" style="width: 100%; text-align: left;">${notes ? escapeHtml(notes).replace(/\n/g, '<br>') : '<em>No notes</em>'}</span>
                        </div>
                    </div>
                </div>
                
                <div class="status-modal-invoice-staff">
                    <strong>${t.staffAllocatedLabel || 'Staff Assigned'}:</strong><br>
                    ${staffHtml}
                </div>
                
                <div class="status-modal-invoice-footer">
                    ${t.generatedBy || 'Generated by'}: ${escapeHtml(preparedBy)}<br>
                    ${t.datePrinted || 'Date Printed'}: ${datePrinted}<br>
                    <small>Last Updated: ${escapeHtml(lastUpdated)}</small>
                </div>
            </div>
        `;
        
        document.getElementById('status_modalBody').innerHTML = modalHtml;
        document.getElementById('status_modal').classList.add('active');
        
        // Update modal translations
        const modalLabels = document.querySelectorAll('[data-modal-lang]');
        modalLabels.forEach(el => {
            const key = el.getAttribute('data-modal-lang');
            if (status_translations[lang] && status_translations[lang][key]) {
                el.textContent = status_translations[lang][key];
            }
        });
    }
    
    /**
     * Close modal
     */
    function status_closeModal() {
        document.getElementById('status_modal').classList.remove('active');
        document.getElementById('status_modalBody').innerHTML = '';
    }
    
    /**
     * Print modal content
     */
    function status_printModal() {
        const modalBody = document.getElementById('status_modalBody');
        if (!modalBody) return;
        
        const invoiceContent = modalBody.innerHTML;
        if (!invoiceContent) return;
        
        const lang = currentStatusLang;
        const t = status_translations[lang];
        
        const printWindow = window.open('', '_blank');
        if (!printWindow) {
            alert('Please allow pop-ups to print the report.');
            return;
        }
        
        printWindow.document.write(`
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>${t.invoiceTitle || 'PROJECT STATUS REPORT'}</title>
                <style>
                    * { margin: 0; padding: 0; box-sizing: border-box; }
                    body {
                        font-family: 'Inter', 'Segoe UI', Arial, sans-serif;
                        background: white;
                        margin: 0;
                        padding: 20px;
                        color: #1e293b;
                    }
                    .print-container { max-width: 1000px; margin: 0 auto; background: white; }
                    .status-modal-invoice { background: white; }
                    .status-modal-invoice-header {
                        text-align: center;
                        padding: 15px 20px;
                        border-bottom: 2px solid #3e2b1f;
                        margin-bottom: 15px;
                    }
                    .status-modal-invoice-logo { max-width: 70px; max-height: 70px; margin-bottom: 10px; }
                    .status-modal-invoice-company-name {
                        font-size: 20px;
                        font-weight: 700;
                        color: #3e2b1f;
                        margin-bottom: 4px;
                    }
                    .status-modal-invoice-company-details {
                        font-size: 10px;
                        color: #64748b;
                        line-height: 1.4;
                    }
                    .status-modal-invoice-title {
                        text-align: center;
                        font-size: 16px;
                        font-weight: 700;
                        color: #3e2b1f;
                        margin: 15px 0;
                        padding: 8px;
                        background: #f0e9e2;
                        letter-spacing: 1px;
                    }
                    .status-modal-invoice-section {
                        margin-bottom: 15px;
                        border: 1px solid #e2e8f0;
                        border-radius: 8px;
                        overflow: hidden;
                    }
                    .status-modal-invoice-section-title {
                        background: #f1f5f9;
                        padding: 8px 12px;
                        font-weight: 700;
                        color: #3e2b1f;
                        border-bottom: 1px solid #e2e8f0;
                        font-size: 12px;
                    }
                    .status-modal-invoice-section-content { padding: 10px 12px; }
                    .status-modal-invoice-row {
                        display: flex;
                        justify-content: space-between;
                        padding: 4px 0;
                        border-bottom: 1px dashed #e2e8f0;
                        font-size: 11px;
                    }
                    .status-modal-invoice-row:last-child { border-bottom: none; }
                    .status-modal-invoice-label {
                        font-weight: 600;
                        color: #475569;
                        width: 40%;
                    }
                    .status-modal-invoice-value {
                        color: #1e293b;
                        width: 60%;
                        text-align: right;
                    }
                    .status-modal-invoice-staff-table {
                        width: 100%;
                        border-collapse: collapse;
                        margin-top: 10px;
                        font-size: 10px;
                    }
                    .status-modal-invoice-staff-table th {
                        background: #e2e8f0;
                        padding: 6px;
                        text-align: left;
                    }
                    .status-modal-invoice-staff-table td {
                        padding: 4px 6px;
                        border-bottom: 1px solid #e2e8f0;
                    }
                    .status-modal-invoice-staff {
                        background: #f0e9e2;
                        padding: 10px;
                        border-radius: 8px;
                        text-align: center;
                        margin-top: 12px;
                        font-size: 11px;
                    }
                    .status-modal-invoice-footer {
                        margin-top: 15px;
                        padding-top: 10px;
                        border-top: 1px solid #e2e8f0;
                        text-align: center;
                        font-size: 9px;
                        color: #64748b;
                    }
                    @media print {
                        body { margin: 0; padding: 0; }
                        .print-container { max-width: 100%; margin: 0; }
                        @page { size: A4 landscape; margin: 0.4in; }
                        .status-modal-invoice-header { padding: 10px 15px; margin-bottom: 10px; }
                        .status-modal-invoice-title { margin: 10px 0; padding: 6px; font-size: 14px; }
                        .status-modal-invoice-section { margin-bottom: 10px; }
                        .status-modal-invoice-section-content { padding: 8px 10px; }
                        .status-modal-invoice-row { padding: 3px 0; font-size: 10px; }
                        .status-modal-invoice-staff { padding: 8px; margin-top: 10px; font-size: 10px; }
                        .status-modal-invoice-footer { margin-top: 10px; padding-top: 8px; font-size: 8px; }
                    }
                </style>
            </head>
            <body>
                <div class="print-container">
                    ${invoiceContent}
                </div>
                <script>
                    window.onload = function() {
                        window.print();
                        window.onafterprint = function() { window.close(); };
                        setTimeout(function() { window.close(); }, 3000);
                    };
                <\/script>
            </body>
            </html>
        `);
        printWindow.document.close();
    }
    
    // Close modal when clicking overlay
    document.getElementById('status_modal')?.addEventListener('click', function(e) {
        if (e.target === this) {
            status_closeModal();
        }
    });
    
    // ============================================================================
    // SCROLL HINT DETECTION - Shows hint when table overflows horizontally
    // ============================================================================
    function status_checkTableOverflow() {
        const container = document.querySelector('.status-table-container');
        const hint = document.getElementById('status_scrollHint');
        if (container && hint) {
            // Check if content width exceeds container width
            if (container.scrollWidth > container.clientWidth + 5) { // +5 for small tolerance
                hint.classList.add('visible');
            } else {
                hint.classList.remove('visible');
            }
        }
    }
    
    // Check on load, resize, and when table content changes
    window.addEventListener('load', function() {
        status_checkTableOverflow();
        // Also check after a short delay for any layout shifts
        setTimeout(status_checkTableOverflow, 100);
    });
    window.addEventListener('resize', status_checkTableOverflow);
    
    // Watch for table content changes (filtering, pagination, edit mode)
    const statusObserver = new MutationObserver(function() {
        status_checkTableOverflow();
    });
    
    const statusTableBody = document.getElementById('status_tableBody');
    if (statusTableBody) {
        statusObserver.observe(statusTableBody, { childList: true, subtree: true, attributes: true });
    }
    
    // Also observe the container for any changes
    const statusContainer = document.querySelector('.status-table-container');
    if (statusContainer) {
        statusObserver.observe(statusContainer, { attributes: true, attributeFilter: ['style'] });
    }
    
    // Refresh button
    document.getElementById('status_refreshBtn')?.addEventListener('click', function() {
        window.location.reload();
    });
    
    // Auto-hide alerts
    setTimeout(() => {
        document.querySelectorAll('.status-alert').forEach(alert => {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(() => alert.style.display = 'none', 500);
        });
    }, 5000);
    
    // Initialize language and scroll detection on page load
    document.addEventListener('DOMContentLoaded', function() {
        updateStatusLanguage(currentStatusLang);
        status_checkTableOverflow();
    });
</script>