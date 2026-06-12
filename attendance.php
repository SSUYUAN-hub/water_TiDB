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
                    'B' => fmtTime($r['s1_start'] ?? ''),
                    'C' => fmtTime($r['s1_end']   ?? ''),
                    'D' => fmtTime($r['s2_start'] ?? ''),
                    'E' => fmtTime($r['s2_end']   ?? ''),
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
                    'B' => fmtTime($r['s1_start'] ?? ''),
                    'C' => fmtTime($r['s1_end']   ?? ''),
                    'D' => fmtTime($r['s2_start'] ?? ''),
                    'E' => fmtTime($r['s2_end']   ?? ''),
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

        if ($action === 'bulk_delete') {
            $ids = array_map('intval', $_POST['ids'] ?? []);
            $ids = array_filter($ids);
            if (!empty($ids)) {
                try {
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $stmt = getDB()->prepare("DELETE FROM attendance WHERE id IN ($placeholders)");
                    $stmt->execute($ids);
                    $deleted = $stmt->rowCount();
                    $message = "🗑️ 已刪除 {$deleted} 筆出勤紀錄";
                    $msgType = 'success';
                } catch (PDOException $e) {
                    $message = '批次刪除失敗：' . $e->getMessage();
                    $msgType = 'error';
                }
            }
        }

        if ($action === 'bulk_edit') {
            $rows   = $_POST['rows'] ?? [];
            $errors = [];
            $saved  = 0;
            foreach ($rows as $rowId => $rowData) {
                $id       = (int)$rowId;
                $s1Start  = trim($rowData['s1_start'] ?? '');
                $s1End    = trim($rowData['s1_end']   ?? '');
                $s2Start  = trim($rowData['s2_start'] ?? '');
                $s2End    = trim($rowData['s2_end']   ?? '');
                $hasBreak = ($rowData['has_break'] ?? '0') === '1';
                $nightPay = (int)($rowData['night_pay'] ?? 0);
                $rec = getDB()->prepare('SELECT * FROM attendance WHERE id = ?');
                $rec->execute([$id]);
                $row = $rec->fetch();
                if (!$row) continue;
                $emp     = getEmployee($row['employee_name']);
                $empType = $emp['type'] ?? 'hourly';
                $wage    = (int)($emp['hourly_rate'] ?? 180);
                // 與 edit action 相同：傳時間字串給 calculateSalary
                $sal1 = ($s1Start && $s1End) ? calculateSalary($s1Start, $s1End, $wage, $empType, $hasBreak) : ['total_hours'=>0,'overtime_hours'=>0,'overtime_pay'=>0,'salary'=>0];
                $sal2 = ($s2Start && $s2End) ? calculateSalary($s2Start, $s2End, $wage, $empType, false)    : ['total_hours'=>0,'overtime_hours'=>0,'overtime_pay'=>0,'salary'=>0];
                $totalHours    = round($sal1['total_hours']    + $sal2['total_hours'],    2);
                $overtimeHours = round($sal1['overtime_hours'] + $sal2['overtime_hours'], 2);
                $overtimePay   = $sal1['overtime_pay'] + $sal2['overtime_pay'];
                $baseSalary    = ($empType === 'fulltime') ? $overtimePay : ($sal1['salary'] + $sal2['salary']);
                $totalSalary   = $baseSalary + $nightPay;
                try {
                    $upd = getDB()->prepare('UPDATE attendance SET s1_start=:s1s,s1_end=:s1e,s2_start=:s2s,s2_end=:s2e,has_break=:hb,total_hours=:th,overtime_hours=:oth,overtime_pay=:otp,night_pay=:np,salary=:sal WHERE id=:id');
                    $upd->execute([':s1s'=>$s1Start?:null,':s1e'=>$s1End?:null,':s2s'=>$s2Start?:null,':s2e'=>$s2End?:null,':hb'=>$hasBreak?1:0,':th'=>$totalHours,':oth'=>$overtimeHours,':otp'=>$overtimePay,':np'=>$nightPay,':sal'=>$totalSalary,':id'=>$id]);
                    $saved++;
                } catch (PDOException $e) {
                    $errors[] = $row['work_date'] . '：' . $e->getMessage();
                }
            }
            $message = "✅ 已更新 {$saved} 筆紀錄" . (!empty($errors) ? '，部分失敗：' . implode('、', $errors) : '');
            $msgType = empty($errors) ? 'success' : 'error';
            // bulk_edit 完成後把查詢參數帶到 GET，讓頁面重新顯示同一查詢
            $qs = http_build_query(array_filter([
                'emp'      => $_POST['emp']     ?? '',
                'ym'       => $_POST['ym']      ?? '',
                'year'     => $_POST['year']    ?? '',
                'mode'     => $_POST['mode']    ?? 'month',
                'searched' => '1',
                'msg'      => $message,
                'msg_type' => $msgType,
            ]));
            header("Location: attendance.php?{$qs}");
            exit;
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
if (!empty($_GET['msg']) && empty($message)) {
    $message = $_GET['msg'];
    $msgType = $_GET['msg_type'] ?? 'success';
}

$yearGrouped  = [];
$attendances  = [];
$monthSummary = [];

if ($searched) {
    if ($queryMode === 'year') {
        $yearGrouped = ($selEmp && $selYear) ? getAttendanceByYear($selEmp, $selYear) : [];
        $allRows = array_merge(...(array_values($yearGrouped) ?: [[]]));
        // 撈該年所有月份的勞健保扣項
        if ($selEmp && $selYear) {
            $ydList = getMonthlyDeductionsByYear($selEmp, $selYear);
            foreach ($ydList as $yd) {
                $yearDeductions[$yd['year_month']] = $yd;
            }
        }
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

// 月份查詢時撈月結扣項（正職 + 月份模式才有意義）
$monthlyDeduction = null;
if ($searched && $queryMode === 'month' && $selEmpType === 'fulltime' && $selEmp && $selYM) {
    $monthlyDeduction = getMonthlyDeduction($selEmp, $selYM);
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
        .ym-header:hover td { background: var(--green-100) !important; }

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

        .summary-cell.deduct .s-value {
            color: var(--purple-600);
        }

        .att-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.92em;
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
                font-size: 0.85em
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
                <?php
                    $bonusAmt    = 0; // 獎金（待規劃）
                    $insDeduct   = 0; // 勞健保實際扣繳（合計）
                    $monthlyWage = (int)($selEmpData['hourly_rate'] ?? 0);

                    if ($queryMode === 'year' && $selEmpType === 'fulltime') {
                        // 年份模式：各月勞健保加總，年度薪資 = 各月(月薪+加班費+夜班津貼-勞健保) 加總
                        $yearInsTotal = 0;
                        $yearNetTotal = 0;
                        foreach ($yearGrouped as $ym2 => $ymRows2) {
                            $ymOTPay2    = array_sum(array_column($ymRows2, 'overtime_pay'));
                            $ymNightPay2 = array_sum(array_column($ymRows2, 'night_pay'));
                            $ymIns2      = 0;
                            if (isset($yearDeductions[$ym2])) {
                                $ymIns2 = (int)$yearDeductions[$ym2]['labor_ins'] + (int)$yearDeductions[$ym2]['health_ins'];
                            }
                            $yearInsTotal += $ymIns2;
                            $yearNetTotal += $monthlyWage + $ymOTPay2 + $ymNightPay2 - $ymIns2;
                        }
                        $insDeduct   = $yearInsTotal;
                        $fulltimeNet = $yearNetTotal;
                    } elseif ($queryMode === 'month' && $selEmpType === 'fulltime') {
                        $mdPreview = getMonthlyDeduction($selEmp, $selYM);
                        if ($mdPreview) $insDeduct = (int)$mdPreview['labor_ins'] + (int)$mdPreview['health_ins'];
                        $fulltimeNet = $monthlyWage + $monthSummary['overtime_pay'] + $monthSummary['night_pay'] + $bonusAmt - $insDeduct;
                    } else {
                        $fulltimeNet = $monthlyWage + $monthSummary['overtime_pay'] + $monthSummary['night_pay'] + $bonusAmt;
                    }
                    $hourlyNet = $monthSummary['total_salary'];
                ?>
                <div class="summary-grid">
                    <!-- 出勤天數：正職時薪都顯示 -->
                    <div class="summary-cell">
                        <div class="s-label">出勤天數</div>
                        <div class="s-value"><?php echo $monthSummary['work_days']; ?> 天</div>
                    </div>

                    <?php if ($selEmpType === 'fulltime'): ?>
                        <!-- 正職專屬 -->
                        <div class="summary-cell ot">
                            <div class="s-label">加班時數</div>
                            <div class="s-value"><?php echo $monthSummary['overtime_hours']; ?> h</div>
                        </div>
                        <div class="summary-cell ot">
                            <div class="s-label">加班費合計</div>
                            <div class="s-value">$<?php echo number_format($monthSummary['overtime_pay']); ?></div>
                        </div>
                        <div class="summary-cell" style="color:var(--grey-400)">
                            <div class="s-label">獎金</div>
                            <div class="s-value" style="color:var(--grey-300);font-size:0.95em">— 待規劃</div>
                        </div>
                        <div class="summary-cell deduct">
                            <div class="s-label">勞健保費用</div>
                            <div class="s-value" style="color:var(--purple-600)">
                                <?php echo $insDeduct > 0 ? '−$'.number_format($insDeduct) : '—'; ?>
                            </div>
                        </div>
                        <div class="summary-cell salary">
                            <div class="s-label"><?php echo $queryMode === 'year' ? '年度薪資合計' : '本月薪資合計'; ?></div>
                            <div class="s-value">$<?php echo number_format($fulltimeNet); ?></div>
                        </div>
                    <?php else: ?>
                        <!-- 時薪制專屬 -->
                        <div class="summary-cell">
                            <div class="s-label">總工時</div>
                            <div class="s-value"><?php echo $monthSummary['total_hours']; ?> h</div>
                        </div>
                        <?php if ($selNightAllow > 0): ?>
                        <div class="summary-cell night">
                            <div class="s-label">🌙 津貼</div>
                            <div class="s-value">$<?php echo number_format($monthSummary['night_pay']); ?></div>
                        </div>
                        <?php endif; ?>
                        <div class="summary-cell salary">
                            <div class="s-label"><?php echo $queryMode === 'year' ? '年度薪資合計' : '本月薪資合計'; ?></div>
                            <div class="s-value">$<?php echo number_format($hourlyNet); ?></div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- 月結扣項摘要（正職 + 月份模式才顯示） -->
                <?php if ($queryMode === 'month' && $selEmpType === 'fulltime'): ?>
                <?php if ($monthlyDeduction): ?>
                <?php
                    $md          = $monthlyDeduction;
                    $laborCalc   = (int)($md['labor_ins_calc']  ?? $md['labor_ins']);
                    $healthCalc  = (int)($md['health_ins_calc'] ?? $md['health_ins']);
                    $laborFinal  = (int)$md['labor_ins'];
                    $healthFinal = (int)$md['health_ins'];
                    $calcTotal   = $laborCalc + $healthCalc;
                    $finalTotal  = $laborFinal + $healthFinal;
                    $wasAdjusted = $calcTotal !== $finalTotal;
                ?>
                <div class="card" style="padding:0;overflow:hidden;margin-bottom:14px">
                    <div style="padding:12px 16px;border-bottom:1px solid #eee;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:6px">
                        <span style="font-size:1em;font-weight:700;color:var(--grey-700)">💰 月結薪資明細</span>
                        <span style="font-size:1em;color:var(--grey-400)">
                            費率快照：勞保 <?php echo round($md['labor_ins_rate'] * 100, 2); ?>%／健保 <?php echo round($md['health_ins_rate'] * 100, 3); ?>%
                        </span>
                    </div>
                    <div style="padding:14px 16px;display:flex;flex-direction:column;gap:0">

                        <!-- 勞健保費用 -->
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;padding:9px 0;border-bottom:1px dashed #eee;font-size:0.93em;gap:8px">
                            <div>
                                <div style="color:var(--grey-500);margin-bottom:4px">勞健保費用</div>
                                <button type="button"
                                    style="font-size:0.78em;background:none;border:1px solid var(--grey-300);border-radius:4px;padding:3px 8px;cursor:pointer;color:var(--grey-500);font-family:var(--font-body)"
                                    onclick="toggleInsFormula(this)">📐 查看計算公式 ▼</button>
                            </div>
                            <div style="text-align:right;flex-shrink:0;display:flex;flex-direction:column;gap:4px">
                                <div style="font-size:0.82em;color:var(--grey-400);font-family:var(--font-num)">
                                    依法應扣：−$<?php echo number_format($calcTotal); ?>
                                </div>
                                <div style="font-size:1.05em;font-weight:700;font-family:var(--font-num);color:var(--purple-600)">
                                    實際扣繳：−$<?php echo number_format($finalTotal); ?>
                                </div>
                            </div>
                        </div>

                        <!-- 勞健保計算公式展開 -->
                        <div class="ins-formula-detail" style="display:none;background:#F8F9FA;border-bottom:1px dashed #eee;padding:14px 16px;font-size:0.92em;color:var(--grey-700);line-height:2">
                            <div style="font-weight:700;color:var(--grey-500);font-size:0.85em;margin-bottom:8px">🛡️ 勞保費</div>
                            <div style="display:flex;justify-content:space-between;padding:2px 0"><span style="color:var(--grey-500)">月薪 $<?php echo number_format((int)($selEmpData['hourly_rate']??0)); ?> → 投保薪資級距</span><span style="font-weight:700;font-family:var(--font-num)">$<?php echo number_format((int)$md['insured_salary']); ?></span></div>
                            <div style="display:flex;justify-content:space-between;padding:2px 0"><span style="color:var(--grey-500)">費率 × 自付比例</span><span style="font-weight:700;font-family:var(--font-num)"><?php echo round($md['labor_ins_rate']*100,2); ?>% × 20%</span></div>
                            <div style="display:flex;justify-content:space-between;background:white;border-radius:6px;padding:7px 10px;margin-top:4px;border:1px solid #eee">
                                <span style="color:var(--purple-600);font-weight:700">$<?php echo number_format((int)$md['insured_salary']); ?> × <?php echo round($md['labor_ins_rate']*100,2); ?>% × 20%</span>
                                <span style="font-weight:700;font-family:var(--font-num);color:var(--purple-600)">= $<?php echo number_format($laborCalc); ?></span>
                            </div>
                            <div style="font-weight:700;color:var(--grey-500);font-size:0.85em;margin-top:14px;margin-bottom:8px">🏥 健保費</div>
                            <div style="display:flex;justify-content:space-between;padding:2px 0"><span style="color:var(--grey-500)">月薪 $<?php echo number_format((int)($selEmpData['hourly_rate']??0)); ?> → 投保薪資級距</span><span style="font-weight:700;font-family:var(--font-num)">$<?php echo number_format((int)$md['insured_salary']); ?></span></div>
                            <div style="display:flex;justify-content:space-between;padding:2px 0"><span style="color:var(--grey-500)">費率 × 自付比例</span><span style="font-weight:700;font-family:var(--font-num)"><?php echo round($md['health_ins_rate']*100,3); ?>% × 30%</span></div>
                            <div style="display:flex;justify-content:space-between;background:white;border-radius:6px;padding:7px 10px;margin-top:4px;border:1px solid #eee">
                                <span style="color:var(--purple-600);font-weight:700">$<?php echo number_format((int)$md['insured_salary']); ?> × <?php echo round($md['health_ins_rate']*100,3); ?>% × 30%</span>
                                <span style="font-weight:700;font-family:var(--font-num);color:var(--purple-600)">= $<?php echo number_format($healthCalc); ?></span>
                            </div>
                        </div>

                        <!-- 加班費 + 加班費計算公式展開 -->
                        <?php
                            $wage4att   = (int)($selEmpData['hourly_rate'] ?? 0);
                            $hrRate4att = round($wage4att / 240, 4);
                            $otDays     = array_filter($attendances, fn($r) => $r['overtime_pay'] > 0);
                        ?>
                        <?php if ($monthSummary['overtime_pay'] > 0): ?>
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;padding:9px 0;border-bottom:1px dashed #eee;font-size:0.93em;gap:8px">
                            <div>
                                <div style="color:var(--grey-500);margin-bottom:4px">加班費合計</div>
                                <button type="button"
                                    style="font-size:0.78em;background:none;border:1px solid var(--grey-300);border-radius:4px;padding:3px 8px;cursor:pointer;color:var(--grey-500);font-family:var(--font-body)"
                                    onclick="toggleOTFormula(this)">📐 查看加班費計算 ▼</button>
                            </div>
                            <span style="font-weight:700;font-family:var(--font-num);color:var(--amber-500);font-size:1.05em">+$<?php echo number_format($monthSummary['overtime_pay']); ?></span>
                        </div>
                        <div class="ot-formula-detail" style="display:none;background:#FFFDE7;border-bottom:1px dashed #eee;padding:14px 16px;font-size:0.88em;color:var(--grey-700);line-height:1.8">
                            <div style="margin-bottom:8px;color:var(--grey-600)">
                                月薪 $<?php echo number_format($wage4att); ?> ÷ 30 ÷ 8 ＝ 時薪 $<?php echo round($hrRate4att, 2); ?> 元
                            </div>
                            <div style="margin-bottom:12px;color:var(--grey-500);font-size:0.9em">
                                🔶 前2h加班（×4/3）：$<?php echo round($hrRate4att * 4/3, 2); ?> ／h　　🔴 第3h起（×5/3）：$<?php echo round($hrRate4att * 5/3, 2); ?> ／h
                            </div>
                            <?php foreach ($otDays as $otRow):
                                $ot1h = min($otRow['overtime_hours'], 2);
                                $ot2h = max($otRow['overtime_hours'] - 2, 0);
                                $ot1p = (int)ceil($ot1h * $hrRate4att * 4/3);
                                $ot2p = (int)ceil($ot2h * $hrRate4att * 5/3);
                            ?>
                            <div style="padding:8px 12px;background:white;border-radius:6px;border:1px solid #FFF176;margin-bottom:6px">
                                <div style="font-weight:700;color:var(--grey-700);margin-bottom:4px"><?php echo $otRow['work_date']; ?> &nbsp;加班 <?php echo $otRow['overtime_hours']; ?>h</div>
                                <?php if ($ot1h > 0): ?>
                                <div style="color:#F57F17">🔶 前<?php echo round($ot1h,2); ?>h × $<?php echo round($hrRate4att*4/3,2); ?>（×4/3）= $<?php echo $ot1p; ?></div>
                                <?php endif; ?>
                                <?php if ($ot2h > 0): ?>
                                <div style="color:#C62828">🔴 後<?php echo round($ot2h,2); ?>h × $<?php echo round($hrRate4att*5/3,2); ?>（×5/3）= $<?php echo $ot2p; ?></div>
                                <?php endif; ?>
                                <div style="font-weight:700;color:var(--amber-600);margin-top:2px">小計：$<?php echo $otRow['overtime_pay']; ?></div>
                            </div>
                            <?php endforeach; ?>
                            <div style="display:flex;justify-content:space-between;background:#FFF8E1;border-radius:6px;padding:8px 12px;border:1px solid #FFE082;margin-top:4px">
                                <span style="font-weight:700;color:var(--amber-600)">加班費合計</span>
                                <span style="font-weight:700;font-family:var(--font-num);color:var(--amber-600)">$<?php echo number_format($monthSummary['overtime_pay']); ?></span>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- 夜班津貼 -->
                        <?php if ($monthSummary['night_pay'] > 0): ?>
                        <div style="display:flex;justify-content:space-between;padding:9px 0;border-bottom:1px dashed #eee;font-size:0.93em">
                            <span style="color:var(--grey-500)">🌙 夜班津貼</span>
                            <span style="font-weight:700;font-family:var(--font-num);color:var(--purple-600)">+$<?php echo number_format($monthSummary['night_pay']); ?></span>
                        </div>
                        <?php endif; ?>

                        <!-- 實領金額（即時計算：月薪+加班費+夜班津貼-實際扣繳） -->
                        <?php $liveNet = (int)($selEmpData['hourly_rate'] ?? 0) + $monthSummary['overtime_pay'] + $monthSummary['night_pay'] - $finalTotal; ?>
                        <div style="display:flex;justify-content:space-between;align-items:center;padding:14px 0;background:var(--green-50);border-top:2px solid #A5D6A7;margin-top:4px">
                            <span style="font-weight:700;color:var(--green-700);font-size:1.2em">實領金額</span>
                            <span style="font-weight:700;font-family:var(--font-num);color:var(--green-700);font-size:1.5em">$<?php echo number_format($liveNet); ?></span>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <!-- 尚無月結紀錄 -->
                <div style="background:var(--amber-100);border-left:4px solid var(--amber-500);border-radius:var(--radius-sm);padding:10px 14px;font-size:0.83em;color:#E65100;margin-bottom:14px">
                    ⚠️ 本月尚無勞健保扣項紀錄，請透過「打卡辨識」流程寫入後才會顯示實領金額
                </div>
                <?php endif; ?>
                <?php endif; ?>

                <!-- 時薪制薪資明細（月份查詢才顯示） -->
                <?php if ($queryMode === 'month' && $selEmpType === 'hourly' && !empty($attendances)): ?>
                <?php
                    $hrWage       = (int)($selEmpData['hourly_rate'] ?? 0);
                    $totalHrsDisp = $monthSummary['total_hours'];
                    $nightDays    = count(array_filter($attendances, fn($r) => $r['night_pay'] > 0));
                    $totalNight   = $monthSummary['night_pay'];
                    $totalWage    = $monthSummary['total_salary'] - $totalNight;
                ?>
                <div class="card" style="padding:0;overflow:hidden;margin-bottom:14px">
                    <div style="padding:12px 16px;border-bottom:1px solid #eee">
                        <span style="font-size:1em;font-weight:700;color:var(--grey-700)">💰 月結薪資明細</span>
                    </div>
                    <div style="padding:14px 16px;display:flex;flex-direction:column;gap:0">

                        <!-- 薪資計算 -->
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;padding:9px 0;border-bottom:1px dashed #eee;font-size:0.93em;gap:8px">
                            <div>
                                <div style="color:var(--grey-500);margin-bottom:4px">時薪薪資</div>
                                <button type="button"
                                    style="font-size:0.78em;background:none;border:1px solid var(--grey-300);border-radius:4px;padding:3px 8px;cursor:pointer;color:var(--grey-500);font-family:var(--font-body)"
                                    onclick="toggleHourlyFormula(this)">📐 查看計算公式 ▼</button>
                            </div>
                            <span style="font-weight:700;font-family:var(--font-num);color:var(--grey-800);font-size:1.05em">$<?php echo number_format($totalWage); ?></span>
                        </div>
                        <div class="hourly-formula-detail" style="display:none;background:#F8F9FA;border-bottom:1px dashed #eee;padding:14px 16px;font-size:0.92em;color:var(--grey-700);line-height:2">
                            <div style="display:flex;justify-content:space-between;background:white;border-radius:6px;padding:8px 12px;border:1px solid #eee">
                                <span style="color:var(--grey-600)">總工時 <?php echo $totalHrsDisp; ?>h × 時薪 $<?php echo $hrWage; ?></span>
                                <span style="font-weight:700;font-family:var(--font-num);color:var(--grey-800)">= $<?php echo number_format($totalWage); ?></span>
                            </div>
                        </div>

                        <!-- 津貼計算 -->
                        <?php if ($totalNight > 0): ?>
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;padding:9px 0;border-bottom:1px dashed #eee;font-size:0.93em;gap:8px">
                            <div>
                                <div style="color:var(--grey-500);margin-bottom:4px">🌙 夜班津貼</div>
                                <button type="button"
                                    style="font-size:0.78em;background:none;border:1px solid var(--grey-300);border-radius:4px;padding:3px 8px;cursor:pointer;color:var(--grey-500);font-family:var(--font-body)"
                                    onclick="toggleNightFormula(this)">📐 查看計算公式 ▼</button>
                            </div>
                            <span style="font-weight:700;font-family:var(--font-num);color:var(--purple-600);font-size:1.05em">+$<?php echo number_format($totalNight); ?></span>
                        </div>
                        <div class="night-formula-detail" style="display:none;background:#F3E5F5;border-bottom:1px dashed #eee;padding:14px 16px;font-size:0.92em;color:var(--grey-700);line-height:2">
                            <div style="display:flex;justify-content:space-between;background:white;border-radius:6px;padding:8px 12px;border:1px solid #E1BEE7">
                                <span style="color:var(--grey-600)"><?php echo $nightDays; ?> 天 × 津貼 $<?php echo $selNightAllow; ?></span>
                                <span style="font-weight:700;font-family:var(--font-num);color:var(--purple-600)">= $<?php echo number_format($totalNight); ?></span>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- 本月薪資合計 -->
                        <div style="display:flex;justify-content:space-between;align-items:center;padding:14px 0;background:var(--green-50);border-top:2px solid #A5D6A7;margin-top:4px">
                            <span style="font-weight:700;color:var(--green-700);font-size:1.2em">本月薪資合計</span>
                            <span style="font-weight:700;font-family:var(--font-num);color:var(--green-700);font-size:1.5em">$<?php echo number_format($monthSummary['total_salary']); ?></span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

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
                    <div style="padding:14px 16px;border-bottom:1px solid #eee;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
                        <div style="font-size:0.88em;font-weight:700;color:var(--green-700)">
                            <?php echo $queryMode === 'year' ? '📆' : '📅'; ?> <?php echo htmlspecialchars($selEmp); ?> · <?php echo $queryMode === 'year' ? ((int)$selYear - 1911) . '年' : $selYM; ?> <?php echo $queryMode === 'year' ? '年度' : '每日'; ?>明細
                        </div>
                        <div style="display:flex;align-items:center;gap:8px">
                            <?php if ($isAdmin): ?>
                            <button type="button" id="btn-bulk-edit" class="btn btn-ghost btn-sm" onclick="openBulkEdit()" style="display:none">✏️ 多筆編輯（<span id="sel-count">0</span>）</button>
                            <button type="button" id="btn-bulk-delete" class="btn btn-danger btn-sm" onclick="openDeleteModal()" style="display:none">🗑️ 多筆刪除（<span id="sel-count2">0</span>）</button>
                            <?php endif; ?>
                            <span class="badge badge-<?php echo $selEmpType; ?>"><?php echo $selEmpType === 'fulltime' ? '正職' : '時薪制'; ?></span>
                        </div>
                    </div>
                    <div style="overflow-x:auto;padding:0 4px 4px">
                        <table class="att-table">
                            <thead<?php echo $queryMode === 'year' ? ' style="display:none"' : ''; ?>>
                                <tr>
                                    <?php if ($isAdmin): ?><th style="width:36px"><input type="checkbox" id="chk-all" onchange="toggleAll(this)" title="全選"></th><?php endif; ?>
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
                                <?php if ($queryMode === 'year'): ?>
                                <?php foreach ($yearGrouped as $ym => $ymRows):
                                    $ymRoc = ((int)explode('-',$ym)[0]-1911).'年'.(int)explode('-',$ym)[1].'月';
                                    $ymId  = 'ym-' . str_replace('-','', $ym);
                                ?>
                                    <tr class="ym-header" style="background:var(--green-50);cursor:pointer;user-select:none"
                                        data-ymid="<?php echo $ymId; ?>">
                                        <?php if ($isAdmin): ?>
                                        <td style="width:36px;padding:10px 6px;text-align:center" onclick="event.stopPropagation()">
                                            <input type="checkbox" class="ym-chk-all" id="ymchk-<?php echo $ymId; ?>"
                                                onchange="toggleYMAll('<?php echo $ymId; ?>', this)" title="選取本月全部">
                                        </td>
                                        <td colspan="98" style="font-weight:700;color:var(--green-700);padding:10px 14px"
                                            onclick="toggleYM('<?php echo $ymId; ?>', this.closest('tr'))">
                                        <?php else: ?>
                                        <td colspan="99" style="font-weight:700;color:var(--green-700);padding:10px 14px"
                                            onclick="toggleYM('<?php echo $ymId; ?>', this.closest('tr'))">
                                        <?php endif; ?>
                                            <span id="arrow-<?php echo $ymId; ?>" style="margin-right:8px">▶</span>
                                            📅 <?php echo $ymRoc; ?>（<?php echo count($ymRows); ?> 天）
                                        </td>
                                    </tr>
                                    <tr class="ym-row ym-row-<?php echo $ymId; ?> ym-subheader" style="display:none;background:var(--grey-50)">
                                        <?php if ($isAdmin): ?><th style="width:36px"></th><?php endif; ?>
                                        <th>日期</th><th>第一段</th><th>第二段</th>
                                        <?php if ($selEmpType === 'fulltime'): ?><th>有無休息</th><?php endif; ?>
                                        <th>工時(h)</th>
                                        <?php if ($selEmpType === 'fulltime'): ?><th>加班時數</th><th>加班費</th><?php endif; ?>
                                        <?php if ($selNightAllow > 0): ?><th>🌙 津貼</th><?php endif; ?>
                                        <th><?php echo $selEmpType === 'fulltime' ? '加班費合計' : '當日薪資'; ?></th>
                                        <?php if ($isAdmin): ?><th>操作</th><?php endif; ?>
                                    </tr>
                                    <?php foreach ($ymRows as $att): ?>
                                    <tr class="ym-row ym-row-<?php echo $ymId; ?>" style="display:none" data-id="<?php echo $att['id']; ?>"
                                        data-date="<?php echo $att['work_date']; ?>"
                                        data-s1s="<?php echo htmlspecialchars(fmtTime($att['s1_start'] ?? '')); ?>"
                                        data-s1e="<?php echo htmlspecialchars(fmtTime($att['s1_end']   ?? '')); ?>"
                                        data-s2s="<?php echo htmlspecialchars(fmtTime($att['s2_start'] ?? '')); ?>"
                                        data-s2e="<?php echo htmlspecialchars(fmtTime($att['s2_end']   ?? '')); ?>"
                                        data-break="<?php echo $att['has_break'] ? '1' : '0'; ?>"
                                        data-night="<?php echo $att['night_pay']; ?>"
                                        data-s1="<?php echo ($att['s1_start']&&$att['s1_end']) ? htmlspecialchars(fmtTime($att['s1_start'])).'→'.htmlspecialchars(fmtTime($att['s1_end'])) : '—'; ?>"
                                        data-s2="<?php echo ($att['s2_start']&&$att['s2_end']) ? htmlspecialchars(fmtTime($att['s2_start'])).'→'.htmlspecialchars(fmtTime($att['s2_end'])) : '—'; ?>"
                                        data-hours="<?php echo $att['total_hours']; ?>"
                                        data-ot="<?php echo $att['overtime_pay']; ?>"
                                        data-salary="<?php echo number_format($att['salary']); ?>">
                                        <?php if ($isAdmin): ?><td><input type="checkbox" class="row-chk" onchange="onRowCheck()" value="<?php echo $att['id']; ?>"></td><?php endif; ?>
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
                                            <?php $attYM = substr($att['work_date'], 0, 7); ?>
                                            <td>
                                                <div class="action-cell">
                                                    <a href="attendance.php?emp=<?php echo urlencode($selEmp); ?>&ym=<?php echo $attYM; ?>&year=<?php echo $selYear; ?>&mode=year&searched=1&edit_id=<?php echo $att['id']; ?>#edit"
                                                        class="btn btn-ghost btn-sm">✏️</a>
                                                    <button type="button" class="btn btn-danger btn-sm"
                                                        onclick="openDeleteModal([<?php echo $att['id']; ?>])">🗑️</button>
                                                </div>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                    <?php endforeach; // end ymRows ?>

                                    <?php
                                    // ── 月結薪資明細（年份模式，每月折疊區下方）──
                                    $ymOTPay    = array_sum(array_column($ymRows, 'overtime_pay'));
                                    $ymNightPay = array_sum(array_column($ymRows, 'night_pay'));
                                    $ymTotalHrs = round(array_sum(array_column($ymRows, 'total_hours')), 2);
                                    $ymTotalSal = array_sum(array_column($ymRows, 'salary'));
                                    $ymMd       = $yearDeductions[$ym] ?? null;
                                    $ymInsDeduct = $ymMd ? (int)$ymMd['labor_ins'] + (int)$ymMd['health_ins'] : 0;
                                    if ($selEmpType === 'fulltime') {
                                        $ymNet = $monthlyWage + $ymOTPay + $ymNightPay - $ymInsDeduct;
                                    } else {
                                        $ymNet = $ymTotalSal;
                                    }
                                    ?>
                                    <tr class="ym-row ym-row-<?php echo $ymId; ?> ym-summary-row" style="display:none">
                                        <td colspan="99" style="padding:0;background:#FAFAFA">
                                        <div style="margin:8px 12px 14px;border:1px solid #E0E0E0;border-radius:10px;overflow:hidden;font-size:1em">
                                            <div style="padding:9px 14px;background:#F5F5F5;font-weight:700;color:var(--grey-700);border-bottom:1px solid #E0E0E0">
                                                💰 <?php echo $ymRoc; ?>月結薪資明細
                                            </div>
                                            <div style="padding:12px 14px;display:flex;flex-direction:column;gap:0">
                                            <?php if ($selEmpType === 'fulltime'): ?>
                                                <?php if ($ymMd): ?>
                                                    <?php
                                                        $ymLaborCalc  = (int)($ymMd['labor_ins_calc']  ?? $ymMd['labor_ins']);
                                                        $ymHealthCalc = (int)($ymMd['health_ins_calc'] ?? $ymMd['health_ins']);
                                                        $ymLaborFinal  = (int)$ymMd['labor_ins'];
                                                        $ymHealthFinal = (int)$ymMd['health_ins'];
                                                        $ymCalcTotal   = $ymLaborCalc + $ymHealthCalc;
                                                        $ymFinalTotal  = $ymLaborFinal + $ymHealthFinal;
                                                    ?>
                                                    <div style="display:flex;justify-content:space-between;align-items:flex-start;padding:7px 0;border-bottom:1px dashed #eee;gap:8px">
                                                        <div>
                                                            <div style="color:var(--grey-500);margin-bottom:3px">勞健保費用</div>
                                                            <button type="button"
                                                                style="font-size:0.75em;background:none;border:1px solid var(--grey-300);border-radius:4px;padding:2px 7px;cursor:pointer;color:var(--grey-500)"
                                                                onclick="toggleInsFormula(this)">📐 查看計算公式 ▼</button>
                                                        </div>
                                                        <div style="text-align:right;flex-shrink:0">
                                                            <div style="font-size:0.8em;color:var(--grey-400)">依法應扣：−$<?php echo number_format($ymCalcTotal); ?></div>
                                                            <div style="font-weight:700;color:var(--purple-600)">實際扣繳：−$<?php echo number_format($ymFinalTotal); ?></div>
                                                        </div>
                                                    </div>
                                                    <div class="ins-formula-detail" style="display:none;background:#F8F9FA;border-bottom:1px dashed #eee;padding:12px 14px;color:var(--grey-700);line-height:1.9">
                                                        <div style="font-weight:700;color:var(--grey-500);font-size:0.85em;margin-bottom:6px">🛡️ 勞保費</div>
                                                        <div style="display:flex;justify-content:space-between;background:white;border-radius:6px;padding:6px 10px;margin-top:3px;border:1px solid #eee">
                                                            <span style="color:var(--purple-600)">$<?php echo number_format((int)$ymMd['insured_salary']); ?> × <?php echo round($ymMd['labor_ins_rate']*100,2); ?>% × 20%</span>
                                                            <span style="font-weight:700;color:var(--purple-600)">= $<?php echo number_format($ymLaborCalc); ?></span>
                                                        </div>
                                                        <div style="font-weight:700;color:var(--grey-500);font-size:0.85em;margin-top:12px;margin-bottom:6px">🏥 健保費</div>
                                                        <div style="display:flex;justify-content:space-between;background:white;border-radius:6px;padding:6px 10px;margin-top:3px;border:1px solid #eee">
                                                            <span style="color:var(--purple-600)">$<?php echo number_format((int)$ymMd['insured_salary']); ?> × <?php echo round($ymMd['health_ins_rate']*100,3); ?>% × 30%</span>
                                                            <span style="font-weight:700;color:var(--purple-600)">= $<?php echo number_format($ymHealthCalc); ?></span>
                                                        </div>
                                                    </div>
                                                <?php else: ?>
                                                    <div style="padding:6px 10px;font-size:0.85em;color:#E65100;background:var(--amber-100);border-radius:6px;margin-bottom:4px;border-left:3px solid var(--amber-500)">
                                                        ⚠️ 本月尚無勞健保扣項紀錄
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($ymOTPay > 0):
                                                    $ymHrRate = round($monthlyWage / 240, 4);
                                                    $ymOTDays = array_filter($ymRows, fn($r) => $r['overtime_pay'] > 0);
                                                ?>
                                                <div style="display:flex;justify-content:space-between;align-items:flex-start;padding:7px 0;border-bottom:1px dashed #eee;gap:8px">
                                                    <div>
                                                        <div style="color:var(--grey-500);margin-bottom:3px">加班費合計</div>
                                                        <button type="button"
                                                            style="font-size:0.75em;background:none;border:1px solid var(--grey-300);border-radius:4px;padding:2px 7px;cursor:pointer;color:var(--grey-500)"
                                                            onclick="toggleOTFormula(this)">📐 查看加班費計算 ▼</button>
                                                    </div>
                                                    <span style="font-weight:700;color:var(--amber-500)">+$<?php echo number_format($ymOTPay); ?></span>
                                                </div>
                                                <div class="ot-formula-detail" style="display:none;background:#FFFDE7;border-bottom:1px dashed #eee;padding:12px 14px;color:var(--grey-700);line-height:1.8">
                                                    <div style="margin-bottom:6px;color:var(--grey-600)">
                                                        月薪 $<?php echo number_format($monthlyWage); ?> ÷ 30 ÷ 8 ＝ 時薪 $<?php echo round($ymHrRate, 2); ?> 元
                                                    </div>
                                                    <div style="margin-bottom:10px;color:var(--grey-500);font-size:0.9em">
                                                        🔶 前2h（×4/3）：$<?php echo round($ymHrRate * 4/3, 2); ?> ／h　　🔴 第3h起（×5/3）：$<?php echo round($ymHrRate * 5/3, 2); ?> ／h
                                                    </div>
                                                    <?php foreach ($ymOTDays as $otRow):
                                                        $ot1h = min($otRow['overtime_hours'], 2);
                                                        $ot2h = max($otRow['overtime_hours'] - 2, 0);
                                                    ?>
                                                    <div style="padding:7px 10px;background:white;border-radius:6px;border:1px solid #FFF176;margin-bottom:5px">
                                                        <div style="font-weight:700;color:var(--grey-700);margin-bottom:3px"><?php echo $otRow['work_date']; ?> 加班 <?php echo $otRow['overtime_hours']; ?>h</div>
                                                        <?php if ($ot1h > 0): ?><div style="color:#F57F17">🔶 前<?php echo round($ot1h,2); ?>h × $<?php echo round($ymHrRate*4/3,2); ?>（×4/3）= $<?php echo (int)ceil($ot1h*$ymHrRate*4/3); ?></div><?php endif; ?>
                                                        <?php if ($ot2h > 0): ?><div style="color:#C62828">🔴 後<?php echo round($ot2h,2); ?>h × $<?php echo round($ymHrRate*5/3,2); ?>（×5/3）= $<?php echo (int)ceil($ot2h*$ymHrRate*5/3); ?></div><?php endif; ?>
                                                        <div style="font-weight:700;color:var(--amber-600);margin-top:2px">小計：$<?php echo $otRow['overtime_pay']; ?></div>
                                                    </div>
                                                    <?php endforeach; ?>
                                                    <div style="display:flex;justify-content:space-between;background:#FFF8E1;border-radius:6px;padding:7px 10px;border:1px solid #FFE082;margin-top:3px">
                                                        <span style="font-weight:700;color:var(--amber-600)">加班費合計</span>
                                                        <span style="font-weight:700;color:var(--amber-600)">$<?php echo number_format($ymOTPay); ?></span>
                                                    </div>
                                                </div>
                                                <?php endif; ?>
                                                <?php if ($ymNightPay > 0): ?>
                                                <div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px dashed #eee">
                                                    <span style="color:var(--grey-500)">🌙 夜班津貼</span>
                                                    <span style="font-weight:700;color:var(--purple-600)">+$<?php echo number_format($ymNightPay); ?></span>
                                                </div>
                                                <?php endif; ?>
                                                <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 0;background:var(--green-50);border-top:2px solid #A5D6A7;margin-top:4px">
                                                    <span style="font-weight:700;color:var(--green-700);font-size:1.1em">實領金額</span>
                                                    <span style="font-weight:700;color:var(--green-700);font-size:1.3em"><?php echo $ymMd ? '$'.number_format($ymNet) : '（待勞健保記錄）'; ?></span>
                                                </div>
                                            <?php else: ?>
                                                <?php
                                                    $ymHrWage   = (int)($selEmpData['hourly_rate'] ?? 0);
                                                    $ymNightDays = count(array_filter($ymRows, fn($r) => $r['night_pay'] > 0));
                                                    $ymWageOnly = $ymTotalSal - $ymNightPay;
                                                ?>
                                                <div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px dashed #eee">
                                                    <span style="color:var(--grey-500)">時薪薪資（<?php echo $ymTotalHrs; ?>h × $<?php echo $ymHrWage; ?>）</span>
                                                    <span style="font-weight:700">$<?php echo number_format($ymWageOnly); ?></span>
                                                </div>
                                                <?php if ($ymNightPay > 0): ?>
                                                <div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px dashed #eee">
                                                    <span style="color:var(--grey-500)">🌙 夜班津貼（<?php echo $ymNightDays; ?>天 × $<?php echo $selNightAllow; ?>）</span>
                                                    <span style="font-weight:700;color:var(--purple-600)">+$<?php echo number_format($ymNightPay); ?></span>
                                                </div>
                                                <?php endif; ?>
                                                <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 0;background:var(--green-50);border-top:2px solid #A5D6A7;margin-top:4px">
                                                    <span style="font-weight:700;color:var(--green-700);font-size:1.1em">本月薪資合計</span>
                                                    <span style="font-weight:700;color:var(--green-700);font-size:1.3em">$<?php echo number_format($ymTotalSal); ?></span>
                                                </div>
                                            <?php endif; ?>
                                            </div>
                                        </div>
                                        </td>
                                    </tr>

                                <?php endforeach; // end yearGrouped ?>
                                <?php else: ?>
                                <?php foreach ($attendances as $att): ?>
                                    <tr data-id="<?php echo $att['id']; ?>"
                                        data-date="<?php echo $att['work_date']; ?>"
                                        data-s1s="<?php echo htmlspecialchars(fmtTime($att['s1_start'] ?? '')); ?>"
                                        data-s1e="<?php echo htmlspecialchars(fmtTime($att['s1_end']   ?? '')); ?>"
                                        data-s2s="<?php echo htmlspecialchars(fmtTime($att['s2_start'] ?? '')); ?>"
                                        data-s2e="<?php echo htmlspecialchars(fmtTime($att['s2_end']   ?? '')); ?>"
                                        data-break="<?php echo $att['has_break'] ? '1' : '0'; ?>"
                                        data-night="<?php echo $att['night_pay']; ?>"
                                        data-s1="<?php echo ($att['s1_start']&&$att['s1_end']) ? htmlspecialchars(fmtTime($att['s1_start'])).'→'.htmlspecialchars(fmtTime($att['s1_end'])) : '—'; ?>"
                                        data-s2="<?php echo ($att['s2_start']&&$att['s2_end']) ? htmlspecialchars(fmtTime($att['s2_start'])).'→'.htmlspecialchars(fmtTime($att['s2_end'])) : '—'; ?>"
                                        data-hours="<?php echo $att['total_hours']; ?>"
                                        data-ot="<?php echo $att['overtime_pay']; ?>"
                                        data-salary="<?php echo number_format($att['salary']); ?>">
                                        <?php if ($isAdmin): ?><td><input type="checkbox" class="row-chk" onchange="onRowCheck()" value="<?php echo $att['id']; ?>"></td><?php endif; ?>
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
                                                    <button type="button" class="btn btn-danger btn-sm"
                                                        onclick="openDeleteModal([<?php echo $att['id']; ?>])">🗑️</button>
                                                </div>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div><!-- /results-area -->

            <!-- ══ 刪除確認 Modal ══ -->
            <div id="delete-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9000;align-items:center;justify-content:center;padding:16px;backdrop-filter:blur(2px)">
                <div style="background:white;border-radius:16px;max-width:620px;width:100%;max-height:85vh;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,0.3)">
                    <div style="padding:20px 24px 16px;border-bottom:2px solid #FFEBEE;display:flex;justify-content:space-between;align-items:center">
                        <div>
                            <div style="font-weight:800;font-size:1.05em;color:var(--red-600)">🗑️ 確認刪除</div>
                            <div style="font-size:0.8em;color:var(--grey-400);margin-top:3px">此操作無法復原</div>
                        </div>
                        <button type="button" onclick="closeDeleteModal()" style="background:var(--grey-100);border:none;width:32px;height:32px;border-radius:50%;font-size:1em;cursor:pointer;color:var(--grey-500);display:flex;align-items:center;justify-content:center">✕</button>
                    </div>
                    <div style="padding:16px 24px;overflow-y:auto">
                        <div style="font-size:0.85em;color:var(--grey-600);margin-bottom:14px;padding:10px 14px;background:#FFF3F3;border-radius:8px;border-left:4px solid var(--red-400)">
                            ⚠️ 以下 <strong id="delete-count">0</strong> 筆出勤紀錄將被永久刪除：
                        </div>
                        <div style="overflow-x:auto;border-radius:8px;border:1px solid #FFCDD2">
                        <table style="width:100%;border-collapse:collapse;font-size:0.88em">
                            <thead>
                                <tr style="background:#FFEBEE">
                                    <th style="padding:10px 12px;text-align:left;color:var(--red-600);white-space:nowrap;font-weight:700">日期</th>
                                    <th style="padding:10px 12px;text-align:center;color:var(--red-600);white-space:nowrap;font-weight:700">第一段</th>
                                    <th style="padding:10px 12px;text-align:center;color:var(--red-600);white-space:nowrap;font-weight:700">第二段</th>
                                    <th style="padding:10px 12px;text-align:center;color:var(--red-600);white-space:nowrap;font-weight:700">工時</th>
                                    <th style="padding:10px 12px;text-align:right;color:var(--red-600);white-space:nowrap;font-weight:700">薪資</th>
                                </tr>
                            </thead>
                            <tbody id="delete-modal-tbody"></tbody>
                        </table>
                        </div>
                    </div>
                    <div style="padding:16px 24px;border-top:1px solid #eee;display:flex;gap:10px;justify-content:flex-end;background:#FAFAFA;border-radius:0 0 16px 16px">
                        <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()" style="min-width:80px">取消</button>
                        <button type="button" class="btn btn-danger" onclick="confirmDelete()" id="confirm-delete-btn" style="min-width:100px">確定刪除</button>
                    </div>
                </div>
            </div>

            <!-- ══ 多筆編輯 Modal ══ -->
            <div id="bulk-edit-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9000;align-items:center;justify-content:center;padding:16px;backdrop-filter:blur(2px)">
                <div style="background:white;border-radius:16px;max-width:720px;width:100%;max-height:90vh;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,0.3)">
                    <div style="padding:20px 24px 16px;border-bottom:2px solid var(--green-100);display:flex;justify-content:space-between;align-items:center">
                        <div>
                            <div style="font-weight:800;font-size:1.05em;color:var(--green-700)">✏️ 多筆編輯出勤紀錄</div>
                            <div style="font-size:0.8em;color:var(--grey-400);margin-top:3px">逐筆修改後點擊「儲存所有變更」</div>
                        </div>
                        <button type="button" onclick="closeBulkEdit()" style="background:var(--grey-100);border:none;width:32px;height:32px;border-radius:50%;font-size:1em;cursor:pointer;color:var(--grey-500);display:flex;align-items:center;justify-content:center">✕</button>
                    </div>
                    <div style="padding:16px 24px;overflow-y:auto;flex:1">
                        <form id="bulk-edit-form" method="post">
                            <input type="hidden" name="action" value="bulk_edit">
                            <?php echo implode('', array_map(fn($k) => '<input type="hidden" name="preserve_' . htmlspecialchars($k) . '" value="' . htmlspecialchars($_GET[$k] ?? '') . '">', ['emp','ym','year','mode','searched'])); ?>
                            <div id="bulk-edit-rows" style="display:flex;flex-direction:column;gap:14px"></div>
                        </form>
                    </div>
                    <div style="padding:16px 24px;border-top:1px solid #eee;display:flex;gap:10px;justify-content:flex-end;background:#FAFAFA;border-radius:0 0 16px 16px">
                        <button type="button" class="btn btn-secondary" onclick="closeBulkEdit()" style="min-width:80px">取消</button>
                        <button type="button" class="btn btn-primary" onclick="submitBulkEdit()" style="min-width:120px">💾 儲存所有變更</button>
                    </div>
                </div>
            </div>

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

    // ── 年份月份折疊 ──
    function toggleYM(ymId, headerRow) {
        const rows  = document.querySelectorAll('.ym-row-' + ymId);
        const arrow = document.getElementById('arrow-' + ymId);
        const open  = rows.length > 0 && rows[0].style.display === 'none';
        rows.forEach(r => r.style.display = open ? '' : 'none');
        if (arrow) arrow.textContent = open ? '▼' : '▶';
        if (!open) {
            rows.forEach(r => { const c = r.querySelector('.row-chk'); if(c) c.checked = false; });
            const ymChk = document.getElementById('ymchk-' + ymId);
            if (ymChk) ymChk.checked = false;
            updateBulkButtons();
        }
    }

    // ── 月份全選（年份模式）──
    function toggleYMAll(ymId, chk) {
        const rows = document.querySelectorAll('.ym-row-' + ymId + ':not(.ym-subheader):not(.ym-summary-row)');
        rows.forEach(r => { const c = r.querySelector('.row-chk'); if(c) c.checked = chk.checked; });
        updateBulkButtons();
    }

    // ── Checkbox 多選邏輯 ──
    function toggleAll(chk) {
        document.querySelectorAll('.row-chk').forEach(c => c.checked = chk.checked);
        updateBulkButtons();
    }
    function onRowCheck() {
        const all   = document.querySelectorAll('.row-chk');
        const chked = document.querySelectorAll('.row-chk:checked');
        const allChk = document.getElementById('chk-all');
        if (allChk) allChk.checked = all.length === chked.length && all.length > 0;
        updateBulkButtons();
    }
    function updateBulkButtons() {
        const n    = document.querySelectorAll('.row-chk:checked').length;
        const bE   = document.getElementById('btn-bulk-edit');
        const bD   = document.getElementById('btn-bulk-delete');
        const sc   = document.getElementById('sel-count');
        const sc2  = document.getElementById('sel-count2');
        if (bE)  { bE.style.display  = n > 0 ? '' : 'none'; }
        if (bD)  { bD.style.display  = n > 0 ? '' : 'none'; }
        if (sc)  sc.textContent  = n;
        if (sc2) sc2.textContent = n;
    }
    function getCheckedRows() {
        return Array.from(document.querySelectorAll('.row-chk:checked'))
            .map(c => c.closest('tr'));
    }

    // ── 刪除確認 Modal ──
    let pendingDeleteIds = [];
    function openDeleteModal(idsArg) {
        // idsArg: 單筆傳 [id]；多筆不傳，從 checkbox 取
        if (idsArg && idsArg.length > 0) {
            pendingDeleteIds = idsArg;
            const tr = document.querySelector('tr[data-id="' + idsArg[0] + '"]');
            renderDeleteRows(tr ? [tr] : []);
        } else {
            const rows = getCheckedRows();
            pendingDeleteIds = rows.map(r => parseInt(r.dataset.id));
            renderDeleteRows(rows);
        }
        const cnt = document.getElementById('delete-count');
        if (cnt) cnt.textContent = pendingDeleteIds.length;
        document.getElementById('delete-modal').style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
    function renderDeleteRows(rows) {
        const tbody = document.getElementById('delete-modal-tbody');
        tbody.innerHTML = rows.map(tr => {
            const d = tr.dataset;
            return `<tr style="border-bottom:1px solid #eee">
                <td style="padding:8px 10px;border:1px solid #eee;font-weight:700;white-space:nowrap">${d.date||''}</td>
                <td style="padding:8px 10px;border:1px solid #eee;text-align:center;white-space:nowrap">${d.s1||'—'}</td>
                <td style="padding:8px 10px;border:1px solid #eee;text-align:center;white-space:nowrap">${d.s2||'—'}</td>
                <td style="padding:8px 10px;border:1px solid #eee;text-align:center;white-space:nowrap">${d.hours||''}h</td>
                <td style="padding:8px 10px;border:1px solid #eee;text-align:right;white-space:nowrap;font-weight:700;color:#E53935">$${d.salary||'0'}</td>
            </tr>`;
        }).join('');
    }
    function closeDeleteModal() {
        document.getElementById('delete-modal').style.display = 'none';
        document.body.style.overflow = '';
        pendingDeleteIds = [];
    }
    function confirmDelete() {
        if (pendingDeleteIds.length === 0) return;
        const form = document.createElement('form');
        form.method = 'post';
        form.style.display = 'none';
        const addHidden = (n, v) => { const i = document.createElement('input'); i.type='hidden'; i.name=n; i.value=v; form.appendChild(i); };
        if (pendingDeleteIds.length === 1) {
            addHidden('action', 'delete');
            addHidden('id', pendingDeleteIds[0]);
        } else {
            addHidden('action', 'bulk_delete');
            pendingDeleteIds.forEach(id => addHidden('ids[]', id));
        }
        // 保留查詢參數
        ['emp','ym','year','mode','searched'].forEach(k => {
            const v = new URLSearchParams(location.search).get(k);
            if (v) addHidden(k, v);
        });
        document.body.appendChild(form);
        form.submit();
    }

    // ── 多筆編輯 Modal ──
    function openBulkEdit() {
        const rows = getCheckedRows();
        if (rows.length === 0) return;
        document.body.style.overflow = 'hidden';
        const container = document.getElementById('bulk-edit-rows');
        container.innerHTML = rows.map(tr => {
            const d = tr.dataset;
            const id = d.id;
            const breakChecked = d.break === '1' ? 'selected' : '';
            const noBreakChecked = d.break !== '1' ? 'selected' : '';
            return `<div style="background:#F8F9FA;border-radius:var(--radius-md);padding:14px 16px;border:1px solid #eee">
                <div style="font-size:0.82em;font-weight:700;color:var(--green-700);margin-bottom:12px">📅 ${d.date}</div>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px;align-items:end">
                    <div>
                        <label style="font-size:0.75em;color:var(--grey-500);font-weight:600;display:block;margin-bottom:4px">🔵 第一段上班</label>
                        <input type="text" name="rows[${id}][s1_start]" class="edit-time-input" value="${d.s1s||''}" placeholder="08:00" inputmode="numeric" maxlength="5" style="width:100%;box-sizing:border-box">
                    </div>
                    <div>
                        <label style="font-size:0.75em;color:var(--grey-500);font-weight:600;display:block;margin-bottom:4px">🟢 第一段下班</label>
                        <input type="text" name="rows[${id}][s1_end]" class="edit-time-input" value="${d.s1e||''}" placeholder="17:00" inputmode="numeric" maxlength="5" style="width:100%;box-sizing:border-box">
                    </div>
                    <div>
                        <label style="font-size:0.75em;color:var(--grey-500);font-weight:600;display:block;margin-bottom:4px">🟣 第二段上班</label>
                        <input type="text" name="rows[${id}][s2_start]" class="edit-time-input" value="${d.s2s||''}" placeholder="（可空白）" inputmode="numeric" maxlength="5" style="width:100%;box-sizing:border-box">
                    </div>
                    <div>
                        <label style="font-size:0.75em;color:var(--grey-500);font-weight:600;display:block;margin-bottom:4px">⚫ 第二段下班</label>
                        <input type="text" name="rows[${id}][s2_end]" class="edit-time-input" value="${d.s2e||''}" placeholder="（可空白）" inputmode="numeric" maxlength="5" style="width:100%;box-sizing:border-box">
                    </div>
                    <div>
                        <label style="font-size:0.75em;color:var(--grey-500);font-weight:600;display:block;margin-bottom:4px">☕ 有無休息</label>
                        <select name="rows[${id}][has_break]" class="form-select" style="width:100%;box-sizing:border-box">
                            <option value="1" ${d.break==='1'?'selected':''}>✅ 有休息</option>
                            <option value="0" ${d.break!=='1'?'selected':''}>⚡ 沒休息</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:0.75em;color:var(--grey-500);font-weight:600;display:block;margin-bottom:4px">🌙 夜班津貼($)</label>
                        <input type="number" name="rows[${id}][night_pay]" value="${d.night||0}" min="0" class="form-input" style="width:100%;box-sizing:border-box">
                    </div>
                </div>
            </div>`;
        }).join('');
        // 套用時間輸入格式化
        container.querySelectorAll('.edit-time-input').forEach(attachTimeFormat);
        document.getElementById('bulk-edit-modal').style.display = 'flex';
    }
    function closeBulkEdit() {
        document.getElementById('bulk-edit-modal').style.display = 'none';
        document.body.style.overflow = '';
    }
    function submitBulkEdit() {
        const form = document.getElementById('bulk-edit-form');
        // 從 URL 補齊查詢參數（preserve_ hidden input 已在 PHP 端填入）
        const params = new URLSearchParams(location.search);
        ['emp','ym','year','mode','searched'].forEach(k => {
            const v = params.get(k);
            if (v) {
                let inp = form.querySelector('[name="' + k + '"]');
                if (!inp) {
                    inp = document.createElement('input');
                    inp.type = 'hidden';
                    inp.name = k;
                    form.appendChild(inp);
                }
                inp.value = v;
            }
        });
        form.submit();
    }

    // 時間輸入格式化（attach 到任意 input）
    function attachTimeFormat(input) {
        input.setAttribute('inputmode','numeric');
        input.setAttribute('maxlength','5');
        input.addEventListener('input', function() {
            let v = this.value.replace(/[^0-9]/g,'').slice(0,4);
            if (v.length === 4) this.value = v.slice(0,2) + ':' + v.slice(2);
            else this.value = v;
        });
        input.addEventListener('blur', function() {
            const p = this.value.split(':');
            if (p.length === 2) {
                const h = parseInt(p[0]), m = parseInt(p[1]);
                if (!isNaN(h) && !isNaN(m))
                    this.value = String(h).padStart(2,'0') + ':' + String(m).padStart(2,'0');
            }
        });
    }

    function toggleInsFormula(btn) {
        const row    = btn.closest('div').parentElement;
        const detail = row ? row.nextElementSibling : null;
        if (!detail || !detail.classList.contains('ins-formula-detail')) return;
        const open = detail.style.display === 'none';
        detail.style.display = open ? 'block' : 'none';
        btn.textContent = open ? '📐 收起計算公式 ▲' : '📐 查看計算公式 ▼';
    }

    function toggleOTFormula(btn) {
        const row    = btn.closest('div').parentElement;
        const detail = row ? row.nextElementSibling : null;
        if (!detail || !detail.classList.contains('ot-formula-detail')) return;
        const open = detail.style.display === 'none';
        detail.style.display = open ? 'block' : 'none';
        btn.textContent = open ? '📐 收起加班費計算 ▲' : '📐 查看加班費計算 ▼';
    }

    function toggleHourlyFormula(btn) {
        const row    = btn.closest('div').parentElement;
        const detail = row ? row.nextElementSibling : null;
        if (!detail || !detail.classList.contains('hourly-formula-detail')) return;
        const open = detail.style.display === 'none';
        detail.style.display = open ? 'block' : 'none';
        btn.textContent = open ? '📐 收起計算公式 ▲' : '📐 查看計算公式 ▼';
    }

    function toggleNightFormula(btn) {
        const row    = btn.closest('div').parentElement;
        const detail = row ? row.nextElementSibling : null;
        if (!detail || !detail.classList.contains('night-formula-detail')) return;
        const open = detail.style.display === 'none';
        detail.style.display = open ? 'block' : 'none';
        btn.textContent = open ? '📐 收起計算公式 ▲' : '📐 查看計算公式 ▼';
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