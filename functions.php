<?php
/**
 * 薪資與時間計算邏輯
 */

// =============================================
// 月薪換算時薪（勞基法：月薪 ÷ 30 ÷ 8）
// =============================================
function monthlyToHourly(int $monthlySalary): float {
    return round($monthlySalary / 30 / 8, 4);
}

// =============================================
// 正職員工：超過 8 小時才計算加班費（依勞基法）
// $hourlyRate 已是換算後的時薪
// =============================================
function calculateSalaryFulltime($startTime, $endTime, float $hourlyRate, bool $hasBreak = false): array {
    $start    = new DateTime($startTime);
    $end      = new DateTime($endTime);
    if ($end < $start) $end->modify('+1 day'); // 跨夜處理

    $interval = $start->diff($end);
    $totalHours = $interval->h + ($interval->i / 60) + ($interval->days * 24);

    // 有勾選休息：扣除 1 小時後再計算
    $breakTime       = $hasBreak ? 1.0 : 0;
    $actualWorkHours = max($totalHours - $breakTime, 0);

    // 加班費計算（依勞基法，超過 8h5min 才算加班）
    $OT_THRESHOLD = 8 + 5 / 60;
    $normalHours = 0;
    $overtime1   = 0; // 前 2 小時加班（×4/3）
    $overtime2   = 0; // 超過 2 小時後（×5/3）

    if ($actualWorkHours <= $OT_THRESHOLD) {
        $normalHours = $actualWorkHours;
    } else {
        $normalHours = $OT_THRESHOLD;
        $remaining   = $actualWorkHours - $OT_THRESHOLD;
        if ($remaining <= 2) {
            $overtime1 = $remaining;
        } else {
            $overtime1 = 2;
            $overtime2 = $remaining - 2;
        }
    }

    $overtimeHours = $overtime1 + $overtime2;
    $overtimePay   = ($overtime1 * $hourlyRate * 4/3) + ($overtime2 * $hourlyRate * 5/3);
    $salary        = ($normalHours * $hourlyRate) + $overtimePay;

    return [
        'total_hours'    => round($actualWorkHours, 2),
        'normal_hours'   => round($normalHours,     2),
        'overtime_hours' => round($overtimeHours,   2),
        'overtime_pay'   => (int)round($overtimePay),
        'salary'         => (int)round($salary),
        'hourly_rate'    => round($hourlyRate, 4),
        'has_break'      => $hasBreak,
        'type'           => 'fulltime',
    ];
}

// =============================================
// 時薪制員工：時薪 × 工時，無加班費
// =============================================
function calculateSalaryHourly($startTime, $endTime, int $hourlyRate): array {
    $start    = new DateTime($startTime);
    $end      = new DateTime($endTime);
    if ($end < $start) $end->modify('+1 day');

    $interval   = $start->diff($end);
    $totalHours = $interval->h + ($interval->i / 60) + ($interval->days * 24);
    $salary     = $totalHours * $hourlyRate;

    return [
        'total_hours'    => round($totalHours, 2),
        'normal_hours'   => round($totalHours, 2),
        'overtime_hours' => 0,
        'overtime_pay'   => 0,
        'salary'         => (int)round($salary),
        'hourly_rate'    => $hourlyRate,
        'has_break'      => false,
        'type'           => 'hourly',
    ];
}

// =============================================
// 統一入口
// 正職傳入月薪 → 內部自動換算時薪
// 時薪制傳入時薪
// =============================================
function calculateSalary($startTime, $endTime, $wage, string $empType = 'fulltime', bool $hasBreak = false): array {
    if ($empType === 'hourly') {
        return calculateSalaryHourly($startTime, $endTime, (int)$wage);
    }
    $hourlyRate = monthlyToHourly((int)$wage);
    return calculateSalaryFulltime($startTime, $endTime, $hourlyRate, $hasBreak);
}

// =============================================
// 時間格式化：截掉秒數，只保留 HH:MM
// 支援 "HH:MM:SS"、"HH:MM"、空值
// =============================================
function fmtTime(string $t): string {
    if (empty($t)) return '';
    // 只取前5碼（HH:MM）
    return substr($t, 0, 5);
}

// =============================================
// 正職加班費核算公式說明（供 UI 顯示）
// =============================================
function getOvertimeFormula(int $monthlySalary): array {
    $h = monthlyToHourly($monthlySalary);
    return [
        'hourly_rate'    => round($h, 2),
        'ot1_rate'       => round($h * 4/3, 2),
        'ot2_rate'       => round($h * 5/3, 2),
        'formula_text'   => sprintf(
            "月薪 %s ÷ 30 ÷ 8 = 時薪 %s 元\n" .
            "正常工時（8h5min內）：%s × 時數\n" .
            "加班前2小時（×4/3≈1.3333）：%s × 時數\n" .
            "加班第3小時起（×5/3≈1.6667）：%s × 時數\n" .
            "※ 勾選休息：扣除 1 小時後再計算",
            number_format($monthlySalary),
            round($h, 2),
            round($h, 2),
            round($h * 4/3, 2),
            round($h * 5/3, 2)
        ),
    ];
}