<?php
require_once __DIR__ . '/db.php';

// ─── Auth ─────────────────────────────────────────────────────────────────────

function isLoggedIn(): bool {
    return isset($_SESSION['business_id']) && (int)$_SESSION['business_id'] > 0;
}

function requireAuth(): void {
    if (!isLoggedIn()) {
        header('Location: ' . APP_URL . '/auth/login.php');
        exit;
    }
}

function redirectIfLoggedIn(): void {
    if (isLoggedIn()) {
        header('Location: ' . APP_URL . '/dashboard/index.php');
        exit;
    }
}

function getCurrentBusiness(): ?array {
    if (!isLoggedIn()) return null;
    static $cache = null;
    if ($cache !== null) return $cache;
    $stmt = db()->prepare('SELECT * FROM businesses WHERE id = ? LIMIT 1');
    $stmt->execute([$_SESSION['business_id']]);
    $cache = $stmt->fetch() ?: null;
    return $cache;
}

function login(array $business): void {
    session_regenerate_id(true);
    $_SESSION['business_id']   = $business['id'];
    $_SESSION['business_name'] = $business['name'];
    $_SESSION['business_email']= $business['email'];
    db()->prepare('UPDATE businesses SET last_login = NOW() WHERE id = ?')
        ->execute([$business['id']]);
}

function logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// ─── Super Admin Auth ─────────────────────────────────────────────────────────

function isSuperAdmin(): bool {
    return isset($_SESSION['super_admin_id']) && (int)$_SESSION['super_admin_id'] > 0;
}

function requireSuperAdmin(): void {
    if (!isSuperAdmin()) {
        header('Location: ' . APP_URL . '/superadmin/login.php');
        exit;
    }
}

function redirectIfSuperAdminLoggedIn(): void {
    if (isSuperAdmin()) {
        header('Location: ' . APP_URL . '/superadmin/index.php');
        exit;
    }
}

function getCurrentSuperAdmin(): ?array {
    if (!isSuperAdmin()) return null;
    static $cache = null;
    if ($cache !== null) return $cache;
    $stmt = db()->prepare('SELECT * FROM super_admins WHERE id = ? LIMIT 1');
    $stmt->execute([$_SESSION['super_admin_id']]);
    $cache = $stmt->fetch() ?: null;
    return $cache;
}

function superAdminLogin(array $admin): void {
    session_regenerate_id(true);
    $_SESSION['super_admin_id']    = $admin['id'];
    $_SESSION['super_admin_name']  = $admin['name'];
    $_SESSION['super_admin_email'] = $admin['email'];
    db()->prepare('UPDATE super_admins SET last_login = NOW() WHERE id = ?')
        ->execute([$admin['id']]);
}

function superAdminLogout(): void {
    unset($_SESSION['super_admin_id'], $_SESSION['super_admin_name'], $_SESSION['super_admin_email']);
}

// ─── Super Admin Data ─────────────────────────────────────────────────────────

function getPlatformStats(): array {
    $pdo = db();
    $stats = [];

    $stmt = $pdo->query("SELECT COUNT(*) FROM businesses");
    $stats['total_businesses'] = (int)$stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM businesses WHERE is_active = 1");
    $stats['active_businesses'] = (int)$stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM whatsapp_configs WHERE is_connected = 1");
    $stats['connected_whatsapp'] = (int)$stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM appointments");
    $stats['total_appointments'] = (int)$stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM customers");
    $stats['total_customers'] = (int)$stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COALESCE(SUM(total_price),0) FROM appointments WHERE status = 'completed'");
    $stats['total_revenue'] = (float)$stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURDATE()");
    $stats['today_appointments'] = (int)$stmt->fetchColumn();

    return $stats;
}

