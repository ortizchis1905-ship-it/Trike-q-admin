<?php

session_start();

require_once __DIR__.'/config/supabase.php';

require_once __DIR__.'/tmu-tools.php';

require_once __DIR__.'/includes/driver-fields.php';

tqRequireAdmin();

function e($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}

$id=is_string($_GET['id']??null)?$_GET['id']:'';

if(!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',$id)){http_response_code(400);exit('Invalid driver.');}

$result=supabaseRequest('drivers','GET',null,http_build_query(['select'=>'*','id'=>'eq.'.$id],'','&',PHP_QUERY_RFC3986));

if(empty($result['success'])||empty($result['data'][0])){http_response_code(404);exit('Driver could not be loaded.');}

$driver=$result['data'][0];

$storedDriver=$driver;

$todaResult=tqFetchAll('toda_organizations','select=id,name,address,status&order=name.asc,id.asc');

$todaLoadOk=!empty($todaResult['success'])&&is_array($todaResult['data']??null);

$todaOptions=[];$todaMap=[];

foreach($todaLoadOk?$todaResult['data']:[] as $organization){

 $organizationId=(string)($organization['id']??'');if($organizationId==='')continue;

 $todaMap[$organizationId]=$organization;

 $active=in_array($organization['status']??false,[true,'true',1,'1'],true);

 if($active||$organizationId===($storedDriver['toda_id']??''))$todaOptions[$organizationId]=$organization;

}



$editFields=['name'=>'Full Name','phone_number'=>'Contact Number','email'=>'Email (Optional)','tricycle_number'=>'Tricycle Number','plate_number'=>'Plate Number','mtop_number'=>'MTOP Number','motorcycle_brand'=>'Motorcycle Brand','engine_number'=>'Engine Number','chassis_number'=>'Chassis Number'];

if(empty($_SESSION['driver_edit_csrf']))$_SESSION['driver_edit_csrf']=bin2hex(random_bytes(32));

if(empty($_SESSION['driver_photo_csrf']))$_SESSION['driver_photo_csrf']=bin2hex(random_bytes(32));

$editing=($_GET['edit']??'')==='1'||$_SERVER['REQUEST_METHOD']==='POST';

$error='';

if(isset($_GET['photo_warning'])){$editing=true;$error='The driver record is saved, but the profile photo was not saved. Choose the photo again below.';}

if($_SERVER['REQUEST_METHOD']==='POST'){

 $submittedToda=tqDriverText($_POST,'toda_id');

 $data=[];foreach($editFields as $field=>$label)$data[$field]=tqDriverText($_POST,$field);

 try{

  if(!hash_equals($_SESSION['driver_edit_csrf'],(string)($_POST['csrf']??'')))throw new RuntimeException('Form expired. Refresh and try again.');

  if($data['name']==='')throw new RuntimeException('Full name is required.');

  if(!preg_match('/^09[0-9]{9}$/',$data['phone_number']))throw new RuntimeException('Use an 11-digit contact number starting with 09.');

  if($data['email']!==''&&!filter_var($data['email'],FILTER_VALIDATE_EMAIL))throw new RuntimeException('Enter a valid email address or leave it blank.');

  if(!$todaLoadOk)throw new RuntimeException('Unable to verify TODA organizations. Refresh the page and try again.');

  if(!isset($todaOptions[$submittedToda]))throw new RuntimeException('Choose an available TODA organization.');

  $data=array_merge($data,tqDriverExtraValues($_POST));

  $data['toda_id']=$submittedToda;

  $data['email']=$data['email']===''?null:$data['email'];

  $saved=supabaseRequest('drivers','PATCH',$data,http_build_query(['id'=>'eq.'.$id],'','&',PHP_QUERY_RFC3986));

  if(empty($saved['success'])||empty($saved['data'][0]['id']))throw new RuntimeException('Unable to confirm the update. '.($saved['error']??'Check the fields and your permissions.'));

  header('Location: driver_view.php?id='.rawurlencode($id).'&saved=1',true,303);exit;

 }catch(RuntimeException $exception){$error=$exception->getMessage();}

 $driver=array_merge($driver,$data);$driver['toda_id']=$submittedToda;

 foreach(['license_number','license_expiry_date','birthdate','province_code','city_code','barangay_code','street'] as $field)$driver[$field]=tqDriverText($_POST,$field);

}

$editValues=$driver;

$driver=$storedDriver;

$org=$todaMap[$driver['toda_id']??'']??[];

if(!$org&&!empty($driver['toda_id'])){

 $orgResult=supabaseRequest('toda_organizations','GET',null,http_build_query(['select'=>'name,address','id'=>'eq.'.$driver['toda_id']],'','&',PHP_QUERY_RFC3986));

 if(!empty($orgResult['success']))$org=$orgResult['data'][0]??[];

}

$driver['toda_name']=$org['name']??'Not assigned';$driver['terminal_address']=$org['address']??'';

$driver['age']='';

$zone=new DateTimeZone('Asia/Manila');$today=new DateTimeImmutable('today',$zone);

$raw=is_string($driver['birthdate']??null)?$driver['birthdate']:'';

$birth=$raw!==''?DateTimeImmutable::createFromFormat('!Y-m-d',$raw,$zone):false;

if($birth&&$birth->format('Y-m-d')===$raw&&$birth<$today)$driver['age']=(string)$birth->diff($today)->y;

$groups=[

 'Personal Information'=>['name'=>'Full Name','birthdate'=>'Birthdate','age'=>'Age','phone_number'=>'Contact Number','email'=>'Email','address'=>'Home Address'],

 'Driver’s License'=>['license_number'=>'License Number','license_expiry_date'=>'License Expiry Date'],

 'TODA and Registration'=>['application_number'=>'Application Number','previous_application_number'=>'Previous Application Number','toda_name'=>'TODA Organization','terminal_address'=>'Terminal Address','position'=>'Position','status'=>'Driver Status','created_at'=>'Registered At'],

 'Vehicle Information'=>['tricycle_number'=>'Tricycle Number','plate_number'=>'Plate Number','mtop_number'=>'MTOP Number','motorcycle_brand'=>'Motorcycle Brand','engine_number'=>'Engine Number','chassis_number'=>'Chassis Number'],

 'Franchise and Renewal'=>['franchise_expiry_date'=>'Franchise Expiry Date','last_renewal_date'=>'Last Renewal Date','renewal_status'=>'Stored Renewal Status']

];

function driverDisplay($value,string $field): string {

 if($value===null||$value==='')return 'Not provided';

 if(in_array($field,['birthdate','license_expiry_date','franchise_expiry_date','last_renewal_date'],true)){

  $date=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$value);

  if($date&&$date->format('Y-m-d')===$value)return $date->format('M d, Y');

 }

 if($field==='created_at'){try{return (new DateTimeImmutable((string)$value))->setTimezone(new DateTimeZone('Asia/Manila'))->format('M d, Y');}catch(Exception $exception){}}

 return (string)$value;

}

