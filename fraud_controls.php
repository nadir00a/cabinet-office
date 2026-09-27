<?php
require_once __DIR__ . '/config/config.php';
require_role(['manager']);

$user = current_user();

// إنشاء عينة عشوائية جديدة من الوثائق المؤكدة التي لم تُفحص بعد
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'sample') {
    csrf_check();
    $sampleSize = max(1, (int) ($_POST['sample_size'] ?? 5));

    $stmt = $pdo->prepare(
        "SELECT q.id FROM queue_items q
         LEFT JOIN fraud_controls fc ON fc.queue_item_id = q.id
         WHERE q.status = 'confirmed' AND fc.id IS NULL
         ORDER BY RAND() LIMIT ?"
    );
    $stmt->bindValue(1, $sampleSize, PDO::PARAM_INT);
    $stmt->execute();
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($ids as $qid) {
        $ins = $pdo->prepare("INSERT INTO fraud_controls (queue_item_id) VALUES (?)");
        $ins->execute([$qid]);
    }
    audit($pdo, 'fraud.sample', 'fraud_controls', 0, ['count' => count($ids)]);
    flash('success', count($ids) . ' وثيقة أُضيفت إلى عينة المراقبة.');
    redirect('/fraud_controls.php');
}

// تسجيل حكم "صحيح" أو "غش" لعملية مراقبة
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'verdict') {
    csrf_check();
    $id = (int) $_POST['id'];
    $verdict = $_POST['verdict'] === 'fraud' ? 'fraud' : 'correct';

    $upd = $pdo->prepare(
        "UPDATE fraud_controls SET
            bank_statement_balance = ?, app_recorded_balance = ?, accounting_software_balance = ?,
            verdict = ?, reviewed_by = ?, reviewed_at = NOW()
         WHERE id = ?"
    );
    $upd->execute([
        $_POST['bank_balance'] ?: null, $_POST['app_balance'] ?: null, $_POST['software_balance'] ?: null,
        $verdict, $user['id'], $id,
    ]);
    audit($pdo, 'fraud.verdict', 'fraud_controls', $id, ['verdict' => $verdict]);
    flash('success', 'تم تسجيل الحكم: ' . ($verdict === 'fraud' ? 'غش' : 'صحيح'));
    redirect('/fraud_controls.php');
}

$pending = $pdo->query(
    "SELECT fc.*, q.item_type, q.bank_balance_after, q.accounting_result_after, c.company_name, u.full_name AS employee
     FROM fraud_controls fc
     JOIN queue_items q ON q.id = fc.queue_item_id
     JOIN clients c ON c.id = q.client_id
     LEFT JOIN users u ON u.id = q.reserved_by
     WHERE fc.verdict IS NULL ORDER BY fc.sampled_at DESC"
)->fetchAll();

$pageTitle = 'المراقبة العشوائية';
include __DIR__ . '/includes/layout_header.php';
?>
<div class="card">
  <h3>سحب عينة جديدة</h3>
  <form method="post" style="display:flex;gap:10px;align-items:end">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="sample">
    <div style="width:120px"><label>عدد الوثائق</label><input type="number" name="sample_size" value="5" min="1"></div>
    <button class="btn" type="submit">سحب العينة</button>
  </form>
</div>

<div class="card">
  <h3>عمليات قيد المراقبة (<?= count($pending) ?>)</h3>
  <?php foreach ($pending as $p): ?>
    <form method="post" style="border-top:1px solid #eef0f2;padding:12px 0">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="verdict">
      <input type="hidden" name="id" value="<?= $p['id'] ?>">
      <div class="row" style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px">
        <div><b><?= e($p['company_name']) ?></b> — <?= e($p['employee']) ?>
          <div class="muted">المبلغ المُدخل في التطبيق: <?= money($p['bank_balance_after'] ?? $p['accounting_result_after']) ?></div>
        </div>
      </div>
      <div class="grid4" style="margin-top:8px">
        <input type="number" step="0.01" name="bank_balance" placeholder="الرصيد في الكشف">
        <input type="number" step="0.01" name="app_balance" placeholder="المسجَّل في التطبيق">
        <input type="number" step="0.01" name="software_balance" placeholder="في برنامج المحاسبة">
        <div style="display:flex;gap:6px">
          <button class="btn" name="verdict" value="correct" type="submit">صحيح</button>
          <button class="btn danger" name="verdict" value="fraud" type="submit">غش</button>
        </div>
      </div>
    </form>
  <?php endforeach; ?>
  <?php if (!$pending): ?><p class="muted">لا توجد عمليات قيد المراقبة.</p><?php endif; ?>
</div>
<?php include __DIR__ . '/includes/layout_footer.php'; ?>
