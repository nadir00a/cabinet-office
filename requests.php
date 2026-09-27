<?php
require_once __DIR__ . '/config/config.php';
require_role(['manager', 'tax_declarant']);

const EXTRACT_DOC_TYPE = 'مستخرج جدول الضرائب';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int) $_POST['id'];
    $status = $_POST['status'] ?? 'processing';
    if (!in_array($status, ['processing','issued','rejected'], true)) { http_response_code(400); exit('حالة غير صالحة.'); }
    $resolvedAt = in_array($status, ['issued', 'rejected'], true) ? date('Y-m-d H:i:s') : null;

    // §8.1: "صلاحية مستخرج جدول الضرائب 3 أشهر" — عند الإصدار نحسب تاريخ الانتهاء تلقائيًا
    $issuedAt = null; $expiryDate = null;
    if ($status === 'issued') {
        $req = $pdo->prepare("SELECT doc_type FROM admin_requests WHERE id=?");
        $req->execute([$id]);
        if ($req->fetchColumn() === EXTRACT_DOC_TYPE) {
            $validityMonths = (int) get_setting($pdo, 'extract_validity_months', 3);
            $issuedAt = date('Y-m-d');
            $expiryDate = date('Y-m-d', strtotime("+$validityMonths months"));
        }
    }

    $pdo->prepare(
        "UPDATE admin_requests SET status=?, rejection_reason=?, amount_due=?, resolved_at=?, issued_at=?, expiry_date=? WHERE id=?"
    )->execute([$status, $_POST['rejection_reason'] ?: null, $_POST['amount_due'] ?: null, $resolvedAt, $issuedAt, $expiryDate, $id]);

    audit($pdo, 'admin_request.update', 'admin_requests', $id, ['status' => $status]);
    flash('success', 'تم تحديث الطلب.' . ($expiryDate ? " صالح إلى $expiryDate." : ''));
    redirect('/requests.php');
}

$rows = $pdo->query(
    "SELECT r.*, c.company_name, c.whatsapp_phone, w.full_name AS worker_name
     FROM admin_requests r JOIN clients c ON c.id = r.client_id
     LEFT JOIN workers w ON w.id = r.worker_id
     WHERE r.status = 'processing' ORDER BY r.created_at ASC"
)->fetchAll();

// §8.1: تنبيه قبل انتهاء صلاحية المستخرج بـ 15 يومًا
$alertDays = (int) get_setting($pdo, 'extract_validity_months', 3) ? 15 : 15;
$expiringExtracts = $pdo->prepare(
    "SELECT r.*, c.company_name FROM admin_requests r JOIN clients c ON c.id=r.client_id
     WHERE r.doc_type = ? AND r.status='issued' AND r.expiry_date IS NOT NULL
       AND r.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 15 DAY)
     ORDER BY r.expiry_date ASC"
);
$expiringExtracts->execute([EXTRACT_DOC_TYPE]);
$expiringExtracts = $expiringExtracts->fetchAll();

$pageTitle = 'الطلبات لدى الإدارات';
include __DIR__ . '/includes/layout_header.php';
?>
<?php if ($expiringExtracts): ?>
<div class="card" style="border-color:#f5a524">
  <h3>⚠ مستخرجات على وشك الانتهاء (خلال 15 يومًا)</h3>
  <table>
    <thead><tr><th>الزبون</th><th>ينتهي في</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($expiringExtracts as $ex): ?>
      <tr>
        <td><?= e($ex['company_name']) ?></td>
        <td><?= e($ex['expiry_date']) ?></td>
        <td>
          <form method="post" action="<?= e(url('/requests_new.php')) ?>" style="display:inline">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="client_id" value="<?= $ex['client_id'] ?>">
            <input type="hidden" name="doc_type" value="<?= e(EXTRACT_DOC_TYPE) ?>">
            <button class="btn secondary" type="submit">طلب مستخرج جديد</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div class="card">
  <h3>الطلبات قيد المعالجة (<?= count($rows) ?>) — مرتبة من الأقدم</h3>
  <table>
    <thead><tr><th>الزبون</th><th>الوثيقة</th><th>العامل</th><th>منذ</th><th>تحديث</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= e($r['company_name']) ?></td>
        <td><?= e($r['doc_type']) ?> <span class="muted">(<?= $r['request_source']==='client'?'من الزبون':'من المكتب' ?>)</span></td>
        <td><?= e($r['worker_name'] ?? '—') ?></td>
        <td><?= days_since($r['created_at']) ?> يوم</td>
        <td>
          <form method="post" style="display:flex;gap:4px;flex-wrap:wrap;align-items:center">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= $r['id'] ?>">
            <select name="status">
              <option value="processing">قيد المعالجة</option>
              <option value="issued">صدرت</option>
              <option value="rejected">مرفوضة</option>
            </select>
            <input name="rejection_reason" placeholder="سبب الرفض" style="width:130px">
            <input name="amount_due" type="number" step="0.01" placeholder="المبلغ الواجب" style="width:100px">
            <button class="btn secondary" type="submit">حفظ</button>
          </form>
          <?php if ($r['whatsapp_phone']): ?>
            <a class="btn secondary" target="_blank" style="margin-top:4px;display:inline-block" href="<?= e(whatsapp_link($r['whatsapp_phone'],
              'السلام عليكم، بخصوص طلب ' . $r['doc_type'] . ($r['rejection_reason'] ? ('، تم الرفض للسبب: ' . $r['rejection_reason']) : '') . ($r['amount_due'] ? ('. المبلغ الواجب تسديده: ' . money($r['amount_due'])) : '')
            )) ?>">إعلام الزبون عبر واتساب</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" class="muted">لا توجد طلبات قيد المعالجة.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php include __DIR__ . '/includes/layout_footer.php'; ?>
