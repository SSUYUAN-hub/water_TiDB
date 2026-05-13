<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/vendor/autoload.php';
include_once __DIR__ . '/auth.php';
requireAdmin(); // 非管理員無法進入

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

$message = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name       = trim($_POST['name'] ?? '');
        $type       = $_POST['type'] ?? 'hourly';
        $wage       = (int)($_POST['wage'] ?? 180);
        $nightAllow = (int)($_POST['night_allowance'] ?? 0);
        if (empty($name)) {
            $message = '請輸入員工姓名'; $msgType = 'error';
        } elseif (addEmployee(['name'=>$name,'type'=>$type,'hourly_rate'=>$wage,'night_allowance'=>$nightAllow])) {
            $message = "✅ 員工「{$name}」已新增"; $msgType = 'success';
        } else {
            $message = "⚠️ 員工「{$name}」已存在"; $msgType = 'error';
        }

    } elseif ($action === 'update') {
        $name       = $_POST['name'] ?? '';
        $type       = $_POST['type'] ?? 'hourly';
        $wage       = (int)($_POST['wage'] ?? 180);
        $nightAllow = (int)($_POST['night_allowance'] ?? 0);
        if (updateEmployee($name, ['type'=>$type,'hourly_rate'=>$wage,'night_allowance'=>$nightAllow])) {
            $message = "✅ 員工「{$name}」已更新"; $msgType = 'success';
        } else {
            $message = '找不到該員工'; $msgType = 'error';
        }

    } elseif ($action === 'delete') {
        $name = $_POST['name'] ?? '';
        if (deleteEmployee($name)) {
            $message = "🗑️ 員工「{$name}」已刪除"; $msgType = 'success';
        } else {
            $message = '找不到該員工'; $msgType = 'error';
        }

    } elseif ($action === 'add_user') {
        $uname    = trim($_POST['u_username']     ?? '');
        $upass    = $_POST['u_password']           ?? '';
        $urole    = $_POST['u_role']               ?? 'staff';
        $uempname = trim($_POST['u_employee_name'] ?? '');

        if (empty($uname) || strlen($upass) < 6) {
            $message = '帳號不能空白，且密碼至少 6 個字元'; $msgType = 'error';
        } else {
            try {
                $stmt = getDB()->prepare(
                    'INSERT INTO users (username, password_hash, role, employee_name)
                     VALUES (:username, :hash, :role, :emp)'
                );
                $stmt->execute([
                    ':username' => $uname,
                    ':hash'     => $upass,
                    ':role'     => $urole,
                    ':emp'      => ($urole === 'staff' && $uempname !== '') ? $uempname : null,
                ]);
                $message = "✅ 帳號「{$uname}」已建立"; $msgType = 'success';
            } catch (PDOException $e) {
                $message = ($e->getCode() === '23000') ? "帳號「{$uname}」已存在" : '建立失敗：'.$e->getMessage();
                $msgType = 'error';
            }
        }

    } elseif ($action === 'reset_password') {
        $uid   = (int)($_POST['u_id']       ?? 0);
        $upass = $_POST['u_new_password']    ?? '';

        if (strlen($upass) < 6) {
            $message = '新密碼至少需要 6 個字元'; $msgType = 'error';
        } else {
            $stmt = getDB()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $stmt->execute([$upass, $uid]);
            $message = $stmt->rowCount() > 0 ? '✅ 密碼已重設' : '找不到該帳號';
            $msgType = $stmt->rowCount() > 0 ? 'success' : 'error';
        }

    } elseif ($action === 'delete_user') {
        $uid      = (int)($_POST['u_id']       ?? 0);
        $uname    = $_POST['u_username']        ?? '';
        $curUser  = currentUser();

        if ($uid === (int)($curUser['id'] ?? 0)) {
            $message = '不能刪除自己的帳號'; $msgType = 'error';
        } else {
            $stmt = getDB()->prepare('DELETE FROM users WHERE id = ?');
            $stmt->execute([$uid]);
            $message = $stmt->rowCount() > 0 ? "🗑️ 帳號「{$uname}」已刪除" : '找不到該帳號';
            $msgType = $stmt->rowCount() > 0 ? 'success' : 'error';
        }

    } elseif ($action === 'export_employees') {
        // ── 匯出員工資料統計表 ──────────────────────────
        $employees = getEmployees();
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('員工資料');

        // 標題列
        $headers = ['姓名', '身分別', '薪資', '薪資單位', '夜班津貼(元/次)', '加入日期'];
        foreach ($headers as $ci => $h) {
            $col = chr(65 + $ci);
            $sheet->setCellValue($col . '1', $h);
        }
        $sheet->getStyle('A1:F1')->applyFromArray([
            'font'      => ['bold'=>true, 'color'=>['rgb'=>'FFFFFF'], 'size'=>11],
            'fill'      => ['fillType'=>Fill::FILL_SOLID, 'startColor'=>['rgb'=>'1B5E20']],
            'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER, 'vertical'=>Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders'=>['borderStyle'=>Border::BORDER_THIN, 'color'=>['rgb'=>'FFFFFF']]],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(22);

        // 資料列
        foreach ($employees as $ri => $emp) {
            $row        = $ri + 2;
            $isFulltime = $emp['type'] === 'fulltime';
            $nightAllow = (int)($emp['night_allowance'] ?? 0);
            $bg         = $row % 2 === 0 ? 'F1F8E9' : 'FFFFFF';

            $sheet->setCellValue('A' . $row, $emp['name']);
            $sheet->setCellValue('B' . $row, $isFulltime ? '正職' : '時薪制');
            $sheet->setCellValue('C' . $row, $emp['hourly_rate']);
            $sheet->setCellValue('D' . $row, $isFulltime ? '元/月' : '元/時');
            $sheet->setCellValue('E' . $row, $nightAllow > 0 ? $nightAllow : '—');
            $sheet->setCellValue('F' . $row, $emp['created_at'] ?? '');

            $sheet->getStyle('A'.$row.':F'.$row)->applyFromArray([
                'fill'      => ['fillType'=>Fill::FILL_SOLID, 'startColor'=>['rgb'=>$bg]],
                'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER],
                'borders'   => ['allBorders'=>['borderStyle'=>Border::BORDER_THIN, 'color'=>['rgb'=>'CCCCCC']]],
                'font'      => ['size'=>10],
            ]);
            // 正職薪資欄藍色
            if ($isFulltime) {
                $sheet->getStyle('C'.$row)->applyFromArray(['font'=>['color'=>['rgb'=>'1565C0'],'bold'=>true]]);
            }
            // 夜班津貼欄紫色
            if ($nightAllow > 0) {
                $sheet->getStyle('E'.$row)->applyFromArray(['font'=>['color'=>['rgb'=>'7C4DFF'],'bold'=>true]]);
            }
        }

        // 自動欄寬
        foreach (range('A','F') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // 若有出勤紀錄檔案，也加入第二個 sheet 做摘要
        $attendanceFile = __DIR__ . '/飲料店_出勤紀錄.xlsx';
        if (file_exists($attendanceFile)) {
            try {
                $attendSheet = $spreadsheet->createSheet();
                $attendSheet->setTitle('出勤摘要');

                $srcBook  = IOFactory::load($attendanceFile);
                $summary  = [];

                foreach ($srcBook->getSheetNames() as $sheetName) {
                    $src     = $srcBook->getSheetByName($sheetName);
                    $maxRow  = $src->getHighestRow();
                    $maxCol  = $src->getHighestColumn();
                    $lastRow = null;

                    // 尋找小計列（含「小計」字樣）
                    for ($r = $maxRow; $r >= 2; $r--) {
                        $cellA = $src->getCell('A'.$r)->getValue();
                        if (strpos((string)$cellA, '小計') !== false) {
                            $lastRow = $r; break;
                        }
                    }

                    if ($lastRow) {
                        // 讀最後一欄（薪資合計）
                        $salary = $src->getCell($maxCol . $lastRow)->getValue();
                        $summary[] = ['name' => $sheetName, 'salary' => $salary];
                    }
                }

                // 寫摘要
                $attendSheet->setCellValue('A1', '員工姓名');
                $attendSheet->setCellValue('B1', '薪資合計($)');
                $attendSheet->getStyle('A1:B1')->applyFromArray([
                    'font'      => ['bold'=>true, 'color'=>['rgb'=>'FFFFFF'], 'size'=>11],
                    'fill'      => ['fillType'=>Fill::FILL_SOLID, 'startColor'=>['rgb'=>'1B5E20']],
                    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER],
                    'borders'   => ['allBorders'=>['borderStyle'=>Border::BORDER_THIN, 'color'=>['rgb'=>'FFFFFF']]],
                ]);

                $total = 0;
                foreach ($summary as $si => $s) {
                    $r   = $si + 2;
                    $bg  = $r % 2 === 0 ? 'F1F8E9' : 'FFFFFF';
                    $attendSheet->setCellValue('A'.$r, $s['name']);
                    $attendSheet->setCellValue('B'.$r, $s['salary']);
                    $attendSheet->getStyle('A'.$r.':B'.$r)->applyFromArray([
                        'fill'      => ['fillType'=>Fill::FILL_SOLID, 'startColor'=>['rgb'=>$bg]],
                        'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER],
                        'borders'   => ['allBorders'=>['borderStyle'=>Border::BORDER_THIN, 'color'=>['rgb'=>'CCCCCC']]],
                    ]);
                    $attendSheet->getStyle('B'.$r)->applyFromArray(['font'=>['bold'=>true,'color'=>['rgb'=>'C62828']]]);
                    $total += (int)$s['salary'];
                }

                // 合計列
                $totalRow = count($summary) + 2;
                $attendSheet->setCellValue('A'.$totalRow, '合計');
                $attendSheet->setCellValue('B'.$totalRow, $total);
                $attendSheet->getStyle('A'.$totalRow.':B'.$totalRow)->applyFromArray([
                    'font'      => ['bold'=>true, 'color'=>['rgb'=>'FFFFFF'], 'size'=>11],
                    'fill'      => ['fillType'=>Fill::FILL_SOLID, 'startColor'=>['rgb'=>'2E7D32']],
                    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER],
                    'borders'   => ['allBorders'=>['borderStyle'=>Border::BORDER_THIN, 'color'=>['rgb'=>'FFFFFF']]],
                ]);
                $attendSheet->getStyle('B'.$totalRow)->applyFromArray(['font'=>['bold'=>true,'color'=>['rgb'=>'FFEB3B'],'size'=>12]]);

                foreach (['A','B'] as $col) {
                    $attendSheet->getColumnDimension($col)->setAutoSize(true);
                }
            } catch (Exception $e) {
                // 出勤紀錄讀取失敗不影響員工資料匯出
            }
        }

        $exportName = '員工資料統計_' . date('Ymd') . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $exportName . '"');
        header('Cache-Control: max-age=0');
        (new Xlsx($spreadsheet))->save('php://output');
        exit;
    }
}

