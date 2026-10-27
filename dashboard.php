<?php

if(session_status()===PHP_SESSION_NONE)session_start();

require_once __DIR__.'/config/supabase.php';

require_once __DIR__.'/tmu-tools.php';

tqRequireAdmin();

define('TRIKEQ_REPORT_CONTEXT',true);

require_once __DIR__.'/includes/tmu-report-data.php';

function e($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}

$data=tqReportDataLoad();$overview=tqReportOverview($data);

$adminResult=supabaseRequest('admin','GET',null,http_build_query(['select'=>'name','user_id'=>'eq.'.($_SESSION['user_id']??'')],'','&',PHP_QUERY_RFC3986));

$adminName=trim((string)($adminResult['data'][0]['name']??''))?:'TMU Administrator';

$avatarLetter=function_exists('mb_substr')?mb_substr($adminName,0,1,'UTF-8'):substr($adminName,0,1);

$validRenewals=$overview['renewals']['Valid'];$dueSoonRenewals=$overview['renewals']['Due Soon'];$expiredRenewals=$overview['renewals']['Expired'];

$renewalAttention=$dueSoonRenewals+$expiredRenewals;

function dashboardNumber($value,$ready,$money=false){return !$ready?'—':($money?'₱'.number_format($value,2):number_format($value));}

$metrics=[

 ['Total Drivers',$overview['drivers'],'All registered records',$data['ready']['drivers'],false],

 ['Active Drivers',$overview['active'],'Currently active drivers',$data['ready']['drivers'],false],

 ['TODA Organizations',$overview['organizations'],'Registered organizations',$data['ready']['toda_organizations'],false],

 ['Complaints',$overview['complaints'],'Passenger complaints received',$data['ready']['complaints'],false],



];

$driverStatus=['Active'=>$overview['active'],'Inactive'=>$overview['inactive']];

if($overview['other_driver_status'])$driverStatus['Other / unspecified']=$overview['other_driver_status'];

$chartData=[

 'driversTODAChart'=>['type'=>'bar','labels'=>array_column(array_values($overview['driver_toda']),'name'),'values'=>array_column(array_values($overview['driver_toda']),'value'),'label'=>'Drivers','colors'=>['#F97316'],'ready'=>$data['ready']['drivers']&&$data['ready']['toda_organizations']],

 'driverStatusChart'=>['type'=>'doughnut','labels'=>array_keys($driverStatus),'values'=>array_values($driverStatus),'colors'=>['#16A34A','#DC2626','#94A3B8'],'ready'=>$data['ready']['drivers']],

 'renewalChart'=>['type'=>'doughnut','labels'=>array_keys($overview['renewals']),'values'=>array_values($overview['renewals']),'colors'=>['#16A34A','#FACC15','#DC2626','#94A3B8'],'ready'=>$data['ready']['drivers']],

 'complaintChart'=>['type'=>'bar','labels'=>array_keys($overview['complaint_status']),'values'=>array_values($overview['complaint_status']),'label'=>'Complaints','colors'=>['#FACC15','#16A34A','#DC2626'],'ready'=>$data['ready']['complaints']],



 'complaintTypeChart'=>['type'=>'doughnut','labels'=>array_keys($overview['complaint_types']),'values'=>array_values($overview['complaint_types']),'colors'=>['#DC2626','#F97316','#FACC15','#16A34A','#F87171','#FB923C','#FDE047','#4ADE80'],'ready'=>$data['ready']['complaints']],

];

?>

<!DOCTYPE html>



<html lang="en">



