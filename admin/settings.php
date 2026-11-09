<?php
/**
 * SHEHITA EMS - Platform settings
 *
 * SHEHITA's own details: what appears on invoices, and the payment instructions
 * every client sees on their billing page. Kept in the control database rather
 * than environment variables so they can be changed without a redeploy.
 */

require_once __DIR__ . '/_auth.php';

$admin   = require_platform_admin();
$control = control_db();

$fields = [
    'payment_instructions' => [
        'label' => 'Payment instructions',
        'hint'  => 'Shown to every client on their billing page. Include your bank account and Lipa namba details.',
        'type'  => 'textarea',
    ],
    'shehita_address' => ['label' => 'SHEHITA address', 'hint' => 'Printed on invoices.', 'type' => 'text'],
    'shehita_email'   => ['label' => 'SHEHITA email',   'hint' => 'Printed on invoices.', 'type' => 'text'],
    'shehita_phone'   => ['label' => 'SHEHITA phone',   'hint' => 'Printed on invoices. Leave blank to omit.', 'type' => 'text'],
    'shehita_tin'     => ['label' => 'SHEHITA TIN',     'hint' => 'Printed on invoices. Leave blank to omit.', 'type' => 'text'],
];

$success = '';
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        foreach (array_keys($fields) as $key) {
            platform_setting_set($control, $key, trim((string)($_POST[$key] ?? '')));
        }
        platform_audit_log($control, $admin['email'], 'settings.updated', null, []);
        $success = 'Settings saved.';
    }
}

$defaults = [
    'payment_instructions' => "Pay by bank transfer or mobile money to SHEHITA, then use the form on this page to tell us.\nContact SHEHITA if you need our current account details.",
    'shehita_address'      => 'Dar es Salaam, Tanzania',
    'shehita_email'        => 'info@shehita.co.tz',
];

echo admin_head('Settings');
echo admin_nav($admin, 'settings.php');
?>
<div class="wrap">
    <h1>Platform settings</h1>
    <p class="lede">SHEHITA's own details, used on invoices and on every client's billing page.</p>

    <?php if ($errors !== []): ?>
        <div class="alert err"><ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <?php if ($success !== ''): ?>
        <div class="alert ok"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="card">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

            <?php foreach ($fields as $key => $meta):
                $value = platform_setting($control, $key, $defaults[$key] ?? '');
            ?>
                <div class="field">
                    <label for="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($meta['label']) ?></label>
                    <?php if ($meta['type'] === 'textarea'): ?>
                        <textarea name="<?= htmlspecialchars($key) ?>" id="<?= htmlspecialchars($key) ?>"
                                  rows="5"><?= htmlspecialchars($value) ?></textarea>
                    <?php else: ?>
                        <input type="text" name="<?= htmlspecialchars($key) ?>" id="<?= htmlspecialchars($key) ?>"
                               value="<?= htmlspecialchars($value) ?>">
                    <?php endif; ?>
                    <div class="muted" style="margin-top:5px;"><?= htmlspecialchars($meta['hint']) ?></div>
                </div>
            <?php endforeach; ?>

            <button class="btn" type="submit">Save settings</button>
        </form>
    </div>

    <div class="card">
        <h2>Where other settings live</h2>
        <p class="muted" style="margin-top:8px;">
            Mail delivery, the trial length, the Anthropic API key and the cron key are read from
            environment variables in <code>.env</code> on the server, because they are secrets or
            need to be set before the database is reachable. Changing them means editing
            <code>.env</code> and running <code>docker compose up -d</code>.
        </p>
    </div>
</div>
</body>
</html>
