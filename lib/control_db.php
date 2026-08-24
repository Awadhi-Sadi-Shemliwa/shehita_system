<?php
/**
 * SHEHITA EMS — Control database
 *
 * The platform's own database, shared across every tenant. It holds who the
 * clients are, what they pay, and who at SHEHITA can administer them.
 *
 * This is deliberately SEPARATE from the per-tenant databases. A tenant's
 * connection ($conn, from config.php) can only ever see that one company's
 * business data; nothing in a tenant database references another tenant, so
 * cross-tenant leaks are structurally impossible rather than merely guarded.
 *
 * Schema is self-bootstrapping (CREATE TABLE IF NOT EXISTS) in the same style
 * as the tenant schema in lib/schema.php.
 */

const CONTROL_SCHEMA_VERSION = '2026.08.23.2';

/**
 * Tenant slugs that can never be claimed, because they collide with real
 * paths on system.shehita.co.tz or would be confusing/abusable.
 * Kept in sync with the passthrough rule at the top of .htaccess.
 */
const RESERVED_SLUGS = [
    'signup', 'admin', 'cron', 'ai', 'lib', 'health', 'partials', 'modules',
    'uploads', 'deploy', 'api', 'www', 'mail', 'ftp', 'static', 'assets',
    'login', 'logout', 'register', 'billing', 'expired', 'index', 'homepage',
    'config', 'bootstrap', 'vendor', 'shehita', 'support', 'help', 'docs',
    'status', 'app', 'test', 'demo', 'null', 'undefined', 'root',
];

/**
 * Open (and lazily build) a connection to the control database.
 *
 * Returns the same connection on repeat calls within a request.
 */
function control_db(): mysqli
{
    static $control = null;
    if ($control instanceof mysqli) {
        return $control;
    }

    $host     = getenv('DB_HOST')     ?: '';
    $username = getenv('DB_USER')     ?: '';
    $password = getenv('DB_PASSWORD') ?: '';
    $database = getenv('CONTROL_DB_NAME') ?: 'shehita_control';

    if ($host === '' || $username === '') {
        error_log('control_db: missing required DB env vars (DB_HOST/DB_USER).');
        http_response_code(500);
        die('Service temporarily unavailable.');
    }

    mysqli_report(MYSQLI_REPORT_OFF);
    $control = @new mysqli($host, $username, $password);

    if ($control->connect_error) {
        error_log('control_db: connection failed: ' . $control->connect_error);
        http_response_code(500);
        die('Service temporarily unavailable. Please try again later.');
    }

    $control->query("CREATE DATABASE IF NOT EXISTS `" . str_replace('`', '', $database) . "`");
    $control->select_db($database);
    $control->set_charset('utf8mb4');

    ensure_control_schema($control);

    return $control;
}

