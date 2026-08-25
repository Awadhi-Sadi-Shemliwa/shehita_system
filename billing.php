<?php
/**
 * SHEHITA EMS — Subscription & billing (company admin view)
 *
 * Reachable WITHOUT an active subscription — that is the point. When a trial or
 * paid period lapses, homepage.php locks staff out but sends the company's own
 * admin here, so they can always see what they owe and tell us they have paid.
 *
 * The payment loop this page starts:
 *   1. Admin pays SHEHITA offline (bank transfer / Lipa namba).
 *   2. Admin presses "I have paid" and enters the reference.
 *   3. SHEHITA's billing admins get an email with a ONE-CLICK confirmation
 *      link they can open straight from their inbox, on a phone.
 *   4. Confirming extends the subscription and emails this company an invoice.
 *
 * Nothing here can grant access by itself: this page only ever creates a
 * *Pending* record. Only SHEHITA confirming it moves any dates.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/mailer.php';
require_once __DIR__ . '/lib/email_templates.php';

if (!isset($_SESSION['email'])) {
    header('Location: login.php');
    exit();
}

// Billing is the company administrator's business. Staff get expired.php.
if ((int)($_SESSION['role_id'] ?? 0) !== 1) {
    header('Location: ' . ($SUBSCRIPTION['is_active'] ? 'homepage.php?page=home' : 'expired.php'));
    exit();
}

$errors  = [];
$success = '';

$plans        = active_plans($control);
$payments     = tenant_payments($control, TENANT_ID, 20);
$payInstructions = platform_setting(
    $control,
    'payment_instructions',
    "Pay by bank transfer or mobile money to SHEHITA, then use the form on this page to tell us.\n"
    . "Contact SHEHITA if you need our current account details."
);

// ---------------------------------------------------------------------------
// "I have paid"
// ---------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['claim_payment'])) {
    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $planCode  = trim((string)($_POST['plan_code'] ?? ''));
        $method    = trim((string)($_POST['method'] ?? ''));
        $reference = trim((string)($_POST['reference'] ?? ''));
        $amount    = (float)($_POST['amount'] ?? 0);

        $plan = plan_by_code($control, $planCode);

        if ($plan === null) {
            $errors[] = 'Please choose a subscription package.';
        }
        if (!in_array($method, ['Bank', 'MobileMoney', 'Cash', 'Other'], true)) {
            $errors[] = 'Please choose how you paid.';
        }
        if ($reference === '' || mb_strlen($reference) > 120) {
            $errors[] = 'Enter the transaction reference from your bank or mobile money receipt.';
        }
        if ($amount <= 0) {
            $errors[] = 'Enter the amount you paid.';
        }

        // Has this exact reference already been submitted?
        if ($errors === []) {
            $existing = payment_by_reference($control, TENANT_ID, $method, $reference);
            if ($existing !== null) {
                if ($existing['status'] === 'Paid') {
                    $errors[] = 'That reference has already been confirmed. If you have made a '
                              . 'second payment, please use its own reference number.';
                } else {
                    $errors[] = 'We already have that reference on file and are checking it. '
                              . 'There is no need to send it again.';
                }
            }
        }

        // Light anti-spam: one claim every five minutes is plenty for a human.
        if ($errors === []) {
            $stmt = $control->prepare(
                "SELECT COUNT(*) AS c FROM subscription_payments
                  WHERE tenant_id = ? AND created_at > (NOW() - INTERVAL 5 MINUTE)"
            );
            $tid = TENANT_ID;
            $stmt->bind_param("i", $tid);
            $stmt->execute();
            $recent = (int)$stmt->get_result()->fetch_assoc()['c'];
            $stmt->close();

            if ($recent > 0) {
                $errors[] = 'You sent us a payment notice a moment ago. Please give us a few '
                          . 'minutes to look at it before sending another.';
            }
        }

        if ($errors === []) {
            // Only the SHA-256 of the token is stored. The raw token exists in
            // exactly one place -- the email we are about to send -- so a leak of
            // the database does not hand anyone the ability to confirm payments.
            $rawToken  = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);
            $expires   = (new DateTimeImmutable('now'))->modify('+7 days')->format('Y-m-d H:i:s');
            $actor     = (string)$_SESSION['email'];
            $tid       = TENANT_ID;
            $currency  = (string)$plan['currency'];

            $stmt = $control->prepare(
                "INSERT INTO subscription_payments
                    (tenant_id, plan_code, amount, currency, method, reference, status,
                     confirm_token_hash, confirm_token_expires_at, confirm_requested_at, recorded_by)
                 VALUES (?, ?, ?, ?, ?, ?, 'Pending', ?, ?, NOW(), ?)"
            );
            $stmt->bind_param(
                "isdssssss",
                $tid, $planCode, $amount, $currency, $method, $reference,
                $tokenHash, $expires, $actor
            );

            if ($stmt->execute()) {
                $paymentId = (int)$stmt->insert_id;
                $stmt->close();

                $payment = [
                    'id'        => $paymentId,
                    'plan_code' => $planCode,
                    'amount'    => $amount,
                    'currency'  => $currency,
                    'method'    => $method,
                    'reference' => $reference,
                ];

                $confirmUrl = platform_url('admin/confirm-payment.php?token=' . urlencode($rawToken));

                notify_payment_claimed($control, $TENANT, $payment, $confirmUrl);
                notify_payment_claim_ack($TENANT, $payment);

                platform_audit_log($control, $actor, 'payment.claimed', TENANT_ID, [
                    'reference' => $reference, 'amount' => $amount, 'method' => $method,
                ]);

                $success = 'Thank you. We have passed your payment details to SHEHITA to verify. '
                         . 'You will get an email with your invoice as soon as it is confirmed.';

                $payments = tenant_payments($control, TENANT_ID, 20);
            } else {
                $stmt->close();
                $errors[] = 'We could not record that just now. Please try again in a moment.';
            }
        }
    }
}

$logo_path    = tenant_logo_url();
$company_name = tenant_display_name();
$product_name = tenant_product_name();
$summary      = subscription_summary($SUBSCRIPTION);
$isActive     = $SUBSCRIPTION['is_active'];
$daysLeft     = $SUBSCRIPTION['days_left'];

/** Status pill colour by state. */
$stateTone = match ($SUBSCRIPTION['status']) {
    'active'                   => $daysLeft <= 7 ? 'warn' : 'ok',
    'trial'                    => $daysLeft <= 3 ? 'warn' : 'ok',
    'expired', 'trial_expired' => 'bad',
    default                    => 'bad',
};

