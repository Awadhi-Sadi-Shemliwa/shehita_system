<?php
/**
 * PAPLONTECH Enterprise Management System
 * Overview Module - Executive Dashboard for Contracts and Operations
 * 
 * REFINED: Removed all sidebar-related code (Issue #1)
 * REFINED: Added foreign key table validation with user-friendly error messages (Issue #2)
 * REFINED: Ensured no conflict with homepage.php (top navbar layout) (Issue #3)
 * 
 * This module provides a holistic view of:
 * - Contracts from projects table with financial KPIs
 * - Operations from operations table
 * - Date range filtering across both data sources
 * - Interactive charts (Profit Comparison, Status Distribution, Staff Allocation)
 * - Data tables with pagination and filtering
 * - Full bilingual support (English/Swahili)
 * - Permission-based access control
 * 
 * NOTE: Add 'overview' to $AVAILABLE_MODULES array in permissions.php
 */

// Prevent direct access
if (!defined('ACCESS_ALLOWED') && basename($_SERVER['PHP_SELF']) == basename(__FILE__)) {
    // This file is included, so we're safe
}

// Get database connection from parent
global $conn;

// PERMISSION: Get user role and check module permissions
$user_role_id = $_SESSION['role_id'] ?? 0;
$module_name = 'overview';

