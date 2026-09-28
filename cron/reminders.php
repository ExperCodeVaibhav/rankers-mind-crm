<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Services\NotificationService;

$provided = $argv[1] ?? ($_GET['key'] ?? '');
$expected = (string) \App\Config\Env::get('REMINDER_CRON_KEY', '');
if (PHP_SAPI !== 'cli' && (!$expected || !hash_equals($expected, (string) $provided))) {
    http_response_code(403);
    exit('Invalid cron key.');
}

$db = Database::connection();
$db->beginTransaction();
try {
    $due = $db->query("SELECT * FROM reminders WHERE status='pending' AND remind_at<=NOW() FOR UPDATE")->fetchAll();
    foreach ($due as $reminder) {
        NotificationService::send((int) $reminder['user_id'], 'reminder', 'Reminder due', ucfirst($reminder['related_type']) . ' reminder is due.', '/' . $reminder['related_type'] . 's/' . $reminder['related_id']);
        $db->prepare("UPDATE reminders SET status='sent',sent_at=NOW() WHERE id=?")->execute([$reminder['id']]);
    }
    $followups = $db->query("SELECT f.* FROM lead_followups f WHERE f.status='Scheduled' AND f.reminder_at<=NOW() AND f.reminder_at>=DATE_SUB(NOW(),INTERVAL 1 DAY) AND NOT EXISTS (SELECT 1 FROM notifications n WHERE n.user_id=f.assigned_to AND n.type='followup_reminder' AND n.link=CONCAT('/followups/',f.id))")->fetchAll();
    foreach ($followups as $followup) NotificationService::send((int) $followup['assigned_to'], 'followup_reminder', 'Follow-up reminder', $followup['followup_type'] . ' follow-up is due at ' . date('g:i A', strtotime($followup['scheduled_at'])), '/followups/' . $followup['id']);
    $db->commit();
    echo date('c') . ' processed ' . count($due) . ' reminders and ' . count($followups) . " follow-ups.\n";
} catch (Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
