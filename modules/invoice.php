<?php
/**
 * SHEHITA Enterprise Management System
 * Invoice Module - Full CRUD Operations with Professional Invoice Generation
 * 
 * This module handles:
 * - Automatic table creation if not exists
 * - Generate professional invoices matching reference PDF
 * - Print invoices with one-page A4 layout
 * - Full CRUD operations with permission checks
 * - Search/filter/pagination for invoice history
 * - Integration with company settings and customers
 * - English/Swahili translation support
 * - Live calculations (Quantity × Rate, VAT 18%)
 * 
 * REFINED: Removed all sidebar-related code (Issue #1)
 * REFINED: Added foreign key table validation with user-friendly error messages (Issue #2)
 * REFINED: Ensured no conflict with homepage.php (top navbar layout) (Issue #3)
 * 
 * FIXED: PARTICULARS field now properly saves text data (was being treated as integer)
 *        - Changed bind_param type from 'i' to 's' for string fields in INSERT/UPDATE
 * 
 * PERMISSION ENHANCED: Buttons respect user permissions
 * INVOICE FORMAT: Exactly matches the reference PDF structure
 */

// Prevent direct access
if (!defined('ACCESS_ALLOWED') && basename($_SERVER['PHP_SELF']) == basename(__FILE__)) {
    // This file is included, so we're safe
}

// Get database connection from parent
global $conn;

// PERMISSION: Get user role and check module permissions
$user_role_id = $_SESSION['role_id'] ?? 0;
$module_name = 'invoice';

// PERMISSION: Check if user has view permission
if (!canView($conn, $user_role_id, $module_name)) {
    echo '<div class="inv-alert inv-alert-danger" style="text-align: center; padding: 40px;">
            <i class="fas fa-lock" style="font-size: 48px; margin-bottom: 16px; display: block;"></i>
            <h3>Access Denied</h3>
            <p>You do not have permission to view this module.</p>
          </div>';
    return;
}

/**
 * ============================================================================
 * FOREIGN KEY TABLE VALIDATION (ISSUE #2)
 * Check if required dependent tables exist before proceeding
 * ============================================================================
 */

$missing_tables = [];

// Check for customers table (required for invoice customer selection)
$check_customers = $conn->query("SHOW TABLES LIKE 'customers'");
if ($check_customers->num_rows == 0) {
    $missing_tables[] = ['table' => 'customers', 'module' => 'customer-management', 'display' => 'Customer Management'];
}

// Check for company_settings table (required for invoice company details)
$check_company = $conn->query("SHOW TABLES LIKE 'company_settings'");
if ($check_company->num_rows == 0) {
    $missing_tables[] = ['table' => 'company_settings', 'module' => 'company-settings', 'display' => 'Company Settings'];
}

