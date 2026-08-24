<?php
/**
 * SHEHITA EMS — Tenant provisioning
 *
 * Creating a new client company: their own database, their own administrator,
 * their logo, and a brand palette read out of that logo.
 *
 * Each company gets a SEPARATE MySQL database rather than a tenant_id column on
 * shared tables. That choice is what lets the 17 files in modules/ stay
 * completely untouched — they use `global $conn`, and config.php points $conn at
 * the right database — and it means one company cannot read another's rows even
 * if a query somewhere forgets a WHERE clause.
 *
 * Everything here is written so a failure half-way leaves nothing behind:
 * provision_tenant() unwinds the database, the uploaded logo and the tenant row
 * if any step fails.
 */

require_once __DIR__ . '/control_db.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/brand.php';
require_once __DIR__ . '/subscription.php';

/** Where a tenant's uploaded files live, relative to the web root. */
function tenant_upload_dir(string $slug): string
{
    return 'uploads/tenants/' . $slug . '/';
}

/**
 * Make an uploaded path removable by the web server later.
 *
 * A tenant's folder can be created by two different users: Apache (www-data)
 * during signup, or root when an operator runs cron/import-tenant.php via
 * `docker compose exec` — which is exactly how ARC was brought in. If root
 * creates it, Apache cannot delete it, and purging that company from the admin
 * console silently leaves their logo on disk.
 *
 * Widening the mode is the pragmatic fix for a container where the app is the
 * only thing writing here, and it matches what the rest of the codebase already
 * does with uploads/. It is safe specifically because uploads/.htaccess stops
 * anything in this tree from ever being executed — these are only ever images
 * served back as static files.
 */
function make_upload_path_writable(string $path): void
{
    // Errors suppressed on purpose: chmod fails on filesystems that do not
    // implement it (bind mounts on Windows/macOS), and that is not a problem
    // there because permissions are not enforced either.
    @chmod($path, is_dir($path) ? 0777 : 0666);
}

/**
 * Derive the MySQL database name for a slug.
 * Hyphens are legal in slugs but awkward in identifiers, so they become
 * underscores; the name is always backtick-quoted at the point of use anyway.
 */
function tenant_db_name(string $slug): string
{
    return 'shems_' . str_replace('-', '_', $slug);
}

/**
 * Create a complete, ready-to-use company.
 *
 * @param array $input keys: company_name, slug, admin_name, admin_email,
 *                     admin_password, contact_phone, logo_tmp_path (nullable),
 *                     logo_extension (nullable)
 *
 * @return array{ok:bool, tenant_id:int, error:string, portal_url:string}
 */
