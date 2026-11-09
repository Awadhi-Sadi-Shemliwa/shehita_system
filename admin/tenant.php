<?php
/**
 * SHEHITA EMS — One company: status, money, and the destructive buttons
 *
 * Everything SHEHITA does to a client account happens here: record a payment,
 * comp them some days, suspend them, close the account, or permanently purge it.
 *
 * Deletion is deliberately two-stage:
 *   "Close account"     — soft. status = Deleted, the portal shows a closed
 *                         notice, ALL data is retained, fully reversible.
 *   "Permanently purge" — drops the company's database and deletes their files.
 *                         Owner-only, and requires typing the slug to confirm,
 *                         because there is no undo and no backup taken here.
 */

require_once __DIR__ . '/_auth.php';

$admin   = require_platform_admin();
$control = control_db();

$tenantId = (int)($_GET['id'] ?? 0);
$tenant   = tenant_by_id($control, $tenantId);

if ($tenant === null) {
    render_notice_page(404, 'Company not found', 'No company with that id exists.');
}

$errors  = [];
$success = '';

// ---------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $action = (string)($_POST['action'] ?? '');

        switch ($action) {

            // ---- record a payment and extend --------------------------------
            case 'record_payment':
                $planCode  = trim((string)($_POST['plan_code'] ?? ''));
                $method    = trim((string)($_POST['method'] ?? 'Bank'));
                $reference = trim((string)($_POST['reference'] ?? ''));
                $amount    = (float)($_POST['amount'] ?? 0);
                $note      = trim((string)($_POST['note'] ?? ''));

                if ($reference === '') {
                    $errors[] = 'Enter the bank or mobile-money reference. It is what stops the '
                              . 'same payment being applied twice.';
                    break;
                }

                $result = subscription_activate(
                    $control, $tenantId, $planCode, $amount, $method, $reference,
                    $admin['email'], $note
                );

                if (!$result['ok']) {
                    $errors[] = $result['error'];
                } elseif ($result['duplicate']) {
                    $success = 'That reference was already confirmed — nothing was changed. '
                             . 'The subscription still runs to '
                             . ($result['period_end'] ? $result['period_end']->format('j M Y') : 'its existing end date') . '.';
                } else {
                    $success = 'Payment recorded. Subscription now runs to '
                             . $result['period_end']->format('j M Y') . ', and the company has been emailed their invoice.';
                }
                break;

            // ---- comp some days ---------------------------------------------
            case 'extend_days':
                $days   = (int)($_POST['days'] ?? 0);
                $reason = trim((string)($_POST['reason'] ?? ''));

                if ($days === 0) {
                    $errors[] = 'Enter how many days to add.';
                    break;
                }

                $newEnd = subscription_extend_days($control, $tenantId, $days, $admin['email'], $reason);
                if ($newEnd === null) {
                    // Must be an error, not a $success string: the page renders
                    // $success in a green "done" box, so a failure here read as
                    // though it had worked.
                    $errors[] = 'Could not change the dates. The company may no longer exist.';
                } else {
                    $success = 'Subscription now runs to ' . $newEnd->format('j M Y')
                             . '. No payment was recorded.';
                }
                break;

            // ---- suspend / reactivate ---------------------------------------
            case 'set_status':
                $status = (string)($_POST['status'] ?? '');
                if (tenant_set_status($control, $tenantId, $status, $admin['email'])) {
                    $success = match ($status) {
                        'Suspended' => 'Company suspended. Nobody there can sign in until you reactivate them.',
                        'Active'    => 'Company reactivated.',
                        'Deleted'   => 'Account closed. Their data is retained and this can be undone.',
                        default     => 'Status updated.',
                    };
                } else {
                    $errors[] = 'Could not update the status.';
                }
                break;

            // ---- resend the welcome email -----------------------------------
            case 'resend_welcome':
                $fresh = tenant_by_id($control, $tenantId);
                $base  = rtrim(getenv('APP_BASE_URL') ?: 'https://system.shehita.co.tz', '/');
                $sent  = notify_welcome($fresh, (string)$fresh['contact_email'],
                                        $base . '/' . $fresh['slug'] . '/login.php');
                $success = $sent
                    ? 'Welcome email resent to ' . $fresh['contact_email'] . '.'
                    : 'Could not send the email — check the mail settings and the server log.';
                break;

            // ---- permanently destroy ----------------------------------------
            case 'purge':
                require_platform_owner();

                $typed = trim((string)($_POST['confirm_slug'] ?? ''));
                if ($typed !== (string)$tenant['slug']) {
                    $errors[] = 'The address you typed did not match. Nothing was deleted.';
                    break;
                }

                $dbName = (string)$tenant['db_name'];
                $slug   = (string)$tenant['slug'];

                $dbDropped = drop_tenant_database($control, $dbName);
                $files     = delete_tenant_uploads($slug);

                platform_audit_log($control, $admin['email'], 'tenant.purge', $tenantId, [
                    'slug'             => $slug,
                    'db'               => $dbName,
                    'company'          => $tenant['company_name'],
                    'database_dropped' => $dbDropped,
                    'files_removed'    => $files['removed'],
                    'files_remaining'  => $files['remaining'],
                ]);

                $stmt = $control->prepare("DELETE FROM tenants WHERE id = ?");
                $stmt->bind_param("i", $tenantId);
                $stmt->execute();
                $stmt->close();

                // Never report a clean purge that was not clean. If any of the
                // company's uploaded files survived, say so and name the path,
                // because otherwise their logo quietly stays on the server after
                // we have told SHEHITA the company was permanently deleted.
                if (!$files['ok']) {
                    $_SESSION['purge_warning'] =
                        'The database was dropped and the company removed, but '
                        . count($files['remaining']) . ' uploaded file(s) could not be deleted. '
                        . 'Remove them on the server: uploads/tenants/' . $slug . '/ '
                        . '(this is usually a file-ownership problem — the web server could not '
                        . 'delete files written by another user).';
                    header('Location: tenants.php?purged=partial');
                    exit();
                }

                header('Location: tenants.php?purged=1');
                exit();
        }

        // Re-read after any change so the page renders current state.
        $tenant = tenant_by_id($control, $tenantId) ?? $tenant;
    }
}

