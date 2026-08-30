<?php
declare(strict_types=1);

function database_backup_identifier(string $name): string
{
    if ($name === '' || str_contains($name, "\0")) {
        throw new RuntimeException('The database contains an invalid identifier.');
    }
    return '`' . str_replace('`', '``', $name) . '`';
}

function database_backup_write($handle, string $contents): void
{
    $length = strlen($contents);
    $written = 0;
    while ($written < $length) {
        $result = fwrite($handle, substr($contents, $written));
        if ($result === false || $result === 0) {
            throw new RuntimeException('The SQL backup could not be written.');
        }
        $written += $result;
    }
}

function database_backup_value(PDO $pdo, mixed $value): string
{
    if ($value === null) { return 'NULL'; }
    $quoted = $pdo->quote((string) $value);
    if ($quoted === false) { throw new RuntimeException('A database value could not be encoded for backup.'); }
    return $quoted;
}

/** @return array{resource: resource, filename: string, size: int} */
function database_backup_create(PDO $pdo): array
{
    $databaseName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($databaseName === '') { throw new RuntimeException('No database is currently selected.'); }
    $handle = tmpfile();
    if ($handle === false) { throw new RuntimeException('A temporary backup file could not be created.'); }
    $transactionStarted = false;
    try {
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        $transactionStarted = true;

        $tableStatement = $pdo->query("SELECT TABLE_NAME,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY CASE WHEN TABLE_TYPE='BASE TABLE' THEN 0 ELSE 1 END,TABLE_NAME");
        $objects = $tableStatement->fetchAll();
        $timestamp = (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM);
        database_backup_write($handle, "-- Dormitory System SQL backup\n-- Created: $timestamp\n-- Database: $databaseName\n-- Contains structure and data. Store this file securely.\n\n");
        database_backup_write($handle, "SET NAMES utf8mb4;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\n\n");
        database_backup_write($handle, 'CREATE DATABASE IF NOT EXISTS ' . database_backup_identifier($databaseName) . " CHARACTER SET utf8mb4;\nUSE " . database_backup_identifier($databaseName) . ";\n\n");

        foreach ($objects as $object) {
            if ($object['TABLE_TYPE'] !== 'BASE TABLE') { continue; }
            $table = (string) $object['TABLE_NAME'];
            $identifier = database_backup_identifier($table);
            $createRow = $pdo->query("SHOW CREATE TABLE $identifier")->fetch();
            $createSql = (string) ($createRow['Create Table'] ?? '');
            if ($createSql === '') { throw new RuntimeException("The structure of table '$table' could not be read."); }
            database_backup_write($handle, "--\n-- Table: $table\n--\nDROP TABLE IF EXISTS $identifier;\n$createSql;\n\n");

            $columnRows = $pdo->query("SHOW COLUMNS FROM $identifier")->fetchAll();
            $columns = [];
            foreach ($columnRows as $column) {
                if (stripos((string) ($column['Extra'] ?? ''), 'GENERATED') !== false) { continue; }
                $columns[] = (string) $column['Field'];
            }
            if (!$columns) { continue; }
            $columnSql = implode(',', array_map('database_backup_identifier', $columns));
            $dataStatement = $pdo->query("SELECT $columnSql FROM $identifier");
            $batch = [];
            $flushBatch = static function () use (&$batch, $handle, $identifier, $columnSql): void {
                if (!$batch) { return; }
                database_backup_write($handle, "INSERT INTO $identifier ($columnSql) VALUES\n" . implode(",\n", $batch) . ";\n");
                $batch = [];
            };
            while ($row = $dataStatement->fetch(PDO::FETCH_ASSOC)) {
                $values = [];
                foreach ($columns as $column) { $values[] = database_backup_value($pdo, $row[$column] ?? null); }
                $batch[] = '(' . implode(',', $values) . ')';
                if (count($batch) >= 50) { $flushBatch(); }
            }
            $dataStatement->closeCursor();
            $flushBatch();
            database_backup_write($handle, "\n");
        }

        foreach ($objects as $object) {
            if ($object['TABLE_TYPE'] !== 'VIEW') { continue; }
            $view = (string) $object['TABLE_NAME'];
            $identifier = database_backup_identifier($view);
            $createRow = $pdo->query("SHOW CREATE VIEW $identifier")->fetch();
            $createSql = (string) ($createRow['Create View'] ?? '');
            $createSql = preg_replace('/DEFINER=`[^`]+`@`[^`]+`\s+/i', '', $createSql) ?? $createSql;
            if ($createSql !== '') { database_backup_write($handle, "DROP VIEW IF EXISTS $identifier;\n$createSql;\n\n"); }
        }

        database_backup_write($handle, "SET UNIQUE_CHECKS=1;\nSET FOREIGN_KEY_CHECKS=1;\n-- End of backup\n");
        if ($transactionStarted && $pdo->inTransaction()) { $pdo->rollBack(); }
        $transactionStarted = false;
        $stat = fstat($handle);
        if ($stat === false) { throw new RuntimeException('The completed backup size could not be determined.'); }
        rewind($handle);
        return [
            'resource' => $handle,
            'filename' => 'dormitory-database-backup-' . (new DateTimeImmutable('now'))->format('Ymd-His') . '.sql',
            'size' => (int) $stat['size'],
        ];
    } catch (Throwable $exception) {
        if ($transactionStarted && $pdo->inTransaction()) { $pdo->rollBack(); }
        fclose($handle);
        throw $exception;
    }
}

function database_backup_download(PDO $pdo): never
{
    $backup = database_backup_create($pdo);
    $handle = $backup['resource'];
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/sql; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $backup['filename'] . '"');
    header('Content-Length: ' . $backup['size']);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    fpassthru($handle);
    fclose($handle);
    exit;
}
