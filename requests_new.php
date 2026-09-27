<?php
require_once __DIR__ . '/config/config.php';
require_role(['manager', 'tax_declarant']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pdo->prepare(
        "INSERT INTO admin_requests (client_id, request_source, doc_type, worker_id, status)
         VALUES (?, 'office', ?, ?, 'processing')"
    )->execute([(int) $_POST['client_id'], $_POST['doc_type'], $_POST['worker_id'] ?: null]);

    audit($pdo, 'admin_request.create_by_office', 'admin_requests', (int) $pdo->lastInsertId());
    flash('success', 'تم إنشاء الطلب.');
    redirect('/requests.php');
}

redirect('/requests.php');
