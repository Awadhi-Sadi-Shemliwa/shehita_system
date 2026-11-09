<?php
/**
 * SHEHITA EMS — All client companies
 *
 * Searchable, filterable list. Filtering by subscription state is done in PHP
 * rather than SQL because "active" is a computed judgement (trial window vs
 * paid_until) that lives in subscription_resolve() — pushing that logic into a
 * WHERE clause would duplicate it, and the two copies would drift.
 *
 * Company counts here are in the tens, not millions, so the cost is irrelevant.
 */

require_once __DIR__ . '/_auth.php';

$admin   = require_platform_admin();
$control = control_db();

$search      = trim((string)($_GET['q'] ?? ''));
$filter      = (string)($_GET['state'] ?? 'all');
$showDeleted = ($_GET['deleted'] ?? '') === '1';

$sql = "SELECT * FROM tenants";
$sql .= $showDeleted ? " WHERE status = 'Deleted'" : " WHERE status != 'Deleted'";

if ($search !== '') {
    $like = '%' . $search . '%';
    $sql .= " AND (company_name LIKE ? OR slug LIKE ? OR contact_email LIKE ?)";
    $stmt = $control->prepare($sql . " ORDER BY company_name");
    $stmt->bind_param("sss", $like, $like, $like);
} else {
    $stmt = $control->prepare($sql . " ORDER BY company_name");
}

$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$tenants = [];
foreach ($rows as $tenant) {
    $state = subscription_resolve($tenant);

    $matches = match ($filter) {
        'trial'   => $state['status'] === 'trial',
        'active'  => $state['status'] === 'active',
        'lapsed'  => in_array($state['status'], ['expired', 'trial_expired'], true),
        'expiring'=> $state['is_active'] && $state['days_left'] <= 7,
        default   => true,
    };

    if ($matches) {
        $tenants[] = ['tenant' => $tenant, 'state' => $state];
    }
}

$base = rtrim(getenv('APP_BASE_URL') ?: 'https://system.shehita.co.tz', '/');

/** Keep the other query parameters when switching filter. */
function filter_url(string $state, string $search, bool $deleted): string
{
    $params = ['state' => $state];
    if ($search !== '') { $params['q'] = $search; }
    if ($deleted)       { $params['deleted'] = '1'; }

    return 'tenants.php?' . http_build_query($params);
}

echo admin_head('Companies');
echo admin_nav($admin, 'tenants.php');
?>
<div class="wrap">
    <h1><?= $showDeleted ? 'Closed accounts' : 'Companies' ?></h1>
    <p class="lede">
        <?= count($tenants) ?> shown<?= $search !== '' ? ' matching “' . htmlspecialchars($search) . '”' : '' ?>.
    </p>

    <?php if (($_GET['purged'] ?? '') === '1'): ?>
        <div class="alert ok">The company and all of its data were permanently deleted.</div>
    <?php elseif (($_GET['purged'] ?? '') === 'partial'): ?>
        <div class="alert warn">
            <?= htmlspecialchars((string)($_SESSION['purge_warning'] ?? 'The purge did not fully complete.')) ?>
        </div>
        <?php unset($_SESSION['purge_warning']); ?>
    <?php endif; ?>

    <div class="card">
        <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
            <div style="flex:1;min-width:220px;">
                <label for="q">Search</label>
                <input type="text" name="q" id="q" value="<?= htmlspecialchars($search) ?>"
                       placeholder="Company name, address or email">
            </div>
            <div style="min-width:170px;">
                <label for="state">Subscription</label>
                <select name="state" id="state">
                    <option value="all"      <?= $filter === 'all' ? 'selected' : '' ?>>All</option>
                    <option value="trial"    <?= $filter === 'trial' ? 'selected' : '' ?>>On free trial</option>
                    <option value="active"   <?= $filter === 'active' ? 'selected' : '' ?>>Paid &amp; active</option>
                    <option value="expiring" <?= $filter === 'expiring' ? 'selected' : '' ?>>Ending within 7 days</option>
                    <option value="lapsed"   <?= $filter === 'lapsed' ? 'selected' : '' ?>>Locked out</option>
                </select>
            </div>
            <?php if ($showDeleted): ?><input type="hidden" name="deleted" value="1"><?php endif; ?>
            <button class="btn" type="submit">Apply</button>
            <?php if ($search !== '' || $filter !== 'all'): ?>
                <a class="btn ghost" href="<?= htmlspecialchars(filter_url('all', '', $showDeleted)) ?>">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="card">
        <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th>Company</th><th>Portal</th><th>Subscription</th>
                    <th>Ends</th><th>Contact</th><th>Joined</th><th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($tenants as $row):
                $t = $row['tenant'];
                $s = $row['state'];
            ?>
                <tr>
                    <td>
                        <span class="swatch" style="background:<?= htmlspecialchars((string)($t['brand_primary'] ?: '#7b583f')) ?>"></span>
                        <a href="tenant.php?id=<?= (int)$t['id'] ?>"><strong><?= htmlspecialchars((string)$t['company_name']) ?></strong></a>
                    </td>
                    <td>
                        <a href="<?= htmlspecialchars($base . '/' . $t['slug'] . '/login.php') ?>" target="_blank"
                           class="muted">/<?= htmlspecialchars((string)$t['slug']) ?></a>
                    </td>
                    <td><?= admin_status_pill($s) ?></td>
                    <td class="muted"><?= $s['ends_at'] ? htmlspecialchars($s['ends_at']->format('j M Y')) : '—' ?></td>
                    <td class="muted"><?= htmlspecialchars((string)$t['contact_email']) ?></td>
                    <td class="muted"><?= htmlspecialchars(date('j M Y', strtotime((string)$t['created_at']))) ?></td>
                    <td><a class="btn ghost" href="tenant.php?id=<?= (int)$t['id'] ?>">Manage</a></td>
                </tr>
            <?php endforeach; ?>

            <?php if ($tenants === []): ?>
                <tr><td colspan="7" class="muted">No companies match that.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

    <p class="muted">
        <?php if ($showDeleted): ?>
            <a href="tenants.php">&larr; Back to active companies</a>
        <?php else: ?>
            <a href="tenants.php?deleted=1">View closed accounts</a>
        <?php endif; ?>
    </p>
</div>
</body>
</html>
