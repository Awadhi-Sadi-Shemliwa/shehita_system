<?php
/**
 * SHEHITA EMS — Subscription state
 *
 * Every client company gets a 14-day free trial the moment their account is
 * created. When it ends they are locked out until SHEHITA records a payment,
 * which extends `tenants.paid_until`.
 *
 * The important design rule here, borrowed from the PesaLens billing service:
 * ONE pure resolver decides the answer, and BOTH the "3 days left" banner and
 * the hard lockout read it. They therefore cannot drift apart and start
 * telling the customer two different things.
 *
 * subscription_resolve() never writes. Only subscription_activate() and the
 * explicit admin actions below change state.
 */

require_once __DIR__ . '/control_db.php';

/** Fallback trial length for new signups. Existing tenants use their own
 *  snapshotted tenants.trial_days, so changing this never moves a live
 *  customer's window. */
function default_trial_days(): int
{
    $env = (int)(getenv('TRIAL_DAYS') ?: 0);
    return $env > 0 ? $env : 14;
}

/**
 * Work out where a tenant stands right now.
 *
 * Precedence:
 *   Suspended / Deleted   -> 'suspended'  (never active)
 *   paid_until in future  -> 'active'
 *   paid_until in past    -> 'expired'    (they paid once, it lapsed)
 *   otherwise trial window-> 'trial' / 'trial_expired'
 *
 * @return array{status:string, is_active:bool, in_trial:bool,
 *               ends_at:?DateTimeImmutable, days_left:int, label:string}
 */
function subscription_resolve(array $tenant, ?DateTimeImmutable $now = null): array
{
    $now = $now ?? new DateTimeImmutable('now');

    $status  = (string)($tenant['status'] ?? 'Active');
    $paidRaw = $tenant['paid_until'] ?? null;

    if ($status === 'Suspended' || $status === 'Deleted') {
        return [
            'status'    => 'suspended',
            'is_active' => false,
            'in_trial'  => false,
            'ends_at'   => null,
            'days_left' => 0,
            'label'     => $status === 'Deleted' ? 'Account closed' : 'Account suspended',
        ];
    }

    if (!empty($paidRaw)) {
        $paidUntil = new DateTimeImmutable((string)$paidRaw);
        $active    = $paidUntil > $now;

        return [
            'status'    => $active ? 'active' : 'expired',
            'is_active' => $active,
            'in_trial'  => false,
            'ends_at'   => $paidUntil,
            'days_left' => $active ? days_between($now, $paidUntil) : 0,
            'label'     => $active ? 'Subscription active' : 'Subscription expired',
        ];
    }

    $trialDays  = (int)($tenant['trial_days'] ?? default_trial_days());
    $trialStart = new DateTimeImmutable((string)($tenant['trial_started_at'] ?? 'now'));
    $trialEnd   = $trialStart->modify("+{$trialDays} days");
    $active     = $trialEnd > $now;

    return [
        'status'    => $active ? 'trial' : 'trial_expired',
        'is_active' => $active,
        'in_trial'  => true,
        'ends_at'   => $trialEnd,
        'days_left' => $active ? days_between($now, $trialEnd) : 0,
        'label'     => $active ? 'Free trial' : 'Free trial ended',
    ];
}

/**
 * Whole days from $from until $to, rounded up, floored at 0.
 * "Expires in 15 hours" reads as 1 day left, not 0.
 */
function days_between(DateTimeImmutable $from, DateTimeImmutable $to): int
{
    $seconds = $to->getTimestamp() - $from->getTimestamp();
    if ($seconds <= 0) {
        return 0;
    }
    return (int)ceil($seconds / 86400);
}

/**
 * A short sentence for banners and emails. Same wording everywhere.
 */
function subscription_summary(array $state): string
{
    $days = $state['days_left'];
    $day  = $days === 1 ? 'day' : 'days';

    switch ($state['status']) {
        case 'trial':
            return "Free trial — {$days} {$day} remaining.";
        case 'trial_expired':
            return 'Your 14-day free trial has ended.';
        case 'active':
            return 'Subscription active until ' . $state['ends_at']->format('j M Y') . '.';
        case 'expired':
            return 'Your subscription expired on ' . $state['ends_at']->format('j M Y') . '.';
        case 'suspended':
            return $state['label'] . '. Please contact SHEHITA.';
    }

    return $state['label'];
}

