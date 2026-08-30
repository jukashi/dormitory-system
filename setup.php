<?php
declare(strict_types=1);

require_once __DIR__ . '/app/includes/auth.php';

$adminCount = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
if ($adminCount > 0) {
    redirect('login.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $fullName = normalize_upper((string) ($_POST['full_name'] ?? '')) ?? '';
    $username = normalize_lower((string) ($_POST['username'] ?? '')) ?? '';
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if ($fullName === '' || !preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
        $error = 'Enter your name and a username of 3–50 letters, numbers, dots, dashes, or underscores.';
    } elseif (strlen($password) < 10) {
        $error = 'Use a password with at least 10 characters.';
    } elseif ($password !== $confirmPassword) {
        $error = 'The passwords do not match.';
    } else {
        $statement = db()->prepare('INSERT INTO users (full_name, username, password_hash, role, status) VALUES (:full_name, :username, :password_hash, \'admin\', \'active\')');
        try {
            $statement->execute([
                'full_name' => $fullName,
                'username' => $username,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
            login_user(['id' => (int) db()->lastInsertId(), 'full_name' => $fullName, 'username' => $username, 'role' => 'admin']);
            redirect('dashboard.php');
        } catch (PDOException $exception) {
            $error = 'That username is already in use. Choose another one.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Initial setup | OFW Dormitory System</title>
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="login-page">
  <main class="login-card">
    <p class="eyebrow">First-time setup</p>
    <h1>Create the administrator account</h1>
    <p class="muted">This page stops working automatically after the first admin is created.</p>
    <?php if ($error !== ''): ?><p class="alert" role="alert"><?= e($error) ?></p><?php endif; ?>
    <form method="post" novalidate>
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <label>Full name<input name="full_name" required autofocus></label>
      <label>Username<input name="username" required></label>
      <label>Password <small>(at least 10 characters)</small><input type="password" name="password" autocomplete="new-password" required></label>
      <label>Confirm password<input type="password" name="confirm_password" autocomplete="new-password" required></label>
      <button class="primary" type="submit">Create administrator account</button>
    </form>
    <p class="help"><a href="login.php">Back to login</a></p>
  </main>
</body>
</html>
