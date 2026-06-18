<?php
/**
 * SHEHITA Enterprise Management System
 * Forgot Password Page - Complete Password Recovery System
 * 
 * This page handles:
 * - Step 1: Email verification and security question display
 * - Step 2: Security answer verification
 * - Step 3: Password reset
 * - CSRF protection for all forms
 * - Rate limiting to prevent abuse
 * - Generic error messages for security
 */

session_start();

// Redirect if already logged in
if (isset($_SESSION['email'])) {
    header("Location: homepage.php?page=home");
    exit();
}

// Include database configuration
$conn = require_once 'config.php';

// CSRF Protection - Generate token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Rate limiting - Track attempts in session
if (!isset($_SESSION['forgot_attempts'])) {
    $_SESSION['forgot_attempts'] = 0;
    $_SESSION['last_attempt_time'] = time();
}

// Rate limiting function
function checkRateLimit() {
    $max_attempts = 5;
    $time_window = 900; // 15 minutes
    
    // Reset attempts if time window has passed
    if (time() - $_SESSION['last_attempt_time'] > $time_window) {
        $_SESSION['forgot_attempts'] = 0;
        $_SESSION['last_attempt_time'] = time();
        return true;
    }
    
    if ($_SESSION['forgot_attempts'] >= $max_attempts) {
        return false;
    }
    
    return true;
}

function incrementRateLimit() {
    $_SESSION['forgot_attempts']++;
    $_SESSION['last_attempt_time'] = time();
}

// Determine current step
$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$email = isset($_SESSION['reset_email']) ? $_SESSION['reset_email'] : '';
$security_question = '';
$user_id = 0;
$error = '';
$success = '';

// Step 1: Email Verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_email']) && $step == 1) {
    // CSRF Validation
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "Invalid form submission. Please try again.";
    } elseif (!checkRateLimit()) {
        $error = "Too many attempts. Please try again after 15 minutes.";
    } else {
        $email_input = trim($_POST['email']);
        
        if (empty($email_input)) {
            $error = "Email address is required";
        } elseif (!filter_var($email_input, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address";
        } else {
            // Check if email exists and get security question
            $stmt = $conn->prepare("SELECT id, security_question, security_answer FROM users WHERE email = ? AND status = 'Active'");
            $stmt->bind_param("s", $email_input);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();
                
                // Check if user has security question set
                if (empty($user['security_question']) || empty($user['security_answer'])) {
                    // Generic message for security
                    $error = "Password recovery is not available for this account. Please contact administrator.";
                    incrementRateLimit();
                } else {
                    // Store email and user_id in session for next steps
                    $_SESSION['reset_email'] = $email_input;
                    $_SESSION['reset_user_id'] = $user['id'];
                    $_SESSION['security_answer_hash'] = $user['security_answer'];
                    $security_question = $user['security_question'];
                    $step = 2;
                }
            } else {
                // Generic message - don't reveal if email exists or not
                $error = "If an account exists with this email, we will proceed with password recovery.";
                incrementRateLimit();
            }
            $stmt->close();
        }
    }
    
    // Generate new CSRF token
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Step 2: Security Question Verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_answer']) && $step == 2) {
    // CSRF Validation
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "Invalid form submission. Please try again.";
    } elseif (!checkRateLimit()) {
        $error = "Too many attempts. Please try again after 15 minutes.";
    } elseif (!isset($_SESSION['reset_email']) || !isset($_SESSION['reset_user_id']) || !isset($_SESSION['security_answer_hash'])) {
        $error = "Session expired. Please start over.";
        $step = 1;
        unset($_SESSION['reset_email'], $_SESSION['reset_user_id'], $_SESSION['security_answer_hash']);
    } else {
        $security_answer = trim($_POST['security_answer']);
        
        if (empty($security_answer)) {
            $error = "Please provide an answer to your security question";
        } else {
            // Verify the answer using password_verify (since it's hashed)
            if (password_verify($security_answer, $_SESSION['security_answer_hash'])) {
                // Answer correct - proceed to password reset
                $step = 3;
                // Clear rate limit counter on success
                $_SESSION['forgot_attempts'] = 0;
            } else {
                $error = "Incorrect answer. Please try again.";
                incrementRateLimit();
            }
        }
    }
    
    // Generate new CSRF token
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Step 3: Password Reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password']) && $step == 3) {
    // CSRF Validation
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "Invalid form submission. Please try again.";
    } elseif (!isset($_SESSION['reset_email']) || !isset($_SESSION['reset_user_id'])) {
        $error = "Session expired. Please start over.";
        $step = 1;
        unset($_SESSION['reset_email'], $_SESSION['reset_user_id'], $_SESSION['security_answer_hash']);
    } else {
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        $errors = [];
        
        if (empty($new_password)) {
            $errors[] = "New password is required";
        } elseif (strlen($new_password) < 6) {
            $errors[] = "Password must be at least 6 characters";
        }
        
        if ($new_password !== $confirm_password) {
            $errors[] = "Passwords do not match";
        }
        
        if (empty($errors)) {
            // Hash the new password
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $user_id = $_SESSION['reset_user_id'];
            
            // Update password in database
            $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $update_stmt->bind_param("si", $hashed_password, $user_id);
            
            if ($update_stmt->execute()) {
                $success = "Password has been reset successfully! Redirecting to login page...";
                
                // Clear session data
                unset($_SESSION['reset_email'], $_SESSION['reset_user_id'], $_SESSION['security_answer_hash']);
                $_SESSION['forgot_attempts'] = 0;
                
                // Redirect after 3 seconds
                echo '<meta http-equiv="refresh" content="3;url=login.php">';
            } else {
                $error = "An error occurred. Please try again.";
            }
            $update_stmt->close();
        } else {
            $error = implode("<br>", $errors);
        }
    }
    
    // Generate new CSRF token
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get security question for display in step 2
if ($step == 2 && isset($_SESSION['reset_email'])) {
    $stmt = $conn->prepare("SELECT security_question FROM users WHERE email = ?");
    $stmt->bind_param("s", $_SESSION['reset_email']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $security_question = $result->fetch_assoc()['security_question'];
    }
    $stmt->close();
}

