<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

// 已登入者不需要此頁
// session_start() 已由 auth.php require_once 時統一執行（含 cookie 安全旗標）
sendSecurityHeaders();
if (isset($_SESSION['user'])) {
    header('Location: index.php'); exit;
}

// ── Rate Limiting（防止短時間大量申請）────────────────
// 同一個 session：10 分鐘內最多送出 3 次
const REG_MAX_ATTEMPTS  = 3;
const REG_WINDOW_SEC    = 600;

function isRegRateLimited(): bool {
    $r = $_SESSION['reg_attempts'] ?? ['count' => 0, 'first_at' => 0];
    if ($r['count'] === 0) return false;
    if ((time() - $r['first_at']) > REG_WINDOW_SEC) return false;
    return $r['count'] >= REG_MAX_ATTEMPTS;
}

function recordRegAttempt(): void {
    $r = $_SESSION['reg_attempts'] ?? ['count' => 0, 'first_at' => 0];
    if ($r['count'] === 0 || (time() - $r['first_at']) > REG_WINDOW_SEC) {
        $r = ['count' => 0, 'first_at' => time()];
    }
    $r['count']++;
    $_SESSION['reg_attempts'] = $r;
}

// ── 個資遮罩輔助（用於 log，避免原始資料出現在記錄中）──
function maskIdNumber(string $id): string {
    // A123456789 → A1****6789
    return strlen($id) >= 6
        ? substr($id, 0, 2) . '****' . substr($id, -4)
        : '****';
}

function maskPhone(string $phone): string {
    // 0912345678 → 0912***678
    return strlen($phone) >= 7
        ? substr($phone, 0, 4) . '***' . substr($phone, -3)
        : '****';
}

