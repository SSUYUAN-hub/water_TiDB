<?php
/**
 * 一次性密碼重設工具（bcrypt 版）
 * 用途：將現有明文密碼的帳號升級為 bcrypt hash
 * 用完後請立即刪除此檔案！
 */
require_once __DIR__ . '/vendor/autoload.php';
include_once __DIR__ . '/db.php';

// ── 設定：填入要重設的帳號和新密碼 ──
// 格式：'帳號' => '新密碼'
$resetList = [
    // 'admin'  => '你的新密碼',
    // 'staff1' => '員工新密碼',
];

$message = '';
$done    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['accounts'])) {
    foreach ($_POST['accounts'] as $uid => $newpass) {
        $uid     = (int)$uid;
        $newpass = trim($newpass);
        if ($uid <= 0 || strlen($newpass) < 6) continue;
        $hash = password_hash($newpass, PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt = getDB()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([$hash, $uid]);
        if ($stmt->rowCount() > 0) $done[] = $uid;
    }
    $message = count($done) > 0
        ? '✅ 已成功更新 ' . count($done) . ' 個帳號的密碼（bcrypt）'
        : '⚠️ 沒有帳號被更新，請確認密碼至少 6 個字元';
}

$allUsers = getDB()->query('SELECT id, username, role FROM users ORDER BY id')->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>一次性密碼重設工具</title>
<style>
* { box-sizing: border-box; }
body { font-family: sans-serif; background: #f5f5f5; padding: 20px; }
.card { background: white; border-radius: 10px; padding: 24px; max-width: 500px; margin: auto; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
.warn { background: #FFF3E0; border-left: 4px solid #FF9800; padding: 10px 14px; border-radius: 4px; margin-bottom: 16px; font-size: 0.88em; }
.ok   { background: #E8F5E9; border-left: 4px solid #4CAF50; padding: 10px 14px; border-radius: 4px; margin-bottom: 16px; }
table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
th, td { padding: 9px 10px; border: 1px solid #ddd; font-size: 0.88em; }
th { background: #f5f5f5; }
input[type="password"] { width: 100%; padding: 7px 9px; border: 1px solid #ddd; border-radius: 5px; font-size: 0.88em; }
button { width: 100%; padding: 12px; background: #1B5E20; color: white; border: none; border-radius: 6px; font-size: 1em; font-weight: bold; cursor: pointer; }
button:hover { background: #2E7D32; }
</style>
</head>
<body>
<div class="card">
  <h2 style="margin-top:0;color:#C62828">🔑 一次性密碼重設（bcrypt）</h2>
  <div class="warn">
    ⚠️ <strong>安全警告：</strong>此頁面無需登入即可存取。<br>
    重設完成後請立即刪除 <code>reset_pw_once.php</code>！
  </div>

  <?php if ($message): ?>
  <div class="ok"><?php echo htmlspecialchars($message); ?></div>
  <?php endif; ?>

  <form method="post">
    <table>
      <thead>
        <tr><th>ID</th><th>帳號</th><th>角色</th><th>新密碼（至少6碼）</th></tr>
      </thead>
      <tbody>
      <?php foreach ($allUsers as $u): ?>
      <tr>
        <td><?php echo $u['id']; ?></td>
        <td><strong><?php echo htmlspecialchars($u['username']); ?></strong></td>
        <td><?php echo $u['role'] === 'admin' ? '👑 管理員' : '👤 員工'; ?></td>
        <td><input type="password" name="accounts[<?php echo $u['id']; ?>]" placeholder="留空則不修改" autocomplete="new-password"></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <button type="submit">🔐 套用 bcrypt 重設密碼</button>
  </form>
</div>
</body>
</html>
