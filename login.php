<?php
declare(strict_types=1);

require_once __DIR__ . '/app/includes/auth.php';

if (is_logged_in()) {
    redirect(permitted_landing_page(current_user()));
}

$error = '';
$hasAdmin = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() > 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $username = normalize_lower((string) ($_POST['username'] ?? '')) ?? '';
    $password = (string) ($_POST['password'] ?? '');

    $retryAfter = login_retry_after($username);
    if ($retryAfter > 0) {
        $error = 'Too many sign-in attempts. Try again in ' . max(1, (int) ceil($retryAfter / 60)) . ' minutes.';
    } else {
        $statement = db()->prepare('SELECT id, full_name, username, password_hash, role, status FROM users WHERE username = :username LIMIT 1');
        $statement->execute(['username' => $username]);
        $user = $statement->fetch();

        if ($user && $user['status'] === 'active' && password_verify($password, $user['password_hash'])) {
            clear_failed_logins($username);
            login_user($user);
            redirect(permitted_landing_page($user));
        }

        record_failed_login($username);
        $error = 'Invalid username or password.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Log in | Dormitory System</title>
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="login-page">
  <span class="login-orb login-orb-one" aria-hidden="true"></span>
  <span class="login-orb login-orb-two" aria-hidden="true"></span>
  <main class="login-shell">
    <section class="login-brand-panel" aria-label="Dormitory management overview">
      <div class="login-brand-mark"><span><svg viewBox="0 0 24 24"><path d="M4 21V9l8-6 8 6v12M8 21v-7h8v7M9 10h.01M15 10h.01"/></svg></span><strong>Dormitory System</strong></div>
      <div class="login-brand-copy">
        <p class="eyebrow">Housing made manageable</p>
        <h2>One calm place to run every residence.</h2>
        <p>Keep rooms, tenants, payments, visitors, and daily operations connected—without the paperwork maze.</p>
      </div>
      <div class="login-building-art" aria-hidden="true">
        <svg viewBox="0 0 520 250" role="img">
          <path class="art-ground" d="M22 220h476"/>
          <path class="art-building" d="M98 219V68l162-44 162 44v151"/>
          <path class="art-wing" d="M45 219v-98l53-22M475 219v-98l-53-22"/>
          <path class="art-detail" d="M260 24v195M98 68h324M98 113h324M98 161h324"/>
          <path class="art-detail" d="M136 84v15M177 84v15M218 84v15M302 84v15M343 84v15M384 84v15M136 129v16M177 129v16M218 129v16M302 129v16M343 129v16M384 129v16"/>
          <path class="art-door" d="M231 219v-37h58v37"/>
          <path class="art-accent" d="M244 52h32M260 36v32"/>
        </svg>
      </div>
      <div class="login-trust-row"><span><b>✓</b> Secure access</span><span><b>✓</b> Centralized records</span><span><b>✓</b> Role controls</span></div>
    </section>
    <section class="login-card">
      <div class="login-mobile-mark"><span><svg viewBox="0 0 24 24"><path d="M4 21V9l8-6 8 6v12M8 21v-7h8v7"/></svg></span>Dormitory System</div>
      <div class="login-heading">
        <span class="login-welcome-icon"><svg viewBox="0 0 24 24"><path d="M12 3v4M5.64 5.64l2.83 2.83M3 12h4M19 12h2M16.5 8.5l2.86-2.86M7 21h10M8 17h8M9 14a5 5 0 1 1 6 0c-.8.55-1 1.2-1 2h-4c0-.8-.2-1.45-1-2Z"/></svg></span>
        <p class="eyebrow">Housing Registry</p>
        <h1>Welcome back</h1>
        <p>Sign in to continue to your dormitory workspace.</p>
      </div>
      <?php if ($error !== ''): ?><p class="alert login-alert" role="alert"><strong>Sign-in failed</strong><span><?= e($error) ?></span></p><?php endif; ?>
      <form method="post" novalidate class="login-form">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <label>Username<span class="login-input-wrap"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg><input name="username" autocomplete="username" placeholder="Enter your username" required autofocus></span></label>
        <label>Password<span class="login-input-wrap"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg><input id="login-password" type="password" name="password" autocomplete="current-password" placeholder="Enter your password" required><button class="password-toggle" type="button" aria-controls="login-password" aria-label="Show password">Show</button></span></label>
        <button class="primary login-submit" type="submit"><span>Log in securely</span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></button>
      </form>
      <div class="login-divider"><span>Administrator access</span></div>
      <?php if (!$hasAdmin): ?><p class="help login-setup">First installation? <a href="setup.php">Create the initial admin account <span aria-hidden="true">→</span></a></p><?php endif; ?>
      <p class="login-security"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/></svg>Your session is protected by secure authentication.</p>
    </section>
  </main>
  <script>
    const passwordToggle = document.querySelector('.password-toggle');
    const passwordInput = document.getElementById('login-password');
    passwordToggle?.addEventListener('click', () => {
      const reveal = passwordInput.type === 'password';
      passwordInput.type = reveal ? 'text' : 'password';
      passwordToggle.textContent = reveal ? 'Hide' : 'Show';
      passwordToggle.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
    });
  </script>
</body>
</html>
