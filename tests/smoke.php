<?php
/**
 * SHEHITA EMS — end-to-end smoke test
 *
 * Drives the real HTTP endpoints against a running instance. Run it after any
 * deploy, and after changing anything in bootstrap.php, .htaccess, config.php
 * or the subscription code:
 *
 *   docker compose exec web php tests/smoke.php
 *   docker compose -f docker-compose.prod.yml exec web php tests/smoke.php --base=https://system.shehita.co.tz
 *
 * It creates two throwaway companies (`smoketest-a`, `smoketest-b`), exercises
 * signup, tenant isolation, the subscription gate, the emailed payment loop and
 * the assistant's permission checks, then deletes everything it made.
 *
 * DO NOT run against production with real customer data unless you are happy
 * for it to create and drop two databases named shems_smoketest_*.
 *
 * Two habits here exist because of bugs that got through without them:
 *
 *  - Redirects are FOLLOWED, not just inspected. A test that asserts
 *    `Location: homepage.php` passes even when that URL 404s or bounces to
 *    signup. The login form once posted to a path that had lost the company
 *    prefix; every header-only check passed and the app was still broken in a
 *    real browser.
 *  - Form actions are checked for the company prefix, because $_SERVER['PHP_SELF']
 *    returns the REWRITTEN path under multi-tenant routing.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$opts = getopt('', ['base::', 'keep']);
$BASE = rtrim((string)($opts['base'] ?? (getenv('APP_BASE_URL') ?: 'http://localhost')), '/');
$KEEP = array_key_exists('keep', $opts);

// When running inside the container, talk to Apache directly rather than going
// back out through the host's published port.
if (str_contains($BASE, 'localhost:8080')) {
    $BASE = 'http://localhost';
}

require_once __DIR__ . '/../lib/util.php';
platform_boot();
require_once __DIR__ . '/../lib/control_db.php';
require_once __DIR__ . '/../lib/subscription.php';
require_once __DIR__ . '/../lib/provision.php';

$passed = 0;
$failed = [];

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * @param bool $follow Follow redirects. Default TRUE -- see the note at the top.
 */
function http(string $path, ?array $post = null, string $jar = '', array $files = [], bool $follow = true): array
{
    global $BASE;

    $ch = curl_init(str_starts_with($path, 'http') ? $path : $BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 30,
    ]);
    if ($jar !== '') {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
    }
    if ($post !== null) {
        foreach ($files as $field => $file) {
            $post[$field] = new CURLFile($file, mime_content_type($file), basename($file));
        }
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $files ? $post : http_build_query($post));
    }

    $raw   = curl_exec($ch);
    $info  = curl_getinfo($ch);
    curl_close($ch);

    $body = substr((string)$raw, (int)$info['header_size']);
    preg_match('/^Location:\s*(.+)$/mi', substr((string)$raw, 0, (int)$info['header_size']), $m);

    return [
        'status'   => (int)$info['http_code'],
        'body'     => $body,
        'final'    => (string)$info['url'],
        'location' => trim($m[1] ?? ''),
    ];
}

function csrf(string $body): string
{
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $body, $m);
    return $m[1] ?? '';
}

function ok(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  ok    {$label}\n";
    } else {
        $failed[] = $label;
        echo "  FAIL  {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n";
    }
}

function section(string $name): void
{
    echo "\n{$name}\n";
}

/** A logo with a coloured mark on white with black "text" — a real logo's shape. */
function make_logo(string $path, array $rgb): void
{
    $im = imagecreatetruecolor(400, 200);
    imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
    imagefilledellipse($im, 105, 100, 120, 120, imagecolorallocate($im, ...$rgb));
    $black = imagecolorallocate($im, 25, 25, 25);
    imagefilledrectangle($im, 190, 84, 380, 101, $black);
    imagefilledrectangle($im, 190, 113, 320, 125, $black);
    imagepng($im, $path);
    imagedestroy($im);
}

// ---------------------------------------------------------------------------

$control = control_db();
$tmp     = sys_get_temp_dir();
$A       = 'smoketest-a';
$B       = 'smoketest-b';

// signup.php throttles to 5 new companies per hour per IP, which is right for
// the real world but would block this test from being run twice in a row. Clear
// only the rows this test's own signups created.
$control->query(
    "DELETE FROM signup_attempts WHERE slug IN ('" . $A . "','" . $B . "')"
);

