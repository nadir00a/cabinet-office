<?php
require_once __DIR__ . '/config/config.php';
require_role(['manager', 'tax_declarant', 'semi_tax_declarant']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $stmt = $pdo->prepare(
        "INSERT INTO administration_controls (client_id, administration, documents_list, delivered_at) VALUES (?,?,?,?)"
    );
    $stmt->execute([$_POST['client_id'], $_POST['administration'], $_POST['documents_list'], $_POST['delivered_at']]);
    audit($pdo, 'control.create', 'administration_controls', (int) $pdo->lastInsertId());
    flash('success', 'تم تسجيل المراقبة.');
    redirect('/controls.php');
}

if (isset($_GET['return_id'])) {
    $pdo->prepare("UPDATE administration_controls SET returned_at = ? WHERE id = ?")
        ->execute([date('Y-m-d'), (int) $_GET['return_id']]);
    audit($pdo, 'control.mark_returned', 'administration_controls', (int) $_GET['return_id']);
    redirect('/controls.php');
}

$clients = $pdo->query("SELECT id, company_name FROM clients ORDER BY company_name")->fetchAll();
$rows = $pdo->query(
    "SELECT ac.*, c.company_name FROM administration_controls ac JOIN clients c ON c.id = ac.client_id
     ORDER BY (ac.returned_at IS NULL) DESC, ac.delivered_at DESC"
)->fetchAll();

$adminLabels = ['tax'=>'الضرائب','cacobatph'=>'CACOBATPH','casnos'=>'CASNOS','cnas'=>'CNAS','labor_inspection'=>'مفتشية العمل'];

$pageTitle = 'المراقبات';
include __DIR__ . '/includes/layout_header.php';
?>
<div class="card">
  <h3>تسجيل مراقبة جديدة</h3>
  <form method="post" class="grid2">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <div class="form-row"><label>الزبون</label>
      <select name="client_id" required>
        <?php foreach ($clients as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['company_name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-row"><label>الإدارة</label>
      <select name="administration" required>
        <?php foreach ($adminLabels as $k=>$l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-row" style="grid-column:1/-1"><label>الوثائق المسلَّمة</label><textarea name="documents_list" rows="2" required></textarea></div>
    <div class="form-row"><label>تاريخ التسليم</label><input type="date" name="delivered_at" required></div>
    <div class="form-row" style="align-self:end"><button class="btn" type="submit">تسجيل</button></div>
  </form>
</div>

<div class="card">
  <h3>سجل المراقبات</h3>
  <table>
    <thead><tr><th>الزبون</th><th>الإدارة</th><th>الوثائق</th><th>التسليم</th><th>الإرجاع</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= e($r['company_name']) ?></td>
        <td><?= $adminLabels[$r['administration']] ?? e($r['administration']) ?></td>
        <td class="muted"><?= e($r['documents_list']) ?></td>
        <td><?= e($r['delivered_at']) ?></td>
        <td><?= e($r['returned_at'] ?? '—') ?></td>
        <td><?php if (!$r['returned_at']): ?><a class="btn secondary" href="?return_id=<?= $r['id'] ?>">تسجيل الإرجاع</a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php include __DIR__ . '/includes/layout_footer.php'; ?>
