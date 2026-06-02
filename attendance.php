<?php
require_once __DIR__ . '/vendor/autoload.php';
include_once __DIR__ . '/functions.php';
include_once __DIR__ . '/db.php';
include_once __DIR__ . '/auth.php';

requireLogin();

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

$user         = currentUser();
$isAdmin      = isAdmin();
$staffEmpName = $isAdmin ? null : $user['employee_name'];

$message = '';
$msgType = '';

// ── Excel 輸出共用函式 ────────────────────────────────────
function outputExcel(array $rows, array $emp, string $yearMonth): void
{
    $empType        = $emp['type'];
    $nightAllowance = (int)($emp['night_allowance'] ?? 0);
    $hasNight       = $nightAllowance > 0;
    $exportEmp      = $emp['name'];

    $spreadsheet = new Spreadsheet();
    $sheet       = $spreadsheet->getActiveSheet();
    $sheet->setTitle($exportEmp);

    if ($empType === 'fulltime') {
        $headers = $hasNight
            ? ['日期', '第一段上班', '第一段下班', '第二段上班', '第二段下班', '有無休息', '實際工時(h)', '加班時數(h)', '加班費($)', '夜班津貼($)', '薪資合計($)']
            : ['日期', '第一段上班', '第一段下班', '第二段上班', '第二段下班', '有無休息', '實際工時(h)', '加班時數(h)', '加班費($)'];
        $endCol = $hasNight ? 'K' : 'I';
    } else {
        $headers = $hasNight
            ? ['日期', '第一段上班', '第一段下班', '第二段上班', '第二段下班', '工作時數(h)', '夜班津貼($)', '當日薪資($)']
            : ['日期', '第一段上班', '第一段下班', '第二段上班', '第二段下班', '工作時數(h)', '當日薪資($)'];
        $endCol = $hasNight ? 'H' : 'G';
    }

    foreach (range('A', $endCol) as $ci => $col) {
        $sheet->setCellValue($col . '1', $headers[$ci]);
    }
    $sheet->getStyle('A1:' . $endCol . '1')->applyFromArray([
        'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 14],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1B5E20']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'FFFFFF']]],
    ]);
    $sheet->getRowDimension(1)->setRowHeight(26);

    $rowNum = 2;
    $totalH = $totalOTH = $totalOTP = $totalNP = $totalS = 0;

    foreach ($rows as $r) {
        $bg = ($rowNum % 2 === 0) ? 'F1F8E9' : 'FFFFFF';
        if ($r['night_pay'] > 0)         $bg = 'F3E5F5';
        elseif (!$r['has_break'])         $bg = 'FFF3E0';
        elseif ($r['overtime_hours'] > 0) $bg = 'FFFDE7';

        $bl = $r['has_break'] ? '有' : '無';
        if ($empType === 'fulltime') {
            foreach (
                [
                    'A' => $r['work_date'],
                    'B' => fmtTime($r['s1_start']),
                    'C' => fmtTime($r['s1_end']),
                    'D' => fmtTime($r['s2_start']),
                    'E' => fmtTime($r['s2_end']),
                    'F' => $bl,
                    'G' => $r['total_hours'],
                    'H' => $r['overtime_hours'],
                    'I' => $r['overtime_pay']
                ] as $c => $v
            ) {
                $sheet->setCellValue($c . $rowNum, $v);
            }
            if ($hasNight) {
                $sheet->setCellValue('J' . $rowNum, $r['night_pay']);
                $sheet->setCellValue('K' . $rowNum, $r['salary']);
                if ($r['night_pay'] > 0) $sheet->getStyle('J' . $rowNum)->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => '7C4DFF']]]);
            } else {
                $sheet->setCellValue('I' . $rowNum, $r['salary']);
            }
        } else {
            foreach (
                [
                    'A' => $r['work_date'],
                    'B' => fmtTime($r['s1_start']),
                    'C' => fmtTime($r['s1_end']),
                    'D' => fmtTime($r['s2_start']),
                    'E' => fmtTime($r['s2_end']),
                    'F' => $r['total_hours']
                ] as $c => $v
            ) {
                $sheet->setCellValue($c . $rowNum, $v);
            }
            if ($hasNight) {
                $sheet->setCellValue('G' . $rowNum, $r['night_pay']);
                $sheet->setCellValue('H' . $rowNum, $r['salary']);
                if ($r['night_pay'] > 0) $sheet->getStyle('G' . $rowNum)->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => '7C4DFF']]]);
            } else {
                $sheet->setCellValue('G' . $rowNum, $r['salary']);
            }
        }
        $sheet->getStyle('A' . $rowNum . ':' . $endCol . $rowNum)->applyFromArray([
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bg]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CCCCCC']]],
            'font'      => ['size' => 12],
        ]);
        $sheet->getStyle($endCol . $rowNum)->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => 'C62828'], 'size' => 12]]);
        $totalH += $r['total_hours'];
        $totalOTH += $r['overtime_hours'];
        $totalOTP += $r['overtime_pay'];
        $totalNP += $r['night_pay'];
        $totalS += $r['salary'];
        $rowNum++;
    }

    $subRow = $rowNum;
    $mergeEnd = $empType === 'fulltime' ? 'F' : 'E';
    $sheet->setCellValue('A' . $subRow, $yearMonth . ' 小計');
    $sheet->mergeCells('A' . $subRow . ':' . $mergeEnd . $subRow);
    if ($empType === 'fulltime') {
        $sheet->setCellValue('G' . $subRow, round($totalH, 2));
        $sheet->setCellValue('H' . $subRow, round($totalOTH, 2));
        if ($hasNight) {
            $sheet->setCellValue('J' . $subRow, $totalNP);
            $sheet->setCellValue('K' . $subRow, $totalS);
        } else {
            $sheet->setCellValue('I' . $subRow, $totalS);
        }
    } else {
        $sheet->setCellValue('F' . $subRow, round($totalH, 2));
        if ($hasNight) {
            $sheet->setCellValue('G' . $subRow, $totalNP);
            $sheet->setCellValue('H' . $subRow, $totalS);
        } else {
            $sheet->setCellValue('G' . $subRow, $totalS);
        }
    }
    $sheet->getStyle('A' . $subRow . ':' . $endCol . $subRow)->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 14],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2E7D32']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'FFFFFF']]],
    ]);
    $sheet->getStyle($endCol . $subRow)->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => 'FFEB3B'], 'size' => 14]]);
    foreach (range('A', $endCol) as $col) $sheet->getColumnDimension($col)->setAutoSize(true);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $exportEmp . '_' . $yearMonth . '_出勤紀錄.xlsx"');
    header('Cache-Control: max-age=0');
    (new Xlsx($spreadsheet))->save('php://output');
    exit;
}

