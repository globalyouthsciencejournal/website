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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();

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
      --gysj-accent-dark: #d79b00;
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
      background: #f8fafc;
      position: relative;
      padding-right: 44px;
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
      background: transparent;
    }

    .field input:focus,
    .eye-btn:focus-visible,
    .btn-primary:focus-visible,
    .btn-admin:focus-visible,
    .role-btn:focus-visible {
      outline: 3px solid rgba(215, 155, 0, 0.24);
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
    .field .input-wrap,
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
            <li class="nav-item">
              <a class="nav-link" href="index.php">Home</a>
            </li>
            <li class="nav-item">
              <a class="nav-link" href="publication.php">Publication</a>
            </li>
            <li class="nav-item ">
              <a class="nav-link" href="editorial-board.php">Editorial Board</a>
            </li>
            <li class="nav-item dropdown">
              <a class="nav-link dropdown-toggle" href="#" id="dropdownMenuButton2" data-toggle="dropdown"
                aria-haspopup="true" aria-expanded="false">About Us</a>
              <div class="dropdown-menu" aria-labelledby="dropdownMenuButton2">
                <a class="dropdown-item" href="our-founders.php">Our Founders</a>
                <a class="dropdown-item" href="our-mission.php">Our Mission</a>
                <a class="dropdown-item" href="our-funding.php">Our Funding</a>
              </div>
            </li>
            <li class="nav-item dropdown">
              <a class="nav-link dropdown-toggle" href="#" id="dropdownMenuButton3" data-toggle="dropdown"
                aria-haspopup="true" aria-expanded="false">Paper Submissions</a>
              <div class="dropdown-menu" aria-labelledby="dropdownMenuButton3">
                <a class="dropdown-item" href="user-dashboard.php?view=submit">Online Submission</a>
                <a class="dropdown-item" href="call-for-paper.php">Call for Paper</a>
                <a class="dropdown-item" href="authorguidelines.php">Guidelines for authors</a>
                <a class="dropdown-item" href="copyright.php">Copyright</a>
              </div>
            </li>
            <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="dropdownSupport" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Support GYSJ</a>
                            <div class="dropdown-menu" aria-labelledby="dropdownSupport">
                                <a class="dropdown-item" href="contribute.php">Contribute</a>
                                <a class="dropdown-item" href="partners.php">Partners</a>
                            </div>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="contact.php">Contact Us</a>
                        </li>

            <li class="nav-item">
              <a class="nav-link btn btn-primary btn-sm text-white px-3" href="login.php" style="margin-top:4px; margin-left:8px;">Login / Sign Up</a>
            </li>
          </ul>
        </div>
      </nav>
    </div>
  </div>

  <div class="wrap">
    <div class="login-page-title">Login</div>
    <div class="login-card">
      <div class="page-tabs">
        <div class="page-tab active" onclick="switchTab('login', this)">Sign in</div>
        <div class="page-tab" onclick="switchTab('register', this)">Create account</div>
      </div>

      <div class="login-card-body">
        <div id="login" class="tab-panel active">
          <div class="role-toggle">
            <button class="role-btn active" onclick="switchRole('author', this)" id="btn-author"><i class="fa fa-pencil" style="font-size:13px; margin-right:6px;"></i>Author</button>
            <button class="role-btn" onclick="switchRole('admin', this)" id="btn-admin"><i class="fa fa-shield" style="font-size:13px; margin-right:6px;"></i>Editor / Admin</button>
          </div>

      <?php if ($error !== ''): ?>
        <div class="alert alert-danger" role="alert"><?php echo e($error); ?></div>
      <?php endif; ?>

      <div id="author-form">
        <form method="post" action="login.php">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="redirect" value="<?php echo e($redirectValue); ?>">
          <div class="field">
            <label>Email address</label>
            <div class="input-wrap">
              <i class="fa fa-envelope"></i>
              <input type="email" name="email" id="email-author" placeholder="you@example.com" required />
            </div>
          </div>
          <div class="field">
            <label>Password</label>
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
            <label>Institutional email</label>
            <div class="input-wrap">
              <i class="fa fa-envelope"></i>
              <input type="email" name="email" id="email-admin" placeholder="you@institution.edu" required />
            </div>
          </div>
          <div class="field">
            <label>Password</label>
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

          <div id="register" class="tab-panel">
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
              <form method="post" action="signup.php" class="signup-embedded">
          <?php echo csrf_field(); ?>

          <div class="field">
            <label for="username">Username</label>
            <div class="input-wrap">
              <i class="fa fa-id-badge"></i>
              <input type="text" id="username" name="username" placeholder="username" required />
            </div>
            <small class="form-text text-muted">Use a professional ID like tuhin.sarkar</small>
          </div>

          <div class="field">
            <label for="email">Email</label>
            <div class="input-wrap">
              <i class="fa fa-envelope"></i>
              <input type="email" id="email" name="email" placeholder="you@example.com" required />
            </div>
          </div>

          <div class="field">
            <label for="phone">Phone</label>
            <div class="input-wrap">
              <i class="fa fa-phone"></i>
              <input type="tel" id="phone" name="phone" placeholder="+91XXXXXXXXXX" required />
            </div>
          </div>

          <div class="field">
            <label for="country">Country</label>
            <div class="input-wrap">
              <i class="fa fa-globe"></i>
              <input type="text" id="country" name="country" placeholder="Country" required />
            </div>
          </div>

          <div class="field">
            <label for="password">Password</label>
            <div class="input-wrap">
              <i class="fa fa-lock"></i>
              <input type="password" id="password_reg" name="password" placeholder="Minimum 8 characters" required />
              <button class="eye-btn" type="button" onclick="togglePw('password_reg', this)"><i class="fa fa-eye"></i></button>
            </div>
          </div>

          <div class="field">
            <label for="password2">Confirm password</label>
            <div class="input-wrap">
              <i class="fa fa-lock"></i>
              <input type="password" id="password2_reg" name="password2" placeholder="Confirm password" required />
            </div>
          </div>

          <hr>
          <div class="field">
            <label for="title">Title <small class="text-muted">(optional)</small></label>
            <div class="input-wrap">
              <i class="fa fa-id-card"></i>
              <input type="text" id="title" name="title" placeholder="Mr./Dr." />
            </div>
          </div>

          <div class="field">
            <label>First name</label>
            <div class="input-wrap">
              <i class="fa fa-user"></i>
              <input type="text" id="first_name" name="first_name" required />
            </div>
          </div>

          <div class="field">
            <label>Middle name <small class="text-muted">(optional)</small></label>
            <div class="input-wrap">
              <i class="fa fa-user"></i>
              <input type="text" id="middle_name" name="middle_name" />
            </div>
          </div>

          <div class="field">
            <label>Last name</label>
            <div class="input-wrap">
              <i class="fa fa-user"></i>
              <input type="text" id="last_name" name="last_name" required />
            </div>
          </div>

          <hr>
          <div class="field">
            <label>Position</label>
            <div class="input-wrap">
              <i class="fa fa-briefcase"></i>
              <input type="text" id="position" name="position" placeholder="Student/Researcher" required />
            </div>
          </div>

          <div class="field">
            <label>Institution</label>
            <div class="input-wrap">
              <i class="fa fa-university"></i>
              <input type="text" id="institution" name="institution" required />
            </div>
          </div>

          <div class="field">
            <label>Department <small class="text-muted">(optional)</small></label>
            <div class="input-wrap">
              <i class="fa fa-building"></i>
              <input type="text" id="department" name="department" />
            </div>
          </div>

          <hr>
          <div class="field">
            <label>Grade/Year Level</label>
            <div class="input-wrap">
              <i class="fa fa-graduation-cap"></i>
              <input type="text" id="grade_level" name="grade_level" required />
            </div>
          </div>

          <div class="field">
            <label>School/College Name</label>
            <div class="input-wrap">
              <i class="fa fa-school"></i>
              <input type="text" id="school_name" name="school_name" required />
            </div>
          </div>

          <div class="field">
            <label>School Email <small class="text-muted">(optional)</small></label>
            <div class="input-wrap">
              <i class="fa fa-envelope-open"></i>
              <input type="email" id="school_email" name="school_email" />
            </div>
          </div>

          <div class="field">
            <label>Admission/ERP Number <small class="text-muted">(optional)</small></label>
            <div class="input-wrap">
              <i class="fa fa-hashtag"></i>
              <input type="text" id="admission_number" name="admission_number" />
            </div>
          </div>

          <hr>
          <div class="field">
            <label>City</label>
            <div class="input-wrap">
              <i class="fa fa-map-marker"></i>
              <input type="text" id="city" name="city" required />
            </div>
          </div>

          <div class="field">
            <label>State/Province</label>
            <div class="input-wrap">
              <i class="fa fa-map"></i>
              <input type="text" id="state" name="state" required />
            </div>
          </div>

          <div class="field">
            <label>Postal Code</label>
            <div class="input-wrap">
              <i class="fa fa-mail-bulk"></i>
              <input type="text" id="postal_code" name="postal_code" required />
            </div>
          </div>

          <button type="submit" class="btn-primary btn-author">Create account</button>
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
  </script>
</body>

</html>
