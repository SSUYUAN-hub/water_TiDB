<?php
session_start();
require_once __DIR__ . '/vendor/autoload.php';
include_once __DIR__ . '/functions.php';
include_once __DIR__ . '/db.php';
include_once __DIR__ . '/auth.php';
requireLogin();

if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}
$apiKey = $_ENV['GOOGLE_API_KEY'] ?? $_SERVER['GOOGLE_API_KEY'] ?? '';

$employeeName   = $_POST['employee_name'] ?? '未命名';
$empData        = getEmployee($employeeName);
$empType        = $empData['type']            ?? 'hourly';
$wage           = (int)($empData['hourly_rate'] ?? 180); // 正職=月薪, 時薪制=時薪
$hourlyRate     = ($empType === 'fulltime') ? round($wage / 30 / 8, 4) : $wage;
$nightAllowance = (int)($empData['night_allowance'] ?? 0);
$yearMonth      = $_POST['prefill_yearmonth'] ?? date('Y-m');

$parsedDays    = [];
$errorMsg      = '';
$debugInfo     = ''; // 開發除錯用
$isSide2       = isset($_POST['carry_side1']) && !empty($_POST['carry_side1']);
$side1Days     = $isSide2 ? (json_decode($_POST['carry_side1'], true) ?? []) : [];
$skipScan      = isset($_POST['skip_scan']) && $_POST['skip_scan'] === '1';

// ── 不掃第二面：把 carry_side1 轉成模板需要的結構後顯示 ──────
if ($skipScan && $isSide2) {
    foreach ($side1Days as $s1) {
        $s1s = $s1['s1_start'] ?? '';
        $s1e = $s1['s1_end']   ?? '';
        $s2s = $s1['s2_start'] ?? '';
        $s2e = $s1['s2_end']   ?? '';
        // 計算預覽薪資
        $previewHours = 0; $previewSalary = 0; $previewOT = 0;
        if ($s1s && $s1e) {
            $cal = calculateSalary($s1s, $s1e, $wage, $empType, ($s1['has_break'] ?? '1') === '1');
            $previewHours  = $cal['total_hours'];
            $previewSalary = $cal['salary'];
            $previewOT     = $cal['overtime_hours'] ?? 0;
        }
        if ($s2s && $s2e) {
            $cal2 = calculateSalary($s2s, $s2e, $wage, $empType, false);
            $previewHours  += $cal2['total_hours'];
            $previewSalary += $cal2['salary'];
        }
        $parsedDays[] = [
            'date'        => $s1['date'],
            'shift1_start'=> $s1s,
            'shift1_end'  => $s1e,
            'shift2_start'=> $s2s,
            'shift2_end'  => $s2e,
            'has_break'   => $s1['has_break']   ?? '1',
            'apply_night' => $s1['apply_night'] ?? '0',
            'is_night'    => ($s1['apply_night'] ?? '0') === '1',
            'preview'     => [
                'total_hours'    => round($previewHours, 2),
                'salary'         => $previewSalary,
                'overtime_hours' => $previewOT,
            ],
        ];
    }
    $isSide2   = false;
    $side1Days = [];
}