// Determine logo path
$logo_path = '';
$possible_logo_paths = [
    'uploads/systemlogo/Shehita_Logo.png',
    'uploads/systemlogo/Shehita_Logo.jpg',
    'uploads/systemlogo/Shehita_Logo.jpeg',
    'uploads/systemlogo/logo.png',
    'uploads/systemlogo/logo.jpg'
];

foreach ($possible_logo_paths as $path) {
    if (file_exists($path)) {
        $logo_path = $path;
        break;
    }
    if (file_exists('../' . $path)) {
        $logo_path = '../' . $path;
        break;
    }
}

$logo_exists = !empty($logo_path);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SHEHITA EMS - Forgot Password</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --brown-900: #3e2b1f;
            --brown-800: #5c3e2d;
            --brown-700: #7b583f;
            --brown-600: #9b7a5a;
            --brown-500: #b89b7e;
            --brown-400: #d4bfa8;
            --brown-300: #e8d9cc;
            --brown-200: #f0e8df;
            --brown-100: #f7f2ec;
            --gray-50: #f8fafc;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e1;
            --gray-400: #94a3b8;
            --gray-500: #64748b;
            --gray-600: #475569;
            --gray-700: #334155;
            --gray-800: #1e293b;
            --gray-900: #0f172a;
            --shadow-sm: 0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
            --shadow-xl: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
        }

        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #3e2b1f 0%, #7b583f 50%, #b89b7e 100%);
            padding: 20px;
            font-family: 'Inter', sans-serif;
        }

        .container {
            width: 100%;
            max-width: 520px;
        }

        .form-container {
            background: white;
            border-radius: 24px;
            box-shadow: var(--shadow-xl);
            padding: 40px;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .form-container:hover {
            transform: translateY(-4px);
            box-shadow: 0 30px 40px -20px rgba(0, 0, 0, 0.3);
        }

        /* Logo Container */
        .logo-container {
            background: white;
            padding: 20px 24px;
            margin-bottom: 28px;
            border-radius: 16px;
            text-align: center;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            border: 1px solid var(--gray-100);
        }

        .logo-container:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.1);
        }

        .logo {
            max-width: 180px;
            max-height: 70px;
            width: auto;
            height: auto;
            object-fit: contain;
            display: block;
            margin: 0 auto;
        }

        .logo-text {
            font-family: 'Montserrat', sans-serif;
            font-size: 28px;
            font-weight: 800;
            background: linear-gradient(135deg, var(--brown-800) 0%, var(--brown-600) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -0.5px;
        }

        .logo-sub {
            font-size: 11px;
            color: var(--brown-500);
            display: block;
            margin-top: 6px;
            font-weight: 500;
            letter-spacing: 0.5px;
        }

        /* Step Indicators */
        .step-indicators {
            display: flex;
            justify-content: space-between;
            margin-bottom: 32px;
            position: relative;
        }

        .step-indicators::before {
            content: '';
            position: absolute;
            top: 20px;
            left: 0;
            right: 0;
            height: 2px;
            background: var(--gray-200);
            z-index: 0;
        }

        .step {
            text-align: center;
            position: relative;
            z-index: 1;
            background: white;
            padding: 0 12px;
        }

        .step-number {
            width: 40px;
            height: 40px;
            background: var(--gray-200);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 8px;
            font-weight: 700;
            color: var(--gray-600);
            transition: all 0.3s;
        }

        .step.active .step-number {
            background: linear-gradient(135deg, var(--brown-800), var(--brown-700));
            color: white;
            box-shadow: var(--shadow-md);
        }

        .step.completed .step-number {
            background: #10b981;
            color: white;
        }

        .step-label {
            font-size: 12px;
            font-weight: 500;
            color: var(--gray-500);
        }

        .step.active .step-label {
            color: var(--brown-700);
            font-weight: 600;
        }

        h2 {
            text-align: center;
            color: var(--gray-800);
            margin-bottom: 28px;
            font-size: 22px;
            font-weight: 700;
            font-family: 'Montserrat', sans-serif;
        }

        .input-group {
            margin-bottom: 20px;
        }

        .input-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--gray-700);
            font-weight: 500;
            font-size: 14px;
        }

        input, select {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid var(--gray-200);
            border-radius: 12px;
            font-size: 15px;
            transition: all 0.3s;
            font-family: inherit;
        }

        input:focus, select:focus {
            outline: none;
            border-color: var(--brown-600);
            box-shadow: 0 0 0 3px rgba(123, 88, 63, 0.1);
        }

        button {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, var(--brown-800), var(--brown-700));
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            font-family: inherit;
        }

        button:hover {
            background: linear-gradient(135deg, var(--brown-900), var(--brown-800));
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .alert {
            padding: 14px 16px;
            border-radius: 12px;
            margin-bottom: 24px;
            text-align: center;
            font-weight: 500;
            position: relative;
            font-size: 14px;
        }

        .alert-danger {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .alert-success {
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .alert-info {
            background: #eff6ff;
            color: #1e40af;
            border: 1px solid #bfdbfe;
        }

        .btn-close {
            position: absolute;
            top: 12px;
            right: 12px;
            background: transparent;
            border: none;
            font-size: 20px;
            cursor: pointer;
            opacity: 0.5;
            width: auto;
            padding: 0;
            margin: 0;
            color: inherit;
        }

        .btn-close:hover {
            opacity: 1;
            transform: none;
            box-shadow: none;
            background: transparent;
        }

        hr {
            margin: 24px 0;
            border: none;
            border-top: 1px solid var(--gray-200);
        }

        .back-link {
            text-align: center;
            margin-top: 20px;
        }

        .back-link a {
            color: var(--brown-700);
            text-decoration: none;
            font-weight: 500;
            font-size: 14px;
            transition: color 0.2s;
        }

        .back-link a:hover {
            color: var(--brown-800);
            text-decoration: underline;
        }

        .security-question-box {
            background: var(--brown-100);
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            border-left: 4px solid var(--brown-700);
        }

        .security-question-box p {
            color: var(--brown-800);
            font-weight: 600;
            margin-bottom: 4px;
            font-size: 13px;
        }

        .security-question-box .question {
            color: var(--gray-800);
            font-size: 15px;
        }

        @media (max-width: 480px) {
            .form-container {
                padding: 28px 20px;
            }
            
            .logo-container {
                padding: 16px 20px;
            }
            
            .logo {
                max-width: 140px;
                max-height: 55px;
            }
            
            .step-number {
                width: 32px;
                height: 32px;
                font-size: 14px;
            }
            
            .step-label {
                font-size: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="form-container">
            <!-- Logo Container -->
            <div class="logo-container">
                <?php if ($logo_exists): ?>
                    <img src="<?= htmlspecialchars($logo_path) ?>" alt="SHEHITA" class="logo">
                <?php else: ?>
                    <div class="logo-text">SHEHITA</div>
                    <span class="logo-sub">Enterprise Management System</span>
                <?php endif; ?>
            </div>

            <!-- Step Indicators -->
            <div class="step-indicators">
                <div class="step <?= $step >= 1 ? ($step > 1 ? 'completed' : 'active') : '' ?>">
                    <div class="step-number"><?= $step > 1 ? '✓' : '1' ?></div>
                    <div class="step-label">Email</div>
                </div>
                <div class="step <?= $step >= 2 ? ($step > 2 ? 'completed' : 'active') : '' ?>">
                    <div class="step-number"><?= $step > 2 ? '✓' : '2' ?></div>
                    <div class="step-label">Security</div>
                </div>
                <div class="step <?= $step >= 3 ? 'active' : '' ?>">
                    <div class="step-number">3</div>
                    <div class="step-label">Reset</div>
                </div>
            </div>

            <h2>
                <?php if ($step == 1): ?>
                    Reset Your Password
                <?php elseif ($step == 2): ?>
                    Verify Your Identity
                <?php else: ?>
                    Create New Password
                <?php endif; ?>
            </h2>

            <!-- Error/Success Messages -->
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger">
                    <?= $error ?>
                    <button type="button" class="btn-close" onclick="this.parentElement.style.display='none'">&times;</button>
                </div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="alert alert-success">
                    <?= $success ?>
                </div>
            <?php endif; ?>

            <!-- Step 1: Email Verification -->
            <?php if ($step == 1 && empty($success)): ?>
                <form method="POST" action="?step=1">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    
                    <div class="input-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" 
                               placeholder="Enter your registered email address" 
                               value="<?= htmlspecialchars($email) ?>" required>
                    </div>
                    
                    <button type="submit" name="verify_email">Continue</button>
                </form>
                
                <hr>
                
                <div class="back-link">
                    <a href="login.php">← Back to Login</a>
                </div>
            <?php endif; ?>

            <!-- Step 2: Security Question Verification -->
            <?php if ($step == 2 && empty($success)): ?>
                <div class="security-question-box">
                    <p>Security Question</p>
                    <div class="question"><?= htmlspecialchars($security_question) ?></div>
                </div>
                
                <form method="POST" action="?step=2">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    
                    <div class="input-group">
                        <label for="security_answer">Your Answer</label>
                        <input type="text" id="security_answer" name="security_answer" 
                               placeholder="Enter your security answer" required>
                    </div>
                    
                    <button type="submit" name="verify_answer">Verify & Continue</button>
                </form>
                
                <hr>
                
                <div class="back-link">
                    <a href="forgotpassword.php?step=1">← Start Over</a>
                </div>
            <?php endif; ?>

            <!-- Step 3: Password Reset -->
            <?php if ($step == 3 && empty($success)): ?>
                <form method="POST" action="?step=3">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    
                    <div class="input-group">
                        <label for="new_password">New Password</label>
                        <input type="password" id="new_password" name="new_password" 
                               placeholder="Enter new password (min. 6 characters)" required>
                    </div>
                    
                    <div class="input-group">
                        <label for="confirm_password">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" 
                               placeholder="Confirm your new password" required>
                    </div>
                    
                    <button type="submit" name="reset_password">Reset Password</button>
                </form>
                
                <hr>
                
                <div class="back-link">
                    <a href="login.php">← Back to Login</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.style.display = 'none', 500);
            });
        }, 5000);
    </script>
</body>
</html>