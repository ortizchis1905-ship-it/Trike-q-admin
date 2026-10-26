<?php

// =====================================================
// TRIKE-Q DRIVER MANAGEMENT
// =====================================================

session_start();

require_once __DIR__ . "/config/supabase.php";
require_once __DIR__ . '/tmu-tools.php';
tqRequireAdmin();
$tqShowArchived = ($_GET['archived'] ?? '') === '1';
$tqDriverNotice = $_SESSION['driver_record_notice'] ?? '';
unset($_SESSION['driver_record_notice']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tqRequireCsrf();
    $action = is_string($_POST['record_action'] ?? null) ? $_POST['record_action'] : '';
    $recordId = is_string($_POST['driver_id'] ?? null) ? $_POST['driver_id'] : '';
    if (!in_array($action,['archive','restore','delete','activate','deactivate'],true) || !preg_match('/^[a-f0-9-]{36}$/i',$recordId)) {
        $tqDriverNotice = 'Invalid driver action.';
    } elseif (in_array($action, ['activate','deactivate'], true)) {
        $currentResult = supabaseRequest('drivers','GET',null,http_build_query([
            'select'=>'id,status,archived_at','id'=>'eq.'.$recordId
        ],'', '&', PHP_QUERY_RFC3986));
        $currentDriver = !empty($currentResult['success']) ? ($currentResult['data'][0] ?? null) : null;
        $nextStatus = $action === 'activate' ? 'Active' : 'Inactive';
        if (!$currentDriver) {
            $tqDriverNotice = $currentResult['error'] ?? 'Driver could not be verified. Refresh and try again.';
        } elseif (!empty($currentDriver['archived_at'])) {
            $tqDriverNotice = 'Restore this archived driver first, then review the license before activation.';
        } elseif (strcasecmp(trim((string)$currentDriver['status']), $nextStatus) === 0) {
            $tqDriverNotice = 'Driver is already '.strtolower($nextStatus).'.';
        } else {
            $actionResult = supabaseRequest('rpc/trikeq_president_set_driver_status','POST',[
                'p_driver_id'=>$recordId,'p_status'=>$nextStatus
            ]);
            $tqDriverNotice = !empty($actionResult['success']) && $actionResult['data'] === true
                ? 'Driver '.($action === 'activate' ? 'activated' : 'deactivated').' successfully.'
                : ($actionResult['error'] ?? 'The status update could not be confirmed. Refresh before retrying.');
        }
    } else {
        $actionResult = supabaseRequest('rpc/trikeq_driver_record_action','POST',['p_driver_id'=>$recordId,'p_action'=>$action]);
        $tqDriverNotice = !empty($actionResult['success']) && $actionResult['data'] === true
            ? ($action === 'delete' ? 'Driver permanently deleted.' : ($action === 'restore' ? 'Driver restored as Inactive. Review the license before activation.' : 'Driver archived as Inactive. Related history is preserved.'))
            : ($actionResult['error'] ?? 'The driver action could not be confirmed.');
    }
    $_SESSION['driver_record_notice'] = $tqDriverNotice;
    header('Location: drivers.php'.($tqShowArchived?'?archived=1':''),true,303); exit;
}



// =====================================================
// CHECK LOGIN
// =====================================================

if (
    !isset($_SESSION["access_token"]) ||
    $_SESSION["access_token"] === "" ||
    !isset($_SESSION["user_id"]) ||
    $_SESSION["user_id"] === ""
) {
    header("Location: login.php");
    exit;
}


// =====================================================
// GET ADMIN INFORMATION
// =====================================================

$adminName = "TMU Admin";

$adminResult = supabaseRequest(
    "admin",
    "GET",
    null,
    "user_id=eq." .
    urlencode($_SESSION["user_id"]) .
    "&select=*"
);

if (
    $adminResult["status"] >= 200 &&
    $adminResult["status"] < 300 &&
    !empty($adminResult["data"])
) {
    $adminName =
        $adminResult["data"][0]["name"]
        ?? "TMU Admin";
}


// =====================================================
// GET DRIVERS
// =====================================================

$result = tqFetchAll("drivers", "select=*&archived_at=".($tqShowArchived?"not.is.null":"is.null")."&order=created_at.desc,id.asc");
// Normalize the helper result and surface transport/Auth/PostgREST errors.
$driverHttpStatus = (int)($result['status'] ?? 0);
$driverLoadFailed = !in_array($driverHttpStatus, [200, 206], true) || !is_array($result['data'] ?? null);
$driverLoadError = is_string($result['error'] ?? null) ? trim($result['error']) : '';
if ($driverLoadError === '' && is_array($result['data'] ?? null)) {
    foreach (['message','error_description','error','hint','details'] as $field) {
        if (is_string($result['data'][$field] ?? null) && trim($result['data'][$field]) !== '') {
            $driverLoadError = trim($result['data'][$field]); break;
        }
    }
}
if ($driverLoadError === '') $driverLoadError = $driverHttpStatus === 0
    ? 'No HTTP response was received. Check the PHP cURL connection, TLS certificate configuration and Supabase project URL.'
    : 'The database request did not return a driver list. Sign out and sign in again, then retry.';


$drivers = [];

if (
    ($driverHttpStatus === 200 ||
     $driverHttpStatus === 206) &&
    is_array($result["data"])
) {
    $drivers = $result["data"];
}


// =====================================================
// GET TODA ORGANIZATIONS
// =====================================================

$todaResult = supabaseRequest(
    "toda_organizations",
    "GET",
    null,
    "select=id,name,address&order=name.asc"
);

$todas = [];

if (
    ($todaResult["status"] === 200 ||
     $todaResult["status"] === 206) &&
    is_array($todaResult["data"])
) {
    $todas = $todaResult["data"];
}


// =====================================================
// TODA LOOKUP
// =====================================================

$todaNames = [];
$todaTerminals = [];

