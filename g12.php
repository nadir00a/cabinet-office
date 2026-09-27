<?php
require_once __DIR__ . '/config/config.php';
require_role(['manager', 'tax_declarant']);

$year = (int) ($_GET['y'] ?? date('Y'));

/**
 * §6.2 (النظام الجزافي):
 * - G12 التقديري قبل 30 جوان: دفع كامل أو 3 دفعات (50% / 25% / 25%) في 30 جوان / 15 سبتمبر / 15 ديسمبر.
 * - G12 bis النهائي قبل 20 جانفي: برقم الأعمال النهائي للسنة ن-1.
 * - السنة لا تصبح خضراء إلا بإيداع الاثنين + تسديد كل الدفعات + أرشفة الوصوالت.
 */

// إنشاء صفوف G12 التقديري لهذه السنة لكل الزبائن الجزافيين إن لم توجد
$pdo->prepare(
    "INSERT INTO tax_declarations (client_id, declaration_type, period_year, status, legal_deadline)
     SELECT c.id, 'g12_provisoire', ?, 'red', ?
     FROM clients c WHERE c.tax_regime = 'forfaitaire'
       AND NOT EXISTS (SELECT 1 FROM tax_declarations d WHERE d.client_id=c.id
                        AND d.declaration_type='g12_provisoire' AND d.period_year=?)"
)->execute([$year, "$year-06-30", $year]);

// تعيين طريقة الدفع وإنشاء الدفعات
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'set_payment_method') {
    csrf_check();
    $id = (int) $_POST['id'];
    $method = $_POST['method']; // full | installments
    $amount = (float) $_POST['total_amount'];

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE tax_declarations SET amount=?, status='orange', filed_at=NOW() WHERE id=?")
            ->execute([$amount, $id]);
        // نظّف أي دفعات قديمة (حالة نادرة: تغيير طريقة الدفع)
        $pdo->prepare("DELETE FROM g12_installments WHERE tax_declaration_id=?")->execute([$id]);

        if ($method === 'full') {
            $pdo->prepare(
                "INSERT INTO g12_installments (tax_declaration_id, installment_no, due_date, percentage, amount)
                 VALUES (?,1,?,100,?)"
            )->execute([$id, "$year-06-30", $amount]);
        } else {
            $schedule = [
                [1, "$year-06-30", 50],
                [2, "$year-09-15", 25],
                [3, "$year-12-15", 25],
            ];
            $ins = $pdo->prepare(
                "INSERT INTO g12_installments (tax_declaration_id, installment_no, due_date, percentage, amount) VALUES (?,?,?,?,?)"
            );
            foreach ($schedule as [$no, $due, $pct]) {
                $ins->execute([$id, $no, $due, $pct, round($amount * $pct / 100, 2)]);
            }
        }
        audit($pdo, 'g12.set_payment_method', 'tax_declarations', $id, ['method' => $method, 'amount' => $amount]);
        $pdo->commit();
        flash('success', 'تم تسجيل رقم الأعمال التقديري وجدول الدفعات.');
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash('error', 'خطأ: ' . $e->getMessage());
    }
    redirect('/g12.php?y=' . $year);
}

// تسجيل تسديد دفعة + مسح الوصل
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'pay_installment') {
    csrf_check();
    $instId = (int) $_POST['installment_id'];
    $pdo->prepare("UPDATE g12_installments SET paid_at=NOW(), receipt_scanned=1 WHERE id=?")->execute([$instId]);
    audit($pdo, 'g12.installment_paid', 'g12_installments', $instId);

    // إعادة تقييم لون التصريح: أخضر فقط إذا كل الدفعات مسددة + bis النهائي مودع
    $decl = $pdo->prepare(
        "SELECT tax_declaration_id FROM g12_installments WHERE id=?"
    );
    $decl->execute([$instId]);
    $declId = $decl->fetchColumn();
    recompute_g12_color($pdo, (int) $declId);

    flash('success', 'تم تسجيل تسديد الدفعة.');
    redirect('/g12.php?y=' . $year);
}

// إيداع G12 bis النهائي
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'file_bis') {
    csrf_check();
    $clientId = (int) $_POST['client_id'];
    $finalAmount = (float) $_POST['final_amount'];
    $prevYear = $year - 1; // النهائي لسنة ن-1 يودع قبل 20 جانفي من السنة ن

    $pdo->beginTransaction();
    $existing = $pdo->prepare(
        "SELECT id FROM tax_declarations WHERE client_id=? AND declaration_type='g12_bis_final' AND period_year=?"
    );
    $existing->execute([$clientId, $prevYear]);
    if ($existingId = $existing->fetchColumn()) {
        $pdo->prepare("UPDATE tax_declarations SET amount=?, filed_at=NOW(), status='orange' WHERE id=?")
            ->execute([$finalAmount, $existingId]);
    } else {
        $pdo->prepare(
            "INSERT INTO tax_declarations (client_id, declaration_type, period_year, status, amount, filed_at, legal_deadline)
             VALUES (?, 'g12_bis_final', ?, 'orange', ?, NOW(), ?)"
        )->execute([$clientId, $prevYear, $finalAmount, "$year-01-20"]);
    }
    $pdo->commit();

    audit($pdo, 'g12_bis.file', 'clients', $clientId, ['final_amount' => $finalAmount, 'for_year' => $prevYear]);

    // إعادة تقييم لون G12 التقديري لسنة ن-1 (لأن bis يخص آخر سنة في النظام الجزافي)
    $provDecl = $pdo->prepare(
        "SELECT id FROM tax_declarations WHERE client_id=? AND declaration_type='g12_provisoire' AND period_year=?"
    );
    $provDecl->execute([$clientId, $prevYear]);
    if ($id = $provDecl->fetchColumn()) recompute_g12_color($pdo, (int) $id);

    flash('success', 'تم إيداع G12 bis النهائي.');
    redirect('/g12.php?y=' . $year);
}

