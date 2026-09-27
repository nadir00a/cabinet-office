<?php
require_once __DIR__ . '/config/config.php';
require_role(['manager', 'reception', 'tax_declarant', 'semi_tax_declarant']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role(['manager', 'reception']);
    csrf_check();

    $stmt = $pdo->prepare(
        "INSERT INTO clients (company_name, legal_status, legal_form, manager_name, activity,
            phone, whatsapp_phone, email, address, rc_number, nif, nis, article_number,
            cnas_number, casnos_number, cacobatph_number, subject_to_cacobatph, notes,
            tax_regime, tva_declaration_method)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    );
    $stmt->execute([
        $_POST['company_name'], $_POST['legal_status'], $_POST['legal_form'] ?: null,
        $_POST['manager_name'] ?: null, $_POST['activity'] ?: null,
        $_POST['phone'] ?: null, $_POST['whatsapp_phone'] ?: null, $_POST['email'] ?: null,
        $_POST['address'] ?: null, $_POST['rc_number'] ?: null, $_POST['nif'] ?: null,
        $_POST['nis'] ?: null, $_POST['article_number'] ?: null,
        $_POST['cnas_number'] ?: null, $_POST['casnos_number'] ?: null, $_POST['cacobatph_number'] ?: null,
        isset($_POST['subject_to_cacobatph']) ? 1 : 0, $_POST['notes'] ?: null,
        $_POST['tax_regime'], $_POST['tva_declaration_method'] ?: null,
    ]);
    $clientId = (int) $pdo->lastInsertId();

    // تسجيل النظام الجبائي الابتدائي في السجل التاريخي (§3)
    $pdo->prepare(
        "INSERT INTO client_tax_regime_history (client_id, tax_regime, effective_year, changed_by) VALUES (?,?,?,?)"
    )->execute([$clientId, $_POST['tax_regime'], (int) date('Y'), current_user()['id']]);

    audit($pdo, 'client.create', 'clients', $clientId);
    flash('success', 'تم إضافة الزبون.');
    redirect('/client_view.php?id=' . $clientId);
}

$clients = $pdo->query(
    "SELECT id, company_name, tax_regime, phone, whatsapp_phone FROM clients ORDER BY company_name"
)->fetchAll();

$pageTitle = 'الزبائن';
include __DIR__ . '/includes/layout_header.php';
?>
<?php if (in_array(current_user()['role_code'], ['manager', 'reception'], true)): ?>
<div class="card">
  <h3>إضافة زبون جديد</h3>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <div class="grid2">
      <div class="form-row"><label>اسم الشركة *</label><input name="company_name" required></div>
      <div class="form-row"><label>الصفة *</label>
        <select name="legal_status" required>
          <option value="moral">شخص معنوي</option>
          <option value="physique">شخص طبيعي</option>
        </select>
      </div>
      <div class="form-row"><label>الشكل القانوني</label><input name="legal_form" placeholder="SARL, EURL..."></div>
      <div class="form-row"><label>اسم المسيّر</label><input name="manager_name"></div>
      <div class="form-row"><label>النشاط</label><input name="activity"></div>
      <div class="form-row"><label>الهاتف</label><input name="phone"></div>
      <div class="form-row"><label>واتساب</label><input name="whatsapp_phone"></div>
      <div class="form-row"><label>البريد الإلكتروني</label><input name="email" type="email"></div>
      <div class="form-row"><label>رقم السجل التجاري</label><input name="rc_number"></div>
      <div class="form-row"><label>NIF</label><input name="nif"></div>
      <div class="form-row"><label>NIS</label><input name="nis"></div>
      <div class="form-row"><label>رقم المادة</label><input name="article_number"></div>
      <div class="form-row"><label>CNAS</label><input name="cnas_number"></div>
      <div class="form-row"><label>CASNOS</label><input name="casnos_number"></div>
      <div class="form-row"><label>CACOBATPH</label><input name="cacobatph_number"></div>
      <div class="form-row"><label>النظام الجبائي *</label>
        <select name="tax_regime" required onchange="document.getElementById('tvaRow').style.display=this.value==='reel'?'block':'none'">
          <option value="reel">حقيقي</option>
          <option value="forfaitaire">جزافي</option>
        </select>
      </div>
      <div class="form-row" id="tvaRow"><label>طريقة التصريح بـ TVA</label>
        <select name="tva_declaration_method">
          <option value="facturation">على الفوترة</option>
          <option value="encaissement">على المقبوضات</option>
        </select>
      </div>
    </div>
    <div class="form-row"><label><input type="checkbox" name="subject_to_cacobatph" style="width:auto"> خاضع لـ CACOBATPH</label></div>
    <div class="form-row"><label>العنوان</label><textarea name="address" rows="2"></textarea></div>
    <div class="form-row"><label>ملاحظات</label><textarea name="notes" rows="2"></textarea></div>
    <button class="btn" type="submit">إضافة</button>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h3>قائمة الزبائن (<?= count($clients) ?>)</h3>
  <table>
    <thead><tr><th>الشركة</th><th>النظام الجبائي</th><th>الهاتف</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($clients as $c): ?>
      <tr>
        <td><?= e($c['company_name']) ?></td>
        <td><?= $c['tax_regime'] === 'reel' ? 'حقيقي' : 'جزافي' ?></td>
        <td><?= e($c['phone']) ?></td>
        <td><a class="btn secondary" href="<?= e(url('/client_view.php?id=' . (int)$c['id'])) ?>">عرض الملف</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php include __DIR__ . '/includes/layout_footer.php'; ?>
