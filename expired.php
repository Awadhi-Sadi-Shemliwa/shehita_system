<?php
/**
 * SHEHITA EMS — Subscription lapsed notice (staff view)
 *
 * Where homepage.php sends ordinary users once the trial or paid period has
 * ended. Their company's own admin goes to billing.php instead, so this page
 * deliberately shows no prices and no payment form — a staff member cannot act
 * on them, and putting them here would only invite calls to the wrong person.
 *
 * Reachable WITHOUT an active subscription (that is the entire point), so it
 * must never redirect back into homepage.php's gate.
 */

require_once __DIR__ . '/bootstrap.php';

// Signed-out visitors belong at the login page, not here.
if (!isset($_SESSION['email'])) {
    header('Location: login.php');
    exit();
}

// An admin who lands here — or anyone whose subscription came back while they
// were sitting on this page — should not be stranded.
if ($SUBSCRIPTION['is_active']) {
    header('Location: homepage.php?page=home');
    exit();
}
if ((int)($_SESSION['role_id'] ?? 0) === 1) {
    header('Location: billing.php');
    exit();
}

$logo_path    = tenant_logo_url();
$company_name = tenant_display_name();
$product_name = tenant_product_name();
$summary      = subscription_summary($SUBSCRIPTION);
$isTrial      = $SUBSCRIPTION['in_trial'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($product_name) ?> — Access paused</title>
    <?php if ($logo_path !== ''): ?>
    <link rel="icon" href="<?= htmlspecialchars($logo_path) ?>">
    <?php endif; ?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --brown-900: #3e2b1f; --brown-800: #5c3e2d; --brown-700: #7b583f;
            --brown-600: #9b7a5a; --brown-500: #b89b7e; --brown-400: #d4bfa8;
            --brown-300: #e8d9cc; --brown-200: #f0e8df; --brown-100: #f7f2ec;
        }

        body {
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
            padding: 24px; font-family: 'Inter', system-ui, sans-serif;
            background: linear-gradient(135deg, var(--brown-900) 0%, var(--brown-700) 50%, var(--brown-500) 100%);
        }

        .card {
            background: #fff; border-radius: 18px; padding: 44px 40px; max-width: 520px;
            width: 100%; text-align: center; box-shadow: 0 20px 25px -5px rgb(0 0 0 / .25);
        }

        .logo { max-height: 62px; max-width: 190px; object-fit: contain; margin-bottom: 8px; }
        .logo-text { font-size: 1.4rem; font-weight: 700; color: var(--brown-900); }

        .badge {
            display: inline-block; margin: 20px 0 18px; padding: 6px 15px; border-radius: 999px;
            background: var(--brown-100); color: var(--brown-800);
            font-size: .78rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase;
        }

        h1 { font-size: 1.42rem; color: var(--brown-900); margin-bottom: 12px; }
        p  { color: #475569; line-height: 1.68; margin-bottom: 14px; font-size: .97rem; }

        .reassure {
            background: var(--brown-100); border-left: 3px solid var(--brown-600);
            border-radius: 10px; padding: 14px 18px; text-align: left; margin: 22px 0;
        }
        .reassure p { margin: 0; font-size: .92rem; color: var(--brown-900); }

        .actions { display: flex; gap: 10px; justify-content: center; margin-top: 26px; flex-wrap: wrap; }
        .btn {
            padding: 11px 22px; border-radius: 9px; font-weight: 600; font-size: .93rem;
            text-decoration: none; border: 1.5px solid var(--brown-300); color: var(--brown-800);
            background: #fff; transition: background .15s, border-color .15s;
        }
        .btn:hover { background: var(--brown-100); border-color: var(--brown-500); }
    </style>
    <?php echo tenant_theme_css(); ?>
</head>
<body>
    <div class="card">
        <?php if ($logo_path !== ''): ?>
            <img src="<?= htmlspecialchars($logo_path) ?>" alt="<?= htmlspecialchars($company_name) ?>" class="logo">
        <?php else: ?>
            <div class="logo-text"><?= htmlspecialchars($product_name) ?></div>
        <?php endif; ?>

        <div class="badge"><?= $isTrial ? 'Trial ended' : 'Subscription expired' ?></div>

        <h1>Access is paused</h1>

        <p><?= htmlspecialchars($summary) ?></p>
        <p>
            To switch <?= htmlspecialchars($company_name) ?> back on, an administrator at your
            company needs to arrange payment with SHEHITA. Once that is confirmed, everyone
            here gets straight back in.
        </p>

        <div class="reassure">
            <p><strong>None of your work has been lost.</strong> Every customer, project and
            invoice is exactly where you left it and returns the moment the subscription
            is renewed.</p>
        </div>

        <div class="actions">
            <a class="btn" href="logout.php">Sign out</a>
        </div>
    </div>
</body>
</html>