function provision_tenant(mysqli $control, array $input): array
{
    $fail = static fn(string $msg): array =>
        ['ok' => false, 'tenant_id' => 0, 'error' => $msg, 'portal_url' => ''];

    $slug        = (string)$input['slug'];
    $companyName = (string)$input['company_name'];
    $adminEmail  = (string)$input['admin_email'];
    $dbName      = tenant_db_name($slug);

    // Track what we've made, so a later failure can be unwound cleanly.
    $createdTenantId = 0;
    $createdDatabase = false;
    $createdLogoPath = '';

    try {
        // ---- 1. Reserve the slug -------------------------------------------
        // Insert first: the UNIQUE key on slug is what actually prevents two
        // simultaneous signups claiming the same address. A "check then insert"
        // would race.
        $trialDays  = default_trial_days();
        $trialStart = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $phone      = (string)($input['contact_phone'] ?? '');
        $adminName  = (string)$input['admin_name'];

        $stmt = $control->prepare(
            "INSERT INTO tenants
                (slug, company_name, db_name, contact_name, contact_email, contact_phone,
                 status, trial_started_at, trial_days)
             VALUES (?, ?, ?, ?, ?, ?, 'Active', ?, ?)"
        );
        $stmt->bind_param(
            "sssssssi",
            $slug, $companyName, $dbName, $adminName, $adminEmail, $phone,
            $trialStart, $trialDays
        );

        if (!$stmt->execute()) {
            $duplicate = $control->errno === 1062;
            $stmt->close();
            return $fail($duplicate
                ? 'That address has just been taken. Please choose another.'
                : 'We could not create the account. Please try again.');
        }
        $createdTenantId = (int)$stmt->insert_id;
        $stmt->close();

        // ---- 2. Logo + brand colours ---------------------------------------
        $palette = brand_default();

        if (!empty($input['logo_tmp_path']) && is_file($input['logo_tmp_path'])) {
            $dir      = tenant_upload_dir($slug);
            $fullDir  = __DIR__ . '/../' . $dir;
            $ext      = (string)($input['logo_extension'] ?? 'png');
            $filename = 'logo.' . $ext;

            if (!is_dir($fullDir) && !@mkdir($fullDir, 0777, true) && !is_dir($fullDir)) {
                throw new RuntimeException("Could not create upload folder {$fullDir}");
            }
            make_upload_path_writable($fullDir);

            if (!@copy($input['logo_tmp_path'], $fullDir . $filename)) {
                throw new RuntimeException('Could not save the uploaded logo.');
            }
            make_upload_path_writable($fullDir . $filename);

            $createdLogoPath = $dir . $filename;

            // Read the brand colour straight out of the image. Falls back to
            // SHEHITA's palette rather than failing signup if GD is unavailable
            // or the logo is pure black and white.
            $palette = brand_from_logo($fullDir . $filename);
        }

        $rampJson = json_encode($palette, JSON_UNESCAPED_SLASHES);
        $primary  = $palette['primary'];

        $stmt = $control->prepare(
            "UPDATE tenants SET logo_path = ?, brand_primary = ?, brand_ramp = ? WHERE id = ?"
        );
        $logoForDb = $createdLogoPath !== '' ? $createdLogoPath : null;
        $stmt->bind_param("sssi", $logoForDb, $primary, $rampJson, $createdTenantId);
        $stmt->execute();
        $stmt->close();

        // ---- 3. The company's own database ---------------------------------
        // Flag it BEFORE the call. tenant_connection() creates the database and
        // can still throw afterwards (select_db, set_charset); setting the flag
        // after would skip the drop in provision_rollback() and strand an orphan
        // shems_<slug> whose tenant row had just been deleted.
        $createdDatabase = true;
        $tenantConn = tenant_connection($dbName, true);

        ensure_tenant_schema($tenantConn);
        seed_tenant_admin($tenantConn, $adminName, $adminEmail, (string)$input['admin_password']);
        seed_tenant_company_settings(
            $tenantConn,
            $companyName,
            $adminEmail,
            $phone,
            $createdLogoPath
        );
        $tenantConn->close();

    } catch (Throwable $e) {
        // The real reason stays out of the browser: /signup is unauthenticated,
        // and these messages carry filesystem paths and SQL. The reference code
        // is the bridge -- it appears on the page AND in the log line, so a
        // failure a customer reports can be found with one grep instead of
        // guessing which of a dozen steps broke.
        $ref = strtoupper(bin2hex(random_bytes(3)));

        error_log("provision_tenant failed for {$slug} [ref {$ref}]: " . $e->getMessage());
        provision_rollback($control, $createdTenantId, $createdDatabase ? $dbName : '', $createdLogoPath);

        return $fail('We could not finish setting up your system. Nothing was saved — please try again. '
                   . "If it keeps happening, quote reference {$ref} to SHEHITA support.");
    }

    $base = rtrim(getenv('APP_BASE_URL') ?: 'https://system.shehita.co.tz', '/');

    platform_audit_log($control, $adminEmail, 'tenant.created', $createdTenantId, [
        'slug' => $slug, 'company' => $companyName, 'ip' => client_ip(),
    ]);

    return [
        'ok'         => true,
        'tenant_id'  => $createdTenantId,
        'error'      => '',
        'portal_url' => $base . '/' . $slug . '/login.php',
    ];
}

/**
 * Undo a partial provision. Order matters: drop the database before the tenant
 * row, so a failure here still leaves a row pointing at the orphan for an
 * operator to find.
 */
