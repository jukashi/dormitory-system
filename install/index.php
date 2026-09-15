<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_trans_sid', '0');
header_remove('X-Powered-By');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; img-src 'self' data:; style-src 'self'; script-src 'self'");
header('Cache-Control: no-store, private');

$remoteAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
if (!in_array($remoteAddress, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('For security, initial installation must be completed from the computer running the server.');
}

$projectRoot = dirname(__DIR__);
$envPath = $projectRoot . DIRECTORY_SEPARATOR . '.env';
$configured = is_file($envPath);

final class InstallException extends RuntimeException
{
}

$scriptPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/install/index.php'));
$cookiePath = rtrim(dirname(dirname($scriptPath)), '/') . '/';
session_name('ofw_dormitory_session');
session_set_cookie_params([
    'path' => $cookiePath === '//' ? '/' : $cookiePath,
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

function install_e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function install_csrf_token(): string
{
    if (empty($_SESSION['install_csrf_token'])) {
        $_SESSION['install_csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['install_csrf_token'];
}

function install_env_value(string $value): string
{
    return "'" . strtr($value, ['\\' => '\\\\', "'" => "\\'"]) . "'";
}

/** @param array{host:string,port:string,name:string,user:string,password:string} $database */
function install_env_contents(array $database): string
{
    return implode("\n", [
        'DB_HOST=' . install_env_value($database['host']),
        'DB_PORT=' . install_env_value($database['port']),
        'DB_NAME=' . install_env_value($database['name']),
        'DB_USER=' . install_env_value($database['user']),
        'DB_PASSWORD=' . install_env_value($database['password']),
        '',
    ]);
}

function install_write_env(string $path, string $contents): void
{
    $handle = @fopen($path, 'x');
    if ($handle === false) {
        throw new InstallException('The installer could not create .env. Confirm that the project folder is writable and that .env does not already exist.');
    }
    try {
        if (!flock($handle, LOCK_EX) || fwrite($handle, $contents) !== strlen($contents) || !fflush($handle)) {
            throw new InstallException('The installer could not finish writing .env.');
        }
    } catch (Throwable $exception) {
        fclose($handle);
        @unlink($path);
        throw $exception;
    }
    fclose($handle);
    @chmod($path, 0640);
}

$form = [
    'db_host' => '127.0.0.1',
    'db_port' => '3306',
    'db_name' => 'ofw_dormitory_system',
    'db_user' => 'root',
    'admin_name' => '',
    'admin_username' => '',
];
$error = '';

if (!$configured && $_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($form) as $key) {
        $form[$key] = trim((string) ($_POST[$key] ?? $form[$key]));
    }
    $databasePassword = (string) ($_POST['db_password'] ?? '');
    $adminPassword = (string) ($_POST['admin_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');
    $adminName = mb_strtoupper(preg_replace('/\s+/u', ' ', $form['admin_name']) ?? $form['admin_name'], 'UTF-8');
    $adminUsername = mb_strtolower($form['admin_username'], 'UTF-8');
    $form['admin_name'] = $adminName;
    $form['admin_username'] = $adminUsername;

    if (!hash_equals((string) ($_SESSION['install_csrf_token'] ?? ''), $csrfToken)) {
        $error = 'This installation form expired. Reload the page and try again.';
    } elseif (!extension_loaded('pdo_mysql')) {
        $error = 'PHP PDO MySQL is not enabled. Enable it in XAMPP before installing.';
    } elseif (!preg_match('/^[A-Za-z0-9._:-]+$/', $form['db_host'])) {
        $error = 'Enter a valid database host name or IP address.';
    } elseif (filter_var($form['db_port'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false) {
        $error = 'Enter a database port from 1 to 65535.';
    } elseif ($form['db_name'] !== 'ofw_dormitory_system') {
        $error = 'The database name must be ofw_dormitory_system.';
    } elseif ($form['db_user'] === '' || strlen($form['db_user']) > 80 || strpbrk($form['db_user'], "\r\n") !== false) {
        $error = 'Enter a valid database username.';
    } elseif (strlen($databasePassword) > 255 || strpbrk($databasePassword, "\r\n") !== false) {
        $error = 'The database password cannot contain a line break or exceed 255 characters.';
    } elseif ($adminName === '' || mb_strlen($adminName) > 150) {
        $error = 'Enter the administrator’s full name.';
    } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $adminUsername)) {
        $error = 'Use 3–50 letters, numbers, dots, dashes, or underscores for the administrator username.';
    } elseif (strlen($adminPassword) < 10) {
        $error = 'Use an administrator password with at least 10 characters.';
    } elseif ($adminPassword !== $confirmPassword) {
        $error = 'The administrator passwords do not match.';
    } elseif (!is_writable($projectRoot)) {
        $error = 'The project folder is not writable. Allow Apache to create the .env file, then try again.';
    } else {
        $serverPdo = null;
        $lockAcquired = false;
        $installationStarted = false;
        try {
            $serverDsn = 'mysql:host=' . $form['db_host'] . ';port=' . $form['db_port'] . ';charset=utf8mb4';
            $serverPdo = new PDO($serverDsn, $form['db_user'], $databasePassword, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
            ]);

            $lockAcquired = (int) $serverPdo->query("SELECT GET_LOCK('ofw_dormitory_web_install', 10)")->fetchColumn() === 1;
            if (!$lockAcquired) {
                throw new InstallException('Another installation is in progress. Try again in a moment.');
            }
            if (is_file($envPath)) {
                throw new InstallException('The system was configured by another request. Open the system instead.');
            }

            $tableStatement = $serverPdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = :database_name');
            $tableStatement->execute(['database_name' => $form['db_name']]);
            if ((int) $tableStatement->fetchColumn() > 0) {
                throw new InstallException('The database already contains tables. This installer only works with a new, empty database.');
            }

            $schemaSql = file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'database.sql');
            if ($schemaSql === false || !str_contains($schemaSql, 'USE ofw_dormitory_system;')) {
                throw new InstallException('The bundled database.sql file is missing or invalid.');
            }

            $installationStarted = true;
            $serverPdo->exec($schemaSql);

            $applicationDsn = $serverDsn . ';dbname=' . $form['db_name'];
            $applicationPdo = new PDO($applicationDsn, $form['db_user'], $databasePassword, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            $applicationPdo->beginTransaction();
            $insert = $applicationPdo->prepare("INSERT INTO users (full_name,username,password_hash,role,status) VALUES (:full_name,:username,:password_hash,'admin','active')");
            $insert->execute([
                'full_name' => $adminName,
                'username' => $adminUsername,
                'password_hash' => password_hash($adminPassword, PASSWORD_DEFAULT),
            ]);
            $userId = (int) $applicationPdo->lastInsertId();
            $applicationPdo->commit();

            install_write_env($envPath, install_env_contents([
                'host' => $form['db_host'],
                'port' => $form['db_port'],
                'name' => $form['db_name'],
                'user' => $form['db_user'],
                'password' => $databasePassword,
            ]));

            session_regenerate_id(true);
            unset($_SESSION['install_csrf_token'], $_SESSION['csrf_token']);
            $_SESSION['user'] = [
                'id' => $userId,
                'full_name' => $adminName,
                'username' => $adminUsername,
                'role' => 'admin',
            ];
            $_SESSION['last_activity'] = time();
            header('Location: ../dashboard.php');
        } catch (Throwable $exception) {
            if (isset($applicationPdo) && $applicationPdo instanceof PDO && $applicationPdo->inTransaction()) {
                $applicationPdo->rollBack();
            }
            if ($installationStarted && $serverPdo instanceof PDO) {
                try {
                    $serverPdo->exec('DROP DATABASE IF EXISTS `ofw_dormitory_system`');
                } catch (Throwable $cleanupException) {
                    error_log('Installer database cleanup failed.');
                }
                if (is_file($envPath)) {
                    @unlink($envPath);
                }
            }
            error_log('Dormitory installation failed: ' . $exception->getMessage());
            $error = $exception instanceof InstallException
                ? $exception->getMessage()
                : 'Installation could not complete. Check the database username and password, then try again.';
        } finally {
            if ($lockAcquired && $serverPdo instanceof PDO) {
                try {
                    $serverPdo->query("SELECT RELEASE_LOCK('ofw_dormitory_web_install')");
                } catch (Throwable $releaseException) {
                    error_log('Installer lock release failed.');
                }
            }
        }
        if (isset($userId) && $error === '') {
            exit;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Install | OFW Dormitory System</title>
  <link rel="stylesheet" href="../assets/css/app.css">
</head>
<body class="install-page">
  <main class="install-shell">
    <header class="install-mast">
      <div class="install-brand">
        <span aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 21V9l8-6 8 6v12M8 21v-7h8v7M9 10h.01M15 10h.01"/></svg></span>
        <div><strong>Dormitory System</strong><small>First-time commissioning</small></div>
      </div>
      <div class="install-progress" aria-label="Installation stages">
        <span class="active"><b>1</b> Connect</span><i></i><span><b>2</b> Create</span><i></i><span><b>3</b> Enter</span>
      </div>
    </header>

    <?php if ($configured): ?>
      <section class="install-complete">
        <span class="install-complete-icon" aria-hidden="true">✓</span>
        <p class="eyebrow">Already configured</p>
        <h1>This system has completed installation.</h1>
        <p>The installer is locked because a local database configuration already exists.</p>
        <a class="install-submit" href="../">Open Dormitory System <span aria-hidden="true">→</span></a>
      </section>
    <?php else: ?>
      <section class="install-intro">
        <div>
          <p class="eyebrow">New installation</p>
          <h1>Prepare this residence workspace.</h1>
          <p>Connect XAMPP’s database and create the first administrator in one secure step.</p>
        </div>
        <aside class="install-summary" aria-label="Installation summary">
          <span>Will create</span>
          <strong>18 database tables</strong>
          <small>No sample tenants or transactions</small>
        </aside>
      </section>

      <?php if ($error !== ''): ?><p class="install-alert" role="alert"><strong>Installation stopped</strong><span><?= install_e($error) ?></span></p><?php endif; ?>

      <form method="post" class="install-form">
        <input type="hidden" name="csrf_token" value="<?= install_e(install_csrf_token()) ?>">
        <div class="install-form-grid">
          <section class="install-section">
            <div class="install-section-heading">
              <span class="install-number">01</span>
              <div><h2>Database connection</h2><p>Default values match a standard XAMPP installation.</p></div>
            </div>
            <div class="install-fields database-fields">
              <label>Database host<input name="db_host" value="<?= install_e($form['db_host']) ?>" maxlength="255" required></label>
              <label>Port<input name="db_port" value="<?= install_e($form['db_port']) ?>" inputmode="numeric" min="1" max="65535" required></label>
              <label class="wide">Database name<input name="db_name" value="<?= install_e($form['db_name']) ?>" readonly aria-describedby="database-name-note"><small id="database-name-note">The application uses this name consistently.</small></label>
              <label class="wide">Database username<input name="db_user" value="<?= install_e($form['db_user']) ?>" maxlength="80" autocomplete="username" required></label>
              <label class="wide">Database password<input type="password" name="db_password" maxlength="255" autocomplete="current-password"><small>Leave blank when using XAMPP’s default local root account.</small></label>
            </div>
          </section>

          <section class="install-section admin-section">
            <div class="install-section-heading">
              <span class="install-number">02</span>
              <div><h2>First administrator</h2><p>This account receives full system access.</p></div>
            </div>
            <div class="install-fields">
              <label>Full name<input name="admin_name" value="<?= install_e($form['admin_name']) ?>" maxlength="150" autocomplete="name" required></label>
              <label>Admin username<input name="admin_username" value="<?= install_e($form['admin_username']) ?>" minlength="3" maxlength="50" pattern="[A-Za-z0-9_.-]{3,50}" autocomplete="username" required></label>
              <label>Admin password<input type="password" name="admin_password" minlength="10" autocomplete="new-password" required><small>Use at least 10 characters.</small></label>
              <label>Confirm admin password<input type="password" name="confirm_password" minlength="10" autocomplete="new-password" required></label>
            </div>
          </section>
        </div>

        <footer class="install-action-row">
          <div><strong>Ready to install</strong><span>The installer writes `.env`, imports the clean schema, and signs in the administrator.</span></div>
          <button class="install-submit" type="submit">Install system <span aria-hidden="true">→</span></button>
        </footer>
      </form>
      <p class="install-local-note">For security, this installer only accepts requests from this computer.</p>
    <?php endif; ?>
  </main>
</body>
</html>