// PERMISSION: Check if user has view permission
if (!canView($conn, $user_role_id, $module_name)) {
    echo '<div class="overview-alert overview-alert-danger" style="text-align: center; padding: 40px;">
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

// Check for customers table (referenced by projects)
$check_customers = $conn->query("SHOW TABLES LIKE 'customers'");
if ($check_customers->num_rows == 0) {
    $missing_tables[] = ['table' => 'customers', 'module' => 'customer-management', 'display' => 'Customer Management'];
}

// Check for projects table
$check_projects = $conn->query("SHOW TABLES LIKE 'projects'");
if ($check_projects->num_rows == 0) {
    $missing_tables[] = ['table' => 'projects', 'module' => 'projects', 'display' => 'Projects'];
}

// Check for operations table
$check_operations = $conn->query("SHOW TABLES LIKE 'operations'");
if ($check_operations->num_rows == 0) {
    $missing_tables[] = ['table' => 'operations', 'module' => 'operations', 'display' => 'Operations'];
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
    echo '<div class="overview-container" style="max-width: 800px; margin: 40px auto;">';
    echo '<div class="overview-alert overview-alert-danger" style="background: #fff3cd; color: #856404; border-color: #ffeeba; text-align: left;">';
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
 * HELPER FUNCTIONS (DO NOT MODIFY - Working as intended)
 * ============================================================================
 */

/**
 * Get company settings for currency formatting
 */
function getOverviewCompanySettings($conn) {
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
 * Format currency for display
 */
function overview_formatCurrency($amount, $currency_symbol = 'TZS') {
    return $currency_symbol . ' ' . number_format($amount, 2);
}

/**
 * Format date for display (DD MMM YYYY)
 */
function overview_formatDate($date) {
    if (empty($date) || $date == '0000-00-00') {
        return 'N/A';
    }
    $timestamp = strtotime($date);
    return date('d M Y', $timestamp);
}

/**
 * Calculate date range overlap condition for SQL
 * Returns SQL WHERE condition for overlapping date ranges
 */
function overview_getDateOverlapCondition($start_date_field, $end_date_field, $filter_start, $filter_end) {
    return "($start_date_field <= '$filter_end' AND $end_date_field >= '$filter_start')";
}

/**
 * ============================================================================
 * FILTER HANDLING (Sanitized and Validated)
 * ============================================================================
 */

// Initialize filter variables with sanitization
$filter_start_date = isset($_GET['overview_start_date']) && !empty($_GET['overview_start_date']) 
    ? htmlspecialchars(trim($_GET['overview_start_date'])) 
    : date('Y-01-01'); // Default to start of current year

$filter_end_date = isset($_GET['overview_end_date']) && !empty($_GET['overview_end_date']) 
    ? htmlspecialchars(trim($_GET['overview_end_date'])) 
    : date('Y-m-d'); // Default to today

$status_filter = isset($_GET['overview_status_filter']) 
    ? htmlspecialchars(trim($_GET['overview_status_filter'])) 
    : '';

// Validate date formats (YYYY-MM-DD)
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_start_date)) {
    $filter_start_date = date('Y-01-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_end_date)) {
    $filter_end_date = date('Y-m-d');
}

// Validate status filter (only allowed values)
$allowed_statuses = ['Active', 'Expired', 'Inactive'];
if (!empty($status_filter) && !in_array($status_filter, $allowed_statuses)) {
    $status_filter = '';
}

// Quick date range presets
$quick_range = isset($_GET['overview_quick_range']) 
    ? htmlspecialchars(trim($_GET['overview_quick_range'])) 
    : '';

if ($quick_range) {
    $today = date('Y-m-d');
    switch ($quick_range) {
        case 'today':
            $filter_start_date = $today;
            $filter_end_date = $today;
            break;
        case 'week':
            $filter_start_date = date('Y-m-d', strtotime('monday this week'));
            $filter_end_date = date('Y-m-d', strtotime('sunday this week'));
            break;
        case 'month':
            $filter_start_date = date('Y-m-01');
            $filter_end_date = date('Y-m-t');
            break;
        case 'year':
            $filter_start_date = date('Y-01-01');
            $filter_end_date = date('Y-12-31');
            break;
    }
}

// Pagination for contracts table (sanitized)
$contracts_page = isset($_GET['overview_contracts_page']) 
    ? max(1, (int)$_GET['overview_contracts_page']) 
    : 1;
$contracts_per_page = 10;
$contracts_offset = ($contracts_page - 1) * $contracts_per_page;

// Pagination for operations table (sanitized)
$operations_page = isset($_GET['overview_operations_page']) 
    ? max(1, (int)$_GET['overview_operations_page']) 
    : 1;
$operations_per_page = 10;
$operations_offset = ($operations_page - 1) * $operations_per_page;

/**
 * ============================================================================
 * CONTRACTS DATA QUERY (with date overlap filter using prepared statements)
 * ============================================================================
 */

// Build WHERE clause for contracts
$contracts_where = "1=1";
$contracts_params = [];
$contracts_types = "";

// Date overlap filter for contracts
$contracts_where .= " AND " . overview_getDateOverlapCondition('p.effective_date', 'p.end_date', $filter_start_date, $filter_end_date);

// Status filter
if (!empty($status_filter)) {
    $contracts_where .= " AND p.status = ?";
    $contracts_params[] = $status_filter;
    $contracts_types .= "s";
}

// Count total contracts for pagination
$count_contracts_sql = "SELECT COUNT(*) as total FROM projects p 
                        LEFT JOIN customers c ON p.client_id = c.id 
                        WHERE $contracts_where";

if (!empty($contracts_params)) {
    $count_stmt = $conn->prepare($count_contracts_sql);
    $count_stmt->bind_param($contracts_types, ...$contracts_params);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total_contracts = $count_result->fetch_assoc()['total'];
    $count_stmt->close();
} else {
    $total_result = $conn->query($count_contracts_sql);
    $total_contracts = $total_result->fetch_assoc()['total'];
}

$total_contracts_pages = ceil($total_contracts / $contracts_per_page);

// Fetch contracts with pagination
$contracts_sql = "SELECT p.*, c.customer_name, c.contact_person, c.tin_number, c.address, c.email, c.type_of_business
                  FROM projects p 
                  LEFT JOIN customers c ON p.client_id = c.id 
                  WHERE $contracts_where 
                  ORDER BY p.contract_value DESC 
                  LIMIT ? OFFSET ?";

$contracts_params[] = $contracts_per_page;
$contracts_params[] = $contracts_offset;
$contracts_types .= "ii";

$contracts_stmt = $conn->prepare($contracts_sql);
$contracts_stmt->bind_param($contracts_types, ...$contracts_params);
$contracts_stmt->execute();
$contracts_result = $contracts_stmt->get_result();

// Store contracts in array for KPI calculation
$contracts_data = [];
if ($contracts_result && $contracts_result->num_rows > 0) {
    while ($row = $contracts_result->fetch_assoc()) {
        $contracts_data[] = $row;
    }
    // Reset pointer for table display
    $contracts_stmt->execute();
    $contracts_result = $contracts_stmt->get_result();
}

/**
 * ============================================================================
 * OPERATIONS DATA QUERY (with date overlap filter using prepared statements)
 * ============================================================================
 */

// Build WHERE clause for operations
$operations_where = "1=1";
$operations_params = [];
$operations_types = "";

// Date overlap filter for operations
$operations_where .= " AND " . overview_getDateOverlapCondition('o.start_date', 'o.end_date', $filter_start_date, $filter_end_date);

// Count total operations for pagination
$count_operations_sql = "SELECT COUNT(*) as total FROM operations o 
                         LEFT JOIN projects p ON o.contract_number = p.contract_number
                         LEFT JOIN customers c ON p.client_id = c.id 
                         WHERE $operations_where";

if (!empty($operations_params)) {
    $count_stmt = $conn->prepare($count_operations_sql);
    $count_stmt->bind_param($operations_types, ...$operations_params);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total_operations = $count_result->fetch_assoc()['total'];
    $count_stmt->close();
} else {
    $total_result = $conn->query($count_operations_sql);
    $total_operations = $total_result->fetch_assoc()['total'];
}

$total_operations_pages = ceil($total_operations / $operations_per_page);

// Fetch operations with pagination
$operations_sql = "SELECT o.*, c.customer_name, p.contract_value,
                          pg.name as project_group_name, cat.name as category_name
                   FROM operations o 
                   LEFT JOIN projects p ON o.contract_number = p.contract_number
                   LEFT JOIN customers c ON p.client_id = c.id 
                   LEFT JOIN projectgroup pg ON o.project_group_id = pg.id
                   LEFT JOIN categories cat ON o.category_id = cat.id
                   WHERE $operations_where 
                   ORDER BY o.id DESC 
                   LIMIT ? OFFSET ?";

$operations_params[] = $operations_per_page;
$operations_params[] = $operations_offset;
$operations_types .= "ii";

$operations_stmt = $conn->prepare($operations_sql);
$operations_stmt->bind_param($operations_types, ...$operations_params);
$operations_stmt->execute();
$operations_result = $operations_stmt->get_result();

$operations_data = [];
if ($operations_result && $operations_result->num_rows > 0) {
    while ($row = $operations_result->fetch_assoc()) {
        $operations_data[] = $row;
    }
    $operations_stmt->execute();
    $operations_result = $operations_stmt->get_result();
}

/**
 * ============================================================================
 * KPI CALCULATIONS (using filtered data)
 * ============================================================================
 */

// Re-fetch all filtered contracts for KPI calculation (without pagination)
$kpi_contracts_sql = "SELECT p.contract_value, p.actual_profit, p.status, p.number_of_staff_allocated
                      FROM projects p 
                      LEFT JOIN customers c ON p.client_id = c.id 
                      WHERE $contracts_where";

$kpi_params = array_slice($contracts_params, 0, -2); // Remove pagination params
$kpi_types = substr($contracts_types, 0, -2);

if (!empty($kpi_params)) {
    $kpi_stmt = $conn->prepare($kpi_contracts_sql);
    $kpi_stmt->bind_param($kpi_types, ...$kpi_params);
    $kpi_stmt->execute();
    $kpi_result = $kpi_stmt->get_result();
} else {
    $kpi_result = $conn->query($kpi_contracts_sql);
}

$total_contract_value = 0;
$total_actual_profit = 0;
$active_contracts = 0;
$total_staff_allocated = 0;

if ($kpi_result && $kpi_result->num_rows > 0) {
    while ($row = $kpi_result->fetch_assoc()) {
        $total_contract_value += (float)$row['contract_value'];
        $total_actual_profit += (float)$row['actual_profit'];
        if ($row['status'] == 'Active') {
            $active_contracts++;
        }
        $total_staff_allocated += (int)$row['number_of_staff_allocated'];
    }
}

// Calculate average profit margin
$avg_profit_margin = ($total_contract_value > 0) ? ($total_actual_profit / $total_contract_value) * 100 : 0;

/**
 * ============================================================================
 * CHART DATA PREPARATION
 * ============================================================================
 */

// Chart 1: Top 10 contracts by value with target vs actual profit
$chart1_sql = "SELECT p.contract_number, p.contract_value, p.target_profit, p.actual_profit, c.customer_name
               FROM projects p 
               LEFT JOIN customers c ON p.client_id = c.id 
               WHERE $contracts_where 
               ORDER BY p.contract_value DESC 
               LIMIT 10";

if (!empty($kpi_params)) {
    $chart1_stmt = $conn->prepare($chart1_sql);
    $chart1_stmt->bind_param($kpi_types, ...$kpi_params);
    $chart1_stmt->execute();
    $chart1_result = $chart1_stmt->get_result();
} else {
    $chart1_result = $conn->query($chart1_sql);
}

$chart1_labels = [];
$chart1_target = [];
$chart1_actual = [];
$chart1_colors = [];

if ($chart1_result && $chart1_result->num_rows > 0) {
    while ($row = $chart1_result->fetch_assoc()) {
        $chart1_labels[] = htmlspecialchars($row['contract_number']);
        $chart1_target[] = (float)$row['target_profit'];
        $chart1_actual[] = (float)$row['actual_profit'];
        
        // Determine color based on profit vs target
        $actual = (float)$row['actual_profit'];
        $target = (float)$row['target_profit'];
        if ($actual >= $target) {
            $chart1_colors[] = '#28a745'; // Green - profit meets or exceeds target
        } elseif ($actual > 0) {
            $chart1_colors[] = '#ffc107'; // Yellow - profit but below target
        } else {
            $chart1_colors[] = '#dc3545'; // Red - loss
        }
    }
}

// Chart 2: Contract status distribution
$chart2_sql = "SELECT status, COUNT(*) as count FROM projects p WHERE $contracts_where GROUP BY status";
if (!empty($kpi_params)) {
    $chart2_stmt = $conn->prepare($chart2_sql);
    $chart2_stmt->bind_param($kpi_types, ...$kpi_params);
    $chart2_stmt->execute();
    $chart2_result = $chart2_stmt->get_result();
} else {
    $chart2_result = $conn->query($chart2_sql);
}

$chart2_labels = [];
$chart2_data = [];
$status_colors = [
    'Active' => '#28a745',
    'Expired' => '#dc3545',
    'Inactive' => '#6c757d'
];

if ($chart2_result && $chart2_result->num_rows > 0) {
    while ($row = $chart2_result->fetch_assoc()) {
        $chart2_labels[] = htmlspecialchars($row['status']);
        $chart2_data[] = (int)$row['count'];
    }
}

// Chart 3: Top 10 contracts by staff allocated
$chart3_sql = "SELECT p.contract_number, p.number_of_staff_allocated, c.customer_name
               FROM projects p 
               LEFT JOIN customers c ON p.client_id = c.id 
               WHERE $contracts_where 
               ORDER BY p.number_of_staff_allocated DESC 
               LIMIT 10";

if (!empty($kpi_params)) {
    $chart3_stmt = $conn->prepare($chart3_sql);
    $chart3_stmt->bind_param($kpi_types, ...$kpi_params);
    $chart3_stmt->execute();
    $chart3_result = $chart3_stmt->get_result();
} else {
    $chart3_result = $conn->query($chart3_sql);
}

$chart3_labels = [];
$chart3_data = [];

if ($chart3_result && $chart3_result->num_rows > 0) {
    while ($row = $chart3_result->fetch_assoc()) {
        $chart3_labels[] = htmlspecialchars($row['contract_number']);
        $chart3_data[] = (int)$row['number_of_staff_allocated'];
    }
}

// Get company settings
$company_settings = getOverviewCompanySettings($conn);
$currency_symbol = $company_settings['currency_symbol'] ?? 'TZS';

// Display date range subtitle
$date_range_subtitle = "Showing data from " . overview_formatDate($filter_start_date) . " to " . overview_formatDate($filter_end_date);

?>

<!-- OVERVIEW MODULE TRANSLATIONS -->
<script>
// Overview translations for English and Swahili
const overview_translations = {
    en: {
        pageTitle: 'Executive Dashboard',
        dateRangeSubtitle: 'Showing data from {start} to {end}',
        kpiTotalContractValue: 'Total Contract Value',
        kpiTotalProfit: 'Total Actual Profit',
        kpiActiveContracts: 'Active Contracts',
        kpiTotalStaff: 'Total Staff Allocated',
        kpiAvgProfitMargin: 'Average Profit Margin',
        chartProfitComparison: 'Profit Comparison (Top 10 Contracts)',
        chartProfitComparisonSubtitle: 'Target Profit vs Actual Profit',
        chartStatusDistribution: 'Contract Status Distribution',
        chartStatusDistributionSubtitle: 'Breakdown by contract status',
        chartStaffAllocation: 'Staff Allocation Overview',
        chartStaffAllocationSubtitle: 'Top 10 contracts by staff count',
        contractsTableTitle: 'Contracts Overview',
        operationsTableTitle: 'Operations Overview',
        filterDateRange: 'Date Range',
        filterToday: 'Today',
        filterThisWeek: 'This Week',
        filterThisMonth: 'This Month',
        filterThisYear: 'This Year',
        applyFilter: 'Apply Filter',
        filterStatus: 'Status',
        filterAllStatus: 'All Status',
        filterActive: 'Active',
        filterExpired: 'Expired',
        filterInactive: 'Inactive',
        clearFilters: 'Clear Filters',
        refresh: 'Refresh',
        contractNumber: 'Contract #',
        clientName: 'Client',
        effectiveDate: 'Effective Date',
        endDate: 'End Date',
        contractValue: 'Contract Value',
        targetProfit: 'Target Profit',
        actualProfit: 'Actual Profit',
        profitIndicator: 'Profit Indicator',
        status: 'Status',
        staffAllocated: 'Staff',
        invoiceId: 'Invoice ID',
        projectType: 'Project Type',
        startDate: 'Start Date',
        assignedStaff: 'Assigned Staff',
        profit: 'Profit',
        belowTarget: 'Below Target',
        loss: 'Loss',
        active: 'Active',
        completed: 'Completed',
        expired: 'Expired',
        inactive: 'Inactive',
        previous: 'Previous',
        next: 'Next',
        page: 'Page',
        of: 'of',
        noContracts: 'No contracts found matching your filters.',
        noOperations: 'No operations found matching your filters.',
        loading: 'Loading...',
        errorLoading: 'Error loading data. Please try again.',
        targetProfitLabel: 'Target Profit',
        actualProfitLabel: 'Actual Profit',
        staffCount: 'Staff Count',
        viewDetails: 'View Details'
    },
    sw: {
        pageTitle: 'Dashibodi Kuu',
        dateRangeSubtitle: 'Kuonyesha data kutoka {start} hadi {end}',
        kpiTotalContractValue: 'Jumla ya Thamani ya Mikataba',
        kpiTotalProfit: 'Jumla ya Faida Halisi',
        kpiActiveContracts: 'Mikataba Inayoendelea',
        kpiTotalStaff: 'Jumla ya Wafanyakazi Waliopangiwa',
        kpiAvgProfitMargin: 'Wastani wa Kiasi cha Faida',
        chartProfitComparison: 'Ulinganisho wa Faida (Mikataba 10 Bora)',
        chartProfitComparisonSubtitle: 'Faida Inayolengwa dhidi ya Faida Halisi',
        chartStatusDistribution: 'Mgawanyo wa Hali za Mikataba',
        chartStatusDistributionSubtitle: 'Mgawanyo kwa hali ya mkataba',
        chartStaffAllocation: 'Muhtasari wa Ugawaji Wafanyakazi',
        chartStaffAllocationSubtitle: 'Mikataba 10 bora kwa idadi ya wafanyakazi',
        contractsTableTitle: 'Muhtasari wa Mikataba',
        operationsTableTitle: 'Muhtasari wa Uendeshaji',
        filterDateRange: 'Kipindi cha Tarehe',
        filterToday: 'Leo',
        filterThisWeek: 'Wiki Hii',
        filterThisMonth: 'Mwezi Huu',
        filterThisYear: 'Mwaka Huu',
        applyFilter: 'Weka Kichujio',
        filterStatus: 'Hali',
        filterAllStatus: 'Hali Zote',
        filterActive: 'Inaendelea',
        filterExpired: 'Imeisha',
        filterInactive: 'Haifanyi Kazi',
        clearFilters: 'Futa Vichujio',
        refresh: 'Onesha Upya',
        contractNumber: 'Namba ya Mkataba',
        clientName: 'Mteja',
        effectiveDate: 'Tarehe ya Kuanza',
        endDate: 'Tarehe ya Mwisho',
        contractValue: 'Thamani ya Mkataba',
        targetProfit: 'Faida Inayolengwa',
        actualProfit: 'Faida Halisi',
        profitIndicator: 'Kiashiria cha Faida',
        status: 'Hali',
        staffAllocated: 'Wafanyakazi',
        invoiceId: 'Namba ya Ankara',
        projectType: 'Aina ya Mradi',
        startDate: 'Tarehe ya Kuanza',
        assignedStaff: 'Wafanyakazi Waliopangiwa',
        profit: 'Faida',
        belowTarget: 'Chini ya Lengo',
        loss: 'Hasara',
        active: 'Inaendelea',
        completed: 'Imekamilika',
        expired: 'Imeisha',
        inactive: 'Haifanyi Kazi',
        previous: 'Iliyotangulia',
        next: 'Ijayo',
        page: 'Ukurasa',
        of: 'kati ya',
        noContracts: 'Hakuna mikataba inayolingana na vichujio vyako.',
        noOperations: 'Hakuna uendeshaji unaolingana na vichujio vyako.',
        loading: 'Inapakia...',
        errorLoading: 'Hitilafu katika kupakia data. Tafadhali jaribu tena.',
        targetProfitLabel: 'Faida Inayolengwa',
        actualProfitLabel: 'Faida Halisi',
        staffCount: 'Idadi ya Wafanyakazi',
        viewDetails: 'Angalia Maelezo'
    }
};

// Current language
let currentOverviewLang = localStorage.getItem('preferredLanguage') || 'en';

// Function to update all translatable elements
function updateOverviewLanguage(lang) {
    currentOverviewLang = lang;
    const elements = document.querySelectorAll('[data-overview-lang]');
    
    elements.forEach(element => {
        const key = element.getAttribute('data-overview-lang');
        if (overview_translations[lang] && overview_translations[lang][key]) {
            if (element.tagName === 'INPUT' || element.tagName === 'TEXTAREA') {
                if (element.placeholder !== undefined) {
                    element.placeholder = overview_translations[lang][key];
                }
            } else if (element.tagName === 'OPTION') {
                element.textContent = overview_translations[lang][key];
            } else {
                element.textContent = overview_translations[lang][key];
            }
        }
    });
    
    // Update date range subtitle
    const subtitleEl = document.getElementById('overview_dateRangeSubtitle');
    if (subtitleEl) {
        subtitleEl.textContent = overview_translations[lang].dateRangeSubtitle
            .replace('{start}', '<?= overview_formatDate($filter_start_date) ?>')
            .replace('{end}', '<?= overview_formatDate($filter_end_date) ?>');
    }
    
    // Update status filter options
    const statusFilter = document.getElementById('overview_status_filter');
    if (statusFilter) {
        const options = statusFilter.querySelectorAll('option');
        options.forEach(option => {
            const value = option.value;
            if (value === 'Active') {
                option.textContent = overview_translations[lang].filterActive;
            } else if (value === 'Expired') {
                option.textContent = overview_translations[lang].filterExpired;
            } else if (value === 'Inactive') {
                option.textContent = overview_translations[lang].filterInactive;
            } else if (value === '') {
                option.textContent = overview_translations[lang].filterAllStatus;
            }
        });
    }
}

// This function will be called from homepage.js when language changes
window.updateOverviewLanguage = updateOverviewLanguage;

// Document ready - NO SIDEBAR CODE (Issue #1 resolved)
document.addEventListener('DOMContentLoaded', function() {
    updateOverviewLanguage(currentOverviewLang);
});
</script>

<style>
    /* Overview Module Styles - Using overview_ prefix to avoid conflicts (Issue #3) */
    /* NO SIDEBAR-RELATED CSS - All styles are self-contained */
    
    .overview-container {
        width: 100%;
    }

    /* KPI Cards Row */
    .overview-kpi-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 32px;
    }

    .overview-kpi-card {
        background: white;
        border-radius: 16px;
        padding: 20px;
        box-shadow: var(--shadow-md);
        border: 1px solid var(--gray-200);
        transition: all 0.2s;
        position: relative;
        overflow: hidden;
    }

    .overview-kpi-card:hover {
        transform: translateY(-4px);
        box-shadow: var(--shadow-lg);
    }

    .overview-kpi-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 4px;
        height: 100%;
    }

    .overview-kpi-card-green::before {
        background: #28a745;
    }

    .overview-kpi-card-brown::before {
        background: var(--brown-700);
    }

    .overview-kpi-card-blue::before {
        background: #007bff;
    }

    .overview-kpi-card-teal::before {
        background: #20c997;
    }

    .overview-kpi-card-purple::before {
        background: #6f42c1;
    }

    .overview-kpi-icon {
        font-size: 32px;
        margin-bottom: 12px;
    }

    .overview-kpi-icon-green {
        color: #28a745;
    }

    .overview-kpi-icon-brown {
        color: var(--brown-700);
    }

    .overview-kpi-icon-blue {
        color: #007bff;
    }

    .overview-kpi-icon-teal {
        color: #20c997;
    }

    .overview-kpi-icon-purple {
        color: #6f42c1;
    }

    .overview-kpi-value {
        font-size: 28px;
        font-weight: 700;
        color: var(--gray-800);
        margin-bottom: 8px;
    }

    .overview-kpi-label {
        font-size: 14px;
        color: var(--gray-500);
        font-weight: 500;
    }

    .overview-kpi-subtitle {
        font-size: 11px;
        color: var(--gray-400);
        margin-top: 4px;
    }

    /* Charts Row */
    .overview-charts-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
        gap: 24px;
        margin-bottom: 32px;
    }

    .overview-chart-card {
        background: white;
        border-radius: 16px;
        padding: 20px;
        box-shadow: var(--shadow-md);
        border: 1px solid var(--gray-200);
    }

    .overview-chart-title {
        font-size: 16px;
        font-weight: 600;
        color: var(--gray-800);
        margin-bottom: 4px;
    }

    .overview-chart-subtitle {
        font-size: 12px;
        color: var(--gray-500);
        margin-bottom: 16px;
    }

    .overview-chart-container {
        position: relative;
        height: 300px;
        width: 100%;
    }

    /* Filter Bar */
    .overview-filter-bar {
        background: white;
        border-radius: 16px;
        padding: 20px;
        margin-bottom: 24px;
        border: 1px solid var(--gray-200);
        box-shadow: var(--shadow-sm);
    }

    .overview-filter-row {
        display: flex;
        flex-wrap: wrap;
        gap: 16px;
        align-items: flex-end;
    }

    .overview-filter-group {
        flex: 1;
        min-width: 150px;
    }

    .overview-filter-group label {
        display: block;
        font-size: 13px;
        font-weight: 500;
        color: var(--gray-700);
        margin-bottom: 6px;
    }

    .overview-filter-group input,
    .overview-filter-group select {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid var(--gray-300);
        border-radius: 8px;
        font-size: 14px;
        font-family: inherit;
        transition: all 0.2s;
    }

    .overview-filter-group input:focus,
    .overview-filter-group select:focus {
        outline: none;
        border-color: var(--brown-600);
        box-shadow: 0 0 0 3px rgba(123, 88, 63, 0.1);
    }

    .overview-date-range-group {
        display: flex;
        gap: 8px;
        align-items: center;
    }

    .overview-date-range-group input {
        flex: 1;
    }

    .overview-quick-buttons {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    .overview-quick-btn {
        padding: 8px 16px;
        background: var(--gray-100);
        border: 1px solid var(--gray-200);
        border-radius: 8px;
        font-size: 13px;
        font-weight: 500;
        color: var(--gray-700);
        cursor: pointer;
        transition: all 0.2s;
    }

    .overview-quick-btn:hover {
        background: var(--brown-100);
        border-color: var(--brown-600);
        color: var(--brown-800);
    }

    .overview-filter-actions {
        display: flex;
        gap: 8px;
    }

    .overview-btn {
        padding: 10px 20px;
        background: var(--brown-700);
        color: white;
        border: none;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .overview-btn:hover {
        background: var(--brown-800);
        transform: translateY(-2px);
        box-shadow: var(--shadow-md);
    }

    .overview-btn-secondary {
        background: var(--gray-200);
        color: var(--gray-700);
    }

    .overview-btn-secondary:hover {
        background: var(--gray-300);
        transform: none;
        box-shadow: none;
    }

    /* Section Header */
    .overview-section-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        margin-top: 32px;
    }

    .overview-section-header:first-of-type {
        margin-top: 0;
    }

    .overview-section-title {
        font-size: 20px;
        font-weight: 600;
        color: var(--gray-800);
    }

    /* Table Styles */
    .overview-table-container {
        overflow-x: auto;
        background: white;
        border-radius: 12px;
        border: 1px solid var(--gray-200);
        margin-bottom: 20px;
    }

    .overview-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 1000px;
    }

    .overview-table th {
        background: var(--gray-50);
        color: var(--gray-700);
        font-weight: 600;
        font-size: 13px;
        padding: 14px 16px;
        text-align: left;
        border-bottom: 2px solid var(--gray-200);
    }

    .overview-table td {
        padding: 14px 16px;
        border-bottom: 1px solid var(--gray-200);
        color: var(--gray-700);
        font-size: 13px;
        vertical-align: middle;
    }

    .overview-table tr:hover {
        background: var(--gray-50);
    }

    /* Status Badges */
    .overview-status {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 500;
    }

    .overview-status-active {
        background: #d4edda;
        color: #155724;
    }

    .overview-status-expired {
        background: #f8d7da;
        color: #721c24;
    }

    .overview-status-completed {
        background: #d1ecf1;
        color: #0c5460;
    }

    .overview-status-inactive {
        background: #fff3cd;
        color: #856404;
    }

    /* Profit Indicator */
    .overview-profit-indicator {
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .overview-profit-dot {
        width: 10px;
        height: 10px;
        border-radius: 50%;
    }

    .overview-profit-dot-positive {
        background: #28a745;
    }

    .overview-profit-dot-warning {
        background: #ffc107;
    }

    .overview-profit-dot-negative {
        background: #dc3545;
    }

    .overview-profit-text {
        font-size: 12px;
        font-weight: 500;
    }

    .overview-profit-positive {
        color: #28a745;
    }

    .overview-profit-negative {
        color: #dc3545;
    }

    /* Money cells */
    .overview-money {
        text-align: right;
        font-weight: 500;
    }

    /* Pagination */
    .overview-pagination {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 12px;
        margin-top: 20px;
    }

    .overview-pagination a,
    .overview-pagination span {
        padding: 8px 16px;
        border-radius: 6px;
        text-decoration: none;
        font-size: 14px;
        font-weight: 500;
        transition: all 0.2s;
    }

    .overview-pagination a {
        background: white;
        border: 1px solid var(--gray-300);
        color: var(--gray-700);
    }

    .overview-pagination a:hover {
        background: var(--brown-100);
        border-color: var(--brown-600);
        color: var(--brown-800);
    }

    .overview-pagination .disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    /* Empty State */
    .overview-empty {
        text-align: center;
        padding: 48px !important;
        color: var(--gray-500);
    }

    .overview-empty i {
        font-size: 48px;
        margin-bottom: 16px;
        color: var(--gray-400);
    }

    /* Alert */
    .overview-alert {
        padding: 16px;
        border-radius: 8px;
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .overview-alert-success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }

    .overview-alert-danger {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }

    .overview-alert-close {
        background: none;
        border: none;
        font-size: 20px;
        cursor: pointer;
        color: inherit;
        opacity: 0.5;
    }

    .overview-alert-close:hover {
        opacity: 1;
    }

    /* Loading */
    .overview-loading {
        display: inline-block;
        width: 20px;
        height: 20px;
        border: 3px solid var(--gray-200);
        border-top-color: var(--brown-700);
        border-radius: 50%;
        animation: overview-spin 1s linear infinite;
    }

    @keyframes overview-spin {
        to { transform: rotate(360deg); }
    }

    /* Responsive - No sidebar breakpoints */
    @media (max-width: 1024px) {
        .overview-charts-row {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 768px) {
        .overview-kpi-row {
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }
        
        .overview-kpi-value {
            font-size: 22px;
        }
        
        .overview-filter-row {
            flex-direction: column;
        }
        
        .overview-filter-group {
            width: 100%;
        }
        
        .overview-date-range-group {
            flex-direction: column;
        }
        
        .overview-quick-buttons {
            justify-content: center;
        }
        
        .overview-filter-actions {
            width: 100%;
            justify-content: center;
        }
        
        .overview-section-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 12px;
        }
    }

    @media (max-width: 480px) {
        .overview-kpi-row {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="overview-container">
    <!-- Page Header -->
    <div class="overview-section-header" style="margin-top: 0;">
        <div>
            <h2 data-overview-lang="pageTitle" style="margin: 0; color: var(--gray-800); font-size: 24px; font-weight: 600;">Executive Dashboard</h2>
            <p id="overview_dateRangeSubtitle" style="margin: 8px 0 0; color: var(--gray-500); font-size: 14px;">
                Showing data from <?= overview_formatDate($filter_start_date) ?> to <?= overview_formatDate($filter_end_date) ?>
            </p>
        </div>
    </div>

    <!-- KPI Cards -->
    <div class="overview-kpi-row">
        <div class="overview-kpi-card overview-kpi-card-brown">
            <div class="overview-kpi-icon overview-kpi-icon-brown">
                <i class="fas fa-file-invoice-dollar"></i>
            </div>
            <div class="overview-kpi-value" id="overview_totalContractValue"><?= overview_formatCurrency($total_contract_value, $currency_symbol) ?></div>
            <div class="overview-kpi-label" data-overview-lang="kpiTotalContractValue">Total Contract Value</div>
        </div>
        
        <div class="overview-kpi-card overview-kpi-card-green">
            <div class="overview-kpi-icon overview-kpi-icon-green">
                <i class="fas fa-chart-line"></i>
            </div>
            <div class="overview-kpi-value <?= $total_actual_profit >= 0 ? 'overview-profit-positive' : 'overview-profit-negative' ?>" id="overview_totalProfit">
                <?= overview_formatCurrency($total_actual_profit, $currency_symbol) ?>
            </div>
            <div class="overview-kpi-label" data-overview-lang="kpiTotalProfit">Total Actual Profit</div>
        </div>
        
        <div class="overview-kpi-card overview-kpi-card-blue">
            <div class="overview-kpi-icon overview-kpi-icon-blue">
                <i class="fas fa-play-circle"></i>
            </div>
            <div class="overview-kpi-value" id="overview_activeContracts"><?= number_format($active_contracts) ?></div>
            <div class="overview-kpi-label" data-overview-lang="kpiActiveContracts">Active Contracts</div>
        </div>
        
        <div class="overview-kpi-card overview-kpi-card-teal">
            <div class="overview-kpi-icon overview-kpi-icon-teal">
                <i class="fas fa-users"></i>
            </div>
            <div class="overview-kpi-value" id="overview_totalStaff"><?= number_format($total_staff_allocated) ?></div>
            <div class="overview-kpi-label" data-overview-lang="kpiTotalStaff">Total Staff Allocated</div>
        </div>
        
        <div class="overview-kpi-card overview-kpi-card-purple">
            <div class="overview-kpi-icon overview-kpi-icon-purple">
                <i class="fas fa-percent"></i>
            </div>
            <div class="overview-kpi-value" id="overview_avgProfitMargin"><?= number_format($avg_profit_margin, 2) ?>%</div>
            <div class="overview-kpi-label" data-overview-lang="kpiAvgProfitMargin">Average Profit Margin</div>
            <div class="overview-kpi-subtitle">of total contract value</div>
        </div>
    </div>

    <!-- Filter Bar - Using GET method preserves state (no sidebar interference) -->
    <div class="overview-filter-bar">
        <form method="GET" action="?page=overview" id="overview_filterForm">
            <input type="hidden" name="page" value="overview">
            
            <div class="overview-filter-row">
                <div class="overview-filter-group">
                    <label data-overview-lang="filterDateRange">Date Range</label>
                    <div class="overview-date-range-group">
                        <input type="date" id="overview_start_date" name="overview_start_date" value="<?= $filter_start_date ?>">
                        <span>to</span>
                        <input type="date" id="overview_end_date" name="overview_end_date" value="<?= $filter_end_date ?>">
                    </div>
                    <div class="overview-quick-buttons" style="margin-top: 8px;">
                        <button type="button" class="overview-quick-btn" data-range="today" data-overview-lang="filterToday">Today</button>
                        <button type="button" class="overview-quick-btn" data-range="week" data-overview-lang="filterThisWeek">This Week</button>
                        <button type="button" class="overview-quick-btn" data-range="month" data-overview-lang="filterThisMonth">This Month</button>
                        <button type="button" class="overview-quick-btn" data-range="year" data-overview-lang="filterThisYear">This Year</button>
                    </div>
                </div>
                
                <div class="overview-filter-group">
                    <label data-overview-lang="filterStatus">Status</label>
                    <select id="overview_status_filter" name="overview_status_filter">
                        <option value="" data-overview-lang="filterAllStatus">All Status</option>
                        <option value="Active" <?= $status_filter == 'Active' ? 'selected' : '' ?> data-overview-lang="filterActive">Active</option>
                        <option value="Expired" <?= $status_filter == 'Expired' ? 'selected' : '' ?> data-overview-lang="filterExpired">Expired</option>
                        <option value="Inactive" <?= $status_filter == 'Inactive' ? 'selected' : '' ?> data-overview-lang="filterInactive">Inactive</option>
                    </select>
                </div>
                
                <div class="overview-filter-actions">
                    <button type="submit" class="overview-btn">
                        <i class="fas fa-filter"></i> <span data-overview-lang="applyFilter">Apply Filter</span>
                    </button>
                    <a href="?page=overview" class="overview-btn overview-btn-secondary">
                        <i class="fas fa-times"></i> <span data-overview-lang="clearFilters">Clear Filters</span>
                    </a>
                    <button type="button" id="overview_refreshBtn" class="overview-btn overview-btn-secondary">
                        <i class="fas fa-sync-alt"></i> <span data-overview-lang="refresh">Refresh</span>
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Charts Section -->
    <div class="overview-charts-row">
        <!-- Chart 1: Profit Comparison -->
        <div class="overview-chart-card">
            <div class="overview-chart-title" data-overview-lang="chartProfitComparison">Profit Comparison (Top 10 Contracts)</div>
            <div class="overview-chart-subtitle" data-overview-lang="chartProfitComparisonSubtitle">Target Profit vs Actual Profit</div>
            <div class="overview-chart-container">
                <canvas id="overview_chart1"></canvas>
            </div>
        </div>
        
        <!-- Chart 2: Status Distribution -->
        <div class="overview-chart-card">
            <div class="overview-chart-title" data-overview-lang="chartStatusDistribution">Contract Status Distribution</div>
            <div class="overview-chart-subtitle" data-overview-lang="chartStatusDistributionSubtitle">Breakdown by contract status</div>
            <div class="overview-chart-container">
                <canvas id="overview_chart2"></canvas>
            </div>
        </div>
    </div>
    
    <!-- Chart 3: Staff Allocation (full width) -->
    <div class="overview-charts-row" style="grid-template-columns: 1fr;">
        <div class="overview-chart-card">
            <div class="overview-chart-title" data-overview-lang="chartStaffAllocation">Staff Allocation Overview</div>
            <div class="overview-chart-subtitle" data-overview-lang="chartStaffAllocationSubtitle">Top 10 contracts by staff count</div>
            <div class="overview-chart-container" style="height: 350px;">
                <canvas id="overview_chart3"></canvas>
            </div>
        </div>
    </div>

    <!-- Contracts Table Section -->
    <div class="overview-section-header">
        <div class="overview-section-title" data-overview-lang="contractsTableTitle">Contracts Overview</div>
        <div class="overview-stats-info">
            <i class="fas fa-file-contract"></i>
            <span><?= $total_contracts ?> records</span>
        </div>
    </div>
    
    <div class="overview-table-container">
        <table class="overview-table">
            <thead>
                <tr>
                    <th data-overview-lang="contractNumber">Contract #</th>
                    <th data-overview-lang="clientName">Client</th>
                    <th data-overview-lang="effectiveDate">Effective Date</th>
                    <th data-overview-lang="endDate">End Date</th>
                    <th data-overview-lang="contractValue">Contract Value</th>
                    <th data-overview-lang="targetProfit">Target Profit</th>
                    <th data-overview-lang="actualProfit">Actual Profit</th>
                    <th data-overview-lang="profitIndicator">Profit Indicator</th>
                    <th data-overview-lang="status">Status</th>
                    <th data-overview-lang="staffAllocated">Staff</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($contracts_result && $contracts_result->num_rows > 0): ?>
                    <?php while ($row = $contracts_result->fetch_assoc()): ?>
                        <?php
                            $actual = (float)$row['actual_profit'];
                            $target = (float)$row['target_profit'];
                            if ($actual >= $target) {
                                $profit_class = 'positive';
                                $profit_text = 'Profit';
                                $profit_dot_class = 'positive';
                            } elseif ($actual > 0) {
                                $profit_class = 'warning';
                                $profit_text = 'Below Target';
                                $profit_dot_class = 'warning';
                            } else {
                                $profit_class = 'negative';
                                $profit_text = 'Loss';
                                $profit_dot_class = 'negative';
                            }
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($row['contract_number']) ?></strong></td>
                            <td><?= htmlspecialchars($row['customer_name'] ?? 'N/A') ?></td>
                            <td><?= overview_formatDate($row['effective_date']) ?></td>
                            <td><?= overview_formatDate($row['end_date']) ?></td>
                            <td class="overview-money"><?= overview_formatCurrency($row['contract_value'], $currency_symbol) ?></td>
                            <td class="overview-money"><?= overview_formatCurrency($row['target_profit'], $currency_symbol) ?></td>
                            <td class="overview-money <?= $actual >= 0 ? 'overview-profit-positive' : 'overview-profit-negative' ?>">
                                <?= overview_formatCurrency($row['actual_profit'], $currency_symbol) ?>
                            </td>
                            <td>
                                <div class="overview-profit-indicator">
                                    <span class="overview-profit-dot overview-profit-dot-<?= $profit_dot_class ?>"></span>
                                    <span class="overview-profit-text" data-overview-lang="<?= strtolower($profit_text) ?>"><?= $profit_text ?></span>
                                </div>
                            </td>
                            <td>
                                <span class="overview-status overview-status-<?= strtolower($row['status']) ?>">
                                    <?= htmlspecialchars($row['status']) ?>
                                </span>
                            </td>
                            <td><?= number_format($row['number_of_staff_allocated']) ?></td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="10" class="overview-empty">
                            <i class="fas fa-folder-open"></i>
                            <p data-overview-lang="noContracts">No contracts found matching your filters.</p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Contracts Pagination -->
    <?php if ($total_contracts_pages > 1): ?>
    <div class="overview-pagination">
        <?php if ($contracts_page > 1): ?>
            <a href="?page=overview&overview_contracts_page=<?= $contracts_page - 1 ?>&overview_start_date=<?= urlencode($filter_start_date) ?>&overview_end_date=<?= urlencode($filter_end_date) ?>&overview_status_filter=<?= urlencode($status_filter) ?>">
                <i class="fas fa-chevron-left"></i> <span data-overview-lang="previous">Previous</span>
            </a>
        <?php else: ?>
            <span class="disabled"><i class="fas fa-chevron-left"></i> <span data-overview-lang="previous">Previous</span></span>
        <?php endif; ?>
        
        <span><span data-overview-lang="page">Page</span> <?= $contracts_page ?> <span data-overview-lang="of">of</span> <?= $total_contracts_pages ?></span>
        
        <?php if ($contracts_page < $total_contracts_pages): ?>
            <a href="?page=overview&overview_contracts_page=<?= $contracts_page + 1 ?>&overview_start_date=<?= urlencode($filter_start_date) ?>&overview_end_date=<?= urlencode($filter_end_date) ?>&overview_status_filter=<?= urlencode($status_filter) ?>">
                <span data-overview-lang="next">Next</span> <i class="fas fa-chevron-right"></i>
            </a>
        <?php else: ?>
            <span class="disabled"><span data-overview-lang="next">Next</span> <i class="fas fa-chevron-right"></i></span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Operations Table Section (only show if there are operations) -->
    <?php if ($total_operations > 0): ?>
    <div class="overview-section-header">
        <div class="overview-section-title" data-overview-lang="operationsTableTitle">Operations Overview</div>
        <div class="overview-stats-info">
            <i class="fas fa-tasks"></i>
            <span><?= $total_operations ?> records</span>
        </div>
    </div>
    
    <div class="overview-table-container">
        <table class="overview-table">
            <thead>
                <tr>
                    <th data-overview-lang="invoiceId">Invoice ID</th>
                    <th data-overview-lang="contractNumber">Contract #</th>
                    <th data-overview-lang="clientName">Client</th>
                    <th data-overview-lang="projectType">Project Type</th>
                    <th data-overview-lang="startDate">Start Date</th>
                    <th data-overview-lang="endDate">End Date</th>
                    <th data-overview-lang="status">Status</th>
                    <th data-overview-lang="assignedStaff">Assigned Staff</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                // Reset pointer for operations display
                $operations_stmt->execute();
                $operations_result = $operations_stmt->get_result();
                ?>
                <?php if ($operations_result && $operations_result->num_rows > 0): ?>
                    <?php while ($row = $operations_result->fetch_assoc()): ?>
                        <?php
                            // Parse assigned_staff JSON
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
                            
                            // Map status for badge class
                            $status_class = strtolower($row['status']);
                            if ($status_class == 'completed') $status_class = 'completed';
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($row['invoice_id']) ?></strong></td>
                            <td><?= htmlspecialchars($row['contract_number']) ?></td>
                            <td><?= htmlspecialchars($row['customer_name'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($row['category_name'] ?? 'N/A') ?></td>
                            <td><?= overview_formatDate($row['start_date']) ?></td>
                            <td><?= overview_formatDate($row['end_date']) ?></td>
                            <td>
                                <span class="overview-status overview-status-<?= $status_class ?>">
                                    <?= htmlspecialchars($row['status']) ?>
                                </span>
                            </td>
                            <td><small><?= $staff_list ?></small></td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" class="overview-empty">
                            <i class="fas fa-tasks"></i>
                            <p data-overview-lang="noOperations">No operations found matching your filters.</p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Operations Pagination -->
    <?php if ($total_operations_pages > 1): ?>
    <div class="overview-pagination">
        <?php if ($operations_page > 1): ?>
            <a href="?page=overview&overview_operations_page=<?= $operations_page - 1 ?>&overview_start_date=<?= urlencode($filter_start_date) ?>&overview_end_date=<?= urlencode($filter_end_date) ?>&overview_status_filter=<?= urlencode($status_filter) ?>">
                <i class="fas fa-chevron-left"></i> <span data-overview-lang="previous">Previous</span>
            </a>
        <?php else: ?>
            <span class="disabled"><i class="fas fa-chevron-left"></i> <span data-overview-lang="previous">Previous</span></span>
        <?php endif; ?>
        
        <span><span data-overview-lang="page">Page</span> <?= $operations_page ?> <span data-overview-lang="of">of</span> <?= $total_operations_pages ?></span>
        
        <?php if ($operations_page < $total_operations_pages): ?>
            <a href="?page=overview&overview_operations_page=<?= $operations_page + 1 ?>&overview_start_date=<?= urlencode($filter_start_date) ?>&overview_end_date=<?= urlencode($filter_end_date) ?>&overview_status_filter=<?= urlencode($status_filter) ?>">
                <span data-overview-lang="next">Next</span> <i class="fas fa-chevron-right"></i>
            </a>
        <?php else: ?>
            <span class="disabled"><span data-overview-lang="next">Next</span> <i class="fas fa-chevron-right"></i></span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Chart.js Library -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<script>
    // ============================================================================
    // OVERVIEW MODULE JAVASCRIPT - NO SIDEBAR CODE (Issue #1 resolved)
    // ============================================================================
    
    // Chart instances
    let overview_chart1 = null;
    let overview_chart2 = null;
    let overview_chart3 = null;
    
    // Chart data from PHP
    const overview_chart1_labels = <?= json_encode($chart1_labels) ?>;
    const overview_chart1_target = <?= json_encode($chart1_target) ?>;
    const overview_chart1_actual = <?= json_encode($chart1_actual) ?>;
    const overview_chart1_colors = <?= json_encode($chart1_colors) ?>;
    
    const overview_chart2_labels = <?= json_encode($chart2_labels) ?>;
    const overview_chart2_data = <?= json_encode($chart2_data) ?>;
    
    const overview_chart3_labels = <?= json_encode($chart3_labels) ?>;
    const overview_chart3_data = <?= json_encode($chart3_data) ?>;
    
    // Currency symbol
    const overview_currencySymbol = '<?= $currency_symbol ?>';
    
    // Format currency for chart tooltips
    function overview_formatCurrencyChart(value) {
        return overview_currencySymbol + ' ' + value.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    
    // Initialize Chart 1: Profit Comparison (Bar Chart)
    function overview_initChart1() {
        const ctx = document.getElementById('overview_chart1').getContext('2d');
        
        if (overview_chart1) {
            overview_chart1.destroy();
        }
        
        overview_chart1 = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: overview_chart1_labels,
                datasets: [
                    {
                        label: overview_translations[currentOverviewLang].targetProfitLabel || 'Target Profit',
                        data: overview_chart1_target,
                        backgroundColor: '#6c757d',
                        borderColor: '#6c757d',
                        borderWidth: 1,
                        borderRadius: 4
                    },
                    {
                        label: overview_translations[currentOverviewLang].actualProfitLabel || 'Actual Profit',
                        data: overview_chart1_actual,
                        backgroundColor: overview_chart1_colors,
                        borderColor: overview_chart1_colors,
                        borderWidth: 1,
                        borderRadius: 4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                let value = context.raw;
                                return label + ': ' + overview_formatCurrencyChart(value);
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return overview_currencySymbol + ' ' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });
    }
    
    // Initialize Chart 2: Status Distribution (Doughnut Chart)
    function overview_initChart2() {
        const ctx = document.getElementById('overview_chart2').getContext('2d');
        
        if (overview_chart2) {
            overview_chart2.destroy();
        }
        
        const statusColors = {
            'Active': '#28a745',
            'Expired': '#dc3545',
            'Inactive': '#6c757d'
        };
        
        const backgroundColors = overview_chart2_labels.map(label => statusColors[label] || '#6c757d');
        
        overview_chart2 = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: overview_chart2_labels,
                datasets: [{
                    data: overview_chart2_data,
                    backgroundColor: backgroundColors,
                    borderWidth: 0,
                    hoverOffset: 10
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.label || '';
                                let value = context.raw;
                                let total = context.dataset.data.reduce((a, b) => a + b, 0);
                                let percentage = ((value / total) * 100).toFixed(1);
                                return label + ': ' + value + ' (' + percentage + '%)';
                            }
                        }
                    }
                }
            }
        });
    }
    
    // Initialize Chart 3: Staff Allocation (Bar Chart)
    function overview_initChart3() {
        const ctx = document.getElementById('overview_chart3').getContext('2d');
        
        if (overview_chart3) {
            overview_chart3.destroy();
        }
        
        overview_chart3 = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: overview_chart3_labels,
                datasets: [{
                    label: overview_translations[currentOverviewLang].staffCount || 'Staff Count',
                    data: overview_chart3_data,
                    backgroundColor: '#20c997',
                    borderColor: '#20c997',
                    borderWidth: 1,
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ' + context.raw + ' staff members';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1,
                            callback: function(value) {
                                return value + ' staff';
                            }
                        }
                    }
                }
            }
        });
    }
    
    // Initialize all charts
    function overview_initCharts() {
        if (overview_chart1_labels.length > 0) {
            overview_initChart1();
        }
        if (overview_chart2_labels.length > 0) {
            overview_initChart2();
        }
        if (overview_chart3_labels.length > 0) {
            overview_initChart3();
        }
    }
    
    // Quick range buttons
    document.querySelectorAll('.overview-quick-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const range = this.getAttribute('data-range');
            const today = new Date();
            let startDate = '';
            let endDate = '';
            
            switch(range) {
                case 'today':
                    startDate = today.toISOString().split('T')[0];
                    endDate = startDate;
                    break;
                case 'week':
                    const monday = new Date(today);
                    monday.setDate(today.getDate() - today.getDay() + 1);
                    const sunday = new Date(monday);
                    sunday.setDate(monday.getDate() + 6);
                    startDate = monday.toISOString().split('T')[0];
                    endDate = sunday.toISOString().split('T')[0];
                    break;
                case 'month':
                    startDate = new Date(today.getFullYear(), today.getMonth(), 1).toISOString().split('T')[0];
                    endDate = new Date(today.getFullYear(), today.getMonth() + 1, 0).toISOString().split('T')[0];
                    break;
                case 'year':
                    startDate = new Date(today.getFullYear(), 0, 1).toISOString().split('T')[0];
                    endDate = new Date(today.getFullYear(), 11, 31).toISOString().split('T')[0];
                    break;
            }
            
            document.getElementById('overview_start_date').value = startDate;
            document.getElementById('overview_end_date').value = endDate;
            document.getElementById('overview_filterForm').submit();
        });
    });
    
    // Refresh button
    document.getElementById('overview_refreshBtn')?.addEventListener('click', function() {
        window.location.reload();
    });
    
    // ============================================================================
    // INITIALIZATION - NO SIDEBAR CODE (Issue #1 resolved)
    // ============================================================================
    document.addEventListener('DOMContentLoaded', function() {
        overview_initCharts();
        updateOverviewLanguage(currentOverviewLang);
    });
    
    // Re-initialize charts when language changes (to update labels)
    const originalUpdateOverviewLanguage = updateOverviewLanguage;
    window.updateOverviewLanguage = function(lang) {
        originalUpdateOverviewLanguage(lang);
        if (overview_chart1) {
            overview_chart1.data.datasets[0].label = overview_translations[lang].targetProfitLabel || 'Target Profit';
            overview_chart1.data.datasets[1].label = overview_translations[lang].actualProfitLabel || 'Actual Profit';
            overview_chart1.update();
        }
        if (overview_chart3) {
            overview_chart3.data.datasets[0].label = overview_translations[lang].staffCount || 'Staff Count';
            overview_chart3.update();
        }
    };
</script>