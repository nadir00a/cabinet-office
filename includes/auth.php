<?php
/**
 * دفتر الشروط §2: "يدخل كل مستخدم باسم مستخدم وكلمة مرور خاصين به."
 * الأدوار: manager, reception, accountant, tax_declarant,
 * semi_tax_declarant, client. هذا الملف يخص واجهة المكتب فقط
 * (الأدوار الخمسة الأولى) — تطبيق الزبون له مصادقة API منفصلة
 * (راجع الهيكل السابق في laravel-scaffold).
 */

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_login(): void
{
    if (!current_user()) {
        redirect('/login.php');
    }
}

/** يقبل قائمة أدوار مسموحة؛ يوقف التنفيذ بخطأ 403 إن لم يكن الدور من ضمنها */
function require_role(array $allowedRoles): void
{
    require_login();
    $user = current_user();
    if (!in_array($user['role_code'], $allowedRoles, true)) {
        http_response_code(403);
        die('لا تملك الصلاحية للوصول إلى هذه الصفحة.');
    }
}

function attempt_login(PDO $pdo, string $username, string $password): bool
{
    $stmt = $pdo->prepare(
        "SELECT u.id, u.username, u.password_hash, u.full_name, u.is_active, r.code AS role_code, r.label_ar
         FROM users u JOIN roles r ON r.id = u.role_id
         WHERE u.username = ? LIMIT 1"
    );
    $stmt->execute([$username]);
    $row = $stmt->fetch();

    if (!$row || !$row['is_active'] || !password_verify($password, $row['password_hash'])) {
        return false;
    }

    session_regenerate_id(true);

    $_SESSION['user'] = [
        'id' => $row['id'],
        'username' => $row['username'],
        'full_name' => $row['full_name'],
        'role_code' => $row['role_code'],
        'role_label' => $row['label_ar'],
    ];
    return true;
}

function logout(): void
{
    $_SESSION = [];
    session_destroy();
}

/** سجل العمليات: يُستدعى من كل عملية حساسة (حجز، نقاط، بوانتاج، تعديل مدير...) */
function audit(PDO $pdo, string $action, string $entityType, int $entityId, array $details = []): void
{
    $user = current_user();
    $stmt = $pdo->prepare(
        "INSERT INTO audit_log (user_id, action, entity_type, entity_id, details) VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->execute([$user['id'] ?? null, $action, $entityType, $entityId, json_encode($details, JSON_UNESCAPED_UNICODE)]);
}
