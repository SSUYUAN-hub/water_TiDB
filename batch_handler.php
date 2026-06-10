<?php
require_once __DIR__ . '/vendor/autoload.php';
include_once __DIR__ . '/functions.php';
include_once __DIR__ . '/db.php';
include_once __DIR__ . '/auth.php';
requireLogin();

if (session_status() === PHP_SESSION_NONE) session_start();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }

$action = $_POST['action'] ?? 'calc';

// ══════════════════════════════════════════════════════════
//  action=write：從 session 取資料寫入 DB
// ══════════════════════════════════════════════════════════
if ($action === 'write') {
    $payload = $_SESSION['confirm_payload'] ?? null;
    if (!$payload) { header('Location: scan_upload.php'); exit; }
    unset($_SESSION['confirm_payload']);

    $name      = $payload['name'];
    $empType   = $payload['emp_type'];
    $yearMonth = $payload['year_month'];
    $records   = $payload['records'];

    // 管理員可覆蓋勞健保合計；預設用公式計算值
    $insTotal      = (int)($_POST['ins_total']    ?? ($payload['labor_ins_calc'] + $payload['health_ins_calc']));
    $laborInsCalc  = (int)($payload['labor_ins_calc']  ?? 0);
    $healthInsCalc = (int)($payload['health_ins_calc'] ?? 0);

    // 實際扣款：管理員輸入的合計，依比例拆回勞健保
    // 若合計與公式相同就直接用公式值，避免浮點拆分誤差
    $calcTotal = $laborInsCalc + $healthInsCalc;
    if ($insTotal === $calcTotal || $calcTotal === 0) {
        $laborIns  = $laborInsCalc;
        $healthIns = $healthInsCalc;
    } else {
        // 按公式比例拆分，ceil 保護不足額
        $ratio     = $calcTotal > 0 ? $insTotal / $calcTotal : 0;
        $laborIns  = (int)ceil($laborInsCalc  * $ratio);
        $healthIns = $insTotal - $laborIns; // 餘額給健保，確保加總精確
    }

    $netSalary = (int)($_POST['net_salary'] ?? $payload['net_salary'] ?? 0);

    $dbErrors = [];

    // 寫入每日出勤紀錄
    foreach ($records as $rec) {
        try {
            saveAttendance([
                'employee_name'  => $name,
                'work_date'      => $rec['date'],
                's1_start'       => $rec['s1_start'],
                's1_end'         => $rec['s1_end'],
                's2_start'       => $rec['s2_start'],
                's2_end'         => $rec['s2_end'],
                'has_break'      => $rec['has_break'] ? 1 : 0,
                'total_hours'    => $rec['total_hours'],
                'overtime_hours' => $rec['overtime_hours'],
                'overtime_pay'   => $rec['overtime_pay'],
                'night_pay'      => $rec['night_pay'],
                'salary'         => $rec['salary'],
            ]);
        } catch (Exception $e) {
            $dbErrors[] = $rec['date'] . '：' . $e->getMessage();
        }
    }

    // 寫入月結扣額（僅正職）
    if ($empType === 'fulltime' && empty($dbErrors)) {
        try {
            saveMonthlyDeduction([
                'employee_name'    => $name,
                'year_month'       => $yearMonth,
                'insured_salary'   => $payload['insured_salary']   ?? 0,
                'labor_ins_rate'   => $payload['labor_ins_rate']   ?? 0,
                'labor_ins_calc'   => $laborInsCalc,
                'labor_ins'        => $laborIns,
                'health_ins_rate'  => $payload['health_ins_rate']  ?? 0,
                'health_ins_calc'  => $healthInsCalc,
                'health_ins'       => $healthIns,
                'net_salary'       => $netSalary,
                'note'             => null,
            ]);
        } catch (Exception $e) {
            $dbErrors[] = '月結扣額：' . $e->getMessage();
        }
    }

    // 結果存 session 供 confirm.php?done=1 顯示
    $_SESSION['write_result'] = [
        'name'            => $name,
        'emp_type'        => $empType,
        'year_month'      => $yearMonth,
        'records'         => $records,
        'total_hours'     => $payload['total_hours'],
        'total_ot_hours'  => $payload['total_ot_hours'],
        'total_ot_pay'    => $payload['total_ot_pay'],
        'total_night_pay' => $payload['total_night_pay'],
        'total_salary'    => $payload['total_salary'],
        'labor_ins_calc'  => $laborInsCalc,
        'health_ins_calc' => $healthInsCalc,
        'labor_ins'       => $laborIns,
        'health_ins'      => $healthIns,
        'net_salary'      => $netSalary,
        'db_errors'       => $dbErrors,
        'count'           => count($records),
    ];

    header('Location: confirm.php?done=1');
    exit;
}

