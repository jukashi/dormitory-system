<?php
declare(strict_types=1);

// This legacy JSON backend is disabled for web requests. The live application
// uses the page-level permission system and the shared database configuration.
if (PHP_SAPI !== 'cli') {
    http_response_code(410);
    header_remove('X-Powered-By');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    exit(json_encode(['success' => false, 'error' => 'Legacy API disabled.']));
}

require_once __DIR__ . '/../app/config/database.php';

const APP_SESSION_NAME = 'ofw_dormitory_session';

function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name(APP_SESSION_NAME);
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}
