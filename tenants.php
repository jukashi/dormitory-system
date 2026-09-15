<?php
declare(strict_types=1);

require_once __DIR__ . '/app/includes/layout.php';
require_once __DIR__ . '/app/includes/tenant_import.php';

$user = require_permission('tenants');
$pdo = db();
const TENANT_EVENT_TYPES = ['da' => 'DA — Day Shift A-PAN', 'db' => 'DB — Day Shift B-PAN', 'na' => 'NA — Night Shift A-PAN', 'nb' => 'NB — Night Shift B-PAN', 'work_shift' => 'Work Shift (legacy)', 'day_off' => 'Day Off', 'vacation_leave' => 'Vacation Leave', 'sick_leave' => 'Sick Leave', 'leave' => 'Leave (legacy)', 'flight' => 'Flight', 'appointment' => 'Appointment', 'emergency' => 'Emergency', 'other' => 'Other'];
const MANUAL_TENANT_EVENT_TYPES = ['vacation_leave' => 'Vacation Leave', 'sick_leave' => 'Sick Leave', 'appointment' => 'Appointment', 'flight' => 'Flight', 'emergency' => 'Emergency', 'other' => 'Other'];

function tenant_id(mixed $value): int { $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]); return $id === false ? 0 : (int) $id; }
function nullable_post(string $name, int $maximum = 255): ?string { $value = trim((string) ($_POST[$name] ?? '')); if (mb_strlen($value) > $maximum) { throw new RuntimeException("$name is too long."); } return $value === '' ? null : $value; }
function contact_number(?string $value, string $label): ?string { if ($value === null) { return null; } if (!preg_match('/^[0-9]{1,11}$/', $value)) { throw new RuntimeException("$label must contain only digits and be no more than 11 digits."); } return $value; }
function valid_date(?string $value, string $label, bool $required = false): ?string { if ($value === null) { if ($required) { throw new RuntimeException("$label is required."); } return null; } $date = DateTime::createFromFormat('Y-m-d', $value); if (!$date || $date->format('Y-m-d') !== $value) { throw new RuntimeException("$label must be a valid date."); } return $value; }
function entity_name_exists(PDO $pdo, string $table, int $id): bool { $allowed = ['employers', 'agencies', 'rooms']; if (!in_array($table, $allowed, true)) { return false; } $statement = $pdo->prepare("SELECT 1 FROM $table WHERE id = :id"); $statement->execute(['id' => $id]); return (bool) $statement->fetchColumn(); }
function active_room_count(PDO $pdo, int $roomId, int $exceptTenantId = 0): int { $statement = $pdo->prepare("SELECT COUNT(*) FROM tenants WHERE room_id = :room_id AND status = 'active' AND id != :tenant_id"); $statement->execute(['room_id' => $roomId, 'tenant_id' => $exceptTenantId]); return (int) $statement->fetchColumn(); }
function room_capacity(PDO $pdo, int $roomId): int { $statement = $pdo->prepare('SELECT capacity FROM rooms WHERE id = :id'); $statement->execute(['id' => $roomId]); $value = $statement->fetchColumn(); return $value === false ? 0 : (int) $value; }
function active_bed_taken(PDO $pdo, int $roomId, string $bedNumber, int $exceptTenantId = 0): bool { $statement = $pdo->prepare("SELECT 1 FROM tenants WHERE room_id = :room_id AND LOWER(bed_number) = LOWER(:bed_number) AND status = 'active' AND id != :tenant_id LIMIT 1"); $statement->execute(['room_id' => $roomId, 'bed_number' => $bedNumber, 'tenant_id' => $exceptTenantId]); return (bool) $statement->fetchColumn(); }
function posted_item_ids(mixed $value): array {
    if (!is_array($value)) { return []; }
    $ids = [];
    foreach ($value as $candidate) { $id = tenant_id($candidate); if ($id > 0) { $ids[$id] = $id; } }
    return array_values($ids);
}
function valid_active_item_ids(PDO $pdo, array $ids): array {
    if (!$ids) { return []; }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $statement = $pdo->prepare("SELECT id FROM tenant_items WHERE is_active=1 AND id IN ($placeholders)");
    $statement->execute($ids);
    $valid = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    sort($ids); sort($valid);
    if ($ids !== $valid) { throw new RuntimeException('One or more selected tenant items are no longer available.'); }
    return $valid;
}
function remember_tenant_form(array $input, int $tenantId): void {
    $allowed = ['full_name','nationality','contact_no','employee_id','passport_no','passport_expiry','arc_no','arc_expiry','shift_code','employer_id','agency_id','designation','emergency_contact_name','emergency_contact_no','room_id','bed_number','date_moved_in','monthly_rent','additional_comments'];
    $values = [];
    foreach ($allowed as $field) {
        $value = $input[$field] ?? '';
        if (!is_scalar($value)) { continue; }
        $values[$field] = mb_substr((string) $value, 0, 5000);
    }
    $values['item_ids'] = posted_item_ids($input['item_ids'] ?? []);
    $_SESSION['tenant_form_input'] = ['tenant_id' => $tenantId, 'values' => $values];
}
function consume_tenant_form(int $tenantId): array {
    $saved = $_SESSION['tenant_form_input'] ?? null;
    unset($_SESSION['tenant_form_input']);
    if (!is_array($saved) || (int) ($saved['tenant_id'] ?? -1) !== $tenantId || !is_array($saved['values'] ?? null)) { return []; }
    return $saved['values'];
}
function upload_tenant_photo(array $file): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { return null; }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > 2 * 1024 * 1024) { throw new RuntimeException('Photo upload failed. Use an image no larger than 2 MB.'); }
    if (!is_uploaded_file((string) ($file['tmp_name'] ?? ''))) { throw new RuntimeException('The uploaded photo is invalid.'); }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime])) { throw new RuntimeException('Photo must be a JPG, PNG, or WebP image.'); }
    $dimensions = @getimagesize($file['tmp_name']);
    if (!$dimensions || $dimensions[0] < 1 || $dimensions[1] < 1 || $dimensions[0] > 6000 || $dimensions[1] > 6000 || $dimensions[0] * $dimensions[1] > 24000000) { throw new RuntimeException('Photo dimensions are invalid or too large.'); }
    $directory = __DIR__ . '/uploads/tenants';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) { throw new RuntimeException('Tenant photo folder could not be created.'); }
    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $filename)) { throw new RuntimeException('Photo could not be saved.'); }
    return 'uploads/tenants/' . $filename;
}
function delete_tenant_photo(?string $storedPath): void {
    if ($storedPath === null || !preg_match('#^uploads/tenants/[a-f0-9]{32}\.(?:jpg|png|webp)$#', $storedPath)) { return; }
    $path = __DIR__ . '/' . $storedPath;
    if (is_file($path) && !unlink($path)) { error_log('Unable to remove obsolete tenant photo: ' . basename($path)); }
}
function tenant_by_id(PDO $pdo, int $id): ?array { $statement = $pdo->prepare("SELECT t.*, r.room_number, d.name AS dormitory_name, e.name AS employer_name, a.name AS agency_name FROM tenants t LEFT JOIN rooms r ON r.id=t.room_id LEFT JOIN dormitories d ON d.id=r.dormitory_id LEFT JOIN employers e ON e.id=t.employer_id LEFT JOIN agencies a ON a.id=t.agency_id WHERE t.id=:id"); $statement->execute(['id' => $id]); return $statement->fetch() ?: null; }

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'import_template') {
    if (($user['role'] ?? '') !== 'admin') { http_response_code(403); exit('Administrator access required.'); }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="tenant-import-template.csv"');
    header('Cache-Control: no-store, max-age=0');
    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'wb');
    fputcsv($output, ['full_name', 'nationality', 'contact_no', 'passport_no', 'passport_expiry', 'arc_no', 'arc_expiry', 'employee_id', 'employer', 'agency', 'designation', 'emergency_contact_name', 'emergency_contact_no', 'dormitory', 'room_number', 'bed_number', 'shift_code', 'monthly_rent', 'date_moved_in', 'additional_comments'], ',', '"', '');
    fclose($output);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $id = tenant_id($_POST['id'] ?? null);
    $photoPath = null;
    try {
      if ($action === 'delete_tenant') {
        if (($user['role'] ?? '') !== 'admin') { throw new RuntimeException('Only the Super administrator can permanently delete tenant records.'); }
        $tenant = tenant_by_id($pdo, $id);
        if (!$tenant) { throw new RuntimeException('Tenant not found.'); }
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM tenants WHERE id=:id')->execute(['id' => $id]);
        $pdo->commit();
        if (!empty($tenant['photo_path']) && str_starts_with((string) $tenant['photo_path'], 'uploads/tenants/')) {
          $photoPath = __DIR__ . '/' . ltrim((string) $tenant['photo_path'], '/');
          if (is_file($photoPath)) { unlink($photoPath); }
        }
        set_flash('Tenant record permanently deleted.');
        redirect('tenants.php');
      }
        if ($action === 'import_tenants') {
            if (($user['role'] ?? '') !== 'admin') { throw new RuntimeException('Only administrators can import tenant records.'); }
            $imported = tenant_import_apply($pdo, tenant_import_upload($_FILES['tenant_file'] ?? []));
            set_flash($imported . ' tenant' . ($imported === 1 ? '' : 's') . ' imported successfully.');
            redirect('tenants.php');
        }
        if ($action === 'move_out') {
            $tenant = tenant_by_id($pdo, $id);
            if (!$tenant || $tenant['status'] !== 'active') { throw new RuntimeException('Only active tenants can be moved out.'); }
            $movedOut = valid_date(nullable_post('date_moved_out', 10), 'Move-out date', true);
            if ($movedOut < $tenant['date_moved_in'] || $movedOut > date('Y-m-d')) { throw new RuntimeException('Move-out date must be between the tenant\'s move-in date and today.'); }
            $statement = $pdo->prepare("UPDATE tenants SET status='moved_out', date_moved_out=:date_moved_out WHERE id=:id");
            $statement->execute(['date_moved_out' => $movedOut, 'id' => $id]);
            set_flash('Tenant moved out. Their bed is now available.');
            redirect('tenants.php');
        }
        if ($action === 'issue_tenant_item') {
            $tenant = tenant_by_id($pdo, $id);
            $itemId = tenant_id($_POST['item_id'] ?? null);
            $issuedOn = valid_date(nullable_post('issued_on', 10), 'Issue date', true);
            $referenceNo = nullable_post('reference_no', 100);
            $issueNotes = nullable_post('issue_notes', 1000);
            if (!$tenant || $tenant['status'] !== 'active') { throw new RuntimeException('Items can only be issued to an active tenant.'); }
            valid_active_item_ids($pdo, [$itemId]);
            if ($issuedOn > date('Y-m-d')) { throw new RuntimeException('Issue date cannot be in the future.'); }
            $statement = $pdo->prepare('INSERT INTO tenant_item_assignments (tenant_id,item_id,reference_no,issue_notes,issued_on,issued_by) VALUES (:tenant_id,:item_id,:reference_no,:issue_notes,:issued_on,:issued_by)');
            $statement->execute(['tenant_id' => $id, 'item_id' => $itemId, 'reference_no' => $referenceNo, 'issue_notes' => $issueNotes, 'issued_on' => $issuedOn, 'issued_by' => $user['id']]);
            set_flash('Item issued to tenant.');
            redirect('tenants.php?action=view&id=' . $id);
        }
        if ($action === 'return_tenant_item') {
            $assignmentId = tenant_id($_POST['assignment_id'] ?? null);
            $returnedOn = valid_date(nullable_post('returned_on', 10), 'Return date', true);
            $returnNotes = nullable_post('return_notes', 1000);
            $statement = $pdo->prepare('SELECT issued_on FROM tenant_item_assignments WHERE id=:assignment_id AND tenant_id=:tenant_id AND returned_on IS NULL');
            $statement->execute(['assignment_id' => $assignmentId, 'tenant_id' => $id]);
            $issuedOn = $statement->fetchColumn();
            if ($issuedOn === false) { throw new RuntimeException('Active item assignment not found.'); }
            if ($returnedOn < $issuedOn || $returnedOn > date('Y-m-d')) { throw new RuntimeException('Return date must be between the issue date and today.'); }
            $statement = $pdo->prepare('UPDATE tenant_item_assignments SET returned_on=:returned_on,return_notes=:return_notes,returned_by=:returned_by WHERE id=:assignment_id AND tenant_id=:tenant_id AND returned_on IS NULL');
            $statement->execute(['returned_on' => $returnedOn, 'return_notes' => $returnNotes, 'returned_by' => $user['id'], 'assignment_id' => $assignmentId, 'tenant_id' => $id]);
            if ($statement->rowCount() !== 1) { throw new RuntimeException('This item was already returned.'); }
            set_flash('Item marked as returned.');
            redirect('tenants.php?action=view&id=' . $id);
        }
        if ($action === 'reactivate_tenant') {
            $tenant = tenant_by_id($pdo, $id);
            $roomId = tenant_id($_POST['room_id'] ?? null);
            $bedNumber = normalize_upper(nullable_post('bed_number', 30));
            $movedIn = valid_date(nullable_post('date_moved_in', 10), 'Move-in date', true);
            if (!$tenant || $tenant['status'] !== 'moved_out') { throw new RuntimeException('Only moved-out tenants can be reactivated.'); }
            if (!$roomId || !entity_name_exists($pdo, 'rooms', $roomId) || $bedNumber === null) { throw new RuntimeException('Choose a room and enter a bed number.'); }
            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT id FROM rooms WHERE id=:id FOR UPDATE');
            $lock->execute(['id' => $roomId]);
            if (active_room_count($pdo, $roomId) >= room_capacity($pdo, $roomId)) { throw new RuntimeException('This room is already at full capacity.'); }
            if (active_bed_taken($pdo, $roomId, $bedNumber)) { throw new RuntimeException('That bed is already assigned to an active tenant in this room.'); }
            $statement = $pdo->prepare("UPDATE tenants SET status='active', room_id=:room_id, bed_number=:bed_number, date_moved_in=:date_moved_in, date_moved_out=NULL WHERE id=:id");
            $statement->execute(['room_id' => $roomId, 'bed_number' => $bedNumber, 'date_moved_in' => $movedIn, 'id' => $id]);
            $pdo->commit();
            set_flash('Tenant reactivated.');
            redirect('tenants.php?action=view&id=' . $id);
        }
        if ($action === 'save_profile_event') {
            $tenant = tenant_by_id($pdo, $id);
            $type = (string) ($_POST['event_type'] ?? '');
            $start = valid_date(nullable_post('start_date', 10), 'Start date', true);
            $end = valid_date(nullable_post('end_date', 10), 'End date');
            $notes = nullable_post('notes', 5000);
            if (!$tenant || $tenant['status'] !== 'active' || !isset(MANUAL_TENANT_EVENT_TYPES[$type])) { throw new RuntimeException('Choose a valid event for an active tenant.'); }
            if ($end !== null && $end < $start) { throw new RuntimeException('End date cannot be before start date.'); }
            $statement = $pdo->prepare('INSERT INTO schedules (tenant_id,event_type,start_date,end_date,notes,created_by) VALUES (:tenant_id,:event_type,:start_date,:end_date,:notes,:created_by)');
            $statement->execute(['tenant_id' => $id, 'event_type' => $type, 'start_date' => $start, 'end_date' => $end, 'notes' => $notes, 'created_by' => $user['id']]);
            set_flash('Schedule event added.');
            redirect('tenants.php?action=view&id=' . $id . '&schedule_month=' . substr($start, 0, 7));
        }
        if ($action === 'update_profile_event') {
            $eventId = tenant_id($_POST['event_id'] ?? null);
            $statement = $pdo->prepare('SELECT id FROM schedules WHERE id=:event_id AND tenant_id=:tenant_id');
            $statement->execute(['event_id' => $eventId, 'tenant_id' => $id]);
            if (!$statement->fetchColumn()) { throw new RuntimeException('Tenant event not found.'); }
            $type = (string) ($_POST['event_type'] ?? '');
            $start = valid_date(nullable_post('start_date', 10), 'Start date', true);
            $end = valid_date(nullable_post('end_date', 10), 'End date');
            $notes = nullable_post('notes', 5000);
            if (!isset(MANUAL_TENANT_EVENT_TYPES[$type])) { throw new RuntimeException('Choose a valid event type.'); }
            if ($end !== null && $end < $start) { throw new RuntimeException('End date cannot be before start date.'); }
            $statement = $pdo->prepare('UPDATE schedules SET event_type=:event_type,start_date=:start_date,end_date=:end_date,notes=:notes WHERE id=:event_id AND tenant_id=:tenant_id');
            $statement->execute(['event_type' => $type, 'start_date' => $start, 'end_date' => $end, 'notes' => $notes, 'event_id' => $eventId, 'tenant_id' => $id]);
            set_flash('Tenant event updated.');
            redirect('tenants.php?action=view&id=' . $id . '&schedule_month=' . substr($start, 0, 7));
        }
        if ($action !== 'save_tenant') { throw new RuntimeException('Unknown request.'); }

        $fullName = normalize_upper(nullable_post('full_name', 150));
        $roomId = tenant_id($_POST['room_id'] ?? null);
        $bedNumber = normalize_upper(nullable_post('bed_number', 30));
        $movedIn = valid_date(nullable_post('date_moved_in', 10), 'Move-in date', true);
        if ($fullName === null || $roomId === 0 || !entity_name_exists($pdo, 'rooms', $roomId) || $bedNumber === null) { throw new RuntimeException('Full name, room, bed number, and move-in date are required.'); }
        $rent = filter_var($_POST['monthly_rent'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($rent === false || $rent < 0 || $rent > 99999999.99) { throw new RuntimeException('Monthly rent must be a valid positive amount.'); }
        $employerId = tenant_id($_POST['employer_id'] ?? null) ?: null;
        $agencyId = tenant_id($_POST['agency_id'] ?? null) ?: null;
        if (($employerId && !entity_name_exists($pdo, 'employers', $employerId)) || ($agencyId && !entity_name_exists($pdo, 'agencies', $agencyId))) { throw new RuntimeException('Select a valid employer and agency.'); }
        $shift = (string) ($_POST['shift_code'] ?? '');
        if (!in_array($shift, ['', 'DA', 'DB', 'NA', 'NB'], true)) { throw new RuntimeException('Select a valid shift.'); }
        $initialItemIds = $id === 0 ? valid_active_item_ids($pdo, posted_item_ids($_POST['item_ids'] ?? [])) : [];
        $photoPath = upload_tenant_photo($_FILES['photo'] ?? []);
        $existingTenant = $id > 0 ? tenant_by_id($pdo, $id) : null;
        if ($id > 0 && !$existingTenant) { throw new RuntimeException('Tenant not found.'); }
        if ($existingTenant === null || $existingTenant['status'] === 'active') {
            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT id FROM rooms WHERE id=:id FOR UPDATE');
            $lock->execute(['id' => $roomId]);
            if (active_room_count($pdo, $roomId, $id) >= room_capacity($pdo, $roomId)) { throw new RuntimeException('This room is already at full capacity.'); }
            if (active_bed_taken($pdo, $roomId, $bedNumber, $id)) { throw new RuntimeException('That bed is already assigned to an active tenant in this room.'); }
        }
        $passportExpiry = valid_date(nullable_post('passport_expiry', 10), 'Passport expiry');
        $arcExpiry = valid_date(nullable_post('arc_expiry', 10), 'ARC expiry');
        $data = [
            'full_name' => $fullName, 'nationality' => normalize_upper(nullable_post('nationality', 80)), 'contact_no' => contact_number(nullable_post('contact_no', 11), 'Contact number'),
            'passport_no' => normalize_upper(nullable_post('passport_no', 80)), 'passport_expiry' => $passportExpiry, 'arc_no' => normalize_upper(nullable_post('arc_no', 80)), 'arc_expiry' => $arcExpiry,
            'employee_id' => normalize_upper(nullable_post('employee_id', 80)), 'employer_id' => $employerId, 'agency_id' => $agencyId, 'designation' => normalize_upper(nullable_post('designation', 150)),
            'emergency_contact_name' => normalize_upper(nullable_post('emergency_contact_name', 150)), 'emergency_contact_no' => contact_number(nullable_post('emergency_contact_no', 11), 'Emergency contact number'), 'additional_comments' => nullable_post('additional_comments', 5000),
            'room_id' => $roomId, 'bed_number' => $bedNumber, 'shift_code' => $shift === '' ? null : $shift, 'monthly_rent' => $rent, 'date_moved_in' => $movedIn,
        ];
        if ($id > 0) {
            $photoSql = $photoPath ? ', photo_path=:photo_path' : '';
            $sql = "UPDATE tenants SET full_name=:full_name,nationality=:nationality,contact_no=:contact_no,passport_no=:passport_no,passport_expiry=:passport_expiry,arc_no=:arc_no,arc_expiry=:arc_expiry,employee_id=:employee_id,employer_id=:employer_id,agency_id=:agency_id,designation=:designation,emergency_contact_name=:emergency_contact_name,emergency_contact_no=:emergency_contact_no,additional_comments=:additional_comments,room_id=:room_id,bed_number=:bed_number,shift_code=:shift_code,monthly_rent=:monthly_rent,date_moved_in=:date_moved_in$photoSql WHERE id=:id";
            if ($photoPath) { $data['photo_path'] = $photoPath; } $data['id'] = $id;
            $pdo->prepare($sql)->execute($data);
            if ($pdo->inTransaction()) { $pdo->commit(); }
            if ($photoPath && !empty($existingTenant['photo_path'])) { delete_tenant_photo((string) $existingTenant['photo_path']); }
            set_flash('Tenant updated.');
        } else {
            $data['photo_path'] = $photoPath;
            $sql = 'INSERT INTO tenants (full_name,nationality,contact_no,passport_no,passport_expiry,arc_no,arc_expiry,employee_id,employer_id,agency_id,designation,emergency_contact_name,emergency_contact_no,additional_comments,room_id,bed_number,shift_code,monthly_rent,date_moved_in,photo_path) VALUES (:full_name,:nationality,:contact_no,:passport_no,:passport_expiry,:arc_no,:arc_expiry,:employee_id,:employer_id,:agency_id,:designation,:emergency_contact_name,:emergency_contact_no,:additional_comments,:room_id,:bed_number,:shift_code,:monthly_rent,:date_moved_in,:photo_path)';
            $pdo->prepare($sql)->execute($data);
            $newTenantId = (int) $pdo->lastInsertId();
            if ($initialItemIds) {
                $issue = $pdo->prepare('INSERT INTO tenant_item_assignments (tenant_id,item_id,issued_on,issued_by) VALUES (:tenant_id,:item_id,:issued_on,:issued_by)');
                foreach ($initialItemIds as $itemId) {
                    $issue->execute(['tenant_id' => $newTenantId, 'item_id' => $itemId, 'issued_on' => $movedIn, 'issued_by' => $user['id']]);
                }
            }
            if ($pdo->inTransaction()) { $pdo->commit(); }
            set_flash('Tenant added.');
        }
        redirect('tenants.php');
    } catch (PDOException $exception) {
      if ($pdo->inTransaction()) { $pdo->rollBack(); }
        delete_tenant_photo($photoPath);
        if ($exception->getCode() === '23000' && $action === 'delete_tenant') {
            set_flash('This tenant cannot be permanently deleted while payment, visitor, or item-assignment history refers to them. Move the tenant out instead.', 'error');
        } elseif ($exception->getCode() === '23000' && $action === 'issue_tenant_item') {
            set_flash('That item is already issued to this tenant.', 'error');
        } elseif ($exception->getCode() === '23000') {
            set_flash('The passport, ARC, or active room and bed assignment is already in use.', 'error');
        } else {
            set_flash('The tenant could not be saved. Please try again.', 'error');
        }
    } catch (RuntimeException $exception) { if ($pdo->inTransaction()) { $pdo->rollBack(); } delete_tenant_photo($photoPath); set_flash($exception->getMessage(), 'error'); }
    if ($action === 'import_tenants') { redirect('tenants.php?action=import'); }
    if (in_array($action, ['issue_tenant_item', 'return_tenant_item'], true)) { redirect('tenants.php?action=view&id=' . $id); }
    if (in_array($action, ['save_profile_event', 'update_profile_event'], true)) { redirect('tenants.php?action=view&id=' . $id); }
    if ($action === 'save_tenant') { remember_tenant_form($_POST, $id); }
    redirect($id > 0 ? 'tenants.php?action=edit&id=' . $id : 'tenants.php?action=add');
}

$action = (string) ($_GET['action'] ?? 'list'); $id = tenant_id($_GET['id'] ?? null);
$rooms = $pdo->query("SELECT r.id, r.room_number, r.capacity, d.id AS dormitory_id, d.name AS dormitory_name, COUNT(t.id) AS occupied FROM rooms r INNER JOIN dormitories d ON d.id=r.dormitory_id LEFT JOIN tenants t ON t.room_id=r.id AND t.status='active' GROUP BY r.id,r.room_number,r.capacity,d.id,d.name ORDER BY d.name,r.room_number")->fetchAll();
$dormitories = $pdo->query('SELECT id,name FROM dormitories ORDER BY name')->fetchAll();
$employers = $pdo->query('SELECT id,name FROM employers ORDER BY name')->fetchAll(); $agencies = $pdo->query('SELECT id,name FROM agencies ORDER BY name')->fetchAll();
$activeTenantItems = $pdo->query('SELECT id,name,description FROM tenant_items WHERE is_active=1 ORDER BY name')->fetchAll();
$flash = consume_flash();

if ($action === 'import') {
    if (($user['role'] ?? '') !== 'admin') { http_response_code(403); exit('Administrator access required.'); }
    page_start('Import Tenants', $user, 'tenants');
    ?>
    <section class="tenant-registry-hero tenant-import-hero">
      <div><p class="eyebrow">Housing Registry</p><h1>Import tenants</h1><p>Add active tenant records in bulk from a CSV or Excel workbook.</p></div>
      <div class="tenant-registry-actions"><a class="hero-link" href="tenants.php">Back to tenants</a></div>
    </section>
    <?php if ($flash): ?><p class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></p><?php endif; ?>
    <section class="panel tenant-import-panel">
      <div class="tenant-import-heading">
        <span aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3v12m0 0-4-4m4 4 4-4M5 19h14"/></svg></span>
        <div><p class="eyebrow">Bulk upload</p><h2>Choose your tenant file</h2><p>CSV and modern Excel <strong>.xlsx</strong> files are accepted. The import is checked completely before any tenant is saved.</p></div>
      </div>
      <form method="post" enctype="multipart/form-data" class="tenant-import-form">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="import_tenants">
        <label for="tenant-import-file">Tenant file <small>Maximum 5 MB and 500 tenant rows</small><input id="tenant-import-file" type="file" name="tenant_file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required></label>
        <button class="primary" type="submit"><span aria-hidden="true">↑</span> Import tenant records</button>
      </form>
    </section>
    <div class="tenant-import-guide">
      <section class="panel">
        <p class="eyebrow">Start here</p><h2>Use the template</h2>
        <p>Download the prepared header row, enter one active tenant per row, then save it as CSV or Excel (.xlsx).</p>
        <a class="button-link secondary" href="tenants.php?action=import_template">Download CSV template ↓</a>
      </section>
      <section class="panel">
        <p class="eyebrow">Before importing</p><h2>Match existing records</h2>
        <ul><li>Dormitory and room must already exist and match their saved names.</li><li>Employer and agency are optional, but any supplied names must already exist.</li><li>Required fields are full name, dormitory, room number, bed number, and move-in date.</li><li>Dates may use <strong>YYYY-MM-DD</strong> or normal Excel date cells. Shift may be DA, DB, NA, or NB.</li></ul>
      </section>
    </div>
    <section class="tenant-import-safety" aria-label="Import safeguards"><span>✓ Room capacity checked</span><span>✓ Duplicate beds blocked</span><span>✓ Passport and ARC checked</span><span>✓ All-or-nothing save</span></section>
    <?php page_end(); exit;
}

if (in_array($action, ['add', 'edit'], true)) {
    $tenant = $action === 'edit' ? tenant_by_id($pdo, $id) : null;
    if ($action === 'edit' && !$tenant) { set_flash('Tenant not found.', 'error'); redirect('tenants.php'); }
    $tenant = array_replace($tenant ?? [], consume_tenant_form($id));
    $selectedDormitoryId = 0;
    foreach ($rooms as $room) { if ((int) $room['id'] === (int) ($tenant['room_id'] ?? 0)) { $selectedDormitoryId = (int) $room['dormitory_id']; break; } }
    page_start($action === 'edit' ? 'Edit Tenant' : 'Add Tenant', $user, 'tenants');
    ?>
    <p class="eyebrow">Housing Registry</p><h1><?= $action === 'edit' ? 'Edit tenant' : 'Add tenant' ?></h1>
    <?php if ($flash): ?><p class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></p><?php endif; ?>
    <form class="tenant-form panel" method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="save_tenant"><input type="hidden" name="id" value="<?= (int) ($tenant['id'] ?? 0) ?>">
      <h2>Personal and document information</h2><div class="form-grid">
        <label>Full name *<input name="full_name" maxlength="150" value="<?= e($tenant['full_name'] ?? '') ?>" required></label><label>Nationality<input name="nationality" maxlength="80" value="<?= e($tenant['nationality'] ?? 'Filipino') ?>"></label>
        <label>Contact number<input name="contact_no" type="text" inputmode="numeric" pattern="[0-9]{1,11}" maxlength="11" value="<?= e($tenant['contact_no'] ?? '') ?>"></label><label>Employee ID<input name="employee_id" maxlength="80" value="<?= e($tenant['employee_id'] ?? '') ?>"></label>
        <label>Passport number<input name="passport_no" maxlength="80" value="<?= e($tenant['passport_no'] ?? '') ?>"></label><label>Passport expiry<input type="date" name="passport_expiry" value="<?= e($tenant['passport_expiry'] ?? '') ?>"></label>
        <label>ARC number<input name="arc_no" maxlength="80" value="<?= e($tenant['arc_no'] ?? '') ?>"></label><label>ARC expiry<input type="date" name="arc_expiry" value="<?= e($tenant['arc_expiry'] ?? '') ?>"></label>
        <label>Shift<select name="shift_code"><option value="">— Not set —</option><?php foreach (['DA' => 'Morning Shift A-Pan', 'DB' => 'Morning Shift B-Pan', 'NA' => 'Night Shift A-Pan', 'NB' => 'Night Shift B-Pan'] as $code => $label): ?><option value="<?= $code ?>" <?= ($tenant['shift_code'] ?? '') === $code ? 'selected' : '' ?>><?= $code ?> — <?= e($label) ?></option><?php endforeach; ?></select></label>
        <label>Tenant photo <small>JPG, PNG, or WebP; max 2 MB</small><input type="file" name="photo" accept="image/jpeg,image/png,image/webp"></label>
      </div>
      <h2>Employment and emergency contact</h2><div class="form-grid">
        <label>Employer<select name="employer_id"><option value="">— None —</option><?php foreach ($employers as $item): ?><option value="<?= (int) $item['id'] ?>" <?= (int) ($tenant['employer_id'] ?? 0) === (int) $item['id'] ? 'selected' : '' ?>><?= e($item['name']) ?></option><?php endforeach; ?></select></label>
        <label>Agency<select name="agency_id"><option value="">— None —</option><?php foreach ($agencies as $item): ?><option value="<?= (int) $item['id'] ?>" <?= (int) ($tenant['agency_id'] ?? 0) === (int) $item['id'] ? 'selected' : '' ?>><?= e($item['name']) ?></option><?php endforeach; ?></select></label>
        <label>Position/designation<input name="designation" maxlength="150" value="<?= e($tenant['designation'] ?? '') ?>"></label><label>Emergency contact name<input name="emergency_contact_name" maxlength="150" value="<?= e($tenant['emergency_contact_name'] ?? '') ?>"></label>
        <label>Emergency contact number<input name="emergency_contact_no" type="text" inputmode="numeric" pattern="[0-9]{1,11}" maxlength="11" value="<?= e($tenant['emergency_contact_no'] ?? '') ?>"></label>
      </div>
      <h2>Accommodation</h2><div class="form-grid">
        <label>Dormitory *<select id="dormitory_id" required><option value="">— Select dormitory —</option><?php foreach ($dormitories as $dormitory): ?><option value="<?= (int) $dormitory['id'] ?>" <?= $selectedDormitoryId === (int) $dormitory['id'] ? 'selected' : '' ?>><?= e($dormitory['name']) ?></option><?php endforeach; ?></select></label>
        <label>Room *<select id="room_id" name="room_id" required><option value="">— Select room —</option><?php foreach ($rooms as $room): $isCurrent = (int) ($tenant['room_id'] ?? 0) === (int) $room['id']; $full = (int) $room['occupied'] >= (int) $room['capacity']; ?><option value="<?= (int) $room['id'] ?>" data-dormitory-id="<?= (int) $room['dormitory_id'] ?>" <?= $isCurrent ? 'selected' : '' ?> <?= $full && !$isCurrent ? 'disabled' : '' ?>>Room <?= e($room['room_number']) ?> (<?= (int) $room['occupied'] ?>/<?= (int) $room['capacity'] ?>)</option><?php endforeach; ?></select></label>
        <label>Bed number *<input name="bed_number" maxlength="30" value="<?= e($tenant['bed_number'] ?? '') ?>" placeholder="e.g. Bed 2" required></label>
        <label>Move-in date *<input type="date" name="date_moved_in" value="<?= e($tenant['date_moved_in'] ?? date('Y-m-d')) ?>" required></label><label>Monthly rent (NT$) *<input type="number" name="monthly_rent" min="0" step="0.01" value="<?= e((string) ($tenant['monthly_rent'] ?? '0.00')) ?>" required></label>
        <label class="tenant-comments-field">Additional comments <small>Optional; maximum 5,000 characters</small><textarea name="additional_comments" maxlength="5000" rows="4" placeholder="Add any helpful notes about this tenant."><?= e($tenant['additional_comments'] ?? '') ?></textarea></label>
      </div>
      <?php if ($action === 'add' && $activeTenantItems): $selectedInitialItems = posted_item_ids($tenant['item_ids'] ?? []); ?>
        <fieldset class="tenant-item-checklist"><legend>Items issued at move-in</legend><p class="muted">Check each item being handed to this tenant. Details such as a key number can be added from the tenant profile afterward.</p><div class="tenant-item-check-grid">
          <?php foreach ($activeTenantItems as $item): ?><label><input type="checkbox" name="item_ids[]" value="<?= (int) $item['id'] ?>" <?= in_array((int) $item['id'], $selectedInitialItems, true) ? 'checked' : '' ?>><span><strong><?= e($item['name']) ?></strong><?php if ($item['description']): ?><small><?= e($item['description']) ?></small><?php endif; ?></span></label><?php endforeach; ?>
        </div></fieldset>
      <?php elseif ($action === 'add'): ?>
        <p class="tenant-items-empty-note">No tenant items are configured yet. An administrator can add them under Dormitories &amp; Rooms.</p>
      <?php endif; ?>
      <button class="primary" type="submit">Save tenant</button> <a class="cancel" href="tenants.php">Cancel</a>
    </form>
    <script>
      (() => {
        const dormitory = document.getElementById('dormitory_id');
        const room = document.getElementById('room_id');
        const refreshRooms = () => {
          const selectedDormitory = dormitory.value;
          let selectedStillVisible = false;
          [...room.options].forEach((option, index) => {
            if (index === 0) return;
            const visible = option.dataset.dormitoryId === selectedDormitory;
            option.hidden = !visible;
            if (option.selected && visible) selectedStillVisible = true;
          });
          if (!selectedStillVisible) room.value = '';
          room.disabled = selectedDormitory === '';
        };
        dormitory.addEventListener('change', refreshRooms);
        refreshRooms();
      })();
    </script>
    <?php page_end(); exit;
}

if ($action === 'edit_schedule') {
    $eventId = tenant_id($_GET['event_id'] ?? null);
    $tenant = tenant_by_id($pdo, $id);
    $statement = $pdo->prepare('SELECT id,event_type,start_date,end_date,notes FROM schedules WHERE id=:event_id AND tenant_id=:tenant_id');
    $statement->execute(['event_id' => $eventId, 'tenant_id' => $id]);
    $event = $statement->fetch() ?: null;
    if (!$tenant || !$event) { set_flash('Tenant event not found.', 'error'); redirect('tenants.php'); }
    page_start('Edit Tenant Event', $user, 'tenants');
    ?>
    <p class="eyebrow">Tenant Schedule</p><h1>Edit event — <?= e($tenant['full_name']) ?></h1>
    <form method="post" class="panel payment-form"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="update_profile_event"><input type="hidden" name="id" value="<?= (int) $tenant['id'] ?>"><input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>"><div class="form-grid"><label>Event type *<select name="event_type"><?php foreach (MANUAL_TENANT_EVENT_TYPES as $key => $label): ?><option value="<?= e($key) ?>" <?= $event['event_type'] === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label><label>Start date *<input type="date" name="start_date" value="<?= e($event['start_date']) ?>" required></label><label>End date <small>(optional)</small><input type="date" name="end_date" value="<?= e($event['end_date']) ?>"></label></div><label>Notes<textarea name="notes" maxlength="5000" rows="4"><?= e($event['notes']) ?></textarea></label><button class="primary" type="submit">Save event</button> <a class="cancel" href="tenants.php?action=view&id=<?= (int) $tenant['id'] ?>">Cancel</a></form>
    <?php page_end(); exit;
}

if ($action === 'view') {
    $tenant = tenant_by_id($pdo, $id); if (!$tenant) { set_flash('Tenant not found.', 'error'); redirect('tenants.php'); }
    $scheduleMonth = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) ($_GET['schedule_month'] ?? '')) ? (string) $_GET['schedule_month'] : date('Y-m');
    $scheduleFirst = new DateTime($scheduleMonth . '-01');
    $scheduleLast = (clone $scheduleFirst)->modify('last day of this month');
    $statement = $pdo->prepare('SELECT id,event_type,start_date,end_date,notes FROM schedules WHERE tenant_id=:tenant_id AND start_date<=:last_day AND (end_date IS NULL OR end_date>=:first_day) ORDER BY start_date');
    $statement->execute(['tenant_id' => $id, 'first_day' => $scheduleFirst->format('Y-m-d'), 'last_day' => $scheduleLast->format('Y-m-d')]);
    $scheduleByDay = [];
    $importedShiftEntryCount = 0;
    foreach ($statement->fetchAll() as $event) {
        $cursor = new DateTime(max($event['start_date'], $scheduleFirst->format('Y-m-d')));
        $eventEnd = new DateTime(min($event['end_date'] ?: $event['start_date'], $scheduleLast->format('Y-m-d')));
        while ($cursor <= $eventEnd) { $scheduleByDay[$cursor->format('j')][] = $event; $cursor->modify('+1 day'); }
    }
    if ($tenant['status'] === 'active' && in_array($tenant['shift_code'], ['DA', 'DB', 'NA', 'NB'], true)) {
        $statement = $pdo->prepare('SELECT shift_code,schedule_date,is_work_day,source_value FROM shift_schedule_entries WHERE shift_code=:shift_code AND schedule_date BETWEEN :first_day AND :last_day ORDER BY schedule_date');
        $statement->execute(['shift_code' => $tenant['shift_code'], 'first_day' => $scheduleFirst->format('Y-m-d'), 'last_day' => $scheduleLast->format('Y-m-d')]);
        foreach ($statement->fetchAll() as $entry) {
            $importedShiftEntryCount++;
            $type = $entry['is_work_day'] ? strtolower($entry['shift_code']) : 'day_off';
            $scheduleByDay[(new DateTime($entry['schedule_date']))->format('j')][] = ['id' => null, 'event_type' => $type, 'display_label' => $entry['source_value'], 'notes' => '', 'is_shift_entry' => true];
        }
    }
    $previousScheduleMonth = (clone $scheduleFirst)->modify('-1 month')->format('Y-m');
    $nextScheduleMonth = (clone $scheduleFirst)->modify('+1 month')->format('Y-m');
    $statement = $pdo->prepare("SELECT a.id,a.item_id,a.reference_no,a.issue_notes,a.issued_on,a.returned_on,a.return_notes,
        i.name item_name,i.is_active,issuer.full_name issued_by_name,receiver.full_name returned_by_name
        FROM tenant_item_assignments a
        INNER JOIN tenant_items i ON i.id=a.item_id
        LEFT JOIN users issuer ON issuer.id=a.issued_by
        LEFT JOIN users receiver ON receiver.id=a.returned_by
        WHERE a.tenant_id=:tenant_id
        ORDER BY (a.returned_on IS NULL) DESC,a.issued_on DESC,a.id DESC");
    $statement->execute(['tenant_id' => $id]);
    $itemAssignments = $statement->fetchAll();
    $activeItemAssignments = array_values(array_filter($itemAssignments, fn(array $assignment): bool => $assignment['returned_on'] === null));
    $activeAssignmentItemIds = array_map(fn(array $assignment): int => (int) $assignment['item_id'], $activeItemAssignments);
    $issuableTenantItems = array_values(array_filter($activeTenantItems, fn(array $item): bool => !in_array((int) $item['id'], $activeAssignmentItemIds, true)));
    $outstandingItemCount = count($activeItemAssignments);
    page_start('Tenant Profile', $user, 'tenants');
    ?>
    <?php if ($flash): ?><p class="flash <?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></p><?php endif; ?>
    <section class="tenant-profile-hero">
      <div><p class="eyebrow">Tenant Profile</p><div class="tenant-profile-title"><h1><?= e($tenant['full_name']) ?></h1><span class="<?= e($tenant['status']) ?>"><?= e(ucwords(str_replace('_', ' ', $tenant['status']))) ?></span></div><p><?= e(($tenant['dormitory_name'] ?? 'Unassigned') . ($tenant['room_number'] ? ' · Room ' . $tenant['room_number'] : '') . ($tenant['bed_number'] ? ' / Bed ' . $tenant['bed_number'] : '')) ?></p></div>
      <div class="tenant-profile-actions"><a class="hero-link" href="tenants.php"><span aria-hidden="true">←</span> Back to tenants</a><a class="button-link" href="tenants.php?action=edit&amp;id=<?= (int) $tenant['id'] ?>">Edit profile <span aria-hidden="true">→</span></a><?php if (($user['role'] ?? '') === 'admin'): ?><form method="post" onsubmit="return confirm('Permanently delete this tenant record? This cannot be undone.');"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="delete_tenant"><input type="hidden" name="id" value="<?= (int) $tenant['id'] ?>"><button class="danger" type="submit">Delete permanently</button></form><?php endif; ?></div>
    </section>
    <section class="profile panel"><div class="photo"><?php if ($tenant['photo_path']): ?><img src="tenant_photo.php?id=<?= (int) $tenant['id'] ?>" alt="Photo of <?= e($tenant['full_name']) ?>"><?php else: ?>No photo<?php endif; ?></div><div><p><strong>Status:</strong> <?= e(ucwords(str_replace('_', ' ', $tenant['status']))) ?></p><p><strong>Room/bed:</strong> <?= e(($tenant['dormitory_name'] ?? 'Unassigned') . ' — ' . ($tenant['room_number'] ?? '') . ' ' . ($tenant['bed_number'] ?? '')) ?></p><p><strong>Shift:</strong> <?= $tenant['shift_code'] ? e(TENANT_EVENT_TYPES[strtolower($tenant['shift_code'])] ?? $tenant['shift_code']) : 'Not set' ?></p><p><strong>Contact:</strong> <?= e($tenant['contact_no']) ?></p><p><strong>Employer:</strong> <?= e($tenant['employer_name']) ?><?= $tenant['designation'] ? ' — ' . e($tenant['designation']) : '' ?></p><p><strong>Agency:</strong> <?= e($tenant['agency_name']) ?></p><p><strong>Passport:</strong> <?= e($tenant['passport_no']) ?><?= $tenant['passport_expiry'] ? ' (expires ' . e($tenant['passport_expiry']) . ')' : '' ?></p><p><strong>ARC:</strong> <?= e($tenant['arc_no']) ?><?= $tenant['arc_expiry'] ? ' (expires ' . e($tenant['arc_expiry']) . ')' : '' ?></p><p><strong>Emergency contact:</strong> <?= e($tenant['emergency_contact_name']) ?> <?= e($tenant['emergency_contact_no']) ?></p><p class="tenant-profile-comments"><strong>Additional comments:</strong> <?= $tenant['additional_comments'] ? nl2br(e($tenant['additional_comments'])) : '<span class="not-set">None</span>' ?></p></div></section>
    <section class="panel tenant-items-profile-card">
      <div class="tenant-items-profile-heading"><div><p class="eyebrow">Property accountability</p><h2>Assigned items</h2><p><?= $outstandingItemCount ?> item<?= $outstandingItemCount === 1 ? '' : 's' ?> currently held</p></div><a href="reports.php?item_status=issued&amp;tenant_status=all" class="table-action">Open items report</a></div>
      <?php if (!$activeItemAssignments): ?><div class="tenant-items-clear">No items are currently issued to this tenant.</div><?php else: ?>
        <div class="tenant-current-items">
          <?php foreach ($activeItemAssignments as $assignment): ?><article><div><strong><?= e($assignment['item_name']) ?></strong><span>Issued <?= e($assignment['issued_on']) ?><?= $assignment['issued_by_name'] ? ' by ' . e($assignment['issued_by_name']) : '' ?></span><?php if ($assignment['reference_no']): ?><small>Reference: <?= e($assignment['reference_no']) ?></small><?php endif; ?><?php if ($assignment['issue_notes']): ?><small><?= e($assignment['issue_notes']) ?></small><?php endif; ?></div><form method="post" class="tenant-item-return-form" onsubmit="return confirm('Mark this item as returned?');"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="return_tenant_item"><input type="hidden" name="id" value="<?= (int) $tenant['id'] ?>"><input type="hidden" name="assignment_id" value="<?= (int) $assignment['id'] ?>"><label>Return date<input type="date" name="returned_on" min="<?= e($assignment['issued_on']) ?>" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" required></label><label>Return notes<input name="return_notes" maxlength="1000" placeholder="Optional condition or details"></label><button type="submit">Mark returned</button></form></article><?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php if ($tenant['status'] === 'active' && $issuableTenantItems): ?>
        <form method="post" class="tenant-item-issue-form"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="issue_tenant_item"><input type="hidden" name="id" value="<?= (int) $tenant['id'] ?>"><h3>Issue another item</h3><div class="form-grid"><label>Item *<select name="item_id" required><option value="">— Select item —</option><?php foreach ($issuableTenantItems as $item): ?><option value="<?= (int) $item['id'] ?>"><?= e($item['name']) ?></option><?php endforeach; ?></select></label><label>Issue date *<input type="date" name="issued_on" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" required></label><label>Reference / identifier<input name="reference_no" maxlength="100" placeholder="e.g. Key No. 12"></label><label>Issue notes<input name="issue_notes" maxlength="1000" placeholder="Optional details"></label></div><button class="primary" type="submit">Issue item</button></form>
      <?php endif; ?>
      <?php if ($itemAssignments): ?><details class="tenant-item-history"><summary>View complete item history (<?= count($itemAssignments) ?>)</summary><div class="table-scroll"><table><thead><tr><th>Item</th><th>Reference</th><th>Issued</th><th>Returned</th><th>Status</th></tr></thead><tbody><?php foreach ($itemAssignments as $assignment): ?><tr><td><strong><?= e($assignment['item_name']) ?></strong></td><td><?= $assignment['reference_no'] ? e($assignment['reference_no']) : '—' ?></td><td><?= e($assignment['issued_on']) ?><small><?= $assignment['issued_by_name'] ? e($assignment['issued_by_name']) : 'Unknown user' ?></small></td><td><?= $assignment['returned_on'] ? e($assignment['returned_on']) : '—' ?><small><?= $assignment['returned_by_name'] ? e($assignment['returned_by_name']) : '' ?></small></td><td><span class="item-state <?= $assignment['returned_on'] ? 'returned' : 'issued' ?>"><?= $assignment['returned_on'] ? 'Returned' : 'Issued' ?></span></td></tr><?php endforeach; ?></tbody></table></div></details><?php endif; ?>
    </section>
    <?php if ($tenant['status'] === 'active'): ?><section class="panel move-out-panel"><h2>Move out</h2><?php if ($outstandingItemCount > 0): ?><p class="outstanding-items-warning"><strong>Outstanding property:</strong> This tenant still has <?= $outstandingItemCount ?> issued item<?= $outstandingItemCount === 1 ? '' : 's' ?>. Moving out will not mark them as returned.</p><?php endif; ?><form method="post" class="inline-form" onsubmit="return confirm('Move this tenant out? Their bed will become available.<?= $outstandingItemCount > 0 ? ' Outstanding items will remain issued.' : '' ?>');"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="move_out"><input type="hidden" name="id" value="<?= (int) $tenant['id'] ?>"><label>Move-out date<input type="date" name="date_moved_out" value="<?= date('Y-m-d') ?>" required></label><button class="danger" type="submit">Move out tenant</button></form></section><?php endif; ?>
    <?php if ($tenant['status'] === 'moved_out'): ?><section class="panel reactivate-panel"><h2>Reactivate tenant</h2><p class="muted">Assign an available room and bed before restoring this tenant to active status.</p><form method="post" class="reactivate-form"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="reactivate_tenant"><input type="hidden" name="id" value="<?= (int) $tenant['id'] ?>"><div class="form-grid"><label>Room *<select name="room_id" required><option value="">— Select room —</option><?php foreach ($rooms as $room): $isCurrent = (int) $tenant['room_id'] === (int) $room['id']; $isFull = (int) $room['occupied'] >= (int) $room['capacity']; ?><option value="<?= (int) $room['id'] ?>" <?= $isCurrent ? 'selected' : '' ?> <?= $isFull ? 'disabled' : '' ?>><?= e($room['dormitory_name'] . ' — Room ' . $room['room_number'] . ' (' . $room['occupied'] . '/' . $room['capacity'] . ')') ?></option><?php endforeach; ?></select></label><label>Bed number *<input name="bed_number" maxlength="30" value="<?= e($tenant['bed_number'] ?? '') ?>" required></label><label>New move-in date *<input type="date" name="date_moved_in" value="<?= date('Y-m-d') ?>" required></label></div><button class="primary" type="submit" onclick="return confirm('Reactivate this tenant?');">Reactivate tenant</button></form></section><?php endif; ?>
    <section class="panel tenant-schedule">
      <div class="title-actions"><h2>Schedule — <?= e($scheduleFirst->format('F Y')) ?></h2><div class="calendar-actions"><a class="button-link secondary" href="tenants.php?action=view&id=<?= (int) $tenant['id'] ?>&schedule_month=<?= e($previousScheduleMonth) ?>">←</a><a class="button-link secondary" href="tenants.php?action=view&id=<?= (int) $tenant['id'] ?>&schedule_month=<?= date('Y-m') ?>">Today</a><a class="button-link secondary" href="tenants.php?action=view&id=<?= (int) $tenant['id'] ?>&schedule_month=<?= e($nextScheduleMonth) ?>">→</a></div></div>
      <?php if ($tenant['status'] === 'active' && $tenant['shift_code'] && $importedShiftEntryCount === 0): ?><p class="muted">No imported <?= e($tenant['shift_code']) ?> shift schedule exists for this month yet.</p><?php endif; ?>
      <div class="calendar-grid tenant-calendar"><?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $day): ?><div class="calendar-heading"><?= $day ?></div><?php endforeach; ?><?php for ($blank=0; $blank < (int) $scheduleFirst->format('w'); $blank++): ?><div class="calendar-day blank"></div><?php endfor; ?><?php for ($day=1; $day <= (int) $scheduleLast->format('j'); $day++): ?><div class="calendar-day"><strong><?= $day ?></strong><?php foreach ($scheduleByDay[$day] ?? [] as $event): $eventLabel = $event['display_label'] ?? (TENANT_EVENT_TYPES[$event['event_type']] ?? $event['event_type']); $eventTooltip = $eventLabel . ($event['notes'] ? ' — ' . $event['notes'] : ''); ?><?php if (!empty($event['is_shift_entry'])): ?><span class="event <?= e($event['event_type']) ?>" title="<?= e($eventTooltip) ?>"><?= e($eventLabel) ?><small><?= e($event['notes']) ?></small></span><?php else: ?><a class="event <?= e($event['event_type']) ?>" title="<?= e($eventTooltip) ?>" href="tenants.php?action=edit_schedule&id=<?= (int) $tenant['id'] ?>&event_id=<?= (int) $event['id'] ?>"><?= e($eventLabel) ?><small><?= e($event['notes']) ?></small></a><?php endif; ?><?php endforeach; ?></div><?php endfor; ?></div>
    </section>
    <?php if ($tenant['status'] === 'active'): ?><section class="panel quick-schedule"><h2>Quick add schedule</h2><form method="post"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="save_profile_event"><input type="hidden" name="id" value="<?= (int) $tenant['id'] ?>"><div class="form-grid"><label>Type *<select name="event_type"><?php foreach (MANUAL_TENANT_EVENT_TYPES as $key => $label): ?><option value="<?= e($key) ?>"><?= e($label) ?></option><?php endforeach; ?></select></label><label>Start date *<input type="date" name="start_date" value="<?= date('Y-m-d') ?>" required></label><label>End date <small>(optional)</small><input type="date" name="end_date"></label></div><label>Notes<textarea name="notes" maxlength="5000" rows="3" placeholder="Optional details"></textarea></label><button class="primary" type="submit">Add to schedule</button></form></section><?php endif; ?>
    <?php page_end(); exit;
}

