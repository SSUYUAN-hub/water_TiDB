<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/vendor/autoload.php';
include_once __DIR__ . '/auth.php';
requireAdmin();

$message = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 修改帳號名稱
    if ($action === 'rename_user') {
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
        $uid   = (int)($_POST['u_id']          ?? 0);
        $upass = $_POST['u_new_password']       ?? '';
        if (strlen($upass) < 6) {
            $message = '新密碼至少需要 6 個字元'; $msgType = 'error';
        } else {
            $stmt = getDB()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $stmt->execute([$upass, $uid]);
            $message = $stmt->rowCount() > 0 ? '✅ 密碼已修改' : '找不到該帳號';
            $msgType = $stmt->rowCount() > 0 ? 'success' : 'error';
        }
    }
}

$allUsers = getDB()->query(
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

  <div class="card">
    <div class="card-title">🔑 帳號密碼管理</div>

    <!-- 搜尋框 -->
    <div class="search-wrap" style="margin-bottom:16px">
      <span class="search-icon">🔍</span>
      <input type="text" id="user-search" class="search-input"
             placeholder="輸入帳號或員工姓名搜尋..."
             oninput="filterUsers(this.value)">
    </div>

    <?php if (empty($allUsers)): ?>
    <div class="empty-state">尚未建立任何帳號</div>
    <?php else: ?>

    <div id="no-user-msg" style="display:none;text-align:center;padding:20px;color:var(--grey-500)">
      找不到符合的帳號
    </div>

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

function filterUsers(kw) {
  const k = kw.trim().toLowerCase();
  const cards = document.querySelectorAll('.account-card');
  let visible = 0;
  cards.forEach(card => {
    const match = card.dataset.username.includes(k) || card.dataset.empname.includes(k);
    card.style.display = match ? '' : 'none';
    if (match) visible++;
  });
  const noMsg = document.getElementById('no-user-msg');
  if (noMsg) noMsg.style.display = visible === 0 ? '' : 'none';
}
</script>
</body>
</html>
