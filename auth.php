<?php
/**
 * auth.php — Session 驗證共用函式
 * 在需要登入的頁面最頂端 include 此檔案
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
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
