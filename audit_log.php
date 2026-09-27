<?php
require_once __DIR__ . '/config/config.php';
require_role(['manager']);

$rows = $pdo->query(
    "SELECT a.*, u.full_name FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
     ORDER BY a.created_at DESC LIMIT 200"
)->fetchAll();

$pageTitle = 'سجل العمليات';
include __DIR__ . '/includes/layout_header.php';
?>
<div class="card">
  <h3>سجل العمليات (غير قابل للحذف)</h3>
  <table>
    <thead><tr><th>التاريخ والساعة</th><th>الموظف</th><th>العملية</th><th>التفاصيل</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= e($r['created_at']) ?></td>
        <td><?= e($r['full_name'] ?? 'نظام') ?></td>
        <td><?= e($r['action']) ?> <span class="muted">(<?= e($r['entity_type']) ?>#<?= $r['entity_id'] ?>)</span></td>
        <td class="muted"><?= e($r['details']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php include __DIR__ . '/includes/layout_footer.php'; ?>
