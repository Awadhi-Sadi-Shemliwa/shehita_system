<?php
/**
 * SHEHITA EMS — Create a company account
 *
 * The front door. A company types their name, uploads a logo, and gets their
 * own Enterprise Management System at:
 *
 *     https://system.shehita.co.tz/<their-name>/login.php
 *
 * carrying their name, their logo, and brand colours read out of that logo.
 * Their 14-day free trial starts immediately — SHEHITA can suspend or close the
 * account afterwards from the admin console, but nobody has to approve a signup
 * for it to work.
 *
 * Note this is a PLATFORM page: it deliberately does not include bootstrap.php,
 * because there is no tenant yet. That is also why /signup is in RESERVED_SLUGS
 * and in the passthrough rule at the top of .htaccess.
 */

require_once __DIR__ . '/lib/util.php';

platform_boot();
platform_session_start('SHEMS_PLATFORM_SIGNUP', '/');

require_once __DIR__ . '/lib/control_db.php';
require_once __DIR__ . '/lib/provision.php';
require_once __DIR__ . '/lib/mailer.php';
require_once __DIR__ . '/lib/email_templates.php';

$control = control_db();

$errors = [];
$done   = null;

// Sticky values, so a rejected form does not make people retype everything.
$form = [
    'company_name' => '',
    'slug'         => '',
    'admin_name'   => '',
    'admin_email'  => '',
    'contact_phone'=> '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($form) as $key) {
        $form[$key] = trim((string)($_POST[$key] ?? ''));
    }

    // A field hidden from people but visible to naive bots.
    $honeypot = trim((string)($_POST['website'] ?? ''));

    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($honeypot !== '') {
        // Silently behave as though it worked; do not tell a bot why it failed.
        $errors[] = 'We could not process that request.';
    } else {
        // Derive the address from the company name when it was left blank. Done
        // HERE rather than inside the validator so the value actually reaches
        // provision_tenant() below.
        if ($form['slug'] === '' && $form['company_name'] !== '') {
            $form['slug'] = slugify_company($form['company_name']);
        }

        $errors = array_merge($errors, validate_signup($control, $form));

        // --- rate limit ------------------------------------------------------
        // Each signup creates a database, so this endpoint has real cost.
        if ($errors === []) {
            $ip = client_ip();
            $stmt = $control->prepare(
                "SELECT COUNT(*) AS c FROM signup_attempts
                  WHERE ip = ? AND created_at > (NOW() - INTERVAL 1 HOUR)"
            );
            $stmt->bind_param("s", $ip);
            $stmt->execute();
            $recent = (int)$stmt->get_result()->fetch_assoc()['c'];
            $stmt->close();

            if ($recent >= 5) {
                $errors[] = 'Too many accounts have been created from this connection recently. '
                          . 'Please try again later, or contact SHEHITA for help.';
            }
        }

        // --- logo -------------------------------------------------------------
        $logoExtension = '';
        if ($errors === []) {
            $logoCheck = validate_logo_upload($_FILES['logo'] ?? []);
            if (!$logoCheck['ok']) {
                $errors[] = $logoCheck['error'];
            } else {
                $logoExtension = $logoCheck['extension'];
            }
        }

        // --- provision --------------------------------------------------------
        if ($errors === []) {
            $ip = client_ip();
            $stmt = $control->prepare("INSERT INTO signup_attempts (ip, slug, succeeded) VALUES (?, ?, 0)");
            $stmt->bind_param("ss", $ip, $form['slug']);
            $stmt->execute();
            $attemptId = (int)$stmt->insert_id;
            $stmt->close();

            $result = provision_tenant($control, [
                'company_name'   => $form['company_name'],
                'slug'           => $form['slug'],
                'admin_name'     => $form['admin_name'],
                'admin_email'    => $form['admin_email'],
                'admin_password' => (string)($_POST['admin_password'] ?? ''),
                'contact_phone'  => $form['contact_phone'],
                'logo_tmp_path'  => $_FILES['logo']['tmp_name'] ?? null,
                'logo_extension' => $logoExtension,
            ]);

            if (!$result['ok']) {
                $errors[] = $result['error'];
            } else {
                $control->query("UPDATE signup_attempts SET succeeded = 1 WHERE id = " . $attemptId);

                $tenant = tenant_by_id($control, $result['tenant_id']);
                notify_welcome($tenant, $form['admin_email'], $result['portal_url']);

                $done = [
                    'portal_url' => $result['portal_url'],
                    'company'    => $form['company_name'],
                    'email'      => $form['admin_email'],
                    'trial_days' => (int)$tenant['trial_days'],
                ];
            }
        }
    }
}

