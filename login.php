<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (auth_is_logged_in()) {
    $user = auth_current_user();
    if ($user) {
        auth_redirect_dashboard($user);
    }
}

$redirectParam = $_GET['redirect'] ?? '';
$redirectFallback = 'user-dashboard.php';
$error = '';
$activeTab = 'login'; // 'login' or 'register'

// Signup variables
$username = '';
$email = '';
$phone = '';
$country = '';

$title = '';
$firstName = '';
$middleName = '';
$lastName = '';

$position = '';
$institution = '';
$department = '';

$gradeLevel = '';
$schoolName = '';
$schoolEmail = '';
$admissionNumber = '';

$city = '';
$state = '';
$postalCode = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();

    if (isset($_POST['register_submit'])) {
$username = trim((string) ($_POST['username'] ?? ''));
          $email = trim((string) ($_POST['email'] ?? ''));
          $phone = trim((string) ($_POST['phone'] ?? ''));
          $country = trim((string) ($_POST['country'] ?? ''));
        
          $title = trim((string) ($_POST['title'] ?? ''));
          $firstName = trim((string) ($_POST['first_name'] ?? ''));
          $middleName = trim((string) ($_POST['middle_name'] ?? ''));
          $lastName = trim((string) ($_POST['last_name'] ?? ''));
        
          $position = trim((string) ($_POST['position'] ?? ''));
          $institution = trim((string) ($_POST['institution'] ?? ''));
          $department = trim((string) ($_POST['department'] ?? ''));
        
          $gradeLevel = trim((string) ($_POST['grade_level'] ?? ''));
          $schoolName = trim((string) ($_POST['school_name'] ?? ''));
          $schoolEmail = trim((string) ($_POST['school_email'] ?? ''));
          $admissionNumber = trim((string) ($_POST['admission_number'] ?? ''));
        
          $city = trim((string) ($_POST['city'] ?? ''));
          $state = trim((string) ($_POST['state'] ?? ''));
          $postalCode = trim((string) ($_POST['postal_code'] ?? ''));
        
            $password = (string) ($_POST['password'] ?? '');
            $password2 = (string) ($_POST['password2'] ?? '');
        
          $nameParts = [$firstName];
          if ($middleName !== '') {
            $nameParts[] = $middleName;
          }
          $nameParts[] = $lastName;
          $name = trim(implode(' ', array_filter($nameParts, static function($v) { return $v !== ''; })));
        
          if ($username === '' || $email === '' || $phone === '' || $country === '' || $firstName === '' || $lastName === '' || $institution === '' || $gradeLevel === '' || $city === '' || $state === '' || $postalCode === '' || $password === '') {
                $error = 'Please fill in all required fields.';
                $activeTab = 'register';
          } elseif (strlen($username) < 3 || strlen($username) > 64) {
            $error = 'Username must be between 3 and 64 characters.';
          } elseif (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{1,62}[a-zA-Z0-9]$/', $username)) {
            $error = 'Username may contain letters, numbers, dots, underscores, and hyphens (must start and end with a letter/number).';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email.';
          } elseif (!preg_match('/^\+?[0-9][0-9\s\-]{6,20}$/', $phone)) {
            $error = 'Please enter a valid phone number (e.g., +91XXXXXXXXXX).';
          } elseif ($schoolEmail !== '' && !filter_var($schoolEmail, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid school email (or leave it blank).';
            } elseif (strlen($password) < 8) {
                $error = 'Password must be at least 8 characters.';
            } elseif ($password !== $password2) {
                $error = 'Passwords do not match.';
            } else {
                try {
                    $pdo = db();
        
                    $stmt = $pdo->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
                    $stmt->execute([$email]);
                    if ($stmt->fetch()) {
                        $error = 'That email is already registered. Please log in.';
                        $activeTab = 'register';
                    } else {
                $stmt = $pdo->prepare('SELECT 1 FROM users WHERE username = ? LIMIT 1');
                $stmt->execute([$username]);
                if ($stmt->fetch()) {
                  $error = 'That username is already taken. Please choose another.';
                } else {
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('INSERT INTO users (name, email, username, phone, country, title, first_name, middle_name, last_name, position, institution, department, grade_level, school_name, school_email, admission_number, city, state, postal_code, password_hash, role) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$name, $email, $username, $phone, $country, $title !== '' ? $title : null, $firstName, $middleName !== '' ? $middleName : null, $lastName, $position, $institution, $department !== '' ? $department : null, $gradeLevel, $schoolName, $schoolEmail !== '' ? $schoolEmail : null, $admissionNumber !== '' ? $admissionNumber : null, $city, $state, $postalCode, $hash, 'user']);
        
                        $userId = (int) $pdo->lastInsertId();
                        auth_login_user($userId);
                        redirect('user-dashboard.php');
                }
                    }
                } catch (Throwable $e) {
                      error_log('Signup error: ' . $e);
        
                      $msg = strtolower($e->getMessage());
                      $code = (string) $e->getCode();
        
                      if (strpos($msg, 'could not find driver') !== false) {
                        $error = 'Sign up is unavailable because the server is missing the MySQL PDO driver (pdo_mysql).';
                      } elseif (strpos($msg, '[2002]') !== false || strpos($msg, 'connection refused') !== false || strpos($msg, 'no connection could be made') !== false || $code === 'hy000') {
                        $error = 'Sign up is unavailable because the site cannot connect to the database server. If you are running locally, make sure MySQL is running and DB_HOST/DB_PORT are correct (or update includes/config.local.php).';
                      } elseif (strpos($msg, 'unknown column') !== false || strpos($msg, 'no such column') !== false) {
                        $error = 'Sign up is unavailable because the database schema is out of date. Please apply the latest updates to the users table (see sql/schema.sql).';
                      } elseif ($code === '42s02' || strpos($msg, 'base table') !== false || strpos($msg, "doesn't exist") !== false) {
                        $error = 'Sign up is unavailable because the database tables are not set up yet. Please run sql/schema.sql on your MySQL database (or run: php scripts/init-db.php).';
                      } elseif (strpos($msg, 'access denied') !== false || strpos($msg, 'sqlstate[28000]') !== false) {
                        $error = 'Sign up is unavailable because the database credentials are invalid. Please check DB_USER/DB_PASS (or includes/config.local.php).';
                      } elseif (strpos($msg, 'duplicate') !== false || strpos($msg, 'uniq_users_email') !== false) {
                        $error = 'That email is already registered. Please log in.';
                        $activeTab = 'register';
                      } elseif (strpos($msg, 'uniq_users_username') !== false) {
                        $error = 'That username is already taken. Please choose another.';
                      } elseif (strpos($msg, 'not configured') !== false || strpos($msg, 'configuration is missing') !== false) {
                        $error = 'Sign up is unavailable because the database is not configured. Please set DB_* environment variables or update includes/config.local.php.';
                      } else {
                        $error = 'Sign up is temporarily unavailable. Please try again later.';
                      }
                }
            }
            if ($error !== '') $activeTab = 'register';
    } else {

    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
  $loginMode = (string) ($_POST['login_mode'] ?? '');
    $redirectTo = safe_redirect_target($_POST['redirect'] ?? null, $redirectFallback);

    if ($email === '' || $password === '') {
        $error = 'Please enter your email and password.';
    } else {
        try {
            $pdo = db();
            $stmt = $pdo->prepare('SELECT id, name, email, role, password_hash FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            $hash = is_array($user) ? (string) ($user['password_hash'] ?? '') : '';

            if (is_array($user) && $hash !== '' && password_verify($password, $hash)) {
                $role = (string) ($user['role'] ?? '');

                // Admin Login should only be used by admin accounts.
                if ($loginMode === 'admin' && $role !== 'admin') {
                    $error = 'This account is not an admin account. Use Author Login.';
                } else {
                    auth_login_user((int) $user['id']);

                    // If no explicit redirect, send to role dashboard.
                    if ($redirectTo === $redirectFallback) {
                        if ($role === 'admin') {
                            $redirectTo = 'admin-dashboard.php';
                        }
                    }

                    redirect($redirectTo);
                }
            }

      // If the user is applying to become an admin, they won't exist in `users` yet.
      // Show a helpful message only when the password matches the pending application.
      if ($error === '') {
        if (!is_array($user)) {
            $error = 'Invalid email or password. (Debug: User not found in database)';
        } elseif ($hash === '') {
            $error = 'Invalid email or password. (Debug: Password hash is empty)';
        } elseif (!password_verify($password, $hash)) {
            $error = 'Invalid email or password. (Debug: Password does not match hash. Hash len: ' . strlen($hash) . ', Pwd len: ' . strlen($password) . ')';
        } else {
            $error = 'Invalid email or password.';
        }

        try {
          $stmt = $pdo->prepare("SELECT password_hash FROM admin_applications WHERE email = ? AND status = 'pending' ORDER BY created_at DESC, id DESC LIMIT 1");
          $stmt->execute([$email]);
          $app = $stmt->fetch();
          $appHash = is_array($app) ? (string) ($app['password_hash'] ?? '') : '';
          if ($appHash !== '' && password_verify($password, $appHash)) {
            $error = 'Your admin application is pending approval. Please try again later.';
          }
        } catch (Throwable $e) {
          // Ignore; fall back to generic invalid login message.
        }
      }
        } catch (Throwable $e) {
      error_log('Login error: ' . $e);

      $msg = strtolower($e->getMessage());
      $code = (string) $e->getCode();

      if (strpos($msg, 'could not find driver') !== false) {
        $error = 'Login is unavailable because the server is missing the MySQL PDO driver (pdo_mysql).';
      } elseif (strpos($msg, '[2002]') !== false || strpos($msg, 'connection refused') !== false || strpos($msg, 'no connection could be made') !== false || $code === 'hy000') {
        $error = 'Login is unavailable because the site cannot connect to the database server. If you are running locally, make sure MySQL is running and DB_HOST/DB_PORT are correct (or update includes/config.local.php).';
      } elseif ($code === '42s02' || strpos($msg, 'base table') !== false || strpos($msg, "doesn't exist") !== false) {
        $error = 'Login is unavailable because the database tables are not set up yet. Please run sql/schema.sql on your MySQL database (or run: php scripts/init-db.php).';
      } elseif (strpos($msg, 'access denied') !== false || strpos($msg, 'sqlstate[28000]') !== false) {
        $error = 'Login is unavailable because the database credentials are invalid. Please check DB_USER/DB_PASS (or includes/config.local.php).';
      } elseif (strpos($msg, 'not configured') !== false || strpos($msg, 'configuration is missing') !== false) {
        $error = 'Login is unavailable because the database is not configured. Please set DB_* environment variables or update includes/config.local.php.';
      } else {
        $error = 'Login is temporarily unavailable. Please try again later.';
      }
        }
    }
    }
}

$redirectValue = safe_redirect_target(is_string($redirectParam) ? $redirectParam : '', $redirectFallback);
?>
<!DOCTYPE html>
<html lang="en" class="no-js">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <link rel="shortcut icon" type="image/jpg" href="images/iysjournal.png">
  <title>Login | Global Youth Science Journal</title>
  <link href="css/media_query.css" rel="stylesheet" type="text/css">
  <link href="css/style.css" rel="stylesheet" type="text/css">
  <link href="css/bootstrap.css" rel="stylesheet" type="text/css">
  <link href="css/font-awesome.min.css" rel="stylesheet" crossorigin="anonymous">
  <link href="css/animate.css" rel="stylesheet" type="text/css">
  <link href="https://fonts.googleapis.com/css?family=Poppins" rel="stylesheet">
  <link href="css/owl.carousel.css" rel="stylesheet" type="text/css">
  <link href="css/owl.theme.default.css" rel="stylesheet" type="text/css">
  <link href="css/style_1.css" rel="stylesheet" type="text/css">
  <link href="css/login.css" rel="stylesheet" type="text/css">
  <style>
    :root {
      --gysj-text-primary: #1f2937;
      --gysj-text-secondary: #4b5563;
      --gysj-border: #e5e7eb;
      --gysj-surface: #ffffff;
      --gysj-surface-soft: #f8f8f5;
      --gysj-accent: #f0b429;
      --gysj-accent-dark: #8a5a00; /* Darker for WCAG contrast */
      --gysj-accent-soft: #fff6d8;
    }

    body {
      background: var(--gysj-surface);
      color: var(--gysj-text-primary);
      font-family: 'Poppins', sans-serif;
    }

    .wrap {
      background: #dde3ea;
      color: var(--gysj-text-primary);
    }

    .page-tabs {
      background: var(--gysj-surface);
      border: 1px solid var(--gysj-border);
    }

    .login-title {
      font-size: 28px;
      font-weight: 600;
      color: var(--gysj-text-primary);
      padding: 4px 22px 10px;
      background: #ffffff;
      border-bottom: 1px solid var(--gysj-border);
    }

    .page-tab {
      color: var(--gysj-text-secondary);
    }

    .page-tab.active {
      color: var(--gysj-text-primary);
      border-bottom-color: var(--gysj-accent-dark);
    }

    .role-toggle,
    .role-card,
    .admin-note,
    .faq-item,
    .field .input-wrap,
    .divider-line {
      border-color: var(--gysj-border);
    }

    .role-btn {
      background: #eef0f3;
      color: var(--gysj-text-secondary);
      border-color: var(--gysj-border);
    }

    .role-btn.active,
    .role-btn:hover {
      background: var(--gysj-accent-soft);
      border-color: var(--gysj-accent-dark);
      color: var(--gysj-text-primary);
    }

    .field label,
    .create-header h2,
    .faq-title,
    .faq-q,
    .faq-a,
    .sign-up-row,
    .role-card h3,
    .role-card p,
    .admin-note p,
    .divider span,
    .forgot a,
    .link {
      color: var(--gysj-text-primary);
    }

    .field .input-wrap {
      position: relative;
    }

    .field .input-wrap i,
    .forgot a,
    .link {
      color: var(--gysj-accent-dark);
    }

    .eye-btn {
      position: absolute;
      right: 10px;
      top: 50%;
      transform: translateY(-50%);
      width: 28px;
      height: 28px;
      border: 0;
      background: transparent;
      color: var(--gysj-text-secondary);
      padding: 0;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
    }

    .eye-btn:hover {
      color: var(--gysj-text-primary);
    }

    .field input {
      color: var(--gysj-text-primary);
      background: #f8fafc;
      border: 1px solid var(--gysj-border) !important;
      border-radius: 4px !important;
      padding-right: 44px !important;
      width: 100%;
      box-sizing: border-box;
    }

    .field input:focus,
    .eye-btn:focus-visible,
    .btn-primary:focus-visible,
    .btn-admin:focus-visible,
    .role-btn:focus-visible {
      outline: 3px solid var(--gysj-text-primary);
      outline-offset: 2px;
    }

    .btn-primary,
    .btn-admin {
      background: var(--gysj-accent);
      border-color: var(--gysj-accent-dark);
      color: var(--gysj-text-primary);
      box-shadow: 0 2px 6px rgba(215, 155, 0, 0.18);
    }

    .btn-primary:hover,
    .btn-admin:hover {
      background: #e6a800;
      border-color: #c58c00;
      color: var(--gysj-text-primary);
      box-shadow: 0 4px 12px rgba(215, 155, 0, 0.22);
    }

    .gysj-navbar .btn-primary {
      background: #eef0f3;
      border-color: #d7dee8;
      color: var(--gysj-text-primary) !important;
      box-shadow: none !important;
    }

    .gysj-navbar .btn-primary:hover,
    .gysj-navbar .btn-primary:focus,
    .gysj-navbar .btn-primary:active {
      background: #e5e7eb;
      border-color: #cfd6de;
      box-shadow: none !important;
      color: var(--gysj-text-primary) !important;
    }

    .btn-primary:active,
    .btn-admin:active {
      transform: translateY(0);
    }

    .btn-admin {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: fit-content;
      border-radius: 0;
      padding: 10px 18px;
      margin-top: 12px;
    }

    .login-card-body {
      padding-top: 14px;
    }

    .page-tabs {
      margin-bottom: 12px;
    }

    .role-toggle {
      margin-bottom: 12px;
    }

    .admin-note {
      background: var(--gysj-accent-soft);
      color: var(--gysj-text-primary);
    }

    .selected-badge {
      background: var(--gysj-accent-soft);
      color: var(--gysj-text-primary);
      border-color: rgba(215, 155, 0, 0.28);
    }

    .faq-q i,
    .admin-note i,
    .role-card i {
      color: var(--gysj-accent-dark);
    }

    body {
      background: #dde3ea;
    }

    .wrap {
      background: #dde3ea;
    }

    .page-tabs,
    .role-toggle,
    .role-card,
    .admin-note,
    .faq-item,
    .btn-primary,
    .btn-admin,
    .selected-badge {
      border-radius: 0;
    }

    .page-tab,
    .role-btn,
    .field .input-wrap,
    .btn-primary,
    .btn-admin,
    .admin-note,
    .faq-item {
      background-image: none;
    }

    .page-tabs,
    .role-toggle,
    .role-card,
    .admin-note,
    .faq-item,
    .field .input-wrap {
      background: #ffffff;
    }

    .login-card {
      max-width: 860px;
      margin: 0 auto;
      background: #ffffff;
      border: 1px solid var(--gysj-border);
      border-radius: 0;
      overflow: hidden;
      box-shadow: 0 10px 30px rgba(31, 41, 55, 0.06);
    }

    .login-card-body {
      padding: 14px 22px 10px;
    }

    .login-card-footer {
      padding: 18px 22px 22px;
      border-top: 1px solid var(--gysj-border);
      background: #ffffff;
    }

    .page-tabs {
      border: 0;
      border-bottom: 1px solid var(--gysj-border);
      border-radius: 0;
      margin-bottom: 10px;
    }

    .login-page-title {
      max-width: 860px;
      margin: 0 auto 10px;
      padding: 0 4px;
      font-size: 30px;
      font-weight: 600;
      color: var(--gysj-text-primary);
      letter-spacing: -0.02em;
    }

    .faq-section {
      margin-top: 0;
    }

    .faq-title {
      padding: 0 0 10px;
    }

    .faq-item {
      padding: 14px 16px;
      margin-bottom: 10px;
    }

    .faq-q {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 12px;
      padding: 0 0 8px;
      line-height: 1.45;
    }

    .faq-q i {
      margin-left: 10px;
      flex-shrink: 0;
    }

    .faq-a {
      padding: 4px 0 0;
      line-height: 1.65;
    }

    .faq-card {
      max-width: 860px;
      margin: 14px auto 0;
      background: #ffffff;
      border: 1px solid var(--gysj-border);
      border-radius: 0;
      padding: 18px 22px 22px;
      box-shadow: 0 10px 30px rgba(31, 41, 55, 0.06);
    }

    .faq-card .faq-item:last-child {
      margin-bottom: 0;
    }

    .login-card .role-toggle {
      margin-bottom: 10px;
    }

    .login-card .divider {
      margin-top: 14px;
      margin-bottom: 10px;
    }

    .login-card .sign-up-row {
      margin-top: 4px;
    }

    .divider {
      margin-top: 18px;
    }

    @media (max-width: 575px) {
      .login-page-title,
      .faq-card {
        max-width: none;
        margin-left: 0;
        margin-right: 0;
      }

      .login-page-title {
        padding-left: 2px;
        padding-right: 2px;
        font-size: 26px;
      }

      .login-card-body,
      .login-card-footer {
        padding-left: 14px;
        padding-right: 14px;
      }

      .faq-card {
        padding-left: 14px;
        padding-right: 14px;
      }
    }
  
    .signup-embedded .field-grid {
      display: grid;
      grid-template-columns: 1fr;
      gap: 15px;
    }
    @media (max-width: 768px) {
      .signup-embedded .field-grid {
        grid-template-columns: 1fr;
        gap: 0;
      }
    }
    .signup-embedded .field {
      margin-bottom: 15px;
    }

    .signup-embedded .field-grid {
      display: grid;
      grid-template-columns: 1fr;
      gap: 15px;
    }
    @media (max-width: 768px) {
      .signup-embedded .field-grid {
        grid-template-columns: 1fr;
        gap: 0;
      }
    }
    .signup-embedded .field {
      margin-bottom: 15px;
    }
    .progress-container {
      display: flex;
      justify-content: space-between;
      margin-bottom: 30px;
      position: relative;
    }
    .progress-container::before {
      content: '';
      background-color: var(--gysj-border);
      position: absolute;
      top: 50%;
      left: 0;
      transform: translateY(-50%);
      height: 4px;
      width: 100%;
      z-index: 1;
    }
    .progress-bar {
      background-color: var(--gysj-accent);
      position: absolute;
      top: 50%;
      left: 0;
      transform: translateY(-50%);
      height: 4px;
      width: 0%;
      z-index: 1;
      transition: 0.4s ease;
    }
    .step-indicator {
      background-color: var(--gysj-surface);
      color: var(--gysj-text-secondary);
      border: 3px solid var(--gysj-border);
      border-radius: 50%;
      height: 35px;
      width: 35px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: bold;
      z-index: 2;
      transition: 0.4s ease;
      position: relative;
    }
    .step-indicator.active {
      border-color: var(--gysj-accent);
      color: var(--gysj-text-primary);
      background-color: var(--gysj-accent-soft);
    }
    .step-actions {
      display: flex;
      gap: 10px;
      margin-top: 20px;
    }
    .btn-secondary {
      background: #eef0f3;
      border: 1px solid var(--gysj-border);
      color: var(--gysj-text-secondary);
      padding: 10px 18px;
      cursor: pointer;
    }
    .btn-secondary:hover {
      background: #e5e7eb;
      color: var(--gysj-text-primary);
    }
    .form-step {
      animation: fadeIn 0.4s;
    }
    .form-step h3 {
      font-size: 18px;
      margin-bottom: 15px;
      color: var(--gysj-text-primary);
      border-bottom: 1px solid var(--gysj-border);
      padding-bottom: 8px;
    }
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(10px); }
      to { opacity: 1; transform: translateY(0); }
    }

  </style>
  <script src="js/modernizr-3.5.0.min.js"></script>
