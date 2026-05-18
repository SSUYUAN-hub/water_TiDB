<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/vendor/autoload.php';
include_once __DIR__ . '/auth.php';
requireAdmin();

$message = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

<<<<<<< HEAD
    // 新增帳號
    if ($action === 'add_user') {
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

    // 修改帳號名稱
    } elseif ($action === 'rename_user') {
=======
    // 修改帳號名稱
    if ($action === 'rename_user') {
>>>>>>> edcc9b1f7dbae475883ac1e36defaee693a1f960
        $uid         = (int)($_POST['u_id']          ?? 0);
        $newUsername = trim($_POST['u_new_username'] ?? '');
        $curUser     = currentUser();
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
<<<<<<< HEAD
        $uid   = (int)($_POST['u_id']       ?? 0);
        $upass = $_POST['u_new_password']   ?? '';
=======
        $uid   = (int)($_POST['u_id']          ?? 0);
        $upass = $_POST['u_new_password']       ?? '';
>>>>>>> edcc9b1f7dbae475883ac1e36defaee693a1f960
        if (strlen($upass) < 6) {
            $message = '新密碼至少需要 6 個字元'; $msgType = 'error';
        } else {
            $stmt = getDB()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $stmt->execute([$upass, $uid]);
            $message = $stmt->rowCount() > 0 ? '✅ 密碼已修改' : '找不到該帳號';
            $msgType = $stmt->rowCount() > 0 ? 'success' : 'error';
        }
<<<<<<< HEAD

    // 刪除帳號
    } elseif ($action === 'delete_user') {
        $uid     = (int)($_POST['u_id']      ?? 0);
        $uname   = $_POST['u_username']       ?? '';
        $curUser = currentUser();
        if ($uid === (int)($curUser['id'] ?? 0)) {
            $message = '不能刪除自己的帳號'; $msgType = 'error';
        } else {
            $stmt = getDB()->prepare('DELETE FROM users WHERE id = ?');
            $stmt->execute([$uid]);
            $message = $stmt->rowCount() > 0 ? "🗑️ 帳號「{$uname}」已刪除" : '找不到該帳號';
            $msgType = $stmt->rowCount() > 0 ? 'success' : 'error';
        }
    }
}

$employees = getEmployees();
$allUsers  = getDB()->query(
=======
    }
}

$allUsers = getDB()->query(
>>>>>>> edcc9b1f7dbae475883ac1e36defaee693a1f960
    'SELECT id, username, role, employee_name, created_at FROM users ORDER BY id'
)->fetchAll();
$curUser = currentUser();
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>帳號管理 — 薪資結算系統</title>
<link rel="stylesheet" href="responsive.css">
<style>
.main-wrap { max-width: 1000px; }

<<<<<<< HEAD
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
  padding: 9px 12px; text-align: left;
  border: 1px solid #C8E6C9; font-weight: 600; white-space: nowrap;
}
.user-table td { padding: 8px 12px; border: 1px solid #eee; vertical-align: middle; }
.user-table tr:nth-child(even) td { background: #FAFAFA; }

.inline-form { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
.inline-form input[type="text"],
.inline-form input[type="password"] {
  padding: 6px 9px; border: 1.5px solid var(--grey-300);
  border-radius: 6px; font-size: 0.85em;
  font-family: var(--font-body); width: 130px;
}
.inline-form input:focus { outline: none; border-color: var(--green-600); }
.btn-add { background: var(--green-700); color: white; min-height: 40px; }
=======
/* 帳號卡片 grid */
.account-grid {
  display: grid;
  gap: 12px;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
}
@media (max-width: 480px)  { .account-grid { grid-template-columns: 1fr; } }
@media (min-width: 600px)  { .account-grid { grid-template-columns: repeat(2, 1fr); } }
@media (min-width: 900px)  { .account-grid { grid-template-columns: repeat(3, 1fr); } }

/* 帳號卡片 */
.account-card {
  border: 1.5px solid #E0E0E0;
  border-radius: var(--radius-md);
  padding: 16px;
  background: var(--white);
  transition: box-shadow var(--transition);
}
.account-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.1); }
.account-card-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 12px;
}
.account-card-name {
  font-size: 1em;
  font-weight: 700;
  color: var(--grey-900);
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}
.self-tag {
  font-size: 0.72em;
  background: var(--green-100);
  color: var(--green-700);
  padding: 2px 7px;
  border-radius: 8px;
}
.account-card-divider {
  border: none;
  border-top: 1px dashed #eee;
  margin: 10px 0;
}

