<?php
/**
 * SHEHITA Enterprise Management System
 * Customer Management Module - Full CRUD Operations
 * 
 * This module handles:
 * - Automatic table creation if not exists
 * - Display all customers in a table with search/filter/pagination
 * - Add new customer (inline form)
 * - Edit existing customer
 * - Delete customer with confirmation
 * - Auto-reset ID when table becomes empty
 * - Full English/Swahili translation support
 * - CSRF protection for forms
 * - Permission-based access control
 * 
 * PERMISSION ENHANCED: Buttons now respect user permissions (can_add, can_edit, can_delete)
 * ENHANCED: Added VRN Number field
 * 
 * NOTE: This module is designed to work with a TOP HORIZONTAL NAVBAR (homepage.php).
 * No sidebar-related code is present. All styles use 'cust-' prefix to avoid conflicts.
 */

// Prevent direct access
if (!defined('ACCESS_ALLOWED') && basename($_SERVER['PHP_SELF']) == basename(__FILE__)) {
    // This file is included, so we're safe
}

// Get database connection from parent
global $conn;

// PERMISSION: Get user role and check module permissions
$user_role_id = $_SESSION['role_id'] ?? 0;
$module_name = 'customer-management';

// PERMISSION: Check if user has view permission
if (!canView($conn, $user_role_id, $module_name)) {
    echo '<div class="cust-alert cust-alert-danger" style="text-align: center; padding: 40px;">
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

// Create customers table if not exists
// Schema note: the `customers` table is created centrally in config.php
// (in dependency order). This module assumes it already exists.

// Add vrn_number column if it doesn't exist (for existing installations)
$check_column = $conn->query("SHOW COLUMNS FROM customers LIKE 'vrn_number'");
if ($check_column && $check_column->num_rows == 0) {
    $alter_sql = "ALTER TABLE customers ADD COLUMN vrn_number VARCHAR(50) DEFAULT NULL AFTER tin_number";
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
$check_empty = $conn->query("SELECT COUNT(*) as count FROM customers");
$row_count = $check_empty->fetch_assoc()['count'];

if ($row_count == 0) {
    $conn->query("ALTER TABLE customers AUTO_INCREMENT = 1");
}

// Initialize variables for messages
$customers_message = '';
$customers_message_type = '';

// Initialize search/filter/pagination variables
$search = isset($_GET['search']) ? sanitize($conn, $_GET['search']) : '';
$status_filter = isset($_GET['status_filter']) ? sanitize($conn, $_GET['status_filter']) : '';
$current_page = isset($_GET['cust_page']) ? (int)$_GET['cust_page'] : 1;
$records_per_page = 10;
$offset = ($current_page - 1) * $records_per_page;

/**
 * ============================================================================
 * TYPE OF BUSINESS OPTIONS
 * ============================================================================
 */
$business_types = [
    'Individual' => 'Individual',
    'Sole Proprietorship' => 'Sole Proprietorship',
    'Partnership' => 'Partnership',
    'Limited Company' => 'Limited Company',
    'Corporation' => 'Corporation',
    'Non-Profit' => 'Non-Profit',
    'Government' => 'Government',
    'Other' => 'Other'
];

/**
 * ============================================================================
 * HANDLE FORM SUBMISSIONS WITH PERMISSION CHECKS
 * ============================================================================
 */

// PERMISSION: Check add permission before processing add operation
if (isset($_POST['customers_add']) && !canAdd($conn, $user_role_id, $module_name)) {
    $customers_message = "You do not have permission to add customers.";
    $customers_message_type = "danger";
} elseif (isset($_POST['customers_add'])) {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $customers_message = "Invalid form submission. Please try again.";
        $customers_message_type = "danger";
    } else {
        $customer_name = sanitize($conn, $_POST['customer_name']);
        $contact_person = sanitize($conn, $_POST['contact_person']);
        $tin_number = sanitize($conn, $_POST['tin_number']);
        $vrn_number = sanitize($conn, $_POST['vrn_number']);
        $address = sanitize($conn, $_POST['address']);
        $email = sanitize($conn, $_POST['email']);
        $type_of_business = sanitize($conn, $_POST['type_of_business']);
        $status = sanitize($conn, $_POST['status']);
        
        // Validate inputs
        $errors = [];
        
        if (empty($customer_name)) {
            $errors[] = "Customer name is required";
        } elseif (strlen($customer_name) > 255) {
            $errors[] = "Customer name must not exceed 255 characters";
        }
        
        if (!empty($contact_person) && strlen($contact_person) > 255) {
            $errors[] = "Contact person name must not exceed 255 characters";
        }
        
        if (!empty($tin_number) && strlen($tin_number) > 50) {
            $errors[] = "TIN number must not exceed 50 characters";
        }
        
        if (!empty($vrn_number)) {
            if (strlen($vrn_number) > 50) {
                $errors[] = "VRN number must not exceed 50 characters";
            } elseif (!preg_match('/^[A-Za-z0-9\-]+$/', $vrn_number)) {
                $errors[] = "VRN number can only contain letters, numbers, and hyphens";
            }
        }
        
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Please enter a valid email address";
        }
        
        if (!array_key_exists($type_of_business, $business_types)) {
            $errors[] = "Invalid business type selected";
        }
        
        if (!in_array($status, ['Active', 'Inactive'])) {
            $errors[] = "Invalid status";
        }
        
        if (empty($errors)) {
            // Insert new customer
            $insert_stmt = $conn->prepare("INSERT INTO customers (customer_name, contact_person, tin_number, vrn_number, address, email, type_of_business, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $insert_stmt->bind_param("ssssssss", $customer_name, $contact_person, $tin_number, $vrn_number, $address, $email, $type_of_business, $status);
            
            if ($insert_stmt->execute()) {
                $customers_message = "Customer added successfully!";
                $customers_message_type = "success";
            } else {
                $customers_message = "Error adding customer: " . $conn->error;
                $customers_message_type = "danger";
            }
            $insert_stmt->close();
        } else {
            $customers_message = implode("<br>", $errors);
            $customers_message_type = "danger";
        }
    }
    
    // Generate new CSRF token
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// PERMISSION: Check edit permission before processing update operation
if (isset($_POST['customers_update']) && !canEdit($conn, $user_role_id, $module_name)) {
    $customers_message = "You do not have permission to edit customers.";
    $customers_message_type = "danger";
} elseif (isset($_POST['customers_update'])) {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $customers_message = "Invalid form submission. Please try again.";
        $customers_message_type = "danger";
    } else {
        $id = (int)$_POST['id'];
        $customer_name = sanitize($conn, $_POST['customer_name']);
        $contact_person = sanitize($conn, $_POST['contact_person']);
        $tin_number = sanitize($conn, $_POST['tin_number']);
        $vrn_number = sanitize($conn, $_POST['vrn_number']);
        $address = sanitize($conn, $_POST['address']);
        $email = sanitize($conn, $_POST['email']);
        $type_of_business = sanitize($conn, $_POST['type_of_business']);
        $status = sanitize($conn, $_POST['status']);
        
        // Validate inputs
        $errors = [];
        
        if ($id <= 0) {
            $errors[] = "Invalid ID";
        }
        
        if (empty($customer_name)) {
            $errors[] = "Customer name is required";
        } elseif (strlen($customer_name) > 255) {
            $errors[] = "Customer name must not exceed 255 characters";
        }
        
        if (!empty($contact_person) && strlen($contact_person) > 255) {
            $errors[] = "Contact person name must not exceed 255 characters";
        }
        
        if (!empty($tin_number) && strlen($tin_number) > 50) {
            $errors[] = "TIN number must not exceed 50 characters";
        }
        
        if (!empty($vrn_number)) {
            if (strlen($vrn_number) > 50) {
                $errors[] = "VRN number must not exceed 50 characters";
            } elseif (!preg_match('/^[A-Za-z0-9\-]+$/', $vrn_number)) {
                $errors[] = "VRN number can only contain letters, numbers, and hyphens";
            }
        }
        
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Please enter a valid email address";
        }
        
        if (!array_key_exists($type_of_business, $business_types)) {
            $errors[] = "Invalid business type selected";
        }
        
        if (!in_array($status, ['Active', 'Inactive'])) {
            $errors[] = "Invalid status";
        }
        
        if (empty($errors)) {
            // Update customer
            $update_stmt = $conn->prepare("UPDATE customers SET customer_name = ?, contact_person = ?, tin_number = ?, vrn_number = ?, address = ?, email = ?, type_of_business = ?, status = ? WHERE id = ?");
            $update_stmt->bind_param("ssssssssi", $customer_name, $contact_person, $tin_number, $vrn_number, $address, $email, $type_of_business, $status, $id);
            
            if ($update_stmt->execute()) {
                $customers_message = "Customer updated successfully!";
                $customers_message_type = "success";
            } else {
                $customers_message = "Error updating customer: " . $conn->error;
                $customers_message_type = "danger";
            }
            $update_stmt->close();
        } else {
            $customers_message = implode("<br>", $errors);
            $customers_message_type = "danger";
        }
    }
    
    // Generate new CSRF token
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// PERMISSION: Check delete permission before processing delete operation
if (isset($_GET['customers_delete']) && !canDelete($conn, $user_role_id, $module_name)) {
    $customers_message = "You do not have permission to delete customers.";
    $customers_message_type = "danger";
} elseif (isset($_GET['customers_delete'])) {
    $id = (int)$_GET['customers_delete'];
    
    if ($id > 0) {
        $delete_stmt = $conn->prepare("DELETE FROM customers WHERE id = ?");
        $delete_stmt->bind_param("i", $id);
        
        if ($delete_stmt->execute()) {
            $customers_message = "Customer deleted successfully!";
            $customers_message_type = "success";
            
            // Check if table is empty and reset auto-increment
            $check_empty = $conn->query("SELECT COUNT(*) as count FROM customers");
            $row_count = $check_empty->fetch_assoc()['count'];
            
            if ($row_count == 0) {
                $conn->query("ALTER TABLE customers AUTO_INCREMENT = 1");
            }
        } else {
            $customers_message = "Error deleting customer: " . $conn->error;
            $customers_message_type = "danger";
        }
        $delete_stmt->close();
    }
}

// Get edit data if in edit mode (and user has edit permission)
$edit_mode = false;
$edit_data = null;

if (isset($_GET['customers_edit'])) {
    $edit_id = (int)$_GET['customers_edit'];
    // PERMISSION: Only allow edit if user has edit permission
    if ($edit_id > 0 && canEdit($conn, $user_role_id, $module_name)) {
        $edit_stmt = $conn->prepare("SELECT * FROM customers WHERE id = ?");
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
 * BUILD QUERY WITH SEARCH AND FILTER
 * ============================================================================
 */
$where_clause = "";
$params = [];
$types = "";

if (!empty($search)) {
    $where_clause .= " (customer_name LIKE ? OR email LIKE ? OR contact_person LIKE ? OR tin_number LIKE ? OR vrn_number LIKE ?) ";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sssss";
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

// Count total records for pagination
$count_query = "SELECT COUNT(*) as total FROM customers $where_clause";
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

// Fetch customers with pagination
$customers_query = "SELECT * FROM customers $where_clause ORDER BY id DESC LIMIT ? OFFSET ?";
$params[] = $records_per_page;
$params[] = $offset;
$types .= "ii";

$customers_stmt = $conn->prepare($customers_query);
$customers_stmt->bind_param($types, ...$params);
$customers_stmt->execute();
$customers_result = $customers_stmt->get_result();
?>

<!-- CUSTOMER MANAGEMENT TRANSLATIONS -->
<script>
// Customer Management translations for English and Swahili
const customer_translations = {
    en: {
        pageTitle: 'Customer Management',
        addNew: 'Add New Customer',
        editCustomer: 'Edit Customer',
        addCustomer: 'Add New Customer',
        id: 'ID',
        customerName: 'Customer Name',
        contactPerson: 'Contact Person',
        tinNumber: 'TIN Number',
        vrnNumber: 'VRN Number',
        address: 'Address',
        email: 'Email',
        typeOfBusiness: 'Type of Business',
        individual: 'Individual',
        soleProprietorship: 'Sole Proprietorship',
        partnership: 'Partnership',
        limitedCompany: 'Limited Company',
        corporation: 'Corporation',
        nonProfit: 'Non-Profit',
        government: 'Government',
        other: 'Other',
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
        confirmDelete: 'Are you sure you want to delete customer',
        confirmDeleteMsg: 'This action cannot be undone.',
        noData: 'No customers found. Click "Add New Customer" to create one.',
        customerNameRequired: 'Customer name is required!',
        emailInvalid: 'Please enter a valid email address!',
        loading: 'Loading...',
        search: 'Search',
        filter: 'Filter',
        allStatus: 'All Status',
        clear: 'Clear',
        totalRecords: 'Total Customers',
        records: 'records',
        customerNamePlaceholder: 'Enter customer/company name',
        contactPersonPlaceholder: 'Enter contact person name',
        tinNumberPlaceholder: 'Enter TIN number',
        vrnNumberPlaceholder: 'Enter VRN number',
        addressPlaceholder: 'Enter physical/postal address',
        emailPlaceholder: 'Enter email address',
        selectBusinessType: 'Select business type',
        page: 'Page',
        of: 'of',
        previous: 'Previous',
        next: 'Next'
    },
    sw: {
        pageTitle: 'Usimamizi wa Wateja',
        addNew: 'Ongeza Mteja Mpya',
        editCustomer: 'Hariri Mteja',
        addCustomer: 'Ongeza Mteja Mpya',
        id: 'Kitambulisho',
        customerName: 'Jina la Mteja',
        contactPerson: 'Mtu wa Kuwasiliana',
        tinNumber: 'Namba ya TIN',
        vrnNumber: 'Namba ya VRN',
        address: 'Anwani',
        email: 'Barua pepe',
        typeOfBusiness: 'Aina ya Biashara',
        individual: 'Mtu Binafsi',
        soleProprietorship: 'Umiliki wa Kipekee',
        partnership: 'Ushirikiano',
        limitedCompany: 'Kampuni ya Hisa',
        corporation: 'Shirika',
        nonProfit: 'Isiyo ya Faida',
        government: 'Serikali',
        other: 'Nyingine',
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
        confirmDelete: 'Una uhakika unataka kufuta mteja',
        confirmDeleteMsg: 'Kitendo hiki hakiwezi kutenguliwa.',
        noData: 'Hakuna wateja waliopatikana. Bofya "Ongeza Mteja Mpya" kuunda.',
        customerNameRequired: 'Jina la mteja linahitajika!',
        emailInvalid: 'Tafadhali ingiza barua pepe halali!',
        loading: 'Inapakia...',
        search: 'Tafuta',
        filter: 'Chuja',
        allStatus: 'Hali Zote',
        clear: 'Futa',
        totalRecords: 'Jumla ya Wateja',
        records: 'rekodi',
        customerNamePlaceholder: 'Weka jina la mteja/kampuni',
        contactPersonPlaceholder: 'Weka jina la mtu wa kuwasiliana',
        tinNumberPlaceholder: 'Weka namba ya TIN',
        vrnNumberPlaceholder: 'Weka namba ya VRN',
        addressPlaceholder: 'Weka anwani ya posta/makazi',
        emailPlaceholder: 'Weka barua pepe',
        selectBusinessType: 'Chagua aina ya biashara',
        page: 'Ukurasa',
        of: 'kati ya',
        previous: 'Iliyotangulia',
        next: 'Ijayo'
    }
};

// Current language (will be updated by System Settings module)
let currentCustomerLang = localStorage.getItem('preferredLanguage') || 'en';

// Function to update all translatable elements in customers module
function updateCustomerLanguage(lang) {
    currentCustomerLang = lang;
    const elements = document.querySelectorAll('[data-cust-lang]');
    
    elements.forEach(element => {
        const key = element.getAttribute('data-cust-lang');
        if (customer_translations[lang] && customer_translations[lang][key]) {
            if (element.tagName === 'INPUT' || element.tagName === 'TEXTAREA') {
                if (element.getAttribute('type') !== 'submit' && element.getAttribute('type') !== 'button') {
                    element.placeholder = customer_translations[lang][key];
                }
            } else if (element.tagName === 'OPTION') {
                element.textContent = customer_translations[lang][key];
            } else {
                element.textContent = customer_translations[lang][key];
            }
        }
    });
    
    // Update table header specifically if they have data-cust-lang attributes
    const thElements = document.querySelectorAll('th[data-cust-lang]');
    thElements.forEach(th => {
        const key = th.getAttribute('data-cust-lang');
        if (customer_translations[lang] && customer_translations[lang][key]) {
            th.textContent = customer_translations[lang][key];
        }
    });
    
    // Update empty state message
    const emptyState = document.querySelector('.cust-empty p');
    if (emptyState) {
        emptyState.textContent = customer_translations[lang].noData;
    }
    
    // Update form header based on edit mode
    const formHeader = document.querySelector('#custForm h3');
    if (formHeader) {
        const isEditMode = document.querySelector('input[name="id"]') !== null;
        formHeader.textContent = customer_translations[lang][isEditMode ? 'editCustomer' : 'addCustomer'];
    }
    
    // Update search input placeholder
    const searchInput = document.querySelector('input[name="search"]');
    if (searchInput) {
        searchInput.placeholder = customer_translations[lang].search;
    }
    
    // Update total records text
    const totalRecordsSpan = document.querySelector('#totalRecords');
    if (totalRecordsSpan) {
        totalRecordsSpan.textContent = customer_translations[lang].totalRecords;
    }
    
    // Update pagination buttons
    const prevBtn = document.querySelector('.cust-prev-btn');
    const nextBtn = document.querySelector('.cust-next-btn');
    if (prevBtn && prevBtn.tagName === 'A') prevBtn.innerHTML = `<i class="fas fa-chevron-left"></i> ${customer_translations[lang].previous}`;
    if (nextBtn && nextBtn.tagName === 'A') nextBtn.innerHTML = `${customer_translations[lang].next} <i class="fas fa-chevron-right"></i>`;
}

// Listen for language change events from System Settings module
document.addEventListener('DOMContentLoaded', function() {
    // Initial update
    updateCustomerLanguage(currentCustomerLang);
});

// This function will be called from System Settings module when language changes
window.updateCustomerLanguage = updateCustomerLanguage;
</script>

<style>
    /* Customer Module Styles - Using 'cust-' prefix to avoid conflicts */
    .cust-container {
        width: 100%;
    }

    .cust-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
        flex-wrap: wrap;
        gap: 16px;
    }

    .cust-header h2 {
        color: var(--gray-800);
        font-size: 24px;
        font-weight: 600;
        margin: 0;
    }

    .cust-btn {
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

    .cust-btn:hover {
        background: var(--brown-800);
        transform: translateY(-2px);
        box-shadow: var(--shadow-md);
    }

    .cust-btn-secondary {
        background: var(--gray-200);
        color: var(--gray-700);
    }

    .cust-btn-secondary:hover {
        background: var(--gray-300);
    }

    .cust-form {
        background: var(--gray-50);
        padding: 24px;
        border-radius: 12px;
        margin-bottom: 32px;
        border: 1px solid var(--gray-200);
        display: <?= $edit_mode ? 'block' : 'none' ?>;
    }

    .cust-form.show {
        display: block;
    }

    .cust-form h3 {
        color: var(--gray-800);
        font-size: 18px;
        font-weight: 600;
        margin-bottom: 20px;
    }

    .cust-form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 20px;
        margin-bottom: 20px;
    }

    .cust-form-group {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .cust-form-group-full {
        grid-column: 1 / -1;
    }

    .cust-form-group label {
        color: var(--gray-700);
        font-size: 14px;
        font-weight: 500;
    }

    .cust-form-group label .required {
        color: #dc3545;
        margin-left: 4px;
    }

    .cust-form-group input,
    .cust-form-group select,
    .cust-form-group textarea {
        padding: 10px 12px;
        border: 1px solid var(--gray-300);
        border-radius: 6px;
        font-size: 14px;
        transition: all 0.2s;
        font-family: inherit;
    }

    .cust-form-group textarea {
        resize: vertical;
        min-height: 80px;
    }

    .cust-form-group input:focus,
    .cust-form-group select:focus,
    .cust-form-group textarea:focus {
        outline: none;
        border-color: var(--brown-600);
        box-shadow: 0 0 0 3px rgba(123, 88, 63, 0.1);
    }

    .cust-form-actions {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
        margin-top: 20px;
    }

    .cust-alert {
        padding: 16px;
        border-radius: 8px;
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .cust-alert-success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }

    .cust-alert-danger {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }

    .cust-alert-close {
        background: none;
        border: none;
        font-size: 20px;
        cursor: pointer;
        color: inherit;
        opacity: 0.5;
    }

    .cust-alert-close:hover {
        opacity: 1;
    }

    /* Search and Filter Bar */
    .cust-search-bar {
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

    .cust-search-group {
        flex: 1;
        min-width: 200px;
    }

    .cust-search-group label {
        display: block;
        color: var(--gray-700);
        font-size: 13px;
        font-weight: 500;
        margin-bottom: 6px;
    }

    .cust-search-group input,
    .cust-search-group select {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid var(--gray-300);
        border-radius: 6px;
        font-size: 14px;
    }

    .cust-search-group input:focus,
    .cust-search-group select:focus {
        outline: none;
        border-color: var(--brown-600);
        box-shadow: 0 0 0 3px rgba(123, 88, 63, 0.1);
    }

    .cust-search-actions {
        display: flex;
        gap: 8px;
    }

    .cust-search-btn {
        padding: 10px 20px;
        background: var(--brown-700);
        color: white;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-weight: 500;
        transition: all 0.2s;
    }

    .cust-search-btn:hover {
        background: var(--brown-800);
    }

    .cust-clear-btn {
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

    .cust-clear-btn:hover {
        background: var(--gray-300);
    }

    /* Stats Bar */
    .cust-stats {
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

    .cust-stats-info {
        display: flex;
        align-items: center;
        gap: 8px;
        color: var(--gray-700);
        font-size: 14px;
    }

    .cust-stats-info i {
        color: var(--brown-600);
    }

    .cust-stats-count {
        font-weight: 700;
        color: var(--brown-700);
        font-size: 18px;
    }

    /* Table Styles */
    .cust-table-container {
        overflow-x: auto;
        background: white;
        border-radius: 12px;
        border: 1px solid var(--gray-200);
        margin-bottom: 20px;
    }

    .cust-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 1200px;
    }

    .cust-table th {
        background: var(--gray-50);
        color: var(--gray-700);
        font-weight: 600;
        font-size: 14px;
        padding: 16px;
        text-align: left;
        border-bottom: 2px solid var(--gray-200);
    }

    .cust-table td {
        padding: 16px;
        border-bottom: 1px solid var(--gray-200);
        color: var(--gray-700);
        font-size: 14px;
        vertical-align: middle;
    }

    .cust-table tr:hover {
        background: var(--gray-50);
    }

    .cust-status {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 500;
    }

    .cust-status-active {
        background: #d4edda;
        color: #155724;
    }

    .cust-status-inactive {
        background: #f8d7da;
        color: #721c24;
    }

    .cust-business-badge {
        background: var(--brown-100);
        color: var(--brown-800);
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 500;
        display: inline-block;
    }

    .cust-actions {
        display: flex;
        gap: 8px;
    }

    .cust-action-btn {
        padding: 6px 12px;
        border-radius: 4px;
        font-size: 13px;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        transition: all 0.2s;
    }

    .cust-action-edit {
        background: var(--brown-100);
        color: var(--brown-800);
    }

    .cust-action-edit:hover {
        background: var(--brown-200);
    }

    .cust-action-delete {
        background: #f8d7da;
        color: #721c24;
    }

    .cust-action-delete:hover {
        background: #f5c6cb;
    }

    .cust-loading {
        display: inline-block;
        width: 20px;
        height: 20px;
        border: 3px solid var(--gray-200);
        border-top-color: var(--brown-700);
        border-radius: 50%;
        animation: cust-spin 1s linear infinite;
    }

    @keyframes cust-spin {
        to { transform: rotate(360deg); }
    }

    .cust-empty {
        text-align: center;
        padding: 48px !important;
        color: var(--gray-500);
    }

    .cust-empty i {
        font-size: 48px;
        margin-bottom: 16px;
        color: var(--gray-400);
    }

    .cust-timestamp {
        font-size: 12px;
        color: var(--gray-500);
        margin-top: 4px;
    }

    /* Pagination */
    .cust-pagination {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 12px;
        margin-top: 20px;
    }

    .cust-pagination a,
    .cust-pagination span {
        padding: 8px 16px;
        border-radius: 6px;
        text-decoration: none;
        font-size: 14px;
        font-weight: 500;
        transition: all 0.2s;
    }

    .cust-pagination a {
        background: white;
        border: 1px solid var(--gray-300);
        color: var(--gray-700);
    }

    .cust-pagination a:hover {
        background: var(--brown-100);
        border-color: var(--brown-600);
        color: var(--brown-800);
    }

    .cust-pagination .active {
        background: var(--brown-700);
        border-color: var(--brown-700);
        color: white;
    }

    .cust-pagination .disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    @media (max-width: 768px) {
        .cust-search-bar {
            flex-direction: column;
        }
        
        .cust-search-actions {
            width: 100%;
        }
        
        .cust-search-btn,
        .cust-clear-btn {
            flex: 1;
            text-align: center;
            justify-content: center;
        }
        
        .cust-form-grid {
            grid-template-columns: 1fr;
        }
        
        .cust-pagination {
            flex-wrap: wrap;
        }
    }
</style>

<div class="cust-container">
    <!-- Header -->
    <div class="cust-header">
        <h2 data-cust-lang="pageTitle">Customer Management</h2>
        <?php if (canAdd($conn, $user_role_id, $module_name)): ?>
        <button class="cust-btn" onclick="toggleCustomerForm()" id="custToggleBtn">
            <i class="fas fa-plus"></i>
            <span data-cust-lang="addNew">Add New Customer</span>
        </button>
        <?php endif; ?>
    </div>

    <!-- Alert Messages -->
    <?php if (!empty($customers_message)): ?>
        <div class="cust-alert cust-alert-<?= $customers_message_type ?>">
            <?= $customers_message ?>
            <button class="cust-alert-close" onclick="this.parentElement.style.display='none'">&times;</button>
        </div>
    <?php endif; ?>

    <!-- Add/Edit Form - Only show if user has add or edit permission -->
    <?php if (canAdd($conn, $user_role_id, $module_name) || ($edit_mode && canEdit($conn, $user_role_id, $module_name))): ?>
    <div class="cust-form <?= $edit_mode ? 'show' : '' ?>" id="custForm">
        <h3 data-cust-lang="<?= $edit_mode ? 'editCustomer' : 'addCustomer' ?>">
            <?= $edit_mode ? 'Edit Customer' : 'Add New Customer' ?>
        </h3>
        
        <form method="POST" action="?page=customer-management" onsubmit="return validateCustomerForm()">
            <?php if ($edit_mode): ?>
                <input type="hidden" name="id" value="<?= $edit_data['id'] ?>">
            <?php endif; ?>
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            
            <div class="cust-form-grid">
                <div class="cust-form-group">
                    <label for="customer_name" data-cust-lang="customerName">Customer Name <span class="required">*</span></label>
                    <input type="text" id="customer_name" name="customer_name" 
                           value="<?= $edit_mode ? htmlspecialchars($edit_data['customer_name']) : '' ?>" 
                           data-cust-lang="customerNamePlaceholder" placeholder="Enter customer/company name" required>
                </div>
                
                <div class="cust-form-group">
                    <label for="contact_person" data-cust-lang="contactPerson">Contact Person</label>
                    <input type="text" id="contact_person" name="contact_person" 
                           value="<?= $edit_mode ? htmlspecialchars($edit_data['contact_person']) : '' ?>" 
                           data-cust-lang="contactPersonPlaceholder" placeholder="Enter contact person name">
                </div>
                
                <div class="cust-form-group">
                    <label for="tin_number" data-cust-lang="tinNumber">TIN Number</label>
                    <input type="text" id="tin_number" name="tin_number" 
                           value="<?= $edit_mode ? htmlspecialchars($edit_data['tin_number']) : '' ?>" 
                           data-cust-lang="tinNumberPlaceholder" placeholder="Enter TIN number">
                </div>
                
                <div class="cust-form-group">
                    <label for="vrn_number" data-cust-lang="vrnNumber">VRN Number</label>
                    <input type="text" id="vrn_number" name="vrn_number" 
                           value="<?= $edit_mode ? htmlspecialchars($edit_data['vrn_number']) : '' ?>" 
                           data-cust-lang="vrnNumberPlaceholder" placeholder="Enter VRN number">
                </div>
                
                <div class="cust-form-group">
                    <label for="email" data-cust-lang="email">Email</label>
                    <input type="email" id="email" name="email" 
                           value="<?= $edit_mode ? htmlspecialchars($edit_data['email']) : '' ?>" 
                           data-cust-lang="emailPlaceholder" placeholder="Enter email address">
                </div>
                
                <div class="cust-form-group">
                    <label for="type_of_business" data-cust-lang="typeOfBusiness">Type of Business <span class="required">*</span></label>
                    <select id="type_of_business" name="type_of_business" required>
                        <option value="" data-cust-lang="selectBusinessType">Select business type</option>
                        <?php foreach ($business_types as $value => $label): ?>
                            <option value="<?= $value ?>" 
                                <?= ($edit_mode && $edit_data['type_of_business'] == $value) ? 'selected' : '' ?>
                                data-cust-lang="<?= strtolower(str_replace(' ', '', $value)) ?>">
                                <?= $label ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="cust-form-group">
                    <label for="status" data-cust-lang="status">Status <span class="required">*</span></label>
                    <select id="status" name="status" required>
                        <option value="Active" data-cust-lang="active" <?= ($edit_mode && $edit_data['status'] == 'Active') ? 'selected' : '' ?>>Active</option>
                        <option value="Inactive" data-cust-lang="inactive" <?= ($edit_mode && $edit_data['status'] == 'Inactive') ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                
                <div class="cust-form-group cust-form-group-full">
                    <label for="address" data-cust-lang="address">Address</label>
                    <textarea id="address" name="address" data-cust-lang="addressPlaceholder" placeholder="Enter physical/postal address"><?= $edit_mode ? htmlspecialchars($edit_data['address']) : '' ?></textarea>
                </div>
            </div>
            
            <div class="cust-form-actions">
                <button type="submit" name="<?= $edit_mode ? 'customers_update' : 'customers_add' ?>" class="cust-btn">
                    <i class="fas fa-save"></i>
                    <span data-cust-lang="save">Save</span>
                </button>
                <a href="?page=customer-management" class="cust-btn cust-btn-secondary">
                    <i class="fas fa-times"></i>
                    <span data-cust-lang="cancel">Cancel</span>
                </a>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- Search and Filter Bar -->
    <div class="cust-search-bar">
        <form method="GET" action="?page=customer-management" style="display: contents;">
            <input type="hidden" name="page" value="customer-management">
            
            <div class="cust-search-group">
                <label for="search" data-cust-lang="search">Search</label>
                <input type="text" id="search" name="search" 
                       value="<?= htmlspecialchars($search) ?>" 
                       placeholder="Search by name, email, TIN or VRN">
            </div>
            
            <div class="cust-search-group">
                <label for="status_filter" data-cust-lang="filter">Status</label>
                <select id="status_filter" name="status_filter">
                    <option value="" data-cust-lang="allStatus">All Status</option>
                    <option value="Active" <?= $status_filter == 'Active' ? 'selected' : '' ?> data-cust-lang="active">Active</option>
                    <option value="Inactive" <?= $status_filter == 'Inactive' ? 'selected' : '' ?> data-cust-lang="inactive">Inactive</option>
                </select>
            </div>
            
            <div class="cust-search-actions">
                <button type="submit" class="cust-search-btn">
                    <i class="fas fa-search"></i> <span data-cust-lang="search">Search</span>
                </button>
                <a href="?page=customer-management" class="cust-clear-btn">
                    <i class="fas fa-times"></i> <span data-cust-lang="clear">Clear</span>
                </a>
            </div>
        </form>
    </div>

    <!-- Stats Bar -->
    <div class="cust-stats">
        <div class="cust-stats-info">
            <i class="fas fa-users"></i>
            <span id="totalRecords" data-cust-lang="totalRecords">Total Customers</span>
            <span>:</span>
            <span class="cust-stats-count"><?= $total_records ?></span>
            <span data-cust-lang="records">records</span>
        </div>
    </div>

    <!-- Data Table -->
    <div class="cust-table-container">
        <table class="cust-table">
            <thead>
                <tr>
                    <th data-cust-lang="id">ID</th>
                    <th data-cust-lang="customerName">Customer Name</th>
                    <th data-cust-lang="contactPerson">Contact Person</th>
                    <th data-cust-lang="tinNumber">TIN Number</th>
                    <th data-cust-lang="vrnNumber">VRN Number</th>
                    <th data-cust-lang="email">Email</th>
                    <th data-cust-lang="typeOfBusiness">Type of Business</th>
                    <th data-cust-lang="status">Status</th>
                    <th data-cust-lang="created">Created</th>
                    <th data-cust-lang="actions">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($customers_result && $customers_result->num_rows > 0): ?>
                    <?php while ($row = $customers_result->fetch_assoc()): ?>
                        <tr>
                            <td>#<?= $row['id'] ?></td>
                            <td><strong><?= htmlspecialchars($row['customer_name']) ?></strong></td>
                            <td><?= htmlspecialchars($row['contact_person']) ?: '—' ?></td>
                            <td><?= htmlspecialchars($row['tin_number']) ?: '—' ?></td>
                            <td><?= htmlspecialchars($row['vrn_number']) ?: '—' ?></td>
                            <td><?= htmlspecialchars($row['email']) ?: '—' ?></td>
                            <td>
                                <span class="cust-business-badge">
                                    <?= htmlspecialchars($row['type_of_business']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="cust-status cust-status-<?= strtolower($row['status']) ?>">
                                    <?= $row['status'] ?>
                                </span>
                            </td>
                            <td>
                                <?= date('d M Y H:i', strtotime($row['created_at'])) ?>
                                <div class="cust-timestamp"><?= date('d M Y', strtotime($row['created_at'])) ?></div>
                            </td>
                            <td>
                                <div class="cust-actions">
                                    <?php if (canEdit($conn, $user_role_id, $module_name)): ?>
                                    <a href="?page=customer-management&customers_edit=<?= $row['id'] ?>" 
                                       class="cust-action-btn cust-action-edit">
                                        <i class="fas fa-edit"></i> <span data-cust-lang="edit">Edit</span>
                                    </a>
                                    <?php endif; ?>
                                    <?php if (canDelete($conn, $user_role_id, $module_name)): ?>
                                    <a href="javascript:void(0)" 
                                       onclick="confirmDeleteCustomer(<?= $row['id'] ?>, '<?= htmlspecialchars(addslashes($row['customer_name'])) ?>')" 
                                       class="cust-action-btn cust-action-delete">
                                        <i class="fas fa-trash"></i> <span data-cust-lang="delete">Delete</span>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="10" class="cust-empty">
                            <i class="fas fa-users"></i>
                            <p data-cust-lang="noData">No customers found. Click "Add New Customer" to create one.</p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="cust-pagination">
        <?php if ($current_page > 1): ?>
            <a href="?page=customer-management&cust_page=<?= $current_page - 1 ?>&search=<?= urlencode($search) ?>&status_filter=<?= urlencode($status_filter) ?>" class="cust-prev-btn">
                <i class="fas fa-chevron-left"></i> <span data-cust-lang="previous">Previous</span>
            </a>
        <?php else: ?>
            <span class="disabled"><i class="fas fa-chevron-left"></i> <span data-cust-lang="previous">Previous</span></span>
        <?php endif; ?>
        
        <span><span data-cust-lang="page">Page</span> <?= $current_page ?> <span data-cust-lang="of">of</span> <?= $total_pages ?></span>
        
        <?php if ($current_page < $total_pages): ?>
            <a href="?page=customer-management&cust_page=<?= $current_page + 1 ?>&search=<?= urlencode($search) ?>&status_filter=<?= urlencode($status_filter) ?>" class="cust-next-btn">
                <span data-cust-lang="next">Next</span> <i class="fas fa-chevron-right"></i>
            </a>
        <?php else: ?>
            <span class="disabled"><span data-cust-lang="next">Next</span> <i class="fas fa-chevron-right"></i></span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<script>
    // Toggle form visibility
    function toggleCustomerForm() {
        const form = document.getElementById('custForm');
        const btn = document.getElementById('custToggleBtn');
        const lang = currentCustomerLang;
        
        if (form.classList.contains('show')) {
            form.classList.remove('show');
            btn.innerHTML = '<i class="fas fa-plus"></i> <span data-cust-lang="addNew">' + 
                (lang === 'en' ? 'Add New Customer' : 'Ongeza Mteja Mpya') + '</span>';
        } else {
            form.classList.add('show');
            btn.innerHTML = '<i class="fas fa-times"></i> <span data-cust-lang="cancel">' + 
                (lang === 'en' ? 'Cancel' : 'Ghairi') + '</span>';
            
            // Update form header when opening
            const formHeader = document.querySelector('#custForm h3');
            if (formHeader) {
                formHeader.textContent = customer_translations[lang].addCustomer;
            }
            
            // Clear any hidden ID field to ensure it's in add mode
            const hiddenId = document.querySelector('input[name="id"]');
            if (hiddenId) {
                hiddenId.remove();
            }
            
            // Clear form fields
            document.getElementById('customer_name').value = '';
            document.getElementById('contact_person').value = '';
            document.getElementById('tin_number').value = '';
            document.getElementById('vrn_number').value = '';
            document.getElementById('address').value = '';
            document.getElementById('email').value = '';
            document.getElementById('type_of_business').value = '';
            document.getElementById('status').value = 'Active';
            
            // Change button name to add mode
            const submitBtn = document.querySelector('button[name="customers_update"], button[name="customers_add"]');
            if (submitBtn) {
                submitBtn.name = 'customers_add';
            }
        }
        
        // Update all translatable elements after toggle
        updateCustomerLanguage(lang);
    }

    // Validate form before submission
    function validateCustomerForm() {
        const customerName = document.getElementById('customer_name').value.trim();
        const email = document.getElementById('email').value.trim();
        const vrnNumber = document.getElementById('vrn_number').value.trim();
        const typeOfBusiness = document.getElementById('type_of_business').value;
        const lang = currentCustomerLang;
        
        if (customerName === '') {
            alert(customer_translations[lang].customerNameRequired);
            document.getElementById('customer_name').focus();
            return false;
        }
        
        if (email !== '') {
            const emailPattern = /^[^\s@]+@([^\s@]+\.)+[^\s@]+$/;
            if (!emailPattern.test(email)) {
                alert(customer_translations[lang].emailInvalid);
                document.getElementById('email').focus();
                return false;
            }
        }
        
        if (vrnNumber !== '') {
            const vrnPattern = /^[A-Za-z0-9\-]+$/;
            if (!vrnPattern.test(vrnNumber)) {
                alert('VRN number can only contain letters, numbers, and hyphens');
                document.getElementById('vrn_number').focus();
                return false;
            }
            if (vrnNumber.length > 50) {
                alert('VRN number must not exceed 50 characters');
                document.getElementById('vrn_number').focus();
                return false;
            }
        }
        
        if (typeOfBusiness === '') {
            alert(customer_translations[lang].selectBusinessType);
            document.getElementById('type_of_business').focus();
            return false;
        }
        
        return true;
    }

    // Confirm delete with loading effect
    function confirmDeleteCustomer(id, name) {
        const lang = currentCustomerLang;
        const confirmMsg = customer_translations[lang].confirmDelete + ' "' + name + '"?\n' + 
                          customer_translations[lang].confirmDeleteMsg;
        
        if (confirm(confirmMsg)) {
            // Show loading effect on the clicked row
            const row = event.target.closest('tr');
            row.style.opacity = '0.5';
            
            // Create loading indicator
            const loadingDiv = document.createElement('div');
            loadingDiv.className = 'cust-loading';
            loadingDiv.style.position = 'absolute';
            loadingDiv.style.top = '50%';
            loadingDiv.style.left = '50%';
            loadingDiv.style.transform = 'translate(-50%, -50%)';
            row.style.position = 'relative';
            row.appendChild(loadingDiv);
            
            // Redirect after a small delay to show loading
            setTimeout(() => {
                window.location.href = `?page=customer-management&customers_delete=${id}`;
            }, 300);
        }
    }

    // Auto-hide alerts after 5 seconds
    setTimeout(() => {
        document.querySelectorAll('.cust-alert').forEach(alert => {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(() => alert.style.display = 'none', 500);
        });
    }, 5000);
</script>