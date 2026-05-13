<?php
require_once __DIR__ . '/vendor/autoload.php';
include_once __DIR__ . '/functions.php';
include_once __DIR__ . '/db.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();
$apiKey = $_ENV['GOOGLE_API_KEY'] ?? '';

$employeeName  = $_POST['employee_name'] ?? '未命名';
$startTime     = '';
$endTime       = '';
$errorMsg      = '';

$empData        = getEmployee($employeeName);
$empType        = $empData['type']            ?? 'hourly';
$wage           = (int)($empData['hourly_rate'] ?? ($_ENV['HOURLY_RATE'] ?? 180));
$nightAllowance = (int)($empData['night_allowance'] ?? 0);
// 正職顯示月薪，時薪制顯示時薪；計算時正職換算時薪
$hourlyRate     = ($empType === 'fulltime') ? monthlyToHourly($wage) : $wage;
$overtimeFormula = ($empType === 'fulltime') ? getOvertimeFormula($wage) : null;

$salaryData = ['total_hours' => 0, 'overtime_hours' => 0, 'overtime_pay' => 0, 'salary' => 0];
$nightTriggered = false; // 是否自動觸發夜班津貼

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['card_image'])) {
    try {
        $rawImage  = file_get_contents($_FILES['card_image']['tmp_name']);
        $imageData = base64_encode($rawImage);

        $payload = json_encode(["requests" => [["image" => ["content" => $imageData], "features" => [["type" => "TEXT_DETECTION"]]]]]);
        $url = "https://vision.googleapis.com/v1/images:annotate?key=" . $apiKey;
        $ch  = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        curl_close($ch);

        $fullText = json_decode($response, true)['responses'][0]['textAnnotations'][0]['description'] ?? '';

        if (!empty($fullText)) {
            $cleanText = preg_replace_callback('/[0-9OBISZobisz]/', function($m) {
                return strtr($m[0], ['O'=>'0','B'=>'8','I'=>'1','S'=>'5','Z'=>'2','o'=>'0','b'=>'8','i'=>'1','s'=>'5','z'=>'2']);
            }, $fullText);
            $cleanText = str_replace(' ', '', $cleanText);
            preg_match_all('/\d{1,2}[:.\-]\d{2}/', $cleanText, $matches);
            $times = $matches[0];

            if (count($times) >= 2) {
                foreach ($times as &$t) $t = str_replace(['.', '-'], ':', $t);
                unset($t);
                usort($times, function($a, $b) {
                    [$ah, $am] = explode(':', $a); [$bh, $bm] = explode(':', $b);
                    return ($ah * 60 + $am) - ($bh * 60 + $bm);
                });
                $startTime  = $times[0];
                $endTime    = end($times);
                $salaryData = calculateSalary($startTime, $endTime, $wage, $empType, true);

                // 判斷是否觸發夜班津貼
                $nightTriggered = shouldApplyNightAllowance($endTime, $nightAllowance);
            } else {
                $errorMsg = 'OCR 找不到足夠的時間，請手動輸入';
            }
        } else {
            $errorMsg = 'OCR 無法讀取圖片，請手動輸入時間';
        }
    } catch (Exception $e) {
        $errorMsg = '辨識發生錯誤：' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>確認辨識結果</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f4f7f6; padding: 20px; margin: 0; }
        .result-card { background: white; padding: 28px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); max-width: 460px; margin: auto; }
        h2 { color: #2E7D32; margin-top: 0; }
        .error-msg { background: #FFF3E0; border-left: 4px solid #FF9800; color: #E65100; padding: 10px 14px; border-radius: 4px; margin-bottom: 16px; font-size: 0.9em; }
        .emp-info { background: #F1F8E9; border-radius: 8px; padding: 10px 14px; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; font-size: 0.9em; }
        .emp-info .emp-name { font-weight: bold; color: #2E7D32; }
        .badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 0.78em; font-weight: bold; }
        .badge-fulltime { background: #E3F2FD; color: #1565C0; }
        .badge-hourly   { background: #FFF8E1; color: #F57F17; }
        .data-row { display: flex; align-items: center; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #eee; gap: 10px; }
        .data-row label { color: #555; font-size: 0.95em; white-space: nowrap; min-width: 90px; }
        .value { font-weight: bold; color: #222; text-align: right; }
        .time-input { border: 2px solid #A5D6A7; border-radius: 6px; padding: 7px 10px; font-size: 1em; font-weight: bold; color: #1B5E20; background: #F1F8E9; width: 120px; text-align: center; transition: border-color 0.2s; }
        .time-input:focus { outline: none; border-color: #2E7D32; background: #E8F5E9; }
        .input-hint { font-size: 0.75em; color: #999; margin-top: 2px; text-align: right; }
        .salary-value { color: #C62828; font-weight: bold; font-size: 1.1em; }

        /* 休息選擇 */
        .break-section { background: #F8F9FA; border: 1.5px solid #dee2e6; border-radius: 10px; padding: 14px 16px; margin: 14px 0; }
        .break-title { font-size: 0.88em; color: #555; font-weight: bold; margin-bottom: 12px; }
        .break-options { display: flex; gap: 10px; }
        .break-option { flex: 1; position: relative; }
        .break-option input[type="radio"] { position: absolute; opacity: 0; width: 100%; height: 100%; cursor: pointer; margin: 0; z-index: 1; }
        .break-option label { display: flex; flex-direction: column; align-items: center; padding: 12px 8px; border: 2px solid #dee2e6; border-radius: 8px; cursor: pointer; transition: all 0.2s; background: white; text-align: center; gap: 4px; }
        .opt-icon  { font-size: 1.5em; }
        .opt-title { font-weight: bold; color: #333; font-size: 0.9em; }
        .opt-desc  { color: #888; font-size: 0.76em; line-height: 1.4; }
        .break-option.has-break input:checked + label { border-color: #2E7D32; background: #F1F8E9; }
        .break-option.has-break input:checked + label .opt-title { color: #2E7D32; }
        .break-option.no-break  input:checked + label { border-color: #FF9800; background: #FFF8E1; }
        .break-option.no-break  input:checked + label .opt-title { color: #E65100; }

        /* 夜班津貼區塊 */
        .night-section { border: 2px solid #B39DDB; border-radius: 10px; padding: 14px 16px; margin: 14px 0; background: #F3E5F5; }
        .night-title { font-size: 0.88em; color: #7C4DFF; font-weight: bold; margin-bottom: 10px; display: flex; align-items: center; gap: 6px; }
        .night-row { display: flex; justify-content: space-between; align-items: center; }
        .night-amount { font-size: 1em; font-weight: bold; color: #7C4DFF; }
        .night-cancel { display: flex; align-items: center; gap: 6px; font-size: 0.84em; color: #888; cursor: pointer; }
        .night-cancel input[type="checkbox"] { cursor: pointer; width: 16px; height: 16px; accent-color: #7C4DFF; }
        .night-note { font-size: 0.76em; color: #9C72C8; margin-top: 6px; }

        .btn-recalc { width: 100%; padding: 10px; background: #E8F5E9; color: #2E7D32; border: 2px solid #A5D6A7; border-radius: 8px; font-size: 0.95em; font-weight: bold; cursor: pointer; margin-top: 4px; }
        .btn-recalc:hover { background: #C8E6C9; }
        .divider { border: none; border-top: 2px dashed #eee; margin: 16px 0; }
        .btn-group { display: flex; gap: 10px; margin-top: 20px; }
        .btn { flex: 1; padding: 13px; border: none; border-radius: 8px; cursor: pointer; text-align: center; text-decoration: none; font-weight: bold; font-size: 0.95em; }
        .btn-confirm { background: #2E7D32; color: white; }
        .btn-confirm:hover { background: #1B5E20; }
        .btn-retry   { background: #6c757d; color: white; }
        .badge-header { display: inline-block; font-size: 0.75em; border-radius: 20px; padding: 2px 10px; margin-left: 8px; font-weight: normal; vertical-align: middle; }
        .badge-header.ok   { background: #E8F5E9; color: #2E7D32; }
        .badge-header.fail { background: #FFF3E0; color: #E65100; }
        .hidden { display: none; }
    </style>
</head>
<body>
<div class="topbar">
  <span class="topbar-title">🔍 確認辨識結果</span>
  <a href="index.php" class="topbar-link">← 返回</a>
</div>
<div class="main-wrap footer-pad">
<div class="card">
    <div class="card-title">
      <?php if (empty($errorMsg) && !empty($startTime)): ?>
        ✓ 辨識成功 <span class="badge badge-ok" style="font-size:0.8em">已識別</span>
      <?php else: ?>
        ✏️ 請手動輸入
      <?php endif; ?>
    </div>

    <?php if (!empty($errorMsg)): ?>
        <div class="msg msg-error">⚠️ <?php echo htmlspecialchars($errorMsg); ?></div>
    <?php endif; ?>

    <div class="emp-bar">
      <div>
        <div class="emp-name"><?php echo htmlspecialchars($employeeName); ?></div>
        <div class="emp-meta">
          <?php if ($empType === 'fulltime'): ?>
            月薪 $<?php echo number_format($wage); ?> · 時薪 $<?php echo round($hourlyRate, 2); ?>
          <?php else: ?>
            時薪 $<?php echo $wage; ?>/h
          <?php endif; ?>
          <?php if ($nightAllowance > 0): ?> · 🌙 $<?php echo $nightAllowance; ?><?php endif; ?>
        </div>
      </div>
      <span class="badge badge-<?php echo $empType; ?>"><?php echo $empType==='fulltime'?'正職':'時薪制'; ?></span>
    </div>

    <form action="upload_handler.php" method="post" id="mainForm">
        <input type="hidden" name="name"            value="<?php echo htmlspecialchars($employeeName); ?>">
        <input type="hidden" name="emp_type"        value="<?php echo $empType; ?>">
        <input type="hidden" name="hourly_rate"     value="<?php echo $wage; ?>">
        <input type="hidden" name="night_allowance" value="<?php echo $nightAllowance; ?>">

        <!-- 上下班時間 -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:4px">
          <div class="time-wrap">
            <div class="data-label" style="margin-bottom:5px">🟢 上班時間</div>
            <input type="text" id="start" name="start" class="time-input"
                   value="<?php echo htmlspecialchars($startTime); ?>"
                   placeholder="0900" required>
            <div class="input-hint">HH:MM</div>
          </div>
          <div class="time-wrap">
            <div class="data-label" style="margin-bottom:5px">🔴 下班時間</div>
            <input type="text" id="end" name="end" class="time-input"
                   value="<?php echo htmlspecialchars($endTime); ?>"
                   placeholder="1800" required>
            <div class="input-hint">HH:MM</div>
          </div>
        </div>

        <!-- 休息時間（正職才顯示） -->
        <?php if ($empType === 'fulltime'): ?>
        <div class="break-section" style="margin:12px 0">
            <div class="break-title">☕ 今日有無休息 30 分鐘？</div>
            <div class="break-options">
                <div class="break-option has-break">
                    <input type="radio" name="has_break" id="break-yes" value="1" checked onchange="recalculate()">
                    <label for="break-yes">
                        <span class="break-opt-icon">✅</span>
                        <span class="break-opt-title">有休息</span>
                        <span class="break-opt-desc">扣除 30 分鐘<br>再計算加班</span>
                    </label>
                </div>
                <div class="break-option no-break">
                    <input type="radio" name="has_break" id="break-no" value="0" onchange="recalculate()">
                    <label for="break-no">
                        <span class="break-opt-icon">⚡</span>
                        <span class="break-opt-title">沒有休息</span>
                        <span class="break-opt-desc">依實際時間<br>計算加班費</span>
                    </label>
                </div>
            </div>
        </div>
        <?php else: ?>
        <input type="hidden" name="has_break" value="0">
        <?php endif; ?>

        <!-- 夜班津貼（有設定且觸發才顯示） -->
        <?php if ($nightAllowance > 0): ?>
        <div class="night-section" id="night-section" style="<?php echo !$nightTriggered ? 'display:none' : ''; ?>">
            <div class="night-title">🌙 夜班津貼</div>
            <div class="night-row">
                <span class="night-amount">+ $<?php echo $nightAllowance; ?> 元</span>
                <label class="night-cancel">
                    <input type="checkbox" id="night-cancel" onchange="recalculate()">
                    取消本次夜班津貼
                </label>
            </div>
            <div class="night-note">下班時間超過 23:00 自動觸發，可手動取消</div>
        </div>
        <?php endif; ?>

        <button type="button" class="btn btn-ghost btn-full" onclick="recalculate()" style="margin-top:8px">🔄 重新計算薪資</button>

        <hr class="divider">

        <div class="data-row">
            <span class="data-label">實際工時</span>
            <span class="data-value" id="display-hours"><?php echo $salaryData['total_hours']; ?> 小時</span>
        </div>
        <div class="data-row <?php echo $empType !== 'fulltime' ? 'hidden' : ''; ?>">
            <span class="data-label">加班時數</span>
            <span class="data-value" id="display-overtime"><?php echo $salaryData['overtime_hours']; ?> 小時</span>
        </div>
        <div class="data-row <?php echo $empType !== 'fulltime' ? 'hidden' : ''; ?>">
            <span class="data-label">加班費</span>
            <span class="data-value" id="display-overtime-pay">$<?php echo $salaryData['overtime_pay'] ?? 0; ?></span>
        </div>
        <!-- 夜班津貼顯示列 -->
        <div class="data-row" id="night-display-row" style="<?php echo (!$nightAllowance || !$nightTriggered) ? 'display:none' : ''; ?>">
            <span class="data-label">🌙 夜班津貼</span>
            <span class="data-value" id="display-night">
                $<?php echo $nightTriggered ? $nightAllowance : 0; ?>
            </span>
        </div>
        <div class="data-row">
            <span class="data-label">預估薪資</span>
            <span class="data-value salary" id="display-salary">
                $<?php echo $salaryData['salary'] + ($nightTriggered ? $nightAllowance : 0); ?>
            </span>
        </div>

        <input type="hidden" name="salary"       id="hidden-salary"       value="<?php echo $salaryData['salary'] + ($nightTriggered ? $nightAllowance : 0); ?>">
        <input type="hidden" name="has_break"    id="hidden-has-break"    value="1">
        <input type="hidden" name="night_pay"    id="hidden-night-pay"    value="<?php echo $nightTriggered ? $nightAllowance : 0; ?>">
        <input type="hidden" name="wage"                                  value="<?php echo $wage; ?>">

        <!-- 加班費核算公式（正職才顯示） -->
        <?php if ($empType === 'fulltime' && $overtimeFormula): ?>
        <div style="margin-top:12px">
            <button type="button" onclick="toggleFormula()" style="width:100%;padding:9px;background:#F3F4F6;border:1.5px solid #ddd;border-radius:8px;font-size:0.85em;color:#555;cursor:pointer;font-weight:bold;text-align:left">
                📐 查看加班費核算公式 <span id="formula-arrow">▼</span>
            </button>
            <div id="formula-box" style="display:none;background:#F8F9FA;border:1.5px solid #ddd;border-radius:0 0 8px 8px;padding:14px 16px;font-size:0.82em;color:#444;line-height:2">
                <div style="margin-bottom:6px;font-weight:bold;color:#2E7D32">📋 勞基法加班費計算方式</div>
                <div>月薪 <strong>$<?php echo number_format($wage); ?></strong> ÷ 30 ÷ 8 ＝ 時薪 <strong>$<?php echo $overtimeFormula['hourly_rate']; ?></strong> 元</div>
                <hr style="border:none;border-top:1px dashed #ddd;margin:8px 0">
                <div>✅ 正常工時（8小時內）：時薪 $<?php echo $overtimeFormula['hourly_rate']; ?> × 時數</div>
                <div>🔶 加班前2小時（×1.34）：$<?php echo $overtimeFormula['ot1_rate']; ?> × 時數</div>
                <div>🔴 加班第3小時起（×1.67）：$<?php echo $overtimeFormula['ot2_rate']; ?> × 時數</div>
                <hr style="border:none;border-top:1px dashed #ddd;margin:8px 0">
                <div style="font-weight:bold;color:#555;margin-bottom:4px">本次計算明細：</div>
                <div id="formula-detail" style="color:#333;background:white;border-radius:6px;padding:8px 10px;border:1px solid #eee;line-height:1.8">
                    輸入時間後自動顯示
                </div>
                <hr style="border:none;border-top:1px dashed #ddd;margin:8px 0">
                <div style="color:#888;font-size:0.92em">※ 工時滿8小時且有休息，扣除30分鐘後再計算加班</div>
            </div>
        </div>
        <?php endif; ?>

        <div class="btn-row" style="margin-top:16px">
            <a href="index.php" class="btn btn-secondary">← 重新拍攝</a>
            <button type="submit" class="btn btn-primary">確認並儲存 ✓</button>
        </div>
    </form>
</div>
</div>
</div>

<script>
const empType        = "<?php echo $empType; ?>";
const wage           = <?php echo $wage; ?>;
// 正職：月薪換算時薪（月薪 ÷ 30 ÷ 8）；時薪制直接用時薪
const hourlyRate     = empType === 'fulltime' ? Math.round(wage / 30 / 8 * 10000) / 10000 : wage;
const nightAllowance = <?php echo $nightAllowance; ?>;

function toMinutes(t) {
    const p = t.split(':');
    if (p.length !== 2) return null;
    const h = parseInt(p[0]), m = parseInt(p[1]);
    return isNaN(h)||isNaN(m) ? null : h * 60 + m;
}

function shouldTriggerNight(endVal) {
    if (nightAllowance <= 0) return false;
    const em = toMinutes(endVal);
    if (em === null) return false;
    // 超過 23:00 或 凌晨 06:00 前（跨夜）
    return em >= 23 * 60 || em < 6 * 60;
}


// ── 時間輸入格式化 ──────────────────────────────
function initTimeInputs() {
    document.querySelectorAll('.time-input').forEach(input => {
        input.setAttribute('inputmode', 'numeric');
        input.setAttribute('maxlength', '5');

        input.addEventListener('input', function() {
            let v = this.value.replace(/[^0-9]/g, '').slice(0, 4);
            if (v.length === 4) {
                this.value = formatTime4(v);
                if (this.value) recalculate();
            } else if (v.length === 3) {
                this.value = v.slice(0,1) + ':' + v.slice(1);
            } else {
                this.value = v;
            }
        });

        input.addEventListener('blur', function() {
            const v = this.value.replace(/[^0-9]/g, '');
            let formatted = '';
            if (!v) { recalculate(); return; }
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
            recalculate();
        });

        input.addEventListener('paste', function(e) {
            e.preventDefault();
            const txt = (e.clipboardData || window.clipboardData).getData('text');
            const d = txt.replace(/[^0-9]/g, '').slice(0,4);
            if (d.length === 4) this.value = formatTime4(d);
            else this.value = d;
            recalculate();
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

function recalculate() {
    const startVal = document.getElementById('start').value.trim();
    const endVal   = document.getElementById('end').value.trim();
    const startMin = toMinutes(startVal);
    const endMin   = toMinutes(endVal);
    if (startMin === null || endMin === null) return;

    const radioYes = document.getElementById('break-yes');
    const hasBreak = radioYes ? radioYes.checked : false;

    let totalHours = ((endMin < startMin ? endMin + 1440 : endMin) - startMin) / 60;
    let overtimeHours = 0, overtimePay = 0, baseSalary = 0;

    if (empType === 'hourly') {
        // 時薪制：時薪 × 工時
        baseSalary = Math.round(totalHours * hourlyRate);
    } else {
        // 正職：只計算加班費（超過8小時的部分），月薪不列入
        const breakTime   = (hasBreak && totalHours >= 8) ? 0.5 : 0;
        const actual      = Math.max(totalHours - breakTime, 0);
        totalHours = actual;
        const ot1 = Math.min(Math.max(actual - 8, 0), 2);
        const ot2 = Math.max(actual - 10, 0);
        overtimeHours = ot1 + ot2;
        overtimePay   = Math.round(ot1 * hourlyRate * 1.34 + ot2 * hourlyRate * 1.67);
        baseSalary    = overtimePay; // 只顯示加班費
    }

    // 夜班津貼判斷
    const nightTriggered = shouldTriggerNight(endVal);
    const cancelBox      = document.getElementById('night-cancel');
    const isCancelled    = cancelBox ? cancelBox.checked : false;
    const nightPay       = (nightTriggered && !isCancelled) ? nightAllowance : 0;

    // 顯示/隱藏夜班區塊
    const nightSection = document.getElementById('night-section');
    const nightRow     = document.getElementById('night-display-row');
    if (nightSection) nightSection.style.display = nightTriggered ? '' : 'none';
    if (nightRow)     nightRow.style.display     = (nightTriggered && !isCancelled) ? '' : 'none';

    const totalSalary = baseSalary + nightPay;

    document.getElementById('display-hours').textContent        = Math.round(totalHours * 100) / 100 + ' 小時';
    if (document.getElementById('display-overtime'))
        document.getElementById('display-overtime').textContent     = Math.round(overtimeHours * 100) / 100 + ' 小時';
    if (document.getElementById('display-overtime-pay'))
        document.getElementById('display-overtime-pay').textContent = '$' + overtimePay;
    if (document.getElementById('display-night'))
        document.getElementById('display-night').textContent        = '$' + nightPay;
    document.getElementById('display-salary').textContent       = '$' + totalSalary;
    document.getElementById('hidden-salary').value              = totalSalary;
    document.getElementById('hidden-has-break').value           = hasBreak ? '1' : '0';
    document.getElementById('hidden-night-pay').value           = nightPay;

    // 更新公式明細（正職才有）
    const detailEl = document.getElementById('formula-detail');
    if (detailEl && empType === 'fulltime') {
        const h      = Math.round(wage / 30 / 8 * 100) / 100;
        const h134   = Math.round(h * 1.34 * 100) / 100;
        const h167   = Math.round(h * 1.67 * 100) / 100;
        const hasBreakNow = document.getElementById('break-yes') ? document.getElementById('break-yes').checked : false;
        const rawHours    = ((toMinutes(endVal) < toMinutes(startVal)
            ? toMinutes(endVal) + 1440 : toMinutes(endVal)) - toMinutes(startVal)) / 60;
        const breakDeducted = (hasBreakNow && rawHours >= 8) ? 0.5 : 0;
        const actual  = Math.max(rawHours - breakDeducted, 0);
        const normal  = Math.min(actual, 8);
        const ot      = Math.max(actual - 8, 0);
        const ot1h    = Math.min(ot, 2);
        const ot2h    = Math.max(ot - 2, 0);
        const normalPay = Math.round(normal * h);
        const ot1Pay  = Math.round(ot1h * h134);
        const ot2Pay  = Math.round(ot2h * h167);
        let detail = '';
        if (breakDeducted > 0) detail += `<span style="color:#888">已扣除休息 0.5h，實際工時 ${Math.round(actual*100)/100}h</span><br>`;
        if (actual <= 8) {
            detail += `<span style="color:#2E7D32">正常工時 ${Math.round(actual*100)/100}h，未超過8小時</span><br>`;
            detail += `<span style="color:#888">本日屬月薪範圍，無需另計加班費</span>`;
        } else {
            detail += `<span style="color:#888">正常工時 8h（月薪範圍，不另計）</span><br>`;
            if (ot1h > 0) detail += `🔶 加班前2h：${Math.round(ot1h*100)/100}h × $${h134}（×1.34）= <strong style="color:#F57F17">$${ot1Pay}</strong><br>`;
            if (ot2h > 0) detail += `🔴 加班第3h起：${Math.round(ot2h*100)/100}h × $${h167}（×1.67）= <strong style="color:#C62828">$${ot2Pay}</strong><br>`;
            detail += `<span style="color:#C62828;font-weight:bold">加班費合計：$${Math.round(ot1Pay+ot2Pay)}</span>`;
        }
        detailEl.innerHTML = detail;
    }
}

document.addEventListener('DOMContentLoaded', initTimeInputs);
document.getElementById('start').addEventListener('change', recalculate);
document.getElementById('end').addEventListener('change',   recalculate);

function toggleFormula() {
    const box   = document.getElementById('formula-box');
    const arrow = document.getElementById('formula-arrow');
    if (!box) return;
    const open = box.style.display === 'none';
    box.style.display   = open ? '' : 'none';
    if (arrow) arrow.textContent = open ? '▲' : '▼';
}

document.getElementById('mainForm').addEventListener('submit', function(e) {
    const s  = document.getElementById('start').value.trim();
    const en = document.getElementById('end').value.trim();
    if (!s || !en || toMinutes(s) === null || toMinutes(en) === null) {
        e.preventDefault(); alert('請確認時間格式正確（HH:MM）');
    }
});
</script>
</body>
</html>
