<?php
require_once __DIR__ . '/config/config.php';
require_role(['manager', 'tax_declarant']);

$user = current_user();
$year = (int) ($_GET['y'] ?? date('Y'));

/**
 * §6.3 الميزانية: "من 1 جانفي تظهر للمكتب قائمة كل زبائن النظام الحقيقي."
 * الحالات: قيد الإعداد ← جاهزة في المكتب ← لدى الضرائب لم تؤرش بعد ← عادت مؤرَّشة.
 * "بعد عودة الميزانية مؤرَّشة يظهر تنبيه لطلب C20 جديدة... ولا يظهر الطلب للزبون."
 */
const BUDGET_STATES = [
    'preparing'      => 'قيد الإعداد',
    'ready_office'   => 'جاهزة في المكتب',
    'at_tax_pending' => 'لدى الضرائب — لم تؤرَّش بعد',
    'stamped'        => 'عادت مؤرَّشة',
];

// إنشاء صفوف الميزانية لكل زبائن النظام الحقيقي إن لم توجد بعد لهذه السنة
$pdo->prepare(
    "INSERT INTO tax_declarations (client_id, declaration_type, period_year, status, sub_status)
     SELECT c.id, 'budget', ?, 'red', 'preparing'
     FROM clients c
     WHERE c.tax_regime = 'reel'
       AND NOT EXISTS (SELECT 1 FROM tax_declarations d WHERE d.client_id = c.id
                        AND d.declaration_type = 'budget' AND d.period_year = ?)"
)->execute([$year, $year]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int) $_POST['id'];
    $newState = $_POST['state'];

    if (!isset(BUDGET_STATES[$newState])) { flash('error', 'حالة غير صالحة.'); redirect('/budget.php?y=' . $year); }

    $color = $newState === 'stamped' ? 'green' : ($newState === 'preparing' ? 'red' : 'orange');
    $archivedAt = $newState === 'stamped' ? date('Y-m-d H:i:s') : null;

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "UPDATE tax_declarations SET sub_status=?, status=?, archived_at=? WHERE id=?"
        )->execute([$newState, $color, $archivedAt, $id]);

        audit($pdo, 'budget.state_change', 'tax_declarations', $id, ['state' => $newState]);

        // عند عودة الميزانية مؤرَّشة: طلب C20 جديد تلقائيًا، لا يظهر للزبون
        if ($newState === 'stamped') {
            $decl = $pdo->prepare("SELECT client_id FROM tax_declarations WHERE id = ?");
            $decl->execute([$id]);
            $clientId = $decl->fetchColumn();

            $exists = $pdo->prepare(
                "SELECT COUNT(*) FROM admin_requests WHERE client_id=? AND doc_type='C20' AND status='processing'"
            );
            $exists->execute([$clientId]);
            if (!$exists->fetchColumn()) {
                $pdo->prepare(
                    "INSERT INTO admin_requests (client_id, request_source, doc_type, status, client_notified)
                     VALUES (?, 'office', 'C20', 'processing', 0)"
                )->execute([$clientId]);
                audit($pdo, 'admin_request.auto_create_c20', 'clients', (int) $clientId);
            }
        }
        $pdo->commit();
        flash('success', 'تم تحديث حالة الميزانية.' . ($newState === 'stamped' ? ' وتم إنشاء طلب C20 تلقائيًا (لا يظهر للزبون).' : ''));
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash('error', 'خطأ: ' . $e->getMessage());
    }
    redirect('/budget.php?y=' . $year);
}

$rows = $pdo->prepare(
    "SELECT d.*, c.company_name FROM tax_declarations d JOIN clients c ON c.id = d.client_id
     WHERE d.declaration_type = 'budget' AND d.period_year = ? ORDER BY d.status, c.company_name"
);
$rows->execute([$year]);
$rows = $rows->fetchAll();

$pageTitle = 'الميزانية';
include __DIR__ . '/includes/layout_header.php';
?>
<div class="card">
  <h3>ميزانية <?= $year ?> — زبائن النظام الحقيقي (قبل 30 أفريل)</h3>
  <form method="get" style="margin-bottom:10px">
    <input type="number" name="y" value="<?= $year ?>" style="width:90px">
    <button class="btn secondary" type="submit">تغيير السنة</button>
  </form>
  <table>
    <thead><tr><th>الزبون</th><th>الحالة</th><th>تحديث</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= e($r['company_name']) ?></td>
        <td><span class="badge" style="background:<?= status_color($r['status']) ?>"><?= BUDGET_STATES[$r['sub_status']] ?? e($r['sub_status']) ?></span></td>
        <td>
          <form method="post" style="display:flex;gap:4px">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= $r['id'] ?>">
            <select name="state">
              <?php foreach (BUDGET_STATES as $k => $lbl): ?>
                <option value="<?= $k ?>" <?= $r['sub_status']===$k?'selected':'' ?>><?= $lbl ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn secondary" type="submit">حفظ</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="3" class="muted">لا يوجد زبائن بالنظام الحقيقي.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php include __DIR__ . '/includes/layout_footer.php'; ?>
