<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/vendor/autoload.php';
include_once __DIR__ . '/auth.php';
requireAdmin();

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
        $name       = trim($_POST['name']             ?? '');
        $type       = $_POST['type']                  ?? 'hourly';
        $wage       = (int)($_POST['wage']            ?? 180);
        $nightAllow = (int)($_POST['night_allowance'] ?? 0);
        $idNumber   = trim($_POST['id_number']        ?? '');
        $phone      = trim($_POST['phone']            ?? '');
        $hireDate   = trim($_POST['hire_date']        ?? '');
        if (empty($name)) {
            $message = '請輸入員工姓名'; $msgType = 'error';
        } elseif (addEmployee([
            'name'            => $name,
            'type'            => $type,
            'hourly_rate'     => $wage,
            'night_allowance' => $nightAllow,
            'id_number'       => $idNumber ?: null,
            'phone'           => $phone    ?: null,
            'hire_date'       => $hireDate ?: null,
        ])) {
            $message = "✅ 員工「{$name}」已新增"; $msgType = 'success';
        } else {
            $message = "⚠️ 員工「{$name}」已存在"; $msgType = 'error';
        }

    } elseif ($action === 'update') {
        $name       = $_POST['name']                  ?? '';
        $type       = $_POST['type']                  ?? 'hourly';
        $wage       = (int)($_POST['wage']            ?? 180);
        $nightAllow = (int)($_POST['night_allowance'] ?? 0);
        $idNumber   = trim($_POST['id_number']        ?? '');
        $phone      = trim($_POST['phone']            ?? '');
        $hireDate   = trim($_POST['hire_date']        ?? '');
        if (updateEmployee($name, [
            'type'            => $type,
            'hourly_rate'     => $wage,
            'night_allowance' => $nightAllow,
            'id_number'       => $idNumber ?: null,
            'phone'           => $phone    ?: null,
            'hire_date'       => $hireDate ?: null,
        ])) {
            $message = "✅ 員工「{$name}」已更新"; $msgType = 'success';
        } else {
            $message = '找不到該員工'; $msgType = 'error';
        }

    } elseif ($action === 'bulk_update') {
        $rows   = $_POST['rows'] ?? [];
        $saved  = 0; $errors = [];
        foreach ($rows as $name => $d) {
            if (updateEmployee($name, [
                'type'            => $d['type']            ?? 'hourly',
                'hourly_rate'     => (int)($d['wage']      ?? 0),
                'night_allowance' => (int)($d['night_allowance'] ?? 0),
                'id_number'       => $d['id_number']       ?: null,
                'phone'           => $d['phone']           ?: null,
                'hire_date'       => $d['hire_date']       ?: null,
            ])) { $saved++; } else { $errors[] = $name; }
        }
        $message = "✅ 已更新 {$saved} 筆員工資料" . (!empty($errors) ? '，失敗：'.implode('、',$errors) : '');
        $msgType = empty($errors) ? 'success' : 'error';

    } elseif ($action === 'delete') {
        $name = $_POST['name'] ?? '';
        if (deleteEmployee($name)) {
            $message = "🗑️ 員工「{$name}」已刪除"; $msgType = 'success';
        } else {
            $message = '找不到該員工或有出勤記錄無法刪除'; $msgType = 'error';
        }

    } elseif ($action === 'bulk_delete') {
        $names = $_POST['names'] ?? [];
        $deleted = 0; $errors = [];
        foreach ($names as $name) {
            if (deleteEmployee($name)) { $deleted++; } else { $errors[] = $name; }
        }
        $message = "🗑️ 已刪除 {$deleted} 筆員工資料" . (!empty($errors) ? '，以下有出勤記錄無法刪除：'.implode('、',$errors) : '');
        $msgType = $deleted > 0 ? 'success' : 'error';

    } elseif ($action === 'export_employees') {
        $employees = getEmployees();
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('員工資料');
        $headers = ['姓名','身分別','月薪/時薪','薪資單位','津貼(元/次)','身分證字號','連絡電話','到職日期','加入日期'];
        foreach ($headers as $ci => $h) $sheet->setCellValue(chr(65+$ci).'1', $h);
        $endCol = chr(65+count($headers)-1);
        $sheet->getStyle("A1:{$endCol}1")->applyFromArray([
            'font'=>['bold'=>true,'color'=>['rgb'=>'FFFFFF'],'size'=>11],
            'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'1B5E20']],
            'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER],
        ]);
        foreach ($employees as $ri => $emp) {
            $r = $ri+2; $isF = $emp['type']==='fulltime';
            $sheet->setCellValue('A'.$r, $emp['name']);
            $sheet->setCellValue('B'.$r, $isF?'正職':'時薪制');
            $sheet->setCellValue('C'.$r, $emp['hourly_rate']);
            $sheet->setCellValue('D'.$r, $isF?'元/月':'元/時');
            $sheet->setCellValue('E'.$r, (int)($emp['night_allowance']??0)>0 ? $emp['night_allowance'] : '—');
            $sheet->setCellValue('F'.$r, $emp['id_number'] ?? '');
            $sheet->setCellValue('G'.$r, $emp['phone']     ?? '');
            $sheet->setCellValue('H'.$r, $emp['hire_date'] ?? '');
            $sheet->setCellValue('I'.$r, $emp['created_at'] ?? '');
        }
        foreach (range('A',$endCol) as $col) $sheet->getColumnDimension($col)->setAutoSize(true);
        $exportName = '員工資料統計_'.date('Ymd').'.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="'.$exportName.'"');
        header('Cache-Control: max-age=0');
        (new Xlsx($spreadsheet))->save('php://output');
        exit;
    }
}

