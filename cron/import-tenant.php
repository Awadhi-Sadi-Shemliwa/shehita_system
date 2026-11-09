<?php
/**
 * SHEHITA EMS — Adopt an existing database as a client company
 *
 * Written for bringing ARC in, but works for any existing single-tenant copy of
 * this codebase.
 *
 * ARC (D:\coding\ARCSystem, arc-system.shehita.co.tz) was a fork of this
 * codebase with nothing changed but the palette, the logo filename and the
 * page title. All three of those are per-company data here, so ARC does not
 * need migrating in any real sense — it needs ADOPTING. Its database already
 * has exactly the right schema, so this registers it as a company and points
 * the tenant row at the database that is already there.
 *
 * No rows are copied and nothing in the adopted database is rewritten, so this
 * is safe to run against live data.
 *
 * CLI only. Usage:
 *
 *   docker compose -f docker-compose.prod.yml exec web php cron/import-tenant.php \
 *       --slug=arc \
 *       --name="Actuarial and Risk Consulting" \
 *       --db=arc_business \
 *       --email=admin@arc.co.tz \
 *       --logo=uploads/systemlogo/arc.png \
 *       --primary=#7d5419 \
 *       --days=3650
 *
 * Options:
 *   --slug      URL path the company will live at:  /arc/login.php   (required)
 *   --name      Display name shown in the UI and on invoices          (required)
 *   --db        EXISTING database to adopt                            (required)
 *   --email     Contact address for subscription email                (required)
 *   --logo      Path to a logo file, relative to the web root         (optional)
 *   --primary   Brand colour hex, overriding what the logo suggests   (optional)
 *   --days      Days of paid subscription to grant, default 365       (optional)
 *   --dry-run   Print what would happen and change nothing
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../lib/util.php';

platform_boot();

require_once __DIR__ . '/../lib/control_db.php';
require_once __DIR__ . '/../lib/schema.php';
require_once __DIR__ . '/../lib/brand.php';
require_once __DIR__ . '/../lib/provision.php';
require_once __DIR__ . '/../lib/subscription.php';

$opts = getopt('', ['slug:', 'name:', 'db:', 'email:', 'logo::', 'primary::', 'days::', 'dry-run']);

foreach (['slug', 'name', 'db', 'email'] as $required) {
    if (empty($opts[$required])) {
        fwrite(STDERR, "Missing --{$required}. See the comment at the top of this file.\n");
        exit(1);
    }
}

$slug    = strtolower(trim((string)$opts['slug']));
$name    = trim((string)$opts['name']);
$dbName  = trim((string)$opts['db']);
$email   = trim((string)$opts['email']);
$logo    = trim((string)($opts['logo'] ?? ''));
$primary = trim((string)($opts['primary'] ?? ''));
$days    = (int)($opts['days'] ?? 365);
$dryRun  = array_key_exists('dry-run', $opts);

$control = control_db();

// ---- checks ---------------------------------------------------------------

$reason = slug_rejection_reason($slug, $control);
if ($reason !== '') {
    fwrite(STDERR, "Cannot use slug '{$slug}': {$reason}\n");
    exit(1);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "'{$email}' is not a valid email address.\n");
    exit(1);
}

// The database must already exist — this command adopts, it does not create.
$exists = false;
$stmt = $control->prepare("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?");
$stmt->bind_param("s", $dbName);
$stmt->execute();
$exists = $stmt->get_result()->num_rows > 0;
$stmt->close();

if (!$exists) {
    fwrite(STDERR, "Database '{$dbName}' does not exist on this MySQL server.\n");
    fwrite(STDERR, "Restore it here first, then re-run. This command adopts an existing database; it does not create one.\n");
    exit(1);
}

$stmt = $control->prepare("SELECT slug FROM tenants WHERE db_name = ?");
$stmt->bind_param("s", $dbName);
$stmt->execute();
$claimed = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($claimed !== null) {
    fwrite(STDERR, "Database '{$dbName}' is already registered to the company '{$claimed['slug']}'.\n");
    exit(1);
}

// Sanity-check the contents so we fail loudly rather than adopting the wrong DB.
$adopted = tenant_connection($dbName, false);
$tables = [];
$r = $adopted->query("SHOW TABLES");
while ($row = $r->fetch_row()) { $tables[] = $row[0]; }

$expected = ['users', 'roles', 'departments', 'permissions', 'customers'];
$missing  = array_diff($expected, $tables);

if ($missing !== []) {
    fwrite(STDERR, "'{$dbName}' does not look like a SHEHITA EMS database — missing: "
        . implode(', ', $missing) . "\n");
    exit(1);
}

$userCount = (int)$adopted->query("SELECT COUNT(*) c FROM users")->fetch_assoc()['c'];
$custCount = (int)$adopted->query("SELECT COUNT(*) c FROM customers")->fetch_assoc()['c'];

// ---- brand ----------------------------------------------------------------

$palette = brand_default();
$logoForDb = null;

if ($primary !== '' && hex_to_rgb($primary) !== null) {
    $palette = brand_ramp($primary);
} elseif ($logo !== '' && is_file(__DIR__ . '/../' . $logo)) {
    $palette = brand_from_logo(__DIR__ . '/../' . $logo);
}

// Copy the logo into this company's own upload folder, so it follows the same
// convention as every company created through signup.
if ($logo !== '' && is_file(__DIR__ . '/../' . $logo)) {
    $ext    = pathinfo($logo, PATHINFO_EXTENSION) ?: 'png';
    $dir    = tenant_upload_dir($slug);
    $target = __DIR__ . '/../' . $dir . 'logo.' . $ext;

    if (!$dryRun) {
        @mkdir(dirname($target), 0777, true);
        // This command is normally run as root via `docker compose exec`, while
        // the admin console runs as www-data. Without this, purging the company
        // later cannot remove the logo it just wrote.
        make_upload_path_writable(dirname($target));
        if (@copy(__DIR__ . '/../' . $logo, $target)) {
            make_upload_path_writable($target);
            $logoForDb = $dir . 'logo.' . $ext;
        }
    } else {
        $logoForDb = $dir . 'logo.' . $ext;
    }
}

// ---- report ---------------------------------------------------------------

$base = rtrim(getenv('APP_BASE_URL') ?: 'https://system.shehita.co.tz', '/');

echo "\n";
echo "Adopting an existing database as a client company\n";
echo "-------------------------------------------------\n";
echo "  Company      : {$name}\n";
echo "  Portal       : {$base}/{$slug}/login.php\n";
echo "  Database     : {$dbName}  ({$userCount} users, {$custCount} customers — left untouched)\n";
echo "  Contact      : {$email}\n";
echo "  Brand colour : {$palette['primary']}\n";
echo "  Logo         : " . ($logoForDb ?? '(none — SHEHITA default palette)') . "\n";
echo "  Subscription : {$days} days from today\n";
echo "\n";

if ($dryRun) {
    echo "--dry-run given: nothing was changed.\n";
    exit(0);
}

// ---- adopt ----------------------------------------------------------------

$rampJson   = json_encode($palette, JSON_UNESCAPED_SLASHES);
$primaryHex = $palette['primary'];
$trialStart = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
$paidUntil  = (new DateTimeImmutable('now'))->modify("+{$days} days")->format('Y-m-d H:i:s');

$stmt = $control->prepare(
    "INSERT INTO tenants
        (slug, company_name, db_name, logo_path, brand_primary, brand_ramp,
         contact_name, contact_email, status, trial_started_at, trial_days, paid_until)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?, 14, ?)"
);
$contactName = $name;
$stmt->bind_param(
    "ssssssssss",
    $slug, $name, $dbName, $logoForDb, $primaryHex, $rampJson,
    $contactName, $email, $trialStart, $paidUntil
);

if (!$stmt->execute()) {
    fwrite(STDERR, "Failed to register the company: " . $control->error . "\n");
    exit(1);
}
$tenantId = (int)$stmt->insert_id;
$stmt->close();

// Bring the adopted schema up to date, and make sure the company profile shown
// on its invoices carries the right name rather than a stale forked default.
ensure_tenant_schema($adopted);

$row = $adopted->query("SELECT id FROM company_settings LIMIT 1")->fetch_assoc();
if ($row === null) {
    seed_tenant_company_settings($adopted, $name, $email, '', (string)$logoForDb);
} else {
    $stmt = $adopted->prepare("UPDATE company_settings SET company_name = ? WHERE id = ?");
    $rowId = (int)$row['id'];
    $stmt->bind_param("si", $name, $rowId);
    $stmt->execute();
    $stmt->close();
}

platform_audit_log($control, 'cli:import-tenant', 'tenant.adopted', $tenantId, [
    'slug' => $slug, 'db' => $dbName, 'users' => $userCount, 'customers' => $custCount,
]);

echo "Done. Company registered as id {$tenantId}.\n\n";
echo "Next:\n";
echo "  1. Open {$base}/{$slug}/login.php and sign in with an EXISTING account from that database.\n";
echo "     (No new administrator was created — the users already in {$dbName} are unchanged.)\n";
echo "  2. If this company had its own hostname, add the rewrite vhost so old links keep working:\n";
echo "     deploy/nginx/arc-system.shehita.co.tz.conf is a working example.\n";
echo "  3. Check the colours at {$base}/{$slug}/login.php; adjust in the admin console if needed.\n";
