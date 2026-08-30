<?php
declare(strict_types=1);

require_once __DIR__ . '/app/includes/layout.php';
require_once __DIR__ . '/app/includes/database_backup.php';

$user = require_role('admin');
$pdo = db();
$permissions = permission_catalog();
$jobRoles = ['Housing Officer', 'Finance Staff', 'Security Staff', 'Maintenance Staff', 'Supervisor', 'Other'];

function admin_staff_id(mixed $value): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $id === false ? 0 : (int) $id;
}

function selected_permissions(array $permissions): array
{
    $selected = $_POST['permissions'] ?? [];
    if (!is_array($selected)) {
        return [];
    }
    return array_values(array_intersect(array_keys($permissions), $selected));
}

function admin_format_bytes(int $bytes): string
{
    if ($bytes < 1024) { return $bytes . ' B'; }
    if ($bytes < 1024 * 1024) { return number_format($bytes / 1024, 1) . ' KB'; }
    if ($bytes < 1024 * 1024 * 1024) { return number_format($bytes / (1024 * 1024), 1) . ' MB'; }
    return number_format($bytes / (1024 * 1024 * 1024), 1) . ' GB';
}

function upload_company_logo(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > 1024 * 1024) {
        throw new RuntimeException('Choose an image logo no larger than 1 MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Logo must be a JPG, PNG, or WebP image.');
    }
    $dimensions = @getimagesize($file['tmp_name']);
    if (!$dimensions || $dimensions[0] < 1 || $dimensions[1] < 1 || $dimensions[0] > 4096 || $dimensions[1] > 4096 || $dimensions[0] * $dimensions[1] > 12000000) {
        throw new RuntimeException('Logo dimensions are invalid or too large.');
    }
    $directory = __DIR__ . '/uploads/company';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Company logo folder could not be created.');
    }
    $path = $directory . '/logo-' . bin2hex(random_bytes(12)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($file['tmp_name'], $path)) {
        throw new RuntimeException('Company logo could not be saved.');
    }
    return 'uploads/company/' . basename($path);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'download_database_backup') {
        try {
            database_backup_download($pdo);
        } catch (Throwable $exception) {
            error_log('Database backup failed: ' . $exception->getMessage());
            set_flash('The database backup could not be created. Please try again.', 'error');
            redirect('admin_control.php');
        }
    }
    if (in_array($action, ['save_company_logo', 'remove_company_logo'], true)) {
        try {
            if ($action === 'save_company_logo') {
                $logoPath = upload_company_logo($_FILES['company_logo'] ?? []);
                $pdo->prepare('INSERT INTO settings (setting_key,setting_value) VALUES (\'company_logo_path\',:setting_value) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute(['setting_value' => $logoPath]);
                set_flash('Company logo updated.');
            } else {
                $pdo->prepare('INSERT INTO settings (setting_key,setting_value) VALUES (\'company_logo_path\',NULL) ON DUPLICATE KEY UPDATE setting_value=NULL')->execute();
                set_flash('Company logo removed.');
            }
        } catch (RuntimeException $exception) {
            set_flash($exception->getMessage(), 'error');
        } catch (PDOException $exception) {
            set_flash('The company logo setting could not be saved.', 'error');
        }
        redirect('admin_control.php');
    }
    $staffId = admin_staff_id($_POST['id'] ?? null);
    try {
        $fullName = normalize_upper((string) ($_POST['full_name'] ?? '')) ?? '';
        $username = normalize_lower((string) ($_POST['username'] ?? '')) ?? '';
        $jobRole = normalize_upper((string) ($_POST['job_role'] ?? '')) ?? '';
        $status = (string) ($_POST['status'] ?? 'active');
        $password = (string) ($_POST['password'] ?? '');
        $assigned = selected_permissions($permissions);
        if ($fullName === '' || mb_strlen($fullName) > 150 || !preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username) || mb_strlen($jobRole) > 100 || !in_array($status, ['active', 'disabled'], true)) {
            throw new RuntimeException('Enter a name, valid username, job role, and status.');
        }
        if ($action === 'add_staff' && strlen($password) < 10) {
            throw new RuntimeException('New staff passwords must be at least 10 characters.');
        }
        if ($action === 'update_staff' && $password !== '' && strlen($password) < 10) {
            throw new RuntimeException('A new password must be at least 10 characters.');
        }
        $pdo->beginTransaction();
        if ($action === 'add_staff') {
            $statement = $pdo->prepare("INSERT INTO users (full_name,username,password_hash,role,job_role,status) VALUES (:full_name,:username,:password_hash,'staff',:job_role,:status)");
            $statement->execute(['full_name' => $fullName, 'username' => $username, 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'job_role' => $jobRole !== '' ? $jobRole : null, 'status' => $status]);
            $staffId = (int) $pdo->lastInsertId();
            set_flash('Staff account created.');
        } elseif ($action === 'update_staff' && $staffId > 0) {
            $check = $pdo->prepare("SELECT id FROM users WHERE id=:id AND role='staff'");
            $check->execute(['id' => $staffId]);
            if (!$check->fetchColumn()) {
                throw new RuntimeException('Staff account not found.');
            }
            $sql = 'UPDATE users SET full_name=:full_name,username=:username,job_role=:job_role,status=:status';
            $data = ['full_name' => $fullName, 'username' => $username, 'job_role' => $jobRole !== '' ? $jobRole : null, 'status' => $status, 'id' => $staffId];
            if ($password !== '') { $sql .= ', password_hash=:password_hash'; $data['password_hash'] = password_hash($password, PASSWORD_DEFAULT); }
            $pdo->prepare($sql . ' WHERE id=:id')->execute($data);
            set_flash('Staff account updated.');
        } else {
            throw new RuntimeException('Unknown request.');
        }
        $pdo->prepare('DELETE FROM staff_permissions WHERE user_id=:user_id')->execute(['user_id' => $staffId]);
        $insert = $pdo->prepare('INSERT INTO staff_permissions (user_id,permission_key) VALUES (:user_id,:permission_key)');
        foreach ($assigned as $permission) { $insert->execute(['user_id' => $staffId, 'permission_key' => $permission]); }
        $pdo->commit();
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        set_flash($exception->getCode() === '23000' ? 'That username already exists.' : 'The staff account could not be saved.', 'error');
    } catch (RuntimeException $exception) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        set_flash($exception->getMessage(), 'error');
    }
    redirect('admin_control.php');
}

