<?php
// OFW Dormitory System - MySQL/XAMPP backend configuration.
// DO NOT put the frontend code or database credentials in JavaScript.

declare(strict_types=1);

// This is an obsolete, incompatible backend retained only as historical source.
// The live application uses app/config/database.php and the page-level permission system.
if (PHP_SAPI !== 'cli') {
    http_response_code(410);
    header_remove('X-Powered-By');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    exit(json_encode(['success' => false, 'error' => 'Legacy API disabled.']));
}

const DB_HOST = '127.0.0.1';
const DB_PORT = '3306';
const DB_NAME = 'ofw_dormitory';
const DB_USER = 'root';
const DB_PASS = ''; // Default XAMPP MySQL password is usually blank.

const APP_SESSION_NAME = 'ofw_dormitory_session';

function db(): PDO {
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';

    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}

function start_app_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name(APP_SESSION_NAME);
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}
