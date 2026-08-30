<?php
declare(strict_types=1);

require_once __DIR__ . '/app/includes/layout.php';

$user = require_permission('payments');
$pdo = db();

function payment_id(mixed $value): int { $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]); return $id === false ? 0 : (int) $id; }
function valid_month(mixed $value): ?string { $month = (string) $value; if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) { return null; } return $month . '-01'; }
function valid_payment_date(mixed $value): ?string { $date = (string) $value; $check = DateTime::createFromFormat('Y-m-d', $date); return $check && $check->format('Y-m-d') === $date ? $date : null; }
function active_tenant_exists(PDO $pdo, int $id): bool { $statement = $pdo->prepare("SELECT 1 FROM tenants WHERE id=:id AND status='active'"); $statement->execute(['id' => $id]); return (bool) $statement->fetchColumn(); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    try {
        if (($_POST['action'] ?? '') !== 'save_payment') { throw new RuntimeException('Unknown request.'); }
        $tenantId = payment_id($_POST['tenant_id'] ?? null);
        $amount = filter_var($_POST['amount'] ?? null, FILTER_VALIDATE_FLOAT);
        $paymentMonth = valid_month($_POST['payment_month'] ?? null);
        $paymentDate = valid_payment_date($_POST['payment_date'] ?? null);
        $method = (string) ($_POST['payment_method'] ?? '');
        $reference = normalize_upper((string) ($_POST['reference_no'] ?? '')) ?? '';
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $allowDuplicate = isset($_POST['allow_duplicate']) && $user['role'] === 'admin';
        if (!$tenantId || !active_tenant_exists($pdo, $tenantId) || $amount === false || $amount <= 0 || $amount > 99999999.99 || !$paymentMonth || !$paymentDate) { throw new RuntimeException('Choose an active tenant and enter a valid amount, payment month, and payment date.'); }
        if (!in_array($method, ['cash', 'bank_transfer', 'payroll_deduction', 'other'], true)) { throw new RuntimeException('Select a valid payment method.'); }
        if (mb_strlen($reference) > 100 || mb_strlen($notes) > 5000) { throw new RuntimeException('Reference or notes are too long.'); }
        if (!$allowDuplicate) {
            $duplicate = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE tenant_id=:tenant_id AND payment_month=:payment_month');
            $duplicate->execute(['tenant_id' => $tenantId, 'payment_month' => $paymentMonth]);
            if ((int) $duplicate->fetchColumn() > 0) { throw new RuntimeException('This tenant already has a payment for the selected month. An administrator may allow an additional payment when needed.'); }
        }
        $statement = $pdo->prepare('INSERT INTO payments (tenant_id,amount,payment_month,payment_date,payment_method,reference_no,notes,recorded_by) VALUES (:tenant_id,:amount,:payment_month,:payment_date,:payment_method,:reference_no,:notes,:recorded_by)');
        $statement->execute(['tenant_id' => $tenantId, 'amount' => $amount, 'payment_month' => $paymentMonth, 'payment_date' => $paymentDate, 'payment_method' => $method, 'reference_no' => $reference !== '' ? $reference : null, 'notes' => $notes !== '' ? $notes : null, 'recorded_by' => $user['id']]);
        set_flash('Payment recorded.');
        redirect('payments.php?month=' . substr($paymentMonth, 0, 7));
    } catch (RuntimeException $exception) { set_flash($exception->getMessage(), 'error'); redirect('payments.php?action=add'); }
}

$action = (string) ($_GET['action'] ?? 'list');
$selectedMonth = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) ($_GET['month'] ?? '')) ? (string) $_GET['month'] : date('Y-m');
$tenants = $pdo->query("SELECT id,full_name,monthly_rent,room_id FROM tenants WHERE status='active' ORDER BY full_name")->fetchAll();
$flash = consume_flash();

if ($action === 'add') {
    $preselectedTenantId = payment_id($_GET['tenant_id'] ?? null);
    page_start('Record Payment', $user, 'payments');
    ?>
    <p class="eyebrow">Rent Ledger</p><h1>Record payment</h1>
    <?php if ($flash): ?><p class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></p><?php endif; ?>
    <?php if (!$tenants): ?><section class="panel"><p class="muted">There are no active tenants to receive a payment. Add a tenant first.</p><a class="button-link" href="tenants.php?action=add">Add tenant</a></section><?php else: ?>
    <form method="post" class="panel payment-form"><input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="save_payment">
      <label>Tenant *<select id="tenant_id" name="tenant_id" required onchange="fillRent()"><option value="">— Select tenant —</option><?php foreach ($tenants as $tenant): ?><option value="<?= (int) $tenant['id'] ?>" data-rent="<?= e((string) $tenant['monthly_rent']) ?>" <?= $preselectedTenantId === (int) $tenant['id'] ? 'selected' : '' ?>><?= e($tenant['full_name']) ?></option><?php endforeach; ?></select></label>
      <div class="form-grid"><label>Amount (NT$) *<input id="amount" type="number" name="amount" min="0.01" step="0.01" required></label><label>For month *<input type="month" name="payment_month" value="<?= e($selectedMonth) ?>" required></label><label>Payment date *<input type="date" name="payment_date" value="<?= date('Y-m-d') ?>" required></label><label>Method *<select name="payment_method"><option value="cash">Cash</option><option value="bank_transfer">Bank transfer</option><option value="payroll_deduction">Payroll deduction</option><option value="other">Other</option></select></label><label>Reference number<input name="reference_no" maxlength="100"></label></div>
      <label>Notes<textarea name="notes" maxlength="5000" rows="4"></textarea></label>
      <?php if ($user['role'] === 'admin'): ?><label class="checkbox"><input type="checkbox" name="allow_duplicate" value="1"> Allow an additional payment for the same tenant and month</label><?php endif; ?>
      <button class="primary" type="submit">Save payment</button> <a class="cancel" href="payments.php">Cancel</a>
    </form><script>function fillRent(){const option=document.getElementById('tenant_id').selectedOptions[0];if(option&&option.dataset.rent){document.getElementById('amount').value=option.dataset.rent;}}fillRent();</script>
    <?php endif; page_end(); exit;
}