// ---------------------------------------------------------------------------
// Activation
// ---------------------------------------------------------------------------

/**
 * Record a payment and extend the tenant's subscription.
 *
 * Two behaviours worth knowing:
 *
 *  - Periods STACK. If the tenant is still inside a paid period, the new one
 *    starts at the current end date rather than today, so paying early never
 *    burns days.
 *  - It is IDEMPOTENT on (method, reference), enforced by a unique key. Clicking
 *    the emailed confirmation link twice cannot extend the subscription twice.
 *
 * @return array{ok:bool, duplicate:bool, payment_id:int, invoice_id:?int,
 *                period_end:?DateTimeImmutable, error:string}
 */
function subscription_activate(
    mysqli $control,
    int $tenantId,
    string $planCode,
    float $amount,
    string $method,
    string $reference,
    string $actor,
    string $note = ''
): array {
    $fail = static fn(string $msg): array => [
        'ok' => false, 'duplicate' => false, 'payment_id' => 0,
        'invoice_id' => null, 'period_end' => null, 'error' => $msg,
    ];

    $tenant = tenant_by_id($control, $tenantId);
    if ($tenant === null) {
        return $fail('Company not found.');
    }

    $plan = plan_by_code($control, $planCode);
    if ($plan === null) {
        return $fail('Unknown subscription package.');
    }

    // Already processed? Return the original result rather than extending again.
    $existing = payment_by_reference($control, $tenantId, $method, $reference);
    if ($existing !== null && $existing['status'] === 'Paid') {
        return [
            'ok'         => true,
            'duplicate'  => true,
            'payment_id' => (int)$existing['id'],
            'invoice_id' => invoice_id_for_payment($control, (int)$existing['id']),
            'period_end' => $existing['period_end'] ? new DateTimeImmutable($existing['period_end']) : null,
            'error'      => '',
        ];
    }

    $now  = new DateTimeImmutable('now');
    $days = (int)$plan['days'];

    // Stack onto an unexpired period; otherwise start from now.
    $base = $now;
    if (!empty($tenant['paid_until'])) {
        $paidUntil = new DateTimeImmutable((string)$tenant['paid_until']);
        if ($paidUntil > $now) {
            $base = $paidUntil;
        }
    }
    $periodEnd = $base->modify("+{$days} days");

    $control->begin_transaction();

    try {
        if ($existing !== null) {
            // A pending row already exists (client clicked "I have paid").
            $stmt = $control->prepare(
                "UPDATE subscription_payments
                    SET status = 'Paid', plan_code = ?, amount = ?, period_start = ?,
                        period_end = ?, recorded_by = ?,
                        confirm_token_hash = NULL, confirm_token_expires_at = NULL
                  WHERE id = ?"
            );
            $start = $base->format('Y-m-d H:i:s');
            $end   = $periodEnd->format('Y-m-d H:i:s');
            $pid   = (int)$existing['id'];
            $stmt->bind_param("sdsssi", $planCode, $amount, $start, $end, $actor, $pid);
            $stmt->execute();
            $stmt->close();
            $paymentId = $pid;
        } else {
            $currency = (string)$plan['currency'];
            $start    = $base->format('Y-m-d H:i:s');
            $end      = $periodEnd->format('Y-m-d H:i:s');

            $stmt = $control->prepare(
                "INSERT INTO subscription_payments
                    (tenant_id, plan_code, amount, currency, method, reference,
                     status, period_start, period_end, recorded_by, note)
                 VALUES (?, ?, ?, ?, ?, ?, 'Paid', ?, ?, ?, ?)"
            );
            $stmt->bind_param(
                "isdsssssss",
                $tenantId, $planCode, $amount, $currency, $method, $reference,
                $start, $end, $actor, $note
            );
            $stmt->execute();
            $paymentId = (int)$stmt->insert_id;
            $stmt->close();
        }

        $stmt = $control->prepare("UPDATE tenants SET paid_until = ?, status = IF(status = 'Suspended', 'Suspended', 'Active') WHERE id = ?");
        $end = $periodEnd->format('Y-m-d H:i:s');
        $stmt->bind_param("si", $end, $tenantId);
        $stmt->execute();
        $stmt->close();

        $invoiceId = create_subscription_invoice(
            $control, $paymentId, $tenantId, $plan, $amount, $base, $periodEnd
        );

        $control->commit();
    } catch (Throwable $e) {
        $control->rollback();
        error_log('subscription_activate failed: ' . $e->getMessage());
        return $fail('Could not record the payment. Please try again.');
    }

    // Wipe this company's reminder history. Two reasons: they should not receive
    // a "your trial ends in 3 days" notice that was queued for a period they
    // have now paid for, and when this new period eventually lapses they must
    // get told again -- which the one-shot expiry notices would otherwise
    // suppress forever. See cron/subscription-notices.php.
    $stmt = $control->prepare("DELETE FROM notices_sent WHERE tenant_id = ?");
    $stmt->bind_param("i", $tenantId);
    $stmt->execute();
    $stmt->close();

    platform_audit_log($control, $actor, 'subscription.activate', $tenantId, [
        'plan'       => $planCode,
        'amount'     => $amount,
        'method'     => $method,
        'reference'  => $reference,
        'period_end' => $periodEnd->format('Y-m-d H:i:s'),
    ]);

    // Tell the customer. Done here rather than at the call sites so that every
    // path to activation -- admin console, emailed magic link -- always mails.
    require_once __DIR__ . '/mailer.php';
    require_once __DIR__ . '/email_templates.php';
    notify_payment_confirmed($control, $tenantId, $paymentId, $invoiceId);

    return [
        'ok'         => true,
        'duplicate'  => false,
        'payment_id' => $paymentId,
        'invoice_id' => $invoiceId,
        'period_end' => $periodEnd,
        'error'      => '',
    ];
}

