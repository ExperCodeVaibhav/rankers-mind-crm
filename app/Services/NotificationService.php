<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class NotificationService
{
    public static function send(int $userId, string $type, string $title, string $message, ?string $link = null): void
    {
        Database::connection()->prepare('INSERT INTO notifications (user_id,type,title,message,link) VALUES (?,?,?,?,?)')
            ->execute([$userId,$type,$title,$message,$link]);
    }

    public static function unread(int $userId, int $limit = 8): array
    {
        $statement = Database::connection()->prepare('SELECT * FROM notifications WHERE user_id=? ORDER BY read_at IS NULL DESC, created_at DESC LIMIT ' . (int) $limit);
        $statement->execute([$userId]);
        return $statement->fetchAll();
    }

    public static function unreadCount(int $userId): int
    {
        $statement = Database::connection()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL');
        $statement->execute([$userId]);
        return (int) $statement->fetchColumn();
    }
}