$adminName='TMU Admin';

$admin=supabaseRequest('admin','GET',null,http_build_query(['select'=>'name','user_id'=>'eq.'.($_SESSION['user_id']??'')],'','&',PHP_QUERY_RFC3986));

if(!empty($admin['success'])&&!empty($admin['data'][0]['name']))$adminName=$admin['data'][0]['name'];

$archived=!empty($driver['archived_at']);

header('Cache-Control: private, no-store');

?>

<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Driver’s Information | TRIKE-Q TMU Admin</title>

<style>

*{box-sizing:border-box}html,body{margin:0;min-height:100%;font-family:Arial,Helvetica,sans-serif;background:#f8fafc;color:#1f2937}.sidebar{width:250px;background:#1f1f1f;color:#fff;position:fixed;inset:0 auto 0 0;overflow-y:auto;padding:24px 16px;z-index:10}.logo{text-align:center;font-size:25px;font-weight:800;color:#f97316;margin-bottom:5px}.logo-subtitle{text-align:center;font-size:12px;color:#9ca3af;margin-bottom:30px}.menu-title{font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:1px;margin:20px 10px 8px}.sidebar a{display:flex;align-items:center;gap:10px;color:#d1d5db;text-decoration:none;padding:12px 13px;margin-bottom:4px;border-radius:8px;font-size:14px;transition:.2s}.sidebar a:hover{background:#2d2d2d;color:#fff}.sidebar a.active{background:#f97316;color:#fff;font-weight:bold}.icon{width:20px;text-align:center;font-size:16px}.main{margin-left:250px;min-height:100vh}.topbar{height:75px;background:#fff;border-bottom:1px solid #e5e7eb;padding:0 30px;display:flex;justify-content:space-between;gap:20px;align-items:center;position:sticky;top:0;z-index:50}.page-title{font-size:23px;font-weight:800;color:#9a3412}.page-subtitle{font-size:15px;color:#64748b;margin-top:5px}.admin-name{font-weight:700;color:#111827}.admin-role{font-size:12px;color:#64748b;margin-top:3px}.content{padding:30px;max-width:1400px}.back{color:#c2410c;text-decoration:none;font-weight:700;display:inline-block;margin-bottom:17px}.back:hover{text-decoration:underline}.application{font-size:14px;color:#64748b;margin:0 0 16px}.card{background:#fff;border:1px solid transparent;border-radius:12px;padding:22px;box-shadow:0 2px 8px rgba(0,0,0,.06);box-shadow:0 3px 12px rgba(15,23,42,.05)}.card-title{font-size:21px;font-weight:800;color:#9a3412;margin:0 0 5px}.card-subtitle{color:#64748b;margin:0 0 22px}.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px 22px}.field label{display:block;font-size:13px;font-weight:700;color:#1f2937;margin-bottom:8px}.field input{width:100%;height:50px;padding:0 14px;border:1px solid #cbd5e1;border-radius:9px;font-size:15px;color:#1f2937;outline:none}.field input:focus{border-color:#f97316;box-shadow:0 0 0 3px rgba(249,115,22,.13)}.helper{margin:20px 0 0;color:#64748b;font-size:13px;line-height:1.5}.actions{display:flex;align-items:center;gap:14px;margin-top:22px}.button{display:inline-block;border:0;border-radius:8px;padding:12px 20px;font-weight:700;font-size:14px;text-decoration:none;cursor:pointer}.primary{background:#f97316;color:#fff}.primary:hover{background:#ea580c}.cancel{color:#c2410c}.alert{padding:13px 15px;border-radius:9px;margin-bottom:18px;font-size:14px}.alert.error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}.alert.success{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534}@media(max-width:780px){.sidebar{position:relative;width:100%;height:auto}.main{margin-left:0}.topbar{padding:22px 18px;align-items:flex-start}.content{padding:20px 16px 30px}.form-grid{grid-template-columns:1fr}.admin-info{display:none}}

</style><link rel="stylesheet" href="admin-theme.css">



<link rel="stylesheet" href="assets/driver-fields.css?v=<?= (int) @filemtime(__DIR__.'/assets/driver-fields.css') ?>"><style>

body.tq-driver-view .main>.content{max-width:none;width:100%;padding:18px 26px}

.tq-bio-actions{display:flex;justify-content:flex-end;align-items:center;gap:8px;flex-wrap:wrap;margin:0 0 14px}

.tq-bio-actions button{cursor:pointer}

body #driverBiodata{padding:0 4px!important;margin:0!important;border:0!important;border-radius:0!important;background:transparent!important;box-shadow:none!important;color:#1f2937;font-family:Arial,Helvetica,sans-serif}

#driverBiodata .tq-bio-heading{display:flex;justify-content:space-between;align-items:center;gap:22px;margin:0 0 16px;padding:0 0 16px;border-bottom:1px solid #dbe2ea}

#driverBiodata .tq-bio-heading-copy{flex:1;text-align:left}

#driverBiodata .tq-bio-system{margin:0 0 7px;color:#64748b;font:400 12px/1.4 Arial,Helvetica,sans-serif;letter-spacing:.3px}

#driverBiodata .tq-bio-heading h1{margin:0 0 8px;color:#1f2937;font:600 25px/1.25 Arial,Helvetica,sans-serif;letter-spacing:0}

#driverBiodata .tq-bio-application{margin:0 0 5px;color:#475569;font:400 14px/1.5 Arial,Helvetica,sans-serif}

#driverBiodata .tq-bio-application strong{font-weight:500}

#driverBiodata .tq-bio-name{margin:0;color:#1f2937;font:500 18px/1.4 Arial,Helvetica,sans-serif}

#driverBiodata .tq-bio-photo{display:block!important;width:104px;height:112px;border:0!important;border-radius:6px!important;flex-shrink:0;overflow:hidden}

#driverBiodata .tq-bio-photo img{display:block;width:100%;height:100%;object-fit:cover}

#driverBiodata .tq-bio-placeholder{display:flex!important;align-items:center;justify-content:center;text-align:center;background:transparent;color:#64748b;font:400 12px/1.6 Arial,Helvetica,sans-serif}

#driverBiodata .tq-info-section{margin:0;padding:0 0 14px;background:transparent!important;border:0!important;border-radius:0!important;box-shadow:none!important}

#driverBiodata .tq-info-section:last-child{padding-bottom:0}

#driverBiodata .tq-info-section h2{margin:0 0 8px;padding:0;color:#334155!important;background:transparent!important;font:600 17px/1.4 Arial,Helvetica,sans-serif;letter-spacing:0}

#driverBiodata .tq-info-details{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px 28px;margin:0;padding:0}

#driverBiodata .tq-info-row{display:grid;grid-template-columns:165px minmax(0,1fr);gap:10px;align-items:baseline;min-width:0;margin:0;padding:0;border:0;background:transparent}

#driverBiodata .tq-info-wide{grid-column:1/-1}

#driverBiodata dt{margin:0;padding:0;color:#64748b!important;background:transparent!important;font:400 14px/1.5 Arial,Helvetica,sans-serif}

#driverBiodata dd{margin:0;padding:0;color:#1f2937!important;font:400 16px/1.5 Arial,Helvetica,sans-serif;overflow-wrap:anywhere}

#driverBiodata .tq-bio-footer{margin:14px 0 0;padding:10px 0 0;border-top:1px solid #dbe2ea;color:#64748b;font:400 12px/1.4 Arial,Helvetica,sans-serif;text-align:right}

@media(max-width:1050px){#driverBiodata .tq-info-details{grid-template-columns:1fr}#driverBiodata .tq-info-wide{grid-column:auto}}

@media(max-width:600px){body.tq-driver-view .main>.content{padding:14px}#driverBiodata .tq-bio-heading{gap:12px}#driverBiodata .tq-bio-heading h1{font-size:22px}#driverBiodata .tq-bio-photo{width:78px;height:86px}#driverBiodata .tq-info-row{grid-template-columns:132px minmax(0,1fr);gap:8px}#driverBiodata dd{font-size:15px}#driverBiodata dt{font-size:13px}}

@page{size:A4 portrait;margin:12mm}

@media print{

 html,body,body.tq-driver-view{background:#fff!important;color:#111!important;margin:0!important;padding:0!important;min-height:0!important;height:auto!important;overflow:visible!important}

 .sidebar,.topbar,.back,[data-bio-no-print],.alert,dialog,.tq-driver-photo-dialog{display:none!important}

 body.tq-driver-view .main{margin:0!important;padding:0!important;min-height:0!important;width:100%!important;display:block!important;transform:none!important}

 body.tq-driver-view .main>.content{margin:0!important;padding:0!important;max-width:none!important;width:100%!important;display:block!important;overflow:visible!important}

 body #driverBiodata{margin:0!important;padding:0!important;width:100%!important;min-height:255mm;display:flex;flex-direction:column}

 #driverBiodata .tq-bio-heading{margin-bottom:10px;padding-bottom:10px;break-inside:avoid}

 #driverBiodata .tq-bio-heading h1{font-size:20pt}#driverBiodata .tq-bio-system{font-size:9pt}#driverBiodata .tq-bio-application{font-size:10pt}#driverBiodata .tq-bio-name{font-size:13pt}

 #driverBiodata .tq-bio-photo{width:25mm!important;height:28mm!important}

 #driverBiodata .tq-info-sections{flex:1;display:flex;flex-direction:column;justify-content:space-between;gap:12px}

 #driverBiodata .tq-info-section{padding-bottom:0;break-inside:avoid;page-break-inside:avoid}

 #driverBiodata .tq-info-section h2{font-size:12pt;margin-bottom:7px;break-after:avoid}

 #driverBiodata .tq-info-details{grid-template-columns:repeat(2,minmax(0,1fr));gap:7px 18px}

 #driverBiodata .tq-info-row{grid-template-columns:34mm minmax(0,1fr);gap:6px;break-inside:avoid;page-break-inside:avoid}

 #driverBiodata .tq-info-wide{grid-column:1/-1}

 #driverBiodata dt{font-size:10pt;line-height:1.5;color:#475569!important}#driverBiodata dd{font-size:11pt;line-height:1.5;color:#111827!important}

 #driverBiodata .tq-bio-footer{font-size:9pt;margin-top:14px;padding-top:9px;flex-shrink:0}

 a[href]::after{content:none!important}

}

</style><script src="assets/driver-fields.js?v=<?= (int) @filemtime(__DIR__.'/assets/driver-fields.js') ?>" defer></script><style>

/* The short Age label sits immediately beside its number. */

#driverBiodata .tq-info-row.tq-info-age{grid-template-columns:max-content max-content;justify-content:start;gap:8px}

body.tq-driver-view #driverEditPanel[hidden],body.tq-driver-view #driverInformationView[hidden],body.tq-driver-view #driverEditToggle[hidden]{display:none!important}

#driverEditPanel{margin-top:8px}

#driverEditPanel .tq-inline-heading{font:600 17px/1.4 Arial,Helvetica,sans-serif;color:#334155;margin:12px 0}

#driverEditPanel .form-grid{gap:14px 18px}

#driverEditPanel .field label{font:600 14px/1.4 Arial,Helvetica,sans-serif;color:#475569;margin-bottom:6px}

#driverEditPanel .tq-driver-fields{margin-top:16px}

.tq-inline-form-actions{display:flex;gap:10px;align-items:center;margin:16px 0}

.tq-inline-photo{border-bottom:1px solid #e2e8f0;padding:0 0 16px;margin:0 0 18px}

.tq-inline-photo label{display:block;font-size:14px;color:#475569;margin-bottom:8px}.tq-inline-photo input[type=file]{display:block;margin-bottom:8px;max-width:100%}.tq-inline-photo .helper{margin:8px 0 12px}

body.tq-driver-view.driver-is-editing .tq-bio-actions #printDriverBiodata{opacity:.5;cursor:not-allowed}

@media print{

 #driverBiodata .tq-info-row.tq-info-age{grid-template-columns:max-content max-content;justify-content:start;gap:6px}

 #driverEditPanel{display:none!important}

 body.tq-driver-view #driverInformationView[hidden]{display:flex!important}

}

</style>

<style id="driver-back-button-style">

body .content a.tq-back-button {

 display:inline-flex; align-items:center; justify-content:center; gap:7px;

 width:auto; min-height:38px; margin:0 0 16px; padding:8px 13px;

 border:1px solid #fdba74; border-radius:8px;

 background:#fff7ed; color:#9a3412;

 font:600 14px/1.4 Arial,Helvetica,sans-serif;

 text-decoration:none; vertical-align:middle; transition:background .15s,border-color .15s;

}

body .content a.tq-back-button:hover { background:#ffedd5; border-color:#f97316; color:#9a3412; text-decoration:none; }

body .content a.tq-back-button:focus-visible { outline:3px solid #f97316; outline-offset:3px; }

@media print { body .content a.tq-back-button { display:none!important; } }

</style>

<!-- Shared TMU header and navigation; refresh versions when the shared files change. -->

<script src="admin-header.js?v=<?= (int) @filemtime(__DIR__.'/admin-header.js') ?>" data-tq-header="1" defer></script>

<script src="admin-nav.js?v=<?= (int) @filemtime(__DIR__.'/admin-nav.js') ?>" defer></script>

<style>.field select{width:100%;height:50px;padding:0 14px;border:1px solid #cbd5e1;border-radius:9px;font:15px Arial,Helvetica,sans-serif;color:#1f2937;background:#fff}.field select:focus{outline:none;border-color:#f97316;box-shadow:0 0 0 3px rgba(249,115,22,.13)}</style><style>#driverEditPanel .tq-edit-toda-section{display:block!important;width:100%;grid-column:1/-1;margin:0 0 18px;padding:0 0 18px;border-bottom:1px solid #e2e8f0}#driverEditPanel .tq-edit-toda-section h3{margin:0 0 10px;color:#9a3412;font:600 17px Arial,Helvetica,sans-serif}#driverEditPanel .tq-edit-toda-section .field{display:block!important;visibility:visible!important}#driverEditPanel #edit_toda_id{display:block!important;visibility:visible!important;width:100%;min-height:50px;height:50px;font:16px Arial,Helvetica,sans-serif;background:#fff;color:#1f2937}</style></head><body class="tq-driver-view">

<aside class="sidebar">



    <div class="logo">TRIKE-Q</div>

    <div class="logo-subtitle">TMU ADMIN SYSTEM</div>



    <div class="menu-title">Main</div>

        <a href="dashboard.php">

            <span class="icon">▣</span>

            Dashboard

        </a>



        <a href="drivers.php" class="active" aria-current="page">

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

<main class="main"><header class="topbar"><div><div class="page-title">Driver Information</div><div class="page-subtitle">View the driver’s profile, registration and vehicle details.</div></div><div class="admin-info"><div class="admin-name"><?=e($adminName)?></div><div class="admin-role">TMU Administrator</div></div></header>

<section class="content">

<a class="back tq-back-button" href="drivers.php<?=$archived?'?archived=1':''?>">← Back to <?=$archived?'Archived Drivers':'Driver List'?></a>

<?php if(isset($_GET['saved'])): ?><p class="alert success" role="status">Driver information updated successfully.</p><?php endif; ?>

<?php if($archived): ?><p class="alert error">This driver is archived. Their linked history is retained.</p><?php endif; ?>

<div class="tq-bio-actions" data-bio-no-print>

<button class="tq-view-button primary" type="button" id="driverEditToggle" aria-controls="driverEditPanel" aria-expanded="<?=$editing?'true':'false'?>" <?=$editing?'hidden':''?>><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m16 3 5 5-12 12-6 1 1-6Z"/></svg>Edit</button>

<a class="tq-view-button" href="driver_qr.php?id=<?=e(urlencode($id))?>">QR Code</a>

<button class="tq-view-button" type="button" id="printDriverBiodata" onclick="window.print()"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9V3h12v6M6 17H3V9h18v8h-3M6 14h12v7H6z"/></svg>Print</button>

</div>

<article class="tq-biodata" id="driverBiodata" aria-labelledby="driverBiodataTitle">

<header class="tq-bio-heading">

<div class="tq-bio-heading-copy"><p class="tq-bio-system">TRIKE-Q · TMU ADMINISTRATOR RECORD</p><h1 id="driverBiodataTitle">Driver’s Information</h1><p class="tq-bio-application">Application No.: <strong><?=e($driver['application_number']??'Not provided')?></strong></p><p class="tq-bio-name"><?=e($driver['name']??'Driver')?></p></div>

<?php if(!empty($driver['profile_picture_url'])): ?><a class="tq-driver-photo-link tq-bio-photo" data-driver-photo data-driver-name="<?=e($driver['name'])?>" href="<?=e($driver['profile_picture_url'])?>" target="_blank" rel="noopener" aria-label="View driver profile picture"><img src="<?=e($driver['profile_picture_url'])?>" alt="<?=e($driver['name'])?> profile picture" width="104" height="112"></a><?php else: ?><div class="tq-bio-photo tq-bio-placeholder">PHOTO<br>Not provided</div><?php endif; ?>

</header>

<div class="tq-info-sections" id="driverInformationView" <?=$editing?'hidden':''?>>

<?php foreach($groups as $title=>$columns): ?>

<section class="tq-info-section"><h2><?=e($title)?></h2><dl class="tq-info-details">

<?php foreach($columns as $field=>$label): ?>

<div class="tq-info-row <?=in_array($field,['name','address','email','terminal_address'],true)?'tq-info-wide':($field==='age'?'tq-info-age':'')?>"><dt><?=e($label)?></dt><dd><?=e(driverDisplay($driver[$field]??null,$field))?></dd></div>

<?php endforeach; ?>

</dl></section>

<?php endforeach; ?>

</div>

<div id="driverEditPanel" data-edit-panel <?=$editing?'':'hidden'?>>

<?php if($error): ?><p class="alert error" role="alert"><?=e($error)?></p><?php endif; ?>

<?php if(empty($driver['province_code'])): ?><p class="helper">Previous address: <?=e($driver['address']??'')?>. Select its province, municipality/city and barangay below.</p><?php endif; ?>

<section class="tq-inline-photo"><h2 class="tq-inline-heading">Change Profile Picture</h2>

<?php if(isset($_GET['photo_saved'])): ?><p class="alert success" role="status">Profile picture updated.</p><?php endif; ?>

<form method="post" action="driver_profile_upload.php?id=<?=e(rawurlencode($id))?>" enctype="multipart/form-data">

<input type="hidden" name="driver_id" value="<?=e($id)?>"><input type="hidden" name="return_to_edit" value="1"><input type="hidden" name="photo_csrf" value="<?=e($_SESSION['driver_photo_csrf'])?>">

<label for="inlineDriverPhoto">Profile Picture (Optional)</label><input id="inlineDriverPhoto" type="file" name="profile_picture" accept="image/jpeg,image/png,image/webp" required>

<p class="helper">JPG, PNG or WEBP, up to 5 MB. Upload saves the photo separately.</p><button class="tq-view-button" type="submit">Upload Photo</button>

</form></section>

<form method="post" action="driver_view.php?id=<?=e(rawurlencode($id))?>" id="driverInformationForm">

<input type="hidden" name="csrf" value="<?=e($_SESSION['driver_edit_csrf'])?>">

<h2 class="tq-inline-heading">Edit Driver’s Information</h2>

<?php if(!$todaLoadOk): ?><p class="alert error" role="alert">TODA organizations could not be loaded. Refresh before saving changes.</p><?php endif; ?>



<div class="form-grid"><?php foreach($editFields as $field=>$label): ?><div class="field"><label for="edit_<?=e($field)?>"><?=e($label)?></label><input id="edit_<?=e($field)?>" name="<?=e($field)?>" type="<?=$field==='email'?'email':'text'?>" value="<?=e($editValues[$field]??'')?>" <?=in_array($field,['name','phone_number'],true)?'required':''?>></div><?php endforeach; ?></div>

<?php tqDriverFields($editValues); ?>

<section class="tq-edit-toda-section" aria-labelledby="editTodaHeading"><h3 id="editTodaHeading">TODA Organization</h3><div class="field"><label for="edit_toda_id">Select TODA organization</label><select id="edit_toda_id" name="toda_id" required <?=!$todaLoadOk?'disabled':''?>><option value="">Choose a TODA organization</option><?php foreach($todaOptions as $organizationId=>$organization): ?><option value="<?=e($organizationId)?>" <?=$organizationId===($editValues['toda_id']??'')?'selected':''?>><?=e($organization['name']??'Unnamed TODA')?><?=in_array($organization['status']??false,[true,'true',1,'1'],true)?'':' (Inactive — current organization)'?></option><?php endforeach; ?></select></div></section>

<div class="tq-inline-form-actions"><button class="tq-view-button primary" type="submit">Save Changes</button><button class="tq-view-button" type="button" data-cancel-driver-edit>Cancel</button></div>

</form>



</div>

<footer class="tq-bio-footer">Generated <?=e((new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('M d, Y · h:i A'))?> · TRIKE-Q Driver Management</footer>

</article>

</section></main>

<style>

.tq-driver-photo-link{display:inline-block;vertical-align:middle;border-radius:8px;cursor:zoom-in}

.tq-driver-photo-link:focus-visible{outline:3px solid #f97316;outline-offset:3px}

.tq-driver-photo-dialog{position:fixed!important;top:0!important;right:0!important;bottom:0!important;left:0!important;transform:none!important;margin:0!important;width:100vw!important;height:100vh!important;height:100dvh!important;max-width:none!important;max-height:none!important;box-sizing:border-box!important;padding:16px!important;border:0!important;border-radius:0!important;background:transparent!important;box-shadow:none!important;font-family:Arial,Helvetica,sans-serif;color:#334155}

.tq-driver-photo-dialog[open]{display:flex!important;align-items:center!important;justify-content:center!important;flex-direction:row!important;overflow:hidden!important}

.tq-driver-photo-panel{position:fixed!important;left:50vw!important;top:50vh!important;top:50dvh!important;right:auto!important;bottom:auto!important;transform:translate(-50%,-50%)!important;box-sizing:border-box!important;width:min(740px,calc(100vw - 32px))!important;max-height:calc(100vh - 32px);max-height:calc(100dvh - 32px);margin:0!important;padding:18px!important;overflow:auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 12px 40px #0003}

.tq-driver-photo-dialog::backdrop{background:rgba(15,23,42,.72)}

.tq-driver-photo-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}

.tq-driver-photo-title{margin:0;color:#9a3412;font-size:20px;line-height:1.4;font-weight:600}

.tq-driver-photo-close{border:0;border-radius:8px;background:#f97316;color:white;font:600 15px Arial;padding:10px 16px;cursor:pointer}

.tq-driver-photo-full{display:block;width:100%;height:auto;max-height:70vh;min-height:0;flex-shrink:1;object-fit:contain;object-position:center;background:#f8fafc;border-radius:8px}

.tq-driver-photo-error{color:#991b1b;background:#fef2f2;padding:12px;border-radius:8px;font:15px/1.5 Arial}

.tq-driver-photo-dialog [hidden]{display:none!important}

</style>

<dialog id="tqDriverPhotoViewer" class="tq-driver-photo-dialog" aria-labelledby="tqDriverPhotoTitle">

<div class="tq-driver-photo-panel">

<div class="tq-driver-photo-toolbar"><h2 id="tqDriverPhotoTitle" class="tq-driver-photo-title">Driver Profile Picture</h2><button type="button" class="tq-driver-photo-close" autofocus>Close</button></div>

<img class="tq-driver-photo-full" alt="Driver profile picture">

<p class="tq-driver-photo-error" role="alert" hidden>The photo could not be loaded. Check the saved image URL or upload the photo again from Edit Driver.</p>

</div>

</dialog>

<script>

(function () {

    const dialog = document.getElementById('tqDriverPhotoViewer');

    if (!dialog || typeof dialog.showModal !== 'function') return;

    const image = dialog.querySelector('img');

    const message = dialog.querySelector('[role="alert"]');

    const title = dialog.querySelector('h2');

    let trigger;

    document.addEventListener('click', function (event) {

        const link = event.target.closest('a[data-driver-photo]');

        if (!link) return;

        event.preventDefault(); trigger = link;

        title.textContent = link.dataset.driverName || 'Driver Profile Picture';

        image.alt = title.textContent + ' profile picture';

        message.hidden = true; image.hidden = false;

        image.src = link.href;

        if (!dialog.open) dialog.showModal();

        // Inline priority keeps site-wide popup CSS from changing this position.

        const panel = dialog.querySelector('.tq-driver-photo-panel');

        const centered = {position:'fixed',left:'50vw',top:'50vh',right:'auto',bottom:'auto',transform:'translate(-50%, -50%)',margin:'0',width:'min(740px, calc(100vw - 32px))',maxHeight:'calc(100vh - 32px)',boxSizing:'border-box'};

        for (const [property, value] of Object.entries(centered)) {

            const cssProperty = property.replace(/[A-Z]/g, letter => '-' + letter.toLowerCase());

            panel.style.setProperty(cssProperty, value, 'important');

        }

    });

    image.addEventListener('error', function () { image.hidden = true; message.hidden = false; });

    dialog.querySelector('button').addEventListener('click', function () { dialog.close(); });

    dialog.addEventListener('click', function (event) {

        if (event.target === dialog) dialog.close();

    });

    dialog.addEventListener('close', function () { image.removeAttribute('src'); if (trigger?.isConnected) trigger.focus(); });

})();

</script>



<script>

// Legacy Print links open this same biodata. The visible Print button always

// works directly from a user click, without depending on another PHP page.

if(new URLSearchParams(window.location.search).get('print')==='1'){

 const runPrint=async()=>{

  const images=Array.from(document.querySelectorAll('#driverBiodata img'));

  await Promise.race([Promise.all(images.map(img=>img.complete?Promise.resolve():new Promise(resolve=>{img.addEventListener('load',resolve,{once:true});img.addEventListener('error',resolve,{once:true});}))),new Promise(resolve=>setTimeout(resolve,3000))]);

  const clean=new URL(window.location.href);clean.searchParams.delete('print');history.replaceState(null,'',clean.pathname+clean.search);

  requestAnimationFrame(()=>requestAnimationFrame(()=>window.print()));

 };

 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',runPrint,{once:true});else runPrint();

}

</script><script>

(()=>{

 const button=document.getElementById('driverEditToggle');

 const panel=document.getElementById('driverEditPanel');

 const details=document.getElementById('driverInformationView');

 const form=document.getElementById('driverInformationForm');

 const print=document.getElementById('printDriverBiodata');

 const originalAddress=Array.from(form.querySelectorAll('select[data-selected]')).map(select=>[select,select.dataset.selected]);

 const setEditing=editing=>{

  panel.hidden=!editing;details.hidden=editing;button.hidden=editing;

  button.setAttribute('aria-expanded',String(editing));document.body.classList.toggle('driver-is-editing',editing);

  print.disabled=editing;

 };

 button.addEventListener('click',()=>{setEditing(true);const first=form.querySelector('input:not([type=hidden])');if(first)first.focus({preventScroll:true});});

 panel.querySelector('[data-cancel-driver-edit]').addEventListener('click',()=>{form.reset();for(const [select,value] of originalAddress){select.dataset.selected=value;select.value='';}const retry=form.querySelector('[data-address-retry]');if(retry)retry.click();const photo=panel.querySelector('input[type=file]');if(photo)photo.value='';setEditing(false);button.focus({preventScroll:true});});

 setEditing(!panel.hidden);

})();

</script></body></html>


