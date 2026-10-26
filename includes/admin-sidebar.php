<?php
/**
 * Shared TMU navigation. Keep in sync with Pasted text(5).txt.
 * No database calls, authentication changes, or session mutations occur here.
 */
$tmuCurrentPage = basename((string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? ''));
$tmuActivePage = [
    'driver_edit.php' => 'drivers.php',
    'edit_driver.php' => 'drivers.php',
    'driver_qr.php' => 'drivers.php',
    'admin_settings.php' => 'settings.php',
][strtolower($tmuCurrentPage)] ?? strtolower($tmuCurrentPage);

// Add an Admin Accounts entry only when its actual PHP destination exists.
// This avoids creating a dead navigation link in installations without that page.
$tmuAdminAccountsFile = null;
foreach (['admin_accounts.php', 'admin-accounts.php', 'admin_account.php', 'accounts.php', 'admins.php'] as $tmuCandidate) {
    if (is_file(__DIR__ . DIRECTORY_SEPARATOR . $tmuCandidate) || is_file(dirname(__DIR__) . DIRECTORY_SEPARATOR . $tmuCandidate)) {
        $tmuAdminAccountsFile = $tmuCandidate;
        break;
    }
}

$tmuNavSections = [
    'Main' => [
        ['dashboard.php', '▣', 'Dashboard'],
        ['drivers.php', '♙', 'Driver'],
        ['register.php', '＋', 'Register'],
        ['complaints.php', '⚠', 'Complaint / Violation'],
        ['toda_feedback.php', '📊', 'TODA Feedback'],
        ['dues.php', '₱', 'Dues'],
    ],
    'Management' => [
        ['renewals.php', '↻', 'Franchise Renewal'],
        ['toda.php', '◆', 'TODA Organizations'],
        ['toda_fares.php', '₱', 'TODA Fares'],
        ['president_requests.php', '◆', 'President Requests'],
        ['reports.php', '▥', 'Reports'],
        ['settings.php', '⚙', 'Settings'],
    ],
    'Account' => [],
];
if ($tmuAdminAccountsFile !== null && ($admin['role'] ?? '') === 'super_admin') {
    $tmuNavSections['Account'][] = [$tmuAdminAccountsFile, '♙', 'Admin Accounts'];
}
$tmuNavSections['Account'][] = ['logout.php', '⇥', 'Logout'];
?>
<aside class="sidebar" aria-label="TMU Admin navigation">
    <img src="assets/default-logo.png" alt="Municipality of Estancia seal" width="72" height="72" style="display:block;width:72px;height:72px;object-fit:contain;margin:0 auto 10px">
    <div class="logo">TRIKE-Q</div>
    <div class="logo-subtitle">TMU ADMIN SYSTEM</div>
    <nav aria-label="Main administration">
        <?php foreach ($tmuNavSections as $tmuSectionTitle => $tmuLinks): ?>
            <div class="menu-title"><?= htmlspecialchars($tmuSectionTitle, ENT_QUOTES, 'UTF-8') ?></div>
            <?php foreach ($tmuLinks as [$tmuHref, $tmuIcon, $tmuLabel]): ?>
                <?php $tmuIsActive = $tmuActivePage === $tmuHref; ?>
                <a href="<?= htmlspecialchars($tmuHref, ENT_QUOTES, 'UTF-8') ?>"
                   <?= $tmuIsActive ? 'class="active" aria-current="page"' : '' ?>>
                    <span class="icon" aria-hidden="true"><?= htmlspecialchars($tmuIcon, ENT_QUOTES, 'UTF-8') ?></span>
                    <?= htmlspecialchars($tmuLabel, ENT_QUOTES, 'UTF-8') ?>
                </a>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </nav>
</aside>
