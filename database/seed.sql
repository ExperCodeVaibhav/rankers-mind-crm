SET NAMES utf8mb4;
SET time_zone = '+05:30';

-- Production-safe system bootstrap only. This file intentionally contains no
-- development users, clients, leads, projects, transactions, or activity data.

INSERT INTO branches (name, code) VALUES
('Rankers Mind - Head Office', 'HO')
ON DUPLICATE KEY UPDATE code = VALUES(code);

INSERT INTO roles (name, slug, description, is_system) VALUES
('Super Admin / Owner', 'super-admin', 'Complete system access', 1),
('CEO', 'ceo', 'Executive access', 1),
('Founder', 'founder', 'Executive access', 1),
('CMO', 'cmo', 'Sales and marketing access', 1),
('HR Manager', 'hr-manager', 'Staff, attendance and payroll access', 1),
('Accounts Manager', 'accounts-manager', 'Billing and payroll financial access', 1),
('Project Manager', 'project-manager', 'Projects and task management', 1),
('Team Leader', 'team-leader', 'Team project and task access', 1),
('Staff', 'staff', 'Personal staff portal access', 1),
('Client', 'client', 'Own client portal access', 1)
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), is_system = VALUES(is_system);

DROP TEMPORARY TABLE IF EXISTS tmp_modules;
DROP TEMPORARY TABLE IF EXISTS tmp_actions;
CREATE TEMPORARY TABLE tmp_modules (module VARCHAR(80), label VARCHAR(100));
CREATE TEMPORARY TABLE tmp_actions (action VARCHAR(40), label VARCHAR(60));

INSERT INTO tmp_modules VALUES
('dashboard','Dashboard'),('leads','Leads'),('followups','Follow-ups'),('clients','Clients'),
('projects','Projects'),('tasks','Tasks'),('staff','Staff'),('attendance','Attendance'),
('leave','Leave'),('payroll','Payroll'),('invoices','Invoices'),('payments','Payments'),
('tickets','Support Tickets'),('reports','Reports'),('settings','Settings'),('roles','Roles'),
('notifications','Notifications'),('backups','Backups'),('announcements','Announcements'),('audit','Audit Logs');

INSERT INTO tmp_actions VALUES
('view','View'),('create','Create'),('edit','Edit'),('delete','Delete'),('approve','Approve'),
('export','Export'),('assign','Assign'),('financial','View Financial Data'),('payroll','View Payroll Data'),('manage','Manage');

INSERT IGNORE INTO permissions (module, action, slug, label)
SELECT m.module, a.action, CONCAT(m.module, '.', a.action), CONCAT(a.label, ' ', m.label)
FROM tmp_modules m CROSS JOIN tmp_actions a;

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.slug = 'super-admin';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.slug IN ('ceo','founder') AND p.module IN ('dashboard','leads','followups','clients','projects','tasks','staff','attendance','leave','payroll','invoices','payments','tickets','reports','notifications','announcements','audit')
AND p.action IN ('view','create','edit','approve','export','assign','financial','payroll');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.slug = 'cmo' AND p.module IN ('dashboard','leads','followups','clients','reports','notifications')
AND p.action IN ('view','create','edit','delete','export','assign');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.slug = 'hr-manager' AND p.module IN ('dashboard','staff','attendance','leave','payroll','tasks','reports','notifications','announcements')
AND p.action IN ('view','create','edit','approve','export','assign','payroll');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.slug = 'accounts-manager' AND p.module IN ('dashboard','clients','payroll','invoices','payments','reports','notifications')
AND p.action IN ('view','create','edit','approve','export','financial','payroll');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.slug IN ('project-manager','team-leader') AND p.module IN ('dashboard','clients','projects','tasks','tickets','reports','notifications')
AND p.action IN ('view','create','edit','approve','export','assign');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.slug = 'staff' AND p.module IN ('dashboard','projects','tasks','attendance','leave','payroll','tickets','notifications')
AND p.action IN ('view','create','edit','export')
AND NOT (p.module = 'leave' AND p.action = 'edit');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.slug = 'client' AND p.module IN ('dashboard','projects','invoices','payments','tickets','notifications','announcements')
AND p.action IN ('view','create','edit','export');

DROP TEMPORARY TABLE IF EXISTS tmp_modules;
DROP TEMPORARY TABLE IF EXISTS tmp_actions;

-- Reference values required by leave and attendance workflows. These are
-- configuration records, not example activity or employee data.
INSERT INTO leave_types (name, code, is_paid, annual_allowance, requires_document) VALUES
('Casual Leave','CL',1,12,0),
('Sick Leave','SL',1,8,1),
('Earned Leave','EL',1,12,0),
('Leave Without Pay','LWP',0,0,0)
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO attendance_rules (
    branch_id, grace_minutes, half_day_minutes, full_day_minutes,
    overtime_after_minutes, missing_checkout_action, require_geofence,
    require_photo, max_accuracy_metres, late_fine_type, late_fine_amount,
    max_monthly_late_deduction
)
SELECT id, 10, 240, 480, 540, 'flag', 0, 0, 300, 'none', 0, 0
FROM branches WHERE code = 'HO'
AND NOT EXISTS (SELECT 1 FROM attendance_rules);

INSERT INTO settings (`group`,`key`,`value`,type,is_public) VALUES
('company','company_name','Rankers Mind','string',1),
('company','company_email','','string',1),
('company','company_phone','','string',1),
('company','company_address','','string',1),
('company','currency','INR','string',1),
('company','financial_year_start','04-01','string',0),
('numbering','lead_prefix','RM-LEAD-','string',0),
('numbering','client_prefix','RM-CLI-','string',0),
('numbering','project_prefix','RM-PRJ-','string',0),
('numbering','invoice_prefix','RM-INV-','string',0),
('numbering','employee_prefix','RM-EMP-','string',0),
('tax','default_gst_percent','18','number',0),
('notifications','email_enabled','false','boolean',0),
('notifications','whatsapp_provider','','string',0)
ON DUPLICATE KEY UPDATE `key` = VALUES(`key`);
