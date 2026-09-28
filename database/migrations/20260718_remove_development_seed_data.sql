-- Remove only records created by the former development seed. Real records
-- with other identifiers are intentionally left untouched.

DROP TEMPORARY TABLE IF EXISTS legacy_demo_users;
CREATE TEMPORARY TABLE legacy_demo_users (id BIGINT UNSIGNED PRIMARY KEY);
INSERT INTO legacy_demo_users (id)
SELECT id FROM users WHERE email IN (
    'admin@rankersmind.local',
    'cmo@rankersmind.local',
    'hr@rankersmind.local',
    'accounts@rankersmind.local',
    'pm@rankersmind.local',
    'staff@rankersmind.local',
    'neha@rankersmind.local',
    'rahul@rankersmind.local',
    'pooja@rankersmind.local',
    'sourav@rankersmind.local',
    'client@rankersmind.local',
    'manish.client@rankersmind.local',
    'farah.client@rankersmind.local'
);

DROP TEMPORARY TABLE IF EXISTS legacy_demo_clients;
CREATE TEMPORARY TABLE legacy_demo_clients (id BIGINT UNSIGNED PRIMARY KEY);
INSERT INTO legacy_demo_clients (id)
SELECT id FROM clients WHERE client_code IN ('RM-CLI-001','RM-CLI-002','RM-CLI-003');

DROP TEMPORARY TABLE IF EXISTS legacy_demo_projects;
CREATE TEMPORARY TABLE legacy_demo_projects (id BIGINT UNSIGNED PRIMARY KEY);
INSERT INTO legacy_demo_projects (id)
SELECT id FROM projects WHERE project_code IN ('RM-PRJ-001','RM-PRJ-002','RM-PRJ-003','RM-PRJ-004','RM-PRJ-005');

DELETE p FROM payments p
JOIN invoices i ON i.id = p.invoice_id
WHERE i.invoice_number IN ('RM-INV-0001','RM-INV-0002','RM-INV-0003')
   OR i.client_id IN (SELECT id FROM legacy_demo_clients);

DELETE FROM invoices
WHERE invoice_number IN ('RM-INV-0001','RM-INV-0002','RM-INV-0003')
   OR client_id IN (SELECT id FROM legacy_demo_clients);

DELETE FROM estimates
WHERE estimate_number = 'RM-EST-0001'
   OR client_id IN (SELECT id FROM legacy_demo_clients);

DELETE FROM tickets
WHERE ticket_number IN ('RM-TKT-0001','RM-TKT-0002')
   OR client_id IN (SELECT id FROM legacy_demo_clients);

DELETE FROM projects WHERE id IN (SELECT id FROM legacy_demo_projects);
DELETE FROM clients WHERE id IN (SELECT id FROM legacy_demo_clients);
DELETE FROM leads WHERE lead_code IN (
    'RM-LEAD-001','RM-LEAD-002','RM-LEAD-003','RM-LEAD-004','RM-LEAD-005',
    'RM-LEAD-006','RM-LEAD-007','RM-LEAD-008','RM-LEAD-009','RM-LEAD-010',
    'RM-LEAD-011','RM-LEAD-012','RM-LEAD-013','RM-LEAD-014','RM-LEAD-015'
);

DELETE aa FROM attendance_adjustments aa
JOIN legacy_demo_users du ON du.id = aa.changed_by;

DELETE pe FROM payroll_entries pe
JOIN staff_profiles sp ON sp.id = pe.staff_id
JOIN legacy_demo_users du ON du.id = sp.user_id;

DELETE pp FROM payroll_periods pp
WHERE pp.notes = 'Development payroll draft'
  AND NOT EXISTS (SELECT 1 FROM payroll_entries pe WHERE pe.payroll_period_id = pp.id);

DELETE lf FROM lead_followups lf
JOIN legacy_demo_users du ON du.id = lf.assigned_to;

DELETE tm FROM ticket_messages tm
JOIN legacy_demo_users du ON du.id = tm.user_id;

DELETE FROM announcements
WHERE title = 'Welcome to Rankers Mind CRM'
  AND created_by IN (SELECT id FROM legacy_demo_users);

DELETE FROM activity_logs
WHERE module = 'system' AND action = 'seed' AND description = 'Demo data installed successfully';

DELETE u FROM users u JOIN legacy_demo_users du ON du.id = u.id;

DELETE FROM office_locations
WHERE name = 'Rankers Mind Head Office'
  AND latitude = 26.8101600
  AND longitude = 75.8635100;

UPDATE attendance_rules ar
JOIN branches b ON b.id = ar.branch_id AND b.code = 'HO'
SET ar.require_geofence = 0,
    ar.require_photo = 0,
    ar.late_fine_type = 'none',
    ar.late_fine_amount = 0,
    ar.max_monthly_late_deduction = 0
WHERE ar.require_geofence = 1
  AND ar.require_photo = 1
  AND ar.late_fine_type = 'occurrence'
  AND ar.late_fine_amount = 100;

UPDATE settings SET `value` = ''
WHERE (`key` = 'company_email' AND `value` = 'info@rankersmind.local')
   OR (`key` = 'company_phone' AND `value` = '+91 90000 00000')
   OR (`key` = 'company_address' AND `value` = 'Kolkata, West Bengal, India');

DROP TEMPORARY TABLE IF EXISTS legacy_demo_projects;
DROP TEMPORARY TABLE IF EXISTS legacy_demo_clients;
DROP TEMPORARY TABLE IF EXISTS legacy_demo_users;
