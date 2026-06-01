<?php
// 正式環境關閉錯誤顯示，改寫入 log
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Session 安全設定
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.cookie_samesite', 'Strict');
session_start();
require_once __DIR__ . '/vendor/autoload.php';
include_once __DIR__ . '/db.php';
include_once __DIR__ . '/auth.php';

// 已登入直接跳走
if (isLoggedIn()) {
    header('Location: ' . (isAdmin() ? 'index.php' : 'attendance.php'));
    exit;
}

$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
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
                // 登入成功：寫入 session
                session_regenerate_id(true); // 防 session fixation
                $_SESSION['user'] = [
                    'id'            => $user['id'],
                    'username'      => $user['username'],
                    'role'          => $user['role'],
                    'employee_name' => $user['employee_name'],
                ];
                // 導向原本要去的頁面，或預設首頁
                $redirect = $_GET['redirect'] ?? '';
                $safe     = filter_var($redirect, FILTER_VALIDATE_URL) === false && strpos($redirect, '//') === false;
                header('Location: ' . ($safe && $redirect ? $redirect : 'index.php'));
                exit;
            } else {
                $errorMsg = '帳號或密碼錯誤';
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
<meta name="apple-mobile-web-app-capable" content="yes">
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

    <?php if ($errorMsg): ?>
    <div class="msg msg-error" style="margin-bottom:16px">
      ⚠️ <?php echo htmlspecialchars($errorMsg); ?>
    </div>
    <?php endif; ?>

    <form method="post">
      <div class="login-field">
        <label>帳號</label>
        <input type="text" name="username" class="login-input"
               placeholder="請輸入帳號"
               value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
               autocomplete="username" required autofocus>
      </div>
      <div class="login-field">
        <label>密碼</label>
        <input type="password" name="password" class="login-input"
               placeholder="請輸入密碼"
               autocomplete="current-password" required>
      </div>
      <button type="submit" class="login-btn">🔑 登入</button>
    </form>

    <div class="login-hint">
      管理員請聯絡系統管理者取得帳號
    </div>
  </div>
</div>
</body>
</html>
