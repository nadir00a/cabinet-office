<?php
/**
 * إعدادات الاتصال بقاعدة البيانات. عدّل هذه القيم الأربعة فقط.
 */
$DB_HOST = 'localhost';
$DB_NAME = 'cabinet-office';
$DB_USER = 'root';
$DB_PASS = '';

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    die('فشل الاتصال بقاعدة البيانات. تحقق من إعدادات config/db.php. تفاصيل: ' . htmlspecialchars($e->getMessage()));
}
