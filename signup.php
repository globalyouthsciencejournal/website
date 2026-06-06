<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (auth_is_logged_in()) {
    $user = auth_current_user();
    if ($user) {
        auth_redirect_dashboard($user);
    }
}

$error = '';

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

  if ($username === '' || $email === '' || $phone === '' || $country === '' || $firstName === '' || $lastName === '' || $position === '' || $institution === '' || $gradeLevel === '' || $schoolName === '' || $city === '' || $state === '' || $postalCode === '' || $password === '') {
        $error = 'Please fill in all required fields.';
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
              } elseif (strpos($msg, 'uniq_users_username') !== false) {
                $error = 'That username is already taken. Please choose another.';
              } elseif (strpos($msg, 'not configured') !== false || strpos($msg, 'configuration is missing') !== false) {
                $error = 'Sign up is unavailable because the database is not configured. Please set DB_* environment variables or update includes/config.local.php.';
              } else {
                $error = 'Sign up is temporarily unavailable. Please try again later.';
              }
        }
    }
}
else {
  // For non-POST (GET) requests, redirect to the consolidated create-account section on login.php
  header('Location: login.php#register');
  exit;
}
?>
<!DOCTYPE html>
<html lang="en" class="no-js">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <link rel="shortcut icon" type="image/jpg" href="images/iysjournal.png">
  <title>Sign Up | Global Youth Science Journal</title>
  <link href="css/media_query.css" rel="stylesheet" type="text/css">
  <link href="css/style.css" rel="stylesheet" type="text/css">
  <link href="css/bootstrap.css" rel="stylesheet" type="text/css">
  <link href="css/font-awesome.min.css" rel="stylesheet" crossorigin="anonymous">
  <link href="css/animate.css" rel="stylesheet" type="text/css">
  <link href="https://fonts.googleapis.com/css?family=Poppins" rel="stylesheet">
  <link href="css/owl.carousel.css" rel="stylesheet" type="text/css">
  <link href="css/owl.theme.default.css" rel="stylesheet" type="text/css">
  <link href="css/style_1.css" rel="stylesheet" type="text/css">
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

  <div class="container" style="padding: 30px 15px; max-width: 980px;">
    <div class="card shadow-sm">
      <div class="card-body">
        <h1 class="h4 mb-3">Create Account</h1>

        <?php if ($error !== ''): ?>
          <div class="alert alert-danger" role="alert"><?php echo e($error); ?></div>
        <?php endif; ?>

        <form method="post" action="signup.php">
          <?php echo csrf_field(); ?>

          <h2 class="h5">Basic Account Info</h2>
          <div class="form-row">
            <div class="form-group col-md-6">
              <label for="username">Username</label>
              <input type="text" class="form-control" id="username" name="username" autocomplete="username" value="<?php echo e($username); ?>" required>
              <small class="form-text text-muted">Use a professional ID like <em>tuhin.sarkar</em>.</small>
            </div>
            <div class="form-group col-md-6">
              <label for="email">Email</label>
              <input type="email" class="form-control" id="email" name="email" autocomplete="email" value="<?php echo e($email); ?>" required>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group col-md-6">
              <label for="phone">Phone Number</label>
              <input type="tel" class="form-control" id="phone" name="phone" autocomplete="tel" value="<?php echo e($phone); ?>" placeholder="+91XXXXXXXXXX" required>
            </div>
            <div class="form-group col-md-6">
              <label for="country">Country</label>
              <input type="text" class="form-control" id="country" name="country" autocomplete="country-name" value="<?php echo e($country); ?>" placeholder="India" required>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group col-md-6">
              <label for="password">Password</label>
              <input type="password" class="form-control" id="password" name="password" autocomplete="new-password" required>
              <small class="form-text text-muted">Minimum 8 characters.</small>
            </div>
            <div class="form-group col-md-6">
              <label for="password2">Confirm Password</label>
              <input type="password" class="form-control" id="password2" name="password2" autocomplete="new-password" required>
            </div>
          </div>

          <hr>
          <h2 class="h5">Personal Information</h2>
          <div class="form-row">
            <div class="form-group col-md-3">
              <label for="title">Title <small class="text-muted">(optional)</small></label>
              <input type="text" class="form-control" id="title" name="title" autocomplete="honorific-prefix" value="<?php echo e($title); ?>" placeholder="Mr.">
            </div>
            <div class="form-group col-md-3">
              <label for="first_name">First Name</label>
              <input type="text" class="form-control" id="first_name" name="first_name" autocomplete="given-name" value="<?php echo e($firstName); ?>" required>
            </div>
            <div class="form-group col-md-3">
              <label for="middle_name">Middle Name <small class="text-muted">(optional)</small></label>
              <input type="text" class="form-control" id="middle_name" name="middle_name" autocomplete="additional-name" value="<?php echo e($middleName); ?>">
            </div>
            <div class="form-group col-md-3">
              <label for="last_name">Last Name</label>
              <input type="text" class="form-control" id="last_name" name="last_name" autocomplete="family-name" value="<?php echo e($lastName); ?>" required>
            </div>
          </div>

          <hr>
          <h2 class="h5">Institution Information</h2>
          <div class="form-row">
            <div class="form-group col-md-4">
              <label for="position">Position</label>
              <input type="text" class="form-control" id="position" name="position" value="<?php echo e($position); ?>" placeholder="Student" required>
            </div>
            <div class="form-group col-md-4">
              <label for="institution">Institution</label>
              <input type="text" class="form-control" id="institution" name="institution" autocomplete="organization" value="<?php echo e($institution); ?>" placeholder="Glentree Academy" required>
            </div>
            <div class="form-group col-md-4">
              <label for="department">Department <small class="text-muted">(optional)</small></label>
              <input type="text" class="form-control" id="department" name="department" value="<?php echo e($department); ?>" placeholder="N/A">
            </div>
          </div>

          <hr>
          <h2 class="h5">Student-Specific Fields</h2>
          <div class="form-row">
            <div class="form-group col-md-6">
              <label for="grade_level">Grade/Year Level</label>
              <input type="text" class="form-control" id="grade_level" name="grade_level" value="<?php echo e($gradeLevel); ?>" placeholder="Grade 9" required>
            </div>
            <div class="form-group col-md-6">
              <label for="school_name">School/College Name</label>
              <input type="text" class="form-control" id="school_name" name="school_name" value="<?php echo e($schoolName); ?>" placeholder="Glentree Academy" required>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group col-md-6">
              <label for="school_email">School Email <small class="text-muted">(optional)</small></label>
              <input type="email" class="form-control" id="school_email" name="school_email" value="<?php echo e($schoolEmail); ?>" placeholder="(leave blank if not required)">
            </div>
            <div class="form-group col-md-6">
              <label for="admission_number">Admission/ERP Number <small class="text-muted">(optional)</small></label>
              <input type="text" class="form-control" id="admission_number" name="admission_number" value="<?php echo e($admissionNumber); ?>" placeholder="(only if required)">
            </div>
          </div>

          <hr>
          <h2 class="h5">Address Information</h2>
          <div class="form-row">
            <div class="form-group col-md-4">
              <label for="city">City</label>
              <input type="text" class="form-control" id="city" name="city" value="<?php echo e($city); ?>" placeholder="Bengaluru" required>
            </div>
            <div class="form-group col-md-4">
              <label for="state">State/Province</label>
              <input type="text" class="form-control" id="state" name="state" value="<?php echo e($state); ?>" placeholder="Karnataka" required>
            </div>
            <div class="form-group col-md-4">
              <label for="postal_code">Postal Code</label>
              <input type="text" class="form-control" id="postal_code" name="postal_code" value="<?php echo e($postalCode); ?>" placeholder="(your PIN code)" required>
            </div>
          </div>

          <button type="submit" class="btn btn-primary">Create account</button>
          <a href="login.php" class="btn btn-link">Already have an account?</a>
        </form>
      </div>
    </div>
  </div>

  <script src="js/jquery.min.js"></script>
  <script src="js/tether.min.js" crossorigin="anonymous"></script>
  <script src="js/bootstrap.min.js" crossorigin="anonymous"></script>
</body>

</html>
