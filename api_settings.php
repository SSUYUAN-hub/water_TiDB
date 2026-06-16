<?php
require_once __DIR__ . '/db.php';
include_once __DIR__ . '/auth.php';
requireLogin();

header('Content-Type: application/json');

// 僅管理員可更新費率
if (!isAdmin()) {
    echo json_encode(['ok' => false, 'error' => '權限不足']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => '方法不允許']);
    exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data) {
    echo json_encode(['ok' => false, 'error' => '資料格式錯誤']);
    exit;
}

// CSRF 驗證：JSON API 從 body 取 token，與 session 比對
$submittedToken = $data['csrf_token'] ?? '';
if (!hash_equals(csrfToken(), $submittedToken)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => '請求驗證失敗']);
    exit;
}

$allowed = ['labor_ins_rate', 'labor_ins_share', 'health_ins_rate', 'health_ins_share'];
try {
    foreach ($allowed as $key) {
        if (isset($data[$key])) {
            $val = (float)$data[$key];
            if ($val < 0 || $val > 1) throw new Exception("費率值超出範圍：{$key}");
            setSetting($key, number_format($val, 4, '.', ''));
        }
    }
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
