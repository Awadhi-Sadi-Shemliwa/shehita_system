<?php
/**
 * SHEHITA EMS — Platform admin sign-in
 *
 * Authenticates SHEHITA staff against platform_admins in the control database.
 * Nothing to do with any company's users table.
 */

require_once __DIR__ . '/_auth.php';

$control = control_db();
$error   = '';

if (!empty($_SESSION['platform_admin_id'])) {
    header('Location: index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } else {
        // Simple throttle: five failures per session, then a cool-off.
        $_SESSION['admin_login_fails'] = (int)($_SESSION['admin_login_fails'] ?? 0);
        $lockedUntil = (int)($_SESSION['admin_login_locked_until'] ?? 0);

        if ($lockedUntil > time()) {
            $error = 'Too many failed attempts. Please wait a few minutes and try again.';
        } elseif ($email === '' || $password === '') {
            $error = 'Enter your email address and password.';
        } else {
            $stmt = $control->prepare(
                "SELECT id, name, email, password, role, status FROM platform_admins WHERE email = ? LIMIT 1"
            );
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $admin = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($admin !== null
                && $admin['status'] === 'Active'
                && password_verify($password, (string)$admin['password'])
            ) {
                // Fresh session id on privilege change, to close off fixation.
                session_regenerate_id(true);

                $_SESSION['platform_admin_id']    = (int)$admin['id'];
                $_SESSION['platform_admin_name']  = (string)$admin['name'];
                $_SESSION['platform_admin_email'] = (string)$admin['email'];
                $_SESSION['platform_admin_role']  = (string)$admin['role'];
                unset($_SESSION['admin_login_fails'], $_SESSION['admin_login_locked_until']);

                $control->query("UPDATE platform_admins SET last_login_at = NOW() WHERE id = " . (int)$admin['id']);
                platform_audit_log($control, (string)$admin['email'], 'admin.login', null, ['ip' => client_ip()]);

                header('Location: index.php');
                exit();
            }

            $_SESSION['admin_login_fails']++;
            if ($_SESSION['admin_login_fails'] >= 5) {
                $_SESSION['admin_login_locked_until'] = time() + 300;
                $_SESSION['admin_login_fails'] = 0;
            }

            // One message for every failure mode, so this cannot be used to
            // discover which email addresses are SHEHITA administrators.
            $error = 'Those details were not recognised.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SHEHITA Admin — Sign in</title>
<link rel="icon" href="../uploads/systemlogo/shehita-logo.jpg">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',system-ui,sans-serif;min-height:100vh;display:flex;align-items:center;
  justify-content:center;padding:24px;background:linear-gradient(135deg,#2a1f18 0%,#3e2b1f 50%,#7b583f 100%)}
.card{background:#fff;border-radius:16px;padding:38px 36px;max-width:410px;width:100%;
  box-shadow:0 20px 25px -5px rgb(0 0 0 / .3)}
.brand{text-align:center;margin-bottom:26px}
.brand .m{font-size:1.3rem;font-weight:700;color:#3e2b1f;letter-spacing:.05em}
.brand .s{font-size:.84rem;color:#64748b;margin-top:4px}
label{display:block;font-size:.84rem;font-weight:600;color:#334155;margin-bottom:5px}
input{width:100%;padding:11px 14px;border:1.5px solid #f0e8df;border-radius:9px;
  font-family:inherit;font-size:.95rem;color:#1e293b}
input:focus{outline:none;border-color:#9b7a5a}
.field{margin-bottom:16px}
.btn{width:100%;padding:13px;border:none;border-radius:9px;background:#7b583f;color:#fff;
  font-weight:600;font-size:.97rem;cursor:pointer;font-family:inherit;margin-top:8px}
.btn:hover{background:#5c3e2d}
.err{background:#fee2e2;color:#991b1b;padding:12px 15px;border-radius:9px;margin-bottom:18px;
  font-size:.9rem;border-left:3px solid #dc2626}
.note{text-align:center;margin-top:20px;font-size:.82rem;color:#94a3b8;line-height:1.5}
</style>
</head>
<body>
<div class="card">
  <div class="brand">
    <div class="m">SHEHITA ADMIN</div>
    <div class="s">Client &amp; subscription management</div>
  </div>

  <?php if ($error !== ''): ?>
    <div class="err"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
    <div class="field">
      <label for="email">Email address</label>
      <input type="email" name="email" id="email" required autofocus
             value="<?= htmlspecialchars((string)($_POST['email'] ?? '')) ?>">
    </div>
    <div class="field">
      <label for="password">Password</label>
      <input type="password" name="password" id="password" required>
    </div>
    <button class="btn" type="submit">Sign in</button>
  </form>

  <div class="note">This console manages every client company.<br>It is not a company login.</div>
</div>
</body>
</html>
