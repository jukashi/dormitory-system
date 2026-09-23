<?php
declare(strict_types=1);
require_once __DIR__ . '/app/includes/layout.php';
$user=require_permission('reports');$pdo=db();
function csv_safe_cell(mixed $value): string { $cell=(string)($value??''); return preg_match('/^[=+\-@\t\r\n＝＋－＠]/u',$cell) ? "\t".$cell : $cell; }
function csv_out(string $filename,array $header,array $rows):never{header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="'.$filename.'"');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');$out=fopen('php://output','wb');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,array_map('csv_safe_cell',$header));foreach($rows as $row)fputcsv($out,array_map('csv_safe_cell',$row));fclose($out);exit;}
function report_month(mixed $value):string{return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',(string)$value)?(string)$value:date('Y-m');}
function report_positive_id(mixed $value):int{$id=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);return $id===false?0:(int)$id;}
function tenant_item_report(PDO $pdo,string $itemStatus,string $tenantStatus,int $itemId,int $dormitoryId):array{
 $where=[];$params=[];
 if($itemStatus==='issued'){$where[]='a.returned_on IS NULL';}elseif($itemStatus==='returned'){$where[]='a.returned_on IS NOT NULL';}
 if($tenantStatus!=='all'){$where[]='t.status=:tenant_status';$params['tenant_status']=$tenantStatus;}
 if($itemId>0){$where[]='i.id=:item_id';$params['item_id']=$itemId;}
 if($dormitoryId>0){$where[]='d.id=:dormitory_id';$params['dormitory_id']=$dormitoryId;}
 $sql="SELECT a.id,t.full_name,t.employee_id,t.status tenant_status,d.name dormitory,r.room_number,i.name item_name,a.reference_no,a.issued_on,issuer.full_name issued_by,a.issue_notes,a.returned_on,receiver.full_name returned_by,a.return_notes FROM tenant_item_assignments a INNER JOIN tenants t ON t.id=a.tenant_id INNER JOIN tenant_items i ON i.id=a.item_id LEFT JOIN rooms r ON r.id=t.room_id LEFT JOIN dormitories d ON d.id=r.dormitory_id LEFT JOIN users issuer ON issuer.id=a.issued_by LEFT JOIN users receiver ON receiver.id=a.returned_by".($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY (a.returned_on IS NULL) DESC,i.name,t.full_name,a.issued_on DESC,a.id DESC';
 $statement=$pdo->prepare($sql);$statement->execute($params);return $statement->fetchAll();
}
function tenant_vehicle_report(PDO $pdo,string $tenantStatus,string $vehicleType,int $dormitoryId,string $search):array{
 $where=[];$params=[];
 if($tenantStatus!=='all'){$where[]='t.status=:vehicle_tenant_status';$params['vehicle_tenant_status']=$tenantStatus;}
 if($vehicleType!=='all'){$where[]='v.vehicle_type=:vehicle_type';$params['vehicle_type']=$vehicleType;}
 if($dormitoryId>0){$where[]='d.id=:vehicle_dormitory_id';$params['vehicle_dormitory_id']=$dormitoryId;}
 if($search!==''){$where[]='(t.full_name LIKE :vehicle_q_tenant OR v.license_plate_no LIKE :vehicle_q_plate OR v.sticker_no LIKE :vehicle_q_sticker)';$term='%'.$search.'%';$params['vehicle_q_tenant']=$term;$params['vehicle_q_plate']=$term;$params['vehicle_q_sticker']=$term;}
 $sql="SELECT v.id,t.full_name,t.employee_id,t.status tenant_status,d.name dormitory,r.room_number,v.vehicle_type,v.license_plate_no,v.sticker_no,v.registration_date,v.notes FROM tenant_vehicles v INNER JOIN tenants t ON t.id=v.tenant_id LEFT JOIN rooms r ON r.id=t.room_id LEFT JOIN dormitories d ON d.id=r.dormitory_id".($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY t.full_name,v.vehicle_type,v.license_plate_no';
 $statement=$pdo->prepare($sql);$statement->execute($params);return $statement->fetchAll();
}
$month=report_month($_GET['month']??'');$action=(string)($_GET['action']??'');$type=(string)($_GET['type']??'');
$requestedItemStatus=(string)($_GET['item_status']??'issued');
$requestedTenantStatus=(string)($_GET['tenant_status']??'all');
$itemStatus=in_array($requestedItemStatus,['issued','returned','all'],true)?$requestedItemStatus:'issued';
$tenantStatus=in_array($requestedTenantStatus,['active','moved_out','all'],true)?$requestedTenantStatus:'all';
$reportItemId=report_positive_id($_GET['item_id']??null);$reportDormitoryId=report_positive_id($_GET['dormitory_id']??null);
$vehicleTypes=['car'=>'Car','motorcycle'=>'Motorcycle','scooter'=>'Scooter','bicycle'=>'Bicycle','van'=>'Van','truck'=>'Truck','other'=>'Other'];
$requestedVehicleTenantStatus=(string)($_GET['vehicle_tenant_status']??'all');$vehicleTenantStatus=in_array($requestedVehicleTenantStatus,['active','moved_out','all'],true)?$requestedVehicleTenantStatus:'all';
$requestedVehicleType=(string)($_GET['vehicle_type']??'all');$vehicleType=$requestedVehicleType==='all'||isset($vehicleTypes[$requestedVehicleType])?$requestedVehicleType:'all';
$vehicleDormitoryId=report_positive_id($_GET['vehicle_dormitory_id']??null);$vehicleSearch=mb_substr(trim((string)($_GET['vehicle_q']??'')),0,100);
if($action==='export'){
 if($type==='payments'){$s=$pdo->prepare('SELECT t.full_name,p.amount,p.payment_month,p.payment_date,p.payment_method,p.reference_no,p.notes FROM payments p INNER JOIN tenants t ON t.id=p.tenant_id WHERE p.payment_month=:month ORDER BY p.payment_date,p.id');$s->execute(['month'=>$month.'-01']);$rows=[];foreach($s as $r)$rows[]=[$r['full_name'],$r['amount'],substr($r['payment_month'],0,7),$r['payment_date'],str_replace('_',' ',$r['payment_method']),$r['reference_no'],$r['notes']];csv_out('payments-'.$month.'.csv',['Tenant','Amount (NT$)','For Month','Paid On','Method','Reference','Notes'],$rows);}
 if($type==='outstanding'){$s=$pdo->prepare("SELECT t.full_name,t.monthly_rent,t.contact_no,t.employee_id,COALESCE(SUM(p.amount),0) paid_amount,GREATEST(t.monthly_rent-COALESCE(SUM(p.amount),0),0) outstanding_amount FROM tenants t LEFT JOIN payments p ON p.tenant_id=t.id AND p.payment_month=:month WHERE t.status='active' AND t.monthly_rent>0 GROUP BY t.id,t.full_name,t.monthly_rent,t.contact_no,t.employee_id HAVING COALESCE(SUM(p.amount),0)<t.monthly_rent ORDER BY t.full_name");$s->execute(['month'=>$month.'-01']);$rows=[];foreach($s as $r)$rows[]=[$r['full_name'],$r['monthly_rent'],$r['paid_amount'],$r['outstanding_amount'],$r['contact_no'],$r['employee_id']];csv_out('outstanding-'.$month.'.csv',['Tenant','Monthly Rent (NT$)','Paid (NT$)','Balance (NT$)','Contact','Employee ID'],$rows);}
 if($type==='occupancy'){$s=$pdo->query("SELECT d.name,r.room_number,r.capacity,COUNT(t.id) occupied FROM rooms r INNER JOIN dormitories d ON d.id=r.dormitory_id LEFT JOIN tenants t ON t.room_id=r.id AND t.status='active' GROUP BY d.name,r.room_number,r.capacity ORDER BY d.name,r.room_number");$rows=[];foreach($s as $r)$rows[]=[$r['name'],$r['room_number'],$r['capacity'],$r['occupied'],max(0,(int)$r['capacity']-(int)$r['occupied'])];csv_out('occupancy.csv',['Dormitory','Room','Capacity','Occupied','Available'],$rows);}
 if($type==='tenants'){$s=$pdo->query("SELECT t.full_name,t.nationality,t.contact_no,t.passport_no,t.arc_no,t.employee_id,e.name employer,a.name agency,d.name dormitory,r.room_number,t.bed_number,t.shift_code,t.monthly_rent,t.date_moved_in,t.date_moved_out,t.status FROM tenants t LEFT JOIN employers e ON e.id=t.employer_id LEFT JOIN agencies a ON a.id=t.agency_id LEFT JOIN rooms r ON r.id=t.room_id LEFT JOIN dormitories d ON d.id=r.dormitory_id ORDER BY t.full_name");$rows=[];foreach($s as $r)$rows[]=array_values($r);csv_out('tenants.csv',['Name','Nationality','Contact','Passport','ARC','Employee ID','Employer','Agency','Dormitory','Room','Bed','Shift','Monthly Rent','Moved In','Moved Out','Status'],$rows);}
 if($type==='visitors'){$s=$pdo->query('SELECT v.visitor_name,v.visitor_id_no,t.full_name,v.purpose,v.time_in,v.time_out FROM visitors v INNER JOIN tenants t ON t.id=v.tenant_id ORDER BY v.time_in DESC');$rows=[];foreach($s as $r)$rows[]=array_values($r);csv_out('visitors.csv',['Visitor','Visitor ID','Visiting Tenant','Purpose','Time In','Time Out'],$rows);}
 if($type==='maintenance'){$s=$pdo->query("SELECT d.name dormitory,r.room_number,m.description,m.date_reported,m.status,u.full_name assigned_to FROM maintenance_requests m LEFT JOIN rooms r ON r.id=m.room_id LEFT JOIN dormitories d ON d.id=r.dormitory_id LEFT JOIN users u ON u.id=m.assigned_to ORDER BY m.date_reported DESC");$rows=[];foreach($s as $r)$rows[]=array_values($r);csv_out('maintenance.csv',['Dormitory','Room','Description','Reported','Status','Assigned To'],$rows);}
 if($type==='schedules'){
  $rows=[];
  $tenantSchedules=$pdo->query('SELECT t.full_name,t.employee_id,s.event_type,s.start_date,s.end_date,s.notes FROM schedules s INNER JOIN tenants t ON t.id=s.tenant_id ORDER BY s.start_date,t.full_name');
  foreach($tenantSchedules as $r){$rows[]=['Tenant',$r['full_name'],$r['employee_id'],$r['event_type'],$r['start_date'],$r['end_date'],$r['notes']];}
  $staffSchedules=$pdo->query('SELECT u.full_name,u.job_role,se.event_type,se.start_date,se.end_date,se.notes FROM staff_events se INNER JOIN users u ON u.id=se.user_id ORDER BY se.start_date,u.full_name');
  foreach($staffSchedules as $r){$rows[]=['Staff',$r['full_name'],$r['job_role'],$r['event_type'],$r['start_date'],$r['end_date'],$r['notes']];}
  $shiftSchedules=$pdo->query('SELECT shift_code,schedule_date,is_work_day,source_value FROM shift_schedule_entries ORDER BY schedule_date,shift_code');
  foreach($shiftSchedules as $r){$rows[]=['Shift',$r['shift_code'],$r['shift_code'],(int)$r['is_work_day']===1?'work_day':'day_off',$r['schedule_date'],$r['schedule_date'],$r['source_value']];}
  usort($rows,fn(array $a,array $b):int=>[$a[4],$a[0],$a[1]]<=>[$b[4],$b[0],$b[1]]);
  csv_out('schedule-calendar.csv',['Audience','Name','Employee ID / Role / Shift','Event Type','Start Date','End Date','Notes'],$rows);
 }
 if($type==='tenant_items'){$data=tenant_item_report($pdo,$itemStatus,$tenantStatus,$reportItemId,$reportDormitoryId);$rows=[];foreach($data as $r)$rows[]=[$r['full_name'],$r['employee_id'],$r['tenant_status'],$r['dormitory'],$r['room_number'],$r['item_name'],$r['reference_no'],$r['issued_on'],$r['issued_by'],$r['issue_notes'],$r['returned_on'],$r['returned_by'],$r['return_notes'],$r['returned_on']?'Returned':'Issued'];$suffix=$itemStatus==='issued'?'currently-issued':($itemStatus==='returned'?'returned':'history');csv_out('tenant-items-'.$suffix.'.csv',['Tenant','Employee ID','Tenant Status','Dormitory','Room','Item','Reference','Issued On','Issued By','Issue Notes','Returned On','Returned By','Return Notes','Item Status'],$rows);}
 if($type==='tenant_vehicles'){$data=tenant_vehicle_report($pdo,$vehicleTenantStatus,$vehicleType,$vehicleDormitoryId,$vehicleSearch);$rows=[];foreach($data as $r)$rows[]=[$r['full_name'],$r['employee_id'],$r['tenant_status'],$r['dormitory'],$r['room_number'],$vehicleTypes[$r['vehicle_type']]??ucwords($r['vehicle_type']),$r['license_plate_no'],$r['sticker_no'],$r['registration_date'],$r['notes']];csv_out('tenant-vehicles.csv',['Tenant','Employee ID','Tenant Status','Dormitory','Room','Vehicle Type','License Plate Number','Sticker Number','Registration Date','Notes'],$rows);}
 http_response_code(404);exit('Unknown report.');
}
$paymentTotals=$pdo->query("SELECT DATE_FORMAT(payment_month,'%Y-%m') month,COUNT(*) count,SUM(amount) total FROM payments GROUP BY payment_month ORDER BY payment_month DESC LIMIT 12")->fetchAll();
$out=$pdo->prepare("SELECT t.full_name,t.monthly_rent,t.contact_no,COALESCE(SUM(p.amount),0) paid_amount,GREATEST(t.monthly_rent-COALESCE(SUM(p.amount),0),0) outstanding_amount FROM tenants t LEFT JOIN payments p ON p.tenant_id=t.id AND p.payment_month=:month WHERE t.status='active' AND t.monthly_rent>0 GROUP BY t.id,t.full_name,t.monthly_rent,t.contact_no HAVING COALESCE(SUM(p.amount),0)<t.monthly_rent ORDER BY t.full_name");
$out->execute(['month'=>$month.'-01']);
$outstanding=$out->fetchAll();
$occupancy=$pdo->query("SELECT d.name dormitory,r.room_number,r.capacity,COUNT(t.id) occupied FROM rooms r INNER JOIN dormitories d ON d.id=r.dormitory_id LEFT JOIN tenants t ON t.room_id=r.id AND t.status='active' GROUP BY d.name,r.room_number,r.capacity ORDER BY d.name,r.room_number")->fetchAll();
$tenantItemRows=tenant_item_report($pdo,$itemStatus,$tenantStatus,$reportItemId,$reportDormitoryId);
$tenantItemCatalog=$pdo->query('SELECT id,name,is_active FROM tenant_items ORDER BY is_active DESC,name')->fetchAll();
$reportDormitories=$pdo->query('SELECT id,name FROM dormitories ORDER BY name')->fetchAll();
$tenantItemExportQuery=http_build_query(['action'=>'export','type'=>'tenant_items','item_status'=>$itemStatus,'tenant_status'=>$tenantStatus,'item_id'=>$reportItemId?:null,'dormitory_id'=>$reportDormitoryId?:null]);
$tenantVehicleRows=tenant_vehicle_report($pdo,$vehicleTenantStatus,$vehicleType,$vehicleDormitoryId,$vehicleSearch);
$tenantVehicleExportQuery=http_build_query(['action'=>'export','type'=>'tenant_vehicles','vehicle_tenant_status'=>$vehicleTenantStatus,'vehicle_type'=>$vehicleType,'vehicle_dormitory_id'=>$vehicleDormitoryId?:null,'vehicle_q'=>$vehicleSearch?:null]);
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

<section class="panel table-panel reports-panel tenant-vehicles-report" id="tenant-vehicles-report">
  <div class="report-title"><div><p class="eyebrow">Vehicle registry</p><h2>Tenant vehicles</h2></div><a class="export-action" href="reports.php?<?=e($tenantVehicleExportQuery)?>"><span aria-hidden="true">↓</span> Export filtered CSV</a></div>
  <form class="tenant-vehicle-report-filters" method="get" action="reports.php#tenant-vehicles-report">
    <label>Search<input name="vehicle_q" maxlength="100" value="<?=e($vehicleSearch)?>" placeholder="Tenant, plate, or sticker"></label>
    <label>Vehicle type<select name="vehicle_type"><option value="all" <?=$vehicleType==='all'?'selected':''?>>All types</option><?php foreach($vehicleTypes as $key=>$label):?><option value="<?=e($key)?>" <?=$vehicleType===$key?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></label>
    <label>Tenant status<select name="vehicle_tenant_status"><option value="all" <?=$vehicleTenantStatus==='all'?'selected':''?>>All tenants</option><option value="active" <?=$vehicleTenantStatus==='active'?'selected':''?>>Active</option><option value="moved_out" <?=$vehicleTenantStatus==='moved_out'?'selected':''?>>Moved out</option></select></label>
    <label>Dormitory<select name="vehicle_dormitory_id"><option value="">All dormitories</option><?php foreach($reportDormitories as $dormitory):?><option value="<?=(int)$dormitory['id']?>" <?=$vehicleDormitoryId===(int)$dormitory['id']?'selected':''?>><?=e($dormitory['name'])?></option><?php endforeach;?></select></label>
    <button type="submit">Apply filters</button>
  </form>
  <div class="table-scroll"><table><thead><tr><th>Tenant</th><th>Room</th><th>Type</th><th>Plate number</th><th>Sticker number</th><th>Registration date</th><th>Notes</th></tr></thead><tbody><?php if(!$tenantVehicleRows):?><tr><td colspan="7"><div class="report-empty">No vehicles match these filters.</div></td></tr><?php else:foreach($tenantVehicleRows as $row):?><tr><td><strong class="primary-cell"><?=e($row['full_name'])?></strong><small><?=e($row['employee_id']?:ucwords(str_replace('_',' ',$row['tenant_status'])))?></small></td><td><?=e(($row['dormitory']?:'Unassigned').($row['room_number']?' · '.$row['room_number']:''))?></td><td><?=e($vehicleTypes[$row['vehicle_type']]??ucwords($row['vehicle_type']))?></td><td><strong><?=e($row['license_plate_no'])?></strong></td><td><?=e($row['sticker_no']?:'—')?></td><td><?=e($row['registration_date']?:'—')?></td><td><?=e($row['notes']?:'—')?></td></tr><?php endforeach;endif;?></tbody></table></div>
</section>

<section class="panel table-panel reports-panel tenant-items-report" id="tenant-items-report">
  <div class="report-title"><div><p class="eyebrow">Property accountability</p><h2>Tenant items</h2></div><a class="export-action" href="reports.php?<?=e($tenantItemExportQuery)?>"><span aria-hidden="true">↓</span> Export filtered CSV</a></div>
  <form class="tenant-item-report-filters" method="get" action="reports.php#tenant-items-report">
    <label>Item<select name="item_id"><option value="">All items</option><?php foreach($tenantItemCatalog as $item):?><option value="<?=(int)$item['id']?>" <?=$reportItemId===(int)$item['id']?'selected':''?>><?=e($item['name'])?><?=(int)$item['is_active']===0?' (inactive)':''?></option><?php endforeach;?></select></label>
    <label>Item status<select name="item_status"><option value="issued" <?=$itemStatus==='issued'?'selected':''?>>Currently issued</option><option value="returned" <?=$itemStatus==='returned'?'selected':''?>>Returned</option><option value="all" <?=$itemStatus==='all'?'selected':''?>>All history</option></select></label>
    <label>Tenant status<select name="tenant_status"><option value="all" <?=$tenantStatus==='all'?'selected':''?>>All tenants</option><option value="active" <?=$tenantStatus==='active'?'selected':''?>>Active</option><option value="moved_out" <?=$tenantStatus==='moved_out'?'selected':''?>>Moved out</option></select></label>
    <label>Dormitory<select name="dormitory_id"><option value="">All dormitories</option><?php foreach($reportDormitories as $dormitory):?><option value="<?=(int)$dormitory['id']?>" <?=$reportDormitoryId===(int)$dormitory['id']?'selected':''?>><?=e($dormitory['name'])?></option><?php endforeach;?></select></label>
    <button type="submit">Apply filters</button>
  </form>
  <div class="table-scroll"><table><thead><tr><th>Tenant</th><th>Room</th><th>Item</th><th>Reference</th><th>Issued</th><th>Returned</th><th>Status</th></tr></thead><tbody><?php if(!$tenantItemRows):?><tr><td colspan="7"><div class="report-empty">No item assignments match these filters.</div></td></tr><?php else:foreach($tenantItemRows as $row):?><tr><td><strong class="primary-cell"><?=e($row['full_name'])?></strong><small><?=e($row['employee_id']?:ucwords(str_replace('_',' ',$row['tenant_status'])))?></small></td><td><?=e(($row['dormitory']?:'Unassigned').($row['room_number']?' · '.$row['room_number']:''))?></td><td><strong><?=e($row['item_name'])?></strong></td><td><?=e($row['reference_no']?:'—')?></td><td><?=e($row['issued_on'])?><small><?=e($row['issued_by']?:'Unknown user')?></small></td><td><?=e($row['returned_on']?:'—')?><?php if($row['returned_by']):?><small><?=e($row['returned_by'])?></small><?php endif;?></td><td><span class="item-state <?=$row['returned_on']?'returned':'issued'?>"><?=$row['returned_on']?'Returned':'Issued'?></span></td></tr><?php endforeach;endif;?></tbody></table></div>
</section>

<section class="panel reports-panel other-exports">
  <div class="report-title"><div><p class="eyebrow">Data library</p><h2>Other exports</h2></div></div>
  <div class="export-links"><a href="reports.php?action=export&amp;type=tenants"><span>↓</span>Export Tenant List</a><a href="reports.php?action=export&amp;type=visitors"><span>↓</span>Export Visitor Log</a><a href="reports.php?action=export&amp;type=maintenance"><span>↓</span>Export Maintenance Log</a><a href="reports.php?action=export&amp;type=schedules"><span>↓</span>Export Schedule Calendar</a></div>
</section>
<?php page_end();
