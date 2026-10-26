<?php
require_once __DIR__ . '/config/supabase.php';
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fareAssessmentLabel($value) { return $value === 'Reasonable' ? 'Fair fare' : $value; }
function query($v) { return http_build_query($v, '', '&', PHP_QUERY_RFC3986); }
function fareInput($source, $key, $default = '') {
    return isset($source[$key]) && is_string($source[$key]) ? trim($source[$key]) : $default;
}
function fareUuid($value) {
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
}
function fareRows($table, $filters) {
    $rows = [];
    for ($offset = 0; ; $offset += 500) {
        $filters['limit'] = 500; $filters['offset'] = $offset;
        $r = supabaseRequest($table, 'GET', null, query($filters));
        if (empty($r['success']) || !is_array($r['data'])) {
            return ['rows'=>[], 'error'=>$r['error'] ?? 'Unable to load records.'];
        }
        $page = $r['data']; $rows = array_merge($rows, $page);
        if (count($page) < 500) return ['rows'=>$rows, 'error'=>''];
    }
}
if (empty($_SESSION['access_token'])) { header('Location: login.php'); exit; }
$auth = supabaseRequest('auth/v1/user');
if (empty($auth['success']) && refreshSupabaseToken()) $auth = supabaseRequest('auth/v1/user');
$uid = $auth['data']['id'] ?? '';
if (!$uid || empty($auth['success'])) { http_response_code(401); exit('Please log in again.'); }
$ar = supabaseRequest('admin', 'GET', null, query(['select'=>'user_id,name,role,is_active,profile_picture_url','user_id'=>'eq.'.$uid]));
$admin = !empty($ar['success']) ? ($ar['data'][0] ?? null) : null;
if (!$admin || $admin['is_active'] !== true) { http_response_code(403); exit('Active TMU administrator access required.'); }
$role = 'tmu_admin';
if (empty($_SESSION['fares_csrf'])) $_SESSION['fares_csrf'] = bin2hex(random_bytes(32));
$error = ''; $success = $_SESSION['fares_notice'] ?? ''; unset($_SESSION['fares_notice']);
$todaId = fareInput($_GET, 'toda_id');
if ($todaId !== '' && !fareUuid($todaId)) { $todaId = ''; $error = 'Choose a valid TODA filter.'; }
$assessment = fareInput($_GET, 'assessment');
$allowed = ['Not assessed','Reasonable','High'];
if (!in_array($assessment, $allowed, true)) $assessment = '';
$search = substr(fareInput($_GET, 'q'), 0, 120);
$action = fareInput($_POST, 'action', 'review_fare');
$failedDestination = '';
$addOpen = false;
$orgResult = fareRows('toda_organizations', ['select'=>'id,name,status', 'order'=>'name.asc,id.asc']);
$organizations = $orgResult['rows']; $names = []; $orgStatus = [];
foreach ($organizations as $t) { $names[$t['id']]=$t['name']; $orgStatus[$t['id']]=$t['status']; }
if ($orgResult['error']) $error = $orgResult['error'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['fares_csrf'], fareInput($_POST, 'csrf'))) {
        $error = 'Your form expired. Refresh and try again.';
    } elseif (in_array($action, ['add_destination','edit_destination'], true)) {
        $id = fareInput($_POST, 'id');
        $destinationName = fareInput($_POST, 'destination_name');
        $activeValue = fareInput($_POST, 'destination_active');
        $expected = fareInput($_POST, 'destination_updated_at');
        $addOpen = $action === 'add_destination';
        $failedDestination = $action === 'edit_destination' ? $id : '';
        if ($destinationName === '' || preg_match('/^.{1,100}$/us', $destinationName) !== 1) $error = 'Enter a barangay or area name, up to 100 characters.';
        elseif (!in_array($activeValue, ['1','0'], true)) $error = 'Choose an assignment status.';
        elseif ($action === 'edit_destination' && (!fareUuid($id) || $expected === '')) $error = 'Refresh the destination list before editing.';
        else {
            $result = supabaseRequest('rpc/trikeq_admin_save_global_destination', 'POST', [
                'p_id'=>$action === 'add_destination' ? null : $id,
                'p_name'=>$destinationName,
                'p_active'=>$activeValue === '1',
                'p_expected_updated_at'=>$action === 'add_destination' ? null : $expected,
            ]);
            if (empty($result['success']) || !is_string($result['data']) || !fareUuid($result['data'])) {
                $error = $result['error'] ?? 'Destination save could not be confirmed. Refresh the list before retrying.';
            } else {
                $_SESSION['fares_notice'] = $action === 'add_destination' ? 'Destination added to the shared list for all TODAs.' : 'Destination updated.';
                $returnQuery = query(array_filter(['toda_id'=>$todaId, 'assessment'=>$assessment, 'q'=>$search], fn($v)=>$v !== ''));
                header('Location: toda_fares.php'.($returnQuery ? '?'.$returnQuery : '').'#destinations'); exit;
            }
        }
    } elseif ($action === 'review_fare') {
        $result = supabaseRequest('rpc/trikeq_review_toda_fare','POST',[
            'p_id'=>fareInput($_POST,'id'), 'p_assessment'=>fareInput($_POST,'assessment'),
            'p_remarks'=>fareInput($_POST,'remarks'), 'p_updated_at'=>fareInput($_POST,'updated_at'),
        ]);
        if (empty($result['success']) || $result['data'] !== true) $error = $result['error'] ?? 'Assessment could not be saved.';
        else {
            $_SESSION['fares_notice']='Assessment saved.';
            $returnQuery = query(array_filter(['toda_id'=>$todaId, 'assessment'=>$assessment, 'q'=>$search], fn($v)=>$v !== ''));
            header('Location: toda_fares.php'.($returnQuery ? '?'.$returnQuery : '').'#destinations'); exit;
        }
    } else $error = 'Unknown action. Refresh and try again.';
}
$destinationFilters = ['select'=>'id,name,active,updated_at,toda_id', 'order'=>'name.asc,id.asc'];
$destinationResult = fareRows('fare_destinations', $destinationFilters);
$destinations = $destinationResult['rows'];
$destinationError = $destinationResult['error'];
$filters=['select'=>'*','order'=>'destination.asc,id.asc'];
if ($todaId !== '') $filters['toda_id']='eq.'.$todaId;
if ($assessment !== '') $filters['assessment']='eq.'.$assessment;
$fareResult = fareRows('toda_fares', $filters);
$rows = $fareResult['rows'];
$fareLoadError = $fareResult['error'];
// One displayed row per destination, with TODA submissions inside that row.
function fareNameKey($name) {
    $name = trim((string)$name);
    return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
}
$groups = [];
foreach ($destinations as $destination) {
    if ($todaId !== '' && $destination['toda_id'] !== null && $destination['toda_id'] !== $todaId) continue;
    $groups['id:'.$destination['id']] = ['destination'=>$destination, 'fares'=>[]];
}
foreach ($rows as $fare) {
    $key = !empty($fare['destination_id']) ? 'id:'.$fare['destination_id'] : '';
    if ($key === '') {
        foreach ($groups as $candidateKey=>$candidate) {
            $d = $candidate['destination'];
            if (fareNameKey($d['name']) === fareNameKey($fare['destination']) && ($d['toda_id'] === null || $d['toda_id'] === $fare['toda_id'])) {
                $key = $candidateKey; break;
            }
        }
    }
    if ($key === '' || !isset($groups[$key])) {
        $key = 'legacy:'.$fare['toda_id'].':'.fareNameKey($fare['destination']);
        if (!isset($groups[$key])) $groups[$key] = ['destination'=>['id'=>null,'name'=>$fare['destination'],'active'=>null,'toda_id'=>$fare['toda_id']], 'fares'=>[]];
    }
    $groups[$key]['fares'][] = $fare;
}
$groups = array_filter($groups, function($group) use ($search, $assessment, $names) {
    if ($assessment !== '' && !$group['fares']) return false;
    if ($search === '') return true;
    $text = $group['destination']['name'];
    foreach ($group['fares'] as $fare) $text .= ' '.($names[$fare['toda_id']] ?? '');
    return stripos($text, $search) !== false;
});
uasort($groups, fn($a,$b)=>strnatcasecmp($a['destination']['name'],$b['destination']['name']));
$today = (new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');

?>
<!DOCTYPE html><html lang="en" data-admin-role="<?= e($role) ?>"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>TODA Fares | TRIKE-Q</title>

<link rel="stylesheet" href="admin-theme.css">






<style>
*{box-sizing:border-box}body{margin:0;background:#f8fafc;font-family:Arial,Helvetica,sans-serif;color:#334155}
h1{font-size:23px;color:#9a3412;margin:0}.subtitle{font-size:13px;margin:5px 0 0;color:#64748b;line-height:1.5}.content{padding:20px 24px}.mobile-menu{display:none}
.fare-panel{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;box-shadow:0 2px 8px #00000008}
.fare-panel h2{margin:0;color:#9a3412;font:600 18px/1.4 Arial,Helvetica,sans-serif}
.fare-section-head{display:flex;justify-content:space-between;align-items:start;gap:12px;margin-bottom:16px}.fare-count{color:#64748b;font-size:13px;white-space:nowrap}
.fare-sr-only{position:absolute!important;width:1px!important;height:1px!important;padding:0!important;margin:-1px!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important;border:0!important}
.fare-panel .fare-toolbar{display:flex;align-items:center;gap:10px;margin:0 0 14px;flex-wrap:nowrap}
.fare-panel .fare-filter{display:flex;align-items:stretch;flex:1 1 auto;min-width:0;margin:0;padding:0;border:0;border-radius:0;background:transparent;overflow:visible;gap:10px}
.fare-panel .fare-filter>div{flex:0 1 205px;min-width:120px;margin:0;padding:0;border-left:0}
.fare-panel .fare-filter>.fare-search{flex:1 1 320px;min-width:180px;border-left:0}
.fare-panel .fare-filter input,.fare-panel .fare-filter select{display:block;box-sizing:border-box;width:100%;height:40px;min-height:40px;max-width:none;min-width:0;margin:0!important;padding:0 11px!important;border:1px solid #cbd5e1!important;border-radius:8px!important;box-shadow:none!important;background:#fff;color:#334155;font:14px Arial,Helvetica,sans-serif}
.fare-panel .fare-filter input:focus,.fare-panel .fare-filter select:focus{outline:2px solid #f97316!important;outline-offset:1px!important}
.fare-panel .fare-add-toggle{flex:0 0 auto;height:42px;margin:0;padding:0 13px;border:1px solid #f97316;border-radius:8px;background:#f97316;color:#fff;font:600 14px Arial,Helvetica,sans-serif;white-space:nowrap;cursor:pointer}
.fare-panel .fare-add-toggle:hover{background:#ea580c}
.fare-panel .destination-add-form[hidden]{display:none!important}
.fare-panel .destination-add-form:not([hidden]){display:flex;gap:12px;flex-wrap:wrap;align-items:end;margin:0 0 14px;padding:12px;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc}
.destination-add-form>div{flex:1 1 170px}.destination-add-form>.destination-name-field{flex:2 1 240px}
.fare-panel label{display:block;font-size:13px;font-weight:600;margin:0 0 5px}
.fare-panel .destination-add-form input,.fare-panel .destination-add-form select{width:100%;height:40px;margin:0;padding:8px 10px;background:#fff;color:#334155;border:1px solid #cbd5e1;border-radius:7px;font:14px Arial,Helvetica,sans-serif}
.table-wrap{overflow-x:auto}.fare-panel .unified-fare-table{width:100%;min-width:1000px;border-collapse:collapse;table-layout:auto;font:14px/1.45 Arial,Helvetica,sans-serif}
.fare-panel .unified-fare-table th{padding:11px 10px;text-align:left;background:#fff7ed;color:#9a3412;border-bottom:1px solid #fed7aa;font-size:13px;white-space:nowrap}
.fare-panel .unified-fare-table td{padding:11px 10px;border-bottom:1px solid #e2e8f0;vertical-align:top;text-align:left}
.fare-panel .unified-fare-table th:last-child{width:280px;min-width:260px}.fare-panel small{display:block;font-size:12px;margin-top:4px;color:#64748b;overflow-wrap:anywhere}
.fare-area-name{font-size:14px;font-weight:500}.fare-muted{font-size:14px;color:#64748b}
.destination-badge{display:inline-block;border-radius:20px;margin-top:4px;padding:3px 8px;font:600 12px/1.3 Arial,Helvetica,sans-serif}.is-active{color:#166534;background:#dcfce7}.is-inactive{color:#991b1b;background:#fee2e2}.is-scheduled{color:#854d0e;background:#fef9c3}.fare-panel small.fare-destination-inactive{color:#991b1b}
.fare-panel .fare-row-actions{display:grid;grid-template-columns:62px 62px minmax(0,1fr);align-items:start;gap:8px;min-width:260px;max-width:300px}
.fare-panel .fare-review{display:contents}
.fare-panel .fare-action-toggle,.fare-panel .fare-disabled-review{display:inline-flex;flex-direction:column;align-items:center;justify-content:center;gap:5px;width:62px;min-height:60px;margin:0;padding:8px 6px;list-style:none;border:1px solid #fed7aa;border-radius:9px;background:#fff7ed;color:#c2410c;font:400 13px/17px Arial,Helvetica,sans-serif;white-space:nowrap;cursor:pointer}
.fare-panel .fare-action-toggle,.fare-panel .fare-disabled-review{grid-row:1}
.fare-panel .fare-review:first-child>.fare-action-toggle{grid-column:1}
.fare-panel .fare-review:nth-child(2)>.fare-action-toggle,.fare-panel .fare-disabled-review{grid-column:2}
.fare-panel .fare-row-actions svg{display:block;width:19px!important;height:19px!important;min-width:19px;flex:0 0 19px}
.fare-panel .fare-disabled-review:disabled{opacity:1;background:#f8fafc;border-color:#e2e8f0;color:#94a3b8;cursor:not-allowed}


.fare-panel .fare-action-toggle:hover,.fare-panel .fare-action-toggle[aria-expanded="true"]{background:#ffedd5;border-color:#f97316}

.fare-panel .fare-action-form{display:grid;grid-column:1/-1;grid-row:2;gap:9px;min-width:0;width:100%;margin:0;padding:11px;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc}
.fare-panel .fare-action-form[hidden]{display:none!important}
.fare-panel .fare-review input,.fare-panel .fare-review select,.fare-panel .fare-review textarea{display:block;width:100%;margin:5px 0 0;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;background:#fff;color:#334155;font:14px Arial,Helvetica,sans-serif}
.fare-panel .fare-review textarea{min-height:78px;resize:vertical}
.fare-panel .fare-save-button{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:9px 12px;border:1px solid #f97316;border-radius:7px;background:#f97316;color:#fff;font:600 13px Arial,Helvetica,sans-serif;cursor:pointer}.fare-save-button svg{width:15px;height:15px;flex:0 0 15px}
.fare-panel button:disabled{opacity:.55;cursor:not-allowed}.fare-panel summary:focus-visible,.fare-panel button:focus-visible,.fare-panel a:focus-visible{outline:2px solid #f97316;outline-offset:3px}
.fare-filter-status{margin:0 0 10px;color:#64748b;font:13px/1.4 Arial,Helvetica,sans-serif}.fare-filter-status.is-error{color:#b91c1c}#fareResults[aria-busy="true"]{opacity:.65}
.fare-footnote{margin:12px 0 0;color:#64748b;font:13px/1.5 Arial,Helvetica,sans-serif}
.alert{padding:12px;margin-bottom:12px;background:#fef2f2;color:#991b1b;font-size:14px;border-radius:8px}.alert.success{background:#f0fdf4;color:#166534}
@media(max-width:1050px){.fare-panel .fare-toolbar{flex-wrap:wrap}.fare-panel .fare-filter{flex-basis:100%}}
@media(max-width:650px){.content{padding:16px 12px}.fare-panel .fare-filter{flex-wrap:wrap}.fare-panel .fare-filter>.fare-search{flex:1 0 100%;border-bottom:0}.fare-panel .fare-filter>div:not(.fare-search){flex:1 1 38%;min-width:100px}.destination-add-form>.fare-save-button{width:100%}.fare-count{white-space:normal}}
.fare-reference-note{margin:0 0 14px;padding:10px 12px;border:1px solid #fed7aa;border-radius:8px;background:#fff7ed;color:#9a3412;font:13px/20px Arial,Helvetica,sans-serif}
</style>
<!-- Shared TMU header and navigation; refresh versions when the shared files change. -->
<script src="admin-header.js?v=<?= (int) @filemtime(__DIR__.'/admin-header.js') ?>" data-tq-header="1" defer></script>
<script src="admin-nav.js?v=<?= (int) @filemtime(__DIR__.'/admin-nav.js') ?>" defer></script>
</head><body>
<?php
$sidebarFile = null;
foreach ([__DIR__.'/admin-sidebar.php', __DIR__.'/includes/admin-sidebar.php'] as $candidate) {
    if (is_file($candidate)) { $sidebarFile=$candidate; break; }
}
if ($sidebarFile !== null) {
    ob_start();
    require $sidebarFile;
    $sidebarMarkup = ob_get_clean();
    // The shared navigation script standardizes the final sidebar after parsing.
    // Keep the included navigation intact while providing its logo immediately.
    $sidebarMarkup = preg_replace_callback('/(<aside\b[^>]*class=["\'][^"\']*\bsidebar\b[^"\']*["\'][^>]*>)/i', function ($match) {
        return $match[0] . '<img data-tq-system-logo src="system-logo.php?v=' . time() . '" alt="System logo" width="72" height="72" style="display:block;width:72px;height:72px;min-width:72px;max-width:72px;object-fit:contain;flex:0 0 72px;margin:0 auto 10px">';
    }, $sidebarMarkup, 1);
    echo $sidebarMarkup;
}
else {
?>
<aside class="sidebar" aria-label="TMU Admin navigation"><img data-tq-system-logo src="system-logo.php?v=<?= time() ?>" alt="System logo" width="72" height="72" style="display:block;width:72px;height:72px;min-width:72px;max-width:72px;object-fit:contain;flex:0 0 72px;margin:0 auto 10px"><div class="logo">TRIKE-Q</div><div class="logo-subtitle">TMU ADMIN SYSTEM</div><a href="dashboard.php">Dashboard</a><a class="active" href="toda_fares.php">TODA Fares</a><a href="logout.php">Logout</a></aside>
<?php } ?>
<main class="main"><header class="topbar"><button class="mobile-menu" type="button" aria-label="Toggle navigation" aria-expanded="false" onclick="const s=document.querySelector('.sidebar');s.classList.toggle('open');this.setAttribute('aria-expanded',s.classList.contains('open'))">Menu</button><div><h1>TODA Fares</h1><p class="subtitle">Manage shared destinations and monitor TODA fares</p></div><div class="admin-info" data-tq-legacy-account><div class="admin-copy"><strong class="admin-name"><?= e($admin['name'] ?: 'TMU Admin') ?></strong><span class="admin-role">TMU Administrator</span></div><span class="admin-avatar"><?php if (!empty($admin['profile_picture_url'])): ?><img src="<?= e($admin['profile_picture_url']) ?>" alt="Admin profile"><?php else: ?><?= e(substr($admin['name'] ?: 'T', 0, 1)) ?><?php endif; ?></span></div></header>
<div class="content">
<?php if($error): ?><div class="alert" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>
<section class="fare-panel" id="destinations"><div class="fare-section-head"><div><h2>Destinations and TODA Fares</h2><p class="subtitle">TMU destinations are shared. President-added destinations belong to their TODA only.</p></div><span class="fare-count"><?=count($groups)?> destinations</span></div>
<p class="fare-reference-note">Passenger temporary fares are automatic: a TODA’s own current fare takes priority. For a missing shared-destination price, the most recently updated current fare from another active TODA is shown and labeled with its source. No assignment or copied fare records are needed.</p>
<div class="fare-toolbar"><form method="GET" class="fare-filter" id="fareAutoFilter" role="search"><div class="fare-search"><label class="fare-sr-only" for="fare-search">Search</label><input id="fare-search" name="q" type="search" maxlength="120" value="<?=e($search)?>" placeholder="Search barangay / area or TODA…"></div><div><label class="fare-sr-only" for="toda">TODA — submitted fares</label><select id="toda" name="toda_id"><option value="">All TODAs</option><?php foreach($names as $id=>$name): ?><option value="<?=e($id)?>" <?=$id===$todaId?'selected':''?>><?=e($name)?></option><?php endforeach; ?></select></div><div><label class="fare-sr-only" for="assessment">Assessment</label><select id="assessment" name="assessment"><option value="">All assessments</option><?php foreach($allowed as $value): ?><option value="<?=e($value)?>" <?=$value===$assessment?'selected':''?>><?=e(fareAssessmentLabel($value))?></option><?php endforeach; ?></select></div></form>
<button type="button" class="fare-add-toggle" aria-controls="addDestinationPanel" aria-expanded="<?=$addOpen?'true':'false'?>" onclick="const panel=document.getElementById('addDestinationPanel');panel.hidden=!panel.hidden;this.setAttribute('aria-expanded',String(!panel.hidden));if(!panel.hidden)document.getElementById('destination-name').focus()">＋ Add Destination</button></div>
<form id="addDestinationPanel" method="POST" class="destination-add-form" <?=$addOpen?'':'hidden'?>>
<input type="hidden" name="csrf" value="<?=e($_SESSION['fares_csrf'])?>"><input type="hidden" name="action" value="add_destination">
<div class="destination-name-field"><label for="destination-name">Barangay / Area</label><input id="destination-name" name="destination_name" maxlength="100" required value="<?=e($addOpen?fareInput($_POST,'destination_name'):'')?>" placeholder="Example: Botongon"></div>
<div><label for="destination-active">Destination Status</label><select id="destination-active" name="destination_active"><option value="1">Active</option><option value="0" <?=$addOpen&&fareInput($_POST,'destination_active')==='0'?'selected':''?>>Inactive</option></select></div>
<button type="submit" class="fare-save-button" <?=$destinationError?'disabled':''?>>Save Destination</button>
</form><noscript><style>#addDestinationPanel[hidden]{display:flex!important}.fare-add-toggle{display:none!important}</style></noscript>
<?php if($destinationError): ?><div class="alert" role="alert">Unable to load shared destinations. <?=e($destinationError)?></div><?php endif; ?>
<?php if($fareLoadError): ?><div class="alert" role="alert">Unable to load submitted fares. <?=e($fareLoadError)?></div><?php endif; ?>
<p id="fareFilterStatus" class="fare-filter-status" role="status" aria-live="polite" hidden></p><div class="table-wrap" id="fareResults"><table class="unified-fare-table"><thead><tr><th>TODA</th><th>Barangay / Area</th><th>Regular</th><th>Discounted</th><th>Effective / Status</th><th>TMU Assessment</th><th>Action</th></tr></thead><tbody>
<?php foreach($groups as $group): $destination=$group['destination']; $failed=$destination['id']!==null && $failedDestination===$destination['id']; $displayFares=$group['fares']?:[null]; foreach($displayFares as $fare): ?>
<tr><td><?=e($fare ? ($names[$fare['toda_id']]??'Unknown TODA') : (!empty($destination['toda_id']) ? ($names[$destination['toda_id']]??'Unknown TODA') : 'All TODAs'))?></td>
<td><span class="fare-area-name"><?=e($destination['name'])?></span><?php if(!empty($destination['toda_id'])): ?><small>TODA-only destination</small><?php endif; ?><?php if($destination['id']===null): ?><small>Existing fare label</small><?php elseif($destination['active']===false): ?><small class="fare-destination-inactive">Destination inactive</small><?php endif; ?><?php if($fare && count($group['fares'])>1 && trim((string)$fare['origin'])!==''): ?><small>Recorded origin: <?=e($fare['origin'])?></small><?php endif; ?></td>
<td><?=$fare?'PHP '.e(number_format((float)$fare['regular_fare'],2)):($fareLoadError?'Unavailable':'Not submitted')?></td>
<td><?=$fare?($fare['discounted_fare']===null?'Not provided':'PHP '.e(number_format((float)$fare['discounted_fare'],2))):'—'?></td>
<td><?php if($fare): $fareStatus=!$fare['active']?'Inactive':($fare['effective_date']>$today?'Scheduled':'Active'); ?><?=e($fare['effective_date'])?><br><span class="destination-badge <?=$fareStatus==='Active'?'is-active':($fareStatus==='Inactive'?'is-inactive':'is-scheduled')?>"><?=e($fareStatus)?></span><?php else: ?>—<?php endif; ?></td>
<td><?=e(fareAssessmentLabel($fare['assessment']??'Not assessed'))?><?php if($fare && trim((string)$fare['remarks'])!==''): ?><small><?=e($fare['remarks'])?></small><?php endif; ?></td>
<td><div class="fare-row-actions">
<?php if($destination['id']!==null): ?>
<div class="fare-review"><button type="button" class="fare-action-toggle" aria-expanded="<?=$failed?'true':'false'?>" title="Edit shared destination"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m16 3 5 5-12 12-6 1 1-6Z"/><path d="m14 5 5 5"/></svg><span>Edit</span></button><form method="POST" class="fare-action-form" <?=$failed?'':'hidden'?>>
<input type="hidden" name="csrf" value="<?=e($_SESSION['fares_csrf'])?>"><input type="hidden" name="action" value="edit_destination"><input type="hidden" name="id" value="<?=e($destination['id'])?>"><input type="hidden" name="destination_updated_at" value="<?=e($failed?fareInput($_POST,'destination_updated_at'):$destination['updated_at'])?>">
<label>Barangay / Area<input name="destination_name" maxlength="100" required value="<?=e($failed?fareInput($_POST,'destination_name'):$destination['name'])?>"></label>
<label>Status<select name="destination_active"><option value="1" <?=($failed?fareInput($_POST,'destination_active')==='1':$destination['active'])?'selected':''?>>Active</option><option value="0" <?=($failed?fareInput($_POST,'destination_active')==='0':!$destination['active'])?'selected':''?>>Inactive</option></select></label>
<button type="submit" class="fare-save-button">Save Changes</button></form></div>
<?php endif; ?>
<?php if($fare): ?>
<div class="fare-review"><button type="button" class="fare-action-toggle" aria-expanded="false" title="Review submitted fare"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M9 4H5v17h14V4h-4"/><path d="M9 2h6v4H9z"/><path d="m8 13 3 3 5-6"/></svg><span>Review</span></button><form method="POST" class="fare-action-form" hidden>
<input type="hidden" name="csrf" value="<?=e($_SESSION['fares_csrf'])?>"><input type="hidden" name="id" value="<?=e($fare['id'])?>"><input type="hidden" name="updated_at" value="<?=e($fare['updated_at'])?>">
<label>Assessment <select name="assessment" required><option value="">Choose assessment</option><?php foreach(array_slice($allowed,1) as $value): ?><option value="<?=e($value)?>" <?=$fare['assessment']===$value?'selected':''?>><?=e(fareAssessmentLabel($value))?></option><?php endforeach; ?></select></label><label>Remarks <textarea name="remarks" maxlength="2000" rows="3"><?=e($fare['remarks'])?></textarea></label>
<button type="submit" class="fare-save-button"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg><span>Save Assessment</span></button>
</form></div>
<?php endif; ?>
<?php if(!$fare): ?><button type="button" class="fare-disabled-review" disabled aria-label="Review unavailable: no fare submitted" title="No fare submitted for the current TODA and assessment filters"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M9 4H5v17h14V4h-4"/><path d="M9 2h6v4H9z"/><path d="m8 13 3 3 5-6"/></svg><span>Review</span></button><?php endif; ?>
<?php if($destination['id']===null && !$fare): ?><span class="fare-muted">—</span><?php endif; ?>
</div></td></tr>
<?php endforeach; endforeach; ?>
<?php if(!$groups): ?><tr><td colspan="7"><?=$destinationError||$fareLoadError?'Records could not be fully loaded. Please try again.':'No destinations match your filters.'?></td></tr><?php endif; ?>
</tbody></table></div>
<p class="fare-footnote">TMU destinations apply to all TODAs. President-added destinations are TODA-specific. Fare amounts remain specific to each TODA. TODA and assessment filters narrow submitted fares. TMU assessments use the applicable fare schedule.</p>
</section></div></main>
<script>
(function () {
    'use strict';
    var form = document.getElementById('fareAutoFilter');
    var search = document.getElementById('fare-search');
    var results = document.getElementById('fareResults');
    var status = document.getElementById('fareFilterStatus');
    if (!form || !search || !results || !status) return;
    var timer, controller, generation = 0, composing = false;
    async function filterList() {
        clearTimeout(timer);
        var current = ++generation;
        if (controller) controller.abort();
        controller = new AbortController();
        var url = new URL('toda_fares.php', window.location.href);
        new FormData(form).forEach(function (value, key) {
            if (String(value).trim() !== '') url.searchParams.set(key, String(value));
        });
        status.hidden = false;
        status.classList.remove('is-error');
        status.textContent = 'Updating list…';
        results.setAttribute('aria-busy', 'true');
        try {
            var response = await fetch(url.href, {
                credentials: 'same-origin', cache: 'no-store', signal: controller.signal
            });
            if (!response.ok) throw new Error('Request failed');
            var documentResult = new DOMParser().parseFromString(await response.text(), 'text/html');
            if (current !== generation) return;
            var nextResults = documentResult.getElementById('fareResults');
            var nextCount = documentResult.querySelector('.fare-count');
            var loadError = documentResult.querySelector('.alert:not(.success)');
            if (!nextResults || loadError) throw new Error('Results unavailable');
            results.replaceChildren.apply(results, Array.from(nextResults.childNodes).map(function (node) {
                return document.importNode(node, true);
            }));
            var count = document.querySelector('.fare-count');
            if (count && nextCount) count.textContent = nextCount.textContent;
            // Keep destination editing/review forms pointed at the current filters.
            history.replaceState(null, '', url.pathname + url.search);
            status.textContent = '';
            status.hidden = true;
        } catch (error) {
            if (current !== generation || error.name === 'AbortError') return;
            status.hidden = false;
            status.classList.add('is-error');
            status.textContent = 'The list could not update. Your previous results remain visible. Try changing a filter again or refresh the page.';
        } finally {
            if (current === generation) results.removeAttribute('aria-busy');
        }
    }
    form.addEventListener('submit', function (event) {
        event.preventDefault(); filterList();
    });
    form.querySelectorAll('select').forEach(function (select) {
        select.addEventListener('change', filterList);
    });
    function scheduleSearch() {
        clearTimeout(timer);
        if (composing) return;
        // Invalidate an older request immediately while the user is typing.
        generation++;
        if (controller) controller.abort();
        timer = setTimeout(filterList, 350);
    }
    search.addEventListener('compositionstart', function () { composing = true; });
    search.addEventListener('compositionend', function () { composing = false; scheduleSearch(); });
    search.addEventListener('input', scheduleSearch);
    search.addEventListener('search', function () { if (!composing) filterList(); });

}());
</script>

<script>
(function () {
    'use strict';
    var results = document.getElementById('fareResults');
    if (!results) return;
    // Delegation survives replacement of the table during automatic filtering.
    results.addEventListener('click', function (event) {
        var button = event.target.closest('.fare-action-toggle');
        if (!button || !results.contains(button)) return;
        var actions = button.closest('.fare-row-actions');
        var panel = button.nextElementSibling;
        if (!actions || !panel || !panel.classList.contains('fare-action-form')) return;
        var opening = panel.hidden;
        actions.querySelectorAll('.fare-action-form').forEach(function (form) { form.hidden = true; });
        actions.querySelectorAll('.fare-action-toggle').forEach(function (toggle) {
            toggle.setAttribute('aria-expanded', 'false');
        });
        panel.hidden = !opening;
        button.setAttribute('aria-expanded', String(opening));
    });
}());
</script>
</body></html>