// ── 共用：寫一個員工的明細 sheet ────────────────────────
function writeEmpSheet(Spreadsheet $spreadsheet, string $sheetTitle, array $rows, array $emp, string $periodLabel): void
{
    $empType = $emp['type'] ?? 'hourly';
    $isFulltime = $empType === 'fulltime';

    // 若同名 sheet 已存在，先移除
    if ($spreadsheet->sheetNameExists($sheetTitle)) {
        $idx = $spreadsheet->getIndex($spreadsheet->getSheetByName($sheetTitle));
        $spreadsheet->removeSheetByIndex($idx);
    }
    $sheet = $spreadsheet->createSheet();
    $sheet->setTitle(mb_substr($sheetTitle, 0, 31));

    // 標題列
    $headers = ['姓名', '班別', '上班日期', '上班時數(h)', '加班時數(h)', '當日薪資($)'];
    $endCol  = 'F';
    foreach ($headers as $ci => $h) {
        $sheet->setCellValue(chr(65 + $ci) . '1', $h);
    }
    $sheet->getStyle('A1:F1')->applyFromArray([
        'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 14],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1B5E20']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'FFFFFF']]],
    ]);
    $sheet->getRowDimension(1)->setRowHeight(26);

    $rowNum = 2;
    $totalH = $totalOTH = $totalS = 0;
    $typeLabel = $isFulltime ? '正職' : 'PT';

    foreach ($rows as $r) {
        $bg = ($rowNum % 2 === 0) ? 'F1F8E9' : 'FFFFFF';
        if (($r['overtime_hours'] ?? 0) > 0) $bg = 'FFFDE7';

        $sheet->setCellValue('A' . $rowNum, $emp['name']);
        $sheet->setCellValue('B' . $rowNum, $typeLabel);
        $sheet->setCellValue('C' . $rowNum, $r['work_date']);
        $sheet->setCellValue('D' . $rowNum, $r['total_hours']);
        $sheet->setCellValue('E' . $rowNum, $r['overtime_hours'] ?? 0);
        $sheet->setCellValue('F' . $rowNum, $r['salary']);

        $sheet->getStyle('A' . $rowNum . ':F' . $rowNum)->applyFromArray([
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bg]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CCCCCC']]],
            'font'      => ['size' => 12],
        ]);
        $sheet->getStyle('F' . $rowNum)->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => 'C62828'], 'size' => 12]]);

        $totalH   += $r['total_hours'];
        $totalOTH += $r['overtime_hours'] ?? 0;
        $totalS   += $r['salary'];
        $rowNum++;
    }

    // 合計列
    $subRow = $rowNum;
    $sheet->setCellValue('A' . $subRow, $periodLabel . ' 合計');
    $sheet->mergeCells('A' . $subRow . ':C' . $subRow);
    $sheet->setCellValue('D' . $subRow, round($totalH, 2));
    $sheet->setCellValue('E' . $subRow, round($totalOTH, 2));
    $sheet->setCellValue('F' . $subRow, $totalS);
    $sheet->getStyle('A' . $subRow . ':F' . $subRow)->applyFromArray([
        'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 14],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2E7D32']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'FFFFFF']]],
    ]);
    $sheet->getStyle('F' . $subRow)->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => 'FFEB3B'], 'size' => 14]]);
    foreach (range('A', 'F') as $col) $sheet->getColumnDimension($col)->setAutoSize(true);
}

// ── 年份匯出：每月一個 sheet，所有員工混在同一 sheet ────
function outputExcelYear(string $year, array $monthlyData, array $allEmps): void
{
    $spreadsheet = new Spreadsheet();
    $spreadsheet->removeSheetByIndex(0);

    $empMap = [];
    foreach ($allEmps as $e) $empMap[$e['name']] = $e;

    foreach ($monthlyData as $ym => $empRows) {
        // 若同名 sheet 已存在移除
        if ($spreadsheet->sheetNameExists($ym)) {
            $idx = $spreadsheet->getIndex($spreadsheet->getSheetByName($ym));
            $spreadsheet->removeSheetByIndex($idx);
        }
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($ym);

        $headers = ['姓名', '班別', '上班日期', '上班時數(h)', '加班時數(h)', '當日薪資($)'];
        foreach ($headers as $ci => $h) $sheet->setCellValue(chr(65 + $ci) . '1', $h);
        $sheet->getStyle('A1:F1')->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 14],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1B5E20']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'FFFFFF']]],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(26);

        $rowNum = 2;
        $grandH = $grandOTH = $grandS = 0;

        foreach ($empRows as $empName => $rows) {
            $emp = $empMap[$empName] ?? ['name' => $empName, 'type' => 'hourly'];
            $typeLabel = ($emp['type'] === 'fulltime') ? '正職' : 'PT';
            $subH = $subOTH = $subS = 0;

            foreach ($rows as $r) {
                $bg = ($rowNum % 2 === 0) ? 'F1F8E9' : 'FFFFFF';
                if (($r['overtime_hours'] ?? 0) > 0) $bg = 'FFFDE7';

                $sheet->setCellValue('A' . $rowNum, $empName);
                $sheet->setCellValue('B' . $rowNum, $typeLabel);
                $sheet->setCellValue('C' . $rowNum, $r['work_date']);
                $sheet->setCellValue('D' . $rowNum, $r['total_hours']);
                $sheet->setCellValue('E' . $rowNum, $r['overtime_hours'] ?? 0);
                $sheet->setCellValue('F' . $rowNum, $r['salary']);

                $sheet->getStyle('A' . $rowNum . ':F' . $rowNum)->applyFromArray([
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bg]],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CCCCCC']]],
                    'font'      => ['size' => 12],
                ]);
                $sheet->getStyle('F' . $rowNum)->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => 'C62828'], 'size' => 12]]);

                $subH   += $r['total_hours'];
                $subOTH += $r['overtime_hours'] ?? 0;
                $subS   += $r['salary'];
                $rowNum++;
            }

            // 員工小計列
            $sheet->setCellValue('A' . $rowNum, $empName . ' 小計');
            $sheet->mergeCells('A' . $rowNum . ':C' . $rowNum);
            $sheet->setCellValue('D' . $rowNum, round($subH, 2));
            $sheet->setCellValue('E' . $rowNum, round($subOTH, 2));
            $sheet->setCellValue('F' . $rowNum, $subS);
            $sheet->getStyle('A' . $rowNum . ':F' . $rowNum)->applyFromArray([
                'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 14],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '388E3C']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'FFFFFF']]],
            ]);
            $sheet->getStyle('F' . $rowNum)->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => 'FFEB3B'], 'size' => 14]]);
            $rowNum++;

            $grandH += $subH;
            $grandOTH += $subOTH;
            $grandS += $subS;
        }

        // 月份總計列
        $sheet->setCellValue('A' . $rowNum, $ym . ' 總計');
        $sheet->mergeCells('A' . $rowNum . ':C' . $rowNum);
        $sheet->setCellValue('D' . $rowNum, round($grandH, 2));
        $sheet->setCellValue('E' . $rowNum, round($grandOTH, 2));
        $sheet->setCellValue('F' . $rowNum, $grandS);
        $sheet->getStyle('A' . $rowNum . ':F' . $rowNum)->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 14],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1B5E20']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'FFFFFF']]],
        ]);
        $sheet->getStyle('F' . $rowNum)->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => 'FFEB3B'], 'size' => 14]]);
        foreach (range('A', 'F') as $col) $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $year . '_年度出勤紀錄.xlsx"');
    header('Cache-Control: max-age=0');
    (new Xlsx($spreadsheet))->save('php://output');
    exit;
}

