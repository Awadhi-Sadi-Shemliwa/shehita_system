<?php
/**
 * SHEHITA EMS — Daily subscription reminders
 *
 * Emails companies before their trial or paid period runs out, and once when it
 * has. Run it once a day from the host crontab:
 *
 *   0 7 * * * curl -fsS -H "X-Cron-Key: $CRON_KEY" \
 *             http://127.0.0.1:8080/cron/subscription-notices.php
 *
 * It is reachable over HTTP (that is how cron triggers it), so it is protected
 * by a shared key. Without CRON_KEY set it refuses to run at all rather than
 * defaulting to open — an unprotected endpoint that emails every customer is
 * not something to leave to configuration.
 *
 * Safe to run more than once a day: notices_sent has a UNIQUE key on
 * (tenant_id, kind, sent_on), so a duplicate run inserts nothing and mails
 * nobody. That matters because a retrying cron or a double-triggered deploy
 * must never spam a customer.
 */

require_once __DIR__ . '/../lib/util.php';

platform_boot();

require_once __DIR__ . '/../lib/control_db.php';
require_once __DIR__ . '/../lib/subscription.php';
require_once __DIR__ . '/../lib/mailer.php';
require_once __DIR__ . '/../lib/email_templates.php';

// ---------------------------------------------------------------------------
// Authorisation
// ---------------------------------------------------------------------------

$isCli      = PHP_SAPI === 'cli';
$expected   = getenv('CRON_KEY') ?: '';
$provided   = $_SERVER['HTTP_X_CRON_KEY'] ?? ($_GET['key'] ?? '');

if (!$isCli) {
    if ($expected === '') {
        http_response_code(503);
        error_log('subscription-notices: CRON_KEY is not configured; refusing to run.');
        exit("Not configured.\n");
    }
    if (!is_string($provided) || !hash_equals($expected, $provided)) {
        http_response_code(403);
        exit("Forbidden.\n");
    }
}

header('Content-Type: text/plain; charset=utf-8');

$control = control_db();
$today   = (new DateTimeImmutable('now'))->format('Y-m-d');

$tenants = $control->query(
    "SELECT * FROM tenants WHERE status = 'Active' ORDER BY id"
)->fetch_all(MYSQLI_ASSOC);

$sent    = 0;
$skipped = 0;

foreach ($tenants as $tenant) {
    $state = subscription_resolve($tenant);
    $kind  = notice_kind_for($state);

    if ($kind === null) {
        continue;
    }

    $tenantId = (int)$tenant['id'];

    // "Your access has ended" must be said ONCE, not every morning for the rest
    // of time. The per-day unique key alone would re-send it daily, so the
    // expiry notices additionally check whether they have ever been sent.
    //
    // They still fire again after a renewal, because subscription_activate()
    // clears this company's notice history when a new period starts.
    if (notice_is_one_shot($kind)) {
        $stmt = $control->prepare(
            "SELECT COUNT(*) AS c FROM notices_sent WHERE tenant_id = ? AND kind = ?"
        );
        $stmt->bind_param("is", $tenantId, $kind);
        $stmt->execute();
        $alreadyEver = (int)$stmt->get_result()->fetch_assoc()['c'] > 0;
        $stmt->close();

        if ($alreadyEver) {
            $skipped++;
            continue;
        }
    }

    // Claim the (tenant, kind, day) slot FIRST. If the insert is rejected by the
    // unique key, this notice already went out today and we send nothing.
    // Claiming before sending is what makes a concurrent second run harmless.
    $stmt = $control->prepare(
        "INSERT IGNORE INTO notices_sent (tenant_id, kind, sent_on) VALUES (?, ?, ?)"
    );
    $stmt->bind_param("iss", $tenantId, $kind, $today);
    $stmt->execute();
    $claimed = $stmt->affected_rows > 0;
    $stmt->close();

    if (!$claimed) {
        $skipped++;
        continue;
    }

    if (notify_subscription_notice($tenant, $kind)) {
        $sent++;
        echo "sent {$kind} to {$tenant['slug']} <{$tenant['contact_email']}>\n";
    } else {
        // Delivery failed. Release the slot so the next run can retry, rather
        // than silently never telling this customer their access is ending.
        $stmt = $control->prepare(
            "DELETE FROM notices_sent WHERE tenant_id = ? AND kind = ? AND sent_on = ?"
        );
        $stmt->bind_param("iss", $tenantId, $kind, $today);
        $stmt->execute();
        $stmt->close();

        echo "FAILED {$kind} for {$tenant['slug']}\n";
    }
}

echo "\ndone: {$sent} sent, {$skipped} already sent today, " . count($tenants) . " companies checked\n";

/**
 * Which reminder, if any, is due for a company today.
 *
 * Reads the same subscription_resolve() state the lockout and the customer's
 * billing page use, so a customer can never be emailed "3 days left" while the
 * system is actually locking them out.
 */
function notice_kind_for(array $state): ?string
{
    $days = $state['days_left'];

    // Thresholds are <=, not ==.
    //
    // With exact equality a missed run LOST the reminder rather than delaying it:
    // if the host was rebooting, a deploy failed, or CRON_KEY was wrong on the
    // one morning days_left happened to be 1, that customer was simply never
    // warned and discovered the lockout themselves.
    //
    // <= makes the notice due from the threshold onwards, and the notices_sent
    // table still guarantees it goes out only once. The most urgent applicable
    // notice wins, so a customer who is one day out does not also get the
    // three-day warning.
    return match ($state['status']) {
        'trial'         => $days <= 1 ? 'trial_1' : ($days <= 3 ? 'trial_3' : null),
        'active'        => $days <= 1 ? 'paid_1'  : ($days <= 7 ? 'paid_7'  : null),
        'trial_expired' => 'trial_expired',
        'expired'       => 'paid_expired',
        default         => null,
    };
}

/**
 * Notices that announce a state rather than count down to one. These are said
 * once per subscription period, not once per day.
 */
function notice_is_one_shot(string $kind): bool
{
    return in_array($kind, ['trial_expired', 'paid_expired'], true);
}
