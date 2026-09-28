SET @schema_name = DATABASE();

SET @statement = IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=@schema_name AND table_name='clients' AND column_name='project_manager_id'),
    'SELECT 1',
    'ALTER TABLE clients ADD COLUMN project_manager_id BIGINT UNSIGNED NULL AFTER account_manager_id'
);
PREPARE migration_statement FROM @statement;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @statement = IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=@schema_name AND table_name='clients' AND column_name='support_staff_id'),
    'SELECT 1',
    'ALTER TABLE clients ADD COLUMN support_staff_id BIGINT UNSIGNED NULL AFTER project_manager_id'
);
PREPARE migration_statement FROM @statement;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @statement = IF(
    EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema=@schema_name AND table_name='clients' AND index_name='idx_clients_project_manager'),
    'SELECT 1',
    'ALTER TABLE clients ADD INDEX idx_clients_project_manager (project_manager_id)'
);
PREPARE migration_statement FROM @statement;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @statement = IF(
    EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema=@schema_name AND table_name='clients' AND index_name='idx_clients_support_staff'),
    'SELECT 1',
    'ALTER TABLE clients ADD INDEX idx_clients_support_staff (support_staff_id)'
);
PREPARE migration_statement FROM @statement;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @statement = IF(
    EXISTS(SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema=@schema_name AND table_name='clients' AND constraint_name='fk_clients_project_manager'),
    'SELECT 1',
    'ALTER TABLE clients ADD CONSTRAINT fk_clients_project_manager FOREIGN KEY (project_manager_id) REFERENCES users(id) ON DELETE SET NULL'
);
PREPARE migration_statement FROM @statement;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @statement = IF(
    EXISTS(SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema=@schema_name AND table_name='clients' AND constraint_name='fk_clients_support_staff'),
    'SELECT 1',
    'ALTER TABLE clients ADD CONSTRAINT fk_clients_support_staff FOREIGN KEY (support_staff_id) REFERENCES users(id) ON DELETE SET NULL'
);
PREPARE migration_statement FROM @statement;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

UPDATE clients
SET project_manager_id = account_manager_id
WHERE project_manager_id IS NULL AND account_manager_id IS NOT NULL;

UPDATE clients c
SET c.project_manager_id = (
    SELECT p.project_manager_id
    FROM projects p
    WHERE p.client_id=c.id AND p.project_manager_id IS NOT NULL AND p.deleted_at IS NULL
    ORDER BY p.id DESC
    LIMIT 1
)
WHERE c.project_manager_id IS NULL;

UPDATE clients c
SET c.support_staff_id = (
    SELECT t.assigned_to
    FROM tickets t
    JOIN users u ON u.id=t.assigned_to AND u.user_type='staff' AND u.status='active' AND u.deleted_at IS NULL
    WHERE t.client_id=c.id AND t.deleted_at IS NULL
    ORDER BY t.id DESC
    LIMIT 1
)
WHERE c.support_staff_id IS NULL;

UPDATE clients c
SET c.support_staff_id = (
    SELECT pm.user_id
    FROM projects p
    JOIN project_members pm ON pm.project_id=p.id
    JOIN users u ON u.id=pm.user_id AND u.user_type='staff' AND u.status='active' AND u.deleted_at IS NULL
    WHERE p.client_id=c.id AND p.deleted_at IS NULL
    ORDER BY p.id DESC, pm.assigned_at
    LIMIT 1
)
WHERE c.support_staff_id IS NULL;

UPDATE clients c
SET c.support_staff_id = (
    SELECT u.id FROM users u
    WHERE u.user_type='staff' AND u.status='active' AND u.deleted_at IS NULL
    ORDER BY u.id
    LIMIT 1
)
WHERE c.support_staff_id IS NULL;
