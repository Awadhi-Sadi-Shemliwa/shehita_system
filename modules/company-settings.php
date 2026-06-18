<?php
/**
 * SHEHITA Enterprise Management System
 * Company Settings Module - Single Record Management
 *
 * This module handles:
 * - Automatic table creation if not exists (single record table)
 * - Display company information in a professional form
 * - Add/Update company settings (single record, update if exists)
 * - Logo upload with cropping (using Cropper.js)
 * - Full English/Swahili translation support
 * - Integration with existing permission system
 *
 * PERMISSION: Only users with edit permission can modify company settings
 * View permission is required to see the page
 * ENHANCED: Added VRN Number field
 * FIXED: Logo upload functionality (undefined variable $full_upload_path)
 * FIXED: Old logo deletion when uploading new logo
 * REFINED: Removed all sidebar-related code, no conflict with homepage.php
 */

// Prevent direct access
if (!defined('ACCESS_ALLOWED') && basename($_SERVER['PHP_SELF']) == basename(__FILE__)) {
    // This file is included, so we're safe
}

// Get database connection from parent
global $conn;

// PERMISSION: Get user role and check module permissions
$user_role_id = $_SESSION['role_id'] ?? 0;
$module_name = 'company-settings';

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
 * CREATE COMPANY SETTINGS TABLE IF NOT EXISTS (SINGLE RECORD)
 * ============================================================================
 */
// Schema note: the `company_settings` table is created centrally in config.php
// (in dependency order). This module assumes it already exists.

/**
 * ============================================================================
 * ADD VRN NUMBER COLUMN IF IT DOESN'T EXIST (FOR EXISTING INSTALLATIONS)
 * ============================================================================
 */
$check_column = $conn->query("SHOW COLUMNS FROM company_settings LIKE 'vrn_number'");
if ($check_column && $check_column->num_rows == 0) {
    $alter_sql = "ALTER TABLE company_settings ADD COLUMN vrn_number VARCHAR(50) DEFAULT NULL AFTER company_tin";
    $conn->query($alter_sql);
}

/**
 * ============================================================================
 * ENSURE ONLY ONE ROW EXISTS (Single Record Logic)
 * If there are multiple rows, keep only the first one and delete others
 * ============================================================================
 */
$check_multiple = $conn->query("SELECT COUNT(*) as count FROM company_settings");
$count = $check_multiple->fetch_assoc()['count'];

if ($count > 1) {
    // Keep the row with smallest ID, delete others
    $conn->query("DELETE FROM company_settings WHERE id > (SELECT MIN(id) FROM (SELECT MIN(id) as min_id FROM company_settings) as t)");
    // Reset auto_increment to next number
    $conn->query("ALTER TABLE company_settings AUTO_INCREMENT = " . ($count > 1 ? 2 : 1));
}

// Initialize variables for messages
$company_message = '';
$company_message_type = '';

// Upload directory for logos
$upload_dir = 'uploads/company/';
$full_upload_path = __DIR__ . '/../' . $upload_dir;

// Create upload directory if it doesn't exist
if (!file_exists($full_upload_path)) {
    mkdir($full_upload_path, 0777, true);
}

/**
 * ============================================================================
 * HELPER FUNCTION: Delete old logo file
 * ============================================================================
 */
function deleteOldLogo($logo_url) {
    if (empty($logo_url)) {
        return;
    }
    
    // Remove any leading slash or ../ from path
    $clean_path = ltrim($logo_url, './');
    
    // Try different path variations
    $paths_to_check = [
        __DIR__ . '/../' . $clean_path,           // Relative from project root
        $clean_path,                               // Direct path
        __DIR__ . '/' . $clean_path,               // From current directory
        '../' . $clean_path                         // One level up
    ];
    
    foreach ($paths_to_check as $path) {
        if (file_exists($path) && is_file($path)) {
            @unlink($path);
        }
    }
}

/**
 * ============================================================================
 * HANDLE FORM SUBMISSIONS
 * ============================================================================
 */