/* 表單列 */
.field-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 8px;
}
.field-row label {
  font-size: 0.75em;
  color: var(--grey-500);
  font-weight: 600;
  white-space: nowrap;
  min-width: 52px;
}
.field-row input {
  flex: 1;
  padding: 8px 10px;
  border: 1.5px solid var(--grey-300);
  border-radius: var(--radius-sm);
  font-size: 0.88em;
  font-family: var(--font-body);
  color: var(--grey-900);
  background: white;
}
.field-row input:focus {
  outline: none;
  border-color: var(--green-600);
  box-shadow: 0 0 0 3px rgba(46,125,50,0.1);
}

.empty-state {
  text-align: center;
  padding: 28px;
  color: var(--grey-500);
  font-size: 0.9em;
}
>>>>>>> edcc9b1f7dbae475883ac1e36defaee693a1f960
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
        👑 <?php echo htmlspecialchars($curUser['username'] ?? ''); ?>
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

<<<<<<< HEAD
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
          <button type="submit" class="btn btn-add">＋ 新增</button>
        </div>
      </div>
    </form>
  </div>

  <!-- ── 現有帳號列表 ── -->
  <div class="card">
    <div class="card-title">📋 現有帳號（共 <?php echo count($allUsers); ?> 個）</div>

    <div class="search-wrap" style="margin-bottom:14px">
=======
  <div class="card">
    <div class="card-title">🔑 帳號密碼管理</div>

    <!-- 搜尋框 -->
    <div class="search-wrap" style="margin-bottom:16px">
>>>>>>> edcc9b1f7dbae475883ac1e36defaee693a1f960
      <span class="search-icon">🔍</span>
      <input type="text" id="user-search" class="search-input"
             placeholder="輸入帳號或員工姓名搜尋..."
             oninput="filterUsers(this.value)">
    </div>

    <?php if (empty($allUsers)): ?>
<<<<<<< HEAD
    <div style="text-align:center;padding:28px;color:var(--grey-500)">尚未建立任何帳號</div>
=======
    <div class="empty-state">尚未建立任何帳號</div>
>>>>>>> edcc9b1f7dbae475883ac1e36defaee693a1f960
    <?php else: ?>

    <div id="no-user-msg" style="display:none;text-align:center;padding:20px;color:var(--grey-500)">
      找不到符合的帳號
    </div>