</head>

<body>
  <div class="container-fluid bg-faded fh5co_padd_mediya padding_786">
    <div class="container padding_786">
      <nav class="navbar navbar-toggleable-md navbar-light gysj-navbar flex-column align-items-start">
        <div class="d-flex w-100 align-items-center justify-content-between">
          <a class="navbar-brand mobile_logo_width" href="index.php">
            <img src="images/iysjournal.png" alt="Global Youth Science Journal" class="gysj-nav-icon">
            <span class="gysj-nav-title">Global Youth Science Journal</span>
          </a>
          <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarSupportedContent"
            aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation"><span
              class="navbar-toggler-icon"></span></button>
        </div>

        <div class="collapse navbar-collapse w-100 mt-3 gysj-nav-links" id="navbarSupportedContent">
          <ul class="navbar-nav mx-auto">
                        <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>">
                            <a class="nav-link" href="index.php">Home</a>
                        </li>
                        <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'publication.php' ? 'active' : ''; ?>">
                            <a class="nav-link" href="publication.php">Publications</a>
                        </li>
                        <li class="nav-item dropdown <?php echo in_array(basename($_SERVER['PHP_SELF']), ['user-dashboard.php', 'call-for-paper.php', 'authorguidelines.php', 'copyright.php']) ? 'active' : ''; ?>">
                            <a class="nav-link dropdown-toggle" href="#" id="dropdownMenuButton3" data-toggle="dropdown"
                                aria-haspopup="true" aria-expanded="false">Paper Submissions</a>
                            <div class="dropdown-menu" aria-labelledby="dropdownMenuButton3">
                                <a class="dropdown-item" href="user-dashboard.php?view=submit">Online Submission</a>
                                <a class="dropdown-item" href="call-for-paper.php">Call for Paper</a>
                                <a class="dropdown-item" href="authorguidelines.php">Guidelines for authors</a>
                                <a class="dropdown-item" href="copyright.php">Copyright</a>
                            </div>
                        </li>
                        <li class="nav-item dropdown <?php echo in_array(basename($_SERVER['PHP_SELF']), ['our-founders.php', 'our-mission.php', 'our-funding.php']) ? 'active' : ''; ?>">
                            <a class="nav-link dropdown-toggle" href="#" id="dropdownMenuButton2" data-toggle="dropdown"
                                aria-haspopup="true" aria-expanded="false">About Us</a>
                            <div class="dropdown-menu" aria-labelledby="dropdownMenuButton2">
                                <a class="dropdown-item" href="our-founders.php">Our Founders</a>
                                <a class="dropdown-item" href="our-mission.php">Our Mission</a>
                                <a class="dropdown-item" href="our-funding.php">Our Funding</a>
                            </div>
                        </li>
                        <li class="nav-item dropdown <?php echo in_array(basename($_SERVER['PHP_SELF']), ['editorial-board.php', 'editorial-members.php']) ? 'active' : ''; ?>">
                            <a class="nav-link dropdown-toggle" href="#" id="dropdownEditorialBoard" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Editorial Board</a>
                            <div class="dropdown-menu" aria-labelledby="dropdownEditorialBoard">
                                <a class="dropdown-item" href="editorial-board.php">About the Board</a>
                                <a class="dropdown-item" href="editorial-members.php">Members</a>
                            </div>
                        </li>
                        <li class="nav-item dropdown <?php echo in_array(basename($_SERVER['PHP_SELF']), ['contribute.php', 'partners.php']) ? 'active' : ''; ?>">
                            <a class="nav-link dropdown-toggle" href="#" id="dropdownSupport" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Support Us</a>
                            <div class="dropdown-menu" aria-labelledby="dropdownSupport">
                                <a class="dropdown-item" href="contribute.php">Contribute</a>
                                <a class="dropdown-item" href="partners.php">Partners</a>
                            </div>
                        </li>
                        <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'contact.php' ? 'active' : ''; ?>">
                            <a class="nav-link" href="contact.php">Contact</a>
                        </li>

                        <?php if (auth_is_logged_in()): $navUser = auth_current_user(); ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="accountMenu" data-toggle="dropdown"
                                aria-haspopup="true" aria-expanded="false"><?php echo e(($navUser['name'] ?? '') !== '' ? $navUser['name'] : ($navUser['email'] ?? 'Account')); ?></a>
                            <div class="dropdown-menu" aria-labelledby="accountMenu">
                                <a class="dropdown-item" href="<?php echo e((($navUser['role'] ?? '') === 'admin') ? 'admin-dashboard.php' : 'user-dashboard.php'); ?>">Dashboard</a>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item" href="account.php">Account Settings</a>
                                <a class="dropdown-item" href="logout.php">Log Out</a>
                            </div>
                        </li>
                        <?php else: ?>
                        <li class="nav-item">
                            <a class="nav-link btn btn-primary btn-sm text-white px-3" href="login.php"
                                style="margin-top:4px; margin-left:8px;">Login / Sign Up</a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </nav>
    </div>
  </div>

  <div class="wrap">
    <div class="login-page-title">Login</div>
    <div class="login-card">
      <div class="page-tabs">
        <div class="page-tab <?php echo $activeTab === 'login' ? 'active' : ''; ?>" onclick="switchTab('login', this)">Sign in</div>
        <div class="page-tab <?php echo $activeTab === 'register' ? 'active' : ''; ?>" onclick="switchTab('register', this)">Create account</div>
      </div>

      <div class="login-card-body">
      <?php if ($error !== ''): ?>
        <div class="alert alert-danger" role="alert" style="margin-bottom: 15px;"><?php echo e($error); ?></div>
      <?php endif; ?>
        <div id="login" class="tab-panel <?php echo $activeTab === 'login' ? 'active' : ''; ?>">
          <div class="role-toggle">
            <button class="role-btn active" onclick="switchRole('author', this)" id="btn-author"><i class="fa fa-pencil" style="font-size:13px; margin-right:6px;"></i>Author</button>
            <button class="role-btn" onclick="switchRole('admin', this)" id="btn-admin"><i class="fa fa-shield" style="font-size:13px; margin-right:6px;"></i>Editor / Admin</button>
          </div>

      <div id="author-form">
        <form method="post" action="login.php">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="redirect" value="<?php echo e($redirectValue); ?>">
          <div class="field">
            <label for="email-author">Email address</label>
            <div class="input-wrap">
              <i class="fa fa-envelope"></i>
              <input type="email" name="email" id="email-author" placeholder="you@example.com" required />
            </div>
          </div>
          <div class="field">
            <label for="pw-author">Password</label>
            <div class="input-wrap">
              <i class="fa fa-lock"></i>
              <input type="password" name="password" id="pw-author" placeholder="Enter your password" required />
              <button class="eye-btn" type="button" onclick="togglePw('pw-author', this)" aria-label="Show password"><i class="fa fa-eye"></i></button>
            </div>
            <div class="forgot"><a href="#">Forgot password?</a></div>
          </div>
          <button type="submit" class="btn-primary btn-author" name="login_mode" value="author">Sign in as Author</button>
        </form>

        <div class="divider"><div class="divider-line"></div><span>don't have an account?</span><div class="divider-line"></div></div>
        <div class="sign-up-row"><span class="link" style="cursor:pointer;" onclick="switchTabByName('register')">Create an author account →</span></div>
      </div>

      <div id="admin-form" style="display:none;">
        <div class="admin-note">
          <i class="fa fa-info-circle"></i>
          <p>Editor and admin accounts are for the editorial team. Access requires prior approval. <a href="admin-signup.php" class="link" style="color:#c58c00;">Apply to join the editorial team →</a></p>
        </div>
        <form method="post" action="login.php">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="redirect" value="<?php echo e($redirectValue); ?>">
          <div class="field" style="margin-top:1.25rem;">
            <label for="email-admin">Institutional email</label>
            <div class="input-wrap">
              <i class="fa fa-envelope"></i>
              <input type="email" name="email" id="email-admin" placeholder="you@institution.edu" required />
            </div>
          </div>
          <div class="field">
            <label for="pw-admin">Password</label>
            <div class="input-wrap">
              <i class="fa fa-lock"></i>
              <input type="password" name="password" id="pw-admin" placeholder="Enter your password" required />
              <button class="eye-btn" type="button" onclick="togglePw('pw-admin', this)" aria-label="Show password"><i class="fa fa-eye"></i></button>
            </div>
            <div class="forgot"><a href="#">Forgot password?</a></div>
          </div>
          <button type="submit" class="btn-primary btn-admin" name="login_mode" value="admin">Sign in as Editor</button>
        </form>
      </div>
        </div>

          <div id="register" class="tab-panel <?php echo $activeTab === 'register' ? 'active' : ''; ?>">
            <div class="create-header">
              <h2>Create your account</h2>
              <p>Choose your account type to get started submitting or reviewing research.</p>
            </div>
            <div class="role-cards">
              <div class="role-card selected" id="card-author" onclick="selectCard('author')">
                <i class="fa fa-pencil"></i>
                <h3>Author</h3>
                <p>Submit papers and track your review status.</p>
                <span class="selected-badge" id="badge-author">Selected</span>
              </div>
              <div class="role-card" id="card-editor" onclick="selectCard('editor')">
                <i class="fa fa-shield"></i>
                <h3>Editor</h3>
                <p>Review submissions and publish accepted papers.</p>
                <span class="selected-badge" id="badge-editor" style="display:none;">Selected</span>
              </div>
            </div>
            <div id="reg-author-fields">
              <form method="post" action="login.php" class="signup-embedded" id="multiStepForm">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="register_submit" value="1">

          <div class="progress-container">
            <div class="progress-bar" id="progressBar"></div>
            <div class="step-indicator active">1</div>
            <div class="step-indicator">2</div>
            <div class="step-indicator">3</div>
            <div class="step-indicator">4</div>
          </div>

          <div class="form-step active" id="step-1">
            <h3>Account Details</h3>
            <div class="field-grid">
              <div class="field">
                <label for="username">Username</label>
                <div class="input-wrap">
                  <i class="fa fa-id-badge"></i>
                  <input type="text" id="username" name="username" placeholder="e.g. john.doe" value="<?php echo e($username); ?>" required />
                </div>
              </div>
              <div class="field">
                <label for="email">Email</label>
                <div class="input-wrap">
                  <i class="fa fa-envelope"></i>
                  <input type="email" id="email" name="email" placeholder="e.g. you@example.com" value="<?php echo e($email); ?>" required />
                </div>
              </div>
            </div>
            <div class="field-grid">
              <div class="field">
                <label for="password_reg">Password</label>
                <div class="input-wrap">
                  <i class="fa fa-lock"></i>
                  <input type="password" id="password_reg" name="password" placeholder="Minimum 8 characters" required />
                  <button class="eye-btn" type="button" onclick="togglePw('password_reg', this)"><i class="fa fa-eye"></i></button>
                </div>
              </div>
              <div class="field">
                <label for="password2_reg">Confirm password</label>
                <div class="input-wrap">
                  <i class="fa fa-lock"></i>
                  <input type="password" id="password2_reg" name="password2" placeholder="Confirm password" required />
                </div>
              </div>
            </div>
            <div class="step-actions">
              <button type="button" class="btn-primary" onclick="nextStep(1)">Next</button>
            </div>
          </div>

          <div class="form-step" id="step-2" style="display:none;">
            <h3>Personal Information</h3>
            <div class="field-grid">
              <div class="field">
                <label for="title">Title <small class="text-muted">(optional)</small></label>
                <div class="input-wrap">
                  <i class="fa fa-id-card"></i>
                  <input type="text" id="title" name="title" placeholder="Mr./Dr." value="<?php echo e($title); ?>" />
                </div>
              </div>
              <div class="field">
                <label for="first_name">First name</label>
                <div class="input-wrap">
                  <i class="fa fa-user"></i>
                  <input type="text" id="first_name" name="first_name" placeholder="e.g. John" value="<?php echo e($firstName); ?>" required />
                </div>
              </div>
            </div>
            <div class="field-grid">
              <div class="field">
                <label for="middle_name">Middle name <small class="text-muted">(optional)</small></label>
                <div class="input-wrap">
                  <i class="fa fa-user"></i>
                  <input type="text" id="middle_name" name="middle_name" placeholder="e.g. Robert" value="<?php echo e($middleName); ?>" />
                </div>
              </div>
              <div class="field">
                <label for="last_name">Last name</label>
                <div class="input-wrap">
                  <i class="fa fa-user"></i>
                  <input type="text" id="last_name" name="last_name" placeholder="e.g. Doe" value="<?php echo e($lastName); ?>" required />
                </div>
              </div>
            </div>
            <div class="field-grid">
              <div class="field">
                <label for="phone">Phone</label>
                <div class="input-wrap">
                  <i class="fa fa-phone"></i>
                  <input type="tel" id="phone" name="phone" placeholder="e.g. +1 234 567 8900" value="<?php echo e($phone); ?>" required />
                </div>
              </div>
              <div class="field">
                <label for="country">Country</label>
                <div class="input-wrap">
                  <i class="fa fa-globe"></i>
                  <input type="text" id="country" name="country" placeholder="e.g. India" value="<?php echo e($country); ?>" required />
                </div>
              </div>
            </div>
            <div class="step-actions">
              <button type="button" class="btn-secondary" onclick="prevStep(2)">Previous</button>
              <button type="button" class="btn-primary" onclick="nextStep(2)">Next</button>
            </div>
          </div>

          <div class="form-step" id="step-3" style="display:none;">
            <h3>Education / Institution</h3>
            <div class="field-grid">
              <div class="field">
                <label for="institution">Institution/School</label>
                <div class="input-wrap">
                  <i class="fa fa-university"></i>
                  <input type="text" id="institution" name="institution" placeholder="e.g. Oxford University" value="<?php echo e($institution); ?>" required />
                </div>
              </div>
              <div class="field">
                <label for="grade_level">Grade/Year Level</label>
                <div class="input-wrap">
                  <i class="fa fa-graduation-cap"></i>
                  <input type="text" id="grade_level" name="grade_level" placeholder="e.g. Undergraduate" value="<?php echo e($gradeLevel); ?>" required />
                </div>
              </div>
            </div>
            <div class="field-grid">
              <div class="field">
                <label for="school_email">School Email <small class="text-muted">(optional)</small></label>
                <div class="input-wrap">
                  <i class="fa fa-envelope-open"></i>
                  <input type="email" id="school_email" name="school_email" placeholder="e.g. student@school.edu" value="<?php echo e($schoolEmail); ?>" />
                </div>
                <div style="margin-top: 8px; display: flex; align-items: center; gap: 8px;">
                  <input type="checkbox" id="no_school_email" onchange="document.getElementById('school_email').disabled = this.checked; if(this.checked) document.getElementById('school_email').value='';" style="width: auto; margin: 0;">
                  <label for="no_school_email" style="margin: 0; font-size: 13px; font-weight: normal; color: var(--gysj-text-secondary); cursor: pointer;">My school doesn't provide an email</label>
                </div>
              </div>
              <div class="field">
                <label for="admission_number">Admission/ERP Number <small class="text-muted">(optional)</small></label>
                <div class="input-wrap">
                  <i class="fa fa-hashtag"></i>
                  <input type="text" id="admission_number" name="admission_number" placeholder="e.g. 12345678" value="<?php echo e($admissionNumber); ?>" />
                </div>
              </div>
            </div>
            <div class="step-actions">
              <button type="button" class="btn-secondary" onclick="prevStep(3)">Previous</button>
              <button type="button" class="btn-primary" onclick="nextStep(3)">Next</button>
            </div>
          </div>

          <div class="form-step" id="step-4" style="display:none;">
            <h3>Address</h3>
            <div class="field-grid">
              <div class="field">
                <label for="city">City</label>
                <div class="input-wrap">
                  <i class="fa fa-map-marker"></i>
                  <input type="text" id="city" name="city" placeholder="e.g. New York" value="<?php echo e($city); ?>" required />
                </div>
              </div>
              <div class="field">
                <label for="state">State/Province</label>
                <div class="input-wrap">
                  <i class="fa fa-map"></i>
                  <input type="text" id="state" name="state" placeholder="e.g. NY" value="<?php echo e($state); ?>" required />
                </div>
              </div>
            </div>
            <div class="field-grid">
              <div class="field">
                <label for="postal_code">Postal Code</label>
                <div class="input-wrap">
                  <i class="fa fa-mail-bulk"></i>
                  <input type="text" id="postal_code" name="postal_code" placeholder="e.g. 10001" value="<?php echo e($postalCode); ?>" required />
                </div>
              </div>
              <div></div>
            </div>
            <div class="step-actions">
              <button type="button" class="btn-secondary" onclick="prevStep(4)">Previous</button>
              <button type="submit" class="btn-primary btn-author">Create account</button>
            </div>
          </div>
        </form>
      </div>

          <div id="reg-editor-cta" style="display:none;">
            <div class="admin-note">
              <i class="fa fa-info-circle"></i>
              <p>Editor accounts require approval from the editorial board. Please apply and upload a CV for review by existing admins.</p>
            </div>
            <a class="btn-admin" href="admin-signup.php">Apply for editor access</a>
          </div>
          <div class="divider"><div class="divider-line"></div><span>already have an account?</span><div class="divider-line"></div></div>
          <div class="sign-up-row"><span class="link" style="cursor:pointer;" onclick="switchTabByName('login')">Sign in →</span></div>
        </div>
      </div>
    </div>

    <div class="faq-card">
      <div class="faq-section">
        <div class="faq-title">Frequently asked</div>
        <div class="faq-item">
          <div class="faq-q" onclick="toggleFaq(this)">What's the difference between authors and editors?<i class="fa fa-chevron-down"></i></div>
          <div class="faq-a">Authors (regular users) submit research papers and track their review status. Editors are the editorial team — they review submissions, leave comments, and publish accepted papers.</div>
        </div>
        <div class="faq-item">
          <div class="faq-q" onclick="toggleFaq(this)">How do I apply to become an editor?<i class="fa fa-chevron-down"></i></div>
          <div class="faq-a">Editor accounts require approval from the editorial board. You can apply via the "Apply to join the editorial team" link in the Editor sign-in panel. Approval typically takes 2–3 business days.</div>
        </div>
        <div class="faq-item">
          <div class="faq-q" onclick="toggleFaq(this)">Can I have both an author and editor account?<i class="fa fa-chevron-down"></i></div>
          <div class="faq-a">Yes — but you'll need separate accounts for each role. We recommend using different email addresses to avoid confusion.</div>
        </div>
      </div>
    </div>
  </div>

  <script src="js/jquery.min.js"></script>
  <script src="js/tether.min.js" crossorigin="anonymous"></script>
  <script src="js/bootstrap.min.js" crossorigin="anonymous"></script>
  <script>
    function switchTab(id, el) {
      document.querySelectorAll('.page-tab').forEach(t => t.classList.remove('active'));
      document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
      if (el) el.classList.add('active');
      document.getElementById(id).classList.add('active');
    }
    function switchTabByName(id) {
      var tabs = document.querySelectorAll('.page-tab');
      var idx = id === 'login' ? 0 : 1;
      switchTab(id, tabs[idx]);
    }
    function switchRole(role, btn) {
      document.querySelectorAll('.role-btn').forEach(b => b.classList.remove('active'));
      if (btn) btn.classList.add('active');
      document.getElementById('author-form').style.display = role === 'author' ? 'block' : 'none';
      document.getElementById('admin-form').style.display = role === 'admin' ? 'block' : 'none';
    }
    function togglePw(id, btn) {
      var inp = document.getElementById(id);
      if (!inp) return;
      var isText = inp.type === 'text';
      inp.type = isText ? 'password' : 'text';
      var icon = btn && btn.querySelector && btn.querySelector('i');
      if (icon) {
        icon.classList.toggle('fa-eye');
        icon.classList.toggle('fa-eye-slash');
      }
    }
    function toggleFaq(el) {
      el.parentElement.classList.toggle('open');
    }
    function selectCard(role) {
      ['author','editor'].forEach(r => {
        var card = document.getElementById('card-' + r);
        if (card) card.classList.remove('selected');
        var badge = document.getElementById('badge-' + r);
        if (badge) badge.style.display = 'none';
      });
      var sel = document.getElementById('card-' + role);
      if (sel) sel.classList.add('selected');
      var selBadge = document.getElementById('badge-' + role);
      if (selBadge) selBadge.style.display = 'inline-block';
      // Toggle registration fields vs editor CTA
      var authorFields = document.getElementById('reg-author-fields');
      var editorCta = document.getElementById('reg-editor-cta');
      if (authorFields && editorCta) {
        if (role === 'editor') {
          authorFields.style.display = 'none';
          editorCta.style.display = 'block';
        } else {
          authorFields.style.display = 'block';
          editorCta.style.display = 'none';
        }
      }
    }
    document.addEventListener('DOMContentLoaded', function(){
      // Ensure initial states
      switchRole('author', document.getElementById('btn-author'));
      selectCard('author');
    });
  
    function updateProgress(currentStep) {
      var steps = document.querySelectorAll('.step-indicator');
      var progress = document.getElementById('progressBar');
      steps.forEach(function(step, idx) {
        if (idx < currentStep) {
          step.classList.add('active');
        } else {
          step.classList.remove('active');
        }
      });
      progress.style.width = ((currentStep - 1) / (steps.length - 1)) * 100 + '%';
    }

    function validateStep(step) {
      var stepEl = document.getElementById('step-' + step);
      var inputs = stepEl.querySelectorAll('input[required]');
      var valid = true;
      inputs.forEach(function(inp) {
        if (!inp.checkValidity()) {
          inp.reportValidity();
          valid = false;
        }
      });
      return valid;
    }

    function nextStep(step) {
      if (!validateStep(step)) return;
      document.getElementById('step-' + step).style.display = 'none';
      document.getElementById('step-' + (step + 1)).style.display = 'block';
      updateProgress(step + 1);
    }

    function prevStep(step) {
      document.getElementById('step-' + step).style.display = 'none';
      document.getElementById('step-' + (step - 1)).style.display = 'block';
      updateProgress(step - 1);
    }

  </script>
</body>

</html>
