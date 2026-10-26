<?php
if(session_status()===PHP_SESSION_NONE)session_start();
require_once __DIR__.'/config/supabase.php';
require_once __DIR__.'/tmu-tools.php';
tqRequireAdmin();
// TODA Feedback is now a view inside Reports; normalize existing filter names.
foreach(['toda_id'=>'toda','date_from'=>'from','date_to'=>'to'] as $oldKey=>$newKey) {
    if(!isset($_GET[$newKey]) && isset($_GET[$oldKey]) && is_string($_GET[$oldKey])) $_GET[$newKey]=$_GET[$oldKey];
}
if(($_GET['report']??'')==='feedback') {
    foreach(['toda'=>'toda_id','from'=>'date_from','to'=>'date_to'] as $oldKey=>$newKey) {
        if(!isset($_GET[$newKey]) && isset($_GET[$oldKey]) && is_string($_GET[$oldKey])) $_GET[$newKey]=$_GET[$oldKey];
    }
    define('TRIKEQ_FEEDBACK_IN_REPORTS',true);
    require __DIR__.'/toda_feedback.php';
    exit;
}

define('TRIKEQ_REPORT_CONTEXT',true);
require_once __DIR__.'/includes/tmu-report-data.php';
header('Cache-Control: private, no-store, max-age=0');
function e($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function reportInput($key,$default=''){return isset($_GET[$key])&&is_string($_GET[$key])?trim($_GET[$key]):$default;}
$data=tqReportDataLoad();
$adminResult=supabaseRequest('admin','GET',null,http_build_query(['select'=>'name','user_id'=>'eq.'.($_SESSION['user_id']??'')],'','&',PHP_QUERY_RFC3986));
$adminName=trim((string)($adminResult['data'][0]['name']??''))?:'TMU Administrator';
$reportTypes=['summary'=>'TODA Collection Summary','drivers'=>'Driver Records','collections'=>'Collection Records','complaints'=>'Complaint Records'];
$type=reportInput('report','summary');if(!isset($reportTypes[$type]))$type='summary';
$todaId=reportInput('toda');$from=reportInput('from');$to=reportInput('to');$filterError='';
$preset=reportInput('range');$nowPH=new DateTimeImmutable('today',new DateTimeZone('Asia/Manila'));
if(in_array($preset,['today','week','month'],true)){$to=$nowPH->format('Y-m-d');$from=($preset==='today'?$nowPH:($preset==='week'?$nowPH->modify('monday this week'):$nowPH->modify('first day of this month')))->format('Y-m-d');}

if($todaId!==''&&!isset($data['toda_names'][$todaId]))$filterError='Choose a valid TODA organization.';
if(($from!==''&&!tqReportDate($from))||($to!==''&&!tqReportDate($to)))$filterError='Enter valid start and end dates.';
elseif($from!==''&&$to!==''&&$from>$to)$filterError='The start date must be on or before the end date.';
$matchesToda=static fn($r)=>$todaId===''||($r['toda_id']??'')===$todaId;
$drivers=array_values(array_filter($data['drivers'],static fn($r)=>$matchesToda($r)&&tqReportInPeriod($r['created_at']??null,$from,$to)));
$dues=array_values(array_filter($data['dues'],static fn($r)=>$matchesToda($r)&&tqReportInPeriod($r['collection_date']??null,$from,$to,true)));
$complaints=array_values(array_filter($data['complaints'],static fn($r)=>$matchesToda($r)&&tqReportInPeriod($r['created_at']??null,$from,$to)));
$orgName=static fn($r)=>$data['toda_names'][$r['toda_id']??'']??'Unassigned / unavailable';
$columns=[];$rows=[];$moneyIndex=null;$totals=null;
$ready=$data['ready']['toda_organizations'];
if($type==='summary'){
 $ready=$ready&&$data['ready']['drivers']&&$data['ready']['dues'];
 $columns=['TODA Organization','Drivers','Active Drivers','Inactive Drivers','Total Collections (PHP)'];$moneyIndex=4;$groups=[];
 foreach($data['toda_organizations'] as $t)if($todaId===''||$todaId===$t['id'])$groups[$t['id']]=[$t['name'],0,0,0,0.0];
 foreach($drivers as $d){$key=isset($data['toda_names'][$d['toda_id']??''])?$d['toda_id']:'unassigned';if(!isset($groups[$key]))$groups[$key]=['Unassigned / unavailable',0,0,0,0.0];$groups[$key][1]++;$status=strtolower(trim((string)($d['status']??'')));if($status==='active')$groups[$key][2]++;elseif($status==='inactive')$groups[$key][3]++;}
 foreach($dues as $d){$key=isset($data['toda_names'][$d['toda_id']??''])?$d['toda_id']:'unassigned';if(!isset($groups[$key]))$groups[$key]=['Unassigned / unavailable',0,0,0,0.0];$groups[$key][4]+=(float)($d['amount']??0);}
 $rows=array_values($groups);$totals=['TOTAL',array_sum(array_column($rows,1)),array_sum(array_column($rows,2)),array_sum(array_column($rows,3)),array_sum(array_column($rows,4))];
}elseif($type==='drivers'){
 $ready=$ready&&$data['ready']['drivers'];
 $columns=['Application Number','Driver','TODA','Tricycle Number','Driver Status','Franchise Expiry','Renewal Status','Registered'];
 foreach($drivers as $d)$rows[]=[$d['application_number']??'Not provided',$d['name']??'Unnamed driver',$orgName($d),$d['tricycle_number']??'Not provided',$d['status']??'Not provided',$d['franchise_expiry_date']??'Not provided',tqReportRenewal($d['franchise_expiry_date']??null),tqReportDay($d['created_at']??null)?:'Not provided'];
}elseif($type==='collections'){
 $ready=$ready&&$data['ready']['dues']&&$data['ready']['drivers'];
 $columns=['Collection Date','Driver','TODA','Amount (PHP)','Remarks'];$moneyIndex=3;
 foreach($dues as $d)$rows[]=[$d['collection_date']??'Not provided',$data['driver_names'][$d['driver_id']??'']??'Unavailable driver',$orgName($d),(float)($d['amount']??0),$d['remarks']??''];
 $totals=['TOTAL','','',array_sum(array_column($rows,3)),''];
}else{
 $ready=$ready&&$data['ready']['complaints'];
 $columns=['Received','Incident Date','Driver','TODA','Complaint Type(s)','Status','TMU Remarks'];
 foreach($complaints as $c){$types=is_array($c['complaint_types']??null)&&$c['complaint_types']?implode(', ',$c['complaint_types']):($c['complaint_type']??'Unspecified');$rows[]=[tqReportDay($c['created_at']??null)?:'Not provided',$c['incident_date']??'Not provided',$data['driver_names'][$c['driver_id']??'']??$c['driver_name']??'Unavailable driver',$orgName($c),$types,tqReportComplaintStatus($c['status']??'Pending'),$c['remarks']??''];}
}
if($filterError!==''||!$ready){$rows=[];$totals=null;}
$reportTitle=$reportTypes[$type];$period=($from!==''?$from:'Beginning').' to '.($to!==''?$to:'Present');
$generated=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('M d, Y h:i A');
if(reportInput('export')==='csv'){
 if($filterError!==''||!$ready){http_response_code(503);exit('The report is unavailable. Correct the filters or refresh before exporting.');}
 header('Content-Type: text/csv; charset=UTF-8');
 header('Content-Disposition: attachment; filename="TRIKE-Q_'.$type.'_'.(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d').'.csv"');
 $stream=fopen('php://output','w');fwrite($stream,"\xEF\xBB\xBF");
 $safe=static function($row){return array_map(static function($v){if(is_int($v)||is_float($v))return $v;$v=(string)$v;return preg_match('/^\s*[=+@-]/u',$v)?"'".$v:$v;},$row);};
 fputcsv($stream,$safe($columns),',','"','');foreach($rows as $row)fputcsv($stream,$safe($row),',','"','');if($totals)fputcsv($stream,$safe($totals),',','"','');fclose($stream);exit;
}
$exportUrl='reports.php?'.http_build_query(['report'=>$type,'toda'=>$todaId,'from'=>$from,'to'=>$to,'export'=>'csv'],'','&',PHP_QUERY_RFC3986);
?>
<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Reports - TRIKE-Q TMU Admin</title>

    

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
.tq-report-tabs{display:flex;gap:6px;border-bottom:1px solid #e2e8f0;margin:0 0 16px;overflow-x:auto}.tq-report-tabs a{display:block;padding:12px 18px;border-bottom:3px solid transparent;color:#64748b;text-decoration:none;white-space:nowrap;font:600 15px Arial,Helvetica,sans-serif}.tq-report-tabs a[aria-current=page]{color:#c2410c;border-bottom-color:#f97316;background:#fff7ed}.tq-report-tabs a:hover{color:#9a3412;background:#fff7ed}@media print{.tq-report-tabs{display:none!important}}
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
@media print{html,body,.layout,.main{margin:0!important;padding:0!important;width:100%!important;min-height:0!important;background:#fff!important}.sidebar,.topbar,.tq-feedback-filter-bar,.tq-report-tools,.tq-report-actions,.tq-report-note{display:none!important}.content{margin:0!important;padding:0!important}.tq-report-panel{border:0;padding:0;box-shadow:none}.tq-report-print-heading{display:block;margin-bottom:14px}.tq-report-print-heading h1{font-size:18pt;margin:0 0 6px;color:#111}.tq-report-print-heading p{font-size:10pt;margin:3px 0}.tq-report-panel table{font-size:10pt;line-height:1.4}.tq-report-table-wrap{overflow:visible}thead{display:table-header-group}.tq-report-panel th,.tq-report-panel td{padding:6px;border-bottom:1px solid #cbd5e1}tr{break-inside:avoid}a[href]::after{content:none!important}}
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

        <section class="content">
<div class="tq-report-print-heading"><h1>TRIKE-Q — <?=e($reportTitle)?></h1><p>TODA: <?=e($todaId!==''?($data['toda_names'][$todaId]??'Unavailable'):'All TODAs')?> · Period: <?=e($period)?></p><p>Generated: <?=e($generated)?> (PH)</p></div>
<?php if($filterError): ?><p class="tq-report-error" role="alert"><?=e($filterError)?></p><?php endif; ?>
<?php foreach($data['errors'] as $loadError): ?><p class="tq-report-error" role="alert"><?=e($loadError)?></p><?php endforeach; ?>
<nav class="tq-report-tabs" aria-label="Report sections"><a href="reports.php" aria-current="page">Reports</a><a href="reports.php?report=feedback">TODA Feedback</a></nav>
<section class="tq-report-panel">
<form method="GET" class="tq-report-tools">
<div><label for="reportType">Report</label><select id="reportType" name="report"><?php foreach($reportTypes as $key=>$label): ?><option value="<?=e($key)?>" <?=$key===$type?'selected':''?>><?=e($label)?></option><?php endforeach; ?></select></div>
<div><label for="reportToda">TODA</label><select id="reportToda" name="toda"><option value="">All TODAs</option><?php foreach($data['toda_names'] as $id=>$name): ?><option value="<?=e($id)?>" <?=$id===$todaId?'selected':''?>><?=e($name)?></option><?php endforeach; ?></select></div>
<div><label for="reportRange">Quick date range</label><select id="reportRange" name="range"><option value="">All dates / Custom</option><?php foreach(['today'=>'Today','week'=>'This week','month'=>'This month'] as $key=>$label): ?><option value="<?=e($key)?>" <?=$preset===$key?'selected':''?>><?=e($label)?></option><?php endforeach; ?></select></div>
<div><label for="reportFrom">From</label><input id="reportFrom" type="date" name="from" value="<?=e($from)?>"></div>
<div><label for="reportTo">To</label><input id="reportTo" type="date" name="to" value="<?=e($to)?>"></div>
<button class="tq-report-btn" type="submit">Apply</button>
</form>
<div class="tq-report-heading"><div><h2><?=e($reportTitle)?></h2><p><?=!$ready||$filterError!==''?'Report unavailable':number_format(count($rows)).' record(s)'?> · <?=e($period)?></p></div><div class="tq-report-actions">
<?php if($ready&&$filterError===''): ?><a class="tq-report-btn tq-report-csv" href="<?=e($exportUrl)?>"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3v12m-5-5 5 5 5-5M4 17v4h16v-4"/></svg>Download CSV</a><?php else: ?><button class="tq-report-btn tq-report-csv" disabled>Download CSV</button><?php endif; ?>
<button type="button" class="tq-report-btn tq-report-print" onclick="window.print()" <?=!$ready||$filterError!==''?'disabled':''?>>Print</button></div></div>
<p class="tq-report-note">Dates filter driver registration, dues collection, and complaint received dates. Driver, franchise, and complaint statuses show their current values. The default report includes all dates.</p>
<div class="tq-report-table-wrap"><table id="detailedReportTable"><thead><tr><?php foreach($columns as $label): ?><th><?=e($label)?></th><?php endforeach; ?></tr></thead><tbody>
<?php if(!$rows): ?><tr><td colspan="<?=count($columns)?>"><?=!$ready||$filterError!==''?'Data is unavailable. Correct the filters or refresh.':'No records match these filters.'?></td></tr><?php endif; ?>
<?php foreach($rows as $row): ?><tr><?php foreach($row as $index=>$cell): ?><td class="<?=$moneyIndex===$index?'tq-money':''?>"><?=$moneyIndex===$index?'₱'.number_format((float)$cell,2):e($cell)?></td><?php endforeach; ?></tr><?php endforeach; ?>
</tbody><?php if($totals): ?><tfoot><tr><?php foreach($totals as $index=>$cell): ?><td class="<?=$moneyIndex===$index?'tq-money':''?>"><?=$moneyIndex===$index?'₱'.number_format((float)$cell,2):e($cell)?></td><?php endforeach; ?></tr></tfoot><?php endif; ?></table></div>
</section></section>


    </main>

</div>





</body>

</html>
