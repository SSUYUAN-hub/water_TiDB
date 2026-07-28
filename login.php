<?php
// 正式環境關閉錯誤顯示，改寫入 log
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Session 安全設定
// cookie_secure 僅在確實為 HTTPS 時開啟，避免 HTTP 本機開發/測試環境登入後 session 遺失
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', $isHttps ? 1 : 0);
ini_set('session.cookie_samesite', 'Strict');
session_start();
require_once __DIR__ . '/vendor/autoload.php';
include_once __DIR__ . '/db.php';
include_once __DIR__ . '/auth.php';

sendSecurityHeaders();

// 已登入直接跳走
if (isLoggedIn()) {
    header('Location: ' . (isAdmin() ? 'index.php' : 'attendance.php'));
    exit;
}

// ── 暴力破解防護設定（DB-based，清除 Cookie 無法繞過）──
const LOGIN_MAX_ATTEMPTS = 10;   // 同一 IP+帳號 最多嘗試次數
const LOGIN_LOCKOUT_SEC  = 600;  // 鎖定時間（秒）：10 分鐘

/**
 * 取得客戶端真實 IP。
 * Render 使用反向代理，REMOTE_ADDR 為 load balancer IP，
 * 必須讀取 X-Forwarded-For 第一個位置才是使用者真實 IP。
 * Render 基礎設施層會過濾外部偽造的 X-Forwarded-For，
 * 但仍做基本格式驗證作為防禦。
 */