/**
 * Give a tenant extra days without recording money (goodwill, comped account,
 * apology for downtime). Stacks the same way as a paid period.
 */
function subscription_extend_days(mysqli $control, int $tenantId, int $days, string $actor, string $reason = ''): ?DateTimeImmutable
{
    $tenant = tenant_by_id($control, $tenantId);
    if ($tenant === null || $days === 0) {
        return null;
    }

    $now  = new DateTimeImmutable('now');
    $base = $now;
    if (!empty($tenant['paid_until'])) {
        $paidUntil = new DateTimeImmutable((string)$tenant['paid_until']);
        if ($paidUntil > $now) {
            $base = $paidUntil;
        }
    }
    $newEnd = $base->modify(($days > 0 ? '+' : '') . $days . ' days');

    $stmt = $control->prepare("UPDATE tenants SET paid_until = ? WHERE id = ?");
    $end = $newEnd->format('Y-m-d H:i:s');
    $stmt->bind_param("si", $end, $tenantId);
    $stmt->execute();
    $stmt->close();

    platform_audit_log($control, $actor, 'subscription.extend', $tenantId, [
        'days' => $days, 'reason' => $reason, 'new_end' => $end,
    ]);

    return $newEnd;
}

function tenant_set_status(mysqli $control, int $tenantId, string $status, string $actor): bool
{
    if (!in_array($status, ['Active', 'Suspended', 'Deleted'], true)) {
        return false;
    }

    $deletedAt = $status === 'Deleted' ? (new DateTimeImmutable('now'))->format('Y-m-d H:i:s') : null;

    $stmt = $control->prepare("UPDATE tenants SET status = ?, deleted_at = ? WHERE id = ?");
    $stmt->bind_param("ssi", $status, $deletedAt, $tenantId);
    $ok = $stmt->execute();
    $stmt->close();

    platform_audit_log($control, $actor, 'tenant.status', $tenantId, ['status' => $status]);

    return $ok;
}

// ---------------------------------------------------------------------------
// Plans, payments, invoices
// ---------------------------------------------------------------------------

