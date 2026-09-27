<?php
require_once __DIR__ . '/config/config.php';
require_role(['manager', 'tax_declarant']);

$year = (int) ($_GET['y'] ?? date('Y'));

/**
 * §6.5 ERA: "ترصيح خاص بالمكتب دون تسديد، ولا يُرسل عنه أي إشعار للزبون.
 * عند رفع الترصيح ... يُحذف الإشعار ويُسَّجل: 'تم إيداع ERA يوم كذا على
 * الساعة كذا'." — لذلك هذه الصفحة لا تحتوي أي زر واتساب أو إشعار للزبون.
 */

$pdo->prepare(
    "INSERT INTO tax_declarations (client_id, declaration_type, period_year, status, legal_deadline)
     SELECT c.id, 'era', ?, 'red', ? FROM clients c
     WHERE NOT EXISTS (SELECT 1 FROM tax_declarations d WHERE d.client_id=c.id AND d.declaration_type='era' AND d.period_year=?)"
)->execute([$year, "$year-09-30", $year]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int) $_POST['id'];
    $now = new DateTime();
    $note = 'تم إيداع ERA يوم ' . $now->format('d/m/Y') . ' على الساعة ' . $now->format('H:i');
    $pdo->prepare("UPDATE tax_declarations SET status='green', sub_status=?, filed_at=NOW(), archived_at=NOW() WHERE id=?")
        ->execute([$note, $id]);
    audit($pdo, 'era.file', 'tax_declarations', $id);
    flash('success', $note);
    redirect('/era.php?y=' . $year);
}

$rows = $pdo->prepare(
    "SELECT d.*, c.company_name FROM tax_declarations d JOIN clients c ON c.id = d.client_id
     WHERE d.declaration_type='era' AND d.period_year=? ORDER BY d.status, c.company_name"
);
$rows->execute([$year]);
$rows = $rows->fetchAll();

$pageTitle = 'ERA';
include __DIR__ . '/includes/layout_header.php';
?>
<div class="card">
  <h3>ERA <?= $year ?> (قبل 30 سبتمبر) — لا إشعار للزبون أبدًا</h3>
  <table>
    <thead><tr><th>الزبون</th><th>الحالة</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= e($r['company_name']) ?></td>
        <td>
          <span class="badge" style="background:<?= status_color($r['status']) ?>"><?= status_label($r['status']) ?></span>
          <?php if ($r['sub_status']): ?><div class="muted"><?= e($r['sub_status']) ?></div><?php endif; ?>
        </td>
        <td>
          <?php if ($r['status'] !== 'green'): ?>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= $r['id'] ?>">
            <button class="btn" type="submit">تسجيل الإيداع</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php include __DIR__ . '/includes/layout_footer.php'; ?>