foreach ($todas as $toda) {
    $todaTerminals[$toda['id'] ?? ''] = $toda['address'] ?? '';

    if (
        isset($toda["id"]) &&
        isset($toda["name"])
    ) {
        $todaNames[$toda["id"]] =
            $toda["name"];
    }
}


// =====================================================
// CURRENT DATE
// =====================================================

$today = new DateTime();


// =====================================================
// DRIVER STATISTICS
// =====================================================

$totalDrivers = count($drivers);

$activeDrivers = 0;
$inactiveDrivers = 0;
$validRenewals = 0;
$dueSoonRenewals = 0;
$expiredRenewals = 0;


// =====================================================
// PREPARE DRIVER DATA
// =====================================================

$preparedDrivers = [];

foreach ($drivers as $driver) {

    // =================================================
    // TODA
    // =================================================

    $todaName = "Not assigned";

    if (
        !empty($driver["toda_id"]) &&
        isset(
            $todaNames[
                $driver["toda_id"]
            ]
        )
    ) {
        $todaName =
            $todaNames[
                $driver["toda_id"]
            ];
    }


    // =================================================
    // DRIVER STATUS
    // =================================================

    $status =
        $driver["status"] ?? "Active";

    if (
        strtolower(
            trim($status)
        ) === "active"
    ) {
        $activeDrivers++;
    } else {
        $inactiveDrivers++;
    }


    // =================================================
    // EXPIRY DATE
    // =================================================

    $expiryDate =
        $driver["franchise_expiry_date"]
        ?? null;


    // =================================================
    // RENEWAL STATUS
    // =================================================

    $renewalStatus =
        "Valid";

    $daysRemaining =
        "-";


    if ($expiryDate) {

        try {

            $expiry =
                new DateTime(
                    $expiryDate
                );

            $difference =
                $today->diff(
                    $expiry
                );


            if ($expiry < $today) {

                $renewalStatus =
                    "Expired";

                $daysRemaining =
                    -1 *
                    $difference->days;

                $expiredRenewals++;

            } else {

                $daysRemaining =
                    $difference->days;

                if (
                    $daysRemaining <= 30
                ) {

                    $renewalStatus =
                        "Due Soon";

                    $dueSoonRenewals++;

                } else {

                    $renewalStatus =
                        "Valid";

                    $validRenewals++;

                }

            }

        } catch (Exception $e) {

            $renewalStatus =
                $driver[
                    "renewal_status"
                ] ?? "Valid";

            if (
                $renewalStatus === "Expired"
            ) {
                $expiredRenewals++;

            } elseif (
                $renewalStatus === "Due Soon"
            ) {
                $dueSoonRenewals++;

            } else {
                $validRenewals++;
            }
        }

    } else {

        $renewalStatus =
            $driver[
                "renewal_status"
            ] ?? "Valid";

        if (
            $renewalStatus === "Expired"
        ) {
            $expiredRenewals++;

        } elseif (
            $renewalStatus === "Due Soon"
        ) {
            $dueSoonRenewals++;

        } else {
            $validRenewals++;
        }
    }


    // =================================================
    // RENEWAL CLASS
    // =================================================

    if (
        $renewalStatus ===
        "Expired"
    ) {

        $renewalClass =
            "renewal-expired";

    } elseif (
        $renewalStatus ===
        "Due Soon"
    ) {

        $renewalClass =
            "renewal-due";

    } else {

        $renewalClass =
            "renewal-valid";
    }


    // =================================================
    // SEARCH DATA
    // =================================================

    $searchData =
        ($driver[
            "application_number"
        ] ?? "") . " " .

        ($driver[
            "name"
        ] ?? "") . " " .

        ($driver[
            "phone_number"
        ] ?? "") . " " .

        ($driver[
            "address"
        ] ?? "") . " " .

        ($driver[
            "mtop_number"
        ] ?? "") . " " .

        ($driver[
            "tricycle_number"
        ] ?? "") . " " .

        ($driver[
            "engine_number"
        ] ?? "") . " " .

        ($driver[
            "chassis_number"
        ] ?? "") . " " .

        ($driver[
            "plate_number"
        ] ?? "") . " " .

        ($driver[
            "motorcycle_brand"
        ] ?? "") . " " .

        ($driver[
            "position"
        ] ?? "") . " " .

        $todaName;


    $preparedDrivers[] = [
        "driver" =>
            $driver,

        "todaName" =>
            $todaName,

        "status" =>
            $status,

        "expiryDate" =>
            $expiryDate,

        "renewalStatus" =>
            $renewalStatus,

        "renewalClass" =>
            $renewalClass,

        "daysRemaining" =>
            $daysRemaining,

        "searchData" =>
            strtolower($searchData)
    ];
}

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
        Driver Management - TRIKE-Q TMU Admin
    </title>


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
           HEADER ACTION
           ================================================= */

        .content-header {

            display: flex;

            justify-content: space-between;

            align-items: center;

            margin-bottom: 22px;
        }


        .content-header h2 {

            color: #9a3412;

            font-size: 20px;

            margin-bottom: 5px;
        }


        .content-header p {

            color: #6b7280;

            font-size: 13px;
        }


        .register-button {

            display: inline-flex;

            align-items: center;

            gap: 6px;

            background: #f97316;

            color: white;

            text-decoration: none;

            padding: 11px 16px;

            border-radius: 8px;

            font-size: 13px;

            font-weight: bold;

            transition: 0.2s;
        }


        .register-button:hover {

            background: #ea580c;
        }


        /* =================================================
           ERROR
           ================================================= */

        .error-message {

            background: #fee2e2;

            border:
                1px solid #fecaca;

            color: #991b1b;

            padding: 15px;

            border-radius: 10px;

            margin-bottom: 20px;

            font-size: 13px;

            line-height: 1.5;
        }


        /* =================================================
           SUMMARY CARDS
           ================================================= */

        .summary-grid {

            display: grid;

            grid-template-columns:
                repeat(4, 1fr);

            gap: 18px;

            margin-bottom: 22px;
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

            font-size: 27px;

            font-weight: bold;

            color: #9a3412;
        }


        .summary-small {

            margin-top: 5px;

            color: #9ca3af;

            font-size: 11px;
        }


        /* =================================================
           FILTER CARD
           ================================================= */

        .filter-card {

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

            margin-bottom: 22px;
        }


        .filter-title {

            color: #9a3412;

            font-size: 17px;

            font-weight: bold;

            margin-bottom: 5px;
        }


        .filter-description {

            color: #6b7280;

            font-size: 12px;

            margin-bottom: 18px;
        }


        .filters {

            display: grid;

            grid-template-columns:
                minmax(250px, 2fr)
                minmax(170px, 1fr)
                minmax(220px, 1fr);

            gap: 12px;
        }


        .filter-group {

            display: flex;

            flex-direction: column;

            gap: 6px;
        }


        .filter-label {

            color: #6b7280;

            font-size: 11px;

            font-weight: bold;
        }


        .filter-input,
        .filter-select {

            width: 100%;

            padding:
                11px 12px;

            border:
                1px solid #d1d5db;

            border-radius: 8px;

            font-size: 13px;

            background: white;

            color: #1f2937;

            outline: none;
        }


        .filter-input:focus,
        .filter-select:focus {

            border-color:
                #f97316;

            box-shadow:
                0 0 0 3px
                rgba(
                    249,
                    115,
                    22,
                    0.12
                );
        }


        /* =================================================
           TABLE CARD
           ================================================= */

        .table-card {

            background: white;

            border-radius: 12px;

            box-shadow:
                0 2px 8px
                rgba(
                    0,
                    0,
                    0,
                    0.06
                );

            overflow: hidden;
        }


        .table-header {

            display: flex;

            justify-content: space-between;

            align-items: center;

            padding:
                20px 22px;

            border-bottom:
                1px solid #e5e7eb;
        }


        .table-title {

            color: #9a3412;

            font-size: 17px;

            font-weight: bold;
        }


        .table-subtitle {

            color: #6b7280;

            font-size: 12px;

            margin-top: 4px;
        }


        .record-count {

            background: #fff7ed;

            color: #9a3412;

            padding:
                7px 11px;

            border-radius: 20px;

            font-size: 11px;

            font-weight: bold;
        }


        .table-container {

            overflow-x: auto;
        }


        table {

            width: 100%;

            border-collapse: collapse;

            min-width: 2000px;
        }


        th {

            background: #fff7ed;

            color: #9a3412;

            text-align: left;

            padding:
                13px 15px;

            font-size: 12px;

            border-bottom:
                1px solid #fed7aa;

            white-space: nowrap;
        }


        td {

            padding:
                13px 15px;

            border-bottom:
                1px solid #e5e7eb;

            font-size: 13px;

            vertical-align: middle;

            color: #374151;
        }


        tbody tr:hover td {

            background: #fffaf5;
        }


        td strong {

            color: #1f2937;
        }


        /* =================================================
           STATUS
           ================================================= */

        .status {

            display: inline-block;

            padding:
                5px 10px;

            border-radius: 20px;

            font-size: 11px;

            font-weight: bold;

            white-space: nowrap;
        }


        .status-active {

            background: #dcfce7;

            color: #166534;
        }


        .status-inactive {

            background: #fee2e2;

            color: #991b1b;
        }


        .renewal-valid {

            background: #dcfce7;

            color: #166534;
        }


        .renewal-due {

            background: #fef3c7;

            color: #92400e;
        }


        .renewal-expired {

            background: #fee2e2;

            color: #991b1b;
        }


        /* =================================================
           DAYS REMAINING
           ================================================= */

        .days-normal {

            color: #166534;

            font-weight: bold;
        }


        .days-warning {

            color: #92400e;

            font-weight: bold;
        }


        .days-expired {

            color: #991b1b;

            font-weight: bold;
        }


        /* =================================================
           EMPTY STATE
           ================================================= */

        .empty {

            padding: 65px 30px;

            text-align: center;

            color: #6b7280;
        }


        .empty-icon {

            width: 55px;

            height: 55px;

            margin:
                0 auto 15px;

            border-radius: 50%;

            background: #fff7ed;

            color: #f97316;

            display: flex;

            justify-content: center;

            align-items: center;

            font-size: 25px;
        }


        .empty h3 {

            color: #334155;

            font-size: 17px;

            margin-bottom: 7px;
        }


        .empty p {

            font-size: 13px;

            margin-bottom: 18px;
        }


        /* =================================================
           NO RESULTS
           ================================================= */

        .no-results {

            display: none;

            padding: 45px 30px;

            text-align: center;

            color: #6b7280;

            font-size: 13px;
        }


        .no-results strong {

            display: block;

            color: #334155;

            font-size: 16px;

            margin-bottom: 6px;
        }


        /* =================================================
           DRIVER ACTION BUTTONS
           ================================================= */

        .actions-heading,
        .action-cell {

            min-width: 130px;
        }


        .driver-actions {

            display: flex;

            flex-direction: column;

            gap: 7px;

            align-items: stretch;

            width: 100%;
        }


        .driver-action {

            display: inline-flex;

            align-items: center;

            justify-content: center;

            min-height: 34px;

            padding: 8px 10px;

            border-radius: 8px;

            font-size: 12px;

            font-weight: bold;

            line-height: 1.15;

            text-align: center;

            text-decoration: none;

            white-space: nowrap;

            border: 1px solid #fed7aa;

            color: #c2410c;

            background: #fff7ed;

            transition:
                background 0.2s,
                border-color 0.2s,
                color 0.2s;
        }


        .driver-action:hover {

            background: #ffedd5;

            border-color: #f97316;

            color: #9a3412;
        }


        .driver-action.primary {

            background: #f97316;

            border-color: #f97316;

            color: white;
        }


        .driver-action.primary:hover {

            background: #ea580c;

            border-color: #ea580c;

            color: white;
        }


        /* =================================================
           RESPONSIVE
           ================================================= */

        @media (max-width: 1100px) {

            .summary-grid {

                grid-template-columns:
                    repeat(2, 1fr);
            }


            .filters {

                grid-template-columns:
                    1fr 1fr;
            }

        }


        @media (max-width: 800px) {

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


            .content {

                padding: 20px;
            }


            .content-header {

                align-items: flex-start;

                gap: 15px;
            }


            .filters {

                grid-template-columns: 1fr;
            }

        }


        @media (max-width: 600px) {

            .layout {

                display: block;
            }


            .sidebar {

                position: relative;

                width: 100%;

                height: auto;
            }


            .main {

                margin-left: 0;

                width: 100%;
            }


            .topbar {

                padding:
                    0 15px;
            }


            .admin-name,
            .admin-role {

                display: none;
            }


            .content {

                padding: 15px;
            }


            .summary-grid {

                grid-template-columns: 1fr;
            }


            .content-header {

                display: block;
            }


            .register-button {

                margin-top: 15px;
            }


            .table-header {

                align-items: flex-start;

                gap: 10px;
            }

        }

    </style>

