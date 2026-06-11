<?php
session_start();
require_once __DIR__ . '/db.php';
include_once __DIR__ . '/auth.php';
requireLogin();

$user    = currentUser();
$isAdmin = isAdmin();

// 待審核申請數（管理員才需要）
$pendingRequestCount = 0;
if ($isAdmin) {
    try {
        // 確保資料表存在後再查
        getDB()->exec("CREATE TABLE IF NOT EXISTS account_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(60) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            real_name VARCHAR(60) NOT NULL,
            id_number VARCHAR(20) NOT NULL,
            phone VARCHAR(30) NOT NULL,
            status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            reject_reason VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) DEFAULT CHARSET=utf8mb4");
        $stmt = getDB()->query("SELECT COUNT(*) FROM account_requests WHERE status='pending'");
        $pendingRequestCount = (int)$stmt->fetchColumn();
    } catch (Exception $e) { $pendingRequestCount = 0; }
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <title>首頁 — 薪資結算系統</title>
  <link rel="stylesheet" href="responsive.css">
  <style>
    /* 柔和漸層底色 */
    body {
      background: linear-gradient(135deg, #e8f5e9 0%, #f1f8e9 40%, #e3f2fd 100%);
      min-height: 100vh;
    }

    .home-wrap {
      max-width: 600px;
      margin: 0 auto;
      padding: 20px 16px 40px;
    }

    /* 歡迎區塊 */
    .welcome-card {
      background: linear-gradient(135deg, var(--green-800) 0%, var(--green-700) 100%);
      border-radius: var(--radius-lg);
      padding: 28px 24px;
      margin-bottom: 24px;
      color: white;
      box-shadow: 0 4px 20px rgba(46,125,50,0.25);
      position: relative;
      overflow: hidden;
    }
    .welcome-card::before {
      content: '';
      position: absolute;
      top: -30px; right: -30px;
      width: 120px; height: 120px;
      border-radius: 50%;
      background: rgba(255,255,255,0.06);
    }
    .welcome-card::after {
      content: '';
      position: absolute;
      bottom: -20px; left: -10px;
      width: 80px; height: 80px;
      border-radius: 50%;
      background: rgba(255,255,255,0.04);
    }
    .welcome-greeting {
      font-size: 0.82em;
      opacity: 0.8;
      margin-bottom: 4px;
      letter-spacing: 0.04em;
    }
    .welcome-name {
      font-size: 1.4em;
      font-weight: 700;
      margin-bottom: 8px;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .welcome-role {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      font-size: 0.75em;
      background: rgba(255,255,255,0.18);
      border-radius: 20px;
      padding: 3px 10px;
      font-weight: 600;
    }
    .welcome-time {
      font-size: 0.8em;
      opacity: 0.7;
      margin-top: 10px;
      font-family: var(--font-num);
    }

    /* 功能選單區塊標題 */
    .section-label {
      font-size: 0.82em;
      font-weight: 700;
      color: var(--grey-600);
      letter-spacing: 0.06em;
      text-transform: uppercase;
      margin-bottom: 12px;
      padding-left: 2px;
    }

    /* 功能卡片 grid */
    .menu-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
      margin-bottom: 20px;
    }
    @media (max-width: 400px) {
      .menu-grid { grid-template-columns: 1fr; }
    }

    .menu-card {
      background: white;
      border-radius: var(--radius-md);
      padding: 24px 20px;
      box-shadow: var(--card-shadow);
      text-decoration: none;
      color: inherit;
      display: flex;
      flex-direction: column;
      gap: 6px;
      transition: all 0.2s ease;
      border: 1.5px solid transparent;
      position: relative;
      overflow: hidden;
    }
    .menu-card:hover {
      box-shadow: var(--card-shadow-hover);
      transform: translateY(-2px);
      border-color: var(--green-100);
    }
    .menu-card:active {
      transform: translateY(0);
    }
    .menu-card::before {
      content: '';
      position: absolute;
      top: 0; left: 0;
      width: 4px; height: 100%;
      border-radius: 4px 0 0 4px;
    }
    .menu-card.green::before  { background: var(--green-700); }
    .menu-card.blue::before   { background: var(--blue-700); }
    .menu-card.purple::before { background: var(--purple-600); }
    .menu-card.amber::before  { background: var(--amber-500); }

    .menu-icon {
      font-size: 2.2em;
      line-height: 1;
      margin: auto;
    }
    .menu-title {
      font-size: 1.05em;
      font-weight: 700;
      color: var(--grey-900);
      margin-bottom: 2px;
      margin: auto;
    }
    .menu-desc {
      font-size: 0.88em;
      color: var(--grey-600);
      line-height: 1.6;
      flex: 1;
    }
    .menu-arrow {
      font-size: 0.85em;
      font-weight: 700;
      color: var(--grey-400);
      margin-top: 8px;
      align-self: flex-end;
    }
    .menu-card.green  .menu-arrow { color: var(--green-600); }
    .menu-card.blue   .menu-arrow { color: var(--blue-700); }
    .menu-card.purple .menu-arrow { color: var(--purple-600); }
    .menu-card.amber  .menu-arrow { color: var(--amber-500); }

    /* 登出按鈕 */
    .logout-wrap {
      text-align: center;
      margin-top: 8px;
    }
    .logout-btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 10px 24px;
      background: white;
      border: 1.5px solid var(--grey-300);
      border-radius: var(--radius-sm);
      color: var(--grey-500);
      font-size: 0.88em;
      font-weight: 600;
      text-decoration: none;
      transition: all var(--transition);
      font-family: var(--font-body);
    }
    .logout-btn:hover {
      background: var(--grey-100);
      color: var(--grey-700);
      border-color: var(--grey-500);
    }

    /* 頂部導覽列右側資訊 */
    .user-chip {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: rgba(255,255,255,0.15);
      border-radius: 20px;
      padding: 4px 12px;
      font-size: 0.8em;
      color: rgba(255,255,255,0.9);
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
      .topbar-nav .topbar-link,
      .topbar-nav .user-chip {
        display: block; width: 100%;
        text-align: left; padding: 10px 18px;
        border-radius: 0; background: transparent;
        box-sizing: border-box; white-space: nowrap;
      }
      .topbar-nav .topbar-link:hover { background: rgba(255,255,255,0.12); }
    }
  </style>
</head>
<body>

<div class="topbar">
  <div class="topbar-inner">
    <span class="topbar-title">🕐 薪資結算系統</span>
    <button class="topbar-burger" onclick="toggleNav(this)" aria-label="選單">
      <span></span><span></span><span></span>
    </button>
    <nav class="topbar-nav" id="topbar-nav">
      <span class="user-chip">
        <?php echo htmlspecialchars(displayName()); ?>
      </span>
      <a href="logout.php" class="topbar-link">登出</a>
    </nav>
  </div>
</div>

<div class="home-wrap footer-pad">

  <!-- 歡迎區塊 -->
  <div class="welcome-card">
    <div class="welcome-greeting">歡迎回來</div>
    <div class="welcome-name">
      <?php echo htmlspecialchars(displayName()); ?>
    </div>
    <div class="welcome-time" id="welcome-time">載入中...</div>
  </div>

  <!-- 主要功能 -->
  <div class="section-label">主要功能</div>
  <div class="menu-grid">

    <!-- 出勤查詢（全員可用） -->
    <a href="attendance.php" class="menu-card green">
      <div class="menu-icon">📊</div>
      <div class="menu-title">出勤查詢</div>
      <div class="menu-desc">查詢出勤紀錄、工時與薪資明細，支援匯出 Excel</div>
      <div class="menu-arrow">→</div>
    </a>

    <?php if ($isAdmin): ?>
    <!-- 打卡辨識（管理員限定） -->
    <a href="scan_upload.php" class="menu-card blue">
      <div class="menu-icon">📷</div>
      <div class="menu-title">打卡辨識</div>
      <div class="menu-desc">拍攝打卡卡片，AI 自動辨識工時並計算薪資</div>
      <div class="menu-arrow">→</div>
    </a>

    <!-- 員工管理（管理員限定） -->
    <a href="admin.php" class="menu-card purple">
      <div class="menu-icon">👥</div>
      <div class="menu-title">員工管理</div>
      <div class="menu-desc">新增、編輯員工資料與薪資設定</div>
      <div class="menu-arrow">→</div>
    </a>

    <!-- 帳號管理（管理員限定） -->
    <a href="account.php" class="menu-card amber" style="<?php echo $pendingRequestCount > 0 ? 'border-color:#F59E0B;border-width:2px' : ''; ?>">
      <div class="menu-icon">🔑</div>
      <div class="menu-title">帳號管理</div>
      <div class="menu-desc">管理登入帳號、重設密碼與權限設定</div>
      <?php if ($pendingRequestCount > 0): ?>
      <div style="background:#FEF3C7;border:1.5px solid #F59E0B;border-radius:8px;padding:8px 10px;display:flex;align-items:center;gap:8px;margin-top:2px">
        <span style="font-size:1.1em;flex-shrink:0">🔔</span>
        <div>
          <div style="font-size:0.82em;font-weight:800;color:#92400E;line-height:1.3">有 <?php echo $pendingRequestCount; ?> 筆帳號申請</div>
          <div style="font-size:0.76em;color:#B45309;font-weight:500">等待審核處理</div>
        </div>
        <span style="background:#DC2626;color:white;font-size:0.72em;font-weight:700;border-radius:20px;padding:2px 8px;white-space:nowrap;margin-left:auto"><?php echo $pendingRequestCount; ?> 筆</span>
      </div>
      <?php endif; ?>
      <div class="menu-arrow">→</div>
    </a>
    <?php else: ?>
    <!-- 員工：修改密碼 -->
    <a href="change_password.php" class="menu-card amber">
      <div class="menu-icon">🔐</div>
      <div class="menu-title">修改密碼</div>
      <div class="menu-desc">修改自己的登入密碼</div>
      <div class="menu-arrow">→</div>
    </a>
    <?php endif; ?>

  </div>

  <!-- 登出 -->
  <div class="logout-wrap">
    <a href="logout.php" class="logout-btn">🚪 登出系統</a>
  </div>

</div>

<script>
function toggleNav(btn) {
  const nav = document.getElementById('topbar-nav');
  nav.classList.toggle('open');
  btn.setAttribute('aria-expanded', nav.classList.contains('open'));
}

// 顯示目前時間
function updateTime() {
  const el = document.getElementById('welcome-time');
  if (!el) return;
  const now = new Date();
  const pad = n => String(n).padStart(2, '0');
  const weekdays = ['日','一','二','三','四','五','六'];
  el.textContent =
    now.getFullYear() + ' / ' +
    pad(now.getMonth()+1) + ' / ' +
    pad(now.getDate()) +
    '（' + weekdays[now.getDay()] + '）' +
    '　' + pad(now.getHours()) + ':' + pad(now.getMinutes());
}
updateTime();
setInterval(updateTime, 10000);
</script>
</body>
</html>