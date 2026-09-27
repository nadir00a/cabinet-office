<?php
require_once __DIR__ . '/config/config.php';
require_role(['manager', 'semi_tax_declarant']);

$typeLabels = ['cnas' => 'CNAS', 'cacobatph' => 'CACOBATPH', 'casnos' => 'CASNOS'];
$type = $_GET['type'] ?? 'cnas';
$year = (int) ($_GET['y'] ?? date('Y'));

/**
 * §7.1: "من لديه 10 عمال فأكثر: ترصيح شهري. ومن لديه من 1 إلى 9: ترصيح
 * ثلاثي في أفريل وجويلية وأكتوبر وجانفي. العتبة قابلة للتعديل."
 */
function active_worker_count(PDO $pdo, int $clientId, string $asOfDate): int
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM workers WHERE client_id=? AND entry_date <= ? AND (exit_date IS NULL OR exit_date >= ?)"
    );
    $stmt->execute([$clientId, $asOfDate, $asOfDate]);
    return (int) $stmt->fetchColumn();
}

// توليد فترات CNAS/CACOBATPH للسنة الحالية حسب عدد العمال والعتبة القابلة للتعديل
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'generate_periods') {
    csrf_check();
    $threshold = (int) get_setting($pdo, 'monthly_declaration_worker_threshold', 10);

    $clientsQuery = $type === 'cacobatph'
        ? "SELECT id FROM clients WHERE subject_to_cacobatph = 1"
        : "SELECT id FROM clients";
    $clients = $pdo->query($clientsQuery)->fetchAll(PDO::FETCH_COLUMN);

    $quarterMonths = [4, 7, 10, 1]; // §7.1

    foreach ($clients as $clientId) {
        $count = active_worker_count($pdo, (int) $clientId, date('Y-m-d'));
        if ($count === 0) continue; // بدون عمال نشطين: لا تصريح

        if ($count >= $threshold) {
            $periodRef = date('Y-m'); // شهري
            $ex = $pdo->prepare("SELECT id FROM semi_tax_declarations WHERE client_id=? AND declaration_type=? AND period_ref=?");
            $ex->execute([$clientId, $type, $periodRef]);
            if (!$ex->fetchColumn()) {
                $pdo->prepare("INSERT INTO semi_tax_declarations (client_id, declaration_type, period_type, period_ref, status) VALUES (?,?,?,?,'red')")
                    ->execute([$clientId, $type, 'monthly', $periodRef]);
            }
        } else {
            $currentMonth = (int) date('n');
            $dueMonth = null;
            foreach ($quarterMonths as $qm) { if ($qm >= $currentMonth) { $dueMonth = $qm; break; } }
            $dueMonth = $dueMonth ?? 4;
            $periodRef = "$year-T$dueMonth";
            $ex = $pdo->prepare("SELECT id FROM semi_tax_declarations WHERE client_id=? AND declaration_type=? AND period_ref=?");
            $ex->execute([$clientId, $type, $periodRef]);
            if (!$ex->fetchColumn()) {
                $pdo->prepare("INSERT INTO semi_tax_declarations (client_id, declaration_type, period_type, period_ref, status) VALUES (?,?,?,?,'red')")
                    ->execute([$clientId, $type, 'quarterly', $periodRef]);
            }
        }
    }
    audit($pdo, 'semi_declaration.generate_periods', 'semi_tax_declarations', 0, ['type' => $type]);
    flash('success', 'تم توليد فترات التصريح لهذه السنة حسب عدد العمال.');
    redirect("/semi_declarations.php?type=$type&y=$year");
}

// توليد CASNOS للسنة الحالية مع حساب سنة الأساس تلقائيًا (§7.2)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'generate_casnos') {
    csrf_check();
    $clients = $pdo->query("SELECT id, tax_regime FROM clients")->fetchAll();
    foreach ($clients as $c) {
        $periodRef = (string) $year;
        $ex = $pdo->prepare("SELECT id FROM semi_tax_declarations WHERE client_id=? AND declaration_type='casnos' AND period_ref=?");
        $ex->execute([$c['id'], $periodRef]);
        if (!$ex->fetchColumn()) {
            $pdo->prepare("INSERT INTO semi_tax_declarations (client_id, declaration_type, period_type, period_ref, status) VALUES (?,'casnos','annual',?,'red')")
                ->execute([$c['id'], $periodRef]);
        }
    }
    audit($pdo, 'semi_declaration.generate_casnos', 'semi_tax_declarations', 0, ['year' => $year]);
    flash('success', 'تم توليد اشتراكات CASNOS لسنة ' . $year);
    redirect("/semi_declarations.php?type=casnos&y=$year");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'update') {
    require_role(['manager', 'semi_tax_declarant']);
    csrf_check();
    $id = (int) $_POST['id'];
    $status = $_POST['status'] ?? '';
    if (!in_array($status, ['red','orange','green'], true)) { http_response_code(400); exit('حالة غير صالحة.'); }
    $filedAt = $status !== 'red' ? date('Y-m-d H:i:s') : null;
    $receiptAt = $status === 'green' ? date('Y-m-d H:i:s') : null;
    $pdo->prepare("UPDATE semi_tax_declarations SET status=?, amount=?, filed_at=COALESCE(filed_at, ?), receipt_scanned_at=? WHERE id=?")
        ->execute([$status, $_POST['amount'] ?: null, $filedAt, $receiptAt, $id]);
    audit($pdo, 'semi_declaration.update', 'semi_tax_declarations', $id, ['status' => $status]);
    flash('success', 'تم التحديث.');
    redirect('/semi_declarations.php?type=' . ($_POST['type'] ?? 'cnas') . "&y=$year");
}

