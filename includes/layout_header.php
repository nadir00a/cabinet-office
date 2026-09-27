<?php
/** @var array|null $user */
$user = current_user();
$currentPage = basename($_SERVER['SCRIPT_NAME']);
$navItems = [
    ['dashboard.php', 'لوحة التحكم', '▦', ['manager']],
    ['queue.php', 'قائمة الانتظار', '◫', ['manager','accountant']],
    ['points.php', 'النقاط', '★', ['manager','accountant']],
    ['clients.php', 'الزبائن', '♙', ['manager','reception','tax_declarant','semi_tax_declarant']],
    ['declarations.php', 'G50 الشهري', '◈', ['manager','tax_declarant']],
    ['g12.php', 'G12 (الجزافي)', '◈', ['manager','tax_declarant']],
    ['budget.php', 'الميزانية', '◈', ['manager','tax_declarant']],
    ['ibs_irg.php', 'IBS / IRG', '◈', ['manager','tax_declarant']],
    ['era.php', 'ERA', '◈', ['manager','tax_declarant']],
    ['taxe_apprentissage.php', 'رسم التمهين', '◈', ['manager','tax_declarant']],
    ['semi_declarations.php', 'شبه الجبائية', '◇', ['manager','semi_tax_declarant']],
    ['requests.php', 'طلبات الإدارات', '✓', ['manager','tax_declarant']],
    ['controls.php', 'المراقبات', '⌁', ['manager','tax_declarant','semi_tax_declarant']],
];
$managerItems = [
    ['fraud_controls.php', 'المراقبة العشوائية', '⚑'],
    ['deadlines_settings.php', 'الآجال والتمديدات', '◷'],
    ['audit_log.php', 'سجل العمليات', '≡'],
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= isset($pageTitle) ? e($pageTitle) . ' — ' : '' ?>مكتب المحاسبة</title>
<link rel="stylesheet" href="<?= e(url('/assets/style.css')) ?>">
</head>
<body>
<div class="app-shell">
  <aside class="sidebar" id="sidebar">
    <div class="brand">
      <div class="brand-mark">م</div>
      <div><strong>مكتب المحاسبة</strong><span>نظام الإدارة</span></div>
    </div>
    <nav class="sidebar-nav">
      <div class="nav-caption">الرئيسية</div>
      <?php foreach ($navItems as [$file,$label,$icon,$roles]): ?>
        <?php if (in_array($user['role_code'], $roles, true)): ?>
          <a href="<?= e(url('/'.$file)) ?>" class="nav-link <?= $currentPage === $file || ($file==='clients.php' && $currentPage==='client_view.php') ? 'active' : '' ?>">
            <span class="nav-icon"><?= $icon ?></span><span><?= e($label) ?></span>
          </a>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php if ($user['role_code'] === 'manager'): ?>
        <div class="nav-caption">الإدارة</div>
        <?php foreach ($managerItems as [$file,$label,$icon]): ?>
          <a href="<?= e(url('/'.$file)) ?>" class="nav-link <?= $currentPage === $file ? 'active' : '' ?>">
            <span class="nav-icon"><?= $icon ?></span><span><?= e($label) ?></span>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </nav>
    <div class="sidebar-footer">
      <a class="logout-link" href="<?= e(url('/logout.php')) ?>"><span>↪</span> تسجيل الخروج</a>
    </div>
  </aside>

  <div class="main-area">
    <header class="topbar">
      <button class="menu-toggle" type="button" onclick="document.body.classList.toggle('sidebar-open')" aria-label="فتح القائمة">☰</button>
      <div class="topbar-title">
        <span class="eyebrow">نظام مكتب المحاسبة</span>
        <h1><?= e($pageTitle ?? 'لوحة التحكم') ?></h1>
      </div>
      <div class="user-menu">
        <div class="avatar"><?= e(mb_strtoupper(mb_substr($user['full_name'] ?? 'م',0,1,'UTF-8'),'UTF-8')) ?></div>
        <div class="user-info"><strong><?= e($user['full_name'] ?? '') ?></strong><span><?= e($user['role_label'] ?? '') ?></span></div>
      </div>
    </header>
    <main class="container">
      <?php if ($m = flash('success')): ?><div class="alert success"><span>✓</span><?= e($m) ?></div><?php endif; ?>
      <?php if ($m = flash('error')): ?><div class="alert error"><span>!</span><?= e($m) ?></div><?php endif; ?>
