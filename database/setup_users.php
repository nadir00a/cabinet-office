<?php
/**
 * شغّل هذا الملف مرة واحدة فقط من المتصفح بعد استيراد schema.sql
 * (مثلاً: http://localhost:8000/database/setup_users.php)
 * ثم احذفه أو انقله خارج المجلد العام لأسباب أمنية.
 *
 * لماذا هذا الملف منفصل: password_hash() يجب أن يُنفَّذ بواسطة PHP
 * الفعلي المُثبَّت عندك حتى يتوافق مع password_verify() في auth.php.
 * لا يمكن توليد bcrypt hash صحيح داخل بيئة إنشاء هذا الكود.
 */
require_once __DIR__ . '/../config/db.php';

$defaultPassword = 'password123';
$hash = password_hash($defaultPassword, PASSWORD_BCRYPT);

$users = [
    ['role' => 'manager',       'username' => 'manager',     'name' => 'المدير'],
    ['role' => 'accountant',    'username' => 'accountant1', 'name' => 'محاسب 1'],
    ['role' => 'accountant',    'username' => 'accountant2', 'name' => 'محاسب 2'],
    ['role' => 'reception',     'username' => 'reception1',  'name' => 'الاستقبال'],
];

$stmt = $pdo->prepare(
    "INSERT INTO users (role_id, username, password_hash, full_name)
     SELECT id, ?, ?, ? FROM roles WHERE code = ?
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)"
);

foreach ($users as $u) {
    $stmt->execute([$u['username'], $hash, $u['name'], $u['role']]);
    echo "تم إنشاء/تحديث: {$u['username']} (كلمة المرور: {$defaultPassword})<br>";
}

echo "<hr>انتهى. غيّر كلمات المرور من صفحة الإعدادات، ثم احذف هذا الملف.";