$where = $type === 'cacobatph'
    ? "s.declaration_type = ? AND c.subject_to_cacobatph = 1"
    : "s.declaration_type = ?";
$stmt = $pdo->prepare(
    "SELECT s.*, c.company_name, c.whatsapp_phone, c.tax_regime FROM semi_tax_declarations s
     JOIN clients c ON c.id = s.client_id
     WHERE $where ORDER BY s.status, c.company_name LIMIT 150"
);
$stmt->execute([$type]);
$rows = $stmt->fetchAll();

// لـ CASNOS: حساب سنة الأساس ومرجعها لكل زبون (§7.2)
$casnosBase = [];
if ($type === 'casnos') {
    foreach ($rows as $r) {
        if ($r['tax_regime'] === 'reel') {
            $baseYear = $year - 2;
            $b = $pdo->prepare("SELECT amount FROM tax_declarations WHERE client_id=? AND declaration_type='budget' AND period_year=?");
            $b->execute([(int) $r['client_id'], $baseYear]);
            $casnosBase[$r['id']] = "ميزانية $baseYear" . ($b->fetchColumn() !== false ? '' : ' (غير مودعة بعد)');
        } else {
            $baseYear = $year - 1;
            $b = $pdo->prepare("SELECT amount FROM tax_declarations WHERE client_id=? AND declaration_type='g12_bis_final' AND period_year=?");
            $b->execute([(int) $r['client_id'], $baseYear]);
            $casnosBase[$r['id']] = "G12 bis $baseYear" . ($b->fetchColumn() !== false ? '' : ' (غير مودع بعد)');
        }
    }
}

$pageTitle = 'التصريحات شبه الجبائية';
include __DIR__ . '/includes/layout_header.php';
?>
<div class="card">
  <h3>التصريحات شبه الجبائية</h3>
  <form method="get" style="display:flex;gap:8px;margin-bottom:10px">
    <select name="type" onchange="this.form.submit()">
      <?php foreach ($typeLabels as $k => $lbl): ?>
        <option value="<?= $k ?>" <?= $type===$k?'selected':'' ?>><?= $lbl ?></option>
      <?php endforeach; ?>
    </select>
    <input type="number" name="y" value="<?= $year ?>" style="width:90px">
    <button class="btn secondary" type="submit">تصفية</button>
  </form>

  <?php if ($type === 'casnos'): ?>
    <form method="post" style="margin-bottom:10px">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="generate_casnos">
      <button class="btn" type="submit">توليد اشتراكات CASNOS لسنة <?= $year ?></button>
    </form>
  <?php else: ?>
    <form method="post" style="margin-bottom:10px">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="generate_periods">
      <button class="btn" type="submit">توليد فترات <?= $typeLabels[$type] ?> (حسب عدد العمال الحالي)</button>
    </form>
  <?php endif; ?>

  <table>
    <thead><tr><th>الزبون</th><th>الفترة</th><?= $type==='casnos' ? '<th>سنة الأساس</th>' : '' ?><th>الحالة</th><th>المبلغ</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= e($r['company_name']) ?></td>
        <td><?= e($r['period_ref']) ?></td>
        <?php if ($type === 'casnos'): ?><td class="muted"><?= e($casnosBase[$r['id']] ?? '') ?></td><?php endif; ?>
        <td><span class="badge" style="background:<?= status_color($r['status']) ?>"><?= status_label($r['status']) ?></span></td>
        <td><?= money($r['amount']) ?></td>
        <td style="display:flex;gap:4px;flex-wrap:wrap">
          <form method="post" style="display:flex;gap:4px">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= $r['id'] ?>">
            <input type="hidden" name="type" value="<?= e($type) ?>">
            <select name="status">
              <option value="red" <?= $r['status']==='red'?'selected':'' ?>>أحمر</option>
              <option value="orange" <?= $r['status']==='orange'?'selected':'' ?>>برتقالي</option>
              <option value="green" <?= $r['status']==='green'?'selected':'' ?>>أخضر</option>
            </select>
            <input name="amount" type="number" step="0.01" value="<?= e((string)$r['amount']) ?>" style="width:90px">
            <button class="btn secondary" type="submit">حفظ</button>
          </form>
          <?php if ($r['whatsapp_phone'] && $r['amount']): ?>
            <a class="btn secondary" target="_blank" href="<?= e(whatsapp_link($r['whatsapp_phone'],
              'السلام عليكم، مبلغ ' . $typeLabels[$type] . ' الواجب دفعه (' . $r['period_ref'] . '): ' . money($r['amount']) . '. يرجى إحضار الصك.'
            )) ?>">واتساب</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="muted">لا توجد تصريحات. استعمل زر التوليد أعلاه.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php include __DIR__ . '/includes/layout_footer.php'; ?>