<<<<<<< HEAD
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
        $isSelf = (int)$u['id'] === (int)($curUser['id'] ?? 0);
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
          <span class="badge badge-<?php echo $u['role']==='admin'?'fulltime':'hourly'; ?>">
            <?php echo $u['role']==='admin'?'👑 管理員':'👤 員工'; ?>
          </span>
        </td>
        <td style="color:var(--grey-<?php echo $u['employee_name']?'900':'300'; ?>)">
          <?php echo $u['employee_name'] ? htmlspecialchars($u['employee_name']) : '—'; ?>
        </td>
        <td style="color:var(--grey-500);font-size:0.85em;white-space:nowrap">
          <?php echo $u['created_at']; ?>
        </td>
        <td>
          <form method="post" class="inline-form">
            <input type="hidden" name="action" value="rename_user">
            <input type="hidden" name="u_id"   value="<?php echo $u['id']; ?>">
            <input type="text" name="u_new_username"
                   value="<?php echo htmlspecialchars($u['username']); ?>"
                   required autocomplete="off">
            <button type="submit" class="btn btn-ghost btn-sm">✏️ 修改</button>
          </form>
        </td>
        <td>
          <form method="post" class="inline-form">
            <input type="hidden" name="action"     value="reset_password">
            <input type="hidden" name="u_id"       value="<?php echo $u['id']; ?>">
            <input type="hidden" name="u_username" value="<?php echo htmlspecialchars($u['username']); ?>">
            <input type="password" name="u_new_password"
                   placeholder="新密碼" minlength="6" required autocomplete="new-password">
            <button type="submit" class="btn btn-ghost btn-sm">🔑 修改</button>
          </form>
        </td>
        <td>
          <?php if ($isSelf): ?>
          <span style="font-size:0.8em;color:var(--grey-300)">無法刪除</span>
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
=======
    <div class="account-grid">
    <?php foreach ($allUsers as $u):
        $isSelf = (int)$u['id'] === (int)($curUser['id'] ?? 0);
    ?>
    <div class="account-card"
         data-username="<?php echo strtolower(htmlspecialchars($u['username'])); ?>"
         data-empname="<?php echo strtolower(htmlspecialchars($u['employee_name'] ?? '')); ?>">

      <!-- 卡片標題 -->
      <div class="account-card-header">
        <div>
          <div class="account-card-name">
            <?php echo htmlspecialchars($u['username']); ?>
            <?php if ($isSelf): ?>
            <span class="self-tag">自己</span>
            <?php endif; ?>
          </div>
          <div style="margin-top:5px">
            <span class="badge badge-<?php echo $u['role']==='admin'?'fulltime':'hourly'; ?>">
              <?php echo $u['role']==='admin'?'👑 管理員':'👤 員工'; ?>
            </span>
          </div>
        </div>
        <div style="text-align:right;font-size:0.78em;color:var(--grey-500);line-height:1.6">
          <?php echo $u['employee_name'] ? htmlspecialchars($u['employee_name']) : '—'; ?><br>
          <span style="color:var(--grey-300)"><?php echo $u['created_at']; ?></span>
        </div>
      </div>

      <hr class="account-card-divider">

      <!-- 修改帳號名稱 -->
      <form method="post" style="margin-bottom:10px">
        <input type="hidden" name="action" value="rename_user">
        <input type="hidden" name="u_id"   value="<?php echo $u['id']; ?>">
        <div class="field-row">
          <label>帳號</label>
          <input type="text" name="u_new_username"
                 value="<?php echo htmlspecialchars($u['username']); ?>"
                 required autocomplete="off">
        </div>
        <button type="submit" class="btn btn-ghost btn-sm btn-full">✏️ 修改帳號名稱</button>
      </form>

      <!-- 修改密碼 -->
      <form method="post">
        <input type="hidden" name="action"     value="reset_password">
        <input type="hidden" name="u_id"       value="<?php echo $u['id']; ?>">
        <div class="field-row">
          <label>新密碼</label>
          <input type="password" name="u_new_password"
                 placeholder="至少 6 碼" minlength="6" required autocomplete="new-password">
        </div>
        <button type="submit" class="btn btn-blue btn-sm btn-full">🔑 修改密碼</button>
      </form>

    </div>
    <?php endforeach; ?>
>>>>>>> edcc9b1f7dbae475883ac1e36defaee693a1f960
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

<<<<<<< HEAD
function toggleEmpSelect() {
  const role = document.getElementById('u-role-sel').value;
  document.getElementById('u-emp-field').style.display = role === 'staff' ? '' : 'none';
}
toggleEmpSelect();

function filterUsers(kw) {
  const k = kw.trim().toLowerCase();
  const rows = document.querySelectorAll('.user-row');
  let visible = 0;
  rows.forEach(row => {
    const match = row.dataset.username.includes(k) || row.dataset.empname.includes(k);
    row.style.display = match ? '' : 'none';
=======
function filterUsers(kw) {
  const k = kw.trim().toLowerCase();
  const cards = document.querySelectorAll('.account-card');
  let visible = 0;
  cards.forEach(card => {
    const match = card.dataset.username.includes(k) || card.dataset.empname.includes(k);
    card.style.display = match ? '' : 'none';
>>>>>>> edcc9b1f7dbae475883ac1e36defaee693a1f960
    if (match) visible++;
  });
  const noMsg = document.getElementById('no-user-msg');
  if (noMsg) noMsg.style.display = visible === 0 ? '' : 'none';
}
</script>
</body>
</html>