$employees = getEmployees();
$allUsers  = getDB()->query(
    'SELECT id, username, role, employee_name, created_at FROM users ORDER BY id'
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>後台管理 — 員工資料</title>
<link rel="stylesheet" href="responsive.css">
<style>
.main-wrap { max-width: 1000px; }

/* 新增員工/帳號表單 */
.form-row {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
  gap: 10px;
  align-items: flex-end;
}
.fg { display: flex; flex-direction: column; gap: 4px; }
.fg label { font-size: 0.78em; color: var(--grey-500); white-space: nowrap; font-weight: 600; }
.fg input, .fg select {
  padding: 9px 11px; border: 1.5px solid var(--grey-300);
  border-radius: var(--radius-sm); font-size: 0.9em;
  color: var(--grey-900); font-family: var(--font-body);
  width: 100%;
}
.fg input:focus, .fg select:focus { outline: none; border-color: var(--green-600); }
.fg-name input { width: 100%; }
.fg-wage input  { width: 100%; }
.fg-night input { width: 100%; }
.btn-add    { background: var(--green-700); color: white; min-height: 40px; }
.btn-save   { background: var(--blue-700); color: white; }
.btn-export { background: var(--green-50); color: var(--green-700); border: 1.5px solid #A5D6A7; }
.btn-export:hover { background: var(--green-100); }
.card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; gap: 10px; }
.card-header h2 { font-size: 0.95em; color: var(--grey-700); margin: 0; }
.empty-state { text-align: center; padding: 28px; color: var(--grey-500); font-size: 0.9em; }
.night-val { color: var(--purple-600); font-weight: 700; }

/* ── 帳號管理 ── */
.user-table { width:100%; border-collapse:collapse; font-size:0.88em; }
.user-table th { background:var(--green-50); color:var(--green-700); padding:9px 12px; text-align:left; border:1px solid #C8E6C9; font-weight:600; white-space:nowrap; }
.user-table td { padding:8px 12px; border:1px solid #eee; vertical-align:middle; }
.user-table tr:nth-child(even) td { background:#FAFAFA; }
.reset-form { display:flex; gap:6px; align-items:center; flex-wrap:wrap; }
.reset-form input[type="password"] { padding:6px 9px; border:1.5px solid var(--grey-300); border-radius:6px; font-size:0.85em; font-family:var(--font-body); width:130px; }
.reset-form input:focus { outline:none; border-color:var(--green-600); }

/* ── 員工卡片列表（桌機橫幅 / 手機直列）── */
.emp-grid {
  display: grid;
  gap: 12px;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
}

.emp-card {
  border: 1.5px solid #E0E0E0;
  border-radius: var(--radius-md);
  padding: 16px;
  background: var(--white);
  transition: box-shadow var(--transition);
}
.emp-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.1); }
.emp-card-header {
  display: flex; justify-content: space-between;
  align-items: flex-start; margin-bottom: 12px;
}
.emp-card-name { font-size: 1em; font-weight: 700; color: var(--grey-900); }
.emp-card-info { display: flex; flex-direction: column; gap: 5px; margin-bottom: 12px; }
.emp-info-row { display: flex; justify-content: space-between; align-items: center; font-size: 0.83em; }
.emp-info-label { color: var(--grey-500); }
.emp-info-val   { font-weight: 600; color: var(--grey-900); }
.emp-card-divider { border: none; border-top: 1px dashed #eee; margin: 10px 0; }

/* 編輯表單（卡片內） */
.edit-form { display: flex; flex-direction: column; gap: 8px; }
.edit-row  { display: flex; gap: 6px; align-items: center; }
.edit-row label { font-size: 0.75em; color: var(--grey-500); white-space: nowrap; min-width: 46px; }
.edit-row select, .edit-row input[type="number"] {
  flex: 1; padding: 7px 9px; border: 1.5px solid var(--grey-300);
  border-radius: 6px; font-size: 0.85em; font-family: var(--font-body);
  color: var(--grey-900);
}
.edit-row select:focus, .edit-row input:focus { outline: none; border-color: var(--green-600); }
.edit-btns { display: flex; gap: 7px; margin-top: 4px; }
.edit-btns .btn { flex: 1; }

@media (max-width: 480px) {
  .emp-grid { grid-template-columns: 1fr; }
  .form-row { grid-template-columns: 1fr 1fr; }
  .fg input, .fg select { width: 100%; }
}
@media (min-width: 600px) {
  .emp-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (min-width: 900px) {
  .emp-grid { grid-template-columns: repeat(3, 1fr); }
}
</style>
</head>
<body>
<div class="topbar">
  <div class="topbar-inner">
    <span class="topbar-title">⚙️ 員工資料管理</span>
    <button class="topbar-burger" onclick="toggleNav(this)" aria-label="選單">
      <span></span><span></span><span></span>
    </button>
    <nav class="topbar-nav" id="topbar-nav">
      <span class="topbar-link" style="background:rgba(255,255,255,0.1);cursor:default">
        👑 <?php echo htmlspecialchars(currentUser()['username'] ?? ''); ?>
      </span>
      <a href="attendance.php" class="topbar-link">📊 出勤查詢</a>
      <a href="index.php"      class="topbar-link">🏠 打卡</a>
      <a href="logout.php"     class="topbar-link">登出</a>
    </nav>
  </div>
</div>
<div class="main-wrap footer-pad">

<?php if (!empty($message)): ?>
<div class="card" style="padding:0">
    <div class="msg msg-<?php echo $msgType==="success"?"success":"error"; ?>" style="margin:0;border-radius:12px">
        <?php echo htmlspecialchars($message); ?>
    </div>
</div>
<?php endif; ?>

<!-- 新增員工 -->
<div class="card">
    <div class="card-title">➕ 新增員工</div>
    <form method="post" id="addForm">
        <input type="hidden" name="action" value="add">
        <div class="form-row">
            <div class="fg fg-name">
                <label>姓名</label>
                <input type="text" name="name" placeholder="例：小明" required>
            </div>
            <div class="fg">
                <label>身分別</label>
                <select name="type" id="add-type" onchange="toggleWageLabel('add')">
                    <option value="hourly">時薪制</option>
                    <option value="fulltime">正職</option>
                </select>
            </div>
            <div class="fg fg-wage">
                <label id="add-wage-label">時薪（元）</label>
                <input type="number" name="wage" id="add-wage" value="180" min="1" max="999999">
            </div>
            <div class="fg fg-night">
                <label>🌙 夜班津貼（元）</label>
                <input type="number" name="night_allowance" value="0" min="0" max="9999" placeholder="0=不啟用">
            </div>
            <div class="fg">
                <label>&nbsp;</label>
                <button type="submit" class="btn btn-add">＋ 新增</button>
            </div>
        </div>
    </form>
</div>

<!-- 員工列表 -->
<div class="card">
    <div class="card-header">
        <h2>員工列表（共 <?php echo count($employees); ?> 人）</h2>
        <form method="post" style="margin:0">
            <input type="hidden" name="action" value="export_employees">
            <button type="submit" class="btn btn-export">
                📊 匯出統計表 Excel
            </button>
        </form>
    </div>
    <!-- 搜尋框 -->
    <div class="search-wrap" style="margin-bottom:14px">
        <span class="search-icon">🔍</span>
        <input type="text" id="emp-search-admin" class="search-input"
               placeholder="輸入任意字元搜尋員工..."
               oninput="filterAdminEmployees(this.value)">
    </div>
    <div id="no-result-msg" style="display:none;text-align:center;padding:20px;color:var(--grey-500);font-size:0.9em">
        找不到符合的員工
    </div>

    <?php if (empty($employees)): ?>
        <div class="empty-state">尚未新增任何員工</div>
    <?php else: ?>
    <div class="emp-grid">
    <?php foreach ($employees as $emp): ?>
    <?php
        $nightAllow = (int)($emp['night_allowance'] ?? 0);
        $isFulltime = $emp['type'] === 'fulltime';
        $wageUnit   = $isFulltime ? '/月' : '/h';
        $wageLabel  = $isFulltime ? '月薪' : '時薪';
        $loop       = md5($emp['name']);
    ?>
    <div class="emp-card" data-name="<?php echo htmlspecialchars(strtolower($emp['name'])); ?>">
        <!-- 卡片標題 -->
        <div class="emp-card-header">
            <div>
                <div class="emp-card-name">
                    <?php echo htmlspecialchars($emp['name']); ?>
                    <?php if ($nightAllow > 0): ?>
                        <span class="badge badge-night" style="font-size:0.7em">🌙</span>
                    <?php endif; ?>
                </div>
                <div style="margin-top:4px">
                    <span class="badge badge-<?php echo $emp['type']; ?>">
                        <?php echo $isFulltime ? '正職' : '時薪制'; ?>
                    </span>
                </div>
            </div>
            <div style="text-align:right;font-size:0.78em;color:var(--grey-500)">
                <?php echo $emp['created_at'] ?? ''; ?>
            </div>
        </div>

        <!-- 員工資訊 -->
        <div class="emp-card-info">
            <div class="emp-info-row">
                <span class="emp-info-label"><?php echo $wageLabel; ?></span>
                <span class="emp-info-val">$<?php echo number_format($emp['hourly_rate']); ?><?php echo $wageUnit; ?></span>
            </div>
            <div class="emp-info-row">
                <span class="emp-info-label">🌙 夜班津貼</span>
                <span class="emp-info-val <?php echo $nightAllow > 0 ? 'night-val' : ''; ?>">
                    <?php echo $nightAllow > 0 ? '$'.$nightAllow.'/次' : '—'; ?>
                </span>
            </div>
        </div>

        <hr class="emp-card-divider">

        <!-- 修改表單 -->
        <form method="post" class="edit-form">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="name" value="<?php echo htmlspecialchars($emp['name']); ?>">

            <div class="edit-row">
                <label>身分別</label>
                <select name="type" id="type_<?php echo $loop; ?>"
                        onchange="toggleWageLabel('<?php echo $loop; ?>')">
                    <option value="hourly"   <?php echo !$isFulltime ? 'selected':''; ?>>時薪制</option>
                    <option value="fulltime" <?php echo  $isFulltime ? 'selected':''; ?>>正職</option>
                </select>
            </div>
            <div class="edit-row">
                <label id="wlabel_<?php echo $loop; ?>"><?php echo $wageLabel; ?></label>
                <input type="number" name="wage"
                       id="wage_<?php echo $loop; ?>"
                       value="<?php echo $emp['hourly_rate']; ?>"
                       min="1" max="999999"
                       placeholder="<?php echo $isFulltime ? '月薪' : '時薪'; ?>">
            </div>
            <div class="edit-row">
                <label>🌙 津貼</label>
                <input type="number" name="night_allowance"
                       value="<?php echo $nightAllow; ?>"
                       min="0" max="9999" placeholder="0=不啟用">
            </div>

            <div class="edit-btns">
                <button type="submit" class="btn btn-save btn-sm">💾 儲存</button>
                <!-- 刪除 -->
                <button type="submit" form="del_<?php echo $loop; ?>" class="btn btn-danger btn-sm">🗑️ 刪除</button>
            </div>
        </form>
        <form id="del_<?php echo $loop; ?>" method="post"
              onsubmit="return confirm('確定刪除員工「<?php echo htmlspecialchars($emp['name']); ?>」？')">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="name" value="<?php echo htmlspecialchars($emp['name']); ?>">
        </form>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<script>
function toggleNav(btn) {
    const nav = document.getElementById('topbar-nav');
    nav.classList.toggle('open');
    btn.setAttribute('aria-expanded', nav.classList.contains('open'));
}
function filterAdminEmployees(kw) {
    const keyword = kw.trim().toLowerCase();
    const cards   = document.querySelectorAll('.emp-card');
    let visible   = 0;
    cards.forEach(card => {
        const name  = card.dataset.name || '';
        const match = name.includes(keyword);
        card.style.display = match ? '' : 'none';
        if (match) visible++;
    });
    const noResult = document.getElementById('no-result-msg');
    if (noResult) noResult.style.display = visible === 0 ? '' : 'none';
}

function toggleWageLabel(id) {
    let typeEl, wageEl, labelEl;
    if (id === 'add') {
        typeEl  = document.getElementById('add-type');
        wageEl  = document.getElementById('add-wage');
        labelEl = document.getElementById('add-wage-label');
    } else {
        typeEl  = document.getElementById('type_' + id);
        wageEl  = document.getElementById('wage_' + id);
        labelEl = document.getElementById('wlabel_' + id);
    }
    if (!typeEl || !wageEl) return;
    const isFulltime = typeEl.value === 'fulltime';
    const text = isFulltime ? '月薪' : '時薪';
    if (labelEl) labelEl.textContent = id === 'add' ? text + '（元）' : text;
    wageEl.placeholder = isFulltime ? '例：30000' : '例：180';
    wageEl.title       = isFulltime ? '月薪' : '時薪';
}
</script>
<!-- ── 帳號管理 ─────────────────────────────────── -->
<div class="card">
    <div class="card-title">🔑 登入帳號管理</div>

    <!-- 新增帳號表單 -->
    <form method="post" style="margin-bottom:20px">
        <input type="hidden" name="action" value="add_user">
        <div class="form-row">
            <div class="fg">
                <label>帳號</label>
                <input type="text" name="u_username" placeholder="登入帳號" required>
            </div>
            <div class="fg">
                <label>密碼（至少6碼）</label>
                <input type="password" name="u_password" placeholder="••••••" required>
            </div>
            <div class="fg">
                <label>角色</label>
                <select name="u_role" id="u-role-sel" onchange="toggleEmpSelect()">
                    <option value="admin">👑 管理員</option>
                    <option value="staff" selected>👤 員工</option>
                </select>
            </div>
            <div class="fg" id="u-emp-field">
                <label>對應員工</label>
                <select name="u_employee_name">
                    <option value="">— 請選擇 —</option>
                    <?php foreach ($employees as $e): ?>
                    <option value="<?php echo htmlspecialchars($e['name']); ?>">
                        <?php echo htmlspecialchars($e['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fg">
                <label>&nbsp;</label>
                <button type="submit" class="btn btn-add">＋ 新增帳號</button>
            </div>
        </div>
    </form>

    <!-- 現有帳號列表 -->
    <?php if (empty($allUsers)): ?>
    <div class="empty-state">尚未建立任何帳號</div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="user-table">
        <thead>
            <tr>
                <th>帳號</th>
                <th>角色</th>
                <th>對應員工</th>
                <th>建立時間</th>
                <th>重設密碼</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $curUser = currentUser();
        foreach ($allUsers as $u):
            $isSelf = (int)$u['id'] === (int)($curUser['id'] ?? 0);
        ?>
        <tr>
            <td>
                <strong><?php echo htmlspecialchars($u['username']); ?></strong>
                <?php if ($isSelf): ?>
                <span style="font-size:0.75em;color:var(--grey-500)">（自己）</span>
                <?php endif; ?>
            </td>
            <td>
                <span class="badge badge-<?php echo $u['role']==='admin'?'fulltime':'hourly'; ?>">
                    <?php echo $u['role']==='admin'?'👑 管理員':'👤 員工'; ?>
                </span>
            </td>
            <td style="color:var(--grey-<?php echo $u['employee_name']?'900':'300'; ?>)">
                <?php echo $u['employee_name'] ? htmlspecialchars($u['employee_name']) : '—'; ?>
            </td>
            <td style="color:var(--grey-500);font-size:0.85em"><?php echo $u['created_at']; ?></td>
            <td>
                <form method="post" class="reset-form">
                    <input type="hidden" name="action"     value="reset_password">
                    <input type="hidden" name="u_id"       value="<?php echo $u['id']; ?>">
                    <input type="hidden" name="u_username" value="<?php echo htmlspecialchars($u['username']); ?>">
                    <input type="password" name="u_new_password" placeholder="新密碼" minlength="6">
                    <button type="submit" class="btn btn-ghost btn-sm">🔄 重設</button>
                </form>
            </td>
            <td>
                <?php if ($isSelf): ?>
                <span style="font-size:0.8em;color:var(--grey-400)">無法刪除</span>
                <?php else: ?>
                <form method="post" style="margin:0"
                      onsubmit="return confirm('確定刪除帳號「<?php echo htmlspecialchars($u['username']); ?>」？')">
                    <input type="hidden" name="action"     value="delete_user">
                    <input type="hidden" name="u_id"       value="<?php echo $u['id']; ?>">
                    <input type="hidden" name="u_username" value="<?php echo htmlspecialchars($u['username']); ?>">
                    <button type="submit" class="btn btn-danger btn-sm">🗑️ 刪除</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<script>
function toggleEmpSelect() {
    const role = document.getElementById('u-role-sel').value;
    document.getElementById('u-emp-field').style.display = role === 'staff' ? '' : 'none';
}
toggleEmpSelect(); // 初始化
</script>

</div>
</body>
</html>
