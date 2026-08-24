<?php
/**
 * SHEHITA EMS — Subscription invoice document
 *
 * Renders the proof-of-payment SHEHITA issues to a client company.
 *
 * There is no PDF library here on purpose. The app already produces its
 * "PDFs" the same way everywhere else -- an A4 print stylesheet plus
 * window.print() (see modules/invoice.php, operations.php, projects.php,
 * status.php) -- and matching that keeps the deployment free of another
 * native dependency. Browsers save to PDF from the print dialog.
 */

require_once __DIR__ . '/control_db.php';
require_once __DIR__ . '/subscription.php';

/**
 * @param bool $showPrintButton Screen chrome. Hidden when embedding.
 */
function render_subscription_invoice(mysqli $control, array $invoice, array $tenant, ?array $payment, bool $showPrintButton = true): string
{
    $company   = htmlspecialchars((string)$tenant['company_name']);
    $number    = htmlspecialchars((string)$invoice['invoice_number']);
    $issued    = date('j F Y', strtotime((string)$invoice['issued_at']));
    $desc      = htmlspecialchars((string)$invoice['description']);
    $currency  = htmlspecialchars((string)$invoice['currency']);
    $subtotal  = number_format((float)$invoice['subtotal'], 2);
    $vat       = number_format((float)$invoice['vat'], 2);
    $total     = number_format((float)$invoice['total'], 2);
    $hasVat    = (float)$invoice['vat'] > 0;

    $contact   = htmlspecialchars((string)($tenant['contact_name'] ?? ''));
    $email     = htmlspecialchars((string)($tenant['contact_email'] ?? ''));
    $phone     = htmlspecialchars((string)($tenant['contact_phone'] ?? ''));

    $method    = $payment !== null ? htmlspecialchars((string)$payment['method']) : '—';
    $reference = $payment !== null ? htmlspecialchars((string)$payment['reference']) : '—';
    $paidOn    = $payment !== null ? date('j F Y', strtotime((string)$payment['created_at'])) : '—';

    // SHEHITA's own details, editable in admin/settings.php.
    $shehitaAddress = htmlspecialchars(platform_setting($control, 'shehita_address', 'Dar es Salaam, Tanzania'));
    $shehitaEmail   = htmlspecialchars(platform_setting($control, 'shehita_email', 'info@shehita.co.tz'));
    $shehitaPhone   = htmlspecialchars(platform_setting($control, 'shehita_phone', ''));
    $shehitaTin     = htmlspecialchars(platform_setting($control, 'shehita_tin', ''));

    $vatRow = $hasVat
        ? "<tr><td class=\"lbl\">VAT</td><td class=\"amt\">{$vat}</td></tr>"
        : '';

    $tinRow = $shehitaTin !== '' ? "<div>TIN: {$shehitaTin}</div>" : '';
    $phoneRow = $shehitaPhone !== '' ? "<div>{$shehitaPhone}</div>" : '';

    $printBtn = $showPrintButton
        ? '<div class="no-print" style="text-align:center;margin:22px 0;">
             <button onclick="window.print()" class="printbtn">Print / Save as PDF</button>
           </div>'
        : '';

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Invoice {$number} — SHEHITA</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  *{margin:0;padding:0;box-sizing:border-box}
  body{font-family:'Inter',system-ui,sans-serif;background:#f1f5f9;color:#1e293b;padding:26px 16px 50px}

  .sheet{max-width:820px;margin:0 auto;background:#fff;padding:44px 48px;
         box-shadow:0 1px 3px rgb(0 0 0 / .1);border-radius:6px}

  .head{display:flex;justify-content:space-between;gap:26px;
        border-bottom:3px solid #3e2b1f;padding-bottom:20px;margin-bottom:26px}
  .head .who{font-size:1.45rem;font-weight:700;color:#3e2b1f;letter-spacing:.03em}
  .head .meta{font-size:.83rem;color:#64748b;line-height:1.65;margin-top:6px}
  .head .right{text-align:right}
  .head .right .t{font-size:1.15rem;font-weight:700;color:#3e2b1f;letter-spacing:.08em}
  .head .right .n{font-size:.95rem;font-weight:600;margin-top:5px}
  .head .right .d{font-size:.83rem;color:#64748b;margin-top:3px}

  .parties{display:flex;justify-content:space-between;gap:26px;margin-bottom:28px}
  .parties h3{font-size:.72rem;text-transform:uppercase;letter-spacing:.09em;
              color:#94a3b8;margin-bottom:7px;font-weight:700}
  .parties .name{font-weight:700;font-size:1rem;color:#3e2b1f}
  .parties div.line{font-size:.87rem;color:#475569;line-height:1.6}

  table.items{width:100%;border-collapse:collapse;margin-bottom:22px}
  table.items th{background:#f7f2ec;color:#3e2b1f;text-align:left;padding:11px 13px;
                 font-size:.75rem;text-transform:uppercase;letter-spacing:.06em;font-weight:700}
  table.items td{padding:14px 13px;border-bottom:1px solid #f0e8df;font-size:.92rem}
  table.items td.r,table.items th.r{text-align:right}

  table.totals{margin-left:auto;min-width:270px;border-collapse:collapse}
  table.totals td{padding:7px 13px;font-size:.92rem}
  table.totals td.lbl{color:#64748b}
  table.totals td.amt{text-align:right;font-weight:600}
  table.totals tr.grand td{border-top:2px solid #3e2b1f;padding-top:11px;
                           font-size:1.08rem;font-weight:700;color:#3e2b1f}

  .paid{display:inline-block;margin-top:20px;padding:7px 17px;border:2px solid #16a34a;
        color:#16a34a;border-radius:7px;font-weight:700;letter-spacing:.08em;font-size:.83rem}

  .payinfo{margin-top:26px;padding-top:18px;border-top:1px solid #f0e8df;
           font-size:.86rem;color:#475569;line-height:1.7}
  .payinfo strong{color:#3e2b1f}

  .foot{margin-top:30px;padding-top:16px;border-top:1px solid #f0e8df;
        text-align:center;font-size:.79rem;color:#94a3b8;line-height:1.6}

  .printbtn{padding:11px 24px;border:none;border-radius:8px;background:#7b583f;color:#fff;
            font-weight:600;font-size:.93rem;cursor:pointer;font-family:inherit}
  .printbtn:hover{background:#5c3e2d}

  @media print{
    body{background:#fff;padding:0}
    .sheet{box-shadow:none;max-width:none;padding:0;border-radius:0}
    .no-print{display:none !important}
    @page{size:A4;margin:14mm}
  }
</style>
</head>
<body>

{$printBtn}

<div class="sheet">

  <div class="head">
    <div>
      <div class="who">SHEHITA</div>
      <div class="meta">
        Enterprise Management System<br>
        {$shehitaAddress}<br>
        {$shehitaEmail}
        {$phoneRow}
        {$tinRow}
      </div>
    </div>
    <div class="right">
      <div class="t">INVOICE</div>
      <div class="n">{$number}</div>
      <div class="d">Issued {$issued}</div>
    </div>
  </div>

  <div class="parties">
    <div>
      <h3>Billed to</h3>
      <div class="name">{$company}</div>
      <div class="line">{$contact}</div>
      <div class="line">{$email}</div>
      <div class="line">{$phone}</div>
    </div>
    <div style="text-align:right;">
      <h3>Payment</h3>
      <div class="line">Method: {$method}</div>
      <div class="line">Reference: {$reference}</div>
      <div class="line">Received: {$paidOn}</div>
    </div>
  </div>

  <table class="items">
    <thead>
      <tr><th>Description</th><th class="r">Amount ({$currency})</th></tr>
    </thead>
    <tbody>
      <tr><td>{$desc}</td><td class="r">{$subtotal}</td></tr>
    </tbody>
  </table>

  <table class="totals">
    <tr><td class="lbl">Subtotal</td><td class="amt">{$subtotal}</td></tr>
    {$vatRow}
    <tr class="grand"><td>Total</td><td class="amt">{$total} {$currency}</td></tr>
  </table>

  <div style="text-align:right;"><span class="paid">PAID</span></div>

  <div class="payinfo">
    <strong>Thank you.</strong> This invoice confirms your subscription payment to SHEHITA.
    Your system stays available for the period shown above, and we will remind you before it ends.
  </div>

  <div class="foot">
    SHEHITA Enterprise Management System &middot; system.shehita.co.tz<br>
    This is a computer-generated invoice.
  </div>

</div>
</body>
</html>
HTML;
}