if (!$skipScan && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['card_image'])) {
    try {
        $rawImage  = file_get_contents($_FILES['card_image']['tmp_name']);
        $imageData = base64_encode($rawImage);

        // ── 呼叫 Google Vision API ──────────────────────
        $payload = json_encode([
            "requests" => [[
                "image"    => ["content" => $imageData],
                "features" => [["type" => "DOCUMENT_TEXT_DETECTION"]] // 比 TEXT_DETECTION 更準確
            ]]
        ]);

        $url = "https://vision.googleapis.com/v1/images:annotate?key=" . $apiKey;
        $ch  = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        curl_close($ch);

        $result = json_decode($response, true);

        if (isset($result['error'])) {
            throw new Exception('Vision API 錯誤：' . $result['error']['message']);
        }

        // ── 取得所有文字區塊及其座標 ────────────────────
        $annotations = $result['responses'][0]['textAnnotations'] ?? [];

        if (count($annotations) < 2) {
            $errorMsg = 'Google Vision 無法辨識圖片文字，請確認圖片清晰度';
        } else {
            // 第一個 annotation 是整體文字，跳過
            // 從第二個開始，每個是單一文字區塊
            $tokens = [];
            foreach (array_slice($annotations, 1) as $ann) {
                $text = trim($ann['description'] ?? '');
                if (empty($text)) continue;

                // 取得邊界框的中心 X 座標（用來判斷欄位）
                $vertices = $ann['boundingPoly']['vertices'] ?? [];
                if (count($vertices) < 2) continue;

                $xCoords = array_column($vertices, 'x');
                $yCoords = array_column($vertices, 'y');
                $centerX = (min($xCoords) + max($xCoords)) / 2;
                $centerY = (min($yCoords) + max($yCoords)) / 2;

                $tokens[] = [
                    'text'    => $text,
                    'x'       => $centerX,
                    'y'       => $centerY,
                    'x_min'   => min($xCoords),
                    'x_max'   => max($xCoords),
                ];
            }

            if (empty($tokens)) {
                throw new Exception('無法取得文字座標資訊');
            }

            // ── 判斷圖片寬度，計算各欄位的 X 範圍 ────────
            $allX    = array_column($tokens, 'x');
            $imgMinX = min($allX);
            $imgMaxX = max($allX);
            $imgWidth = $imgMaxX - $imgMinX;

            // ══════════════════════════════════════════════
            // 卡片欄位由左到右（依顏色區分）：
            //  🔴 日期     │⚫ 結束(第二段下班)│🟣 加班(第二段上班)│🟢 下班(第一段下班)│🔵 上班(第一段上班)│備註
            //   0%~15%    │  15%~33%          │  33%~52%           │  52%~72%           │  72%~92%           │92%+
            // ══════════════════════════════════════════════
            // 依偵錯座標校正欄位比例：
            // 日期  relX~0.025  結束  relX~0.208  加班  relX~0.39
            // 下班  relX~0.572  上班  relX~0.794  備註  relX~0.94+
            $colBounds = [
                'date'    => [0.00, 0.12], // 🔴 日期
                's2_end'  => [0.12, 0.32], // ⚫ 結束（第二段下班）
                's2_start'=> [0.32, 0.50], // 🟣 加班上班（第二段上班）
                's1_end'  => [0.50, 0.70], // 🟢 下班（第一段下班）
                's1_start'=> [0.70, 0.92], // 🔵 上班（第一段上班）
                // 0.92+ = 備註欄，忽略
            ];

            // 將每個 token 分配到對應欄位
            $classified = [];
            foreach ($tokens as $tok) {
                $relX = ($tok['x'] - $imgMinX) / $imgWidth;

                $col    = null;
                // 匹配帶冒號時間 12:34 或純數字4位 0200 或3位 200
                // 先做 OCR 錯誤替換再判斷，避免 O200/D200 等被排除
                $textForCheck = strtr($tok['text'], [
                    'O'=>'0','o'=>'0','Q'=>'0','D'=>'0','q'=>'0',
                    'l'=>'1','I'=>'1','i'=>'1','|'=>'1',
                    'S'=>'5','s'=>'5','B'=>'8','Z'=>'2','z'=>'2','?'=>'0',
                ]);
                $textForCheck = preg_replace('/[^0-9:]/', '', $textForCheck);
                $isTime = preg_match('/^\d{1,2}:\d{2}$/', $textForCheck)
                       || preg_match('/^\d{3,4}$/', $textForCheck);
                $isDate = preg_match('/^\d{1,2}$/', $tok['text'])
                          && (int)$tok['text'] >= 1
                          && (int)$tok['text'] <= 31;

                foreach ($colBounds as $colName => [$minRel, $maxRel]) {
                    if ($relX >= $minRel && $relX < $maxRel) {
                        $col = $colName;
                        break;
                    }
                }

                if ($col) {
                    $classified[] = array_merge($tok, [
                        'col'     => $col,
                        'is_time' => $isTime,
                        'is_date' => $isDate,
                    ]);
                }
            }

            // ── 依 Y 座標分組（同一行的 token 歸為同一天）──
            // 先找出所有日期 token，以其 Y 座標為基準
            $dateTokens = array_filter($classified, fn($t) => $t['col'] === 'date' && $t['is_date']);
            usort($dateTokens, fn($a, $b) => $a['y'] <=> $b['y']);

            // ══════════════════════════════════════════════════
            // 找年月：用座標位置直接抓，不依賴「年」「月」文字
            // OCR 常把「年」讀成「#」「$」，「月」讀成「A」「月」等
            // 策略：在圖片上方 25% 區域，依 X 座標位置找民國年和月份
            // 卡片年份在右半部（relX > 0.5），月份在更右側（relX > 0.8）
            // ══════════════════════════════════════════════════
            $fullText = $result['responses'][0]['textAnnotations'][0]['description'] ?? '';
            $yearMonth = date('Y-m'); // 預設當月

            // 取圖片 Y 和 X 範圍
            $allY    = array_column($tokens, 'y');
            $allX2   = array_column($tokens, 'x');
            $imgMinY = min($allY);
            $imgMaxY = max($allY);
            $imgMinX2 = min($allX2);
            $imgMaxX2 = max($allX2);
            $imgH    = max($imgMaxY - $imgMinY, 1);
            $imgW2   = max($imgMaxX2 - $imgMinX2, 1);

            // 只取圖片上方 25% 的 token
            $topYcutoff = $imgMinY + $imgH * 0.25;
            $topTokens2 = array_filter($tokens, fn($t) => $t['y'] <= $topYcutoff);

            // 策略一：直接從座標找年（民國3位數）和月（1-2位數）
            // 年份 token：上方區域、relX 約 0.55~0.80、純數字 100-199
            // 月份 token：上方區域、relX 約 0.80~0.99、純數字 1-12
            $candidateYear  = null;
            $candidateMonth = null;
            $yearX = 0;

            // ── 策略：利用「年」字 token 精確定位月份 ──────────
            // 步驟 1：找到「年」字 token（通常不會被 OCR 誤讀）
            $yearCharX  = null; // 「年」字的 X 座標
            $yearCharY  = null;
            $monthCharX = null; // 「月」字的 X 座標（如果找到）
            $yearTokenY = 0;

            foreach ($topTokens2 as $tok) {
                $text = $tok['text'];
                $cx   = $tok['x'];
                $cy   = $tok['y'];
                if ($text === '年') { $yearCharX = $cx; $yearCharY = $cy; }
                if ($text === '月') { $monthCharX = $cx; }
            }

            // 步驟 2：在「年」字右側找民國年數字（通常在「年」左側，但偶爾順序不同）
            foreach ($topTokens2 as $tok) {
                $cx      = $tok['x'];
                $cy      = $tok['y'];
                $relX    = ($cx - $imgMinX2) / $imgW2;
                $cleaned = strtr($tok['text'], ['l'=>'1','O'=>'0','I'=>'1','o'=>'0','S'=>'5','B'=>'8']);

                if (preg_match('/^([1][0-9]{2})$/', $cleaned, $m)) {
                    $y = (int)$m[1];
                    if ($y >= 100 && $y <= 199 && $relX >= 0.40 && $relX <= 0.88) {
                        $candidateYear = $y;
                        $yearX         = $relX;
                        $yearTokenY    = $cy;
                    }
                }
            }

            // 步驟 3：找月份
            // 方法 A：若找到「年」字，月份必須在「年」字右側（X > yearCharX）
            //         且若找到「月」字，月份在「月」字左側
            // 方法 B：若無「年」字，用 Y 座標約束
            if ($candidateYear !== null) {
                $yTolerance = $imgH * 0.05;
                $bestMonthScore = -1;

                foreach ($topTokens2 as $tok) {
                    $cx       = $tok['x'];
                    $cy       = $tok['y'];
                    $relX     = ($cx - $imgMinX2) / $imgW2;
                    $origText = $tok['text'];
                    $cleaned  = strtr($origText, ['l'=>'1','O'=>'0','I'=>'1','o'=>'0','S'=>'5','B'=>'8']);

                    if (!preg_match('/^([0-9]{1,2})$/', $cleaned, $m)) continue;
                    $mo = (int)$m[1];
                    if ($mo < 1 || $mo > 12) continue;

                    // 基本條件：在年份右側且同一行
                    if (!($relX > $yearX && ($relX - $yearX) <= 0.40
                          && abs($cy - $yearTokenY) <= $yTolerance)) continue;

                    // 計算分數
                    $score = 0;

                    // A. 如果找到「年」字，月份必須在「年」字右側
                    if ($yearCharX !== null) {
                        if ($cx <= $yearCharX) continue; // 在「年」左側，排除
                        $score += 20; // 有「年」字定位，加高分
                    }

                    // B. 如果找到「月」字，月份必須在「月」字左側
                    if ($monthCharX !== null && $cx >= $monthCharX) continue;

                    // C. 原始字元長度：1位數優先（單個「4」比「11」更可能是月份）
                    $score += (strlen($origText) === 1) ? 10 : 3;

                    // D. 離「年」字越近越優先
                    if ($yearCharX !== null) {
                        $distFromYear = $cx - $yearCharX;
                        $score += (int)(5 * max(0, 1 - $distFromYear / 200));
                    }

                    if ($score > $bestMonthScore) {
                        $candidateMonth = $mo;
                        $bestMonthScore = $score;
                    }
                }
            }

            // ── 年月決策 ──────────────────────────────────────
            // OCR 結果先存入 $ocrYearMonth，再決定是否採用
            $ocrYearMonth = null;
            if ($candidateYear !== null && $candidateMonth !== null) {
                $ocrYearMonth = ($candidateYear + 1911) . '-' . str_pad($candidateMonth, 2, '0', STR_PAD_LEFT);
            } else {
                // 策略二：文字比對（支援「年」被誤讀的情況）
                $topTexts2 = array_map(fn($t) => $t['text'], $topTokens2);
                $topStr    = implode(' ', $topTexts2);
                $topClean  = strtr($topStr, ['l'=>'1','O'=>'0','I'=>'1','o'=>'0','A'=>'月','#'=>'年','$'=>'年','&'=>'年']);

                // 民國年月格式（含誤讀版本）
                if (preg_match('/([1][0-9]{2})\\s*[年#\\$&]\\s*([0-9]{1,2})\\s*[月A]/', $topClean, $m)) {
                    $y=(int)$m[1]; $mo=(int)$m[2];
                    if ($y>=100&&$y<=199&&$mo>=1&&$mo<=12)
                        $ocrYearMonth = ($y+1911).'-'.str_pad($mo,2,'0',STR_PAD_LEFT);
                }
                // 純數字格式「115 4」（年後面緊接月）
                elseif (preg_match('/([1][0-9]{2})\\s+([1-9]|1[0-2])(?:\\s|$)/', $topClean, $m)) {
                    $y=(int)$m[1]; $mo=(int)$m[2];
                    if ($y>=100&&$y<=199&&$mo>=1&&$mo<=12)
                        $ocrYearMonth = ($y+1911).'-'.str_pad($mo,2,'0',STR_PAD_LEFT);
                }
            }

            // 第二面掃描時（prefill_yearmonth 有值）→ 鎖定第一面年月，不被 OCR 覆蓋
            // 第一面掃描 → 用 OCR 結果，沒辨識到則維持預設 date('Y-m')
            $prefillYM = $_POST['prefill_yearmonth'] ?? '';
            if (!empty($prefillYM)) {
                $yearMonth = $prefillYM; // 第二面：強制沿用第一面確認的年月
            } elseif ($ocrYearMonth !== null) {
                $yearMonth = $ocrYearMonth; // 第一面：採用 OCR 辨識結果
            }
            // else: 維持 $yearMonth = date('Y-m') 預設值

            // ── 對每個日期，尋找同行的時間 token ──────────
            // 用相鄰日期 token 的平均間距算出行高，取 45% 作容忍（防止串位）
            $dateTokensArr = array_values($dateTokens);
            if (count($dateTokensArr) >= 2) {
                $yDiffs = [];
                for ($di = 1; $di < count($dateTokensArr); $di++) {
                    $diff = $dateTokensArr[$di]['y'] - $dateTokensArr[$di-1]['y'];
                    if ($diff > 0) $yDiffs[] = $diff;
                }
                $avgRowHeight = !empty($yDiffs) ? array_sum($yDiffs) / count($yDiffs) : ($imgH * 0.05);
                $rowTolerance = $avgRowHeight * 0.45;
            } else {
                $rowTolerance = $imgH * 0.025;
            }

            foreach ($dateTokens as $dateTok) {
                $dateNum = (int)$dateTok['text'];
                $dateY   = $dateTok['y'];

                // 找同行的時間 token
                $rowTokens = array_filter($classified, function($t) use ($dateY, $rowTolerance) {
                    return abs($t['y'] - $dateY) <= $rowTolerance && $t['is_time'];
                });

                // 依欄位整理
                $s1_start = $s1_end = $s2_start = $s2_end = '';
                foreach ($rowTokens as $rt) {
                    $timeStr = normalizeTime($rt['text']);
                    switch ($rt['col']) {
                        case 's1_start': $s1_start = $timeStr; break;
                        case 's1_end':   $s1_end   = $timeStr; break;
                        case 's2_start': $s2_start = $timeStr; break;
                        case 's2_end':   $s2_end   = $timeStr; break;
                    }
                }

                // ── 替補規則：第一段下班空白 → 用第二段下班遞補 ──
                // 不需要 s1_start 有值才觸發，只要 s1_end 空且 s2_end 有值即遞補
                if (!$s1_end && $s2_end) {
                    $s1_end = $s2_end;
                    $s2_end = '';
                }

                // 至少要有一個時間才列入
                if (!$s1_start && !$s1_end && !$s2_start && !$s2_end) continue;

                $preview  = previewCalc($s1_start, $s1_end, $s2_start, $s2_end, $wage, $empType);
                $lastEnd  = $s2_end ?: $s1_end;
                $isNight  = ($nightAllowance > 0) && checkNightShift($lastEnd);

                $parsedDays[] = [
                    'date'         => $dateNum,
                    'shift1_start' => $s1_start,
                    'shift1_end'   => $s1_end,
                    'shift2_start' => $s2_start,
                    'shift2_end'   => $s2_end,
                    'preview'      => $preview,
                    'is_night'     => $isNight,
                ];
            }

            // 依日期排序
            usort($parsedDays, fn($a, $b) => $a['date'] <=> $b['date']);

            if (empty($parsedDays)) {
                $errorMsg = '辨識到文字但無法解析出出勤記錄，請確認圖片角度是否正確，或使用手動輸入';
            }
        }

    } catch (Exception $e) {
        $errorMsg = '辨識失敗：' . $e->getMessage();
    }
}

