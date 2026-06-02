<?php
session_start();
include_once __DIR__ . '/auth.php';
requireLogin();
include_once __DIR__ . '/db.php';
$employees = getEmployees();
$first = $employees[0] ?? null;
$nextSide   = isset($_POST['next_side_scan']);
$prefillEmp = $_POST['prefill_employee']  ?? '';
$prefillYM  = $_POST['prefill_yearmonth'] ?? '';
$carryData  = $_POST['carry_side1']       ?? '';
$user    = currentUser();
$isAdmin = isAdmin();
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <title>打卡辨識 — 薪資結算系統</title>
  <link rel="stylesheet" href="responsive.css">
  <style>
    .upload-zone {
      border: 2px dashed #A5D6A7;
      border-radius: var(--radius-md);
      padding: 20px 16px;
      text-align: center;
      transition: all var(--transition);
      background: var(--green-50);
      margin-bottom: 4px;
    }
    .upload-zone.drag-over {
      border-color: var(--green-700);
      background: var(--green-100);
    }
    .upload-icon { font-size: 2em; display: block; margin-bottom: 6px; }
    .upload-text { font-size: 0.88em; color: var(--grey-500); font-weight: 500; margin-bottom: 12px; }
    .upload-text strong { color: var(--green-700); }
    .file-btn-wrap { display: flex; gap: 8px; justify-content: center; flex-wrap: wrap; }
    .file-label {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 10px 18px; border-radius: var(--radius-sm);
      font-size: 0.88em; font-weight: 700; cursor: pointer;
      transition: all var(--transition); min-height: 44px;
    }
    .file-label-cam { background: var(--green-700); color: white; }
    .file-label-cam:hover { background: var(--green-800); }
    .file-label-album { background: var(--green-50); color: var(--green-700); border: 1.5px solid #A5D6A7; }
    .file-label-album:hover { background: var(--green-100); }
    .file-input-hidden { display: none; }
    #preview, #preview2 {
      width: 100%; border-radius: var(--radius-sm);
      margin-top: 10px; display: none; border: 2px solid #A5D6A7;
    }
    #preview2 { border-color: #B39DDB; }
    .no-emp-box {
      background: var(--amber-100); border-left: 4px solid var(--amber-500);
      border-radius: var(--radius-sm); padding: 12px 14px;
      font-size: 0.88em; color: #E65100;
    }
    .no-emp-box a { color: #E65100; font-weight: 700; }
    .user-chip {
      display: inline-flex; align-items: center; gap: 6px;
      background: rgba(255,255,255,0.15); border-radius: 20px;
      padding: 4px 12px; font-size: 0.8em; color: rgba(255,255,255,0.9);
    }
    /* ── 漢堡選單：向下滑出 dropdown ── */
    .topbar { position: relative; }
    .topbar-nav {
      display: none;
      position: absolute; top: 100%; right: 0;
      min-width: 160px;
      background: var(--green-800, #2e7d32);
      border-radius: 0 0 10px 10px;
      box-shadow: 0 6px 20px rgba(0,0,0,0.25);
      flex-direction: column; padding: 6px 0; z-index: 200;
    }
    .topbar-nav.open { display: flex; }
    .topbar-nav .topbar-link,
    .topbar-nav .user-chip {
      display: block; width: 100%; text-align: left;
      padding: 10px 18px; border-radius: 0;
      background: transparent; box-sizing: border-box; white-space: nowrap;
    }
    .topbar-nav .topbar-link:hover { background: rgba(255,255,255,0.12); }
  </style>
</head>
<body>
  <div class="topbar">
    <div class="topbar-inner">
      <span class="topbar-title">📷 打卡辨識</span>
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

    <?php if (empty($employees)): ?>
      <div class="no-emp-box">
        ⚠️ 尚未設定員工資料，請先前往 <a href="admin.php">後台管理</a> 新增員工。
      </div>
    <?php else: ?>

      <!-- 員工選擇 -->
      <div class="card">
        <div class="card-title">👤 選擇員工</div>
        <div class="search-wrap">
          <span class="search-icon">🔍</span>
          <input type="text" id="emp-search" class="search-input"
            placeholder="輸入任意字元搜尋員工..."
            oninput="filterEmployees(this.value)">
        </div>
        <div class="form-group" style="margin-bottom:6px">
          <select id="emp-select" class="form-select" onchange="updateEmpMeta(this)">
            <?php foreach ($employees as $emp): ?>
              <?php
              $wageUnit = $emp['type'] === 'fulltime' ? '月薪' : '時薪';
              $wageDisp = number_format($emp['hourly_rate']);
              ?>
              <option value="<?php echo htmlspecialchars($emp['name']); ?>"
                data-type="<?php echo $emp['type']; ?>"
                data-rate="<?php echo $emp['hourly_rate']; ?>">
                <?php echo htmlspecialchars($emp['name']); ?>
                （<?php echo $emp['type'] === 'fulltime' ? '正職' : '時薪制'; ?> / <?php echo $wageUnit; ?> $<?php echo $wageDisp; ?>）
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php $fType = $first['type']; $fWage = number_format($first['hourly_rate']); ?>
        <div id="emp-meta" style="font-size:0.82em;color:var(--grey-500);display:flex;align-items:center;gap:6px;flex-wrap:wrap;padding:4px 2px">
          <span class="badge badge-<?php echo $fType; ?>"><?php echo $fType === 'fulltime' ? '正職' : '時薪制'; ?></span>
          <?php if ($fType === 'fulltime'): ?>
            月薪 $<?php echo $fWage; ?>/月 &nbsp;·&nbsp; 超過8h計加班費
          <?php else: ?>
            時薪 $<?php echo $fWage; ?>/h &nbsp;·&nbsp; 時薪×工時
          <?php endif; ?>
        </div>
      </div>

      <!-- 模式切換 -->
      <div class="mode-tabs">
        <button type="button" class="mode-tab active" onclick="switchMode('scan')">📋 整張卡片辨識</button>
        <button type="button" class="mode-tab" onclick="switchMode('single')">📷 單日辨識</button>
      </div>

      <!-- 整張卡片 -->
      <div class="mode-panel active" id="panel-scan">
        <div class="msg msg-info" style="font-size:0.82em">
          <strong>📋 整張卡片辨識（推薦）</strong><br>
          拍攝整張月份卡片，AI 解析所有日期與兩段工時，逐日確認後一次寫入 Excel。
        </div>
        <?php if ($nextSide && $carryData): ?>
          <div style="background:#EDE7F6;border-left:4px solid #7C4DFF;border-radius:8px;padding:12px 14px;margin-bottom:10px;font-size:0.85em;color:#4A148C;">
            <div style="font-weight:700;margin-bottom:8px">📷 繼續辨識第二面｜請拍攝卡片的另一面，辨識完成後將與第一面合併統計</div>
            <form method="post" action="scan.php" style="margin:0">
              <input type="hidden" name="employee_name" value="<?php echo htmlspecialchars($prefillEmp); ?>">
              <input type="hidden" name="carry_side1" value="<?php echo htmlspecialchars($carryData); ?>">
              <input type="hidden" name="prefill_yearmonth" value="<?php echo htmlspecialchars($prefillYM); ?>">
              <input type="hidden" name="skip_scan" value="1">
              <button type="submit" style="background:white;color:#4A148C;border:1.5px solid #7C4DFF;border-radius:6px;padding:8px 16px;font-size:0.85em;font-weight:700;cursor:pointer;width:100%">
                ✅ 不掃第二面，直接回到確認畫面
              </button>
            </form>
          </div>
        <?php endif; ?>
        <form action="scan.php" method="post" enctype="multipart/form-data">
          <input type="hidden" name="employee_name" id="scan-emp-name"
            value="<?php echo htmlspecialchars($first['name']); ?>">
          <?php if ($nextSide && $carryData): ?>
            <input type="hidden" name="carry_side1" value="<?php echo htmlspecialchars($carryData); ?>">
            <input type="hidden" name="prefill_yearmonth" value="<?php echo htmlspecialchars($prefillYM); ?>">
          <?php endif; ?>
          <div class="upload-zone" id="zone-scan">
            <span class="upload-icon">📸</span>
            <div class="upload-text">選擇或拍攝整張打卡卡片<br><strong>支援 JPG / PNG</strong></div>
            <div class="file-btn-wrap">
              <label class="file-label file-label-cam" for="file-scan-cam">📷 拍照</label>
              <label class="file-label file-label-album" for="file-scan-album">🖼️ 從相簿/檔案選取</label>
            </div>
            <input type="file" id="file-scan-cam" class="file-input-hidden" accept="image/*" capture="environment"
              onchange="syncFile(this,'file-scan-album','preview2','scan-file-status')">
            <input type="file" id="file-scan-album" class="file-input-hidden" name="card_image" accept="image/*"
              onchange="syncFile(this,'file-scan-cam','preview2','scan-file-status')" required>
          </div>
          <div id="scan-file-status" style="font-size:0.82em;color:var(--green-700);margin-top:6px;display:none;font-weight:600"></div>
          <img id="preview2" src="#" alt="預覽">
          <button type="submit" class="btn btn-purple btn-full" style="margin-top:12px">
            🔍 AI 解析整張卡片
          </button>
        </form>
      </div>

      <!-- 單日辨識 -->
      <div class="mode-panel" id="panel-single" style="display:none">
        <div class="msg msg-info" style="font-size:0.82em">
          <strong>📷 單日辨識</strong><br>
          拍攝當天那一列，辨識上下班時間，確認後即時寫入 Excel。
        </div>
        <form action="config.php" method="post" enctype="multipart/form-data">
          <input type="hidden" name="employee_name" id="single-emp-name"
            value="<?php echo htmlspecialchars($first['name']); ?>">
          <div class="upload-zone" id="zone-single">
            <span class="upload-icon">📷</span>
            <div class="upload-text">選擇或拍攝當日打卡照片<br><strong>支援 JPG / PNG</strong></div>
            <div class="file-btn-wrap">
              <label class="file-label file-label-cam" for="file-single-cam">📷 拍照</label>
              <label class="file-label file-label-album" for="file-single-album">🖼️ 從相簿/檔案選取</label>
            </div>
            <input type="file" id="file-single-cam" class="file-input-hidden" accept="image/*" capture="environment"
              onchange="syncFile(this,'file-single-album','preview','single-file-status')">
            <input type="file" id="file-single-album" class="file-input-hidden" name="card_image" accept="image/*"
              onchange="syncFile(this,'file-single-cam','preview','single-file-status')" required>
          </div>
          <div id="single-file-status" style="font-size:0.82em;color:var(--green-700);margin-top:6px;display:none;font-weight:600"></div>
          <img id="preview" src="#" alt="預覽">
          <button type="submit" class="btn btn-primary btn-full" style="margin-top:12px">
            📷 送出辨識
          </button>
        </form>
      </div>

    <?php endif; ?>
  </div>

  <script>
    function toggleNav(btn) {
      const nav = document.getElementById('topbar-nav');
      nav.classList.toggle('open');
      btn.setAttribute('aria-expanded', nav.classList.contains('open'));
    }

    function switchMode(mode) {
      document.querySelectorAll('.mode-tab').forEach((t, i) => {
        t.classList.toggle('active', (mode === 'scan' && i === 0) || (mode === 'single' && i === 1));
      });
      document.getElementById('panel-scan').style.display = mode === 'scan' ? '' : 'none';
      document.getElementById('panel-single').style.display = mode === 'single' ? '' : 'none';
    }

    function filterEmployees(kw) {
      const sel = document.getElementById('emp-select');
      const k = kw.trim().toLowerCase();
      let first = null;
      sel.querySelectorAll('option').forEach(opt => {
        const match = opt.textContent.toLowerCase().includes(k);
        opt.style.display = match ? '' : 'none';
        if (match && !first) first = opt;
      });
      if (first) { sel.value = first.value; updateEmpMeta(sel); }
    }

    function updateEmpMeta(sel) {
      const opt = sel.options[sel.selectedIndex];
      const name = opt.value, type = opt.dataset.type, rate = opt.dataset.rate;
      const badge = type === 'fulltime'
        ? "<span class='badge badge-fulltime'>正職</span>"
        : "<span class='badge badge-hourly'>時薪制</span>";
      const wageUnit   = type === 'fulltime' ? '月薪' : '時薪';
      const wageSuffix = type === 'fulltime' ? '/月' : '/h';
      const rule       = type === 'fulltime' ? '超過8h計加班費' : '時薪×工時';
      document.getElementById('emp-meta').innerHTML =
        badge + ' ' + wageUnit + ' $' + parseInt(rate).toLocaleString() + wageSuffix + ' &nbsp;·&nbsp; ' + rule;
      document.getElementById('scan-emp-name').value   = name;
      document.getElementById('single-emp-name').value = name;
    }

    function syncFile(srcInput, otherInputId, previewId, statusId) {
      const file = srcInput.files[0];
      if (!file) return;
      const other = document.getElementById(otherInputId);
      try { const dt = new DataTransfer(); dt.items.add(file); if (other) other.files = dt.files; } catch(e){}
      const img = document.getElementById(previewId);
      if (img) { img.src = URL.createObjectURL(file); img.style.display = 'block'; }
      const status = document.getElementById(statusId);
      if (status) { status.textContent = '✓ 已選取：' + file.name; status.style.display = 'block'; }
    }

    <?php if ($nextSide && $prefillEmp): ?>
    (function() {
      const sel = document.getElementById('emp-select');
      if (sel) {
        for (let i = 0; i < sel.options.length; i++) {
          if (sel.options[i].value === <?php echo json_encode($prefillEmp); ?>) {
            sel.selectedIndex = i; updateEmpMeta(sel); break;
          }
        }
      }
      switchMode('scan');
    })();
    <?php endif; ?>

    document.querySelectorAll('.upload-zone').forEach(zone => {
      zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('drag-over'); });
      zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
      zone.addEventListener('drop', e => {
        e.preventDefault(); zone.classList.remove('drag-over');
        const file = e.dataTransfer.files[0];
        if (!file) return;
        const albumInput = zone.querySelector('input[name="card_image"]');
        if (albumInput) {
          const dt = new DataTransfer(); dt.items.add(file);
          albumInput.files = dt.files;
          albumInput.dispatchEvent(new Event('change'));
        }
      });
    });
  </script>
</body>
</html>