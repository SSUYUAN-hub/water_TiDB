<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/vendor/autoload.php';
include_once __DIR__ . '/auth.php';
requireAdmin();

$message = '';
$msgType = '';
$curUser    = currentUser();
$isSysAdmin = isSysAdmin(); // 只有 role='admin' 才是系統管理

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── 通用安全攔截：非系統管理不得操作 role=admin 的帳號 ──
    if (!$isSysAdmin && in_array($action, ['rename_user','reset_password','delete_user'])) {
        $targetId = (int)($_POST['u_id'] ?? 0);
        if ($targetId > 0) {
            $chk = getDB()->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
            $chk->execute([$targetId]);
            $targetRole = $chk->fetchColumn();
            if ($targetRole === 'admin') {
                $message = '⛔ 無權限操作系統管理帳號'; $msgType = 'error';
                goto render_page;
            }
        }
    }

    // 新增帳號
    if ($action === 'add_user') {
        $uname    = trim($_POST['u_username']     ?? '');
        $upass    = $_POST['u_password']           ?? '';
        $urole    = $_POST['u_role']               ?? 'staff';
        $uempname = trim($_POST['u_employee_name'] ?? '');

        // 非系統管理不得新增 admin 角色
        if ($urole === 'admin' && !$isSysAdmin) {
            $message = '⛔ 無權限新增系統管理帳號'; $msgType = 'error';
        } elseif (empty($uname) || strlen($upass) < 6) {
            $message = '帳號不能空白，且密碼至少 6 個字元'; $msgType = 'error';
        } else {
            try {
                $hashedPass = password_hash($upass, PASSWORD_BCRYPT, ['cost' => 12]);
                $stmt = getDB()->prepare(
                    'INSERT INTO users (username, password_hash, role, employee_name)
                     VALUES (:username, :hash, :role, :emp)'
                );
                $stmt->execute([
                    ':username' => $uname,
                    ':hash'     => $hashedPass,
                    ':role'     => $urole,
                    ':emp'      => ($urole === 'staff' && $uempname !== '') ? $uempname : null,
                ]);
                $message = "✅ 帳號「{$uname}」已建立"; $msgType = 'success';
            } catch (PDOException $e) {
                $message = ($e->getCode() === '23000') ? "帳號「{$uname}」已存在" : '建立失敗：'.$e->getMessage();
                $msgType = 'error';
            }
        }

    // 修改帳號名稱
    } elseif ($action === 'rename_user') {
        $uid         = (int)($_POST['u_id']          ?? 0);
        $newUsername = trim($_POST['u_new_username'] ?? '');
        if (empty($newUsername)) {
            $message = '帳號名稱不能空白'; $msgType = 'error';
        } else {
            try {
                $stmt = getDB()->prepare('UPDATE users SET username = ? WHERE id = ?');
                $stmt->execute([$newUsername, $uid]);
                if ($uid === (int)($curUser['id'] ?? 0)) {
                    $_SESSION['user']['username'] = $newUsername;
                }
                $message = "✅ 帳號名稱已更新為「{$newUsername}」"; $msgType = 'success';
            } catch (PDOException $e) {
                $message = ($e->getCode() === '23000') ? "帳號「{$newUsername}」已存在" : '更新失敗：'.$e->getMessage();
                $msgType = 'error';
            }
        }

    // 修改密碼
    } elseif ($action === 'reset_password') {
        $uid   = (int)($_POST['u_id']     ?? 0);
        $upass = $_POST['u_new_password'] ?? '';
        if (strlen($upass) < 6) {
            $message = '新密碼至少需要 6 個字元'; $msgType = 'error';
        } else {
            $hashedPass = password_hash($upass, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = getDB()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $stmt->execute([$hashedPass, $uid]);
            $message = $stmt->rowCount() > 0 ? '✅ 密碼已修改' : '找不到該帳號';
            $msgType = $stmt->rowCount() > 0 ? 'success' : 'error';
        }

    // 刪除帳號
    } elseif ($action === 'delete_user') {
        $uid   = (int)($_POST['u_id']  ?? 0);
        $uname = $_POST['u_username']  ?? '';
        if ($uid === (int)($curUser['id'] ?? 0)) {
            $message = '不能刪除自己的帳號'; $msgType = 'error';
        } else {
            $stmt = getDB()->prepare('DELETE FROM users WHERE id = ?');
            $stmt->execute([$uid]);
            $message = $stmt->rowCount() > 0 ? "🗑️ 帳號「{$uname}」已刪除" : '找不到該帳號';
            $msgType = $stmt->rowCount() > 0 ? 'success' : 'error';
        }

    // ── 審核申請：通過 ──
    } elseif ($action === 'approve_request') {
        $reqId    = (int)($_POST['req_id'] ?? 0);
        $urole    = $_POST['req_role']     ?? 'staff';
        $uempname = trim($_POST['req_employee_name'] ?? '');
        $req = getDB()->prepare('SELECT * FROM account_requests WHERE id = ? AND status = "pending" LIMIT 1');
        $req->execute([$reqId]);
        $row = $req->fetch();
        if (!$row) {
            $message = '找不到該申請或已處理'; $msgType = 'error';
        } else {
            try {
                $ins = getDB()->prepare('INSERT INTO users (username, password_hash, role, employee_name) VALUES (?, ?, ?, ?)');
                $empLinkName = ($urole === 'staff' && $uempname !== '') ? $uempname : null;
                $ins->execute([$row['username'], $row['password_hash'], $urole, $empLinkName]);
                getDB()->prepare('UPDATE account_requests SET status="approved" WHERE id=?')->execute([$reqId]);
                // 自動帶入員工資料（身分證、電話）
                $overwriteConflict = null;
                if ($empLinkName) {
                    $empRow = getEmployee($empLinkName);
                    if ($empRow) {
                        $hasIdNum = !empty($empRow['id_number']);
                        $hasPhone = !empty($empRow['phone']);
                        $newIdNum = $row['id_number'];
                        $newPhone = $row['phone'];
                        if (($hasIdNum && $empRow['id_number'] !== $newIdNum) ||
                            ($hasPhone && $empRow['phone']     !== $newPhone)) {
                            // 有衝突，存入 session 等管理員確認
                            $_SESSION['emp_data_conflict'] = [
                                'emp_name'    => $empLinkName,
                                'old_id'      => $empRow['id_number'] ?? '',
                                'old_phone'   => $empRow['phone']     ?? '',
                                'new_id'      => $newIdNum,
                                'new_phone'   => $newPhone,
                            ];
                        } else {
                            // 無衝突，空值直接帶入
                            $updateFields = [];
                            if (!$hasIdNum && $newIdNum) $updateFields['id_number'] = $newIdNum;
                            if (!$hasPhone && $newPhone)  $updateFields['phone']     = $newPhone;
                            if ($updateFields) {
                                updateEmployee($empLinkName, array_merge([
                                    'type'            => $empRow['type'],
                                    'hourly_rate'     => $empRow['hourly_rate'],
                                    'night_allowance' => $empRow['night_allowance'] ?? 0,
                                    'hire_date'       => $empRow['hire_date'] ?? null,
                                ], $updateFields));
                            }
                        }
                    }
                }
                $message = "✅ 已核准帳號「{$row['username']}」"; $msgType = 'success';
            } catch (PDOException $e) {
                $message = ($e->getCode() === '23000') ? "帳號「{$row['username']}」已存在" : '核准失敗：'.$e->getMessage();
                $msgType = 'error';
            }
        }

    // ── 審核申請：拒絕 ──
    } elseif ($action === 'reject_request') {
        $reqId  = (int)($_POST['req_id'] ?? 0);
        $reason = trim($_POST['reject_reason'] ?? '');
        $req = getDB()->prepare('SELECT * FROM account_requests WHERE id = ? AND status = "pending" LIMIT 1');
        $req->execute([$reqId]);
        $row = $req->fetch();
        if (!$row) {
            $message = '找不到該申請或已處理'; $msgType = 'error';
        } else {
            try {
                $bl = getDB()->prepare('INSERT INTO account_blacklist (real_name, id_number, reason) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE rejected_at=NOW(), reason=VALUES(reason)');
                $bl->execute([$row['real_name'], $row['id_number'], $reason ?: null]);
                getDB()->prepare('UPDATE account_requests SET status="rejected", reject_reason=? WHERE id=?')->execute([$reason ?: null, $reqId]);
                $message = "🚫 已拒絕「{$row['real_name']}」的申請並加入黑名單"; $msgType = 'success';
            } catch (PDOException $e) {
                $message = '拒絕失敗：'.$e->getMessage(); $msgType = 'error';
            }
        }

    // ── 員工資料衝突覆蓋確認 ──
    } elseif ($action === 'resolve_conflict') {
        $empName  = $_POST['conflict_emp']   ?? '';
        $choice   = $_POST['conflict_choice'] ?? 'keep';
        if ($choice === 'overwrite' && $empName) {
            $empRow = getEmployee($empName);
            if ($empRow) {
                updateEmployee($empName, array_merge([
                    'type'            => $empRow['type'],
                    'hourly_rate'     => $empRow['hourly_rate'],
                    'night_allowance' => $empRow['night_allowance'] ?? 0,
                    'hire_date'       => $empRow['hire_date'] ?? null,
                ], [
                    'id_number' => $_POST['new_id']    ?? $empRow['id_number'],
                    'phone'     => $_POST['new_phone'] ?? $empRow['phone'],
                ]));
                $message = "✅ 已覆蓋「{$empName}」的身分證及電話資料"; $msgType = 'success';
            }
        } elseif ($choice === 'selective' && $empName) {
            $empRow = getEmployee($empName);
            if ($empRow) {
                $fields = $_POST['overwrite_fields'] ?? [];
                $update = [
                    'type'            => $empRow['type'],
                    'hourly_rate'     => $empRow['hourly_rate'],
                    'night_allowance' => $empRow['night_allowance'] ?? 0,
                    'hire_date'       => $empRow['hire_date'] ?? null,
                    'id_number'       => $empRow['id_number'],
                    'phone'           => $empRow['phone'],
                ];
                $labels = [];
                if (in_array('id_number', $fields)) {
                    $update['id_number'] = $_POST['new_id'] ?? $empRow['id_number'];
                    $labels[] = '身分證字號';
                }
                if (in_array('phone', $fields)) {
                    $update['phone'] = $_POST['new_phone'] ?? $empRow['phone'];
                    $labels[] = '連絡電話';
                }
                if ($labels) {
                    updateEmployee($empName, $update);
                    $message = "✅ 已更新「{$empName}」的" . implode('、', $labels); $msgType = 'success';
                } else {
                    $message = "未選擇任何欄位，已保留「{$empName}」的原有資料"; $msgType = 'success';
                }
            }
        } else {
            $message = "已保留「{$empName}」的原有資料"; $msgType = 'success';
        }
        unset($_SESSION['emp_data_conflict']);

    // ── 移除黑名單 ──
    } elseif ($action === 'remove_blacklist') {
        $blId = (int)($_POST['bl_id'] ?? 0);
        getDB()->prepare('DELETE FROM account_blacklist WHERE id=?')->execute([$blId]);
        $message = '✅ 已從黑名單移除'; $msgType = 'success';
    }
}

