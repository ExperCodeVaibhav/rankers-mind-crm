<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

final class TicketWorkflowService
{
    public static function find(int $ticketId): ?array
    {
        $query = Database::connection()->prepare(
            'SELECT t.*,c.portal_user_id,c.portal_enabled,c.status client_status,c.project_manager_id client_project_manager_id,c.support_staff_id,p.project_manager_id project_project_manager_id
             FROM tickets t
             JOIN clients c ON c.id=t.client_id
             LEFT JOIN projects p ON p.id=t.project_id AND p.deleted_at IS NULL
             WHERE t.id=? AND t.deleted_at IS NULL AND c.deleted_at IS NULL'
        );
        $query->execute([$ticketId]);
        return $query->fetch() ?: null;
    }

    public static function canHandle(array $ticket, array $user): bool
    {
        if ($user['user_type'] === 'admin') {
            return can('tickets.edit');
        }
        if ($user['user_type'] !== 'staff') {
            return false;
        }
        $userId = (int) $user['id'];
        if ((int) $ticket['assigned_to'] === $userId || (int) $ticket['client_project_manager_id'] === $userId || (int) $ticket['project_project_manager_id'] === $userId) {
            return true;
        }
        if (empty($ticket['project_id'])) {
            return false;
        }
        $query = Database::connection()->prepare('SELECT 1 FROM project_members WHERE project_id=? AND user_id=?');
        $query->execute([(int) $ticket['project_id'], $userId]);
        return (bool) $query->fetchColumn();
    }

    public static function resolve(int $ticketId, int $actorId, string $resolutionNote): void
    {
        Database::transaction(function (PDO $db) use ($ticketId, $actorId, $resolutionNote): void {
            $query = $db->prepare('SELECT t.*,c.portal_user_id FROM tickets t JOIN clients c ON c.id=t.client_id WHERE t.id=? AND t.deleted_at IS NULL FOR UPDATE');
            $query->execute([$ticketId]);
            $ticket = $query->fetch();
            if (!$ticket) {
                throw new \RuntimeException('Ticket not found.');
            }
            if ($ticket['status'] === 'Closed') {
                throw new \RuntimeException('A client-confirmed closed ticket cannot be resolved again.');
            }
            if ($ticket['status'] === 'Resolved' && $ticket['client_confirmation_status'] === 'Pending') {
                throw new \RuntimeException('This ticket is already awaiting client confirmation.');
            }

            $note = mb_substr(trim($resolutionNote), 0, 5000);
            $db->prepare('INSERT INTO ticket_messages(ticket_id,user_id,message,is_internal) VALUES(?,?,?,0)')->execute([$ticketId, $actorId, $note]);
            $db->prepare("UPDATE tickets SET status='Resolved',resolved_at=NOW(),resolved_by=?,resolution_note=?,client_confirmation_status='Pending',client_confirmation_requested_at=NOW(),client_confirmed_at=NULL,closed_at=NULL WHERE id=?")
                ->execute([$actorId, $note, $ticketId]);

            if ($ticket['portal_user_id']) {
                NotificationService::send(
                    (int) $ticket['portal_user_id'],
                    'ticket_resolution_confirmation',
                    'Is your issue resolved?',
                    'The team marked '.$ticket['ticket_number'].' as resolved. Please confirm Yes to close it or No to reopen it.',
                    '/portal/tickets/'.$ticketId
                );
            }
        });
    }

    public static function confirm(int $ticketId, int $portalUserId, bool $isResolved, string $clientNote = ''): string
    {
        return Database::transaction(function (PDO $db) use ($ticketId, $portalUserId, $isResolved, $clientNote): string {
            $query = $db->prepare('SELECT t.*,c.portal_user_id,c.project_manager_id client_project_manager_id,c.support_staff_id,p.project_manager_id project_project_manager_id FROM tickets t JOIN clients c ON c.id=t.client_id LEFT JOIN projects p ON p.id=t.project_id AND p.deleted_at IS NULL WHERE t.id=? AND t.deleted_at IS NULL FOR UPDATE');
            $query->execute([$ticketId]);
            $ticket = $query->fetch();
            if (!$ticket || (int) $ticket['portal_user_id'] !== $portalUserId) {
                throw new \RuntimeException('Ticket not found.');
            }
            if ($ticket['status'] !== 'Resolved' || $ticket['client_confirmation_status'] !== 'Pending') {
                throw new \RuntimeException('This ticket is not awaiting your resolution confirmation.');
            }

            $note = trim($clientNote);
            $message = $isResolved
                ? 'Client confirmed: Yes, the issue is resolved.'
                : 'Client response: No, the issue is still not resolved.'.($note !== '' ? "\n\n".mb_substr($note, 0, 4000) : '');
            $db->prepare('INSERT INTO ticket_messages(ticket_id,user_id,message,is_internal) VALUES(?,?,?,0)')->execute([$ticketId, $portalUserId, $message]);

            if ($isResolved) {
                $status = 'Closed';
                $db->prepare("UPDATE tickets SET status='Closed',client_confirmation_status='Confirmed',client_confirmed_at=NOW(),closed_at=NOW() WHERE id=?")->execute([$ticketId]);
                $title = 'Client closed '.$ticket['ticket_number'];
                $notificationMessage = 'The client confirmed that the issue is resolved. The ticket is now closed.';
            } else {
                $status = 'Reopened';
                $db->prepare("UPDATE tickets SET status='Reopened',resolved_at=NULL,client_confirmation_status='Rejected',client_confirmed_at=NOW(),closed_at=NULL WHERE id=?")->execute([$ticketId]);
                $title = 'Client reopened '.$ticket['ticket_number'];
                $notificationMessage = 'The client said the issue is not resolved. The ticket has been reopened.';
            }

            foreach (self::recipientIds($db, $ticket) as $recipientId) {
                NotificationService::send($recipientId, $isResolved ? 'ticket_client_confirmed' : 'ticket_client_rejected', $title, $notificationMessage, '/tickets/'.$ticketId);
            }
            return $status;
        });
    }

    public static function notifyTeam(array $ticket, string $type, string $title, string $message): void
    {
        $db = Database::connection();
        foreach (self::recipientIds($db, $ticket) as $recipientId) {
            NotificationService::send($recipientId, $type, $title, $message, '/tickets/'.$ticket['id']);
        }
    }

    private static function recipientIds(PDO $db, array $ticket): array
    {
        $ids = array_filter([
            (int) ($ticket['assigned_to'] ?? 0),
            (int) ($ticket['resolved_by'] ?? 0),
            (int) ($ticket['client_project_manager_id'] ?? 0),
            (int) ($ticket['support_staff_id'] ?? 0),
            (int) ($ticket['project_project_manager_id'] ?? 0),
        ]);
        if (!empty($ticket['project_id'])) {
            $query = $db->prepare('SELECT user_id FROM project_members WHERE project_id=?');
            $query->execute([(int) $ticket['project_id']]);
            $ids = [...$ids, ...array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN))];
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $query = $db->prepare("SELECT id FROM users WHERE id IN ({$placeholders}) AND status='active' AND deleted_at IS NULL");
        $query->execute($ids);
        return array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN));
    }
}
