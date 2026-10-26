<?php

/** Shared TRIKE-Q administrator navigation and top bar. */

if (!function_exists('tqAdminEscape')) {
    function tqAdminEscape($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('tqAdminInitial')) {
    function tqAdminInitial($name)
    {
        $name = trim((string) $name) ?: 'TMU Administrator';
        return function_exists('mb_substr')
            ? mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8')
            : strtoupper(substr($name, 0, 1));
    }
}

if (!function_exists('tqAdminSidebar')) {
    function tqAdminSidebar($currentPage, $adminName = 'TMU Administrator')
    {
        $adminName = trim((string) $adminName) ?: 'TMU Administrator';
        $groups = [
            'Main' => [
                ['dashboard', 'dashboard.php', 'Dashboard'],
                ['drivers', 'drivers.php', 'Drivers'],
                ['register', 'register.php', 'Register'],
                ['complaints', 'complaints.php', 'Complaint / Violation'],
                ['toda_feedback', 'toda_feedback.php', 'TODA Feedback'],
                ['dues', 'dues.php', 'Dues'],
            ],
            'Management' => [
                ['renewals', 'renewals.php', 'Franchise Renewal'],
                ['toda', 'toda.php', 'TODA Organizations'],
                ['toda_fares', 'toda_fares.php', 'TODA Fares'],
                ['president_requests', 'president_requests.php', 'President Requests'],
                ['reports', 'reports.php', 'Reports'],
                ['settings', 'settings.php', 'Settings'],
            ],
        ];
        ?>
        <aside class="sidebar" aria-label="Administrator navigation">
            <a class="sidebar-brand" href="dashboard.php" aria-label="TRIKE-Q Dashboard">
                <img src="assets/default-logo.png" alt="Municipality of Estancia seal" width="72" height="72" style="display:block;object-fit:contain;margin:0 auto 10px">
                <span class="logo">TRIKE-Q</span>
                <span class="logo-subtitle">TMU ADMIN SYSTEM</span>
            </a>
            <nav class="sidebar-nav">
                <?php foreach ($groups as $label => $links): ?>
                    <div class="menu-title"><?= tqAdminEscape($label) ?></div>
                    <?php foreach ($links as [$key, $href, $text]): ?>
                        <a href="<?= tqAdminEscape($href) ?>" class="<?= $currentPage === $key ? 'active' : '' ?>"<?= $currentPage === $key ? ' aria-current="page"' : '' ?>>
                            <span class="nav-icon" aria-hidden="true"></span>
                            <span><?= tqAdminEscape($text) ?></span>
                        </a>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <div class="menu-title">Account</div>
                <a href="logout.php"><span class="nav-icon" aria-hidden="true"></span><span>Logout</span></a>
            </nav>
            <div class="sidebar-profile">
                <a href="settings.php" class="sidebar-profile-box">
                    <span class="sidebar-avatar"><?= tqAdminEscape(tqAdminInitial($adminName)) ?></span>
                    <span class="sidebar-profile-text">
                        <strong class="sidebar-admin-name"><?= tqAdminEscape($adminName) ?></strong>
                        <span class="sidebar-admin-role">TMU Administrator</span>
                    </span>
                </a>
            </div>
        </aside>
        <?php
    }
}

if (!function_exists('tqAdminTopbar')) {
    function tqAdminTopbar($title, $subtitle, $adminName = 'TMU Administrator')
    {
        $adminName = trim((string) $adminName) ?: 'TMU Administrator';
        ?>
        <header class="topbar">
            <div class="topbar-heading">
                <h1 class="page-title"><?= tqAdminEscape($title) ?></h1>
                <?php if (trim((string) $subtitle) !== ''): ?>
                    <p class="page-subtitle"><?= tqAdminEscape($subtitle) ?></p>
                <?php endif; ?>
            </div>
            <div class="admin-info">
                <div class="admin-copy">
                    <strong class="admin-name"><?= tqAdminEscape($adminName) ?></strong>
                    <span class="admin-role">TMU Administrator</span>
                </div>
                <span class="admin-avatar" aria-hidden="true"><?= tqAdminEscape(tqAdminInitial($adminName)) ?></span>
            </div>
        </header>
        <script src="admin-header.js" data-tq-header="1" defer></script>
        <?php
    }
}