// ── 月份匯出：每人一個 sheet ─────────────────────────────
function outputExcelMonthAll(string $yearMonth, array $empGrouped, array $allEmps): void
{
    $spreadsheet = new Spreadsheet();
    $spreadsheet->removeSheetByIndex(0);

    $empMap = [];
    foreach ($allEmps as $e) $empMap[$e['name']] = $e;

    foreach ($empGrouped as $empName => $rows) {
        $emp = $empMap[$empName] ?? ['name' => $empName, 'type' => 'hourly'];
        writeEmpSheet($spreadsheet, $empName, $rows, $emp, $yearMonth);
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $yearMonth . '_月份出勤紀錄.xlsx"');
    header('Cache-Control: max-age=0');
    (new Xlsx($spreadsheet))->save('php://output');
    exit;
}

// ══════════════════════════════════════════════════════════
//  POST 處理
// ══════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 修改自己的密碼（所有登入使用者）
    if ($action === 'change_password') {
        $oldPass  = $_POST['old_password']  ?? '';
        $newPass  = $_POST['new_password']  ?? '';
        $newPass2 = $_POST['new_password2'] ?? '';
        if (empty($oldPass) || empty($newPass) || empty($newPass2)) {
            $message = '請填寫所有欄位';
            $msgType = 'error';
        } elseif ($newPass !== $newPass2) {
            $message = '新密碼兩次輸入不一致';
            $msgType = 'error';
        } elseif (strlen($newPass) < 6) {
            $message = '新密碼至少需要 6 個字元';
            $msgType = 'error';
        } else {
            $stmt = getDB()->prepare('SELECT password_hash FROM users WHERE id = ?');
            $stmt->execute([$user['id']]);
            $row = $stmt->fetch();
            if (!$row || !password_verify($oldPass, $row['password_hash'])) {
                $message = '目前密碼輸入錯誤';
                $msgType = 'error';
            } else {
                $hashedNew = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
                $upd = getDB()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                $upd->execute([$hashedNew, $user['id']]);
                $message = '✅ 密碼已成功修改';
                $msgType = 'success';
            }
        }
    }

    // 匯出（管理員 + 員工都可以）
    if ($action === 'export_db') {
        $exportEmp = $isAdmin ? ($_POST['export_emp'] ?? '') : $staffEmpName;
        // 員工身分二次驗證：確保匯出的員工名稱與 session 綁定的一致
        if (!$isAdmin && $exportEmp !== $staffEmpName) {
            $message = '權限不足';
            $msgType = 'error';
        } else {
            $exportYM  = $_POST['export_ym'] ?? date('Y-m');
            $rows      = getAttendanceByMonth($exportEmp, $exportYM);
            $emp       = getEmployee($exportEmp);
            if (!$emp || empty($rows)) {
                $message = '此月份無出勤紀錄';
                $msgType = 'error';
            } else outputExcel($rows, $emp, $exportYM);
        }
    }

    // 年份匯出（管理員限定）
    if ($action === 'export_year' && $isAdmin) {
        $exportYear = $_POST['export_year'] ?? date('Y');
        $allEmps    = getEmployees();
        $monthlyData = getAllEmployeesAttendanceByYear($exportYear);
        if (empty($monthlyData)) {
            $message = "{$exportYear} 年無出勤紀錄";
            $msgType = 'error';
        } else outputExcelYear($exportYear, $monthlyData, $allEmps);
    }

    // 月份全員匯出（管理員限定）
    if ($action === 'export_month_all' && $isAdmin) {
        $exportYM   = $_POST['export_ym_all'] ?? date('Y-m');
        $allEmps    = getEmployees();
        $empGrouped = getAllEmployeesAttendanceByMonth($exportYM);
        if (empty($empGrouped)) {
            $message = "{$exportYM} 無出勤紀錄";
            $msgType = 'error';
        } else outputExcelMonthAll($exportYM, $empGrouped, $allEmps);
    }

    // 以下僅限管理員
    if ($isAdmin) {
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            try {
                $stmt = getDB()->prepare('DELETE FROM attendance WHERE id = ?');
                $stmt->execute([$id]);
                $message = $stmt->rowCount() > 0 ? '🗑️ 已刪除該筆出勤紀錄' : '找不到該筆紀錄';
                $msgType = $stmt->rowCount() > 0 ? 'success' : 'error';
            } catch (PDOException $e) {
                $message = '刪除失敗：' . $e->getMessage();
                $msgType = 'error';
            }
        }

        if ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            $s1Start = trim($_POST['s1_start'] ?? '');
            $s1End = trim($_POST['s1_end'] ?? '');
            $s2Start = trim($_POST['s2_start'] ?? '');
            $s2End = trim($_POST['s2_end'] ?? '');
            $hasBreak = ($_POST['has_break'] ?? '0') === '1';
            $nightPay = (int)($_POST['night_pay'] ?? 0);

            $rec = getDB()->prepare('SELECT * FROM attendance WHERE id = ?');
            $rec->execute([$id]);
            $row = $rec->fetch();

            if ($row) {
                $emp = getEmployee($row['employee_name']);
                $empType = $emp['type'] ?? 'hourly';
                $wage = (int)($emp['hourly_rate'] ?? 180);
                $sal1 = ($s1Start && $s1End) ? calculateSalary($s1Start, $s1End, $wage, $empType, $hasBreak) : ['total_hours' => 0, 'overtime_hours' => 0, 'overtime_pay' => 0, 'salary' => 0];
                $sal2 = ($s2Start && $s2End) ? calculateSalary($s2Start, $s2End, $wage, $empType, false)    : ['total_hours' => 0, 'overtime_hours' => 0, 'overtime_pay' => 0, 'salary' => 0];
                $totalHours = round($sal1['total_hours'] + $sal2['total_hours'], 2);
                $overtimeHours = round($sal1['overtime_hours'] + $sal2['overtime_hours'], 2);
                $overtimePay = $sal1['overtime_pay'] + $sal2['overtime_pay'];
                $baseSalary = ($empType === 'fulltime') ? $overtimePay : ($sal1['salary'] + $sal2['salary']);
                $totalSalary = $baseSalary + $nightPay;
                try {
                    $stmt = getDB()->prepare('UPDATE attendance SET s1_start=:s1s,s1_end=:s1e,s2_start=:s2s,s2_end=:s2e,has_break=:hb,total_hours=:th,overtime_hours=:oth,overtime_pay=:otp,night_pay=:np,salary=:sal WHERE id=:id');
                    $stmt->execute([':s1s' => $s1Start ?: null, ':s1e' => $s1End ?: null, ':s2s' => $s2Start ?: null, ':s2e' => $s2End ?: null, ':hb' => $hasBreak ? 1 : 0, ':th' => $totalHours, ':oth' => $overtimeHours, ':otp' => $overtimePay, ':np' => $nightPay, ':sal' => $totalSalary, ':id' => $id]);
                    $message = '✅ 已更新並重新計算薪資';
                    $msgType = 'success';
                } catch (PDOException $e) {
                    $message = '更新失敗：' . $e->getMessage();
                    $msgType = 'error';
                }
            } else {
                $message = '找不到該筆紀錄';
                $msgType = 'error';
            }
        }
    }
}

