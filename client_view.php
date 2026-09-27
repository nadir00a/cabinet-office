<?php
require_once __DIR__ . '/config/config.php';
require_role(['manager', 'reception', 'tax_declarant', 'semi_tax_declarant']);

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
$stmt->execute([$id]);
$client = $stmt->fetch();
if (!$client) { flash('error', 'الزبون غير موجود.'); redirect('/clients.php'); }

// إضافة عامل جديد — تسجيل إداري أساسي، صلاحية الاستقبال (كتسجيل الزبائن)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_worker') {
    require_role(['manager', 'reception']);
    csrf_check();
    $pdo->prepare("INSERT INTO workers (client_id, full_name, entry_date) VALUES (?,?,?)")
        ->execute([$id, $_POST['full_name'], $_POST['entry_date']]);
    audit($pdo, 'worker.create', 'workers', (int)$pdo->lastInsertId());
    flash('success', 'تمت إضافة العامل.');
    redirect('/client_view.php?id=' . $id);
}

/**
 * تسجيل استلام وثيقة من الزبون (الكشف البنكي، فواتير الشراء، فواتير
 * البيع) ودفعها إلى قائمة الانتظار — §4.1 و§5.1. هذا هو الإجراء الذي
 * ينقل الوثيقة من "استلمها الاستقبال" إلى ظهورها في queue.php لكل محاسب.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_document_to_queue') {
    require_role(['manager', 'reception']); // §2: "استلام الوثائق ومسحها ضوئيًا" هي صلاحية الاستقبال
    csrf_check();
    $itemType = $_POST['item_type'];
    $periodMonth = (int) $_POST['period_month'];
    $periodYear = (int) $_POST['period_year'];

    if (!in_array($itemType, ['bank_statement', 'purchase_invoice', 'sale_invoice'], true)) {
        flash('error', 'نوع وثيقة غير صالح.');
        redirect('/client_view.php?id=' . $id);
    }

    $pdo->beginTransaction();
    try {
        $docStmt = $pdo->prepare(
            "INSERT INTO documents (client_id, category, doc_type, period_month, period_year, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $docStmt->execute([$id, $itemType, $itemType, $periodMonth, $periodYear, current_user()['id']]);
        $documentId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO queue_items (document_id, client_id, item_type, status) VALUES (?, ?, ?, 'waiting')"
        )->execute([$documentId, $id, $itemType]);

        audit($pdo, 'document.register_to_queue', 'documents', $documentId, ['item_type' => $itemType]);
        $pdo->commit();
        flash('success', 'تم تسجيل الوثيقة، وهي الآن ظاهرة في قائمة الانتظار لكل المحاسبين.');
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash('error', 'خطأ: ' . $e->getMessage());
    }
    redirect('/client_view.php?id=' . $id);
}

/**
 * §4.2: "يرسل الزبون ملف العامل من التطبيق، فيقوم المكتب بالانتساب على
 * موقع CNAS، ثم تُنزَّل شهادة الانتساب في التطبيق. يظهر تنبيه للمكتب إذا
 * تأخر الملف (مدة قابلة للتعديل)."
 * هنا يُسجِّل المكتب استلام الملف، ثم تأكيد الانتساب بعد إتمامه فعليًا.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_file_submitted') {
    require_role(['manager', 'semi_tax_declarant']); // §2: "انتساب العمال" صلاحية المصرِّح شبه الجبائي
    csrf_check();
    $workerId = (int) $_POST['worker_id'];
    $pdo->prepare("UPDATE workers SET cnas_affiliation_status='submitted', file_submitted_at=NOW() WHERE id=? AND client_id=?")
        ->execute([$workerId, $id]);
    audit($pdo, 'worker.file_submitted', 'workers', $workerId);
    flash('success', 'تم تسجيل استلام ملف العامل.');
    redirect('/client_view.php?id=' . $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_affiliated') {
    require_role(['manager', 'semi_tax_declarant']);
    csrf_check();
    $workerId = (int) $_POST['worker_id'];
    $pdo->prepare("UPDATE workers SET cnas_affiliation_status='affiliated' WHERE id=? AND client_id=?")
        ->execute([$workerId, $id]);
    audit($pdo, 'worker.affiliated', 'workers', $workerId);
    flash('success', 'تم تأكيد الانتساب. شهادة الانتساب متاحة الآن للزبون.');
    redirect('/client_view.php?id=' . $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_worker_exit') {
    require_role(['manager', 'reception', 'semi_tax_declarant']);
    csrf_check();
    $workerId = (int) $_POST['worker_id'];
    $pdo->prepare("UPDATE workers SET exit_date=?, is_active=0 WHERE id=? AND client_id=?")
        ->execute([$_POST['exit_date'], $workerId, $id]);
    audit($pdo, 'worker.exit', 'workers', $workerId, ['exit_date' => $_POST['exit_date']]);
    flash('success', 'تم تسجيل خروج العامل. سيُحذف من البوانتاج ابتداءً من الشهر الموالي مع بقائه في الأرشيف.');
    redirect('/client_view.php?id=' . $id);
}

$w = $pdo->prepare("SELECT * FROM workers WHERE client_id = ? ORDER BY is_active DESC, full_name");
$w->execute([$id]);
$workers = $w->fetchAll();
$workerFileAlertDays = (int) get_setting($pdo, 'new_worker_file_alert_days', 7);

$docs = $pdo->prepare(
    "SELECT * FROM documents WHERE client_id = ? ORDER BY period_year DESC, period_month DESC, created_at DESC LIMIT 30"
);
$docs->execute([$id]);
$docs = $docs->fetchAll();

$decl = $pdo->prepare(
    "SELECT * FROM tax_declarations WHERE client_id = ? ORDER BY period_year DESC, period_month DESC LIMIT 12"
);
$decl->execute([$id]);
$decl = $decl->fetchAll();

$pageTitle = $client['company_name'];
$role = current_user()['role_code'];
include __DIR__ . '/includes/layout_header.php';
?>
<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center">
    <h3><?= e($client['company_name']) ?></h3>
    <div>
      <?php if ($client['whatsapp_phone']): ?>
        <a class="btn secondary" target="_blank"
           href="<?= e(whatsapp_link($client['whatsapp_phone'], 'السلام عليكم، مكتب المحاسبة يتواصل معكم بخصوص ملفكم.')) ?>">واتساب</a>
      <?php endif; ?>
      <?php if ($client['phone']): ?><a class="btn secondary" href="tel:<?= e($client['phone']) ?>">اتصال</a><?php endif; ?>
    </div>
  </div>
  <div class="grid2" style="margin-top:10px">
    <div><span class="muted">النظام الجبائي:</span> <?= $client['tax_regime']==='reel' ? 'حقيقي' : 'جزافي' ?></div>
    <div><span class="muted">الصفة:</span> <?= $client['legal_status']==='moral' ? 'شخص معنوي' : 'شخص طبيعي' ?></div>
    <div><span class="muted">NIF:</span> <?= e($client['nif']) ?></div>
    <div><span class="muted">NIS:</span> <?= e($client['nis']) ?></div>
    <div><span class="muted">CACOBATPH:</span> <?= $client['subject_to_cacobatph'] ? 'خاضع' : 'غير خاضع' ?></div>
    <div><span class="muted">ملاحظات:</span> <?= e($client['notes']) ?></div>
  </div>
</div>

<div class="card">
  <h3>العمال (<?= count($workers) ?>)</h3>
  <table>
    <thead><tr><th>الاسم</th><th>تاريخ الدخول</th><th>تاريخ الخروج</th><th>الانتساب CNAS</th><th>الحالة</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($workers as $wk):
      $late = $wk['cnas_affiliation_status'] !== 'affiliated' && $wk['file_submitted_at']
        && (time() - strtotime($wk['file_submitted_at'])) > $workerFileAlertDays * 86400;
    ?>
      <tr>
        <td><?= e($wk['full_name']) ?></td>
        <td><?= e($wk['entry_date']) ?></td>
        <td><?= e($wk['exit_date'] ?? '—') ?></td>
        <td>
          <?= ['pending'=>'قيد الانتظار','submitted'=>'مُرسل','affiliated'=>'مُنتسب'][$wk['cnas_affiliation_status']] ?? $wk['cnas_affiliation_status'] ?>
          <?php if ($late): ?><span class="badge" style="background:#e5484d">متأخر</span><?php endif; ?>
        </td>
        <td><?= $wk['is_active'] ? 'نشط' : 'خارج' ?></td>
        <td style="display:flex;gap:4px;flex-wrap:wrap">
          <?php if (in_array($role, ['manager', 'semi_tax_declarant'], true)): ?>
          <?php if ($wk['cnas_affiliation_status'] === 'pending'): ?>
            <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="mark_file_submitted"><input type="hidden" name="worker_id" value="<?= $wk['id'] ?>">
              <button class="btn secondary" type="submit">تم استلام الملف</button></form>
          <?php elseif ($wk['cnas_affiliation_status'] === 'submitted'): ?>
            <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="mark_affiliated"><input type="hidden" name="worker_id" value="<?= $wk['id'] ?>">
              <button class="btn" type="submit">تأكيد الانتساب</button></form>
          <?php endif; ?>
          <?php endif; ?>
          <?php if ($wk['is_active'] && in_array($role, ['manager', 'reception', 'semi_tax_declarant'], true)): ?>
            <form method="post" style="display:flex;gap:4px">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="mark_worker_exit"><input type="hidden" name="worker_id" value="<?= $wk['id'] ?>">
              <input type="date" name="exit_date" required style="width:130px">
              <button class="btn danger" type="submit">تسجيل الخروج</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$workers): ?><tr><td colspan="6" class="muted">لا يوجد عمال.</td></tr><?php endif; ?>
    </tbody>
  </table>
  <?php if (in_array($role, ['manager', 'reception'], true)): ?>
  <form method="post" style="margin-top:10px;display:flex;gap:8px">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="add_worker">
    <input name="full_name" placeholder="اسم العامل" required>
    <input type="date" name="entry_date" required>
    <button class="btn" type="submit">إضافة عامل</button>
  </form>
  <?php endif; ?>
</div>

<?php if (in_array($role, ['manager', 'reception'], true)): ?>
<div class="card">
  <h3>تسجيل وثيقة جديدة ← قائمة الانتظار</h3>
  <p class="muted">استعمل هذا عندما يحضر الزبون الكشف البنكي أو الفواتير — تظهر فورًا في قائمة الانتظار لكل المحاسبين.</p>
  <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="add_document_to_queue">
    <div class="form-row" style="margin:0">
      <label>نوع الوثيقة</label>
      <select name="item_type" required>
        <option value="bank_statement">الكشف البنكي</option>
        <option value="purchase_invoice">فواتير الشراء</option>
        <option value="sale_invoice">فواتير البيع</option>
      </select>
    </div>
    <div class="form-row" style="margin:0">
      <label>الشهر</label>
      <input type="number" name="period_month" min="1" max="12" value="<?= (int) date('n') ?>" style="width:70px" required>
    </div>
    <div class="form-row" style="margin:0">
      <label>السنة</label>
      <input type="number" name="period_year" value="<?= (int) date('Y') ?>" style="width:90px" required>
    </div>
    <button class="btn" type="submit">تسجيل ودفع إلى قائمة الانتظار</button>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h3>آخر الوثائق</h3>
  <table>
    <thead><tr><th>النوع</th><th>الفئة</th><th>الشهر</th><th>تاريخ الإضافة</th></tr></thead>
    <tbody>
    <?php foreach ($docs as $d): ?>
      <tr>
        <td><?= e($d['doc_type'] ?? $d['category']) ?></td>
        <td><?= e($d['category']) ?></td>
        <td><?= $d['period_month'] ? month_label($d['period_month'], $d['period_year']) : '—' ?></td>
        <td><?= e($d['created_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$docs): ?><tr><td colspan="4" class="muted">لا توجد وثائق مسجَّلة.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <h3>التصريحات</h3>
  <table>
    <thead><tr><th>النوع</th><th>الفترة</th><th>الحالة</th></tr></thead>
    <tbody>
    <?php foreach ($decl as $d): ?>
      <tr>
        <td><?= e($d['declaration_type']) ?></td>
        <td><?= $d['period_month'] ? month_label($d['period_month'], $d['period_year']) : $d['period_year'] ?></td>
        <td><span class="badge" style="background:<?= status_color($d['status']) ?>"><?= status_label($d['status']) ?></span></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$decl): ?><tr><td colspan="3" class="muted">لا توجد تصريحات مسجَّلة.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php include __DIR__ . '/includes/layout_footer.php'; ?>
