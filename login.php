<?php
/**
 * SHEHITA Enterprise Management System
 * Login Page - Professional Redesign with Logo Support
 * 
 * ENHANCED: Professional logo display matching homepage.php
 * ENHANCED: Maintains all existing session and permission loading logic
 */

session_start();

// Redirect if already logged in
if (isset($_SESSION['email'])) {
    header("Location: homepage.php?page=home");
    exit();
}

// Include database configuration
$conn = require_once 'config.php';

$error = '';
$preserved_email = '';

// Determine logo path - dynamic based on current directory
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
    // Also check with ../ prefix
    if (file_exists('../' . $path)) {
        $logo_path = '../' . $path;
        break;
    }
}

// If no logo found, use text fallback
$logo_exists = !empty($logo_path);

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $preserved_email = htmlspecialchars($email);
    
    $errors = [];
    
    if (empty($email)) {
        $errors[] = "Email address is required";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address";
    }
    
    if (empty($password)) {
        $errors[] = "Password is required";
    }
    
    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT id, name, email, password, role_id, department_id, status FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            if (password_verify($password, $user['password'])) {
                if ($user['status'] === 'Active') {
                    // Set session variables
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['name'] = $user['name'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role_id'] = $user['role_id'];
                    $_SESSION['department_id'] = $user['department_id'];
                    
                    // PERMISSION: Load user permissions into session
                    loadUserPermissions($conn, $user['role_id']);
                    
                    header("Location: homepage.php?page=home");
                    exit();
                } else {
                    $errors[] = "Your account is inactive. Please contact the administrator.";
                }
            } else {
                $errors[] = "Invalid email or password";
            }
        } else {
            $errors[] = "Invalid email or password";
        }
        $stmt->close();
    }
    
    if (!empty($errors)) {
        $error = implode("<br>", $errors);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SHEHITA EMS - Login</title>
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
            max-width: 480px;
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

        /* Logo Container - White Background (matching homepage.php) */
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

        /* Fallback text logo when image not available */
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

        input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid var(--gray-200);
            border-radius: 12px;
            font-size: 15px;
            transition: all 0.3s;
            font-family: inherit;
        }

        input:focus {
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
            margin-bottom: 20px;
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

        .demo-credentials {
            background: var(--gray-50);
            padding: 16px;
            border-radius: 12px;
            margin-top: 20px;
            text-align: center;
            font-size: 13px;
            color: var(--gray-600);
            border: 1px solid var(--gray-200);
        }

        .demo-credentials strong {
            color: var(--brown-700);
        }

        .links-row {
            display: flex;
            justify-content: space-between;
            margin-top: 8px;
            margin-bottom: 20px;
        }

        .about-link a,
        .forgot-link a {
            color: var(--brown-700);
            text-decoration: none;
            font-weight: 500;
            font-size: 14px;
            transition: color 0.2s;
        }

        .about-link a:hover,
        .forgot-link a:hover {
            color: var(--brown-800);
            text-decoration: underline;
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
            
            .logo-text {
                font-size: 24px;
            }
            
            h2 {
                font-size: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="form-container">
            <!-- Logo Container - White Background (matching homepage.php) -->
            <div class="logo-container">
                <?php if ($logo_exists): ?>
                    <img src="<?= htmlspecialchars($logo_path) ?>" alt="SHEHITA" class="logo">
                <?php else: ?>
                    <div class="logo-text">SHEHITA</div>
                    <span class="logo-sub">Enterprise Management System</span>
                <?php endif; ?>
            </div>
            
            
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger">
                    <?= $error ?>
                    <button type="button" class="btn-close" onclick="this.parentElement.style.display='none'">&times;</button>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>">
                <div class="input-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" value="<?= $preserved_email ?>" placeholder="Enter your email address" required>
                </div>
                <div class="input-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="Enter your password" required>
                </div>
                <button type="submit" name="login">Login</button>
                <div class="links-row">
                    <div class="about-link"><a href="aboutus.php">About Us</a></div>
                    <div class="forgot-link"><a href="forgotpassword.php">Forgot Password?</a></div>
                </div>
            </form>
        </div>
    </div>
    <script>
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.transition = 'opacity 1s';
                alert.style.opacity = '0';
                setTimeout(() => alert.style.display = 'none', 1000);
            });
        }, 5000);
    </script>
</body>
</html>