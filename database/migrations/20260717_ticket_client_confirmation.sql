SET @schema_name = DATABASE();

SET @statement = IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=@schema_name AND table_name='tickets' AND column_name='resolved_by'),
    'SELECT 1',
    'ALTER TABLE tickets ADD COLUMN resolved_by BIGINT UNSIGNED NULL AFTER resolved_at'
);
PREPARE migration_statement FROM @statement;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @statement = IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=@schema_name AND table_name='tickets' AND column_name='client_confirmation_status'),
    'SELECT 1',
    "ALTER TABLE tickets ADD COLUMN client_confirmation_status ENUM('Not Requested','Pending','Confirmed','Rejected') NOT NULL DEFAULT 'Not Requested' AFTER resolution_note"
);
PREPARE migration_statement FROM @statement;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @statement = IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=@schema_name AND table_name='tickets' AND column_name='client_confirmation_requested_at'),
    'SELECT 1',
    'ALTER TABLE tickets ADD COLUMN client_confirmation_requested_at DATETIME NULL AFTER client_confirmation_status'
);
PREPARE migration_statement FROM @statement;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @statement = IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=@schema_name AND table_name='tickets' AND column_name='client_confirmed_at'),
    'SELECT 1',
    'ALTER TABLE tickets ADD COLUMN client_confirmed_at DATETIME NULL AFTER client_confirmation_requested_at'
);
PREPARE migration_statement FROM @statement;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @statement = IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=@schema_name AND table_name='tickets' AND column_name='closed_at'),
    'SELECT 1',
    'ALTER TABLE tickets ADD COLUMN closed_at DATETIME NULL AFTER client_confirmed_at'
);
PREPARE migration_statement FROM @statement;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @statement = IF(
    EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema=@schema_name AND table_name='tickets' AND index_name='idx_tickets_confirmation'),
    'SELECT 1',
    'ALTER TABLE tickets ADD INDEX idx_tickets_confirmation (client_confirmation_status)'
);
PREPARE migration_statement FROM @statement;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @statement = IF(
    EXISTS(SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema=@schema_name AND table_name='tickets' AND constraint_name='fk_tickets_resolver'),
    'SELECT 1',
    'ALTER TABLE tickets ADD CONSTRAINT fk_tickets_resolver FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL'
);
PREPARE migration_statement FROM @statement;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

UPDATE tickets
SET client_confirmation_status='Pending',
    client_confirmation_requested_at=COALESCE(resolved_at,updated_at),
    resolved_by=COALESCE(resolved_by,assigned_to,created_by)
WHERE status='Resolved' AND client_confirmation_status='Not Requested';

UPDATE tickets
SET client_confirmation_status='Confirmed',
    client_confirmed_at=COALESCE(client_confirmed_at,updated_at),
    closed_at=COALESCE(closed_at,updated_at)
WHERE status='Closed' AND client_confirmation_status='Not Requested';
