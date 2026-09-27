<?php

date_default_timezone_set('Africa/Algiers');
error_reporting(E_ALL);
ini_set('display_errors', '1'); // عطّل هذا في بيئة الإنتاج

if (session_status() === PHP_SESSION_NONE) {
    // مسار الكوكي يُشتق من مجلد التنصيب الفعلي، لا اسم ثابت، حتى يعمل
    // البرنامج مهما كان مسار النشر.
    $cookiePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    if ($cookiePath === '' || $cookiePath === '.') $cookiePath = '/';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $cookiePath,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
