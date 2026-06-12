<?php
/**
 * auth.php — Session 驗證共用函式
 * 在需要登入的頁面最頂端 include 此檔案
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Session 閒置逾時（2 小時）────────────────────────
const SESSION_IDLE_TIMEOUT = 7200; // 秒

function checkSessionTimeout(): void {
    if (!isset($_SESSION['user'])) return;
    $last = $_SESSION['_last_activity'] ?? 0;
    if ($last > 0 && (time() - $last) > SESSION_IDLE_TIMEOUT) {
        session_unset();
        session_destroy();
        session_start();
        header('Location: login.php?reason=timeout');
        exit;
    }
    $_SESSION['_last_activity'] = time();
}

// ── HTTP 安全標頭 ─────────────────────────────────────
function sendSecurityHeaders(): void {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; object-src 'none'; base-uri 'self'; form-action 'self'");
    header('X-Powered-By: ');
}

// ── 密碼強度驗證 ─────────────────────────────────────
// 回傳錯誤訊息字串；空字串代表通過
function validatePasswordStrength(string $pw): string {
    if (strlen($pw) < 8) {
        return '密碼至少需要 8 個字元';
    }
    if (!preg_match('/\d/', $pw)) {
        return '密碼需包含至少一個數字';
    }
    return '';
}

// ── 取得目前登入的使用者資訊 ──────────────────────────
function currentUser(): ?array {
    return $_SESSION['user'] ?? null;
}

// ── 是否已登入 ────────────────────────────────────────
function isLoggedIn(): bool {
    return isset($_SESSION['user']);
}

// ── 是否為系統管理員（role = admin）─────────────────
function isSysAdmin(): bool {
    return ($_SESSION['user']['role'] ?? '') === 'admin';
}

// ── 是否為女神Plus ────────────────────────────────────
function isGoddessPlus(): bool {
    return ($_SESSION['user']['role'] ?? '') === 'goddess_plus';
}

// ── 是否具有管理員權限（admin 或 goddess_plus）──────
function isAdmin(): bool {
    $role = $_SESSION['user']['role'] ?? '';
    return $role === 'admin' || $role === 'goddess_plus';
}

// ── 是否為員工 ────────────────────────────────────────
function isStaff(): bool {
    return ($_SESSION['user']['role'] ?? '') === 'staff';
}

// ── 顯示名稱（管理員/女神Plus → 角色名；員工 → 員工姓名）──
function displayName(): string {
    $user = $_SESSION['user'] ?? [];
    $role = $user['role'] ?? '';
    if ($role === 'admin') return '👑 系統管理';
    if ($role === 'goddess_plus') return '✨ 女神Plus';
    // 員工：優先顯示綁定的員工姓名，否則顯示帳號
    return $user['employee_name'] ?? $user['username'] ?? '';
}

// ── 角色顯示名稱（含 emoji）──────────────────────────
function roleLabel(?string $role = null): string {
    $r = $role ?? ($_SESSION['user']['role'] ?? '');
    return match($r) {
        'admin'        => '👑 系統管理',
        'goddess_plus' => '✨ 女神Plus',
        default        => '👤 員工',
    };
}

// ── 角色 emoji（僅圖示）──────────────────────────────
function roleIcon(?string $role = null): string {
    $r = $role ?? ($_SESSION['user']['role'] ?? '');
    return match($r) {
        'admin'        => '👑',
        'goddess_plus' => '✨',
        default        => '👤',
    };
}

// ── 強制登入（未登入則導向 login.php）────────────────
function requireLogin(): void {
    sendSecurityHeaders();
    checkSessionTimeout();
    if (!isLoggedIn()) {
        header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }
}

// ── 強制管理員權限（非 admin/goddess_plus 則拒絕）───
function requireAdmin(): void {
    requireLogin();
    if (!isAdmin()) {
        http_response_code(403);
        echo '<div style="font-family:sans-serif;padding:40px;text-align:center">';
        echo '<h2>⛔ 權限不足</h2><p>此頁面僅限管理員存取。</p>';
        echo '<a href="attendance.php">← 返回出勤查詢</a></div>';
        exit;
    }
}

// ── CSRF Token：產生（或取得已存在的）────────────────
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// ── CSRF Token：輸出隱藏欄位（直接在表單內呼叫）────
function csrfField(): void {
    echo '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(csrfToken(), ENT_QUOTES) . '">';
}

// ── CSRF Token：驗證（POST 處理最頂端呼叫）──────────
// 驗證失敗直接終止，不回傳任何有用訊息
function verifyCsrf(): void {
    $submitted = $_POST['csrf_token'] ?? '';
    if (!hash_equals(csrfToken(), $submitted)) {
        http_response_code(403);
        exit('請求驗證失敗，請重新整理頁面後再試。');
    }
}
