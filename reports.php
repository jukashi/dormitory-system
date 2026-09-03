<?php
declare(strict_types=1);
require_once __DIR__ . '/app/includes/layout.php';
$user=require_permission('reports');$pdo=db();
function csv_safe_cell(mixed $value): string { $cell=(string)($value??''); return preg_match('/^[=+\-@\t\r\n＝＋－＠]/u',$cell) ? "\t".$cell : $cell; }
function csv_out(string $filename,array $header,array $rows):never{header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="'.$filename.'"');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');$out=fopen('php://output','wb');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,array_map('csv_safe_cell',$header));foreach($rows as $row)fputcsv($out,array_map('csv_safe_cell',$row));fclose($out);exit;}
function report_month(mixed $value):string{return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',(string)$value)?(string)$value:date('Y-m');}
$month=report_month($_GET['month']??'');$action=(string)($_GET['action']??'');$type=(string)($_GET['type']??'');
if($action==='export'){
 if($type==='payments'){$s=$pdo->prepare('SELECT t.full_name,p.amount,p.payment_month,p.payment_date,p.payment_method,p.reference_no,p.notes FROM payments p INNER JOIN tenants t ON t.id=p.tenant_id WHERE p.payment_month=:month ORDER BY p.payment_date,p.id');$s->execute(['month'=>$month.'-01']);$rows=[];foreach($s as $r)$rows[]=[$r['full_name'],$r['amount'],substr($r['payment_month'],0,7),$r['payment_date'],str_replace('_',' ',$r['payment_method']),$r['reference_no'],$r['notes']];csv_out('payments-'.$month.'.csv',['Tenant','Amount (NT$)','For Month','Paid On','Method','Reference','Notes'],$rows);}
 if($type==='outstanding'){$s=$pdo->prepare("SELECT t.full_name,t.monthly_rent,t.contact_no,t.employee_id,COALESCE(SUM(p.amount),0) paid_amount,GREATEST(t.monthly_rent-COALESCE(SUM(p.amount),0),0) outstanding_amount FROM tenants t LEFT JOIN payments p ON p.tenant_id=t.id AND p.payment_month=:month WHERE t.status='active' AND t.monthly_rent>0 GROUP BY t.id,t.full_name,t.monthly_rent,t.contact_no,t.employee_id HAVING COALESCE(SUM(p.amount),0)<t.monthly_rent ORDER BY t.full_name");$s->execute(['month'=>$month.'-01']);$rows=[];foreach($s as $r)$rows[]=[$r['full_name'],$r['monthly_rent'],$r['paid_amount'],$r['outstanding_amount'],$r['contact_no'],$r['employee_id']];csv_out('outstanding-'.$month.'.csv',['Tenant','Monthly Rent (NT$)','Paid (NT$)','Balance (NT$)','Contact','Employee ID'],$rows);}
 if($type==='occupancy'){$s=$pdo->query("SELECT d.name,r.room_number,r.capacity,COUNT(t.id) occupied FROM rooms r INNER JOIN dormitories d ON d.id=r.dormitory_id LEFT JOIN tenants t ON t.room_id=r.id AND t.status='active' GROUP BY d.name,r.room_number,r.capacity ORDER BY d.name,r.room_number");$rows=[];foreach($s as $r)$rows[]=[$r['name'],$r['room_number'],$r['capacity'],$r['occupied'],max(0,(int)$r['capacity']-(int)$r['occupied'])];csv_out('occupancy.csv',['Dormitory','Room','Capacity','Occupied','Available'],$rows);}
 if($type==='tenants'){$s=$pdo->query("SELECT t.full_name,t.nationality,t.contact_no,t.passport_no,t.arc_no,t.employee_id,e.name employer,a.name agency,d.name dormitory,r.room_number,t.bed_number,t.shift_code,t.monthly_rent,t.date_moved_in,t.date_moved_out,t.status FROM tenants t LEFT JOIN employers e ON e.id=t.employer_id LEFT JOIN agencies a ON a.id=t.agency_id LEFT JOIN rooms r ON r.id=t.room_id LEFT JOIN dormitories d ON d.id=r.dormitory_id ORDER BY t.full_name");$rows=[];foreach($s as $r)$rows[]=array_values($r);csv_out('tenants.csv',['Name','Nationality','Contact','Passport','ARC','Employee ID','Employer','Agency','Dormitory','Room','Bed','Shift','Monthly Rent','Moved In','Moved Out','Status'],$rows);}
 if($type==='visitors'){$s=$pdo->query('SELECT v.visitor_name,v.visitor_id_no,t.full_name,v.purpose,v.time_in,v.time_out FROM visitors v INNER JOIN tenants t ON t.id=v.tenant_id ORDER BY v.time_in DESC');$rows=[];foreach($s as $r)$rows[]=array_values($r);csv_out('visitors.csv',['Visitor','Visitor ID','Visiting Tenant','Purpose','Time In','Time Out'],$rows);}
 if($type==='maintenance'){$s=$pdo->query("SELECT d.name dormitory,r.room_number,m.description,m.date_reported,m.status,u.full_name assigned_to FROM maintenance_requests m LEFT JOIN rooms r ON r.id=m.room_id LEFT JOIN dormitories d ON d.id=r.dormitory_id LEFT JOIN users u ON u.id=m.assigned_to ORDER BY m.date_reported DESC");$rows=[];foreach($s as $r)$rows[]=array_values($r);csv_out('maintenance.csv',['Dormitory','Room','Description','Reported','Status','Assigned To'],$rows);}
 if($type==='schedules'){$s=$pdo->query('SELECT t.full_name,t.employee_id,s.event_type,s.start_date,s.end_date,s.notes FROM schedules s INNER JOIN tenants t ON t.id=s.tenant_id ORDER BY s.start_date,t.full_name');$rows=[];foreach($s as $r)$rows[]=array_values($r);csv_out('schedules.csv',['Tenant','Employee ID','Event Type','Start Date','End Date','Notes'],$rows);}
 http_response_code(404);exit('Unknown report.');
}
$paymentTotals=$pdo->query("SELECT DATE_FORMAT(payment_month,'%Y-%m') month,COUNT(*) count,SUM(amount) total FROM payments GROUP BY payment_month ORDER BY payment_month DESC LIMIT 12")->fetchAll();
$out=$pdo->prepare("SELECT t.full_name,t.monthly_rent,t.contact_no,COALESCE(SUM(p.amount),0) paid_amount,GREATEST(t.monthly_rent-COALESCE(SUM(p.amount),0),0) outstanding_amount FROM tenants t LEFT JOIN payments p ON p.tenant_id=t.id AND p.payment_month=:month WHERE t.status='active' AND t.monthly_rent>0 GROUP BY t.id,t.full_name,t.monthly_rent,t.contact_no HAVING COALESCE(SUM(p.amount),0)<t.monthly_rent ORDER BY t.full_name");
$out->execute(['month'=>$month.'-01']);
$outstanding=$out->fetchAll();
$occupancy=$pdo->query("SELECT d.name dormitory,r.room_number,r.capacity,COUNT(t.id) occupied FROM rooms r INNER JOIN dormitories d ON d.id=r.dormitory_id LEFT JOIN tenants t ON t.room_id=r.id AND t.status='active' GROUP BY d.name,r.room_number,r.capacity ORDER BY d.name,r.room_number")->fetchAll();
$selectedCollected=0.0;
foreach($paymentTotals as $paymentTotal){if($paymentTotal['month']===$month){$selectedCollected=(float)$paymentTotal['total'];break;}}
$outstandingRent=array_sum(array_column($outstanding,'outstanding_amount'));
$totalBeds=array_sum(array_column($occupancy,'capacity'));
$occupiedBeds=array_sum(array_column($occupancy,'occupied'));
$occupancyRate=$totalBeds>0?(int)round(((int)$occupiedBeds/(int)$totalBeds)*100):0;
page_start('Reports',$user,'reports');?>

<section class="reports-hero">
  <div><p class="eyebrow">Analytics</p><h1>Reports &amp; Export</h1><p>Review financial and occupancy performance, then export the data you need.</p></div>
  <div class="reports-hero-month"><span>Reporting period</span><strong><?=e(date('F Y',strtotime($month.'-01')))?></strong></div>
</section>

<section class="reports-kpi-grid" aria-label="Reporting summary">
  <article class="reports-kpi collected"><span class="reports-kpi-icon" aria-hidden="true">$</span><div><span>Collected this month</span><strong>NT$ <?=number_format($selectedCollected,2)?></strong></div></article>
  <article class="reports-kpi outstanding"><span class="reports-kpi-icon" aria-hidden="true">!</span><div><span>Outstanding tenants</span><strong><?=count($outstanding)?></strong><small>NT$ <?=number_format((float)$outstandingRent,2)?> due</small></div></article>
  <article class="reports-kpi occupancy"><span class="reports-kpi-icon" aria-hidden="true">%</span><div><span>Bed occupancy</span><strong><?=$occupancyRate?>%</strong><small><?=(int)$occupiedBeds?> of <?=(int)$totalBeds?> beds</small></div></article>
</section>

<form class="reports-filter panel" method="get">
  <div><p class="eyebrow">Report controls</p><label>Reporting month<input type="month" name="month" value="<?=e($month)?>"></label></div>
  <button type="submit">Update reports <span aria-hidden="true">→</span></button>
</form>

<section class="panel table-panel reports-panel">
  <div class="report-title"><div><p class="eyebrow">Finance history</p><h2>Monthly payment totals</h2></div><a class="export-action" href="reports.php?action=export&type=payments&amp;month=<?=e($month)?>"><span aria-hidden="true">↓</span> Export selected month</a></div>
  <table><thead><tr><th>Month</th><th>Payments</th><th>Total collected</th></tr></thead><tbody><?php if(!$paymentTotals):?><tr><td colspan="3"><div class="report-empty">No payments recorded yet.</div></td></tr><?php else:foreach($paymentTotals as $row):?><tr><td><strong class="primary-cell"><?=e(date('F Y',strtotime($row['month'].'-01')))?></strong></td><td><span class="report-count-badge"><?=(int)$row['count']?> payment<?= (int)$row['count']===1?'':'s'?></span></td><td><strong>NT$ <?=number_format((float)$row['total'],2)?></strong></td></tr><?php endforeach;endif;?></tbody></table>
</section>

<section class="panel table-panel reports-panel">
  <div class="report-title"><div><p class="eyebrow">Collections</p><h2>Outstanding rent <span>· <?=e(date('F Y',strtotime($month.'-01')))?></span></h2></div><a class="export-action" href="reports.php?action=export&amp;type=outstanding&amp;month=<?=e($month)?>"><span aria-hidden="true">↓</span> Export CSV</a></div>
  <table><thead><tr><th>Tenant</th><th>Monthly rent</th><th>Paid</th><th>Balance due</th><th>Contact</th></tr></thead><tbody><?php if(!$outstanding):?><tr><td colspan="5"><div class="report-empty success">All active tenants are fully paid for this month.</div></td></tr><?php else:foreach($outstanding as $row):?><tr><td><strong class="primary-cell"><?=e($row['full_name'])?></strong></td><td>NT$ <?=number_format((float)$row['monthly_rent'],2)?></td><td>NT$ <?=number_format((float)$row['paid_amount'],2)?></td><td><span class="rent-due">NT$ <?=number_format((float)$row['outstanding_amount'],2)?></span></td><td><?=e($row['contact_no'])?></td></tr><?php endforeach;endif;?></tbody></table>
</section>

<section class="panel table-panel reports-panel">
  <div class="report-title"><div><p class="eyebrow">Accommodation</p><h2>Room occupancy</h2></div><a class="export-action" href="reports.php?action=export&amp;type=occupancy"><span aria-hidden="true">↓</span> Export CSV</a></div>
  <table><thead><tr><th>Dormitory</th><th>Room</th><th>Capacity</th><th>Occupied</th></tr></thead><tbody><?php if(!$occupancy):?><tr><td colspan="4"><div class="report-empty">No rooms added yet.</div></td></tr><?php else:foreach($occupancy as $row):$rowRate=(int)$row['capacity']>0?(int)round(((int)$row['occupied']/(int)$row['capacity'])*100):0;?><tr><td><strong class="primary-cell"><?=e($row['dormitory'])?></strong></td><td><span class="room-number"><?=e($row['room_number'])?></span></td><td><?=(int)$row['capacity']?></td><td><div class="occupancy-meter"><span><i style="width:<?=$rowRate?>%"></i></span><strong><?=(int)$row['occupied']?> / <?=(int)$row['capacity']?></strong></div></td></tr><?php endforeach;endif;?></tbody></table>
</section>

<section class="panel reports-panel other-exports">
  <div class="report-title"><div><p class="eyebrow">Data library</p><h2>Other exports</h2></div></div>
  <div class="export-links"><a href="reports.php?action=export&amp;type=tenants"><span>↓</span>Export Tenant List</a><a href="reports.php?action=export&amp;type=visitors"><span>↓</span>Export Visitor Log</a><a href="reports.php?action=export&amp;type=maintenance"><span>↓</span>Export Maintenance Log</a><a href="reports.php?action=export&amp;type=schedules"><span>↓</span>Export Schedule Calendar</a></div>
</section>
<?php page_end();
