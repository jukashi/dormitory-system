<?php
declare(strict_types=1);

require_once __DIR__ . '/app/includes/layout.php';

$user = require_permission('dormitories');
$pdo = db();

function positive_id(mixed $value): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $id === false ? 0 : (int) $id;
}

function dormitory_exists(PDO $pdo, int $id): bool
{
    $statement = $pdo->prepare('SELECT 1 FROM dormitories WHERE id = :id');
    $statement->execute(['id' => $id]);
    return (bool) $statement->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'save_dormitory') {
            $id = positive_id($_POST['id'] ?? null);
            $name = normalize_upper((string) ($_POST['name'] ?? '')) ?? '';
            $address = trim((string) ($_POST['address'] ?? ''));
            if ($name === '' || mb_strlen($name) > 150 || mb_strlen($address) > 255) {
                throw new RuntimeException('Enter a dormitory name of up to 150 characters and an address of up to 255 characters.');
            }
            if ($id > 0) {
                $statement = $pdo->prepare('UPDATE dormitories SET name = :name, address = :address WHERE id = :id');
                $statement->execute(['name' => $name, 'address' => $address !== '' ? $address : null, 'id' => $id]);
                set_flash('Dormitory updated.');
            } else {
                $statement = $pdo->prepare('INSERT INTO dormitories (name, address) VALUES (:name, :address)');
                $statement->execute(['name' => $name, 'address' => $address !== '' ? $address : null]);
                set_flash('Dormitory added.');
            }
        } elseif ($action === 'delete_dormitory') {
          require_role('admin');
            $id = positive_id($_POST['id'] ?? null);
            if ($id === 0) {
                throw new RuntimeException('Invalid dormitory.');
            }
            $statement = $pdo->prepare('DELETE FROM dormitories WHERE id = :id');
            $statement->execute(['id' => $id]);
            set_flash('Dormitory deleted.');
        } elseif ($action === 'save_room') {
            $id = positive_id($_POST['id'] ?? null);
            $dormitoryId = positive_id($_POST['dormitory_id'] ?? null);
            $roomNumber = normalize_upper((string) ($_POST['room_number'] ?? '')) ?? '';
            $capacity = filter_var($_POST['capacity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 99]]);
            if (!$dormitoryId || !dormitory_exists($pdo, $dormitoryId) || $roomNumber === '' || mb_strlen($roomNumber) > 30 || $capacity === false) {
                throw new RuntimeException('Select a dormitory, enter a room number, and set capacity between 1 and 99.');
            }
            if ($id > 0) {
                $pdo->beginTransaction();
                $roomLock = $pdo->prepare('SELECT id FROM rooms WHERE id = :id FOR UPDATE');
                $roomLock->execute(['id' => $id]);
                if (!$roomLock->fetchColumn()) {
                    throw new RuntimeException('Room not found.');
                }
                $occupancy = $pdo->prepare("SELECT COUNT(*) FROM tenants WHERE room_id = :id AND status = 'active'");
                $occupancy->execute(['id' => $id]);
                if ((int) $occupancy->fetchColumn() > (int) $capacity) {
                    throw new RuntimeException('Room capacity cannot be lower than its current active occupancy.');
                }
                $statement = $pdo->prepare('UPDATE rooms SET dormitory_id = :dormitory_id, room_number = :room_number, capacity = :capacity WHERE id = :id');
                $statement->execute(['dormitory_id' => $dormitoryId, 'room_number' => $roomNumber, 'capacity' => $capacity, 'id' => $id]);
                $pdo->commit();
                set_flash('Room updated.');
            } else {
                $statement = $pdo->prepare('INSERT INTO rooms (dormitory_id, room_number, capacity) VALUES (:dormitory_id, :room_number, :capacity)');
                $statement->execute(['dormitory_id' => $dormitoryId, 'room_number' => $roomNumber, 'capacity' => $capacity]);
                set_flash('Room added.');
            }
        } elseif ($action === 'delete_room') {
          require_role('admin');
            $id = positive_id($_POST['id'] ?? null);
            if ($id === 0) {
                throw new RuntimeException('Invalid room.');
            }
            $statement = $pdo->prepare('DELETE FROM rooms WHERE id = :id');
            $statement->execute(['id' => $id]);
            set_flash('Room deleted.');
        } else {
            throw new RuntimeException('Unknown request.');
        }
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if ($exception->getCode() === '23000') {
            set_flash('This item cannot be deleted because it is being used, or the room number already exists in this dormitory.', 'error');
        } else {
            set_flash('The change could not be saved. Please try again.', 'error');
        }
    } catch (RuntimeException $exception) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        set_flash($exception->getMessage(), 'error');
    }

    redirect('dormitories.php');
}

