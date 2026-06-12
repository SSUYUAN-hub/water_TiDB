<?php
session_start();
include_once __DIR__ . '/auth.php';
requireLogin();
include_once __DIR__ . '/db.php';
$employees = getEmployees();
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
            <option value="">— 請先選擇員工 —</option>
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
        <div id="emp-meta" style="font-size:0.82em;color:var(--grey-500);display:flex;align-items:center;gap:6px;flex-wrap:wrap;padding:4px 2px">
          <span style="color:var(--amber-500)">⚠️ 請先從上方選擇員工後再上傳照片</span>
        </div>
      </div>


      <!-- 整張卡片辨識 -->
      <div class="card" id="panel-scan">
        <div class="msg msg-info" style="font-size:0.82em">
          <strong>📋 整張卡片辨識（推薦）</strong><br>
          拍攝整張月份卡片，AI 解析所有日期與兩段工時，逐日確認後一次寫入資料庫。
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
          <input type="hidden" name="employee_name" id="scan-emp-name" value="">
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


    <?php endif; ?>
  </div>

  <script>
    function toggleNav(btn) {
      const nav = document.getElementById('topbar-nav');
      nav.classList.toggle('open');
      btn.setAttribute('aria-expanded', nav.classList.contains('open'));
    }

    function filterEmployees(kw) {
      const sel = document.getElementById('emp-select');
      const k = kw.trim().toLowerCase();
      // 只過濾顯示，不自動選取
      sel.querySelectorAll('option').forEach(opt => {
        opt.style.display = (!opt.value || opt.textContent.toLowerCase().includes(k)) ? '' : 'none';
      });
    }

    function updateEmpMeta(sel) {
      const opt  = sel.options[sel.selectedIndex];
      const name = opt.value;
      const meta = document.getElementById('emp-meta');

      // 未選取：顯示提示，清空 hidden input
      if (!name) {
        meta.innerHTML = "<span style='color:var(--amber-500)'>⚠️ 請先從上方選擇員工後再上傳照片</span>";
        document.getElementById('scan-emp-name').value = '';
        setUploadEnabled(false);
        return;
      }

      const type = opt.dataset.type, rate = opt.dataset.rate;
      const badge      = type === 'fulltime'
        ? "<span class='badge badge-fulltime'>正職</span>"
        : "<span class='badge badge-hourly'>時薪制</span>";
      const wageUnit   = type === 'fulltime' ? '月薪' : '時薪';
      const wageSuffix = type === 'fulltime' ? '/月' : '/h';
      const rule       = type === 'fulltime' ? '超過8h計加班費' : '時薪×工時';
      meta.innerHTML =
        badge + ' ' + wageUnit + ' $' + parseInt(rate).toLocaleString() + wageSuffix + ' &nbsp;·&nbsp; ' + rule;
      document.getElementById('scan-emp-name').value = name;
      setUploadEnabled(true);
      hideEmpAlert();
    }

    // 上傳區塊視覺鎖定（員工未選時淡化）
    function setUploadEnabled(enabled) {
      const z = document.getElementById('zone-scan');
      if (!z) return;
      z.style.opacity      = enabled ? '' : '0.45';
      z.style.pointerEvents = enabled ? '' : 'none';
    }

    // 顯示 / 隱藏行內提示訊息
    function showEmpAlert(formId) {
      let el = document.getElementById('emp-alert-' + formId);
      if (!el) {
        el = document.createElement('div');
        el.id = 'emp-alert-' + formId;
        el.style.cssText =
          'background:#FFF3E0;border-left:4px solid #FF9800;border-radius:6px;' +
          'padding:10px 14px;font-size:0.88em;color:#E65100;font-weight:600;margin-bottom:8px';
        el.textContent = '⚠️ 請先選擇員工，再上傳照片';
        const panel = document.getElementById('panel-' + formId);
        if (panel) panel.insertBefore(el, panel.querySelector('form'));
      }
      el.style.display = '';
      // 捲動到提示
      document.getElementById('emp-select').scrollIntoView({ behavior: 'smooth', block: 'center' });
      document.getElementById('emp-select').focus();
    }
    function hideEmpAlert() {
      const el = document.getElementById('emp-alert-scan');
      if (el) el.style.display = 'none';
    }

    function syncFile(srcInput, otherInputId, previewId, statusId) {
      const file = srcInput.files[0];
      if (!file) return;
      // 員工未選取：阻擋並提示
      if (!document.getElementById('scan-emp-name').value) {
        showEmpAlert('scan');
        srcInput.value = '';
        return;
      }
      const other = document.getElementById(otherInputId);
      try { const dt = new DataTransfer(); dt.items.add(file); if (other) other.files = dt.files; } catch(e){}
      const img = document.getElementById(previewId);
      if (img) { img.src = URL.createObjectURL(file); img.style.display = 'block'; }
      const status = document.getElementById(statusId);
      if (status) { status.textContent = '✓ 已選取：' + file.name; status.style.display = 'block'; }
    }

    // form submit 攔截（防止直接按按鈕送出）
    document.addEventListener('DOMContentLoaded', function() {
      // 初始鎖定上傳區
      setUploadEnabled(false);

      // 整張卡片 form
      const scanForm = document.querySelector('#panel-scan form[action="scan.php"]');
      if (scanForm) {
        scanForm.addEventListener('submit', function(e) {
          if (!document.getElementById('scan-emp-name').value) {
            e.preventDefault();
            showEmpAlert('scan');
          }
        });
      }
    });

    <?php if ($nextSide && $prefillEmp): ?>
    document.addEventListener('DOMContentLoaded', function() {
      const sel = document.getElementById('emp-select');
      if (sel) {
        for (let i = 0; i < sel.options.length; i++) {
          if (sel.options[i].value === <?php echo json_encode($prefillEmp); ?>) {
            sel.selectedIndex = i; updateEmpMeta(sel); break;
          }
        }
      }
    });
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