/**
 * Read this company's staff list straight from their own database.
 *
 * SHEHITA can SEE who has access to each client system -- which is necessary to
 * answer "who is an administrator there?" -- but this page never writes to a
 * client database. Changing a client's users is their administrator's job.
 */
$tenantUsers   = [];
$tenantUserErr = '';
$superAdmins   = 0;
$rolelessUsers = 0;

try {
    $tconn = tenant_connection((string)$tenant['db_name']);
    $res = $tconn->query(
        "SELECT u.id, u.name, u.email, u.status, u.role_id, u.created_at,
                r.name AS role_name, d.name AS dept_name
           FROM users u
           LEFT JOIN roles r ON r.id = u.role_id
           LEFT JOIN departments d ON d.id = u.department_id
          ORDER BY (u.role_id = 1) DESC, u.name
          LIMIT 200"
    );
    $tenantUsers = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $tconn->close();

    foreach ($tenantUsers as $u) {
        if ((int)$u['role_id'] === 1)  { $superAdmins++; }
        if ($u['role_id'] === null)    { $rolelessUsers++; }
    }
} catch (Throwable $e) {
    // A company whose database is missing or unreachable should not take this
    // whole page down -- the billing controls above still need to work.
    $tenantUserErr = 'Could not read the user list for this company: ' . $e->getMessage();
}

$state    = subscription_resolve($tenant);
$plans    = active_plans($control);
$payments = tenant_payments($control, $tenantId, 40);
$base     = rtrim(getenv('APP_BASE_URL') ?: 'https://system.shehita.co.tz', '/');
$portal   = $base . '/' . $tenant['slug'] . '/login.php';

$palette = json_decode((string)($tenant['brand_ramp'] ?? ''), true);
$ramp    = is_array($palette) && !empty($palette['ramp']) ? $palette['ramp'] : [];

