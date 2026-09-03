<?php
declare(strict_types=1);

require_once __DIR__ . '/app/includes/layout.php';

$user = require_permission('calendar');
$pdo = db();

const EVENT_TYPES = [
    'da' => 'DA — Day Shift A-PAN', 'db' => 'DB — Day Shift B-PAN',
    'na' => 'NA — Night Shift A-PAN', 'nb' => 'NB — Night Shift B-PAN',
    'work_shift' => 'Work Shift (legacy)', 'day_off' => 'Day Off',
    'vacation_leave' => 'VL — Vacation Leave', 'sick_leave' => 'SL — Sick Leave',
    'leave' => 'Leave (legacy)', 'flight' => 'Flight', 'appointment' => 'Appointment', 'emergency' => 'Emergency', 'other' => 'Other',
];
const SHIFT_TYPES = ['DA' => 'DA — Day Shift A-PAN', 'DB' => 'DB — Day Shift B-PAN', 'NA' => 'NA — Night Shift A-PAN', 'NB' => 'NB — Night Shift B-PAN'];
const STAFF_EVENT_TYPES = ['work_schedule' => 'Work Schedule', 'day_off' => 'Day Off', 'leave' => 'Leave', 'meeting' => 'Meeting', 'training' => 'Training', 'other' => 'Other'];

