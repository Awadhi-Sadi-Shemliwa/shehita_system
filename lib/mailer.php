<?php
/**
 * SHEHITA EMS — Outbound email
 *
 * The whole subscription flow runs through email: the welcome message with the
 * portal link, trial reminders, the one-click payment confirmation link that
 * SHEHITA opens from their inbox, and the invoice that follows.
 *
 * Driver is chosen with MAIL_DRIVER:
 *   resend  (default) — HTTPS POST to the Resend API. Needs only curl, which
 *                       the php:8.2-apache image already has, so there is no
 *                       SMTP configuration and no MTA inside the container.
 *   smtp              — PHPMailer over SMTP, for running on someone else's mail.
 *   log               — write the whole message to the error log.
 *
 * With no API key configured the driver falls back to `log`, so local
 * development and the test flow work with zero setup: every email lands in
 * `docker compose logs web` where you can click the links out of it.
 *
 * IMPORTANT: this FAILS SOFT. A mail outage returns false and logs; it never
 * throws. Signup provisions a database and seeds an admin before it sends the
 * welcome message, and a mail failure must not tear that down half-finished.
 */

/**
 * @param string      $to      Recipient address.
 * @param string      $subject
 * @param string      $html    Full HTML body.
 * @param string|null $replyTo Set to the customer's address on admin
 *                             notifications, so hitting Reply reaches them.
 * @return bool                True when the provider accepted the message.
 */
function send_mail(string $to, string $subject, string $html, ?string $replyTo = null): bool
{
    $to = trim($to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log("send_mail: refusing to send to invalid address '{$to}'");
        return false;
    }

    $driver = strtolower(getenv('MAIL_DRIVER') ?: 'resend');
    $from   = getenv('MAIL_FROM') ?: 'SHEHITA EMS <no-reply@shehita.co.tz>';

    try {
        switch ($driver) {
            case 'resend':
                if ((getenv('RESEND_API_KEY') ?: '') === '') {
                    return send_mail_log($to, $subject, $html, 'no RESEND_API_KEY set');
                }
                return send_mail_resend($to, $subject, $html, $from, $replyTo);

            case 'smtp':
                return send_mail_smtp($to, $subject, $html, $from, $replyTo);

            case 'log':
            default:
                return send_mail_log($to, $subject, $html, "MAIL_DRIVER={$driver}");
        }
    } catch (Throwable $e) {
        error_log('send_mail: ' . $e->getMessage());
        return false;
    }
}

/**
 * Send several recipients the same message (e.g. every billing admin).
 * Returns true if at least one delivery was accepted.
 */
function send_mail_many(array $recipients, string $subject, string $html, ?string $replyTo = null): bool
{
    $anySent = false;
    foreach ($recipients as $recipient) {
        if (send_mail($recipient, $subject, $html, $replyTo)) {
            $anySent = true;
        }
    }

    return $anySent;
}

/**
 * SHEHITA staff who should receive payment-confirmation requests.
 * Comma-separated in BILLING_ADMIN_EMAILS.
 */
function billing_admin_emails(): array
{
    $raw = getenv('BILLING_ADMIN_EMAILS') ?: (getenv('PLATFORM_ADMIN_EMAIL') ?: '');
    $out = [];

    foreach (explode(',', $raw) as $candidate) {
        $candidate = trim($candidate);
        if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
            $out[] = $candidate;
        }
    }

    return $out;
}

// ---------------------------------------------------------------------------
// Drivers
// ---------------------------------------------------------------------------

function send_mail_resend(string $to, string $subject, string $html, string $from, ?string $replyTo): bool
{
    $payload = [
        'from'    => $from,
        'to'      => [$to],
        'subject' => $subject,
        'html'    => $html,
    ];
    if ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $payload['reply_to'] = $replyTo;
    }

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . getenv('RESEND_API_KEY'),
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);

    $body   = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error  = curl_error($ch);
    curl_close($ch);

    if ($body === false || $status < 200 || $status >= 300) {
        error_log("send_mail_resend: to={$to} status={$status} error={$error} body=" . substr((string)$body, 0, 500));
        return false;
    }

    return true;
}

/**
 * PHPMailer over SMTP. Only used when MAIL_DRIVER=smtp; the dependency is
 * optional so the app still runs without `composer install`.
 */
function send_mail_smtp(string $to, string $subject, string $html, string $from, ?string $replyTo): bool
{
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload)) {
        return send_mail_log($to, $subject, $html, 'MAIL_DRIVER=smtp but vendor/ is missing');
    }
    require_once $autoload;

    if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        return send_mail_log($to, $subject, $html, 'PHPMailer not installed');
    }

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = getenv('SMTP_HOST') ?: 'localhost';
    $mail->Port       = (int)(getenv('SMTP_PORT') ?: 587);
    $mail->SMTPAuth   = (getenv('SMTP_USER') ?: '') !== '';
    $mail->Username   = getenv('SMTP_USER') ?: '';
    $mail->Password   = getenv('SMTP_PASSWORD') ?: '';
    $mail->SMTPSecure = getenv('SMTP_SECURE') ?: 'tls';
    $mail->CharSet    = 'UTF-8';

    [$fromName, $fromAddress] = parse_mail_from($from);
    $mail->setFrom($fromAddress, $fromName);
    $mail->addAddress($to);
    if ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $mail->addReplyTo($replyTo);
    }

    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body    = $html;
    $mail->AltBody = trim(strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $html)));

    return $mail->send();
}

/**
 * The development driver: dump the message where `docker compose logs web`
 * will show it.
 *
 * Links are listed separately at the bottom. That is the whole point of this
 * driver: the payment-confirmation flow is driven by a link inside an <a href>,
 * and strip_tags() alone would throw the href away and leave you looking at the
 * words "Confirm this payment" with no way to actually do it.
 *
 * Set MAIL_LOG_FILE to also append messages to a file, which is easier to grep
 * than container stderr when you are stepping through the billing flow.
 */
function send_mail_log(string $to, string $subject, string $html, string $why): bool
{
    $text = trim(strip_tags(preg_replace('/<br\s*\/?>|<\/p>|<\/div>|<\/tr>/i', "\n", $html)));
    $text = preg_replace("/\n{3,}/", "\n\n", $text);

    $links = '';
    if (preg_match_all('/href="([^"]+)"/i', $html, $m)) {
        $unique = array_values(array_unique($m[1]));
        if ($unique !== []) {
            $links = "\n\nLinks in this email:\n";
            foreach ($unique as $i => $url) {
                $links .= '  [' . ($i + 1) . '] ' . html_entity_decode($url) . "\n";
            }
        }
    }

    $message =
        "\n==================== EMAIL (not sent: {$why}) ====================\n"
        . "To:      {$to}\n"
        . "Subject: {$subject}\n"
        . "------------------------------------------------------------------\n"
        . $text
        . $links
        . "\n==================================================================\n";

    error_log($message);

    $file = getenv('MAIL_LOG_FILE') ?: '';
    if ($file !== '') {
        @file_put_contents($file, $message, FILE_APPEND);
    }

    return true;
}

/**
 * "SHEHITA EMS <no-reply@shehita.co.tz>" -> ['SHEHITA EMS', 'no-reply@shehita.co.tz']
 */
function parse_mail_from(string $from): array
{
    if (preg_match('/^\s*(.*?)\s*<([^>]+)>\s*$/', $from, $m)) {
        return [trim($m[1], " \"'"), trim($m[2])];
    }

    return ['SHEHITA EMS', trim($from)];
}