function ensure_control_schema(mysqli $control, bool $force = false): void
{
    if (!$force && control_schema_is_current($control)) {
        return;
    }

    // One row per client company. `db_name` points at that company's own
    // database; `trial_days` is snapshotted at signup so that changing the
    // platform-wide trial length later can never retroactively expire or
    // revive an existing customer.
    $control->query("CREATE TABLE IF NOT EXISTS tenants (
        id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(40) NOT NULL UNIQUE,
        company_name VARCHAR(255) NOT NULL,
        db_name VARCHAR(64) NOT NULL UNIQUE,
        logo_path VARCHAR(500) DEFAULT NULL,
        brand_primary CHAR(7) DEFAULT NULL,
        brand_ramp JSON NULL,
        contact_name VARCHAR(255) DEFAULT NULL,
        contact_email VARCHAR(255) DEFAULT NULL,
        contact_phone VARCHAR(50) DEFAULT NULL,
        status ENUM('Active', 'Suspended', 'Deleted') NOT NULL DEFAULT 'Active',
        trial_started_at DATETIME NOT NULL,
        trial_days INT(11) NOT NULL DEFAULT 14,
        paid_until DATETIME NULL DEFAULT NULL,
        ai_enabled TINYINT(1) NOT NULL DEFAULT 1,
        ai_monthly_cap INT(11) NOT NULL DEFAULT 500,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        deleted_at DATETIME NULL DEFAULT NULL,
        INDEX idx_status (status),
        INDEX idx_paid_until (paid_until),
        INDEX idx_contact_email (contact_email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Subscription packages. Prices are editable by SHEHITA in admin/plans.php.
    $control->query("CREATE TABLE IF NOT EXISTS plans (
        code VARCHAR(32) NOT NULL PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        days INT(11) NOT NULL,
        price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        currency CHAR(3) NOT NULL DEFAULT 'TZS',
        sort_order INT(11) NOT NULL DEFAULT 0,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // The payment ledger. UNIQUE(tenant_id, method, reference) is what makes
    // activation idempotent: re-submitting the same bank reference for the same
    // company cannot extend it twice.
    //
    // The key MUST include tenant_id. It was originally (method, reference)
    // alone, which assumed references are globally unique -- they are not.
    // Customers copy them off bank slips and mobile-money SMS, and two banks
    // will happily issue the same one. Globally, that meant company B paying
    // with a reference company A had already used would flip A's payment row to
    // Paid while crediting B's subscription, or would be refused outright.
    $control->query("CREATE TABLE IF NOT EXISTS subscription_payments (
        id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT(11) UNSIGNED NOT NULL,
        plan_code VARCHAR(32) NOT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        currency CHAR(3) NOT NULL DEFAULT 'TZS',
        method ENUM('Bank', 'MobileMoney', 'Cash', 'Other') NOT NULL DEFAULT 'Bank',
        reference VARCHAR(120) NOT NULL,
        status ENUM('Pending', 'Paid', 'Rejected') NOT NULL DEFAULT 'Pending',
        period_start DATETIME NULL DEFAULT NULL,
        period_end DATETIME NULL DEFAULT NULL,
        confirm_token_hash CHAR(64) NULL DEFAULT NULL,
        confirm_token_expires_at DATETIME NULL DEFAULT NULL,
        confirm_requested_at DATETIME NULL DEFAULT NULL,
        recorded_by VARCHAR(255) DEFAULT NULL,
        note TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_tenant_method_reference (tenant_id, method, reference),
        INDEX idx_tenant (tenant_id),
        INDEX idx_status (status),
        INDEX idx_token (confirm_token_hash),
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $control->query("CREATE TABLE IF NOT EXISTS subscription_invoices (
        id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        payment_id INT(11) UNSIGNED NOT NULL,
        tenant_id INT(11) UNSIGNED NOT NULL,
        invoice_number VARCHAR(40) NOT NULL UNIQUE,
        issued_at DATETIME NOT NULL,
        period_start DATETIME NULL DEFAULT NULL,
        period_end DATETIME NULL DEFAULT NULL,
        description VARCHAR(255) NOT NULL,
        subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        vat DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        currency CHAR(3) NOT NULL DEFAULT 'TZS',
        view_token CHAR(64) NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_tenant (tenant_id),
        INDEX idx_payment (payment_id),
        FOREIGN KEY (payment_id) REFERENCES subscription_payments(id) ON DELETE CASCADE,
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // SHEHITA staff. Completely separate from any tenant's `users` table --
    // a tenant's role_id = 1 Super Admin is only super inside their own company.
    $control->query("CREATE TABLE IF NOT EXISTS platform_admins (
        id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        email VARCHAR(255) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        role ENUM('Owner', 'Billing') NOT NULL DEFAULT 'Billing',
        status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
        last_login_at DATETIME NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $control->query("CREATE TABLE IF NOT EXISTS platform_audit (
        id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        actor VARCHAR(255) NOT NULL,
        action VARCHAR(100) NOT NULL,
        tenant_id INT(11) UNSIGNED NULL DEFAULT NULL,
        detail JSON NULL,
        ip VARCHAR(45) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_tenant (tenant_id),
        INDEX idx_action (action),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // UNIQUE(tenant_id, kind, sent_on) makes the daily notice cron idempotent:
    // running it twice in one day cannot double-mail a customer.
    $control->query("CREATE TABLE IF NOT EXISTS notices_sent (
        id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT(11) UNSIGNED NOT NULL,
        kind VARCHAR(50) NOT NULL,
        sent_on DATE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_notice (tenant_id, kind, sent_on),
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $control->query("CREATE TABLE IF NOT EXISTS ai_usage (
        id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT(11) UNSIGNED NOT NULL,
        user_email VARCHAR(255) DEFAULT NULL,
        tokens_in INT(11) NOT NULL DEFAULT 0,
        tokens_out INT(11) NOT NULL DEFAULT 0,
        cached_tokens INT(11) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_tenant_created (tenant_id, created_at),
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Platform-wide key/value settings (payment instructions, bank details...).
    $control->query("CREATE TABLE IF NOT EXISTS platform_settings (
        setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
        setting_value TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Per-IP signup throttling, so /signup cannot be used to spray databases.
    $control->query("CREATE TABLE IF NOT EXISTS signup_attempts (
        id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        ip VARCHAR(45) NOT NULL,
        slug VARCHAR(40) DEFAULT NULL,
        succeeded TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ip_created (ip, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // CREATE TABLE IF NOT EXISTS is a no-op on a table that already exists, so
    // anything that CHANGES an existing table has to be applied explicitly here.
    if (!run_control_migrations($control)) {
        // Do not record the version as applied when a migration failed, or the
        // database would be permanently out of date with nothing reporting it.
        error_log('ensure_control_schema: migrations did not complete; schema version not recorded.');
        return;
    }

    seed_default_plans($control);
    seed_platform_admin($control);
    control_schema_mark_current($control);
}

/**
 * Idempotent changes to existing control tables.
 *
 * Each step checks the current state first, so running this repeatedly is safe
 * and a partially-migrated database converges.
 *
 * @return bool False if anything failed, so the caller can avoid marking the
 *              schema version as applied.
 */
function run_control_migrations(mysqli $control): bool
{
    $ok = true;

    // Payment references are only unique WITHIN a company -- see the comment on
    // subscription_payments above. Replace the old global key if it is present.
    if (control_index_exists($control, 'subscription_payments', 'uniq_method_reference')) {
        if (!$control->query("ALTER TABLE subscription_payments DROP INDEX uniq_method_reference")) {
            error_log('migration: could not drop uniq_method_reference: ' . $control->error);
            $ok = false;
        }
    }

    if ($ok && !control_index_exists($control, 'subscription_payments', 'uniq_tenant_method_reference')) {
        if (!$control->query(
            "ALTER TABLE subscription_payments
             ADD UNIQUE KEY uniq_tenant_method_reference (tenant_id, method, reference)"
        )) {
            // A duplicate (tenant, method, reference) already in the data would
            // land here. Report it rather than silently continuing.
            error_log('migration: could not add uniq_tenant_method_reference: ' . $control->error);
            $ok = false;
        }
    }

    return $ok;
}

/** Does a named index exist on a control-database table? */
function control_index_exists(mysqli $control, string $table, string $index): bool
{
    $stmt = $control->prepare(
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
 * Starter packages. Prices are placeholders SHEHITA edits in the admin console.
 */
function seed_default_plans(mysqli $control): void
{
    $result = $control->query("SELECT COUNT(*) AS c FROM plans");
    if ($result && (int)$result->fetch_assoc()['c'] > 0) {
        return;
    }

    $defaults = [
        ['monthly',    'Monthly',       30,  50000.00, 1],
        ['quarterly',  'Quarterly',     90, 135000.00, 2],
        ['semiannual', 'Semi-Annual',  180, 255000.00, 3],
        ['annual',     'Annual',       365, 480000.00, 4],
    ];

    $stmt = $control->prepare(
        "INSERT INTO plans (code, name, days, price, currency, sort_order, active)
         VALUES (?, ?, ?, ?, 'TZS', ?, 1)"
    );
    foreach ($defaults as [$code, $name, $days, $price, $order]) {
        $stmt->bind_param("ssidi", $code, $name, $days, $price, $order);
        $stmt->execute();
    }
    $stmt->close();
}

/**
 * Seed the first SHEHITA administrator from environment variables, mirroring
 * the pattern config.php already used for the single-tenant admin.
 * Skipped entirely when PLATFORM_ADMIN_EMAIL is unset.
 */
function seed_platform_admin(mysqli $control): void
{
    $email = getenv('PLATFORM_ADMIN_EMAIL') ?: '';
    if ($email === '') {
        return;
    }

    $stmt = $control->prepare("SELECT id FROM platform_admins WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    if ($exists) {
        return;
    }

    $plain = getenv('PLATFORM_ADMIN_PASSWORD');
    if ($plain === false || $plain === '') {
        $plain = bin2hex(random_bytes(9));
        error_log("control_db: created initial platform admin '{$email}' with generated password: {$plain}");
    }

    $name   = 'SHEHITA Administrator';
    $hash   = password_hash($plain, PASSWORD_DEFAULT);
    $role   = 'Owner';
    $status = 'Active';

    $stmt = $control->prepare(
        "INSERT INTO platform_admins (name, email, password, role, status)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("sssss", $name, $email, $hash, $role, $status);
    $stmt->execute();
    $stmt->close();
}

// ---------------------------------------------------------------------------
// Tenant lookup helpers
// ---------------------------------------------------------------------------

/**
 * Fetch a tenant by URL slug. Returns null when unknown.
 * Callers decide how to treat status = 'Deleted' / 'Suspended'.
 */
function tenant_by_slug(mysqli $control, string $slug): ?array
{
    $stmt = $control->prepare("SELECT * FROM tenants WHERE slug = ? LIMIT 1");
    $stmt->bind_param("s", $slug);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function tenant_by_id(mysqli $control, int $id): ?array
{
    $stmt = $control->prepare("SELECT * FROM tenants WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

/**
 * Turn a company name into a candidate URL slug: "Acme Traders Ltd." -> "acmetraders-ltd".
 */
function slugify_company(string $name): string
{
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    $slug = preg_replace('/-+/', '-', $slug);

    return substr($slug, 0, 39);
}

/**
 * Is this slug syntactically valid AND free to claim?
 *
 * @param string      $slug
 * @param mysqli|null $control Pass a connection to also check for collisions.
 * @return string     '' when acceptable, otherwise a human-readable reason.
 */
function slug_rejection_reason(string $slug, ?mysqli $control = null): string
{
    if (!preg_match('/^[a-z0-9][a-z0-9-]{1,38}$/', $slug)) {
        return 'Use 2-39 characters: lowercase letters, numbers and hyphens, starting with a letter or number.';
    }
    if (str_ends_with($slug, '-')) {
        return 'The address cannot end with a hyphen.';
    }
    if (in_array($slug, RESERVED_SLUGS, true)) {
        return 'That address is reserved by the system. Please choose another.';
    }
    if ($control !== null && tenant_by_slug($control, $slug) !== null) {
        return 'That address is already taken by another company.';
    }

    return '';
}

/**
 * Read a platform setting (payment instructions, bank details, ...).
 */
function platform_setting(mysqli $control, string $key, string $default = ''): string
{
    $stmt = $control->prepare("SELECT setting_value FROM platform_settings WHERE setting_key = ?");
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row === null ? $default : (string)$row['setting_value'];
}

function platform_setting_set(mysqli $control, string $key, string $value): void
{
    $stmt = $control->prepare(
        "INSERT INTO platform_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    $stmt->bind_param("ss", $key, $value);
    $stmt->execute();
    $stmt->close();
}

/**
 * Append a row to the audit trail. Every destructive or money-touching action
 * in admin/ should call this.
 */
function platform_audit_log(mysqli $control, string $actor, string $action, ?int $tenantId = null, array $detail = []): void
{
    $json = json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $ip   = $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? null;

    $stmt = $control->prepare(
        "INSERT INTO platform_audit (actor, action, tenant_id, detail, ip) VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("ssiss", $actor, $action, $tenantId, $json, $ip);
    $stmt->execute();
    $stmt->close();
}

// ---------------------------------------------------------------------------
// Schema version bookkeeping
// ---------------------------------------------------------------------------

function control_schema_is_current(mysqli $control): bool
{
    $result = @$control->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'schema_version'");
    if (!$result || $result->num_rows === 0) {
        return false;
    }
    $row = $result->fetch_assoc();
    return ($row['setting_value'] ?? '') === CONTROL_SCHEMA_VERSION;
}

function control_schema_mark_current(mysqli $control): void
{
    platform_setting_set($control, 'schema_version', CONTROL_SCHEMA_VERSION);
}