$edit = null;
$editId = admin_staff_id($_GET['edit'] ?? null);
if ($editId > 0) {
    $statement = $pdo->prepare("SELECT id,full_name,username,job_role,status FROM users WHERE id=:id AND role='staff'");
    $statement->execute(['id' => $editId]);
    $edit = $statement->fetch() ?: null;
}
$staff = $pdo->query("SELECT id,full_name,username,job_role,status FROM users WHERE role='staff' ORDER BY full_name")->fetchAll();
$permissionRows = $pdo->query('SELECT user_id,permission_key FROM staff_permissions')->fetchAll();
$staffPermissions = [];
foreach ($permissionRows as $row) { $staffPermissions[(int) $row['user_id']][] = $row['permission_key']; }
$editPermissions = $edit ? ($staffPermissions[(int) $edit['id']] ?? []) : [];
$companyLogo = app_setting('company_logo_path');
$databaseStats = $pdo->query("SELECT COUNT(*) AS table_count,COALESCE(SUM(DATA_LENGTH+INDEX_LENGTH),0) AS total_bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'")->fetch();
$flash = consume_flash();
page_start('Administrator Control', $user, 'admin_control');
?>
  <section class="admin-hero-ui">
    <div>
      <p class="eyebrow">Administration</p>
      <h1>Administrator Control</h1>
      <p>Manage your staff workspace, brand identity, and module-level access from one secure place.</p>
    </div>
    <div class="admin-hero-badge"><span>Access policy</span><strong>Permission based</strong></div>
  </section>
  <section class="admin-summary-grid" aria-label="Administration summary">
    <article><span class="admin-summary-icon"><svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg></span><div><span>Staff accounts</span><strong><?= count($staff) ?></strong></div></article>
    <article><span class="admin-summary-icon active"><svg viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></svg></span><div><span>Active staff</span><strong><?= count(array_filter($staff, fn(array $member): bool => $member['status'] === 'active')) ?></strong></div></article>
    <article><span class="admin-summary-icon modules"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg></span><div><span>Available modules</span><strong><?= count($permissions) ?></strong></div></article>
  </section>
  <?php if ($flash): ?><p class="flash <?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></p><?php endif; ?>
  <div class="admin-settings-grid">
  <section class="panel logo-control admin-logo-ui">
    <div class="admin-section-heading"><span><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8.5" cy="9" r="1.5"/><path d="m5 17 4-4 3 3 3-4 4 5"/></svg></span><div><p class="eyebrow">Brand settings</p><h2>Company logo</h2><p>Displayed prominently in the navigation sidebar.</p></div></div>
    <div class="logo-control-row"><?php if ($companyLogo): ?><div class="admin-logo-preview-wrap"><img class="logo-preview" src="<?= e($companyLogo) ?>" alt="Current company logo"><span>Current logo</span></div><?php endif; ?><form method="post" enctype="multipart/form-data" class="quick-add admin-logo-form"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="save_company_logo"><label>Upload image<input type="file" name="company_logo" accept="image/jpeg,image/png,image/webp" required></label><button type="submit"><?= $companyLogo ? 'Replace logo' : 'Add logo' ?></button></form><?php if ($companyLogo): ?><form method="post" class="admin-logo-remove"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="remove_company_logo"><button class="link-danger" type="submit" onclick="return confirm('Remove the company logo?');">Remove logo</button></form><?php endif; ?></div>
  </section>
  <section class="panel admin-backup-ui">
    <div class="admin-section-heading"><span class="backup-icon"><svg viewBox="0 0 24 24"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/></svg></span><div><p class="eyebrow">System protection</p><h2>Database backup</h2><p>Create a portable SQL copy of the complete database whenever you need it.</p></div></div>
    <div class="admin-backup-content">
      <div class="admin-backup-details"><div><span>Database tables</span><strong><?= (int) ($databaseStats['table_count'] ?? 0) ?></strong></div><div><span>Approximate size</span><strong><?= e(admin_format_bytes((int) ($databaseStats['total_bytes'] ?? 0))) ?></strong></div><div><span>Backup format</span><strong>SQL</strong></div></div>
      <form method="post" class="admin-backup-action"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="download_database_backup"><button class="primary" type="submit"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12m0 0-4-4m4 4 4-4M5 19h14"/></svg> Create &amp; download backup</button><small>Contains account records and tenant information. Store it securely.</small></form>
    </div>
  </section>
  </div>
  <section class="panel staff-form admin-staff-form-ui">
    <div class="admin-section-heading"><span><svg viewBox="0 0 24 24"><path d="M15 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M8 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M19 8v6M16 11h6"/></svg></span><div><p class="eyebrow"><?= $edit ? 'Account update' : 'Team access' ?></p><h2><?= $edit ? 'Edit staff account' : 'Add staff account' ?></h2><p><?= $edit ? 'Update this team member’s profile and access.' : 'Create a secure profile and choose the modules this person can use.' ?></p></div></div>
    <form method="post"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="<?= $edit ? 'update_staff' : 'add_staff' ?>"><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
      <div class="form-grid"><label>Full name *<input name="full_name" maxlength="150" value="<?= e($edit['full_name'] ?? '') ?>" required></label><label>Username *<input name="username" maxlength="50" value="<?= e($edit['username'] ?? '') ?>" required></label><label>Job role *<input name="job_role" list="job-role-options" maxlength="100" value="<?= e($edit['job_role'] ?? '') ?>" placeholder="e.g. Security Staff" required><datalist id="job-role-options"><?php foreach ($jobRoles as $jobRole): ?><option value="<?= e($jobRole) ?>"><?php endforeach; ?></datalist></label><label>Status<select name="status"><option value="active" <?= ($edit['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option><option value="disabled" <?= ($edit['status'] ?? '') === 'disabled' ? 'selected' : '' ?>>Disabled</option></select></label><label>Password <?= $edit ? '<small>(leave blank to keep current)</small>' : '*' ?><input type="password" name="password" autocomplete="new-password" <?= $edit ? '' : 'required' ?>></label></div>
      <fieldset class="permissions"><legend>Module access *</legend><p class="muted">Select the work areas this staff member is allowed to use.</p><div class="permission-grid"><?php foreach ($permissions as $key => [$label, $description]): ?><label class="permission-check"><input type="checkbox" name="permissions[]" value="<?= e($key) ?>" <?= in_array($key, $editPermissions, true) ? 'checked' : '' ?>><span><strong><?= e($label) ?></strong><small><?= e($description) ?></small></span></label><?php endforeach; ?></div></fieldset>
      <div class="admin-form-actions"><button class="primary" type="submit"><?= $edit ? 'Save staff account' : 'Create staff account' ?> <span aria-hidden="true">→</span></button><?php if ($edit): ?> <a class="cancel" href="admin_control.php">Cancel</a><?php endif; ?></div>
    </form>
  </section>
  <section class="panel table-panel admin-staff-table-ui"><div class="admin-table-heading"><div><p class="eyebrow">Team directory</p><h2>Staff accounts</h2></div><span><?= count($staff) ?> <?= count($staff) === 1 ? 'account' : 'accounts' ?></span></div><div class="table-scroll"><table><thead><tr><th>Name</th><th>Username</th><th>Job role</th><th>Status</th><th>Assigned access</th><th></th></tr></thead><tbody><?php if (!$staff): ?><tr><td colspan="6" class="muted">No staff accounts yet.</td></tr><?php else: foreach ($staff as $member): $assigned = $staffPermissions[(int) $member['id']] ?? []; ?><tr><td><strong><?= e($member['full_name']) ?></strong></td><td><?= e($member['username']) ?></td><td><?= e($member['job_role']) ?></td><td><span class="admin-status <?= e($member['status']) ?>"><?= e(ucfirst($member['status'])) ?></span></td><td><?= e(implode(', ', array_map(fn(string $key): string => $permissions[$key][0] ?? $key, $assigned))) ?: 'None' ?></td><td><a class="admin-edit-link" href="admin_control.php?edit=<?= (int) $member['id'] ?>">Edit</a></td></tr><?php endforeach; endif; ?></tbody></table></div></section>
<?php page_end(); ?>