<link rel="stylesheet" href="admin-theme.css">

<link rel="stylesheet" href="tmu-updates.css?v=20261019b">
<script src="tmu-updates.js?v=20261019" defer></script>
<style>
/* Driver-only layout: CSV occupies the same column as the TODA filter. */
#tqDriverFilterCard{display:grid;grid-template-columns:minmax(0,2fr) minmax(0,1fr) minmax(0,1fr);column-gap:12px;row-gap:14px;align-items:start}
#tqDriverFilterCard .tq-driver-filter-copy{grid-column:1 / 3;grid-row:1;min-width:0}
#tqDriverFilterCard .filter-description{margin-bottom:0}
#tqDriverFilterCard .tq-driver-export{grid-column:3;grid-row:1;display:flex;justify-content:flex-end;align-items:flex-start;min-width:0}
#tqDriverFilterCard .filters{grid-column:1 / -1;display:grid;grid-template-columns:minmax(0,2fr) minmax(0,1fr) minmax(0,1fr);gap:12px;margin:0}
#tqDriverFilterCard .filter-group{min-width:0}
#tqDriverFilterCard .filter-input,#tqDriverFilterCard .filter-select{width:100%;min-width:0;box-sizing:border-box}
#tqDriverFilterCard .tq-csv-button{display:inline-flex;align-items:center;justify-content:center;gap:7px;background:#f0fdf4!important;color:#15803d!important;border:1px solid #86efac!important;border-radius:7px;padding:8px 11px;min-height:34px;font:600 13px Arial,Helvetica,sans-serif;white-space:nowrap;cursor:pointer}
#tqDriverFilterCard .tq-csv-button svg{display:block;width:16px!important;height:16px!important;min-width:16px;flex:0 0 16px;stroke:#15803d!important;fill:none}
#driversTable .action-cell{min-width:82px;width:82px}
#driversTable .driver-actions{display:flex!important;flex-direction:row!important;align-items:center;justify-content:flex-start;gap:6px;width:auto}
#driversTable .driver-actions a.tq-icon-button{display:inline-flex!important;align-items:center;justify-content:center;width:30px!important;height:30px!important;min-width:30px!important;min-height:30px!important;max-width:30px;max-height:30px;padding:0!important;flex:0 0 30px;border-radius:6px;box-sizing:border-box}
#driversTable .driver-actions a.tq-icon-button svg{display:block;width:14px!important;height:14px!important;min-width:14px;max-width:14px;max-height:14px;flex:0 0 14px;fill:none;stroke:currentColor}
@media(max-width:850px){#tqDriverFilterCard{grid-template-columns:minmax(0,1fr) auto}#tqDriverFilterCard .tq-driver-filter-copy{grid-column:1}#tqDriverFilterCard .tq-driver-export{grid-column:2}#tqDriverFilterCard .filters{grid-template-columns:1fr}#tqDriverFilterCard .tq-driver-export .tq-csv-button{font-size:12px;padding:8px}}
@media(max-width:460px){#tqDriverFilterCard .tq-driver-filter-copy{grid-column:1/-1}#tqDriverFilterCard .tq-driver-export{grid-column:1/-1;grid-row:2;justify-content:flex-end}}
</style>
<link rel="stylesheet" href="assets/driver-fields.css?v=<?= (int) @filemtime(__DIR__.'/assets/driver-fields.css') ?>"><style id="tmu-driver-status-buttons">
#driversTable .driver-actions{flex-wrap:wrap}
#driversTable .tq-status-action-form{display:inline-flex;margin:0}
#driversTable button.tq-status-toggle{display:inline-flex!important;flex-direction:column;align-items:center;justify-content:center;gap:4px;width:auto!important;min-width:66px;min-height:48px;height:auto!important;padding:7px 9px!important;border:1px solid;border-radius:8px;font:600 12px/16px Arial,Helvetica,sans-serif;cursor:pointer}
#driversTable button.tq-status-toggle svg{width:17px!important;height:17px!important;flex:none}
#driversTable button.tq-status-activate{color:#166534!important;background:#f0fdf4!important;border-color:#86efac!important}
#driversTable button.tq-status-deactivate{color:#991b1b!important;background:#fef2f2!important;border-color:#fecaca!important}
#driversTable button.tq-status-activate:hover{background:#dcfce7!important}
#driversTable button.tq-status-deactivate:hover{background:#fee2e2!important}
#driversTable button.tq-status-toggle:focus-visible{outline:3px solid #f97316;outline-offset:2px}
</style>
<!-- Shared TMU header and navigation; refresh versions when the shared files change. -->
<script src="admin-header.js?v=<?= (int) @filemtime(__DIR__.'/admin-header.js') ?>" data-tq-header="1" defer></script>
<script src="admin-nav.js?v=<?= (int) @filemtime(__DIR__.'/admin-nav.js') ?>" defer></script>
</head>


<body>


<div class="layout">


    <!-- =================================================
         SIDEBAR
         ================================================= -->

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
                    Driver Management
                </div>

                <div class="page-subtitle">
                    View and manage registered tricycle drivers
                </div>

            </div>


            <div class="admin-info">


                <div>

                    <div class="admin-name">
                        <?= htmlspecialchars(
                            $adminName
                        ) ?>
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
<div class="tq-record-toolbar">
    <nav class="tq-record-tabs" aria-label="Driver record lists">
        <a href="drivers.php" class="<?= !$tqShowArchived ? 'is-active' : '' ?>" <?= !$tqShowArchived ? 'aria-current="page"' : '' ?>><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>Driver List</a>
        <a href="drivers.php?archived=1" class="<?= $tqShowArchived ? 'is-active' : '' ?>" <?= $tqShowArchived ? 'aria-current="page"' : '' ?>><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 3h18v5H3zM5 8v13h14V8M9 12h6"/></svg>Archived Drivers</a>
    </nav>
    <p class="tq-record-explanation"><?= $tqShowArchived ? 'Archived records retain their history. Restore a driver or delete permanently if no linked records exist.' : 'Delete moves a driver to Archived Drivers and preserves their history.' ?></p>
</div>
<?php if($tqDriverNotice): ?><p class="tq-record-notice" role="status"><?=tqEscape($tqDriverNotice)?></p><?php endif; ?>


            <!-- =================================================
                 CONTENT HEADER
                 ================================================= -->

            <div class="content-header">


                <div>

                    <h2>
                        Registered Drivers
                    </h2>

                    <p>
                        Review driver registration, TODA membership,
                        and franchise renewal information.
                    </p>

                </div>


                <a
                    href="register.php"
                    class="register-button"
                >

                    <span>
                        ＋
                    </span>

                    Register Driver

                </a>


            </div>


            <!-- =================================================
                 ERROR
                 ================================================= -->

            <?php if ($driverLoadFailed): ?>

                <div class="error-message">

                    <strong>
                        Unable to load driver records.
                    </strong>

                    <br>

                    HTTP status: <?= (int)$driverHttpStatus ?><br>
                    <?= htmlspecialchars($driverLoadError, ENT_QUOTES, 'UTF-8') ?>
                    <br><a href="drivers.php">Retry driver request</a>

                </div>

            <?php endif; ?>


            <!-- =================================================
                 SUMMARY
                 ================================================= -->

            <div class="summary-grid">


                <div class="summary-card">

                    <div class="summary-label">
                        Total Drivers
                    </div>

                    <div
                        class="summary-value"
                        id="driverCount"
                    >
                        <?= number_format(
                            $totalDrivers
                        ) ?>
                    </div>

                    <div class="summary-small">
                        Registered drivers
                    </div>

                </div>


                <div class="summary-card">

                    <div class="summary-label">
                        Active Drivers
                    </div>

                    <div class="summary-value">
                        <?= number_format(
                            $activeDrivers
                        ) ?>
                    </div>

                    <div class="summary-small">
                        Currently active
                    </div>

                </div>


                <div class="summary-card">

                    <div class="summary-label">
                        Due Soon
                    </div>

                    <div class="summary-value">
                        <?= number_format(
                            $dueSoonRenewals
                        ) ?>
                    </div>

                    <div class="summary-small">
                        Renewal within 30 days
                    </div>

                </div>


                <div class="summary-card">

                    <div class="summary-label">
                        Expired
                    </div>

                    <div class="summary-value">
                        <?= number_format(
                            $expiredRenewals
                        ) ?>
                    </div>

                    <div class="summary-small">
                        Franchise / MTOP expired
                    </div>

                </div>


            </div>


            <!-- =================================================
                 FILTER CARD
                 ================================================= -->

            <div class="filter-card" id="tqDriverFilterCard">


<div class="tq-driver-filter-copy">
<div class="filter-title">Search &amp; Filter Drivers</div>
<div class="filter-description">Search registration information or filter drivers by status and TODA organization.</div>
</div><div class="tq-driver-export"><button type="button" class="tq-button tq-csv-button" data-csv-table="driversTable" data-csv-name="drivers"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3v12m-5-5 5 5 5-5M4 16v5h16v-5"/></svg><span>Download CSV</span></button></div>
                <div class="filters">


                    <div class="filter-group">

                        <label
                            class="filter-label"
                            for="searchInput"
                        >
                            SEARCH
                        </label>

                        <input
                            type="text"
                            id="searchInput"
                            class="filter-input"
                            placeholder="Search name, application no., MTOP, plate, tricycle no..."
                        >

                    </div>


                    <div class="filter-group">

                        <label
                            class="filter-label"
                            for="statusFilter"
                        >
                            DRIVER STATUS
                        </label>

                        <select
                            id="statusFilter"
                            class="filter-select"
                        >

                            <option value="">
                                All Driver Status
                            </option>

                            <option value="Active">
                                Active
                            </option>

                            <option value="Inactive">
                                Inactive
                            </option>

                        </select>

                    </div>


                    <div class="filter-group">

                        <label
                            class="filter-label"
                            for="todaFilter"
                        >
                            TODA ORGANIZATION
                        </label>

                        <select
                            id="todaFilter"
                            class="filter-select"
                        >

                            <option value="">
                                All TODA Organizations
                            </option>


                            <?php foreach (
                                $todas
                                as $toda
                            ): ?>

                                <option
                                    value="<?= htmlspecialchars(
                                        strtolower(
                                            $toda["name"]
                                        )
                                    ) ?>"
                                >

                                    <?= htmlspecialchars(
                                        $toda["name"]
                                    ) ?>

                                </option>

                            <?php endforeach; ?>


                        </select>

                    </div>


                </div>


            </div>


            <!-- =================================================
                 TABLE CARD
                 ================================================= -->

            <div class="table-card">


                <div class="table-header">


                    <div>

                        <div class="table-title">
                            Driver Records
                        </div>

                        <div class="table-subtitle">
                            Complete registered driver information
                        </div>

                    </div>


                    <div
                        class="record-count"
                        id="recordCount"
                    >

                        <?= number_format(
                            $totalDrivers
                        ) ?>

                        records

                    </div>


                </div>


                <?php if (
                    empty($preparedDrivers)
                ): ?>


                    <!-- EMPTY STATE -->

                    <div class="empty">


                        <div class="empty-icon">
                            ♙
                        </div>


                        <h3>
                            No drivers registered yet
                        </h3>


                        <p>
                            Click "Register Driver" to add a new driver.
                        </p>


                        <a
                            href="register.php"
                            class="register-button"
                        >
                            ＋ Register Driver
                        </a>


                    </div>


                <?php else: ?>


                    <!-- =================================================
                         TABLE
                         ================================================= -->

                    <div class="table-container">


                        <table id="driversTable">


                            <thead>

                                <tr>

                                    <th data-no-export>Profile</th>
                                    <th>
                                        Application No.
                                    </th>

                                    <th>
                                        Full Name
                                    </th>
                                    <th>Birthdate</th>
                                    <th>Age</th>
                                    <th>Email Address</th>

                                    <th>
                                        Address
                                    </th>

                                    <th>
                                        Contact
                                    </th>

                                    <th>
                                        MTOP
                                    </th>

                                    <th>
                                        Tricycle No.
                                    </th>

                                    <th>
                                        Engine No.
                                    </th>

                                    <th>
                                        Chassis No.
                                    </th>

                                    <th>
                                        Plate No.
                                    </th>

                                    <th>
                                        Motorcycle Brand
                                    </th>

                                    <th>
                                        TODA
                                    </th>
<th>Terminal Address</th>

                                    <th>
                                        Position
                                    </th>

                                    <th>
                                        Status
                                    </th>

                                    <th>
                                        Last Renewal
                                    </th>

                                    <th>
                                        Franchise / MTOP Expiry
                                    </th>

                                    <th>
                                        Days Remaining
                                    </th>

                                    <th>
                                        Renewal Status
                                    </th>

                                    <th class="actions-heading" data-no-export>Actions</th>

                                </tr>

                            </thead>


                            <tbody>


                                <?php foreach (
                                    $preparedDrivers
                                    as $item
                                ): ?>


                                    <?php

                                    $driver =
                                        $item[
                                            "driver"
                                        ];

                                    $todaName =
                                        $item[
                                            "todaName"
                                        ];

                                    $status =
                                        $item[
                                            "status"
                                        ];

                                    $expiryDate =
                                        $item[
                                            "expiryDate"
                                        ];

                                    $renewalStatus =
                                        $item[
                                            "renewalStatus"
                                        ];

                                    $renewalClass =
                                        $item[
                                            "renewalClass"
                                        ];

                                    $daysRemaining =
                                        $item[
                                            "daysRemaining"
                                        ];

                                    $searchData =
                                        $item[
                                            "searchData"
                                        ];

                                    ?>


                                    <tr
                                        class="driver-row"

                                        data-search="<?= htmlspecialchars(
                                            $searchData . ' ' . strtolower($todaTerminals[$driver['toda_id'] ?? ''] ?? '')
                                        ) ?>"

                                        data-status="<?= htmlspecialchars(
                                            $status
                                        ) ?>"

                                        data-toda="<?= htmlspecialchars(
                                            strtolower(
                                                $todaName
                                            )
                                        ) ?>"
                                    >


                                        <td>
                                            <?php $picture = trim((string)($driver['profile_picture_url'] ?? '')); ?>
                                            <?php if ($picture !== ''): ?><a class="tq-driver-photo-link" data-driver-photo data-driver-name="<?= htmlspecialchars((string)($driver['name'] ?? 'Driver'), ENT_QUOTES, 'UTF-8') ?>" href="<?= htmlspecialchars($picture, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" aria-label="View <?= htmlspecialchars((string)($driver['name'] ?? 'Driver'), ENT_QUOTES, 'UTF-8') ?> profile picture"><?php endif; ?>
                                            <div style="width:48px;height:48px;border-radius:8px;overflow:hidden;background:#fff7ed;position:relative;display:flex;align-items:center;justify-content:center;color:#9a3412;font-size:18px">
                                                <span><?= htmlspecialchars(strtoupper(substr((string)($driver['name'] ?? 'D'), 0, 1)), ENT_QUOTES, 'UTF-8') ?></span>
                                                <?php if ($picture !== ''): ?>
                                                <img src="<?= htmlspecialchars($picture, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string)($driver['name'] ?? 'Driver'), ENT_QUOTES, 'UTF-8') ?> profile picture" loading="lazy" width="48" height="48" style="width:48px;height:48px;object-fit:cover;position:absolute;inset:0;background:white" onerror="this.remove()">
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($picture !== ''): ?></a><?php endif; ?>
                                        </td>
                                        <!-- APPLICATION -->

                                        <td>

                                            <strong>

                                                <?= htmlspecialchars(
                                                    $driver[
                                                        "application_number"
                                                    ] ?? "-"
                                                ) ?>

                                            </strong>

                                        </td>


                                        <!-- NAME -->

                                        <td>

                                            <strong>

                                                <?= htmlspecialchars(
                                                    $driver[
                                                        "name"
                                                    ] ?? "-"
                                                ) ?>

                                            </strong>

                                        </td>


                                        <!-- BIRTHDATE AND AGE -->
                                        <?php
                                        $birthdateText = 'Not provided';
                                        $driverAge = '—';
                                        $rawBirthdate = is_string($driver['birthdate'] ?? null) ? $driver['birthdate'] : '';
                                        $ageTimezone = new DateTimeZone('Asia/Manila');
                                        $ageToday = new DateTimeImmutable('today', $ageTimezone);
                                        $birthdateValue = $rawBirthdate !== '' ? DateTimeImmutable::createFromFormat('!Y-m-d', $rawBirthdate, $ageTimezone) : false;
                                        if ($birthdateValue && $birthdateValue->format('Y-m-d') === $rawBirthdate && $birthdateValue < $ageToday) {
                                            $birthdateText = $birthdateValue->format('M d, Y');
                                            $driverAge = (string)$birthdateValue->diff($ageToday)->y;
                                        }
                                        ?>
                                        <td><?= tqEscape($birthdateText) ?></td>
                                        <td><?= tqEscape($driverAge) ?></td>

                                        <!-- EMAIL -->
                                        <td><?= htmlspecialchars($driver['email'] ?? 'No email', ENT_QUOTES, 'UTF-8') ?></td>

                                        <!-- ADDRESS -->
                                        <td>

                                            <?= htmlspecialchars(
                                                $driver[
                                                    "address"
                                                ] ?? "-"
                                            ) ?>

                                        </td>


                                        <!-- CONTACT -->

                                        <td>

                                            <?= htmlspecialchars(
                                                $driver[
                                                    "phone_number"
                                                ] ?? "-"
                                            ) ?>

                                        </td>


                                        <!-- MTOP -->

                                        <td>

                                            <?= htmlspecialchars(
                                                $driver[
                                                    "mtop_number"
                                                ] ?? "-"
                                            ) ?>

                                        </td>


                                        <!-- TRICYCLE NUMBER -->

                                        <td>

                                            <?= htmlspecialchars(
                                                $driver[
                                                    "tricycle_number"
                                                ] ?? "-"
                                            ) ?>

                                        </td>


                                        <!-- ENGINE -->

                                        <td>

                                            <?= htmlspecialchars(
                                                $driver[
                                                    "engine_number"
                                                ] ?? "-"
                                            ) ?>

                                        </td>


                                        <!-- CHASSIS -->

                                        <td>

                                            <?= htmlspecialchars(
                                                $driver[
                                                    "chassis_number"
                                                ] ?? "-"
                                            ) ?>

                                        </td>


                                        <!-- PLATE -->

                                        <td>

                                            <?= htmlspecialchars(
                                                $driver[
                                                    "plate_number"
                                                ] ?? "-"
                                            ) ?>

                                        </td>


                                        <!-- MOTORCYCLE BRAND -->

                                        <td>

                                            <?= htmlspecialchars(
                                                $driver[
                                                    "motorcycle_brand"
                                                ] ?? "-"
                                            ) ?>

                                        </td>


                                        <!-- TODA -->

                                        <td>

                                            <?= htmlspecialchars(
                                                $todaName
                                            ) ?>

                                        </td>
