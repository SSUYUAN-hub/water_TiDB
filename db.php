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

    $host    = $_ENV['DB_HOST']    ?? $_SERVER['DB_HOST']    ?? '';
    $port    = $_ENV['DB_PORT']    ?? $_SERVER['DB_PORT']    ?? '4000';
    $dbname  = $_ENV['DB_NAME']    ?? $_SERVER['DB_NAME']    ?? '';
    $user    = $_ENV['DB_USER']    ?? $_SERVER['DB_USER']    ?? '';
    $pass    = $_ENV['DB_PASS']    ?? $_SERVER['DB_PASS']    ?? '';
    $charset = $_ENV['DB_CHARSET'] ?? $_SERVER['DB_CHARSET'] ?? 'utf8mb4';

$dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::MYSQL_ATTR_SSL_CA       => '/etc/ssl/certs/ca-certificates.crt',
]);

    return $pdo;
}

// ══════════════════════════════════════════════════════════
//  員工 CRUD（介面與原 JSON 版完全相同，其他頁面不用改）
// ══════════════════════════════════════════════════════════

/**
 * 取得所有員工（依建立日期降冪）
 */
function ensureEmployeeColumns(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $db = getDB();
    $cols = $db->query("SHOW COLUMNS FROM employees")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('id_number', $cols))
        $db->exec("ALTER TABLE employees ADD COLUMN id_number VARCHAR(20) DEFAULT NULL AFTER name");
    if (!in_array('phone', $cols))
        $db->exec("ALTER TABLE employees ADD COLUMN phone VARCHAR(30) DEFAULT NULL AFTER id_number");
    if (!in_array('hire_date', $cols))
        $db->exec("ALTER TABLE employees ADD COLUMN hire_date DATE DEFAULT NULL AFTER phone");
}

