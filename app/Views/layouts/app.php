<?php
$user = auth();
$successMessage = flash('success');
$errorMessage = flash('error');
$logoExists = is_file(public_path('assets/images/rankers-mind-logo.png'));
$navGroups = [
    'Workspace' => [
        ['dashboard','Dashboard','fa-chart-pie','dashboard.view'],
        ['favourites','Favourites & Recent','fa-star','dashboard.view'],
        ['leads','Leads','fa-bullseye','leads.view'],
        ['followups','Follow-ups','fa-calendar-check','followups.view'],
        ['clients','Clients','fa-building','clients.view'],
    ],
    'Delivery' => [
        ['projects','Projects','fa-diagram-project','projects.view'],
        ['tasks','Tasks','fa-list-check','tasks.view'],
        ['tickets','Support','fa-headset','tickets.view'],
    ],
    'People' => [
        ['staff','Staff Directory','fa-users','staff.view'],
        ['attendance','Attendance','fa-location-dot','attendance.view'],
        ['attendance-corrections','Attendance Corrections','fa-clock-rotate-left','attendance.view'],
        ['leave','Leave Requests','fa-plane-departure','leave.view'],
        ['salary-advances','Salary Advances','fa-hand-holding-dollar','payroll.view'],
        ['payroll','Payroll','fa-money-check-dollar','payroll.view'],
    ],
    'Finance & Insights' => [
        ['estimates','Estimates','fa-file-signature','invoices.view'],
        ['invoices','Invoices','fa-file-invoice-dollar','invoices.view'],
        ['reports','Reports Centre','fa-chart-line','reports.view'],
    ],
];
$requestPath = trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '', '/');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="theme-color" content="#101936">
    <title><?= e($title ?? 'Dashboard') ?> · Rankers Mind CRM</title>
    <link rel="icon" href="<?= asset('images/favicon.svg') ?>?v=1.0.0" type="image/svg+xml">
    <link rel="manifest" href="<?= url('site.webmanifest') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <link href="<?= asset('vendor/bootstrap/bootstrap.min.css') ?>?v=5.3.8" rel="stylesheet">
    <link href="<?= asset('css/app.css') ?>?v=1.2.1" rel="stylesheet">
    <link href="<?= asset('css/modules.css') ?>?v=1.1.0" rel="stylesheet">
    <link href="<?= asset('css/enterprise.css') ?>?v=1.0.3" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js" defer></script>