// ══════════════════════════════════════════════════════════
//  action=calc（預設）：計算薪資 → session → redirect confirm.php
// ══════════════════════════════════════════════════════════
$name           = $_POST['employee_name']  ?? '未知';
$empType        = $_POST['emp_type']       ?? 'hourly';
$wage           = (int)($_POST['hourly_rate']     ?? 180);
$nightAllowance = (int)($_POST['night_allowance'] ?? 0);
$yearMonth      = $_POST['year_month']     ?? date('Y-m');
$days           = $_POST['day']            ?? [];
$hourlyRate     = ($empType === 'fulltime') ? round($wage / 30 / 8, 4) : $wage;

// 雙面合併
$carrySide1Raw = $_POST['carry_side1'] ?? '';
if ($carrySide1Raw) {
    $side1Days = json_decode($carrySide1Raw, true) ?? [];
    $maxIdx = count($days);
    foreach ($side1Days as $s1day) {
        $days[$maxIdx++] = [
            'date'        => $s1day['date'],
            's1_start'    => $s1day['s1_start']   ?? '',
            's1_end'      => $s1day['s1_end']      ?? '',
            's2_start'    => $s1day['s2_start']    ?? '',
            's2_end'      => $s1day['s2_end']      ?? '',
            'has_break'   => $s1day['has_break']   ?? '1',
            'apply_night' => $s1day['apply_night'] ?? '0',
        ];
    }
}

// 輔助函式
function timeDiff2($s, $e): float {
    try {
        $st = new DateTime($s); $et = new DateTime($e);
        if ($et < $st) $et->modify('+1 day');
        $d = $st->diff($et);
        return $d->h + ($d->i / 60) + ($d->days * 24);
    } catch (Exception $ex) { return 0; }
}
function latestTime($t1, $t2): string {
    $m1 = (int)(new DateTime($t1))->format('H') * 60 + (int)(new DateTime($t1))->format('i');
    $m2 = (int)(new DateTime($t2))->format('H') * 60 + (int)(new DateTime($t2))->format('i');
    if ($m1 < 360) $m1 += 1440;
    if ($m2 < 360) $m2 += 1440;
    return $m1 >= $m2 ? $t1 : $t2;
}
function calcHours(float $total, float $rate, string $type, bool $hasBreak): array {
    if ($type === 'hourly') {
        return ['total_hours' => round($total, 2), 'overtime_hours' => 0, 'overtime_pay' => 0, 'salary' => (int)round($total * $rate)];
    }
    $bt     = ($hasBreak && $total >= 8) ? 0.5 : 0;
    $actual = max($total - $bt, 0);
    $ot1    = min(max($actual - 8, 0), 2);
    $ot2    = max($actual - 10, 0);
    $otPay  = $ot1 * $rate * (4 / 3) + $ot2 * $rate * (5 / 3);
    return ['total_hours' => round($actual, 2), 'overtime_hours' => round($ot1 + $ot2, 2), 'overtime_pay' => (int)ceil($otPay), 'salary' => (int)ceil($otPay)];
}

// 逐日計算
$records = [];
$totalSalary = $totalHoursAll = $totalOTHours = $totalOTPay = $totalNightPay = 0;

