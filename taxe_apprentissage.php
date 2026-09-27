<?php
require_once __DIR__ . '/config/config.php';
require_role(['manager', 'tax_declarant']);

$year = (int) ($_GET['y'] ?? date('Y'));
$semester = (int) ($_GET['s'] ?? (date('n') <= 6 ? 1 : 2));

/**
 * §6.6: السداسي الأول (جانفي-جوان) يُسدَّد 1-20 جويلية.
 * السداسي الثاني (جويلية-ديسمبر) يُسدَّد 1 جانفي - 20 فيفري.
 * "يخص الزبائن الذين لديهم عمال خلال السداسي" فقط.
 */
[$from, $to] = $semester === 1 ? ["$year-01-01", "$year-06-30"] : ["$year-07-01", "$year-12-31"];
$periodRef = "$year-S$semester";

// الزبائن المؤهَّلون: من كان له عامل واحد نشط على الأقل خلال السداسي
$eligible = $pdo->prepare(
    "SELECT DISTINCT c.id, c.company_name, c.whatsapp_phone
     FROM clients c JOIN workers w ON w.client_id = c.id
     WHERE w.entry_date <= ? AND (w.exit_date IS NULL OR w.exit_date >= ?)
     ORDER BY c.company_name"
);
$eligible->execute([$to, $from]);
$eligible = $eligible->fetchAll();

// إنشاء صفوف تصريح لهذا السداسي إن لم توجد
foreach ($eligible as $c) {
    $exists = $pdo->prepare(
        "SELECT id FROM tax_declarations WHERE client_id=? AND declaration_type='taxe_apprentissage' AND sub_status=?"
    );
    $exists->execute([$c['id'], $periodRef]);
    if (!$exists->fetchColumn()) {
        $pdo->prepare(
            "INSERT INTO tax_declarations (client_id, declaration_type, period_year, sub_status, status)
             VALUES (?, 'taxe_apprentissage', ?, ?, 'red')"
        )->execute([$c['id'], $year, $periodRef]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int) $_POST['id'];
    $amount = (float) $_POST['amount'];
    $pdo->prepare("UPDATE tax_declarations SET amount=?, status='orange', filed_at=NOW() WHERE id=?")
        ->execute([$amount, $id]);
    audit($pdo, 'taxe_apprentissage.set_amount', 'tax_declarations', $id, ['amount' => $amount]);
    flash('success', 'تم تسجيل المبلغ.');
    redirect("/taxe_apprentissage.php?y=$year&s=$semester");
}
if (isset($_GET['mark_paid'])) {
    $id = (int) $_GET['mark_paid'];
    $pdo->prepare("UPDATE tax_declarations SET status='green', archived_at=NOW() WHERE id=?")->execute([$id]);
    audit($pdo, 'taxe_apprentissage.mark_paid', 'tax_declarations', $id);
    redirect("/taxe_apprentissage.php?y=$year&s=$semester");
}

$rows = $pdo->prepare(
    "SELECT d.*, c.company_name, c.whatsapp_phone FROM tax_declarations d JOIN clients c ON c.id=d.client_id
     WHERE d.declaration_type='taxe_apprentissage' AND d.sub_status=? ORDER BY d.status, c.company_name"
);
$rows->execute([$periodRef]);
$rows = $rows->fetchAll();

$dueLabel = $semester === 1 ? "1 - 20 جويلية $year" : "1 جانفي - 20 فيفري " . ($year + 1);

$pageTitle = 'رسم التمهين';
include __DIR__ . '/includes/layout_header.php';
?>
<div class="card">
  <h3>رسم التمهين — السداسي <?= $semester ?> لسنة <?= $year ?> (يُسدَّد <?= $dueLabel ?>)</h3>
  <form method="get" style="display:flex;gap:8px;margin-bottom:10px">
    <input type="number" name="y" value="<?= $year ?>" style="width:90px">
    <select name="s"><option value="1" <?= $semester===1?'selected':'' ?>>السداسي الأول</option><option value="2" <?= $semester===2?'selected':'' ?>>السداسي الثاني</option></select>
    <button class="btn secondary" type="submit">عرض</button>
  </form>
  <p class="muted">يخص فقط الزبائن الذين كان لديهم عامل نشط خلال هذا السداسي (<?= count($eligible) ?> زبون).</p>
  <table>
    <thead><tr><th>الزبون</th><th>المبلغ</th><th>الحالة</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= e($r['company_name']) ?></td>
        <td><?= money($r['amount']) ?></td>
        <td><span class="badge" style="background:<?= status_color($r['status']) ?>"><?= status_label($r['status']) ?></span></td>
        <td style="display:flex;gap:4px;flex-wrap:wrap">
          <?php if (!$r['amount']): ?>
            <form method="post" style="display:flex;gap:4px">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="id" value="<?= $r['id'] ?>">
              <input type="number" step="0.01" name="amount" placeholder="المبلغ" required style="width:100px">
              <button class="btn secondary" type="submit">تسجيل</button>
            </form>
          <?php else: ?>
            <?php if ($r['whatsapp_phone']): ?>
              <a class="btn secondary" target="_blank" href="<?= e(whatsapp_link($r['whatsapp_phone'], 'السلام عليكم، مبلغ رسم التمهين الواجب دفعه: ' . money($r['amount']) . '. يرجى إحضار الصك قبل ' . $dueLabel . '.')) ?>">واتساب</a>
            <?php endif; ?>
            <?php if ($r['status'] !== 'green'): ?>
              <a class="btn" href="?y=<?= $year ?>&s=<?= $semester ?>&mark_paid=<?= $r['id'] ?>">مسح وصل التسديد</a>
            <?php endif; ?>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php include __DIR__ . '/includes/layout_footer.php'; ?>