function provision_rollback(mysqli $control, int $tenantId, string $dbName, string $logoPath): void
{
    if ($dbName !== '') {
        drop_tenant_database($control, $dbName);
    }

    if ($logoPath !== '') {
        @unlink(__DIR__ . '/../' . $logoPath);
        @rmdir(dirname(__DIR__ . '/../' . $logoPath));
    }

    if ($tenantId > 0) {
        $stmt = $control->prepare("DELETE FROM tenants WHERE id = ?");
        $stmt->bind_param("i", $tenantId);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Open a connection to one tenant's database.
 *
 * @param bool $create Create the database if it does not exist.
 */
function tenant_connection(string $dbName, bool $create = false): mysqli
{
    $host     = getenv('DB_HOST')     ?: '';
    $username = getenv('DB_USER')     ?: '';
    $password = getenv('DB_PASSWORD') ?: '';

    mysqli_report(MYSQLI_REPORT_OFF);
    $conn = @new mysqli($host, $username, $password);

    if ($conn->connect_error) {
        throw new RuntimeException('Database connection failed: ' . $conn->connect_error);
    }

    $safe = '`' . str_replace('`', '', $dbName) . '`';

    if ($create && !$conn->query("CREATE DATABASE IF NOT EXISTS {$safe} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci")) {
        throw new RuntimeException('Could not create database ' . $dbName . ': ' . $conn->error);
    }

    if (!$conn->select_db($dbName)) {
        throw new RuntimeException('Could not select database ' . $dbName);
    }

    $conn->set_charset('utf8mb4');

    return $conn;
}

/**
 * Permanently destroy a tenant's database.
 *
 * Only ever called from the rollback above and from the explicit "purge" action
 * in the admin console, which requires an operator to type the slug to confirm.
 */
function drop_tenant_database(mysqli $control, string $dbName): bool
{
    // Refuse to drop anything that is not one of ours. A bug that passed the
    // wrong string here would otherwise be able to destroy the control database.
    if (!preg_match('/^shems_[a-z0-9_]{1,50}$/', $dbName)) {
        error_log("drop_tenant_database: refusing to drop unexpected database name '{$dbName}'");
        return false;
    }

    $safe = '`' . str_replace('`', '', $dbName) . '`';

    return (bool)$control->query("DROP DATABASE IF EXISTS {$safe}");
}

/**
 * Remove a tenant's uploaded files.
 *
 * REPORTS FAILURE rather than swallowing it. This used to be all @unlink and
 * @rmdir with no return value, so a purge that could not actually remove the
 * files still told the operator the company had been permanently deleted. A
 * client's logo surviving a "permanent purge" is not something to discover
 * later — the caller needs to know so it can say so and name the path.
 *
 * The usual cause is ownership: files written by a CLI run as root cannot be
 * removed by Apache running as www-data.
 *
 * @return array{ok:bool, removed:int, remaining:string[], error:string}
 */
function delete_tenant_uploads(string $slug): array
{
    if (!preg_match('/^[a-z0-9][a-z0-9-]{1,38}$/', $slug)) {
        return ['ok' => false, 'removed' => 0, 'remaining' => [], 'error' => 'Invalid company address.'];
    }

    $dir = __DIR__ . '/../' . tenant_upload_dir($slug);

    if (!is_dir($dir)) {
        return ['ok' => true, 'removed' => 0, 'remaining' => [], 'error' => ''];
    }

    $removed   = 0;
    $remaining = [];

    // Recursive, deepest-first, so nested folders empty before we remove them.
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        /** @var SplFileInfo $item */
        $path = $item->getPathname();
        $done = $item->isDir() ? @rmdir($path) : @unlink($path);

        if ($done) {
            $removed++;
        } else {
            $remaining[] = $path;
        }
    }

    if (!@rmdir($dir) && is_dir($dir)) {
        $remaining[] = $dir;
    }

    if ($remaining !== []) {
        error_log('delete_tenant_uploads: could not remove ' . count($remaining)
            . ' path(s) under ' . $dir . ' — check ownership/permissions.');

        return [
            'ok'        => false,
            'removed'   => $removed,
            'remaining' => $remaining,
            'error'     => 'Some uploaded files could not be deleted.',
        ];
    }

    return ['ok' => true, 'removed' => $removed, 'remaining' => [], 'error' => ''];
}

/**
 * Validate an uploaded logo and report the extension to save it under.
 *
 * The type is decided by INSPECTING THE BYTES, never the filename the browser
 * sent. This is a public, unauthenticated endpoint, so a file called
 * "logo.png" containing PHP is a realistic submission rather than a theoretical
 * one. (uploads/.htaccess is the second layer that stops such a file executing.)
 *
 * @return array{ok:bool, extension:string, error:string}
 */
function validate_logo_upload(array $file): array
{
    $bad = static fn(string $m): array => ['ok' => false, 'extension' => '', 'error' => $m];

    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return $bad('Please choose a logo image.');
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return $bad(match ($file['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That image is too large. Please use one under 5 MB.',
            UPLOAD_ERR_PARTIAL                        => 'The upload was interrupted. Please try again.',
            default                                   => 'The logo could not be uploaded. Please try again.',
        });
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        return $bad('The logo could not be read. Please try again.');
    }

    if ($file['size'] > 5 * 1024 * 1024) {
        return $bad('That image is too large. Please use one under 5 MB.');
    }

    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        return $bad('That file is not an image we can read. Please upload a PNG, JPG or WebP.');
    }

    $extension = match ($info[2]) {
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_GIF  => 'gif',
        IMAGETYPE_WEBP => 'webp',
        default        => '',
    };

    if ($extension === '') {
        return $bad('Please upload a PNG, JPG, GIF or WebP image.');
    }

    if ($info[0] < 32 || $info[1] < 32) {
        return $bad('That image is very small. Please upload a logo at least 32x32 pixels.');
    }

    return ['ok' => true, 'extension' => $extension, 'error' => ''];
}
