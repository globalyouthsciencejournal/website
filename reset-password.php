<?php
require_once __DIR__ . '/includes/bootstrap.php';

$error = '';
$success = '';
$token = $_GET['token'] ?? ($_POST['token'] ?? '');

if ($token === '') {
    $error = 'Invalid or missing reset token.';
} else {
    try {
        $pdo = db();
        // Check if token exists and hasn't expired
        // Works for SQLite, Postgres, MySQL
        $stmt = $pdo->prepare('SELECT id, reset_expires FROM users WHERE reset_token = ? LIMIT 1');
        $stmt->execute([$token]);
        $user = $stmt->fetch();

        if (!$user) {
            $error = 'Invalid or expired reset token.';
        } else {
            // Check expiry
            $expires = strtotime($user['reset_expires']);
            if (time() > $expires) {
                $error = 'This reset token has expired. Please request a new one.';
            } else if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                csrf_validate();
                $password = (string)($_POST['password'] ?? '');
                $password_confirm = (string)($_POST['password_confirm'] ?? '');

                if (strlen($password) < 8) {
                    $error = 'Password must be at least 8 characters long.';
                } else if ($password !== $password_confirm) {
                    $error = 'Passwords do not match.';
                } else {
                    // Update password and clear token
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $updateStmt = $pdo->prepare('UPDATE users SET password_hash = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?');
                    $updateStmt->execute([$hash, $user['id']]);

                    $success = 'Your password has been reset successfully. You can now log in.';
                }
            }
        }
    } catch (Throwable $e) {
        error_log('Reset password error: ' . $e);
        $error = 'An error occurred. Please try again later.';
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="no-js">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <link rel="shortcut icon" type="image/jpg" href="images/iysjournal.png">
  <title>Reset Password | Global Youth Science Journal</title>
  <link href="css/style.css" rel="stylesheet" type="text/css">
  <link href="css/bootstrap.css" rel="stylesheet" type="text/css">
  <link href="https://fonts.googleapis.com/css?family=Poppins" rel="stylesheet">
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
      <h2 class="auth-title">Set New Password</h2>
      <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
      <?php endif; ?>
      <?php if ($success !== ''): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <div class="text-center mt-4">
          <a href="login.php" class="btn btn-primary w-100 py-2 font-weight-bold">Go to Login</a>
        </div>
      <?php elseif ($error === '' || $error === 'Passwords do not match.' || strpos($error, '8 characters') !== false): ?>
        <form method="post" action="reset-password.php">
          <?php csrf_field(); ?>
          <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
          <div class="form-group mb-3">
            <label for="password">New Password</label>
            <input type="password" name="password" id="password" class="form-control" required>
          </div>
          <div class="form-group mb-4">
            <label for="password_confirm">Confirm New Password</label>
            <input type="password" name="password_confirm" id="password_confirm" class="form-control" required>
          </div>
          <button type="submit" class="btn btn-primary w-100 py-2 font-weight-bold">Reset Password</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
