<?php

/**
 * 員工資料管理（MySQL PDO 版）
 * 取代原本的 employees.json 檔案儲存
 */

// ── 載入環境變數（本地用 .env，Render 直接讀 $_ENV/$_SERVER）──
if (!isset($_ENV['DB_HOST']) && !isset($_SERVER['DB_HOST'])) {
    if (file_exists(__DIR__ . '/.env')) {
        require_once __DIR__ . '/vendor/autoload.php';
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
        $dotenv->load();
    }
}

function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $host    = $_ENV['DB_HOST']    ?? $_SERVER['DB_HOST']    ?? 'gateway01.ap-northeast-1.prod.aws.tidbcloud.com';
    $port    = $_ENV['DB_PORT']    ?? $_SERVER['DB_PORT']    ?? '4000';
    $dbname  = $_ENV['DB_NAME']    ?? $_SERVER['DB_NAME']    ?? 'water';
    $user    = $_ENV['DB_USER']    ?? $_SERVER['DB_USER']    ?? '2fVv8RnCzd3hVVd.root';
    $pass    = $_ENV['DB_PASS']    ?? $_SERVER['DB_PASS']    ?? 'mqFIZv8n2YujgJOA';
    $charset = $_ENV['DB_CHARSET'] ?? $_SERVER['DB_CHARSET'] ?? 'utf8mb4';

    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false, // ← 加這行
    ]);

    return $pdo;
}

// ══════════════════════════════════════════════════════════
//  員工 CRUD（介面與原 JSON 版完全相同，其他頁面不用改）
// ══════════════════════════════════════════════════════════

/**
 * 取得所有員工（依建立日期降冪）
 */
function getEmployees(): array
{
    $stmt = getDB()->query('SELECT * FROM employees ORDER BY created_at DESC');
    return $stmt->fetchAll();
}

/**
 * 依姓名取得單一員工，找不到回傳 null
 */