// 時間正規化（處理 OCR 常見錯誤）
function normalizeTime(string $t): string {
    // 替換常見 OCR 錯誤字元（手寫數字特別容易被誤讀）
    $t = strtr($t, [
        'O'=>'0','o'=>'0','Q'=>'0','D'=>'0','q'=>'0',
        'l'=>'1','I'=>'1','i'=>'1','|'=>'1',
        'S'=>'5','s'=>'5',
        'B'=>'8','b'=>'6',
        'Z'=>'2','z'=>'2',
        'G'=>'6','g'=>'9',
        '?'=>'0',
    ]);
    // 統一分隔符為冒號
    $t = preg_replace('/[.\-]/', ':', $t);

    // 去掉非數字非冒號的雜訊字元（保留冒號）
    $tClean = preg_replace('/[^0-9:]/', '', $t);

    // 已有冒號格式：12:34
    if (preg_match('/^(\d{1,2}):(\d{2})$/', $tClean, $m)) {
        $h = (int)$m[1]; $mi = (int)$m[2];
        if ($h <= 29 && $mi <= 59)
            return str_pad($h, 2, '0', STR_PAD_LEFT) . ':' . str_pad($mi, 2, '0', STR_PAD_LEFT);
    }
    // 4位純數字：0200 → 02:00
    if (preg_match('/^(\d{4})$/', $tClean, $m)) {
        $h  = (int)substr($tClean, 0, 2);
        $mi = (int)substr($tClean, 2, 2);
        if ($h <= 29 && $mi <= 59)
            return str_pad($h, 2, '0', STR_PAD_LEFT) . ':' . str_pad($mi, 2, '0', STR_PAD_LEFT);
    }
    // 3位數字：200 → 02:00
    if (preg_match('/^(\d{3})$/', $tClean, $m)) {
        $h  = (int)substr($tClean, 0, 1);
        $mi = (int)substr($tClean, 1, 2);
        if ($h <= 9 && $mi <= 59)
            return '0' . $h . ':' . str_pad($mi, 2, '0', STR_PAD_LEFT);
    }
    return '';
}

// 預覽計算（合併兩段工時）
function previewCalc($s1, $e1, $s2, $e2, $wage, $empType): array {
    $total = 0;
    if ($s1 && $e1) $total += timeDiffHours($s1, $e1);
    if ($s2 && $e2) $total += timeDiffHours($s2, $e2);
    if ($total <= 0) return ['total_hours' => 0, 'overtime_hours' => 0, 'salary' => 0];

    if ($empType === 'hourly') {
        return ['total_hours' => round($total, 2), 'overtime_hours' => 0, 'salary' => (int)round($total * $wage)];
    }
    // 正職：只計算加班費（超過8小時的部分），不足8小時薪資為0
    $h   = round($wage / 30 / 8, 4);
    $ot  = max($total - 8, 0);
    $ot1 = min($ot, 2);
    $ot2 = max($ot - 2, 0);
    $otPay = (int)round($ot1 * $h * 1.34 + $ot2 * $h * 1.67);
    return [
        'total_hours'    => round($total, 2),
        'overtime_hours' => round($ot, 2),
        'salary'         => $otPay, // 只顯示加班費，正常月薪不列入
    ];
}

function timeDiffHours($start, $end): float {
    try {
        $s = new DateTime($start);
        $e = new DateTime($end);
        if ($e < $s) $e->modify('+1 day');
        $d = $s->diff($e);
        return $d->h + ($d->i / 60) + ($d->days * 24);
    } catch (Exception $ex) { return 0; }
}

