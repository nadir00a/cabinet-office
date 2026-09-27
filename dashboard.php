<?php
require_once __DIR__ . '/config/config.php';
require_role(['manager']);

$waiting = $pdo->query("SELECT COUNT(*) FROM queue_items WHERE status='waiting'")->fetchColumn();
$oldestDays = $pdo->query(
    "SELECT TIMESTAMPDIFF(DAY, MIN(created_at), NOW()) FROM queue_items WHERE status='waiting'"
)->fetchColumn();
$g50NotValidated = $pdo->query(
    "SELECT COUNT(*) FROM tax_declarations WHERE declaration_type='g50' AND status != 'green'
     AND period_month = MONTH(CURDATE()) AND period_year = YEAR(CURDATE())"
)->fetchColumn();
$pointageMissing = $pdo->query(
    "SELECT COUNT(*) FROM clients c WHERE NOT EXISTS (
        SELECT 1 FROM pointages p WHERE p.client_id = c.id AND p.period_month = MONTH(CURDATE())
        AND p.period_year = YEAR(CURDATE()) AND p.status IN ('submitted','locked'))"
)->fetchColumn();
$pendingRequests = $pdo->query("SELECT COUNT(*) FROM admin_requests WHERE status='processing'")->fetchColumn();
$underControl = $pdo->query("SELECT COUNT(*) FROM administration_controls WHERE returned_at IS NULL")->fetchColumn();

// §4.2: تنبيه تأخر ملف عامل جديد (المدة قابلة للتعديل، 7 أيام افتراضيًا)
$workerFileAlertDays = (int) get_setting($pdo, 'new_worker_file_alert_days', 7);
$lateWorkerFiles = $pdo->prepare(
    "SELECT COUNT(*) FROM workers WHERE cnas_affiliation_status != 'affiliated'
     AND file_submitted_at IS NOT NULL AND file_submitted_at <= DATE_SUB(NOW(), INTERVAL ? DAY)"
);
$lateWorkerFiles->execute([$workerFileAlertDays]);
$lateWorkerFiles = (int) $lateWorkerFiles->fetchColumn();

// §8.1: مستخرجات جدول الضرائب تنتهي صلاحيتها خلال 15 يومًا
$expiringExtracts = (int) $pdo->query(
    "SELECT COUNT(*) FROM admin_requests WHERE doc_type='مستخرج جدول الضرائب' AND status='issued'
     AND expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 15 DAY)"
)->fetchColumn();


$perEmployee = $pdo->query(
    "SELECT u.full_name,
        SUM(CASE WHEN p.status='temporary' THEN 1 ELSE 0 END) AS in_progress,
        SUM(CASE WHEN p.status='validated' THEN 1 ELSE 0 END) AS done,
        SUM(CASE WHEN p.status='cancelled' THEN 1 ELSE 0 END) AS cancelled
     FROM users u LEFT JOIN points p ON p.user_id = u.id
        AND p.period_month = MONTH(CURDATE()) AND p.period_year = YEAR(CURDATE())
     JOIN roles r ON r.id = u.role_id AND r.code IN ('accountant','reception')
     GROUP BY u.id, u.full_name"
)->fetchAll();

$pageTitle = 'لوحة المدير';
include __DIR__ . '/includes/layout_header.php';
?>
<div class="grid4">
  <div class="stat"><b><?= $waiting ?></b><span>وثائق في الانتظار</span></div>
  <div class="stat"><b><?= $oldestDays ?? 0 ?></b><span>أقدم وثيقة (أيام)</span></div>
  <div class="stat"><b><?= $g50NotValidated ?></b><span>G50 غير مصادَق عليه</span></div>
  <div class="stat"><b><?= $pointageMissing ?></b><span>بوانتاج غير مستلم</span></div>
  <div class="stat"><b><?= $pendingRequests ?></b><span>طلبات لدى الإدارات</span></div>
  <div class="stat"><b><?= $underControl ?></b><span>ملفات لدى المراقبة</span></div>
  <div class="stat"><b style="color:<?= $lateWorkerFiles ? '#e5484d' : 'inherit' ?>"><?= $lateWorkerFiles ?></b><span>ملفات عمال متأخرة</span></div>
  <div class="stat"><b style="color:<?= $expiringExtracts ? '#f5a524' : 'inherit' ?>"><?= $expiringExtracts ?></b><span>مستخرجات تنتهي قريبًا</span></div>
</div>

<div class="card" style="margin-top:14px">
  <h3>إنتاج الموظفين هذا الشهر</h3>
  <table>
    <thead><tr><th>الموظف</th><th>قيد العمل</th><th>منجز</th><th>مُلغى (غش)</th></tr></thead>
    <tbody>
    <?php foreach ($perEmployee as $r): ?>
      <tr><td><?= e($r['full_name']) ?></td><td><?= $r['in_progress'] ?? 0 ?></td><td><?= $r['done'] ?? 0 ?></td>
        <td><?= $r['cancelled'] ?? 0 ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php include __DIR__ . '/includes/layout_footer.php'; ?>
