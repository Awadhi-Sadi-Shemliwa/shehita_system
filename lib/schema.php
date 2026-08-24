<?php
/**
 * SHEHITA EMS — Tenant schema
 *
 * Every tenant (client company) gets its OWN MySQL database containing the
 * tables below. This file is the single definition of that schema.
 *
 * It is used in two places:
 *   1. config.php  — on every request, to self-heal the current tenant's schema.
 *   2. signup.php  — once, to build a brand-new tenant's database from scratch.
 *
 * Previously this DDL lived inline in config.php, which meant provisioning a
 * new tenant would have required duplicating ~250 lines. Keeping it here means
 * there is exactly one definition.
 *
 * Tables are created in strict foreign-key dependency order (parents first):
 *   departments -> roles -> users -> permissions
 *   projectgroup -> categories
 *   customers
 *   company_settings, system_settings   (standalone)
 *   projects (-> customers), invoices (-> customers)
 *   operations (-> projectgroup, categories)
 */

/**
 * Bump this whenever the schema below changes.
 *
 * ensure_tenant_schema() records the applied version in each tenant database and
 * skips the DDL pass when it already matches, so the steady-state cost is one
 * SELECT rather than a dozen CREATE TABLE statements per request.
 *
 * IMPORTANT — bumping this is NOT sufficient to change an existing table.
 * Every statement below is CREATE TABLE IF NOT EXISTS, which is a no-op once the
 * table exists. Bumping the constant alone would re-run the pass, change
 * nothing on existing companies, then record the new version as applied — so
 * their schema would be permanently out of date with nothing reporting it, and
 * the version marker would actively hide the problem.
 *
 * Anything that ALTERS an existing table must be added to
 * run_tenant_migrations() below, which is what actually makes a change roll out
 * across every company's database.
 */
const TENANT_SCHEMA_VERSION = '2026.08.23.2';

/**
 * Create/verify every table in a tenant database.
 *
 * @param mysqli $conn  Connection with a tenant database already selected.
 * @param bool   $force Re-run the DDL even if the recorded version matches.
 */
