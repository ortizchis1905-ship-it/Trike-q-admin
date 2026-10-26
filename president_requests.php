<?php
// TRIKE-Q: TMU review of TODA President applications.
// Requires the toda_president_accounts table and policies supplied earlier.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config/supabase.php';
require_once __DIR__ . '/tmu-tools.php';
tqRequireAdmin();


function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function inputText($source, $key) {
    return isset($source[$key]) && is_string($source[$key])
        ? trim($source[$key]) : '';
}
function isUuid($value) {
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
}
function queryString($values) {
    return http_build_query($values, '', '&', PHP_QUERY_RFC3986);
}
function failPage($code, $message) {
    http_response_code($code);
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>President Requests</title>';
    echo '<body><h1>President Requests</h1><p>' . e($message) . '</p>';
    echo '<p><a href="login.php">Sign in</a> · <a href="dashboard.php">Dashboard</a></p></body></html>';
    exit;
}

$userId = inputText($_SESSION, 'user_id');
if (empty($_SESSION['access_token']) || !isUuid($userId)) {
    header('Location: login.php');
    exit;
}
$adminResult = supabaseRequest('admin', 'GET', null, queryString([
    'select' => 'user_id,name', 'user_id' => 'eq.' . $userId
]));
if (empty($adminResult['success'])) {
    failPage(503, 'Unable to verify admin access. Please try again or sign in again.');
}
$admin = $adminResult['data'][0] ?? null;
if (!is_array($admin) || ($admin['user_id'] ?? '') !== $userId) {
    failPage(403, 'This page is available to TMU administrators only.');
}
$adminName = trim((string)($admin['name'] ?? '')) ?: 'TMU Admin';
if (empty($_SESSION['president_requests_csrf'])) {
    $_SESSION['president_requests_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['president_requests_csrf'];
$errorMessage = '';
$successMessage = inputText($_SESSION, 'president_requests_flash');
unset($_SESSION['president_requests_flash']);
$statuses = ['Pending', 'Approved', 'Rejected', 'Suspended'];
$filter = inputText($_GET, 'status');
if (!in_array($filter, $statuses, true)) {
    $filter = 'All';
}
$page = max(1, (int)($_GET['page'] ?? 1));
$pageSize = 25;
$allowedTransitions = [
    'Pending' => ['Approved', 'Rejected'],
    'Approved' => ['Suspended'],
    'Rejected' => ['Approved'],
    'Suspended' => ['Approved']
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $targetId = inputText($_POST, 'user_id');
    $nextStatus = inputText($_POST, 'decision');
    $expectedStatus = inputText($_POST, 'expected_status');
    $expectedReview = inputText($_POST, 'expected_reviewed_at');
    $remarks = inputText($_POST, 'admin_remarks');
    if (!hash_equals($csrf, inputText($_POST, 'csrf_token'))) {
        $errorMessage = 'Your form has expired. Refresh the page and try again.';
    } elseif (!isUuid($targetId) || !isset($allowedTransitions[$expectedStatus]) ||
        !in_array($nextStatus, $allowedTransitions[$expectedStatus], true)) {
        $errorMessage = 'Invalid review action. Refresh the page and try again.';
    } elseif (strlen($remarks) > 2000) {
        $errorMessage = 'Please keep remarks within 2,000 bytes.';
    } elseif (in_array($nextStatus, ['Rejected', 'Suspended'], true) && $remarks === '') {
        $errorMessage = 'Enter a reason before rejecting or suspending an account.';
    } else {
        $currentResult = supabaseRequest('toda_president_accounts', 'GET', null,
            queryString(['select' => '*', 'user_id' => 'eq.' . $targetId]));
        $current = $currentResult['data'][0] ?? null;
        if (empty($currentResult['success']) || !is_array($current)) {
            $errorMessage = 'Unable to load this request. Refresh the page and try again.';
        } elseif (($current['status'] ?? '') !== $expectedStatus ||
            (string)($current['reviewed_at'] ?? '') !== $expectedReview) {
            $errorMessage = 'This request was changed by another review. Refresh before reviewing it again.';
        } else {
            if ($nextStatus === 'Approved') {
                $todaResult = supabaseRequest('toda_organizations', 'GET', null,
                    queryString(['select' => 'id,status', 'id' => 'eq.' . $current['toda_id']]));
                $toda = $todaResult['data'][0] ?? null;
                if (empty($todaResult['success']) || !is_array($toda) ||
                    !in_array($toda['status'] ?? false, [true, 1, '1', 'true'], true)) {
                    $errorMessage = 'Approval requires an active TODA organization.';
                }
            }
            if ($errorMessage === '') {
                $reviewedAt = gmdate('Y-m-d\TH:i:s\Z');
                $result = supabaseRequest('toda_president_accounts', 'PATCH', [
                    'status' => $nextStatus,
                    'admin_remarks' => $remarks,
                    'reviewed_by' => $userId,
                    'reviewed_at' => $reviewedAt
                ], queryString([
                    'user_id' => 'eq.' . $targetId,
                    'status' => 'eq.' . $expectedStatus,
                    'reviewed_at' => $expectedReview === '' ? 'is.null' : 'eq.' . $expectedReview
                ]));
                $saved = $result['data'][0] ?? null;
                if (empty($result['success'])) {
                    $errorMessage = 'Unable to save the decision. ' . ($result['error'] ?? 'Please try again.');
                } elseif (!is_array($saved) || ($saved['user_id'] ?? '') !== $targetId ||
                    ($saved['status'] ?? '') !== $nextStatus || ($saved['reviewed_by'] ?? '') !== $userId) {
                    $errorMessage = 'No matching request was updated. Refresh to check its latest status.';
                } else {
                    $_SESSION['president_requests_flash'] = 'Account status saved as ' . $nextStatus . '.';
                    header('Location: president_requests.php?' . queryString(['status' => $filter, 'page' => $page]));
                    exit;
                }
            }
        }
    }
}

$query = [
    'select' => '*', 'order' => 'created_at.desc,user_id.asc',
    'limit' => $pageSize + 1, 'offset' => ($page - 1) * $pageSize
];
if ($filter !== 'All') {
    $query['status'] = 'eq.' . $filter;
}
$result = supabaseRequest('toda_president_accounts', 'GET', null, queryString($query));
$loadFailed = empty($result['success']) || !is_array($result['data']);
$rows = $loadFailed ? [] : $result['data'];
$hasNext = count($rows) > $pageSize;
$rows = array_slice($rows, 0, $pageSize);
if ($loadFailed) {
    $errorMessage = 'Unable to load President Requests. Confirm that the table and policies were created. ' .
        ($result['error'] ?? '');
}
$todas = [];
$todaIds = [];
foreach ($rows as $row) {
    if (isUuid((string)($row['toda_id'] ?? ''))) {
        $todaIds[] = $row['toda_id'];
    }
}
if ($todaIds) {
    $todaResult = supabaseRequest('toda_organizations', 'GET', null, queryString([
        'select' => 'id,name,status', 'id' => 'in.(' . implode(',', array_unique($todaIds)) . ')'
    ]));
    if (!empty($todaResult['success']) && is_array($todaResult['data'])) {
        foreach ($todaResult['data'] as $toda) {
            $todas[$toda['id']] = $toda;
        }
    } elseif ($errorMessage === '') {
        $errorMessage = 'Organization details could not be loaded. Refresh before approving requests.';
    }
}
function displayDate($value) {
    if (!$value) { return 'Not reviewed'; }
    try {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('Asia/Manila'))->format('M d, Y · h:i A');
    } catch (Exception $exception) { return 'Date unavailable'; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>President Requests | TRIKE-Q TMU Admin</title>
<style>
*{box-sizing:border-box}body{margin:0;font-family:Arial,Helvetica,sans-serif;background:#f8fafc;color:#1f2937}
.sidebar{position:fixed;inset:0 auto 0 0;width:250px;background:#111827;color:white;padding:26px 16px;overflow:auto}
.logo{font-size:25px;font-weight:800;color:#f97316;padding:0 12px}.subtitle{font-size:11px;color:#94a3b8;padding:5px 12px 20px}
.menu-title{font-size:10px;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;margin:22px 12px 8px}
.sidebar a{display:block;padding:12px;border-radius:8px;color:#e2e8f0;text-decoration:none;font-size:14px;margin:3px 0}
.sidebar a:hover{background:#253047}.sidebar a.active{background:#f97316;color:white;font-weight:bold}
.main{margin-left:250px}.topbar{background:white;border-bottom:1px solid #e5e7eb;padding:22px 30px;display:flex;justify-content:space-between;gap:16px;align-items:center}
h1{font-size:23px;margin:0;color:#9a3412}.muted{font-size:12px;color:#64748b;line-height:1.6}.topbar p{margin:5px 0 0}
.content{padding:26px 30px;max-width:1500px}.toolbar,.card{background:white;border:1px solid #e2e8f0;border-radius:12px;padding:20px;margin-bottom:18px}
.toolbar{display:flex;gap:15px;align-items:end;justify-content:space-between;flex-wrap:wrap}.toolbar form{display:flex;align-items:end;gap:10px}
label{display:block;font-size:12px;font-weight:bold;margin-bottom:7px}select,textarea{font:inherit;border:1px solid #cbd5e1;border-radius:8px;padding:10px;background:white;color:#1f2937}
textarea{width:100%;min-height:70px;resize:vertical}select:focus,textarea:focus{outline:2px solid #fdba74}
button,.button{display:inline-block;border:0;border-radius:8px;padding:10px 14px;font-size:12px;font-weight:bold;cursor:pointer;text-decoration:none;background:#e2e8f0;color:#1f2937}
.primary{background:#f97316;color:white}.approve{background:#047857;color:white}.reject{background:#b91c1c;color:white}.suspend{background:#92400e;color:white}
.card-head{display:flex;align-items:start;justify-content:space-between;gap:15px}h2{font-size:18px;margin:0 0 5px}.badge{padding:6px 10px;border-radius:20px;font-size:12px;font-weight:bold;background:#f1f5f9}
.badge.Pending{background:#fff7ed;color:#9a3412}.badge.Approved{background:#ecfdf5;color:#047857}.badge.Rejected{background:#fef2f2;color:#b91c1c}.badge.Suspended{background:#fef3c7;color:#92400e}
.details{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px;margin:20px 0;font-size:13px;overflow-wrap:anywhere}.details strong{display:block;margin-bottom:6px;font-size:11px;color:#64748b}
.actions{display:flex;gap:9px;flex-wrap:wrap;margin-top:12px}.alert{padding:14px 17px;border-radius:9px;margin-bottom:18px;font-size:13px;overflow-wrap:anywhere}.error{background:#fef2f2;color:#991b1b}.success{background:#ecfdf5;color:#047857}
.remarks{white-space:pre-wrap;overflow-wrap:anywhere;font-size:13px}.pagination{display:flex;gap:15px;align-items:center;margin:20px 0}.empty{text-align:center;padding:45px 20px}
@media(max-width:850px){.sidebar{width:210px}.main{margin-left:210px}.details{grid-template-columns:1fr 1fr}.content{padding:20px}}
@media(max-width:600px){.sidebar{position:static;width:100%}.main{margin-left:0}.topbar{padding:20px;align-items:start}.details{grid-template-columns:1fr}.admin-label{display:none}.content{padding:14px}}
</style>
<link rel="stylesheet" href="admin-theme.css">
<style>
.president-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.president-list .card{padding:16px;margin:0;min-width:0}.president-list .details{gap:10px;margin:12px 0;grid-template-columns:repeat(2,minmax(0,1fr))}.president-list summary{background:#f97316;color:#fff;font-size:14px;border-radius:8px;padding:10px 14px}.president-list .review-form{margin-top:12px}.president-list textarea{width:100%;box-sizing:border-box}.president-list .remarks{font-size:14px}
@media(max-width:1100px){.president-list{grid-template-columns:1fr}}
</style>

<link rel="stylesheet" href="tmu-updates.css?v=20261019b">
<script src="tmu-updates.js?v=20261019" defer></script>
<style>
/* Explicit local sizing prevents the original image dimensions expanding the card. */
#tqPresidentRequests .card-head{display:flex;align-items:center;justify-content:space-between;gap:12px}
#tqPresidentRequests .tq-president-name{display:flex!important;flex-direction:row!important;align-items:center!important;gap:10px;flex:1 1 auto;min-width:0}
#tqPresidentRequests .tq-president-photo-link{display:inline-flex;align-items:center;justify-content:center;box-sizing:border-box;width:32px;height:32px;min-width:32px;max-width:32px;flex:0 0 32px;border-radius:50%;text-decoration:none;cursor:pointer}
#tqPresidentRequests .tq-president-name .tq-thumb,#tqPresidentRequests .tq-president-name .tq-initial{box-sizing:border-box;width:32px!important;height:32px!important;min-width:32px!important;max-width:32px!important;min-height:32px!important;max-height:32px!important;flex:0 0 32px;border:1px solid #fed7aa;border-radius:50%;object-fit:cover;font:600 14px Arial,Helvetica,sans-serif}
#tqPresidentRequests .tq-president-name .tq-thumb{display:block}
#tqPresidentRequests .tq-president-name .tq-initial{display:inline-flex;align-items:center;justify-content:center;background:#fff7ed;color:#9a3412}
#tqPresidentRequests .tq-president-name [hidden]{display:none!important}
#tqPresidentRequests .tq-president-photo-link:hover{outline:2px solid #fdba74;outline-offset:2px}
#tqPresidentRequests .tq-president-photo-link:focus-visible{outline:2px solid #f97316;outline-offset:3px}
#tqPresidentRequests .tq-president-copy{flex:1 1 auto;min-width:0;overflow-wrap:anywhere}
#tqPresidentRequests .tq-president-copy h2{margin:0 0 3px;font-size:17px;line-height:1.35}
#tqPresidentRequests .card-head>.badge{flex:0 0 auto}
</style>
<style>
#tqPresidentRequests .tq-president-table-wrap{width:100%;overflow-x:auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px}
#tqPresidentRequests .tq-president-table{width:100%;min-width:1080px;border-collapse:collapse;font:14px Arial,Helvetica,sans-serif;color:#334155}
#tqPresidentRequests .tq-president-table th{padding:12px;text-align:left;background:#f8fafc;color:#9a3412;font-size:13px;font-weight:600;white-space:nowrap;border-bottom:1px solid #e2e8f0}
#tqPresidentRequests .tq-president-table td{padding:11px 12px;text-align:left;vertical-align:middle;border-bottom:1px solid #eef2f6;line-height:1.5}
#tqPresidentRequests .tq-president-table tbody tr:last-child td{border-bottom:0}
#tqPresidentRequests .tq-president-table tbody tr:hover{background:#fffbf7}
#tqPresidentRequests .tq-president-table .tq-president-name{min-width:180px}
#tqPresidentRequests .tq-president-table .tq-president-copy h2{font-size:15px;font-weight:600;line-height:1.4;margin:0}
#tqPresidentRequests .tq-president-table .badge{display:inline-block;white-space:nowrap;font-size:12px}
#tqPresidentRequests .tq-president-table small{display:block}
#tqPresidentRequests .tq-president-review-cell{min-width:145px}
#tqPresidentRequests .tq-president-review>summary,#tqPresidentRequests .tq-president-remarks>summary{display:inline-flex;align-items:center;justify-content:center;list-style:none;white-space:nowrap;border:1px solid #fdba74;border-radius:7px;padding:7px 10px;background:#fff7ed;color:#c2410c;font:600 12px Arial,Helvetica,sans-serif;cursor:pointer}
#tqPresidentRequests .tq-president-review>summary::-webkit-details-marker,#tqPresidentRequests .tq-president-remarks>summary::-webkit-details-marker{display:none}
#tqPresidentRequests .tq-president-review>summary:hover,#tqPresidentRequests .tq-president-remarks>summary:hover{background:#ffedd5}
#tqPresidentRequests .tq-president-review[open]{min-width:250px}
#tqPresidentRequests .tq-president-review .review-form{margin-top:10px;padding:12px;border:1px solid #e2e8f0;border-radius:8px;background:#fff}
#tqPresidentRequests .tq-president-review textarea{font-size:14px}
#tqPresidentRequests .tq-president-remarks .remarks{min-width:160px;max-width:240px;white-space:pre-wrap;overflow-wrap:anywhere;font-size:14px}
#tqPresidentRequests .tq-president-table .tq-president-empty{text-align:center;padding:30px;color:#64748b}
</style>
<!-- Shared TMU header and navigation; refresh versions when the shared files change. -->
<script src="admin-header.js?v=<?= (int) @filemtime(__DIR__.'/admin-header.js') ?>" data-tq-header="1" defer></script>
<script src="admin-nav.js?v=<?= (int) @filemtime(__DIR__.'/admin-nav.js') ?>" defer></script>
</head>
<body>
<aside class="sidebar">

    <div class="logo">TRIKE-Q</div>
    <div class="logo-subtitle">TMU ADMIN SYSTEM</div>

    <div class="menu-title">Main</div>
        <a href="dashboard.php">
            <span class="icon">▣</span>
            Dashboard
        </a>

        <a href="drivers.php">
            <span class="icon">♙</span>
            Driver
        </a>

        <a href="register.php">
            <span class="icon">＋</span>
            Register
        </a>

        <a href="complaints.php">
            <span class="icon">⚠</span>
            Complaint / Violation
        </a>

        <a href="toda_feedback.php">
            <span class="icon">▤</span>
            TODA Feedback
        </a>

        <a href="dues.php">
            <span class="icon">₱</span>
            Dues
        </a>

    <div class="menu-title">Management</div>
        <a href="renewals.php">
            <span class="icon">↻</span>
            Franchise Renewal
        </a>

        <a href="toda.php">
            <span class="icon">◆</span>
            TODA Organizations
        </a>

        <a href="president_requests.php" class="active" aria-current="page">
            <span class="icon">◇</span>
            President Requests
        </a>

        <a href="reports.php">
            <span class="icon">▥</span>
            Reports
        </a>

        <a href="settings.php">
            <span class="icon">⚙</span>
            Settings
        </a>

    <div class="menu-title">Account</div>
        <a href="logout.php">
            <span class="icon">⇥</span>
            Logout
        </a>

</aside>
<main class="main">
<header class="topbar"><div><h1>President Requests</h1><p class="muted">Review TODA President registrations and account status.</p></div>
<div class="admin-label"><strong><?= e($adminName) ?></strong><div class="muted">TMU Administrator</div></div></header>
<section class="content" id="tqPresidentRequests">
<?php if ($successMessage !== ''): ?><div class="alert success" role="status"><?= e($successMessage) ?></div><?php endif; ?>
<?php if ($errorMessage !== ''): ?><div class="alert error" role="alert"><?= e($errorMessage) ?></div><?php endif; ?>
<div class="toolbar"><form method="GET"><div><label for="status">Account status</label><select name="status" id="status">
<?php foreach (array_merge(['All'], $statuses) as $option): ?>
<option value="<?= e($option) ?>" <?= $filter === $option ? 'selected' : '' ?>><?= e($option) ?></option>
<?php endforeach; ?></select></div><button class="primary" type="submit">Filter</button></form>
<a class="button" href="president_requests.php?<?= e(queryString(['status' => $filter, 'page' => $page])) ?>">Refresh</a></div>
<div class="tq-president-table-wrap"><table class="tq-president-table" aria-label="TODA President requests"><thead><tr><th scope="col">President</th><th scope="col">TODA</th><th scope="col">Contact Number</th><th scope="col">Status</th><th scope="col">Registered</th><th scope="col">Last Review</th><th scope="col">Remarks</th><th scope="col">Action</th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="8" class="tq-president-empty"><?= $loadFailed ? 'Requests could not be loaded. See the error above.' : 'No president requests to display.' ?></td></tr><?php endif; ?>
<?php foreach ($rows as $row):
    $status = (string)$row['status'];
    $toda = $todas[$row['toda_id']] ?? null;
    $activeToda = $toda && in_array($toda['status'], [true, 1, '1', 'true'], true);
    $actions = $allowedTransitions[$status] ?? [];
?>
<tr><td><div class="tq-president-name">
<?php if (!empty($row['profile_object_path'])): ?><a class="tq-president-photo-link" aria-label="View president profile picture" data-tq-photo data-photo-title="<?= e($row['full_name']) ?>" href="admin-photo.php?kind=president&amp;id=<?= e($row['user_id']) ?>" target="_blank" rel="noopener"><img class="tq-thumb" width="32" height="32" src="admin-photo.php?kind=president&amp;id=<?= e($row['user_id']) ?>" alt="<?= e($row['full_name']) ?> profile picture" loading="lazy" onerror="this.hidden=true;this.nextElementSibling.hidden=false"><span class="tq-initial" hidden><?= e(substr($row['full_name'],0,1)) ?></span></a><?php else: ?><span class="tq-initial"><?= e(substr($row['full_name'],0,1)) ?></span><?php endif; ?>
<div class="tq-president-copy"><h2><?= e($row['full_name']) ?></h2></div></div></td>
<td><?= e($toda['name'] ?? 'Organization unavailable') ?><?php if ($toda && !$activeToda): ?><small class="muted">Inactive organization</small><?php endif; ?></td>
<td><?= e($row['phone_number'] ?: '—') ?></td>
<td><span class="badge <?= e(in_array($status, $statuses, true) ? $status : '') ?>"><?= e($status) ?></span></td>
<td><?= e(displayDate($row['created_at'])) ?></td>
<td><?= e(displayDate($row['reviewed_at'])) ?></td>
<td><?php if ($row['admin_remarks'] !== ''): ?><details class="tq-president-remarks"><summary>View remarks</summary><p class="remarks"><?= e($row['admin_remarks']) ?></p></details><?php else: ?>—<?php endif; ?></td>
<td class="tq-president-review-cell">
<?php if ($actions): ?><details class="tq-president-review"><summary class="tq-review-toggle">Review / Remarks</summary><form method="POST" class="review-form">
<input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
<input type="hidden" name="user_id" value="<?= e($row['user_id']) ?>">
<input type="hidden" name="expected_status" value="<?= e($status) ?>">
<input type="hidden" name="expected_reviewed_at" value="<?= e($row['reviewed_at'] ?? '') ?>">
<label for="remarks-<?= e($row['user_id']) ?>">Review remarks (required for rejection or suspension)</label>
<textarea name="admin_remarks" id="remarks-<?= e($row['user_id']) ?>" maxlength="2000" placeholder="Record the reason for your decision."></textarea>
<div class="actions">
<?php foreach ($actions as $decision):
    if ($decision === 'Approved' && !$activeToda) { continue; }
    $label = $decision === 'Approved' ? 'Approve' : ($decision === 'Rejected' ? 'Reject' : 'Suspend');
?>
<button type="submit" name="decision" value="<?= e($decision) ?>" class="<?= e(strtolower($label)) ?>"><?= e($label) ?></button>
<?php endforeach; ?></div>
<?php if (!$activeToda): ?><p class="muted">Approval is available when the TODA organization is active and its details can be loaded.</p><?php endif; ?>
</form></details><?php else: ?>—<?php endif; ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<div class="pagination">
<?php if ($page > 1): ?><a class="button" href="?<?= e(queryString(['status' => $filter, 'page' => $page - 1])) ?>">Previous</a><?php endif; ?>
<span class="muted">Page <?= e($page) ?></span>
<?php if ($hasNext): ?><a class="button" href="?<?= e(queryString(['status' => $filter, 'page' => $page + 1])) ?>">Next</a><?php endif; ?>
</div>
</section></main>
<script>
document.querySelectorAll('.review-form').forEach(function (form) {
    form.addEventListener('submit', function (event) {
        var button = event.submitter;
        if (!button) { event.preventDefault(); return; }
        var remarks = form.querySelector('textarea');
        if ((button.value === 'Rejected' || button.value === 'Suspended') && !remarks.value.trim()) {
            event.preventDefault();
            alert('Enter a reason for this decision.');
            remarks.focus();
            return;
        }
        if (!confirm('Save this account status as ' + button.value + '?')) {
            event.preventDefault();
        }
    });
});
</script>
</body></html>