// Clean up anything a previous interrupted run left behind.
foreach ([$A, $B] as $slug) {
    if (($t = tenant_by_slug($control, $slug)) !== null) {
        drop_tenant_database($control, (string)$t['db_name']);
        delete_tenant_uploads($slug);
        $control->query("DELETE FROM tenants WHERE id = " . (int)$t['id']);
    }
}

make_logo("{$tmp}/smoke_a.png", [0x1d, 0x4e, 0xd8]);   // blue
make_logo("{$tmp}/smoke_b.png", [0x0f, 0x7b, 0x3f]);   // green

echo "SHEHITA EMS smoke test against {$BASE}\n";

// ---------------------------------------------------------------------------
section('Routing');

$r = http('/', null, '', [], false);
ok('the bare domain points at signup', str_contains($r['location'], 'signup'), $r['location']);

$r = http('/signup.php');
ok('signup page loads', $r['status'] === 200);

$r = http('/no-such-company-here/login.php');
ok('an unknown company gives a clear 404', $r['status'] === 404, 'status=' . $r['status']);

// ---------------------------------------------------------------------------
section('Signup');

foreach ([[$A, 'Smoke Test A', 'smoke_a'], [$B, 'Smoke Test B', 'smoke_b']] as [$slug, $name, $logo]) {
    $jar  = "{$tmp}/smoke_{$slug}.jar";
    @unlink($jar);

    $page = http('/signup.php', null, $jar);
    $r = http('/signup.php', [
        'csrf_token'             => csrf($page['body']),
        'company_name'           => $name,
        'slug'                   => $slug,
        'admin_name'             => 'Smoke Owner',
        'admin_email'            => "owner@{$slug}.test",
        'contact_phone'          => '+255700000000',
        'admin_password'         => 'smoketest123',
        'admin_password_confirm' => 'smoketest123',
        'website'                => '',
    ], $jar, ['logo' => "{$tmp}/{$logo}.png"]);

    ok("company '{$slug}' is created", str_contains($r['body'], 'Welcome, ' . $name));
}

$ta = tenant_by_slug($control, $A);
$tb = tenant_by_slug($control, $B);

ok('each company got its own database', $ta && $tb && $ta['db_name'] !== $tb['db_name']);
ok('a brand colour was read from the logo', $ta && !empty($ta['brand_primary']), (string)($ta['brand_primary'] ?? ''));
ok('the two companies got different colours', $ta && $tb && $ta['brand_primary'] !== $tb['brand_primary']);

// ---------------------------------------------------------------------------
section('Branding');

$pa = http("/{$A}/login.php");
$pb = http("/{$B}/login.php");

ok('company A shows its own name', str_contains($pa['body'], 'Smoke Test A'));
ok('company A does not show company B', !str_contains($pa['body'], 'Smoke Test B'));

preg_match('/<style id="tenant-theme">:root\{(.*?)\}<\/style>/s', $pa['body'], $ma);
preg_match('/<style id="tenant-theme">:root\{(.*?)\}<\/style>/s', $pb['body'], $mb);
ok('each page carries a theme block', !empty($ma[1]) && !empty($mb[1]));
ok('the two themes differ', ($ma[1] ?? 'x') !== ($mb[1] ?? 'y'));

// REGRESSION: the login form must post back inside the company path.
// $_SERVER['PHP_SELF'] returns the rewritten path and silently drops the prefix.
preg_match('/<form method="POST"([^>]*)>/', $pa['body'], $formTag);
$formAttrs = $formTag[1] ?? '';
ok('the login form does not post to a rewritten absolute path',
   !preg_match('#action="/(?!' . preg_quote($A, '#') . ')#', $formAttrs),
   trim($formAttrs));

// ---------------------------------------------------------------------------
section('Login and isolation');

$jarA = "{$tmp}/smoke_login_a.jar";
@unlink($jarA);
$loginPage = http("/{$A}/login.php", null, $jarA);

// The login form is CSRF-protected, so a post without a token must be refused.
$r = http("/{$A}/login.php", [
    'email' => "owner@{$A}.test", 'password' => 'smoketest123', 'login' => '1',
], $jarA);
ok('a sign-in without a CSRF token is refused',
   !str_contains($r['final'], 'homepage.php'), 'ended at ' . $r['final']);

$loginPage = http("/{$A}/login.php", null, $jarA);

// FOLLOWED, so a redirect to the wrong place fails here rather than passing.
$r = http("/{$A}/login.php", [
    'csrf_token' => csrf($loginPage['body']),
    'email' => "owner@{$A}.test", 'password' => 'smoketest123', 'login' => '1',
], $jarA);

