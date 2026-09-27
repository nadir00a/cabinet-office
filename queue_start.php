<?php
require_once __DIR__ . '/config/config.php';
require_role(['manager', 'accountant']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/queue.php');
csrf_check();

$id = (int) ($_POST['id'] ?? 0);
$user = current_user();

/**
 * دفتر الشروط §5.1: "تُنفَّذ العملية داخل معاملة ذرية (transaction) في
 * الخادم حتى لا يأخذ موظفان الوثيقة نفسها."
 *
 * SELECT ... FOR UPDATE يقفل الصف حتى انتهاء المعاملة، فإذا ضغط موظفان
 * الزر في نفس اللحظة، الثاني ينتظر القفل ثم يجد status != 'waiting'
 * فيُرفض بدل أن يحجز الوثيقة نفسها.
 */
try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT * FROM queue_items WHERE id = ? FOR UPDATE");
    $stmt->execute([$id]);
    $item = $stmt->fetch();

    if (!$item) {
        $pdo->rollBack();
        flash('error', 'الوثيقة غير موجودة.');
        redirect('/queue.php');
    }

    if ($item['status'] !== 'waiting') {
        $pdo->rollBack();
        flash('error', 'هذه الوثيقة محجوزة بالفعل من طرف موظف آخر.');
        redirect('/queue.php');
    }

    $upd = $pdo->prepare(
        "UPDATE queue_items SET status = 'reserved', reserved_by = ?, reserved_at = NOW() WHERE id = ?"
    );
    $upd->execute([$user['id'], $id]);

    audit($pdo, 'queue.reserve', 'queue_items', $id);

    $pdo->commit();
    flash('success', 'تم حجز الوثيقة باسمك.');
} catch (Throwable $e) {
    $pdo->rollBack();
    flash('error', 'حدث خطأ: ' . $e->getMessage());
}

redirect('/queue.php');
