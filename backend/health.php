<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = db();
    $pdo->query('SELECT 1');
    echo json_encode([
        'success' => true,
        'message' => 'OFW Dormitory backend can connect to MySQL.',
        'database' => DB_NAME,
        'php' => PHP_VERSION,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Backend is running, but MySQL/database is not ready yet.',
    ]);
}