<td><?= tqEscape($todaTerminals[$driver['toda_id'] ?? ''] ?? '') ?></td>


                                        <!-- POSITION -->

                                        <td>

                                            <?= htmlspecialchars(
                                                $driver[
                                                    "position"
                                                ] ?? "-"
                                            ) ?>

                                        </td>


                                        <!-- STATUS -->

                                        <td>

                                            <span
                                                class="status
                                                <?= strtolower(
                                                    trim(
                                                        $status
                                                    )
                                                ) === "active"
                                                    ? "status-active"
                                                    : "status-inactive" ?>"
                                            >

                                                <?= htmlspecialchars(
                                                    $status
                                                ) ?>

                                            </span>

                                        </td>


                                        <!-- LAST RENEWAL -->

                                        <td>

                                            <?php if (
                                                !empty(
                                                    $driver[
                                                        "last_renewal_date"
                                                    ]
                                                )
                                            ): ?>

                                                <?= htmlspecialchars(
                                                    date(
                                                        "M d, Y",
                                                        strtotime(
                                                            $driver[
                                                                "last_renewal_date"
                                                            ]
                                                        )
                                                    )
                                                ) ?>

                                            <?php else: ?>

                                                -

                                            <?php endif; ?>

                                        </td>


                                        <!-- EXPIRY -->

                                        <td>

                                            <?php if (
                                                $expiryDate
                                            ): ?>

                                                <?= htmlspecialchars(
                                                    date(
                                                        "M d, Y",
                                                        strtotime(
                                                            $expiryDate
                                                        )
                                                    )
                                                ) ?>

                                            <?php else: ?>

                                                -

                                            <?php endif; ?>

                                        </td>


                                        <!-- DAYS REMAINING -->

                                        <td>

                                            <?php if (
                                                $daysRemaining === "-"
                                            ): ?>

                                                -

                                            <?php elseif (
                                                $daysRemaining < 0
                                            ): ?>

                                                <span
                                                    class="days-expired"
                                                >

                                                    <?= abs(
                                                        (int)
                                                        $daysRemaining
                                                    ) ?>

                                                    days overdue

                                                </span>

                                            <?php elseif (
                                                $daysRemaining <= 30
                                            ): ?>

                                                <span
                                                    class="days-warning"
                                                >

                                                    <?= (int)
                                                        $daysRemaining ?>

                                                    days

                                                </span>

                                            <?php else: ?>

                                                <span
                                                    class="days-normal"
                                                >

                                                    <?= (int)
                                                        $daysRemaining ?>

                                                    days

                                                </span>

                                            <?php endif; ?>

                                        </td>


                                        <!-- RENEWAL STATUS -->

                                        <td>

                                            <span
                                                class="status
                                                <?= htmlspecialchars(
                                                    $renewalClass
                                                ) ?>"
                                            >

                                                <?= htmlspecialchars(
                                                    $renewalStatus
                                                ) ?>

                                            </span>

                                        </td>


                                        <!-- ACTIONS -->

                                        <td class="action-cell">

                                            <div class="driver-actions">
                                                <a class="driver-action tq-labelled" href="driver_view.php?id=<?=urlencode($driver['id'])?>" title="View driver information"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg><span>View</span></a>
                                                <a class="driver-action tq-labelled" href="driver_qr.php?id=<?=urlencode($driver['id'])?>" title="View QR code"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 3h6v6H3zM15 3h6v6h-6zM3 15h6v6H3zM15 15h2v2h-2zM19 15h2v6h-6v-2"/></svg><span>QR</span></a>
                                                <a class="driver-action tq-labelled" href="driver_print.php?id=<?=urlencode($driver['id'])?>" target="_blank" rel="noopener" title="Print driver information"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9V3h12v6M6 17H3V9h18v8h-3M6 14h12v7H6z"/></svg><span>Print</span></a>
                                                <?php if(!$tqShowArchived): $tqIsActive = strtolower(trim((string)($driver['status']??''))) === 'active'; ?>
                                                <form method="post" class="tq-status-action-form" onsubmit="return confirm(this.dataset.confirm)" data-confirm="<?=tqEscape(($tqIsActive?'Deactivate ':'Activate ').($driver['name']??'this driver').'?')?>">
                                                    <input type="hidden" name="tq_csrf" value="<?=tqEscape(tqCsrf())?>">
                                                    <input type="hidden" name="driver_id" value="<?=tqEscape($driver['id'])?>">
                                                    <input type="hidden" name="record_action" value="<?=$tqIsActive?'deactivate':'activate'?>">
                                                    <button class="driver-action tq-labelled tq-status-toggle <?=$tqIsActive?'tq-status-deactivate':'tq-status-activate'?>" type="submit" title="<?=$tqIsActive?'Deactivate driver':'Activate driver'?>">
                                                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 2v10M6.3 5.3a9 9 0 1 0 11.4 0"/></svg>
                                                        <span><?=$tqIsActive?'Deactivate':'Activate'?></span>
                                                    </button>
                                                </form>
                                                <?php endif; ?>
                                                <form method="post" onsubmit="return confirm(this.dataset.confirm)" data-confirm="<?=tqEscape(($tqShowArchived?'Permanently delete ':'Archive ').($driver['name']??'this driver').($tqShowArchived?'? This cannot be undone. Drivers with linked history cannot be deleted.':'? The driver becomes Inactive and remains in Archived Drivers.'))?>">
                                                    <input type="hidden" name="tq_csrf" value="<?=tqEscape(tqCsrf())?>"><input type="hidden" name="driver_id" value="<?=tqEscape($driver['id'])?>"><input type="hidden" name="record_action" value="<?=$tqShowArchived?'delete':'archive'?>">
                                                    <button class="driver-action tq-labelled tq-danger" type="submit" title="<?=$tqShowArchived?'Permanently delete driver':'Archive driver'?>"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7m4-7v7"/></svg><span>Delete</span></button>
                                                </form>
                                                <?php if($tqShowArchived): ?><form method="post"><input type="hidden" name="tq_csrf" value="<?=tqEscape(tqCsrf())?>"><input type="hidden" name="driver_id" value="<?=tqEscape($driver['id'])?>"><input type="hidden" name="record_action" value="restore"><button class="driver-action tq-labelled" type="submit">↶<span>Restore</span></button></form><?php endif; ?>
                                            </div>

                                        </td>


                                    </tr>


                                <?php endforeach; ?>


                            </tbody>


                        </table>


                    </div>


                    <!-- NO RESULTS -->

                    <div
                        id="noResults"
                        class="no-results"
                    >

                        <strong>
                            No drivers found
                        </strong>

                        No driver records match your current
                        search or filter.

                    </div>


                <?php endif; ?>


            </div>


        </section>


    </main>


