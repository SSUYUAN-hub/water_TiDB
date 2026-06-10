<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/vendor/autoload.php';
include_once __DIR__ . '/auth.php';
requireAdmin();

use PhpOffice\PhpSpreadsheet\IOFactory;

$message  = '';
$msgType  = '';
$preview  = [];
$imported = 0;
$skipped  = 0;
$errors   = [];
$action   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── 預覽：解析 Excel ──────────────────────────────────
    if ($action === 'preview' && isset($_FILES['emp_file'])) {
        $file = $_FILES['emp_file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $message = '上傳失敗，請重試'; $msgType = 'error';
        } else {
            try {
                $spreadsheet = IOFactory::load($file['tmp_name']);
                $sheet       = $spreadsheet->getActiveSheet();
                $rows        = $sheet->toArray(null, true, true, false);

                // 跳過標題列（第1列）和說明列（第2列），從第3列開始
                // 同時跳過範例資料（第3~4列，若姓名是「林小明」或「陳美玲」）
                $exampleNames = ['林小明', '陳美玲'];

                foreach (array_slice($rows, 2) as $row) {
                    $name  = trim($row[0] ?? '');
                    $type  = trim($row[1] ?? '');
                    $wage  = trim($row[2] ?? '');
                    $night = (int)($row[3] ?? 0);

                    if (empty($name)) continue;
                    if (in_array($name, $exampleNames)) continue;

                    // 驗證
                    $rowErrors = [];
                    if (!in_array($type, ['hourly', 'fulltime'])) {
                        $rowErrors[] = '身分別必須是 hourly 或 fulltime';
                    }
                    if (!is_numeric($wage) || (int)$wage <= 0) {
                        $rowErrors[] = '薪資必須是正整數';
                    }

                    $preview[] = [
                        'name'            => $name,
                        'type'            => $type,
                        'hourly_rate'     => (int)$wage,
                        'night_allowance' => $night,
                        'errors'          => $rowErrors,
                    ];
                }

                if (empty($preview)) {
                    $message = '找不到有效的員工資料，請確認從第 5 列開始填寫，且已刪除範例資料';
                    $msgType = 'error';
                } else {
                    // 把預覽資料存入 session 供下一步確認匯入
                    if (session_status() === PHP_SESSION_NONE) session_start();
                    $_SESSION['import_preview'] = $preview;
                    $message = '已解析 ' . count($preview) . ' 筆資料，請確認後按「確認匯入」';
                    $msgType = 'info';
                }
            } catch (Exception $e) {
                $message = '檔案解析失敗：' . $e->getMessage();
                $msgType = 'error';
            }
        }

    // ── 確認匯入：寫入資料庫 ─────────────────────────────
    } elseif ($action === 'import') {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $toImport = $_SESSION['import_preview'] ?? [];

        if (empty($toImport)) {
            $message = '找不到待匯入資料，請重新上傳';
            $msgType = 'error';
        } else {
            foreach ($toImport as $emp) {
                if (!empty($emp['errors'])) { $skipped++; continue; }
                $result = addEmployee([
                    'name'            => $emp['name'],
                    'type'            => $emp['type'],
                    'hourly_rate'     => $emp['hourly_rate'],
                    'night_allowance' => $emp['night_allowance'],
                ]);
                if ($result) {
                    $imported++;
                } else {
                    $skipped++;
                    $errors[] = "「{$emp['name']}」已存在，略過";
                }
            }
            unset($_SESSION['import_preview']);
            $preview = []; // 清空預覽
            $message = "✅ 匯入完成：成功 {$imported} 筆，略過 {$skipped} 筆";
            $msgType = 'success';
        }
    }
}

// 讀取 session 中的預覽（頁面重新整理後保留）
if (empty($preview) && $action !== 'import') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $preview = $_SESSION['import_preview'] ?? [];
}

$curUser = currentUser();
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>批量匯入員工 — 薪資結算系統</title>
<link rel="stylesheet" href="responsive.css">
<style>
.main-wrap { max-width: 900px; }

