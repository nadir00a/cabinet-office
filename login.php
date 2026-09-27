<?php
require_once __DIR__ . '/config/config.php';
if (current_user()) redirect(home_page_for_role(current_user()['role_code']));
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if (attempt_login($pdo, $username, $password)) redirect(home_page_for_role(current_user()['role_code']));
    $error = 'اسم المستخدم أو كلمة المرور غير صحيحة.';
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>تسجيل الدخول — مكتب المحاسبة</title>
<link rel="stylesheet" href="<?= e(url('/assets/style.css')) ?>">
</head>
<body>
<div class="login-page">
  <div class="login-box">
    <div class="login-brand">
      <div class="brand-mark">م</div>
      <h2>مكتب المحاسبة</h2>
      <p>نظام إدارة الملفات والتصريحات والمتابعة</p>
    </div>
    <?php if ($error): ?><div class="alert error"><span>!</span><?= e($error) ?></div><?php endif; ?>
    <form method="post" autocomplete="on">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <div class="form-row"><label for="username">اسم المستخدم</label><input id="username" type="text" name="username" required autofocus autocomplete="username" placeholder="أدخل اسم المستخدم"></div>
      <div class="form-row"><label for="password">كلمة المرور</label><input id="password" type="password" name="password" required autocomplete="current-password" placeholder="أدخل كلمة المرور"></div>
      <button class="btn" style="width:100%" type="submit">تسجيل الدخول <span>←</span></button>
    </form>
  </div>
</div>
</body>
</html>
