<?php
declare(strict_types=1);

require_once __DIR__ . '/app/includes/layout.php';

$user = require_permission('dashboard');
$pdo = db();
$month = date('Y-m-01');
$today = date('Y-m-d');
$activeTenants = (int) $pdo->query("SELECT COUNT(*) FROM tenants WHERE status='active'")->fetchColumn();
$statement = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_month=:month');
$statement->execute(['month' => $month]);
$collected = (float) $statement->fetchColumn();
$openMaintenance = (int) $pdo->query("SELECT COUNT(*) FROM maintenance_requests WHERE status!='resolved'")->fetchColumn();
$occupiedBeds = (int) $pdo->query("SELECT COUNT(*) FROM tenants WHERE status='active' AND room_id IS NOT NULL")->fetchColumn();
$totalBeds = (int) $pdo->query('SELECT COALESCE(SUM(capacity),0) FROM rooms')->fetchColumn();
$recentPayments = $pdo->query('SELECT p.amount,p.payment_date,t.full_name FROM payments p INNER JOIN tenants t ON t.id=p.tenant_id ORDER BY p.payment_date DESC,p.id DESC LIMIT 5')->fetchAll();
$upcomingEvents = $pdo->query("SELECT event_type,start_date,full_name FROM (SELECT s.event_type,s.start_date,t.full_name FROM schedules s INNER JOIN tenants t ON t.id=s.tenant_id WHERE s.start_date>=CURDATE() UNION ALL SELECT se.event_type,se.start_date,CONCAT('Admin: ', u.full_name) AS full_name FROM staff_events se INNER JOIN users u ON u.id=se.user_id WHERE se.start_date>=CURDATE()) AS events ORDER BY start_date,full_name LIMIT 5")->fetchAll();
$expiringDocuments = $pdo->query("SELECT id,full_name,passport_expiry,arc_expiry FROM tenants WHERE status='active' AND ((passport_expiry IS NOT NULL AND passport_expiry <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)) OR (arc_expiry IS NOT NULL AND arc_expiry <= DATE_ADD(CURDATE(), INTERVAL 60 DAY))) ORDER BY LEAST(COALESCE(passport_expiry, '9999-12-31'), COALESCE(arc_expiry, '9999-12-31')), full_name")->fetchAll();
page_start('Dashboard', $user, 'dashboard');
?>
  <section class="dashboard-hero">
    <div><p class="eyebrow">Dormitory overview</p><h1>Welcome back, <?= e($user['full_name']) ?></h1><p><?= e(date('l, F j, Y')) ?> · Here is what needs your attention.</p></div>
    <div class="dashboard-hero-actions"><a class="button-link" href="tenants.php?action=add">+ Add tenant</a><a class="hero-link" href="calendar.php?shift=ADMIN">View Admin Calendar</a></div>
  </section>
  <section class="stats-grid dashboard-stats">
    <article class="stat-card stat-tenants"><span class="stat-icon">⌂</span><div><span>Active tenants</span><strong><?= $activeTenants ?></strong><a href="tenants.php">View tenant list <b>→</b></a></div></article>
    <article class="stat-card stat-payments"><span class="stat-icon">$</span><div><span>Collected this month</span><strong>NT$ <?= number_format($collected, 2) ?></strong><a href="payments.php">View payments <b>→</b></a></div></article>
    <article class="stat-card stat-occupancy"><span class="stat-icon">▦</span><div><span>Bed occupancy</span><strong><?= $occupiedBeds ?> <small>/ <?= $totalBeds ?></small></strong><a href="dormitories.php">Manage rooms <b>→</b></a></div></article>
    <article class="stat-card stat-maintenance"><span class="stat-icon">⚒</span><div><span>Open maintenance</span><strong><?= $openMaintenance ?></strong><a href="maintenance.php?filter=open">View requests <b>→</b></a></div></article>
  </section>
  <div class="management-grid dashboard-activity-grid">
    <section class="panel dashboard-panel"><div class="dashboard-panel-title"><div><p class="eyebrow">Finance</p><h2>Recent payments</h2></div><a href="payments.php">All payments →</a></div><?php if (!$recentPayments): ?><div class="empty-state"><span>◌</span><p>No payments recorded yet.</p></div><?php else: ?><div class="table-panel"><table><thead><tr><th>Tenant</th><th>Amount</th><th>Date</th></tr></thead><tbody><?php foreach ($recentPayments as $payment): ?><tr><td><?= e($payment['full_name']) ?></td><td><strong>NT$ <?= number_format((float) $payment['amount'], 2) ?></strong></td><td><?= e($payment['payment_date']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
    <section class="panel dashboard-panel"><div class="dashboard-panel-title"><div><p class="eyebrow">Schedule</p><h2>Upcoming events</h2></div><a href="calendar.php?shift=ADMIN">Admin Calendar →</a></div><?php if (!$upcomingEvents): ?><div class="empty-state"><span>◌</span><p>No upcoming schedule events.</p></div><?php else: ?><div class="table-panel"><table><thead><tr><th>Person</th><th>Event</th><th>Date</th></tr></thead><tbody><?php foreach ($upcomingEvents as $event): ?><tr><td><?= e($event['full_name']) ?></td><td><span class="event-pill"><?= e(ucwords(str_replace('_', ' ', $event['event_type']))) ?></span></td><td><?= e($event['start_date']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
  </div>
  <section class="panel dashboard-expiring-documents dashboard-panel"><div class="dashboard-panel-title"><div><p class="eyebrow">Document alerts</p><h2>Expired and nearly expired documents</h2></div><span class="alert-badge"><?= count($expiringDocuments) ?> alert<?= count($expiringDocuments) === 1 ? '' : 's' ?></span></div><p class="muted">Expired documents are marked in red. Other listed documents expire within the next 60 days.</p><?php if (!$expiringDocuments): ?><div class="empty-state compact"><span>✓</span><p>No passports or ARCs are expired or nearing expiry.</p></div><?php else: ?><div class="table-panel"><table><thead><tr><th>Tenant</th><th>Passport expiry</th><th>ARC expiry</th></tr></thead><tbody><?php foreach ($expiringDocuments as $tenant): $passportExpired = $tenant['passport_expiry'] && $tenant['passport_expiry'] < $today; $arcExpired = $tenant['arc_expiry'] && $tenant['arc_expiry'] < $today; ?><tr><td><a href="tenants.php?action=view&id=<?= (int) $tenant['id'] ?>"><?= e($tenant['full_name']) ?> <b>→</b></a></td><td><?php if ($tenant['passport_expiry']): ?><span class="document-date <?= $passportExpired ? 'expired' : 'due-soon' ?>"><?= e($tenant['passport_expiry']) ?><small><?= $passportExpired ? 'Expired' : 'Due soon' ?></small></span><?php else: ?>—<?php endif; ?></td><td><?php if ($tenant['arc_expiry']): ?><span class="document-date <?= $arcExpired ? 'expired' : 'due-soon' ?>"><?= e($tenant['arc_expiry']) ?><small><?= $arcExpired ? 'Expired' : 'Due soon' ?></small></span><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<?php page_end(); ?>