function getClientIp(): string {
    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($forwarded !== '') {
        $ip = trim(explode(',', $forwarded)[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * 產生 identifier：clientIP:username
 */
function loginIdentifier(string $username): string {
    return getClientIp() . ':' . mb_strtolower(trim($username));
}

function getLoginAttempts(string $identifier): array {
    try {
        $row = getLoginAttemptRecord($identifier, LOGIN_LOCKOUT_SEC);
        if (!$row) return ['count' => 0, 'first_at' => 0];
        return ['count' => (int)$row['attempts'], 'first_at' => (int)$row['first_at']];
    } catch (Exception $e) {
        error_log('login_attempts DB error (read): ' . $e->getMessage());
        return ['count' => 0, 'first_at' => 0]; // DB 異常時放行，不阻擋正常使用者
    }
}

function recordLoginFailure(string $identifier): void {
    try {
        recordLoginAttemptDB($identifier);
    } catch (Exception $e) {
        error_log('login_attempts DB error (write): ' . $e->getMessage());
    }
}

function resetLoginAttempts(string $identifier): void {
    try {
        resetLoginAttemptDB($identifier);
    } catch (Exception $e) {
        error_log('login_attempts DB error (reset): ' . $e->getMessage());
    }
}

function isLoginLocked(string $identifier): bool {
    $a = getLoginAttempts($identifier);
    if ($a['count'] < LOGIN_MAX_ATTEMPTS) return false;
    return (time() - $a['first_at']) < LOGIN_LOCKOUT_SEC;
}

function lockoutSecondsRemaining(string $identifier): int {
    $a = getLoginAttempts($identifier);
    $remaining = LOGIN_LOCKOUT_SEC - (time() - $a['first_at']);
    return max(0, (int)$remaining);
}

// ── Redirect 安全白名單 ───────────────────────────────
const REDIRECT_WHITELIST = ['index.php', 'attendance.php', 'admin.php', 'account.php', 'scan_upload.php'];

function safeRedirect(string $redirect): string {
    // 只允許白名單內的檔名（允許帶 query string，但不允許路徑跳出）
    $path = strtok($redirect, '?');  // 取出路徑部分（去掉 query string）
    $base = basename($path);
    if (in_array($base, REDIRECT_WHITELIST, true) && $base === $path) {
        // 還原 query string（如有）
        $qs = strstr($redirect, '?');
        return $base . ($qs !== false ? $qs : '');
    }
    return 'index.php';
}

$errorMsg   = '';
$lockoutMsg = '';
$infoMsg    = ($_GET['reason'] ?? '') === 'timeout' ? '閒置逾時已自動登出，請重新登入。' : '';

// 先取得 username（用於組合 identifier，GET 時用空字串）
$_postUsername = trim($_POST['username'] ?? '');
$_loginId      = loginIdentifier($_postUsername);

if (isLoginLocked($_loginId)) {
    $lockoutMsg = '登入失敗次數過多，請 ' . ceil(lockoutSecondsRemaining($_loginId) / 60) . ' 分鐘後再試。';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_postUsername;
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $errorMsg = '請輸入帳號和密碼';
    } else {
        try {
            $stmt = getDB()->prepare(
                'SELECT * FROM users WHERE username = ? LIMIT 1'
            );
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                // 登入成功：清除失敗計數、重建 session
                resetLoginAttempts($_loginId);
                session_regenerate_id(true); // 防 session fixation
                $_SESSION['user'] = [
                    'id'            => $user['id'],
                    'username'      => $user['username'],
                    'role'          => $user['role'],
                    'employee_name' => $user['employee_name'],
                ];
                $redirect = $_GET['redirect'] ?? '';
                header('Location: ' . safeRedirect($redirect));
                exit;
            } else {
                recordLoginFailure($_loginId);
                if (isLoginLocked($_loginId)) {
                    $lockoutMsg = '登入失敗次數過多，請 ' . ceil(lockoutSecondsRemaining($_loginId) / 60) . ' 分鐘後再試。';
                } else {
                    $remaining = LOGIN_MAX_ATTEMPTS - getLoginAttempts($_loginId)['count'];
                    $errorMsg  = '帳號或密碼錯誤' . ($remaining <= 3 ? "（還剩 {$remaining} 次機會）" : '');
                }
            }
        } catch (PDOException $e) {
            error_log('Login DB error: ' . $e->getMessage());
            $errorMsg = '系統發生錯誤，請稍後再試';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">

<!-- PWA / 加到主畫面 捷徑設定 -->
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#2E7D32">
<link rel="icon" type="image/x-icon" href="favicon.ico">
<link rel="icon" type="image/png" sizes="32x32" href="favicon-32.png">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="薪資結算系統">
<meta name="mobile-web-app-capable" content="yes">

<title>登入 — 薪資結算系統</title>
<link rel="stylesheet" href="responsive.css">
<style>
body { display: flex; align-items: center; justify-content: center; min-height: 100vh; background: #f0f4f1; }
.login-wrap {
  width: 100%; max-width: 400px; padding: 24px 16px;
}
.login-card {
  background: white; border-radius: var(--radius-lg);
  box-shadow: 0 8px 32px rgba(0,0,0,0.12); padding: 32px 28px;
}
.login-logo {
  text-align: center; margin-bottom: 24px;
}
.login-logo .logo-icon { font-size: 2.5em; display: block; margin-bottom: 8px; }
.login-logo .logo-title {
  font-size: 1.1em; font-weight: 700; color: var(--green-800);
  letter-spacing: 0.04em;
}
.login-logo .logo-sub {
  font-size: 0.8em; color: var(--grey-500); margin-top: 4px;
}
.login-field { margin-bottom: 16px; }
.login-field label {
  display: block; font-size: 0.8em; font-weight: 600;
  color: var(--grey-500); margin-bottom: 6px; letter-spacing: 0.04em;
}
.login-input {
  width: 100%; padding: 13px 14px;
  border: 1.5px solid var(--grey-300); border-radius: var(--radius-sm);
  font-size: 1em; font-family: var(--font-body); color: var(--grey-900);
  background: var(--white); transition: border-color var(--transition);
  box-sizing: border-box;
}
.login-input:focus { outline: none; border-color: var(--green-600); box-shadow: 0 0 0 3px rgba(46,125,50,0.1); }
.login-btn {
  width: 100%; padding: 14px; background: var(--green-700); color: white;
  border: none; border-radius: var(--radius-sm); font-size: 1em;
  font-weight: 700; cursor: pointer; font-family: var(--font-body);
  transition: background var(--transition); margin-top: 4px;
  min-height: 48px;
}
.login-btn:hover { background: var(--green-800); }
.login-hint {
  text-align: center; margin-top: 16px; font-size: 0.8em; color: var(--grey-500);
}
</style>
</head>
<body>
<div class="login-wrap">
  <div class="login-card">
    <div class="login-logo">
      <span class="logo-icon">🕐</span>
      <div class="logo-title">薪資結算系統</div>
      <div class="logo-sub">請登入以繼續</div>
    </div>

    <?php if ($lockoutMsg): ?>
    <div class="msg msg-error" style="margin-bottom:16px">
      🔒 <?php echo htmlspecialchars($lockoutMsg); ?>
    </div>
    <?php elseif ($errorMsg): ?>
    <div class="msg msg-error" style="margin-bottom:16px">
      ⚠️ <?php echo htmlspecialchars($errorMsg); ?>
    </div>
    <?php elseif ($infoMsg): ?>
    <div class="msg msg-info" style="margin-bottom:16px">
      ⏱️ <?php echo htmlspecialchars($infoMsg); ?>
    </div>
    <?php endif; ?>

    <form method="post" <?php if ($lockoutMsg) echo 'onsubmit="return false"'; ?>>
      <div class="login-field">
        <label>帳號</label>
        <input type="text" name="username" class="login-input"
               placeholder="請輸入帳號"
               value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
               autocomplete="username" required autofocus
               <?php if ($lockoutMsg) echo 'disabled'; ?>>
      </div>
      <div class="login-field">
        <label>密碼</label>
        <input type="password" name="password" class="login-input"
               placeholder="請輸入密碼"
               autocomplete="current-password" required
               <?php if ($lockoutMsg) echo 'disabled'; ?>>
      </div>
      <button type="submit" class="login-btn" <?php if ($lockoutMsg) echo 'disabled style="opacity:0.5;cursor:not-allowed"'; ?>>🔑 登入</button>
    </form>

    <div class="login-hint">
      還沒有帳號？<a href="register.php" style="color:var(--green-700);font-weight:600;text-decoration:none">申請帳號 →</a>
    </div>
  </div>
</div>
</body>
</html>
