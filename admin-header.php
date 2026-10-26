<?php
require_once __DIR__.'/config/supabase.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

function tqHeaderReply($status, $body) {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function tqHeaderInput($values, $key, $default = '') {
    return isset($values[$key]) && is_string($values[$key]) ? $values[$key] : $default;
}
function tqHeaderUuid($value) {
    return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$view = tqHeaderInput($_GET, 'view', 'profile');
if (!in_array($method, ['GET','POST'], true)) tqHeaderReply(405, ['error'=>'Use GET or POST.']);
if (!in_array($view, ['profile','notifications'], true)) tqHeaderReply(400, ['error'=>'Invalid header request.']);
if ($method === 'POST' && $view !== 'notifications') tqHeaderReply(405, ['error'=>'Use GET to load the profile.']);
$noticeToken = isset($_SESSION['access_token']) && is_string($_SESSION['access_token']) ? trim($_SESSION['access_token']) : '';
if ($noticeToken === '') tqHeaderReply(401, ['error'=>'Please sign in again.']);
if (empty($_SESSION['tmu_notification_csrf']) || !is_string($_SESSION['tmu_notification_csrf'])) {
    $_SESSION['tmu_notification_csrf'] = bin2hex(random_bytes(32));
}
$noticeCsrf = $_SESSION['tmu_notification_csrf'];
// Release the session before normal network requests so page navigation is not blocked.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$noticeBase = rtrim($SUPABASE_URL, '/');
function tqHeaderRequest($path, $method = 'GET', $data = null) {
    global $noticeBase, $noticeToken;
    if (strtoupper($method) === 'POST' && $data === null) $data = new stdClass();
    return trikeqSupabaseHttp($noticeBase.'/'.$path, $method, $data, $noticeToken);
}
$auth = tqHeaderRequest('auth/v1/user');
if (empty($auth['success']) && (int)($auth['status'] ?? 0) === 401) {
    // Serialize token refreshes. Another request may already have rotated the token.
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $currentToken = isset($_SESSION['access_token']) && is_string($_SESSION['access_token']) ? trim($_SESSION['access_token']) : '';
    if ($currentToken !== '' && $currentToken !== $noticeToken) {
        $noticeToken = $currentToken;
    } elseif ($currentToken !== '' && function_exists('refreshSupabaseToken') && refreshSupabaseToken()) {
        $noticeToken = $_SESSION['access_token'];
    } else {
        session_write_close();
        tqHeaderReply(401, ['error'=>'Your session expired. Please sign in again.']);
    }
    session_write_close();
    $auth = tqHeaderRequest('auth/v1/user');
}
$uid = $auth['data']['id'] ?? '';
if (empty($auth['success']) || !tqHeaderUuid($uid)) {
    $expired = (int)($auth['status'] ?? 0) === 401;
    tqHeaderReply($expired ? 401 : 503, ['error'=>$expired ? 'Please sign in again.' : 'Account verification is temporarily unavailable. Try again.']);
}
$membership = tqHeaderRequest('rest/v1/admin?'.http_build_query([
    'select'=>'name,role,is_active,profile_picture_url','user_id'=>'eq.'.$uid,'limit'=>1,
], '', '&', PHP_QUERY_RFC3986));
if (empty($membership['success'])) tqHeaderReply(503, ['error'=>'Administrator verification is temporarily unavailable. Try again.']);
$admin = $membership['data'][0] ?? null;
if (!$admin || ($admin['is_active'] ?? false) !== true) tqHeaderReply(403, ['error'=>'Active TMU administrator access required.']);

if ($method === 'GET' && $view === 'profile') {
    tqHeaderReply(200, [
        'name'=>trim((string)($admin['name'] ?? '')) ?: 'TMU Administrator',
        'role'=>($admin['role'] ?? '') === 'super_admin' ? 'Super Admin' : 'TMU Administrator',
        'photo'=>$admin['profile_picture_url'] ?? null,'user_id'=>$uid,'csrf'=>$noticeCsrf,
    ]);
}
if ($method === 'POST') {
    $sentCsrf = tqHeaderInput($_POST, 'csrf');
    if ($sentCsrf === '' || !hash_equals($noticeCsrf, $sentCsrf)) tqHeaderReply(403, ['error'=>'The notification form expired. Refresh the page and try again.']);
    if (tqHeaderInput($_POST, 'user_id') !== $uid) tqHeaderReply(409, ['error'=>'Your account changed. Refresh the page before updating notifications.']);
    $action = tqHeaderInput($_POST, 'action');
    if ($action === 'mark_read') {
        $id = tqHeaderInput($_POST, 'id');
        if (!tqHeaderUuid($id)) tqHeaderReply(400, ['error'=>'Invalid notification.']);
        $result = tqHeaderRequest('rest/v1/rpc/trikeq_mark_tmu_notification', 'POST', ['p_id'=>$id]);
        if (empty($result['success'])) tqHeaderReply(503, ['error'=>'The read status could not be confirmed. Refresh notifications before retrying.']);
        if (($result['data'] ?? null) !== true) tqHeaderReply(404, ['error'=>'This notification is unavailable for your account.']);
        tqHeaderReply(200, ['ok'=>true]);
    }
    if ($action === 'mark_all_read') {
        $result = tqHeaderRequest('rest/v1/rpc/trikeq_mark_all_tmu_notifications', 'POST');
        if (empty($result['success']) || !is_int($result['data'] ?? null) || $result['data'] < 0) {
            tqHeaderReply(503, ['error'=>'The read status could not be confirmed. Refresh notifications before retrying.']);
        }
        tqHeaderReply(200, ['ok'=>true,'marked'=>$result['data']]);
    }
    tqHeaderReply(400, ['error'=>'Invalid notification action.']);
}
$filter = tqHeaderInput($_GET, 'filter', 'all');
if (!in_array($filter, ['all','unread'], true)) tqHeaderReply(400, ['error'=>'Invalid notification filter.']);
$sync = tqHeaderRequest('rest/v1/rpc/trikeq_sync_tmu_notifications', 'POST');
if (empty($sync['success'])) tqHeaderReply(503, ['error'=>'Notifications could not be loaded. Check that activity_notifications.sql is installed, then try again.']);
$filters = [
    'select'=>'id,toda_id,title,body,target,target_id,created_at,read_at',
    'recipient_id'=>'eq.'.$uid,'order'=>'created_at.desc,id.desc','limit'=>51,
];
if ($filter === 'unread') $filters['read_at'] = 'is.null';
$list = tqHeaderRequest('rest/v1/tmu_notifications?'.http_build_query($filters, '', '&', PHP_QUERY_RFC3986));
if (empty($list['success']) || !is_array($list['data'])) tqHeaderReply(503, ['error'=>'Notifications could not be loaded. Try again.']);
// Count all unread rows, not just the displayed page. Advance by actual returned rows
// to respect a project-specific REST row limit. Never present a partial count as exact.
$unread = 0; $offset = 0; $finished = false;
for ($page = 0; $page < 100; $page++) {
    $count = tqHeaderRequest('rest/v1/tmu_notifications?'.http_build_query([
        'select'=>'id','recipient_id'=>'eq.'.$uid,'read_at'=>'is.null',
        'order'=>'id.asc','limit'=>500,'offset'=>$offset,
    ], '', '&', PHP_QUERY_RFC3986));
    if (empty($count['success']) || !is_array($count['data'])) tqHeaderReply(503, ['error'=>'The unread count could not be loaded. Try again.']);
    $size = count($count['data']);
    if ($size === 0) { $finished = true; break; }
    $unread += $size; $offset += $size;
}
if (!$finished) tqHeaderReply(503, ['error'=>'There are too many unread notifications to count in this request. Try again later.']);
// Enrich only these inbox items, using the current TMU JWT and existing RLS.
// Private complainant contact details are never copied into the bell.
$detailsUnavailable = false;
$records = ['complaints'=>[], 'president_requests'=>[]];
$recordIds = ['complaints'=>[], 'president_requests'=>[]];
$organizationIds = [];
foreach (array_slice($list['data'],0,50) as $row) {
    $target = $row['target'] ?? '';
    if (isset($recordIds[$target]) && tqHeaderUuid($row['target_id'] ?? null)) $recordIds[$target][$row['target_id']] = $row['target_id'];
    if (tqHeaderUuid($row['toda_id'] ?? null)) $organizationIds[$row['toda_id']] = $row['toda_id'];
}
foreach (['complaints'=>['complaints','id','id,driver_name,complaint_type,status,toda_id,drivers(name,tricycle_number)'],
          'president_requests'=>['toda_president_accounts','user_id','user_id,full_name,status,toda_id']] as $target=>$source) {
    if (!$recordIds[$target]) continue;
    [$table,$key,$select] = $source;
    $detailResult = tqHeaderRequest('rest/v1/'.$table.'?'.http_build_query([
        'select'=>$select,$key=>'in.('.implode(',',array_values($recordIds[$target])).')','limit'=>50,
    ],'', '&',PHP_QUERY_RFC3986));
    if (empty($detailResult['success']) || !is_array($detailResult['data'])) { $detailsUnavailable=true; continue; }
    foreach ($detailResult['data'] as $detail) {
        if (!tqHeaderUuid($detail[$key] ?? null)) continue;
        $records[$target][$detail[$key]]=$detail;
        if (tqHeaderUuid($detail['toda_id'] ?? null)) $organizationIds[$detail['toda_id']]=$detail['toda_id'];
    }
}
$organizationNames=[];
if ($organizationIds) {
    $organizationResult=tqHeaderRequest('rest/v1/toda_organizations?'.http_build_query([
        'select'=>'id,name','id'=>'in.('.implode(',',array_values($organizationIds)).')','limit'=>100,
    ],'', '&',PHP_QUERY_RFC3986));
    if (empty($organizationResult['success']) || !is_array($organizationResult['data'])) $detailsUnavailable=true;
    else foreach($organizationResult['data'] as $organization) if(tqHeaderUuid($organization['id'] ?? null)) $organizationNames[$organization['id']] = (string)($organization['name'] ?? '');
}
$links = ['complaints'=>'complaints.php','president_requests'=>'president_requests.php'];
$items = [];
foreach (array_slice($list['data'], 0, 50) as $row) {
    if (!isset($links[$row['target'] ?? '']) || !tqHeaderUuid($row['id'] ?? null)) continue;
    $detail = $records[$row['target']][$row['target_id'] ?? ''] ?? null;
    $detailLines = []; $recordStatus = '';
    if ($detail) {
        $recordStatus = (string)($detail['status'] ?? '');
        if ($row['target'] === 'complaints') {
            $driverName = (string)($detail['drivers']['name'] ?? $detail['driver_name'] ?? 'Driver');
            $tricycle = (string)($detail['drivers']['tricycle_number'] ?? '');
            $detailLines[] = ['label'=>'Driver','value'=>$driverName.($tricycle !== '' ? ' · Tricycle '.$tricycle : '')];
            if (!empty($detail['complaint_type'])) $detailLines[] = ['label'=>'Complaint','value'=>(string)$detail['complaint_type']];
        } else {
            $detailLines[] = ['label'=>'President','value'=>(string)($detail['full_name'] ?? 'TODA President')];
        }
        $organizationId = $detail['toda_id'] ?? ($row['toda_id'] ?? '');
        if (!empty($organizationNames[$organizationId])) $detailLines[] = ['label'=>'TODA','value'=>$organizationNames[$organizationId]];
    }
    $items[] = [
        'id'=>$row['id'],'title'=>(string)($row['title'] ?? 'Notification'),
        'body'=>(string)($row['body'] ?? ''),'href'=>$links[$row['target']],
        'date'=>(string)($row['created_at'] ?? ''),'read_at'=>$row['read_at'] ?? null,
        'details'=>$detailLines,'record_status'=>$recordStatus,
    ];
}
tqHeaderReply(200, [
    'items'=>$items,'unread'=>$unread,'has_more'=>count($list['data'])>50,
    'filter'=>$filter,'user_id'=>$uid,'csrf'=>$noticeCsrf,'unavailable'=>false,
    'details_unavailable'=>$detailsUnavailable,
]);