ensureEmployeeColumns();
$employees    = getEmployees();
$fulltimeEmps = array_filter($employees, fn($e) => $e['type'] === 'fulltime');
$hourlyEmps   = array_filter($employees, fn($e) => $e['type'] === 'hourly');
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>員工管理 — 薪資結算系統</title>
<link rel="stylesheet" href="responsive.css">
<style>
.main-wrap { max-width: 1400px; }

.topbar { position: relative; }
@media (max-width: 540px) {
  .topbar-nav { display:none; position:absolute; top:100%; right:0; min-width:160px;
    background:var(--green-800,#2e7d32); border-radius:0 0 10px 10px;
    box-shadow:0 6px 20px rgba(0,0,0,0.25); flex-direction:column; padding:6px 0; z-index:200; }
  .topbar-nav.open { display:flex; }
  .topbar-nav .topbar-link { display:block; width:100%; text-align:left; padding:10px 18px;
    border-radius:0; background:transparent; box-sizing:border-box; white-space:nowrap; }
  .topbar-nav .topbar-link:hover { background:rgba(255,255,255,0.12); }
}

.form-row { display:grid; grid-template-columns:repeat(auto-fill,minmax(130px,1fr)); gap:10px; align-items:flex-end; }
.fg { display:flex; flex-direction:column; gap:4px; }
.fg label { font-size:0.78em; color:var(--grey-500); white-space:nowrap; font-weight:600; }
.fg input, .fg select { padding:9px 11px; border:1.5px solid var(--grey-300); border-radius:var(--radius-sm);
  font-size:0.9em; color:var(--grey-900); font-family:var(--font-body); width:100%; }
.fg input:focus, .fg select:focus { outline:none; border-color:var(--green-600); }
.btn-add    { background:var(--green-700); color:white; min-height:40px; }
.btn-export { background:var(--green-50); color:var(--green-700); border:1.5px solid #A5D6A7; }
.btn-export:hover { background:var(--green-100); }

/* 收折區 */
.section-header {
  display:flex; align-items:center; justify-content:space-between;
  padding:12px 16px; background:var(--green-50);
  border:1.5px solid #C8E6C9; border-radius:var(--radius-md);
  cursor:pointer; user-select:none; margin-bottom:6px;
}
.section-header:hover { background:var(--green-100); }
.section-header-title { font-weight:700; color:var(--green-700); font-size:0.95em; display:flex; align-items:center; gap:8px; }
.section-header-arrow { color:var(--green-600); font-size:0.9em; transition:transform 0.2s; }
.section-header.pt-header { background:#F3E5F5; border-color:#CE93D8; }
.section-header.pt-header:hover { background:#EDE0F7; }
.section-header.pt-header .section-header-title { color:var(--purple-600); }
.section-header.pt-header .section-header-arrow { color:var(--purple-600); }

/* 員工表格 */
.emp-table { width:100%; border-collapse:collapse; font-size:0.92em; }
.emp-table th {padding:10px 12px; text-align:center; border:1px solid #C8E6C9;
  background:var(--green-50); color:var(--green-700); font-weight:600; white-space:nowrap; }
.emp-table.pt-table th {text-align: center; background:#F3E5F5; border-color:#CE93D8; color:var(--purple-600); }
.emp-table td {text-align: center; padding:9px 12px; border:1px solid #eee; vertical-align:middle; white-space:nowrap; }
.emp-table tr:nth-child(even) td { background:#FAFAFA; }
.emp-table tr:hover td { background:#F1F8E9; }
.emp-table.pt-table tr:hover td { background:#F8F0FF; }

.wage-cell  { font-weight:700; color:var(--blue-700); }
.night-cell { font-weight:700; color:var(--purple-600); }
.empty-td   { text-align:center; color:var(--grey-400); padding:24px !important; }

/* 操作按鈕 */
.action-cell { display:flex; gap:6px; justify-content:center; }

/* 多選工具列 */
.bulk-toolbar {
  display:none; align-items:center; gap:10px;
  padding:10px 14px; background:var(--green-50);
  border:1.5px solid #A5D6A7; border-radius:var(--radius-sm);
  margin-bottom:8px; font-size:0.88em;
}
.bulk-toolbar.active { display:flex; }

/* 編輯 Modal */
.modal-overlay {
  display:none; position:fixed; inset:0;
  background:rgba(0,0,0,0.55); z-index:9000;
  align-items:center; justify-content:center; padding:16px;
  backdrop-filter:blur(2px);
}
.modal-overlay.open { display:flex; }
.modal-box {
  background:white; border-radius:16px;
  max-width:700px; width:100%; max-height:88vh;
  overflow:hidden; display:flex; flex-direction:column;
  box-shadow:0 20px 60px rgba(0,0,0,0.3);
}
.modal-header {
  padding:18px 22px 14px;
  border-bottom:2px solid var(--green-100);
  display:flex; justify-content:space-between; align-items:center;
}
.modal-header.confirm-header { border-bottom-color:var(--amber-200); }
.modal-title { font-weight:800; font-size:1.05em; color:var(--green-700); }
.modal-title.confirm-title { color:#92400E; }
.modal-close { background:var(--grey-100); border:none; width:32px; height:32px;
  border-radius:50%; font-size:1em; cursor:pointer; color:var(--grey-500);
  display:flex; align-items:center; justify-content:center; }
.modal-body  { padding:16px 22px; overflow-y:auto; flex:1; }
.modal-footer { padding:14px 22px; border-top:1px solid #eee; display:flex; gap:10px;
  justify-content:flex-end; background:#FAFAFA; border-radius:0 0 16px 16px; }

/* 編輯區塊（每位員工） */
.edit-block {
  background:#F8F9FA; border-radius:10px; padding:14px 16px;
  border:1px solid #eee; margin-bottom:12px;
}
.edit-block:last-child { margin-bottom:0; }
.edit-block-name { font-size:0.85em; font-weight:700; color:var(--green-700); margin-bottom:12px; }
.edit-block.pt-block .edit-block-name { color:var(--purple-600); }
.edit-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:10px; }
.edit-field { display:flex; flex-direction:column; gap:4px; }
.edit-field label { font-size:0.75em; color:var(--grey-500); font-weight:600; }
.edit-field input, .edit-field select {
  padding:8px 10px; border:1.5px solid #B39DDB; border-radius:var(--radius-sm);
  font-size:0.9em; font-family:var(--font-body); background:white; color:var(--grey-900);
}
.edit-field input:focus, .edit-field select:focus { outline:none; border-color:var(--green-600); }
.wage-required-msg { font-size:0.78em; color:var(--red-500); display:none; margin-top:3px; }

/* 確認視窗表格 */
.confirm-table { width:100%; border-collapse:collapse; font-size:0.9em; }
.confirm-table th { padding:9px 12px; background:var(--amber-100); color:#92400E;
  border:1px solid #FDE68A; text-align:left; font-weight:600; }
.confirm-table td { padding:8px 12px; border:1px solid #eee; vertical-align:middle; }
.confirm-old { color:var(--grey-400); text-decoration:line-through; font-size:0.88em; }
.confirm-new { color:var(--green-700); font-weight:700; }
.confirm-unchanged { color:var(--grey-500); }

/* 區塊分隔標題（多筆混合時） */
.group-label { font-size:0.78em; font-weight:700; letter-spacing:0.06em;
  color:var(--grey-500); text-transform:uppercase; padding:6px 0 8px; margin-top:4px; }
</style>
</head>
<body>
<div class="topbar">
  <div class="topbar-inner">
    <span class="topbar-title">👥 員工管理</span>
    <button class="topbar-burger" onclick="toggleNav(this)" aria-label="選單">
      <span></span><span></span><span></span>
    </button>
    <nav class="topbar-nav" id="topbar-nav">
      <span class="topbar-link" style="background:rgba(255,255,255,0.1);cursor:default">
        <?php echo htmlspecialchars(displayName()); ?>
      </span>
      <a href="index.php"  class="topbar-link">🏠 首頁</a>
      <a href="logout.php" class="topbar-link">登出</a>
    </nav>
  </div>
</div>

<div class="main-wrap footer-pad" style="margin-top:14px">

<?php if (!empty($message)): ?>
<div class="msg msg-<?php echo $msgType==='success'?'success':'error'; ?>" style="margin-bottom:14px">
  <?php echo htmlspecialchars($message); ?>
</div>
<?php endif; ?>

<!-- ── 新增員工 ── -->
<div class="card" style="margin-bottom:14px">
  <div class="card-title">➕ 新增員工</div>
  <form method="post" id="addForm">
    <input type="hidden" name="action" value="add">
    <div class="form-row">
      <div class="fg">
        <label>姓名 <span style="color:var(--red-500)">*</span></label>
        <input type="text" name="name" placeholder="例：小明" required>
      </div>
      <div class="fg">
        <label>身分別</label>
        <select name="type" id="add-type" onchange="toggleAddWage()">
          <option value="hourly">時薪制</option>
          <option value="fulltime">正職</option>
        </select>
      </div>
      <div class="fg">
        <label id="add-wage-label">時薪（元）</label>
        <input type="number" name="wage" id="add-wage" value="180" min="1" max="999999" required>
      </div>
      <div class="fg">
        <label>津貼（元）</label>
        <input type="number" name="night_allowance" value="0" min="0" max="9999" placeholder="0=不啟用">
      </div>
      <div class="fg">
        <label>身分證字號</label>
        <input type="text" name="id_number" placeholder="A123456789" maxlength="10" style="text-transform:uppercase">
      </div>
      <div class="fg">
        <label>連絡電話</label>
        <input type="tel" name="phone" placeholder="0912345678">
      </div>
      <div class="fg">
        <label>到職日期</label>
        <input type="date" name="hire_date">
      </div>
      <div class="fg">
        <label>&nbsp;</label>
        <button type="submit" class="btn btn-add">＋ 新增</button>
      </div>
    </div>
  </form>
</div>

<!-- ── 工具列 ── -->
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:10px">
  <div id="bulk-toolbar" class="bulk-toolbar">
    <span id="bulk-count" style="color:var(--green-700);font-weight:700">已選 0 人</span>
    <button type="button" class="btn btn-ghost btn-sm" onclick="openBulkEdit()">✏️ 多筆編輯</button>
    <button type="button" class="btn btn-danger btn-sm" onclick="openBulkDelete()">🗑️ 多筆刪除</button>
  </div>
  <div style="display:flex;gap:8px;margin-left:auto">
    <form method="post" style="margin:0">
      <input type="hidden" name="action" value="export_employees">
      <button type="submit" class="btn btn-export btn-sm">📊 匯出 Excel</button>
    </form>
    <a href="import_employees.php" class="btn btn-ghost btn-sm">📥 批量匯入</a>
  </div>
</div>

<!-- ── 正職員工區 ── -->
<div class="card" style="padding:0;overflow:hidden;margin-bottom:12px">
  <div class="section-header" onclick="toggleSection('fulltime-body', this)">
    <div class="section-header-title">
      <input type="checkbox" id="chk-all-fulltime" onclick="event.stopPropagation()"
             onchange="toggleGroupAll('fulltime', this)" title="全選正職">
      👔 正職員工（<?php echo count($fulltimeEmps); ?> 人）
    </div>
    <span class="section-header-arrow" id="arrow-fulltime">▼</span>
  </div>
  <div id="fulltime-body" style="overflow-x:auto;padding:0 4px 4px">
    <table class="emp-table">
      <thead>
        <tr>
          <th style="width:36px"></th>
          <th>姓名</th>
          <th>身分別</th>
          <th>身分證字號</th>
          <th>連絡電話</th>
          <th>到職日期</th>
          <th>月薪</th>
          <th>津貼</th>
          <th>操作</th>
        </tr>
      </thead>
      <tbody id="fulltime-tbody">
      <?php if (empty($fulltimeEmps)): ?>
        <tr><td colspan="9" class="empty-td">目前沒有正職員工</td></tr>
      <?php else: foreach ($fulltimeEmps as $emp): ?>
        <?php $na = (int)($emp['night_allowance']??0); ?>
        <tr class="emp-row" data-name="<?php echo htmlspecialchars($emp['name']); ?>"
            data-type="fulltime"
            data-wage="<?php echo $emp['hourly_rate']; ?>"
            data-night="<?php echo $na; ?>"
            data-id="<?php echo htmlspecialchars($emp['id_number']??''); ?>"
            data-phone="<?php echo htmlspecialchars($emp['phone']??''); ?>"
            data-hire="<?php echo $emp['hire_date']??''; ?>">
          <td><input type="checkbox" class="emp-chk" value="<?php echo htmlspecialchars($emp['name']); ?>"
                     onchange="onEmpCheck()"></td>
          <td><strong><?php echo htmlspecialchars($emp['name']); ?></strong></td>
          <td><span class="badge badge-fulltime" style="white-space:nowrap">正職</span></td>
          <td><?php echo $emp['id_number'] ? htmlspecialchars($emp['id_number']) : '—'; ?></td>
          <td><?php echo $emp['phone']     ? htmlspecialchars($emp['phone'])     : '—'; ?></td>
          <td><?php echo $emp['hire_date'] ? $emp['hire_date'] : '—'; ?></td>
          <td class="wage-cell">$<?php echo number_format($emp['hourly_rate']); ?>/月</td>
          <td class="<?php echo $na>0?'night-cell':''; ?>"><?php echo $na>0?'$'.$na:'—'; ?></td>
          <td>
            <div class="action-cell">
              <button type="button" class="btn btn-ghost btn-sm"
                      onclick="openEditModal([this.closest('tr')])">✏️ 編輯</button>
              <button type="button" class="btn btn-danger btn-sm"
                      onclick="deleteSingle('<?php echo htmlspecialchars($emp['name']); ?>')">🗑️</button>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ── 時薪制員工區 ── -->
<div class="card" style="padding:0;overflow:hidden;margin-bottom:12px">
  <div class="section-header pt-header" onclick="toggleSection('hourly-body', this)">
    <div class="section-header-title">
      <input type="checkbox" id="chk-all-hourly" onclick="event.stopPropagation()"
             onchange="toggleGroupAll('hourly', this)" title="全選時薪制">
      ⏰ PT員工（<?php echo count($hourlyEmps); ?> 人）
    </div>
    <span class="section-header-arrow" id="arrow-hourly">▼</span>
  </div>
  <div id="hourly-body" style="overflow-x:auto;padding:0 4px 4px">
    <table class="emp-table pt-table">
      <thead>
        <tr>
          <th style="width:36px"></th>
          <th>姓名</th>
          <th>身分別</th>
          <th>身分證字號</th>
          <th>連絡電話</th>
          <th>到職日期</th>
          <th>時薪</th>
          <th>津貼</th>
          <th>操作</th>
        </tr>
      </thead>
      <tbody id="hourly-tbody">
      <?php if (empty($hourlyEmps)): ?>
        <tr><td colspan="9" class="empty-td">目前沒有時薪制員工</td></tr>
      <?php else: foreach ($hourlyEmps as $emp): ?>
        <?php $na = (int)($emp['night_allowance']??0); ?>
        <tr class="emp-row" data-name="<?php echo htmlspecialchars($emp['name']); ?>"
            data-type="hourly"
            data-wage="<?php echo $emp['hourly_rate']; ?>"
            data-night="<?php echo $na; ?>"
            data-id="<?php echo htmlspecialchars($emp['id_number']??''); ?>"
            data-phone="<?php echo htmlspecialchars($emp['phone']??''); ?>"
            data-hire="<?php echo $emp['hire_date']??''; ?>">
          <td><input type="checkbox" class="emp-chk" value="<?php echo htmlspecialchars($emp['name']); ?>"
                     onchange="onEmpCheck()"></td>
          <td><strong><?php echo htmlspecialchars($emp['name']); ?></strong></td>
          <td><span class="badge badge-hourly" style="white-space:nowrap">時薪制</span></td>
          <td><?php echo $emp['id_number'] ? htmlspecialchars($emp['id_number']) : '—'; ?></td>
          <td><?php echo $emp['phone']     ? htmlspecialchars($emp['phone'])     : '—'; ?></td>
          <td><?php echo $emp['hire_date'] ? $emp['hire_date'] : '—'; ?></td>
          <td class="wage-cell">$<?php echo number_format($emp['hourly_rate']); ?>/h</td>
          <td class="<?php echo $na>0?'night-cell':''; ?>"><?php echo $na>0?'$'.$na:'—'; ?></td>
          <td>
            <div class="action-cell">
              <button type="button" class="btn btn-ghost btn-sm"
                      onclick="openEditModal([this.closest('tr')])">✏️ 編輯</button>
              <button type="button" class="btn btn-danger btn-sm"
                      onclick="deleteSingle('<?php echo htmlspecialchars($emp['name']); ?>')">🗑️</button>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

</div><!-- /main-wrap -->

<!-- ══ 編輯 Modal ══ -->
<div id="edit-modal" class="modal-overlay">
  <div class="modal-box">
    <div class="modal-header">
      <div class="modal-title">✏️ 編輯員工資料</div>
      <button class="modal-close" onclick="closeEditModal()">✕</button>
    </div>
    <div class="modal-body" id="edit-modal-body"></div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="closeEditModal()">取消</button>
      <button type="button" class="btn btn-primary" id="edit-confirm-btn" onclick="submitEdit()">修改</button>
    </div>
  </div>
</div>

<!-- ══ 確認 Modal ══ -->
<div id="confirm-modal" class="modal-overlay">
  <div class="modal-box">
    <div class="modal-header confirm-header">
      <div class="modal-title confirm-title">⚠️ 確認修改內容</div>
      <button class="modal-close" onclick="closeConfirmModal()">✕</button>
    </div>
    <div class="modal-body" id="confirm-modal-body"></div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="closeConfirmModal()">← 返回修改</button>
      <button type="button" class="btn btn-primary" onclick="executeUpdate()">✅ 確認修改</button>
    </div>
  </div>
</div>

<!-- ══ 刪除確認 Modal ══ -->
<div id="delete-modal" class="modal-overlay">
  <div class="modal-box" style="max-width:480px">
    <div class="modal-header" style="border-bottom-color:#FFEBEE">
      <div class="modal-title" style="color:var(--red-600)">🗑️ 確認刪除</div>
      <button class="modal-close" onclick="closeDeleteModal()">✕</button>
    </div>
    <div class="modal-body" id="delete-modal-body"></div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">取消</button>
      <button type="button" class="btn btn-danger" id="delete-confirm-btn" onclick="executeDelete()">確定刪除</button>
    </div>
  </div>
</div>

<script>
function toggleNav(btn) {
  const nav = document.getElementById('topbar-nav');
  nav.classList.toggle('open');
  btn.setAttribute('aria-expanded', nav.classList.contains('open'));
}

// ── 收折 ──
function toggleSection(bodyId, header) {
  const body  = document.getElementById(bodyId);
  const type  = bodyId.replace('-body', '');
  const arrow = document.getElementById('arrow-' + type);
  const open  = body.style.display !== 'none';
  body.style.display = open ? 'none' : '';
  if (arrow) arrow.textContent = open ? '▶' : '▼';
}

// ── Checkbox ──
function onEmpCheck() {
  const checked = document.querySelectorAll('.emp-chk:checked');
  const toolbar = document.getElementById('bulk-toolbar');
  const countEl = document.getElementById('bulk-count');
  toolbar.classList.toggle('active', checked.length > 0);
  if (countEl) countEl.textContent = '已選 ' + checked.length + ' 人';
}
function toggleGroupAll(type, chk) {
  document.querySelectorAll(
    '#' + type + '-tbody .emp-chk'
  ).forEach(c => c.checked = chk.checked);
  onEmpCheck();
}

// ── 新增表單薪資 label 切換 ──
function toggleAddWage() {
  const type = document.getElementById('add-type').value;
  document.getElementById('add-wage-label').textContent = type === 'fulltime' ? '月薪（元）' : '時薪（元）';
  document.getElementById('add-wage').placeholder = type === 'fulltime' ? '例：30000' : '例：180';
}

// ── 取得已勾選的 rows ──
function getCheckedRows() {
  return Array.from(document.querySelectorAll('.emp-chk:checked')).map(c => c.closest('tr'));
}

// ══ 編輯 Modal ══
let editOriginalData = {}; // 儲存原始資料，供身分別切換時還原

function openEditModal(rows) {
  editOriginalData = {};
  const body = document.getElementById('edit-modal-body');

  // 依身分分組
  const ftRows = rows.filter(r => r.dataset.type === 'fulltime');
  const hrRows = rows.filter(r => r.dataset.type === 'hourly');
  const showGroup = rows.length > 1 && ftRows.length > 0 && hrRows.length > 0;

  let html = '';
  const allGroups = [
    { label: '👔 正職', rows: ftRows, cls: '' },
    { label: '⏰ 時薪制', rows: hrRows, cls: 'pt-block' },
  ];
  for (const grp of allGroups) {
    if (grp.rows.length === 0) continue;
    if (showGroup) html += `<div class="group-label">${grp.label}</div>`;
    for (const tr of grp.rows) {
      const d = tr.dataset;
      const name      = d.name;
      const origType  = d.type;
      const origWage  = d.wage;
      const origNight = d.night;
      editOriginalData[name] = { type: origType, wage: origWage, night: origNight,
                                  id: d.id, phone: d.phone, hire: d.hire };
      const wageLabel = origType === 'fulltime' ? '月薪' : '時薪';
      html += `
      <div class="edit-block ${grp.cls}" id="block-${name.replace(/[^a-zA-Z0-9]/g,'_')}">
        <div class="edit-block-name">📋 ${name}</div>
        <div class="edit-grid">
          <div class="edit-field">
            <label>身分別</label>
            <select name="rows[${name}][type]" onchange="onTypeChange('${name}', this)">
              <option value="hourly"   ${origType==='hourly'  ?'selected':''}>時薪制</option>
              <option value="fulltime" ${origType==='fulltime'?'selected':''}>正職</option>
            </select>
          </div>
          <div class="edit-field" id="wage-field-${name.replace(/[^a-zA-Z0-9]/g,'_')}">
            <label id="wage-label-${name.replace(/[^a-zA-Z0-9]/g,'_')}">${wageLabel}（元）</label>
            <input type="number" name="rows[${name}][wage]"
                   id="wage-input-${name.replace(/[^a-zA-Z0-9]/g,'_')}"
                   value="${origWage}" min="1" max="999999" required>
            <span class="wage-required-msg" id="wage-msg-${name.replace(/[^a-zA-Z0-9]/g,'_')}">
              請填入薪資才能送出
            </span>
          </div>
          <div class="edit-field">
            <label>津貼（元）</label>
            <input type="number" name="rows[${name}][night_allowance]" value="${origNight}" min="0" max="9999">
          </div>
          <div class="edit-field">
            <label>身分證字號</label>
            <input type="text" name="rows[${name}][id_number]" value="${d.id}"
                   maxlength="10" style="text-transform:uppercase">
          </div>
          <div class="edit-field">
            <label>連絡電話</label>
            <input type="tel" name="rows[${name}][phone]" value="${d.phone}">
          </div>
          <div class="edit-field">
            <label>到職日期</label>
            <input type="date" name="rows[${name}][hire_date]" value="${d.hire}">
          </div>
        </div>
      </div>`;
    }
  }
  body.innerHTML = html;
  document.getElementById('edit-modal').classList.add('open');
  document.body.style.overflow = 'hidden';
}

function onTypeChange(name, sel) {
  const safeId    = name.replace(/[^a-zA-Z0-9]/g,'_');
  const wageInput = document.getElementById('wage-input-' + safeId);
  const wageLabel = document.getElementById('wage-label-' + safeId);
  const orig      = editOriginalData[name];
  if (!orig) return;
  if (sel.value === orig.type) {
    // 回到原始身分 → 還原原始薪資
    wageInput.value = orig.wage;
    wageLabel.textContent = (orig.type === 'fulltime' ? '月薪' : '時薪') + '（元）';
  } else {
    // 切換到不同身分 → 清空薪資
    wageInput.value = '';
    wageLabel.textContent = (sel.value === 'fulltime' ? '月薪' : '時薪') + '（元）';
  }
}

function closeEditModal() {
  document.getElementById('edit-modal').classList.remove('open');
  document.body.style.overflow = '';
}

function submitEdit() {
  // 驗證：所有薪資欄位不能為空
  let valid = true;
  document.querySelectorAll('#edit-modal-body input[type="number"][name*="[wage]"]').forEach(inp => {
    const name    = inp.name.match(/rows\[(.+?)\]\[wage\]/)?.[1] ?? '';
    const safeId  = name.replace(/[^a-zA-Z0-9]/g,'_');
    const msgEl   = document.getElementById('wage-msg-' + safeId);
    if (!inp.value || parseInt(inp.value) < 1) {
      if (msgEl) msgEl.style.display = 'block';
      valid = false;
    } else {
      if (msgEl) msgEl.style.display = 'none';
    }
  });
  if (!valid) return;

  // 收集資料，產生確認視窗
  const rows = {};
  const body = document.getElementById('edit-modal-body');
  body.querySelectorAll('.edit-block').forEach(block => {
    const nameEl = block.querySelector('[name*="[type]"]');
    if (!nameEl) return;
    const name = nameEl.name.match(/rows\[(.+?)\]\[type\]/)?.[1] ?? '';
    rows[name] = {
      type:            block.querySelector('[name*="[type]"]').value,
      wage:            block.querySelector('[name*="[wage]"]').value,
      night_allowance: block.querySelector('[name*="[night_allowance]"]').value,
      id_number:       block.querySelector('[name*="[id_number]"]').value,
      phone:           block.querySelector('[name*="[phone]"]').value,
      hire_date:       block.querySelector('[name*="[hire_date]"]').value,
    };
  });

  // 建立確認表格
  const fieldLabels = {
    type: '身分別', wage: '月薪/時薪', night_allowance: '津貼',
    id_number: '身分證字號', phone: '連絡電話', hire_date: '到職日期'
  };
  const typeLabel = v => v === 'fulltime' ? '正職' : '時薪制';
  let html = '<table class="confirm-table"><thead><tr><th>姓名</th><th>欄位</th><th>原始值</th><th>修改後</th></tr></thead><tbody>';
  let hasChange = false;
  for (const [name, newD] of Object.entries(rows)) {
    const orig = editOriginalData[name] ?? {};
    const origMap = { type: orig.type, wage: orig.wage, night_allowance: orig.night,
                      id_number: orig.id, phone: orig.phone, hire_date: orig.hire };
    for (const [key, label] of Object.entries(fieldLabels)) {
      const oldVal = origMap[key] ?? '';
      const newVal = newD[key]   ?? '';
      const dispOld = key === 'type' ? typeLabel(oldVal) : (oldVal || '—');
      const dispNew = key === 'type' ? typeLabel(newVal) : (newVal || '—');
      if (String(oldVal) !== String(newVal)) {
        hasChange = true;
        html += `<tr><td style="font-weight:700">${name}</td><td>${label}</td>
          <td class="confirm-old">${dispOld}</td><td class="confirm-new">${dispNew}</td></tr>`;
      }
    }
  }
  html += '</tbody></table>';
  if (!hasChange) { html = '<p style="padding:12px;color:var(--grey-500)">沒有任何修改內容。</p>'; }

  document.getElementById('confirm-modal-body').innerHTML = html;
  // 儲存待送出資料
  window._pendingEditRows = rows;
  document.getElementById('confirm-modal').classList.add('open');
}

function closeConfirmModal() {
  document.getElementById('confirm-modal').classList.remove('open');
  // 不關閉 edit-modal，讓管理員可以繼續修改
}

function executeUpdate() {
  const rows = window._pendingEditRows;
  if (!rows) return;
  const form = document.createElement('form');
  form.method = 'post'; form.style.display = 'none';
  const addHidden = (n,v) => { const i = document.createElement('input'); i.type='hidden'; i.name=n; i.value=v; form.appendChild(i); };
  addHidden('action', 'bulk_update');
  for (const [name, d] of Object.entries(rows)) {
    addHidden(`rows[${name}][type]`,            d.type);
    addHidden(`rows[${name}][wage]`,            d.wage);
    addHidden(`rows[${name}][night_allowance]`, d.night_allowance);
    addHidden(`rows[${name}][id_number]`,       d.id_number);
    addHidden(`rows[${name}][phone]`,           d.phone);
    addHidden(`rows[${name}][hire_date]`,       d.hire_date);
  }
  document.body.appendChild(form);
  form.submit();
}

// ══ 多筆編輯 ══
function openBulkEdit() {
  const rows = getCheckedRows();
  if (rows.length === 0) return;
  openEditModal(rows);
}

// ══ 刪除 ══
let _pendingDeleteNames = [];
function deleteSingle(name) {
  _pendingDeleteNames = [name];
  document.getElementById('delete-modal-body').innerHTML =
    `<p style="padding:12px 4px;font-size:0.95em">確定刪除員工「<strong>${name}</strong>」？<br>
    <span style="font-size:0.85em;color:var(--grey-500)">若該員工有出勤記錄則無法刪除。</span></p>`;
  document.getElementById('delete-modal').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function openBulkDelete() {
  const rows = getCheckedRows();
  if (rows.length === 0) return;
  _pendingDeleteNames = rows.map(r => r.dataset.name);
  const nameList = _pendingDeleteNames.map(n => `<li>${n}</li>`).join('');
  document.getElementById('delete-modal-body').innerHTML =
    `<p style="padding:12px 4px 8px;font-size:0.95em">確定刪除以下 <strong>${_pendingDeleteNames.length}</strong> 位員工？</p>
    <ul style="padding:0 4px 4px 20px;font-size:0.9em;color:var(--grey-700)">${nameList}</ul>
    <p style="font-size:0.82em;color:var(--grey-500);padding:0 4px 12px">若有出勤記錄的員工將無法刪除。</p>`;
  document.getElementById('delete-modal').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeDeleteModal() {
  document.getElementById('delete-modal').classList.remove('open');
  document.body.style.overflow = '';
  _pendingDeleteNames = [];
}
function executeDelete() {
  if (_pendingDeleteNames.length === 0) return;
  const form = document.createElement('form');
  form.method = 'post'; form.style.display = 'none';
  const addHidden = (n,v) => { const i = document.createElement('input'); i.type='hidden'; i.name=n; i.value=v; form.appendChild(i); };
  if (_pendingDeleteNames.length === 1) {
    addHidden('action', 'delete');
    addHidden('name', _pendingDeleteNames[0]);
  } else {
    addHidden('action', 'bulk_delete');
    _pendingDeleteNames.forEach(n => addHidden('names[]', n));
  }
  document.body.appendChild(form);
  form.submit();
}
</script>
</body>
</html>
