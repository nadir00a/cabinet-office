-- تعديلات إضافية لضمان التوافق الكامل مع دفتر الشروط.
-- شغّل هذا الملف مرة واحدة بعد schema.sql (وبعد seed_sample_data.sql إن استعملته).

ALTER TABLE admin_requests
    ADD COLUMN issued_at DATE NULL AFTER resolved_at,
    ADD COLUMN expiry_date DATE NULL AFTER issued_at;

-- فهرس يسهّل حساب "أقدم وثيقة" ومؤشرات لوحة المدير
CREATE INDEX idx_admin_requests_status ON admin_requests(status);
CREATE INDEX idx_semi_decl_client_type ON semi_tax_declarations(client_id, declaration_type);

-- إعدادات إضافية قابلة للتعديل من صفحة الآجال (§9)
INSERT INTO deadline_settings (deadline_key, value_json) VALUES
  ('casnos_deadline_month_day', '{"month": 2, "day": 28}'),
  ('taxe_apprentissage_s1_due', '{"month": 7, "day": 20}'),
  ('taxe_apprentissage_s2_due', '{"month": 2, "day": 20}'),
  ('era_deadline', '{"month": 9, "day": 30}'),
  ('budget_deadline', '{"month": 4, "day": 30}'),
  ('ibs_irg_deadline', '{"month": 5, "day": 20}'),
  ('g12_provisoire_deadline', '{"month": 6, "day": 30}'),
  ('g12_bis_deadline', '{"month": 1, "day": 20}')
ON DUPLICATE KEY UPDATE value_json = VALUES(value_json);