</head>
<body class="app-body">
<div class="app-shell" id="appShell">
    <aside class="sidebar" id="sidebar">
        <a class="brand" href="<?= url('dashboard') ?>" aria-label="Rankers Mind CRM home">
            <?php if ($logoExists): ?><span class="rm-logo sidebar-logo" role="img" aria-label="Rankers Mind"></span><span class="brand-mark brand-compact" aria-hidden="true">RM</span><?php else: ?><span class="brand-mark">RM</span><span class="brand-copy"><strong>Rankers Mind</strong><small>CRM Workspace</small></span><?php endif; ?>
        </a>
        <nav class="sidebar-nav" aria-label="Main navigation">
            <?php if ($user['user_type'] === 'staff'): ?>
                <div class="nav-group"><span class="nav-label">Staff Portal</span>
                    <a href="<?= url('portal/staff') ?>"><i class="fa-solid fa-grid-2"></i><span>My Dashboard</span></a>
                    <a href="<?= url('attendance/punch') ?>"><i class="fa-solid fa-camera"></i><span>Punch Attendance</span></a>
                    <a href="<?= url('tasks') ?>"><i class="fa-solid fa-list-check"></i><span>My Tasks</span></a>
                    <a href="<?= url('leave/create') ?>"><i class="fa-solid fa-plane-departure"></i><span>Request Leave</span></a>
                    <a href="<?= url('attendance-corrections/create') ?>"><i class="fa-solid fa-clock-rotate-left"></i><span>Attendance Correction</span></a>
                </div>
            <?php elseif ($user['user_type'] === 'client'): ?>
                <div class="nav-group"><span class="nav-label">Client Portal</span>
                    <a href="<?= url('portal/client') ?>"><i class="fa-solid fa-grid-2"></i><span>My Dashboard</span></a>
                    <a href="<?= url('portal/client') ?>#projects"><i class="fa-solid fa-diagram-project"></i><span>My Projects</span></a>
                    <a href="<?= url('portal/client') ?>#invoices"><i class="fa-solid fa-file-invoice-dollar"></i><span>Invoices</span></a>
                    <a href="<?= url('portal/client') ?>#payments"><i class="fa-solid fa-receipt"></i><span>Payment Receipts</span></a>
                    <a href="<?= url('portal/client') ?>#documents"><i class="fa-solid fa-file-shield"></i><span>Documents</span></a>
                    <a href="<?= url('portal/client') ?>#support"><i class="fa-solid fa-headset"></i><span>Support</span></a>
                    <a href="<?= url('portal/client') ?>#profile"><i class="fa-solid fa-building-user"></i><span>Company Profile</span></a>
                </div>
            <?php else: ?>
                <?php foreach ($navGroups as $group => $items): ?>
                    <?php $visible = array_filter($items, fn($item) => can($item[3])); if (!$visible) continue; ?>
                    <div class="nav-group"><span class="nav-label"><?= e($group) ?></span>
                        <?php foreach ($visible as [$path,$label,$icon,$permission]): $active = str_contains($requestPath, $path); ?>
                            <a href="<?= url($path) ?>" class="<?= $active ? 'active' : '' ?>"><i class="fa-solid <?= e($icon) ?>"></i><span><?= e($label) ?></span></a>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
                <?php if (can('settings.manage')): ?><div class="nav-group"><span class="nav-label">Administration</span>
                    <a href="<?= url('settings') ?>"><i class="fa-solid fa-gear"></i><span>Settings</span></a>
                    <a href="<?= url('settings/roles') ?>"><i class="fa-solid fa-shield-halved"></i><span>Roles & Permissions</span></a>
                </div><?php endif; ?>
            <?php endif; ?>
        </nav>
        <button class="sidebar-collapse" type="button" id="sidebarCollapse"><i class="fa-solid fa-angles-left"></i><span>Collapse menu</span></button>
    </aside>
    <div class="main-shell">
        <header class="topbar">
            <div class="topbar-left"><button class="icon-button mobile-menu" id="mobileMenu" aria-label="Open menu"><i class="fa-solid fa-bars"></i></button>
                <?php if($user['user_type']==='admin'): ?><form class="global-search" action="<?= url('search') ?>" method="get"><i class="fa-solid fa-magnifying-glass"></i><input name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Search leads, clients, projects…" aria-label="Global search"><kbd>⌘ K</kbd></form><?php else:?><div class="portal-context"><i class="fa-solid <?= $user['user_type']==='client'?'fa-building-shield':'fa-id-card-clip' ?>"></i><span><?= $user['user_type']==='client'?'Secure Client Workspace':'Staff Workspace' ?></span></div><?php endif; ?>
            </div>
            <div class="topbar-actions">
                <?php if ($user['user_type'] === 'admin'): ?><div class="quick-menu"><button class="button button-primary button-sm" type="button" data-dropdown="quick"><i class="fa-solid fa-plus"></i><span>Quick create</span></button><div class="dropdown" id="quick"><a href="<?= url('leads/create') ?>">New lead</a><a href="<?= url('clients/create') ?>">Client & portal login</a><a href="<?= url('projects/create') ?>">New project</a><a href="<?= url('tasks/create') ?>">Manual task</a><a href="<?= url('invoices/create') ?>">New invoice</a></div></div><?php endif; ?>
                <div class="notification-menu"><button class="icon-button" id="notificationButton" aria-label="Notifications"><i class="fa-regular fa-bell"></i><span class="notification-count" id="notificationCount" hidden>0</span></button><div class="dropdown notification-dropdown" id="notificationDropdown"><div class="dropdown-head"><strong>Notifications</strong><a href="<?= url('notifications') ?>">View all</a></div><div id="notificationItems" class="notification-items"><div class="loading-line"></div></div></div></div>
                <div class="profile-menu"><button class="profile-trigger" type="button" data-dropdown="profile"><span class="avatar"><?= e(mb_strtoupper(mb_substr($user['name'],0,1))) ?></span><span class="profile-copy"><strong><?= e($user['name']) ?></strong><small><?= e($user['role_names'] ?: ucfirst($user['user_type'])) ?></small></span><i class="fa-solid fa-chevron-down"></i></button><div class="dropdown profile-dropdown" id="profile"><div class="profile-dropdown-head"><strong><?= e($user['name']) ?></strong><span><?= e($user['email']) ?></span></div><?php if($user['user_type']!=='admin'):?><a href="<?= url($user['user_type']==='client'?'portal/client#profile':'portal/staff#profile') ?>"><i class="fa-solid fa-user-pen"></i> My profile</a><?php endif;?><a href="<?= url('account/password') ?>"><i class="fa-solid fa-key"></i> Change password</a><form action="<?= url('logout') ?>" method="post"><?= csrf_field() ?><button><i class="fa-solid fa-arrow-right-from-bracket"></i> Sign out</button></form></div></div>
            </div>
        </header>
        <main class="content-wrap">
            <div class="breadcrumb"><a href="<?= url('dashboard') ?>">Home</a><i class="fa-solid fa-chevron-right"></i><span><?= e($title ?? 'Dashboard') ?></span></div>
            <?= $content ?>
        </main>
    </div>
</div>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<div class="toast-stack" id="toastStack">
    <?php if ($successMessage): ?><div class="toast toast-success"><i class="fa-solid fa-circle-check"></i><span><?= e($successMessage) ?></span><button aria-label="Dismiss">×</button></div><?php endif; ?>
    <?php if ($errorMessage): ?><div class="toast toast-error"><i class="fa-solid fa-circle-exclamation"></i><span><?= e($errorMessage) ?></span><button aria-label="Dismiss">×</button></div><?php endif; ?>
</div>
<dialog class="confirm-dialog" id="confirmDialog"><div class="dialog-icon"><i class="fa-solid fa-triangle-exclamation"></i></div><h3>Confirm this action</h3><p id="confirmMessage">This change cannot be undone.</p><div class="dialog-actions"><button class="button button-secondary" id="confirmCancel">Cancel</button><button class="button button-danger" id="confirmAccept">Confirm</button></div></dialog>
<script>window.RM={baseUrl:<?= json_encode(base_url()) ?>,csrf:<?= json_encode(csrf_token()) ?>,sidebarScrollKey:<?= json_encode('rmSidebarScroll:'.$user['id']) ?>};</script>
<script src="<?= asset('vendor/bootstrap/bootstrap.bundle.min.js') ?>?v=5.3.8"></script>
<script src="<?= asset('js/app.js') ?>?v=1.0.3" defer></script>
</body>
</html>
