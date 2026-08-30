<?php
declare(strict_types=1);

require_once __DIR__ . '/app/includes/layout.php';

if (!isset($masterType) || !in_array($masterType, ['employers', 'agencies'], true)) {
    http_response_code(404);
    exit('Page not found.');
}

$user = require_role('admin');
$pdo = db();
$isEmployer = $masterType === 'employers';
$title = $isEmployer ? 'Employers' : 'Agencies';
$singular = $isEmployer ? 'Employer' : 'Agency';
$tenantColumn = $isEmployer ? 'employer_id' : 'agency_id';
$selfPage = $isEmployer ? 'employers.php' : 'agencies.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'add') {
            $name = normalize_upper((string) ($_POST['name'] ?? '')) ?? '';
            if ($name === '' || mb_strlen($name) > 150) {
                throw new RuntimeException("Enter a $singular name of up to 150 characters.");
            }
            $pdo->prepare("INSERT INTO $masterType (name) VALUES (:name)")->execute(['name' => $name]);
            set_flash("$singular added.");
        } elseif ($action === 'delete') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                throw new RuntimeException("Invalid $singular.");
            }
            $count = $pdo->prepare("SELECT COUNT(*) FROM tenants WHERE $tenantColumn = :id");
            $count->execute(['id' => $id]);
            if ((int) $count->fetchColumn() > 0) {
                throw new RuntimeException("This $singular is in use by a tenant and cannot be removed.");
            }
            $statement = $pdo->prepare("DELETE FROM $masterType WHERE id = :id");
            $statement->execute(['id' => $id]);
            set_flash("$singular removed.");
        } else {
            throw new RuntimeException('Unknown request.');
        }
    } catch (PDOException $exception) {
        set_flash($exception->getCode() === '23000' ? 'That name already exists.' : 'The record could not be saved. Please try again.', 'error');
    } catch (RuntimeException $exception) {
        set_flash($exception->getMessage(), 'error');
    }
    redirect($selfPage);
}

$records = $pdo->query("SELECT m.id, m.name, COUNT(t.id) AS tenant_count FROM $masterType m LEFT JOIN tenants t ON t.$tenantColumn = m.id GROUP BY m.id, m.name ORDER BY m.name")->fetchAll();
$assignedTenants = array_sum(array_column($records, 'tenant_count'));
$availableRecords = count(array_filter($records, static fn(array $record): bool => (int) $record['tenant_count'] === 0));
$flash = consume_flash();
page_start($title, $user, $masterType);
?>
  <section class="master-data-hero">
    <div><p class="eyebrow">Administration</p><h1><?= e($title) ?></h1><p>Manage the <?= strtolower(e($title)) ?> connected to your active tenant records.</p></div>
    <div class="master-data-summary" aria-label="<?= e($title) ?> summary">
      <div><strong><?= count($records) ?></strong><span>Total <?= strtolower(e($title)) ?></span></div>
      <div><strong><?= (int) $assignedTenants ?></strong><span>Tenant assignments</span></div>
      <div><strong><?= $availableRecords ?></strong><span>Not in use</span></div>
    </div>
  </section>
  <?php if ($flash): ?><p class="flash <?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></p><?php endif; ?>
  <section class="panel master-form master-data-form">
    <div class="master-data-heading">
      <span class="master-data-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><?php if ($isEmployer): ?><path d="M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2M4 21h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2Zm-2-8h20M10 13v2h4v-2"/><?php else: ?><path d="M3 21h18M5 21V9l7-5 7 5v12M9 21v-6h6v6M9 11h.01M15 11h.01"/><?php endif; ?></svg></span>
      <div><p class="eyebrow">New record</p><h2>Add <?= e($singular) ?></h2><p>Create a <?= strtolower(e($singular)) ?> option for tenant profiles.</p></div>
    </div>
    <form method="post" class="quick-add">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="add">
      <input name="name" maxlength="150" placeholder="<?= e($singular) ?> name" required autofocus>
      <button type="submit">Add <?= e($singular) ?> <span aria-hidden="true">→</span></button>
    </form>
  </section>
  <section class="panel table-panel master-data-table">
    <div class="master-data-table-heading"><div><p class="eyebrow">Directory</p><h2>Saved <?= e($title) ?></h2></div><span class="count-badge"><?= count($records) ?> total</span></div>
    <table><thead><tr><th>Name</th><th>Assigned tenants</th><th>Status</th><th><span class="visually-hidden">Actions</span></th></tr></thead><tbody>
    <?php if (!$records): ?><tr><td colspan="4"><div class="report-empty">No <?= strtolower(e($title)) ?> added yet.</div></td></tr><?php else: ?>
      <?php foreach ($records as $record): $inUse = (int) $record['tenant_count'] > 0; ?><tr><td><strong class="primary-cell"><?= e($record['name']) ?></strong></td><td><span class="assignment-count"><?= (int) $record['tenant_count'] ?></span></td><td><span class="usage-badge <?= $inUse ? 'in-use' : 'available' ?>"><?= $inUse ? 'In use' : 'Available' ?></span></td><td class="master-data-action"><?php if (!$inUse): ?><form method="post" class="inline-delete" onsubmit="return confirm('Remove this <?= strtolower(e($singular)) ?>?');"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $record['id'] ?>"><button class="table-action danger-action" type="submit">Remove</button></form><?php else: ?><span class="locked-label">Protected</span><?php endif; ?></td></tr><?php endforeach; ?>
    <?php endif; ?>
    </tbody></table>
  </section>
<?php page_end(); ?>