// PERMISSION: Check edit permission before processing update/add operation
if (isset($_POST['company_update']) && !canEdit($conn, $user_role_id, $module_name)) {
    $company_message = "You do not have permission to update company settings.";
    $company_message_type = "danger";
} elseif (isset($_POST['company_update'])) {
    // Get form data
    $company_name = sanitize($conn, $_POST['company_name']);
    $company_address = sanitize($conn, $_POST['company_address']);
    $company_email = sanitize($conn, $_POST['company_email']);
    $company_phone = sanitize($conn, $_POST['company_phone']);
    $company_tin = sanitize($conn, $_POST['company_tin']);
    $vrn_number = sanitize($conn, $_POST['vrn_number']);
    $currency_symbol = sanitize($conn, $_POST['currency_symbol']);
    $logo_data = isset($_POST['cropped_image_data']) ? $_POST['cropped_image_data'] : '';
    
    // Handle existing logo URL if no new image uploaded
    $existing_logo = isset($_POST['existing_logo']) ? $_POST['existing_logo'] : '';
    
    // Validate inputs
    $errors = [];
    
    if (empty($company_name)) {
        $errors[] = "Company name is required";
    }
    
    if (empty($company_address)) {
        $errors[] = "Company address is required";
    }
    
    if (empty($company_email)) {
        $errors[] = "Company email is required";
    } elseif (!filter_var($company_email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address";
    }
    
    if (empty($company_phone)) {
        $errors[] = "Company phone is required";
    }
    
    if (empty($company_tin)) {
        $errors[] = "Company TIN is required";
    }
    
    // VRN Number validation (optional field)
    if (!empty($vrn_number)) {
        if (strlen($vrn_number) > 50) {
            $errors[] = "VRN number must not exceed 50 characters";
        } elseif (!preg_match('/^[A-Za-z0-9\-]+$/', $vrn_number)) {
            $errors[] = "VRN number can only contain letters, numbers, and hyphens";
        }
    }
    
    if (empty($currency_symbol)) {
        $errors[] = "Currency symbol is required";
    }
    
    // Handle logo upload
    $logo_url = $existing_logo;
    
    if (!empty($logo_data) && strpos($logo_data, 'data:image/') === 0) {
        // Process base64 image upload
        $image_parts = explode(";base64,", $logo_data);
        if (count($image_parts) == 2) {
            $image_type_aux = explode("image/", $image_parts[0]);
            $image_type = $image_type_aux[1];
            $image_base64 = base64_decode($image_parts[1]);
            
            // Generate unique filename
            $filename = 'logo_company_' . date('Ymd_His') . '.jpg';
            $filepath = $full_upload_path . $filename;
            
            // Save file
            if (file_put_contents($filepath, $image_base64)) {
                // Delete old logo if it exists
                if (!empty($existing_logo)) {
                    deleteOldLogo($existing_logo);
                }
                $logo_url = $upload_dir . $filename;
            } else {
                $errors[] = "Failed to upload logo. Please check folder permissions.";
            }
        } else {
            $errors[] = "Invalid image data format";
        }
    }
    
    if (empty($errors)) {
        // Check if a record already exists
        $check_query = $conn->query("SELECT id, logo_url FROM company_settings LIMIT 1");
        
        if ($check_query && $check_query->num_rows > 0) {
            // Update existing record
            $existing = $check_query->fetch_assoc();
            
            // If we have a new logo URL and it's different from the existing one,
            // delete the old logo file (handled above in upload section)
            // Also, if logo was removed (no new logo and existing_logo is empty), delete the file
            if (empty($logo_url) && !empty($existing['logo_url'])) {
                deleteOldLogo($existing['logo_url']);
            }
            
            $update_stmt = $conn->prepare("UPDATE company_settings SET 
                company_name = ?, company_address = ?, company_email = ?, 
                company_phone = ?, company_tin = ?, vrn_number = ?, currency_symbol = ?, 
                logo_url = ? WHERE id = ?");
            $update_stmt->bind_param("ssssssssi", 
                $company_name, $company_address, $company_email,
                $company_phone, $company_tin, $vrn_number, $currency_symbol,
                $logo_url, $existing['id']);
            
            if ($update_stmt->execute()) {
                $company_message = "Company settings updated successfully!";
                $company_message_type = "success";
            } else {
                $company_message = "Error updating company settings: " . $conn->error;
                $company_message_type = "danger";
            }
            $update_stmt->close();
        } else {
            // Insert new record (no old logo to delete)
            $insert_stmt = $conn->prepare("INSERT INTO company_settings 
                (company_name, company_address, company_email, company_phone, 
                 company_tin, vrn_number, currency_symbol, logo_url) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $insert_stmt->bind_param("ssssssss", 
                $company_name, $company_address, $company_email,
                $company_phone, $company_tin, $vrn_number, $currency_symbol, $logo_url);
            
            if ($insert_stmt->execute()) {
                $company_message = "Company settings saved successfully!";
                $company_message_type = "success";
            } else {
                $company_message = "Error saving company settings: " . $conn->error;
                $company_message_type = "danger";
            }
            $insert_stmt->close();
        }
    } else {
        $company_message = implode("<br>", $errors);
        $company_message_type = "danger";
    }
}

/**
 * ============================================================================
 * FETCH EXISTING COMPANY DATA
 * ============================================================================
 */
$company_data = null;
$query = $conn->query("SELECT * FROM company_settings LIMIT 1");
if ($query && $query->num_rows > 0) {
    $company_data = $query->fetch_assoc();
}
?>

<!-- COMPANY SETTINGS TRANSLATIONS -->
<script>
// Company Settings translations for English and Swahili
const company_translations = {
    en: {
        pageTitle: 'Company Settings',
        pageSubtitle: 'Configure your company information and branding',
        companyInformation: 'Company Information',
        currentCompanyInfo: 'Current Company Information',
        companyName: 'Company Name',
        companyAddress: 'Company Address',
        companyEmail: 'Company Email',
        companyPhone: 'Company Phone',
        companyTIN: 'Company TIN',
        companyVRN: 'VRN Number',
        currencySymbol: 'Currency Symbol',
        uploadLogo: 'Upload Company Logo',
        chooseImage: 'Choose Image',
        cropLogo: 'Crop Logo',
        cropAndSave: 'Crop & Save',
        saveSettings: 'Save Settings',
        updateSettings: 'Update Settings',
        cancel: 'Cancel',
        clearForm: 'Clear Form',
        loadingCompanyData: 'Loading company data...',
        processing: 'Processing...',
        companyDataLoaded: 'Company data loaded successfully',
        companySaved: 'Company settings saved successfully',
        companyUpdated: 'Company settings updated successfully',
        companyLoadError: 'Error loading company data',
        companySaveError: 'Error saving company settings',
        logoRequired: 'Company logo is required',
        imageCropSuccess: 'Image cropped successfully',
        imageUploadSuccess: 'Image uploaded successfully',
        imageUploadError: 'Error uploading image',
        formCleared: 'Form cleared successfully',
        noDataFound: 'No company settings found. Please configure your company.',
        confirmReset: 'Are you sure you want to clear the form? Any unsaved changes will be lost.',
        nameRequired: 'Company name is required',
        addressRequired: 'Company address is required',
        emailRequired: 'Company email is required',
        emailInvalid: 'Please enter a valid email address',
        phoneRequired: 'Company phone is required',
        phoneInvalid: 'Please enter a valid phone number',
        tinRequired: 'Company TIN is required',
        vrnInvalid: 'VRN number can only contain letters, numbers, and hyphens',
        vrnMaxLength: 'VRN number must not exceed 50 characters',
        currencyRequired: 'Currency symbol is required',
        validationError: 'Please fill in all required fields',
        lastUpdated: 'Last Updated',
        addressPlaceholder: 'Enter full company address',
        namePlaceholder: 'Enter company name',
        emailPlaceholder: 'info@company.com',
        phonePlaceholder: '+255 XXX XXX XXX',
        tinPlaceholder: 'Tax Identification Number',
        vrnPlaceholder: 'Value Added Tax Registration Number (optional)',
        currencyPlaceholder: 'TZS',
        logoPreview: 'Logo Preview',
        selectImage: 'Select an image to upload'
    },
    sw: {
        pageTitle: 'Mipangilio ya Kampuni',
        pageSubtitle: 'Sanidi taarifa za kampuni yako na chapa',
        companyInformation: 'Taarifa za Kampuni',
        currentCompanyInfo: 'Taarifa za Sasa za Kampuni',
        companyName: 'Jina la Kampuni',
        companyAddress: 'Anwani ya Kampuni',
        companyEmail: 'Barua Pepe ya Kampuni',
        companyPhone: 'Namba ya Simu ya Kampuni',
        companyTIN: 'Namba ya Kodi ya Kampuni (TIN)',
        companyVRN: 'Namba ya VRN',
        currencySymbol: 'Alama ya Fedha',
        uploadLogo: 'Pakia Nembo ya Kampuni',
        chooseImage: 'Chagua Picha',
        cropLogo: 'Kata Nembo',
        cropAndSave: 'Kata na Hifadhi',
        saveSettings: 'Hifadhi Mipangilio',
        updateSettings: 'Sasisha Mipangilio',
        cancel: 'Ghairi',
        clearForm: 'Futa Fomu',
        loadingCompanyData: 'Inapakia taarifa za kampuni...',
        processing: 'Inashughulikia...',
        companyDataLoaded: 'Taarifa za kampuni zimepakiwa',
        companySaved: 'Mipangilio ya kampuni imehifadhiwa',
        companyUpdated: 'Mipangilio ya kampuni imesasishwa',
        companyLoadError: 'Hitilafu katika kupakia taarifa za kampuni',
        companySaveError: 'Hitilafu katika kuhifadhi mipangilio ya kampuni',
        logoRequired: 'Nembo ya kampuni inahitajika',
        imageCropSuccess: 'Picha imekatwa kwa mafanikio',
        imageUploadSuccess: 'Picha imepakiwa kwa mafanikio',
        imageUploadError: 'Hitilafu katika kupakia picha',
        formCleared: 'Fomu imefutwa kwa mafanikio',
        noDataFound: 'Hakuna mipangilio ya kampuni. Tafadhali sanidi kampuni yako.',
        confirmReset: 'Una uhakika unataka kufuta fomu? Taarifa zozote ambazo hazijahifadhiwa zitapotea.',
        nameRequired: 'Jina la kampuni linahitajika',
        addressRequired: 'Anwani ya kampuni inahitajika',
        emailRequired: 'Barua pepe ya kampuni inahitajika',
        emailInvalid: 'Tafadhali weka anwani sahihi ya barua pepe',
        phoneRequired: 'Namba ya simu ya kampuni inahitajika',
        phoneInvalid: 'Tafadhali weka namba sahihi ya simu',
        tinRequired: 'Namba ya TIN ya kampuni inahitajika',
        vrnInvalid: 'Namba ya VRN inaweza kuwa na herufi, namba, na viungo tu',
        vrnMaxLength: 'Namba ya VRN haipaswi kuzidi herufi 50',
        currencyRequired: 'Alama ya fedha inahitajika',
        validationError: 'Tafadhali jaza sehemu zote zinazohitajika',
        lastUpdated: 'Ilisasishwa Mwisho',
        addressPlaceholder: 'Weka anwani kamili ya kampuni',
        namePlaceholder: 'Weka jina la kampuni',
        emailPlaceholder: 'info@kampuni.com',
        phonePlaceholder: '+255 XXX XXX XXX',
        tinPlaceholder: 'Namba ya Kitambulisho cha Kodi',
        vrnPlaceholder: 'Namba ya Usajili wa Kodi ya Ongezeko la Thamani (si lazima)',
        currencyPlaceholder: 'TZS',
        logoPreview: 'Muundo wa Nembo',
        selectImage: 'Chagua picha ya kupakia'
    }
};

// Current language (will be updated by homepage.js)
let currentCompanyLang = localStorage.getItem('preferredLanguage') || 'en';

// Function to update all translatable elements in company settings module
function updateCompanyLanguage(lang) {
    currentCompanyLang = lang;
    const elements = document.querySelectorAll('[data-company-lang]');
    
    elements.forEach(element => {
        const key = element.getAttribute('data-company-lang');
        if (company_translations[lang] && company_translations[lang][key]) {
            if (element.tagName === 'INPUT' || element.tagName === 'TEXTAREA') {
                if (element.getAttribute('type') !== 'submit' && element.getAttribute('type') !== 'button') {
                    element.placeholder = company_translations[lang][key];
                } else {
                    element.textContent = company_translations[lang][key];
                }
            } else if (element.tagName === 'OPTION') {
                element.textContent = company_translations[lang][key];
            } else {
                element.textContent = company_translations[lang][key];
            }
        }
    });
    
    // Update save button text based on whether data exists
    const saveBtn = document.querySelector('#company_saveBtn span');
    if (saveBtn) {
        const hasData = <?= $company_data ? 'true' : 'false' ?>;
        saveBtn.textContent = company_translations[lang][hasData ? 'updateSettings' : 'saveSettings'];
    }
    
    // Update empty state message if visible
    const emptyState = document.querySelector('.company-empty p');
    if (emptyState) {
        emptyState.textContent = company_translations[lang].noDataFound;
    }
    
    // Update info card headers
    const infoHeader = document.querySelector('#company_infoCard h4 span');
    if (infoHeader) {
        infoHeader.textContent = company_translations[lang].currentCompanyInfo;
    }
}

// Listen for language change events from homepage
document.addEventListener('DOMContentLoaded', function() {
    // Initial update
    updateCompanyLanguage(currentCompanyLang);
});

// This function will be called from homepage.js when language changes
window.updateCompanyLanguage = updateCompanyLanguage;
</script>

<style>
    /* Company Settings Module Styles - Professional Card Design */
    /* Using company- prefix to avoid conflicts with homepage.php */
    .company-container {
        width: 100%;
        max-width: 1000px;
        margin: 0 auto;
        animation: company-fadeIn 0.3s ease-out;
    }
    
    @keyframes company-fadeIn {
        from {
            opacity: 0;
            transform: translateY(10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    /* Header Styles */
    .company-header {
        margin-bottom: 28px;
    }
    
    .company-main-heading {
        font-family: 'Montserrat', sans-serif;
        font-weight: 700;
        font-size: 2rem;
        color: var(--brown-800);
        margin-bottom: 8px;
        letter-spacing: -0.02em;
    }
    
    .company-subheading {
        font-family: 'Poppins', sans-serif;
        color: var(--gray-500);
        font-size: 1rem;
    }
    
    /* Alert Container */
    #company_alertContainer {
        margin-bottom: 24px;
    }
    
    .company-alert {
        padding: 16px 20px;
        border-radius: 16px;
        margin-bottom: 20px;
        font-family: 'Poppins', sans-serif;
        font-size: 0.95rem;
        display: flex;
        align-items: center;
        gap: 12px;
        animation: company-slideDown 0.3s ease-out;
    }
    
    @keyframes company-slideDown {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    .company-alert-success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }
    
    .company-alert-danger {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }
    
    .company-alert-warning {
        background: #fff3cd;
        color: #856404;
        border: 1px solid #ffeeba;
    }
    
    .company-alert-info {
        background: #d1ecf1;
        color: #0c5460;
        border: 1px solid #bee5eb;
    }
    
    /* Form Card */
    .company-form-card {
        background: white;
        border-radius: 20px;
        box-shadow: var(--shadow-md);
        border: 1px solid var(--gray-200);
        overflow: hidden;
        margin-bottom: 28px;
        transition: all 0.2s;
    }
    
    .company-form-card:hover {
        box-shadow: var(--shadow-lg);
        border-color: var(--brown-200);
    }
    
    .company-form-header {
        background: linear-gradient(135deg, #faf7f2 0%, #f5efe8 100%);
        padding: 20px 28px;
        border-bottom: 2px solid var(--gray-200);
    }
    
    .company-form-title {
        font-family: 'Montserrat', sans-serif;
        font-weight: 700;
        font-size: 1.3rem;
        color: var(--brown-800);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .company-form-title i {
        color: var(--brown-700);
    }
    
    .company-form-body {
        padding: 32px;
    }
    
    /* Form Groups */
    .company-form-group {
        margin-bottom: 24px;
    }
    
    .company-form-label {
        display: block;
        margin-bottom: 8px;
        font-family: 'Poppins', sans-serif;
        font-weight: 500;
        font-size: 0.95rem;
        color: var(--brown-800);
    }
    
    .company-required::after {
        content: " *";
        color: #dc3545;
        font-weight: 600;
    }
    
    .company-form-control {
        border: 2px solid var(--gray-200) !important;
        border-radius: 12px !important;
        padding: 12px 16px !important;
        font-size: 0.95rem !important;
        transition: all 0.2s !important;
        background: white !important;
        width: 100%;
        font-family: 'Roboto', sans-serif;
    }
    
    .company-form-control:hover {
        border-color: var(--brown-300) !important;
        background: #fcf9f6 !important;
    }
    
    .company-form-control:focus {
        border-color: var(--brown-700) !important;
        outline: none !important;
        box-shadow: 0 0 0 4px rgba(123, 88, 63, 0.1) !important;
        background: white !important;
    }
    
    .company-form-control.error {
        border-color: #dc3545 !important;
    }
    
    .company-form-textarea {
        min-height: 100px;
        resize: vertical;
    }
    
    .company-form-error {
        color: #dc3545;
        font-size: 0.8rem;
        margin-top: 6px;
        display: none;
        padding-left: 4px;
    }
    
    .company-form-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 20px;
    }
    
    /* Logo Upload Section */
    .company-logo-section {
        background: linear-gradient(135deg, #faf7f2 0%, #f5efe8 100%);
        border-radius: 16px;
        padding: 28px;
        margin-bottom: 28px;
        border: 2px dashed var(--gray-200);
        transition: all 0.2s;
        text-align: center;
    }
    
    .company-logo-section:hover {
        border-color: var(--brown-300);
        background: #f5efe8;
    }
    
    .company-logo-preview-container {
        display: flex;
        justify-content: center;
        margin-bottom: 20px;
    }
    
    .company-logo-preview {
        width: 200px;
        height: 200px;
        border-radius: 16px;
        border: 3px solid var(--gray-200);
        overflow: hidden;
        background: white;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: var(--shadow-md);
        transition: all 0.2s;
    }
    
    .company-logo-preview:hover {
        border-color: var(--brown-700);
        transform: scale(1.02);
    }
    
    .company-logo-preview img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    
    .company-logo-placeholder {
        width: 100%;
        height: 100%;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        color: var(--gray-500);
        background: #f5efe8;
    }
    
    .company-logo-placeholder i {
        font-size: 3.5rem;
        margin-bottom: 12px;
        color: var(--brown-300);
    }
    
    .company-logo-placeholder p {
        font-size: 0.9rem;
        font-weight: 500;
    }
    
    .company-file-input-container {
        position: relative;
    }
    
    .company-file-input {
        position: absolute;
        width: 0.1px;
        height: 0.1px;
        opacity: 0;
        overflow: hidden;
        z-index: -1;
    }
    
    .company-file-label {
        display: inline-block;
        padding: 12px 32px;
        background: linear-gradient(135deg, var(--brown-700) 0%, var(--brown-800) 100%);
        color: white;
        border-radius: 30px;
        font-family: 'Poppins', sans-serif;
        font-weight: 600;
        font-size: 0.95rem;
        cursor: pointer;
        transition: all 0.2s;
        box-shadow: var(--shadow-sm);
        border: none;
    }
    
    .company-file-label:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-md);
        filter: brightness(1.05);
    }
    
    .company-file-label i {
        margin-right: 8px;
    }
    
    /* Buttons */
    .company-form-actions {
        display: flex;
        gap: 16px;
        justify-content: flex-end;
        margin-top: 32px;
        padding-top: 24px;
        border-top: 2px solid var(--gray-200);
    }
    
    .company-btn {
        font-family: 'Poppins', sans-serif;
        font-weight: 500;
        padding: 12px 28px;
        border: none;
        border-radius: 30px;
        cursor: pointer;
        transition: all 0.2s;
        font-size: 0.95rem;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        letter-spacing: 0.02em;
    }
    
    .company-btn-primary {
        background: linear-gradient(135deg, var(--brown-700) 0%, var(--brown-800) 100%);
        color: white;
        box-shadow: var(--shadow-sm);
    }
    
    .company-btn-primary:hover:not(:disabled) {
        transform: translateY(-2px);
        box-shadow: var(--shadow-md);
        filter: brightness(1.05);
    }
    
    .company-btn-primary:active:not(:disabled) {
        transform: translateY(0);
    }
    
    .company-btn-outline {
        background: transparent;
        border: 2px solid var(--brown-300);
        color: var(--brown-700);
    }
    
    .company-btn-outline:hover:not(:disabled) {
        background: rgba(123, 88, 63, 0.04);
        border-color: var(--brown-700);
        transform: translateY(-2px);
    }
    
    .company-btn-secondary {
        background: white;
        border: 2px solid var(--gray-200);
        color: var(--gray-500);
    }
    
    .company-btn-secondary:hover:not(:disabled) {
        background: var(--gray-100);
        border-color: var(--brown-300);
        color: var(--brown-700);
    }
    
    .company-btn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }
    
    .company-btn-loading {
        position: relative;
        pointer-events: none;
        opacity: 0.8;
    }
    
    .company-btn-spinner {
        display: inline-block;
        width: 16px;
        height: 16px;
        border: 2px solid rgba(255,255,255,0.3);
        border-top: 2px solid white;
        border-radius: 50%;
        animation: company-spin 0.6s linear infinite;
        margin-right: 8px;
    }
    
    /* Info Card */
    .company-info-card {
        background: white;
        border-radius: 20px;
        padding: 24px;
        border: 1px solid var(--gray-200);
        box-shadow: var(--shadow-sm);
        transition: all 0.2s;
    }
    
    .company-info-card:hover {
        box-shadow: var(--shadow-md);
        border-color: var(--brown-200);
    }
    
    .company-info-header {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 20px;
        padding-bottom: 16px;
        border-bottom: 2px solid var(--gray-200);
    }
    
    .company-info-header i {
        font-size: 1.3rem;
        color: var(--brown-700);
    }
    
    .company-info-header h4 {
        font-family: 'Poppins', sans-serif;
        font-weight: 600;
        font-size: 1.1rem;
        color: var(--gray-800);
        margin: 0;
    }
    
    .company-info-item {
        display: flex;
        justify-content: space-between;
        padding: 10px 0;
        border-bottom: 1px dashed var(--gray-200);
    }
    
    .company-info-item:last-child {
        border-bottom: none;
    }
    
    .company-info-label {
        color: var(--gray-500);
        font-size: 0.95rem;
    }
    
    .company-info-value {
        font-weight: 600;
        color: var(--gray-800);
    }
    
    /* Processing Overlay */
    .company-processing-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(250, 247, 245, 0.95);
        backdrop-filter: blur(10px);
        display: none;
        justify-content: center;
        align-items: center;
        z-index: 2000;
        flex-direction: column;
        gap: 20px;
    }
    
    .company-spinner {
        width: 48px;
        height: 48px;
        border: 4px solid #f0e8e0;
        border-top: 4px solid var(--brown-700);
        border-radius: 50%;
        animation: company-spin 0.8s cubic-bezier(0.4, 0, 0.2, 1) infinite;
    }
    
    @keyframes company-spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    
    /* Loading State */
    .company-loading-container {
        text-align: center;
        padding: 60px;
        background: white;
        border-radius: 20px;
        box-shadow: var(--shadow-md);
        margin: 20px 0;
    }
    
    .company-loading-spinner {
        width: 48px;
        height: 48px;
        border: 4px solid var(--gray-200);
        border-top: 4px solid var(--brown-700);
        border-radius: 50%;
        animation: company-spin 0.8s linear infinite;
        margin: 0 auto 20px;
    }
    
    .company-loading-text {
        color: var(--gray-500);
        font-size: 1rem;
        font-weight: 500;
    }
    
    /* Cropper Modal */
    .company-modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(44, 62, 47, 0.5);
        backdrop-filter: blur(4px);
        display: none;
        justify-content: center;
        align-items: center;
        z-index: 2100;
        padding: 20px;
    }
    
    .company-modal {
        background: white;
        border-radius: 24px;
        width: 100%;
        max-width: 800px;
        max-height: 90vh;
        overflow: hidden;
        box-shadow: var(--shadow-lg);
        border: 1px solid var(--gray-200);
        animation: company-modalFadeIn 0.2s ease-out;
    }
    
    @keyframes company-modalFadeIn {
        from {
            opacity: 0;
            transform: scale(0.95);
        }
        to {
            opacity: 1;
            transform: scale(1);
        }
    }
    
    .company-modal-header {
        padding: 20px 28px;
        border-bottom: 2px solid var(--gray-200);
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: linear-gradient(135deg, #faf7f2 0%, #f5efe8 100%);
    }
    
    .company-modal-title {
        font-family: 'Montserrat', sans-serif;
        font-weight: 600;
        font-size: 1.3rem;
        color: var(--brown-800);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .company-modal-close {
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
    
    .company-modal-close:hover {
        background: rgba(139, 90, 43, 0.1);
        color: #dc3545;
    }
    
    .company-modal-body {
        padding: 28px;
    }
    
    .company-modal-footer {
        padding: 20px 28px;
        border-top: 2px solid var(--gray-200);
        display: flex;
        justify-content: flex-end;
        gap: 12px;
        background: #faf7f5;
    }
    
    .company-img-container {
        max-width: 100%;
        max-height: 400px;
        overflow: hidden;
        text-align: center;
    }
    
    .company-img-container img {
        max-width: 100%;
    }
    
    /* Responsive */
    @media (max-width: 768px) {
        .company-main-heading {
            font-size: 1.6rem;
        }
        
        .company-form-body {
            padding: 20px;
        }
        
        .company-form-row {
            grid-template-columns: 1fr;
            gap: 16px;
        }
        
        .company-form-actions {
            flex-direction: column;
        }
        
        .company-btn {
            width: 100%;
            justify-content: center;
        }
        
        .company-logo-preview {
            width: 150px;
            height: 150px;
        }
        
        .company-modal-footer {
            flex-direction: column;
        }
        
        .company-modal-footer .company-btn {
            width: 100%;
        }
    }
    
    @media (max-width: 480px) {
        .company-main-heading {
            font-size: 1.4rem;
        }
        
        .company-form-title {
            font-size: 1.1rem;
        }
    }
</style>

<div class="company-container">
    <!-- Header -->
    <div class="company-header">
        <h1 class="company-main-heading" data-company-lang="pageTitle">Company Settings</h1>
        <p class="company-subheading" data-company-lang="pageSubtitle">Configure your company information and branding</p>
    </div>

    <!-- Alert Container -->
    <div id="company_alertContainer">
        <?php if (!empty($company_message)): ?>
            <div class="company-alert company-alert-<?= $company_message_type ?>">
                <i class="fas <?= $company_message_type == 'success' ? 'fa-check-circle' : ($company_message_type == 'danger' ? 'fa-exclamation-circle' : 'fa-info-circle') ?>"></i>
                <?= $company_message ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Main Form Card -->
    <div class="company-form-card" id="company_formCard">
        <div class="company-form-header">
            <h3 class="company-form-title">
                <i class="fas fa-building"></i>
                <span data-company-lang="companyInformation">Company Information</span>
            </h3>
        </div>
        
        <div class="company-form-body">
            <form method="POST" action="?page=company-settings" id="company_companyForm" enctype="multipart/form-data">
                <input type="hidden" name="cropped_image_data" id="cropped_image_data" value="">
                <input type="hidden" name="existing_logo" id="existing_logo" value="<?= htmlspecialchars($company_data['logo_url'] ?? '') ?>">
                
                <!-- Logo Upload Section -->
                <div class="company-logo-section">
                    <div class="company-logo-preview-container">
                        <div id="company_logoPreview" class="company-logo-preview">
                            <?php if ($company_data && !empty($company_data['logo_url'])): ?>
                                <img src="<?= htmlspecialchars($company_data['logo_url']) ?>" alt="Company Logo">
                            <?php else: ?>
                                <div class="company-logo-placeholder">
                                    <i class="fas fa-cloud-upload-alt"></i>
                                    <p data-company-lang="uploadLogo">Upload Company Logo</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="company-file-input-container">
                        <input type="file" id="company_logo" class="company-file-input" accept="image/*">
                        <label for="company_logo" class="company-file-label">
                            <i class="fas fa-camera"></i>
                            <span data-company-lang="chooseImage">Choose Image</span>
                        </label>
                    </div>
                </div>
                
                <!-- Company Details -->
                <div class="company-form-row">
                    <!-- Company Name -->
                    <div class="company-form-group">
                        <label for="company_name" class="company-form-label company-required" data-company-lang="companyName">Company Name</label>
                        <input type="text" id="company_name" name="company_name" class="company-form-control" 
                               value="<?= htmlspecialchars($company_data['company_name'] ?? '') ?>"
                               required data-company-lang="namePlaceholder" placeholder="Enter company name">
                        <div class="company-form-error" id="company_nameError">
                            <i class="fas fa-exclamation-circle"></i> 
                            <span id="company_nameErrorText" data-company-lang="nameRequired">Company name is required</span>
                        </div>
                    </div>
                    
                    <!-- Company Email -->
                    <div class="company-form-group">
                        <label for="company_email" class="company-form-label company-required" data-company-lang="companyEmail">Company Email</label>
                        <input type="email" id="company_email" name="company_email" class="company-form-control" 
                               value="<?= htmlspecialchars($company_data['company_email'] ?? '') ?>"
                               required data-company-lang="emailPlaceholder" placeholder="info@company.com">
                        <div class="company-form-error" id="company_emailError">
                            <i class="fas fa-exclamation-circle"></i> 
                            <span id="company_emailErrorText" data-company-lang="emailInvalid">Please enter a valid email address</span>
                        </div>
                    </div>
                </div>
                
                <div class="company-form-row">
                    <!-- Company Phone -->
                    <div class="company-form-group">
                        <label for="company_phone" class="company-form-label company-required" data-company-lang="companyPhone">Company Phone</label>
                        <input type="tel" id="company_phone" name="company_phone" class="company-form-control" 
                               value="<?= htmlspecialchars($company_data['company_phone'] ?? '') ?>"
                               required data-company-lang="phonePlaceholder" placeholder="+255 XXX XXX XXX">
                        <div class="company-form-error" id="company_phoneError">
                            <i class="fas fa-exclamation-circle"></i> 
                            <span id="company_phoneErrorText" data-company-lang="phoneInvalid">Please enter a valid phone number</span>
                        </div>
                    </div>
                    
                    <!-- Company TIN -->
                    <div class="company-form-group">
                        <label for="company_tin" class="company-form-label company-required" data-company-lang="companyTIN">Company TIN</label>
                        <input type="text" id="company_tin" name="company_tin" class="company-form-control" 
                               value="<?= htmlspecialchars($company_data['company_tin'] ?? '') ?>"
                               required data-company-lang="tinPlaceholder" placeholder="Tax Identification Number">
                        <div class="company-form-error" id="company_tinError">
                            <i class="fas fa-exclamation-circle"></i> 
                            <span id="company_tinErrorText" data-company-lang="tinRequired">Company TIN is required</span>
                        </div>
                    </div>
                </div>
                
                <div class="company-form-row">
                    <!-- VRN Number (New Field) -->
                    <div class="company-form-group">
                        <label for="vrn_number" class="company-form-label" data-company-lang="companyVRN">VRN Number</label>
                        <input type="text" id="vrn_number" name="vrn_number" class="company-form-control" 
                               value="<?= htmlspecialchars($company_data['vrn_number'] ?? '') ?>"
                               maxlength="50" data-company-lang="vrnPlaceholder" placeholder="Value Added Tax Registration Number (optional)">
                        <div class="company-form-error" id="company_vrnError">
                            <i class="fas fa-exclamation-circle"></i> 
                            <span id="company_vrnErrorText" data-company-lang="vrnInvalid">VRN number can only contain letters, numbers, and hyphens</span>
                        </div>
                    </div>
                    
                    <!-- Currency Symbol -->
                    <div class="company-form-group">
                        <label for="currency_symbol" class="company-form-label company-required" data-company-lang="currencySymbol">Currency Symbol</label>
                        <input type="text" id="currency_symbol" name="currency_symbol" class="company-form-control" 
                               value="<?= htmlspecialchars($company_data['currency_symbol'] ?? 'TZS') ?>"
                               required maxlength="5" data-company-lang="currencyPlaceholder" placeholder="TZS">
                        <div class="company-form-error" id="company_currencySymbolError">
                            <i class="fas fa-exclamation-circle"></i> 
                            <span id="company_currencySymbolErrorText" data-company-lang="currencyRequired">Currency symbol is required</span>
                        </div>
                    </div>
                </div>
                
                <!-- Company Address (Full Width) -->
                <div class="company-form-group">
                    <label for="company_address" class="company-form-label company-required" data-company-lang="companyAddress">Company Address</label>
                    <textarea id="company_address" name="company_address" class="company-form-control company-form-textarea" 
                              required data-company-lang="addressPlaceholder" placeholder="Enter full company address"><?= htmlspecialchars($company_data['company_address'] ?? '') ?></textarea>
                    <div class="company-form-error" id="company_addressError">
                        <i class="fas fa-exclamation-circle"></i> 
                        <span id="company_addressErrorText" data-company-lang="addressRequired">Company address is required</span>
                    </div>
                </div>
                
                <!-- Form Actions -->
                <div class="company-form-actions">
                    <?php if (canEdit($conn, $user_role_id, $module_name)): ?>
                        <button type="button" class="company-btn company-btn-secondary" id="company_clearBtn">
                            <i class="fas fa-undo"></i> <span data-company-lang="clearForm">Clear Form</span>
                        </button>
                        <button type="submit" name="company_update" class="company-btn company-btn-primary" id="company_saveBtn">
                            <i class="fas fa-save"></i> 
                            <span data-company-lang="<?= $company_data ? 'updateSettings' : 'saveSettings' ?>">
                                <?= $company_data ? 'Update Settings' : 'Save Settings' ?>
                            </span>
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Company Info Card (only show if data exists) -->
    <?php if ($company_data): ?>
    <div class="company-info-card" id="company_infoCard">
        <div class="company-info-header">
            <i class="fas fa-info-circle"></i>
            <h4><span data-company-lang="currentCompanyInfo">Current Company Information</span></h4>
        </div>
        <div id="company_infoContent">
            <div class="company-info-item">
                <span class="company-info-label" data-company-lang="companyName">Company Name:</span>
                <span class="company-info-value"><?= htmlspecialchars($company_data['company_name']) ?></span>
            </div>
            <div class="company-info-item">
                <span class="company-info-label" data-company-lang="companyEmail">Company Email:</span>
                <span class="company-info-value"><?= htmlspecialchars($company_data['company_email']) ?></span>
            </div>
            <div class="company-info-item">
                <span class="company-info-label" data-company-lang="companyPhone">Company Phone:</span>
                <span class="company-info-value"><?= htmlspecialchars($company_data['company_phone']) ?></span>
            </div>
            <div class="company-info-item">
                <span class="company-info-label" data-company-lang="companyTIN">Company TIN:</span>
                <span class="company-info-value"><?= htmlspecialchars($company_data['company_tin']) ?></span>
            </div>
            <div class="company-info-item">
                <span class="company-info-label" data-company-lang="companyVRN">VRN Number:</span>
                <span class="company-info-value"><?= htmlspecialchars($company_data['vrn_number'] ?? '—') ?></span>
            </div>
            <div class="company-info-item">
                <span class="company-info-label" data-company-lang="currencySymbol">Currency Symbol:</span>
                <span class="company-info-value"><?= htmlspecialchars($company_data['currency_symbol']) ?></span>
            </div>
            <div class="company-info-item">
                <span class="company-info-label" data-company-lang="lastUpdated">Last Updated:</span>
                <span class="company-info-value"><?= date('d M Y H:i', strtotime($company_data['updated_at'])) ?></span>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Cropper Modal -->
<div id="company_cropperModal" class="company-modal-overlay">
    <div class="company-modal">
        <div class="company-modal-header">
            <h3 class="company-modal-title">
                <i class="fas fa-crop-alt"></i> <span data-company-lang="cropLogo">Crop Logo</span>
            </h3>
            <button type="button" class="company-modal-close" id="company_closeModalBtn">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="company-modal-body">
            <div class="company-img-container">
                <img id="company_imageToCrop" src="" alt="Image to crop">
            </div>
        </div>
        <div class="company-modal-footer">
            <button type="button" class="company-btn company-btn-outline" id="company_cancelCropBtn">
                <i class="fas fa-times"></i> <span data-company-lang="cancel">Cancel</span>
            </button>
            <button type="button" class="company-btn company-btn-primary" id="company_cropAndSaveBtn">
                <i class="fas fa-crop"></i> <span data-company-lang="cropAndSave">Crop & Save</span>
            </button>
        </div>
    </div>
</div>

<!-- Processing Overlay -->
<div id="company_processingOverlay" class="company-processing-overlay">
    <div class="company-spinner"></div>
    <div data-company-lang="processing">Processing...</div>
</div>

<!-- Cropper.js Script -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.12/cropper.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.12/cropper.min.js"></script>

<script>
    // ===== GLOBAL VARIABLES =====
    let company_cropper = null;
    let company_croppedImageDataURL = null;
    
    // ===== UTILITY FUNCTIONS =====
    function company_showProcessing() {
        document.getElementById('company_processingOverlay').style.display = 'flex';
    }
    
    function company_hideProcessing() {
        document.getElementById('company_processingOverlay').style.display = 'none';
    }
    
    function company_showAlert(message, type) {
        const alertContainer = document.getElementById('company_alertContainer');
        const alertClass = type === 'success' ? 'company-alert-success' : 
                          type === 'error' ? 'company-alert-danger' : 
                          type === 'info' ? 'company-alert-info' : 
                          'company-alert-warning';
        const icon = type === 'success' ? 'fa-check-circle' : 
                    type === 'error' ? 'fa-exclamation-circle' : 
                    'fa-info-circle';
        
        const alertDiv = document.createElement('div');
        alertDiv.className = `company-alert ${alertClass}`;
        alertDiv.innerHTML = `<i class="fas ${icon}"></i>${message}`;
        alertContainer.innerHTML = '';
        alertContainer.appendChild(alertDiv);
        
        setTimeout(() => {
            alertDiv.style.transition = 'opacity 0.5s';
            alertDiv.style.opacity = '0';
            setTimeout(() => {
                if (alertDiv.parentNode) alertDiv.remove();
            }, 500);
        }, 3000);
    }
    
    function company_validateForm() {
        let isValid = true;
        
        // Clear previous errors
        document.querySelectorAll('.company-form-control.error').forEach(input => {
            input.classList.remove('error');
        });
        document.querySelectorAll('.company-form-error').forEach(error => {
            error.style.display = 'none';
        });
        
        const companyName = document.getElementById('company_name').value.trim();
        const companyAddress = document.getElementById('company_address').value.trim();
        const companyEmail = document.getElementById('company_email').value.trim();
        const companyPhone = document.getElementById('company_phone').value.trim();
        const companyTIN = document.getElementById('company_tin').value.trim();
        const vrnNumber = document.getElementById('vrn_number').value.trim();
        const currencySymbol = document.getElementById('currency_symbol').value.trim();
        const lang = currentCompanyLang;
        
        if (!companyName) {
            document.getElementById('company_name').classList.add('error');
            document.getElementById('company_nameError').style.display = 'block';
            isValid = false;
        }
        
        if (!companyAddress) {
            document.getElementById('company_address').classList.add('error');
            document.getElementById('company_addressError').style.display = 'block';
            isValid = false;
        }
        
        if (!companyEmail) {
            document.getElementById('company_email').classList.add('error');
            document.getElementById('company_emailError').querySelector('span').textContent = company_translations[lang].emailRequired;
            document.getElementById('company_emailError').style.display = 'block';
            isValid = false;
        } else {
            const emailRegex = /^[^\s@]+@([^\s@]+\.)+[^\s@]+$/;
            if (!emailRegex.test(companyEmail)) {
                document.getElementById('company_email').classList.add('error');
                document.getElementById('company_emailError').querySelector('span').textContent = company_translations[lang].emailInvalid;
                document.getElementById('company_emailError').style.display = 'block';
                isValid = false;
            }
        }
        
        if (!companyPhone) {
            document.getElementById('company_phone').classList.add('error');
            document.getElementById('company_phoneError').querySelector('span').textContent = company_translations[lang].phoneRequired;
            document.getElementById('company_phoneError').style.display = 'block';
            isValid = false;
        } else {
            const phoneRegex = /^[\d\s\+\-\(\)]{8,}$/;
            if (!phoneRegex.test(companyPhone)) {
                document.getElementById('company_phone').classList.add('error');
                document.getElementById('company_phoneError').querySelector('span').textContent = company_translations[lang].phoneInvalid;
                document.getElementById('company_phoneError').style.display = 'block';
                isValid = false;
            }
        }
        
        if (!companyTIN) {
            document.getElementById('company_tin').classList.add('error');
            document.getElementById('company_tinError').style.display = 'block';
            isValid = false;
        }
        
        // VRN Number validation (optional field)
        if (vrnNumber !== '') {
            const vrnPattern = /^[A-Za-z0-9\-]+$/;
            if (!vrnPattern.test(vrnNumber)) {
                document.getElementById('vrn_number').classList.add('error');
                document.getElementById('company_vrnError').querySelector('span').textContent = company_translations[lang].vrnInvalid;
                document.getElementById('company_vrnError').style.display = 'block';
                isValid = false;
            }
            if (vrnNumber.length > 50) {
                document.getElementById('vrn_number').classList.add('error');
                document.getElementById('company_vrnError').querySelector('span').textContent = company_translations[lang].vrnMaxLength;
                document.getElementById('company_vrnError').style.display = 'block';
                isValid = false;
            }
        }
        
        if (!currencySymbol) {
            document.getElementById('currency_symbol').classList.add('error');
            document.getElementById('company_currencySymbolError').style.display = 'block';
            isValid = false;
        }
        
        return isValid;
    }
    
    function company_resetForm() {
        const lang = currentCompanyLang;
        if (confirm(company_translations[lang].confirmReset)) {
            document.getElementById('company_companyForm').reset();
            document.getElementById('cropped_image_data').value = '';
            document.getElementById('existing_logo').value = '';
            company_croppedImageDataURL = null;
            
            // Reset logo preview
            const preview = document.getElementById('company_logoPreview');
            preview.innerHTML = `
                <div class="company-logo-placeholder">
                    <i class="fas fa-cloud-upload-alt"></i>
                    <p data-company-lang="uploadLogo">Upload Company Logo</p>
                </div>
            `;
            
            // Reset VRN field to empty
            document.getElementById('vrn_number').value = '';
            
            company_showAlert(company_translations[lang].formCleared, 'info');
        }
    }
    
    // ===== CROPPER MODAL FUNCTIONS =====
    function company_openCropperModal() {
        document.getElementById('company_cropperModal').style.display = 'flex';
        
        setTimeout(function() {
            const image = document.getElementById('company_imageToCrop');
            if (image && image.src && !company_cropper) {
                if (company_cropper) {
                    company_cropper.destroy();
                    company_cropper = null;
                }
                
                company_cropper = new Cropper(image, {
                    aspectRatio: 1,
                    viewMode: 1,
                    autoCropArea: 1,
                    cropBoxResizable: true,
                    cropBoxMovable: true,
                    guides: true,
                    background: false,
                    responsive: true,
                    checkOrientation: false
                });
            }
        }, 100);
    }
    
    function company_closeCropperModal() {
        document.getElementById('company_cropperModal').style.display = 'none';
        if (company_cropper) {
            company_cropper.destroy();
            company_cropper = null;
        }
    }
    
    // ===== IMAGE HANDLING =====
    function company_handleImageUpload(file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const imageElement = document.getElementById('company_imageToCrop');
            imageElement.src = e.target.result;
            
            if (company_cropper) {
                company_cropper.destroy();
                company_cropper = null;
            }
            
            company_openCropperModal();
        };
        reader.readAsDataURL(file);
    }
    
    // ===== EVENT LISTENERS =====
    document.addEventListener('DOMContentLoaded', function() {
        // File input change
        const fileInput = document.getElementById('company_logo');
        if (fileInput) {
            fileInput.addEventListener('change', function(event) {
                const files = event.target.files;
                if (files && files.length > 0) {
                    company_handleImageUpload(files[0]);
                }
                event.target.value = '';
            });
        }
        
        // Crop and save button
        const cropBtn = document.getElementById('company_cropAndSaveBtn');
        if (cropBtn) {
            cropBtn.addEventListener('click', function() {
                if (!company_cropper) {
                    console.error('Cropper not initialized');
                    return;
                }
                
                try {
                    const canvas = company_cropper.getCroppedCanvas({
                        width: 500,
                        height: 500,
                        imageSmoothingEnabled: true,
                        imageSmoothingQuality: 'high'
                    });
                    
                    company_croppedImageDataURL = canvas.toDataURL('image/jpeg', 0.95);
                    company_closeCropperModal();
                    
                    const preview = document.getElementById('company_logoPreview');
                    preview.innerHTML = `<img src="${company_croppedImageDataURL}" alt="Company Logo">`;
                    
                    // Store cropped image data in hidden field
                    document.getElementById('cropped_image_data').value = company_croppedImageDataURL;
                    // Clear existing logo to use new one
                    document.getElementById('existing_logo').value = '';
                    
                    const lang = currentCompanyLang;
                    company_showAlert(company_translations[lang].imageCropSuccess, 'success');
                } catch (error) {
                    console.error('Error cropping image:', error);
                    const lang = currentCompanyLang;
                    company_showAlert(company_translations[lang].imageUploadError, 'error');
                }
            });
        }
        
        // Close modal buttons
        const closeModalBtn = document.getElementById('company_closeModalBtn');
        if (closeModalBtn) {
            closeModalBtn.addEventListener('click', company_closeCropperModal);
        }
        
        const cancelCropBtn = document.getElementById('company_cancelCropBtn');
        if (cancelCropBtn) {
            cancelCropBtn.addEventListener('click', company_closeCropperModal);
        }
        
        // Close modal when clicking overlay
        const modalOverlay = document.getElementById('company_cropperModal');
        if (modalOverlay) {
            modalOverlay.addEventListener('click', function(event) {
                if (event.target === this) {
                    company_closeCropperModal();
                }
            });
        }
        
        // Clear button
        const clearBtn = document.getElementById('company_clearBtn');
        if (clearBtn) {
            clearBtn.addEventListener('click', company_resetForm);
        }
        
        // Form submit validation
        const form = document.getElementById('company_companyForm');
        if (form) {
            form.addEventListener('submit', function(event) {
                if (!company_validateForm()) {
                    event.preventDefault();
                    const lang = currentCompanyLang;
                    company_showAlert(company_translations[lang].validationError, 'error');
                }
            });
        }
    });
</script>