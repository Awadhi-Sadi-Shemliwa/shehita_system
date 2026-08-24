<?php
/**
 * SHEHITA EMS — One-click payment confirmation
 *
 * The link that goes to SHEHITA's billing admins when a customer says they have
 * paid. It is PUBLIC and unauthenticated on purpose: the whole value is that
 * whoever checks the bank account can confirm straight from their inbox, on a
 * phone, without finding a password first.
 *
 * A GET only SHOWS the payment and a confirm button; the activation happens on
 * the POST that button submits. That is not decoration: mail security products
 * (Microsoft Defender Safe Links, corporate AV, chat unfurlers) fetch every URL
 * in a message before a human sees it. With the state change on GET, the first
 * scanner to touch the mail confirmed the payment automatically -- before anyone
 * had looked at the bank account.
 *
 * What makes that safe:
 *   - The token is 32 random bytes, and only its SHA-256 is stored. Whoever
 *     steals the database still cannot confirm a payment.
 *   - It expires after 7 days.
 *   - It is single-use: activation clears the hash. A second click is
 *     idempotent (subscription_activate() dedupes on method+reference) and
 *     shows the success page again rather than extending twice.
 *   - EVERY failure -- unknown token, expired, already used, malformed --
 *     renders the IDENTICAL page. The endpoint never reveals whether a token
 *     was real, so it cannot be probed.
 */

require_once __DIR__ . '/_auth.php';

$control = control_db();

// GET carries it in the URL (the emailed link); POST carries it in the form the
// GET rendered.
$token = (string)($_SERVER['REQUEST_METHOD'] === 'POST'
    ? ($_POST['token'] ?? '')
    : ($_GET['token'] ?? ''));

/**
 * Show what is about to be confirmed, and a button that POSTs.
 *
 * The token travels in a hidden field rather than the URL of the form action, so
 * the page can be opened, read, and abandoned with nothing happening.
 */
