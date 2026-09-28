<?php
$activeProjects = count(array_filter($projects, fn($project) => !in_array($project['status'], ['Completed','Cancelled'], true)));
$pendingAmount = array_sum(array_map(fn($invoice) => (float) $invoice['pending_amount'], $invoices));
$unpaidInvoices = count(array_filter($invoices, fn($invoice) => (float) $invoice['pending_amount'] > 0));
$openTickets = count(array_filter($tickets, fn($ticket) => !in_array($ticket['status'], ['Resolved','Closed'], true)));
?>
<section class="portal-hero client-hero">
    <div><span class="eyebrow">Secure Client Portal</span><h1>Welcome, <?= e($client['primary_contact']) ?></h1><p><?= e($client['company_name']) ?> · <?= e($client['client_code']) ?></p></div>
    <div class="client-hero-actions"><a class="button button-secondary button-sm" href="#projects"><i class="fa-solid fa-diagram-project"></i> View projects</a><button class="button button-primary button-sm" data-modal-open="ticketModal"><i class="fa-solid fa-headset"></i> New support ticket</button></div>
</section>

<div class="staff-metrics client-metrics">
    <div><span>Active projects</span><strong><?= $activeProjects ?></strong><small><?= count($projects) ?> total projects</small></div>
    <div><span>Unpaid invoices</span><strong><?= $unpaidInvoices ?></strong><small><?= count($invoices) ?> visible invoices</small></div>
    <div><span>Outstanding amount</span><strong><?= money($pendingAmount) ?></strong><small>Across this client account only</small></div>
    <div><span>Open support tickets</span><strong><?= $openTickets ?></strong><small><?= count($tickets) ?> total tickets</small></div>
</div>

<?php if($announcements): ?><div class="announcement-banner"><i class="fa-solid fa-bullhorn"></i><div><strong><?= e($announcements[0]['title']) ?></strong><p><?= e($announcements[0]['body']) ?></p></div></div><?php endif; ?>

<div class="portal-grid client-portal-grid">
    <section class="panel span-2" id="projects">
        <div class="panel-head"><div><h2>My projects</h2><p>Live progress and updates published for your company</p></div><span class="status-badge info"><?= count($projects) ?> total</span></div>
        <?php if($projects): ?><div class="project-cards"><?php foreach($projects as $project): ?><a href="<?= url('portal/projects/'.$project['id']) ?>" class="project-card"><div><span class="status-badge <?= status_class($project['status']) ?>"><?= e($project['status']) ?></span><span><?= e($project['project_code']) ?></span></div><h3><?= e($project['name']) ?></h3><p><?= e($project['client_visible_notes']?:mb_substr($project['description']??'',0,110)) ?></p><div class="project-progress"><span><i style="width:<?= (int)$project['progress'] ?>%"></i></span><strong><?= (int)$project['progress'] ?>%</strong></div><small>Expected <?= e(date('d M Y',strtotime($project['expected_completion_date']))) ?></small></a><?php endforeach; ?></div><?php else: ?><div class="empty-state compact"><span><i class="fa-solid fa-diagram-project"></i></span><h3>No projects published yet</h3><p>Your projects will appear here after the Rankers Mind team links them to this client account.</p></div><?php endif; ?>
    </section>

    <section class="panel" id="invoices">
        <div class="panel-head"><div><h2>Invoices</h2><p>View details or download a PDF</p></div></div>
        <div class="compact-list"><?php foreach($invoices as $invoice): ?><div class="list-row portal-invoice-row"><span class="list-icon"><i class="fa-solid fa-file-invoice-dollar"></i></span><span class="list-main"><strong><?= e($invoice['invoice_number']) ?></strong><small><?= e(date('d M Y',strtotime($invoice['invoice_date']))) ?> · <?= money($invoice['grand_total']) ?></small><span class="status-badge <?= status_class($invoice['status']) ?>"><?= e($invoice['status']) ?></span></span><span class="portal-invoice-actions"><a class="button button-secondary button-sm" href="<?= url('portal/invoices/'.$invoice['id']) ?>">View</a><a class="button button-primary button-sm" href="<?= url('invoices/'.$invoice['id'].'/print?format=pdf') ?>"><i class="fa-solid fa-download"></i> PDF</a></span></div><?php endforeach; ?><?php if(!$invoices): ?><div class="empty-state compact"><span><i class="fa-solid fa-file-invoice"></i></span><h3>No invoices available</h3><p>Only invoices published for this client account appear here.</p></div><?php endif; ?></div>
    </section>

    <section class="panel" id="support">
        <div class="panel-head"><div><h2>Support</h2><p>Raise and track company queries</p></div><button class="button button-primary button-sm" data-modal-open="ticketModal">New ticket</button></div>
        <div class="compact-list"><?php foreach(array_slice($tickets,0,6) as $ticket): ?><a class="list-row" href="<?= url('portal/tickets/'.$ticket['id']) ?>"><span class="list-icon"><i class="fa-solid fa-headset"></i></span><span class="list-main"><strong><?= e($ticket['subject']) ?></strong><small><?= e($ticket['ticket_number']) ?> · <?= e(date('d M Y',strtotime($ticket['created_at']))) ?></small></span><span class="status-badge <?= status_class($ticket['status']) ?>"><?= e($ticket['status']) ?></span></a><?php endforeach; ?><?php if(!$tickets): ?><div class="empty-state compact"><span><i class="fa-regular fa-circle-check"></i></span><h3>No support tickets</h3><p>Create a ticket whenever your team needs help.</p></div><?php endif; ?></div>
    </section>

    <?php require root_path('app/Views/portal/client-payments.php'); ?>
    <?php require root_path('app/Views/portal/client-documents.php'); ?>
    <?php require root_path('app/Views/portal/client-profile.php'); ?>
</div>

<dialog class="modal" id="ticketModal" aria-labelledby="ticketModalTitle">
    <form method="post" action="<?= url('portal/tickets') ?>" data-ticket-form>
        <?= csrf_field() ?>
        <div class="modal-head"><div><h3 id="ticketModalTitle">Raise a support ticket</h3><p>Your assigned project manager and support staff will be notified immediately.</p></div><button type="button" data-modal-close aria-label="Close ticket form">×</button></div>
        <div class="modal-body form-grid">
            <label class="wide">Subject *<input name="subject" required maxlength="190"></label>
            <label>Related project<select name="project_id"><option value="">General query</option><?php foreach($projects as $project): ?><option value="<?= $project['id'] ?>"><?= e($project['name']) ?></option><?php endforeach; ?></select></label>
            <label>Category<select name="category"><option>General</option><option>Technical</option><option>Billing</option><option>Project</option><option>Content</option><option>Access</option></select></label>
            <label>Priority<select name="priority"><option>Low</option><option selected>Medium</option><option>High</option><option>Urgent</option></select></label>
            <label class="wide">Description *<textarea name="description" rows="5" required></textarea></label>
        </div>
        <div class="form-actions"><button type="button" class="button button-secondary" data-modal-close>Cancel</button><button type="submit" class="button button-primary">Create ticket</button></div>
    </form>
</dialog>
