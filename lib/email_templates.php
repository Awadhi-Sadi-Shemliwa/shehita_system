<?php
/**
 * SHEHITA EMS — Email content
 *
 * One shared HTML shell plus the specific messages the subscription flow sends.
 * Kept apart from lib/mailer.php so that "how we send" and "what we say" can
 * change independently.
 *
 * Email clients are a hostile rendering target: no external CSS, no custom
 * properties, no flexbox worth relying on. Everything here is inline styles on
 * a single centred table, which is the shape that survives Gmail and Outlook.
 */

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/control_db.php';
require_once __DIR__ . '/subscription.php';

const SHEHITA_MAIL_BRAND   = '#7b583f';
const SHEHITA_MAIL_INK     = '#3e2b1f';

/**
 * Wrap body content in the SHEHITA shell.
 *
 * @param string $title    Headline shown at the top of the card.
 * @param string $bodyHtml Pre-escaped HTML.
 * @param array  $cta      Optional ['label' => ..., 'url' => ...] button.
 */
function email_shell(string $title, string $bodyHtml, array $cta = []): string
{
    $brand = SHEHITA_MAIL_BRAND;
    $ink   = SHEHITA_MAIL_INK;
    $year  = date('Y');
    $safeTitle = htmlspecialchars($title);

    $button = '';
    if (!empty($cta['url']) && !empty($cta['label'])) {
        $url   = htmlspecialchars($cta['url']);
        $label = htmlspecialchars($cta['label']);
        $button = <<<HTML
            <tr><td style="padding:8px 32px 28px;">
              <a href="{$url}" style="display:inline-block;background:{$brand};color:#ffffff;
                 text-decoration:none;padding:13px 26px;border-radius:8px;font-weight:600;
                 font-size:15px;">{$label}</a>
            </td></tr>
HTML;
    }

    return <<<HTML
<!DOCTYPE html>
<html><body style="margin:0;padding:0;background:#f1f5f9;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:32px 12px;">
<tr><td align="center">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
         style="max-width:560px;background:#ffffff;border-radius:14px;overflow:hidden;
                font-family:'Segoe UI',Helvetica,Arial,sans-serif;
                box-shadow:0 1px 3px rgba(0,0,0,.1);">
    <tr><td style="background:{$ink};padding:20px 32px;">
      <div style="color:#ffffff;font-size:17px;font-weight:700;letter-spacing:.5px;">SHEHITA EMS</div>
    </td></tr>
    <tr><td style="padding:30px 32px 12px;">
      <h1 style="margin:0 0 14px;font-size:20px;color:{$ink};font-weight:700;">{$safeTitle}</h1>
      <div style="font-size:15px;line-height:1.65;color:#334155;">{$bodyHtml}</div>
    </td></tr>
    {$button}
    <tr><td style="padding:18px 32px 26px;border-top:1px solid #e2e8f0;">
      <div style="font-size:12px;color:#94a3b8;line-height:1.6;">
        &copy; {$year} SHEHITA. Enterprise Management System.<br>
        You are receiving this because your company has an account with SHEHITA.
      </div>
    </td></tr>
  </table>
</td></tr></table>
</body></html>
HTML;
}

/** Small helper: a labelled row for the detail blocks below. */
function email_detail_row(string $label, string $value): string
{
    $l = htmlspecialchars($label);
    $v = htmlspecialchars($value);

    return "<tr><td style=\"padding:5px 14px 5px 0;color:#64748b;font-size:14px;\">{$l}</td>"
         . "<td style=\"padding:5px 0;color:#1e293b;font-size:14px;font-weight:600;\">{$v}</td></tr>";
}

function email_details_table(array $rows): string
{
    $html = '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:16px 0;">';
    foreach ($rows as $label => $value) {
        $html .= email_detail_row($label, (string)$value);
    }

    return $html . '</table>';
}

// ---------------------------------------------------------------------------
// The messages
// ---------------------------------------------------------------------------

/**
 * Sent the moment a company finishes signup. Carries their portal URL, the
 * admin login they just chose, and when the free trial ends.
 */
