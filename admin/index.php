<?php
/**
 * SHEHITA EMS — Admin dashboard
 *
 * The at-a-glance view: how many companies exist, who is about to lapse, and
 * what has come in this month.
 *
 * Every "is this company active?" judgement on this page goes through
 * subscription_resolve(), the same function the customer's own billing page and
 * the lockout in homepage.php use — so the numbers here can never disagree with
 * what a customer is being told.
 */

require_once __DIR__ . '/_auth.php';

$admin   = require_platform_admin();
$control = control_db();

$tenants = $control->query("SELECT * FROM tenants WHERE status != 'Deleted' ORDER BY created_at DESC")
    ->fetch_all(MYSQLI_ASSOC);

$counts  = ['trial' => 0, 'active' => 0, 'expiring' => 0, 'lapsed' => 0, 'suspended' => 0];
$expiring = [];

foreach ($tenants as $tenant) {
    $state = subscription_resolve($tenant);

    switch ($state['status']) {
        case 'trial':
            $counts['trial']++;
            break;
        case 'active':
            $counts['active']++;
            break;
        case 'suspended':
            $counts['suspended']++;
            break;
        default:
            $counts['lapsed']++;
    }

    // Anything active but inside a week of running out.
    if ($state['is_active'] && $state['days_left'] <= 7) {
        $counts['expiring']++;
        $expiring[] = ['tenant' => $tenant, 'state' => $state];
    }
}

usort($expiring, static fn($a, $b) => $a['state']['days_left'] <=> $b['state']['days_left']);

// Money in this calendar month.
$revenue = $control->query(
    "SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) AS n
       FROM subscription_payments
      WHERE status = 'Paid'
        AND YEAR(created_at) = YEAR(CURDATE())
        AND MONTH(created_at) = MONTH(CURDATE())"
)->fetch_assoc();

$pending = $control->query(
    "SELECT p.*, t.company_name, t.slug
       FROM subscription_payments p
       JOIN tenants t ON t.id = p.tenant_id
      WHERE p.status = 'Pending'
      ORDER BY p.created_at ASC
      LIMIT 10"
)->fetch_all(MYSQLI_ASSOC);

$recent = array_slice($tenants, 0, 8);

echo admin_head('Dashboard');
echo admin_nav($admin, 'index.php');
?>
<div class="wrap">
    <h1>Dashboard</h1>
    <p class="lede">Every company using SHEHITA EMS, and where they stand.</p>

    <div class="stats">
        <div class="stat">
            <div class="n"><?= count($tenants) ?></div>
            <div class="l">Companies</div>
        </div>
        <div class="stat">
            <div class="n"><?= $counts['trial'] ?></div>
            <div class="l">On free trial</div>
        </div>
        <div class="stat">
            <div class="n"><?= $counts['active'] ?></div>
            <div class="l">Paid &amp; active</div>
        </div>
        <div class="stat">
            <div class="n"><?= $counts['lapsed'] ?></div>
            <div class="l">Locked out</div>
        </div>
        <div class="stat">
            <div class="n"><?= admin_money((float)$revenue['total']) ?></div>
            <div class="l">This month (<?= (int)$revenue['n'] ?> payments)</div>
        </div>
    </div>

    <?php if ($pending !== []): ?>
        <div class="card">
            <h2>Payments awaiting your confirmation</h2>
            <p class="muted">
                These companies say they have paid. Check each against your bank or mobile-money
                records before confirming — confirming extends their subscription immediately.
            </p>
            <div style="overflow-x:auto;">
            <table>
                <thead><tr><th>Company</th><th>Package</th><th>Amount</th><th>Reference</th><th>Told us</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($pending as $p): ?>
                    <tr>
                        <td><a href="tenant.php?id=<?= (int)$p['tenant_id'] ?>"><?= htmlspecialchars((string)$p['company_name']) ?></a></td>
                        <td><?= htmlspecialchars((string)$p['plan_code']) ?></td>
                        <td><?= htmlspecialchars(admin_money((float)$p['amount'], (string)$p['currency'])) ?></td>
                        <td><?= htmlspecialchars((string)$p['reference']) ?></td>
                        <td><?= htmlspecialchars(date('j M, H:i', strtotime((string)$p['created_at']))) ?></td>
                        <td><a class="btn ghost" href="tenant.php?id=<?= (int)$p['tenant_id'] ?>#payments">Review</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($expiring !== []): ?>
        <div class="card">
            <h2>Running out within 7 days</h2>
            <p class="muted">Worth a nudge before they lose access.</p>
            <div style="overflow-x:auto;">
            <table>
                <thead><tr><th>Company</th><th>Status</th><th>Ends</th><th>Contact</th></tr></thead>
                <tbody>
                <?php foreach ($expiring as $row): ?>
                    <tr>
                        <td><a href="tenant.php?id=<?= (int)$row['tenant']['id'] ?>"><?= htmlspecialchars((string)$row['tenant']['company_name']) ?></a></td>
                        <td><?= admin_status_pill($row['state']) ?></td>
                        <td><?= $row['state']['ends_at'] ? htmlspecialchars($row['state']['ends_at']->format('j M Y')) : '—' ?></td>
                        <td class="muted"><?= htmlspecialchars((string)$row['tenant']['contact_email']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2>Newest companies</h2>
        <div style="overflow-x:auto;">
        <table>
            <thead><tr><th>Company</th><th>Address</th><th>Status</th><th>Joined</th></tr></thead>
            <tbody>
            <?php foreach ($recent as $tenant): $state = subscription_resolve($tenant); ?>
                <tr>
                    <td>
                        <span class="swatch" style="background:<?= htmlspecialchars((string)($tenant['brand_primary'] ?: '#7b583f')) ?>"></span>
                        <a href="tenant.php?id=<?= (int)$tenant['id'] ?>"><?= htmlspecialchars((string)$tenant['company_name']) ?></a>
                    </td>
                    <td class="muted">/<?= htmlspecialchars((string)$tenant['slug']) ?></td>
                    <td><?= admin_status_pill($state) ?></td>
                    <td class="muted"><?= htmlspecialchars(date('j M Y', strtotime((string)$tenant['created_at']))) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($recent === []): ?>
                <tr><td colspan="4" class="muted">No companies yet. They appear here as soon as someone signs up.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
</body>
</html>
