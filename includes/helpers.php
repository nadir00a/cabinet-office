<?php

function e(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

function days_since(string $datetime): int
{
    $then = new DateTime($datetime);
    $now = new DateTime();
    return $now->diff($then)->days;
}

function money(?float $n): string
{
    if ($n === null) return '—';
    return number_format($n, 2) . ' دج';
}

/** أحمر/برتقالي/أخضر → لون CSS */
function status_color(string $status): string
{
    return match ($status) {
        'red' => '#e5484d',
        'orange' => '#f5a524',
        'green' => '#2fa84f',
        default => '#9aa4b1',
    };
}

function status_label(string $status): string
{
    return match ($status) {
        'red' => 'أحمر', 'orange' => 'برتقالي', 'green' => 'أخضر', default => $status,
    };
}

const ARABIC_MONTHS = [
    1 => 'جانفي', 2 => 'فيفري', 3 => 'مارس', 4 => 'أفريل', 5 => 'ماي', 6 => 'جوان',
    7 => 'جويلية', 8 => 'أوت', 9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر',
];

function month_label(int $m, int $y): string
{
    return (ARABIC_MONTHS[$m] ?? $m) . ' ' . $y;
}

/** رابط واتساب بمرحلته الأولى: زر يفتح واتساب برسالة جاهزة (§10) */
function whatsapp_link(?string $phone, string $message): string
{
    $phone = preg_replace('/\D/', '', $phone ?? '');
    return 'https://wa.me/' . $phone . '?text=' . rawurlencode($message);
}

/**
 * قراءة قيمة من إعدادات الآجال القابلة للتعديل (§9)، دون تدخل مطوّر.
 * يقرأ القيمة المخزَّنة في deadline_settings.value_json، بمفتاح 'value'
 * (كما تحفظه صفحة deadlines_settings.php) أو أول قيمة في الكائن.
 */
function get_setting(PDO $pdo, string $key, $default = null)
{
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];

    $stmt = $pdo->prepare("SELECT value_json FROM deadline_settings WHERE deadline_key = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    if (!$row) return $cache[$key] = $default;

    $decoded = json_decode($row['value_json'], true);
    if (is_array($decoded)) {
        $value = $decoded['value'] ?? reset($decoded);
    } else {
        $value = $decoded;
    }
    return $cache[$key] = ($value ?? $default);
}

function home_page_for_role(string $roleCode): string
{
    // كل دور له صفحة انطلاق مطابقة لصلاحيته (§2) بدل لوحة المدير للجميع
    return match ($roleCode) {
        'manager' => '/dashboard.php',
        'reception' => '/clients.php',
        'accountant' => '/queue.php',
        'tax_declarant' => '/declarations.php',
        'semi_tax_declarant' => '/semi_declarations.php',
        default => '/login.php',
    };
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check(): void
{
    $token = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(419);
        die('انتهت صلاحية الجلسة، أعد تحميل الصفحة وحاول مجددًا.');
    }
}

function url(string $path = ''): string
{
    // يُكتشف مجلد التنصيب تلقائيًا (بدل تثبيت "/cabinet-office") حتى يعمل
    // البرنامج مهما كان اسم المجلد أو كان في جذر الموقع.
    static $base = null;
    if ($base === null) {
        $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        if ($base === '.') $base = '';
    }
    if ($path === '') return $base . '/';
    if (preg_match('#^https?://#i', $path)) return $path;
    return $base . '/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

function flash(string $key, ?string $message = null)
{
    if ($message !== null) {
        $_SESSION['flash'][$key] = $message;
        return null;
    }
    $msg = $_SESSION['flash'][$key] ?? null;
    unset($_SESSION['flash'][$key]);
    return $msg;
}