function ensure_tenant_schema(mysqli $conn, bool $force = false): void
{
    if (!$force && tenant_schema_is_current($conn)) {
        return;
    }

    // ---------------------------------------------------------------- core RBAC

    $conn->query("CREATE TABLE IF NOT EXISTS departments (
        id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        description TEXT,
        status ENUM('Active', 'Inactive') DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS roles (
        id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        department_id INT(11) UNSIGNED NOT NULL,
        status ENUM('Active', 'Inactive') DEFAULT 'Active',
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE RESTRICT,
        UNIQUE KEY unique_role_per_department (name, department_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS users (
        id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        email VARCHAR(255) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        phone VARCHAR(20) DEFAULT NULL,
        address TEXT DEFAULT NULL,
        profile_image VARCHAR(255) DEFAULT NULL,
        department_id INT(11) UNSIGNED DEFAULT NULL,
        role_id INT(11) UNSIGNED DEFAULT NULL,
        status ENUM('Active', 'Inactive') DEFAULT 'Inactive',
        security_question TEXT DEFAULT NULL,
        security_answer VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
        FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS permissions (
        id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        role_id INT(11) UNSIGNED NOT NULL,
        department_id INT(11) UNSIGNED NULL,
        module_name VARCHAR(100) NOT NULL,
        can_view TINYINT(1) DEFAULT 0,
        can_add TINYINT(1) DEFAULT 0,
        can_edit TINYINT(1) DEFAULT 0,
        can_delete TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
        FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
        UNIQUE KEY unique_role_module (role_id, module_name),
        INDEX idx_module (module_name),
        INDEX idx_role (role_id),
        INDEX idx_department (department_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ------------------------------------------------------------ business data

    $conn->query("CREATE TABLE IF NOT EXISTS projectgroup (
        id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        status ENUM('Active', 'Inactive') DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci AUTO_INCREMENT=1");

    $conn->query("CREATE TABLE IF NOT EXISTS categories (
        id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        projectgroup_id INT(11) UNSIGNED NOT NULL,
        name VARCHAR(255) NOT NULL,
        status ENUM('Active', 'Inactive') DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_projectgroup (projectgroup_id),
        INDEX idx_status (status),
        FOREIGN KEY (projectgroup_id) REFERENCES projectgroup(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci AUTO_INCREMENT=1");

    $conn->query("CREATE TABLE IF NOT EXISTS customers (
        id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_name VARCHAR(255) NOT NULL,
        contact_person VARCHAR(255) DEFAULT NULL,
        tin_number VARCHAR(50) DEFAULT NULL,
        vrn_number VARCHAR(50) DEFAULT NULL,
        address TEXT DEFAULT NULL,
        email VARCHAR(255) DEFAULT NULL,
        type_of_business ENUM('Individual', 'Sole Proprietorship', 'Partnership', 'Limited Company', 'Corporation', 'Non-Profit', 'Government', 'Other') DEFAULT 'Individual',
        status ENUM('Active', 'Inactive') DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_customer_name (customer_name),
        INDEX idx_status (status),
        INDEX idx_email (email),
        INDEX idx_type (type_of_business),
        INDEX idx_vrn_number (vrn_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci AUTO_INCREMENT=1");

    // Per-company profile shown on invoices/reports. Enforced single-row by
    // modules/company-settings.php. Brand colours are NOT here: they live in
    // the control DB on the tenants row -- see lib/brand.php.
    $conn->query("CREATE TABLE IF NOT EXISTS company_settings (
        id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        company_name VARCHAR(255) NOT NULL,
        company_address TEXT NOT NULL,
        company_email VARCHAR(255) NOT NULL,
        company_phone VARCHAR(50) NOT NULL,
        company_tin VARCHAR(100) NOT NULL,
        vrn_number VARCHAR(50) DEFAULT NULL,
        currency_symbol VARCHAR(5) NOT NULL DEFAULT 'TZS',
        logo_url VARCHAR(500) DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci AUTO_INCREMENT=1");

    $conn->query("CREATE TABLE IF NOT EXISTS system_settings (
        id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) NOT NULL UNIQUE,
        setting_value TEXT,
        setting_type ENUM('text', 'textarea', 'number', 'toggle', 'select', 'email') DEFAULT 'text',
        display_name VARCHAR(255) NOT NULL,
        description TEXT,
        options JSON NULL,
        sort_order INT(11) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_key (setting_key),
        INDEX idx_sort (sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS projects (
        id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        contract_number VARCHAR(50) NOT NULL UNIQUE,
        client_id INT(11) UNSIGNED NOT NULL,
        effective_date DATE NOT NULL,
        end_date DATE NOT NULL,
        contract_value DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        tax DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        commission DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        cost_of_project DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        staff_cost DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        overhead_cost DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        number_of_staff_allocated INT(11) NOT NULL DEFAULT 0,
        target_profit DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        actual_profit DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        profit_difference DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        status ENUM('Active', 'Expired', 'Inactive') DEFAULT 'Active',
        created_by VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_client (client_id),
        INDEX idx_status (status),
        INDEX idx_contract_number (contract_number),
        INDEX idx_dates (effective_date, end_date),
        FOREIGN KEY (client_id) REFERENCES customers(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci AUTO_INCREMENT=1");

    $conn->query("CREATE TABLE IF NOT EXISTS invoices (
        id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        invoice_number VARCHAR(50) NOT NULL UNIQUE,
        customer_id INT(11) UNSIGNED NOT NULL,
        invoice_date DATE NOT NULL,
        due_date DATE NOT NULL,
        particulars TEXT NOT NULL,
        quantity INT(11) NOT NULL DEFAULT 1,
        rate DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        subtotal DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        vat DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        bank_name VARCHAR(255) NOT NULL,
        account_number VARCHAR(100) NOT NULL,
        account_name VARCHAR(255) DEFAULT NULL,
        status ENUM('Paid', 'Unpaid', 'Partially Paid') DEFAULT 'Unpaid',
        created_by VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_invoice_number (invoice_number),
        INDEX idx_customer (customer_id),
        INDEX idx_status (status),
        INDEX idx_dates (invoice_date, due_date),
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci AUTO_INCREMENT=1");

    $conn->query("CREATE TABLE IF NOT EXISTS operations (
        id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        invoice_id VARCHAR(20) NOT NULL UNIQUE,
        contract_number VARCHAR(50) NOT NULL,
        project_group_id INT(11) UNSIGNED NOT NULL,
        category_id INT(11) UNSIGNED NOT NULL,
        duration_type ENUM('Non Recurring', 'Recurring - Monthly', 'Recurring - Quarterly', 'Recurring - Semi Annually', 'Recurring - Annually') DEFAULT 'Non Recurring',
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        status ENUM('Active', 'Completed', 'Inactive') DEFAULT 'Active',
        invoice_data JSON NULL,
        assigned_staff JSON DEFAULT NULL,
        created_by VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_invoice_id (invoice_id),
        INDEX idx_contract (contract_number),
        INDEX idx_status (status),
        INDEX idx_dates (start_date, end_date),
        FOREIGN KEY (project_group_id) REFERENCES projectgroup(id) ON DELETE RESTRICT,
        FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci AUTO_INCREMENT=1");

    ensure_tenant_defaults($conn);

    // Apply anything that changes an EXISTING table. Only record the version as
    // applied if that succeeded -- otherwise the marker would claim a database
    // is up to date when it is not.
    if (!run_tenant_migrations($conn)) {
        error_log('ensure_tenant_schema: migrations did not complete; schema version not recorded.');
        return;
    }

    tenant_schema_mark_current($conn);
}

/**
 * Idempotent changes to existing tenant tables.
 *
 * Add a step here whenever a column or index changes. Each step must check the
 * current state first so that running it repeatedly is harmless and a
 * partially-migrated database converges.
 *
 * There are no steps yet — the schema has not changed since launch. The
 * machinery exists so that the first change does not silently skip every
 * company that already has the table.
 *
 * @return bool False if any step failed.
 */
function run_tenant_migrations(mysqli $conn): bool
{
    $ok = true;

    // Example of the shape a step takes:
    //
    // if (!tenant_column_exists($conn, 'customers', 'region')) {
    //     if (!$conn->query("ALTER TABLE customers ADD COLUMN region VARCHAR(100) NULL")) {
    //         error_log('tenant migration: add customers.region: ' . $conn->error);
    //         $ok = false;
    //     }
    // }

    return $ok;
}

/** Does a column exist on a table in the current tenant database? */
function tenant_column_exists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare(
        "SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
          LIMIT 1"
    );
    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $found = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $found;
}

/** Does a named index exist on a table in the current tenant database? */
function tenant_index_exists(mysqli $conn, string $table, string $index): bool
{
    $stmt = $conn->prepare(
        "SELECT 1 FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
          LIMIT 1"
    );
    $stmt->bind_param("ss", $table, $index);
    $stmt->execute();
    $found = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $found;
}

/**
 * Every tenant needs department #1 and role #1 to exist, because the whole
 * permission system treats role_id = 1 as "this company's Super Admin"
 * (see hasPermission() in config.php).
 */
function ensure_tenant_defaults(mysqli $conn): void
{
    $result = $conn->query("SELECT id FROM departments WHERE name = 'Administrator'");
    if ($result && $result->num_rows == 0) {
        $stmt = $conn->prepare("INSERT INTO departments (name, description, status) VALUES (?, ?, ?)");
        $name   = 'Administrator';
        $desc   = 'Default system department for administrator roles';
        $status = 'Active';
        $stmt->bind_param("sss", $name, $desc, $status);
        $stmt->execute();
        $stmt->close();

        $conn->query("UPDATE departments SET id = 1 WHERE name = 'Administrator'");
        $conn->query("ALTER TABLE departments AUTO_INCREMENT = 2");
    }

    $result = $conn->query("SELECT id FROM roles WHERE name = 'Super Admin' AND department_id = 1");
    if ($result && $result->num_rows == 0) {
        $stmt = $conn->prepare("INSERT INTO roles (name, department_id, status, description) VALUES (?, ?, ?, ?)");
        $name    = 'Super Admin';
        $dept_id = 1;
        $status  = 'Active';
        $desc    = 'Default system role with full administrative privileges';
        $stmt->bind_param("siss", $name, $dept_id, $status, $desc);
        $stmt->execute();
        $stmt->close();

        $conn->query("UPDATE roles SET id = 1 WHERE name = 'Super Admin' AND department_id = 1");
        $conn->query("ALTER TABLE roles AUTO_INCREMENT = 2");
    }
}

/**
 * Create the company's first administrator (role_id = 1).
 *
 * Called once at signup. Replaces the old ADMIN_DEFAULT_EMAIL env-var seeding
 * that ran on every request back when there was only one company.
 *
 * @return bool True when the account was created, false when it already existed.
 */
function seed_tenant_admin(mysqli $conn, string $name, string $email, string $plainPassword): bool
{
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    if ($exists) {
        return false;
    }

    $dept_id = 1;
    $role_id = 1;
    $status  = 'Active';
    $hash    = password_hash($plainPassword, PASSWORD_DEFAULT);

    $stmt = $conn->prepare(
        "INSERT INTO users (name, email, password, department_id, role_id, status)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("sssiis", $name, $email, $hash, $dept_id, $role_id, $status);
    $stmt->execute();
    $stmt->close();

    return true;
}

/**
 * Seed the single company_settings row so invoices and reports carry the real
 * company name from day one, instead of falling back to 'SHEHITA EMS'.
 */
function seed_tenant_company_settings(
    mysqli $conn,
    string $companyName,
    string $email,
    string $phone,
    string $logoUrl = ''
): void {
    $result = $conn->query("SELECT id FROM company_settings LIMIT 1");
    if ($result && $result->num_rows > 0) {
        return;
    }

    $address  = '';
    $tin      = '';
    $currency = 'TZS';

    $stmt = $conn->prepare(
        "INSERT INTO company_settings
            (company_name, company_address, company_email, company_phone,
             company_tin, currency_symbol, logo_url)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("sssssss", $companyName, $address, $email, $phone, $tin, $currency, $logoUrl);
    $stmt->execute();
    $stmt->close();
}

// ---------------------------------------------------------------------------
// Schema version bookkeeping
// ---------------------------------------------------------------------------

function tenant_schema_is_current(mysqli $conn): bool
{
    // @ because the table legitimately does not exist on a fresh database.
    $result = @$conn->query("SELECT meta_value FROM schema_meta WHERE meta_key = 'version'");
    if (!$result || $result->num_rows === 0) {
        return false;
    }
    $row = $result->fetch_assoc();
    return ($row['meta_value'] ?? '') === TENANT_SCHEMA_VERSION;
}

function tenant_schema_mark_current(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS schema_meta (
        meta_key VARCHAR(32) NOT NULL PRIMARY KEY,
        meta_value VARCHAR(64) NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $version = TENANT_SCHEMA_VERSION;
    $stmt = $conn->prepare("REPLACE INTO schema_meta (meta_key, meta_value) VALUES ('version', ?)");
    $stmt->bind_param("s", $version);
    $stmt->execute();
    $stmt->close();
}
