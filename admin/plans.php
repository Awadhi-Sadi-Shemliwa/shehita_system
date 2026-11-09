<?php
/**
 * SHEHITA EMS — Subscription packages
 *
 * Prices and periods SHEHITA offers. Editing a price here changes what new
 * customers are quoted on their billing page; it does NOT retroactively alter
 * any period already paid for, because subscription_payments stores the amount
 * and dates at the moment of activation.
 */

require_once __DIR__ . '/_auth.php';

$admin   = require_platform_admin();
$control = control_db();

$errors  = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (($_POST['action'] ?? '') === 'save') {
        $codes  = (array)($_POST['code'] ?? []);
        $names  = (array)($_POST['name'] ?? []);
        $days   = (array)($_POST['days'] ?? []);
        $prices = (array)($_POST['price'] ?? []);
        $active = (array)($_POST['active'] ?? []);

        $stmt = $control->prepare(
            "UPDATE plans SET name = ?, days = ?, price = ?, active = ? WHERE code = ?"
        );

        foreach ($codes as $i => $code) {
            $code    = (string)$code;
            $name    = trim((string)($names[$i] ?? ''));
            $dayCnt  = (int)($days[$i] ?? 0);
            $price   = (float)($prices[$i] ?? 0);
            $isOn    = isset($active[$code]) ? 1 : 0;

            if ($name === '' || $dayCnt < 1) {
                $errors[] = "“{$code}” needs a name and a period of at least one day.";
                continue;
            }

            $stmt->bind_param("sidis", $name, $dayCnt, $price, $isOn, $code);
            $stmt->execute();
        }
        $stmt->close();

        if ($errors === []) {
            $success = 'Packages updated.';
            platform_audit_log($control, $admin['email'], 'plans.updated', null, []);
        }
    } elseif (($_POST['action'] ?? '') === 'add') {
        $code  = strtolower(trim((string)($_POST['new_code'] ?? '')));
        $name  = trim((string)($_POST['new_name'] ?? ''));
        $days  = (int)($_POST['new_days'] ?? 0);
        $price = (float)($_POST['new_price'] ?? 0);

        if (!preg_match('/^[a-z0-9_]{2,32}$/', $code)) {
            $errors[] = 'The package code should be 2-32 lowercase letters, numbers or underscores.';
        } elseif ($name === '' || $days < 1) {
            $errors[] = 'Give the package a name and a period of at least one day.';
        } elseif (plan_by_code($control, $code) !== null) {
            $errors[] = 'A package with that code already exists.';
        } else {
            $stmt = $control->prepare(
                "INSERT INTO plans (code, name, days, price, currency, sort_order, active)
                 VALUES (?, ?, ?, ?, 'TZS', 99, 1)"
            );
            $stmt->bind_param("ssid", $code, $name, $days, $price);
            $stmt->execute();
            $stmt->close();

            $success = 'Package added.';
        }
    }
}

$plans = $control->query("SELECT * FROM plans ORDER BY sort_order, days")->fetch_all(MYSQLI_ASSOC);

echo admin_head('Packages');
echo admin_nav($admin, 'plans.php');
?>
<div class="wrap">
    <h1>Subscription packages</h1>
    <p class="lede">
        What clients can pay for. These prices show on every company's billing page.
    </p>

    <?php if ($errors !== []): ?>
        <div class="alert err"><ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <?php if ($success !== ''): ?>
        <div class="alert ok"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="card">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="save">

            <div style="overflow-x:auto;">
            <table>
                <thead><tr><th>Code</th><th>Name</th><th>Days</th><th>Price</th><th>Offered</th></tr></thead>
                <tbody>
                <?php foreach ($plans as $plan): ?>
                    <tr>
                        <td class="muted"><code><?= htmlspecialchars((string)$plan['code']) ?></code>
                            <input type="hidden" name="code[]" value="<?= htmlspecialchars((string)$plan['code']) ?>"></td>
                        <td><input type="text" name="name[]" value="<?= htmlspecialchars((string)$plan['name']) ?>" required></td>
                        <td><input type="number" name="days[]" min="1" value="<?= (int)$plan['days'] ?>" required style="max-width:100px;"></td>
                        <td><input type="number" step="0.01" min="0" name="price[]" value="<?= htmlspecialchars((string)(float)$plan['price']) ?>" style="max-width:140px;"></td>
                        <td><input type="checkbox" name="active[<?= htmlspecialchars((string)$plan['code']) ?>]"
                                   <?= (int)$plan['active'] === 1 ? 'checked' : '' ?> style="width:auto;"></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <button class="btn" type="submit" style="margin-top:16px;">Save changes</button>
        </form>
    </div>

    <div class="card">
        <h2>Add a package</h2>
        <form method="POST" style="margin-top:14px;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="add">

            <div class="grid3">
                <div class="field">
                    <label for="new_code">Code</label>
                    <input type="text" name="new_code" id="new_code" placeholder="two_year" required>
                </div>
                <div class="field">
                    <label for="new_name">Name</label>
                    <input type="text" name="new_name" id="new_name" placeholder="Two years" required>
                </div>
                <div class="field">
                    <label for="new_days">Days</label>
                    <input type="number" name="new_days" id="new_days" min="1" placeholder="730" required>
                </div>
                <div class="field">
                    <label for="new_price">Price (TZS)</label>
                    <input type="number" step="0.01" min="0" name="new_price" id="new_price" placeholder="900000">
                </div>
            </div>

            <button class="btn ghost" type="submit">Add package</button>
        </form>
    </div>
</div>
</body>
</html>
