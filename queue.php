<?php
require_once __DIR__ . '/config/config.php';
require_role(['manager', 'accountant']);

$pageTitle = 'قائمة الانتظار';

$items = $pdo->query(
    "SELECT q.*, c.company_name, u.full_name AS reserved_by_name
     FROM queue_items q
     JOIN clients c ON c.id = q.client_id
     LEFT JOIN users u ON u.id = q.reserved_by
     WHERE q.status IN ('waiting','reserved')
     ORDER BY q.created_at ASC"
)->fetchAll();

$typeLabels = ['bank_statement' => 'الكشف البنكي', 'purchase_invoice' => 'فواتير الشراء', 'sale_invoice' => 'فواتير البيع'];

include __DIR__ . '/includes/layout_header.php';
?>

<div class="card">
<h3>قائمة الانتظار (<?= count($items) ?>)</h3>
<table>
<thead><tr><th>الزبون</th><th>النوع</th><th>منذ</th><th>الحالة</th><th></th></tr></thead>
<tbody>
<?php foreach ($items as $item): ?>
  <tr>
    <td><?= e($item['company_name']) ?></td>
    <td><?= e($typeLabels[$item['item_type']] ?? $item['item_type']) ?></td>
    <td><?= days_since($item['created_at']) ?> يوم</td>
    <td>
      <?php if ($item['status'] === 'waiting'): ?>
        <span class="badge" style="background:#9aa4b1">في الانتظار</span>
      <?php else: ?>
        <span class="badge" style="background:#f5a524">محجوزة — <?= e($item['reserved_by_name']) ?></span>
      <?php endif; ?>
    </td>
    <td>
      <?php if ($item['status'] === 'waiting'): ?>
        <form method="post" action="<?= e(url('/queue_start.php')) ?>" style="display:inline">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="id" value="<?= $item['id'] ?>">
          <button class="btn" type="submit">بدء الحجز</button>
        </form>
      <?php elseif ($item['status'] === 'reserved' && $item['reserved_by'] == current_user()['id']): ?>
        <a class="btn" href="<?= e(url('/queue_confirm.php?id=' . (int)$item['id'])) ?>">تم الحجز</a>
      <?php else: ?>
        <span class="muted">—</span>
      <?php endif; ?>
    </td>
  </tr>
<?php endforeach; ?>
<?php if (!$items): ?><tr><td colspan="5" class="muted">لا توجد وثائق في الانتظار.</td></tr><?php endif; ?>
</tbody>
</table>
</div>

<?php include __DIR__ . '/includes/layout_footer.php'; ?>