function notify_welcome(array $tenant, string $adminEmail, string $portalUrl): bool
{
    $state    = subscription_resolve($tenant);
    $trialEnd = $state['ends_at'] ? $state['ends_at']->format('j F Y') : '';
    $company  = htmlspecialchars((string)$tenant['company_name']);

    $body = "<p>Welcome, {$company}.</p>"
          . '<p>Your Enterprise Management System is ready. It carries your company name, '
          . 'your logo and colours taken from the logo you uploaded.</p>'
          . email_details_table([
                'Your portal'  => $portalUrl,
                'Sign in as'   => $adminEmail,
                'Free trial'   => $state['days_left'] . ' days, ending ' . $trialEnd,
            ])
          . '<p>Everything is unlocked during the trial. When it ends, make a payment to '
          . 'SHEHITA and we will extend your subscription straight away — your data stays '
          . 'exactly where it is in the meantime.</p>'
          . '<p style="color:#64748b;font-size:13.5px;">Keep this email: it is the easiest '
          . 'way back to your portal address.</p>';

    return send_mail(
        $adminEmail,
        'Your ' . (string)$tenant['company_name'] . ' EMS is ready — 14-day trial started',
        email_shell('Your system is ready', $body, ['label' => 'Open your portal', 'url' => $portalUrl])
    );
}

/**
 * The customer pressed "I have paid". Two messages go out: this one to
 * SHEHITA's billing admins carrying the one-click confirmation link, and the
 * acknowledgement below to the customer.
 *
 * Reply-To is the customer, so a billing admin can just hit Reply to ask a
 * question about the reference.
 */
function notify_payment_claimed(mysqli $control, array $tenant, array $payment, string $confirmUrl): bool
{
    $admins = billing_admin_emails();
    if ($admins === []) {
        error_log('notify_payment_claimed: BILLING_ADMIN_EMAILS is not configured; nobody was notified.');
        return false;
    }

    $plan = plan_by_code($control, (string)$payment['plan_code']);

    $body = '<p>A customer says they have paid. Check the payment against your bank or '
          . 'mobile-money records, then confirm below.</p>'
          . email_details_table([
                'Company'   => (string)$tenant['company_name'],
                'Package'   => $plan['name'] ?? $payment['plan_code'],
                'Amount'    => number_format((float)$payment['amount'], 2) . ' ' . $payment['currency'],
                'Method'    => (string)$payment['method'],
                'Reference' => (string)$payment['reference'],
                'Contact'   => (string)($tenant['contact_email'] ?? ''),
            ])
          . '<p><strong>Only confirm once you have actually seen the money.</strong> '
          . 'Confirming extends their subscription immediately and issues an invoice.</p>'
          . '<p style="color:#64748b;font-size:13.5px;">This link works for 7 days and can '
          . 'only be used once.</p>';

    return send_mail_many(
        $admins,
        'Confirm payment — ' . (string)$tenant['company_name'],
        email_shell('Payment awaiting confirmation', $body, [
            'label' => 'Confirm this payment',
            'url'   => $confirmUrl,
        ]),
        (string)($tenant['contact_email'] ?? '') ?: null
    );
}

/** Acknowledgement to the customer that their payment notice was received. */
function notify_payment_claim_ack(array $tenant, array $payment): bool
{
    $to = (string)($tenant['contact_email'] ?? '');
    if ($to === '') {
        return false;
    }

    $body = '<p>Thank you — we have received your payment notice and passed it to the '
          . 'SHEHITA team to verify.</p>'
          . email_details_table([
                'Amount'    => number_format((float)$payment['amount'], 2) . ' ' . $payment['currency'],
                'Method'    => (string)$payment['method'],
                'Reference' => (string)$payment['reference'],
            ])
          . '<p>As soon as it is confirmed your subscription is extended automatically and '
          . 'we will email you the invoice. This is usually the same working day.</p>';

    return send_mail($to, 'We received your payment notice — SHEHITA EMS',
        email_shell('Payment notice received', $body));
}

/**
 * Payment confirmed: subscription extended, invoice issued.
 * Called from subscription_activate() so that EVERY route to activation mails
 * the customer — the admin console and the emailed link alike.
 */