$editDormitory = null;
$editDormitoryId = positive_id($_GET['edit_dormitory'] ?? null);
if ($editDormitoryId > 0) {
    $statement = $pdo->prepare('SELECT id, name, address FROM dormitories WHERE id = :id');
    $statement->execute(['id' => $editDormitoryId]);
    $editDormitory = $statement->fetch() ?: null;
}

$editRoom = null;
$editRoomId = positive_id($_GET['edit_room'] ?? null);
if ($editRoomId > 0) {
    $statement = $pdo->prepare('SELECT id, dormitory_id, room_number, capacity FROM rooms WHERE id = :id');
    $statement->execute(['id' => $editRoomId]);
    $editRoom = $statement->fetch() ?: null;
}

$dormitories = $pdo->query('SELECT id, name, address FROM dormitories ORDER BY name')->fetchAll();
$rooms = $pdo->query("SELECT r.id, r.dormitory_id, r.room_number, r.capacity, d.name AS dormitory_name,
    COUNT(t.id) AS occupied_beds
    FROM rooms r
    INNER JOIN dormitories d ON d.id = r.dormitory_id
    LEFT JOIN tenants t ON t.room_id = r.id AND t.status = 'active'
    GROUP BY r.id, r.dormitory_id, r.room_number, r.capacity, d.name
    ORDER BY d.name, r.room_number")->fetchAll();
$totalCapacity = array_sum(array_column($rooms, 'capacity'));
$occupiedBeds = array_sum(array_column($rooms, 'occupied_beds'));
$availableBeds = max(0, (int) $totalCapacity - (int) $occupiedBeds);
$flash = consume_flash();

page_start('Dormitories & Rooms', $user, 'dormitories');
?>
  <section class="accommodation-hero">
    <div class="accommodation-hero-copy">
      <p class="eyebrow">Administration</p>
      <h1>Dormitories &amp; Rooms</h1>
      <p>Manage accommodation, room assignments, and bed availability from one workspace.</p>
    </div>
    <div class="accommodation-summary" aria-label="Accommodation summary">
      <div><strong><?= count($dormitories) ?></strong><span>Dormitories</span></div>
      <div><strong><?= count($rooms) ?></strong><span>Rooms</span></div>
      <div><strong><?= $availableBeds ?></strong><span>Beds available</span></div>
    </div>
  </section>
  <?php if ($flash): ?><p class="flash <?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></p><?php endif; ?>

  <div class="management-grid accommodation-form-grid">
    <section class="panel accommodation-form-card dormitory-form-card">
      <div class="accommodation-card-heading"><span class="accommodation-card-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 21V3h12v5h6v13M7 7h4M7 11h4M7 15h4M17 12h1M17 16h1M2 21h20"/></svg></span><div><p class="eyebrow"><?= $editDormitory ? 'Update location' : 'New location' ?></p><h2><?= $editDormitory ? 'Edit dormitory' : 'Add dormitory' ?></h2><p>Create and maintain accommodation locations.</p></div></div>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="save_dormitory">
        <input type="hidden" name="id" value="<?= (int) ($editDormitory['id'] ?? 0) ?>">
        <label>Name<input name="name" maxlength="150" value="<?= e($editDormitory['name'] ?? '') ?>" required></label>
        <label>Address<input name="address" maxlength="255" value="<?= e($editDormitory['address'] ?? '') ?>"></label>
        <button class="primary" type="submit"><?= $editDormitory ? 'Save changes' : 'Add dormitory' ?></button>
        <?php if ($editDormitory): ?><a class="cancel" href="dormitories.php">Cancel</a><?php endif; ?>
      </form>
    </section>

    <section class="panel accommodation-form-card room-form-card">
      <div class="accommodation-card-heading"><span class="accommodation-card-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 21V5a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v16M2 21h20M8 8h.01M8 12h.01M8 16h.01M16 8h.01M16 12h.01M16 16h.01"/></svg></span><div><p class="eyebrow"><?= $editRoom ? 'Update inventory' : 'Room inventory' ?></p><h2><?= $editRoom ? 'Edit room' : 'Add room' ?></h2><p>Set room numbers and maximum bed capacity.</p></div></div>
      <?php if (!$dormitories): ?>
        <p class="muted">Add a dormitory before adding rooms.</p>
      <?php else: ?>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="save_room">
          <input type="hidden" name="id" value="<?= (int) ($editRoom['id'] ?? 0) ?>">
          <label>Dormitory<select name="dormitory_id" required><?php foreach ($dormitories as $dormitory): ?><option value="<?= (int) $dormitory['id'] ?>" <?= (int) ($editRoom['dormitory_id'] ?? 0) === (int) $dormitory['id'] ? 'selected' : '' ?>><?= e($dormitory['name']) ?></option><?php endforeach; ?></select></label>
          <label>Room number<input name="room_number" maxlength="30" value="<?= e($editRoom['room_number'] ?? '') ?>" required></label>
          <label>Capacity<input type="number" name="capacity" min="1" max="99" value="<?= (int) ($editRoom['capacity'] ?? 4) ?>" required></label>
          <button class="primary" type="submit"><?= $editRoom ? 'Save changes' : 'Add room' ?></button>
          <?php if ($editRoom): ?><a class="cancel" href="dormitories.php">Cancel</a><?php endif; ?>
        </form>
      <?php endif; ?>
    </section>
  </div>

  <section class="panel table-panel accommodation-table-card">
    <div class="accommodation-section-heading"><div><p class="eyebrow">Locations</p><h2>Dormitories</h2></div><span class="count-badge"><?= count($dormitories) ?> total</span></div>
    <?php if (!$dormitories): ?><p class="muted">No dormitories added yet.</p><?php else: ?>
      <table><thead><tr><th>Name</th><th>Address</th><th>Actions</th></tr></thead><tbody>
      <?php foreach ($dormitories as $dormitory): ?><tr><td><strong class="primary-cell"><?= e($dormitory['name']) ?></strong></td><td><?= $dormitory['address'] ? e($dormitory['address']) : '<span class="not-set">Not set</span>' ?></td><td class="actions"><a class="table-action" href="dormitories.php?edit_dormitory=<?= (int) $dormitory['id'] ?>">Edit</a><form method="post" onsubmit="return confirm('Delete this dormitory? Rooms must be deleted first.');"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="delete_dormitory"><input type="hidden" name="id" value="<?= (int) $dormitory['id'] ?>"><button class="table-action danger-action" type="submit">Delete</button></form></td></tr><?php endforeach; ?>
      </tbody></table>
    <?php endif; ?>
  </section>

  <section class="panel table-panel accommodation-table-card">
    <div class="accommodation-section-heading"><div><p class="eyebrow">Capacity</p><h2>Rooms &amp; bed availability</h2></div><span class="count-badge"><?= count($rooms) ?> total</span></div>
    <?php if (!$rooms): ?><p class="muted">No rooms added yet.</p><?php else: ?>
      <table><thead><tr><th>Dormitory</th><th>Room</th><th>Capacity</th><th>Occupied beds</th><th>Available beds</th><th>Actions</th></tr></thead><tbody>
      <?php foreach ($rooms as $room): $available = max(0, (int) $room['capacity'] - (int) $room['occupied_beds']); ?><tr><td><strong class="primary-cell"><?= e($room['dormitory_name']) ?></strong></td><td><span class="room-number"><?= e($room['room_number']) ?></span></td><td><?= (int) $room['capacity'] ?></td><td><span class="occupancy-badge"><?= (int) $room['occupied_beds'] ?> occupied</span></td><td><span class="availability-badge <?= $available === 0 ? 'full' : '' ?>"><?= $available ?> available</span></td><td class="actions"><a class="table-action" href="dormitories.php?edit_room=<?= (int) $room['id'] ?>">Edit</a><form method="post" onsubmit="return confirm('Delete this room?');"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="delete_room"><input type="hidden" name="id" value="<?= (int) $room['id'] ?>"><button class="table-action danger-action" type="submit">Delete</button></form></td></tr><?php endforeach; ?>
      </tbody></table>
    <?php endif; ?>
  </section>
<?php page_end(); ?>
