<?php
/**
 * OFW Dormitory System REST API
 * Target environment: XAMPP / Apache / PHP 8+ / MySQL 8+
 *
 * This file is the backend layer only.
 * The database/schema will be created separately.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

start_app_session();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function json_response(mixed $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function error_response(string $message, int $status = 400, ?Throwable $e = null): never {
    $payload = ['success' => false, 'error' => $message];

    // Detailed PHP errors are intentionally not exposed to browser users.
    if ($e && getenv('OFW_DEBUG') === '1') {
        $payload['debug'] = $e->getMessage();
    }

    json_response($payload, $status);
}

function request_json(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        error_response('Request body must be valid JSON.', 400);
    }

    return $data;
}

function path_parts(): array {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

    // Strip everything before /api/ so the backend works inside any XAMPP subfolder.
    $pos = strpos($path, '/api/');
    if ($pos !== false) {
        $path = substr($path, $pos + 5);
    } else {
        $path = trim($path, '/');
    }

    $path = trim($path, '/');
    return $path === '' ? [] : explode('/', $path);
}

function require_login(): array {
    if (empty($_SESSION['staff_id'])) {
        error_response('Authentication required.', 401);
    }

    return [
        'id'     => $_SESSION['staff_id'],
        'role'   => $_SESSION['role'] ?? 'staff',
        'name'   => $_SESSION['full_name'] ?? '',
        'username' => $_SESSION['username'] ?? '',
    ];
}

function require_admin(): array {
    $session = require_login();

    if (($session['role'] ?? '') !== 'admin') {
        error_response('Administrator permission required.', 403);
    }

    return $session;
}

function id(): string {
    $parts = path_parts();
    $value = $parts[1] ?? '';
    if ($value === '') {
        error_response('Record ID is required.', 400);
    }
    return $value;
}

function now_iso(): string {
    return date('Y-m-d H:i:s');
}

function allowed_fields(string $resource): array {
    return match ($resource) {
        'tenants' => [
            'full_name','nationality','contact_no','passport_no','passport_expiry',
            'arc_no','arc_expiry','date_moved_in','employer','agency','designation',
            'employee_id','emergency_contact_name','emergency_contact_no','room_id',
            'bed_number','monthly_rent','shift','status','photo'
        ],
        'payments' => [
            'tenant_id','amount','for_month','payment_date','method','reference_no','notes'
        ],
        'visitors' => [
            'tenant_id','visitor_name','visitor_id_no','purpose','time_in','time_out'
        ],
        'maintenance' => [
            'room_id','description','date_reported','status','assigned_to','date_resolved'
        ],
        'schedules' => [
            'tenant_id','event_type','title','start_date','end_date','notes',
            'imported_shift','imported_batch'
        ],
        'dormitories' => ['name','address'],
        'rooms' => ['dormitory_id','room_number','capacity'],
        'employers' => ['name'],
        'agencies' => ['name'],
        'settings' => ['company_name','logo'],
        default => [],
    };
}

function resource_table(string $resource): string {
    $tables = [
        'tenants'     => 'tenants',
        'payments'    => 'payments',
        'visitors'    => 'visitors',
        'maintenance' => 'maintenance',
        'schedules'   => 'schedules',
        'dormitories' => 'dormitories',
        'rooms'       => 'rooms',
        'employers'   => 'employers',
        'agencies'    => 'agencies',
        'settings'    => 'settings',
    ];

    if (!isset($tables[$resource])) {
        error_response('Unknown resource.', 404);
    }

    return $tables[$resource];
}

function sanitize_payload(string $resource, array $payload): array {
    $fields = allowed_fields($resource);
    $clean = [];

    foreach ($fields as $field) {
        if (array_key_exists($field, $payload)) {
            $clean[$field] = $payload[$field];
        }
    }

    return $clean;
}

function validate_contact_numbers(array &$data): void {
    foreach (['contact_no' => 'Contact number', 'emergency_contact_no' => 'Emergency contact number'] as $field => $label) {
        if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
            continue;
        }
        if (!preg_match('/^[0-9]{1,11}$/', (string) $data[$field])) {
            error_response($label . ' must contain only digits and be no more than 11 digits.', 422);
        }
    }
}

function make_id(string $prefix): string {
    return $prefix . '_' . bin2hex(random_bytes(4));
}

function resource_prefix(string $resource): string {
    return [
        'tenants' => 'tn',
        'payments' => 'pay',
        'visitors' => 'vs',
        'maintenance' => 'mt',
        'schedules' => 'sc',
        'dormitories' => 'dm',
        'rooms' => 'rm',
        'employers' => 'em',
        'agencies' => 'ag',
        'settings' => 'set',
    ][$resource] ?? 'id';
}

function fetch_one(string $resource, string $recordId): ?array {
    $table = resource_table($resource);
    $stmt = db()->prepare("SELECT * FROM `$table` WHERE id = ? LIMIT 1");
    $stmt->execute([$recordId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function create_record(string $resource, array $payload): array {
    $data = sanitize_payload($resource, $payload);
    if ($resource === 'tenants') {
        validate_contact_numbers($data);
    }

    if ($resource === 'settings') {
        return upsert_settings($data);
    }

    $id = (string)($payload['id'] ?? make_id(resource_prefix($resource)));

    if ($resource === 'tenants') {
        $data['status'] = $data['status'] ?? 'active';
        $data['monthly_rent'] = $data['monthly_rent'] ?? 0;
    }

    if ($resource === 'maintenance') {
        $data['status'] = $data['status'] ?? 'pending';
        $data['date_reported'] = $data['date_reported'] ?? date('Y-m-d');
    }

    if ($resource === 'schedules') {
        $data['title'] = $data['title'] ?? '';
        $data['end_date'] = $data['end_date'] ?? ($data['start_date'] ?? null);
    }

    $data['id'] = $id;

    $columns = array_keys($data);
    $quotedColumns = implode(',', array_map(fn($c) => "`$c`", $columns));
    $placeholders = implode(',', array_fill(0, count($columns), '?'));

    $stmt = db()->prepare(
        "INSERT INTO `" . resource_table($resource) . "` ($quotedColumns)
         VALUES ($placeholders)"
    );
    $stmt->execute(array_values($data));

    return fetch_one($resource, $id) ?? $data;
}

function update_record(string $resource, string $recordId, array $payload): array {
    $existing = fetch_one($resource, $recordId);

    if (!$existing) {
        error_response('Record not found.', 404);
    }

    $data = sanitize_payload($resource, $payload);
    if ($resource === 'tenants') {
        validate_contact_numbers($data);
    }

    if (!$data) {
        return $existing;
    }

    $sets = [];
    $values = [];

    foreach ($data as $column => $value) {
        $sets[] = "`$column` = ?";
        $values[] = $value;
    }

    $values[] = $recordId;

    $stmt = db()->prepare(
        "UPDATE `" . resource_table($resource) . "`
         SET " . implode(', ', $sets) . "
         WHERE id = ?"
    );
    $stmt->execute($values);

    return fetch_one($resource, $recordId) ?? $existing;
}

function delete_record(string $resource, string $recordId): void {
    $stmt = db()->prepare("DELETE FROM `" . resource_table($resource) . "` WHERE id = ?");
    $stmt->execute([$recordId]);

    if ($stmt->rowCount() === 0) {
        error_response('Record not found.', 404);
    }
}

function list_records(string $resource): array {
    $table = resource_table($resource);
    $pdo = db();

    $order = match ($resource) {
        'payments' => 'payment_date DESC, id DESC',
        'visitors' => 'time_in DESC, id DESC',
        'maintenance' => 'date_reported DESC, id DESC',
        'schedules' => 'start_date ASC, id ASC',
        'tenants' => 'full_name ASC',
        'rooms' => 'room_number ASC',
        'dormitories' => 'name ASC',
        'employers', 'agencies' => 'name ASC',
        default => 'id ASC',
    };

    $stmt = $pdo->query("SELECT * FROM `$table` ORDER BY $order");
    return $stmt->fetchAll();
}

function upsert_settings(array $data): array {
    $pdo = db();

    $existing = $pdo->query("SELECT * FROM `settings` ORDER BY id LIMIT 1")->fetch();

    if ($existing) {
        $sets = [];
        $values = [];
        foreach ($data as $column => $value) {
            $sets[] = "`$column` = ?";
            $values[] = $value;
        }

        if ($sets) {
            $values[] = $existing['id'];
            $stmt = $pdo->prepare("UPDATE `settings` SET " . implode(',', $sets) . " WHERE id = ?");
            $stmt->execute($values);
        }

        return fetch_one('settings', $existing['id']) ?? $existing;
    }

    return create_record('settings', $data);
}

function login(): never {
    $data = request_json();

    $username = trim((string)($data['username'] ?? ''));
    $password = (string)($data['password'] ?? '');

    if ($username === '' || $password === '') {
        error_response('Username and password are required.', 422);
    }

    $stmt = db()->prepare(
        "SELECT id, username, password_hash, full_name, role, status
         FROM staff WHERE username = ? LIMIT 1"
    );
    $stmt->execute([$username]);
    $staff = $stmt->fetch();

    if (!$staff || $staff['status'] !== 'active' || !password_verify($password, $staff['password_hash'])) {
        error_response('Invalid username or password.', 401);
    }

    session_regenerate_id(true);
    $_SESSION['staff_id'] = $staff['id'];
    $_SESSION['username'] = $staff['username'];
    $_SESSION['full_name'] = $staff['full_name'];
    $_SESSION['role'] = $staff['role'];

    unset($staff['password_hash']);

    json_response(['success' => true, 'staff' => $staff]);
}

function logout(): never {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(), '',
            time() - 42000,
            $params['path'], $params['domain'] ?? '',
            (bool)$params['secure'], (bool)$params['httponly']
        );
    }

    session_destroy();
    json_response(['success' => true]);
}

function me(): never {
    if (empty($_SESSION['staff_id'])) {
        json_response(['authenticated' => false]);
    }

    json_response([
        'authenticated' => true,
        'staff' => [
            'id' => $_SESSION['staff_id'],
            'username' => $_SESSION['username'] ?? '',
            'full_name' => $_SESSION['full_name'] ?? '',
            'role' => $_SESSION['role'] ?? 'staff',
        ],
    ]);
}

function dashboard(): never {
    require_login();
    $pdo = db();
    $month = date('Y-m');

    $activeTenants = (int)$pdo->query(
        "SELECT COUNT(*) FROM tenants WHERE status = 'active'"
    )->fetchColumn();

    $capacity = (int)$pdo->query("SELECT COALESCE(SUM(capacity),0) FROM rooms")->fetchColumn();

    $occupancy = $capacity > 0 ? round(($activeTenants / $capacity) * 100, 1) : 0;

    $unpaidStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM tenants t
         WHERE t.status='active'
           AND COALESCE(t.monthly_rent,0) > 0
           AND NOT EXISTS (
             SELECT 1 FROM payments p
             WHERE p.tenant_id=t.id AND p.for_month=?
           )"
    );
    $unpaidStmt->execute([$month]);
    $unpaid = (int)$unpaidStmt->fetchColumn();

    $openMaintenance = (int)$pdo->query(
        "SELECT COUNT(*) FROM maintenance WHERE status <> 'resolved'"
    )->fetchColumn();

    $recent = $pdo->query(
        "SELECT p.*, t.full_name AS tenant_name
         FROM payments p
         LEFT JOIN tenants t ON t.id=p.tenant_id
         ORDER BY p.payment_date DESC, p.id DESC LIMIT 10"
    )->fetchAll();

    $upcoming = $pdo->prepare(
        "SELECT s.*, t.full_name AS tenant_name
         FROM schedules s
         LEFT JOIN tenants t ON t.id=s.tenant_id
         WHERE s.event_type='vacation_ph'
           AND s.end_date >= ?
         ORDER BY s.start_date ASC LIMIT 10"
    );
    $upcoming->execute([date('Y-m-d')]);

    json_response([
        'success' => true,
        'stats' => [
            'active_tenants' => $activeTenants,
            'occupancy_rate' => $occupancy,
            'unpaid_this_month' => $unpaid,
            'open_maintenance' => $openMaintenance,
        ],
        'recent_payments' => $recent,
        'upcoming_vacation' => $upcoming->fetchAll(),
    ]);
}

function comments_add(string $scheduleId): never {
    $session = require_login();
    $data = request_json();
    $text = trim((string)($data['text'] ?? ''));

    if ($text === '') {
        error_response('Comment cannot be empty.', 422);
    }

    if (!fetch_one('schedules', $scheduleId)) {
        error_response('Schedule not found.', 404);
    }

    $comment = [
        'id' => make_id('cm'),
        'schedule_id' => $scheduleId,
        'author_id' => $session['id'],
        'author' => $session['name'],
        'role' => $session['role'],
        'text' => $text,
        'created_at' => now_iso(),
    ];

    $stmt = db()->prepare(
        "INSERT INTO schedule_comments
        (id, schedule_id, author_id, author, role, text, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute(array_values($comment));

    json_response(['success' => true, 'comment' => $comment], 201);
}

function comments_list(string $scheduleId): never {
    require_login();

    $stmt = db()->prepare(
        "SELECT * FROM schedule_comments
         WHERE schedule_id=?
         ORDER BY created_at ASC, id ASC"
    );
    $stmt->execute([$scheduleId]);

    json_response(['success' => true, 'comments' => $stmt->fetchAll()]);
}

function import_shift(): never {
    require_admin();

    if (empty($_FILES['file'])) {
        error_response('CSV file is required.', 422);
    }

    $shift = trim((string)($_POST['shift'] ?? ''));
    $replace = filter_var($_POST['replace'] ?? false, FILTER_VALIDATE_BOOLEAN);

    if ($shift === '') {
        error_response('Shift code is required.', 422);
    }

    $handle = fopen($_FILES['file']['tmp_name'], 'rb');
    if (!$handle) {
        error_response('Could not read the uploaded CSV.', 400);
    }

    $dates = [];

    while (($row = fgetcsv($handle)) !== false) {
        $value = trim((string)($row[0] ?? ''));
        if ($value === '' || strtolower($value) === 'date') {
            continue;
        }

        $date = DateTime::createFromFormat('Y-m-d', $value)
             ?: DateTime::createFromFormat('m/d/Y', $value);

        if ($date) {
            $dates[] = $date->format('Y-m-d');
        }
    }

    fclose($handle);

    $dates = array_values(array_unique($dates));
    sort($dates);

    if (!$dates) {
        error_response('No valid dates found in the CSV.', 422);
    }

    $ranges = [];
    $start = $dates[0];
    $previous = $dates[0];

    foreach (array_slice($dates, 1) as $date) {
        $next = (new DateTime($previous))->modify('+1 day')->format('Y-m-d');

        if ($date !== $next) {
            $ranges[] = [$start, $previous];
            $start = $date;
        }

        $previous = $date;
    }

    $ranges[] = [$start, $previous];

    $pdo = db();
    $tenantStmt = $pdo->prepare(
        "SELECT id FROM tenants WHERE status='active' AND shift=?"
    );
    $tenantStmt->execute([$shift]);
    $tenants = $tenantStmt->fetchAll();

    if (!$tenants) {
        error_response('No active tenants are assigned to that shift.', 422);
    }

    $batch = make_id('imp');
    $added = 0;
    $removed = 0;

    $pdo->beginTransaction();

    try {
        foreach ($tenants as $tenant) {
            if ($replace) {
                $delete = $pdo->prepare(
                    "DELETE FROM schedules
                     WHERE tenant_id=?
                       AND imported_shift=?
                       AND start_date>=?
                       AND end_date<=?"
                );
                $delete->execute([
                    $tenant['id'], $shift,
                    $ranges[0][0], $ranges[count($ranges)-1][1]
                ]);
                $removed += $delete->rowCount();
            }

            foreach ($ranges as [$startDate, $endDate]) {
                $stmt = $pdo->prepare(
                    "INSERT INTO schedules
                    (id, tenant_id, event_type, title, start_date, end_date,
                     notes, imported_shift, imported_batch)
                    VALUES (?, ?, 'work_shift', ?, ?, ?, ?, ?, ?)"
                );

                $title = 'Imported ' . $shift . ' schedule';
                $stmt->execute([
                    make_id('sc'), $tenant['id'], $title,
                    $startDate, $endDate,
                    $title, $shift, $batch
                ]);

                $added++;
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_response('Shift import failed.', 500, $e);
    }

    json_response([
        'success' => true,
        'added' => $added,
        'removed' => $removed,
        'tenants' => count($tenants),
        'batch_id' => $batch,
    ]);
}

function staff_create(): never {
    require_admin();

    $data = request_json();
    $fullName = trim((string)($data['full_name'] ?? ''));
    $username = trim((string)($data['username'] ?? ''));
    $password = (string)($data['password'] ?? '');
    $role = in_array(($data['role'] ?? 'staff'), ['staff','admin'], true)
        ? $data['role'] : 'staff';

    if ($fullName === '' || $username === '' || strlen($password) < 6) {
        error_response('Full name, username and a password of at least 6 characters are required.', 422);
    }

    $pdo = db();

    $check = $pdo->prepare("SELECT id FROM staff WHERE username=? LIMIT 1");
    $check->execute([$username]);

    if ($check->fetch()) {
        error_response('That username is already taken.', 409);
    }

    $id = make_id('st');

    $stmt = $pdo->prepare(
        "INSERT INTO staff
        (id, username, password_hash, full_name, role, status)
        VALUES (?, ?, ?, ?, ?, 'active')"
    );

    $stmt->execute([
        $id,
        $username,
        password_hash($password, PASSWORD_DEFAULT),
        $fullName,
        $role,
    ]);

    json_response([
        'success' => true,
        'staff' => [
            'id' => $id,
            'username' => $username,
            'full_name' => $fullName,
            'role' => $role,
            'status' => 'active',
        ],
    ], 201);
}

function staff_toggle(string $staffId): never {
    require_admin();

    $session = require_login();
    if ($session['id'] === $staffId) {
        error_response('You cannot disable your own account.', 422);
    }

    $stmt = db()->prepare("SELECT status FROM staff WHERE id=? LIMIT 1");
    $stmt->execute([$staffId]);
    $staff = $stmt->fetch();

    if (!$staff) {
        error_response('Staff account not found.', 404);
    }

    $newStatus = $staff['status'] === 'active' ? 'disabled' : 'active';

    $update = db()->prepare("UPDATE staff SET status=? WHERE id=?");
    $update->execute([$newStatus, $staffId]);

    json_response(['success' => true, 'status' => $newStatus]);
}

function route(): never {
    $parts = path_parts();
    $resource = $parts[0] ?? '';
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    try {
        if ($resource === 'auth') {
            $action = $parts[1] ?? '';

            if ($action === 'login' && $method === 'POST') login();
            if ($action === 'logout' && $method === 'POST') { require_login(); logout(); }
            if ($action === 'me' && $method === 'GET') me();

            error_response('Authentication route not found.', 404);
        }

        if ($resource === 'health' && $method === 'GET') {
            db()->query('SELECT 1');
            json_response([
                'success' => true,
                'api' => 'OFW Dormitory System',
                'database' => 'connected',
                'php' => PHP_VERSION,
            ]);
        }

        if ($resource === 'dashboard' && $method === 'GET') dashboard();

        if ($resource === 'shift-import' && $method === 'POST') import_shift();

        if ($resource === 'staff') {
            if (($parts[1] ?? '') === 'create' && $method === 'POST') staff_create();
            if (($parts[1] ?? '') === 'toggle' && $method === 'POST') staff_toggle(id());
        }

        if ($resource === 'schedules' && ($parts[2] ?? '') === 'comments') {
            $scheduleId = $parts[1] ?? '';
            if ($method === 'GET') comments_list($scheduleId);
            if ($method === 'POST') comments_add($scheduleId);
            error_response('Comment route not found.', 404);
        }

        // Generic CRUD resources.
        $generic = [
            'tenants','payments','visitors','maintenance','schedules',
            'dormitories','rooms','employers','agencies','settings'
        ];

        if (in_array($resource, $generic, true)) {
            require_login();

            $recordId = $parts[1] ?? null;

            if ($method === 'GET' && !$recordId) {
                json_response(['success' => true, 'data' => list_records($resource)]);
            }

            if ($method === 'GET' && $recordId) {
                $record = fetch_one($resource, $recordId);
                if (!$record) error_response('Record not found.', 404);
                json_response(['success' => true, 'data' => $record]);
            }

            if ($method === 'POST' && !$recordId) {
                json_response(['success' => true, 'data' => create_record($resource, request_json())], 201);
            }

            if (($method === 'PUT' || $method === 'PATCH') && $recordId) {
                json_response(['success' => true, 'data' => update_record($resource, $recordId, request_json())]);
            }

            if ($method === 'DELETE' && $recordId) {
                require_admin();
                delete_record($resource, $recordId);
                json_response(['success' => true]);
            }

            error_response('Unsupported method for this resource.', 405);
        }

        error_response('API route not found.', 404);

    } catch (PDOException $e) {
        error_response('Database operation failed. The database/schema may not exist yet.', 500, $e);
    } catch (Throwable $e) {
        error_response('Server error.', 500, $e);
    }
}

route();