function calendar_id(mixed $value): int { $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]); return $id === false ? 0 : (int) $id; }
function calendar_date(mixed $value): ?string { $raw = (string) $value; $date = DateTime::createFromFormat('Y-m-d', $raw); return $date && $date->format('Y-m-d') === $raw ? $raw : null; }
function csv_schedule_date(mixed $value): ?string { $raw = trim((string) $value); foreach (['Y-m-d', 'n/j/Y', 'm/d/Y'] as $format) { $date = DateTime::createFromFormat($format, $raw); if ($date && $date->format($format) === $raw) { return $date->format('Y-m-d'); } } return null; }
function active_tenant_exists(PDO $pdo, int $id): bool { $statement = $pdo->prepare("SELECT 1 FROM tenants WHERE id=:id AND status='active'"); $statement->execute(['id' => $id]); return (bool) $statement->fetchColumn(); }
function active_staff_exists(PDO $pdo, int $id): bool { $statement = $pdo->prepare("SELECT 1 FROM users WHERE id=:id AND status='active'"); $statement->execute(['id' => $id]); return (bool) $statement->fetchColumn(); }
function schedule_event(PDO $pdo, int $id): ?array { $statement = $pdo->prepare('SELECT s.*,t.full_name FROM schedules s INNER JOIN tenants t ON t.id=s.tenant_id WHERE s.id=:id'); $statement->execute(['id' => $id]); return $statement->fetch() ?: null; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save_event') {
            $staffId = calendar_id($_POST['staff_id'] ?? null);
            $type = (string) ($_POST['event_type'] ?? '');
            $start = calendar_date($_POST['start_date'] ?? null);
            $end = calendar_date($_POST['end_date'] ?? null);
            $notes = trim((string) ($_POST['notes'] ?? ''));
            if (!$staffId || !active_staff_exists($pdo, $staffId) || !isset(STAFF_EVENT_TYPES[$type]) || !$start || mb_strlen($notes) > 5000) { throw new RuntimeException('Choose an active staff member, event type, and start date.'); }
            if ($end && $end < $start) { throw new RuntimeException('End date cannot be before start date.'); }
            $pdo->prepare('INSERT INTO staff_events (user_id,event_type,start_date,end_date,notes,created_by) VALUES (:user_id,:event_type,:start_date,:end_date,:notes,:created_by)')->execute(['user_id'=>$staffId,'event_type'=>$type,'start_date'=>$start,'end_date'=>$end,'notes'=>$notes ?: null,'created_by'=>$user['id']]);
            set_flash('Staff event added.');
        } elseif ($action === 'add_comment') {
            $eventId = calendar_id($_POST['event_id'] ?? null);
            $comment = trim((string) ($_POST['comment_text'] ?? ''));
            if (!$eventId || $comment === '' || mb_strlen($comment) > 5000 || !schedule_event($pdo, $eventId)) { throw new RuntimeException('Enter a comment for an existing event.'); }
            $pdo->prepare('INSERT INTO schedule_comments (schedule_id,user_id,comment_text) VALUES (:schedule_id,:user_id,:comment_text)')->execute(['schedule_id'=>$eventId,'user_id'=>$user['id'],'comment_text'=>$comment]);
            set_flash('Comment posted.');
            redirect('calendar.php?action=comments&id=' . $eventId);
        } elseif ($action === 'import_csv') {
            if ($user['role'] !== 'admin') { throw new RuntimeException('Only administrators can import shift schedules.'); }
            $shift = (string) ($_POST['event_type'] ?? '');
            $file = $_FILES['csv_file'] ?? [];
            if (!isset(SHIFT_TYPES[$shift]) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > 512 * 1024) { throw new RuntimeException('Choose a shift type and CSV file up to 512 KB.'); }
            if (!is_uploaded_file((string) ($file['tmp_name'] ?? ''))) { throw new RuntimeException('The uploaded CSV file is invalid.'); }
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
            if (!in_array($mime, ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel', 'application/octet-stream'], true)) { throw new RuntimeException('The uploaded file is not a valid CSV document.'); }
            $handle = fopen($file['tmp_name'], 'rb');
            if (!$handle) { throw new RuntimeException('CSV file could not be read.'); }
            $header = fgetcsv($handle);
            if (!$header) { throw new RuntimeException('CSV file is empty.'); }
            $header = array_map(fn($value) => strtolower(trim(str_replace("\xEF\xBB\xBF", '', (string) $value))), $header);
            $dateColumn = array_search('date', $header, true);
            $workDayColumn = false;
            foreach (['work', 'work(yes or no)', 'work (yes or no)'] as $acceptedWorkHeader) {
                $column = array_search($acceptedWorkHeader, $header, true);
                if ($column !== false) { $workDayColumn = $column; break; }
            }
            if ($dateColumn === false || $workDayColumn === false) { throw new RuntimeException('CSV must have header columns named DATE and WORK(YES or NO).'); }
            $saved = 0; $skipped = 0; $workDays = 0; $daysOff = 0; $seen = [];
            $pdo->beginTransaction();
            try {
                $upsert = $pdo->prepare('INSERT INTO shift_schedule_entries (shift_code,schedule_date,is_work_day,source_value,created_by) VALUES (:shift_code,:schedule_date,:is_work_day,:source_value,:created_by) ON DUPLICATE KEY UPDATE is_work_day=VALUES(is_work_day),source_value=VALUES(source_value),created_by=VALUES(created_by)');
                while (($row = fgetcsv($handle)) !== false) {
                    $date = csv_schedule_date($row[$dateColumn] ?? null);
                    $workValue = strtoupper(trim((string) ($row[$workDayColumn] ?? '')));
                    $workValues = ['WORK', 'YES', 'Y', 'TRUE', '1'];
                    $dayOffValues = ['DAY OFF', 'NO', 'N', 'FALSE', '0', 'BUPAN'];
                    if (!$date || (!in_array($workValue, $workValues, true) && !in_array($workValue, $dayOffValues, true)) || isset($seen[$date])) { $skipped++; continue; }
                    $seen[$date] = true;
                    $isWorkDay = in_array($workValue, $workValues, true);
                    $upsert->execute(['shift_code'=>$shift,'schedule_date'=>$date,'is_work_day'=>$isWorkDay ? 1 : 0,'source_value'=>$workValue,'created_by'=>$user['id']]);
                    $saved++; $isWorkDay ? $workDays++ : $daysOff++;
                }
                if ($saved === 0) { throw new RuntimeException('No valid schedule rows were found in the CSV file.'); }
                $pdo->commit();
            } catch (Throwable $exception) { $pdo->rollBack(); throw $exception; } finally { fclose($handle); }
            set_flash("CSV import complete: $saved shift dates saved; $workDays work dates and $daysOff non-working dates processed. Tenant calendars update automatically from each tenant's current shift.");
        } else { throw new RuntimeException('Unknown request.'); }
    } catch (RuntimeException $exception) { set_flash($exception->getMessage(), 'error'); }
      catch (Throwable $exception) { set_flash('The schedule change could not be completed. Please try again.', 'error'); }
    redirect('calendar.php');
}