$query = trim((string) ($_GET['q'] ?? '')); $status = (string) ($_GET['status'] ?? 'all'); if (!in_array($status, ['active', 'moved_out', 'all'], true)) { $status = 'all'; }
$where = []; $params = []; if ($status !== 'all') { $where[] = 't.status=:status'; $params['status'] = $status; } if ($query !== '') { $where[] = '(t.full_name LIKE :q_name OR t.passport_no LIKE :q_passport OR t.employee_id LIKE :q_employee OR e.name LIKE :q_employer)'; $searchTerm = '%' . $query . '%'; $params['q_name'] = $searchTerm; $params['q_passport'] = $searchTerm; $params['q_employee'] = $searchTerm; $params['q_employer'] = $searchTerm; }
$sql = "SELECT t.id,t.full_name,t.employee_id,t.contact_no,t.status,t.date_moved_in,t.monthly_rent,t.bed_number,r.room_number,d.name AS dormitory_name,e.name AS employer_name FROM tenants t LEFT JOIN rooms r ON r.id=t.room_id LEFT JOIN dormitories d ON d.id=r.dormitory_id LEFT JOIN employers e ON e.id=t.employer_id" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY t.full_name';
$statement = $pdo->prepare($sql); $statement->execute($params); $tenants = $statement->fetchAll();
$tenantCounts = ['active' => 0, 'moved_out' => 0, 'all' => 0];
foreach ($pdo->query('SELECT status, COUNT(*) AS total FROM tenants GROUP BY status') as $countRow) {
    $count = (int) $countRow['total'];
    if (isset($tenantCounts[$countRow['status']])) { $tenantCounts[$countRow['status']] = $count; }
    $tenantCounts['all'] += $count;
}
page_start('Tenants', $user, 'tenants');
?>
  <section class="tenant-registry-hero">
    <div><p class="eyebrow">Housing Registry</p><h1>Tenants</h1><p>Manage resident profiles, room assignments, and occupancy status.</p></div>
    <div class="tenant-registry-actions"><?php if (($user['role'] ?? '') === 'admin'): ?><a class="hero-link tenant-import-link" href="tenants.php?action=import"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12m0 0-4-4m4 4 4-4M5 19h14"/></svg> Import tenants</a><?php endif; ?><a class="button-link" href="tenants.php?action=add"><span aria-hidden="true">+</span> Add tenant</a></div>
  </section>
  <section class="tenant-summary-grid" aria-label="Tenant summary">
    <article><span class="tenant-summary-icon active" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M17 11l2 2 4-5"/></svg></span><div><span>Active tenants</span><strong><?= $tenantCounts['active'] ?></strong></div></article>
    <article><span class="tenant-summary-icon moved" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M10 17l5-5-5-5M15 12H3M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/></svg></span><div><span>Moved out</span><strong><?= $tenantCounts['moved_out'] ?></strong></div></article>
    <article><span class="tenant-summary-icon total" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg></span><div><span>All records</span><strong><?= $tenantCounts['all'] ?></strong></div></article>
  </section>
  <?php if ($flash): ?><p class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></p><?php endif; ?>
  <section class="panel tenant-registry-controls">
    <nav class="tenant-status-tabs" aria-label="Filter tenants by status">
      <a class="<?= $status === 'active' ? 'selected' : '' ?>" href="tenants.php?status=active&amp;q=<?= e(rawurlencode($query)) ?>">Active <span><?= $tenantCounts['active'] ?></span></a>
      <a class="<?= $status === 'moved_out' ? 'selected' : '' ?>" href="tenants.php?status=moved_out&amp;q=<?= e(rawurlencode($query)) ?>">Moved out <span><?= $tenantCounts['moved_out'] ?></span></a>
      <a class="<?= $status === 'all' ? 'selected' : '' ?>" href="tenants.php?status=all&amp;q=<?= e(rawurlencode($query)) ?>">All tenants <span><?= $tenantCounts['all'] ?></span></a>
    </nav>
    <form class="tenant-search" method="get"><input type="hidden" name="status" value="<?= e($status) ?>"><label class="visually-hidden" for="tenant-search-input">Search tenants</label><span class="tenant-search-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg></span><input id="tenant-search-input" name="q" value="<?= e($query) ?>" placeholder="Search name, passport, employer, or employee ID"><button type="submit">Search</button><?php if ($query !== ''): ?><a href="tenants.php?status=<?= e($status) ?>">Clear</a><?php endif; ?></form>
  </section>
  <section class="panel table-panel tenant-registry-table">
    <div class="tenant-table-heading"><div><p class="eyebrow"><?= $status === 'all' ? 'Full directory' : e(ucwords(str_replace('_', ' ', $status))) ?></p><h2>Tenant records</h2></div><span class="count-badge"><?= count($tenants) ?> result<?= count($tenants) === 1 ? '' : 's' ?></span></div>
    <table><thead><tr><th>Name</th><th>Employee ID</th><th>Room / bed</th><th>Employer</th><th>Contact</th><th>Moved in</th><th>Status</th><th><span class="visually-hidden">Actions</span></th></tr></thead><tbody><?php if (!$tenants): ?><tr><td colspan="8"><div class="report-empty">No tenants match the current filters.</div></td></tr><?php else: foreach ($tenants as $tenant): preg_match('/^./u', (string) $tenant['full_name'], $tenantInitialMatch); ?><tr><td><div class="tenant-name-cell"><span aria-hidden="true"><?= e(strtoupper($tenantInitialMatch[0] ?? '?')) ?></span><strong><?= e($tenant['full_name']) ?></strong></div></td><td><span class="employee-id"><?= e($tenant['employee_id'] ?: 'Not set') ?></span></td><td><?php if ($tenant['room_number']): ?><span class="tenant-room"><?= e(($tenant['dormitory_name'] ? $tenant['dormitory_name'] . ' · ' : '') . $tenant['room_number'] . ' / ' . ($tenant['bed_number'] ?? '—')) ?></span><?php else: ?><span class="not-set">Unassigned</span><?php endif; ?></td><td><?= $tenant['employer_name'] ? e($tenant['employer_name']) : '<span class="not-set">Not set</span>' ?></td><td><?= $tenant['contact_no'] ? e($tenant['contact_no']) : '<span class="not-set">Not set</span>' ?></td><td><?= e($tenant['date_moved_in']) ?></td><td><span class="tenant-status <?= e($tenant['status']) ?>"><?= e(ucwords(str_replace('_', ' ', $tenant['status']))) ?></span></td><td><a class="tenant-view-action" href="tenants.php?action=view&amp;id=<?= (int) $tenant['id'] ?>">View <span aria-hidden="true">→</span></a><?php if (($user['role'] ?? '') === 'admin'): ?><form method="post" class="inline-delete" onsubmit="return confirm('Permanently delete this tenant record? This cannot be undone.');"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="delete_tenant"><input type="hidden" name="id" value="<?= (int) $tenant['id'] ?>"><button class="table-action danger-action" type="submit">Delete</button></form><?php endif; ?></td></tr><?php endforeach; endif; ?></tbody></table>
  </section>
<?php page_end();
