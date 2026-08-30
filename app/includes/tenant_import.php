<?php
declare(strict_types=1);

const TENANT_IMPORT_MAX_BYTES = 5 * 1024 * 1024;
const TENANT_IMPORT_MAX_ROWS = 500;

function tenant_import_key(string $value): string
{
    return mb_strtolower(trim($value), 'UTF-8');
}

function tenant_import_header(string $value): string
{
    $value = preg_replace('/^\xEF\xBB\xBF/', '', trim($value)) ?? '';
    $value = preg_replace('/[^a-z0-9]+/', '_', strtolower($value)) ?? '';
    $value = trim($value, '_');
    $aliases = [
        'name' => 'full_name', 'tenant_name' => 'full_name', 'dorm' => 'dormitory',
        'room' => 'room_number', 'bed' => 'bed_number', 'moved_in' => 'date_moved_in',
        'rent' => 'monthly_rent', 'shift' => 'shift_code', 'contact' => 'contact_no',
        'passport' => 'passport_no', 'arc' => 'arc_no', 'position' => 'designation',
    ];
    return $aliases[$value] ?? $value;
}

function tenant_import_upload(array $file): array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('Choose a CSV or Excel (.xlsx) file to import.');
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The tenant file could not be uploaded. Please try again.');
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size < 1 || $size > TENANT_IMPORT_MAX_BYTES) {
        throw new RuntimeException('The tenant file must be no larger than 5 MB.');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('The uploaded tenant file is invalid.');
    }
    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($extension, ['csv', 'xlsx'], true)) {
        throw new RuntimeException('Use a .csv or .xlsx tenant file. Legacy .xls files must be saved as .xlsx first.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
    $allowedMimes = $extension === 'csv'
        ? ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel', 'application/octet-stream']
        : ['application/zip', 'application/x-zip-compressed', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/octet-stream'];
    if (!in_array($mime, $allowedMimes, true)) {
        throw new RuntimeException('The file contents do not match the selected CSV or Excel format.');
    }
    $matrix = $extension === 'csv' ? tenant_import_csv_matrix($tmp) : tenant_import_xlsx_matrix($tmp);
    return tenant_import_associate_rows($matrix);
}

function tenant_import_csv_matrix(string $path): array
{
    $handle = fopen($path, 'rb');
    if ($handle === false) { throw new RuntimeException('The CSV file could not be opened.'); }
    $rows = [];
    while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
        $rows[] = array_map(static fn ($value): string => trim((string) $value), $row);
        if (count($rows) > TENANT_IMPORT_MAX_ROWS + 1) {
            fclose($handle);
            throw new RuntimeException('Import files can contain at most 500 tenant rows.');
        }
    }
    fclose($handle);
    return $rows;
}

function tenant_import_zip_entry(ZipArchive $zip, string $name, int $limit = 15728640): ?string
{
    $stat = $zip->statName($name);
    if ($stat === false) { return null; }
    if ((int) ($stat['size'] ?? 0) > $limit) {
        throw new RuntimeException('The Excel file is too complex to import safely.');
    }
    $contents = $zip->getFromName($name);
    return $contents === false ? null : $contents;
}

function tenant_import_xlsx_matrix(string $path): array
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('Excel import is unavailable on this server. Use the CSV template instead.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) { throw new RuntimeException('The Excel workbook is damaged or password-protected.'); }
    try {
        $workbookXml = tenant_import_zip_entry($zip, 'xl/workbook.xml');
        $relationshipsXml = tenant_import_zip_entry($zip, 'xl/_rels/workbook.xml.rels');
        if ($workbookXml === null || $relationshipsXml === null) {
            throw new RuntimeException('The Excel workbook structure is invalid.');
        }
        $workbook = simplexml_load_string($workbookXml, SimpleXMLElement::class, LIBXML_NONET);
        $relationships = simplexml_load_string($relationshipsXml, SimpleXMLElement::class, LIBXML_NONET);
        if ($workbook === false || $relationships === false) { throw new RuntimeException('The Excel workbook XML is invalid.'); }
        $sheets = $workbook->xpath('//*[local-name()="sheet"]') ?: [];
        if (!$sheets) { throw new RuntimeException('The Excel workbook does not contain a worksheet.'); }
        $relationshipAttributes = $sheets[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $relationshipId = (string) ($relationshipAttributes['id'] ?? '');
        $sheetTarget = '';
        foreach ($relationships->xpath('//*[local-name()="Relationship"]') ?: [] as $relationship) {
            $attributes = $relationship->attributes();
            if ((string) ($attributes['Id'] ?? '') === $relationshipId) { $sheetTarget = (string) ($attributes['Target'] ?? ''); break; }
        }
        $sheetTarget = str_replace('\\', '/', $sheetTarget);
        $sheetPath = str_starts_with($sheetTarget, '/') ? ltrim($sheetTarget, '/') : 'xl/' . $sheetTarget;
        if ($sheetTarget === '' || str_contains($sheetPath, '..') || !str_starts_with($sheetPath, 'xl/')) {
            throw new RuntimeException('The Excel worksheet path is invalid.');
        }
        $sheetXml = tenant_import_zip_entry($zip, $sheetPath);
        if ($sheetXml === null) { throw new RuntimeException('The first Excel worksheet could not be read.'); }
        $sharedStrings = [];
        $sharedXml = tenant_import_zip_entry($zip, 'xl/sharedStrings.xml');
        if ($sharedXml !== null) {
            $shared = simplexml_load_string($sharedXml, SimpleXMLElement::class, LIBXML_NONET);
            if ($shared === false) { throw new RuntimeException('The Excel shared text table is invalid.'); }
            foreach ($shared->xpath('//*[local-name()="si"]') ?: [] as $item) {
                $parts = [];
                foreach ($item->xpath('.//*[local-name()="t"]') ?: [] as $text) { $parts[] = (string) $text; }
                $sharedStrings[] = implode('', $parts);
            }
        }
        $sheet = simplexml_load_string($sheetXml, SimpleXMLElement::class, LIBXML_NONET);
        if ($sheet === false) { throw new RuntimeException('The Excel worksheet XML is invalid.'); }
        $matrix = [];
        foreach ($sheet->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [] as $rowNode) {
            $row = [];
            foreach ($rowNode->xpath('./*[local-name()="c"]') ?: [] as $cell) {
                $cellAttributes = $cell->attributes();
                $reference = (string) ($cellAttributes['r'] ?? '');
                if (!preg_match('/^([A-Z]+)\d+$/i', $reference, $matches)) { continue; }
                $columnIndex = 0;
                foreach (str_split(strtoupper($matches[1])) as $letter) { $columnIndex = $columnIndex * 26 + ord($letter) - 64; }
                $columnIndex--;
                if ($cell->xpath('./*[local-name()="f"]')) {
                    throw new RuntimeException("Excel formulas are not accepted (cell $reference). Replace formulas with values.");
                }
                $type = (string) ($cellAttributes['t'] ?? '');
                $valueNodes = $cell->xpath('./*[local-name()="v"]') ?: [];
                $raw = $valueNodes ? (string) $valueNodes[0] : '';
                if ($type === 's') { $raw = $sharedStrings[(int) $raw] ?? ''; }
                if ($type === 'inlineStr') {
                    $parts = [];
                    foreach ($cell->xpath('.//*[local-name()="t"]') ?: [] as $text) { $parts[] = (string) $text; }
                    $raw = implode('', $parts);
                }
                $row[$columnIndex] = trim($raw);
            }
            if ($row) {
                $width = max(array_keys($row)) + 1;
                $matrix[] = array_replace(array_fill(0, $width, ''), $row);
            }
            if (count($matrix) > TENANT_IMPORT_MAX_ROWS + 1) {
                throw new RuntimeException('Import files can contain at most 500 tenant rows.');
            }
        }
        return $matrix;
    } finally {
        $zip->close();
    }
}

function tenant_import_associate_rows(array $matrix): array
{
    while ($matrix && !array_filter($matrix[0], static fn ($value): bool => trim((string) $value) !== '')) { array_shift($matrix); }
    if (!$matrix) { throw new RuntimeException('The import file is empty.'); }
    $allowed = [
        'full_name', 'nationality', 'contact_no', 'passport_no', 'passport_expiry', 'arc_no', 'arc_expiry',
        'employee_id', 'employer', 'agency', 'designation', 'emergency_contact_name', 'emergency_contact_no',
        'dormitory', 'room_number', 'bed_number', 'shift_code', 'monthly_rent', 'date_moved_in', 'additional_comments',
    ];
    $headers = [];
    foreach ($matrix[0] as $index => $heading) {
        $normalized = tenant_import_header((string) $heading);
        if ($normalized === '') { continue; }
        if (!in_array($normalized, $allowed, true)) { throw new RuntimeException('Unknown import column: ' . trim((string) $heading) . '. Use the provided template.'); }
        if (in_array($normalized, $headers, true)) { throw new RuntimeException("The import column '$normalized' appears more than once."); }
        $headers[$index] = $normalized;
    }
    foreach (['full_name', 'dormitory', 'room_number', 'bed_number', 'date_moved_in'] as $required) {
        if (!in_array($required, $headers, true)) { throw new RuntimeException("The required '$required' column is missing."); }
    }
    $rows = [];
    foreach (array_slice($matrix, 1) as $offset => $values) {
        if (!array_filter($values, static fn ($value): bool => trim((string) $value) !== '')) { continue; }
        $row = ['_row' => $offset + 2];
        foreach ($headers as $index => $header) { $row[$header] = trim((string) ($values[$index] ?? '')); }
        $rows[] = $row;
    }
    if (!$rows) { throw new RuntimeException('The import file does not contain any tenant rows.'); }
    if (count($rows) > TENANT_IMPORT_MAX_ROWS) { throw new RuntimeException('Import files can contain at most 500 tenant rows.'); }
    return $rows;
}

function tenant_import_date(string $value, string $label, int $row): ?string
{
    if ($value === '') { return null; }
    if (is_numeric($value)) {
        $serial = (int) floor((float) $value);
        if ($serial > 0 && $serial < 2958466) { return (new DateTimeImmutable('1899-12-30'))->modify("+$serial days")->format('Y-m-d'); }
    }
    foreach (['Y-m-d', 'Y/m/d', 'm/d/Y', 'm/d/y'] as $format) {
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
        if ($date && $date->format($format) === $value) { return $date->format('Y-m-d'); }
    }
    throw new RuntimeException("Row $row: $label must be YYYY-MM-DD (Excel date cells are also accepted).");
}

function tenant_import_text(array $row, string $field, int $maximum, bool $required = false): ?string
{
    $value = trim((string) ($row[$field] ?? ''));
    $rowNumber = (int) $row['_row'];
    if ($required && $value === '') { throw new RuntimeException("Row $rowNumber: $field is required."); }
    if (mb_strlen($value) > $maximum) { throw new RuntimeException("Row $rowNumber: $field is too long (maximum $maximum characters)."); }
    return $value === '' ? null : $value;
}

function tenant_import_apply(PDO $pdo, array $rows): int
{
    $pdo->beginTransaction();
    try {
        $roomRows = $pdo->query('SELECT r.id,r.room_number,r.capacity,d.name AS dormitory_name FROM rooms r INNER JOIN dormitories d ON d.id=r.dormitory_id FOR UPDATE')->fetchAll();
        $rooms = [];
        $occupied = [];
        foreach ($roomRows as $room) {
            $key = tenant_import_key($room['dormitory_name']) . '|' . tenant_import_key($room['room_number']);
            $rooms[$key] = ['id' => (int) $room['id'], 'capacity' => (int) $room['capacity']];
            $occupied[(int) $room['id']] = 0;
        }
        $beds = [];
        foreach ($pdo->query("SELECT room_id,bed_number FROM tenants WHERE status='active' AND room_id IS NOT NULL") as $tenant) {
            $roomId = (int) $tenant['room_id'];
            $occupied[$roomId] = ($occupied[$roomId] ?? 0) + 1;
            $beds[$roomId . '|' . tenant_import_key((string) $tenant['bed_number'])] = true;
        }
        $entityMaps = [];
        foreach (['employers', 'agencies'] as $table) {
            $entityMaps[$table] = [];
            foreach ($pdo->query("SELECT id,name FROM $table") as $entity) { $entityMaps[$table][tenant_import_key($entity['name'])] = (int) $entity['id']; }
        }
        $uniqueValues = ['passport_no' => [], 'arc_no' => []];
        foreach ($pdo->query('SELECT passport_no,arc_no FROM tenants') as $tenant) {
            foreach (array_keys($uniqueValues) as $field) {
                if ($tenant[$field] !== null && trim((string) $tenant[$field]) !== '') { $uniqueValues[$field][tenant_import_key((string) $tenant[$field])] = true; }
            }
        }
        $sql = 'INSERT INTO tenants (full_name,nationality,contact_no,passport_no,passport_expiry,arc_no,arc_expiry,employee_id,employer_id,agency_id,designation,emergency_contact_name,emergency_contact_no,additional_comments,room_id,bed_number,shift_code,monthly_rent,date_moved_in,status) VALUES (:full_name,:nationality,:contact_no,:passport_no,:passport_expiry,:arc_no,:arc_expiry,:employee_id,:employer_id,:agency_id,:designation,:emergency_contact_name,:emergency_contact_no,:additional_comments,:room_id,:bed_number,:shift_code,:monthly_rent,:date_moved_in,\'active\')';
        $insert = $pdo->prepare($sql);
        foreach ($rows as $row) {
            $rowNumber = (int) $row['_row'];
            $fullName = normalize_upper(tenant_import_text($row, 'full_name', 150, true));
            $dormitory = tenant_import_text($row, 'dormitory', 150, true);
            $roomNumber = tenant_import_text($row, 'room_number', 30, true);
            $bedNumber = normalize_upper(tenant_import_text($row, 'bed_number', 30, true));
            $roomKey = tenant_import_key((string) $dormitory) . '|' . tenant_import_key((string) $roomNumber);
            if (!isset($rooms[$roomKey])) { throw new RuntimeException("Row $rowNumber: dormitory '$dormitory', room '$roomNumber' was not found."); }
            $room = $rooms[$roomKey];
            if (($occupied[$room['id']] ?? 0) >= $room['capacity']) { throw new RuntimeException("Row $rowNumber: room '$roomNumber' in '$dormitory' is already full."); }
            $bedKey = $room['id'] . '|' . tenant_import_key((string) $bedNumber);
            if (isset($beds[$bedKey])) { throw new RuntimeException("Row $rowNumber: bed '$bedNumber' is already occupied in that room."); }
            $employerName = tenant_import_text($row, 'employer', 150);
            $agencyName = tenant_import_text($row, 'agency', 150);
            $employerId = $employerName === null ? null : ($entityMaps['employers'][tenant_import_key($employerName)] ?? null);
            $agencyId = $agencyName === null ? null : ($entityMaps['agencies'][tenant_import_key($agencyName)] ?? null);
            if ($employerName !== null && $employerId === null) { throw new RuntimeException("Row $rowNumber: employer '$employerName' does not exist. Add it on the Employers page first."); }
            if ($agencyName !== null && $agencyId === null) { throw new RuntimeException("Row $rowNumber: agency '$agencyName' does not exist. Add it on the Agencies page first."); }
            $passport = normalize_upper(tenant_import_text($row, 'passport_no', 80));
            $arc = normalize_upper(tenant_import_text($row, 'arc_no', 80));
            foreach (['passport_no' => $passport, 'arc_no' => $arc] as $field => $value) {
                if ($value !== null) {
                    $key = tenant_import_key($value);
                    if (isset($uniqueValues[$field][$key])) { throw new RuntimeException("Row $rowNumber: $field '$value' is already assigned to another tenant."); }
                    $uniqueValues[$field][$key] = true;
                }
            }
            $shift = strtoupper(trim((string) ($row['shift_code'] ?? '')));
            if (!in_array($shift, ['', 'DA', 'DB', 'NA', 'NB'], true)) { throw new RuntimeException("Row $rowNumber: shift_code must be DA, DB, NA, NB, or blank."); }
            $rentValue = trim((string) ($row['monthly_rent'] ?? ''));
            $rent = $rentValue === '' ? 0.0 : filter_var(str_replace(',', '', $rentValue), FILTER_VALIDATE_FLOAT);
            if ($rent === false || $rent < 0 || $rent > 99999999.99) { throw new RuntimeException("Row $rowNumber: monthly_rent must be a valid non-negative amount."); }
            $dateMovedIn = tenant_import_date(trim((string) ($row['date_moved_in'] ?? '')), 'date_moved_in', $rowNumber);
            if ($dateMovedIn === null) { throw new RuntimeException("Row $rowNumber: date_moved_in is required."); }
            $passportExpiry = tenant_import_date(trim((string) ($row['passport_expiry'] ?? '')), 'passport_expiry', $rowNumber);
            $arcExpiry = tenant_import_date(trim((string) ($row['arc_expiry'] ?? '')), 'arc_expiry', $rowNumber);
            $insert->execute([
                'full_name' => $fullName, 'nationality' => normalize_upper(tenant_import_text($row, 'nationality', 80)),
                'contact_no' => tenant_import_text($row, 'contact_no', 50), 'passport_no' => $passport,
                'passport_expiry' => $passportExpiry, 'arc_no' => $arc, 'arc_expiry' => $arcExpiry,
                'employee_id' => normalize_upper(tenant_import_text($row, 'employee_id', 80)), 'employer_id' => $employerId,
                'agency_id' => $agencyId, 'designation' => normalize_upper(tenant_import_text($row, 'designation', 150)),
                'emergency_contact_name' => normalize_upper(tenant_import_text($row, 'emergency_contact_name', 150)),
                'emergency_contact_no' => tenant_import_text($row, 'emergency_contact_no', 50),
                'additional_comments' => tenant_import_text($row, 'additional_comments', 5000),
                'room_id' => $room['id'], 'bed_number' => $bedNumber, 'shift_code' => $shift === '' ? null : $shift,
                'monthly_rent' => number_format((float) $rent, 2, '.', ''), 'date_moved_in' => $dateMovedIn,
            ]);
            $occupied[$room['id']]++;
            $beds[$bedKey] = true;
        }
        $pdo->commit();
        return count($rows);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $exception;
    }
}
