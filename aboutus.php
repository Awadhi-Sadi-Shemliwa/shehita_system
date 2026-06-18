<?php
/**
 * SHEHITA Enterprise Management System
 * About Us Page - Professional Company Information
 * 
 * This page provides:
 * - Company overview and mission statement
 * - Key features of the EMS system
 * - Contact information and support details
 * - Professional branding consistent with SHEHITA identity
 */

session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SHEHITA EMS - About Us | Enterprise Management Solutions</title>
    <meta name="description" content="SHEHITA Enterprise Management System - Comprehensive business management solution for modern enterprises.">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
            background: linear-gradient(135deg, var(--brown-900) 0%, var(--brown-700) 50%, var(--brown-500) 100%);
            padding: 40px 20px;
            font-family: 'Inter', sans-serif;
        }

        .container {
            width: 100%;
            max-width: 1280px;
            margin: 0 auto;
        }

        /* Main Card */
        .about-card {
            background: white;
            border-radius: 32px;
            box-shadow: var(--shadow-xl);
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .about-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 30px 50px -25px rgba(0, 0, 0, 0.3);
        }

        /* Hero Header */
        .about-header {
            background: linear-gradient(135deg, var(--brown-800) 0%, var(--brown-700) 50%, var(--brown-600) 100%);
            padding: 60px 60px 50px 60px;
            text-align: center;
            color: white;
            position: relative;
            overflow: hidden;
        }

        .about-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 300px;
            height: 300px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 50%;
            pointer-events: none;
        }

        .about-header::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -10%;
            width: 250px;
            height: 250px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 50%;
            pointer-events: none;
        }

        /* Logo Container - White Background (matching login.php) */
        .logo-container {
            background: white;
            padding: 20px 24px;
            margin-bottom: 32px;
            border-radius: 20px;
            text-align: center;
            transition: all 0.3s ease;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.1);
            display: inline-block;
            width: auto;
            position: relative;
            z-index: 2;
        }

        .logo-container:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
        }

        .header-logo {
            max-width: 180px;
            max-height: 70px;
            width: auto;
            height: auto;
            object-fit: contain;
            display: block;
            margin: 0 auto;
        }

        /* Fallback text logo */
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
            font-size: 10px;
            color: var(--brown-500);
            display: block;
            margin-top: 4px;
            font-weight: 500;
            letter-spacing: 0.5px;
        }

        .about-header h1 {
            font-family: 'Montserrat', sans-serif;
            font-size: 42px;
            font-weight: 800;
            margin-bottom: 16px;
            letter-spacing: -0.02em;
            position: relative;
            z-index: 1;
        }

        .about-header .tagline {
            font-size: 18px;
            opacity: 0.9;
            font-weight: 400;
            position: relative;
            z-index: 1;
        }

        /* Content Area */
        .about-content {
            padding: 60px;
        }

        /* Mission Section */
        .mission-section {
            text-align: center;
            margin-bottom: 60px;
        }

        .mission-badge {
            display: inline-block;
            background: var(--brown-100);
            color: var(--brown-800);
            padding: 6px 16px;
            border-radius: 50px;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 20px;
            letter-spacing: 0.5px;
        }

        .mission-section h2 {
            font-family: 'Montserrat', sans-serif;
            color: var(--gray-800);
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 24px;
        }

        .mission-text {
            max-width: 800px;
            margin: 0 auto;
            color: var(--gray-600);
            font-size: 16px;
            line-height: 1.8;
        }

        .mission-text p {
            margin-bottom: 16px;
        }

        /* Stats Section */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 30px;
            margin: 60px 0;
            padding: 40px 0;
            border-top: 1px solid var(--gray-200);
            border-bottom: 1px solid var(--gray-200);
        }

        .stat-card {
            text-align: center;
        }

        .stat-number {
            font-family: 'Montserrat', sans-serif;
            font-size: 42px;
            font-weight: 800;
            color: var(--brown-700);
            margin-bottom: 8px;
        }

        .stat-label {
            font-size: 14px;
            color: var(--gray-500);
            font-weight: 500;
        }

        /* Features Section */
        .features-section {
            margin: 60px 0;
        }

        .section-title {
            text-align: center;
            font-family: 'Montserrat', sans-serif;
            font-size: 28px;
            font-weight: 700;
            color: var(--gray-800);
            margin-bottom: 16px;
        }

        .section-subtitle {
            text-align: center;
            color: var(--gray-500);
            margin-bottom: 48px;
            font-size: 16px;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
        }

        .feature-card {
            background: var(--gray-50);
            padding: 32px 28px;
            border-radius: 20px;
            text-align: center;
            transition: all 0.3s ease;
            border: 1px solid var(--gray-200);
        }

        .feature-card:hover {
            transform: translateY(-6px);
            box-shadow: var(--shadow-lg);
            border-color: var(--brown-300);
        }

        .feature-icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, var(--brown-100), var(--brown-200));
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
        }

        .feature-icon i {
            font-size: 32px;
            color: var(--brown-700);
        }

        .feature-card h3 {
            font-family: 'Montserrat', sans-serif;
            color: var(--gray-800);
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 12px;
        }

        .feature-card p {
            color: var(--gray-600);
            font-size: 14px;
            line-height: 1.6;
        }

        /* Technology Stack */
        .tech-section {
            background: var(--gray-50);
            border-radius: 24px;
            padding: 40px;
            margin: 60px 0;
            text-align: center;
            border: 1px solid var(--gray-200);
        }

        .tech-section h3 {
            font-family: 'Montserrat', sans-serif;
            font-size: 22px;
            font-weight: 700;
            color: var(--gray-800);
            margin-bottom: 24px;
        }

        .tech-icons {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 32px;
        }

        .tech-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
        }

        .tech-item i {
            font-size: 36px;
            color: var(--brown-600);
        }

        .tech-item span {
            font-size: 13px;
            font-weight: 500;
            color: var(--gray-600);
        }

        /* Contact Section */
        .contact-section {
            background: linear-gradient(135deg, var(--brown-800), var(--brown-700));
            border-radius: 24px;
            padding: 48px;
            margin: 60px 0 30px;
            text-align: center;
            color: white;
        }

        .contact-section h2 {
            font-family: 'Montserrat', sans-serif;
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 12px;
        }

        .contact-section > p {
            opacity: 0.9;
            margin-bottom: 32px;
            font-size: 15px;
        }

        .contact-info {
            display: flex;
            justify-content: center;
            gap: 40px;
            flex-wrap: wrap;
        }

        .contact-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 24px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 60px;
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
        }

        .contact-item:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
        }

        .contact-item i {
            font-size: 20px;
        }

        .contact-item a {
            color: white;
            text-decoration: none;
            font-weight: 500;
        }

        .contact-item a:hover {
            text-decoration: underline;
        }

        /* Back Button */
        .back-button {
            text-align: center;
            margin: 40px 0 20px;
        }

        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 14px 32px;
            background: white;
            color: var(--brown-700);
            text-decoration: none;
            border-radius: 60px;
            font-weight: 600;
            transition: all 0.3s;
            border: 2px solid var(--brown-200);
            box-shadow: var(--shadow-sm);
        }

        .btn-back:hover {
            background: var(--brown-50);
            border-color: var(--brown-600);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        /* Footer */
        .footer-note {
            text-align: center;
            padding-top: 30px;
            border-top: 1px solid var(--gray-200);
            color: var(--gray-500);
            font-size: 13px;
        }

        .footer-note p {
            margin-bottom: 8px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            body {
                padding: 20px;
            }

            .about-header {
                padding: 40px 30px;
            }

            .about-header h1 {
                font-size: 32px;
            }

            .about-content {
                padding: 30px 20px;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 20px;
            }

            .stat-number {
                font-size: 32px;
            }

            .features-grid {
                grid-template-columns: 1fr;
            }

            .contact-section {
                padding: 32px 24px;
            }

            .contact-info {
                gap: 16px;
            }

            .contact-item {
                padding: 10px 18px;
                font-size: 13px;
            }

            .tech-icons {
                gap: 20px;
            }

            .tech-item i {
                font-size: 28px;
            }

            .logo-container {
                padding: 14px 20px;
                margin-bottom: 24px;
            }

            .header-logo {
                max-width: 140px;
                max-height: 55px;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .about-header h1 {
                font-size: 28px;
            }

            .mission-section h2 {
                font-size: 24px;
            }

            .section-title {
                font-size: 24px;
            }

            .logo-container {
                padding: 12px 16px;
            }

            .header-logo {
                max-width: 120px;
                max-height: 50px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="about-card">
            <!-- Hero Header -->
            <div class="about-header">
                <?php
                // Check for logo in various locations (matching login.php logic)
                $logo_path = '';
                $possible_logo_paths = [
                    'uploads/systemlogo/Shehita_Logo.png',
                    'uploads/systemlogo/Shehita_Logo.jpg',
                    'uploads/systemlogo/Shehita_Logo.jpeg',
                    'uploads/systemlogo/logo.png',
                    'uploads/systemlogo/logo.jpg',
                    '../uploads/systemlogo/Shehita_Logo.png',
                    '../uploads/systemlogo/Shehita_Logo.jpg',
                    '../uploads/systemlogo/logo.png',
                    '../uploads/systemlogo/logo.jpg'
                ];
                
                foreach ($possible_logo_paths as $path) {
                    if (file_exists($path)) {
                        $logo_path = $path;
                        break;
                    }
                }
                
                $logo_exists = !empty($logo_path);
                ?>
                
                <!-- Logo Container with White Background (matching login.php) -->
                <div class="logo-container">
                    <?php if ($logo_exists): ?>
                        <img src="<?= htmlspecialchars($logo_path) ?>" alt="SHEHITA" class="header-logo">
                    <?php else: ?>
                        <div class="logo-text">SHEHITA</div>
                        <span class="logo-sub">Enterprise Management System</span>
                    <?php endif; ?>
                </div>
                
                <h1>About SHEHITA EMS</h1>
                <p class="tagline">Empowering Enterprises Through Innovative Technology Solutions</p>
            </div>

            <div class="about-content">
                <!-- Mission Section -->
                <div class="mission-section">
                    <span class="mission-badge"><i class="fas fa-star"></i> Our Mission</span>
                    <h2>Transforming Business Management</h2>
                    <div class="mission-text">
                        <p>SHEHITA Enterprise Management System (EMS) is a comprehensive, all-in-one business management solution designed to streamline operations, enhance productivity, and drive growth for modern enterprises. Our system provides powerful tools for managing projects, finances, users, and organizational structure - all in one centralized, intuitive platform.</p>
                        <p>Founded with a vision to simplify complex business processes, SHEHITA EMS combines cutting-edge technology with user-centric design to deliver an exceptional management experience for businesses of all sizes.</p>
                    </div>
                </div>

                <!-- Stats Section -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number">100%</div>
                        <div class="stat-label">Client Satisfaction</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number">24/7</div>
                        <div class="stat-label">Technical Support</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number">100+</div>
                        <div class="stat-label">Active Clients</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number">99.9%</div>
                        <div class="stat-label">Uptime Guarantee</div>
                    </div>
                </div>

                <!-- Features Section -->
                <div class="features-section">
                    <h3 class="section-title">Core Features</h3>
                    <p class="section-subtitle">Everything you need to manage your enterprise efficiently</p>
                    
                    <div class="features-grid">
                        <div class="feature-card">
                            <div class="feature-icon"><i class="fas fa-chart-line"></i></div>
                            <h3>Project Management</h3>
                            <p>Easily manage projects, categories, and groups with our intuitive project management tools. Track progress, assign tasks, and monitor deadlines in real-time.</p>
                        </div>

                        <div class="feature-card">
                            <div class="feature-icon"><i class="fas fa-money-bill-wave"></i></div>
                            <h3>Expense Tracking</h3>
                            <p>Track and manage expenses efficiently with categorized expense groups and categories. Generate detailed reports for better financial insights.</p>
                        </div>

                        <div class="feature-card">
                            <div class="feature-icon"><i class="fas fa-users"></i></div>
                            <h3>User Management</h3>
                            <p>Complete user management system with role-based access control and department assignment. Secure, scalable, and easy to manage.</p>
                        </div>

                        <div class="feature-card">
                            <div class="feature-icon"><i class="fas fa-building"></i></div>
                            <h3>Department Management</h3>
                            <p>Organize your organization with flexible department structures and role assignments. Streamline workflows across teams.</p>
                        </div>

                        <div class="feature-card">
                            <div class="feature-icon"><i class="fas fa-shield-alt"></i></div>
                            <h3>Secure & Reliable</h3>
                            <p>Built with enterprise-grade security featuring password hashing, CSRF protection, secure sessions, and regular security updates.</p>
                        </div>

                        <div class="feature-card">
                            <div class="feature-icon"><i class="fas fa-language"></i></div>
                            <h3>Multi-Language Support</h3>
                            <p>Full English and Swahili support for all modules, making the system accessible to all users across East Africa and beyond.</p>
                        </div>

                        <div class="feature-card">
                            <div class="feature-icon"><i class="fas fa-chart-pie"></i></div>
                            <h3>Advanced Analytics</h3>
                            <p>Comprehensive dashboards and reports providing actionable insights into your business performance and operations.</p>
                        </div>

                        <div class="feature-card">
                            <div class="feature-icon"><i class="fas fa-mobile-alt"></i></div>
                            <h3>Responsive Design</h3>
                            <p>Fully responsive interface that works seamlessly on desktop, tablet, and mobile devices for on-the-go access.</p>
                        </div>

                        <div class="feature-card">
                            <div class="feature-icon"><i class="fas fa-database"></i></div>
                            <h3>Data Backup & Recovery</h3>
                            <p>Automated backup systems and robust recovery procedures ensure your business data is always protected.</p>
                        </div>
                    </div>
                </div>

                <!-- Technology Stack -->
                <div class="tech-section">
                    <h3><i class="fas fa-code"></i> Built With Modern Technology</h3>
                    <div class="tech-icons">
                        <div class="tech-item"><i class="fab fa-php"></i><span>PHP 7.4+</span></div>
                        <div class="tech-item"><i class="fas fa-database"></i><span>MySQL</span></div>
                        <div class="tech-item"><i class="fab fa-js"></i><span>JavaScript</span></div>
                        <div class="tech-item"><i class="fab fa-html5"></i><span>HTML5</span></div>
                        <div class="tech-item"><i class="fab fa-css3-alt"></i><span>CSS3</span></div>
                        <div class="tech-item"><i class="fas fa-shield-alt"></i><span>Security First</span></div>
                    </div>
                </div>

                <!-- Contact Section -->
                <div class="contact-section">
                    <h2>Get In Touch</h2>
                    <p>Have questions? We're here to help and answer any questions you might have.</p>
                    <div class="contact-info">
                        <div class="contact-item">
                            <i class="fas fa-phone-alt"></i>
                            <a href="tel:+255756230792">+255 756 230 792</a>
                        </div>
                        <div class="contact-item">
                            <i class="fas fa-envelope"></i>
                            <a href="mailto:info@shehita.com">info@shehita.com</a>
                        </div>
                        <div class="contact-item">
                            <i class="fas fa-map-marker-alt"></i>
                            <span>Dar es Salaam, Tanzania</span>
                        </div>
                    </div>
                </div>

                <!-- Back Button -->
                <div class="back-button">
                    <a href="login.php" class="btn-back">
                        <i class="fas fa-arrow-left"></i> Back to Login
                    </a>
                </div>

                <!-- Footer -->
                <div class="footer-note">
                    <p>&copy; <?= date('Y') ?> SHEHITA Enterprise Management System. All rights reserved.</p>
                    <p>Version 2.0 | Empowering Businesses Through Technology | <i class="fas fa-heart" style="color: var(--brown-600);"></i> Made in Tanzania</p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>