function notify_payment_confirmed(mysqli $control, int $tenantId, int $paymentId, ?int $invoiceId): bool
{
    $tenant = tenant_by_id($control, $tenantId);
    if ($tenant === null) {
        return false;
    }

    $to = (string)($tenant['contact_email'] ?? '');
    if ($to === '') {
        return false;
    }

    $invoice = $invoiceId !== null ? invoice_by_id($control, $invoiceId) : null;
    $state   = subscription_resolve($tenant);
    $until   = $state['ends_at'] ? $state['ends_at']->format('j F Y') : '';

    $rows = ['Active until' => $until];
    if ($invoice !== null) {
        $rows['Invoice']  = (string)$invoice['invoice_number'];
        $rows['Amount']   = number_format((float)$invoice['total'], 2) . ' ' . $invoice['currency'];
    }

    $base       = rtrim(getenv('APP_BASE_URL') ?: 'https://system.shehita.co.tz', '/');
    $portalUrl  = $base . '/' . (string)$tenant['slug'] . '/login.php';
    $invoiceUrl = $invoice !== null
        ? $base . '/invoice-view.php?id=' . (int)$invoice['id'] . '&token=' . urlencode((string)$invoice['view_token'])
        : $portalUrl;

    $body = '<p>Your payment has been confirmed and your subscription is now active.</p>'
          . email_details_table($rows)
          . '<p>Everyone at your company has full access again. We will remind you before '
          . 'this period ends.</p>';

    return send_mail(
        $to,
        'Payment confirmed — active until ' . $until,
        email_shell('Subscription active', $body, [
            'label' => $invoice !== null ? 'View your invoice' : 'Open your portal',
            'url'   => $invoiceUrl,
        ])
    );
}

/**
 * The scheduled reminders (cron/subscription-notices.php).
 *
 * @param string $kind One of: trial_3, trial_1, trial_expired,
 *                     paid_7, paid_1, paid_expired
 */
function notify_subscription_notice(array $tenant, string $kind): bool
{
    $to = (string)($tenant['contact_email'] ?? '');
    if ($to === '') {
        return false;
    }

    $state     = subscription_resolve($tenant);
    $base      = rtrim(getenv('APP_BASE_URL') ?: 'https://system.shehita.co.tz', '/');
    $billing   = $base . '/' . (string)$tenant['slug'] . '/billing.php';
    $ends      = $state['ends_at'] ? $state['ends_at']->format('j F Y') : '';
    $days      = $state['days_left'];
    $dayWord   = $days === 1 ? 'day' : 'days';

    switch ($kind) {
        case 'trial_3':
        case 'trial_1':
            $subject = "Your free trial ends in {$days} {$dayWord}";
            $title   = 'Your trial is ending';
            $body    = "<p>Your free trial of the Enterprise Management System ends on "
                     . "<strong>{$ends}</strong> — that is {$days} {$dayWord} from now.</p>"
                     . '<p>To keep working without interruption, make a payment to SHEHITA '
                     . 'and let us know. Your data is safe either way.</p>';
            break;

        case 'trial_expired':
            $subject = 'Your free trial has ended';
            $title   = 'Trial ended';
            $body    = '<p>Your 14-day free trial has ended, so your team can no longer sign in.</p>'
                     . '<p><strong>Nothing has been deleted.</strong> Everything comes back the '
                     . 'moment a payment is confirmed.</p>';
            break;

        case 'paid_7':
        case 'paid_1':
            $subject = "Your subscription renews in {$days} {$dayWord}";
            $title   = 'Renewal due';
            $body    = "<p>Your subscription runs until <strong>{$ends}</strong>.</p>"
                     . '<p>Paying before then keeps your access unbroken — and any days you '
                     . 'have left are added on top, so you never lose time by paying early.</p>';
            break;

        case 'paid_expired':
            $subject = 'Your subscription has expired';
            $title   = 'Subscription expired';
            $body    = '<p>Your subscription has expired and your team can no longer sign in.</p>'
                     . '<p><strong>Your data is untouched.</strong> Access returns as soon as '
                     . 'a new payment is confirmed.</p>';
            break;

        default:
            return false;
    }

    return send_mail($to, $subject . ' — SHEHITA EMS',
        email_shell($title, $body, ['label' => 'View billing', 'url' => $billing]));
}