render_page:
// 確保資料表存在
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
getDB()->exec("CREATE TABLE IF NOT EXISTS account_blacklist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    real_name VARCHAR(60) NOT NULL,
    id_number VARCHAR(20) NOT NULL UNIQUE,
    rejected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reason VARCHAR(255) DEFAULT NULL
) DEFAULT CHARSET=utf8mb4");

$employees = getEmployees();

// 系統管理帳號可看全部；其他角色看不到 role='admin' 的帳號
if ($isSysAdmin) {
    $allUsers = getDB()->query(
        'SELECT id, username, role, employee_name, created_at FROM users ORDER BY id'
    )->fetchAll();
} else {
    $stmt = getDB()->prepare(
        "SELECT id, username, role, employee_name, created_at FROM users WHERE role != 'admin' ORDER BY id"
    );
    $stmt->execute();
    $allUsers = $stmt->fetchAll();
}

// 待審核申請
$pendingRequests = getDB()->query("SELECT * FROM account_requests WHERE status='pending' ORDER BY created_at ASC")->fetchAll();
$pendingCount    = count($pendingRequests);

// 黑名單
$blacklist = getDB()->query('SELECT * FROM account_blacklist ORDER BY rejected_at DESC')->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>帳號管理 — 薪資結算系統</title>
<link rel="stylesheet" href="responsive.css">
<style>
.main-wrap { max-width: 1400px; }


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
  color: var(--grey-900); font-family: var(--font-body); width: 100%;
}
.fg input:focus, .fg select:focus { outline: none; border-color: var(--green-600); }

