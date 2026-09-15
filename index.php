<?php
declare(strict_types=1);

$hasServerCredentials = getenv('DB_USER') !== false && getenv('DB_PASSWORD') !== false;
$hasDatabaseConfiguration = is_file(__DIR__ . '/.env') || $hasServerCredentials;
if (!$hasDatabaseConfiguration) {
    header('Location: install/');
    exit;
}

require_once __DIR__ . '/app/includes/auth.php';

if (is_logged_in()) {
    redirect(permitted_landing_page(current_user()));
}

$hasAdmin = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() > 0;
redirect($hasAdmin ? 'login.php' : 'setup.php');
