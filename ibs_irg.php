<?php
require_once __DIR__ . '/config/config.php';
require_role(['manager', 'tax_declarant']);

$year = (int) ($_GET['y'] ?? date('Y'));

/**
 * §6.4: IBS (أشخاص معنويون) و IRG (أشخاص طبيعيون بالنظام الحقيقي)، قبل 20 ماي.
 * "بعد إيداع الميزانية يطلب النظام من المكتب إدخال المبلغ، ثم يُرسل إشعار
 * إلى الزبون بالمبلغ الواجب دفعه. يُمسح وصل التسديد وتتوقف الإشعارات."
 * لذلك تظهر هنا فقط قائمة زبائن النظام الحقيقي الذين أودعوا الميزانية (أخضر).
 */
$stmt = $pdo->prepare(
    "SELECT c.id, c.company_name, c.legal_status, c.whatsapp_phone,
        i.id AS ibs_id, i.amount, i.status, i.archived_at
     FROM clients c
     JOIN tax_declarations b ON b.client_id = c.id AND b.declaration_type='budget'
        AND b.period_year = ? AND b.status = 'green'
     LEFT JOIN tax_declarations i ON i.client_id = c.id AND i.declaration_type='ibs_irg_annuel'
        AND i.period_year = ?
     WHERE c.tax_regime = 'reel'
     ORDER BY c.company_name"
);
$stmt->execute([$year, $year]);
$rows = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'set_amount') {
    csrf_check();
    $clientId = (int) $_POST['client_id'];
    $amount = (float) $_POST['amount'];

    $existing = $pdo->prepare(
        "SELECT id FROM tax_declarations WHERE client_id=? AND declaration_type='ibs_irg_annuel' AND period_year=?"
    );
    $existing->execute([$clientId, $year]);

    if ($id = $existing->fetchColumn()) {
        $pdo->prepare("UPDATE tax_declarations SET amount=?, status='orange', filed_at=NOW() WHERE id=?")
            ->execute([$amount, $id]);
    } else {
        $pdo->prepare(
            "INSERT INTO tax_declarations (client_id, declaration_type, period_year, status, amount, filed_at, legal_deadline)
             VALUES (?, 'ibs_irg_annuel', ?, 'orange', ?, NOW(), ?)"
        )->execute([$clientId, $year, $amount, "$year-05-20"]);
    }
    audit($pdo, 'ibs_irg.set_amount', 'clients', $clientId, ['amount' => $amount]);
    flash('success', 'تم تسجيل المبلغ. أرسل إشعارًا للزبون عبر زر واتساب.');
    redirect('/ibs_irg.php?y=' . $year);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'mark_paid') {
    csrf_check();
    $id = (int) $_POST['id'];
    $pdo->prepare("UPDATE tax_declarations SET status='green', archived_at=NOW() WHERE id=?")->execute([$id]);
    audit($pdo, 'ibs_irg.mark_paid', 'tax_declarations', $id);
    flash('success', 'تم تسجيل التسديد. تتوقف الإشعارات.');
    redirect('/ibs_irg.php?y=' . $year);
}

$pageTitle = 'IBS / IRG السنوي';
include __DIR__ . '/includes/layout_header.php';
?>
<div class="card">
  <h3>IBS / IRG <?= $year ?> (قبل 20 ماي) — بعد إيداع الميزانية فقط</h3>
  <table>
    <thead><tr><th>الزبون</th><th>النوع</th><th>المبلغ</th><th>الحالة</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $isIbs = $r['legal_status'] === 'moral'; ?>
      <tr>
        <td><?= e($r['company_name']) ?></td>
        <td><?= $isIbs ? 'IBS' : 'IRG' ?></td>
        <td><?= money($r['amount'] ?? null) ?></td>
        <td><?= $r['status'] ? '<span class="badge" style="background:'.status_color($r['status']).'">'.status_label($r['status']).'</span>' : '<span class="muted">لم يُدخَل المبلغ</span>' ?></td>
        <td style="display:flex;gap:4px;flex-wrap:wrap">
          <?php if (!$r['amount']): ?>
            <form method="post" style="display:flex;gap:4px">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="set_amount">
              <input type="hidden" name="client_id" value="<?= $r['id'] ?>">
              <input type="number" step="0.01" name="amount" placeholder="المبلغ" required style="width:110px">
              <button class="btn secondary" type="submit">تسجيل</button>
            </form>
          <?php else: ?>
            <?php if ($r['whatsapp_phone']): ?>
              <a class="btn secondary" target="_blank" href="<?= e(whatsapp_link($r['whatsapp_phone'],
                'السلام عليكم، مبلغ ' . ($isIbs ? 'IBS' : 'IRG') . ' السنوي الواجب دفعه: ' . money($r['amount']) . '. يرجى إحضار الصك قبل 20 ماي.'
              )) ?>">واتساب</a>
            <?php endif; ?>
            <?php if ($r['status'] !== 'green'): ?>
              <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="mark_paid">
                <input type="hidden" name="id" value="<?= $r['ibs_id'] ?>">
                <button class="btn" type="submit">مسح وصل التسديد</button>
              </form>
            <?php endif; ?>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" class="muted">لا يوجد زبائن أودعوا الميزانية بعد.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php include __DIR__ . '/includes/layout_footer.php'; ?>