.user-table { width: 100%; border-collapse: collapse; font-size: 0.88em; }
.user-table th {
  background: var(--green-50); color: var(--green-700);
  padding: 9px 12px; text-align: center;
  border: 1px solid #C8E6C9; font-weight: 600; white-space: nowrap;
}
.user-table td { padding: 8px 10px; border: 1px solid #eee; vertical-align: middle; text-align: center; white-space: nowrap; }
.user-table tr:nth-child(even) td { background: #FAFAFA; }

.inline-form { display: flex; gap: 6px; align-items: center; flex-wrap: nowrap; justify-content: center;}
.inline-form input[type="text"],
.inline-form input[type="password"] {
  padding: 6px 9px; border: 1.5px solid var(--grey-300);
  border-radius: 6px; font-size: 0.85em;
  font-family: var(--font-body); width: 130px;
}
.inline-form input:focus { outline: none; border-color: var(--green-600); }
.btn-add { background: var(--green-700); color: white; min-height: 40px; }

.req-table { width:100%; border-collapse:collapse; font-size:0.9em; }
.req-table th { background:var(--amber-100);color:#92400E;padding:9px 12px;text-align:center;border:1px solid #FDE68A;font-weight:600;white-space:nowrap; }
.req-table td { padding:8px 10px;border:1px solid #eee;vertical-align:middle;white-space:nowrap;text-align: center; }
.req-table tr:nth-child(even) td { background:#FFFBF0; }
.approve-form { display:flex;gap:6px;flex-wrap:nowrap;align-items:center; justify-content: center;}
.approve-form select,.approve-form input[type="text"] { padding:6px 8px;border:1.5px solid var(--grey-300);border-radius:6px;font-size:0.82em;font-family:var(--font-body); }
.reject-wrap { display:flex;gap:6px;align-items:center;flex-wrap:nowrap; }
.reject-wrap input[type="text"] { padding:6px 8px;border:1.5px solid var(--grey-300);border-radius:6px;font-size:0.82em;font-family:var(--font-body);width:110px; }
.pending-badge { display:inline-flex;align-items:center;justify-content:center;background:var(--red-500);color:white;font-size:0.75em;font-weight:700;border-radius:50%;width:20px;height:20px;margin-left:6px;line-height:1; }
.bl-table { width:100%;border-collapse:collapse;font-size:0.9em; }
.bl-table th { background:#FFF3F3;color:var(--red-600);padding:9px 12px;text-align:left;border:1px solid #FFCDD2;font-weight:600;white-space:nowrap; }
.bl-table td { padding:8px 10px;border:1px solid #eee;vertical-align:middle; }
</style>
</head>
<body>

<div class="topbar">
  <div class="topbar-inner">
    <span class="topbar-title">🔑 帳號管理</span>
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

<div class="main-wrap footer-pad" style="margin-top:14px">

  <?php if (!empty($message)): ?>
  <div class="msg msg-<?php echo $msgType === 'success' ? 'success' : 'error'; ?>" style="margin-bottom:14px">
    <?php echo htmlspecialchars($message); ?>
  </div>
  <?php endif; ?>

  <?php if (!empty($_SESSION['emp_data_conflict'])): ?>
  <?php $cf = $_SESSION['emp_data_conflict'];
        $conflictCount = (($cf['old_id'] !== $cf['new_id']) ? 1 : 0)
                       + (($cf['old_phone'] !== $cf['new_phone']) ? 1 : 0);
  ?>
  <div class="card" style="border:2px solid var(--amber-400);margin-bottom:14px">
    <div class="card-title" style="color:#92400E">⚠️ 員工資料衝突確認</div>
    <p style="font-size:0.9em;color:var(--grey-700);margin:0 0 12px">
      員工「<?php echo htmlspecialchars($cf['emp_name']); ?>」已有以下資料，與申請者填寫的內容不同，請確認是否覆蓋：
    </p>
    <table style="width:100%;border-collapse:collapse;font-size:0.9em;margin-bottom:14px">
      <thead><tr>
        <th style="padding:8px 12px;background:var(--amber-100);color:#92400E;border:1px solid #FDE68A;text-align:left">欄位</th>
        <th style="padding:8px 12px;background:var(--amber-100);color:#92400E;border:1px solid #FDE68A;text-align:left">現有資料</th>
        <th style="padding:8px 12px;background:var(--amber-100);color:#92400E;border:1px solid #FDE68A;text-align:left">申請者填寫</th>
        <?php if ($conflictCount > 1): ?>
        <th style="padding:8px 12px;background:var(--amber-100);color:#92400E;border:1px solid #FDE68A;text-align:center;white-space:nowrap">選擇覆蓋</th>
        <?php endif; ?>
      </tr></thead>
      <tbody>
        <?php if ($cf['old_id'] !== $cf['new_id']): ?>
        <tr>
          <td style="padding:8px 12px;border:1px solid #eee">身分證字號</td>
          <td style="padding:8px 12px;border:1px solid #eee;color:var(--grey-500)"><?php echo htmlspecialchars($cf['old_id']); ?></td>
          <td style="padding:8px 12px;border:1px solid #eee;font-weight:700;color:var(--green-700)"><?php echo htmlspecialchars($cf['new_id']); ?></td>
          <?php if ($conflictCount > 1): ?>
          <td style="padding:8px 12px;border:1px solid #eee;text-align:center">
            <input type="checkbox" name="sel_id_number" id="sel_id_number" value="1" style="width:16px;height:16px;cursor:pointer;accent-color:var(--green-700)">
          </td>
          <?php endif; ?>
        </tr>
        <?php endif; ?>
        <?php if ($cf['old_phone'] !== $cf['new_phone']): ?>
        <tr>
          <td style="padding:8px 12px;border:1px solid #eee">連絡電話</td>
          <td style="padding:8px 12px;border:1px solid #eee;color:var(--grey-500)"><?php echo htmlspecialchars($cf['old_phone']); ?></td>
          <td style="padding:8px 12px;border:1px solid #eee;font-weight:700;color:var(--green-700)"><?php echo htmlspecialchars($cf['new_phone']); ?></td>
          <?php if ($conflictCount > 1): ?>
          <td style="padding:8px 12px;border:1px solid #eee;text-align:center">
            <input type="checkbox" name="sel_phone" id="sel_phone" value="1" style="width:16px;height:16px;cursor:pointer;accent-color:var(--green-700)">
          </td>
          <?php endif; ?>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
      <!-- 全部覆蓋 -->
      <form method="post" style="margin:0">
        <input type="hidden" name="action"          value="resolve_conflict">
        <input type="hidden" name="conflict_emp"    value="<?php echo htmlspecialchars($cf['emp_name']); ?>">
        <input type="hidden" name="conflict_choice" value="overwrite">
        <input type="hidden" name="new_id"          value="<?php echo htmlspecialchars($cf['new_id']); ?>">
        <input type="hidden" name="new_phone"       value="<?php echo htmlspecialchars($cf['new_phone']); ?>">
        <button type="submit" class="btn btn-primary">✅ 覆蓋為申請者資料</button>
      </form>
      <!-- 保留全部 -->
      <form method="post" style="margin:0">
        <input type="hidden" name="action"          value="resolve_conflict">
        <input type="hidden" name="conflict_emp"    value="<?php echo htmlspecialchars($cf['emp_name']); ?>">
        <input type="hidden" name="conflict_choice" value="keep">
        <button type="submit" class="btn btn-secondary">保留現有資料</button>
      </form>
      <?php if ($conflictCount > 1): ?>
      <!-- 僅覆蓋勾選項目 -->
      <form method="post" id="selective-form" style="margin:0">
        <input type="hidden" name="action"          value="resolve_conflict">
        <input type="hidden" name="conflict_emp"    value="<?php echo htmlspecialchars($cf['emp_name']); ?>">
        <input type="hidden" name="conflict_choice" value="selective">
        <input type="hidden" name="new_id"          value="<?php echo htmlspecialchars($cf['new_id']); ?>">
        <input type="hidden" name="new_phone"       value="<?php echo htmlspecialchars($cf['new_phone']); ?>">
        <input type="hidden" name="overwrite_fields[]" id="sf-id-number" value="" disabled>
        <input type="hidden" name="overwrite_fields[]" id="sf-phone"     value="" disabled>
        <button type="submit" class="btn btn-ghost" onclick="return prepareSelective()">☑️ 僅覆蓋勾選項目</button>
      </form>
      <script>
      function prepareSelective() {
        const selId    = document.getElementById('sel_id_number');
        const selPhone = document.getElementById('sel_phone');
        const sfId     = document.getElementById('sf-id-number');
        const sfPhone  = document.getElementById('sf-phone');
        if (selId && selId.checked)    { sfId.value = 'id_number'; sfId.disabled = false; }
        if (selPhone && selPhone.checked) { sfPhone.value = 'phone'; sfPhone.disabled = false; }
        if (!sfId.value && !sfPhone.value) {
          alert('請先勾選至少一個要覆蓋的欄位');
          return false;
        }
        return true;
      }
      </script>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── 新增帳號 ── -->
  <div class="card">
    <div class="card-title">➕ 新增帳號</div>
    <form method="post">
      <input type="hidden" name="action" value="add_user">
      <div class="form-row">
        <div class="fg">
          <label>帳號</label>
          <input type="text" name="u_username" placeholder="登入帳號" required autocomplete="off">
        </div>
        <div class="fg">
          <label>密碼（至少6碼）</label>
          <input type="password" name="u_password" placeholder="••••••" required autocomplete="new-password">
        </div>
        <div class="fg">
          <label>角色</label>
          <select name="u_role" id="u-role-sel" onchange="toggleEmpSelect()">
            <?php if ($isSysAdmin): ?>
            <option value="admin">👑 系統管理</option>
            <?php endif; ?>
            <option value="goddess_plus">✨ 女神Plus</option>
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
          <button type="submit" class="btn btn-add">＋ 新增</button>
        </div>
      </div>
    </form>
  </div>

  <!-- ── 待審核申請 ── -->
  <div class="card" style="<?php echo $pendingCount > 0 ? 'border:2px solid var(--amber-400)' : ''; ?>">
    <div class="card-title" style="<?php echo $pendingCount > 0 ? 'color:#92400E' : ''; ?>">
      ⏳ 待審核帳號申請
      <?php if ($pendingCount > 0): ?>
      <span class="pending-badge"><?php echo $pendingCount; ?></span>
      <?php endif; ?>
    </div>
    <?php if ($pendingCount === 0): ?>
    <div style="text-align:center;padding:14px;color:var(--grey-400);font-size:0.9em">目前沒有待審核的申請</div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="req-table">
      <thead><tr>
        <th>申請時間</th><th>帳號</th><th>姓名</th><th>身分證字號</th><th>電話</th><th>審核通過</th><th>拒絕</th>
      </tr></thead>
      <tbody>
      <?php foreach ($pendingRequests as $req): ?>
      <tr>
        <td style="white-space:nowrap;font-size:0.85em;color:var(--grey-500)"><?php echo $req['created_at']; ?></td>
        <td><strong><?php echo htmlspecialchars($req['username']); ?></strong></td>
        <td><?php echo htmlspecialchars($req['real_name']); ?></td>
        <td style="font-family:var(--font-num)"><?php echo htmlspecialchars($req['id_number']); ?></td>
        <td><?php echo htmlspecialchars($req['phone']); ?></td>
        <td>
          <form method="post" class="approve-form"
                onsubmit="return confirm('確定核准「<?php echo htmlspecialchars($req['username']); ?>」的申請？')">
            <input type="hidden" name="action" value="approve_request">
            <input type="hidden" name="req_id" value="<?php echo $req['id']; ?>">
            <select name="req_role" id="req-role-<?php echo $req['id']; ?>"
                    onchange="toggleReqEmp(<?php echo $req['id']; ?>)">
              <?php if ($isSysAdmin): ?><option value="admin">👑 系統管理</option><?php endif; ?>
              <option value="goddess_plus">✨ 女神Plus</option>
              <option value="staff" selected>👤 員工</option>
            </select>
            <select name="req_employee_name" id="req-emp-<?php echo $req['id']; ?>">
              <option value="">— 對應員工 —</option>
              <?php foreach ($employees as $e): ?>
              <option value="<?php echo htmlspecialchars($e['name']); ?>"
                <?php echo ($e['name'] === $req['real_name']) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($e['name']); ?>
              </option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary btn-sm">✅ 通過</button>
          </form>
        </td>
        <td>
          <form method="post" class="reject-wrap"
                onsubmit="return confirm('確定拒絕「<?php echo htmlspecialchars($req['real_name']); ?>」並加入黑名單？')">
            <input type="hidden" name="action" value="reject_request">
            <input type="hidden" name="req_id" value="<?php echo $req['id']; ?>">
            <input type="text" name="reject_reason" placeholder="原因（選填）">
            <button type="submit" class="btn btn-danger btn-sm">🚫 拒絕</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── 黑名單 ── -->
  <?php if (!empty($blacklist)): ?>
  <div class="card">
    <div class="card-title" style="color:var(--red-600)">🚫 拒絕黑名單（共 <?php echo count($blacklist); ?> 筆）</div>
    <div style="overflow-x:auto">
    <table class="bl-table">
      <thead><tr><th>姓名</th><th>身分證字號</th><th>拒絕時間</th><th>原因</th><th>操作</th></tr></thead>
      <tbody>
      <?php foreach ($blacklist as $bl): ?>
      <tr>
        <td><?php echo htmlspecialchars($bl['real_name']); ?></td>
        <td style="font-family:var(--font-num)"><?php echo htmlspecialchars($bl['id_number']); ?></td>
        <td style="font-size:0.85em;color:var(--grey-500);white-space:nowrap"><?php echo $bl['rejected_at']; ?></td>
        <td style="font-size:0.85em;color:var(--grey-600)"><?php echo $bl['reason'] ? htmlspecialchars($bl['reason']) : '—'; ?></td>
        <td>
          <form method="post" style="margin:0"
                onsubmit="return confirm('確定從黑名單移除「<?php echo htmlspecialchars($bl['real_name']); ?>」？')">
            <input type="hidden" name="action" value="remove_blacklist">
            <input type="hidden" name="bl_id"  value="<?php echo $bl['id']; ?>">
            <button type="submit" class="btn btn-ghost btn-sm">🗑️ 移除</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── 現有帳號列表 ── -->
  <div class="card">
    <div class="card-title">📋 現有帳號（共 <?php echo count($allUsers); ?> 個）</div>

    <div class="search-wrap" style="margin-bottom:14px">
      <span class="search-icon">🔍</span>
      <input type="text" id="user-search" class="search-input"
             placeholder="輸入帳號或員工姓名搜尋..."
             oninput="filterUsers(this.value)">
    </div>

    <?php if (empty($allUsers)): ?>
    <div style="text-align:center;padding:28px;color:var(--grey-500)">尚未建立任何帳號</div>
    <?php else: ?>

    <div id="no-user-msg" style="display:none;text-align:center;padding:20px;color:var(--grey-500)">
      找不到符合的帳號
    </div>

    <div style="overflow-x:auto">
    <table class="user-table">
      <thead>
        <tr>
          <th>帳號</th>
          <th>角色</th>
          <th>對應員工</th>
          <th>建立時間</th>
          <th>修改帳號名稱</th>
          <th>修改密碼</th>
          <th>操作</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($allUsers as $u):
        $isSelf  = (int)$u['id'] === (int)($curUser['id'] ?? 0);
        $locked  = ($u['role'] === 'admin') && !$isSysAdmin; // 非系統管理者看到此列時鎖定（正常不應出現，雙重保護）
      ?>
      <tr class="user-row"
          data-username="<?php echo strtolower(htmlspecialchars($u['username'])); ?>"
          data-empname="<?php echo strtolower(htmlspecialchars($u['employee_name'] ?? '')); ?>">
        <td>
          <strong><?php echo htmlspecialchars($u['username']); ?></strong>
          <?php if ($isSelf): ?>
          <span style="font-size:0.75em;color:var(--grey-500)">（自己）</span>
          <?php endif; ?>
        </td>
        <td>
          <span class="badge badge-<?php echo $u['role']==='admin'?'fulltime':($u['role']==='goddess_plus'?'fulltime':'hourly'); ?>" style="white-space:nowrap">
            <?php
              if ($u['role'] === 'admin') echo '👑 系統管理';
              elseif ($u['role'] === 'goddess_plus') echo '✨ 女神Plus';
              else echo '👤 員工';
            ?>
          </span>
        </td>
        <td style="color:var(--grey-<?php echo $u['employee_name']?'900':'300'; ?>)">
          <?php echo $u['employee_name'] ? htmlspecialchars($u['employee_name']) : '—'; ?>
        </td>
        <td style="color:var(--grey-700);font-size:0.88em;white-space:nowrap">
          <?php echo $u['created_at']; ?>
        </td>
        <td>
          <?php if ($locked): ?>
          <span style="font-size:0.8em;color:var(--grey-300)">🔒 無權限</span>
          <?php else: ?>
          <form method="post" class="inline-form">
            <input type="hidden" name="action" value="rename_user">
            <input type="hidden" name="u_id"   value="<?php echo $u['id']; ?>">
            <input type="text" name="u_new_username"
                   value="<?php echo htmlspecialchars($u['username']); ?>"
                   required autocomplete="off">
            <button type="submit" class="btn btn-ghost btn-sm">✏️ 修改</button>
          </form>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($locked): ?>
          <span style="font-size:0.8em;color:var(--grey-300)">🔒 無權限</span>
          <?php else: ?>
          <form method="post" class="inline-form">
            <input type="hidden" name="action"     value="reset_password">
            <input type="hidden" name="u_id"       value="<?php echo $u['id']; ?>">
            <input type="hidden" name="u_username" value="<?php echo htmlspecialchars($u['username']); ?>">
            <input type="password" name="u_new_password"
                   placeholder="新密碼" minlength="6" required autocomplete="new-password">
            <button type="submit" class="btn btn-ghost btn-sm">🔑 修改</button>
          </form>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($isSelf): ?>
          <span style="font-size:0.8em;color:var(--grey-300)">無法刪除</span>
          <?php elseif ($locked): ?>
          <span style="font-size:0.8em;color:var(--grey-300)">🔒 無權限</span>
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

</div>

<script>
function toggleNav(btn) {
  const nav = document.getElementById('topbar-nav');
  nav.classList.toggle('open');
  btn.setAttribute('aria-expanded', nav.classList.contains('open'));
}

function toggleEmpSelect() {
  const role = document.getElementById('u-role-sel').value;
  document.getElementById('u-emp-field').style.display = role === 'staff' ? '' : 'none';
}
toggleEmpSelect();

function toggleReqEmp(reqId) {
  const sel = document.getElementById('req-role-' + reqId);
  const emp = document.getElementById('req-emp-'  + reqId);
  if (!sel || !emp) return;
  emp.style.display = sel.value === 'staff' ? '' : 'none';
}
document.querySelectorAll('[id^="req-role-"]').forEach(sel => {
  toggleReqEmp(sel.id.replace('req-role-', ''));
});

function filterUsers(kw) {
  const k = kw.trim().toLowerCase();
  const rows = document.querySelectorAll('.user-row');
  let visible = 0;
  rows.forEach(row => {
    const match = row.dataset.username.includes(k) || row.dataset.empname.includes(k);
    row.style.display = match ? '' : 'none';
    if (match) visible++;
  });
  const noMsg = document.getElementById('no-user-msg');
  if (noMsg) noMsg.style.display = visible === 0 ? '' : 'none';
}
</script>
</body>
</html>