function getEmployee(string $name): ?array
{
    $stmt = getDB()->prepare('SELECT * FROM employees WHERE name = ? LIMIT 1');
    $stmt->execute([$name]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * 新增員工，姓名重複回傳 false
 */
function addEmployee(array $data): bool
{
    try {
        $stmt = getDB()->prepare(
            'INSERT INTO employees (name, type, hourly_rate, night_allowance, created_at)
             VALUES (:name, :type, :hourly_rate, :night_allowance, :created_at)'
        );
        $stmt->execute([
            ':name'            => $data['name'],
            ':type'            => $data['type'],
            ':hourly_rate'     => (int)$data['hourly_rate'],
            ':night_allowance' => (int)($data['night_allowance'] ?? 0),
            ':created_at'      => date('Y-m-d'),
        ]);
        return true;
    } catch (PDOException $e) {
        // 1062 = Duplicate entry（UNIQUE KEY 衝突）
        if ($e->getCode() === '23000') return false;
        throw $e; // 其他錯誤繼續往上拋
    }
}

/**
 * 更新員工薪資設定，找不到回傳 false
 */
function updateEmployee(string $name, array $data): bool
{
    $stmt = getDB()->prepare(
        'UPDATE employees
            SET type            = :type,
                hourly_rate     = :hourly_rate,
                night_allowance = :night_allowance
          WHERE name = :name'
    );
    $stmt->execute([
        ':type'            => $data['type'],
        ':hourly_rate'     => (int)$data['hourly_rate'],
        ':night_allowance' => (int)($data['night_allowance'] ?? 0),
        ':name'            => $name,
    ]);
    return $stmt->rowCount() > 0;
}

/**
 * 刪除員工，找不到或有出勤紀錄時回傳 false
 */
function deleteEmployee(string $name): bool
{
    try {
        $stmt = getDB()->prepare('DELETE FROM employees WHERE name = ?');
        $stmt->execute([$name]);
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        // 1451 = 外鍵限制（該員工仍有出勤紀錄）
        if ($e->getCode() === '23000') return false;
        throw $e;
    }
}

// ══════════════════════════════════════════════════════════
//  出勤紀錄 CRUD
// ══════════════════════════════════════════════════════════

/**
 * 寫入單日出勤紀錄
 * 同一員工同一天重複寫入時，以新資料覆蓋（UPDATE）
 */
function saveAttendance(array $data): bool
{
    $sql = '
        INSERT INTO attendance
            (employee_name, work_date, s1_start, s1_end, s2_start, s2_end,
             has_break, total_hours, overtime_hours, overtime_pay, night_pay, salary)
        VALUES
            (:employee_name, :work_date, :s1_start, :s1_end, :s2_start, :s2_end,
             :has_break, :total_hours, :overtime_hours, :overtime_pay, :night_pay, :salary)
        ON DUPLICATE KEY UPDATE
            s1_start       = VALUES(s1_start),
            s1_end         = VALUES(s1_end),
            s2_start       = VALUES(s2_start),
            s2_end         = VALUES(s2_end),
            has_break      = VALUES(has_break),
            total_hours    = VALUES(total_hours),
            overtime_hours = VALUES(overtime_hours),
            overtime_pay   = VALUES(overtime_pay),
            night_pay      = VALUES(night_pay),
            salary         = VALUES(salary)
    ';

    $stmt = getDB()->prepare($sql);
    $stmt->execute([
        ':employee_name'   => $data['employee_name'],
        ':work_date'       => $data['work_date'],          // 'Y-m-d'
        ':s1_start'        => $data['s1_start']  ?: null,  // 'HH:MM' 或 null
        ':s1_end'          => $data['s1_end']    ?: null,
        ':s2_start'        => $data['s2_start']  ?: null,
        ':s2_end'          => $data['s2_end']    ?: null,
        ':has_break'       => (int)($data['has_break']      ?? 1),
        ':total_hours'     => (float)($data['total_hours']  ?? 0),
        ':overtime_hours'  => (float)($data['overtime_hours'] ?? 0),
        ':overtime_pay'    => (int)($data['overtime_pay']   ?? 0),
        ':night_pay'       => (int)($data['night_pay']      ?? 0),
        ':salary'          => (int)($data['salary']         ?? 0),
    ]);
    return true;
}

/**
 * 查詢某員工某年月的出勤紀錄
 * $yearMonth 格式：'2026-04'
 */
function getAttendanceByMonth(string $employeeName, string $yearMonth): array
{
    $stmt = getDB()->prepare(
        'SELECT * FROM attendance
          WHERE employee_name = ?
            AND DATE_FORMAT(work_date, "%Y-%m") = ?
          ORDER BY work_date ASC'
    );
    $stmt->execute([$employeeName, $yearMonth]);
    return $stmt->fetchAll();
}
/**
 * 查詢某員工某年全年的出勤紀錄（依月份分組）
 */
function getAttendanceByYear(string $employeeName, string $year): array
{
    $stmt = getDB()->prepare(
        'SELECT * FROM attendance
          WHERE employee_name = ?
            AND YEAR(work_date) = ?
          ORDER BY work_date ASC'
    );
    $stmt->execute([$employeeName, $year]);
    $rows = $stmt->fetchAll();
    $grouped = [];
    foreach ($rows as $row) {
        $ym = substr($row['work_date'], 0, 7);
        $grouped[$ym][] = $row;
    }
    return $grouped;
}

/**
 * 查詢所有員工某年月的出勤紀錄（月份匯出用）
 */
function getAllEmployeesAttendanceByMonth(string $yearMonth): array
{
    $stmt = getDB()->prepare(
        'SELECT * FROM attendance
          WHERE DATE_FORMAT(work_date, "%Y-%m") = ?
          ORDER BY employee_name ASC, work_date ASC'
    );
    $stmt->execute([$yearMonth]);
    $rows = $stmt->fetchAll();
    $grouped = [];
    foreach ($rows as $row) {
        $grouped[$row['employee_name']][] = $row;
    }
    return $grouped;
}

/**
 * 查詢所有員工某年全年的出勤紀錄（年份匯出用）
 */
function getAllEmployeesAttendanceByYear(string $year): array
{
    $stmt = getDB()->prepare(
        'SELECT * FROM attendance
          WHERE YEAR(work_date) = ?
          ORDER BY work_date ASC, employee_name ASC'
    );
    $stmt->execute([$year]);
    $rows = $stmt->fetchAll();
    $grouped = [];
    foreach ($rows as $row) {
        $ym = substr($row['work_date'], 0, 7);
        $grouped[$ym][$row['employee_name']][] = $row;
    }
    return $grouped;
}
/**
 * 查詢所有員工某年月的出勤摘要（用於 admin 匯出統計）
 */
function getAttendanceSummaryByMonth(string $yearMonth): array
{
    $stmt = getDB()->prepare(
        'SELECT
            employee_name,
            COUNT(*)            AS work_days,
            SUM(total_hours)    AS total_hours,
            SUM(overtime_hours) AS overtime_hours,
            SUM(overtime_pay)   AS overtime_pay,
            SUM(night_pay)      AS night_pay,
            SUM(salary)         AS total_salary
           FROM attendance
          WHERE DATE_FORMAT(work_date, "%Y-%m") = ?
          GROUP BY employee_name
          ORDER BY employee_name'
    );
    $stmt->execute([$yearMonth]);
    return $stmt->fetchAll();
}

// ══════════════════════════════════════════════════════════
//  夜班津貼判斷（邏輯不變，保留在此）
// ══════════════════════════════════════════════════════════

/**
 * 判斷是否觸發夜班津貼
 * 規則：下班時間超過 23:00，或凌晨 06:00 以前（跨夜）
 */
function shouldApplyNightAllowance(string $endTime, int $nightAllowance): bool
{
    if ($nightAllowance <= 0) return false;
    try {
        $end    = new DateTime($endTime);
        $endMin = (int)$end->format('H') * 60 + (int)$end->format('i');
        return ($endMin >= 23 * 60) || ($endMin < 6 * 60);
    } catch (Exception $e) {
        return false;
    }
}
