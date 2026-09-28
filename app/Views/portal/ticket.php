<section class="page-header">
    <div>
        <a class="back-link" href="<?= url('portal/client#support') ?>"><i class="fa-solid fa-arrow-left"></i> Back to support</a>
        <h1><?= e($ticket['subject']) ?></h1>
        <p><?= e($ticket['ticket_number']) ?> · <?= e($ticket['project_name']?:'General support') ?></p>
    </div>
    <span class="status-badge <?= status_class($ticket['status']) ?>"><?= e($ticket['status']) ?></span>
</section>

<section class="panel client-ticket-panel">
    <div class="ticket-thread">
        <div class="ticket-message"><div><strong>You</strong><span>Original request</span></div><p><?= nl2br(e($ticket['description'])) ?></p><small><?= e(date('d M Y, g:i A',strtotime($ticket['created_at']))) ?></small></div>
        <?php foreach($messages as $message): ?>
            <div class="ticket-message"><div><strong><?= e($message['name']) ?></strong><span><?= e(ucfirst($message['user_type'])) ?></span></div><p><?= nl2br(e($message['message'])) ?></p><small><?= e(date('d M Y, g:i A',strtotime($message['created_at']))) ?></small></div>
        <?php endforeach; ?>
    </div>

    <?php if($ticket['status']==='Resolved' && ($ticket['client_confirmation_status']??'')==='Pending'): ?>
        <section class="client-resolution-confirmation">
            <span class="confirmation-icon"><i class="fa-solid fa-circle-question"></i></span>
            <div class="confirmation-copy"><span class="eyebrow">Your confirmation is required</span><h2>Is your issue resolved?</h2><p>Please confirm the result. The ticket will close only when you select Yes.</p><?php if($ticket['resolution_note']):?><div class="resolution-summary"><strong>Resolution from the team</strong><p><?= nl2br(e($ticket['resolution_note'])) ?></p></div><?php endif;?></div>
            <div class="confirmation-actions">
                <form method="post" action="<?= url('portal/tickets/'.$ticket['id'].'/confirmation') ?>"><?= csrf_field() ?><input type="hidden" name="resolved" value="yes"><button class="button button-success"><i class="fa-solid fa-check"></i> Yes, issue resolved</button></form>
                <form class="confirmation-no-form" method="post" action="<?= url('portal/tickets/'.$ticket['id'].'/confirmation') ?>"><?= csrf_field() ?><input type="hidden" name="resolved" value="no"><textarea name="client_note" rows="2" placeholder="Tell us what is still not working (optional)"></textarea><button class="button button-danger"><i class="fa-solid fa-rotate-left"></i> No, issue continues</button></form>
            </div>
        </section>
    <?php elseif($ticket['status']==='Closed' && ($ticket['client_confirmation_status']??'')==='Confirmed'): ?>
        <div class="resolution-state confirmed client-closed-state"><i class="fa-solid fa-circle-check"></i><div><strong>Ticket closed</strong><p>You confirmed that this issue was resolved.</p></div></div>
    <?php else: ?>
        <form class="mini-form" method="post" action="<?= url('tickets/'.$ticket['id'].'/reply') ?>"><?= csrf_field() ?><textarea name="message" required placeholder="Reply to the Rankers Mind team…"></textarea><div><button class="button button-primary">Post reply</button></div></form>
    <?php endif; ?>
</section>
