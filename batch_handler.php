<?php
require_once __DIR__ . '/vendor/autoload.php';
include_once __DIR__ . '/functions.php';
include_once __DIR__ . '/db.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

if ($_SERVER['REQUEST_METHOD']!=='POST') { header('Location: index.php'); exit; }

$name           = $_POST['employee_name'] ?? '未知';
$empType        = $_POST['emp_type']      ?? 'hourly';
$wage           = (int)($_POST['hourly_rate'] ?? 180);
$nightAllowance = (int)($_POST['night_allowance'] ?? 0);
$yearMonth      = $_POST['year_month']    ?? date('Y-m');
$days           = $_POST['day']           ?? [];

$hourlyRate  = ($empType==='fulltime') ? round($wage/30/8, 4) : $wage;

// ── 雙面合併：把第一面已確認資料加入 $days ──
$carrySide1Raw = $_POST['carry_side1'] ?? '';
if ($carrySide1Raw) {
    $side1Days = json_decode($carrySide1Raw, true) ?? [];
    $maxIdx = count($days);
    foreach ($side1Days as $s1day) {
        $days[$maxIdx++] = [
            'date'        => $s1day['date'],
            's1_start'    => $s1day['s1_start']    ?? '',
            's1_end'      => $s1day['s1_end']       ?? '',
            's2_start'    => $s1day['s2_start']     ?? '',
            's2_end'      => $s1day['s2_end']       ?? '',
            'has_break'   => $s1day['has_break']    ?? '1',
            'apply_night' => $s1day['apply_night']  ?? '0',
        ];
    }
}

$records=[];$totalSalary=0;$totalHoursAll=0;$totalOTHours=0;$totalOTPay=0;$totalNightPay=0;

function timeDiff2($s,$e): float {
    try { $st=new DateTime($s);$et=new DateTime($e); if($et<$st)$et->modify('+1 day'); $d=$st->diff($et); return $d->h+($d->i/60)+($d->days*24); } catch(Exception $ex){return 0;}
}
function latestTime($t1,$t2): string {
    $m1=(int)(new DateTime($t1))->format('H')*60+(int)(new DateTime($t1))->format('i');
    $m2=(int)(new DateTime($t2))->format('H')*60+(int)(new DateTime($t2))->format('i');
    if($m1<360)$m1+=1440; if($m2<360)$m2+=1440; return $m1>=$m2?$t1:$t2;
}
function calcHours(float $total, float $rate, string $type, bool $hasBreak): array {
    if($type==='hourly') return ['total_hours'=>round($total,2),'overtime_hours'=>0,'overtime_pay'=>0,'salary'=>(int)round($total*$rate)];
    $bt=($hasBreak&&$total>=8)?0.5:0; $actual=max($total-$bt,0);
    $ot1=min(max($actual-8,0),2); $ot2=max($actual-10,0);
    $otPay=$ot1*$rate*1.34+$ot2*$rate*1.67;
    return ['total_hours'=>round($actual,2),'overtime_hours'=>round($ot1+$ot2,2),'overtime_pay'=>(int)round($otPay),'salary'=>(int)round($otPay)];
}