ok('signing in lands inside the company portal',
   str_contains($r['final'], "/{$A}/homepage.php") && $r['status'] === 200,
   'ended at ' . $r['final']);
ok('the portal shows the right company', str_contains($r['body'], 'Smoke Test A'));

$r = http("/{$B}/homepage.php?page=home", null, $jarA);
ok('the same browser is NOT signed in to the other company',
   str_contains($r['final'], "/{$B}/login.php"), 'ended at ' . $r['final']);

// Data isolation, checked at the database.
$ca = tenant_connection((string)$ta['db_name']);
$cb = tenant_connection((string)$tb['db_name']);
$ca->query("INSERT INTO customers (customer_name, status) VALUES ('Isolation Probe', 'Active')");
$seen = (int)$cb->query("SELECT COUNT(*) c FROM customers WHERE customer_name='Isolation Probe'")->fetch_assoc()['c'];
ok('a record in one company is invisible in the other', $seen === 0);

// ---------------------------------------------------------------------------
section('Subscription gate');

$aid = (int)$ta['id'];
$control->query("UPDATE tenants SET trial_started_at = DATE_SUB(NOW(), INTERVAL 400 DAY) WHERE id = {$aid}");

$r = http("/{$A}/homepage.php?page=home", null, $jarA);
ok('an expired admin is taken to billing', str_contains($r['final'], 'billing.php'), 'ended at ' . $r['final']);

$r = http("/{$A}/homepage.php?page=invoice", null, $jarA);
ok('a module cannot be opened directly once expired',
   str_contains($r['final'], 'billing.php'), 'ended at ' . $r['final']);

// ---------------------------------------------------------------------------
section('Payment by email');

$bill = http("/{$A}/billing.php", null, $jarA);
ok('the billing page is reachable while expired', $bill['status'] === 200);

$ref = 'SMOKE-' . bin2hex(random_bytes(4));
$r = http("/{$A}/billing.php", [
    'csrf_token' => csrf($bill['body']), 'claim_payment' => '1',
    'plan_code'  => 'monthly', 'amount' => '50000', 'method' => 'Bank', 'reference' => $ref,
], $jarA);
ok('the payment notice is accepted', str_contains($r['body'], 'passed your payment details'));

$pay = payment_by_reference($control, $aid, 'Bank', $ref);
ok('a pending payment was recorded', $pay !== null && $pay['status'] === 'Pending');
ok('only the token hash is stored', $pay !== null && strlen((string)$pay['confirm_token_hash']) === 64);