// Display error message if any required tables are missing
if (!empty($missing_tables)) {
    echo '<div class="inv-container" style="max-width: 800px; margin: 40px auto;">';
    echo '<div class="inv-alert inv-alert-danger" style="background: #fff3cd; color: #856404; border-color: #ffeeba; text-align: left;">';
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

// Create invoices table if not exists
// Schema note: the `invoices` table is created centrally in config.php
// (in dependency order). This module assumes it already exists.

/**
 * ============================================================================
 * ADD ACCOUNT_NAME COLUMN IF IT DOESN'T EXIST (FOR EXISTING INSTALLATIONS)
 * ============================================================================
 */
$check_column = $conn->query("SHOW COLUMNS FROM invoices LIKE 'account_name'");
if ($check_column && $check_column->num_rows == 0) {
    $alter_sql = "ALTER TABLE invoices ADD COLUMN account_name VARCHAR(255) DEFAULT NULL AFTER account_number";
    $conn->query($alter_sql);
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
 * CHECK IF TABLE IS EMPTY AND RESET AUTO_INCREMENT IF NEEDED
 * ============================================================================
 */
$check_empty = $conn->query("SELECT COUNT(*) as count FROM invoices");
$row_count = $check_empty->fetch_assoc()['count'];

if ($row_count == 0) {
    $conn->query("ALTER TABLE invoices AUTO_INCREMENT = 1");
}

/**
 * ============================================================================
 * GENERATE NEXT INVOICE NUMBER
 * Format: INV00001, INV00002, etc.
 * ============================================================================
 */
function getNextInvoiceNumber($conn) {
    $result = $conn->query("SELECT invoice_number FROM invoices ORDER BY id DESC LIMIT 1");
    if ($result && $result->num_rows > 0) {
        $last = $result->fetch_assoc()['invoice_number'];
        // Extract numeric part (remove INV prefix)
        $num = (int)substr($last, 3);
        $next = $num + 1;
        return 'INV' . str_pad($next, 5, '0', STR_PAD_LEFT);
    }
    return 'INV00001';
}

/**
 * ============================================================================
 * GET COMPANY SETTINGS FOR INVOICE
 * ============================================================================
 */
function getCompanySettingsForInvoice($conn) {
    $result = $conn->query("SELECT * FROM company_settings LIMIT 1");
    if ($result && $result->num_rows > 0) {
        $settings = $result->fetch_assoc();
        // Ensure VRN is available
        if (!isset($settings['vrn_number']) || empty($settings['vrn_number'])) {
            $settings['vrn_number'] = 'Not Registered';
        }
        return $settings;
    }
    return [
        'company_name' => 'SHEHITA EMS',
        'company_address' => '',
        'company_email' => '',
        'company_phone' => '',
        'company_tin' => '',
        'vrn_number' => 'Not Registered',
        'currency_symbol' => 'TZS',
        'logo_url' => null
    ];
}

// Initialize variables
$invoice_message = '';
$invoice_message_type = '';
$edit_mode = false;
$edit_data = null;

// Pagination variables
$current_page = isset($_GET['inv_page']) ? (int)$_GET['inv_page'] : 1;
$records_per_page = 10;
$offset = ($current_page - 1) * $records_per_page;

// Search/filter variables
$search = isset($_GET['search']) ? sanitize($conn, $_GET['search']) : '';
$status_filter = isset($_GET['status_filter']) ? sanitize($conn, $_GET['status_filter']) : '';
$customer_filter = isset($_GET['customer_filter']) ? (int)$_GET['customer_filter'] : '';

/**
 * ============================================================================
 * FETCH ACTIVE CUSTOMERS FOR DROPDOWN
 * ============================================================================
 */
$customers_list = [];
$customers_query = "SELECT id, customer_name, contact_person, tin_number, vrn_number, address, email, type_of_business 
                    FROM customers WHERE status = 'Active' ORDER BY customer_name ASC";
$customers_result = $conn->query($customers_query);
if ($customers_result && $customers_result->num_rows > 0) {
    while ($row = $customers_result->fetch_assoc()) {
        $customers_list[] = $row;
    }
}

/**
 * ============================================================================
 * HANDLE FORM SUBMISSIONS WITH PERMISSION CHECKS
 * ============================================================================
 */

// PERMISSION: Check add permission before processing add operation
if (isset($_POST['inv_add']) && !canAdd($conn, $user_role_id, $module_name)) {
    $invoice_message = "You do not have permission to add invoices.";
    $invoice_message_type = "danger";
} elseif (isset($_POST['inv_add'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $invoice_message = "Invalid form submission. Please try again.";
        $invoice_message_type = "danger";
    } else {
        $invoice_number = getNextInvoiceNumber($conn);
        $customer_id = (int)$_POST['customer_id'];
        $invoice_date = sanitize($conn, $_POST['invoice_date']);
        $due_date = sanitize($conn, $_POST['due_date']);
        $particulars = sanitize($conn, $_POST['particulars']);  // TEXT field - keep as string
        $quantity = (int)$_POST['quantity'];
        $rate = (float)$_POST['rate'];
        $bank_name = sanitize($conn, $_POST['bank_name']);
        $account_number = sanitize($conn, $_POST['account_number']);
        $account_name = sanitize($conn, $_POST['account_name'] ?? '');
        $status = sanitize($conn, $_POST['status']);
        $created_by = $_SESSION['name'];
        
        // Calculate financials
        $subtotal = $quantity * $rate;
        $vat = $subtotal * 0.18;
        $total = $subtotal + $vat;
        
        // Validate inputs
        $errors = [];
        
        if ($customer_id <= 0) {
            $errors[] = "Please select a valid customer";
        }
        
        if (empty($invoice_date)) {
            $errors[] = "Invoice date is required";
        }
        
        if (empty($due_date)) {
            $errors[] = "Due date is required";
        }
        
        if ($due_date < $invoice_date) {
            $errors[] = "Due date must be on or after invoice date";
        }
        
        if (empty($particulars)) {
            $errors[] = "Particulars are required";
        }
        
        if ($quantity <= 0) {
            $errors[] = "Quantity must be greater than 0";
        }
        
        if ($rate <= 0) {
            $errors[] = "Rate must be greater than 0";
        }
        
        if (empty($bank_name)) {
            $errors[] = "Bank name is required";
        }
        
        if (empty($account_number)) {
            $errors[] = "Account number is required";
        }
        
        if (!in_array($status, ['Paid', 'Unpaid', 'Partially Paid'])) {
            $errors[] = "Invalid status";
        }
        
        if (empty($errors)) {
            // FIXED: Correct bind_param types - all string fields use 's', not 'i'
            // Previous incorrect: "sissiiddddsssss" (had 'i' for particulars, bank_name, etc.)
            // Corrected: "sisssiddddsssss" - particulars, bank_name, account_number, account_name, status are all strings
            $insert_stmt = $conn->prepare("INSERT INTO invoices (
                invoice_number, customer_id, invoice_date, due_date, particulars,
                quantity, rate, subtotal, vat, total, bank_name, account_number, account_name, status, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $insert_stmt->bind_param("sisssiddddsssss", 
                $invoice_number, $customer_id, $invoice_date, $due_date, $particulars,
                $quantity, $rate, $subtotal, $vat, $total, $bank_name, $account_number, $account_name, $status, $created_by
            );
            
            if ($insert_stmt->execute()) {
                $invoice_message = "Invoice created successfully!";
                $invoice_message_type = "success";
            } else {
                $invoice_message = "Error creating invoice: " . $conn->error;
                $invoice_message_type = "danger";
            }
            $insert_stmt->close();
        } else {
            $invoice_message = implode("<br>", $errors);
            $invoice_message_type = "danger";
        }
    }
    
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// PERMISSION: Check edit permission before processing update operation
if (isset($_POST['inv_update']) && !canEdit($conn, $user_role_id, $module_name)) {
    $invoice_message = "You do not have permission to edit invoices.";
    $invoice_message_type = "danger";
} elseif (isset($_POST['inv_update'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $invoice_message = "Invalid form submission. Please try again.";
        $invoice_message_type = "danger";
    } else {
        $id = (int)$_POST['id'];
        $customer_id = (int)$_POST['customer_id'];
        $invoice_date = sanitize($conn, $_POST['invoice_date']);
        $due_date = sanitize($conn, $_POST['due_date']);
        $particulars = sanitize($conn, $_POST['particulars']);  // TEXT field - keep as string
        $quantity = (int)$_POST['quantity'];
        $rate = (float)$_POST['rate'];
        $bank_name = sanitize($conn, $_POST['bank_name']);
        $account_number = sanitize($conn, $_POST['account_number']);
        $account_name = sanitize($conn, $_POST['account_name'] ?? '');
        $status = sanitize($conn, $_POST['status']);
        
        // Calculate financials
        $subtotal = $quantity * $rate;
        $vat = $subtotal * 0.18;
        $total = $subtotal + $vat;
        
        $errors = [];
        
        if ($id <= 0) {
            $errors[] = "Invalid ID";
        }
        
        if ($customer_id <= 0) {
            $errors[] = "Please select a valid customer";
        }
        
        if (empty($invoice_date)) {
            $errors[] = "Invoice date is required";
        }
        
        if (empty($due_date)) {
            $errors[] = "Due date is required";
        }
        
        if ($due_date < $invoice_date) {
            $errors[] = "Due date must be on or after invoice date";
        }
        
        if (empty($particulars)) {
            $errors[] = "Particulars are required";
        }
        
        if ($quantity <= 0) {
            $errors[] = "Quantity must be greater than 0";
        }
        
        if ($rate <= 0) {
            $errors[] = "Rate must be greater than 0";
        }
        
        if (empty($bank_name)) {
            $errors[] = "Bank name is required";
        }
        
        if (empty($account_number)) {
            $errors[] = "Account number is required";
        }
        
        if (!in_array($status, ['Paid', 'Unpaid', 'Partially Paid'])) {
            $errors[] = "Invalid status";
        }
        
        if (empty($errors)) {
            // FIXED: Correct bind_param types - all string fields use 's', not 'i'
            // Previous incorrect: "isssiddddssssi" (had 'i' for string fields)
            // Corrected: "isssiddddsssss" - all string fields use 's' type
            $update_stmt = $conn->prepare("UPDATE invoices SET 
                customer_id = ?, invoice_date = ?, due_date = ?, particulars = ?,
                quantity = ?, rate = ?, subtotal = ?, vat = ?, total = ?,
                bank_name = ?, account_number = ?, account_name = ?, status = ?
                WHERE id = ?");
            
            $update_stmt->bind_param("isssiddddssssi", 
                $customer_id, $invoice_date, $due_date, $particulars,
                $quantity, $rate, $subtotal, $vat, $total,
                $bank_name, $account_number, $account_name, $status, $id
            );
            
            if ($update_stmt->execute()) {
                $invoice_message = "Invoice updated successfully!";
                $invoice_message_type = "success";
            } else {
                $invoice_message = "Error updating invoice: " . $conn->error;
                $invoice_message_type = "danger";
            }
            $update_stmt->close();
        } else {
            $invoice_message = implode("<br>", $errors);
            $invoice_message_type = "danger";
        }
    }
    
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// PERMISSION: Check delete permission before processing delete operation
if (isset($_GET['inv_delete']) && !canDelete($conn, $user_role_id, $module_name)) {
    $invoice_message = "You do not have permission to delete invoices.";
    $invoice_message_type = "danger";
} elseif (isset($_GET['inv_delete'])) {
    $id = (int)$_GET['inv_delete'];
    
    if ($id > 0) {
        $delete_stmt = $conn->prepare("DELETE FROM invoices WHERE id = ?");
        $delete_stmt->bind_param("i", $id);
        
        if ($delete_stmt->execute()) {
            $invoice_message = "Invoice deleted successfully!";
            $invoice_message_type = "success";
            
            $check_empty = $conn->query("SELECT COUNT(*) as count FROM invoices");
            $row_count = $check_empty->fetch_assoc()['count'];
            
            if ($row_count == 0) {
                $conn->query("ALTER TABLE invoices AUTO_INCREMENT = 1");
            }
        } else {
            $invoice_message = "Error deleting invoice: " . $conn->error;
            $invoice_message_type = "danger";
        }
        $delete_stmt->close();
    }
}

// Get edit data if in edit mode
if (isset($_GET['inv_edit'])) {
    $edit_id = (int)$_GET['inv_edit'];
    if ($edit_id > 0 && canEdit($conn, $user_role_id, $module_name)) {
        $edit_stmt = $conn->prepare("SELECT * FROM invoices WHERE id = ?");
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
 * STATUS UPDATE HANDLER (One-click toggle from listing)
 * ============================================================================
 */
if (isset($_GET['inv_update_status']) && canEdit($conn, $user_role_id, $module_name)) {
    $id = (int)$_GET['inv_update_status'];
    $new_status = sanitize($conn, $_GET['new_status']);
    
    if ($id > 0 && in_array($new_status, ['Paid', 'Unpaid', 'Partially Paid'])) {
        $status_stmt = $conn->prepare("UPDATE invoices SET status = ? WHERE id = ?");
        $status_stmt->bind_param("si", $new_status, $id);
        if ($status_stmt->execute()) {
            $invoice_message = "Invoice status updated successfully!";
            $invoice_message_type = "success";
        }
        $status_stmt->close();
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
    $where_clause .= " (i.invoice_number LIKE ? OR c.customer_name LIKE ? OR i.particulars LIKE ?) ";
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
    $where_clause .= " i.status = ? ";
    $params[] = $status_filter;
    $types .= "s";
}

if (!empty($customer_filter)) {
    if (!empty($where_clause)) {
        $where_clause .= " AND ";
    }
    $where_clause .= " i.customer_id = ? ";
    $params[] = $customer_filter;
    $types .= "i";
}

if (!empty($where_clause)) {
    $where_clause = "WHERE " . $where_clause;
}

// Count total records for pagination
$count_query = "SELECT COUNT(*) as total FROM invoices i 
                LEFT JOIN customers c ON i.customer_id = c.id 
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

if ($current_page > $total_pages && $total_pages > 0) {
    $current_page = $total_pages;
    $offset = ($current_page - 1) * $records_per_page;
}

// Calculate KPI values based on filtered data
$kpi_query = "SELECT 
                COUNT(*) as total_invoices,
                SUM(i.total) as total_revenue,
                SUM(CASE WHEN i.status = 'Paid' THEN i.total ELSE 0 END) as paid_amount,
                SUM(CASE WHEN i.status = 'Unpaid' THEN i.total ELSE 0 END) as unpaid_amount
              FROM invoices i 
              LEFT JOIN customers c ON i.customer_id = c.id 
              $where_clause";
if (!empty($params)) {
    $kpi_stmt = $conn->prepare($kpi_query);
    $kpi_stmt->bind_param($types, ...$params);
    $kpi_stmt->execute();
    $kpi_result = $kpi_stmt->get_result();
    $kpi_data = $kpi_result->fetch_assoc();
    $kpi_stmt->close();
} else {
    $kpi_result = $conn->query($kpi_query);
    $kpi_data = $kpi_result->fetch_assoc();
}

$total_invoices = $kpi_data['total_invoices'] ?? 0;
$total_revenue = $kpi_data['total_revenue'] ?? 0;
$paid_amount = $kpi_data['paid_amount'] ?? 0;
$unpaid_amount = $kpi_data['unpaid_amount'] ?? 0;

// Fetch invoices
$invoices_query = "SELECT i.*, c.customer_name, c.contact_person, c.tin_number, c.vrn_number, c.address, c.email, c.type_of_business
                   FROM invoices i 
                   LEFT JOIN customers c ON i.customer_id = c.id 
                   $where_clause 
                   ORDER BY i.id DESC 
                   LIMIT ? OFFSET ?";
$params[] = $records_per_page;
$params[] = $offset;
$types .= "ii";

$invoices_stmt = $conn->prepare($invoices_query);
$invoices_stmt->bind_param($types, ...$params);
$invoices_stmt->execute();
$invoices_result = $invoices_stmt->get_result();

$invoices_data = [];
if ($invoices_result && $invoices_result->num_rows > 0) {
    while ($row = $invoices_result->fetch_assoc()) {
        $invoices_data[] = $row;
    }
    $invoices_stmt->execute();
    $invoices_result = $invoices_stmt->get_result();
}

$company_settings = getCompanySettingsForInvoice($conn);
?>

<!-- INVOICE TRANSLATIONS -->
<script>
const invoice_translations = {
    en: {
        pageTitle: 'Invoice Management',
        addNew: 'Generate New Invoice',
        editInvoice: 'Edit Invoice',
        generateInvoice: 'Generate New Invoice',
        invoiceNumber: 'Invoice Number',
        invoiceDate: 'Invoice Date',
        dueDate: 'Due Date',
        customerName: 'Customer Name',
        particulars: 'Particulars',
        quantity: 'Quantity',
        rate: 'Rate',
        subtotal: 'Subtotal',
        vat: 'VAT (18%)',
        total: 'Total',
        bankName: 'Bank Name',
        accountNumber: 'Account Number',
        accountName: 'Account Name',
        status: 'Status',
        paid: 'Paid',
        unpaid: 'Unpaid',
        partiallyPaid: 'Partially Paid',
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
        confirmDelete: 'Are you sure you want to delete invoice',
        confirmDeleteMsg: 'This action cannot be undone.',
        noData: 'No invoices found. Click "Generate New Invoice" to create one.',
        selectCustomer: 'Select Customer',
        customerDetails: 'Customer Details',
        invoiceTitle: 'TAX INVOICE',
        bankDetails: 'Bank Details',
        signature: 'Signature and Stamp:',
        signatureLine: '_____________________',
        totalAmountDue: 'Total Amount Due',
        subtotalLabel: 'Subtotal',
        vatLabel: 'VAT (18%)',
        grandTotal: 'GRAND TOTAL',
        search: 'Search',
        filter: 'Filter',
        allStatus: 'All Status',
        allCustomers: 'All Customers',
        clear: 'Clear',
        totalRecords: 'Total Invoices',
        records: 'records',
        page: 'Page',
        of: 'of',
        previous: 'Previous',
        next: 'Next',
        loading: 'Loading...',
        particularsPlaceholder: 'Enter description of goods/services',
        bankNamePlaceholder: 'Enter bank name',
        accountNumberPlaceholder: 'Enter account number',
        accountNamePlaceholder: 'Enter account holder name',
        invoiceInfo: 'Invoice Information',
        financialSummary: 'Financial Summary',
        totalInvoices: 'Total Invoices',
        totalRevenue: 'Total Revenue',
        paidAmount: 'Paid Amount',
        unpaidAmount: 'Unpaid Amount',
        generatedBy: 'Generated by',
        datePrinted: 'Date Printed',
        billTo: 'BILL TO',
        invoiceDetails: 'INVOICE DETAILS',
        qty: 'QTY',
        particularsHeader: 'PARTICULARS',
        rateHeader: 'RATE',
        totalHeader: 'TOTAL'
    },
    sw: {
        pageTitle: 'Usimamizi wa Ankara',
        addNew: 'Tengeneza Ankara Mpya',
        editInvoice: 'Hariri Ankara',
        generateInvoice: 'Tengeneza Ankara Mpya',
        invoiceNumber: 'Namba ya Ankara',
        invoiceDate: 'Tarehe ya Ankara',
        dueDate: 'Tarehe ya Malipo',
        customerName: 'Jina la Mteja',
        particulars: 'Maelezo',
        quantity: 'Idadi',
        rate: 'Bei',
        subtotal: 'Jumla Ndogo',
        vat: 'VAT (18%)',
        total: 'Jumla',
        bankName: 'Jina la Benki',
        accountNumber: 'Namba ya Akaunti',
        accountName: 'Jina la Mwenye Akaunti',
        status: 'Hali',
        paid: 'Imelipwa',
        unpaid: 'Haijalipwa',
        partiallyPaid: 'Imelipwa Kiasi',
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
        confirmDelete: 'Una uhakika unataka kufuta ankara',
        confirmDeleteMsg: 'Kitendo hiki hakiwezi kutenguliwa.',
        noData: 'Hakuna ankara zilizopatikana. Bofya "Tengeneza Ankara Mpya" kuunda.',
        selectCustomer: 'Chagua Mteja',
        customerDetails: 'Taarifa za Mteja',
        invoiceTitle: 'ANKARA YA KODI',
        bankDetails: 'Taarifa za Benki',
        signature: 'Saini na Muhuri:',
        signatureLine: '_____________________',
        totalAmountDue: 'Jumla ya Malipo',
        subtotalLabel: 'Jumla Ndogo',
        vatLabel: 'VAT (18%)',
        grandTotal: 'JUMLA KUU',
        search: 'Tafuta',
        filter: 'Chuja',
        allStatus: 'Hali Zote',
        allCustomers: 'Wateja Wote',
        clear: 'Futa',
        totalRecords: 'Jumla ya Ankara',
        records: 'rekodi',
        page: 'Ukurasa',
        of: 'kati ya',
        previous: 'Iliyotangulia',
        next: 'Ijayo',
        loading: 'Inapakia...',
        particularsPlaceholder: 'Weka maelezo ya bidhaa/huduma',
        bankNamePlaceholder: 'Weka jina la benki',
        accountNumberPlaceholder: 'Weka namba ya akaunti',
        accountNamePlaceholder: 'Weka jina la mwenye akaunti',
        invoiceInfo: 'Taarifa za Ankara',
        financialSummary: 'Muhtasari wa Kifedha',
        totalInvoices: 'Jumla ya Ankara',
        totalRevenue: 'Jumla ya Mapato',
        paidAmount: 'Kiasi Kilicholipwa',
        unpaidAmount: 'Kiasi ambacho Hakijalipwa',
        generatedBy: 'Imetolewa na',
        datePrinted: 'Tarehe ya Kuchapishwa',
        billTo: 'KWA MTEJA',
        invoiceDetails: 'TAARIFA ZA ANKARA',
        qty: 'IDADI',
        particularsHeader: 'MAELEZO',
        rateHeader: 'BEI',
        totalHeader: 'JUMLA'
    }
};

let currentInvoiceLang = localStorage.getItem('preferredLanguage') || 'en';

function updateInvoiceLanguage(lang) {
    currentInvoiceLang = lang;
    const elements = document.querySelectorAll('[data-inv-lang]');
    
    elements.forEach(element => {
        const key = element.getAttribute('data-inv-lang');
        if (invoice_translations[lang] && invoice_translations[lang][key]) {
            if (element.tagName === 'INPUT' || element.tagName === 'TEXTAREA') {
                if (element.getAttribute('type') !== 'submit' && element.getAttribute('type') !== 'button') {
                    element.placeholder = invoice_translations[lang][key];
                } else {
                    element.textContent = invoice_translations[lang][key];
                }
            } else if (element.tagName === 'OPTION') {
                element.textContent = invoice_translations[lang][key];
            } else {
                element.textContent = invoice_translations[lang][key];
            }
        }
    });
    
    const thElements = document.querySelectorAll('th[data-inv-lang]');
    thElements.forEach(th => {
        const key = th.getAttribute('data-inv-lang');
        if (invoice_translations[lang] && invoice_translations[lang][key]) {
            th.textContent = invoice_translations[lang][key];
        }
    });
    
    const emptyState = document.querySelector('.inv-empty p');
    if (emptyState) {
        emptyState.textContent = invoice_translations[lang].noData;
    }
    
    const formHeader = document.querySelector('#invForm h3');
    if (formHeader) {
        const isEditMode = document.querySelector('input[name="id"]') !== null;
        formHeader.textContent = invoice_translations[lang][isEditMode ? 'editInvoice' : 'generateInvoice'];
    }
    
    const searchInput = document.querySelector('input[name="search"]');
    if (searchInput) {
        searchInput.placeholder = invoice_translations[lang].search;
    }
    
    const totalRecordsSpan = document.querySelector('#totalRecords');
    if (totalRecordsSpan) {
        totalRecordsSpan.textContent = invoice_translations[lang].totalRecords;
    }
    
    const prevBtn = document.querySelector('.inv-prev-btn');
    const nextBtn = document.querySelector('.inv-next-btn');
    if (prevBtn && prevBtn.tagName === 'A') prevBtn.innerHTML = `<i class="fas fa-chevron-left"></i> ${invoice_translations[lang].previous}`;
    if (nextBtn && nextBtn.tagName === 'A') nextBtn.innerHTML = `${invoice_translations[lang].next} <i class="fas fa-chevron-right"></i>`;
    
    // Update KPI labels
    const kpiLabels = document.querySelectorAll('.inv-kpi-label');
    kpiLabels.forEach(label => {
        const key = label.getAttribute('data-inv-lang');
        if (key && invoice_translations[lang] && invoice_translations[lang][key]) {
            label.textContent = invoice_translations[lang][key];
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    updateInvoiceLanguage(currentInvoiceLang);
});

window.updateInvoiceLanguage = updateInvoiceLanguage;
</script>

<style>
    /* Invoice Module Styles - Using inv_ prefix for consistency (ISSUE #3: No sidebar conflicts) */
    .inv-container {
        width: 100%;
    }

    /* KPI Cards Row */
    .inv-kpi-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 28px;
    }

    .inv-kpi-card {
        background: white;
        border-radius: 16px;
        padding: 20px;
        box-shadow: var(--shadow-md);
        border: 1px solid var(--gray-200);
        transition: all 0.2s;
        position: relative;
        overflow: hidden;
    }

    .inv-kpi-card:hover {
        transform: translateY(-3px);
        box-shadow: var(--shadow-lg);
    }

    .inv-kpi-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 4px;
        height: 100%;
    }

    .inv-kpi-card-total::before { background: var(--brown-700); }
    .inv-kpi-card-revenue::before { background: #28a745; }
    .inv-kpi-card-paid::before { background: #17a2b8; }
    .inv-kpi-card-unpaid::before { background: #dc3545; }

    .inv-kpi-icon {
        font-size: 28px;
        margin-bottom: 12px;
    }

    .inv-kpi-icon-brown { color: var(--brown-700); }
    .inv-kpi-icon-green { color: #28a745; }
    .inv-kpi-icon-teal { color: #17a2b8; }
    .inv-kpi-icon-red { color: #dc3545; }

    .inv-kpi-value {
        font-size: 28px;
        font-weight: 700;
        color: var(--gray-800);
        margin-bottom: 6px;
    }

    .inv-kpi-label {
        font-size: 13px;
        color: var(--gray-500);
        font-weight: 500;
    }

    .inv-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
        flex-wrap: wrap;
        gap: 16px;
    }

    .inv-header h2 {
        color: var(--gray-800);
        font-size: 24px;
        font-weight: 600;
        margin: 0;
    }

    .inv-btn {
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

    .inv-btn:hover {
        background: var(--brown-800);
        transform: translateY(-2px);
        box-shadow: var(--shadow-md);
    }

    .inv-btn-secondary {
        background: var(--gray-200);
        color: var(--gray-700);
    }

    .inv-btn-secondary:hover {
        background: var(--gray-300);
    }

    .inv-form {
        background: white;
        border-radius: 20px;
        padding: 32px;
        margin-bottom: 32px;
        border: 1px solid var(--gray-200);
        box-shadow: var(--shadow-lg);
        display: none;
    }

    .inv-form.show {
        display: block;
        animation: inv-fadeIn 0.3s ease-out;
    }

    @keyframes inv-fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .inv-form h3 {
        color: var(--gray-800);
        font-size: 20px;
        font-weight: 600;
        margin-bottom: 24px;
        padding-bottom: 16px;
        border-bottom: 2px solid var(--gray-200);
    }

    .inv-form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 20px;
        margin-bottom: 24px;
    }

    .inv-form-group {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .inv-form-group-full {
        grid-column: 1 / -1;
    }

    .inv-form-group label {
        color: var(--gray-700);
        font-size: 14px;
        font-weight: 500;
    }

    .inv-form-group label .required {
        color: #dc3545;
        margin-left: 4px;
    }

    .inv-form-group input,
    .inv-form-group select,
    .inv-form-group textarea {
        padding: 10px 12px;
        border: 1px solid var(--gray-300);
        border-radius: 8px;
        font-size: 14px;
        transition: all 0.2s;
        font-family: inherit;
    }

    .inv-form-group textarea {
        resize: vertical;
        min-height: 80px;
    }

    .inv-form-group input:focus,
    .inv-form-group select:focus,
    .inv-form-group textarea:focus {
        outline: none;
        border-color: var(--brown-600);
        box-shadow: 0 0 0 3px rgba(123, 88, 63, 0.1);
    }

    /* Customer Details Card */
    .inv-customer-details {
        background: linear-gradient(135deg, #faf7f2 0%, #f5efe8 100%);
        border-radius: 12px;
        padding: 20px;
        margin-top: 16px;
        border: 1px solid var(--gray-200);
        display: none;
    }

    .inv-customer-details h4 {
        color: var(--brown-800);
        font-size: 16px;
        font-weight: 600;
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .inv-customer-details-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 12px;
    }

    .inv-customer-detail-item {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .inv-customer-detail-label {
        font-size: 12px;
        color: var(--gray-500);
        font-weight: 500;
    }

    .inv-customer-detail-value {
        font-size: 14px;
        color: var(--gray-800);
        font-weight: 500;
    }

    /* Financial Summary Card */
    .inv-financial-summary {
        background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
        border-radius: 12px;
        padding: 20px;
        margin-top: 24px;
        border: 1px solid #a5d6a7;
    }

    .inv-financial-summary h4 {
        color: #2e7d32;
        font-size: 16px;
        font-weight: 600;
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .inv-financial-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 16px;
    }

    .inv-financial-item {
        background: white;
        border-radius: 8px;
        padding: 12px;
        text-align: center;
    }

    .inv-financial-label {
        font-size: 12px;
        color: var(--gray-500);
        margin-bottom: 4px;
    }

    .inv-financial-value {
        font-size: 18px;
        font-weight: 700;
    }

    .inv-financial-value-positive {
        color: #28a745;
    }

    .inv-form-actions {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
        margin-top: 24px;
        padding-top: 20px;
        border-top: 1px solid var(--gray-200);
    }

    .inv-alert {
        padding: 16px;
        border-radius: 8px;
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .inv-alert-success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }

    .inv-alert-danger {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }

    .inv-alert-close {
        background: none;
        border: none;
        font-size: 20px;
        cursor: pointer;
        color: inherit;
        opacity: 0.5;
    }

    .inv-alert-close:hover {
        opacity: 1;
    }

    .inv-search-bar {
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

    .inv-search-group {
        flex: 1;
        min-width: 180px;
    }

    .inv-search-group label {
        display: block;
        color: var(--gray-700);
        font-size: 13px;
        font-weight: 500;
        margin-bottom: 6px;
    }

    .inv-search-group input,
    .inv-search-group select {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid var(--gray-300);
        border-radius: 6px;
        font-size: 14px;
    }

    .inv-search-group input:focus,
    .inv-search-group select:focus {
        outline: none;
        border-color: var(--brown-600);
        box-shadow: 0 0 0 3px rgba(123, 88, 63, 0.1);
    }

    .inv-search-actions {
        display: flex;
        gap: 8px;
    }

    .inv-search-btn {
        padding: 10px 20px;
        background: var(--brown-700);
        color: white;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-weight: 500;
        transition: all 0.2s;
    }

    .inv-search-btn:hover {
        background: var(--brown-800);
    }

    .inv-clear-btn {
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

    .inv-clear-btn:hover {
        background: var(--gray-300);
    }

    .inv-stats {
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

    .inv-stats-info {
        display: flex;
        align-items: center;
        gap: 8px;
        color: var(--gray-700);
        font-size: 14px;
    }

    .inv-stats-info i {
        color: var(--brown-600);
    }

    .inv-stats-count {
        font-weight: 700;
        color: var(--brown-700);
        font-size: 18px;
    }

    .inv-table-container {
        overflow-x: auto;
        background: white;
        border-radius: 12px;
        border: 1px solid var(--gray-200);
        margin-bottom: 20px;
    }

    .inv-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 1000px;
    }

    .inv-table th {
        background: var(--gray-50);
        color: var(--gray-700);
        font-weight: 600;
        font-size: 14px;
        padding: 16px;
        text-align: left;
        border-bottom: 2px solid var(--gray-200);
    }

    .inv-table td {
        padding: 16px;
        border-bottom: 1px solid var(--gray-200);
        color: var(--gray-700);
        font-size: 14px;
        vertical-align: middle;
    }

    .inv-table tr:hover {
        background: var(--gray-50);
    }

    .inv-status {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 500;
    }

    .inv-status-paid {
        background: #d4edda;
        color: #155724;
    }

    .inv-status-unpaid {
        background: #f8d7da;
        color: #721c24;
    }

    .inv-status-partiallypaid {
        background: #fff3cd;
        color: #856404;
    }

    .inv-actions {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    .inv-action-btn {
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

    .inv-action-edit {
        background: var(--brown-100);
        color: var(--brown-800);
    }

    .inv-action-edit:hover {
        background: var(--brown-200);
    }

    .inv-action-delete {
        background: #f8d7da;
        color: #721c24;
    }

    .inv-action-delete:hover {
        background: #f5c6cb;
    }

    .inv-action-view {
        background: #d1ecf1;
        color: #0c5460;
    }

    .inv-action-view:hover {
        background: #bee5eb;
    }

    .inv-status-select {
        padding: 4px 8px;
        border-radius: 4px;
        border: 1px solid var(--gray-300);
        font-size: 12px;
        font-weight: 500;
        cursor: pointer;
        background: white;
    }

    .inv-status-select-paid { background: #d4edda; color: #155724; border-color: #c3e6cb; }
    .inv-status-select-unpaid { background: #f8d7da; color: #721c24; border-color: #f5c6cb; }
    .inv-status-select-partiallypaid { background: #fff3cd; color: #856404; border-color: #ffeeba; }

    .inv-loading {
        display: inline-block;
        width: 20px;
        height: 20px;
        border: 3px solid var(--gray-200);
        border-top-color: var(--brown-700);
        border-radius: 50%;
        animation: inv-spin 1s linear infinite;
    }

    @keyframes inv-spin {
        to { transform: rotate(360deg); }
    }

    .inv-empty {
        text-align: center;
        padding: 48px !important;
        color: var(--gray-500);
    }

    .inv-empty i {
        font-size: 48px;
        margin-bottom: 16px;
        color: var(--gray-400);
    }

    .inv-timestamp {
        font-size: 12px;
        color: var(--gray-500);
        margin-top: 4px;
    }

    .inv-pagination {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 12px;
        margin-top: 20px;
    }

    .inv-pagination a,
    .inv-pagination span {
        padding: 8px 16px;
        border-radius: 6px;
        text-decoration: none;
        font-size: 14px;
        font-weight: 500;
        transition: all 0.2s;
    }

    .inv-pagination a {
        background: white;
        border: 1px solid var(--gray-300);
        color: var(--gray-700);
    }

    .inv-pagination a:hover {
        background: var(--brown-100);
        border-color: var(--brown-600);
        color: var(--brown-800);
    }

    .inv-pagination .active {
        background: var(--brown-700);
        border-color: var(--brown-700);
        color: white;
    }

    .inv-pagination .disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    /* Modal Styles - Matching Reference PDF */
    .inv-modal-overlay {
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

    .inv-modal-overlay.active {
        display: flex;
    }

    .inv-modal {
        background: white;
        border-radius: 16px;
        width: 100%;
        max-width: 900px;
        max-height: 90vh;
        overflow: hidden;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        animation: inv-modalFadeIn 0.2s ease-out;
        display: flex;
        flex-direction: column;
    }

    @keyframes inv-modalFadeIn {
        from { opacity: 0; transform: scale(0.95); }
        to { opacity: 1; transform: scale(1); }
    }

    .inv-modal-header {
        padding: 20px 24px;
        border-bottom: 2px solid var(--gray-200);
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: linear-gradient(135deg, #faf7f2 0%, #f5efe8 100%);
    }

    .inv-modal-title {
        font-family: 'Montserrat', sans-serif;
        font-weight: 700;
        font-size: 1.3rem;
        color: var(--brown-800);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .inv-modal-close {
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

    .inv-modal-close:hover {
        background: rgba(139, 90, 43, 0.1);
        color: #dc3545;
    }

    .inv-modal-body {
        padding: 24px;
        overflow-y: auto;
        flex: 1;
    }

    .inv-modal-footer {
        padding: 16px 24px;
        border-top: 1px solid var(--gray-200);
        display: flex;
        justify-content: flex-end;
        gap: 12px;
        background: #faf7f5;
    }

    /* Invoice Content Styles - EXACTLY Matching Reference PDF */
    .inv-modal-invoice {
        background: white;
        font-family: 'Inter', sans-serif;
    }

    .inv-modal-invoice-header {
        text-align: center;
        padding: 15px 20px;
        border-bottom: 2px solid var(--brown-700);
        margin-bottom: 20px;
    }

    .inv-modal-invoice-logo {
        max-width: 80px;
        max-height: 80px;
        margin-bottom: 12px;
    }

    .inv-modal-invoice-company-name {
        font-size: 22px;
        font-weight: 700;
        color: var(--brown-800);
        margin-bottom: 6px;
    }

    .inv-modal-invoice-company-details {
        font-size: 11px;
        color: var(--gray-600);
        line-height: 1.5;
    }

    .inv-modal-invoice-title {
        text-align: center;
        font-size: 20px;
        font-weight: 700;
        color: var(--brown-800);
        margin: 20px 0;
        padding: 10px;
        background: var(--brown-100);
        letter-spacing: 2px;
        border: 1px solid var(--brown-200);
    }

    .inv-modal-invoice-section {
        margin-bottom: 20px;
        border: 1px solid var(--gray-200);
        border-radius: 8px;
        overflow: hidden;
    }

    .inv-modal-invoice-section-title {
        background: var(--gray-100);
        padding: 10px 15px;
        font-weight: 700;
        color: var(--brown-800);
        border-bottom: 1px solid var(--gray-200);
        font-size: 13px;
        letter-spacing: 0.5px;
    }

    .inv-modal-invoice-section-content {
        padding: 15px;
    }

    .inv-modal-invoice-row {
        display: flex;
        justify-content: space-between;
        padding: 6px 0;
        border-bottom: 1px dashed var(--gray-200);
        font-size: 13px;
    }

    .inv-modal-invoice-row:last-child {
        border-bottom: none;
    }

    .inv-modal-invoice-label {
        font-weight: 600;
        color: var(--gray-700);
        width: 40%;
    }

    .inv-modal-invoice-value {
        color: var(--gray-800);
        width: 60%;
        text-align: right;
    }

    .inv-modal-invoice-table {
        width: 100%;
        border-collapse: collapse;
        margin: 15px 0;
    }

    .inv-modal-invoice-table th {
        background: var(--brown-100);
        padding: 10px;
        text-align: left;
        font-size: 12px;
        font-weight: 700;
        color: var(--brown-800);
        border-bottom: 2px solid var(--brown-300);
    }

    .inv-modal-invoice-table td {
        padding: 8px 10px;
        border-bottom: 1px solid var(--gray-200);
        font-size: 12px;
    }

    .inv-modal-invoice-table td:last-child,
    .inv-modal-invoice-table th:last-child {
        text-align: right;
    }

    .inv-modal-invoice-table td:first-child,
    .inv-modal-invoice-table th:first-child {
        text-align: center;
    }

    .inv-modal-invoice-financial-item {
        display: flex;
        justify-content: space-between;
        padding: 6px 0;
        font-size: 13px;
    }

    .inv-modal-invoice-financial-label {
        font-weight: 500;
        color: var(--gray-700);
    }

    .inv-modal-invoice-financial-value {
        font-weight: 600;
        color: var(--gray-800);
    }

    .inv-modal-invoice-total-row {
        border-top: 2px solid var(--gray-300);
        margin-top: 8px;
        padding-top: 10px;
        font-weight: 800;
        font-size: 15px;
    }

    .inv-modal-invoice-total-row .inv-modal-invoice-financial-label,
    .inv-modal-invoice-total-row .inv-modal-invoice-financial-value {
        font-weight: 800;
        color: var(--brown-800);
    }

    .inv-modal-invoice-bank-section {
        margin-top: 20px;
        background: #f8f9fa;
        padding: 12px;
        border-radius: 8px;
        font-size: 12px;
    }

    .inv-modal-invoice-signature {
        margin-top: 30px;
        padding-top: 20px;
        border-top: 1px dashed var(--gray-300);
        text-align: center;
        font-size: 12px;
    }

    .inv-modal-invoice-signature-line {
        margin-top: 30px;
        font-style: italic;
    }

    .inv-modal-invoice-footer {
        margin-top: 20px;
        padding-top: 12px;
        border-top: 1px solid var(--gray-200);
        text-align: center;
        font-size: 10px;
        color: var(--gray-500);
    }

    /* Print styles - Optimized for one-page A4 output */
    @media print {
        body * {
            visibility: hidden;
        }
        .inv-modal-body,
        .inv-modal-body * {
            visibility: visible;
        }
        .inv-modal-body {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            padding: 0;
            margin: 0;
        }
        .inv-modal-header,
        .inv-modal-footer {
            display: none;
        }
        @page {
            size: A4;
            margin: 0.5in;
        }
        .inv-modal-invoice-table th,
        .inv-modal-invoice-table td {
            border: 1px solid #ccc;
        }
    }

    @media (max-width: 768px) {
        .inv-kpi-row {
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }
        
        .inv-kpi-value {
            font-size: 22px;
        }
        
        .inv-search-bar {
            flex-direction: column;
        }
        
        .inv-search-actions {
            width: 100%;
        }
        
        .inv-search-btn,
        .inv-clear-btn {
            flex: 1;
            text-align: center;
            justify-content: center;
        }
        
        .inv-form-grid {
            grid-template-columns: 1fr;
        }
        
        .inv-pagination {
            flex-wrap: wrap;
        }
        
        .inv-modal {
            max-width: 95%;
            max-height: 95vh;
        }
        
        .inv-modal-body {
            padding: 16px;
        }
        
        .inv-customer-details-grid {
            grid-template-columns: 1fr;
        }
        
        .inv-financial-grid {
            grid-template-columns: 1fr;
        }
    }
    
    @media (max-width: 480px) {
        .inv-kpi-row {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="inv-container">
    <!-- Header -->
    <div class="inv-header">
        <h2 data-inv-lang="pageTitle">Invoice Management</h2>
        <?php if (canAdd($conn, $user_role_id, $module_name)): ?>
        <button class="inv-btn" onclick="inv_toggleForm()" id="inv_toggleBtn">
            <i class="fas fa-plus"></i>
            <span data-inv-lang="addNew">Generate New Invoice</span>
        </button>
        <?php endif; ?>
    </div>

    <!-- Alert Messages -->
    <?php if (!empty($invoice_message)): ?>
        <div class="inv-alert inv-alert-<?= $invoice_message_type ?>">
            <?= $invoice_message ?>
            <button class="inv-alert-close" onclick="this.parentElement.style.display='none'">&times;</button>
        </div>
    <?php endif; ?>

    <!-- Add/Edit Form -->
    <?php if (canAdd($conn, $user_role_id, $module_name) || ($edit_mode && canEdit($conn, $user_role_id, $module_name))): ?>
    <div class="inv-form <?= $edit_mode ? 'show' : '' ?>" id="invForm">
        <h3 data-inv-lang="<?= $edit_mode ? 'editInvoice' : 'generateInvoice' ?>">
            <?= $edit_mode ? 'Edit Invoice' : 'Generate New Invoice' ?>
        </h3>
        
        <form method="POST" action="?page=invoice" id="inv_mainForm" onsubmit="return inv_validateForm()">
            <?php if ($edit_mode): ?>
                <input type="hidden" name="id" value="<?= $edit_data['id'] ?>">
            <?php endif; ?>
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            
            <div class="inv-form-grid">
                <!-- Customer Selection -->
                <div class="inv-form-group">
                    <label for="inv_customer_id" data-inv-lang="customerName">Customer <span class="required">*</span></label>
                    <select id="inv_customer_id" name="customer_id" required onchange="inv_loadCustomerDetails(this.value)">
                        <option value="" data-inv-lang="selectCustomer">Select Customer</option>
                        <?php foreach ($customers_list as $customer): ?>
                            <option value="<?= $customer['id'] ?>" 
                                data-customer-name="<?= htmlspecialchars($customer['customer_name']) ?>"
                                data-customer-tin="<?= htmlspecialchars($customer['tin_number'] ?? 'N/A') ?>"
                                data-customer-vrn="<?= htmlspecialchars($customer['vrn_number'] ?? 'N/A') ?>"
                                data-customer-address="<?= htmlspecialchars($customer['address'] ?? 'N/A') ?>"
                                data-customer-email="<?= htmlspecialchars($customer['email'] ?? 'N/A') ?>"
                                data-customer-business="<?= htmlspecialchars($customer['type_of_business'] ?? 'N/A') ?>"
                                <?= ($edit_mode && $edit_data['customer_id'] == $customer['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($customer['customer_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Invoice Date -->
                <div class="inv-form-group">
                    <label for="inv_invoice_date" data-inv-lang="invoiceDate">Invoice Date <span class="required">*</span></label>
                    <input type="date" id="inv_invoice_date" name="invoice_date" 
                           value="<?= $edit_mode ? $edit_data['invoice_date'] : date('Y-m-d') ?>" required>
                </div>
                
                <!-- Due Date -->
                <div class="inv-form-group">
                    <label for="inv_due_date" data-inv-lang="dueDate">Due Date <span class="required">*</span></label>
                    <input type="date" id="inv_due_date" name="due_date" 
                           value="<?= $edit_mode ? $edit_data['due_date'] : date('Y-m-d', strtotime('+30 days')) ?>" required>
                </div>
                
                <!-- Particulars (Full Width) -->
                <div class="inv-form-group inv-form-group-full">
                    <label for="inv_particulars" data-inv-lang="particulars">Particulars <span class="required">*</span></label>
                    <textarea id="inv_particulars" name="particulars" rows="3" 
                              data-inv-lang="particularsPlaceholder" 
                              placeholder="Enter description of goods/services" required><?= $edit_mode ? htmlspecialchars($edit_data['particulars']) : '' ?></textarea>
                </div>
                
                <!-- Quantity -->
                <div class="inv-form-group">
                    <label for="inv_quantity" data-inv-lang="quantity">Quantity <span class="required">*</span></label>
                    <input type="number" id="inv_quantity" name="quantity" 
                           value="<?= $edit_mode ? $edit_data['quantity'] : '1' ?>" 
                           min="1" step="1" required oninput="inv_updateFinancialSummary()">
                </div>
                
                <!-- Rate -->
                <div class="inv-form-group">
                    <label for="inv_rate" data-inv-lang="rate">Rate (<?= htmlspecialchars($company_settings['currency_symbol'] ?? 'TZS') ?>) <span class="required">*</span></label>
                    <input type="number" step="0.01" id="inv_rate" name="rate" 
                           value="<?= $edit_mode ? $edit_data['rate'] : '0.00' ?>" 
                           min="0.01" required oninput="inv_updateFinancialSummary()">
                </div>
                
                <!-- Bank Name -->
                <div class="inv-form-group">
                    <label for="inv_bank_name" data-inv-lang="bankName">Bank Name <span class="required">*</span></label>
                    <input type="text" id="inv_bank_name" name="bank_name" 
                           value="<?= $edit_mode ? htmlspecialchars($edit_data['bank_name']) : '' ?>" 
                           data-inv-lang="bankNamePlaceholder" placeholder="Enter bank name" required>
                </div>
                
                <!-- Account Name -->
                <div class="inv-form-group">
                    <label for="inv_account_name" data-inv-lang="accountName">Account Name</label>
                    <input type="text" id="inv_account_name" name="account_name" 
                           value="<?= $edit_mode ? htmlspecialchars($edit_data['account_name'] ?? '') : '' ?>" 
                           data-inv-lang="accountNamePlaceholder" placeholder="Enter account holder name">
                </div>
                
                <!-- Account Number -->
                <div class="inv-form-group">
                    <label for="inv_account_number" data-inv-lang="accountNumber">Account Number <span class="required">*</span></label>
                    <input type="text" id="inv_account_number" name="account_number" 
                           value="<?= $edit_mode ? htmlspecialchars($edit_data['account_number']) : '' ?>" 
                           data-inv-lang="accountNumberPlaceholder" placeholder="Enter account number" required>
                </div>
                
                <!-- Status -->
                <div class="inv-form-group">
                    <label for="inv_status" data-inv-lang="status">Status</label>
                    <select id="inv_status" name="status">
                        <option value="Unpaid" data-inv-lang="unpaid" <?= ($edit_mode && $edit_data['status'] == 'Unpaid') ? 'selected' : '' ?>>Unpaid</option>
                        <option value="Paid" data-inv-lang="paid" <?= ($edit_mode && $edit_data['status'] == 'Paid') ? 'selected' : '' ?>>Paid</option>
                        <option value="Partially Paid" data-inv-lang="partiallyPaid" <?= ($edit_mode && $edit_data['status'] == 'Partially Paid') ? 'selected' : '' ?>>Partially Paid</option>
                    </select>
                </div>
            </div>
            
            <!-- Customer Details Card -->
            <div id="inv_customerDetails" class="inv-customer-details">
                <h4><i class="fas fa-building"></i> <span data-inv-lang="customerDetails">Customer Details</span></h4>
                <div class="inv-customer-details-grid" id="inv_customerDetailsGrid">
                    <!-- Populated by JavaScript -->
                </div>
            </div>
            
            <!-- Financial Summary Card -->
            <div class="inv-financial-summary">
                <h4><i class="fas fa-calculator"></i> <span data-inv-lang="financialSummary">Financial Summary</span></h4>
                <div class="inv-financial-grid">
                    <div class="inv-financial-item">
                        <div class="inv-financial-label" data-inv-lang="subtotalLabel">Subtotal</div>
                        <div class="inv-financial-value" id="inv_subtotalDisplay">0.00</div>
                    </div>
                    <div class="inv-financial-item">
                        <div class="inv-financial-label" data-inv-lang="vatLabel">VAT (18%)</div>
                        <div class="inv-financial-value" id="inv_vatDisplay">0.00</div>
                    </div>
                    <div class="inv-financial-item">
                        <div class="inv-financial-label" data-inv-lang="grandTotal">GRAND TOTAL</div>
                        <div class="inv-financial-value inv-financial-value-positive" id="inv_totalDisplay">0.00</div>
                    </div>
                </div>
            </div>
            
            <input type="hidden" name="subtotal" id="inv_subtotal" value="0">
            <input type="hidden" name="vat" id="inv_vat" value="0">
            <input type="hidden" name="total" id="inv_total" value="0">
            
            <div class="inv-form-actions">
                <button type="submit" name="<?= $edit_mode ? 'inv_update' : 'inv_add' ?>" class="inv-btn" id="inv_saveBtn">
                    <i class="fas fa-save"></i>
                    <span data-inv-lang="save">Save</span>
                </button>
                <a href="?page=invoice" class="inv-btn inv-btn-secondary">
                    <i class="fas fa-times"></i>
                    <span data-inv-lang="cancel">Cancel</span>
                </a>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- KPI Cards Row -->
    <div id="inv_kpiRow" class="inv-kpi-row" style="<?= $edit_mode ? 'display: none;' : '' ?>">
        <div class="inv-kpi-card inv-kpi-card-total">
            <div class="inv-kpi-icon inv-kpi-icon-brown">
                <i class="fas fa-file-invoice"></i>
            </div>
            <div class="inv-kpi-value" id="inv_totalInvoices"><?= number_format($total_invoices) ?></div>
            <div class="inv-kpi-label" data-inv-lang="totalInvoices">Total Invoices</div>
        </div>
        
        <div class="inv-kpi-card inv-kpi-card-revenue">
            <div class="inv-kpi-icon inv-kpi-icon-green">
                <i class="fas fa-chart-line"></i>
            </div>
            <div class="inv-kpi-value" id="inv_totalRevenue"><?= number_format($total_revenue, 2) ?></div>
            <div class="inv-kpi-label" data-inv-lang="totalRevenue">Total Revenue</div>
        </div>
        
        <div class="inv-kpi-card inv-kpi-card-paid">
            <div class="inv-kpi-icon inv-kpi-icon-teal">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="inv-kpi-value" id="inv_paidAmount"><?= number_format($paid_amount, 2) ?></div>
            <div class="inv-kpi-label" data-inv-lang="paidAmount">Paid Amount</div>
        </div>
        
        <div class="inv-kpi-card inv-kpi-card-unpaid">
            <div class="inv-kpi-icon inv-kpi-icon-red">
                <i class="fas fa-clock"></i>
            </div>
            <div class="inv-kpi-value" id="inv_unpaidAmount"><?= number_format($unpaid_amount, 2) ?></div>
            <div class="inv-kpi-label" data-inv-lang="unpaidAmount">Unpaid Amount</div>
        </div>
    </div>

    <!-- Search and Filter Bar -->
    <div id="inv_searchBar" class="inv-search-bar" style="<?= $edit_mode ? 'display: none;' : '' ?>">
        <form method="GET" action="?page=invoice" style="display: contents;">
            <input type="hidden" name="page" value="invoice">
            
            <div class="inv-search-group">
                <label for="search" data-inv-lang="search">Search</label>
                <input type="text" id="search" name="search" 
                       value="<?= htmlspecialchars($search) ?>" 
                       placeholder="Search by invoice #, customer or particulars">
            </div>
            
            <div class="inv-search-group">
                <label for="status_filter" data-inv-lang="filter">Status</label>
                <select id="status_filter" name="status_filter">
                    <option value="" data-inv-lang="allStatus">All Status</option>
                    <option value="Paid" <?= $status_filter == 'Paid' ? 'selected' : '' ?> data-inv-lang="paid">Paid</option>
                    <option value="Unpaid" <?= $status_filter == 'Unpaid' ? 'selected' : '' ?> data-inv-lang="unpaid">Unpaid</option>
                    <option value="Partially Paid" <?= $status_filter == 'Partially Paid' ? 'selected' : '' ?> data-inv-lang="partiallyPaid">Partially Paid</option>
                </select>
            </div>
            
            <div class="inv-search-group">
                <label for="customer_filter" data-inv-lang="customerName">Customer</label>
                <select id="customer_filter" name="customer_filter">
                    <option value="" data-inv-lang="allCustomers">All Customers</option>
                    <?php foreach ($customers_list as $customer): ?>
                        <option value="<?= $customer['id'] ?>" <?= $customer_filter == $customer['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($customer['customer_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="inv-search-actions">
                <button type="submit" class="inv-search-btn">
                    <i class="fas fa-search"></i> <span data-inv-lang="search">Search</span>
                </button>
                <a href="?page=invoice" class="inv-clear-btn">
                    <i class="fas fa-times"></i> <span data-inv-lang="clear">Clear</span>
                </a>
            </div>
        </form>
    </div>

    <!-- Stats Bar -->
    <div id="inv_statsBar" class="inv-stats" style="<?= $edit_mode ? 'display: none;' : '' ?>">
        <div class="inv-stats-info">
            <i class="fas fa-file-invoice"></i>
            <span id="totalRecords" data-inv-lang="totalRecords">Total Invoices</span>
            <span>:</span>
            <span class="inv-stats-count" id="inv_totalRecordsCount"><?= $total_records ?></span>
            <span data-inv-lang="records">records</span>
        </div>
    </div>

    <!-- Data Table -->
    <div id="inv_tableContainer" class="inv-table-container" style="<?= $edit_mode ? 'display: none;' : '' ?>">
        <table class="inv-table" id="inv_dataTable">
            <thead>
                <tr>
                    <th data-inv-lang="invoiceNumber">Invoice Number</th>
                    <th data-inv-lang="customerName">Customer Name</th>
                    <th data-inv-lang="invoiceDate">Invoice Date</th>
                    <th data-inv-lang="dueDate">Due Date</th>
                    <th data-inv-lang="total">Total</th>
                    <th data-inv-lang="status">Status</th>
                    <th data-inv-lang="created">Created</th>
                    <th data-inv-lang="actions">Actions</th>
                </tr>
            </thead>
            <tbody id="inv_tableBody">
                <?php if ($invoices_result && $invoices_result->num_rows > 0): ?>
                    <?php while ($row = $invoices_result->fetch_assoc()): ?>
                        <tr data-invoice='<?= json_encode([
                            'id' => $row['id'],
                            'invoice_number' => $row['invoice_number'],
                            'customer_id' => $row['customer_id'],
                            'customer_name' => $row['customer_name'] ?? 'N/A',
                            'customer_tin' => $row['tin_number'] ?? 'N/A',
                            'customer_vrn' => $row['vrn_number'] ?? 'N/A',
                            'customer_address' => $row['address'] ?? 'N/A',
                            'customer_email' => $row['email'] ?? 'N/A',
                            'customer_business' => $row['type_of_business'] ?? 'N/A',
                            'invoice_date' => $row['invoice_date'],
                            'due_date' => $row['due_date'],
                            'particulars' => $row['particulars'],
                            'quantity' => $row['quantity'],
                            'rate' => $row['rate'],
                            'subtotal' => $row['subtotal'],
                            'vat' => $row['vat'],
                            'total' => $row['total'],
                            'bank_name' => $row['bank_name'],
                            'account_number' => $row['account_number'],
                            'account_name' => $row['account_name'] ?? '',
                            'status' => $row['status'],
                            'created_by' => $row['created_by'],
                            'created_at' => $row['created_at']
                        ]) ?>'>
                            <td><strong><?= htmlspecialchars($row['invoice_number']) ?></strong></td>
                            <td><?= htmlspecialchars($row['customer_name'] ?? 'N/A') ?></td>
                            <td><?= date('d M Y', strtotime($row['invoice_date'])) ?></td>
                            <td><?= date('d M Y', strtotime($row['due_date'])) ?></td>
                            <td><?= number_format($row['total'], 2) ?></td>
                            <td>
                                <select class="inv-status-select inv-status-select-<?= strtolower(str_replace(' ', '', $row['status'])) ?>" 
                                        onchange="inv_updateStatus(<?= $row['id'] ?>, this.value)">
                                    <option value="Paid" <?= $row['status'] == 'Paid' ? 'selected' : '' ?> data-inv-lang="paid">Paid</option>
                                    <option value="Unpaid" <?= $row['status'] == 'Unpaid' ? 'selected' : '' ?> data-inv-lang="unpaid">Unpaid</option>
                                    <option value="Partially Paid" <?= $row['status'] == 'Partially Paid' ? 'selected' : '' ?> data-inv-lang="partiallyPaid">Partially Paid</option>
                                </select>
                            </td>
                            <td>
                                <?= date('d M Y', strtotime($row['created_at'])) ?>
                                <div class="inv-timestamp"><?= date('H:i', strtotime($row['created_at'])) ?></div>
                            </td>
                            <td>
                                <div class="inv-actions">
                                    <?php if (canEdit($conn, $user_role_id, $module_name)): ?>
                                    <a href="?page=invoice&inv_edit=<?= $row['id'] ?>" 
                                       class="inv-action-btn inv-action-edit">
                                        <i class="fas fa-edit"></i> <span data-inv-lang="edit">Edit</span>
                                    </a>
                                    <?php endif; ?>
                                    <?php if (canDelete($conn, $user_role_id, $module_name)): ?>
                                    <a href="javascript:void(0)" 
                                       onclick="inv_confirmDelete(<?= $row['id'] ?>, '<?= htmlspecialchars(addslashes($row['invoice_number'])) ?>')" 
                                       class="inv-action-btn inv-action-delete">
                                        <i class="fas fa-trash"></i> <span data-inv-lang="delete">Delete</span>
                                    </a>
                                    <?php endif; ?>
                                    <button onclick="inv_openInvoiceModal(this)" 
                                            data-invoice='<?= json_encode([
                                                'id' => $row['id'],
                                                'invoice_number' => $row['invoice_number'],
                                                'customer_id' => $row['customer_id'],
                                                'customer_name' => $row['customer_name'] ?? 'N/A',
                                                'customer_tin' => $row['tin_number'] ?? 'N/A',
                                                'customer_vrn' => $row['vrn_number'] ?? 'N/A',
                                                'customer_address' => $row['address'] ?? 'N/A',
                                                'customer_email' => $row['email'] ?? 'N/A',
                                                'customer_business' => $row['type_of_business'] ?? 'N/A',
                                                'invoice_date' => $row['invoice_date'],
                                                'due_date' => $row['due_date'],
                                                'particulars' => $row['particulars'],
                                                'quantity' => $row['quantity'],
                                                'rate' => $row['rate'],
                                                'subtotal' => $row['subtotal'],
                                                'vat' => $row['vat'],
                                                'total' => $row['total'],
                                                'bank_name' => $row['bank_name'],
                                                'account_number' => $row['account_number'],
                                                'account_name' => $row['account_name'] ?? '',
                                                'status' => $row['status'],
                                                'created_by' => $row['created_by'],
                                                'created_at' => $row['created_at']
                                            ]) ?>'
                                            class="inv-action-btn inv-action-view">
                                        <i class="fas fa-eye"></i> <span data-inv-lang="viewInvoice">View Invoice</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr class="inv-empty-row">
                        <td colspan="8" class="inv-empty">
                            <i class="fas fa-file-invoice"></i>
                            <p data-inv-lang="noData">No invoices found. Click "Generate New Invoice" to create one.</p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div id="inv_pagination" class="inv-pagination" style="<?= $edit_mode ? 'display: none;' : '' ?>">
        <?php if ($current_page > 1): ?>
            <a href="?page=invoice&inv_page=<?= $current_page - 1 ?>&search=<?= urlencode($search) ?>&status_filter=<?= urlencode($status_filter) ?>&customer_filter=<?= $customer_filter ?>" class="inv-prev-btn">
                <i class="fas fa-chevron-left"></i> <span data-inv-lang="previous">Previous</span>
            </a>
        <?php else: ?>
            <span class="disabled"><i class="fas fa-chevron-left"></i> <span data-inv-lang="previous">Previous</span></span>
        <?php endif; ?>
        
        <span><span data-inv-lang="page">Page</span> <?= $current_page ?> <span data-inv-lang="of">of</span> <?= $total_pages ?></span>
        
        <?php if ($current_page < $total_pages): ?>
            <a href="?page=invoice&inv_page=<?= $current_page + 1 ?>&search=<?= urlencode($search) ?>&status_filter=<?= urlencode($status_filter) ?>&customer_filter=<?= $customer_filter ?>" class="inv-next-btn">
                <span data-inv-lang="next">Next</span> <i class="fas fa-chevron-right"></i>
            </a>
        <?php else: ?>
            <span class="disabled"><span data-inv-lang="next">Next</span> <i class="fas fa-chevron-right"></i></span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Invoice Modal -->
<div id="inv_invoiceModal" class="inv-modal-overlay">
    <div class="inv-modal">
        <div class="inv-modal-header">
            <h3 class="inv-modal-title">
                <i class="fas fa-file-invoice"></i> <span data-inv-lang="invoiceTitle">TAX INVOICE</span>
            </h3>
            <button type="button" class="inv-modal-close" onclick="inv_closeInvoiceModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="inv-modal-body" id="inv_invoiceModalBody">
            <!-- Invoice content will be dynamically inserted here -->
        </div>
        <div class="inv-modal-footer">
            <button type="button" class="inv-btn inv-btn-secondary" onclick="inv_closeInvoiceModal()">
                <i class="fas fa-times"></i> <span data-inv-lang="close">Close</span>
            </button>
            <button type="button" class="inv-btn" onclick="inv_printInvoiceFromModal()">
                <i class="fas fa-print"></i> <span data-inv-lang="print">Print</span>
            </button>
        </div>
    </div>
</div>

<script>
    // Company settings for invoice display
    const inv_companySettings = <?php echo json_encode([
        'company_name' => $company_settings['company_name'] ?? 'SHEHITA EMS',
        'company_address' => $company_settings['company_address'] ?? '',
        'company_email' => $company_settings['company_email'] ?? '',
        'company_phone' => $company_settings['company_phone'] ?? '',
        'company_tin' => $company_settings['company_tin'] ?? '',
        'company_vrn' => $company_settings['vrn_number'] ?? 'Not Registered',
        'currency_symbol' => $company_settings['currency_symbol'] ?? 'TZS',
        'logo_url' => $company_settings['logo_url'] ?? null
    ]); ?>;
    
    // Format money with currency symbol
    function inv_formatMoney(value) {
        let numValue = parseFloat(value);
        if (isNaN(numValue)) numValue = 0;
        return inv_companySettings.currency_symbol + ' ' + 
            new Intl.NumberFormat('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }).format(numValue);
    }
    
    // Update financial summary in real-time
    function inv_updateFinancialSummary() {
        const quantity = parseInt(document.getElementById('inv_quantity').value) || 0;
        const rate = parseFloat(document.getElementById('inv_rate').value) || 0;
        
        const subtotal = quantity * rate;
        const vat = subtotal * 0.18;
        const total = subtotal + vat;
        
        document.getElementById('inv_subtotalDisplay').textContent = inv_formatMoney(subtotal);
        document.getElementById('inv_vatDisplay').textContent = inv_formatMoney(vat);
        document.getElementById('inv_totalDisplay').textContent = inv_formatMoney(total);
        
        document.getElementById('inv_subtotal').value = subtotal.toFixed(2);
        document.getElementById('inv_vat').value = vat.toFixed(2);
        document.getElementById('inv_total').value = total.toFixed(2);
    }
    
    // Load customer details when customer is selected
    function inv_loadCustomerDetails(customerId) {
        const detailsDiv = document.getElementById('inv_customerDetails');
        const detailsGrid = document.getElementById('inv_customerDetailsGrid');
        const select = document.getElementById('inv_customer_id');
        const selectedOption = select.options[select.selectedIndex];
        
        if (!customerId || customerId === '') {
            detailsDiv.style.display = 'none';
            return;
        }
        
        const customerName = selectedOption.getAttribute('data-customer-name') || 'N/A';
        const customerTin = selectedOption.getAttribute('data-customer-tin') || 'N/A';
        const customerVrn = selectedOption.getAttribute('data-customer-vrn') || 'N/A';
        const customerAddress = selectedOption.getAttribute('data-customer-address') || 'N/A';
        const customerEmail = selectedOption.getAttribute('data-customer-email') || 'N/A';
        const customerBusiness = selectedOption.getAttribute('data-customer-business') || 'N/A';
        const lang = currentInvoiceLang;
        
        detailsGrid.innerHTML = `
            <div class="inv-customer-detail-item">
                <div class="inv-customer-detail-label" data-inv-lang="customerName">Customer Name</div>
                <div class="inv-customer-detail-value">${escapeHtml(customerName)}</div>
            </div>
            <div class="inv-customer-detail-item">
                <div class="inv-customer-detail-label" data-inv-lang="tinNumber">TIN Number</div>
                <div class="inv-customer-detail-value">${escapeHtml(customerTin)}</div>
            </div>
            <div class="inv-customer-detail-item">
                <div class="inv-customer-detail-label" data-inv-lang="vrnNumber">VRN Number</div>
                <div class="inv-customer-detail-value">${escapeHtml(customerVrn)}</div>
            </div>
            <div class="inv-customer-detail-item">
                <div class="inv-customer-detail-label" data-inv-lang="address">Address</div>
                <div class="inv-customer-detail-value">${escapeHtml(customerAddress)}</div>
            </div>
            <div class="inv-customer-detail-item">
                <div class="inv-customer-detail-label" data-inv-lang="email">Email</div>
                <div class="inv-customer-detail-value">${escapeHtml(customerEmail)}</div>
            </div>
            <div class="inv-customer-detail-item">
                <div class="inv-customer-detail-label" data-inv-lang="typeOfBusiness">Type of Business</div>
                <div class="inv-customer-detail-value">${escapeHtml(customerBusiness)}</div>
            </div>
        `;
        detailsDiv.style.display = 'block';
        
        // Update translations for customer details
        const detailLabels = detailsGrid.querySelectorAll('[data-inv-lang]');
        detailLabels.forEach(el => {
            const key = el.getAttribute('data-inv-lang');
            if (invoice_translations[lang] && invoice_translations[lang][key]) {
                el.textContent = invoice_translations[lang][key];
            }
        });
    }
    
    // Toggle form visibility
    function inv_toggleForm() {
        const form = document.getElementById('invForm');
        const kpiRow = document.getElementById('inv_kpiRow');
        const searchBar = document.getElementById('inv_searchBar');
        const statsBar = document.getElementById('inv_statsBar');
        const tableContainer = document.getElementById('inv_tableContainer');
        const pagination = document.getElementById('inv_pagination');
        const toggleBtn = document.getElementById('inv_toggleBtn');
        const lang = currentInvoiceLang;
        
        if (form.classList.contains('show')) {
            form.classList.remove('show');
            if (kpiRow) kpiRow.style.display = '';
            if (searchBar) searchBar.style.display = '';
            if (statsBar) statsBar.style.display = '';
            if (tableContainer) tableContainer.style.display = '';
            if (pagination) pagination.style.display = '';
            toggleBtn.innerHTML = '<i class="fas fa-plus"></i> <span data-inv-lang="addNew">' + 
                (lang === 'en' ? 'Generate New Invoice' : 'Tengeneza Ankara Mpya') + '</span>';
        } else {
            form.classList.add('show');
            if (kpiRow) kpiRow.style.display = 'none';
            if (searchBar) searchBar.style.display = 'none';
            if (statsBar) statsBar.style.display = 'none';
            if (tableContainer) tableContainer.style.display = 'none';
            if (pagination) pagination.style.display = 'none';
            toggleBtn.innerHTML = '<i class="fas fa-times"></i> <span data-inv-lang="cancel">' + 
                (lang === 'en' ? 'Cancel' : 'Ghairi') + '</span>';
            
            const hiddenId = document.querySelector('input[name="id"]');
            if (hiddenId) hiddenId.remove();
            
            document.getElementById('inv_customer_id').value = '';
            document.getElementById('inv_invoice_date').value = new Date().toISOString().slice(0,10);
            document.getElementById('inv_due_date').value = new Date(Date.now() + 30*24*60*60*1000).toISOString().slice(0,10);
            document.getElementById('inv_particulars').value = '';
            document.getElementById('inv_quantity').value = '1';
            document.getElementById('inv_rate').value = '0.00';
            document.getElementById('inv_bank_name').value = '';
            document.getElementById('inv_account_name').value = '';
            document.getElementById('inv_account_number').value = '';
            document.getElementById('inv_status').value = 'Unpaid';
            document.getElementById('inv_customerDetails').style.display = 'none';
            
            inv_updateFinancialSummary();
            
            const submitBtn = document.querySelector('button[name="inv_update"], button[name="inv_add"]');
            if (submitBtn) submitBtn.name = 'inv_add';
            
            const formHeader = document.querySelector('#invForm h3');
            if (formHeader) formHeader.textContent = invoice_translations[lang].generateInvoice;
        }
        
        updateInvoiceLanguage(lang);
    }
    
    // Validate form before submission
    function inv_validateForm() {
        const customerId = document.getElementById('inv_customer_id').value;
        const invoiceDate = document.getElementById('inv_invoice_date').value;
        const dueDate = document.getElementById('inv_due_date').value;
        const particulars = document.getElementById('inv_particulars').value.trim();
        const quantity = parseInt(document.getElementById('inv_quantity').value) || 0;
        const rate = parseFloat(document.getElementById('inv_rate').value) || 0;
        const bankName = document.getElementById('inv_bank_name').value.trim();
        const accountNumber = document.getElementById('inv_account_number').value.trim();
        const lang = currentInvoiceLang;
        
        if (!customerId) {
            alert(invoice_translations[lang].selectCustomer);
            document.getElementById('inv_customer_id').focus();
            return false;
        }
        
        if (!invoiceDate) {
            alert('Invoice date is required');
            document.getElementById('inv_invoice_date').focus();
            return false;
        }
        
        if (!dueDate) {
            alert('Due date is required');
            document.getElementById('inv_due_date').focus();
            return false;
        }
        
        if (dueDate < invoiceDate) {
            alert('Due date must be on or after invoice date');
            document.getElementById('inv_due_date').focus();
            return false;
        }
        
        if (!particulars) {
            alert('Particulars are required');
            document.getElementById('inv_particulars').focus();
            return false;
        }
        
        if (quantity <= 0) {
            alert('Quantity must be greater than 0');
            document.getElementById('inv_quantity').focus();
            return false;
        }
        
        if (rate <= 0) {
            alert('Rate must be greater than 0');
            document.getElementById('inv_rate').focus();
            return false;
        }
        
        if (!bankName) {
            alert('Bank name is required');
            document.getElementById('inv_bank_name').focus();
            return false;
        }
        
        if (!accountNumber) {
            alert('Account number is required');
            document.getElementById('inv_account_number').focus();
            return false;
        }
        
        return true;
    }
    
    // Update invoice status via AJAX (one-click toggle)
    function inv_updateStatus(id, newStatus) {
        if (confirm('Are you sure you want to change the status?')) {
            window.location.href = `?page=invoice&inv_update_status=${id}&new_status=${encodeURIComponent(newStatus)}`;
        } else {
            // Reload page to reset select
            window.location.reload();
        }
    }
    
    // Confirm delete with loading effect
    function inv_confirmDelete(id, invoiceNumber) {
        const lang = currentInvoiceLang;
        const confirmMsg = invoice_translations[lang].confirmDelete + ' "' + invoiceNumber + '"?\n' + 
                          invoice_translations[lang].confirmDeleteMsg;
        
        if (confirm(confirmMsg)) {
            const row = event.target.closest('tr');
            row.style.opacity = '0.5';
            const loadingDiv = document.createElement('div');
            loadingDiv.className = 'inv-loading';
            loadingDiv.style.position = 'absolute';
            loadingDiv.style.top = '50%';
            loadingDiv.style.left = '50%';
            loadingDiv.style.transform = 'translate(-50%, -50%)';
            row.style.position = 'relative';
            row.appendChild(loadingDiv);
            
            setTimeout(() => {
                window.location.href = `?page=invoice&inv_delete=${id}`;
            }, 300);
        }
    }
    
    // Open invoice modal with professional layout matching reference PDF
    function inv_openInvoiceModal(button) {
        const invoiceDataRaw = button.getAttribute('data-invoice');
        if (!invoiceDataRaw) return;
        
        try {
            const invoice = JSON.parse(invoiceDataRaw);
            const lang = currentInvoiceLang;
            const t = invoice_translations[lang];
            const now = new Date();
            const datePrinted = now.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) + 
                               ' ' + now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
            
            // Parse financial values
            const subtotal = parseFloat(invoice.subtotal) || 0;
            const vat = parseFloat(invoice.vat) || 0;
            const total = parseFloat(invoice.total) || 0;
            const quantity = parseInt(invoice.quantity) || 0;
            const rate = parseFloat(invoice.rate) || 0;
            
            const invoiceHtml = `
                <div class="inv-modal-invoice">
                    <!-- Company Header - Matching Reference PDF -->
                    <div class="inv-modal-invoice-header">
                        ${inv_companySettings.logo_url ? `<img src="${inv_companySettings.logo_url}" alt="Logo" class="inv-modal-invoice-logo">` : ''}
                        <div class="inv-modal-invoice-company-name">${escapeHtml(inv_companySettings.company_name)}</div>
                        <div class="inv-modal-invoice-company-details">
                            ${escapeHtml(inv_companySettings.company_address)}<br>
                            ${escapeHtml(inv_companySettings.company_email)} | ${escapeHtml(inv_companySettings.company_phone)}<br>
                            TIN: ${escapeHtml(inv_companySettings.company_tin)} | VRN: ${escapeHtml(inv_companySettings.company_vrn)}
                        </div>
                    </div>
                    
                    <!-- Invoice Title -->
                    <div class="inv-modal-invoice-title">${t.invoiceTitle || 'TAX INVOICE'}</div>
                    
                    <!-- Bill To Section -->
                    <div class="inv-modal-invoice-section">
                        <div class="inv-modal-invoice-section-title">${t.billTo || 'BILL TO'}</div>
                        <div class="inv-modal-invoice-section-content">
                            <div class="inv-modal-invoice-row">
                                <span class="inv-modal-invoice-label">Customer Name:</span>
                                <span class="inv-modal-invoice-value">${escapeHtml(invoice.customer_name)}</span>
                            </div>
                            <div class="inv-modal-invoice-row">
                                <span class="inv-modal-invoice-label">TIN:</span>
                                <span class="inv-modal-invoice-value">${escapeHtml(invoice.customer_tin)}</span>
                            </div>
                            <div class="inv-modal-invoice-row">
                                <span class="inv-modal-invoice-label">VRN:</span>
                                <span class="inv-modal-invoice-value">${escapeHtml(invoice.customer_vrn)}</span>
                            </div>
                            <div class="inv-modal-invoice-row">
                                <span class="inv-modal-invoice-label">Address:</span>
                                <span class="inv-modal-invoice-value">${escapeHtml(invoice.customer_address)}</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Invoice Details Section -->
                    <div class="inv-modal-invoice-section">
                        <div class="inv-modal-invoice-section-title">${t.invoiceDetails || 'INVOICE DETAILS'}</div>
                        <div class="inv-modal-invoice-section-content">
                            <div class="inv-modal-invoice-row">
                                <span class="inv-modal-invoice-label">Invoice Number:</span>
                                <span class="inv-modal-invoice-value">${escapeHtml(invoice.invoice_number)}</span>
                            </div>
                            <div class="inv-modal-invoice-row">
                                <span class="inv-modal-invoice-label">Invoice Date:</span>
                                <span class="inv-modal-invoice-value">${invoice.invoice_date}</span>
                            </div>
                            <div class="inv-modal-invoice-row">
                                <span class="inv-modal-invoice-label">Due Date:</span>
                                <span class="inv-modal-invoice-value">${invoice.due_date}</span>
                            </div>
                            <div class="inv-modal-invoice-row">
                                <span class="inv-modal-invoice-label">Status:</span>
                                <span class="inv-modal-invoice-value">${invoice.status}</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Line Items Table -->
                    <table class="inv-modal-invoice-table">
                        <thead>
                            <tr>
                                <th>${t.qty || 'QTY'}</th>
                                <th>${t.particularsHeader || 'PARTICULARS'}</th>
                                <th>${t.rateHeader || 'RATE'} (${inv_companySettings.currency_symbol})</th>
                                <th>${t.totalHeader || 'TOTAL'} (${inv_companySettings.currency_symbol})</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>${quantity}</td>
                                <td>${escapeHtml(invoice.particulars)}</td>
                                <td>${inv_formatMoney(rate)}</td>
                                <td>${inv_formatMoney(subtotal)}</td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <!-- Financial Summary -->
                    <div class="inv-modal-invoice-section">
                        <div class="inv-modal-invoice-section-content">
                            <div class="inv-modal-invoice-financial-item">
                                <span class="inv-modal-invoice-financial-label">${t.subtotalLabel || 'Subtotal'}:</span>
                                <span class="inv-modal-invoice-financial-value">${inv_formatMoney(subtotal)}</span>
                            </div>
                            <div class="inv-modal-invoice-financial-item">
                                <span class="inv-modal-invoice-financial-label">${t.vatLabel || 'VAT (18%)'}:</span>
                                <span class="inv-modal-invoice-financial-value">${inv_formatMoney(vat)}</span>
                            </div>
                            <div class="inv-modal-invoice-financial-item inv-modal-invoice-total-row">
                                <span class="inv-modal-invoice-financial-label">${t.grandTotal || 'GRAND TOTAL'}:</span>
                                <span class="inv-modal-invoice-financial-value">${inv_formatMoney(total)}</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Bank Details Section -->
                    <div class="inv-modal-invoice-section">
                        <div class="inv-modal-invoice-section-title">${t.bankDetails || 'BANK DETAILS'}</div>
                        <div class="inv-modal-invoice-section-content">
                            <div class="inv-modal-invoice-row">
                                <span class="inv-modal-invoice-label">Bank Name:</span>
                                <span class="inv-modal-invoice-value">${escapeHtml(invoice.bank_name)}</span>
                            </div>
                            ${invoice.account_name ? `
                            <div class="inv-modal-invoice-row">
                                <span class="inv-modal-invoice-label">Account Name:</span>
                                <span class="inv-modal-invoice-value">${escapeHtml(invoice.account_name)}</span>
                            </div>
                            ` : ''}
                            <div class="inv-modal-invoice-row">
                                <span class="inv-modal-invoice-label">Account Number:</span>
                                <span class="inv-modal-invoice-value">${escapeHtml(invoice.account_number)}</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Signature and Footer -->
                    <div class="inv-modal-invoice-signature">
                        <div>${t.signature || 'Signature and Stamp:'}</div>
                        <div class="inv-modal-invoice-signature-line">${t.signatureLine || '_____________________'}</div>
                    </div>
                    
                    <div class="inv-modal-invoice-footer">
                        ${t.generatedBy || 'Generated by'}: ${escapeHtml(invoice.created_by)}<br>
                        ${t.datePrinted || 'Date Printed'}: ${datePrinted}
                    </div>
                </div>
            `;
            
            document.getElementById('inv_invoiceModalBody').innerHTML = invoiceHtml;
            document.getElementById('inv_invoiceModal').classList.add('active');
            
            // Update modal translations
            const modalLabels = document.querySelectorAll('[data-inv-lang]');
            modalLabels.forEach(el => {
                const key = el.getAttribute('data-inv-lang');
                if (invoice_translations[lang] && invoice_translations[lang][key]) {
                    el.textContent = invoice_translations[lang][key];
                }
            });
            
        } catch (e) {
            console.error('Error loading invoice:', e);
            alert('Error loading invoice. Please try again.');
        }
    }
    
    // Close invoice modal
    function inv_closeInvoiceModal() {
        document.getElementById('inv_invoiceModal').classList.remove('active');
        document.getElementById('inv_invoiceModalBody').innerHTML = '';
    }
    
    // Escape HTML to prevent XSS
    function escapeHtml(str) {
        if (!str) return 'N/A';
        return String(str).replace(/[&<>]/g, function(m) {
            if (m === '&') return '&amp;';
            if (m === '<') return '&lt;';
            if (m === '>') return '&gt;';
            return m;
        });
    }
    
    // Print invoice from modal
    function inv_printInvoiceFromModal() {
        const modalBody = document.getElementById('inv_invoiceModalBody');
        if (!modalBody) return;
        
        const invoiceContent = modalBody.innerHTML;
        if (!invoiceContent) return;
        
        const lang = currentInvoiceLang;
        const t = invoice_translations[lang];
        
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
                <title>${t.invoiceTitle || 'TAX INVOICE'}</title>
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
                        max-width: 900px;
                        margin: 0 auto;
                        background: white;
                    }
                    
                    .inv-modal-invoice-header {
                        text-align: center;
                        padding: 15px 20px;
                        border-bottom: 2px solid #3e2b1f;
                        margin-bottom: 15px;
                    }
                    
                    .inv-modal-invoice-logo {
                        max-width: 70px;
                        max-height: 70px;
                        margin-bottom: 10px;
                    }
                    
                    .inv-modal-invoice-company-name {
                        font-size: 20px;
                        font-weight: 700;
                        color: #3e2b1f;
                        margin-bottom: 4px;
                    }
                    
                    .inv-modal-invoice-company-details {
                        font-size: 10px;
                        color: #64748b;
                        line-height: 1.4;
                    }
                    
                    .inv-modal-invoice-title {
                        text-align: center;
                        font-size: 18px;
                        font-weight: 700;
                        color: #3e2b1f;
                        margin: 15px 0;
                        padding: 8px;
                        background: #f0e9e2;
                        letter-spacing: 1px;
                    }
                    
                    .inv-modal-invoice-section {
                        margin-bottom: 15px;
                        border: 1px solid #e2e8f0;
                        border-radius: 8px;
                        overflow: hidden;
                    }
                    
                    .inv-modal-invoice-section-title {
                        background: #f1f5f9;
                        padding: 8px 12px;
                        font-weight: 700;
                        color: #3e2b1f;
                        border-bottom: 1px solid #e2e8f0;
                        font-size: 12px;
                    }
                    
                    .inv-modal-invoice-section-content {
                        padding: 10px 12px;
                    }
                    
                    .inv-modal-invoice-row {
                        display: flex;
                        justify-content: space-between;
                        padding: 4px 0;
                        border-bottom: 1px dashed #e2e8f0;
                        font-size: 11px;
                    }
                    
                    .inv-modal-invoice-row:last-child {
                        border-bottom: none;
                    }
                    
                    .inv-modal-invoice-label {
                        font-weight: 600;
                        color: #475569;
                        width: 40%;
                    }
                    
                    .inv-modal-invoice-value {
                        color: #1e293b;
                        width: 60%;
                        text-align: right;
                    }
                    
                    .inv-modal-invoice-table {
                        width: 100%;
                        border-collapse: collapse;
                        margin: 15px 0;
                    }
                    
                    .inv-modal-invoice-table th {
                        background: #f0e9e2;
                        padding: 8px;
                        text-align: left;
                        font-size: 11px;
                        font-weight: 600;
                        color: #3e2b1f;
                        border-bottom: 2px solid #b89b7e;
                    }
                    
                    .inv-modal-invoice-table td {
                        padding: 6px 8px;
                        border-bottom: 1px solid #e2e8f0;
                        font-size: 11px;
                    }
                    
                    .inv-modal-invoice-table td:last-child,
                    .inv-modal-invoice-table th:last-child {
                        text-align: right;
                    }
                    
                    .inv-modal-invoice-table td:first-child,
                    .inv-modal-invoice-table th:first-child {
                        text-align: center;
                    }
                    
                    .inv-modal-invoice-financial-item {
                        display: flex;
                        justify-content: space-between;
                        padding: 6px 0;
                        font-size: 12px;
                    }
                    
                    .inv-modal-invoice-financial-label {
                        font-weight: 500;
                        color: #475569;
                    }
                    
                    .inv-modal-invoice-financial-value {
                        font-weight: 600;
                        color: #1e293b;
                    }
                    
                    .inv-modal-invoice-total-row {
                        border-top: 2px solid #cbd5e1;
                        margin-top: 8px;
                        padding-top: 10px;
                        font-weight: 700;
                        font-size: 14px;
                    }
                    
                    .inv-modal-invoice-signature {
                        margin-top: 30px;
                        padding-top: 20px;
                        border-top: 1px dashed #cbd5e1;
                        text-align: center;
                        font-size: 12px;
                    }
                    
                    .inv-modal-invoice-footer {
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
                            size: A4;
                            margin: 0.4in;
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
    document.getElementById('inv_invoiceModal').addEventListener('click', function(e) {
        if (e.target === this) {
            inv_closeInvoiceModal();
        }
    });
    
    // Auto-hide alerts after 5 seconds
    setTimeout(() => {
        document.querySelectorAll('.inv-alert').forEach(alert => {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(() => alert.style.display = 'none', 500);
        });
    }, 5000);
    
    // Initialize on page load (NO SIDEBAR CODE - ISSUE #1 resolved)
    document.addEventListener('DOMContentLoaded', function() {
        <?php if ($edit_mode): ?>
            inv_updateFinancialSummary();
            const editCustomerId = <?= json_encode($edit_data['customer_id'] ?? '') ?>;
            if (editCustomerId) inv_loadCustomerDetails(editCustomerId);
        <?php endif; ?>
        
        const quantityInput = document.getElementById('inv_quantity');
        const rateInput = document.getElementById('inv_rate');
        if (quantityInput) quantityInput.addEventListener('input', inv_updateFinancialSummary);
        if (rateInput) rateInput.addEventListener('input', inv_updateFinancialSummary);
        
        inv_updateFinancialSummary();
    });
</script>