foreach ($days as $day) {
    if (!empty($day['skip'])) continue;
    $date     = $day['date'] ?? '';
    $s1s      = trim($day['s1_start'] ?? '');
    $s1e      = trim($day['s1_end']   ?? '');
    $s2s      = trim($day['s2_start'] ?? '');
    $s2e      = trim($day['s2_end']   ?? '');
    $hasBreak = ($day['has_break'] ?? '1') === '1';
    $ymParts  = explode('-', $yearMonth);
    if (!checkdate((int)$ymParts[1], (int)$date, (int)$ymParts[0])) continue;
    $h1    = ($s1s && $s1e) ? timeDiff2($s1s, $s1e) : 0;
    $h2    = ($s2s && $s2e) ? timeDiff2($s2s, $s2e) : 0;
    $total = $h1 + $h2;
    if ($total <= 0) continue;
    $sal = calcHours($total, (float)$hourlyRate, $empType, $hasBreak);
    $latestEnd = '';
    if ($s1e && $s2e)  $latestEnd = latestTime($s1e, $s2e);
    elseif ($s1e)      $latestEnd = $s1e;
    elseif ($s2e)      $latestEnd = $s2e;
    $useNight = isset($day['apply_night'])
        ? $day['apply_night'] === '1'
        : shouldApplyNightAllowance($latestEnd, $nightAllowance);
    if (($day['night_cancel'] ?? '0') === '1') $useNight = false;
    $nightPay  = $useNight ? $nightAllowance : 0;
    $daySalary = $sal['salary'] + $nightPay;
    $records[] = [
        'date'           => $yearMonth . '-' . str_pad($date, 2, '0', STR_PAD_LEFT),
        's1_start'       => $s1s,
        's1_end'         => $s1e,
        's2_start'       => $s2s,
        's2_end'         => $s2e,
        'has_break'      => $hasBreak,
        'total_hours'    => $sal['total_hours'],
        'overtime_hours' => $sal['overtime_hours'],
        'overtime_pay'   => $sal['overtime_pay'],
        'night_pay'      => $nightPay,
        'salary'         => $daySalary,
    ];
    $totalSalary   += $daySalary;
    $totalHoursAll += $sal['total_hours'];
    $totalOTHours  += $sal['overtime_hours'];
    $totalOTPay    += $sal['overtime_pay'];
    $totalNightPay += $nightPay;
}

if (empty($records)) {
    header('Location: scan_upload.php?err=norecords');
    exit;
}

// 勞健保預算（正職才算）
$insData = [];
if ($empType === 'fulltime') {
    $rates   = getInsuranceRates();
    $insData = calcInsurance($wage, $rates);
}

$laborInsCalc  = (int)($insData['labor_ins']  ?? 0);
$healthInsCalc = (int)($insData['health_ins'] ?? 0);
$insTotal      = $laborInsCalc + $healthInsCalc;
$netSalary     = $empType === 'fulltime'
    ? ($wage + $totalOTPay + $totalNightPay - $insTotal)
    : $totalSalary;

// 存 session
$_SESSION['confirm_payload'] = [
    'name'             => $name,
    'emp_type'         => $empType,
    'wage'             => $wage,
    'year_month'       => $yearMonth,
    'records'          => $records,
    'total_hours'      => round($totalHoursAll, 2),
    'total_ot_hours'   => round($totalOTHours,  2),
    'total_ot_pay'     => $totalOTPay,
    'total_night_pay'  => $totalNightPay,
    'total_salary'     => $totalSalary,
    'insured_salary'   => $insData['labor_insured']   ?? 0,
    'labor_ins_rate'   => $insData['labor_ins_rate']  ?? 0,
    'labor_ins_share'  => $insData['labor_ins_share'] ?? 0,
    'labor_ins_calc'   => $laborInsCalc,
    'health_insured'   => $insData['health_insured']  ?? 0,
    'health_ins_rate'  => $insData['health_ins_rate'] ?? 0,
    'health_ins_share' => $insData['health_ins_share'] ?? 0,
    'health_ins_calc'  => $healthInsCalc,
    'ins_total'        => $insTotal,
    'net_salary'       => $netSalary,
];

header('Location: confirm.php');
exit;
