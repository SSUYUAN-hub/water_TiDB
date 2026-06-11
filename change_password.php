<?php
require_once __DIR__ . '/db.php';
include_once __DIR__ . '/auth.php';
requireLogin();

$user    = currentUser();
$message = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $oldPass  = $_POST['old_password']  ?? '';
    $newPass  = $_POST['new_password']  ?? '';
    $newPass2 = $_POST['new_password2'] ?? '';

    if (empty($oldPass) || empty($newPass) || empty($newPass2)) {
        $message = '請填寫所有欄位'; $msgType = 'error';
    } elseif ($newPass !== $newPass2) {
        $message = '新密碼兩次輸入不一致'; $msgType = 'error';
    } elseif (strlen($newPass) < 6) {
        $message = '新密碼至少需要 6 個字元'; $msgType = 'error';
    } else {
        $stmt = getDB()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($oldPass, $row['password_hash'])) {
            $message = '目前密碼輸入錯誤'; $msgType = 'error';
        } else {
            $hashedNew = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
            $upd = getDB()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $upd->execute([$hashedNew, $user['id']]);
            $message = '✅ 密碼已成功修改'; $msgType = 'success';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>修改密碼 — 薪資結算系統</title>
<link rel="stylesheet" href="responsive.css">
<style>
body { background: linear-gradient(135deg, #e8f5e9 0%, #f1f8e9 40%, #e3f2fd 100%); min-height:100vh; }
.main-wrap { max-width: 480px; }
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
.pw-field { margin-bottom:16px; }
.pw-field label { display:block; font-size:0.82em; font-weight:600;
  color:var(--grey-600); margin-bottom:6px; }
.pw-input { width:100%; padding:12px 14px; border:1.5px solid var(--grey-300);
  border-radius:var(--radius-sm); font-size:0.97em; font-family:var(--font-body);
  color:var(--grey-900); background:white; box-sizing:border-box;
  transition:border-color var(--transition); }
.pw-input:focus { outline:none; border-color:var(--green-600); box-shadow:0 0 0 3px rgba(46,125,50,0.1); }
</style>
</head>
<body>
<div class="topbar">
  <div class="topbar-inner">
    <span class="topbar-title">🔐 修改密碼</span>
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

<div class="main-wrap footer-pad" style="margin-top:24px">

  <?php if (!empty($message)): ?>
  <div class="msg msg-<?php echo $msgType === 'success' ? 'success' : 'error'; ?>" style="margin-bottom:14px">
    <?php echo htmlspecialchars($message); ?>
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-title">🔐 修改登入密碼</div>
    <form method="post" onsubmit="return validateForm()">
      <div class="pw-field">
        <label>目前密碼</label>
        <input type="password" name="old_password" class="pw-input"
               placeholder="輸入目前密碼" required autocomplete="current-password">
      </div>
      <div class="pw-field">
        <label>新密碼（至少 6 個字元）</label>
        <input type="password" name="new_password" id="pw-new" class="pw-input"
               placeholder="輸入新密碼" minlength="6" required autocomplete="new-password">
      </div>
      <div class="pw-field">
        <label>確認新密碼</label>
        <input type="password" name="new_password2" id="pw-new2" class="pw-input"
               placeholder="再次輸入新密碼" minlength="6" required autocomplete="new-password">
      </div>
      <div style="display:flex;gap:10px;margin-top:4px">
        <button type="submit" class="btn btn-primary" style="flex:1">🔐 確認修改密碼</button>
        <a href="index.php" class="btn btn-secondary">取消</a>
      </div>
    </form>
  </div>

</div>

<script>
function toggleNav(btn) {
  const nav = document.getElementById('topbar-nav');
  nav.classList.toggle('open');
  btn.setAttribute('aria-expanded', nav.classList.contains('open'));
}
function validateForm() {
  const n1 = document.getElementById('pw-new').value;
  const n2 = document.getElementById('pw-new2').value;
  if (n1 !== n2) { alert('新密碼兩次輸入不一致'); return false; }
  if (n1.length < 6) { alert('新密碼至少需要 6 個字元'); return false; }
  return true;
}
</script>
</body>
</html>
