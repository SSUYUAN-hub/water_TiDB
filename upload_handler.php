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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') exit;

$name           = $_POST['name']           ?? '未知';
$start          = $_POST['start']          ?? '00:00';
$end            = $_POST['end']            ?? '00:00';
$empType        = $_POST['emp_type']       ?? 'hourly';
$wage           = (int)($_POST['hourly_rate'] ?? 180);
$hasBreak       = ($_POST['has_break']     ?? '1') === '1';
$nightPay       = (int)($_POST['night_pay']       ?? 0);
$nightAllowance = (int)($_POST['night_allowance'] ?? 0);
$date           = date('Y-m-d');

$hourlyRate    = ($empType === 'fulltime') ? round($wage / 30 / 8, 4) : $wage;
$salaryData    = calculateSalary($start, $end, $wage, $empType, $hasBreak);
$totalHours    = $salaryData['total_hours'];
$overtimeHours = $salaryData['overtime_hours'];
$overtimePay   = $salaryData['overtime_pay'] ?? 0;
$baseSalary    = ($empType === 'fulltime') ? $overtimePay : $salaryData['salary'];
$totalSalary   = $baseSalary + $nightPay;

// ══════════════════════════════════════════════════════════
//  ★ 新增：寫入 MySQL
// ══════════════════════════════════════════════════════════
$dbError = '';
try {
    saveAttendance([
        'employee_name'   => $name,
        'work_date'       => $date,
        's1_start'        => $start,
        's1_end'          => $end,
        's2_start'        => '',    // 單日辨識只有一段
        's2_end'          => '',
        'has_break'       => $hasBreak ? 1 : 0,
        'total_hours'     => $totalHours,
        'overtime_hours'  => $overtimeHours,
        'overtime_pay'    => $overtimePay,
        'night_pay'       => $nightPay,
        'salary'          => $totalSalary,
    ]);
} catch (Exception $e) {
    $dbError = $e->getMessage();
}

// ══════════════════════════════════════════════════════════
//  原有 Excel 寫入邏輯（保留不動）
// ══════════════════════════════════════════════════════════
$fileName = '員工出勤紀錄.xlsx';
$spreadsheet = new Spreadsheet(); $spreadsheet->removeSheetByIndex(0);