// 判斷是否觸發夜班（下班 >= 23:00 或凌晨 06:00 前）
function checkNightShift(string $endTime): bool {
    if (empty($endTime)) return false;
    try {
        $endMin = (int)(new DateTime($endTime))->format('H') * 60
                + (int)(new DateTime($endTime))->format('i');
        return ($endMin >= 23 * 60) || ($endMin < 6 * 60);
    } catch (Exception $e) { return false; }
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<title>逐日確認打卡記錄</title>
<link rel="stylesheet" href="responsive.css">
<style>
/* scan.php 專用樣式 */
.break-row {
  margin-top: 10px; display: flex; gap: 8px;
  align-items: center; font-size: 0.82em; flex-wrap: wrap;
}
.break-row label { cursor: pointer; display: flex; align-items: center; gap: 4px; }
.break-tag { padding: 3px 10px; border-radius: 12px; font-size: 0.88em; font-weight: 700; }
.break-tag.yes { background: var(--green-100); color: var(--green-700); }
.break-tag.no  { background: var(--amber-100); color: var(--amber-500); }

/* 兩段班輸入：手機垂直排列，桌機水平 */
.shifts {
  display: grid;
  grid-template-columns: 1fr;
  gap: 10px;
  margin-bottom: 4px;
}
@media (min-width: 480px) {
  .shifts { grid-template-columns: 1fr 1fr; }
}

.shift-block {
  background: #F8F9FA; border-radius: var(--radius-sm);
  padding: 10px 12px;
}
.shift-label {
  font-size: 0.72em; font-weight: 700;
  color: var(--grey-500); margin-bottom: 8px;
  text-transform: uppercase; letter-spacing: 0.04em;
}
.shift-block.shift2 .shift-label { color: var(--purple-600); }

.time-pair {
  display: flex; align-items: center; gap: 6px;
}
.time-pair .time-input {
  flex: 1; min-width: 0;
  border: 1.5px solid #ddd; border-radius: 6px;
  padding: 9px 6px; font-size: 0.95em;
  font-weight: 700; color: #1B5E20;
  background: white; text-align: center;
  font-family: var(--font-num);
}
.time-pair .time-input:focus { outline: none; border-color: var(--green-600); }
.time-sep { color: var(--grey-300); font-size: 0.9em; flex-shrink: 0; }

/* 夜班列 */
.night-row-day {
  display: flex; align-items: center; justify-content: space-between;
  margin-top: 10px; background: #F8F4FF;
  border: 1.5px solid #B39DDB; border-radius: var(--radius-sm); padding: 9px 12px;
  font-size: 0.83em; gap: 8px; flex-wrap: wrap;
}
.night-row-day .night-label { color: var(--purple-600); font-weight: 700; }
.night-cancel-label { display: flex; align-items: center; gap: 5px; color: var(--grey-500); cursor: pointer; }
.night-cancel-label input { accent-color: var(--purple-600); width: 15px; height: 15px; cursor: pointer; }

/* 略過 */
.skip-row { display: flex; align-items: center; gap: 8px; margin-top: 10px; }
.skip-row label { font-size: 0.8em; color: var(--grey-500); cursor: pointer; }
.skip-row input[type="checkbox"] { width: 16px; height: 16px; accent-color: var(--grey-500); cursor: pointer; }

/* 公式展開 */
.formula-btn {
  width: 100%; margin-top: 10px; padding: 9px 13px;
  background: #F3F4F6; border: 1.5px solid var(--grey-300);
  border-radius: var(--radius-sm); font-size: 0.82em;
  color: var(--grey-700); cursor: pointer; font-weight: 700;
  text-align: left; font-family: var(--font-body);
  transition: background var(--transition);
}
.formula-btn:hover { background: #E8E9EB; }
.formula-box {
  display: none; background: #F8F9FA;
  border: 1.5px solid var(--grey-300); border-top: none;
  border-radius: 0 0 var(--radius-sm) var(--radius-sm);
  padding: 12px 14px; font-size: 0.8em; color: var(--grey-700); line-height: 1.9;
}
.formula-detail {
  background: white; border-radius: 6px; padding: 8px 10px;
  border: 1px solid #eee; margin-top: 6px; line-height: 1.9;
}

/* day-card 覆寫，確保手機正確顯示 */
.day-card { padding: 14px 14px; }
.day-date { font-family: var(--font-num); }
.day-badge {
  display: inline-block; font-size: 0.7em; font-weight: 700;
  padding: 2px 7px; border-radius: 10px; margin-left: 5px; vertical-align: middle;
}
.day-badge-ot    { background: #FFF3E0; color: var(--amber-500); }
.day-badge-shift { background: var(--purple-100); color: var(--purple-600); }

.empty-state {
  background: white; border-radius: var(--radius-md);
  padding: 30px; text-align: center; color: var(--grey-500);
}
</style>
</head>
<body>

<div class="topbar">
  <span class="topbar-title">📋 逐日確認打卡記錄</span>
</div>

<div class="main-wrap footer-pad">

<!-- 員工資訊列 -->
<div class="emp-bar" style="margin-top:12px">
  <div style="flex:1">
    <div class="emp-name"><?php echo htmlspecialchars($employeeName); ?></div>
    <div class="emp-meta" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:4px">
      <?php if ($empType === 'fulltime'): ?>
        月薪 $<?php echo number_format($wage); ?> &nbsp;·&nbsp; 時薪 $<?php echo round($hourlyRate,2); ?>
      <?php else: ?>
        時薪 $<?php echo $wage; ?>/h
      <?php endif; ?>
      <!-- &nbsp;·&nbsp; <?php echo htmlspecialchars($yearMonth); ?> -->
    </div>
  </div>
  <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px">
    <span class="badge badge-<?php echo $empType; ?>">
      <?php echo $empType==='fulltime'?'正職':'時薪制'; ?>
    </span>
    <a href="index.php" style="font-size:0.75em;color:rgba(255,255,255,0.7);text-decoration:none">← 返回首頁</a>
  </div>
</div>
<div id="ym-msg" style="display:none;font-size:0.8em;padding:6px 12px;border-radius:6px;margin-bottom:8px"></div>

<!-- 年月確認列：可直接修改 -->
<div style="background:white;border-radius:var(--radius-md);padding:14px 16px;margin-bottom:10px;box-shadow:var(--card-shadow);display:flex;align-items:center;gap:10px;flex-wrap:wrap">
  <span style="font-size:0.88em;color:var(--grey-500);font-weight:600;white-space:nowrap">📅 出勤年月：</span>
  <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
    <select id="ym-year" style="padding:7px 10px;border:1.5px solid #ddd;border-radius:6px;font-size:0.92em;font-family:var(--font-num);color:var(--grey-900)" onchange="updateYearMonth()">
      <?php
        $curYear = (int)date('Y');
        $ymYear  = (int)explode('-', $yearMonth)[0];
        for ($y = $curYear - 3; $y <= $curYear + 1; $y++) {
            $sel = $y === $ymYear ? 'selected' : '';
            echo "<option value='{$y}' {$sel}>{$y} 年</option>";
        }
      ?>
    </select>
    <select id="ym-month" style="padding:7px 10px;border:1.5px solid #ddd;border-radius:6px;font-size:0.92em;font-family:var(--font-num);color:var(--grey-900)" onchange="updateYearMonth()">
      <?php
        $ymMonth = (int)explode('-', $yearMonth)[1];
        for ($m = 1; $m <= 12; $m++) {
            $sel = $m === $ymMonth ? 'selected' : '';
            echo "<option value='{$m}' {$sel}>{$m} 月</option>";
        }
      ?>
    </select>
    <span style="font-size:0.8em;color:var(--grey-500)">（若辨識錯誤請直接修正）</span>
  </div>
</div>

<!-- 辨識說明 -->
<div class="msg msg-info" style="font-size:0.83em">
  💡 <strong>辨識說明：</strong>依卡片欄位座標辨識
  <!-- <span style="display:inline-flex;align-items:center;gap:3px;margin:0 3px">
    <span style="width:9px;height:9px;border-radius:50%;background:#1565C0;display:inline-block"></span><small>藍=第一段上班</small>
  </span>
  <span style="display:inline-flex;align-items:center;gap:3px;margin:0 3px">
    <span style="width:9px;height:9px;border-radius:50%;background:#388E3C;display:inline-block"></span><small>綠=第一段下班</small>
  </span>
  <span style="display:inline-flex;align-items:center;gap:3px;margin:0 3px">
    <span style="width:9px;height:9px;border-radius:50%;background:#7C4DFF;display:inline-block"></span><small>紫=第二段上班</small>
  </span>
  <span style="display:inline-flex;align-items:center;gap:3px;margin:0 3px">
    <span style="width:9px;height:9px;border-radius:50%;background:#757575;display:inline-block"></span><small>灰=第二段下班</small>
  </span> -->
  <br>辨識結果僅供參考，<strong>請務必逐日核對</strong>，有誤差可直接修改後再送出。若第一段下班空白但有加班欄位，系統會自動替補。
</div>

<?php if ($errorMsg): ?>
<div class="msg msg-error">⚠️ <?php echo htmlspecialchars($errorMsg); ?></div>
<?php endif; ?>

<?php if (!empty($parsedDays)): ?>

<?php if ($isSide2 && !empty($side1Days)): ?>
<div style="background:#EDE7F6;border-left:4px solid #7C4DFF;border-radius:8px;padding:10px 14px;margin-bottom:8px;font-size:0.85em;color:#4A148C;font-weight:600">
  ✅ 第一面已儲存 <strong><?php echo count($side1Days); ?></strong> 天記錄，確認後將與第二面合併寫入 Excel
</div>
<?php endif; ?>
<div class="summary-bar">
  <?php if ($isSide2): ?>
  第二面辨識 <strong><?php echo count($parsedDays); ?></strong> 天＋第一面 <strong><?php echo count($side1Days); ?></strong> 天＝共 <strong><?php echo count($parsedDays) + count($side1Days); ?></strong> 天
  <?php else: ?>
  共辨識 <strong><?php echo count($parsedDays); ?></strong> 天出勤記錄，請逐一確認後送出
  <?php endif; ?>
  <?php if ($empType === 'fulltime'): ?>
  &nbsp;·&nbsp; 正職員工請確認每天休息狀況
  <?php endif; ?>
</div>

<div class="month-title"><?php echo $yearMonth; ?> 出勤記錄</div>

<form action="batch_handler.php" method="post" id="batchForm">
    <input type="hidden" name="employee_name" value="<?php echo htmlspecialchars($employeeName); ?>">
    <input type="hidden" name="emp_type"      value="<?php echo $empType; ?>">
    <input type="hidden" name="hourly_rate"   value="<?php echo $wage; ?>">
    <input type="hidden" name="night_allowance" value="<?php echo $nightAllowance; ?>">
    <input type="hidden" name="year_month"    value="<?php echo $yearMonth; ?>">
    <input type="hidden" name="day_count"     value="<?php echo count($parsedDays); ?>">

    <?php foreach ($parsedDays as $i => $day): ?>
    <?php
        $hasShift2 = !empty($day['shift2_start']) || !empty($day['shift2_end']);
        $hasOT     = $day['preview']['overtime_hours'] > 0;
        $cardClass = $hasOT ? 'has-overtime' : ($hasShift2 ? 'night-shift' : '');
    ?>
    <div class="day-card <?php echo $cardClass; ?>">
        <div class="day-header">
            <span class="day-date">
                <?php echo $yearMonth . '-' . str_pad($day['date'], 2, '0', STR_PAD_LEFT); ?>
                <?php if ($hasShift2): ?><span style="font-size:0.75em;color:#7C4DFF;margin-left:6px">兩段班</span><?php endif; ?>
                <?php if ($hasOT): ?><span style="font-size:0.75em;color:#FF9800;margin-left:4px">加班</span><?php endif; ?>
            </span>
            <span class="day-preview">
                <span class="hrs"><?php echo $day['preview']['total_hours']; ?>h</span>
                &nbsp;預估&nbsp;
                <span class="sal">$<?php echo $day['preview']['salary']; ?></span>
            </span>
        </div>

        <input type="hidden" name="day[<?php echo $i; ?>][date]" value="<?php echo $day['date']; ?>">

        <div class="shifts">
            <div class="shift-block">
                <div class="shift-label" style="display:flex;gap:5px;align-items:center">
                  <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#1565C0;flex-shrink:0"></span>上班
                  <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#388E3C;flex-shrink:0;margin-left:4px"></span>下班
                </div>
                <div class="time-pair">
                    <input type="text" class="time-input" placeholder=""
                           name="day[<?php echo $i; ?>][s1_start]"
                           value="<?php echo htmlspecialchars($day['shift1_start']); ?>"
                           onchange="recalcDay(<?php echo $i; ?>)">
                    <span class="time-sep">→</span>
                    <input type="text" class="time-input" placeholder=""
                           name="day[<?php echo $i; ?>][s1_end]"
                           value="<?php echo htmlspecialchars($day['shift1_end']); ?>"
                           onchange="recalcDay(<?php echo $i; ?>)">
                </div>
            </div>
            <div class="shift-block shift2">
                <div class="shift-label" style="display:flex;gap:5px;align-items:center">
                  <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#7C4DFF;flex-shrink:0"></span>加班上班
                  <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#757575;flex-shrink:0;margin-left:4px"></span>加班下班
                </div>
                <div class="time-pair">
                    <input type="text" class="time-input" placeholder=""
                           name="day[<?php echo $i; ?>][s2_start]"
                           value="<?php echo htmlspecialchars($day['shift2_start']); ?>"
                           onchange="recalcDay(<?php echo $i; ?>)">
                    <span class="time-sep">→</span>
                    <input type="text" class="time-input" placeholder=""
                           name="day[<?php echo $i; ?>][s2_end]"
                           value="<?php echo htmlspecialchars($day['shift2_end']); ?>"
                           onchange="recalcDay(<?php echo $i; ?>)">
                </div>
            </div>
        </div>

        <?php if ($empType === 'fulltime'): ?>
        <div class="break-row">
            <span>休息：</span>
            <label>
                <input type="radio" name="day[<?php echo $i; ?>][has_break]" value="1"
                       checked onchange="recalcDay(<?php echo $i; ?>)">
                <span class="break-tag yes">✅ 有休息</span>
            </label>
            <label>
                <input type="radio" name="day[<?php echo $i; ?>][has_break]" value="0"
                       onchange="recalcDay(<?php echo $i; ?>)">
                <span class="break-tag no">⚡ 沒休息</span>
            </label>
        </div>
        <?php else: ?>
        <input type="hidden" name="day[<?php echo $i; ?>][has_break]" value="0">
        <?php endif; ?>

        <!-- 夜班津貼選項 -->
        <?php if ($nightAllowance > 0): ?>
        <div class="break-row" style="margin-top:6px">
            <span>🌙 夜班津貼：</span>
            <label>
                <input type="radio" name="day[<?php echo $i; ?>][apply_night]" value="1"
                       <?php echo $day['is_night'] ? 'checked' : ''; ?>
                       onchange="recalcDay(<?php echo $i; ?>)">
                <span class="break-tag" style="background:#EDE7F6;color:#7C4DFF">
                    加入 $<?php echo $nightAllowance; ?>
                </span>
            </label>
            <label>
                <input type="radio" name="day[<?php echo $i; ?>][apply_night]" value="0"
                       <?php echo $day['is_night'] ? '' : 'checked'; ?>
                       onchange="recalcDay(<?php echo $i; ?>)">
                <span class="break-tag" style="background:#F5F5F5;color:#757575">不套用</span>
            </label>
        </div>
        <?php else: ?>
        <input type="hidden" name="day[<?php echo $i; ?>][apply_night]" value="0">
        <?php endif; ?>

        <?php if ($empType === 'fulltime'): ?>
        <button type="button" class="formula-btn"
                onclick="toggleFormula(<?php echo $i; ?>)">
            📐 查看加班費核算公式
            <span id="formula-arrow-<?php echo $i; ?>">▼</span>
        </button>
        <div class="formula-box" id="formula-box-<?php echo $i; ?>">
            <div style="font-weight:bold;color:#2E7D32;margin-bottom:4px">📋 勞基法加班費計算方式</div>
            <div>月薪 <strong>$<?php echo number_format($wage); ?></strong> ÷ 30 ÷ 8 ＝ 時薪 <strong>$<?php echo round($hourlyRate, 2); ?></strong> 元</div>
            <div>✅ 正常工時（8h內）：$<?php echo round($hourlyRate, 2); ?> × 時數</div>
            <div>🔶 加班前2h（×1.34）：$<?php echo round($hourlyRate * 1.34, 2); ?> × 時數</div>
            <div>🔴 加班第3h起（×1.67）：$<?php echo round($hourlyRate * 1.67, 2); ?> × 時數</div>
            <div style="font-weight:bold;color:#555;margin-top:6px">本次計算明細：</div>
            <div class="formula-detail" id="formula-detail-<?php echo $i; ?>">輸入時間後自動顯示</div>
            <div style="color:#888;font-size:0.9em;margin-top:4px">※ 工時滿8小時且有休息，扣除30分鐘後再計算加班</div>
        </div>
        <?php endif; ?>

        <div class="skip-row">
            <input type="checkbox" id="skip_<?php echo $i; ?>"
                   name="day[<?php echo $i; ?>][skip]" value="1"
                   onchange="toggleSkip(<?php echo $i; ?>, this.checked)">
            <label for="skip_<?php echo $i; ?>">略過此天（例假日 / 辨識有誤）</label>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- 手動新增出勤日期 -->
    <div id="manual-add-section" style="background:white;border-radius:var(--radius-md);padding:16px;margin-top:10px;box-shadow:var(--card-shadow);border:2px dashed #A5D6A7">
        <div style="font-size:0.88em;font-weight:700;color:var(--green-700);margin-bottom:10px">
            ➕ 手動新增出勤日期
        </div>
        <div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
            <div>
                <div style="font-size:0.75em;color:var(--grey-500);margin-bottom:4px">日期（1-31）</div>
                <input type="number" id="manual-date" min="1" max="31"
                       placeholder="例：21"
                       style="width:80px;padding:9px 10px;border:1.5px solid #ddd;border-radius:6px;font-size:0.95em;font-family:var(--font-body)">
            </div>
            <div>
                <div style="font-size:0.75em;color:var(--grey-500);margin-bottom:4px">🔵 上班時間</div>
                <input type="text" id="manual-s1-start" placeholder=""
                       inputmode="numeric" maxlength="5"
                       style="width:85px;padding:9px 8px;border:1.5px solid #ddd;border-radius:6px;font-size:0.95em;font-weight:700;text-align:center;font-family:var(--font-num)">
            </div>
            <div style="color:var(--grey-300);padding-bottom:10px">→</div>
            <div>
                <div style="font-size:0.75em;color:var(--grey-500);margin-bottom:4px">🟢 下班時間</div>
                <input type="text" id="manual-s1-end" placeholder=""
                       inputmode="numeric" maxlength="5"
                       style="width:85px;padding:9px 8px;border:1.5px solid #ddd;border-radius:6px;font-size:0.95em;font-weight:700;text-align:center;font-family:var(--font-num)">
            </div>
            <button type="button" class="btn btn-ghost"
                    onclick="addManualDay()" style="min-height:40px">
                ＋ 加入
            </button>
        </div>
        <div id="manual-msg" style="font-size:0.8em;margin-top:6px;display:none"></div>
    </div>

    <?php if ($isSide2 && !empty($side1Days)): ?>
    <input type="hidden" name="carry_side1" value="<?php echo htmlspecialchars($_POST['carry_side1']); ?>">
    <?php endif; ?>

    <div style="background:white;border-radius:var(--radius-md);padding:16px;margin-top:16px;box-shadow:var(--card-shadow)">
        <div style="font-size:0.88em;font-weight:700;color:var(--grey-700);margin-bottom:12px">📤 確認後，選擇下一步：</div>
        <div style="display:flex;flex-direction:column;gap:10px">
            <button type="submit" class="btn btn-primary btn-full" onclick="return confirmSubmit()" style="justify-content:center">
                ✅ 確認完成，寫入資料庫
            </button>
            <button type="button" class="btn btn-purple btn-full" onclick="submitForNextSide()" style="justify-content:center;background:#7C4DFF;color:white;border:none;border-radius:var(--radius-sm);padding:12px 16px;font-weight:700;cursor:pointer;font-size:0.95em">
                📷 繼續辨識另一面（雙面卡片）
            </button>
            <a href="index.php" class="btn btn-secondary btn-full" style="justify-content:center;text-align:center">← 返回首頁</a>
        </div>
    </div>
</form>

<?php else: ?>
<div class="empty-state">
  <div style="font-size:2em;margin-bottom:12px">📷</div>
  <div style="margin-bottom:16px">請返回首頁上傳打卡卡片圖片</div>
  <a href="index.php" class="btn btn-primary" style="display:inline-flex">← 返回首頁</a>
</div>
<?php endif; ?>

</div>

<script>
const empType        = "<?php echo $empType; ?>";
const wage           = <?php echo $wage; ?>;
// 正職：月薪換算時薪；時薪制直接用時薪
const hourlyRate     = empType === 'fulltime' ? Math.round(wage / 30 / 8 * 10000) / 10000 : wage;

function toMin(t) {
    if (!t) return null;
    const p = t.split(':');
    if (p.length !== 2) return null;
    const h = parseInt(p[0]), m = parseInt(p[1]);
    return isNaN(h)||isNaN(m) ? null : h * 60 + m;
}
function diffHours(s, e) {
    if (!s || !e) return 0;
    let sm = toMin(s), em = toMin(e);
    if (sm===null||em===null) return 0;
    if (em < sm) em += 1440; // 跨午夜
    return (em - sm) / 60;
}

// ── 時間輸入格式化 ──────────────────────────────
function initTimeInputs() {
    document.querySelectorAll('.time-input').forEach(input => {
        input.setAttribute('inputmode', 'numeric');
        input.setAttribute('maxlength', '5');

        // 取得這個 input 所屬的 day-card index
        function getCardIndex(el) {
            const card = el.closest('.day-card');
            if (!card) return -1;
            return Array.from(document.querySelectorAll('.day-card')).indexOf(card);
        }

        input.addEventListener('input', function() {
            let v = this.value.replace(/[^0-9]/g, '').slice(0, 4);
            if (v.length === 4) {
                this.value = formatTime4(v);
                // 4位完整輸入後立即更新
                if (this.value) {
                    const idx = getCardIndex(this);
                    if (idx >= 0) recalcDay(idx);
                }
            } else if (v.length === 3) {
                this.value = v.slice(0,1) + ':' + v.slice(1);
            } else {
                this.value = v;
            }
        });

        input.addEventListener('blur', function() {
            const v = this.value.replace(/[^0-9]/g, '');
            let formatted = '';
            if (!v) {
                // 清空時也要更新
                const idx = getCardIndex(this);
                if (idx >= 0) recalcDay(idx);
                return;
            }
            if (v.length <= 2) {
                const h = parseInt(v);
                if (h >= 0 && h <= 23) formatted = String(h).padStart(2,'0') + ':00';
            } else if (v.length === 3) {
                formatted = formatTime4('0' + v);
            } else {
                formatted = formatTime4(v.slice(0,4));
            }
            if (formatted) {
                const parts = formatted.split(':').map(Number);
                if (parts[0] <= 23 && parts[1] <= 59) {
                    this.value = formatted;
                    this.style.borderColor = '';
                } else {
                    this.value = '';
                    flashError(this);
                }
            } else {
                this.value = '';
                flashError(this);
            }
            // 失焦後一定更新
            const idx = getCardIndex(this);
            if (idx >= 0) recalcDay(idx);
        });

        input.addEventListener('paste', function(e) {
            e.preventDefault();
            const txt = (e.clipboardData || window.clipboardData).getData('text');
            const d = txt.replace(/[^0-9]/g, '').slice(0,4);
            if (d.length === 4) this.value = formatTime4(d);
            else this.value = d;
            // 貼上後更新
            const idx = getCardIndex(this);
            if (idx >= 0) recalcDay(idx);
        });
    });
}

function formatTime4(v) {
    const h = parseInt(v.slice(0,2), 10);
    const m = parseInt(v.slice(2,4), 10);
    if (isNaN(h)||isNaN(m)||h>23||m>59) return '';
    return String(h).padStart(2,'0') + ':' + String(m).padStart(2,'0');
}

function flashError(el) {
    el.style.borderColor = '#C62828';
    el.style.background  = '#FFEBEE';
    setTimeout(() => { el.style.borderColor=''; el.style.background=''; }, 1500);
}

function recalcDay(i) {
    const g = name => { const el = document.querySelector(`[name="day[${i}][${name}]"]`); return el ? el.value.trim() : ''; };
    let total = diffHours(g('s1_start'), g('s1_end')) + diffHours(g('s2_start'), g('s2_end'));
    let ot = 0, salary = 0;
    if (empType === 'hourly') {
        salary = Math.round(total * hourlyRate);
    } else {
        // 正職：只計算加班費，不足8小時薪資為0
        const rb = document.querySelector(`[name="day[${i}][has_break]"]:checked`);
        const hb = rb ? rb.value === '1' : true;
        const bt = (hb && total >= 8) ? 0.5 : 0;
        const act = Math.max(total - bt, 0);
        total = act;
        const ot1 = Math.min(Math.max(act-8,0),2);
        const ot2 = Math.max(act-10,0);
        ot = ot1 + ot2;
        // 只加班費，不含正常月薪部分
        salary = Math.round(ot1*hourlyRate*1.34 + ot2*hourlyRate*1.67);
    }

    // 夜班津貼
    const nightRadio = document.querySelector(`[name="day[${i}][apply_night]"]:checked`);
    const nightPay   = (nightRadio && nightRadio.value === '1') ? <?php echo $nightAllowance; ?> : 0;
    salary += nightPay;

    // 更新公式明細
    const detailEl = document.getElementById('formula-detail-' + i);
    if (detailEl && empType === 'fulltime') {
        const h    = Math.round(wage / 30 / 8 * 100) / 100;
        const h134 = Math.round(h * 1.34 * 100) / 100;
        const h167 = Math.round(h * 1.67 * 100) / 100;
        const rb2  = document.querySelector(`[name="day[${i}][has_break]"]:checked`);
        const hb2  = rb2 ? rb2.value === '1' : true;
        const rawH = diffHours(g('s1_start'), g('s1_end')) + diffHours(g('s2_start'), g('s2_end'));
        const brkT = (hb2 && rawH >= 8) ? 0.5 : 0;
        const act  = Math.max(rawH - brkT, 0);
        const norm = Math.min(act, 8);
        const ot1h = Math.min(Math.max(act-8,0), 2);
        const ot2h = Math.max(act-10, 0);
        const normPay = Math.round(norm * h);
        const ot1Pay  = Math.round(ot1h * h134);
        const ot2Pay  = Math.round(ot2h * h167);
        let det = '';
        if (brkT > 0) det += `<span style="color:#888">已扣除休息 0.5h，實際工時 ${Math.round(act*100)/100}h</span><br>`;
        if (act <= 8) {
            // 未超過8小時：只顯示工時，無加班費
            det += `<span style="color:#2E7D32">正常工時 ${Math.round(act*100)/100}h，未超過8小時</span><br>`;
            det += `<span style="color:#888">本日屬月薪範圍，無需另計加班費</span>`;
        } else {
            // 超過8小時：顯示加班明細
            det += `<span style="color:#888">正常工時 8h（月薪範圍，不另計）</span><br>`;
            if (ot1h > 0) det += `🔶 加班前2h：${Math.round(ot1h*100)/100}h × $${h134}（×1.34）= <strong style="color:#F57F17">$${ot1Pay}</strong><br>`;
            if (ot2h > 0) det += `🔴 加班第3h起：${Math.round(ot2h*100)/100}h × $${h167}（×1.67）= <strong style="color:#C62828">$${ot2Pay}</strong><br>`;
            det += `<span style="color:#C62828;font-weight:bold">加班費合計：$${Math.round(ot1Pay+ot2Pay)}</span>`;
        }
        detailEl.innerHTML = det;
    }

    const card = document.querySelectorAll('.day-card')[i];
    if (!card) return;
    const hrs = card.querySelector('.hrs');
    const sal = card.querySelector('.sal');
    if (hrs) hrs.textContent = Math.round(total*100)/100 + 'h';
    if (sal) sal.textContent = '$' + salary;
    card.classList.remove('has-overtime','night-shift');
    if (ot > 0) card.classList.add('has-overtime');
}
function toggleFormula(i) {
    const box   = document.getElementById('formula-box-' + i);
    const arrow = document.getElementById('formula-arrow-' + i);
    if (!box) return;
    const open = box.style.display === 'none' || box.style.display === '';
    box.style.display   = open ? 'block' : 'none';
    if (arrow) arrow.textContent = open ? '▲' : '▼';
    // 展開時立即計算明細
    if (open) recalcDay(i);
}

function toggleSkip(i, skipped) {
    // 更新公式明細
    const detailEl = document.getElementById('formula-detail-' + i);
    if (detailEl && empType === 'fulltime') {
        const h    = Math.round(wage / 30 / 8 * 100) / 100;
        const h134 = Math.round(h * 1.34 * 100) / 100;
        const h167 = Math.round(h * 1.67 * 100) / 100;
        const rb2  = document.querySelector(`[name="day[${i}][has_break]"]:checked`);
        const hb2  = rb2 ? rb2.value === '1' : true;
        const rawH = diffHours(g('s1_start'), g('s1_end')) + diffHours(g('s2_start'), g('s2_end'));
        const brkT = (hb2 && rawH >= 8) ? 0.5 : 0;
        const act  = Math.max(rawH - brkT, 0);
        const norm = Math.min(act, 8);
        const ot1h = Math.min(Math.max(act-8,0), 2);
        const ot2h = Math.max(act-10, 0);
        const normPay = Math.round(norm * h);
        const ot1Pay  = Math.round(ot1h * h134);
        const ot2Pay  = Math.round(ot2h * h167);
        let det = '';
        if (brkT > 0) det += `<span style="color:#888">已扣除休息 0.5h，實際工時 ${Math.round(act*100)/100}h</span><br>`;
        if (act <= 8) {
            // 未超過8小時：只顯示工時，無加班費
            det += `<span style="color:#2E7D32">正常工時 ${Math.round(act*100)/100}h，未超過8小時</span><br>`;
            det += `<span style="color:#888">本日屬月薪範圍，無需另計加班費</span>`;
        } else {
            // 超過8小時：顯示加班明細
            det += `<span style="color:#888">正常工時 8h（月薪範圍，不另計）</span><br>`;
            if (ot1h > 0) det += `🔶 加班前2h：${Math.round(ot1h*100)/100}h × $${h134}（×1.34）= <strong style="color:#F57F17">$${ot1Pay}</strong><br>`;
            if (ot2h > 0) det += `🔴 加班第3h起：${Math.round(ot2h*100)/100}h × $${h167}（×1.67）= <strong style="color:#C62828">$${ot2Pay}</strong><br>`;
            det += `<span style="color:#C62828;font-weight:bold">加班費合計：$${Math.round(ot1Pay+ot2Pay)}</span>`;
        }
        detailEl.innerHTML = det;
    }

    const card = document.querySelectorAll('.day-card')[i];
    if (!card) return;
    card.querySelectorAll('input.time-input,input[type="radio"]').forEach(el => el.disabled = skipped);
    card.style.opacity = skipped ? '0.4' : '1';
}
document.addEventListener('DOMContentLoaded', () => {
    initTimeInputs();
    // 頁面載入後立即以正確的休息設定重算每張卡片的工時與薪資
    document.querySelectorAll('.day-card').forEach((card, i) => {
        recalcDay(i);
    });
});

// 取得目前最大的 day-card index
function getMaxDayIndex() {
    const cards = document.querySelectorAll('.day-card');
    return cards.length;
}

// 手動新增出勤日期
function addManualDay() {
    const dateVal  = document.getElementById('manual-date').value.trim();
    const s1Start  = document.getElementById('manual-s1-start').value.trim();
    const s1End    = document.getElementById('manual-s1-end').value.trim();
    const msg      = document.getElementById('manual-msg');

    // 驗證日期
    const dateNum = parseInt(dateVal);
    if (!dateVal || isNaN(dateNum) || dateNum < 1 || dateNum > 31) {
        msg.textContent = '⚠️ 請輸入正確的日期（1-31）';
        msg.style.color = 'var(--red-600)';
        msg.style.display = '';
        return;
    }

    // 格式化時間
    function fmtTime(t) {
        if (!t) return '';
        const d = t.replace(/[^0-9]/g, '');
        if (d.length === 4) {
            const h=parseInt(d.slice(0,2)), m=parseInt(d.slice(2,4));
            if (h<=23 && m<=59) return String(h).padStart(2,'0')+':'+String(m).padStart(2,'0');
        } else if (d.length === 3) {
            const h=parseInt(d.slice(0,1)), m=parseInt(d.slice(1,3));
            if (h<=9 && m<=59) return '0'+h+':'+String(m).padStart(2,'0');
        } else if (d.length <= 2) {
            const h=parseInt(d);
            if (h>=0 && h<=23) return String(h).padStart(2,'0')+':00';
        }
        return t.includes(':') ? t : '';
    }

    const fmtStart = fmtTime(s1Start);
    const fmtEnd   = fmtTime(s1End);

    if (!fmtStart && !fmtEnd) {
        msg.textContent = '⚠️ 請至少輸入上班或下班時間';
        msg.style.color = 'var(--red-600)';
        msg.style.display = '';
        return;
    }

    // 取得 year_month
    const ymInput  = document.querySelector('input[name="year_month"]');
    const yearMonth = ymInput ? ymInput.value : '';
    const dateStr  = yearMonth ? yearMonth + '-' + String(dateNum).padStart(2,'0') : String(dateNum);

    // 取得目前的 night_allowance
    const nightInput = document.querySelector('input[name="night_allowance"]');
    const nightAllow = nightInput ? parseInt(nightInput.value) || 0 : 0;

    const i = getMaxDayIndex();

    // 計算預估
    function toMin(t) { if(!t) return null; const p=t.split(':'); const h=parseInt(p[0]),m=parseInt(p[1]); return isNaN(h)||isNaN(m)?null:h*60+m; }
    function diffH(s,e) { let sm=toMin(s),em=toMin(e); if(sm===null||em===null) return 0; if(em<sm) em+=1440; return (em-sm)/60; }
    const totalH = diffH(fmtStart, fmtEnd);
    const estSalary = Math.round(totalH * hourlyRate);

    // 動態生成 night_allowance 選項
    const nightHtml = nightAllow > 0 ? `
        <div class="break-row" style="margin-top:6px">
            <span>🌙 夜班津貼：</span>
            <label><input type="radio" name="day[${i}][apply_night]" value="1" onchange="recalcDay(${i})">
                <span class="break-tag" style="background:#EDE7F6;color:#7C4DFF">加入 $${nightAllow}</span></label>
            <label><input type="radio" name="day[${i}][apply_night]" value="0" checked onchange="recalcDay(${i})">
                <span class="break-tag" style="background:#F5F5F5;color:#757575">不套用</span></label>
        </div>` : `<input type="hidden" name="day[${i}][apply_night]" value="0">`;

    const breakHtml = empType === 'fulltime' ? `
        <div class="break-row">
            <span>休息：</span>
            <label><input type="radio" name="day[${i}][has_break]" value="1" checked onchange="recalcDay(${i})">
                <span class="break-tag yes">✅ 有休息</span></label>
            <label><input type="radio" name="day[${i}][has_break]" value="0" onchange="recalcDay(${i})">
                <span class="break-tag no">⚡ 沒休息</span></label>
        </div>` : `<input type="hidden" name="day[${i}][has_break]" value="0">`;

    // 建立新卡片 HTML
    const cardHtml = `
    <div class="day-card" style="border-left-color:#A5D6A7;border-left-style:dashed">
        <div class="day-header">
            <span class="day-date">
                ${dateStr}
                <span style="font-size:0.72em;background:#E8F5E9;color:var(--green-700);padding:2px 6px;border-radius:8px;margin-left:4px">手動新增</span>
            </span>
            <span class="day-preview">
                <span class="hrs">${Math.round(totalH*100)/100}h</span>
                &nbsp;預估&nbsp;
                <span class="sal">$${estSalary}</span>
            </span>
        </div>
        <input type="hidden" name="day[${i}][date]" value="${dateNum}">
        <div class="shifts">
            <div class="shift-block">
                <div class="shift-label" style="display:flex;gap:5px;align-items:center">
                    <span style="width:10px;height:10px;border-radius:50%;background:#1565C0;display:inline-block;flex-shrink:0"></span>上班
                    <span style="width:10px;height:10px;border-radius:50%;background:#388E3C;display:inline-block;flex-shrink:0;margin-left:4px"></span>下班
                </div>
                <div class="time-pair">
                    <input type="text" class="time-input" placeholder="" name="day[${i}][s1_start]"
                           value="${fmtStart}" onchange="recalcDay(${i})">
                    <span class="time-sep">→</span>
                    <input type="text" class="time-input" placeholder="" name="day[${i}][s1_end]"
                           value="${fmtEnd}" onchange="recalcDay(${i})">
                </div>
            </div>
            <div class="shift-block shift2">
                <div class="shift-label" style="display:flex;gap:5px;align-items:center">
                    <span style="width:10px;height:10px;border-radius:50%;background:#7C4DFF;display:inline-block;flex-shrink:0"></span>加班上班
                    <span style="width:10px;height:10px;border-radius:50%;background:#757575;display:inline-block;flex-shrink:0;margin-left:4px"></span>加班下班
                </div>
                <div class="time-pair">
                    <input type="text" class="time-input" placeholder="" name="day[${i}][s2_start]"
                           value="" onchange="recalcDay(${i})">
                    <span class="time-sep">→</span>
                    <input type="text" class="time-input" placeholder="" name="day[${i}][s2_end]"
                           value="" onchange="recalcDay(${i})">
                </div>
            </div>
        </div>
        ${breakHtml}
        ${nightHtml}
        <div class="skip-row">
            <input type="checkbox" id="skip_${i}" name="day[${i}][skip]" value="1"
                   onchange="toggleSkip(${i}, this.checked)">
            <label for="skip_${i}">略過此天（例假日 / 辨識有誤）</label>
        </div>
    </div>`;

    // 插入到正確的排序位置（依日期升冪）
    const manualSection = document.getElementById('manual-add-section');
    const allCards = Array.from(document.querySelectorAll('.day-card'));
    let inserted = false;
    for (const card of allCards) {
        const di = card.querySelector('input[name*="[date]"]');
        if (di && parseInt(di.value) > dateNum) {
            card.insertAdjacentHTML('beforebegin', cardHtml);
            inserted = true;
            break;
        }
    }
    if (!inserted) {
        manualSection.insertAdjacentHTML('beforebegin', cardHtml);
    }

    // 重新編號所有卡片的 name index（確保 batch_handler 接收正確）
    reindexCards();
    initTimeInputs();

    // 更新 day_count
    const dcInput = document.querySelector('input[name="day_count"]');
    if (dcInput) dcInput.value = document.querySelectorAll('.day-card').length;

    // 清空輸入並顯示成功
    document.getElementById('manual-date').value     = '';
    document.getElementById('manual-s1-start').value = '';
    document.getElementById('manual-s1-end').value   = '';
    msg.textContent = `✅ 已新增 ${dateStr} 的出勤記錄`;
    msg.style.color = 'var(--green-700)';
    msg.style.display = '';

    // 更新摘要
    const summaryEl = document.querySelector('.summary-bar strong');
    if (summaryEl) summaryEl.textContent = document.querySelectorAll('.day-card').length;

    // 捲動到新卡片
    const newCard = Array.from(document.querySelectorAll('.day-card')).find(c => {
        const di = c.querySelector('input[name*="[date]"]');
        return di && parseInt(di.value) === dateNum;
    });
    if (newCard) newCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

// 重新為所有 day-card 的 name attribute 連續編號
function reindexCards() {
    document.querySelectorAll('.day-card').forEach((card, newIdx) => {
        card.querySelectorAll('[name]').forEach(el => {
            el.name = el.name.replace(/day\[(\d+)\]/, `day[${newIdx}]`);
        });
        const cb = card.querySelector('input[type="checkbox"][name*="skip"]');
        if (cb) {
            cb.id = `skip_${newIdx}`;
            const lbl = card.querySelector('label[for^="skip_"]');
            if (lbl) lbl.setAttribute('for', `skip_${newIdx}`);
        }
        card.querySelectorAll('[onchange]').forEach(el => {
            el.setAttribute('onchange', el.getAttribute('onchange').replace(/recalcDay\(\d+\)/, `recalcDay(${newIdx})`));
        });
        const fBtn = card.querySelector('.formula-btn');
        if (fBtn) fBtn.setAttribute('onclick', `toggleFormula(${newIdx})`);
        const fBox = card.querySelector('[id^="formula-box-"]');
        if (fBox) fBox.id = `formula-box-${newIdx}`;
        const fArr = card.querySelector('[id^="formula-arrow-"]');
        if (fArr) fArr.id = `formula-arrow-${newIdx}`;
        const fDet = card.querySelector('[id^="formula-detail-"]');
        if (fDet) fDet.id = `formula-detail-${newIdx}`;
    });
}

function updateYearMonth() {
    const y  = document.getElementById('ym-year').value;
    const m  = String(document.getElementById('ym-month').value).padStart(2, '0');
    const ym = y + '-' + m;

    // 更新所有 year_month hidden input
    document.querySelectorAll('input[name="year_month"]').forEach(el => el.value = ym);

    // 更新每張卡片的日期顯示，保留內部 span 標籤
    document.querySelectorAll('.day-card').forEach(card => {
        const dateInput = card.querySelector('input[name*="[date]"]');
        const dayDateEl = card.querySelector('.day-date');
        if (!dateInput || !dayDateEl) return;
        const day = String(parseInt(dateInput.value)).padStart(2, '0');
        const badges = Array.from(dayDateEl.querySelectorAll('span'));
        dayDateEl.textContent = ym + '-' + day + ' ';
        badges.forEach(b => dayDateEl.appendChild(b));
    });

    // 更新 month-title
    const titleEl = document.querySelector('.month-title');
    if (titleEl) titleEl.textContent = ym + ' 出勤記錄';

    // 顯示提示
    const msg = document.getElementById('ym-msg');
    if (msg) {
        msg.textContent = '✅ 年月已更新為 ' + ym;
        msg.style.background = '#E8F5E9';
        msg.style.color = '#2E7D32';
        msg.style.display = '';
        setTimeout(() => { msg.style.display = 'none'; }, 2500);
    }
}
function confirmSubmit() {
    const total   = document.querySelectorAll('.day-card').length;
    const skipped = document.querySelectorAll('input[type="checkbox"]:checked').length;
    const active  = total - skipped;
    if (active === 0) { alert('所有記錄都被略過了，請至少保留一筆'); return false; }
    const isSide2    = <?php echo $isSide2 ? 'true' : 'false'; ?>;
    const side1Count = <?php echo count($side1Days); ?>;
    if (isSide2 && side1Count > 0) {
        return confirm(`確認送出第二面 ${active} 天 + 第一面 ${side1Count} 天，共 ${active + side1Count} 天出勤記錄？`);
    }
    return confirm(`確認送出 ${active} 天的出勤記錄？`);
}

// 收集目前所有卡片資料，序列化後導向 index.php 拍攝下一面
function submitForNextSide() {
    const cards = document.querySelectorAll('.day-card');
    if (cards.length === 0) { alert('沒有可儲存的記錄'); return; }
    const days = [];
    cards.forEach(card => {
        const skipEl = card.querySelector('input[type="checkbox"][name*="skip"]');
        if (skipEl && skipEl.checked) return;
        const dateEl = card.querySelector('input[name*="[date]"]');
        if (!dateEl) return;
        const g = name => { const el = card.querySelector(`[name$="[${name}]"]`); return el ? el.value.trim() : ''; };
        const nightEl = card.querySelector('[name$="[apply_night]"]:checked') || card.querySelector('[name$="[apply_night]"]');
        days.push({
            date:        parseInt(dateEl.value),
            s1_start:    g('s1_start'),
            s1_end:      g('s1_end'),
            s2_start:    g('s2_start'),
            s2_end:      g('s2_end'),
            has_break:   g('has_break') || '0',
            apply_night: nightEl ? nightEl.value : '0',
        });
    });
    if (days.length === 0) { alert('所有記錄都被略過了，請至少保留一筆再繼續'); return; }

    // 若已是第二面，把第一面資料也合進來
    const carry1El = document.querySelector('input[name="carry_side1"]');
    let prev = [];
    if (carry1El && carry1El.value) { try { prev = JSON.parse(carry1El.value); } catch(e){} }
    const merged = prev.concat(days);

    const empName = document.querySelector('input[name="employee_name"]').value;
    const ym      = document.querySelector('input[name="year_month"]').value;
    const f = document.createElement('form');
    f.method = 'post'; f.action = 'scan_upload.php'; f.style.display = 'none';
    [['next_side_scan','1'],['prefill_employee',empName],['prefill_yearmonth',ym],['carry_side1',JSON.stringify(merged)]].forEach(([k,v]) => {
        const inp = document.createElement('input');
        inp.type='hidden'; inp.name=k; inp.value=v; f.appendChild(inp);
    });
    document.body.appendChild(f);
    f.submit();
}
</script>
</body>
</html>