<head>



    <meta charset="UTF-8">



    <meta

        name="viewport"

        content="width=device-width, initial-scale=1.0"

    >



    <title>

        Dashboard - TRIKE-Q TMU Admin

    </title>



    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>





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



            background:

                #111827;



            color: #f9fafb;



            position: fixed;



            top: 0;

            left: 0;

            bottom: 0;



            overflow-y: auto;



            padding:

                24px 15px 18px;



            z-index: 100;



            border-right:

                1px solid #263244;



            display: flex;



            flex-direction: column;

        }





        .sidebar::-webkit-scrollbar {

            width: 5px;

        }





        .sidebar::-webkit-scrollbar-track {

            background: #111827;

        }





        .sidebar::-webkit-scrollbar-thumb {

            background: #374151;

            border-radius: 10px;

        }





        .logo {



            text-align: center;



            font-size: 25px;



            font-weight: bold;



            color:

                #fb923c;



            margin-bottom: 5px;



            letter-spacing:

                0.5px;

        }





        .logo-subtitle {



            text-align: center;



            font-size: 11px;



            color:

                #cbd5e1;



            margin-bottom: 28px;



            letter-spacing:

                0.5px;

        }





        .menu-title {



            font-size: 10px;



            color:

                #94a3b8;



            text-transform:

                uppercase;



            letter-spacing:

                1.2px;



            margin:

                20px 10px 8px;



            font-weight:

                700;

        }





        .sidebar a {



            display: flex;



            align-items: center;



            gap: 10px;



            color:

                #e2e8f0;



            text-decoration: none;



            padding:

                11px 13px;



            margin-bottom: 4px;



            border-radius: 8px;



            font-size: 14px;



            font-weight:

                500;



            transition:

                *background* 0.2s,

                color 0.2s,

                transform 0.2s;

        }





        .sidebar a:hover {



            background:

                #253047;



            color:

                #ffffff;



            transform:

                translateX(2px);

        }





        .sidebar a.active {



            background:

                #f97316;



            color:

                #ffffff;



            font-weight:

                bold;



            box-shadow:

                0 3px 10px

                rgba(

                    249,

                    115,

                    22,

                    0.22

                );

        }





        .sidebar a.active:hover {



            background:

                #ea580c;



            transform:

                none;

        }





        .icon {



            width: 20px;



            min-width: 20px;



            text-align: center;



            font-size: 16px;



            color:

                #cbd5e1;

        }





        .sidebar a.active .icon {



            color:

                #ffffff;

        }





        /* =================================================

           SIDEBAR ADMIN PROFILE

           ================================================= */



        .sidebar-profile {



            margin-top: auto;



            padding-top: 15px;



            border-top:

                1px solid #263244;

        }





        .sidebar-profile-box {



            display: flex;



            align-items: center;



            gap: 10px;



            padding:

                10px;



            border-radius: 9px;



            background:

                #1a2333;



            border:

                1px solid #2b374a;

        }





        .sidebar-avatar {



            width: 36px;



            height: 36px;



            min-width: 36px;



            border-radius: 50%;



            background:

                #f97316;



            color:

                #ffffff;



            display: flex;



            justify-content: center;



            align-items: center;



            font-size: 14px;



            font-weight: bold;



            text-transform:

                uppercase;

        }





        .sidebar-profile-text {



            min-width: 0;



            flex: 1;

        }





        .sidebar-admin-name {



            color:

                #ffffff;



            font-size: 12px;



            font-weight: bold;



            white-space: nowrap;



            overflow: hidden;



            text-overflow: ellipsis;

        }





        .sidebar-admin-role {



            color:

                #aeb9c9;



            font-size: 10px;



            margin-top: 3px;

        }





        /* =================================================

           MAIN

           ================================================= */



        .main {



            margin-left: 250px;



            width:

                calc(

                    100% - 250px

                );



            min-height: 100vh;

        }





        /* =================================================

           TOPBAR

           ================================================= */



        .topbar {



            height: 75px;



            background:

                white;



            border-bottom:

                1px solid #e5e7eb;



            display: flex;



            align-items: center;



            justify-content:

                space-between;



            padding:

                0 30px;



            position: sticky;



            top: 0;



            z-index: 50;

        }





        .page-title {



            font-size: 23px;



            font-weight: bold;



            color:

                #9a3412;

        }





        .page-subtitle {



            margin-top: 4px;



            font-size: 13px;



            color:

                #6b7280;

        }





        .admin-info {



            display: flex;



            align-items: center;



            gap: 10px;

        }





        .admin-name {



            font-size: 14px;



            font-weight: bold;



            color:

                #374151;

        }





        .admin-role {



            font-size: 11px;



            color:

                #6b7280;



            margin-top: 3px;

        }





        .admin-avatar {



            width: 40px;



            height: 40px;



            border-radius: 50%;



            background:

                #f97316;



            color:

                white;



            display: flex;



            justify-content: center;



            align-items: center;



            font-weight: bold;



            text-transform:

                uppercase;

        }





        /* =================================================

           CONTENT

           ================================================= */



        .content {

            padding: 30px;

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



            background:

                white;



            border-radius:

                12px;



            padding:

                20px;



            border:

                1px solid #e5e7eb;



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



            transition:

                transform 0.2s,

                box-shadow 0.2s;

        }





        .summary-card:hover {



            transform:

                translateY(-2px);



            box-shadow:

                0 6px 16px

                rgba(

                    0,

                    0,

                    0,

                    0.08

                );

        }





        .summary-label {



            color:

                #6b7280;



            font-size: 13px;



            margin-bottom: 8px;



            font-weight: 600;

        }





        .summary-value {



            font-size: 28px;



            font-weight: bold;



            color:

                #9a3412;

        }





        .summary-small {



            margin-top: 5px;



            color:

                #9ca3af;



            font-size: 11px;

        }





        /* =================================================

           SECTION TITLE

           ================================================= */



        .section-title {



            color:

                #9a3412;



            font-size: 19px;



            font-weight: bold;



            margin:

                25px 0 15px;

        }





        /* =================================================

           RENEWAL CARDS

           ================================================= */



        .renewal-grid {



            display: grid;



            grid-template-columns:

                repeat(3, 1fr);



            gap: 18px;



            margin-bottom: 22px;

        }





        .renewal-card {



            background:

                white;



            border-radius:

                12px;



            padding:

                20px;



            border:

                1px solid #e5e7eb;



            box-shadow:

                0 2px 8px

                rgba(

                    0,

                    0,

                    0,

                    0.06

                );



            transition:

                transform 0.2s,

                box-shadow 0.2s;

        }





        .renewal-card:hover {



            transform:

                translateY(-2px);



            box-shadow:

                0 6px 16px

                rgba(

                    0,

                    0,

                    0,

                    0.08

                );

        }





        .renewal-label {



            font-size: 13px;



            color:

                #6b7280;



            font-weight: 600;



            margin-bottom: 8px;

        }





        .renewal-value {



            font-size: 28px;



            font-weight: bold;



            color:

                #9a3412;

        }





        .renewal-description {



            margin-top: 5px;



            color:

                #9ca3af;



            font-size: 11px;

        }





        /* =================================================

           RENEWAL ALERT

           ================================================= */



        .alert {



            background:

                #fff7ed;



            border:

                1px solid #fed7aa;



            border-left:

                4px solid #f97316;



            color:

                #7c2d12;



            padding:

                18px;



            border-radius:

                10px;



            margin-bottom:

                25px;

        }





        .alert-title {



            font-size: 16px;



            font-weight: bold;



            color:

                #9a3412;



            margin-bottom:

                6px;

        }





        .alert-text {



            font-size: 13px;



            line-height: 1.5;

        }





        .orange-button {



            display: inline-block;



            margin-top: 12px;



            background:

                #f97316;



            color:

                white;



            padding:

                10px 15px;



            border-radius:

                7px;



            text-decoration: none;



            font-size: 12px;



            font-weight: bold;



            transition:

                *background* 0.2s;

        }





        .orange-button:hover {



            background:

                #ea580c;

        }





        /* =================================================

           ANALYTICS

           ================================================= */



        .analytics-grid {



            display: grid;



            grid-template-columns:

                repeat(2, 1fr);



            gap: 20px;



            margin-bottom: 25px;

        }





        .chart-card {



            background:

                white;



            border-radius:

                12px;



            padding:

                22px;



            border:

                1px solid #e5e7eb;



            box-shadow:

                0 2px 8px

                rgba(

                    0,

                    0,

                    0,

                    0.06

                );

        }





        .chart-card.full {



            grid-column:

                1 / -1;

        }





        .chart-header {

            margin-bottom: 18px;

        }





        .chart-title {



            color:

                #9a3412;



            font-size: 17px;



            font-weight: bold;



            margin-bottom: 5px;

        }





        .chart-description {



            color:

                #6b7280;



            font-size: 12px;



            line-height: 1.5;

        }





        .chart-container {



            position: relative;



            width: 100%;



            height: 300px;

        }





        /* =================================================

           QUICK ACCESS

           ================================================= */



        .quick-grid {



            display: grid;



            grid-template-columns:

                repeat(3, 1fr);



            gap: 18px;

        }





        .quick-card {



            background:

                white;



            border:

                1px solid #e5e7eb;



            border-radius:

                12px;



            padding:

                20px;



            text-decoration:

                none;



            color:

                #1f2937;



            box-shadow:

                0 2px 8px

                rgba(

                    0,

                    0,

                    0,

                    0.06

                );



            transition:

                transform 0.2s,

                border-color 0.2s,

                box-shadow 0.2s;

        }





        .quick-card:hover {



            transform:

                translateY(-2px);



            border-color:

                #f97316;



            box-shadow:

                0 6px 16px

                rgba(

                    0,

                    0,

                    0,

                    0.08

                );

        }





        .quick-card h3 {



            color:

                #9a3412;



            font-size: 16px;



            margin-bottom:

                7px;

        }





        .quick-card p {



            color:

                #6b7280;



            font-size: 12px;



            line-height:

                1.5;

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





            .analytics-grid {



                grid-template-columns:

                    1fr;

            }





            .chart-card.full {



                grid-column:

                    auto;

            }





            .quick-grid {



                grid-template-columns:

                    repeat(2, 1fr);

            }

        }





        @media (

            max-width: 800px

        ) {



            .sidebar {



                position: relative;



                width: 100%;



                height: auto;



                padding:

                    20px 15px;

            }





            .main {



                margin-left: 0;



                width: 100%;

            }





            .layout {



                display: block;

            }





            .sidebar-profile {



                margin-top: 15px;

            }





            .topbar {



                position: relative;



                height: auto;



                min-height: 75px;



                padding:

                    15px 20px;

            }





            .content {



                padding: 20px;

            }





            .renewal-grid {



                grid-template-columns:

                    1fr;

            }

        }





        @media (

            max-width: 600px

        ) {



            .summary-grid {



                grid-template-columns:

                    1fr;

            }





            .quick-grid {



                grid-template-columns:

                    1fr;

            }





            .topbar {



                display: block;

            }





            .admin-info {



                margin-top:

                    15px;

            }





            .content {



                padding:

                    15px;

            }





            .chart-container {



                height:

                    260px;

            }

        }



    </style>



