<?php
declare(strict_types=1);

require_once __DIR__ . '/app/includes/auth.php';

if (is_logged_in()) {
    redirect(permitted_landing_page(current_user()));
}

$hasAdmin = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() > 0;
redirect($hasAdmin ? 'login.php' : 'setup.php');
