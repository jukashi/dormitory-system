<?php
declare(strict_types=1);

// Local XAMPP defaults. Change these values if your MySQL account differs.
const DB_HOST = '127.0.0.1';
const DB_NAME = 'ofw_dormitory_system';
const DB_USER = 'root';
const DB_PASS = '';

function db(): PDO
{
    static $connection = null;

    if ($connection instanceof PDO) {
        return $connection;
    }

    $host = getenv('DORMITORY_DB_HOST') ?: DB_HOST;
    $name = getenv('DORMITORY_DB_NAME') ?: DB_NAME;
    $user = getenv('DORMITORY_DB_USER') ?: DB_USER;
    $environmentPassword = getenv('DORMITORY_DB_PASS');
    $password = $environmentPassword === false ? DB_PASS : $environmentPassword;
    $dsn = 'mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4';

    try {
        $connection = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    } catch (PDOException $exception) {
        http_response_code(500);
        exit('Database connection failed. Check app/config/database.php and confirm the database was imported.');
    }

    return $connection;
}
