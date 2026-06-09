<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/mailer.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
    $email = trim((string)($_POST['email'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        try {
            $pdo = db();
            $stmt = $pdo->prepare('SELECT id, name FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user) {
                // Generate token and expiry
                $token = bin2hex(random_bytes(32));
                // Set expiry to 1 hour from now
                $expires = date('Y-m-d H:i:s', time() + 3600);

                $updateStmt = $pdo->prepare('UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?');
                $updateStmt->execute([$token, $expires, $user['id']]);

                // Prepare email
                $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $resetLink = $scheme . '://' . $host . '/reset-password.php?token=' . urlencode($token);

                $subject = "Password Reset - Global Youth Science Journal";
                $body = "
                <p>Hello {$user['name']},</p>
                <p>You requested a password reset for your account at Global Youth Science Journal.</p>
                <p>Please click the link below to reset your password. This link will expire in 1 hour.</p>
                <p><a href=\"{$resetLink}\">Reset Password</a></p>
                <p>If you did not request this, please ignore this email.</p>
                ";

                if (send_email($email, $subject, $body, $user['name'])) {
                    $success = 'If that email is in our system, we have sent a password reset link to it.';
                } else {
                    $error = 'Failed to send the email. Please try again later.';
                }
            } else {
                // Do not reveal if email exists or not
                $success = 'If that email is in our system, we have sent a password reset link to it.';
            }
        } catch (Throwable $e) {
            error_log('Forgot password error: ' . $e);
            $error = 'An error occurred. Please try again later.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="no-js">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <link rel="shortcut icon" type="image/jpg" href="images/iysjournal.png">
  <title>Forgot Password | Global Youth Science Journal</title>
  <link href="css/style.css" rel="stylesheet" type="text/css">
  <link href="css/bootstrap.css" rel="stylesheet" type="text/css">
  <link href="https://fonts.googleapis.com/css?family=Poppins" rel="stylesheet">
  <link href="css/login.css" rel="stylesheet" type="text/css">
  <style>
    body { background: #f8fafc; font-family: 'Poppins', sans-serif; }
    .auth-card { max-width: 500px; margin: 60px auto; background: #fff; border: 1px solid #e5e7eb; padding: 30px; box-shadow: 0 10px 30px rgba(31,41,55,0.06); }
    .auth-title { font-size: 24px; font-weight: 600; margin-bottom: 20px; text-align: center; }
    .btn-primary { background: #f0b429; border-color: #8a5a00; color: #1f2937; }
    .btn-primary:hover { background: #e6a800; }
    .form-control { border-radius: 4px; border: 1px solid #e5e7eb; padding: 10px 15px; }
  </style>
</head>
<body>
  <div class="container">
    <div class="auth-card">
      <div class="text-center mb-4">
        <a href="index.php"><img src="images/iysjournal.png" alt="Logo" style="height: 60px;"></a>
      </div>
      <h2 class="auth-title">Reset Password</h2>
      <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
      <?php endif; ?>
      <?php if ($success !== ''): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
      <?php else: ?>
        <p class="text-muted text-center mb-4">Enter your email address and we'll send you a link to reset your password.</p>
        <form method="post" action="forgot-password.php">
          <?php csrf_field(); ?>
          <div class="form-group mb-4">
            <label for="email">Email Address</label>
            <input type="email" name="email" id="email" class="form-control" required autofocus>
          </div>
          <button type="submit" class="btn btn-primary w-100 py-2 font-weight-bold">Send Reset Link</button>
        </form>
      <?php endif; ?>
      <div class="text-center mt-4">
        <a href="login.php" class="text-muted"><i class="fa fa-arrow-left"></i> Back to Login</a>
      </div>
    </div>
  </div>
</body>
</html>
