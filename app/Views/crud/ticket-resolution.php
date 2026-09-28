<?php
$confirmationStatus = $record['client_confirmation_status'] ?? 'Not Requested';
$awaitingClient = $record['status'] === 'Resolved' && $confirmationStatus === 'Pending';
$closedByClient = $record['status'] === 'Closed' && $confirmationStatus === 'Confirmed';
?>
<section class="panel span-2 ticket-resolution-panel" data-ticket-workflow-state="<?= e(strtolower($record['status'])) ?>">
    <div class="panel-head">
        <div><h2>Resolution & client confirmation</h2><p>A team resolution does not close the ticket until the client confirms Yes.</p></div>
    </div>
    <?php if($awaitingClient): ?>
        <div class="resolution-state awaiting"><i class="fa-regular fa-clock"></i><div><strong>Awaiting client confirmation</strong><p>The client received “Is your issue resolved?” and must answer before this ticket can close.</p></div></div>
    <?php elseif($closedByClient): ?>
        <div class="resolution-state confirmed"><i class="fa-solid fa-circle-check"></i><div><strong>Closed by client confirmation</strong><p>The client confirmed the issue was resolved<?= $record['client_confirmed_at']?' on '.e(date('d M Y, g:i A',strtotime($record['client_confirmed_at']))):'' ?>.</p></div></div>
    <?php else: ?>
        <form class="mini-form ticket-resolve-form" method="post" action="<?= url('tickets/'.$record['id'].'/resolve') ?>">
            <?= csrf_field() ?>
            <textarea name="resolution_note" required placeholder="Summarise exactly what was fixed for the client…"><?= e($record['resolution_note']??'') ?></textarea>
            <div><span class="resolution-hint"><i class="fa-solid fa-user-check"></i> This sends a confirmation request to the client.</span><button class="button button-success"><i class="fa-solid fa-check-double"></i> Mark resolved & ask client</button></div>
        </form>
    <?php endif; ?>
</section>