// ══════════════════════════════════════════════════════════
//  GET 查詢
// ══════════════════════════════════════════════════════════
$employees  = getEmployees();
// 員工身分：強制鎖定自己的 employee_name，忽略 URL 參數
$selEmp     = $isAdmin ? ($_GET['emp'] ?? ($employees[0]['name'] ?? '')) : $staffEmpName;
// 員工帳號若沒有對應員工姓名（employee_name 為空），導向提示
if (!$isAdmin && empty($selEmp)) {
    $message = '您的帳號尚未綁定員工資料，請聯絡管理員';
    $msgType = 'error';
}
$queryMode  = $_GET['mode'] ?? 'month';
$selYear    = $_GET['year'] ?? date('Y');
$selYM      = $_GET['ym']   ?? date('Y-m');

// 只有明確按下查詢（URL 帶有 searched=1）才查資料
// edit_id 存在時也視同已查詢，確保編輯面板能正常顯示
$searched = isset($_GET['searched']) || isset($_GET['edit_id']);

$yearGrouped  = [];
$attendances  = [];
$monthSummary = [];

if ($searched) {
    if ($queryMode === 'year') {
        $yearGrouped = ($selEmp && $selYear) ? getAttendanceByYear($selEmp, $selYear) : [];
        $allRows = array_merge(...(array_values($yearGrouped) ?: [[]]));
        if (!empty($allRows)) {
            $monthSummary = [
                'work_days'      => count($allRows),
                'total_hours'    => round(array_sum(array_column($allRows, 'total_hours')), 2),
                'overtime_hours' => round(array_sum(array_column($allRows, 'overtime_hours')), 2),
                'overtime_pay'   => array_sum(array_column($allRows, 'overtime_pay')),
                'night_pay'      => array_sum(array_column($allRows, 'night_pay')),
                'total_salary'   => array_sum(array_column($allRows, 'salary')),
            ];
            $attendances = $allRows;
        }
    } else {
        $attendances = ($selEmp && $selYM) ? getAttendanceByMonth($selEmp, $selYM) : [];
        if (!empty($attendances)) {
            $monthSummary = [
                'work_days'      => count($attendances),
                'total_hours'    => round(array_sum(array_column($attendances, 'total_hours')), 2),
                'overtime_hours' => round(array_sum(array_column($attendances, 'overtime_hours')), 2),
                'overtime_pay'   => array_sum(array_column($attendances, 'overtime_pay')),
                'night_pay'      => array_sum(array_column($attendances, 'night_pay')),
                'total_salary'   => array_sum(array_column($attendances, 'salary')),
            ];
        }
    }
}

$selEmpData    = getEmployee($selEmp);
$selEmpType    = $selEmpData['type']            ?? 'hourly';
$selNightAllow = (int)($selEmpData['night_allowance'] ?? 0);