.upload-zone {
display: flex;

    flex-direction: column;
    justify-content: center;
    align-items: center;

    width: 100%;
    min-height: 180px;

    border: 2px dashed #A5D6A7;
    border-radius: var(--radius-md);

    padding: 28px 20px;

    text-align: center;
    background: var(--green-50);

    cursor: pointer;
    transition: all var(--transition);
}
#upload-form{
    width:100%;
}
.upload-zone:hover { border-color: var(--green-700); background: var(--green-100); }
.upload-zone input[type="file"] { display: none; }

.preview-table { width: 100%; border-collapse: collapse; font-size: 0.88em; }
.preview-table th {
  background: var(--green-50); color: var(--green-700);
  padding: 9px 12px; text-align: center;
  border: 1px solid #C8E6C9; font-weight: 600;
}
.preview-table td {
  padding: 8px 12px; text-align: center;
  border: 1px solid #eee; vertical-align: middle;
}
.preview-table tr:nth-child(even) td { background: #FAFAFA; }
.preview-table tr.has-error td { background: #FFF3E0; }
.error-tag {
  display: inline-block; background: #FFEBEE;
  color: var(--red-600); font-size: 0.78em;
  padding: 2px 7px; border-radius: 8px; margin: 2px;
}
.step-bar {
  display: flex; gap: 0; margin-bottom: 20px;
  border-radius: var(--radius-md); overflow: hidden;
  box-shadow: var(--card-shadow);
}
.step {
  flex: 1; padding: 12px; text-align: center;
  font-size: 0.83em; font-weight: 600;
  background: var(--grey-100); color: var(--grey-500);
}
.step.active { background: var(--green-700); color: white; }
.step.done   { background: var(--green-100); color: var(--green-700); }
</style>
</head>
<body>

<div class="topbar">
  <div class="topbar-inner">
    <span class="topbar-title">📥 批量匯入員工</span>
    <button class="topbar-burger" onclick="toggleNav(this)" aria-label="選單">
      <span></span><span></span><span></span>
    </button>
    <nav class="topbar-nav" id="topbar-nav">
      <span class="topbar-link" style="background:rgba(255,255,255,0.1);cursor:default">
        👑 <?php echo htmlspecialchars($curUser['username'] ?? ''); ?>
      </span>
      <a href="admin.php" class="topbar-link">👥 員工管理</a>
      <a href="index.php" class="topbar-link">🏠 首頁</a>
      <a href="logout.php" class="topbar-link">登出</a>
    </nav>
  </div>
</div>

<div class="main-wrap footer-pad" style="margin-top:14px">

  <!-- 步驟列 -->
  <div class="step-bar">
    <div class="step <?php echo empty($preview) ? 'active' : 'done'; ?>">① 下載範本</div>
    <div class="step <?php echo !empty($preview) && $msgType !== 'success' ? 'active' : (empty($preview) ? '' : 'done'); ?>">② 上傳 Excel</div>
    <div class="step <?php echo $msgType === 'success' ? 'active' : ''; ?>">③ 確認匯入</div>
  </div>

  <?php if (!empty($message)): ?>
  <div class="msg msg-<?php echo $msgType === 'success' ? 'success' : ($msgType === 'error' ? 'error' : 'info'); ?>"
       style="margin-bottom:14px">
    <?php echo htmlspecialchars($message); ?>
    <?php if (!empty($errors)): ?>
    <ul style="margin:6px 0 0 16px;font-size:0.9em">
      <?php foreach ($errors as $e): ?>
      <li><?php echo htmlspecialchars($e); ?></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- 步驟一：下載範本 -->
  <div class="card">
    <div class="card-title">① 下載填寫範本</div>
    <p style="font-size:0.88em;color:var(--grey-500);margin-bottom:14px">
      先下載 Excel 範本，填寫完畢後再上傳。範本內有詳細說明，從第 5 列開始填寫員工資料。
    </p>
    <a href="員工資料匯入範本.xlsx" download class="btn btn-blue" style="display:inline-flex;align-items:center;gap:6px">
      ⬇ 下載 Excel 範本
    </a>
  </div>

  <!-- 步驟二：上傳 Excel -->
  <div class="card">
    <div class="card-title">② 上傳填妥的 Excel</div>
    <form method="post" enctype="multipart/form-data" id="upload-form">
      <input type="hidden" name="action" value="preview">
      <label class="upload-zone" for="emp-file-input">
        <div style="font-size:2em;margin-bottom:8px">📂</div>
        <div style="font-weight:700;color:var(--green-700);margin-bottom:4px">點擊選取 Excel 檔案</div>
        <div style="font-size:0.82em;color:var(--grey-500)">支援 .xlsx 格式</div>
        <input type="file" id="emp-file-input" name="emp_file" accept=".xlsx,.xls"
               onchange="document.getElementById('file-status').textContent='已選取：'+this.files[0].name;
                         document.getElementById('upload-form').submit();">
      </label>
      <div id="file-status" style="font-size:0.82em;color:var(--green-700);margin-top:8px;font-weight:600"></div>
    </form>
  </div>

  <!-- 步驟三：預覽與確認 -->
  <?php if (!empty($preview)): ?>
  <?php
    $validCount   = count(array_filter($preview, fn($r) => empty($r['errors'])));
    $invalidCount = count($preview) - $validCount;
  ?>
  <div class="card">
    <div class="card-title">③ 確認資料後匯入</div>
    <p style="font-size:0.88em;color:var(--grey-500);margin-bottom:12px">
      共解析 <strong><?php echo count($preview); ?></strong> 筆，
      可匯入 <strong style="color:var(--green-700)"><?php echo $validCount; ?></strong> 筆，
      <?php if ($invalidCount > 0): ?>
      有誤 <strong style="color:var(--red-600)"><?php echo $invalidCount; ?></strong> 筆（標橘色列）
      <?php endif; ?>
    </p>

    <div style="overflow-x:auto;margin-bottom:16px">
    <table class="preview-table">
      <thead>
        <tr>
          <th>#</th>
          <th>姓名</th>
          <th>身分別</th>
          <th>薪資</th>
          <th>夜班津貼</th>
          <th>狀態</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($preview as $i => $row): ?>
      <tr class="<?php echo !empty($row['errors']) ? 'has-error' : ''; ?>">
        <td style="color:var(--grey-400)"><?php echo $i + 1; ?></td>
        <td style="font-weight:700"><?php echo htmlspecialchars($row['name']); ?></td>
        <td>
          <span class="badge badge-<?php echo $row['type']; ?>">
            <?php echo $row['type'] === 'fulltime' ? '正職' : '時薪制'; ?>
          </span>
        </td>
        <td>
          $<?php echo number_format($row['hourly_rate']); ?>
          <span style="font-size:0.78em;color:var(--grey-400)">
            <?php echo $row['type'] === 'fulltime' ? '/月' : '/h'; ?>
          </span>
        </td>
        <td>
          <?php if ($row['night_allowance'] > 0): ?>
            <span style="color:var(--purple-600);font-weight:600">$<?php echo $row['night_allowance']; ?></span>
          <?php else: ?>
            <span style="color:var(--grey-300)">—</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if (empty($row['errors'])): ?>
            <span style="color:var(--green-700)">✅ 可匯入</span>
          <?php else: ?>
            <?php foreach ($row['errors'] as $err): ?>
              <span class="error-tag"><?php echo htmlspecialchars($err); ?></span>
            <?php endforeach; ?>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <?php if ($validCount > 0): ?>
    <form method="post">
      <input type="hidden" name="action" value="import">
      <div class="btn-row">
        <button type="submit" class="btn btn-primary"
                onclick="return confirm('確認匯入 <?php echo $validCount; ?> 筆員工資料？')">
          ✅ 確認匯入 <?php echo $validCount; ?> 筆
        </button>
        <a href="import_employees.php" class="btn btn-secondary">重新上傳</a>
      </div>
    </form>
    <?php else: ?>
    <div class="msg msg-error">所有資料都有錯誤，請修正後重新上傳</div>
    <a href="import_employees.php" class="btn btn-secondary" style="margin-top:8px;display:inline-flex">重新上傳</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($msgType === 'success'): ?>
  <div class="btn-row" style="margin-top:4px">
    <a href="admin.php" class="btn btn-primary">👥 前往員工管理查看</a>
    <a href="import_employees.php" class="btn btn-secondary">繼續匯入</a>
  </div>
  <?php endif; ?>

</div>

<script>
function toggleNav(btn) {
  const nav = document.getElementById('topbar-nav');
  nav.classList.toggle('open');
  btn.setAttribute('aria-expanded', nav.classList.contains('open'));
}
</script>
</body>
</html>