function money(float $amount, string $currency): string
{
    return number_format($amount, 0) . ' ' . $currency;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($product_name) ?> — Subscription</title>
    <?php if ($logo_path !== ''): ?>
    <link rel="icon" href="<?= htmlspecialchars($logo_path) ?>">
    <?php endif; ?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --brown-900: #3e2b1f; --brown-800: #5c3e2d; --brown-700: #7b583f;
            --brown-600: #9b7a5a; --brown-500: #b89b7e; --brown-400: #d4bfa8;
            --brown-300: #e8d9cc; --brown-200: #f0e8df; --brown-100: #f7f2ec;
            --gray-500: #64748b; --gray-700: #334155; --gray-800: #1e293b;
        }

        body {
            font-family: 'Inter', system-ui, sans-serif; background: #f1f5f9;
            color: var(--gray-800); min-height: 100vh; padding: 28px 16px 60px;
        }

        .wrap { max-width: 940px; margin: 0 auto; }

        header.bar {
            display: flex; align-items: center; gap: 14px; margin-bottom: 24px; flex-wrap: wrap;
        }
        header.bar img { max-height: 46px; max-width: 150px; object-fit: contain; }
        header.bar .name { font-weight: 700; font-size: 1.12rem; color: var(--brown-900); }
        header.bar .spacer { flex: 1; }
        header.bar a {
            color: var(--brown-700); text-decoration: none; font-weight: 600; font-size: .9rem;
        }
        header.bar a:hover { text-decoration: underline; }

        .card {
            background: #fff; border-radius: 14px; padding: 26px 28px; margin-bottom: 20px;
            box-shadow: 0 1px 3px rgb(0 0 0 / .08);
        }

        h2 { font-size: 1.05rem; color: var(--brown-900); margin-bottom: 6px; }
        .muted { color: var(--gray-500); font-size: .9rem; line-height: 1.6; }

        .status-head { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
        .pill {
            padding: 5px 13px; border-radius: 999px; font-size: .76rem; font-weight: 700;
            letter-spacing: .05em; text-transform: uppercase;
        }
        .pill.ok   { background: #dcfce7; color: #166534; }
        .pill.warn { background: #fef3c7; color: #92400e; }
        .pill.bad  { background: #fee2e2; color: #991b1b; }

        .status-line { font-size: 1.16rem; font-weight: 600; margin: 12px 0 4px; color: var(--brown-900); }

        .alert { padding: 13px 17px; border-radius: 10px; margin-bottom: 18px; font-size: .93rem; line-height: 1.6; }
        .alert.err { background: #fee2e2; color: #991b1b; border-left: 3px solid #dc2626; }
        .alert.ok  { background: #dcfce7; color: #166534; border-left: 3px solid #16a34a; }
        .alert ul { margin: 6px 0 0 18px; }

        .plans { display: grid; gap: 14px; grid-template-columns: repeat(auto-fit, minmax(min(190px, 100%), 1fr)); margin-top: 16px; }
        .plan {
            border: 1.5px solid var(--brown-200); border-radius: 12px; padding: 17px 18px;
            background: var(--brown-100);
        }
        .plan .pname { font-weight: 700; color: var(--brown-900); font-size: .95rem; }
        .plan .pprice { font-size: 1.28rem; font-weight: 700; color: var(--brown-800); margin: 7px 0 2px; }
        .plan .pdays { font-size: .82rem; color: var(--gray-500); }

        .instructions {
            white-space: pre-line; background: var(--brown-100); border-radius: 10px;
            padding: 15px 18px; font-size: .92rem; line-height: 1.65; color: var(--brown-900);
            border-left: 3px solid var(--brown-600); margin-top: 12px;
        }

        .grid2 { display: grid; gap: 14px; grid-template-columns: 1fr 1fr; }
        @media (max-width: 620px) { .grid2 { grid-template-columns: 1fr; } }

        label { display: block; font-size: .84rem; font-weight: 600; color: var(--gray-700); margin-bottom: 5px; }
        input, select {
            width: 100%; padding: 10px 13px; border: 1.5px solid var(--brown-200); border-radius: 9px;
            font-family: inherit; font-size: .93rem; background: #fff; color: var(--gray-800);
        }
        input:focus, select:focus { outline: none; border-color: var(--brown-600); }
        .field { margin-bottom: 14px; }

        .btn {
            padding: 12px 24px; border: none; border-radius: 9px; background: var(--brown-700);
            color: #fff; font-weight: 600; font-size: .95rem; cursor: pointer; font-family: inherit;
        }
        .btn:hover { background: var(--brown-800); }

        table { width: 100%; border-collapse: collapse; margin-top: 12px; font-size: .89rem; }
        th, td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--brown-200); }
        th { color: var(--gray-500); font-weight: 600; font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; }
        td a { color: var(--brown-700); font-weight: 600; }

        .tag { padding: 3px 9px; border-radius: 999px; font-size: .74rem; font-weight: 700; }
        .tag.Paid { background: #dcfce7; color: #166534; }
        .tag.Pending { background: #fef3c7; color: #92400e; }
        .tag.Rejected { background: #fee2e2; color: #991b1b; }
    </style>
    <?php echo tenant_theme_css(); ?>
</head>
<body>
<div class="wrap">

    <header class="bar">
        <?php if ($logo_path !== ''): ?>
            <img src="<?= htmlspecialchars($logo_path) ?>" alt="<?= htmlspecialchars($company_name) ?>">
        <?php endif; ?>
        <span class="name"><?= htmlspecialchars($product_name) ?></span>
        <span class="spacer"></span>
        <?php if ($isActive): ?>
            <a href="homepage.php?page=home">&larr; Back to the system</a>
        <?php else: ?>
            <a href="logout.php">Sign out</a>
        <?php endif; ?>
    </header>

    <?php if ($errors !== []): ?>
        <div class="alert err">
            <strong>Please check the following:</strong>
            <ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
        <div class="alert ok"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <!-- Where this company stands -->
    <div class="card">
        <div class="status-head">
            <h2>Subscription</h2>
            <span class="pill <?= $stateTone ?>"><?= htmlspecialchars($SUBSCRIPTION['label']) ?></span>
        </div>
        <div class="status-line"><?= htmlspecialchars($summary) ?></div>
        <?php if (!$isActive): ?>
            <p class="muted">
                Your team cannot sign in until a payment is confirmed. Nothing has been deleted —
                every record returns exactly as it was.
            </p>
        <?php elseif ($daysLeft <= 7): ?>
            <p class="muted">
                Paying before the end date keeps access unbroken. Any days you still have are added
                on top of what you buy, so paying early never costs you time.
            </p>
        <?php endif; ?>
    </div>

    <!-- Packages -->
    <div class="card">
        <h2>Packages</h2>
        <p class="muted">Choose the period you want to pay for.</p>
        <div class="plans">
            <?php foreach ($plans as $plan): ?>
                <div class="plan">
                    <div class="pname"><?= htmlspecialchars($plan['name']) ?></div>
                    <div class="pprice"><?= htmlspecialchars(money((float)$plan['price'], (string)$plan['currency'])) ?></div>
                    <div class="pdays"><?= (int)$plan['days'] ?> days</div>
                </div>
            <?php endforeach; ?>
        </div>

        <h2 style="margin-top:24px;">How to pay</h2>
        <div class="instructions"><?= htmlspecialchars($payInstructions) ?></div>
    </div>

    <!-- Tell us you've paid -->
    <div class="card">
        <h2>I have paid</h2>
        <p class="muted">
            Once your payment has gone through, fill this in. It goes straight to SHEHITA for
            checking — your subscription is extended as soon as we confirm it, and we email you
            the invoice.
        </p>

        <form method="POST" style="margin-top:18px;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

            <div class="grid2">
                <div class="field">
                    <label for="plan_code">Package paid for</label>
                    <select name="plan_code" id="plan_code" required>
                        <option value="">Choose…</option>
                        <?php foreach ($plans as $plan): ?>
                            <option value="<?= htmlspecialchars($plan['code']) ?>"
                                    data-price="<?= htmlspecialchars((string)(float)$plan['price']) ?>">
                                <?= htmlspecialchars($plan['name']) ?>
                                — <?= htmlspecialchars(money((float)$plan['price'], (string)$plan['currency'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="amount">Amount paid</label>
                    <input type="number" step="0.01" min="0" name="amount" id="amount" required
                           placeholder="e.g. 50000">
                </div>

                <div class="field">
                    <label for="method">How did you pay?</label>
                    <select name="method" id="method" required>
                        <option value="Bank">Bank transfer</option>
                        <option value="MobileMoney">Mobile money (Lipa namba)</option>
                        <option value="Cash">Cash</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <div class="field">
                    <label for="reference">Transaction reference</label>
                    <input type="text" name="reference" id="reference" required maxlength="120"
                           placeholder="From your receipt or SMS">
                </div>
            </div>

            <button class="btn" type="submit" name="claim_payment" value="1">Send payment details</button>
        </form>
    </div>

    <!-- History -->
    <div class="card">
        <h2>Payment history</h2>
        <?php if ($payments === []): ?>
            <p class="muted" style="margin-top:8px;">No payments recorded yet.</p>
        <?php else: ?>
            <div style="overflow-x:auto;">
            <table>
                <thead>
                    <tr>
                        <th>Date</th><th>Package</th><th>Amount</th>
                        <th>Reference</th><th>Status</th><th>Invoice</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($payments as $p): ?>
                    <tr>
                        <td><?= htmlspecialchars(date('j M Y', strtotime((string)$p['created_at']))) ?></td>
                        <td><?= htmlspecialchars((string)$p['plan_code']) ?></td>
                        <td><?= htmlspecialchars(money((float)$p['amount'], (string)$p['currency'])) ?></td>
                        <td><?= htmlspecialchars((string)$p['reference']) ?></td>
                        <td><span class="tag <?= htmlspecialchars((string)$p['status']) ?>"><?= htmlspecialchars((string)$p['status']) ?></span></td>
                        <td>
                            <?php if (!empty($p['invoice_id'])): ?>
                                <a href="invoice-view.php?id=<?= (int)$p['invoice_id'] ?>" target="_blank">
                                    <?= htmlspecialchars((string)$p['invoice_number']) ?>
                                </a>
                            <?php else: ?>
                                &mdash;
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </div>

</div>

<script>
// Prefill the amount from the chosen package -- most people pay the list price,
// and a mismatch between amount and package is a common source of confusion
// when SHEHITA later checks the payment against the bank statement.
document.getElementById('plan_code').addEventListener('change', function () {
    var price = this.options[this.selectedIndex].getAttribute('data-price');
    var amount = document.getElementById('amount');
    if (price && !amount.value) { amount.value = price; }
});
</script>
</body>
</html>
