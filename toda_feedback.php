<?php
// TODA President feedback and boarding history for TRIKE-Q.
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config/supabase.php';

function tqFeedbackEscape($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function tqFeedbackQuery($v) { return http_build_query($v, '', '&', PHP_QUERY_RFC3986); }
function tqFeedbackDatePH($v) {
    if (!$v) return '—';
    try { return (new DateTimeImmutable($v,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'))->format('M d, Y h:i A'); }
    catch (Exception $x) { return '—'; }
}

if (empty($_SESSION['access_token'])) { header('Location: login.php'); exit; }
$userId = trim((string)($_SESSION['user_id'] ?? ''));
$adminResult = supabaseRequest('admin', 'GET', null, tqFeedbackQuery(['select'=>'user_id,name','user_id'=>'eq.'.$userId]));
$presidentResult = supabaseRequest('toda_president_accounts', 'GET', null, tqFeedbackQuery(['select'=>'user_id,toda_id,status','user_id'=>'eq.'.$userId,'status'=>'eq.Approved']));
$admin = $adminResult['data'][0] ?? null;
$president = $presidentResult['data'][0] ?? null;
if (!is_array($admin) && !is_array($president)) {
    http_response_code(403); exit('This page is available to TMU administrators and approved TODA Presidents only.');
}

$isAdmin = is_array($admin);
$feedbackInReports = defined('TRIKEQ_FEEDBACK_IN_REPORTS') && TRIKEQ_FEEDBACK_IN_REPORTS;
if($isAdmin && !$feedbackInReports) {
    $destination=['report'=>'feedback'];
    foreach(['toda_id','range','date_from','date_to','export'] as $key) if(isset($_GET[$key]) && is_string($_GET[$key]) && $_GET[$key]!=='') $destination[$key]=$_GET[$key];
    header('Location: reports.php?'.tqFeedbackQuery($destination),true,($_SERVER['REQUEST_METHOD']??'GET')==='POST'?307:302);
    exit;
}

$name = $isAdmin ? (($admin['name'] ?? '') ?: 'TMU Admin') : 'TODA President';
$todaId = $isAdmin ? trim((string)($_GET['toda_id'] ?? '')) : trim((string)($president['toda_id'] ?? ''));
$todaRows = [];
if ($isAdmin) {
    $tr = supabaseRequest('toda_organizations','GET',null,tqFeedbackQuery(['select'=>'id,name','status'=>'eq.true','order'=>'name.asc']));
    if (!empty($tr['success']) && is_array($tr['data'])) $todaRows = $tr['data'];
}
$drivers = $ratings = $complaints = $boardings = [];
$error = '';
$success = '';
$dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date_from'] ?? '')) ? (string)$_GET['date_from'] : '';
$dateTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date_to'] ?? '')) ? (string)$_GET['date_to'] : '';
$preset = (string)($_GET['range'] ?? '');
if ($preset === 'today') { $dateFrom = $dateTo = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d'); }
if ($preset === 'week') { $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')); $dateFrom = $now->modify('monday this week')->format('Y-m-d'); $dateTo = $now->format('Y-m-d'); }
if ($preset === 'month') { $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')); $dateFrom = $now->format('Y-m-01'); $dateTo = $now->format('Y-m-d'); }
$allowedComplaintStatuses = ['Pending', 'Resolved', 'Dismissed'];
$csrfKey = 'toda_feedback_csrf';
if (empty($_SESSION[$csrfKey])) $_SESSION[$csrfKey] = bin2hex(random_bytes(32));
$csrf = $_SESSION[$csrfKey];
if ($todaId !== '') {
    $dr = supabaseRequest('drivers','GET',null,tqFeedbackQuery(['select'=>'id,name,tricycle_number,toda_id','toda_id'=>'eq.'.$todaId,'order'=>'name.asc','limit'=>1000]));
    if (empty($dr['success']) || !is_array($dr['data'])) $error = 'Unable to load drivers for this TODA.';
    else {
        $drivers = $dr['data'];
        $ids = array_values(array_filter(array_map(fn($r)=>(string)($r['id'] ?? ''), $drivers)));
        if ($ids) {
            $in = '('.implode(',', array_map('urlencode', $ids)).')';
            $rr = supabaseRequest('driver_ratings','GET',null,tqFeedbackQuery(['select'=>'*','driver_id'=>'in.'.$in,'order'=>'created_at.desc','limit'=>500]));
            $cr = supabaseRequest('complaints','GET',null,tqFeedbackQuery(['select'=>'*','driver_id'=>'in.'.$in,'order'=>'created_at.desc','limit'=>500]));
            $br = supabaseRequest('passenger_boardings','GET',null,tqFeedbackQuery(['select'=>'*','driver_id'=>'in.'.$in,'order'=>'boarded_at.desc','limit'=>500]));
            $ratings = is_array($rr['data'] ?? null) ? $rr['data'] : [];
            $complaints = is_array($cr['data'] ?? null) ? $cr['data'] : [];
            $boardings = is_array($br['data'] ?? null) ? $br['data'] : [];

            if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_complaint') {
                $complaintId = trim((string)($_POST['complaint_id'] ?? ''));
                $nextStatus = trim((string)($_POST['status'] ?? ''));
                $remarks = trim((string)($_POST['remarks'] ?? ''));
                $expectedVersion=filter_var($_POST['review_version']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
                if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
                    $error = 'Your form expired. Refresh the page and try again.';
                } elseif (!preg_match('/^[0-9a-f-]{36}$/i', $complaintId) || !in_array($nextStatus, $allowedComplaintStatuses, true)) {
                    $error = 'Invalid complaint update.';
                } elseif ($expectedVersion===false || $expectedVersion===null) {
                    $error = 'Refresh the complaint record before updating it.';
                } elseif (strlen($remarks) > 2000) {
                    $error = 'Remarks must be 2,000 characters or fewer.';
                } elseif (in_array($nextStatus, ['Resolved', 'Dismissed'], true) && $remarks === '') {
                    $error = 'Add remarks before marking a complaint Resolved or Dismissed.';
                } else {
                    $belongs = false;
                    foreach ($complaints as $c) if ((string)($c['id'] ?? '') === $complaintId) { $belongs = true; break; }
                    if (!$belongs) {
                        $error = 'That complaint does not belong to the selected TODA.';
                    } else {
                        $saved = supabaseRequest('rpc/trikeq_review_complaint','POST',[
                            'p_complaint_id'=>$complaintId,'p_status'=>$nextStatus,
                            'p_remarks'=>$remarks,'p_expected_version'=>$expectedVersion
                        ]);
                        if (empty($saved['success']) || ($saved['data']??null)!==true) $error = 'Unable to save complaint update. '.($saved['error'] ?? '');
                        else $success = 'Complaint status updated successfully.';
                    }
                }
                $cr2 = supabaseRequest('complaints','GET',null,tqFeedbackQuery(['select'=>'*','driver_id'=>'in.'.$in,'order'=>'created_at.desc','limit'=>500]));
                $complaints = is_array($cr2['data'] ?? null) ? $cr2['data'] : $complaints;
            }
        }
    }
}
$driverMap = [];
foreach ($drivers as $d) $driverMap[(string)($d['id'] ?? '')] = $d;
$summary = [];
foreach ($drivers as $d) {
    $id = (string)($d['id'] ?? '');
    $summary[$id] = ['rating_total'=>0, 'rating_sum'=>0, 'complaints'=>0, 'pending'=>0, 'resolved'=>0, 'boardings'=>0];
}
foreach ($ratings as $r) {
    $id = (string)($r['driver_id'] ?? '');
    if (isset($summary[$id])) { $summary[$id]['rating_total']++; $summary[$id]['rating_sum'] += (int)($r['rating'] ?? 0); }
}
foreach ($complaints as $c) {
    $id = (string)($c['driver_id'] ?? '');
    if (isset($summary[$id])) { $summary[$id]['complaints']++; if (($c['status'] ?? 'Pending') === 'Pending') $summary[$id]['pending']++; if (($c['status'] ?? '') === 'Resolved') $summary[$id]['resolved']++; }
}
foreach ($boardings as $b) { $id = (string)($b['driver_id'] ?? ''); if (isset($summary[$id])) $summary[$id]['boardings']++; }
if ($dateFrom !== '' || $dateTo !== '') {
    $inRange = function($value) use ($dateFrom, $dateTo) {
        $day = substr((string)$value, 0, 10);
        return ($dateFrom === '' || $day >= $dateFrom) && ($dateTo === '' || $day <= $dateTo);
    };
    $ratings = array_values(array_filter($ratings, fn($r) => $inRange($r['created_at'] ?? '')));
    $complaints = array_values(array_filter($complaints, fn($c) => $inRange($c['created_at'] ?? '')));
    $boardings = array_values(array_filter($boardings, fn($b) => $inRange($b['boarded_at'] ?? '')));
    $summary = [];
    foreach ($drivers as $d) { $id=(string)($d['id']??''); $summary[$id]=['rating_total'=>0,'rating_sum'=>0,'complaints'=>0,'pending'=>0,'resolved'=>0,'boardings'=>0]; }
    foreach ($ratings as $r) { $id=(string)($r['driver_id']??''); if(isset($summary[$id])){$summary[$id]['rating_total']++;$summary[$id]['rating_sum']+=(int)($r['rating']??0);} }
    foreach ($complaints as $c) { $id=(string)($c['driver_id']??''); if(isset($summary[$id])){$summary[$id]['complaints']++;if(($c['status']??'Pending')==='Pending')$summary[$id]['pending']++;if(($c['status']??'')==='Resolved')$summary[$id]['resolved']++;} }
    foreach ($boardings as $b) { $id=(string)($b['driver_id']??''); if(isset($summary[$id]))$summary[$id]['boardings']++; }
}
if ($todaId !== '' && ($_GET['export'] ?? '') === 'csv') {
    $orgName = 'TODA';
    foreach ($todaRows as $tr) if ((string)($tr['id'] ?? '') === $todaId) $orgName = (string)($tr['name'] ?? 'TODA');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="trikeq-'.preg_replace('/[^a-z0-9]+/i','-', $orgName).'-report.csv"');
    $out = fopen('php://output', 'w');fwrite($out,"\xEF\xBB\xBF");
    $csvSafe=static fn($values)=>array_map(static function($v){if(is_int($v)||is_float($v))return $v;$v=(string)$v;return preg_match('/^\s*[=+@-]/u',$v)?"'".$v:$v;},$values);
    fputcsv($out, ['Driver','Tricycle Number','Average Rating','Total Ratings','Boardings','Complaints','Pending','Resolved'],',','"','');
    foreach ($drivers as $d) { $sid=(string)($d['id']??''); $s=$summary[$sid]??[]; $total=(int)($s['rating_total']??0); fputcsv($out,$csvSafe([$d['name']??'', $d['tricycle_number']??'', $total ? number_format(((int)($s['rating_sum']??0))/$total,1) : '', $total, $s['boardings']??0, $s['complaints']??0, $s['pending']??0, $s['resolved']??0]),',','"',''); }
    fclose($out); exit;
}
$activity = [];
foreach ($ratings as $r) $activity[] = ['kind'=>'Rating', 'text'=>tqFeedbackDriverLabel($r['driver_id']??'', $driverMap).' received a '.($r['rating']??'').' / 5 rating', 'date'=>$r['created_at']??''];
foreach ($complaints as $c) $activity[] = ['kind'=>'Complaint', 'text'=>tqFeedbackDriverLabel($c['driver_id']??'', $driverMap).' complaint is '.($c['status']??'Pending'), 'date'=>$c['created_at']??''];
foreach ($boardings as $b) $activity[] = ['kind'=>'Boarding', 'text'=>tqFeedbackDriverLabel($b['driver_id']??'', $driverMap).' passenger boarding confirmed', 'date'=>$b['boarded_at']??''];
usort($activity, fn($a,$b) => strcmp((string)($b['date']??''), (string)($a['date']??'')));
$activity = array_slice($activity, 0, 12);
function tqFeedbackDriverLabel($id, $map) {
    $d = $map[(string)$id] ?? [];
    $n = trim((string)($d['name'] ?? 'Unknown driver'));
    $t = trim((string)($d['tricycle_number'] ?? ''));
    return $t !== '' ? $n.' · Tricycle '.$t : $n;
}
?>
<?php $adminName=$name; if($feedbackInReports): ?>
<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>TODA Feedback | Reports | TRIKE-Q</title>

    

    <style>

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family:
                Arial,
                Helvetica,
                sans-serif;

            background: #f8fafc;

            color: #1f2937;
        }

        .layout {
            display: flex;
            min-height: 100vh;
        }


        /* =================================================
           SIDEBAR
           ================================================= */

        .sidebar {
            width: 250px;

            background: #1f1f1f;

            color: white;

            position: fixed;

            top: 0;
            left: 0;
            bottom: 0;

            overflow-y: auto;

            padding: 24px 16px;

            z-index: 100;
        }

        .logo {
            text-align: center;

            font-size: 25px;

            font-weight: bold;

            color: #f97316;

            margin-bottom: 5px;
        }

        .logo-subtitle {
            text-align: center;

            font-size: 12px;

            color: #9ca3af;

            margin-bottom: 30px;
        }

        .menu-title {
            font-size: 11px;

            color: #6b7280;

            text-transform: uppercase;

            letter-spacing: 1px;

            margin:
                20px 10px
                8px;
        }

        .sidebar a {
            display: flex;

            align-items: center;

            gap: 10px;

            color: #d1d5db;

            text-decoration: none;

            padding: 12px 13px;

            margin-bottom: 4px;

            border-radius: 8px;

            font-size: 14px;

            transition: 0.2s;
        }

        .sidebar a:hover {
            background: #2d2d2d;

            color: white;
        }

        .sidebar a.active {
            background: #f97316;

            color: white;

            font-weight: bold;
        }

        .icon {
            width: 20px;

            text-align: center;

            font-size: 16px;
        }


        /* =================================================
           MAIN
           ================================================= */

        .main {
            margin-left: 250px;

            width: calc(100% - 250px);

            min-height: 100vh;
        }


        /* =================================================
           TOPBAR
           ================================================= */

        .topbar {
            height: 75px;

            background: white;

            border-bottom:
                1px solid #e5e7eb;

            display: flex;

            align-items: center;

            justify-content: space-between;

            padding:
                0 30px;

            position: sticky;

            top: 0;

            z-index: 50;
        }

        .page-title {
            font-size: 23px;

            font-weight: bold;

            color: #9a3412;
        }

        .page-subtitle {
            margin-top: 4px;

            font-size: 13px;

            color: #6b7280;
        }

        .admin-info {
            display: flex;

            align-items: center;

            gap: 10px;
        }

        .admin-avatar {
            width: 40px;

            height: 40px;

            border-radius: 50%;

            background: #f97316;

            color: white;

            display: flex;

            justify-content: center;

            align-items: center;

            font-weight: bold;
        }

        .admin-name {
            font-size: 14px;

            font-weight: bold;
        }

        .admin-role {
            font-size: 11px;

            color: #6b7280;
        }


        /* =================================================
           CONTENT
           ================================================= */

        .content {
            padding: 30px;
        }


        /* =================================================
           ALERT
           ================================================= */

        .alert {
            background: #fff7ed;

            border:
                1px solid #fed7aa;

            color: #9a3412;

            padding: 14px 16px;

            border-radius: 10px;

            margin-bottom: 20px;

            font-size: 14px;
        }


        /* =================================================
           SUMMARY CARDS
           ================================================= */

        .summary-grid {
            display: grid;

            grid-template-columns:
                repeat(4, 1fr);

            gap: 18px;

            margin-bottom: 25px;
        }

        .summary-card {
            background: white;

            border-radius: 12px;

            padding: 20px;

            box-shadow:
                0 2px 8px
                rgba(
                    0,
                    0,
                    0,
                    0.06
                );

            border-left:
                4px solid #f97316;
        }

        .summary-label {
            color: #6b7280;

            font-size: 13px;

            margin-bottom: 8px;
        }

        .summary-value {
            font-size: 28px;

            font-weight: bold;

            color: #9a3412;
        }

        .summary-small {
            margin-top: 5px;

            color: #9ca3af;

            font-size: 11px;
        }


        /* =================================================
           REPORT GRID
           ================================================= */

        .report-grid {
            display: grid;

            grid-template-columns:
                repeat(2, 1fr);

            gap: 22px;

            margin-bottom: 22px;
        }

        .report-card {
            background: white;

            border-radius: 12px;

            padding: 22px;

            box-shadow:
                0 2px 8px
                rgba(
                    0,
                    0,
                    0,
                    0.06
                );
        }

        .report-card.full {
            grid-column:
                1 / -1;
        }

        .report-title {
            color: #9a3412;

            font-size: 17px;

            font-weight: bold;

            margin-bottom: 5px;
        }

        .report-description {
            color: #6b7280;

            font-size: 12px;

            margin-bottom: 20px;
        }

        .chart-container {
            position: relative;

            height: 320px;

            width: 100%;
        }


        /* =================================================
           COMPLAINT STATS
           ================================================= */

        .complaint-summary {
            display: grid;

            grid-template-columns:
                repeat(4, 1fr);

            gap: 12px;

            margin-bottom: 20px;
        }

        .complaint-box {
            border-radius: 9px;

            padding: 15px;

            background: #f8fafc;

            border:
                1px solid #e5e7eb;
        }

        .complaint-box-label {
            font-size: 11px;

            color: #6b7280;

            margin-bottom: 5px;
        }

        .complaint-box-value {
            font-size: 22px;

            font-weight: bold;

            color: #374151;
        }


        /* =================================================
           TABLE
           ================================================= */

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;

            border-collapse: collapse;
        }

        th {
            text-align: left;

            padding: 13px;

            background: #fff7ed;

            color: #9a3412;

            font-size: 12px;

            border-bottom:
                1px solid #fed7aa;
        }

        td {
            padding: 13px;

            border-bottom:
                1px solid #e5e7eb;

            font-size: 13px;

            color: #374151;
        }

        tr:hover td {
            background: #fafafa;
        }

        .amount {
            font-weight: bold;

            color: #15803d;
        }

        .status-active {
            display: inline-block;

            padding: 5px 9px;

            border-radius: 20px;

            background: #dcfce7;

            color: #166534;

            font-size: 11px;

            font-weight: bold;
        }

        .status-inactive {
            display: inline-block;

            padding: 5px 9px;

            border-radius: 20px;

            background: #fee2e2;

            color: #991b1b;

            font-size: 11px;

            font-weight: bold;
        }


        /* =================================================
           EMPTY
           ================================================= */

        .empty {
            text-align: center;

            color: #9ca3af;

            padding: 30px;

            font-size: 13px;
        }


        /* =================================================
           RESPONSIVE
           ================================================= */

        @media (
            max-width: 1100px
        ) {

            .summary-grid {
                grid-template-columns:
                    repeat(2, 1fr);
            }

            .complaint-summary {
                grid-template-columns:
                    repeat(2, 1fr);
            }

        }


        @media (
            max-width: 800px
        ) {

            .sidebar {
                width: 210px;
            }

            .main {
                margin-left: 210px;

                width:
                    calc(
                        100% - 210px
                    );
            }

            .report-grid {
                grid-template-columns: 1fr;
            }

            .report-card.full {
                grid-column: auto;
            }

            .content {
                padding: 20px;
            }

        }


        @media (
            max-width: 600px
        ) {

            .sidebar {
                position: relative;

                width: 100%;

                height: auto;
            }

            .layout {
                display: block;
            }

            .main {
                margin-left: 0;

                width: 100%;
            }

            .summary-grid {
                grid-template-columns: 1fr;
            }

            .complaint-summary {
                grid-template-columns: 1fr;
            }

            .topbar {
                padding: 0 15px;
            }

            .content {
                padding: 15px;
            }

        }

    </style>

<link rel="stylesheet" href="admin-theme.css">

<!-- Shared TMU header and navigation; refresh versions when the shared files change. -->
<script src="admin-header.js?v=<?= (int) @filemtime(__DIR__.'/admin-header.js') ?>" data-tq-header="1" defer></script>
<script src="admin-nav.js?v=<?= (int) @filemtime(__DIR__.'/admin-nav.js') ?>" defer></script>
<style>
.tq-report-panel{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px;box-shadow:0 2px 8px #00000008}
.tq-report-tools{display:flex;align-items:end;gap:10px;flex-wrap:wrap;padding-bottom:14px;border-bottom:1px solid #e2e8f0;margin-bottom:14px}
.tq-report-tools>div{flex:1 1 160px;min-width:130px}.tq-report-tools label{display:block;margin-bottom:5px;font:600 13px Arial,Helvetica,sans-serif;color:#475569}
.tq-report-tools input,.tq-report-tools select{width:100%;height:40px;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#334155;font:14px Arial,Helvetica,sans-serif}
.tq-report-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:38px;margin:0;padding:9px 12px;border:1px solid #f97316;border-radius:8px;background:#f97316;color:#fff;text-decoration:none;font:600 13px Arial,Helvetica,sans-serif;cursor:pointer;white-space:nowrap}
.tq-report-csv{background:#16a34a;border-color:#16a34a}.tq-report-csv:hover{background:#15803d}.tq-report-print{background:#fff7ed;color:#9a3412;border-color:#fed7aa}.tq-report-btn:disabled{opacity:.5;cursor:default}
.tq-report-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.tq-report-heading h2{margin:0;color:#9a3412;font-size:18px}.tq-report-heading p,.tq-report-note{margin:6px 0;color:#64748b;font:13px/20px Arial,Helvetica,sans-serif}.tq-report-actions{display:flex;gap:8px}.tq-report-note{margin:10px 0 14px}
.tq-report-table-wrap{overflow-x:auto}.tq-report-panel table{width:100%;border-collapse:collapse;font:14px/21px Arial,Helvetica,sans-serif}.tq-report-panel th,.tq-report-panel td{padding:10px 12px;text-align:left;border-bottom:1px solid #e2e8f0;vertical-align:top;overflow-wrap:anywhere}.tq-report-panel th{background:#fff7ed;color:#9a3412;font-weight:600;white-space:nowrap}.tq-report-panel tfoot{background:#f8fafc;font-weight:600}.tq-money{text-align:right!important;white-space:nowrap}.tq-report-error{padding:12px;margin:0 0 12px;border:1px solid #fecaca;border-radius:8px;background:#fef2f2;color:#991b1b;font:14px/21px Arial,Helvetica,sans-serif}.tq-report-print-heading{display:none}
@media(max-width:600px){.tq-report-panel{padding:12px}.tq-report-actions{width:100%}.tq-report-panel th,.tq-report-panel td{padding:9px 8px}}
@page{size:A4 landscape;margin:12mm}
@media print{html,body,.layout,.main{margin:0!important;padding:0!important;width:100%!important;min-height:0!important;background:#fff!important}.sidebar,.topbar,.tq-report-tools,.tq-report-actions,.tq-report-note{display:none!important}.content{margin:0!important;padding:0!important}.tq-report-panel{border:0;padding:0;box-shadow:none}.tq-report-print-heading{display:block;margin-bottom:14px}.tq-report-print-heading h1{font-size:18pt;margin:0 0 6px;color:#111}.tq-report-print-heading p{font-size:10pt;margin:3px 0}.tq-report-panel table{font-size:10pt;line-height:1.4}.tq-report-table-wrap{overflow:visible}thead{display:table-header-group}.tq-report-panel th,.tq-report-panel td{padding:6px;border-bottom:1px solid #cbd5e1}tr{break-inside:avoid}a[href]::after{content:none!important}}
</style><style>.tq-report-tabs{display:flex;gap:6px;border-bottom:1px solid #e2e8f0;margin:0 0 16px;overflow-x:auto}.tq-report-tabs a{display:block;padding:12px 18px;border-bottom:3px solid transparent;color:#64748b;text-decoration:none;white-space:nowrap;font:600 15px Arial,Helvetica,sans-serif}.tq-report-tabs a[aria-current=page]{color:#c2410c;border-bottom-color:#f97316;background:#fff7ed}.tq-report-tabs a:hover{color:#9a3412;background:#fff7ed}@media print{.tq-report-tabs{display:none!important}}
.tq-feedback-report .tq-feedback-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px}.tq-feedback-heading h2{margin:0;color:#9a3412;font:600 20px Arial,Helvetica,sans-serif}.tq-feedback-heading p{margin:5px 0;color:#64748b;font:13px/20px Arial,Helvetica,sans-serif}.tq-feedback-heading>div:last-child{display:flex;gap:8px;flex-wrap:wrap}
.tq-feedback-report .panel{margin:0 0 14px;padding:16px;border:1px solid #e2e8f0;border-radius:12px;background:#fff;box-shadow:0 2px 8px #00000008}
.tq-feedback-report h2{margin:0 0 12px;color:#9a3412;font-size:18px}.tq-feedback-report .toolbar{display:flex;align-items:end;gap:10px;flex-wrap:wrap}.tq-feedback-report .toolbar>div{flex:1 1 160px;min-width:130px}
.tq-feedback-report label{display:block;margin-bottom:5px;color:#475569;font:600 13px Arial,Helvetica,sans-serif}.tq-feedback-report input[type=date],.tq-feedback-report select{box-sizing:border-box;width:100%;min-width:0;height:40px;margin:0;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#334155;font:14px Arial,Helvetica,sans-serif}
.tq-feedback-report button{min-height:38px;padding:9px 12px;border:1px solid #f97316;border-radius:8px;background:#f97316;color:#fff;font:600 13px Arial,Helvetica,sans-serif;cursor:pointer}
.tq-feedback-report .stats{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:0 0 14px;padding:2px 0;background:transparent}.tq-feedback-report .stat{display:inline-flex;gap:6px;align-items:center;padding:0;border:0;background:transparent;color:#64748b;font:14px Arial,Helvetica,sans-serif}.tq-feedback-report .stat strong{color:#334155;font-size:14px;font-weight:600}
.tq-feedback-report .table-wrap{overflow:auto}.tq-feedback-report table{width:100%;border-collapse:collapse;min-width:620px;font:14px/21px Arial,Helvetica,sans-serif}.tq-feedback-report th,.tq-feedback-report td{text-align:left;vertical-align:top;padding:10px 12px;border-bottom:1px solid #e2e8f0}.tq-feedback-report th{color:#9a3412;background:#fff7ed;font-size:13px;font-weight:600}.tq-feedback-report .muted,.tq-feedback-report small{color:#64748b}
.tq-feedback-report .alert{padding:12px;margin:0 0 14px;border-radius:8px;background:#fef2f2;color:#991b1b;font:14px/21px Arial,Helvetica,sans-serif}.tq-feedback-update{min-width:200px;margin-top:7px}.tq-feedback-update summary{width:fit-content;padding:6px 9px;border:1px solid #fed7aa;border-radius:7px;background:#fff7ed;color:#9a3412;font-size:13px;cursor:pointer}.tq-feedback-update form{display:grid;gap:8px;margin-top:8px}.tq-feedback-update textarea{box-sizing:border-box;width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:7px;font:14px/20px Arial,Helvetica,sans-serif}
@media print{.tq-feedback-heading>div:last-child,.tq-feedback-report .toolbar,.tq-feedback-update{display:none!important}.tq-feedback-report .panel{border:0;padding:0;margin-bottom:16px;box-shadow:none}.tq-feedback-report table{font-size:10pt;min-width:0}.tq-feedback-report th,.tq-feedback-report td{padding:6px}.tq-feedback-report h2{break-after:avoid}.tq-feedback-report tr{break-inside:avoid}}
</style></head>


<body>

<div class="layout">


    <!-- =================================================
         SIDEBAR
         ================================================= -->

    <aside class="sidebar">
    <img data-tq-system-logo src="system-logo.php?v=<?= time() ?>" alt="System logo" width="72" height="72" style="display:block;width:72px;height:72px;min-width:72px;max-width:72px;object-fit:contain;flex:0 0 72px;margin:0 auto 10px">

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

        <a href="president_requests.php">
            <span class="icon">◇</span>
            President Requests
        </a>

        <a href="reports.php" class="active" aria-current="page">
            <span class="icon">▥</span>
            Reports
        </a>

        <a href="settings.php">
            <span class="icon">⚙</span>
            Settings
        </a>

        <a href="admin_accounts.php">
            <span class="icon">▣</span>
            Admin Accounts
        </a>

    <div class="menu-title">Account</div>
        <a href="logout.php">
            <span class="icon">⇥</span>
            Logout
        </a>

</aside>


    <!-- =================================================
         MAIN
         ================================================= -->

    <main class="main">


        <!-- =================================================
             TOPBAR
             ================================================= -->

        <header class="topbar">

            <div>

                <div class="page-title">
                    Reports
                </div>

                <div class="page-subtitle">Filter detailed records, export CSV, and print reports.</div>

            </div>


            <div class="admin-info">

                <div>

                    <div class="admin-name">
                        <?= htmlspecialchars($adminName) ?>
                    </div>

                    <div class="admin-role">
                        TMU Administrator
                    </div>

                </div>

                <div class="admin-avatar">
                    <?= strtoupper(
                        substr(
                            $adminName,
                            0,
                            1
                        )
                    ) ?>
                </div>

            </div>

        </header>


        <!-- =================================================
             CONTENT
             ================================================= -->

        <section class="content"><nav class="tq-report-tabs" aria-label="Report sections"><a href="reports.php">Reports</a><a href="reports.php?report=feedback" aria-current="page">TODA Feedback</a></nav><div class="tq-feedback-report"><div class="tq-feedback-heading"><div><h2>TODA Feedback</h2><p>Driver ratings, complaints, and passenger confirmations</p></div><div><button class="tq-report-btn" type="button" onclick="window.print()">Print</button></div></div>
<?php else: ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>TODA Feedback | TRIKE-Q</title>
<style>*{box-sizing:border-box}body{margin:0;background:#f8fafc;color:#1f2937;font:14px Arial,sans-serif}.top{background:#111827;color:#fff;padding:20px 26px;display:flex;justify-content:space-between;gap:15px;align-items:center}.top h1{margin:0;color:#fb923c;font-size:22px}.top a{color:#fff;text-decoration:none}.wrap{max-width:1250px;margin:auto;padding:24px}.panel{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:18px;margin-bottom:18px}label{font-weight:bold;font-size:12px;display:block;margin-bottom:7px}select{padding:10px;border:1px solid #cbd5e1;border-radius:8px;min-width:280px}button{padding:10px 16px;border:0;border-radius:8px;background:#f97316;color:#fff;font-weight:bold;cursor:pointer}.toolbar{display:flex;gap:12px;align-items:end;flex-wrap:wrap}.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.stat{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px}.stat strong{display:block;font-size:25px;color:#c2410c;margin-top:5px}h2{font-size:18px;margin:0 0 14px;color:#9a3412}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;min-width:650px}th,td{text-align:left;padding:11px;border-bottom:1px solid #e5e7eb;vertical-align:top}th{font-size:11px;color:#64748b;text-transform:uppercase}small,.muted{color:#64748b}.alert{padding:13px;border-radius:8px;background:#fef2f2;color:#991b1b;margin-bottom:16px}@media(max-width:700px){.wrap{padding:14px}.stats{grid-template-columns:repeat(2,1fr)}.top{padding:16px}.top h1{font-size:18px}}
</style><link rel="stylesheet" href="admin-theme.css">
<!-- Shared TMU header and navigation; refresh versions when the shared files change. -->
<script src="admin-header.js?v=<?= (int) @filemtime(__DIR__.'/admin-header.js') ?>" data-tq-header="1" defer></script>
<script src="admin-nav.js?v=<?= (int) @filemtime(__DIR__.'/admin-nav.js') ?>" defer></script>
</head><body><aside class="sidebar">
    <img data-tq-system-logo src="system-logo.php?v=<?= time() ?>" alt="System logo" width="72" height="72" style="display:block;width:72px;height:72px;min-width:72px;max-width:72px;object-fit:contain;flex:0 0 72px;margin:0 auto 10px">

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

        <a href="toda_feedback.php" class="active" aria-current="page">
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

        <a href="president_requests.php">
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

</aside><main class="main"><header class="top"><div><h1>TRIKE-Q · TODA Feedback</h1><div class="muted">Ratings, complaints, and boarding history</div></div><div><?=tqFeedbackEscape($name)?></div></header><div class="wrap">

<?php endif; ?>
<?php if ($isAdmin): ?><section class="panel"><form class="toolbar" method="get">
<?php if($feedbackInReports): ?><input type="hidden" name="report" value="feedback"><?php endif; ?><div><label for="toda_id">Select TODA organization</label><select id="toda_id" name="toda_id" required><option value="">Choose a TODA</option><?php foreach($todaRows as $t): ?><option value="<?=tqFeedbackEscape($t['id'])?>" <?=$todaId===(string)$t['id']?'selected':''?>><?=tqFeedbackEscape($t['name'])?></option><?php endforeach; ?></select></div><div><label for="range">Quick date range</label><select id="range" name="range"><option value="">All dates</option><option value="today" <?=$preset==='today'?'selected':''?>>Today</option><option value="week" <?=$preset==='week'?'selected':''?>>This week</option><option value="month" <?=$preset==='month'?'selected':''?>>This month</option></select></div><div><label for="date_from">From</label><input type="date" id="date_from" name="date_from" value="<?=tqFeedbackEscape($dateFrom)?>"></div><div><label for="date_to">To</label><input type="date" id="date_to" name="date_to" value="<?=tqFeedbackEscape($dateTo)?>"></div><button type="submit">Apply</button><?php if($todaId!==''): ?><a href="?<?= $feedbackInReports ? 'report=feedback&amp;' : '' ?>toda_id=<?=tqFeedbackEscape($todaId)?>&range=<?=tqFeedbackEscape($preset)?>&date_from=<?=tqFeedbackEscape($dateFrom)?>&date_to=<?=tqFeedbackEscape($dateTo)?>&export=csv" style="display:inline-block;padding:10px 16px;border-radius:8px;background:#047857;color:#fff;text-decoration:none;font-weight:bold">Download CSV</a><?php endif; ?></form></section><?php else: ?><section class="panel"><strong>Your TODA records</strong><p class="muted">Only drivers and passenger activity belonging to your approved TODA are shown.</p><form class="toolbar" method="get" style="margin-top:12px"><div><label for="range">Quick date range</label><select id="range" name="range"><option value="">All dates</option><option value="today" <?=$preset==='today'?'selected':''?>>Today</option><option value="week" <?=$preset==='week'?'selected':''?>>This week</option><option value="month" <?=$preset==='month'?'selected':''?>>This month</option></select></div><div><label for="date_from">From</label><input type="date" id="date_from" name="date_from" value="<?=tqFeedbackEscape($dateFrom)?>"></div><div><label for="date_to">To</label><input type="date" id="date_to" name="date_to" value="<?=tqFeedbackEscape($dateTo)?>"></div><button type="submit">Apply Filter</button><?php if($todaId!==''): ?><a href="?range=<?=tqFeedbackEscape($preset)?>&date_from=<?=tqFeedbackEscape($dateFrom)?>&date_to=<?=tqFeedbackEscape($dateTo)?>&export=csv" style="display:inline-block;padding:10px 16px;border-radius:8px;background:#047857;color:#fff;text-decoration:none;font-weight:bold">Download CSV</a><?php endif; ?></form></section><?php endif; ?>
<?php if ($error): ?><div class="alert"><?=tqFeedbackEscape($error)?></div><?php endif; ?>
<?php if ($success): ?><div class="alert" style="background:#ecfdf5;color:#047857"><?=tqFeedbackEscape($success)?></div><?php endif; ?>
<?php if ($todaId===''): ?><section class="panel"><p class="muted">Select a TODA organization to view records.</p></section><?php else: ?><section class="stats"><div class="stat">Drivers<strong><?=count($drivers)?></strong></div><div class="stat">Ratings<strong><?=count($ratings)?></strong></div><div class="stat">Complaints<strong><?=count($complaints)?></strong></div><div class="stat">Boardings<strong><?=count($boardings)?></strong></div></section>
<section class="panel"><h2>Driver Performance Summary</h2><div class="table-wrap"><table><tr><th>Driver</th><th>Average rating</th><th>Ratings</th><th>Boardings</th><th>Complaints</th><th>Pending</th><th>Resolved</th></tr><?php foreach($drivers as $d): $sid=(string)($d['id']??''); $s=$summary[$sid]??[]; $avg=($s['rating_total']??0)>0?number_format($s['rating_sum']/$s['rating_total'],1):'—'; ?><tr><td><?=tqFeedbackEscape(tqFeedbackDriverLabel($sid,$driverMap))?></td><td><?=tqFeedbackEscape($avg)?><?=($avg!=='—'?' / 5':'')?></td><td><?=tqFeedbackEscape($s['rating_total']??0)?></td><td><?=tqFeedbackEscape($s['boardings']??0)?></td><td><?=tqFeedbackEscape($s['complaints']??0)?></td><td><?=tqFeedbackEscape($s['pending']??0)?></td><td><?=tqFeedbackEscape($s['resolved']??0)?></td></tr><?php endforeach; ?><?php if(!$drivers): ?><tr><td colspan="7" class="muted">No drivers found for this TODA.</td></tr><?php endif; ?></table></div></section>
<section class="panel"><h2>Recent Activity Notifications</h2><div class="table-wrap"><table><tr><th>Type</th><th>Activity</th><th>Date</th></tr><?php foreach($activity as $a): ?><tr><td><strong><?=tqFeedbackEscape($a['kind'])?></strong></td><td><?=tqFeedbackEscape($a['text'])?></td><td><?=tqFeedbackEscape(tqFeedbackDatePH($a['date']))?></td></tr><?php endforeach; ?><?php if(!$activity): ?><tr><td colspan="3" class="muted">No recent activity for the selected range.</td></tr><?php endif; ?></table></div></section>
<section class="panel"><h2>Driver Ratings</h2><div class="table-wrap"><table><tr><th>Driver</th><th>Rating</th><th>Comment</th><th>Date</th></tr><?php foreach($ratings as $r): ?><tr><td><?=tqFeedbackEscape(tqFeedbackDriverLabel($r['driver_id']??'', $driverMap))?></td><td><?=tqFeedbackEscape($r['rating']??'')?> / 5</td><td><?=tqFeedbackEscape($r['comment']??'')?></td><td><?=tqFeedbackEscape(tqFeedbackDatePH($r['created_at']??''))?></td></tr><?php endforeach; ?><?php if(!$ratings): ?><tr><td colspan="4" class="muted">No ratings yet.</td></tr><?php endif; ?></table></div></section>
<section class="panel"><h2>Passenger Complaints</h2><div class="table-wrap"><table><tr><th>Driver</th><th>Type / Message</th><th>Status and remarks</th><th>Date</th></tr><?php foreach($complaints as $c): ?><tr><td><?=tqFeedbackEscape(tqFeedbackDriverLabel($c['driver_id']??'', $driverMap))?></td><td><strong><?=tqFeedbackEscape($c['complaint_type']??'')?></strong><br><?=tqFeedbackEscape($c['message']??'')?></td><td><strong><?=tqFeedbackEscape($c['status']??'Pending')?></strong><?php if(!empty($c['remarks'])): ?><br><small><?=tqFeedbackEscape($c['remarks'])?></small><?php endif; ?><?php if($isAdmin): ?><details class="tq-feedback-update"><summary>Update complaint</summary><form method="post"><input type="hidden" name="review_version" value="<?=tqFeedbackEscape($c['tmu_review_version']??'')?>"><input type="hidden" name="action" value="update_complaint"><input type="hidden" name="csrf_token" value="<?=tqFeedbackEscape($csrf)?>"><input type="hidden" name="complaint_id" value="<?=tqFeedbackEscape($c['id']??'')?>"><select name="status" style="padding:7px;border:1px solid #cbd5e1;border-radius:6px"><?php foreach($allowedComplaintStatuses as $st): ?><option value="<?=tqFeedbackEscape($st)?>" <?=($st===($c['status']??'Pending'))?'selected':''?>><?=tqFeedbackEscape($st)?></option><?php endforeach; ?></select><br><textarea name="remarks" rows="2" maxlength="2000" placeholder="Remarks"><?=tqFeedbackEscape($c['remarks']??'')?></textarea><br><button type="submit" style="padding:7px 10px;font-size:12px">Save Update</button></form></details><?php endif; ?></td><td><?=tqFeedbackEscape(tqFeedbackDatePH($c['created_at']??''))?></td></tr><?php endforeach; ?><?php if(!$complaints): ?><tr><td colspan="4" class="muted">No complaints yet.</td></tr><?php endif; ?></table></div></section>
<section class="panel"><h2>Passenger Confirmation History</h2><div class="table-wrap"><table><tr><th>Driver</th><th>Confirmed at</th></tr><?php foreach($boardings as $b): ?><tr><td><?=tqFeedbackEscape(tqFeedbackDriverLabel($b['driver_id']??'', $driverMap))?></td><td><?=tqFeedbackEscape(tqFeedbackDatePH($b['boarded_at']??''))?></td></tr><?php endforeach; ?><?php if(!$boardings): ?><tr><td colspan="2" class="muted">No passenger confirmations yet.</td></tr><?php endif; ?></table></div></section><?php endif; ?>
<?php if($feedbackInReports): ?></div></section></main></div></body></html><?php else: ?></div></main></body></html><?php endif; ?>