function getAllBusinesses(string $search = ''): array {
    $sql = "
        SELECT b.*, w.is_connected, w.phone_number_id, w.waba_id, w.access_token, w.webhook_verify_token, w.phone_number, w.display_name
        FROM businesses b
        LEFT JOIN whatsapp_configs w ON w.business_id = b.id
    ";
    $params = [];
    if ($search !== '') {
        $sql .= " WHERE b.name LIKE ? OR b.email LIKE ?";
        $params = ["%$search%", "%$search%"];
    }
    $sql .= " ORDER BY b.created_at DESC";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getBusinessById(int $businessId): ?array {
    $stmt = db()->prepare("
        SELECT b.*, w.is_connected, w.phone_number_id, w.waba_id, w.access_token, w.webhook_verify_token, w.phone_number, w.display_name
        FROM businesses b
        LEFT JOIN whatsapp_configs w ON w.business_id = b.id
        WHERE b.id = ?
    ");
    $stmt->execute([$businessId]);
    return $stmt->fetch() ?: null;
}

// ─── Platform Settings (public, site-wide config) ──────────────────────────────

function getPlatformSettings(): array {
    $defaults = [
        'currency_symbol' => '$',
        'contact_phone'   => '',
        'contact_email'   => '',
        'demo_whatsapp'   => '',
        'wa_verify_token' => '',
    ];
    try {
        $row = db()->query("SELECT * FROM platform_settings WHERE id = 1 LIMIT 1")->fetch();
    } catch (Exception $e) {
        $row = false;
    }
    return $row ? array_merge($defaults, $row) : $defaults;
}

function updatePlatformSettings(array $data): void {
    $sets = ['currency_symbol = ?', 'contact_phone = ?', 'contact_email = ?', 'demo_whatsapp = ?'];
    $vals = [$data['currency_symbol'], $data['contact_phone'], $data['contact_email'], $data['demo_whatsapp']];

    if (array_key_exists('wa_verify_token', $data)) {
        $sets[] = 'wa_verify_token = ?';
        $vals[] = $data['wa_verify_token'];
    }

    db()->prepare("
        INSERT INTO platform_settings (id, currency_symbol, contact_phone, contact_email, demo_whatsapp)
        VALUES (1, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE " . implode(', ', $sets)
    )->execute(array_merge([$data['currency_symbol'], $data['contact_phone'], $data['contact_email'], $data['demo_whatsapp']], $vals));
}

/**
 * Assign the next daily token number for a business on a given date.
 * Call this BEFORE inserting the appointment (counts existing rows).
 */
function assignDailyToken(int $businessId, string $date): int {
    $stmt = db()->prepare("SELECT COALESCE(MAX(daily_token), 0) + 1 FROM appointments WHERE business_id = ? AND appointment_date = ?");
    $stmt->execute([$businessId, $date]);
    return (int)$stmt->fetchColumn();
}

function getActivePlans(): array {
    return db()->query("SELECT * FROM subscription_plans WHERE is_active = 1 ORDER BY price_monthly ASC")->fetchAll();
}

// ─── CSRF ─────────────────────────────────────────────────────────────────────

function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(string $token): bool {
    return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken()) . '">';
}

// ─── Flash Messages ───────────────────────────────────────────────────────────

function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array {
    if (!isset($_SESSION['flash'])) return null;
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function renderFlash(): string {
    $flash = getFlash();
    if (!$flash) return '';
    $icon = match($flash['type']) {
        'success' => '✓',
        'error'   => '✕',
        'warning' => '⚠',
        default   => 'ℹ',
    };
    $msg = htmlspecialchars($flash['message']);
    return "<div class=\"alert alert-{$flash['type']}\"><span>{$icon}</span>{$msg}</div>";
}

// ─── Input Helpers ────────────────────────────────────────────────────────────

function sanitize(string $input): string {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

function post(string $key, string $default = ''): string {
    return isset($_POST[$key]) ? trim($_POST[$key]) : $default;
}

function get(string $key, string $default = ''): string {
    return isset($_GET[$key]) ? trim($_GET[$key]) : $default;
}

// ─── Slug Generator ───────────────────────────────────────────────────────────

function generateSlug(string $text): string {
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
    $text = preg_replace('/[\s-]+/', '-', $text);
    return rtrim($text, '-');
}

function uniqueSlug(string $base): string {
    $slug = generateSlug($base);
    $original = $slug;
    $i = 1;
    while (true) {
        $stmt = db()->prepare('SELECT id FROM businesses WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        if (!$stmt->fetch()) break;
        $slug = $original . '-' . $i++;
    }
    return $slug;
}

// ─── Formatting ───────────────────────────────────────────────────────────────

function formatPrice(float $price, string $currency = 'USD'): string {
    static $symbols = [
        'USD' => '$',   'EUR' => '€',   'GBP' => '£',   'CAD' => 'CA$',
        'AUD' => 'A$',  'INR' => '₹',   'PKR' => 'Rs',  'AED' => 'د.إ',
        'SAR' => '﷼',   'ZAR' => 'R',   'NGN' => '₦',   'EGP' => 'E£',
        'PHP' => '₱',   'IDR' => 'Rp',  'MYR' => 'RM',  'SGD' => 'S$',
        'BDT' => '৳',   'TRY' => '₺',   'NZD' => 'NZ$', 'BRL' => 'R$',
        'MXN' => 'MX$',
    ];
    $symbol = $symbols[$currency] ?? $currency;
    return $symbol . number_format($price, 2);
}

function formatTime(string $time): string {
    return date('h:i A', strtotime($time));
}

function formatDate(string $date): string {
    return date('M j, Y', strtotime($date));
}

function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'Just now';
    if ($diff < 3600)   return floor($diff / 60) . ' min ago';
    if ($diff < 86400)  return floor($diff / 3600) . ' hr ago';
    if ($diff < 604800) return floor($diff / 86400) . ' days ago';
    return date('M j, Y', strtotime($datetime));
}

// ─── Dashboard Stats ──────────────────────────────────────────────────────────

function getDashboardStats(int $businessId): array {
    $pdo = db();
    $stats = [];

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM appointments WHERE business_id = ? AND appointment_date = CURDATE()');
    $stmt->execute([$businessId]);
    $stats['today_appointments'] = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE business_id = ? AND status = 'pending'");
    $stmt->execute([$businessId]);
    $stats['pending'] = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM customers WHERE business_id = ?');
    $stmt->execute([$businessId]);
    $stats['total_customers'] = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_price),0) FROM appointments WHERE business_id = ? AND status='completed' AND MONTH(appointment_date)=MONTH(CURDATE()) AND YEAR(appointment_date)=YEAR(CURDATE())");
    $stmt->execute([$businessId]);
    $stats['monthly_revenue'] = (float)$stmt->fetchColumn();

    return $stats;
}

function getRecentAppointments(int $businessId, int $limit = 10): array {
    $stmt = db()->prepare("
        SELECT a.*, c.name AS customer_name, c.phone AS customer_phone,
               s.name AS service_name, st.name AS staff_name
        FROM appointments a
        LEFT JOIN customers c ON c.id = a.customer_id
        LEFT JOIN services  s ON s.id = a.service_id
        LEFT JOIN staff    st ON st.id = a.staff_id
        WHERE a.business_id = ?
        ORDER BY a.created_at DESC
        LIMIT ?
    ");
    $stmt->execute([$businessId, $limit]);
    return $stmt->fetchAll();
}

function getUnreadNotificationCount(int $businessId): int {
    $stmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE business_id = ? AND is_read = 0');
    $stmt->execute([$businessId]);
    return (int)$stmt->fetchColumn();
}

// ─── Default Business Hours ───────────────────────────────────────────────────

function createDefaultBusinessHours(int $businessId): void {
    $pdo = db();
    for ($day = 0; $day <= 6; $day++) {
        $isOpen = ($day !== 0 && $day !== 6) ? 1 : 0; // Mon–Fri open
        $pdo->prepare("
            INSERT IGNORE INTO business_hours (business_id, day_of_week, open_time, close_time, is_open, slot_interval)
            VALUES (?, ?, '09:00:00', '17:00:00', ?, 30)
        ")->execute([$businessId, $day, $isOpen]);
    }
}

// ─── Sidebar Badge Counts ──────────────────────────────────────────────────────

function getSidebarBadgeCounts(int $businessId): array {
    $pdo = db();
    $counts = ['pending' => 0, 'unread_messages' => 0];

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE business_id = ? AND status = 'pending'");
    $stmt->execute([$businessId]);
    $counts['pending'] = (int)$stmt->fetchColumn();

    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM whatsapp_messages WHERE business_id = ? AND direction='inbound' AND is_read = 0");
        $stmt->execute([$businessId]);
        $counts['unread_messages'] = (int)$stmt->fetchColumn();
    } catch (Exception $e) {}

    return $counts;
}

// ─── Shared Data Lookups ────────────────────────────────────────────────────────

function getCategories(int $businessId, bool $activeOnly = false): array {
    $sql = "SELECT * FROM service_categories WHERE business_id = ?";
    if ($activeOnly) $sql .= " AND is_active = 1";
    $sql .= " ORDER BY sort_order ASC, name ASC";
    $stmt = db()->prepare($sql);
    $stmt->execute([$businessId]);
    return $stmt->fetchAll();
}

function getServicesList(int $businessId, ?int $categoryId = null, bool $activeOnly = false): array {
    $sql = "SELECT s.*, c.name AS category_name FROM services s
            LEFT JOIN service_categories c ON c.id = s.category_id
            WHERE s.business_id = ?";
    $params = [$businessId];
    if ($categoryId) { $sql .= " AND s.category_id = ?"; $params[] = $categoryId; }
    if ($activeOnly) $sql .= " AND s.is_active = 1";
    $sql .= " ORDER BY s.sort_order ASC, s.name ASC";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getStaffList(int $businessId, bool $activeOnly = false): array {
    $sql = "SELECT * FROM staff WHERE business_id = ?";
    if ($activeOnly) $sql .= " AND is_active = 1";
    $sql .= " ORDER BY name ASC";
    $stmt = db()->prepare($sql);
    $stmt->execute([$businessId]);
    return $stmt->fetchAll();
}

function getServiceStaffIds(int $serviceId): array {
    $stmt = db()->prepare("SELECT staff_id FROM service_staff WHERE service_id = ?");
    $stmt->execute([$serviceId]);
    return array_map('intval', array_column($stmt->fetchAll(), 'staff_id'));
}

function ownsRecord(string $table, int $id, int $businessId): bool {
    $stmt = db()->prepare("SELECT id FROM `$table` WHERE id = ? AND business_id = ? LIMIT 1");
    $stmt->execute([$id, $businessId]);
    return (bool)$stmt->fetch();
}

const DAY_NAMES = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

function dayName(int $dow): string {
    return DAY_NAMES[$dow] ?? '';
}

// ─── Booking / Availability ────────────────────────────────────────────────────

function timeToMinutes(string $time): int {
    $parts = explode(':', $time);
    return ((int)$parts[0]) * 60 + (int)($parts[1] ?? 0);
}

function minutesToTime(int $minutes): string {
    $h = intdiv($minutes, 60) % 24;
    $m = $minutes % 60;
    return sprintf('%02d:%02d:00', $h, $m);
}

/**
 * Find an existing customer by phone for this business, or create a new one.
 */
function findOrCreateCustomer(int $businessId, string $phone, string $name = '', string $email = ''): int {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id FROM customers WHERE business_id = ? AND phone = ? LIMIT 1");
    $stmt->execute([$businessId, $phone]);
    $row = $stmt->fetch();

    if ($row) {
        if ($name !== '' || $email !== '') {
            $sets = []; $params = [];
            if ($name !== '')  { $sets[] = 'name = ?';  $params[] = $name; }
            if ($email !== '') { $sets[] = 'email = ?'; $params[] = $email; }
            if ($sets) {
                $params[] = $row['id'];
                $pdo->prepare("UPDATE customers SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
            }
        }
        return (int)$row['id'];
    }

    $stmt = $pdo->prepare("INSERT INTO customers (business_id, name, phone, email) VALUES (?,?,?,?)");
    $stmt->execute([$businessId, $name ?: null, $phone, $email ?: null]);
    return (int)$pdo->lastInsertId();
}

/**
 * Compute available booking slots for a service on a given date.
 * Returns array of ['time' => 'HH:MM:SS', 'label' => '9:00 AM', 'staff_id' => int|null]
 *
 * @param int    $businessId
 * @param int    $serviceId
 * @param string $date       'Y-m-d'
 * @param int    $staffId    0 = any eligible staff
 * @param int    $excludeAppointmentId  appointment id to ignore when checking conflicts (for editing)
 */
function getAvailableSlots(int $businessId, int $serviceId, string $date, int $staffId = 0, int $excludeAppointmentId = 0): array {
    $pdo = db();

    // Fetch business booking-mode settings
    $bStmt = $pdo->prepare("SELECT enable_parallel_bookings, time_required FROM businesses WHERE id = ?");
    $bStmt->execute([$businessId]);
    $bizOpts = $bStmt->fetch();
    $parallelMode = $bizOpts && (int)$bizOpts['enable_parallel_bookings'] === 1;

    $stmt = $pdo->prepare("SELECT * FROM services WHERE id = ? AND business_id = ?");
    $stmt->execute([$serviceId, $businessId]);
    $service = $stmt->fetch();
    if (!$service) return [];

    $duration   = (int)$service['duration'];
    $buffer     = (int)$service['buffer_time'];
    $totalBlock = $duration + $buffer;
    if ($totalBlock <= 0) return [];

    $dow = (int)date('w', strtotime($date));

    // Holiday check (exact date or recurring yearly)
    $stmt = $pdo->prepare("
        SELECT id FROM holidays
        WHERE business_id = ?
          AND (holiday_date = ? OR (is_recurring = 1 AND DATE_FORMAT(holiday_date, '%m-%d') = DATE_FORMAT(?, '%m-%d')))
        LIMIT 1
    ");
    $stmt->execute([$businessId, $date, $date]);
    if ($stmt->fetch()) return [];

    // Business hours for this day
    $stmt = $pdo->prepare("SELECT * FROM business_hours WHERE business_id = ? AND day_of_week = ?");
    $stmt->execute([$businessId, $dow]);
    $bh = $stmt->fetch();
    if (!$bh || !$bh['is_open']) return [];

    $openMin    = timeToMinutes($bh['open_time']);
    $closeMin   = timeToMinutes($bh['close_time']);
    $interval   = max(5, (int)$bh['slot_interval']);
    $breakStart = $bh['break_start'] ? timeToMinutes($bh['break_start']) : null;
    $breakEnd   = $bh['break_end']   ? timeToMinutes($bh['break_end'])   : null;

    // Eligible staff for this service
    $assignedStaffIds = getServiceStaffIds($serviceId);

    $candidateStaff = [];
    if ($staffId > 0) {
        if (!empty($assignedStaffIds) && !in_array($staffId, $assignedStaffIds, true)) {
            return []; // requested staff doesn't perform this service
        }
        $candidateStaff = [$staffId];
    } else {
        $candidateStaff = $assignedStaffIds; // may be empty = "any staff" / no staff constraint
    }

    // Pre-load staff schedules + leaves for candidates
    $staffSchedules = [];
    $staffOnLeave   = [];
    if (!empty($candidateStaff)) {
        $in = implode(',', array_fill(0, count($candidateStaff), '?'));

        $stmt = $pdo->prepare("SELECT * FROM staff_schedules WHERE day_of_week = ? AND staff_id IN ($in)");
        $stmt->execute(array_merge([$dow], $candidateStaff));
        foreach ($stmt->fetchAll() as $row) {
            $staffSchedules[(int)$row['staff_id']] = $row;
        }

        $stmt = $pdo->prepare("SELECT staff_id FROM staff_leaves WHERE leave_date = ? AND approved = 1 AND staff_id IN ($in)");
        $stmt->execute(array_merge([$date], $candidateStaff));
        foreach ($stmt->fetchAll() as $row) {
            $staffOnLeave[(int)$row['staff_id']] = true;
        }
    }

    // Existing appointments for this date (with their service buffer) to detect conflicts
    $sql = "
        SELECT a.staff_id, a.appointment_time, a.end_time, COALESCE(s.buffer_time, 0) AS buffer_time
        FROM appointments a
        LEFT JOIN services s ON s.id = a.service_id
        WHERE a.business_id = ? AND a.appointment_date = ? AND a.status NOT IN ('cancelled','no_show')
    ";
    $params = [$businessId, $date];
    if ($excludeAppointmentId > 0) {
        $sql .= " AND a.id != ?";
        $params[] = $excludeAppointmentId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $busyByStaff = []; // staff_id => [[start,end], ...]
    $busyAny     = []; // for "no staff constraint" services: all bookings regardless of staff
    foreach ($stmt->fetchAll() as $row) {
        $start = timeToMinutes($row['appointment_time']);
        $end   = timeToMinutes($row['end_time']) + (int)$row['buffer_time'];
        if (!empty($row['staff_id'])) {
            $busyByStaff[(int)$row['staff_id']][] = [$start, $end];
        } else {
            $busyAny[] = [$start, $end];
        }
    }

    $noStaffConstraint = empty($candidateStaff);
    $nowMin = (date('Y-m-d') === $date) ? ((int)date('G') * 60 + (int)date('i')) : -1;

    $slots = [];
    for ($t = $openMin; $t + $totalBlock <= $closeMin; $t += $interval) {
        $blockEnd = $t + $totalBlock;

        // Skip past times for today
        if ($nowMin >= 0 && $t <= $nowMin) continue;

        // Skip if overlapping break
        if ($breakStart !== null && $breakEnd !== null && $t < $breakEnd && $blockEnd > $breakStart) {
            continue;
        }

        $assignedStaff = null;

        if ($parallelMode && !$noStaffConstraint) {
            // Parallel mode: slot is available if fewer bookings exist than active staff for this service.
            $capacity    = count($candidateStaff);
            $bookedCount = 0;
            $allBusy     = $busyAny;
            foreach ($busyByStaff as $staffBusy) {
                $allBusy = array_merge($allBusy, $staffBusy);
            }
            foreach ($allBusy as [$bs, $be]) {
                if ($t < $be && $blockEnd > $bs) $bookedCount++;
            }
            if ($bookedCount >= $capacity) continue;
            // assignedStaff stays null; actual assignment happens at booking time
        } elseif ($noStaffConstraint) {
            $conflict = false;
            foreach ($busyAny as [$bs, $be]) {
                if ($t < $be && $blockEnd > $bs) { $conflict = true; break; }
            }
            if ($conflict) continue;
        } else {
            $found = false;
            foreach ($candidateStaff as $sid) {
                if (!empty($staffOnLeave[$sid])) continue;

                $sched = $staffSchedules[$sid] ?? null;
                if (!$sched || !$sched['is_working']) continue;

                $staffStart = timeToMinutes($sched['start_time']);
                $staffEnd   = timeToMinutes($sched['end_time']);
                if ($t < $staffStart || $blockEnd > $staffEnd) continue;

                $conflict = false;
                foreach ($busyByStaff[$sid] ?? [] as [$bs, $be]) {
                    if ($t < $be && $blockEnd > $bs) { $conflict = true; break; }
                }
                if ($conflict) continue;

                $found = true;
                $assignedStaff = $sid;
                break;
            }
            if (!$found) continue;
        }

        $timeStr = minutesToTime($t);
        $slots[] = [
            'time'     => $timeStr,
            'label'    => date('g:i A', strtotime($timeStr)),
            'staff_id' => $assignedStaff,
        ];
    }

    return $slots;
}
