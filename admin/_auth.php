<?php
/**
 * SHEHITA EMS — Platform admin authentication
 *
 * SHEHITA's own staff — the "super-duper admin" above every client company.
 *
 * This is a SEPARATE identity system from any company's users table. A client's
 * role_id = 1 Super Admin is only super inside their own company and has no
 * standing here at all; conversely nothing in this file grants access to a
 * company's business data by itself.
 *
 * The session is scoped to /admin/ with its own cookie name, so it can never be
 * confused with (or clobbered by) a tenant session on the same domain.
 */

require_once __DIR__ . '/../lib/util.php';

platform_boot();
platform_session_start('SHEMS_PLATFORM_ADMIN', '/admin/');

require_once __DIR__ . '/../lib/control_db.php';
require_once __DIR__ . '/../lib/subscription.php';
require_once __DIR__ . '/../lib/provision.php';
require_once __DIR__ . '/../lib/mailer.php';
require_once __DIR__ . '/../lib/email_templates.php';

/**
 * Stop the page unless a SHEHITA administrator is signed in.
 * Call at the top of every admin page except login and the emailed
 * payment-confirmation link.
 */
function require_platform_admin(): array
{
    if (empty($_SESSION['platform_admin_id'])) {
        header('Location: login.php');
        exit();
    }

    return [
        'id'    => (int)$_SESSION['platform_admin_id'],
        'name'  => (string)($_SESSION['platform_admin_name'] ?? ''),
        'email' => (string)($_SESSION['platform_admin_email'] ?? ''),
        'role'  => (string)($_SESSION['platform_admin_role'] ?? 'Billing'),
    ];
}

/**
 * Destructive actions (purging a company's database) are restricted to Owners.
 */
function require_platform_owner(): array
{
    $admin = require_platform_admin();

    if ($admin['role'] !== 'Owner') {
        http_response_code(403);
        render_notice_page(403, 'Not permitted',
            'This action is limited to SHEHITA account owners.');
    }

    return $admin;
}

/**
 * Shared page chrome for the admin console.
 */