if ($spreadsheet->sheetNameExists($name)) {
    $sheet = $spreadsheet->getSheetByName($name);
    $lastRow = $sheet->getHighestRow() + 1;
} else {
    $sheet = $spreadsheet->createSheet(); $sheet->setTitle($name);
    if ($empType === 'fulltime') {
        $headers = $nightAllowance>0
            ? ['日期','上班','下班','有無休息','實際工時(h)','加班時數(h)','加班費($)','夜班津貼($)','加班費+津貼($)']
            : ['日期','上班','下班','有無休息','實際工時(h)','加班時數(h)','加班費($)'];
        $endCol = $nightAllowance>0 ? 'I' : 'G';
    } else {
        $headers = $nightAllowance>0
            ? ['日期','上班','下班','工作時數(h)','夜班津貼($)','當日薪資($)']
            : ['日期','上班','下班','工作時數(h)','當日薪資($)'];
        $endCol = $nightAllowance>0 ? 'F' : 'E';
    }
    foreach (range('A',$endCol) as $ci=>$col) $sheet->setCellValue($col.'1',$headers[$ci]);
    $sheet->getStyle('A1:'.$endCol.'1')->applyFromArray([
        'font'=>['bold'=>true,'color'=>['rgb'=>'FFFFFF'],'size'=>10],
        'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'1B5E20']],
        'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER],
        'borders'=>['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['rgb'=>'FFFFFF']]],
    ]);
    $sheet->getRowDimension(1)->setRowHeight(22);
    $lastRow = 2;
}

$endCol = $sheet->getHighestColumn();
if ($endCol==='A') $endCol = ($empType==='fulltime') ? ($nightAllowance>0?'I':'G') : ($nightAllowance>0?'F':'E');
$bg = ($lastRow%2===0) ? 'F1F8E9' : 'FFFFFF';

if ($empType==='fulltime') {
    $bl = $hasBreak ? '有' : '無';
    $sheet->setCellValue('A'.$lastRow,$date); $sheet->setCellValue('B'.$lastRow,$start);
    $sheet->setCellValue('C'.$lastRow,$end);  $sheet->setCellValue('D'.$lastRow,$bl);
    $sheet->setCellValue('E'.$lastRow,$totalHours); $sheet->setCellValue('F'.$lastRow,$overtimeHours);
    $sheet->setCellValue('G'.$lastRow,$overtimePay);
    if ($nightAllowance>0) { $sheet->setCellValue('H'.$lastRow,$nightPay); $sheet->setCellValue('I'.$lastRow,$totalSalary); $endCol='I'; }
    else $endCol='G';
    if (!$hasBreak) $bg='FFF3E0';
    if ($overtimeHours>0) $bg='FFFDE7';
} else {
    $sheet->setCellValue('A'.$lastRow,$date); $sheet->setCellValue('B'.$lastRow,$start);
    $sheet->setCellValue('C'.$lastRow,$end);  $sheet->setCellValue('D'.$lastRow,$totalHours);
    if ($nightAllowance>0) { $sheet->setCellValue('E'.$lastRow,$nightPay); $sheet->setCellValue('F'.$lastRow,$totalSalary); $endCol='F'; }
    else { $sheet->setCellValue('E'.$lastRow,$totalSalary); $endCol='E'; }
}
if ($nightPay>0) { $bg='F3E5F5'; }

$sheet->getStyle('A'.$lastRow.':'.$endCol.$lastRow)->applyFromArray([
    'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$bg]],
    'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER],
    'borders'=>['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['rgb'=>'CCCCCC']]],
    'font'=>['size'=>10],
]);
$sheet->getStyle($endCol.$lastRow)->applyFromArray(['font'=>['bold'=>true,'color'=>['rgb'=>'C62828']]]);
foreach (range('A',$endCol) as $col) $sheet->getColumnDimension($col)->setAutoSize(true);
(new Xlsx($spreadsheet))->save($fileName);
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>儲存成功</title>
<link rel="stylesheet" href="responsive.css">
</head>
<body>
<div class="topbar">
  <span class="topbar-title">✅ 資料已記錄</span>
  <a href="index.php" class="topbar-link">← 首頁</a>
</div>
<div class="main-wrap footer-pad">
  <div class="card">
    <div class="card-title">📋 本次紀錄摘要</div>

    <div class="emp-bar">
      <div>
        <div class="emp-name"><?php echo htmlspecialchars($name); ?></div>
        <div class="emp-meta"><?php echo $date; ?></div>
      </div>
      <span class="badge badge-<?php echo $empType; ?>">
        <?php echo $empType==='fulltime'?'正職':'時薪制'; ?>
      </span>
    </div>

    <div class="data-row"><span class="data-label">上班時間</span><span class="data-value"><?php echo $start; ?></span></div>
    <div class="data-row"><span class="data-label">下班時間</span><span class="data-value"><?php echo $end; ?></span></div>
    <?php if ($empType==='fulltime'): ?>
    <div class="data-row"><span class="data-label">休息狀況</span><span class="data-value"><?php echo $hasBreak?'✅ 有休息':'⚡ 沒休息'; ?></span></div>
    <div class="data-row"><span class="data-label">實際工時</span><span class="data-value"><?php echo $totalHours; ?> 小時</span></div>
    <div class="data-row"><span class="data-label">加班時數</span><span class="data-value ot"><?php echo $overtimeHours; ?> 小時</span></div>
    <div class="data-row"><span class="data-label">加班費</span><span class="data-value ot">$<?php echo $overtimePay; ?></span></div>
    <?php else: ?>
    <div class="data-row"><span class="data-label">工作時數</span><span class="data-value"><?php echo $totalHours; ?> 小時</span></div>
    <?php endif; ?>
    <?php if ($nightPay>0): ?>
    <div class="data-row"><span class="data-label">🌙 夜班津貼</span><span class="data-value night">$<?php echo $nightPay; ?></span></div>
    <?php endif; ?>
    <div class="data-row"><span class="data-label"><?php echo $empType==='fulltime'?'加班費合計':'當日薪資'; ?></span><span class="data-value salary">$<?php echo $totalSalary; ?></span></div>

    <!-- ★ MySQL 寫入狀態 -->
    <?php if(empty($dbError)): ?>
    <div class="msg msg-success" style="margin-top:12px">✅ 已同步寫入資料庫</div>
    <?php else: ?>
    <div class="msg msg-error" style="margin-top:12px">⚠️ 資料庫寫入失敗：<?php echo htmlspecialchars($dbError); ?></div>
    <?php endif; ?>

    <div class="btn-row" style="margin-top:18px">
      <a href="scan_upload.php" class="btn btn-primary">繼續下一筆</a>
      <a href="<?php echo $fileName; ?>" class="btn btn-blue" download>⬇ 下載 Excel</a>
    </div>
  </div>
</div>
</body>
</html>
