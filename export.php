<?php
require_once 'config.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. 接收確認後的資料
    $name   = $_POST['name'];
    $start  = $_POST['start'];
    $end    = $_POST['end'];
    $salary = $_POST['salary'];
    $date   = date('Y-m-d'); // 今天的日期

    $fileName = '飲料店_2026_04_出勤紀錄.xlsx';

    // 2. 判斷檔案是否已存在，存在就讀取，不存在就建立新的
    if (file_exists($fileName)) {
        $spreadsheet = IOFactory::load($fileName);
        $sheet = $spreadsheet->getActiveSheet();
        $lastRow = $sheet->getHighestRow() + 1; // 找到最後一行，準備往下寫
    } else {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('出勤紀錄');
        
        // 設定標題列
        $sheet->setCellValue('A1', '日期');
        $sheet->setCellValue('B1', '員工姓名');
        $sheet->setCellValue('C1', '上班時間');
        $sheet->setCellValue('D1', '下班時間');
        $sheet->setCellValue('E1', '當日薪資(預算)');
        
        $lastRow = 2;
    }

    // 3. 寫入本次打卡資料
    $sheet->setCellValue('A' . $lastRow, $date);
    $sheet->setCellValue('B' . $lastRow, $name);
    $sheet->setCellValue('C' . $lastRow, $start);
    $sheet->setCellValue('D' . $lastRow, $end);
    $sheet->setCellValue('E' . $lastRow, $salary);

    // 自動調整欄寬
    foreach (range('A', 'E') as $columnID) {
        $sheet->getColumnDimension($columnID)->setAutoSize(true);
    }

    // 4. 儲存並下載
    $writer = new Xlsx($spreadsheet);
    
    // 如果您想直接在伺服器上累積檔案而不下載，改用 $writer->save($fileName);
    // 這裡我們設計成下載模式，讓您手機可以看到結果
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $fileName . '"');
    header('Cache-Control: max-age=0');
    
    $writer->save('php://output');
    exit;
}