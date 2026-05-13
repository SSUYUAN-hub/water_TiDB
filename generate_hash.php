<?php
/**
 * 一次性工具：建立管理員帳號
 * 使用完畢請立即刪除此檔案！
 */
require_once __DIR__ . '/vendor/autoload.php';
include_once __DIR__ . '/db.php';

$message = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role     = $_POST['role'] ?? 'admin';
    $empName  = trim($_POST['employee_name'] ?? '');

    if (empty($username) || empty($password)) {
        $message = '帳號和密碼不能空白';
        $msgType = 'error';
    } elseif (strlen($password) < 6) {
        $message = '密碼至少需要 6 個字元';
        $msgType = 'error';
    } else {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        try {
            $stmt = getDB()->prepare(
                'INSERT INTO users (username, password_hash, role, employee_name)
                 VALUES (:username, :password_hash, :role, :employee_name)'
            );
            $stmt->execute([
                ':username'      => $username,
                ':password_hash' => $hash,
                ':role'          => $role,
                ':employee_name' => $role === 'staff' ? ($empName ?: $username) : null,
            ]);
            $message = "✅ 帳號「{$username}」建立成功！請立即刪除此檔案。";
            $msgType = 'success';
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $message = "帳號「{$username}」已存在";
            } else {
                $message = '建立失敗：' . $e->getMessage();
            }
            $msgType = 'error';
        }
    }
}

$employees = getEmployees();
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>建立帳號（一次性工具）</title>
<link rel="stylesheet" href="responsive.css">
</head>
<body>
<div class="topbar">
  <span class="topbar-title">🔑 建立登入帳號（使用後請刪除此頁）</span>
</div>
<div class="main-wrap footer-pad" style="max-width:480px;margin-top:20px">

  <div class="msg msg-error">
    ⚠️ <strong>安全警告：</strong>此頁面無需登入即可存取，建立完帳號後請立即刪除 <code>generate_hash.php</code>！
  </div>

  <?php if (!empty($message)): ?>
  <div class="msg msg-<?php echo $msgType; ?>"><?php echo htmlspecialchars($message); ?></div>
  <?php endif; ?>

  <div class="card">
    <div class="card-title">➕ 建立新帳號</div>
    <form method="post">
      <div class="form-group">
        <label class="form-label">帳號</label>
        <input type="text" name="username" class="form-input"
               placeholder="管理員輸入自訂帳號，員工輸入姓名" required>
      </div>
      <div class="form-group">
        <label class="form-label">密碼（至少 6 字元）</label>
        <input type="password" name="password" class="form-input" required>
      </div>
      <div class="form-group">
        <label class="form-label">角色</label>
        <select name="role" class="form-select" id="role-sel" onchange="toggleEmpField()">
          <option value="admin">👑 管理員（admin）</option>
          <option value="staff">👤 員工（staff）</option>
        </select>
      </div>
      <div class="form-group" id="emp-field" style="display:none">
        <label class="form-label">對應員工（帳號為員工姓名時自動對應）</label>
        <select name="employee_name" class="form-select">
          <option value="">— 與帳號名稱相同 —</option>
          <?php foreach ($employees as $e): ?>
          <option value="<?php echo htmlspecialchars($e['name']); ?>">
            <?php echo htmlspecialchars($e['name']); ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-primary btn-full">建立帳號</button>
    </form>
  </div>

  <!-- 現有帳號列表 -->
  <?php
  $allUsers = getDB()->query('SELECT id, username, role, employee_name, created_at FROM users ORDER BY id')->fetchAll();
  if (!empty($allUsers)):
  ?>
  <div class="card">
    <div class="card-title">📋 現有帳號</div>
    <table style="width:100%;border-collapse:collapse;font-size:0.85em">
      <thead>
        <tr style="background:var(--green-50)">
          <th style="padding:8px;border:1px solid #C8E6C9;text-align:left">帳號</th>
          <th style="padding:8px;border:1px solid #C8E6C9">角色</th>
          <th style="padding:8px;border:1px solid #C8E6C9">對應員工</th>
          <th style="padding:8px;border:1px solid #C8E6C9">建立時間</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($allUsers as $u): ?>
      <tr>
        <td style="padding:7px 8px;border:1px solid #eee;font-weight:600"><?php echo htmlspecialchars($u['username']); ?></td>
        <td style="padding:7px 8px;border:1px solid #eee;text-align:center">
          <span class="badge badge-<?php echo $u['role']==='admin'?'fulltime':'hourly'; ?>">
            <?php echo $u['role']==='admin'?'管理員':'員工'; ?>
          </span>
        </td>
        <td style="padding:7px 8px;border:1px solid #eee;text-align:center"><?php echo htmlspecialchars($u['employee_name'] ?? '—'); ?></td>
        <td style="padding:7px 8px;border:1px solid #eee;text-align:center;color:var(--grey-500)"><?php echo $u['created_at']; ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<script>
function toggleEmpField() {
  const role = document.getElementById('role-sel').value;
  document.getElementById('emp-field').style.display = role === 'staff' ? '' : 'none';
}
</script>
</body>
</html>
