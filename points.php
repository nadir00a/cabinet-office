<?php
require_once __DIR__ . '/config/config.php';
require_role(['manager', 'accountant']);

$user = current_user();
$month = (int) ($_GET['m'] ?? date('n'));
$year = (int) ($_GET['y'] ?? date('Y'));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'validate_month') {
    require_role(['manager']);
    csrf_check();

    /**
     * §5.2: "تبقى النقاط 'مؤقتة' حتى يصادق عليها المدير في آخر الشهر."
     * "عند ثبوت الغش يُلغي المدير كل نقاط الموظف لذلك الشهر."
     * كلتا الخطوتين تتم هنا داخل معاملة واحدة.
     */
    try {
        $pdo->beginTransaction();

        $fraudUsers = $pdo->prepare(
            "SELECT DISTINCT p.user_id
             FROM fraud_controls fc
             JOIN queue_items q ON q.id = fc.queue_item_id
             JOIN points p ON p.queue_item_id = q.id
             WHERE fc.verdict = 'fraud' AND p.period_month = ? AND p.period_year = ?"
        );
        $fraudUsers->execute([$month, $year]);
        $fraudUserIds = $fraudUsers->fetchAll(PDO::FETCH_COLUMN);

        foreach ($fraudUserIds as $uid) {
            $cancel = $pdo->prepare(
                "UPDATE points SET status='cancelled' WHERE user_id=? AND period_month=? AND period_year=?"
            );
            $cancel->execute([$uid, $month, $year]);
            audit($pdo, 'points.cancel_month_fraud', 'users', (int)$uid, ['month' => $month, 'year' => $year]);
        }

        $validate = $pdo->prepare(
            "UPDATE points SET status='validated', validated_by=?, validated_at=NOW()
             WHERE period_month=? AND period_year=? AND status='temporary'"
        );
        $validate->execute([$user['id'], $month, $year]);

        audit($pdo, 'points.validate_month', 'points', 0, ['month' => $month, 'year' => $year]);

        $pdo->commit();
        flash('success', 'تمت المصادقة على نقاط الشهر. تم إلغاء نقاط ' . count($fraudUserIds) . ' موظف(ين) بسبب الغش.');
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash('error', 'حدث خطأ: ' . $e->getMessage());
    }
    redirect("/points.php?m=$month&y=$year");
}

$rows = $pdo->prepare(
    "SELECT u.full_name,
        SUM(CASE WHEN p.status='validated' THEN 1 ELSE 0 END) AS validated,
        SUM(CASE WHEN p.status='temporary' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN p.status='cancelled' THEN 1 ELSE 0 END) AS cancelled
     FROM points p JOIN users u ON u.id = p.user_id
     WHERE p.period_month = ? AND p.period_year = ?
     GROUP BY u.id, u.full_name ORDER BY u.full_name"
);
$rows->execute([$month, $year]);
$data = $rows->fetchAll();

$pageTitle = 'النقاط';
include __DIR__ . '/includes/layout_header.php';
?>
<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center">
    <h3>نقاط <?= month_label($month, $year) ?></h3>
    <?php if ($user['role_code'] === 'manager'): ?>
      <form method="post" onsubmit="return confirm('هل تريد المصادقة على نقاط هذا الشهر؟ لا يمكن التراجع.');">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="validate_month">
        <button class="btn" type="submit">مصادقة نقاط الشهر</button>
      </form>
    <?php endif; ?>
  </div>
  <table>
    <thead><tr><th>الموظف</th><th>مؤقتة</th><th>مصادَق عليها</th><th>مُلغاة (غش)</th></tr></thead>
    <tbody>
    <?php foreach ($data as $r): ?>
      <tr>
        <td><?= e($r['full_name']) ?></td>
        <td><?= $r['pending'] ?></td>
        <td><?= $r['validated'] ?></td>
        <td><?= $r['cancelled'] > 0 ? "<span style='color:#e5484d'>{$r['cancelled']}</span>" : '0' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$data): ?><tr><td colspan="4" class="muted">لا نقاط لهذا الشهر.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php include __DIR__ . '/includes/layout_footer.php'; ?>
