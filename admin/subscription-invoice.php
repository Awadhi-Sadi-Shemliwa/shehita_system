<?php
/**
 * SHEHITA EMS - Subscription invoice (admin view)
 *
 * The same document the customer receives, viewable by SHEHITA staff from a
 * company's payment history.
 */

require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../lib/invoice_render.php';

require_platform_admin();

$control   = control_db();
$invoiceId = (int)($_GET['id'] ?? 0);
$invoice   = invoice_by_id($control, $invoiceId);

if ($invoice === null) {
    render_notice_page(404, 'Invoice not found', 'No invoice with that id exists.');
}

$tenant = tenant_by_id($control, (int)$invoice['tenant_id']);
if ($tenant === null) {
    render_notice_page(404, 'Invoice not found', 'The company this invoice belongs to no longer exists.');
}

$stmt = $control->prepare("SELECT * FROM subscription_payments WHERE id = ? LIMIT 1");
$paymentId = (int)$invoice['payment_id'];
$stmt->bind_param("i", $paymentId);
$stmt->execute();
$payment = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();

echo render_subscription_invoice($control, $invoice, $tenant, $payment);
