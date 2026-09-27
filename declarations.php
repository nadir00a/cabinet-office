<?php
require_once __DIR__ . '/config/config.php';
require_role(['manager', 'tax_declarant']);

$typeLabels = ['g50' => 'G50 الشهري'];

// تغيير الحالة أو تسجيل تقدّم
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role(['manager', 'tax_declarant']);
    csrf_check();
    $id = (int) $_POST['id'];
    $status = $_POST['status'];
    $filedAt = $status !== 'red' ? ($_POST['filed_at'] ?: date('Y-m-d H:i:s')) : null;
    $archivedAt = $status === 'green' ? ($_POST['archived_at'] ?: date('Y-m-d H:i:s')) : null;

    $pdo->prepare(
        "UPDATE tax_declarations SET status=?, sub_status=?, amount=?, filed_at=?, archived_at=? WHERE id=?"
    )->execute([$status, $_POST['sub_status'] ?: null, $_POST['amount'] ?: null, $filedAt, $archivedAt, $id]);

    audit($pdo, 'declaration.update_status', 'tax_declarations', $id, ['status' => $status]);
    flash('success', 'تم تحديث حالة التصريح.');
    redirect('/declarations.php?type=' . ($_GET['type'] ?? 'g50'));
}

$month = (int) ($_GET['m'] ?? date('n'));
$year = (int) ($_GET['y'] ?? date('Y'));
$type = 'g50';

// إنشاء صفوف G50 لهذا الشهر تلقائيًا لزبائن النظام الحقيقي إن لم توجد (§6.1)
$pdo->prepare(
    "INSERT INTO tax_declarations (client_id, declaration_type, period_month, period_year, status, legal_deadline)
     SELECT c.id, 'g50', ?, ?, 'red', ?
     FROM clients c WHERE c.tax_regime = 'reel'
       AND NOT EXISTS (SELECT 1 FROM tax_declarations d WHERE d.client_id=c.id
                        AND d.declaration_type='g50' AND d.period_month=? AND d.period_year=?)"
)->execute([$month, $year, date('Y-m-20', strtotime("$year-$month-01 +1 month")), $month, $year]);


$stmt = $pdo->prepare(
    "SELECT d.*, c.company_name, c.whatsapp_phone FROM tax_declarations d JOIN clients c ON c.id = d.client_id
     WHERE d.declaration_type = ? AND d.period_year = ? AND (d.period_month = ? OR d.period_month IS NULL)
     ORDER BY d.status, c.company_name"
);
$stmt->execute([$type, $year, $month]);
$rows = $stmt->fetchAll();

$counts = ['red' => 0, 'orange' => 0, 'green' => 0];
foreach ($rows as $r) $counts[$r['status']]++;

$pageTitle = 'الترصيحات الجبائية';
include __DIR__ . '/includes/layout_header.php';
?>
<div class="card">
  <h3>أنواع أخرى من التصريحات</h3>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a class="btn secondary" href="<?= e(url('/g12.php')) ?>">G12 (النظام الجزافي)</a>
    <a class="btn secondary" href="<?= e(url('/budget.php')) ?>">الميزانية</a>
    <a class="btn secondary" href="<?= e(url('/ibs_irg.php')) ?>">IBS / IRG السنوي</a>
    <a class="btn secondary" href="<?= e(url('/era.php')) ?>">ERA</a>
    <a class="btn secondary" href="<?= e(url('/taxe_apprentissage.php')) ?>">رسم التمهين</a>
  </div>
</div>

<div class="card">
  <h3>الترصيحات الجبائية</h3>
  <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px">
    <select name="type" onchange="this.form.submit()">
      <?php foreach ($typeLabels as $k => $lbl): ?>
        <option value="<?= $k ?>" <?= $type === $k ? 'selected' : '' ?>><?= $lbl ?></option>
      <?php endforeach; ?>
    </select>
    <input type="number" name="m" value="<?= $month ?>" min="1" max="12" style="width:70px">
    <input type="number" name="y" value="<?= $year ?>" style="width:90px">
    <button class="btn secondary" type="submit">تصفية</button>
  </form>
  <div class="grid4">
    <div class="stat"><b style="color:#e5484d"><?= $counts['red'] ?></b><span>أحمر</span></div>
    <div class="stat"><b style="color:#f5a524"><?= $counts['orange'] ?></b><span>برتقالي</span></div>
    <div class="stat"><b style="color:#2fa84f"><?= $counts['green'] ?></b><span>أخضر</span></div>
    <div class="stat"><b><?= count($rows) ?></b><span>الإجمالي</span></div>
  </div>
</div>

<div class="card">
  <table>
    <thead><tr><th>الزبون</th><th>الحالة</th><th>ملاحظة</th><th>المبلغ</th><th>تحديث</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= e($r['company_name']) ?></td>
        <td><span class="badge" style="background:<?= status_color($r['status']) ?>"><?= status_label($r['status']) ?></span></td>
        <td class="muted"><?= e($r['sub_status']) ?></td>
        <td><?= money($r['amount']) ?></td>
        <td style="display:flex;gap:6px;flex-wrap:wrap">
          <?php if ($r['whatsapp_phone'] && $r['amount']): ?>
            <a class="btn secondary" target="_blank" href="<?= e(whatsapp_link($r['whatsapp_phone'],
              'السلام عليكم، نتيجة تصريح G50 لشهر ' . month_label($month, $year) . ': مبلغ TVA الواجب دفعه ' . money($r['amount']) . '. يرجى التأكيد وإحضار الصك.'
            )) ?>">واتساب</a>
          <?php endif; ?>
          <form method="post" style="display:flex;gap:4px">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= $r['id'] ?>">
            <select name="status">
              <option value="red" <?= $r['status']==='red'?'selected':'' ?>>أحمر</option>
              <option value="orange" <?= $r['status']==='orange'?'selected':'' ?>>برتقالي</option>
              <option value="green" <?= $r['status']==='green'?'selected':'' ?>>أخضر</option>
            </select>
            <input name="sub_status" placeholder="ملاحظة" value="<?= e($r['sub_status']) ?>" style="width:120px">
            <input name="amount" type="number" step="0.01" placeholder="المبلغ" value="<?= e((string)$r['amount']) ?>" style="width:90px">
            <button class="btn secondary" type="submit">حفظ</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" class="muted">لا توجد تصريحات لهذه الفترة.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php include __DIR__ . '/includes/layout_footer.php'; ?>