$statement = $pdo->prepare("SELECT p.*,t.full_name,u.full_name AS recorded_by_name FROM payments p INNER JOIN tenants t ON t.id=p.tenant_id LEFT JOIN users u ON u.id=p.recorded_by WHERE p.payment_month=:payment_month ORDER BY p.payment_date DESC,p.id DESC");
$statement->execute(['payment_month' => $selectedMonth . '-01']); $payments = $statement->fetchAll();
$total = array_sum(array_map(fn(array $payment): float => (float) $payment['amount'], $payments));
$paidTenants = count(array_unique(array_column($payments, 'tenant_id')));
page_start('Payments', $user, 'payments');
?>
  <section class="payments-hero">
    <div><p class="eyebrow">Rent Ledger</p><h1>Payments</h1><p>Track monthly rent collections and maintain a clear payment history.</p></div>
    <div class="payments-hero-actions"><div><span>Reporting period</span><strong><?= e(date('F Y', strtotime($selectedMonth . '-01'))) ?></strong></div><a class="button-link" href="payments.php?action=add&amp;month=<?= e($selectedMonth) ?>"><span aria-hidden="true">+</span> Record payment</a></div>
  </section>
  <section class="payments-summary-grid" aria-label="Payment summary">
    <article><span class="payment-summary-icon collected" aria-hidden="true">$</span><div><span>Total collected</span><strong>NT$ <?= number_format($total, 2) ?></strong></div></article>
    <article><span class="payment-summary-icon records" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M6 2h12v20l-3-2-3 2-3-2-3 2V2ZM9 7h6M9 11h6M9 15h3"/></svg></span><div><span>Payment records</span><strong><?= count($payments) ?></strong></div></article>
    <article><span class="payment-summary-icon tenants" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M17 11l2 2 4-5"/></svg></span><div><span>Tenants paid</span><strong><?= $paidTenants ?> <small>/ <?= count($tenants) ?> active</small></strong></div></article>
  </section>
  <?php if ($flash): ?><p class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></p><?php endif; ?>
  <form class="panel payments-filter" method="get"><div><p class="eyebrow">Ledger controls</p><label>Payment month<input type="month" name="month" value="<?= e($selectedMonth) ?>"></label></div><button type="submit">Show payments <span aria-hidden="true">→</span></button></form>
  <section class="panel table-panel payments-ledger">
    <div class="payments-ledger-heading"><div><p class="eyebrow">Transactions</p><h2>Payment history</h2></div><span class="count-badge"><?= count($payments) ?> record<?= count($payments) === 1 ? '' : 's' ?></span></div>
    <table><thead><tr><th>Tenant</th><th>Amount</th><th>For month</th><th>Paid on</th><th>Method</th><th>Reference</th><th>Recorded by</th></tr></thead><tbody><?php if (!$payments): ?><tr><td colspan="7"><div class="payment-empty"><span aria-hidden="true">$</span><div><strong>No payments recorded</strong><p>There are no transactions for <?= e(date('F Y', strtotime($selectedMonth . '-01'))) ?> yet.</p></div></div></td></tr><?php else: foreach ($payments as $payment): preg_match('/^./u', (string) $payment['full_name'], $paymentInitialMatch); ?><tr><td><div class="payment-tenant"><span aria-hidden="true"><?= e(strtoupper($paymentInitialMatch[0] ?? '?')) ?></span><strong><?= e($payment['full_name']) ?></strong></div></td><td><strong class="payment-amount">NT$ <?= number_format((float) $payment['amount'], 2) ?></strong></td><td><?= e(date('M Y', strtotime($payment['payment_month']))) ?></td><td><?= e($payment['payment_date']) ?></td><td><span class="payment-method <?= e($payment['payment_method']) ?>"><?= e(ucwords(str_replace('_', ' ', $payment['payment_method']))) ?></span></td><td><?= $payment['reference_no'] ? '<span class="payment-reference">' . e($payment['reference_no']) . '</span>' : '<span class="not-set">Not provided</span>' ?></td><td><?= $payment['recorded_by_name'] ? e($payment['recorded_by_name']) : '<span class="not-set">Unknown</span>' ?></td></tr><?php endforeach; endif; ?></tbody></table>
  </section>
<?php page_end();
