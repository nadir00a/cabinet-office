<?php
require_once __DIR__ . '/config/config.php';
require_role(['manager', 'accountant']);

$user = current_user();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT q.*, c.company_name FROM queue_items q JOIN clients c ON c.id = q.client_id WHERE q.id = ?"
);
$stmt->execute([$id]);
$item = $stmt->fetch();

if (!$item) { flash('error', 'الوثيقة غير موجودة.'); redirect('/queue.php'); }
if ($item['status'] !== 'reserved' || (int)$item['reserved_by'] !== (int)$user['id']) {
    flash('error', 'لا يمكنك تأكيد وثيقة لم تحجزها.');
    redirect('/queue.php');
}

$isBank = $item['item_type'] === 'bank_statement';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $entryNo = trim($_POST['accounting_entry_number'] ?? '');
    $amount = trim($_POST['amount'] ?? '');

    if ($entryNo === '' || $amount === '' || !is_numeric($amount)) {
        flash('error', 'رقم القيد والمبلغ إلزاميان.');
        redirect('/queue_confirm.php?id=' . $id);
    }

    try {
        $pdo->beginTransaction();

        // إعادة القفل والتحقق تحت المعاملة، ثم التأكيد
        $lock = $pdo->prepare("SELECT status, reserved_by FROM queue_items WHERE id = ? FOR UPDATE");
        $lock->execute([$id]);
        $row = $lock->fetch();

        if (!$row || $row['status'] !== 'reserved' || (int)$row['reserved_by'] !== (int)$user['id']) {
            $pdo->rollBack();
            flash('error', 'تغيّرت حالة الوثيقة، أعد المحاولة.');
            redirect('/queue.php');
        }

        $field = $isBank ? 'bank_balance_after' : 'accounting_result_after';
        $upd = $pdo->prepare(
            "UPDATE queue_items SET status='confirmed', confirmed_at = NOW(),
             accounting_entry_number = ?, $field = ? WHERE id = ?"
        );
        $upd->execute([$entryNo, $amount, $id]);

        // كل وثيقة محجوزة = نقطة مؤقتة للموظف (§5.2)
        $pt = $pdo->prepare(
            "INSERT INTO points (user_id, queue_item_id, period_month, period_year, status)
             VALUES (?, ?, ?, ?, 'temporary')"
        );
        $pt->execute([$user['id'], $id, (int)date('n'), (int)date('Y')]);

        audit($pdo, 'queue.confirm', 'queue_items', $id, ['entry_no' => $entryNo, 'amount' => $amount]);

        $pdo->commit();
        flash('success', 'تم تأكيد الحجز. لك نقطة جديدة هذا الشهر.');
        redirect('/queue.php');
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash('error', 'حدث خطأ: ' . $e->getMessage());
        redirect('/queue_confirm.php?id=' . $id);
    }
}

$pageTitle = 'تأكيد الحجز';
include __DIR__ . '/includes/layout_header.php';
?>
<div class="card" style="max-width:420px">
  <h3>تأكيد الحجز — <?= e($item['company_name']) ?></h3>
  <p class="muted"><?= $isBank ? 'الكشف البنكي' : ($item['item_type']==='purchase_invoice' ? 'فواتير الشراء' : 'فواتير البيع') ?></p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <div class="form-row">
      <label>رقم القيد (من برنامج المحاسبة)</label>
      <input type="text" name="accounting_entry_number" required>
    </div>
    <div class="form-row">
      <label><?= $isBank ? 'الرصيد البنكي بعد الحجز' : 'النتيجة المحاسبية بعد الحجز' ?></label>
      <input type="number" step="0.01" name="amount" required>
    </div>
    <button class="btn" type="submit">تأكيد</button>
    <a class="btn secondary" href="<?= e(url('/queue.php')) ?>">رجوع</a>
  </form>
</div>
<?php include __DIR__ . '/includes/layout_footer.php'; ?>