function plan_by_code(mysqli $control, string $code): ?array
{
    $stmt = $control->prepare("SELECT * FROM plans WHERE code = ? LIMIT 1");
    $stmt->bind_param("s", $code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function active_plans(mysqli $control): array
{
    $result = $control->query("SELECT * FROM plans WHERE active = 1 ORDER BY sort_order, days");
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

/**
 * Find a payment by its bank/mobile-money reference WITHIN ONE COMPANY.
 *
 * Scoped to $tenantId deliberately. References are chosen by banks and mobile
 * money operators, not by us, and the same string genuinely does recur across
 * different customers. A global lookup meant one company's reference could match
 * another company's payment row -- crediting the wrong subscription, or refusing
 * a legitimate payment as a duplicate.
 */
function payment_by_reference(mysqli $control, int $tenantId, string $method, string $reference): ?array
{
    $stmt = $control->prepare(
        "SELECT * FROM subscription_payments
          WHERE tenant_id = ? AND method = ? AND reference = ? LIMIT 1"
    );
    $stmt->bind_param("iss", $tenantId, $method, $reference);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function invoice_id_for_payment(mysqli $control, int $paymentId): ?int
{
    $stmt = $control->prepare("SELECT id FROM subscription_invoices WHERE payment_id = ? LIMIT 1");
    $stmt->bind_param("i", $paymentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? (int)$row['id'] : null;
}

/**
 * Issue the proof-of-payment invoice for an activated period.
 *
 * The invoice number is derived from the row's own auto-increment id, so it is
 * unique by construction -- no COUNT(*) race between two concurrent payments.
 */
function create_subscription_invoice(
    mysqli $control,
    int $paymentId,
    int $tenantId,
    array $plan,
    float $amount,
    DateTimeImmutable $periodStart,
    DateTimeImmutable $periodEnd
): int {
    $existing = invoice_id_for_payment($control, $paymentId);
    if ($existing !== null) {
        return $existing;
    }

    $now         = new DateTimeImmutable('now');
    $description = $plan['name'] . ' subscription (' .
                   $periodStart->format('j M Y') . ' – ' . $periodEnd->format('j M Y') . ')';
    $placeholder = 'TMP-' . bin2hex(random_bytes(8));
    $viewToken   = bin2hex(random_bytes(32));

    $stmt = $control->prepare(
        "INSERT INTO subscription_invoices
            (payment_id, tenant_id, invoice_number, issued_at, period_start, period_end,
             description, subtotal, vat, total, currency, view_token)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0.00, ?, ?, ?)"
    );
    $issued   = $now->format('Y-m-d H:i:s');
    $start    = $periodStart->format('Y-m-d H:i:s');
    $end      = $periodEnd->format('Y-m-d H:i:s');
    $currency = (string)$plan['currency'];
    $stmt->bind_param(
        "iisssssddss",
        $paymentId, $tenantId, $placeholder, $issued, $start, $end,
        $description, $amount, $amount, $currency, $viewToken
    );
    $stmt->execute();
    $invoiceId = (int)$stmt->insert_id;
    $stmt->close();

    $number = sprintf('SHE-%s-%04d', $now->format('Y'), $invoiceId);
    $stmt = $control->prepare("UPDATE subscription_invoices SET invoice_number = ? WHERE id = ?");
    $stmt->bind_param("si", $number, $invoiceId);
    $stmt->execute();
    $stmt->close();

    return $invoiceId;
}

function invoice_by_id(mysqli $control, int $invoiceId): ?array
{
    $stmt = $control->prepare("SELECT * FROM subscription_invoices WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $invoiceId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function tenant_payments(mysqli $control, int $tenantId, int $limit = 50): array
{
    $stmt = $control->prepare(
        "SELECT p.*, i.id AS invoice_id, i.invoice_number
           FROM subscription_payments p
           LEFT JOIN subscription_invoices i ON i.payment_id = p.id
          WHERE p.tenant_id = ?
          ORDER BY p.created_at DESC
          LIMIT ?"
    );
    $stmt->bind_param("ii", $tenantId, $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}