/**
 * @return string[] Human-readable problems, empty when the form is acceptable.
 */
function validate_signup(mysqli $control, array $form): array
{
    $errors = [];

    if ($form['company_name'] === '' || mb_strlen($form['company_name']) < 2) {
        $errors[] = 'Please enter your company name.';
    } elseif (mb_strlen($form['company_name']) > 255) {
        $errors[] = 'That company name is too long.';
    }

    // The caller has already derived the slug from the company name if it was
    // left blank, so by this point it should always be populated.
    $reason = slug_rejection_reason($form['slug'], $control);
    if ($reason !== '') {
        $errors[] = $reason;
    }

    if ($form['admin_name'] === '') {
        $errors[] = 'Please enter the administrator\'s full name.';
    }

    if (!filter_var($form['admin_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address — this is where your login details go.';
    }

    $password = (string)($_POST['admin_password'] ?? '');
    $confirm  = (string)($_POST['admin_password_confirm'] ?? '');

    if (strlen($password) < 8) {
        $errors[] = 'Choose a password of at least 8 characters.';
    } elseif ($password !== $confirm) {
        $errors[] = 'The two passwords do not match.';
    }

    return $errors;
}

// Make the derived slug available back to the form.
if ($form['slug'] === '' && $form['company_name'] !== '') {
    $form['slug'] = slugify_company($form['company_name']);
}

$baseUrl = rtrim(getenv('APP_BASE_URL') ?: 'https://system.shehita.co.tz', '/');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create your company account — SHEHITA EMS</title>
    <link rel="icon" href="uploads/systemlogo/shehita-logo.jpg">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --brown-900: #3e2b1f; --brown-800: #5c3e2d; --brown-700: #7b583f;
            --brown-600: #9b7a5a; --brown-500: #b89b7e; --brown-400: #d4bfa8;
            --brown-300: #e8d9cc; --brown-200: #f0e8df; --brown-100: #f7f2ec;
            --gray-500: #64748b; --gray-700: #334155; --gray-800: #1e293b;
        }

        body {
            font-family: 'Inter', system-ui, sans-serif; min-height: 100vh; padding: 34px 16px 70px;
            background: linear-gradient(135deg, var(--brown-900) 0%, var(--brown-700) 55%, var(--brown-500) 100%);
            color: var(--gray-800);
        }

        .wrap { max-width: 640px; margin: 0 auto; }

        .brand { text-align: center; margin-bottom: 22px; }
        .brand .mark { color: #fff; font-size: 1.35rem; font-weight: 700; letter-spacing: .06em; }
        .brand .sub  { color: rgba(255,255,255,.8); font-size: .9rem; margin-top: 5px; }

        .card {
            background: #fff; border-radius: 18px; padding: 34px 34px 30px;
            box-shadow: 0 20px 25px -5px rgb(0 0 0 / .22);
        }

        h1 { font-size: 1.32rem; color: var(--brown-900); margin-bottom: 7px; }
        .lede { color: var(--gray-500); font-size: .94rem; line-height: 1.62; margin-bottom: 24px; }

        .section-label {
            font-size: .76rem; font-weight: 700; letter-spacing: .07em; text-transform: uppercase;
            color: var(--brown-600); margin: 26px 0 12px; padding-bottom: 6px;
            border-bottom: 1px solid var(--brown-200);
        }
        .section-label:first-of-type { margin-top: 4px; }

        label { display: block; font-size: .85rem; font-weight: 600; color: var(--gray-700); margin-bottom: 5px; }
        .hint { font-size: .81rem; color: var(--gray-500); margin-top: 5px; line-height: 1.5; }

        input[type=text], input[type=email], input[type=password], input[type=tel] {
            width: 100%; padding: 11px 14px; border: 1.5px solid var(--brown-200);
            border-radius: 9px; font-family: inherit; font-size: .95rem; color: var(--gray-800);
            transition: border-color .15s;
        }
        input:focus { outline: none; border-color: var(--brown-600); }

        .field { margin-bottom: 17px; }
        .grid2 { display: grid; gap: 15px; grid-template-columns: 1fr 1fr; }
        @media (max-width: 560px) { .grid2 { grid-template-columns: 1fr; } }

        .url-preview {
            margin-top: 8px; padding: 10px 13px; background: var(--brown-100);
            border-radius: 8px; font-size: .87rem; color: var(--brown-800);
            word-break: break-all; border-left: 3px solid var(--brown-500);
        }
        .url-preview strong { color: var(--brown-900); }

        .drop {
            border: 2px dashed var(--brown-300); border-radius: 12px; padding: 22px;
            text-align: center; cursor: pointer; transition: border-color .15s, background .15s;
            background: var(--brown-100);
        }
        .drop:hover { border-color: var(--brown-600); }
        .drop input { display: none; }
        .drop .icon { font-size: 1.7rem; }
        .drop .text { font-size: .89rem; color: var(--gray-700); margin-top: 7px; font-weight: 600; }
        .drop .sub  { font-size: .8rem; color: var(--gray-500); margin-top: 3px; }
        #logoPreview { max-height: 84px; max-width: 190px; object-fit: contain; margin-bottom: 9px; display: none; }

        .honeypot { position: absolute; left: -9999px; opacity: 0; height: 0; width: 0; }

        .btn {
            width: 100%; padding: 14px; border: none; border-radius: 10px; margin-top: 24px;
            background: var(--brown-700); color: #fff; font-weight: 600; font-size: 1rem;
            cursor: pointer; font-family: inherit; transition: background .15s;
        }
        .btn:hover { background: var(--brown-800); }

        .alert { padding: 14px 17px; border-radius: 10px; margin-bottom: 20px; font-size: .92rem; line-height: 1.6; }
        .alert.err { background: #fee2e2; color: #991b1b; border-left: 3px solid #dc2626; }
        .alert ul { margin: 7px 0 0 18px; }

        .foot { text-align: center; margin-top: 20px; font-size: .88rem; color: rgba(255,255,255,.85); }
        .foot a { color: #fff; font-weight: 600; }

        /* success state */
        .done { text-align: center; }
        .done .tick {
            width: 58px; height: 58px; border-radius: 50%; background: #dcfce7; color: #166534;
            display: flex; align-items: center; justify-content: center; font-size: 1.8rem;
            margin: 0 auto 18px;
        }
        .portal-link {
            display: block; margin: 20px 0; padding: 15px; border-radius: 10px;
            background: var(--brown-100); border: 1.5px solid var(--brown-300);
            color: var(--brown-900); font-weight: 600; text-decoration: none; word-break: break-all;
        }
        .portal-link:hover { border-color: var(--brown-600); }
        .done .note { color: var(--gray-500); font-size: .9rem; line-height: 1.65; }
    </style>
</head>
<body>
<div class="wrap">

    <div class="brand">
        <div class="mark">SHEHITA EMS</div>
        <div class="sub">Enterprise Management System</div>
    </div>

    <div class="card">

    <?php if ($done !== null): ?>

        <div class="done">
            <div class="tick">&#10003;</div>
            <h1>Welcome, <?= htmlspecialchars($done['company']) ?></h1>
            <p class="lede" style="margin-bottom:0;">
                Your system is ready and your <?= (int)$done['trial_days'] ?>-day free trial has started.
            </p>

            <a class="portal-link" href="<?= htmlspecialchars($done['portal_url']) ?>">
                <?= htmlspecialchars($done['portal_url']) ?>
            </a>

            <p class="note">
                Sign in as <strong><?= htmlspecialchars($done['email']) ?></strong> with the password
                you just chose. We have also emailed you this link — keep it, it is the easiest way
                back to your portal.
            </p>
        </div>

    <?php else: ?>

        <h1>Create your company account</h1>
        <p class="lede">
            Tell us your company name and upload your logo. We will build your own management
            system — with your branding — and start a <?= (int)default_trial_days() ?>-day free trial
            straight away. No payment now.
        </p>

        <?php if ($errors !== []): ?>
            <div class="alert err">
                <strong>Please check the following:</strong>
                <ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" id="signupForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

            <!-- bots fill this in; people never see it -->
            <div class="honeypot" aria-hidden="true">
                <label for="website">Website</label>
                <input type="text" name="website" id="website" tabindex="-1" autocomplete="off">
            </div>

            <div class="section-label">Your company</div>

            <div class="field">
                <label for="company_name">Company name</label>
                <input type="text" name="company_name" id="company_name" required maxlength="255"
                       value="<?= htmlspecialchars($form['company_name']) ?>"
                       placeholder="e.g. Acme Traders">
            </div>

            <div class="field">
                <label for="slug">Your web address</label>
                <input type="text" name="slug" id="slug" required maxlength="39"
                       value="<?= htmlspecialchars($form['slug']) ?>"
                       pattern="[a-z0-9][a-z0-9\-]{1,38}"
                       placeholder="acmetraders">
                <div class="url-preview">
                    Your team will sign in at<br>
                    <?= htmlspecialchars($baseUrl) ?>/<strong id="slugEcho"><?= htmlspecialchars($form['slug'] ?: 'your-company') ?></strong>/login.php
                </div>
                <div class="hint">Lowercase letters, numbers and hyphens. This cannot be changed later.</div>
            </div>

            <div class="field">
                <label for="logo">Company logo</label>
                <label class="drop" for="logo">
                    <img id="logoPreview" alt="Logo preview">
                    <div class="icon" id="dropIcon">&#128247;</div>
                    <div class="text" id="dropText">Choose your logo</div>
                    <div class="sub">PNG, JPG or WebP — up to 5 MB</div>
                    <input type="file" name="logo" id="logo" accept="image/png,image/jpeg,image/gif,image/webp" required>
                </label>
                <div class="hint">
                    We read your brand colours straight out of this image, so your system looks like
                    your company from the first login. You can adjust the colours later.
                </div>
            </div>

            <div class="section-label">Your administrator account</div>

            <div class="field">
                <label for="admin_name">Full name</label>
                <input type="text" name="admin_name" id="admin_name" required maxlength="255"
                       value="<?= htmlspecialchars($form['admin_name']) ?>">
            </div>

            <div class="grid2">
                <div class="field">
                    <label for="admin_email">Email address</label>
                    <input type="email" name="admin_email" id="admin_email" required maxlength="255"
                           value="<?= htmlspecialchars($form['admin_email']) ?>">
                </div>
                <div class="field">
                    <label for="contact_phone">Phone (optional)</label>
                    <input type="tel" name="contact_phone" id="contact_phone" maxlength="50"
                           value="<?= htmlspecialchars($form['contact_phone']) ?>">
                </div>
            </div>

            <div class="grid2">
                <div class="field">
                    <label for="admin_password">Password</label>
                    <input type="password" name="admin_password" id="admin_password" required minlength="8">
                </div>
                <div class="field">
                    <label for="admin_password_confirm">Confirm password</label>
                    <input type="password" name="admin_password_confirm" id="admin_password_confirm" required minlength="8">
                </div>
            </div>

            <button class="btn" type="submit">Create my system</button>
        </form>

    <?php endif; ?>

    </div>

    <div class="foot">
        Already have an account? Go to <a href="<?= htmlspecialchars($baseUrl) ?>/your-company/login.php">your company address</a>.
    </div>
</div>

<script>
(function () {
    var nameInput = document.getElementById('company_name');
    if (!nameInput) { return; }

    var slugInput = document.getElementById('slug');
    var slugEcho  = document.getElementById('slugEcho');
    var slugTouched = slugInput.value !== '';

    // Two variants on purpose.
    //
    // `slugify` is for deriving a slug from the company name, and trims the
    // hyphens at both ends. `slugifyTyping` deliberately KEEPS a trailing hyphen,
    // because it runs on every keystroke in the address field: trimming there
    // deleted the '-' the instant you typed it, so "my-company" was physically
    // impossible to enter. The trailing hyphen is cleaned up on blur and before
    // submit instead.
    function slugify(value) {
        return slugifyTyping(value).replace(/^-+|-+$/g, '');
    }

    function slugifyTyping(value) {
        return value.toLowerCase()
            .replace(/[^a-z0-9-]+/g, '-')
            .replace(/-{2,}/g, '-')
            .replace(/^-+/, '')
            .slice(0, 39);
    }

    function echoSlug() {
        slugEcho.textContent = slugInput.value || 'your-company';
    }

    // Derive the address from the company name until the person edits it
    // themselves -- after that, leave their choice alone.
    nameInput.addEventListener('input', function () {
        if (!slugTouched) {
            slugInput.value = slugify(nameInput.value);
            echoSlug();
        }
    });

    slugInput.addEventListener('input', function () {
        slugTouched = true;
        slugInput.value = slugifyTyping(slugInput.value);   // keeps a trailing '-'
        echoSlug();
    });

    // Tidy the trailing hyphen only once the user has finished with the field.
    slugInput.addEventListener('blur', function () {
        slugInput.value = slugify(slugInput.value);
        echoSlug();
    });

    document.getElementById('signupForm').addEventListener('submit', function () {
        slugInput.value = slugify(slugInput.value);
    });

    // Show the chosen logo so people can see they picked the right file.
    document.getElementById('logo').addEventListener('change', function () {
        var file = this.files && this.files[0];
        if (!file) { return; }

        var preview = document.getElementById('logoPreview');
        var reader = new FileReader();
        reader.onload = function (e) {
            preview.src = e.target.result;
            preview.style.display = 'block';
            document.getElementById('dropIcon').style.display = 'none';
            document.getElementById('dropText').textContent = file.name;
        };
        reader.readAsDataURL(file);
    });
})();
</script>
</body>
</html>
