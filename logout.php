<?php
declare(strict_types=1);

require_once __DIR__ . '/app/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('login.php');
}

require_valid_csrf();
logout_user();
redirect('login.php');