$editRow = null;
if ($isAdmin && isset($_GET['edit_id'])) {
    $editStmt = getDB()->prepare('SELECT * FROM attendance WHERE id = ?');
    $editStmt->execute([(int)$_GET['edit_id']]);
    $editRow = $editStmt->fetch();
    // 確認此紀錄屬於管理員有權存取的範圍（任何員工皆可）
}
?>
<!DOCTYPE html>
<html lang="zh-TW">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>出勤查詢 — 打卡辨識系統</title>
    <link rel="stylesheet" href="responsive.css">
    <style>
        .main-wrap {
            max-width: 900px;
        }

        /* ── 漢堡選單：手機版向下滑出 dropdown ── */
        .topbar { position: relative; }
        @media (max-width: 540px) {
            .topbar-nav {
                display: none;
                position: absolute;
                top: 100%; right: 0;
                min-width: 160px;
                background: var(--green-800, #2e7d32);
                border-radius: 0 0 10px 10px;
                box-shadow: 0 6px 20px rgba(0,0,0,0.25);
                flex-direction: column;
                padding: 6px 0;
                z-index: 200;
            }
            .topbar-nav.open { display: flex; }
            .topbar-nav .topbar-link {
                display: block; width: 100%;
                text-align: left; padding: 10px 18px;
                border-radius: 0; background: transparent;
                box-sizing: border-box; white-space: nowrap;
            }
            .topbar-nav .topbar-link:hover { background: rgba(255,255,255,0.12); }
        }

        .filter-bar {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: flex-end;
            background: white;
            border-radius: var(--radius-md);
            padding: 16px;
            box-shadow: var(--card-shadow);
            margin-bottom: 14px;
        }
        .fg-ym { /* 年月區塊標記 */ }
        .fg-year-sel { /* 年份區塊標記 */ }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .filter-group label {
            font-size: 0.75em;
            color: var(--grey-500);
            font-weight: 600;
        }

        .filter-group select,
        .filter-group input[type="month"] {
            padding: 9px 12px;
            border: 1.5px solid var(--grey-300);
            border-radius: var(--radius-sm);
            font-size: 0.9em;
            font-family: var(--font-body);
            color: var(--grey-900);
            background: white;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            gap: 10px;
            margin-bottom: 14px;
        }

        .summary-cell {
            background: white;
            border-radius: var(--radius-md);
            padding: 14px 16px;
            box-shadow: var(--card-shadow);
            text-align: center;
        }

        .summary-cell .s-label {
            font-size: 0.75em;
            color: var(--grey-500);
            margin-bottom: 4px;
        }

        .summary-cell .s-value {
            font-size: 1.2em;
            font-weight: 700;
            font-family: var(--font-num);
            color: var(--grey-900);
        }

        .summary-cell.salary .s-value {
            color: var(--red-600);
        }

        .summary-cell.ot .s-value {
            color: var(--amber-500);
        }

        .summary-cell.night .s-value {
            color: var(--purple-600);
        }

        .att-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85em;
        }

        .att-table th {
            background: var(--green-50);
            color: var(--green-700);
            padding: 9px 10px;
            text-align: center;
            border: 1px solid #C8E6C9;
            font-weight: 600;
            white-space: nowrap;
        }

        .att-table td {
            padding: 8px 10px;
            text-align: center;
            border: 1px solid #eee;
            white-space: nowrap;
        }

        .att-table tr:nth-child(even) td {
            background: #FAFAFA;
        }

        .att-table tr:hover td {
            background: #F1F8E9;
        }

        .salary-cell {
            color: var(--red-600);
            font-weight: 700;
        }

        .ot-cell {
            color: var(--amber-500);
            font-weight: 600;
        }

        .night-cell {
            color: var(--purple-600);
            font-weight: 600;
        }

        .action-cell {
            display: flex;
            gap: 6px;
            justify-content: center;
        }

        .edit-panel {
            background: #EDE7F6;
            border: 2px solid #B39DDB;
            border-radius: var(--radius-md);
            padding: 18px;
            margin-bottom: 14px;
        }

        .edit-panel .panel-title {
            font-size: 0.9em;
            font-weight: 700;
            color: var(--purple-600);
            margin-bottom: 14px;
        }

        .edit-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 10px;
        }

        .edit-field {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .edit-field label {
            font-size: 0.75em;
            color: var(--grey-500);
            font-weight: 600;
        }

        .edit-field input,
        .edit-field select {
            padding: 9px 10px;
            border: 1.5px solid #B39DDB;
            border-radius: var(--radius-sm);
            font-size: 0.9em;
            font-family: var(--font-body);
            background: white;
            color: var(--grey-900);
        }

        .empty-box {
            background: white;
            border-radius: var(--radius-md);
            padding: 40px;
            text-align: center;
            color: var(--grey-500);
            box-shadow: var(--card-shadow);
        }

        @media(max-width:600px) {
            .filter-bar {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }
            .filter-bar .filter-group { min-width: 0; }
            /* 年月欄位橫跨整行，讓兩個 select 不被擠壓 */
            .filter-bar .fg-ym { grid-column: 1 / -1; }
            /* 年份查詢欄也橫跨整行 */
            .filter-bar .fg-year-sel { grid-column: 1 / -1; }

            .summary-grid {
                grid-template-columns: repeat(2, 1fr)
            }

            .att-table {
                font-size: 0.78em
            }

            .att-table td,
            .att-table th {
                padding: 6px
            }
        }
    </style>
</head>

<body>
    <div class="topbar">
      <div class="topbar-inner">
        <span class="topbar-title">📊 出勤查詢</span>
        <button class="topbar-burger" onclick="toggleNav(this)" aria-label="選單">
          <span></span><span></span><span></span>
        </button>
        <nav class="topbar-nav" id="topbar-nav">
          <span class="topbar-link" style="background:rgba(255,255,255,0.1);cursor:default">
            <?php echo htmlspecialchars(displayName()); ?>
          </span>
          <a href="index.php" class="topbar-link">🏠 首頁</a>
          <a href="logout.php" class="topbar-link">登出</a>
        </nav>
      </div>
    </div>

    <div class="main-wrap footer-pad" style="margin-top:14px">

        <?php if (!empty($message)): ?>
            <div class="msg msg-<?php echo $msgType === 'success' ? 'success' : 'error'; ?>" style="margin-bottom:14px">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- 模式切換（管理員才有年份模式） -->
        <?php if ($isAdmin): ?>
            <div class="mode-tabs" style="margin-bottom:10px">
                <button type="button" class="mode-tab <?php echo $queryMode === 'month' ? 'active' : ''; ?>"
                    onclick="switchQueryMode('month')">📅 月份查詢</button>
                <button type="button" class="mode-tab <?php echo $queryMode === 'year' ? 'active' : ''; ?>"
                    onclick="switchQueryMode('year')">📆 年份查詢</button>
            </div>
        <?php endif; ?>

        <!-- 篩選列 -->
        <form method="get" class="filter-bar" id="filter-form">
            <input type="hidden" name="mode" id="query-mode-input" value="<?php echo $queryMode; ?>">

            <?php if ($isAdmin): ?>
                <div class="filter-group">
                    <label>👤 員工</label>
                    <select name="emp">
                        <?php foreach ($employees as $e): ?>
                            <option value="<?php echo htmlspecialchars($e['name']); ?>" <?php echo $e['name'] === $selEmp ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($e['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php else: ?>
                <div class="filter-group">
                    <label>👤 員工</label>
                    <div style="padding:9px 12px;background:var(--green-50);border:1.5px solid #A5D6A7;border-radius:var(--radius-sm);font-size:0.9em;font-weight:600;color:var(--green-700)">
                        <?php echo htmlspecialchars($selEmp); ?>
                    </div>
                    <input type="hidden" name="emp" value="<?php echo htmlspecialchars($selEmp); ?>">
                </div>
            <?php endif; ?>

            <!-- 月份選擇（月份模式） -->
            <div class="filter-group fg-ym" id="fg-month" <?php echo $queryMode === 'year' ? 'style="display:none"' : ''; ?>>
                <label>📅 年月</label>
                <div style="display:flex;gap:4px;align-items:center">
                    <?php
                    $ymYear  = (int)explode('-', $selYM)[0];
                    $ymMonth = (int)explode('-', $selYM)[1];
                    ?>
                    <select id="ym-year-sel" style="padding:9px 8px;border:1.5px solid var(--grey-300);border-radius:var(--radius-sm);font-size:0.9em;font-family:var(--font-body);color:var(--grey-900);background:white" onchange="syncYmInput()">
                        <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y === $ymYear ? 'selected' : ''; ?>><?php echo ($y - 1911); ?> 年</option>
                        <?php endfor; ?>
                    </select>
                    <select id="ym-month-sel" style="padding:9px 8px;border:1.5px solid var(--grey-300);border-radius:var(--radius-sm);font-size:0.9em;font-family:var(--font-body);color:var(--grey-900);background:white" onchange="syncYmInput()">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $m === $ymMonth ? 'selected' : ''; ?>><?php echo $m; ?> 月</option>
                        <?php endfor; ?>
                    </select>
                    <!-- 隱藏欄位傳送 ym=YYYY-MM -->
                    <input type="hidden" name="ym" id="ym-hidden" value="<?php echo $selYM; ?>">
                </div>
            </div>

            <!-- 年份選擇（年份模式） -->
            <div class="filter-group fg-year-sel" id="fg-year" <?php echo $queryMode === 'month' ? 'style="display:none"' : ''; ?>>
                <label>📆 年份</label>
                <select name="year">
                    <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                        <option value="<?php echo $y; ?>" <?php echo $y == (int)$selYear ? 'selected' : ''; ?>><?php echo ($y - 1911); ?> 年</option>
                    <?php endfor; ?>
                </select>
            </div>

            <div class="filter-group" style="justify-content:flex-end">
                <label>&nbsp;</label>
                <button type="submit" class="btn btn-primary" style="min-height:40px"
                    onclick="document.getElementById('searched-input').value='1'">🔍 查詢</button>
            </div>
            <input type="hidden" name="searched" id="searched-input" value="<?php echo $searched ? '1' : ''; ?>">

            <?php if (!empty($attendances)): ?>
                <div class="filter-group" style="justify-content:flex-end;margin-left:auto">
                    <label>&nbsp;</label>
                    <button type="submit" form="export-form" class="btn btn-blue" style="min-height:40px">⬇ 個人 Excel</button>
                </div>
            <?php endif; ?>

            <?php if ($isAdmin): ?>
                <div class="filter-group" style="justify-content:flex-end">
                    <label>&nbsp;</label>
                    <?php if ($queryMode === 'year'): ?>
                        <button type="submit" form="export-year-form" class="btn btn-purple" style="min-height:40px">📊 全員年報</button>
                    <?php else: ?>
                        <button type="submit" form="export-month-all-form" class="btn btn-purple" style="min-height:40px">📊 全員月報</button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </form>

        <!-- 隱藏匯出 forms -->
        <?php if (!empty($attendances)): ?>
            <form id="export-form" method="post" style="display:none">
                <input type="hidden" name="action" value="export_db">
                <input type="hidden" name="export_emp" value="<?php echo htmlspecialchars($selEmp); ?>">
                <input type="hidden" name="export_ym" value="<?php echo $selYM; ?>">
            </form>
        <?php endif; ?>

        <?php if ($isAdmin): ?>
            <form id="export-year-form" method="post" style="display:none">
                <input type="hidden" name="action" value="export_year">
                <input type="hidden" name="export_year" value="<?php echo htmlspecialchars($selYear); ?>">
            </form>
            <form id="export-month-all-form" method="post" style="display:none">
                <input type="hidden" name="action" value="export_month_all">
                <input type="hidden" name="export_ym_all" value="<?php echo htmlspecialchars($selYM); ?>">
            </form>
        <?php endif; ?>

        <?php if (!$searched): ?>
            <div class="empty-box">
                <div style="font-size:2em;margin-bottom:10px">🔍</div>
                <div style="color:var(--grey-500)">請選擇員工與查詢條件後，按下查詢按鈕</div>
            </div>

        <?php elseif (empty($attendances)): ?>
            <div class="empty-box" id="results-area">
                <div style="font-size:2em;margin-bottom:10px">📭</div>
                <div><?php echo htmlspecialchars($selEmp); ?> 在 <?php echo $queryMode === 'year' ? ((int)$selYear - 1911) . '年' : $selYM; ?> 沒有出勤紀錄</div>
            </div>

        <?php else: ?>
            <div id="results-area">

                <!-- 月份摘要 -->
                <div class="summary-grid">
                    <div class="summary-cell">
                        <div class="s-label">出勤天數</div>
                        <div class="s-value"><?php echo $monthSummary['work_days']; ?> 天</div>
                    </div>
                    <div class="summary-cell">
                        <div class="s-label">總工時</div>
                        <div class="s-value"><?php echo $monthSummary['total_hours']; ?> h</div>
                    </div>
                    <?php if ($selEmpType === 'fulltime'): ?>
                        <div class="summary-cell ot">
                            <div class="s-label">加班時數</div>
                            <div class="s-value"><?php echo $monthSummary['overtime_hours']; ?> h</div>
                        </div>
                        <div class="summary-cell ot">
                            <div class="s-label">加班費合計</div>
                            <div class="s-value">$<?php echo number_format($monthSummary['overtime_pay']); ?></div>
                        </div>
                    <?php endif; ?>
                    <?php if ($selNightAllow > 0): ?>
                        <div class="summary-cell night">
                            <div class="s-label">🌙 夜班津貼</div>
                            <div class="s-value">$<?php echo number_format($monthSummary['night_pay']); ?></div>
                        </div>
                    <?php endif; ?>
                    <div class="summary-cell salary">
                        <div class="s-label"><?php echo $queryMode === 'year' ? '年度薪資合計' : '本月薪資合計'; ?></div>
                        <div class="s-value">$<?php echo number_format($monthSummary['total_salary']); ?></div>
                    </div>
                </div>

                <!-- 編輯面板（僅管理員） -->
                <?php if ($isAdmin && $editRow): ?>
                    <div class="edit-panel" id="edit">
                        <div class="panel-title">✏️ 編輯出勤紀錄 — <?php echo htmlspecialchars($editRow['work_date']); ?></div>
                        <form method="post">
                            <input type="hidden" name="action" value="edit">
                            <input type="hidden" name="id" value="<?php echo $editRow['id']; ?>">
                            <div class="edit-grid">
                                <div class="edit-field"><label>🔵 第一段上班</label><input type="text" name="s1_start" class="edit-time-input" value="<?php echo htmlspecialchars(fmtTime($editRow['s1_start'] ?? '')); ?>" placeholder="08:00" inputmode="numeric" maxlength="5"></div>
                                <div class="edit-field"><label>🟢 第一段下班</label><input type="text" name="s1_end" class="edit-time-input" value="<?php echo htmlspecialchars(fmtTime($editRow['s1_end'] ?? ''));   ?>" placeholder="17:00" inputmode="numeric" maxlength="5"></div>
                                <div class="edit-field"><label>🟣 第二段上班</label><input type="text" name="s2_start" class="edit-time-input" value="<?php echo htmlspecialchars(fmtTime($editRow['s2_start'] ?? '')); ?>" placeholder="（可空白）" inputmode="numeric" maxlength="5"></div>
                                <div class="edit-field"><label>⚫ 第二段下班</label><input type="text" name="s2_end" class="edit-time-input" value="<?php echo htmlspecialchars(fmtTime($editRow['s2_end'] ?? '')); ?>" placeholder="（可空白）" inputmode="numeric" maxlength="5"></div>
                                <?php if ($selEmpType === 'fulltime'): ?>
                                    <div class="edit-field"><label>☕ 有無休息</label>
                                        <select name="has_break">
                                            <option value="1" <?php echo  $editRow['has_break'] ? 'selected' : ''; ?>>✅ 有休息</option>
                                            <option value="0" <?php echo !$editRow['has_break'] ? 'selected' : ''; ?>>⚡ 沒休息</option>
                                        </select>
                                    </div>
                                <?php else: ?><input type="hidden" name="has_break" value="0"><?php endif; ?>
                                <?php if ($selNightAllow > 0): ?>
                                    <div class="edit-field"><label>🌙 夜班津貼($)</label><input type="number" name="night_pay" value="<?php echo $editRow['night_pay']; ?>" min="0"></div>
                                <?php else: ?><input type="hidden" name="night_pay" value="0"><?php endif; ?>
                            </div>
                            <div class="btn-row" style="margin-top:14px">
                                <button type="submit" class="btn btn-purple">💾 儲存並重新計算薪資</button>
                                <a href="attendance.php?emp=<?php echo urlencode($selEmp); ?>&ym=<?php echo $selYM; ?>" class="btn btn-secondary">取消</a>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>

                <!-- 明細表格 -->
                <div class="card" style="padding:0;overflow:hidden">
                    <div style="padding:14px 16px;border-bottom:1px solid #eee;display:flex;justify-content:space-between;align-items:center">
                        <div style="font-size:0.88em;font-weight:700;color:var(--green-700)">
                            <?php echo $queryMode === 'year' ? '📆' : '📅'; ?> <?php echo htmlspecialchars($selEmp); ?> · <?php echo $queryMode === 'year' ? ((int)$selYear - 1911) . '年' : $selYM; ?> <?php echo $queryMode === 'year' ? '年度' : '每日'; ?>明細
                        </div>
                        <span class="badge badge-<?php echo $selEmpType; ?>"><?php echo $selEmpType === 'fulltime' ? '正職' : '時薪制'; ?></span>
                    </div>
                    <div style="overflow-x:auto;padding:0 4px 4px">
                        <table class="att-table">
                            <thead>
                                <tr>
                                    <th>日期</th>
                                    <th>第一段</th>
                                    <th>第二段</th>
                                    <?php if ($selEmpType === 'fulltime'): ?><th>有無休息</th><?php endif; ?>
                                    <th>工時(h)</th>
                                    <?php if ($selEmpType === 'fulltime'): ?><th>加班時數</th>
                                        <th>加班費</th><?php endif; ?>
                                    <?php if ($selNightAllow > 0): ?><th>🌙 津貼</th><?php endif; ?>
                                    <th><?php echo $selEmpType === 'fulltime' ? '加班費合計' : '當日薪資'; ?></th>
                                    <?php if ($isAdmin): ?><th>操作</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($attendances as $att): ?>
                                    <tr>
                                        <td><?php echo $att['work_date']; ?></td>
                                        <td><?php echo ($att['s1_start'] && $att['s1_end']) ? htmlspecialchars(fmtTime($att['s1_start'])) . '→' . htmlspecialchars(fmtTime($att['s1_end'])) : '—'; ?></td>
                                        <td><?php echo ($att['s2_start'] && $att['s2_end']) ? htmlspecialchars(fmtTime($att['s2_start'])) . '→' . htmlspecialchars(fmtTime($att['s2_end'])) : '—'; ?></td>
                                        <?php if ($selEmpType === 'fulltime'): ?><td><?php echo $att['has_break'] ? '✅ 有' : '⚡ 無'; ?></td><?php endif; ?>
                                        <td><?php echo $att['total_hours']; ?></td>
                                        <?php if ($selEmpType === 'fulltime'): ?>
                                            <td class="<?php echo $att['overtime_hours'] > 0 ? 'ot-cell' : ''; ?>"><?php echo $att['overtime_hours'] > 0 ? $att['overtime_hours'] . 'h' : '—'; ?></td>
                                            <td class="<?php echo $att['overtime_pay'] > 0 ? 'ot-cell' : ''; ?>">$<?php echo $att['overtime_pay']; ?></td>
                                        <?php endif; ?>
                                        <?php if ($selNightAllow > 0): ?>
                                            <td class="<?php echo $att['night_pay'] > 0 ? 'night-cell' : ''; ?>"><?php echo $att['night_pay'] > 0 ? '$' . $att['night_pay'] : '—'; ?></td>
                                        <?php endif; ?>
                                        <td class="salary-cell">$<?php echo number_format($att['salary']); ?></td>
                                        <?php if ($isAdmin): ?>
                                            <td>
                                                <div class="action-cell">
                                                    <a href="attendance.php?emp=<?php echo urlencode($selEmp); ?>&ym=<?php echo $selYM; ?>&mode=<?php echo $queryMode; ?>&searched=1&edit_id=<?php echo $att['id']; ?>#edit"
                                                        class="btn btn-ghost btn-sm">✏️</a>
                                                    <form method="post" style="margin:0" onsubmit="return confirm('確定刪除 <?php echo $att['work_date']; ?> 的出勤紀錄？')">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?php echo $att['id']; ?>">
                                                        <button type="submit" class="btn btn-danger btn-sm">🗑️</button>
                                                    </form>
                                                </div>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div><!-- /results-area -->
        <?php endif; ?>

        <!-- ── 修改登入密碼 ──
        <div class="card" style="margin-top:14px">
            <div class="card-title">🔑 修改登入密碼</div>
            <form method="post" onsubmit="return validatePwForm()">
                <input type="hidden" name="action" value="change_password">
                <div style="margin-bottom:12px">
                    <label style="font-size:0.78em;color:var(--grey-500);font-weight:600;display:block;margin-bottom:5px">目前密碼</label>
                    <input type="password" name="old_password" class="form-input" placeholder="輸入目前密碼" required>
                </div>
                <div style="margin-bottom:12px">
                    <label style="font-size:0.78em;color:var(--grey-500);font-weight:600;display:block;margin-bottom:5px">新密碼（至少 6 碼）</label>
                    <input type="password" name="new_password" id="pw-new" class="form-input" placeholder="輸入新密碼" minlength="6" required>
                </div>
                <div style="margin-bottom:16px">
                    <label style="font-size:0.78em;color:var(--grey-500);font-weight:600;display:block;margin-bottom:5px">確認新密碼</label>
                    <input type="password" name="new_password2" id="pw-new2" class="form-input" placeholder="再次輸入新密碼" minlength="6" required>
                </div>
                <button type="submit" class="btn btn-primary">🔐 確認修改密碼</button>
            </form>
        </div>

    </div> -->

    <script>
    function toggleNav(btn) {
        const nav = document.getElementById('topbar-nav');
        nav.classList.toggle('open');
        btn.setAttribute('aria-expanded', nav.classList.contains('open'));
    }
        // ── 編輯面板時間輸入格式化 ──────────────────────────
        function formatTime4(v) {
            const h = parseInt(v.slice(0, 2), 10);
            const m = parseInt(v.slice(2, 4), 10);
            if (isNaN(h) || isNaN(m) || h > 23 || m > 59) return '';
            return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
        }

        function flashTimeError(el) {
            el.style.borderColor = '#C62828';
            el.style.background = '#FFEBEE';
            setTimeout(() => {
                el.style.borderColor = '';
                el.style.background = '';
            }, 1500);
        }
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.edit-time-input').forEach(function(input) {
                // 輸入中：4位數字自動插冒號
                input.addEventListener('input', function() {
                    let v = this.value.replace(/[^0-9]/g, '').slice(0, 4);
                    if (v.length === 4) {
                        const fmt = formatTime4(v);
                        this.value = fmt || v;
                    } else if (v.length === 3) {
                        this.value = v.slice(0, 1) + ':' + v.slice(1);
                    } else {
                        this.value = v;
                    }
                });
                // 離開欄位：補全格式
                input.addEventListener('blur', function() {
                    const v = this.value.replace(/[^0-9]/g, '');
                    if (!v) return; // 空白允許（第二段可空）
                    let fmt = '';
                    if (v.length <= 2) {
                        const h = parseInt(v);
                        if (h >= 0 && h <= 23) fmt = String(h).padStart(2, '0') + ':00';
                    } else if (v.length === 3) {
                        fmt = formatTime4('0' + v);
                    } else {
                        fmt = formatTime4(v.slice(0, 4));
                    }
                    if (fmt) {
                        this.value = fmt;
                        this.style.borderColor = '';
                    } else {
                        this.value = '';
                        flashTimeError(this);
                    }
                });
                // 貼上處理
                input.addEventListener('paste', function(e) {
                    e.preventDefault();
                    const txt = (e.clipboardData || window.clipboardData).getData('text');
                    const d = txt.replace(/[^0-9]/g, '').slice(0, 4);
                    this.value = d.length === 4 ? formatTime4(d) : d;
                });
            });
        });

        function validatePwForm() {
            const n1 = document.getElementById('pw-new').value;
            const n2 = document.getElementById('pw-new2').value;
            if (n1 !== n2) {
                alert('新密碼兩次輸入不一致');
                return false;
            }
            if (n1.length < 6) {
                alert('新密碼至少需要 6 個字元');
                return false;
            }
            return true;
        }

        if (window.location.hash === '#edit') {
            const el = document.getElementById('edit');
            if (el) setTimeout(() => el.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            }), 100);
        }

        function syncYmInput() {
            const y = document.getElementById('ym-year-sel');
            const m = document.getElementById('ym-month-sel');
            const h = document.getElementById('ym-hidden');
            if (!y || !m || !h) return;
            h.value = y.value + '-' + String(m.value).padStart(2, '0');
        }

        function switchQueryMode(mode) {
            document.getElementById('query-mode-input').value = mode;
            document.getElementById('fg-month').style.display = mode === 'month' ? '' : 'none';
            document.getElementById('fg-year').style.display = mode === 'year' ? '' : 'none';
            document.querySelectorAll('.mode-tab').forEach((t, i) => {
                t.classList.toggle('active', (mode === 'month' && i === 0) || (mode === 'year' && i === 1));
            });
            // 切換模式時清除 searched 旗標，避免顯示前次查詢結果
            document.getElementById('searched-input').value = '';
            // 同時隱藏已顯示的結果區塊（若存在）
            const resultsArea = document.getElementById('results-area');
            if (resultsArea) resultsArea.style.display = 'none';
        }

        // 任何篩選條件改變時，清除 searched
        document.addEventListener('DOMContentLoaded', function() {
            const filterForm = document.getElementById('filter-form');
            if (!filterForm) return;
            filterForm.querySelectorAll('select, input[type="month"]').forEach(function(el) {
                el.addEventListener('change', function() {
                    document.getElementById('searched-input').value = '';
                    const resultsArea = document.getElementById('results-area');
                    if (resultsArea) resultsArea.style.display = 'none';
                });
            });
            // 初始化 ym-hidden
            syncYmInput();
        });
    </script>
</body>

</html>