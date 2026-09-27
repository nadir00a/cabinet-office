-- ============================================================
-- نظام مكتب المحاسبة — قاعدة بيانات MySQL
-- ============================================================
CREATE DATABASE IF NOT EXISTS cabinet_office CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE cabinet_office;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(30) UNIQUE NOT NULL,
    label_ar VARCHAR(60) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NOT NULL,
    username VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(150),
    phone VARCHAR(30),
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNIQUE,
    company_name VARCHAR(200) NOT NULL,
    legal_status VARCHAR(20) NOT NULL,      -- moral | physique
    legal_form VARCHAR(50),
    manager_name VARCHAR(150),
    activity VARCHAR(200),
    phone VARCHAR(30),
    whatsapp_phone VARCHAR(30),
    email VARCHAR(150),
    address TEXT,
    rc_number VARCHAR(50),
    nif VARCHAR(50),
    nis VARCHAR(50),
    article_number VARCHAR(50),
    cnas_number VARCHAR(50),
    casnos_number VARCHAR(50),
    cacobatph_number VARCHAR(50),
    subject_to_cacobatph TINYINT(1) DEFAULT 0,
    notes TEXT,
    tax_regime VARCHAR(15) NOT NULL DEFAULT 'reel',   -- reel | forfaitaire
    tva_declaration_method VARCHAR(20),               -- facturation | encaissement
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE client_tax_regime_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    tax_regime VARCHAR(15) NOT NULL,
    effective_year INT NOT NULL,
    changed_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id),
    FOREIGN KEY (changed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE workers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    entry_date DATE NOT NULL,
    exit_date DATE NULL,
    cnas_affiliation_status VARCHAR(20) DEFAULT 'pending', -- pending|submitted|affiliated
    file_submitted_at DATETIME NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE worker_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    worker_id INT NOT NULL,
    doc_type VARCHAR(40) NOT NULL,
    file_path VARCHAR(255),
    uploaded_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (worker_id) REFERENCES workers(id),
    FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE pointages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    period_month TINYINT NOT NULL,
    period_year SMALLINT NOT NULL,
    status VARCHAR(20) DEFAULT 'draft', -- draft|submitted|locked
    submitted_at DATETIME NULL,
    reopened_by INT NULL,
    reopened_at DATETIME NULL,
    UNIQUE KEY uk_pointage (client_id, period_month, period_year),
    FOREIGN KEY (client_id) REFERENCES clients(id),
    FOREIGN KEY (reopened_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE pointage_lines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pointage_id INT NOT NULL,
    worker_id INT NOT NULL,
    days_worked DECIMAL(5,2) NOT NULL,
    base_salary DECIMAL(12,2),
    poste_salary DECIMAL(12,2),
    taxable_salary DECIMAL(12,2),
    FOREIGN KEY (pointage_id) REFERENCES pointages(id),
    FOREIGN KEY (worker_id) REFERENCES workers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    category VARCHAR(40) NOT NULL, -- bank_statement|purchase_invoice|sale_invoice|admin_file|other
    doc_type VARCHAR(60),
    period_month TINYINT,
    period_year SMALLINT,
    file_path VARCHAR(255),
    uploaded_by INT,
    is_pre_provisioned TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id),
    FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE monthly_document_checklist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    period_month TINYINT NOT NULL,
    period_year SMALLINT NOT NULL,
    bank_statement_received TINYINT(1) DEFAULT 0,
    purchase_invoices_received TINYINT(1) DEFAULT 0,
    sale_invoices_received TINYINT(1) DEFAULT 0,
    pointage_received TINYINT(1) DEFAULT 0,
    reminder_stage TINYINT DEFAULT 0,
    UNIQUE KEY uk_checklist (client_id, period_month, period_year),
    FOREIGN KEY (client_id) REFERENCES clients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE queue_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NULL,
    client_id INT NOT NULL,
    item_type VARCHAR(30) NOT NULL,   -- bank_statement|purchase_invoice|sale_invoice
    status VARCHAR(20) NOT NULL DEFAULT 'waiting', -- waiting|reserved|confirmed
    reserved_by INT NULL,
    reserved_at DATETIME NULL,
    confirmed_at DATETIME NULL,
    accounting_entry_number VARCHAR(50),
    bank_balance_after DECIMAL(14,2),
    accounting_result_after DECIMAL(14,2),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id),
    FOREIGN KEY (reserved_by) REFERENCES users(id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE points (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    queue_item_id INT NOT NULL,
    period_month TINYINT NOT NULL,
    period_year SMALLINT NOT NULL,
    status VARCHAR(20) DEFAULT 'temporary', -- temporary|validated|cancelled
    validated_by INT NULL,
    validated_at DATETIME NULL,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (queue_item_id) REFERENCES queue_items(id),
    FOREIGN KEY (validated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE fraud_controls (
    id INT AUTO_INCREMENT PRIMARY KEY,
    queue_item_id INT NOT NULL,
    sampled_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    bank_statement_balance DECIMAL(14,2),
    app_recorded_balance DECIMAL(14,2),
    accounting_software_balance DECIMAL(14,2),
    verdict VARCHAR(10) NULL,  -- correct|fraud
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    FOREIGN KEY (queue_item_id) REFERENCES queue_items(id),
    FOREIGN KEY (reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE audit_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50),
    entity_id INT,
    details JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE tax_declarations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    declaration_type VARCHAR(30) NOT NULL, -- g50|g12_provisoire|g12_bis_final|budget|ibs_irg_annuel|era|taxe_apprentissage
    period_month TINYINT NULL,
    period_year SMALLINT NOT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'red', -- red|orange|green
    sub_status VARCHAR(60),
    amount DECIMAL(14,2),
    filed_at DATETIME NULL,
    archived_at DATETIME NULL,
    legal_deadline DATE NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE g12_installments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tax_declaration_id INT NOT NULL,
    installment_no TINYINT NOT NULL,
    due_date DATE NOT NULL,
    percentage DECIMAL(5,2),
    amount DECIMAL(14,2),
    paid_at DATETIME NULL,
    receipt_scanned TINYINT(1) DEFAULT 0,
    FOREIGN KEY (tax_declaration_id) REFERENCES tax_declarations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE bank_operations_to_justify (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    operation_date DATE NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    check_number VARCHAR(50),
    counterparty VARCHAR(150),
    operation_type VARCHAR(15) NOT NULL, -- payment|receipt
    status VARCHAR(20) DEFAULT 'pending', -- pending|justified|rejected
    justification_text TEXT,
    justification_file_path VARCHAR(255),
    reviewed_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id),
    FOREIGN KEY (reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE semi_tax_declarations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    declaration_type VARCHAR(20) NOT NULL, -- cnas|cacobatph|casnos
    period_type VARCHAR(15),
    period_ref VARCHAR(20),
    status VARCHAR(10) NOT NULL DEFAULT 'red',
    amount DECIMAL(14,2),
    filed_at DATETIME NULL,
    paid_at DATETIME NULL,
    receipt_scanned_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE admin_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    request_source VARCHAR(10) NOT NULL, -- client|office
    doc_type VARCHAR(60) NOT NULL,
    worker_id INT NULL,
    status VARCHAR(15) NOT NULL DEFAULT 'processing', -- processing|issued|rejected
    rejection_reason TEXT,
    amount_due DECIMAL(14,2),
    client_notified TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    FOREIGN KEY (client_id) REFERENCES clients(id),
    FOREIGN KEY (worker_id) REFERENCES workers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE administration_controls (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    administration VARCHAR(30) NOT NULL, -- tax|cacobatph|casnos|cnas|labor_inspection
    documents_list TEXT,
    delivered_at DATE NULL,
    returned_at DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE deadline_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    deadline_key VARCHAR(50) UNIQUE NOT NULL,
    value_json JSON NOT NULL,
    updated_by INT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE deadline_extensions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    deadline_key VARCHAR(50) NOT NULL,
    period_ref VARCHAR(20),
    new_date DATE NOT NULL,
    visible_to_employees TINYINT(1) DEFAULT 0,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE whatsapp_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    sent_by INT NULL,
    message_text TEXT NOT NULL,
    template_name VARCHAR(60),
    method VARCHAR(15) NOT NULL, -- manual_button|cloud_api
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id),
    FOREIGN KEY (sent_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ============ بيانات أولية ============
INSERT INTO roles (code, label_ar) VALUES
 ('manager','المدير'), ('reception','الاستقبال'), ('accountant','محاسب'),
 ('tax_declarant','التصريحات الجبائية'), ('semi_tax_declarant','التصريحات شبه الجبائية'),
 ('client','زبون');

-- المستخدمون الأوليون: شغّل database/setup_users.php مرة واحدة بعد استيراد
-- هذا الملف — هو من يُنشئ الحسابات بكلمات مرور مُشفَّرة صحيحة (password_hash
-- الحقيقية لا يمكن توليدها هنا لأن PHP غير متوفر في بيئة الإنشاء هذه).

INSERT INTO deadline_settings (deadline_key, value_json) VALUES
 ('g50_day', '{"day": 20}'),
 ('extract_validity_months', '{"months": 3}'),
 ('monthly_declaration_worker_threshold', '{"threshold": 10}'),
 ('new_worker_file_alert_days', '{"days": 7}');
