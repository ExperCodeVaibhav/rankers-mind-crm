<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class AuditService
{
    public static function log(string $event, string $type, ?int $id = null, array $old = [], array $new = []): void
    {
        try {
            Database::connection()->prepare('INSERT INTO audit_logs (user_id,event,auditable_type,auditable_id,old_values,new_values,ip_address,user_agent) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([
                    $_SESSION['user_id'] ?? null, $event, $type, $id,
                    $old ? json_encode($old, JSON_UNESCAPED_UNICODE) : null,
                    $new ? json_encode($new, JSON_UNESCAPED_UNICODE) : null,
                    $_SERVER['REMOTE_ADDR'] ?? null, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
                ]);
        } catch (\Throwable $ignored) {
        }
    }

    public static function activity(string $module, string $action, string $description, ?string $type = null, ?int $id = null): void
    {
        Database::connection()->prepare('INSERT INTO activity_logs (user_id,module,action,subject_type,subject_id,description,ip_address) VALUES (?,?,?,?,?,?,?)')
            ->execute([$_SESSION['user_id'] ?? null,$module,$action,$type,$id,$description,$_SERVER['REMOTE_ADDR'] ?? null]);
    }
}