</div>


<!-- =====================================================
     SEARCH AND FILTER
====================================================== -->

<script>


    const searchInput =
        document.getElementById(
            "searchInput"
        );


    const statusFilter =
        document.getElementById(
            "statusFilter"
        );


    const todaFilter =
        document.getElementById(
            "todaFilter"
        );


    const rows =
        document.querySelectorAll(
            ".driver-row"
        );


    const noResults =
        document.getElementById(
            "noResults"
        );


    const driverCount =
        document.getElementById(
            "driverCount"
        );


    const recordCount =
        document.getElementById(
            "recordCount"
        );


    function filterDrivers() {


        const search =
            searchInput
                ? searchInput.value
                    .toLowerCase()
                    .trim()
                : "";


        const selectedStatus =
            statusFilter
                ? statusFilter.value
                : "";


        const selectedToda =
            todaFilter
                ? todaFilter.value
                : "";


        let visibleCount = 0;


        rows.forEach(
            function(row) {


                const rowSearch =
                    row.dataset.search
                    || "";


                const rowStatus =
                    row.dataset.status
                    || "";


                const rowToda =
                    row.dataset.toda
                    || "";


                const matchesSearch =
                    rowSearch.includes(
                        search
                    );


                const matchesStatus =
                    selectedStatus === "" ||
                    rowStatus ===
                    selectedStatus;


                const matchesToda =
                    selectedToda === "" ||
                    rowToda ===
                    selectedToda;


                if (
                    matchesSearch &&
                    matchesStatus &&
                    matchesToda
                ) {

                    row.style.display =
                        "";

                    visibleCount++;

                } else {

                    row.style.display =
                        "none";
                }

            }
        );


        if (driverCount) {

            driverCount.textContent =
                visibleCount;
        }


        if (recordCount) {

            recordCount.textContent =
                visibleCount +
                (
                    visibleCount === 1
                        ? " record"
                        : " records"
                );

        }


        if (noResults) {

            if (
                visibleCount === 0 &&
                rows.length > 0
            ) {

                noResults.style.display =
                    "block";

            } else {

                noResults.style.display =
                    "none";
            }

        }

    }


    if (searchInput) {

        searchInput.addEventListener(
            "input",
            filterDrivers
        );

    }


    if (statusFilter) {

        statusFilter.addEventListener(
            "change",
            filterDrivers
        );

    }


    if (todaFilter) {

        todaFilter.addEventListener(
            "change",
            filterDrivers
        );

    }


</script>


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

</body>

</html>