function admin_head(string $title): string
{
    $safe = htmlspecialchars($title);

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$safe} — SHEHITA Admin</title>
<link rel="icon" href="../uploads/systemlogo/shehita-logo.jpg">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{
  --brown-900:#3e2b1f;--brown-800:#5c3e2d;--brown-700:#7b583f;--brown-600:#9b7a5a;
  --brown-500:#b89b7e;--brown-400:#d4bfa8;--brown-300:#e8d9cc;--brown-200:#f0e8df;--brown-100:#f7f2ec;
  --gray-500:#64748b;--gray-700:#334155;--gray-800:#1e293b;
}
body{font-family:'Inter',system-ui,sans-serif;background:#f1f5f9;color:var(--gray-800);min-height:100vh}
a{color:var(--brown-700)}

.topbar{background:linear-gradient(135deg,#2a1f18 0%,#1e1610 100%);padding:0 22px;
  display:flex;align-items:center;gap:22px;height:60px;position:sticky;top:0;z-index:20}
.topbar .brand{color:#fff;font-weight:700;letter-spacing:.05em;font-size:1rem}
.topbar .brand span{color:var(--brown-400);font-weight:500;font-size:.8rem;margin-left:7px}
.topbar nav{display:flex;gap:4px;flex:1;flex-wrap:wrap}
.topbar nav a{color:rgba(255,255,255,.78);text-decoration:none;font-size:.9rem;font-weight:500;
  padding:7px 13px;border-radius:7px}
.topbar nav a:hover{background:rgba(255,255,255,.1);color:#fff}
.topbar nav a.on{background:rgba(255,255,255,.16);color:#fff}
.topbar .who{color:rgba(255,255,255,.65);font-size:.83rem}
.topbar .who a{color:#fff;margin-left:10px;text-decoration:none;font-weight:600}

.wrap{max-width:1180px;margin:0 auto;padding:26px 20px 60px}
h1{font-size:1.3rem;color:var(--brown-900);margin-bottom:5px}
.lede{color:var(--gray-500);font-size:.92rem;margin-bottom:22px;line-height:1.6}

.card{background:#fff;border-radius:13px;padding:22px 24px;margin-bottom:18px;
  box-shadow:0 1px 3px rgb(0 0 0 / .08)}
.card h2{font-size:1rem;color:var(--brown-900);margin-bottom:4px}

.stats{display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(min(160px, 100%), 1fr));margin-bottom:20px}
.stat{background:#fff;border-radius:13px;padding:18px 20px;box-shadow:0 1px 3px rgb(0 0 0 / .08)}
.stat .n{font-size:1.75rem;font-weight:700;color:var(--brown-800);line-height:1.1}
.stat .l{font-size:.81rem;color:var(--gray-500);margin-top:5px;font-weight:500}

table{width:100%;border-collapse:collapse;font-size:.89rem}
th,td{text-align:left;padding:11px 12px;border-bottom:1px solid var(--brown-200);vertical-align:middle}
th{color:var(--gray-500);font-weight:600;font-size:.78rem;text-transform:uppercase;letter-spacing:.04em}
tbody tr:hover{background:var(--brown-100)}

.pill{display:inline-block;padding:3px 10px;border-radius:999px;font-size:.73rem;font-weight:700}
.pill.ok{background:#dcfce7;color:#166534}
.pill.warn{background:#fef3c7;color:#92400e}
.pill.bad{background:#fee2e2;color:#991b1b}
.pill.mute{background:#e2e8f0;color:#475569}

label{display:block;font-size:.83rem;font-weight:600;color:var(--gray-700);margin-bottom:5px}
input,select,textarea{width:100%;padding:10px 13px;border:1.5px solid var(--brown-200);
  border-radius:8px;font-family:inherit;font-size:.92rem;background:#fff;color:var(--gray-800)}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--brown-600)}
.field{margin-bottom:14px}
.grid2{display:grid;gap:14px;grid-template-columns:1fr 1fr}
.grid3{display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(min(180px, 100%), 1fr))}
@media(max-width:600px){.grid2{grid-template-columns:1fr}}

/* ------------------------------------------------------------------------
   Responsive. This file is the shell for all nine admin pages, so these
   rules cover the whole console.

   The topbar was a fixed-height single row that could not wrap: on a narrow
   screen the "who / Sign out" block was pushed straight off the right-hand
   edge, taking 119px of the page with it and making every admin page scroll
   sideways.
   ------------------------------------------------------------------------ */
@media(max-width:820px){
  .topbar{height:auto;flex-wrap:wrap;gap:10px 16px;padding:10px 16px}
  .topbar nav{flex:1 1 100%;order:3;gap:2px}
  .topbar .who{margin-left:auto;font-size:.78rem}
  .wrap{padding:20px 16px 48px}
}

@media(max-width:479px){
  .topbar{padding:9px 12px;gap:8px 10px}
  .topbar .brand{font-size:.92rem}
  .topbar nav a{padding:6px 9px;font-size:.84rem}
  .topbar .who{font-size:.74rem}
  .topbar .who a{margin-left:7px}
  .wrap{padding:16px 12px 40px}
  .card{padding:16px 14px;border-radius:11px}
  .stats{grid-template-columns:1fr;gap:10px}
  .grid3{grid-template-columns:1fr}
  h1{font-size:1.15rem}
  .btn{padding:10px 16px;font-size:.88rem}
}

/* Tables in this console are wrapped in overflow-x:auto containers at their
   call sites, which is the right pattern for wide tabular data -- this only
   makes the scroll obvious rather than something you discover by accident. */
@media(max-width:820px){
  .card > div[style*="overflow-x"]{-webkit-overflow-scrolling:touch}
  table{min-width:640px}
}

.btn{padding:10px 20px;border:none;border-radius:8px;background:var(--brown-700);color:#fff;
  font-weight:600;font-size:.91rem;cursor:pointer;font-family:inherit;text-decoration:none;
  display:inline-block}
.btn:hover{background:var(--brown-800)}
.btn.ghost{background:#fff;color:var(--brown-800);border:1.5px solid var(--brown-300)}
.btn.ghost:hover{background:var(--brown-100)}
.btn.danger{background:#dc2626}
.btn.danger:hover{background:#b91c1c}

.alert{padding:13px 16px;border-radius:9px;margin-bottom:18px;font-size:.92rem;line-height:1.6}
.alert.err{background:#fee2e2;color:#991b1b;border-left:3px solid #dc2626}
.alert.ok{background:#dcfce7;color:#166534;border-left:3px solid #16a34a}
.alert.warn{background:#fef3c7;color:#92400e;border-left:3px solid #d97706}
.alert ul{margin:6px 0 0 18px}

.muted{color:var(--gray-500);font-size:.88rem;line-height:1.6}
.swatch{display:inline-block;width:15px;height:15px;border-radius:4px;vertical-align:-2px;
  margin-right:6px;border:1px solid rgba(0,0,0,.12)}
</style>
</head>
<body>
HTML;
}

function admin_nav(array $admin, string $current): string
{
    $items = [
        'index.php'    => 'Dashboard',
        'tenants.php'  => 'Companies',
        'payments.php' => 'Payments',
        'plans.php'    => 'Packages',
        'settings.php' => 'Settings',
    ];

    $links = '';
    foreach ($items as $href => $label) {
        $on = $href === $current ? ' class="on"' : '';
        $links .= "<a href=\"{$href}\"{$on}>{$label}</a>";
    }

    $who = htmlspecialchars($admin['email']);

    return <<<HTML
<div class="topbar">
  <div class="brand">SHEHITA<span>admin</span></div>
  <nav>{$links}</nav>
  <div class="who">{$who}<a href="logout.php">Sign out</a></div>
</div>
HTML;
}

/**
 * Status pill for a tenant, from the shared resolver so the admin console and
 * the customer's own billing page always agree.
 */
function admin_status_pill(array $state): string
{
    $tone = match ($state['status']) {
        'active' => $state['days_left'] <= 7 ? 'warn' : 'ok',
        'trial'  => $state['days_left'] <= 3 ? 'warn' : 'ok',
        'suspended' => 'mute',
        default  => 'bad',
    };

    $label = match ($state['status']) {
        'trial'         => 'Trial · ' . $state['days_left'] . 'd',
        'active'        => 'Active · ' . $state['days_left'] . 'd',
        'trial_expired' => 'Trial ended',
        'expired'       => 'Expired',
        'suspended'     => $state['label'],
        default         => $state['label'],
    };

    return '<span class="pill ' . $tone . '">' . htmlspecialchars($label) . '</span>';
}

function admin_money(float $amount, string $currency = 'TZS'): string
{
    return number_format($amount, 0) . ' ' . $currency;
}