<link rel="stylesheet" href="admin-theme.css">



<!-- Shared TMU header and navigation; refresh versions when the shared files change. -->

<script src="admin-header.js?v=<?= (int) @filemtime(__DIR__.'/admin-header.js') ?>" data-tq-header="1" defer></script>

<script src="admin-nav.js?v=<?= (int) @filemtime(__DIR__.'/admin-nav.js') ?>" defer></script>

<style>

#dashboardSummary{grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}

#dashboardSummary .summary-value{overflow-wrap:anywhere}

.renewal-grid .renewal-card:nth-child(1){border-top:3px solid #16a34a}.renewal-grid .renewal-card:nth-child(2){border-top:3px solid #facc15}.renewal-grid .renewal-card:nth-child(3){border-top:3px solid #dc2626}

.dashboard-complaint-counts{display:flex;gap:7px;flex-wrap:wrap;margin:0 0 10px}.dashboard-complaint-counts span{padding:4px 8px;border-radius:6px;font:13px Arial,Helvetica,sans-serif}.dashboard-count-pending{background:#fef9c3;color:#854d0e}.dashboard-count-resolved{background:#dcfce7;color:#166534}.dashboard-count-dismissed{background:#fee2e2;color:#991b1b}

.dashboard-chart-empty{display:flex;align-items:center;justify-content:center;min-height:180px;margin:0;padding:16px;color:#64748b;text-align:center;font:14px/21px Arial,Helvetica,sans-serif}

.dashboard-load-error{margin:0 0 12px;padding:12px;border:1px solid #fecaca;border-radius:8px;background:#fef2f2;color:#991b1b;font:14px/21px Arial,Helvetica,sans-serif}

@media(max-width:1100px){#dashboardSummary{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:380px){#dashboardSummary{grid-template-columns:1fr}}

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

        <a href="dashboard.php" class="active" aria-current="page">

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

                    Dashboard

                </div>



                <div class="page-subtitle">

                    TRIKE-Q TMU administrative overview

                </div>



            </div>





            <div class="admin-info">



                <div>



                    <div class="admin-name">

                        <?= e($adminName) ?>

                    </div>



                    <div class="admin-role">

                        TMU Administrator

                    </div>



                </div>





                <div class="admin-avatar">

                    <?= e($avatarLetter) ?>

                </div>



            </div>



        </header>





        <!-- =================================================

             CONTENT

             ================================================= -->



        <section class="content">

<?php foreach($data['errors'] as $loadError): ?><p class="dashboard-load-error" role="alert"><?=e($loadError)?></p><?php endforeach; ?>

<div class="summary-grid" id="dashboardSummary">

<?php foreach($metrics as [$label,$value,$caption,$ready,$money]): ?><div class="summary-card"><div class="summary-label"><?=e($label)?></div><div class="summary-value"><?=dashboardNumber($value,$ready,$money)?></div><div class="summary-small"><?=e($caption)?></div></div><?php endforeach; ?>

</div>

<!-- =================================================

                 RENEWAL OVERVIEW

                 ================================================= -->



            <div class="section-title">

                Franchise Renewal Overview

            </div>





            <div class="renewal-grid">





                <div class="renewal-card">



                    <div class="renewal-label">

                        Valid Franchises

                    </div>



                    <div class="renewal-value">

                        <?=dashboardNumber($validRenewals,$data['ready']['drivers'])?>

                    </div>



                    <div class="renewal-description">

                        More than 30 days remaining

                    </div>



                </div>





                <div class="renewal-card">



                    <div class="renewal-label">

                        Due Soon

                    </div>



                    <div class="renewal-value">

                        <?=dashboardNumber($dueSoonRenewals,$data['ready']['drivers'])?>

                    </div>



                    <div class="renewal-description">

                        Renewal due within 30 days

                    </div>



                </div>





                <div class="renewal-card">



                    <div class="renewal-label">

                        Expired

                    </div>



                    <div class="renewal-value">

                        <?=dashboardNumber($expiredRenewals,$data['ready']['drivers'])?>

                    </div>



                    <div class="renewal-description">

                        Franchise or MTOP already expired

                    </div>



                </div>





            </div>





            <!-- =================================================

                 RENEWAL ALERT

                 ================================================= -->



            <?php if (

                $renewalAttention > 0

            ): ?>



                <div class="alert">



                    <div class="alert-title">

                        Franchise Renewal Attention

                    </div>



                    <div class="alert-text">



                        There are



                        <strong>

                            <?= $expiredRenewals ?>

                        </strong>



                        expired franchise record(s) and



                        <strong>

                            <?= $dueSoonRenewals ?>

                        </strong>



                        record(s) due for renewal within



                        <strong>

                            30 days

                        </strong>.



                    </div>





                    <a

                        href="renewals.php"

                        class="orange-button"

                    >

                        View Franchise Renewals

                    </a>



                </div>



            <?php endif; ?>





            <!-- =================================================

                 SYSTEM ANALYTICS

                 ================================================= -->



            <div class="section-title">

                System Analytics

            </div>





            <div class="analytics-grid">

<div class="chart-card full"><div class="chart-header"><div class="chart-title">Drivers by TODA</div><div class="chart-description">Number of registered driver records per organization.</div></div><div class="chart-container"><canvas id="driversTODAChart" role="img" aria-label="Drivers by TODA"></canvas></div></div>

<div class="chart-card"><div class="chart-header"><div class="chart-title">Driver Status</div><div class="chart-description">Current active and inactive driver distribution.</div></div><div class="chart-container"><canvas id="driverStatusChart" role="img" aria-label="Driver Status"></canvas></div></div>

<div class="chart-card"><div class="chart-header"><div class="chart-title">Franchise Renewal Status</div><div class="chart-description">Green: valid · Yellow: due soon · Red: expired.</div></div><div class="chart-container"><canvas id="renewalChart" role="img" aria-label="Franchise Renewal Status"></canvas></div></div>

<div class="chart-card"><div class="chart-header"><div class="chart-title">Complaint Status</div><div class="chart-description">Current Pending, Resolved, and Dismissed complaints.</div></div><div class="dashboard-complaint-counts"><?php foreach($overview['complaint_status'] as $status=>$count): ?><span class="dashboard-count-<?=strtolower($status)?>"><?=e($status)?>: <?=$data['ready']['complaints']?number_format($count):'—'?></span><?php endforeach; ?></div><div class="chart-container"><canvas id="complaintChart" role="img" aria-label="Complaint Status"></canvas></div></div>





<div class="chart-card"><div class="chart-header"><div class="chart-title">Complaint / Violation Types</div><div class="chart-description">Each selected type is counted once per complaint.</div></div><div class="chart-container"><canvas id="complaintTypeChart" role="img" aria-label="Complaint / Violation Types"></canvas></div></div>

</div>

<!-- =================================================

                 WELCOME

                 ================================================= -->



            <div class="chart-card">



                <div class="chart-header">



                    <div class="chart-title">

                        TRIKE-Q Web-Based Management System

                    </div>



                    <div class="chart-description">



                        This dashboard provides TMU administrators

                        with access to driver registration, TODA

                        organizations, passenger complaints,

                        franchise renewal monitoring,

                        and reports.



                    </div>



                </div>



            </div>





            <!-- =================================================

                 QUICK ACCESS

                 ================================================= -->



            <div class="section-title">

                Quick Access

            </div>





            <div class="quick-grid">





                <a

                    href="register.php"

                    class="quick-card"

                >



                    <h3>

                        Register Driver

                    </h3>



                    <p>

                        Register a new tricycle driver

                        and assign the driver's TODA

                        organization.

                    </p>



                </a>





                <a

                    href="renewals.php"

                    class="quick-card"

                >



                    <h3>

                        Franchise Renewal

                    </h3>



                    <p>

                        Monitor franchise and MTOP

                        expiration dates and renewal status.

                    </p>



                </a>





                <a

                    href="complaints.php"

                    class="quick-card"

                >



                    <h3>

                        Complaints & Violations

                    </h3>



                    <p>

                        Review passenger complaints

                        and update their status and remarks.

                    </p>



                </a>





                





                <a

                    href="drivers.php"

                    class="quick-card"

                >



                    <h3>

                        Driver Management

                    </h3>



                    <p>

                        View registered drivers,

                        MTOP, tricycle information,

                        and status.

                    </p>



                </a>





                <a

                    href="toda.php"

                    class="quick-card"

                >



                    <h3>

                        TODA Organizations

                    </h3>



                    <p>

                        Add and manage registered

                        TODA organizations.

                    </p>



                </a>





            </div>





        </section>



    </main>



</div>





<script id="dashboardChartData" type="application/json"><?=json_encode($chartData,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE)?></script>

<script>(function(){

 const charts=JSON.parse(document.getElementById('dashboardChartData').textContent);

 Object.entries(charts).forEach(([id,config])=>{

  const canvas=document.getElementById(id);if(!canvas)return;

  if(!config.ready || typeof Chart==='undefined' || !config.values.length || config.values.every(value=>Number(value)===0)){

   const message=document.createElement('p');message.className='dashboard-chart-empty';

   message.textContent=!config.ready?'This data could not be loaded.':typeof Chart==='undefined'?'Charts are unavailable. Check your connection and refresh.':'No records to display yet.';

   canvas.replaceWith(message);return;

  }

  const dataset={label:config.label||'',data:config.values,backgroundColor:config.type==='line'?'rgba(249,115,22,.12)':config.colors,borderColor:config.type==='doughnut'?'#FFFFFF':'#F97316',borderWidth:config.type==='doughnut'?2:1};

  if(config.type==='line'){dataset.fill=true;dataset.tension=.25;dataset.pointRadius=3;dataset.borderWidth=2;}

  if(config.type==='bar')dataset.borderRadius=5;

  const options={responsive:true,maintainAspectRatio:false,plugins:{legend:{display:config.type==='doughnut',position:'bottom'}}};

  if(config.type!=='doughnut')options.scales={y:{beginAtZero:true,ticks:config.money?{callback:value=>'₱'+Number(value).toLocaleString('en-PH')}:{precision:0}}};

  new Chart(canvas,{type:config.type,data:{labels:config.labels,datasets:[dataset]},options});

 });

})();

</script>



</body>



</html>