function recompute_g12_color(PDO $pdo, int $declId): void
{
    $unpaid = $pdo->prepare(
        "SELECT COUNT(*) FROM g12_installments WHERE tax_declaration_id=? AND (paid_at IS NULL OR receipt_scanned=0)"
    );
    $unpaid->execute([$declId]);
    $stillUnpaid = (int) $unpaid->fetchColumn();

    $decl = $pdo->prepare("SELECT client_id, period_year FROM tax_declarations WHERE id=?");
    $decl->execute([$declId]);
    $d = $decl->fetch();
    if (!$d) return;

    $bis = $pdo->prepare(
        "SELECT status FROM tax_declarations WHERE client_id=? AND declaration_type='g12_bis_final' AND period_year=?"
    );
    $bis->execute([$d['client_id'], $d['period_year']]);
    $bisFiled = (bool) $bis->fetchColumn();

    // أخضر فقط: كل الدفعات مسدَّدة والوصل ممسوح + bis النهائي مودع (§6.2)
    $newStatus = ($stillUnpaid === 0 && $bisFiled) ? 'green' : 'orange';
    $pdo->prepare("UPDATE tax_declarations SET status=? WHERE id=?")->execute([$newStatus, $declId]);
}

$rows = $pdo->prepare(
    "SELECT d.*, c.company_name, c.whatsapp_phone FROM tax_declarations d JOIN clients c ON c.id = d.client_id
     WHERE d.declaration_type = 'g12_provisoire' AND d.period_year = ? ORDER BY d.status, c.company_name"
);
$rows->execute([$year]);
$rows = $rows->fetchAll();

$installmentsByDecl = [];
if ($rows) {
    $ids = array_column($rows, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $inst = $pdo->prepare("SELECT * FROM g12_installments WHERE tax_declaration_id IN ($in) ORDER BY installment_no");
    $inst->execute($ids);
    foreach ($inst->fetchAll() as $i) $installmentsByDecl[$i['tax_declaration_id']][] = $i;
}

$pageTitle = 'G12';
include __DIR__ . '/includes/layout_header.php';
?>
<div class="card">
  <h3>G12 التقديري <?= $year ?> — زبائن النظام الجزافي</h3>
  <?php foreach ($rows as $r): $insts = $installmentsByDecl[$r['id']] ?? []; ?>
    <div style="border-top:1px solid #eef0f2;padding:12px 0">
      <div class="row" style="display:flex;justify-content:space-between;align-items:center">
        <b><?= e($r['company_name']) ?></b>
        <span class="badge" style="background:<?= status_color($r['status']) ?>"><?= status_label($r['status']) ?></span>
      </div>

      <?php if (!$insts): ?>
        <form method="post" style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="set_payment_method">
          <input type="hidden" name="id" value="<?= $r['id'] ?>">
          <input type="number" step="0.01" name="total_amount" placeholder="رقم الأعمال التقديري" required style="width:160px">
          <select name="method">
            <option value="full">دفع كامل (30 جوان)</option>
            <option value="installments">3 دفعات (50%/25%/25%)</option>
          </select>
          <button class="btn" type="submit">تسجيل</button>
        </form>
      <?php else: ?>
        <table style="margin-top:8px">
          <thead><tr><th>الدفعة</th><th>الأجل</th><th>النسبة</th><th>المبلغ</th><th>الحالة</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($insts as $i): ?>
            <tr>
              <td>دفعة <?= $i['installment_no'] ?></td>
              <td><?= e($i['due_date']) ?></td>
              <td><?= $i['percentage'] ?>%</td>
              <td><?= money($i['amount']) ?></td>
              <td><?= $i['paid_at'] ? '<span class="badge" style="background:#2fa84f">مسدَّدة</span>' : '<span class="badge" style="background:#f5a524">غير مسدَّدة</span>' ?></td>
              <td>
                <?php if (!$i['paid_at']): ?>
                <form method="post">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="pay_installment">
                  <input type="hidden" name="installment_id" value="<?= $i['id'] ?>">
                  <button class="btn secondary" type="submit">تسجيل التسديد ومسح الوصل</button>
                </form>
                <?php else: ?>—<?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  <?php if (!$rows): ?><p class="muted">لا يوجد زبائن بالنظام الجزافي.</p><?php endif; ?>
</div>

<div class="card">
  <h3>G12 bis النهائي (قبل 20 جانفي، عن سنة <?= $year - 1 ?>)</h3>
  <form method="post" style="display:flex;gap:8px;flex-wrap:wrap">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="file_bis">
    <select name="client_id" required>
      <?php foreach ($pdo->query("SELECT id, company_name FROM clients WHERE tax_regime='forfaitaire' ORDER BY company_name") as $c): ?>
        <option value="<?= $c['id'] ?>"><?= e($c['company_name']) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="number" step="0.01" name="final_amount" placeholder="رقم الأعمال النهائي" required style="width:160px">
    <button class="btn" type="submit">إيداع</button>
  </form>
</div>
<?php include __DIR__ . '/includes/layout_footer.php'; ?>