function render_confirm_prompt(mysqli $control, string $token, array $payment, array $tenant): never
{
    $plan     = plan_by_code($control, (string)$payment['plan_code']);
    $company  = htmlspecialchars((string)$tenant['company_name']);
    $amount   = htmlspecialchars(number_format((float)$payment['amount'], 2) . ' ' . $payment['currency']);
    $method   = htmlspecialchars((string)$payment['method']);
    $ref      = htmlspecialchars((string)$payment['reference']);
    $planName = htmlspecialchars((string)($plan['name'] ?? $payment['plan_code']));
    $days     = (int)($plan['days'] ?? 0);
    $claimed  = htmlspecialchars(date('j M Y, H:i', strtotime((string)$payment['created_at'])));
    $safeTok  = htmlspecialchars($token);

    http_response_code(200);
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Confirm payment — SHEHITA</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',system-ui,sans-serif;min-height:100vh;display:flex;align-items:center;
  justify-content:center;padding:24px;background:linear-gradient(135deg,#3e2b1f 0%,#7b583f 50%,#b89b7e 100%)}
.card{background:#fff;border-radius:16px;padding:34px;max-width:470px;width:100%;
  box-shadow:0 20px 25px -5px rgb(0 0 0 / .25)}
h1{font-size:1.2rem;color:#3e2b1f;margin-bottom:8px}
p{color:#475569;line-height:1.6;font-size:.93rem}
table{margin:18px 0;width:100%;font-size:.92rem}
td{padding:6px 0}
td.l{color:#64748b;padding-right:16px;white-space:nowrap}
td.v{font-weight:600;color:#1e293b}
.warn{background:#fef3c7;color:#92400e;padding:12px 15px;border-radius:9px;
  font-size:.88rem;line-height:1.55;margin-bottom:18px;border-left:3px solid #d97706}
.btn{width:100%;padding:13px;border:none;border-radius:9px;background:#16a34a;color:#fff;
  font-weight:600;font-size:.97rem;cursor:pointer;font-family:inherit}
.btn:hover{background:#15803d}
.note{margin-top:14px;font-size:.82rem;color:#94a3b8;line-height:1.5;text-align:center}
</style>
</head>
<body>
<div class="card">
  <h1>Confirm this payment?</h1>
  <p>Nothing has been changed yet.</p>

  <table>
    <tr><td class="l">Company</td><td class="v">{$company}</td></tr>
    <tr><td class="l">Package</td><td class="v">{$planName} ({$days} days)</td></tr>
    <tr><td class="l">Amount</td><td class="v">{$amount}</td></tr>
    <tr><td class="l">Method</td><td class="v">{$method}</td></tr>
    <tr><td class="l">Reference</td><td class="v">{$ref}</td></tr>
    <tr><td class="l">They told us</td><td class="v">{$claimed}</td></tr>
  </table>

  <div class="warn">
    Only confirm once you have actually seen this money in the bank or mobile-money
    account. Confirming extends their subscription immediately and issues an invoice.
  </div>

  <form method="POST">
    <input type="hidden" name="token" value="{$safeTok}">
    <button class="btn" type="submit">Yes, I have seen the money — confirm</button>
  </form>

  <div class="note">If this is not right, just close this page. Nothing will happen.</div>
</div>
</body>
</html>
HTML;
    exit();
}

/** The single response for every failure mode. Deliberately uninformative. */
function confirm_failure(): void
{
    render_notice_page(
        400,
        'This link is no longer valid',
        'It may have already been used, or it may have expired. Open the SHEHITA admin console '
        . 'and record the payment from the company\'s page instead.'
    );
}

if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    confirm_failure();
}

$hash = hash('sha256', $token);

$stmt = $control->prepare(
    "SELECT * FROM subscription_payments
      WHERE confirm_token_hash = ?
        AND confirm_token_expires_at > NOW()
      LIMIT 1"
);
$stmt->bind_param("s", $hash);
$stmt->execute();
$payment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($payment === null) {
    confirm_failure();
}

$tenant = tenant_by_id($control, (int)$payment['tenant_id']);
if ($tenant === null) {
    confirm_failure();
}

// Whoever opened the link is acting on SHEHITA's behalf. Record who, if we know.
$actor = (string)($_SESSION['platform_admin_email'] ?? 'billing-link');

// A GET never changes anything -- it renders the details and a confirm button.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    render_confirm_prompt($control, $token, $payment, $tenant);
}

$result = subscription_activate(
    $control,
    (int)$payment['tenant_id'],
    (string)$payment['plan_code'],
    (float)$payment['amount'],
    (string)$payment['method'],
    (string)$payment['reference'],
    $actor,
    'Confirmed via emailed link'
);

if (!$result['ok']) {
    error_log('confirm-payment: activation failed for payment ' . $payment['id'] . ': ' . $result['error']);
    confirm_failure();
}

// Burn the token so the link cannot be reused, even though activation itself is
// already idempotent. Belt and braces: two independent reasons a double-click
// cannot double-charge.
$stmt = $control->prepare(
    "UPDATE subscription_payments
        SET confirm_token_hash = NULL, confirm_token_expires_at = NULL
      WHERE id = ?"
);
$paymentId = (int)$payment['id'];
$stmt->bind_param("i", $paymentId);
$stmt->execute();
$stmt->close();

platform_audit_log($control, $actor, 'payment.confirmed_by_link', (int)$payment['tenant_id'], [
    'reference' => $payment['reference'],
    'amount'    => $payment['amount'],
    'duplicate' => $result['duplicate'],
    'ip'        => client_ip(),
]);

$fresh   = tenant_by_id($control, (int)$payment['tenant_id']);
$state   = subscription_resolve($fresh);
$until   = $state['ends_at'] ? $state['ends_at']->format('j F Y') : '';
$company = htmlspecialchars((string)$tenant['company_name']);
$invoice = $result['invoice_id'] !== null ? invoice_by_id($control, $result['invoice_id']) : null;
$tenantId = (int)$payment['tenant_id'];

$alreadyDone = $result['duplicate']
    ? '<p style="color:#92400e;background:#fef3c7;padding:11px 15px;border-radius:9px;'
      . 'font-size:.9rem;margin-bottom:16px;">This payment had already been confirmed, '
      . 'so nothing was charged or extended a second time.</p>'
    : '';

$invoiceRow = $invoice !== null
    ? '<tr><td style="padding:5px 14px 5px 0;color:#64748b;">Invoice</td>'
      . '<td style="padding:5px 0;font-weight:600;">' . htmlspecialchars((string)$invoice['invoice_number']) . '</td></tr>'
    : '';

http_response_code(200);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Payment confirmed — SHEHITA</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',system-ui,sans-serif;min-height:100vh;display:flex;align-items:center;
  justify-content:center;padding:24px;background:linear-gradient(135deg,#3e2b1f 0%,#7b583f 50%,#b89b7e 100%)}
.card{background:#fff;border-radius:16px;padding:38px 34px;max-width:470px;width:100%;
  box-shadow:0 20px 25px -5px rgb(0 0 0 / .25);text-align:center}
.tick{width:56px;height:56px;border-radius:50%;background:#dcfce7;color:#166534;display:flex;
  align-items:center;justify-content:center;font-size:1.8rem;margin:0 auto 18px}
h1{font-size:1.28rem;color:#3e2b1f;margin-bottom:9px}
p{color:#475569;line-height:1.62;font-size:.95rem}
table{margin:20px auto;text-align:left;font-size:.92rem}
.btn{display:inline-block;margin-top:20px;padding:11px 22px;border-radius:9px;background:#7b583f;
  color:#fff;text-decoration:none;font-weight:600;font-size:.92rem}
.btn:hover{background:#5c3e2d}
.note{margin-top:16px;font-size:.84rem;color:#94a3b8;line-height:1.55}
</style>
</head>
<body>
<div class="card">
  <div class="tick">&#10003;</div>
  <h1>Payment confirmed</h1>

  <?= $alreadyDone ?>

  <p><strong><?= $company ?></strong> has full access again.</p>

  <table>
    <tr><td style="padding:5px 14px 5px 0;color:#64748b;">Active until</td>
        <td style="padding:5px 0;font-weight:600;"><?= htmlspecialchars($until) ?></td></tr>
    <tr><td style="padding:5px 14px 5px 0;color:#64748b;">Amount</td>
        <td style="padding:5px 0;font-weight:600;"><?= htmlspecialchars(number_format((float)$payment['amount'], 2) . ' ' . $payment['currency']) ?></td></tr>
    <tr><td style="padding:5px 14px 5px 0;color:#64748b;">Reference</td>
        <td style="padding:5px 0;font-weight:600;"><?= htmlspecialchars((string)$payment['reference']) ?></td></tr>
    <?= $invoiceRow ?>
  </table>

  <p class="note">The company has been emailed their invoice automatically.</p>

  <a class="btn" href="tenant.php?id=<?= $tenantId ?>">Open their account</a>
</div>
</body>
</html>
