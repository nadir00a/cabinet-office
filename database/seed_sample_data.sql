-- بيانات تجريبية للاختبار. شغّلها بعد schema.sql و setup_users.php.

INSERT INTO clients (company_name, legal_status, legal_form, manager_name, phone, whatsapp_phone, tax_regime, subject_to_cacobatph)
VALUES
 ('شركة النور للتجارة', 'moral', 'SARL', 'أحمد بن علي', '0555000001', '213555000001', 'reel', 0),
 ('مؤسسة الأمين للبناء', 'moral', 'EURL', 'كريم مالكي', '0555000002', '213555000002', 'reel', 1),
 ('ورشة الصفاء', 'physique', NULL, 'ياسين حمدي', '0555000003', '213555000003', 'forfaitaire', 0);

INSERT INTO workers (client_id, full_name, entry_date, cnas_affiliation_status)
VALUES
 (2, 'بلال شريف', '2024-03-01', 'affiliated'),
 (2, 'رياض بوزيد', '2025-01-15', 'affiliated'),
 (1, 'سمير عيساني', '2023-09-01', 'affiliated');

INSERT INTO queue_items (client_id, item_type, status, created_at)
VALUES
 (1, 'bank_statement', 'waiting', NOW() - INTERVAL 6 DAY),
 (2, 'purchase_invoice', 'waiting', NOW() - INTERVAL 4 DAY),
 (3, 'sale_invoice', 'waiting', NOW() - INTERVAL 9 DAY);

INSERT INTO tax_declarations (client_id, declaration_type, period_month, period_year, status, sub_status)
VALUES
 (1, 'g50', MONTH(CURDATE()), YEAR(CURDATE()), 'red', 'الوثائق غير مستلمة'),
 (2, 'g50', MONTH(CURDATE()), YEAR(CURDATE()), 'orange', 'في انتظار موافقة TVA'),
 (3, 'g12_provisoire', NULL, YEAR(CURDATE()), 'green', 'مكتمل ومؤرشف');

INSERT INTO admin_requests (client_id, request_source, doc_type, status)
VALUES (1, 'client', 'C20', 'processing'), (2, 'office', 'Mise à jour CNAS', 'processing');
