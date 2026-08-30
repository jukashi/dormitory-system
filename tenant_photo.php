<?php
declare(strict_types=1);

require_once __DIR__ . '/app/includes/auth.php';

require_permission('tenants');
$tenantId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($tenantId === false) {
    http_response_code(404);
    exit;
}

$statement = db()->prepare('SELECT photo_path FROM tenants WHERE id=:id LIMIT 1');
$statement->execute(['id' => (int) $tenantId]);
$storedPath = $statement->fetchColumn();
if (!is_string($storedPath) || $storedPath === '') {
    http_response_code(404);
    exit;
}

$filename = basename(str_replace('\\', '/', $storedPath));
if (!preg_match('/^[a-f0-9]{32}\.(?:jpg|png|webp)$/', $filename)) {
    http_response_code(404);
    exit;
}

$uploadDirectory = realpath(__DIR__ . '/uploads/tenants');
$photoPath = realpath(__DIR__ . '/uploads/tenants/' . $filename);
if ($uploadDirectory === false || $photoPath === false || !str_starts_with($photoPath, $uploadDirectory . DIRECTORY_SEPARATOR) || !is_file($photoPath)) {
    http_response_code(404);
    exit;
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($photoPath);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($photoPath));
header('Cache-Control: private, max-age=3600');
readfile($photoPath);
exit;