foreach ($days as $day) {
    if (!empty($day['skip'])) continue;
    $date=$day['date']??''; $s1s=trim($day['s1_start']??''); $s1e=trim($day['s1_end']??'');
    $s2s=trim($day['s2_start']??''); $s2e=trim($day['s2_end']??''); $hasBreak=($day['has_break']??'1')==='1';
    $ymParts=explode('-',$yearMonth);
    if(!checkdate((int)$ymParts[1],(int)$date,(int)$ymParts[0])) continue;
    $h1=($s1s&&$s1e)?timeDiff2($s1s,$s1e):0; $h2=($s2s&&$s2e)?timeDiff2($s2s,$s2e):0;
    $total=$h1+$h2; if($total<=0) continue;
    $sal=calcHours($total,(float)$hourlyRate,$empType,$hasBreak);
    $latestEnd='';
    if($s1e&&$s2e) $latestEnd=latestTime($s1e,$s2e); elseif($s1e) $latestEnd=$s1e; elseif($s2e) $latestEnd=$s2e;
    $useNight=isset($day['apply_night']) ? $day['apply_night']==='1' : shouldApplyNightAllowance($latestEnd,$nightAllowance);
    if(($day['night_cancel']??'0')==='1') $useNight=false;
    $nightPay=$useNight?$nightAllowance:0;
    $daySalary=$sal['salary']+$nightPay;
    $records[]=['date'=>$yearMonth.'-'.str_pad($date,2,'0',STR_PAD_LEFT),'s1_start'=>$s1s,'s1_end'=>$s1e,'s2_start'=>$s2s,'s2_end'=>$s2e,'has_break'=>$hasBreak,'total_hours'=>$sal['total_hours'],'overtime_hours'=>$sal['overtime_hours'],'overtime_pay'=>$sal['overtime_pay'],'night_pay'=>$nightPay,'salary'=>$daySalary];
    $totalSalary+=$daySalary; $totalHoursAll+=$sal['total_hours']; $totalOTHours+=$sal['overtime_hours']; $totalOTPay+=$sal['overtime_pay']; $totalNightPay+=$nightPay;
}
if(empty($records)) die('<p style="padding:20px">沒有有效記錄。<a href="index.php">返回首頁</a></p>');

// ══════════════════════════════════════════════════════════
//  ★ 新增：寫入 MySQL（每筆記錄呼叫一次 saveAttendance）
// ══════════════════════════════════════════════════════════
$dbErrors = [];
foreach ($records as $rec) {
    try {
        saveAttendance([
            'employee_name'   => $name,
            'work_date'       => $rec['date'],
            's1_start'        => $rec['s1_start'],
            's1_end'          => $rec['s1_end'],
            's2_start'        => $rec['s2_start'],
            's2_end'          => $rec['s2_end'],
            'has_break'       => $rec['has_break'] ? 1 : 0,
            'total_hours'     => $rec['total_hours'],
            'overtime_hours'  => $rec['overtime_hours'],
            'overtime_pay'    => $rec['overtime_pay'],
            'night_pay'       => $rec['night_pay'],
            'salary'          => $rec['salary'],
        ]);
    } catch (Exception $e) {
        $dbErrors[] = $rec['date'] . '：' . $e->getMessage();
    }
}

// ══════════════════════════════════════════════════════════
//  原有 Excel 寫入邏輯（保留不動）
// ══════════════════════════════════════════════════════════
$hasNight=$nightAllowance>0; $fileName='員工出勤紀錄.xlsx';
$spreadsheet=new Spreadsheet(); $spreadsheet->removeSheetByIndex(0);

if($spreadsheet->sheetNameExists($name)) { $sheet=$spreadsheet->getSheetByName($name); $lastRow=$sheet->getHighestRow()+1; }
else {
    $sheet=$spreadsheet->createSheet(); $sheet->setTitle($name);
    if($empType==='fulltime') {
        $headers=$hasNight?['日期','第一段上班','第一段下班','第二段上班','第二段下班','有無休息','實際工時(h)','加班時數(h)','加班費($)','夜班津貼($)','加班費+津貼($)']:['日期','第一段上班','第一段下班','第二段上班','第二段下班','有無休息','實際工時(h)','加班時數(h)','加班費($)'];
        $endCol=$hasNight?'K':'I';
    } else {
        $headers=$hasNight?['日期','第一段上班','第一段下班','第二段上班','第二段下班','工作時數(h)','夜班津貼($)','當日薪資($)']:['日期','第一段上班','第一段下班','第二段上班','第二段下班','工作時數(h)','當日薪資($)'];
        $endCol=$hasNight?'H':'G';
    }
    foreach(range('A',$endCol) as $ci=>$col) $sheet->setCellValue($col.'1',$headers[$ci]);
    $sheet->getStyle('A1:'.$endCol.'1')->applyFromArray(['font'=>['bold'=>true,'color'=>['rgb'=>'FFFFFF'],'size'=>10],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'1B5E20']],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER],'borders'=>['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['rgb'=>'FFFFFF']]]]);
    $sheet->getRowDimension(1)->setRowHeight(22); $lastRow=2;
}
$endCol=$empType==='fulltime'?($hasNight?'K':'I'):($hasNight?'H':'G');

