<?php
declare(strict_types=1);

/** @return array<string, string> */
function database_env_file(): array
{
    static $values = null;
    if (is_array($values)) {
        return $values;
    }

    $values = [];
    $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';
    if (!is_file($path) || !is_readable($path)) {
        return $values;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return $values;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $separator = strpos($line, '=');
        if ($separator === false) {
            continue;
        }
        $key = trim(substr($line, 0, $separator));
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
            continue;
        }
        $value = trim(substr($line, $separator + 1));
        $length = strlen($value);
        if ($length >= 2 && (($value[0] === '"' && $value[$length - 1] === '"') || ($value[0] === "'" && $value[$length - 1] === "'"))) {
            $value = substr($value, 1, -1);
        }
        $values[$key] = $value;
    }

    return $values;
}

function database_env(string $key, ?string $default = null): ?string
{
    $environmentValue = getenv($key);
    if ($environmentValue !== false) {
        return $environmentValue;
    }
    $fileValues = database_env_file();
    return array_key_exists($key, $fileValues) ? $fileValues[$key] : $default;
}

/** @return array{host: string, port: int, name: string, user: string, password: string} */
function database_config(): array
{
    $host = trim((string) database_env('DB_HOST', '127.0.0.1'));
    $portValue = trim((string) database_env('DB_PORT', '3306'));
    $name = trim((string) database_env('DB_NAME', 'ofw_dormitory_system'));
    $user = database_env('DB_USER');
    $password = database_env('DB_PASSWORD');

    $port = filter_var($portValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
    if ($host === '' || $port === false || $name === '' || $user === null || trim($user) === '' || $password === null) {
        throw new RuntimeException('Database configuration is incomplete. Copy .env.example to .env and set DB_HOST, DB_PORT, DB_NAME, DB_USER, and DB_PASSWORD.');
    }

    return [
        'host' => $host,
        'port' => (int) $port,
        'name' => $name,
        'user' => trim($user),
        'password' => $password,
    ];
}

function db(): PDO
{
    static $connection = null;

    if ($connection instanceof PDO) {
        return $connection;
    }

    try {
        $config = database_config();
        $dsn = 'mysql:host=' . $config['host'] . ';port=' . $config['port'] . ';dbname=' . $config['name'] . ';charset=utf8mb4';
        $connection = new PDO($dsn, $config['user'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    } catch (RuntimeException $exception) {
        http_response_code(500);
        exit($exception->getMessage());
    } catch (PDOException $exception) {
        http_response_code(500);
        exit('Database connection failed. Confirm the .env settings and that database.sql was imported.');
    }

    return $connection;
}
