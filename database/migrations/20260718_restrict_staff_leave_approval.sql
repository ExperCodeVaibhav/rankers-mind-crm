-- Staff can submit and view their own leave requests, but cannot edit or
-- approve/reject them. Approval remains with roles granted leave.approve.
DELETE rp
FROM role_permissions rp
JOIN roles r ON r.id = rp.role_id
JOIN permissions p ON p.id = rp.permission_id
WHERE r.slug = 'staff'
  AND p.slug = 'leave.edit';
