<?php
/**
 * SHEHITA EMS - Subscription invoice (customer view)
 *
 * Reached from the emailed confirmation and from billing.php.
 *
 * Two ways in, because the invoice has to be readable in both situations:
 *   - with the token from the email, which works with no login at all (the
 *     recipient may be a finance person who has no account); or
 *   - while signed in as that company's admin.
 *
 * The token is 32 random bytes compared with hash_equals(), and it only ever
 * unlocks this one document.
 *
 * This is a PLATFORM page (no /<slug>/ prefix) so that the link in an email
 * stays valid regardless of which company it belongs to.
 */

require_once __DIR__ . '/lib/util.php';

platform_boot();

require_once __DIR__ . '/lib/control_db.php';
require_once __DIR__ . '/lib/subscription.php';
require_once __DIR__ . '/lib/invoice_render.php';

$control   = control_db();
$invoiceId = (int)($_GET['id'] ?? 0);
$token     = (string)($_GET['token'] ?? '');

$invoice = invoice_by_id($control, $invoiceId);

if ($invoice === null) {
    render_notice_page(404, 'Invoice not found', 'That invoice does not exist.');
}

$authorised = $token !== ''
    && !empty($invoice['view_token'])
    && hash_equals((string)$invoice['view_token'], $token);

// No token? Fall back to a signed-in session for the owning company.
if (!$authorised) {
    $tenantRow = tenant_by_id($control, (int)$invoice['tenant_id']);
    if ($tenantRow !== null) {
        platform_session_start(
            'SHEMS_' . strtoupper(str_replace('-', '_', (string)$tenantRow['slug'])),
            '/' . $tenantRow['slug'] . '/'
        );
        $authorised = !empty($_SESSION['email']) && (int)($_SESSION['role_id'] ?? 0) === 1;
    }
}

if (!$authorised) {
    render_notice_page(
        403,
        'Invoice not available',
        'Please open this invoice using the link in your email, or sign in to your company '
        . 'portal as an administrator and open it from the billing page.'
    );
}

$tenant = tenant_by_id($control, (int)$invoice['tenant_id']);
if ($tenant === null) {
    render_notice_page(404, 'Invoice not found', 'That invoice is no longer available.');
}

$stmt = $control->prepare("SELECT * FROM subscription_payments WHERE id = ? LIMIT 1");
$paymentId = (int)$invoice['payment_id'];
$stmt->bind_param("i", $paymentId);
$stmt->execute();
$payment = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();

echo render_subscription_invoice($control, $invoice, $tenant, $payment);
