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
function calculateSalaryFulltime($startTime, $endTime, float $hourlyRate, bool $hasBreak = true): array {
    $start    = new DateTime($startTime);
    $end      = new DateTime($endTime);
    if ($end < $start) $end->modify('+1 day'); // 跨夜處理

    $interval = $start->diff($end);
    $totalHours = $interval->h + ($interval->i / 60) + ($interval->days * 24);

    // 只有選擇「有休息」且工時 >= 8 小時才扣 0.5 小時
    $breakTime       = ($hasBreak && $totalHours >= 8) ? 0.5 : 0;
    $actualWorkHours = max($totalHours - $breakTime, 0);

    // 加班費計算（依勞基法）
    $normalHours = 0;
    $overtime1   = 0; // 前 2 小時加班（1.34 倍）
    $overtime2   = 0; // 超過 2 小時後（1.67 倍）

    if ($actualWorkHours <= 8) {
        $normalHours = $actualWorkHours;
    } else {
        $normalHours = 8;
        $remaining   = $actualWorkHours - 8;
        if ($remaining <= 2) {
            $overtime1 = $remaining;
        } else {
            $overtime1 = 2;
            $overtime2 = $remaining - 2;
        }
    }

    $overtimeHours = $overtime1 + $overtime2;
    $overtimePay   = ($overtime1 * $hourlyRate * 1.34) + ($overtime2 * $hourlyRate * 1.67);
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
function calculateSalary($startTime, $endTime, $wage, string $empType = 'fulltime', bool $hasBreak = true): array {
    if ($empType === 'hourly') {
        return calculateSalaryHourly($startTime, $endTime, (int)$wage);
    }
    $hourlyRate = monthlyToHourly((int)$wage);
    return calculateSalaryFulltime($startTime, $endTime, $hourlyRate, $hasBreak);
}

// =============================================
// 正職加班費核算公式說明（供 UI 顯示）
// =============================================
function getOvertimeFormula(int $monthlySalary): array {
    $h = monthlyToHourly($monthlySalary);
    return [
        'hourly_rate'    => round($h, 2),
        'ot1_rate'       => round($h * 1.34, 2),
        'ot2_rate'       => round($h * 1.67, 2),
        'formula_text'   => sprintf(
            "月薪 %s ÷ 30 ÷ 8 = 時薪 %s 元\n" .
            "正常工時（8h內）：%s × 時數\n" .
            "加班前2小時（×1.34）：%s × 時數\n" .
            "加班第3小時起（×1.67）：%s × 時數",
            number_format($monthlySalary),
            round($h, 2),
            round($h, 2),
            round($h * 1.34, 2),
            round($h * 1.67, 2)
        ),
    ];
}
