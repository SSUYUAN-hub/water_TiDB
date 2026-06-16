<?php
require_once __DIR__ . '/vendor/autoload.php';
include_once __DIR__ . '/functions.php';
include_once __DIR__ . '/db.php';
include_once __DIR__ . '/auth.php';
requireLogin();

$done    = isset($_GET['done']);
$isAdmin = isAdmin();

// ══════════════════════════════════════════════════════════
//  done=1：顯示寫入結果
// ══════════════════════════════════════════════════════════
if ($done) {
    $r = $_SESSION['write_result'] ?? null;
    if (!$r) { header('Location: scan_upload.php'); exit; }
    unset($_SESSION['write_result']);

    $name        = $r['name'];
    $empType     = $r['emp_type'];
    $yearMonth   = $r['year_month'];
    $rocYM       = ((int)explode('-', $yearMonth)[0] - 1911) . '年' . (int)explode('-', $yearMonth)[1] . '月';
    $records     = $r['records'];
    $hasNight    = $r['total_night_pay'] > 0;
    $isFulltime  = $empType === 'fulltime';
    $dbErrors    = $r['db_errors'];
    $laborCalc   = $r['labor_ins_calc']  ?? 0;
    $healthCalc  = $r['health_ins_calc'] ?? 0;
    $laborFinal  = $r['labor_ins']       ?? 0;
    $healthFinal = $r['health_ins']      ?? 0;
    $wasAdjusted = ($laborCalc + $healthCalc) !== ($laborFinal + $healthFinal);
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>寫入完成 — 薪資結算系統</title>
<link rel="stylesheet" href="responsive.css">
<style>
.main-wrap { max-width: 700px; }
.done-hero {
  background: linear-gradient(135deg, var(--green-800) 0%, var(--green-700) 100%);
  border-radius: var(--radius-lg); padding: 28px 24px; color: white;
  margin-bottom: 16px; box-shadow: 0 4px 20px rgba(46,125,50,0.25);
}
.done-hero .hero-icon { font-size: 2.2em; margin-bottom: 8px; }
.done-hero .hero-name { font-size: 1.3em; font-weight: 700; margin-bottom: 4px; }
.done-hero .hero-sub  { font-size: 0.85em; opacity: 0.8; }
.salary-breakdown { display: flex; flex-direction: column; gap: 0; margin-bottom: 14px; }
.sb-row {
  display: flex; justify-content: space-between; align-items: center;
  padding: 11px 16px; background: white; border-bottom: 1px solid #f0f0f0;
  font-size: 0.88em;
}
.sb-row:first-child { border-radius: var(--radius-md) var(--radius-md) 0 0; }
.sb-row:last-child  { border-radius: 0 0 var(--radius-md) var(--radius-md); border-bottom: none; }
.sb-row.total {
  background: var(--green-50); border-top: 2px solid #A5D6A7;
  border-radius: 0 0 var(--radius-md) var(--radius-md);
}
.sb-label { color: var(--grey-500); }
.sb-val   { font-weight: 700; font-family: var(--font-num); }
.sb-val.plus  { color: var(--amber-500); }
.sb-val.minus { color: var(--purple-600); }
.sb-val.net   { color: var(--green-700); font-size: 1.15em; }
.adjusted-tag {
  font-size: 0.72em; background: #FFF3E0; color: #E65100;
  border-radius: 4px; padding: 1px 6px; margin-left: 6px; font-weight: 600;
}
.result-table { width:100%; border-collapse:collapse; font-size:0.85em; }
.result-table th { background:var(--green-50); color:var(--green-700); padding:9px 10px; text-align:center; border:1px solid #C8E6C9; font-weight:600; white-space:nowrap; }
.result-table td { padding:8px 10px; text-align:center; border:1px solid #eee; white-space:nowrap; }
.result-table tr:nth-child(even) td { background:#FAFAFA; }
</style>
</head>
<body>
<div class="topbar">
  <div class="topbar-inner">
    <span class="topbar-title">✅ 寫入完成</span>
    <nav class="topbar-nav" id="topbar-nav">
      <a href="index.php" class="topbar-link">🏠 首頁</a>
    </nav>
  </div>
</div>
<div class="main-wrap footer-pad">

  <div class="done-hero">
    <div class="hero-icon">✅</div>
    <div class="hero-name"><?php echo htmlspecialchars($name); ?></div>
    <div class="hero-sub"><?php echo $rocYM; ?> 出勤紀錄已寫入（<?php echo $r['count']; ?> 筆）</div>
  </div>

  <?php if (!empty($dbErrors)): ?>
  <div class="msg msg-error" style="margin-bottom:14px">
    ⚠️ 部分資料寫入失敗：<br>
    <?php foreach ($dbErrors as $err) echo htmlspecialchars($err) . '<br>'; ?>
  </div>
  <?php else: ?>
  <div class="msg msg-success" style="margin-bottom:14px">✅ 所有資料已成功寫入</div>
  <?php endif; ?>

  <!-- 薪資明細 -->
  <div class="salary-breakdown" style="box-shadow:var(--card-shadow);border-radius:var(--radius-md)">
    <?php if ($isFulltime): ?>
    <div class="sb-row">
      <span class="sb-label">月薪</span>
      <span class="sb-val">$<?php echo number_format($r['total_salary'] - $r['total_ot_pay'] - $r['total_night_pay'] + ($r['labor_ins'] ?? 0) + ($r['health_ins'] ?? 0) - ($r['net_salary'] - ($r['total_salary'] - $r['total_ot_pay'] - $r['total_night_pay']))); ?></span>
    </div>
    <?php if ($r['total_ot_pay'] > 0): ?>
    <div class="sb-row">
      <span class="sb-label">加班費</span>
      <span class="sb-val plus">+$<?php echo number_format($r['total_ot_pay']); ?></span>
    </div>
    <?php endif; ?>
    <?php if ($r['total_night_pay'] > 0): ?>
    <div class="sb-row">
      <span class="sb-label">🌙 夜班津貼</span>
      <span class="sb-val plus">+$<?php echo number_format($r['total_night_pay']); ?></span>
    </div>
    <?php endif; ?>
    <div class="sb-row">
      <span class="sb-label">
        勞健保費用
        <?php if ($wasAdjusted): ?><span class="adjusted-tag">已調整</span><?php endif; ?>
      </span>
      <span class="sb-val minus">−$<?php echo number_format($laborFinal + $healthFinal); ?></span>
    </div>
    <div class="sb-row total">
      <span class="sb-label" style="font-weight:700;color:var(--green-700)">實領金額</span>
      <span class="sb-val net">$<?php echo number_format($r['net_salary']); ?></span>
    </div>
    <?php else: ?>
    <?php if ($hasNight): ?>
    <div class="sb-row">
      <span class="sb-label">🌙 夜班津貼</span>
      <span class="sb-val plus">$<?php echo number_format($r['total_night_pay']); ?></span>
    </div>
    <?php endif; ?>
    <div class="sb-row total">
      <span class="sb-label" style="font-weight:700;color:var(--green-700)">本月薪資</span>
      <span class="sb-val net">$<?php echo number_format($r['total_salary']); ?></span>
    </div>
    <?php endif; ?>
  </div>

  <!-- 每日明細 -->
  <div class="card" style="padding:0;overflow:hidden">
    <div style="padding:12px 16px;border-bottom:1px solid #eee;font-size:0.88em;font-weight:700;color:var(--green-700)">
      📅 每日明細
    </div>
    <div style="overflow-x:auto;padding:0 4px 4px">
    <table class="result-table">
      <thead><tr>
        <th>日期</th><th>第一段</th><th>第二段</th><th>工時</th>
        <?php if ($isFulltime): ?><th>加班費</th><?php endif; ?>
        <?php if ($hasNight): ?><th>🌙</th><?php endif; ?>
        <th><?php echo $isFulltime ? '加班費小計' : '薪資'; ?></th>
      </tr></thead>
      <tbody>
      <?php foreach ($records as $rec): ?>
      <tr>
        <td><?php echo $rec['date']; ?></td>
        <td><?php echo ($rec['s1_start'] && $rec['s1_end']) ? $rec['s1_start'].'→'.$rec['s1_end'] : '—'; ?></td>
        <td><?php echo ($rec['s2_start'] && $rec['s2_end']) ? $rec['s2_start'].'→'.$rec['s2_end'] : '—'; ?></td>
        <td><?php echo $rec['total_hours']; ?>h</td>
        <?php if ($isFulltime): ?>
        <td style="<?php echo $rec['overtime_pay'] > 0 ? 'color:var(--amber-500);font-weight:700' : ''; ?>">
          $<?php echo $rec['overtime_pay']; ?></td>
        <?php endif; ?>
        <?php if ($hasNight): ?>
        <td style="<?php echo $rec['night_pay'] > 0 ? 'color:var(--purple-600);font-weight:700' : 'color:var(--grey-300)'; ?>">
          <?php echo $rec['night_pay'] > 0 ? '$'.$rec['night_pay'] : '—'; ?></td>
        <?php endif; ?>
        <td style="color:var(--red-600);font-weight:700">$<?php echo number_format($rec['salary']); ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>

  <div class="btn-row" style="margin-top:16px">
    <a href="scan_upload.php" class="btn btn-primary">📷 繼續下一位</a>
    <a href="attendance.php?emp=<?php echo urlencode($name); ?>&ym=<?php echo $yearMonth; ?>&searched=1" class="btn btn-ghost">📊 查看出勤紀錄</a>
  </div>
</div>
</body>
</html>
<?php
    exit;
}

// ══════════════════════════════════════════════════════════
//  確認頁：從 session 取 payload 顯示
// ══════════════════════════════════════════════════════════
$p = $_SESSION['confirm_payload'] ?? null;
if (!$p) { header('Location: scan_upload.php'); exit; }

$name          = $p['name'];
$empType       = $p['emp_type'];
$wage          = (int)$p['wage'];
$yearMonth     = $p['year_month'];
$rocYM         = ((int)explode('-', $yearMonth)[0] - 1911) . '年' . (int)explode('-', $yearMonth)[1] . '月';
$records       = $p['records'];
$isFulltime    = $empType === 'fulltime';
$hasNight      = $p['total_night_pay'] > 0;
$totalOTPay    = $p['total_ot_pay'];
$totalNightPay = $p['total_night_pay'];
$totalHours    = $p['total_hours'];
$totalOTHours  = $p['total_ot_hours'];
$totalSalary   = $p['total_salary'];

// 勞健保（正職）
$insuredSalary  = $p['insured_salary']   ?? 0;
$healthInsured  = $p['health_insured']   ?? 0;
$laborRate      = $p['labor_ins_rate']   ?? 0;
$laborShare     = $p['labor_ins_share']  ?? 0;
$healthRate     = $p['health_ins_rate']  ?? 0;
$healthShare    = $p['health_ins_share'] ?? 0;
$laborInsCalc   = $p['labor_ins_calc']   ?? 0;
$healthInsCalc  = $p['health_ins_calc']  ?? 0;
$insTotal       = $p['ins_total']        ?? 0;
$netSalary      = $p['net_salary']       ?? $totalSalary;
// 時薪 & 夜班天數（時薪制明細用）
$hourlyRate     = $isFulltime ? 0 : $wage;
$nightDays      = count(array_filter($records, fn($r) => ($r['night_pay'] ?? 0) > 0));
$nightAllow     = $nightDays > 0 && $totalNightPay > 0 ? (int)round($totalNightPay / $nightDays) : 0;
// 正職夜班同樣計算
$ftNightDays    = $isFulltime ? count(array_filter($records, fn($r) => ($r['night_pay'] ?? 0) > 0)) : 0;
$ftNightAllow   = $ftNightDays > 0 && $totalNightPay > 0 ? (int)round($totalNightPay / $ftNightDays) : 0;
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>確認統計 — 薪資結算系統</title>
<link rel="stylesheet" href="responsive.css">
<style>
.main-wrap { max-width: 700px; }

.emp-hero {
  background: linear-gradient(135deg, var(--green-800) 0%, var(--green-700) 100%);
  border-radius: var(--radius-lg); padding: 20px 22px; color: white;
  margin-bottom: 14px; display: flex; justify-content: space-between; align-items: center;
  box-shadow: 0 4px 16px rgba(46,125,50,0.2);
}
.emp-hero-left .hero-name { font-size: 1.2em; font-weight: 700; margin-bottom: 4px; }
.emp-hero-left .hero-sub  { font-size: 0.82em; opacity: 0.8; }

/* 摘要格 */
.summary-grid {
  display: grid; grid-template-columns: repeat(auto-fit, minmax(130px,1fr));
  gap: 10px; margin-bottom: 14px;
}
.summary-cell {
  background: white; border-radius: var(--radius-md);
  padding: 14px 16px; box-shadow: var(--card-shadow); text-align: center;
}
.summary-cell .s-label { font-size: 0.75em; color: var(--grey-500); margin-bottom: 4px; }
.summary-cell .s-value { font-size: 1.15em; font-weight: 700; font-family: var(--font-num); color: var(--grey-900); }
.summary-cell.ot     .s-value { color: var(--amber-500); }
.summary-cell.night  .s-value { color: var(--purple-600); }
.summary-cell.salary .s-value { color: var(--red-600); }

/* 薪資明細卡片 */
.salary-card {
  background: white; border-radius: var(--radius-md);
  box-shadow: var(--card-shadow); margin-bottom: 14px; overflow: hidden;
}
.salary-card-title {
  padding: 13px 16px; border-bottom: 1px solid #eee;
  font-size: 0.9em; font-weight: 700; color: var(--grey-900);
}
.sb-row {
  display: flex; justify-content: space-between; align-items: center;
  padding: 11px 16px; border-bottom: 1px solid #f5f5f5; font-size: 0.93em;
}
.sb-row:last-child { border-bottom: none; }
.sb-row.net-row {
  background: var(--green-50); border-top: 2px solid #A5D6A7;
  padding: 14px 16px;
}
.sb-label { color: var(--grey-600); }
.sb-val   { font-weight: 700; font-family: var(--font-num); }
.sb-val.plus  { color: var(--amber-500); }
.sb-val.minus { color: var(--purple-600); }
.sb-val.net   { color: var(--green-700); font-size: 1.25em; }

/* 勞健保輸入區 */
.ins-input-row {
  display: flex; align-items: center; gap: 10px;
  padding: 11px 16px; border-bottom: 1px solid #f5f5f5;
}
.ins-input-label { font-size: 0.88em; color: var(--grey-600); flex: 1; }
.ins-input-wrap  { display: flex; align-items: center; gap: 6px; }
.ins-prefix      { font-size: 0.88em; color: var(--purple-600); font-weight: 700; }
.ins-input {
  padding: 8px 11px; border: 1.5px solid #B39DDB;
  border-radius: var(--radius-sm); font-size: 0.95em;
  font-family: var(--font-num); font-weight: 700;
  color: var(--purple-600); width: 110px; text-align: right; background: white;
}
.ins-input:focus { outline: none; border-color: var(--purple-600); }
.ins-input[readonly] {
  background: var(--grey-100); color: var(--grey-500);
  border-color: var(--grey-300); cursor: not-allowed;
}

/* 公式展開 */
.formula-toggle {
  width: 100%; padding: 9px 16px;
  background: #F8F9FA; border: none; border-top: 1px solid #f0f0f0;
  font-size: 0.82em; color: var(--grey-600); cursor: pointer; font-weight: 600;
  text-align: left; font-family: var(--font-body);
  display: flex; justify-content: space-between; align-items: center;
  transition: background var(--transition);
}
.formula-toggle:hover { background: #F0F1F3; }
.formula-box {
  display: none; background: #F8F9FA; border-top: 1px solid #eee;
  padding: 14px 16px; font-size: 0.82em; color: var(--grey-700); line-height: 2;
}
.f-section-title {
  font-size: 0.8em; font-weight: 700; color: var(--grey-500);
  text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 6px; margin-top: 10px;
}
.f-section-title:first-child { margin-top: 0; }
.f-row { display: flex; justify-content: space-between; padding: 3px 0; }
.f-label { color: var(--grey-500); }
.f-val   { font-weight: 700; font-family: var(--font-num); }
.f-result {
  margin-top: 8px; padding: 8px 12px; background: white;
  border-radius: 6px; border: 1px solid #ddd;
  display: flex; justify-content: space-between; font-weight: 700;
}
.f-result-label { color: var(--purple-600); }
.f-result-val   { color: var(--purple-600); font-family: var(--font-num); }

/* 費率更新區（管理員） */
.rate-update-wrap {
  margin-top: 12px; padding: 12px 14px;
  background: #FFF8E1; border: 1.5px solid #FFE082; border-radius: var(--radius-sm);
}
.rate-update-title { font-size: 0.8em; font-weight: 700; color: #E65100; margin-bottom: 10px; }
.rate-fields { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.rate-field label { font-size: 0.78em; color: var(--grey-500); display: block; margin-bottom: 3px; font-weight: 600; }
.rate-field input {
  width: 100%; padding: 7px 10px; border: 1.5px solid #FFE082;
  border-radius: 6px; font-size: 0.88em; font-family: var(--font-num);
  background: white; box-sizing: border-box;
}
.rate-field input:focus { outline: none; border-color: var(--amber-500); }

/* 每日明細折疊 */
.detail-toggle {
  width: 100%; padding: 14px 16px; background: none; border: none; cursor: pointer;
  display: flex; justify-content: space-between; align-items: center;
  font-size: 0.88em; font-weight: 700; color: var(--green-700);
  font-family: var(--font-body);
}
.result-table { width:100%; border-collapse:collapse; font-size:0.85em; }
.result-table th { background:var(--green-50); color:var(--green-700); padding:9px 10px; text-align:center; border:1px solid #C8E6C9; font-weight:600; white-space:nowrap; }
.result-table td { padding:8px 10px; text-align:center; border:1px solid #eee; white-space:nowrap; }
.result-table tr:nth-child(even) td { background:#FAFAFA; }

@media (max-width: 540px) {
  .summary-grid { grid-template-columns: repeat(2, 1fr); }
  .rate-fields  { grid-template-columns: 1fr; }
}
</style>
</head>
<body>
<input type="hidden" id="global-csrf-token" name="csrf_token"
       value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES); ?>">

<div class="topbar">
  <div class="topbar-inner">
    <span class="topbar-title">📋 確認統計結果</span>
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

<div class="main-wrap footer-pad">

  <!-- 員工資訊頭 -->
  <div class="emp-hero">
    <div class="emp-hero-left">
      <div class="hero-name"><?php echo htmlspecialchars($name); ?></div>
      <div class="hero-sub"><?php echo $rocYM; ?> · 請確認後再寫入資料庫</div>
    </div>
    <span class="badge badge-<?php echo $empType; ?>" style="background:rgba(255,255,255,0.2);color:white;border:1px solid rgba(255,255,255,0.4)">
      <?php echo $isFulltime ? '正職' : '時薪制'; ?>
    </span>
  </div>

  <!-- 出勤摘要格 -->
  <div class="summary-grid">
    <div class="summary-cell">
      <div class="s-label">出勤天數</div>
      <div class="s-value"><?php echo count($records); ?> 天</div>
    </div>
    <div class="summary-cell">
      <div class="s-label">總工時</div>
      <div class="s-value"><?php echo $totalHours; ?> h</div>
    </div>
    <?php if ($isFulltime): ?>
    <div class="summary-cell ot">
      <div class="s-label">加班時數</div>
      <div class="s-value"><?php echo $totalOTHours; ?> h</div>
    </div>
    <div class="summary-cell ot">
      <div class="s-label">加班費合計</div>
      <div class="s-value">$<?php echo number_format($totalOTPay); ?></div>
    </div>
    <?php endif; ?>
    <?php if ($hasNight): ?>
    <div class="summary-cell night">
      <div class="s-label">🌙 夜班津貼</div>
      <div class="s-value">$<?php echo number_format($totalNightPay); ?></div>
    </div>
    <?php endif; ?>
    <?php if (!$isFulltime): ?>
    <div class="summary-cell salary">
      <div class="s-label">本月薪資</div>
      <div class="s-value">$<?php echo number_format($totalSalary); ?></div>
    </div>
    <?php endif; ?>
  </div>

  <!-- 薪資確認 form -->
  <form action="batch_handler.php" method="post" id="confirm-form">
    <input type="hidden" name="action"     value="write">
    <input type="hidden" name="net_salary" id="h-net-salary" value="<?php echo $netSalary; ?>">
    <?php csrfField(); ?>

    <!-- 薪資明細卡片 -->
    <div class="salary-card">
      <div class="salary-card-title">💰 薪資明細</div>

      <?php if ($isFulltime): ?>
      <!-- 月薪 -->
      <div class="sb-row">
        <span class="sb-label">月薪</span>
        <span class="sb-val">$<?php echo number_format($wage); ?></span>
      </div>
      <!-- 加班費 -->
      <?php if ($totalOTPay > 0): ?>
      <div class="sb-row">
        <span class="sb-label">加班費</span>
        <span class="sb-val plus">+$<?php echo number_format($totalOTPay); ?></span>
      </div>
      <?php endif; ?>
      <!-- 夜班津貼 -->
      <?php if ($totalNightPay > 0): ?>
      <div class="sb-row">
        <span class="sb-label">
          🌙 夜班津貼
          <span style="font-size:0.82em;color:var(--grey-400);font-weight:400;margin-left:4px">
            （<?php echo $ftNightDays; ?>天 × $<?php echo number_format($ftNightAllow); ?>）
          </span>
        </span>
        <span class="sb-val plus">+$<?php echo number_format($totalNightPay); ?></span>
      </div>
      <?php endif; ?>

      <!-- 勞健保費用輸入（管理員可改，員工唯讀） -->
      <div class="ins-input-row">
        <span class="ins-input-label">勞健保費用</span>
        <div class="ins-input-wrap">
          <span class="ins-prefix">−$</span>
          <?php if ($isAdmin): ?>
          <input type="number" class="ins-input" id="ins-total-input"
            name="ins_total" value="<?php echo $insTotal; ?>" min="0"
            oninput="onInsTotalChange()">
          <?php else: ?>
          <input type="number" class="ins-input" id="ins-total-input"
            value="<?php echo $insTotal; ?>" readonly>
          <input type="hidden" name="ins_total" value="<?php echo $insTotal; ?>">
          <?php endif; ?>
        </div>
      </div>

      <!-- 公式展開按鈕 -->
      <button type="button" class="formula-toggle" onclick="toggleFormula()">
        <span>📐 查看勞健保計算公式</span>
        <span id="formula-arrow">▼</span>
      </button>
      <div class="formula-box" id="formula-box">

        <!-- 勞保 -->
        <div class="f-section-title">🛡️ 勞保費</div>
        <div class="f-row">
          <span class="f-label">員工月薪</span>
          <span class="f-val">$<?php echo number_format($wage); ?></span>
        </div>
        <div class="f-row">
          <span class="f-label">對應投保薪資級距</span>
          <span class="f-val">$<?php echo number_format($insuredSalary); ?></span>
        </div>
        <div class="f-row">
          <span class="f-label">勞保費率</span>
          <span class="f-val" id="disp-labor-rate"><?php echo round($laborRate * 100, 2); ?>%</span>
        </div>
        <div class="f-row">
          <span class="f-label">員工自付比例</span>
          <span class="f-val" id="disp-labor-share"><?php echo round($laborShare * 100, 2); ?>%</span>
        </div>
        <div class="f-row">
          <span class="f-label">計算式</span>
          <span class="f-val" id="disp-labor-formula">
            $<?php echo number_format($insuredSalary); ?> × <?php echo round($laborRate*100,2); ?>% × <?php echo round($laborShare*100,2); ?>%
          </span>
        </div>
        <div class="f-result">
          <span class="f-result-label">勞保員工自付</span>
          <span class="f-result-val" id="disp-labor-result">$<?php echo number_format($laborInsCalc); ?></span>
        </div>

        <!-- 健保 -->
        <div class="f-section-title" style="margin-top:16px">🏥 健保費</div>
        <div class="f-row">
          <span class="f-label">員工月薪</span>
          <span class="f-val">$<?php echo number_format($wage); ?></span>
        </div>
        <div class="f-row">
          <span class="f-label">對應投保薪資級距</span>
          <span class="f-val">$<?php echo number_format($healthInsured); ?></span>
        </div>
        <div class="f-row">
          <span class="f-label">健保費率</span>
          <span class="f-val" id="disp-health-rate"><?php echo round($healthRate * 100, 3); ?>%</span>
        </div>
        <div class="f-row">
          <span class="f-label">員工自付比例</span>
          <span class="f-val" id="disp-health-share"><?php echo round($healthShare * 100, 2); ?>%</span>
        </div>
        <div class="f-row">
          <span class="f-label">計算式</span>
          <span class="f-val" id="disp-health-formula">
            $<?php echo number_format($healthInsured); ?> × <?php echo round($healthRate*100,3); ?>% × <?php echo round($healthShare*100,2); ?>%
          </span>
        </div>
        <div class="f-result">
          <span class="f-result-label">健保員工自付</span>
          <span class="f-result-val" id="disp-health-result">$<?php echo number_format($healthInsCalc); ?></span>
        </div>
        <div class="f-result" style="margin-top:6px;border-color:#B39DDB">
          <span class="f-result-label">勞健保合計</span>
          <span class="f-result-val" id="disp-ins-total">$<?php echo number_format($laborInsCalc + $healthInsCalc); ?></span>
        </div>

        <?php if ($isAdmin): ?>
        <!-- 費率更新（管理員） -->
        <div class="rate-update-wrap">
          <div class="rate-update-title">⚙️ 更新費率（儲存後套用所有未來計算）</div>
          <div class="rate-fields">
            <div class="rate-field">
              <label>勞保費率（%）</label>
              <input type="number" id="inp-labor-rate" step="0.01" min="0" max="100"
                value="<?php echo round($laborRate * 100, 2); ?>" oninput="recalcFromRates()">
            </div>
            <div class="rate-field">
              <label>勞保員工自付比例（%）</label>
              <input type="number" id="inp-labor-share" step="0.01" min="0" max="100"
                value="<?php echo round($laborShare * 100, 2); ?>" oninput="recalcFromRates()">
            </div>
            <div class="rate-field">
              <label>健保費率（%）</label>
              <input type="number" id="inp-health-rate" step="0.001" min="0" max="100"
                value="<?php echo round($healthRate * 100, 3); ?>" oninput="recalcFromRates()">
            </div>
            <div class="rate-field">
              <label>健保員工自付比例（%）</label>
              <input type="number" id="inp-health-share" step="0.01" min="0" max="100"
                value="<?php echo round($healthShare * 100, 2); ?>" oninput="recalcFromRates()">
            </div>
          </div>
          <button type="button" class="btn btn-ghost btn-sm" style="margin-top:10px" onclick="saveRates()">
            💾 儲存費率設定
          </button>
          <div id="rate-save-msg" style="font-size:0.8em;margin-top:6px;display:none"></div>
        </div>
        <?php endif; ?>
      </div><!-- /formula-box -->

      <!-- 實領金額 -->
      <div class="sb-row net-row">
        <span class="sb-label" style="font-weight:700;color:var(--green-700)">
          月薪<?php echo $totalOTPay > 0 ? ' + 加班費' : ''; ?><?php echo $totalNightPay > 0 ? ' + 津貼' : ''; ?> − 勞健保 ＝ 應領金額
        </span>
        <span class="sb-val net" id="disp-net-salary">$<?php echo number_format($netSalary); ?></span>
      </div>

      <?php else: ?>
      <!-- 時薪制：薪資計算式 -->
      <div class="sb-row">
        <span class="sb-label">
          薪資
          <span style="font-size:0.82em;color:var(--grey-400);font-weight:400;margin-left:4px">
            （<?php echo $totalHours; ?>h × $<?php echo number_format($hourlyRate); ?>）
          </span>
        </span>
        <span class="sb-val">$<?php echo number_format($totalSalary - $totalNightPay); ?></span>
      </div>
      <?php if ($hasNight): ?>
      <div class="sb-row">
        <span class="sb-label">
          🌙 夜班津貼
          <span style="font-size:0.82em;color:var(--grey-400);font-weight:400;margin-left:4px">
            （<?php echo $nightDays; ?>天 × $<?php echo number_format($nightAllow); ?>）
          </span>
        </span>
        <span class="sb-val plus">+$<?php echo number_format($totalNightPay); ?></span>
      </div>
      <?php endif; ?>
      <div class="sb-row net-row">
        <span class="sb-label" style="font-weight:700;color:var(--green-700)">本月薪資合計</span>
        <span class="sb-val net">$<?php echo number_format($totalSalary); ?></span>
      </div>
      <?php endif; ?>
    </div><!-- /salary-card -->

    <!-- 確認按鈕 -->
    <div class="btn-row">
      <a href="scan_upload.php" class="btn btn-secondary">← 取消</a>
      <button type="submit" class="btn btn-primary" style="min-height:48px">
        ✅ 確認無誤，寫入資料庫
      </button>
    </div>
  </form>

  <!-- 每日明細（折疊） -->
  <div class="card" style="padding:0;overflow:hidden;margin-top:14px">
    <button type="button" class="detail-toggle" onclick="toggleDetail()">
      <span>📅 每日明細（<?php echo count($records); ?> 天）</span>
      <span id="detail-arrow">▼</span>
    </button>
    <div id="detail-panel" style="display:none;overflow-x:auto;padding:0 4px 4px">
    <table class="result-table">
      <thead><tr>
        <th>日期</th><th>第一段</th><th>第二段</th><th>工時</th>
        <?php if ($isFulltime): ?><th>加班費</th><?php endif; ?>
        <?php if ($hasNight): ?><th>🌙</th><?php endif; ?>
        <th><?php echo $isFulltime ? '加班費小計' : '薪資'; ?></th>
      </tr></thead>
      <tbody>
      <?php foreach ($records as $rec): ?>
      <tr>
        <td><?php echo $rec['date']; ?></td>
        <td><?php echo ($rec['s1_start'] && $rec['s1_end']) ? $rec['s1_start'].'→'.$rec['s1_end'] : '—'; ?></td>
        <td><?php echo ($rec['s2_start'] && $rec['s2_end']) ? $rec['s2_start'].'→'.$rec['s2_end'] : '—'; ?></td>
        <td><?php echo $rec['total_hours']; ?>h</td>
        <?php if ($isFulltime): ?>
        <td style="<?php echo $rec['overtime_pay'] > 0 ? 'color:var(--amber-500);font-weight:700' : ''; ?>">
          $<?php echo $rec['overtime_pay']; ?></td>
        <?php endif; ?>
        <?php if ($hasNight): ?>
        <td style="<?php echo $rec['night_pay'] > 0 ? 'color:var(--purple-600);font-weight:700' : 'color:var(--grey-300)'; ?>">
          <?php echo $rec['night_pay'] > 0 ? '$'.$rec['night_pay'] : '—'; ?></td>
        <?php endif; ?>
        <td style="color:var(--red-600);font-weight:700">$<?php echo number_format($rec['salary']); ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>

</div>

<script>
function toggleNav(btn) {
  const nav = document.getElementById('topbar-nav');
  nav.classList.toggle('open');
  btn.setAttribute('aria-expanded', nav.classList.contains('open'));
}
function toggleFormula() {
  const box   = document.getElementById('formula-box');
  const arrow = document.getElementById('formula-arrow');
  const open  = box.style.display === 'none';
  box.style.display = open ? 'block' : 'none';
  arrow.textContent = open ? '▲' : '▼';
}
function toggleDetail() {
  const p = document.getElementById('detail-panel');
  const a = document.getElementById('detail-arrow');
  const open = p.style.display === 'none';
  p.style.display = open ? '' : 'none';
  a.textContent   = open ? '▲' : '▼';
}

// PHP 注入常數
const wage         = <?php echo $wage; ?>;
const totalOTPay   = <?php echo $totalOTPay; ?>;
const totalNightPay= <?php echo $totalNightPay; ?>;
const insuredLabor = <?php echo $insuredSalary; ?>;
const insuredHealth= <?php echo $healthInsured; ?>;

function fmt(n) { return '$' + Math.round(n).toLocaleString(); }

// 管理員直接修改勞健保合計 → 重算應領
function onInsTotalChange() {
  const ins = parseInt(document.getElementById('ins-total-input').value) || 0;
  const net = wage + totalOTPay + totalNightPay - ins;
  document.getElementById('disp-net-salary').textContent = fmt(net);
  document.getElementById('h-net-salary').value = net;
}

// 費率輸入框變更 → 重算勞健保並更新公式顯示
function recalcFromRates() {
  <?php if ($isAdmin): ?>
  const lRate  = parseFloat(document.getElementById('inp-labor-rate').value)   / 100 || 0;
  const lShare = parseFloat(document.getElementById('inp-labor-share').value)  / 100 || 0;
  const hRate  = parseFloat(document.getElementById('inp-health-rate').value)  / 100 || 0;
  const hShare = parseFloat(document.getElementById('inp-health-share').value) / 100 || 0;

  const labor  = Math.ceil(insuredLabor  * lRate  * lShare);
  const health = Math.ceil(insuredHealth * hRate  * hShare);
  const total  = labor + health;

  // 更新公式顯示
  document.getElementById('disp-labor-rate').textContent    = (lRate  * 100).toFixed(2)  + '%';
  document.getElementById('disp-labor-share').textContent   = (lShare * 100).toFixed(2)  + '%';
  document.getElementById('disp-labor-formula').textContent =
    '$' + insuredLabor.toLocaleString() + ' × ' + (lRate*100).toFixed(2) + '% × ' + (lShare*100).toFixed(2) + '%';
  document.getElementById('disp-labor-result').textContent  = fmt(labor);
  document.getElementById('disp-health-rate').textContent   = (hRate  * 100).toFixed(3)  + '%';
  document.getElementById('disp-health-share').textContent  = (hShare * 100).toFixed(2)  + '%';
  document.getElementById('disp-health-formula').textContent=
    '$' + insuredHealth.toLocaleString() + ' × ' + (hRate*100).toFixed(3) + '% × ' + (hShare*100).toFixed(2) + '%';
  document.getElementById('disp-health-result').textContent = fmt(health);
  document.getElementById('disp-ins-total').textContent     = fmt(total);

  // 同步到勞健保合計輸入框並觸發應領重算
  document.getElementById('ins-total-input').value = total;
  onInsTotalChange();
  <?php endif; ?>
}

// 儲存費率（AJAX）
function saveRates() {
  <?php if ($isAdmin): ?>
  const data = {
    csrf_token:       document.getElementById('global-csrf-token').value,
    labor_ins_rate:   (parseFloat(document.getElementById('inp-labor-rate').value)   / 100).toFixed(4),
    labor_ins_share:  (parseFloat(document.getElementById('inp-labor-share').value)  / 100).toFixed(4),
    health_ins_rate:  (parseFloat(document.getElementById('inp-health-rate').value)  / 100).toFixed(4),
    health_ins_share: (parseFloat(document.getElementById('inp-health-share').value) / 100).toFixed(4),
  };
  const msg = document.getElementById('rate-save-msg');
  fetch('api_settings.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data),
  })
  .then(r => r.json())
  .then(res => {
    msg.textContent  = res.ok ? '✅ 費率已儲存，下次計算起生效' : ('⚠️ ' + (res.error || '儲存失敗'));
    msg.style.color  = res.ok ? 'var(--green-700)' : 'var(--red-600)';
    msg.style.display = '';
  })
  .catch(() => {
    msg.textContent = '⚠️ 網路錯誤，請重試';
    msg.style.color = 'var(--red-600)';
    msg.style.display = '';
  });
  <?php endif; ?>
}
</script>
</body>
</html>