echo admin_head((string)$tenant['company_name']);
echo admin_nav($admin, 'tenants.php');
?>
<div class="wrap">

    <p class="muted" style="margin-bottom:10px;"><a href="tenants.php">&larr; All companies</a></p>

    <h1>
        <span class="swatch" style="background:<?= htmlspecialchars((string)($tenant['brand_primary'] ?: '#7b583f')) ?>"></span>
        <?= htmlspecialchars((string)$tenant['company_name']) ?>
    </h1>
    <p class="lede">
        <a href="<?= htmlspecialchars($portal) ?>" target="_blank"><?= htmlspecialchars($portal) ?></a>
    </p>

    <?php if ($errors !== []): ?>
        <div class="alert err"><ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <?php if ($success !== ''): ?>
        <div class="alert ok"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <!-- Overview -->
    <div class="card">
        <h2>Subscription</h2>
        <p style="margin:10px 0 14px;font-size:1.1rem;font-weight:600;color:var(--brown-900);">
            <?= admin_status_pill($state) ?>
            &nbsp;<?= htmlspecialchars(subscription_summary($state)) ?>
        </p>

        <div class="grid3">
            <div>
                <div class="muted">Contact</div>
                <div><?= htmlspecialchars((string)$tenant['contact_name']) ?></div>
                <div class="muted"><?= htmlspecialchars((string)$tenant['contact_email']) ?></div>
                <div class="muted"><?= htmlspecialchars((string)$tenant['contact_phone']) ?></div>
            </div>
            <div>
                <div class="muted">Trial</div>
                <div><?= (int)$tenant['trial_days'] ?> days from
                    <?= htmlspecialchars(date('j M Y', strtotime((string)$tenant['trial_started_at']))) ?></div>
                <div class="muted">Database: <?= htmlspecialchars((string)$tenant['db_name']) ?></div>
            </div>
            <div>
                <div class="muted">Brand</div>
                <div style="margin-top:5px;">
                    <?php foreach ($ramp as $step => $hex): ?>
                        <span class="swatch" title="<?= htmlspecialchars((string)$step . ' ' . $hex) ?>"
                              style="background:<?= htmlspecialchars((string)$hex) ?>;margin-right:2px;"></span>
                    <?php endforeach; ?>
                    <?php if ($ramp === []): ?><span class="muted">Default palette</span><?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Who has access at this company -->
    <div class="card">
        <h2>People at this company</h2>
        <p class="muted">
            Read-only. Their own administrator manages these accounts &mdash; SHEHITA does not
            change them from here.
        </p>

        <?php if ($tenantUserErr !== ''): ?>
            <div class="alert err" style="margin-top:14px;"><?= htmlspecialchars($tenantUserErr) ?></div>
        <?php else: ?>

            <div class="stats" style="margin-top:16px;">
                <div class="stat">
                    <div class="n"><?= count($tenantUsers) ?></div>
                    <div class="l">User accounts</div>
                </div>
                <div class="stat">
                    <div class="n"><?= $superAdmins ?></div>
                    <div class="l">Super Admins</div>
                </div>
                <?php if ($rolelessUsers > 0): ?>
                <div class="stat">
                    <div class="n" style="color:#92400e;"><?= $rolelessUsers ?></div>
                    <div class="l">With no role</div>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($superAdmins > 2): ?>
                <div class="alert warn">
                    <strong><?= $superAdmins ?> accounts here are Super Admin.</strong>
                    That is more than most companies need. Until recently a bug could promote
                    staff to Super Admin automatically when a role was deleted, so it is worth
                    asking this company to check the list below and demote anyone who should
                    not have full access.
                </div>
            <?php endif; ?>

            <?php if ($rolelessUsers > 0): ?>
                <div class="alert warn">
                    <?= $rolelessUsers ?> account<?= $rolelessUsers === 1 ? '' : 's' ?> here
                    <?= $rolelessUsers === 1 ? 'has' : 'have' ?> no role, so
                    <?= $rolelessUsers === 1 ? 'it' : 'they' ?> can sign in but cannot open
                    anything. Their administrator needs to assign a role.
                </div>
            <?php endif; ?>

            <div style="overflow-x:auto;">
            <table>
                <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Department</th><th>Status</th><th>Added</th></tr></thead>
                <tbody>
                <?php foreach ($tenantUsers as $u): ?>
                    <tr>
                        <td><?= htmlspecialchars((string)$u['name']) ?></td>
                        <td class="muted"><?= htmlspecialchars((string)$u['email']) ?></td>
                        <td>
                            <?php if ((int)$u['role_id'] === 1): ?>
                                <span class="pill bad">Super Admin</span>
                            <?php elseif ($u['role_id'] === null): ?>
                                <span class="pill warn">No role</span>
                            <?php else: ?>
                                <?= htmlspecialchars((string)($u['role_name'] ?? 'Unknown')) ?>
                            <?php endif; ?>
                        </td>
                        <td class="muted"><?= htmlspecialchars((string)($u['dept_name'] ?? '—')) ?></td>
                        <td>
                            <span class="pill <?= $u['status'] === 'Active' ? 'ok' : 'mute' ?>">
                                <?= htmlspecialchars((string)$u['status']) ?>
                            </span>
                        </td>
                        <td class="muted"><?= htmlspecialchars(date('j M Y', strtotime((string)$u['created_at']))) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($tenantUsers === []): ?>
                    <tr><td colspan="6" class="muted">No user accounts found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Record a payment -->
    <div class="card" id="payments">
        <h2>Record a payment</h2>
        <p class="muted">
            Use this once you have seen the money. The new period is added on top of any time
            they have left, so paying early never costs them days. The reference must be unique —
            that is what makes it impossible to apply the same payment twice.
        </p>

        <form method="POST" style="margin-top:16px;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="record_payment">

            <div class="grid3">
                <div class="field">
                    <label for="plan_code">Package</label>
                    <select name="plan_code" id="plan_code" required>
                        <?php foreach ($plans as $plan): ?>
                            <option value="<?= htmlspecialchars((string)$plan['code']) ?>"
                                    data-price="<?= htmlspecialchars((string)(float)$plan['price']) ?>">
                                <?= htmlspecialchars((string)$plan['name']) ?>
                                (<?= (int)$plan['days'] ?>d — <?= htmlspecialchars(admin_money((float)$plan['price'], (string)$plan['currency'])) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="amount">Amount received</label>
                    <input type="number" step="0.01" min="0" name="amount" id="amount" required>
                </div>
                <div class="field">
                    <label for="method">Method</label>
                    <select name="method" id="method">
                        <option value="Bank">Bank transfer</option>
                        <option value="MobileMoney">Mobile money</option>
                        <option value="Cash">Cash</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="field">
                    <label for="reference">Reference</label>
                    <input type="text" name="reference" id="reference" required maxlength="120">
                </div>
                <div class="field" style="grid-column:span 2;">
                    <label for="note">Note (optional)</label>
                    <input type="text" name="note" id="note" maxlength="255">
                </div>
            </div>

            <button class="btn" type="submit">Record payment &amp; extend</button>
        </form>
    </div>

    <!-- Payment history -->
    <div class="card">
        <h2>Payment history</h2>
        <div style="overflow-x:auto;">
        <table>
            <thead><tr><th>Date</th><th>Package</th><th>Amount</th><th>Method</th><th>Reference</th><th>Status</th><th>Covers</th><th>Invoice</th></tr></thead>
            <tbody>
            <?php foreach ($payments as $p): ?>
                <tr>
                    <td class="muted"><?= htmlspecialchars(date('j M Y', strtotime((string)$p['created_at']))) ?></td>
                    <td><?= htmlspecialchars((string)$p['plan_code']) ?></td>
                    <td><?= htmlspecialchars(admin_money((float)$p['amount'], (string)$p['currency'])) ?></td>
                    <td class="muted"><?= htmlspecialchars((string)$p['method']) ?></td>
                    <td class="muted"><?= htmlspecialchars((string)$p['reference']) ?></td>
                    <td>
                        <span class="pill <?= $p['status'] === 'Paid' ? 'ok' : ($p['status'] === 'Pending' ? 'warn' : 'bad') ?>">
                            <?= htmlspecialchars((string)$p['status']) ?>
                        </span>
                    </td>
                    <td class="muted">
                        <?php if (!empty($p['period_end'])): ?>
                            to <?= htmlspecialchars(date('j M Y', strtotime((string)$p['period_end']))) ?>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($p['invoice_id'])): ?>
                            <a href="subscription-invoice.php?id=<?= (int)$p['invoice_id'] ?>" target="_blank">
                                <?= htmlspecialchars((string)$p['invoice_number']) ?></a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($payments === []): ?>
                <tr><td colspan="8" class="muted">No payments recorded yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

    <!-- Other actions -->
    <div class="card">
        <h2>Other actions</h2>

        <div class="grid2" style="margin-top:14px;">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="action" value="extend_days">
                <label for="days">Add free days (no payment recorded)</label>
                <div style="display:flex;gap:9px;">
                    <input type="number" name="days" id="days" placeholder="e.g. 14" style="max-width:120px;">
                    <input type="text" name="reason" placeholder="Reason" maxlength="255">
                    <button class="btn ghost" type="submit">Add</button>
                </div>
            </form>

            <div>
                <label>Access</label>
                <div style="display:flex;gap:9px;flex-wrap:wrap;">
                    <?php if ($tenant['status'] === 'Active'): ?>
                        <form method="POST" onsubmit="return confirm('Suspend this company? Nobody there will be able to sign in.');">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                            <input type="hidden" name="action" value="set_status">
                            <input type="hidden" name="status" value="Suspended">
                            <button class="btn ghost" type="submit">Suspend</button>
                        </form>
                    <?php else: ?>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                            <input type="hidden" name="action" value="set_status">
                            <input type="hidden" name="status" value="Active">
                            <button class="btn ghost" type="submit">Reactivate</button>
                        </form>
                    <?php endif; ?>

                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                        <input type="hidden" name="action" value="resend_welcome">
                        <button class="btn ghost" type="submit">Resend welcome email</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Deletion -->
    <div class="card" style="border:1.5px solid #fecaca;">
        <h2 style="color:#991b1b;">Closing this account</h2>

        <?php if ($tenant['status'] !== 'Deleted'): ?>
            <p class="muted">
                Closing is reversible: their portal shows a closed notice and nobody can sign in,
                but every record is kept exactly as it is.
            </p>
            <form method="POST" style="margin-top:12px;"
                  onsubmit="return confirm('Close this account? Their data is kept and you can undo this.');">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="action" value="set_status">
                <input type="hidden" name="status" value="Deleted">
                <button class="btn ghost" type="submit">Close account</button>
            </form>
        <?php else: ?>
            <div class="alert warn" style="margin-top:12px;">
                This account is closed. Their data is still intact and they can be restored.
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="action" value="set_status">
                <input type="hidden" name="status" value="Active">
                <button class="btn ghost" type="submit">Restore this account</button>
            </form>
        <?php endif; ?>

        <hr style="margin:22px 0;border:none;border-top:1px solid #fecaca;">

        <h2 style="color:#991b1b;">Permanently purge</h2>
        <p class="muted">
            This drops <strong><?= htmlspecialchars((string)$tenant['db_name']) ?></strong> and deletes
            their uploaded files. Every customer, project and invoice they have ever entered is gone.
            <strong>There is no undo and no backup is taken here.</strong>
        </p>

        <?php if ($admin['role'] !== 'Owner'): ?>
            <p class="muted" style="margin-top:12px;"><em>Only a SHEHITA account owner can do this.</em></p>
        <?php else: ?>
            <form method="POST" style="margin-top:14px;"
                  onsubmit="return confirm('Last chance. This permanently destroys all of this company\'s data. Continue?');">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="action" value="purge">
                <label for="confirm_slug">Type <code><?= htmlspecialchars((string)$tenant['slug']) ?></code> to confirm</label>
                <div style="display:flex;gap:9px;max-width:430px;">
                    <input type="text" name="confirm_slug" id="confirm_slug" autocomplete="off" required>
                    <button class="btn danger" type="submit">Purge</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<script>
// Prefill the amount from the chosen package.
document.getElementById('plan_code').addEventListener('change', function () {
    var price = this.options[this.selectedIndex].getAttribute('data-price');
    var amount = document.getElementById('amount');
    if (price && !amount.value) { amount.value = price; }
});
</script>
</body>
</html>
