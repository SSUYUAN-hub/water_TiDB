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

// ── 台灣身分證字號檢查碼驗證 ─────────────────────────────

/**
 * 檢查數字字串是否包含連續 4 碼相同或順序的數字
 * 用於過濾 1111、1234 等明顯假造字號
 */
function hasConsecutivePattern(string $digits): bool {
    // 規則一：連續 4 個相同數字（如 1111、9999）
    if (preg_match('/(\\d)\\1{3}/', $digits)) return true;
    // 規則二：連續 4 個遞增或遞減順序（如 1234、9876）
    $seq_asc  = ['0123','1234','2345','3456','4567','5678','6789'];
    $seq_desc = ['9876','8765','7654','6543','5432','4321','3210'];
    foreach (array_merge($seq_asc, $seq_desc) as $s) {
        if (strpos($digits, $s) !== false) return true;
    }
    return false;
}

function validateTwId(string $id): bool {
    // 字母對應數字表（A=10 ... Z=35，按內政部對照）
    $map = ['A'=>10,'B'=>11,'C'=>12,'D'=>13,'E'=>14,'F'=>15,'G'=>16,'H'=>17,
            'I'=>34,'J'=>18,'K'=>19,'L'=>20,'M'=>21,'N'=>22,'O'=>35,'P'=>23,
            'Q'=>24,'R'=>25,'S'=>26,'T'=>27,'U'=>28,'V'=>29,'W'=>32,'X'=>30,
            'Y'=>31,'Z'=>33];
    // 第一關：格式正則
    if (!preg_match('/^[A-Z][12]\d{8}$/', $id)) return false;
    // 第二關：連號檢查（取字母後 9 碼數字）
    if (hasConsecutivePattern(substr($id, 1))) return false;
    // 第三關：檢查碼驗算
    $n = $map[$id[0]];
    $digits = [$n % 10];
    array_unshift($digits, intdiv($n, 10));
    for ($i = 1; $i < 10; $i++) $digits[] = (int)$id[$i];
    $weights = [1,9,8,7,6,5,4,3,2,1,1];
    $sum = 0;
    foreach ($weights as $k => $w) $sum += $digits[$k] * $w;
    return ($sum % 10) === 0;
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
        } elseif (!validateTwId($idNumber)) {
            $status  = 'error';
            $message = '身分證字號格式或檢查碼不正確（例：A123456789）';
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

/* disabled 欄位 overlay */
.reg-field { position:relative; }
.field-overlay {
  display:none; position:absolute;
  top:28px; left:0; width:100%; height:calc(100% - 28px);
  cursor:not-allowed; z-index:10;
}
.field-overlay.active { display:block; }

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
        <label>身分證字號 <span style="color:var(--red-500)">*</span></label>
        <input type="text" name="id_number" id="id_number" class="reg-input"
               placeholder="例：A123456789" maxlength="10"
               value="<?php echo htmlspecialchars($_POST['id_number'] ?? ''); ?>"
               style="text-transform:uppercase" required autocomplete="off">
        <div id="id-verify-msg" style="font-size:0.82em;margin-top:5px;min-height:1.2em;color:var(--green-700);display:none">
          ✅ 身分證驗證通過
        </div>
      </div>
      <div class="reg-field">
        <label>真實姓名 <span style="color:var(--red-500)">*</span></label>
        <input type="text" name="real_name" id="f_real_name" class="reg-input"
               placeholder="請輸入真實姓名"
               value="<?php echo htmlspecialchars($_POST['real_name'] ?? ''); ?>"
               disabled required>
      </div>
      <div class="reg-field">
        <label>登入帳號 <span style="color:var(--red-500)">*</span></label>
        <input type="text" name="username" id="f_username" class="reg-input"
               placeholder="設定您的登入帳號"
               value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
               autocomplete="username" disabled required>
      </div>
      <div class="reg-field">
        <label>密碼（至少 8 個字元，需含數字）<span style="color:var(--red-500)">*</span></label>
        <input type="password" name="password" id="f_password" class="reg-input"
               placeholder="設定登入密碼" autocomplete="new-password" minlength="8" disabled required>
      </div>
      <div class="reg-field">
        <label>聯絡電話 <span style="color:var(--red-500)">*</span></label>
        <input type="tel" name="phone" id="f_phone" class="reg-input"
               placeholder="例：0912345678"
               value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>"
               disabled required>
      </div>
      <button type="submit" id="reg-submit" class="reg-btn" disabled
              style="opacity:0.5;cursor:not-allowed">📨 送出申請</button>
    </form>

    <div class="reg-hint">
      已有帳號？<a href="login.php">← 返回登入</a>
    </div>
  <?php endif; ?>

  </div>
</div>
<script>
(function () {
  // 台灣身分證檢查碼驗證（前端同步版）
  var ID_MAP = {A:10,B:11,C:12,D:13,E:14,F:15,G:16,H:17,I:34,J:18,K:19,
                L:20,M:21,N:22,O:35,P:23,Q:24,R:25,S:26,T:27,U:28,V:29,
                W:32,X:30,Y:31,Z:33};
  // 連號檢查：連續 4 碼相同或順序視為假造字號
  function hasConsecutivePattern(digits) {
    if (/(\d)\1{3}/.test(digits)) return true;
    var asc  = ['0123','1234','2345','3456','4567','5678','6789'];
    var desc = ['9876','8765','7654','6543','5432','4321','3210'];
    var all = asc.concat(desc);
    for (var i = 0; i < all.length; i++) {
      if (digits.indexOf(all[i]) !== -1) return true;
    }
    return false;
  }
  function validateTwId(id) {
    id = id.toUpperCase();
    // 第一關：格式正則
    if (!/^[A-Z][12]\d{8}$/.test(id)) return false;
    // 第二關：連號檢查（字母後 9 碼）
    if (hasConsecutivePattern(id.slice(1))) return false;
    // 第三關：檢查碼驗算
    var n = ID_MAP[id[0]];
    var digits = [Math.floor(n / 10), n % 10];
    for (var i = 1; i < 10; i++) digits.push(parseInt(id[i], 10));
    var w = [1,9,8,7,6,5,4,3,2,1,1], sum = 0;
    for (var j = 0; j < 11; j++) sum += digits[j] * w[j];
    return sum % 10 === 0;
  }

  var idInput   = document.getElementById('id_number');
  var verifyMsg = document.getElementById('id-verify-msg');
  var submitBtn = document.getElementById('reg-submit');
  var fields    = ['f_real_name','f_username','f_password','f_phone']
                    .map(function(id){ return document.getElementById(id); });

  function allFilled() {
    return fields.every(function(f){ return f.value.trim() !== ''; });
  }

  function updateSubmit(idOk) {
    var ok = idOk && allFilled();
    submitBtn.disabled = !ok;
    submitBtn.style.opacity = ok ? '1' : '0.5';
    submitBtn.style.cursor  = ok ? 'pointer' : 'not-allowed';
  }

  var idVerified = false;

  // 身分證即時驗證
  idInput.addEventListener('input', function () {
    var val = this.value.toUpperCase();
    this.value = val;
    idVerified = validateTwId(val);
    verifyMsg.style.display = idVerified ? 'block' : 'none';
    setFieldsLocked(!idVerified);
    updateSubmit(idVerified);
  });

  // 點擊 disabled 欄位時警告
  // disabled 元素不觸發任何滑鼠事件，改用透明 overlay div 攔截點擊
  fields.forEach(function(f) {
    var overlay = document.createElement('div');
    overlay.className = 'field-overlay active';
    overlay.addEventListener('click', function () {
      alert('請先完成身分證驗證');
    });
    f.parentNode.appendChild(overlay);
    f._overlay = overlay;
  });

  function setFieldsLocked(locked) {
    fields.forEach(function(f) {
      f.disabled = locked;
      f._overlay.className = 'field-overlay' + (locked ? ' active' : '');
    });
  }

  // 其他欄位有值時即時更新送出按鈕狀態
  fields.forEach(function(f) {
    f.addEventListener('input', function () {
      updateSubmit(idVerified);
    });
  });

  // 頁面載入：若後端回填了合法身分證（驗證失敗重新顯示表單時）自動解鎖
  if (validateTwId(idInput.value)) {
    idVerified = true;
    verifyMsg.style.display = 'block';
    setFieldsLocked(false);
    updateSubmit(true);
  }
})();
</script>
</body>
</html>