foreach ($records as $rec) {
    $bg=($lastRow%2===0)?'F1F8E9':'FFFFFF';
    if($rec['night_pay']>0) $bg='F3E5F5'; elseif(!$rec['has_break']) $bg='FFF3E0'; elseif($rec['overtime_hours']>0) $bg='FFFDE7';
    if($empType==='fulltime') {
        $bl=$rec['has_break']?'有':'無';
        foreach(['A'=>$rec['date'],'B'=>$rec['s1_start'],'C'=>$rec['s1_end'],'D'=>$rec['s2_start'],'E'=>$rec['s2_end'],'F'=>$bl,'G'=>$rec['total_hours'],'H'=>$rec['overtime_hours'],'I'=>$rec['overtime_pay']] as $c=>$v) $sheet->setCellValue($c.$lastRow,$v);
        if($hasNight){$sheet->setCellValue('J'.$lastRow,$rec['night_pay']);$sheet->setCellValue('K'.$lastRow,$rec['salary']); if($rec['night_pay']>0) $sheet->getStyle('J'.$lastRow)->applyFromArray(['font'=>['bold'=>true,'color'=>['rgb'=>'7C4DFF']]]);}
        else $sheet->setCellValue('I'.$lastRow,$rec['salary']);
    } else {
        foreach(['A'=>$rec['date'],'B'=>$rec['s1_start'],'C'=>$rec['s1_end'],'D'=>$rec['s2_start'],'E'=>$rec['s2_end'],'F'=>$rec['total_hours']] as $c=>$v) $sheet->setCellValue($c.$lastRow,$v);
        if($hasNight){$sheet->setCellValue('G'.$lastRow,$rec['night_pay']);$sheet->setCellValue('H'.$lastRow,$rec['salary']); if($rec['night_pay']>0) $sheet->getStyle('G'.$lastRow)->applyFromArray(['font'=>['bold'=>true,'color'=>['rgb'=>'7C4DFF']]]);}
        else $sheet->setCellValue('G'.$lastRow,$rec['salary']);
    }
    $sheet->getStyle('A'.$lastRow.':'.$endCol.$lastRow)->applyFromArray(['fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$bg]],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER],'borders'=>['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['rgb'=>'CCCCCC']]],'font'=>['size'=>10]]);
    $sheet->getStyle($endCol.$lastRow)->applyFromArray(['font'=>['bold'=>true,'color'=>['rgb'=>'C62828']]]);
    $lastRow++;
}
$subRow=$lastRow; $mergeEnd=$empType==='fulltime'?'F':'E';
$sheet->setCellValue('A'.$subRow,$yearMonth.' 小計'); $sheet->mergeCells('A'.$subRow.':'.$mergeEnd.$subRow);
if($empType==='fulltime'){$sheet->setCellValue('G'.$subRow,round($totalHoursAll,2));$sheet->setCellValue('H'.$subRow,round($totalOTHours,2));$sheet->setCellValue('I'.$subRow,$totalOTPay); if($hasNight){$sheet->setCellValue('J'.$subRow,$totalNightPay);$sheet->setCellValue('K'.$subRow,$totalSalary);}else $sheet->setCellValue('I'.$subRow,$totalSalary);}
else{$sheet->setCellValue('F'.$subRow,round($totalHoursAll,2)); if($hasNight){$sheet->setCellValue('G'.$subRow,$totalNightPay);$sheet->setCellValue('H'.$subRow,$totalSalary);}else $sheet->setCellValue('G'.$subRow,$totalSalary);}
$sheet->getStyle('A'.$subRow.':'.$endCol.$subRow)->applyFromArray(['font'=>['bold'=>true,'color'=>['rgb'=>'FFFFFF'],'size'=>10],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'2E7D32']],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER],'borders'=>['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['rgb'=>'FFFFFF']]]]);
$sheet->getStyle($endCol.$subRow)->applyFromArray(['font'=>['bold'=>true,'color'=>['rgb'=>'FFEB3B'],'size'=>11]]);
$sheet->getRowDimension($lastRow+1)->setRowHeight(6);
foreach(range('A',$endCol) as $col) $sheet->getColumnDimension($col)->setAutoSize(true);
(new Xlsx($spreadsheet))->save($fileName);
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>寫入成功</title>
<link rel="stylesheet" href="responsive.css">
</head>
<body>
<div class="topbar">
  <span class="topbar-title">✅ 出勤記錄已寫入</span>
  <a href="index.php" class="topbar-link">← 首頁</a>
</div>
<div class="main-wrap footer-pad">
  <div class="card">
    <div class="emp-bar">
      <div><div class="emp-name"><?php echo htmlspecialchars($name); ?></div>
           <div class="emp-meta"><?php echo $yearMonth; ?> 出勤記錄</div></div>
      <span class="badge badge-<?php echo $empType; ?>"><?php echo $empType==='fulltime'?'正職':'時薪制'; ?></span>
    </div>

    <div class="data-row"><span class="data-label">出勤天數</span><span class="data-value"><?php echo count($records); ?> 天</span></div>
    <div class="data-row"><span class="data-label">總工時</span><span class="data-value"><?php echo round($totalHoursAll,2); ?> 小時</span></div>
    <?php if($empType==='fulltime'): ?>
    <div class="data-row"><span class="data-label">總加班時數</span><span class="data-value ot"><?php echo round($totalOTHours,2); ?> 小時</span></div>
    <div class="data-row"><span class="data-label">總加班費</span><span class="data-value ot">$<?php echo $totalOTPay; ?></span></div>
    <?php endif; ?>
    <?php if($totalNightPay>0): ?>
    <div class="data-row"><span class="data-label">🌙 夜班津貼</span><span class="data-value night">$<?php echo $totalNightPay; ?></span></div>
    <?php endif; ?>
    <div class="data-row"><span class="data-label">本月合計</span><span class="data-value salary">$<?php echo $totalSalary; ?></span></div>

    <!-- ★ MySQL 寫入狀態 -->
    <?php if(empty($dbErrors)): ?>
    <div class="msg msg-success" style="margin-top:12px">
      ✅ 已同步寫入資料庫（<?php echo count($records); ?> 筆）
    </div>
    <?php else: ?>
    <div class="msg msg-error" style="margin-top:12px">
      ⚠️ 部分資料寫入資料庫失敗：<br>
      <?php foreach($dbErrors as $err) echo htmlspecialchars($err).'<br>'; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-title">📅 每日明細</div>
    <div style="overflow-x:auto">
    <table class="result-table">
      <thead><tr>
        <th>日期</th><th>第一段</th><th>第二段</th><th>工時</th>
        <?php if($empType==='fulltime'): ?><th>加班費</th><?php endif; ?>
        <?php if($hasNight): ?><th>🌙</th><?php endif; ?>
        <th><?php echo $empType==='fulltime'?'加班費':'薪資'; ?></th>
      </tr></thead>
      <tbody>
      <?php foreach($records as $r): ?>
      <tr>
        <td style="white-space:nowrap"><?php echo $r['date']; ?></td>
        <td style="white-space:nowrap"><?php echo($r['s1_start']&&$r['s1_end'])?$r['s1_start'].'→'.$r['s1_end']:'—'; ?></td>
        <td style="white-space:nowrap"><?php echo($r['s2_start']&&$r['s2_end'])?$r['s2_start'].'→'.$r['s2_end']:'—'; ?></td>
        <td><?php echo $r['total_hours']; ?>h</td>
        <?php if($empType==='fulltime'): ?>
        <td style="<?php echo $r['overtime_pay']>0?'color:var(--amber-500);font-weight:700':''; ?>">$<?php echo $r['overtime_pay']; ?></td>
        <?php endif; ?>
        <?php if($hasNight): ?>
        <td style="<?php echo $r['night_pay']>0?'color:var(--purple-600);font-weight:700':'color:var(--grey-300)'; ?>">
          <?php echo $r['night_pay']>0?'$'.$r['night_pay']:'—'; ?>
        </td>
        <?php endif; ?>
        <td style="color:var(--red-600);font-weight:700">$<?php echo $r['salary']; ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>

  <div class="btn-row">
    <a href="index.php" class="btn btn-primary">繼續下一位</a>
    <a href="<?php echo $fileName; ?>" class="btn btn-blue" download>⬇ 下載 Excel</a>
  </div>
</div>
</body>
</html>