// Mint a fresh token the way billing.php does, to exercise the confirm endpoint
// without needing to scrape the outgoing email.
$raw  = bin2hex(random_bytes(32));
$hash = hash('sha256', $raw);
$pid  = (int)$pay['id'];
$control->query("UPDATE subscription_payments
                    SET confirm_token_hash = '{$hash}',
                        confirm_token_expires_at = DATE_ADD(NOW(), INTERVAL 7 DAY)
                  WHERE id = {$pid}");

$bad = http('/admin/confirm-payment.php?token=' . str_repeat('0', 64));
ok('a forged confirmation token is rejected', $bad['status'] === 400);

// A GET must NOT confirm anything. Mail security scanners fetch every link in a
// message; if opening the URL activated the subscription, the first scanner to
// touch the email would confirm an unverified payment on its own.
$preview = http('/admin/confirm-payment.php?token=' . $raw);
ok('opening the emailed link only shows a prompt',
   $preview['status'] === 200 && str_contains($preview['body'], 'Confirm this payment?'));

$stillPending = payment_by_reference($control, $aid, 'Bank', $ref);
ok('merely opening the link does NOT confirm the payment',
   $stillPending !== null && $stillPending['status'] === 'Pending',
   'status=' . ($stillPending['status'] ?? '?'));
ok('and does not extend the subscription',
   empty(tenant_by_id($control, $aid)['paid_until']));

$good = http('/admin/confirm-payment.php', ['token' => $raw]);
ok('submitting the confirm button activates it',
   $good['status'] === 200 && str_contains($good['body'], 'Payment confirmed'));

$ta2 = tenant_by_id($control, $aid);
ok('the subscription was extended', !empty($ta2['paid_until']), (string)$ta2['paid_until']);
$firstEnd = (string)$ta2['paid_until'];

ok('an invoice was issued', invoice_id_for_payment($control, $pid) !== null);

$r = http("/{$A}/homepage.php?page=home", null, $jarA);
ok('the company has access again', $r['status'] === 200 && str_contains($r['body'], 'Smoke Test A'));

// Idempotency.
$again = http('/admin/confirm-payment.php', ['token' => $raw]);
$ta3 = tenant_by_id($control, $aid);
ok('clicking the link twice does not extend twice', (string)$ta3['paid_until'] === $firstEnd);
$badPost = http('/admin/confirm-payment.php', ['token' => str_repeat('0', 64)]);
ok('a used link looks exactly like a forged one',
   $again['status'] === 400 && $again['body'] === $badPost['body']);

// Stacking.
$before = new DateTimeImmutable($firstEnd);
subscription_activate($control, $aid, 'monthly', 50000, 'Bank', $ref . '-2', 'smoketest');
$after = new DateTimeImmutable((string)tenant_by_id($control, $aid)['paid_until']);
$gained = (int)round(($after->getTimestamp() - $before->getTimestamp()) / 86400);
ok('a second month is added to the end date, not to today', $gained === 30, "gained {$gained} days");

// Load the app the way ai/chat.php does, so canView() is present. Without
// config.php the tools deny everything by design, which would make the
// permission assertions below pass for the wrong reason.
define('TENANT_DB', (string)$ta['db_name']);
$conn = require __DIR__ . '/../config.php';

require_once __DIR__ . '/../lib/ai_tools.php';

$GLOBALS['TENANT']       = $ta;      // read by the subscription tool
$GLOBALS['SUBSCRIPTION'] = subscription_resolve($ta);

ok('the permission helper is loaded', function_exists('canView'));

// ---------------------------------------------------------------------------
section('Cross-company safety');

// Payment references are chosen by banks and customers, not by us, and the same
// string genuinely recurs across companies. Two companies must be able to use
// the same reference without one crediting or blocking the other.
$shared = 'SHARED-' . bin2hex(random_bytes(3));
$bid    = (int)$tb['id'];

$r1 = subscription_activate($control, $aid, 'monthly', 50000, 'Bank', $shared, 'smoketest');
$r2 = subscription_activate($control, $bid, 'monthly', 50000, 'Bank', $shared, 'smoketest');

ok('company A can use a reference', $r1['ok'] && !$r1['duplicate']);
ok('company B can use the SAME reference', $r2['ok'] && !$r2['duplicate'],
   $r2['duplicate'] ? 'wrongly treated as a duplicate' : $r2['error']);
ok('both companies were actually credited',
   !empty(tenant_by_id($control, $aid)['paid_until'])
   && !empty(tenant_by_id($control, $bid)['paid_until']));
ok('the two payments are separate rows', $r1['payment_id'] !== $r2['payment_id']);

// ...while a repeat within ONE company is still idempotent.
$r3 = subscription_activate($control, $aid, 'monthly', 50000, 'Bank', $shared, 'smoketest');
ok('repeating it within one company is still a duplicate', $r3['duplicate']);

// Include-only code must never be reachable over HTTP, with or without a
// company prefix. modules/*.php run with no session and no $conn.
foreach (['modules/user-management.php', 'lib/control_db.php', 'partials/ai-assistant.php'] as $path) {
    $direct = http('/' . $path);
    $viaTenant = http("/{$A}/" . $path);
    ok("/{$path} is not served", $direct['status'] >= 400, 'status=' . $direct['status']);
    ok("/<company>/{$path} is not served", $viaTenant['status'] >= 400, 'status=' . $viaTenant['status']);
}

// ---------------------------------------------------------------------------
section('Privilege escalation guards');

// A role with members must not be deletable: users.role_id is ON DELETE SET NULL,
// and a role-less user used to be auto-promoted to Super Admin (role 1).
$ca->query("INSERT IGNORE INTO roles (id, name, department_id, status) VALUES (7,'Smoke Junior',1,'Active')");
$hash = password_hash('smoketest123', PASSWORD_DEFAULT);
$ca->query("INSERT IGNORE INTO users (id, name, email, password, department_id, role_id, status)
            VALUES (77,'Smoke Junior User','junior@" . $A . ".test','{$hash}',1,7,'Active')");

$members = (int)$ca->query("SELECT COUNT(*) c FROM users WHERE role_id = 7")->fetch_assoc()['c'];
ok('a junior role with a member exists for the test', $members === 1);

// Simulate what the old FK cascade did, then confirm nothing re-promotes them.
$ca->query("UPDATE users SET role_id = NULL WHERE id = 77");
$roleAfter = $ca->query("SELECT role_id FROM users WHERE id = 77")->fetch_assoc()['role_id'];
ok('a user left without a role stays without one', $roleAfter === null,
   'role_id=' . var_export($roleAfter, true));

ok('a role-less user has NO permissions', !canView($ca, 0, 'invoice'));
ok('a role-less user is not labelled Super Admin',
   getUserRoleName($ca, null) !== 'Super Admin', getUserRoleName($ca, null));

$ca->query("UPDATE users SET role_id = 7 WHERE id = 77");

// The permission cache must invalidate when permissions change, not go stale.
$ca->query("DELETE FROM permissions WHERE role_id = 7");
$v1 = permissions_current_version($ca, true);
$ca->query("INSERT INTO permissions (role_id, module_name, can_view) VALUES (7,'invoice',1)");
permissions_bump_version($ca);
$v2 = permissions_current_version($ca, true);
ok('changing permissions bumps the cache version', $v1 !== $v2);

$ca->query("DELETE FROM users WHERE id = 77");
$ca->query("DELETE FROM roles WHERE id = 7");

// ---------------------------------------------------------------------------
section('Assistant permissions');


$conn->query("INSERT IGNORE INTO roles (id,name,department_id,status) VALUES (2,'Smoke Staff',1,'Active')");
$conn->query("DELETE FROM permissions WHERE role_id = 2");
$conn->query("INSERT INTO permissions (role_id, module_name, can_view) VALUES (2,'customer-management',1)");

$r = ai_run_tool($conn, 1, 'count_records', ['entity' => 'customers']);
ok('an admin can count customers', str_contains($r, 'Total customers'));

$r = ai_run_tool($conn, 2, 'search_customers', ['query' => 'Isolation']);
ok('a limited role sees what it is allowed', str_contains($r, 'Isolation Probe'));

$r = ai_run_tool($conn, 2, 'list_invoices', []);
ok('a limited role is refused invoices', str_contains($r, 'PERMISSION DENIED'));

$r = ai_run_tool($conn, 2, 'financial_summary', []);
ok('a limited role is refused financial totals',
   str_contains($r, 'PERMISSION DENIED') && !str_contains($r, 'Total invoiced'));

$r = ai_run_tool($conn, 1, 'count_records', ['entity' => 'customers; DROP TABLE customers']);
ok('an unknown entity is rejected rather than interpolated', str_contains($r, 'Unknown record type'));
ok('the customers table survived', $conn->query("SHOW TABLES LIKE 'customers'")->num_rows === 1);

$r = http("/{$A}/ai/chat.php");
ok('the assistant endpoint refuses GET', $r['status'] === 405, 'status=' . $r['status']);

// ---------------------------------------------------------------------------
section('Cleanup');

if ($KEEP) {
    echo "  --keep given: smoketest-a and smoketest-b were left in place.\n";
} else {
    $uploadReports = [];
    foreach ([$A, $B] as $slug) {
        if (($t = tenant_by_slug($control, $slug)) !== null) {
            drop_tenant_database($control, (string)$t['db_name']);
            $uploadReports[$slug] = delete_tenant_uploads($slug);
            $control->query("DELETE FROM tenants WHERE id = " . (int)$t['id']);
        }
    }

    // Deleting a company must actually delete their uploaded logo, and must
    // report honestly when it cannot. A purge that says "done" while the
    // client's files are still on disk is the failure mode worth guarding.
    foreach ($uploadReports as $slug => $rep) {
        ok("uploads for {$slug} were really removed", $rep['ok'],
           $rep['ok'] ? '' : implode(', ', $rep['remaining']));
        ok("the upload folder for {$slug} is gone",
           !is_dir(__DIR__ . '/../' . tenant_upload_dir($slug)));
    }
    $gone = tenant_by_slug($control, $A) === null && tenant_by_slug($control, $B) === null;
    ok('the test companies were removed', $gone);
}

@unlink("{$tmp}/smoke_a.png");
@unlink("{$tmp}/smoke_b.png");

// ---------------------------------------------------------------------------

echo "\n";
if ($failed === []) {
    echo "PASSED — {$passed} checks\n";
    exit(0);
}

echo count($failed) . " FAILED of " . ($passed + count($failed)) . ":\n";
foreach ($failed as $f) {
    echo "  - {$f}\n";
}
exit(1);