// ── 確保資料表存在 ───────────────────────────────────────
function ensureRequestTables(): void {
    $db = getDB();
    $db->exec("
        CREATE TABLE IF NOT EXISTS account_requests (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            username     VARCHAR(60)  NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            real_name    VARCHAR(60)  NOT NULL,
            id_number    VARCHAR(20)  NOT NULL,
            phone        VARCHAR(30)  NOT NULL,
            status       ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            reject_reason VARCHAR(255) DEFAULT NULL,
            created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) DEFAULT CHARSET=utf8mb4
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS account_blacklist (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            real_name    VARCHAR(60)  NOT NULL,
            id_number    VARCHAR(20)  NOT NULL UNIQUE,
            rejected_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reason       VARCHAR(255) DEFAULT NULL
        ) DEFAULT CHARSET=utf8mb4
    ");
}
ensureRequestTables();

$status  = ''; // 'success' | 'blacklist' | 'duplicate_user' | 'duplicate_pending' | 'error'
$message = '';
$pendingDate = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Rate limit 檢查（優先於任何資料庫操作）
    if (isRegRateLimited()) {
        $status  = 'error';
        $message = '送出次數過多，請稍後再試。';
    } else {
        $username = trim($_POST['username']  ?? '');
        $password = $_POST['password']       ?? '';
        $realName = trim($_POST['real_name'] ?? '');
        $idNumber = strtoupper(trim($_POST['id_number'] ?? ''));
        $phone    = trim($_POST['phone']     ?? '');

        // 基本驗證
        $pwError = validatePasswordStrength($password);
        if (empty($username) || !empty($pwError) || empty($realName) || empty($idNumber) || empty($phone)) {
            $status  = 'error';
            $message = !empty($pwError) ? $pwError : '所有欄位皆為必填';
        } elseif (!preg_match('/^[A-Z][12]\d{8}$/', $idNumber)) {
            $status  = 'error';
            $message = '身分證字號格式不正確（例：A123456789）';
        } else {
            $db = getDB();
            recordRegAttempt(); // 只在格式驗證通過後才計入

            // 1. 黑名單檢查
            $blStmt = $db->prepare('SELECT id FROM account_blacklist WHERE id_number = ? LIMIT 1');
            $blStmt->execute([$idNumber]);
            if ($blStmt->fetch()) {
                $status = 'blacklist';
            } else {
                // 2. 已存在 users 表（已核准帳號）
                $userStmt = $db->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
                $userStmt->execute([$username]);
                $idUserStmt = $db->prepare(
                    "SELECT r.id FROM account_requests r WHERE r.id_number = ? AND r.status = 'approved' LIMIT 1"
                );
                $idUserStmt->execute([$idNumber]);
                if ($userStmt->fetch() || $idUserStmt->fetch()) {
                    $status = 'duplicate_user';
                } else {
                    // 3. 已有 pending 申請
                    $pendStmt = $db->prepare(
                        "SELECT created_at FROM account_requests WHERE id_number = ? AND status = 'pending' LIMIT 1"
                    );
                    $pendStmt->execute([$idNumber]);
                    $pendRow = $pendStmt->fetch();
                    if ($pendRow) {
                        $status = 'duplicate_pending';
                        $dt = new DateTime($pendRow['created_at']);
                        $roc = ((int)$dt->format('Y') - 1911);
                        $pendingDate = $roc . '年' . $dt->format('n') . '月' . $dt->format('j') . '日';
                    } else {
                        // 4. 寫入申請
                        try {
                            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                            $ins = $db->prepare(
                                'INSERT INTO account_requests (username, password_hash, real_name, id_number, phone)
                                 VALUES (?, ?, ?, ?, ?)'
                            );
                            $ins->execute([$username, $hash, $realName, $idNumber, $phone]);
                            $status = 'success';
                            // log 用遮罩後的資料，不記錄原始個資
                            error_log('register: new request username=' . $username
                                . ' id=' . maskIdNumber($idNumber)
                                . ' phone=' . maskPhone($phone));
                        } catch (PDOException $e) {
                            $status  = 'error';
                            $message = '系統發生錯誤，請稍後再試';
                            error_log('register DB error: ' . $e->getMessage());
                        }
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>申請帳號 — 薪資結算系統</title>
<link rel="stylesheet" href="responsive.css">
<style>
body { display:flex; align-items:center; justify-content:center; min-height:100vh; background:#f0f4f1; }
.reg-wrap { width:100%; max-width:440px; padding:24px 16px; }
.reg-card {
  background:white; border-radius:var(--radius-lg);
  box-shadow:0 8px 32px rgba(0,0,0,0.12); padding:32px 28px;
}
.reg-logo { text-align:center; margin-bottom:24px; }
.reg-logo .logo-icon { font-size:2.2em; display:block; margin-bottom:8px; }
.reg-logo .logo-title { font-size:1.1em; font-weight:700; color:var(--green-800); letter-spacing:0.04em; }
.reg-logo .logo-sub   { font-size:0.82em; color:var(--grey-500); margin-top:4px; }

.reg-field { margin-bottom:15px; }
.reg-field label {
  display:block; font-size:0.82em; font-weight:600;
  color:var(--grey-600); margin-bottom:6px;
}
.reg-input {
  width:100%; padding:12px 14px;
  border:1.5px solid var(--grey-300); border-radius:var(--radius-sm);
  font-size:0.97em; font-family:var(--font-body); color:var(--grey-900);
  background:white; transition:border-color var(--transition); box-sizing:border-box;
}
.reg-input:focus { outline:none; border-color:var(--green-600); box-shadow:0 0 0 3px rgba(46,125,50,0.1); }
.reg-btn {
  width:100%; padding:13px; background:var(--green-700); color:white;
  border:none; border-radius:var(--radius-sm); font-size:1em;
  font-weight:700; cursor:pointer; font-family:var(--font-body);
  transition:background var(--transition); margin-top:4px; min-height:48px;
}
.reg-btn:hover { background:var(--green-800); }
.reg-hint { text-align:center; margin-top:16px; font-size:0.85em; color:var(--grey-500); }
.reg-hint a { color:var(--green-700); text-decoration:none; font-weight:600; }
.reg-hint a:hover { text-decoration:underline; }

/* 結果畫面 */
.result-box {
  text-align:center; padding:32px 16px;
}
.result-icon { font-size:3em; margin-bottom:12px; }
.result-title { font-size:1.15em; font-weight:700; margin-bottom:8px; }
.result-msg   { font-size:0.9em; color:var(--grey-600); line-height:1.7; margin-bottom:20px; }
.result-box.success .result-title { color:var(--green-700); }
.result-box.error   .result-title { color:var(--red-600); }
.result-box.warn    .result-title { color:var(--amber-600); }
</style>
</head>
<body>
<div class="reg-wrap">
  <div class="reg-card">

  <?php if ($status === 'success'): ?>
    <div class="result-box success">
      <div class="result-icon">✅</div>
      <div class="result-title">申請已送出</div>
      <div class="result-msg">您的申請已送出，請等待或聯繫管理員審核。</div>
      <a href="login.php" class="btn btn-primary" style="display:inline-block;min-width:140px">← 返回登入</a>
    </div>

  <?php elseif ($status === 'blacklist'): ?>
    <div class="result-box error">
      <div class="result-icon">🚫</div>
      <div class="result-title">申請已被系統拒絕</div>
      <div class="result-msg">您的申請已被系統拒絕，請聯繫管理員確認。</div>
      <a href="login.php" class="btn btn-secondary" style="display:inline-block;min-width:140px">← 返回登入</a>
    </div>

  <?php elseif ($status === 'duplicate_user'): ?>
    <div class="result-box error">
      <div class="result-icon">⚠️</div>
      <div class="result-title">您已重複申請</div>
      <div class="result-msg">您已重複申請，請聯繫管理員確認。</div>
      <a href="login.php" class="btn btn-secondary" style="display:inline-block;min-width:140px">← 返回登入</a>
    </div>

  <?php elseif ($status === 'duplicate_pending'): ?>
    <div class="result-box warn">
      <div class="result-icon">🕐</div>
      <div class="result-title">申請審核中</div>
      <div class="result-msg">
        您的申請已於 <strong><?php echo htmlspecialchars($pendingDate); ?></strong> 送出，<br>
        請等待或聯繫管理員審核。
      </div>
      <a href="login.php" class="btn btn-secondary" style="display:inline-block;min-width:140px">← 返回登入</a>
    </div>

  <?php else: ?>
    <!-- 申請表單 -->
    <div class="reg-logo">
      <span class="logo-icon">📝</span>
      <div class="logo-title">申請帳號</div>
      <div class="logo-sub">薪資結算系統</div>
    </div>

    <?php if ($status === 'error' && $message): ?>
    <div class="msg msg-error" style="margin-bottom:16px">⚠️ <?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <form method="post" novalidate>
      <div class="reg-field">
        <label>登入帳號 <span style="color:var(--red-500)">*</span></label>
        <input type="text" name="username" class="reg-input"
               placeholder="設定您的登入帳號"
               value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
               autocomplete="username" required>
      </div>
      <div class="reg-field">
        <label>密碼（至少 8 個字元，需含數字）<span style="color:var(--red-500)">*</span></label>
        <input type="password" name="password" class="reg-input"
               placeholder="設定登入密碼" autocomplete="new-password" minlength="8" required>
      </div>
      <div class="reg-field">
        <label>真實姓名 <span style="color:var(--red-500)">*</span></label>
        <input type="text" name="real_name" class="reg-input"
               placeholder="請輸入真實姓名"
               value="<?php echo htmlspecialchars($_POST['real_name'] ?? ''); ?>"
               required>
      </div>
      <div class="reg-field">
        <label>身分證字號 <span style="color:var(--red-500)">*</span></label>
        <input type="text" name="id_number" class="reg-input"
               placeholder="例：A123456789" maxlength="10"
               value="<?php echo htmlspecialchars($_POST['id_number'] ?? ''); ?>"
               style="text-transform:uppercase" required>
      </div>
      <div class="reg-field">
        <label>聯絡電話 <span style="color:var(--red-500)">*</span></label>
        <input type="tel" name="phone" class="reg-input"
               placeholder="例：0912345678"
               value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>"
               required>
      </div>
      <button type="submit" class="reg-btn">📨 送出申請</button>
    </form>

    <div class="reg-hint">
      已有帳號？<a href="login.php">← 返回登入</a>
    </div>
  <?php endif; ?>

  </div>
</div>
</body>
</html>
