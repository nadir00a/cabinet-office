<?php
require_once __DIR__ . '/config/config.php';
require_role(['manager']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deadline_key']) && !isset($_POST['add_extension'])) {
    csrf_check();
    $key = $_POST['deadline_key'];
    $valueJson = json_encode(['value' => $_POST['value']], JSON_UNESCAPED_UNICODE);
    $pdo->prepare(
        "INSERT INTO deadline_settings (deadline_key, value_json, updated_by) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE value_json = VALUES(value_json), updated_by = VALUES(updated_by)"
    )->execute([$key, $valueJson, current_user()['id']]);
    audit($pdo, 'deadline.update', 'deadline_settings', 0, ['key' => $key, 'value' => $_POST['value']]);
    flash('success', 'تم تحديث الأجل.');
    redirect('/deadlines_settings.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_extension'])) {
    csrf_check();
    $pdo->prepare(
        "INSERT INTO deadline_extensions (deadline_key, period_ref, new_date, visible_to_employees, created_by)
         VALUES (?,?,?,?,?)"
    )->execute([
        $_POST['ext_deadline_key'], $_POST['ext_period_ref'], $_POST['ext_new_date'],
        isset($_POST['ext_visible']) ? 1 : 0, current_user()['id'],
    ]);
    audit($pdo, 'deadline.extend', 'deadline_extensions', 0, $_POST);
    flash('success', 'تم تسجيل التمديد. لن يظهر للزبون أبدًا.');
    redirect('/deadlines_settings.php');
}

$settings = $pdo->query("SELECT * FROM deadline_settings ORDER BY deadline_key")->fetchAll();
$extensions = $pdo->query("SELECT * FROM deadline_extensions ORDER BY created_at DESC LIMIT 20")->fetchAll();

$pageTitle = 'الآجال والتمديدات';
include __DIR__ . '/includes/layout_header.php';
?>
<div class="card">
  <h3>الآجال القابلة للتعديل (§9)</h3>
  <p class="muted">تعديل هذه القيم يُعيد حساب الألوان والإشعارات تلقائيًا دون تدخل المطوّر.</p>
  <table>
    <thead><tr><th>المفتاح</th><th>القيمة الحالية</th><th>تحديث</th></tr></thead>
    <tbody>
    <?php foreach ($settings as $s): $v = json_decode($s['value_json'], true); ?>
      <tr>
        <td><?= e($s['deadline_key']) ?></td>
        <td class="muted"><?= e(json_encode($v, JSON_UNESCAPED_UNICODE)) ?></td>
        <td>
          <form method="post" style="display:flex;gap:4px">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="deadline_key" value="<?= e($s['deadline_key']) ?>">
            <input name="value" placeholder="قيمة جديدة" style="width:100px">
            <button class="btn secondary" type="submit">حفظ</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <h3>تمديد أجل — "لا يظهر للزبون أبدًا"</h3>
  <form method="post" class="grid2">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="add_extension" value="1">
    <div class="form-row"><label>مفتاح الأجل</label><input name="ext_deadline_key" required></div>
    <div class="form-row"><label>الفترة</label><input name="ext_period_ref" placeholder="مثلاً 2026-09"></div>
    <div class="form-row"><label>التاريخ الجديد</label><input type="date" name="ext_new_date" required></div>
    <div class="form-row"><label><input type="checkbox" name="ext_visible" style="width:auto"> يظهر للموظفين</label></div>
    <button class="btn" type="submit" style="grid-column:1/-1">تسجيل التمديد</button>
  </form>
  <table style="margin-top:14px">
    <thead><tr><th>المفتاح</th><th>الفترة</th><th>التاريخ الجديد</th><th>يظهر للموظفين</th></tr></thead>
    <tbody>
    <?php foreach ($extensions as $ex): ?>
      <tr><td><?= e($ex['deadline_key']) ?></td><td><?= e($ex['period_ref']) ?></td><td><?= e($ex['new_date']) ?></td>
        <td><?= $ex['visible_to_employees'] ? 'نعم' : 'لا' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php include __DIR__ . '/includes/layout_footer.php'; ?>