function getEmployees(): array
{
    ensureEmployeeColumns();
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
        ensureEmployeeColumns();
        $stmt = getDB()->prepare(
            'INSERT INTO employees (name, type, hourly_rate, night_allowance, id_number, phone, hire_date, created_at)
             VALUES (:name, :type, :hourly_rate, :night_allowance, :id_number, :phone, :hire_date, :created_at)'
        );
        $stmt->execute([
            ':name'            => $data['name'],
            ':type'            => $data['type'],
            ':hourly_rate'     => (int)$data['hourly_rate'],
            ':night_allowance' => (int)($data['night_allowance'] ?? 0),
            ':id_number'       => $data['id_number']  ?? null,
            ':phone'           => $data['phone']       ?? null,
            ':hire_date'       => $data['hire_date']   ?? null,
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
    ensureEmployeeColumns();
    $stmt = getDB()->prepare(
        'UPDATE employees
            SET type            = :type,
                hourly_rate     = :hourly_rate,
                night_allowance = :night_allowance,
                id_number       = :id_number,
                phone           = :phone,
                hire_date       = :hire_date
          WHERE name = :name'
    );
    $stmt->execute([
        ':type'            => $data['type'],
        ':hourly_rate'     => (int)$data['hourly_rate'],
        ':night_allowance' => (int)($data['night_allowance'] ?? 0),
        ':id_number'       => $data['id_number']  ?: null,
        ':phone'           => $data['phone']       ?: null,
        ':hire_date'       => $data['hire_date']   ?: null,
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
function shouldApplyNightAllowance(?string $endTime, int $nightAllowance): bool
{
    if ($nightAllowance <= 0) return false;
    if (empty($endTime)) return false;
    try {
        $end    = new DateTime($endTime);
        $endMin = (int)$end->format('H') * 60 + (int)$end->format('i');
        return ($endMin >= 23 * 60) || ($endMin < 6 * 60);
    } catch (Exception $e) {
        return false;
    }
}

// ══════════════════════════════════════════════════════════
//  系統設定（system_settings）
// ══════════════════════════════════════════════════════════

/**
 * 讀取單一設定值，找不到回傳 $default
 */
function getSetting(string $key, string $default = ''): string
{
    $stmt = getDB()->prepare('SELECT `value` FROM system_settings WHERE `key` = ? LIMIT 1');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['value'] : $default;
}

/**
 * 寫入／更新設定值（upsert）
 */
function setSetting(string $key, string $value): void
{
    $stmt = getDB()->prepare(
        'INSERT INTO system_settings (`key`, `value`)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = NOW()'
    );
    $stmt->execute([$key, $value]);
}

/**
 * 一次取得所有費率設定，回傳 assoc array
 * 若 DB 尚未有該 key，使用程式內建預設值（首次部署保護）
 */
function getInsuranceRates(): array
{
    $defaults = [
        'labor_ins_rate'   => '0.12',
        'labor_ins_share'  => '0.20',
        'health_ins_rate'  => '0.0517',
        'health_ins_share' => '0.30',
    ];
    $stmt = getDB()->query(
        "SELECT `key`, `value` FROM system_settings
          WHERE `key` IN ('labor_ins_rate','labor_ins_share','health_ins_rate','health_ins_share')"
    );
    $rows = $stmt->fetchAll();
    foreach ($rows as $row) {
        $defaults[$row['key']] = $row['value'];
    }
    return [
        'labor_ins_rate'   => (float)$defaults['labor_ins_rate'],
        'labor_ins_share'  => (float)$defaults['labor_ins_share'],
        'health_ins_rate'  => (float)$defaults['health_ins_rate'],
        'health_ins_share' => (float)$defaults['health_ins_share'],
    ];
}

// ══════════════════════════════════════════════════════════
//  勞健保投保薪資分級表
// ══════════════════════════════════════════════════════════

/**
 * 勞保投保薪資分級表（2025年版）
 */
function getLaborInsuredSalary(int $monthlySalary): int
{
    $brackets = [
        27470, 28800, 30300, 31800, 33300, 34800, 36300, 38200,
        40100, 42000, 43900, 45800, 48200, 50600, 53000, 55400,
        57800, 60800, 63800, 66800, 69800, 72800, 76500, 80200,
        87600, 92400, 98600,110100,120900,131700,142500,157200,
        173200,189200,205200,
    ];
    foreach ($brackets as $b) {
        if ($monthlySalary <= $b) return $b;
    }
    return end($brackets);
}

/**
 * 健保投保薪資分級表（2025年版）
 */
function getHealthInsuredSalary(int $monthlySalary): int
{
    $brackets = [
        27470, 28800, 30300, 31800, 33300, 34800, 36300, 38200,
        40100, 42000, 43900, 45800, 48200, 50600, 53000, 55400,
        57800, 60800, 63800, 66800, 69800, 72800, 76500, 80200,
        87600, 92400, 98600,110100,120900,131700,142500,157200,
        173200,189200,205200,219500,
    ];
    foreach ($brackets as $b) {
        if ($monthlySalary <= $b) return $b;
    }
    return end($brackets);
}

/**
 * 計算勞健保員工自付額（僅正職）
 */
function calcInsurance(int $monthlySalary, array $rates): array
{
    $laborInsured  = getLaborInsuredSalary($monthlySalary);
    $healthInsured = getHealthInsuredSalary($monthlySalary);
    $laborIns      = (int)ceil($laborInsured  * $rates['labor_ins_rate']  * $rates['labor_ins_share']);
    $healthIns     = (int)ceil($healthInsured * $rates['health_ins_rate'] * $rates['health_ins_share']);
    return [
        'labor_insured'   => $laborInsured,
        'labor_ins'       => $laborIns,
        'labor_ins_rate'  => $rates['labor_ins_rate'],
        'labor_ins_share' => $rates['labor_ins_share'],
        'health_insured'  => $healthInsured,
        'health_ins'      => $healthIns,
        'health_ins_rate' => $rates['health_ins_rate'],
        'health_ins_share'=> $rates['health_ins_share'],
    ];
}

// ══════════════════════════════════════════════════════════
//  monthly_deductions CRUD
// ══════════════════════════════════════════════════════════

/**
 * 寫入／更新月結扣額（upsert）
 */
function saveMonthlyDeduction(array $data): void
{
    $sql = '
        INSERT INTO monthly_deductions
            (employee_name, `year_month`, insured_salary,
             labor_ins_rate, labor_ins_calc, labor_ins,
             health_ins_rate, health_ins_calc, health_ins,
             net_salary, note)
        VALUES
            (:employee_name, :year_month, :insured_salary,
             :labor_ins_rate, :labor_ins_calc, :labor_ins,
             :health_ins_rate, :health_ins_calc, :health_ins,
             :net_salary, :note)
        ON DUPLICATE KEY UPDATE
            insured_salary   = VALUES(insured_salary),
            labor_ins_rate   = VALUES(labor_ins_rate),
            labor_ins_calc   = VALUES(labor_ins_calc),
            labor_ins        = VALUES(labor_ins),
            health_ins_rate  = VALUES(health_ins_rate),
            health_ins_calc  = VALUES(health_ins_calc),
            health_ins       = VALUES(health_ins),
            net_salary       = VALUES(net_salary),
            note             = VALUES(note),
            updated_at       = NOW()
    ';
    $stmt = getDB()->prepare($sql);
    $stmt->execute([
        ':employee_name'   => $data['employee_name'],
        ':year_month'      => $data['year_month'],
        ':insured_salary'  => (int)($data['insured_salary']   ?? 0),
        ':labor_ins_rate'  => (float)($data['labor_ins_rate'] ?? 0),
        ':labor_ins_calc'  => (int)($data['labor_ins_calc']   ?? 0),
        ':labor_ins'       => (int)($data['labor_ins']        ?? 0),
        ':health_ins_rate' => (float)($data['health_ins_rate'] ?? 0),
        ':health_ins_calc' => (int)($data['health_ins_calc']  ?? 0),
        ':health_ins'      => (int)($data['health_ins']       ?? 0),
        ':net_salary'      => (int)($data['net_salary']       ?? 0),
        ':note'            => $data['note'] ?? null,
    ]);
}

/**
 * 查詢某員工某年月的扣額紀錄，找不到回傳 null
 */
function getMonthlyDeduction(string $employeeName, string $yearMonth): ?array
{
    $stmt = getDB()->prepare(
        'SELECT * FROM monthly_deductions
          WHERE employee_name = ? AND `year_month` = ? LIMIT 1'
    );
    $stmt->execute([$employeeName, $yearMonth]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * 查詢某員工某年所有月份的扣額（年度查詢用）
 */
function getMonthlyDeductionsByYear(string $employeeName, string $year): array
{
    $stmt = getDB()->prepare(
        "SELECT * FROM monthly_deductions
          WHERE employee_name = ? AND `year_month` LIKE ?
          ORDER BY `year_month` ASC"
    );
    $stmt->execute([$employeeName, $year . '-%']);
    return $stmt->fetchAll();
}