$action = (string) ($_GET['action'] ?? 'calendar');
$eventId = calendar_id($_GET['id'] ?? null);
$flash = consume_flash();
$staffMembers = $pdo->query("SELECT id,full_name,role,job_role FROM users WHERE status='active' ORDER BY full_name")->fetchAll();

if ($action === 'add') {
    page_start('Add Schedule Event', $user, 'calendar');
    ?>
    <p class="eyebrow">Staff Schedule</p><h1>Add staff event</h1>
    <form class="panel payment-form" method="post"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="save_event"><label>Administrator or staff member *<select name="staff_id" required><option value="">— Select active staff member —</option><?php foreach ($staffMembers as $member): ?><option value="<?= (int) $member['id'] ?>"><?= e($member['full_name']) ?> — <?= e($member['job_role'] ?: ucfirst($member['role'])) ?></option><?php endforeach; ?></select></label><div class="form-grid"><label>Event type *<select name="event_type"><?php foreach (STAFF_EVENT_TYPES as $key => $label): ?><option value="<?= e($key) ?>"><?= e($label) ?></option><?php endforeach; ?></select></label><label>Start date *<input type="date" name="start_date" value="<?= date('Y-m-d') ?>" required></label><label>End date <small>(optional)</small><input type="date" name="end_date"></label></div><label>Notes<textarea name="notes" maxlength="5000" rows="4"></textarea></label><button class="primary" type="submit">Add staff event</button> <a class="cancel" href="calendar.php">Cancel</a></form>
    <?php page_end(); exit;
}

if ($action === 'comments') {
    $event = schedule_event($pdo, $eventId); if (!$event) { set_flash('Event not found.', 'error'); redirect('calendar.php'); }
    $statement = $pdo->prepare('SELECT c.*,u.full_name FROM schedule_comments c LEFT JOIN users u ON u.id=c.user_id WHERE c.schedule_id=:id ORDER BY c.created_at'); $statement->execute(['id'=>$eventId]); $comments = $statement->fetchAll();
    page_start('Event Comments', $user, 'calendar');
    ?>
    <p class="eyebrow">Schedule event</p><h1><?= e(EVENT_TYPES[$event['event_type']] ?? $event['event_type']) ?> — <?= e($event['full_name']) ?></h1><section class="panel"><p><strong>Dates:</strong> <?= e($event['start_date']) ?><?= $event['end_date'] ? ' to ' . e($event['end_date']) : '' ?></p><p><strong>Notes:</strong> <?= e($event['notes']) ?></p></section><section class="panel"><h2>Comments</h2><?php foreach ($comments as $comment): ?><article class="comment"><strong><?= e($comment['full_name'] ?? 'Former staff member') ?></strong><small><?= e($comment['created_at']) ?></small><p><?= nl2br(e($comment['comment_text'])) ?></p></article><?php endforeach; ?><form method="post"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="add_comment"><input type="hidden" name="event_id" value="<?= $eventId ?>"><label>Add comment<textarea name="comment_text" rows="3" maxlength="5000" required></textarea></label><button class="primary" type="submit">Post comment</button> <a class="cancel" href="calendar.php">Back to calendar</a></form></section>
    <?php page_end(); exit;
}

