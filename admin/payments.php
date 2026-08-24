<?php
/**
 * SHEHITA EMS - All subscription payments
 *
 * The money ledger across every client company, newest first, with the pending
 * ones surfaced at the top because those are the ones needing a decision.
 */

require_once __DIR__ . '/_auth.php';

$admin   = require_platform_admin();
$control = control_db();

$filter = (string)($_GET['status'] ?? 'all');
$valid  = ['all', 'Pending', 'Paid', 'Rejected'];
if (!in_array($filter, $valid, true)) {
    $filter = 'all';
}

$sql = "SELECT p.*, t.company_name, t.slug, i.id AS invoice_id, i.invoice_number
          FROM subscription_payments p
          JOIN tenants t ON t.id = p.tenant_id
          LEFT JOIN subscription_invoices i ON i.payment_id = p.id";

if ($filter !== 'all') {
    $sql .= " WHERE p.status = ?";
    $stmt = $control->prepare($sql . " ORDER BY p.created_at DESC LIMIT 300");
    $stmt->bind_param("s", $filter);
} else {
    $stmt = $control->prepare($sql . " ORDER BY (p.status = 'Pending') DESC, p.created_at DESC LIMIT 300");
}

$stmt->execute();
$payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$totals = $control->query(
    "SELECT COALESCE(SUM(amount),0) AS paid_total, COUNT(*) AS paid_count
       FROM subscription_payments WHERE status = 'Paid'"
)->fetch_assoc();

echo admin_head('Payments');
echo admin_nav($admin, 'payments.php');
?>
<div class="wrap">
    <h1>Payments</h1>
    <p class="lede">
        <?= (int)$totals['paid_count'] ?> confirmed payments,
        <?= htmlspecialchars(admin_money((float)$totals['paid_total'])) ?> in total.
    </p>

    <div class="card">
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <?php foreach (['all' => 'All', 'Pending' => 'Awaiting confirmation', 'Paid' => 'Confirmed', 'Rejected' => 'Rejected'] as $key => $label): ?>
                <a class="btn <?= $filter === $key ? '' : 'ghost' ?>"
                   href="payments.php?status=<?= urlencode($key) ?>"><?= htmlspecialchars($label) ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card">
        <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr><th>Date</th><th>Company</th><th>Package</th><th>Amount</th>
                    <th>Method</th><th>Reference</th><th>Status</th><th>Invoice</th></tr>
            </thead>
            <tbody>
            <?php foreach ($payments as $p): ?>
                <tr>
                    <td class="muted"><?= htmlspecialchars(date('j M Y', strtotime((string)$p['created_at']))) ?></td>
                    <td><a href="tenant.php?id=<?= (int)$p['tenant_id'] ?>"><?= htmlspecialchars((string)$p['company_name']) ?></a></td>
                    <td><?= htmlspecialchars((string)$p['plan_code']) ?></td>
                    <td><?= htmlspecialchars(admin_money((float)$p['amount'], (string)$p['currency'])) ?></td>
                    <td class="muted"><?= htmlspecialchars((string)$p['method']) ?></td>
                    <td class="muted"><?= htmlspecialchars((string)$p['reference']) ?></td>
                    <td><span class="pill <?= $p['status'] === 'Paid' ? 'ok' : ($p['status'] === 'Pending' ? 'warn' : 'bad') ?>">
                        <?= htmlspecialchars((string)$p['status']) ?></span></td>
                    <td>
                        <?php if (!empty($p['invoice_id'])): ?>
                            <a href="subscription-invoice.php?id=<?= (int)$p['invoice_id'] ?>" target="_blank">
                                <?= htmlspecialchars((string)$p['invoice_number']) ?></a>
                        <?php else: ?>&mdash;<?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($payments === []): ?>
                <tr><td colspan="8" class="muted">No payments to show.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
</body>
</html>