$month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) ($_GET['month'] ?? '')) ? (string) $_GET['month'] : date('Y-m');
$selectedShift = (string) ($_GET['shift'] ?? 'DA');
if ($selectedShift !== 'ADMIN' && !isset(SHIFT_TYPES[$selectedShift])) { $selectedShift = 'DA'; }
$first = new DateTime($month . '-01'); $last = (clone $first)->modify('last day of this month');
$events = [];
if ($selectedShift === 'ADMIN') {
    $statement = $pdo->prepare('SELECT se.event_type,se.start_date,se.end_date,se.notes,u.full_name FROM staff_events se INNER JOIN users u ON u.id=se.user_id WHERE se.start_date<=:last AND (se.end_date IS NULL OR se.end_date>=:first) ORDER BY se.start_date,u.full_name');
    $statement->execute(['first'=>$first->format('Y-m-d'),'last'=>$last->format('Y-m-d')]);
    foreach ($statement->fetchAll() as $staffEvent) { $events[] = ['id'=>null,'event_type'=>'staff_' . $staffEvent['event_type'],'start_date'=>$staffEvent['start_date'],'end_date'=>$staffEvent['end_date'],'full_name'=>'Staff: ' . $staffEvent['full_name'],'notes'=>STAFF_EVENT_TYPES[$staffEvent['event_type']] . ($staffEvent['notes'] ? ' — ' . $staffEvent['notes'] : ''),'is_staff_event'=>true]; }
} else {
    $statement = $pdo->prepare('SELECT shift_code,schedule_date,is_work_day,source_value FROM shift_schedule_entries WHERE shift_code=:shift_code AND schedule_date BETWEEN :first AND :last ORDER BY schedule_date');
    $statement->execute(['shift_code'=>$selectedShift,'first'=>$first->format('Y-m-d'),'last'=>$last->format('Y-m-d')]);
    foreach ($statement->fetchAll() as $entry) { $events[] = ['id'=>null,'event_type'=>$entry['is_work_day'] ? strtolower($entry['shift_code']) : 'day_off','start_date'=>$entry['schedule_date'],'end_date'=>null,'full_name'=>$entry['source_value'],'notes'=>'','is_shift_entry'=>true]; }
    $statement = $pdo->prepare("SELECT s.id,s.event_type,s.start_date,s.end_date,s.notes,t.full_name FROM schedules s INNER JOIN tenants t ON t.id=s.tenant_id WHERE t.status='active' AND t.shift_code=:shift_code AND s.start_date<=:last AND (s.end_date IS NULL OR s.end_date>=:first) ORDER BY s.start_date,t.full_name");
    $statement->execute(['shift_code'=>$selectedShift,'first'=>$first->format('Y-m-d'),'last'=>$last->format('Y-m-d')]);
    foreach ($statement->fetchAll() as $tenantEvent) { $tenantEvent['is_tenant_event'] = true; $events[] = $tenantEvent; }
}
$byDay = [];
foreach ($events as $event) { $cursor = new DateTime(max($event['start_date'], $first->format('Y-m-d'))); $end = new DateTime(min($event['end_date'] ?: $event['start_date'], $last->format('Y-m-d'))); while ($cursor <= $end) { $byDay[$cursor->format('j')][] = $event; $cursor->modify('+1 day'); } }
$previous = (clone $first)->modify('-1 month')->format('Y-m'); $next = (clone $first)->modify('+1 month')->format('Y-m');
page_start('Calendar', $user, 'calendar');
?>
<section class="calendar-hero-ui"><div><p class="eyebrow">Schedules</p><h1>Calendar</h1><p>Coordinate staff events and imported shift schedules across the month.</p></div><div class="calendar-hero-actions"><div><span>Viewing month</span><strong><?= e($first->format('F Y')) ?></strong></div><a class="button-link" href="calendar.php?action=add"><span aria-hidden="true">+</span> Add event</a></div></section>
<section class="calendar-summary-grid" aria-label="Calendar summary"><article><span class="calendar-summary-icon"><svg viewBox="0 0 24 24"><path d="M3 9h18M8 2v4M16 2v4M5 4h14a2 2 0 0 1 2 2v14H3V6a2 2 0 0 1 2-2Z"/></svg></span><div><span>Schedule entries</span><strong><?= count($events) ?></strong></div></article><article><span class="calendar-summary-icon days"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/></svg></span><div><span>Scheduled days</span><strong><?= count($byDay) ?></strong></div></article><article><span class="calendar-summary-icon team"><svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg></span><div><span>Active staff</span><strong><?= count($staffMembers) ?></strong></div></article></section>
<?php if ($flash): ?><p class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></p><?php endif; ?>
<?php if ($user['role'] === 'admin'): ?><section class="panel shift-import calendar-import-ui"><div class="calendar-section-heading"><span><svg viewBox="0 0 24 24"><path d="M12 3v12M7 10l5 5 5-5M4 21h16"/></svg></span><div><p class="eyebrow">Schedule tools</p><h2>Import shift schedule</h2><p>Upload a CSV to create or update a shift calendar.</p></div></div><div class="calendar-import-layout"><pre class="csv-sample">DATE,WORK
9/1/2026,WORK
9/2/2026,DAY OFF</pre><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="import_csv"><div class="form-grid"><label>Shift to import<select name="event_type"><?php foreach (SHIFT_TYPES as $key => $label): ?><option value="<?= e($key) ?>"><?= e($label) ?></option><?php endforeach; ?></select></label><label>CSV file<input type="file" name="csv_file" accept=".csv,text/csv" required></label></div><button class="primary" type="submit">Import schedule <span aria-hidden="true">→</span></button></form></div></section><?php endif; ?>
<section class="panel calendar-toolbar-ui"><form method="get"><input type="hidden" name="month" value="<?= e($month) ?>"><label>Calendar view<select name="shift" onchange="this.form.submit()"><option value="ADMIN" <?= $selectedShift === 'ADMIN' ? 'selected' : '' ?>>Admin Calendar</option><?php foreach (SHIFT_TYPES as $key => $label): ?><option value="<?= e($key) ?>" <?= $selectedShift === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label></form><div class="calendar-month-navigation"><div><p class="eyebrow">Selected month</p><h2><?= e($first->format('F Y')) ?></h2></div><p class="calendar-nav"><a href="calendar.php?month=<?= $previous ?>&amp;shift=<?= e($selectedShift) ?>">← Previous</a><a class="today" href="calendar.php?month=<?= date('Y-m') ?>&amp;shift=<?= e($selectedShift) ?>">Today</a><a href="calendar.php?month=<?= $next ?>&amp;shift=<?= e($selectedShift) ?>">Next →</a></p></div></section>
<section class="calendar-grid panel calendar-grid-ui"><?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $day): ?><div class="calendar-heading"><?= $day ?></div><?php endforeach; ?><?php for ($blank=0; $blank<(int) $first->format('w'); $blank++): ?><div class="calendar-day blank"></div><?php endfor; ?><?php for ($day=1; $day<=(int) $last->format('j'); $day++): ?><div class="calendar-day <?= $month === date('Y-m') && $day === (int) date('j') ? 'today' : '' ?>"><strong><?= $day ?></strong><?php foreach ($byDay[$day] ?? [] as $event): $eventTooltip = $event['full_name'] . ($event['notes'] ? ' — ' . $event['notes'] : ''); ?><?php if (!empty($event['is_shift_entry']) || !empty($event['is_staff_event'])): ?><span class="event <?= e($event['event_type']) ?>" title="<?= e($eventTooltip) ?>"><?= e($event['full_name']) ?><small><?= e($event['notes']) ?></small></span><?php else: ?><a class="event <?= e($event['event_type']) ?>" title="<?= e($eventTooltip) ?>" href="calendar.php?action=comments&amp;id=<?= (int) $event['id'] ?>"><?= e($event['full_name']) ?><small><?= e(EVENT_TYPES[$event['event_type']] ?? $event['event_type']) ?></small></a><?php endif; ?><?php endforeach; ?></div><?php endfor; ?></section>
<?php page_end();
