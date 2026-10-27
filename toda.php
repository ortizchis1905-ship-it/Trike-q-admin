<?php

// =====================================================
// TRIKE-Q TODA ORGANIZATIONS
// =====================================================

session_start();

require_once __DIR__ . "/config/supabase.php";
require_once __DIR__ . '/tmu-tools.php';
tqRequireAdmin();
tqRequireCsrf();



// =====================================================
// LOGIN CHECK
// =====================================================

if (
    !isset($_SESSION["access_token"]) ||
    $_SESSION["access_token"] === ""
) {
    header("Location: login.php");
    exit;
}


// =====================================================
// GET ADMIN INFORMATION
// =====================================================

$adminName = "TMU Admin";

if (
    isset($_SESSION["user_id"]) &&
    $_SESSION["user_id"] !== ""
) {
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
}


// =====================================================
// HELPER
// =====================================================

function e($value)
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        "UTF-8"
    );
}


// =====================================================
// FORM VARIABLES
// =====================================================

$errorMessage = "";
$successMessage = (string)($_SESSION['tq_toda_delete_notice']??'');
unset($_SESSION['tq_toda_delete_notice']);

$name = "";
$address = "";


// =====================================================
// HANDLE POST REQUEST
// =====================================================

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST["action"] ?? "";


    // =================================================
    // ADD TODA
    // =================================================

    if ($action === 'delete_toda') {
        $deleteId=is_string($_POST['id']??null)?trim($_POST['id']):'';
        if(!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',$deleteId)) {
            $errorMessage='Choose a valid TODA organization.';
        } else {
            $deleted=supabaseRequest('rpc/trikeq_delete_empty_toda','POST',['p_toda_id'=>$deleteId]);
            if(!empty($deleted['success']) && ($deleted['data']??null)===true) {
                $_SESSION['tq_toda_delete_notice']='TODA organization deleted successfully.';
                header('Location: toda.php',true,303);exit;
            }
            $message=$deleted['error']??($deleted['data']['message']??'');
            $errorMessage=is_string($message)&&$message!==''?$message:'Unable to delete this organization. Install toda_delete.sql first, then try again.';
        }
    }
    elseif ($action === "add_toda") {

        $name =
            trim(
                $_POST["name"] ?? ""
            );

        $address =
            trim(
                $_POST["address"] ?? ""
            );


        if ($name === "") {

            $errorMessage =
                "TODA organization name is required.";

        } else {

            // New TODA organizations are Active by default.
            $todaData = [
                "name" =>
                    $name,

                "address" =>
                    $address,

                "status" =>
                    true
            ];


            $insertResult =
                supabaseRequest(
                    "toda_organizations",
                    "POST",
                    $todaData
                );


            if (
                $insertResult["status"] >= 200 &&
                $insertResult["status"] < 300
            ) {

                $successMessage =
                    "TODA organization added successfully.";

                $name = "";
                $address = "";

            } else {

                $errorMessage =
                    "Unable to add TODA organization. " .
                    "Supabase error: " .
                    json_encode(
                        $insertResult["data"]
                    );
            }
        }
    }


    // =================================================
    // EDIT TODA
    // =================================================

    elseif ($action === "edit_toda") {

        $editId =
            trim(
                $_POST["id"] ?? ""
            );

        $editName =
            trim(
                $_POST["name"] ?? ""
            );

        $editAddress =
            trim(
                $_POST["address"] ?? ""
            );

        $editStatus =
            $_POST["status"] ?? "1";


        if ($editId === "") {

            $errorMessage =
                "Invalid TODA organization.";

        } elseif ($editName === "") {

            $errorMessage =
                "TODA organization name is required.";

        } elseif (
            !in_array(
                $editStatus,
                ["0", "1"],
                true
            )
        ) {

            $errorMessage =
                "Invalid TODA status.";

        } else {

            $updateData = [

                "name" =>
                    $editName,

                "address" =>
                    $editAddress,

                "status" =>
                    $editStatus === "1"
            ];


            $updateResult =
                supabaseRequest(
                    "toda_organizations",
                    "PATCH",
                    $updateData,
                    "id=eq." .
                    urlencode($editId)
                );


            if (
                $updateResult["status"] >= 200 &&
                $updateResult["status"] < 300
            ) {

                $successMessage =
                    "TODA organization updated successfully.";

            } else {

                $errorMessage =
                    "Unable to update TODA organization. " .
                    "Supabase error: " .
                    json_encode(
                        $updateResult["data"]
                    );
            }
        }
    }
}


// =====================================================
// FETCH TODA ORGANIZATIONS
// =====================================================

$todaResult =
    supabaseRequest(
        "toda_organizations",
        "GET",
        null,
        "select=id,name,address,status,created_at&order=name.asc"
    );


$todas = [];


if (
    $todaResult["status"] >= 200 &&
    $todaResult["status"] < 300 &&
    is_array($todaResult["data"])
) {

    $todas =
        $todaResult["data"];

} else {

    if ($errorMessage === "") {

        $errorMessage =
            "Unable to load TODA organizations.";
    }
}


// =====================================================
// COUNTS
// =====================================================

$totalTodas =
    count($todas);

$activeTodas = 0;
$inactiveTodas = 0;


foreach ($todas as $toda) {

    $status =
        $toda["status"] ?? false;


    if (
        $status === true ||
        $status === "true" ||
        $status === 1 ||
        $status === "1"
    ) {

        $activeTodas++;

    } else {

        $inactiveTodas++;
    }
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
        TODA Organizations - TRIKE-Q TMU Admin
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

            width:
                calc(100% - 250px);

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
           ALERTS
           ================================================= */

        .alert {

            padding: 14px 16px;

            border-radius: 10px;

            margin-bottom: 20px;

            font-size: 14px;
        }


        .alert-error {

            background: #fef2f2;

            border:
                1px solid #fecaca;

            color: #991b1b;
        }


        .alert-success {

            background: #f0fdf4;

            border:
                1px solid #bbf7d0;

            color: #166534;
        }


        /* =================================================
           SUMMARY
           ================================================= */

        .summary-grid {

            display: grid;

            grid-template-columns:
                repeat(3, 1fr);

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
           CARD GRID
           ================================================= */

        .card-grid {

            display: grid;

            grid-template-columns:
                1fr 1.7fr;

            gap: 22px;

            align-items: start;
        }


        .card {

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


        .card-title {

            color: #9a3412;

            font-size: 17px;

            font-weight: bold;

            margin-bottom: 5px;
        }


        .card-description {

            color: #6b7280;

            font-size: 12px;

            margin-bottom: 20px;
        }


        /* =================================================
           FORM
           ================================================= */

        .form-group {

            margin-bottom: 16px;
        }


        .form-label {

            display: block;

            font-size: 13px;

            font-weight: bold;

            color: #374151;

            margin-bottom: 7px;
        }


        .form-control {

            width: 100%;

            padding: 11px 12px;

            border:
                1px solid #d1d5db;

            border-radius: 8px;

            font-size: 13px;

            color: #374151;

            background: white;

            outline: none;

            transition: 0.2s;
        }


        .form-control:focus {

            border-color: #f97316;

            box-shadow:
                0 0 0 3px
                rgba(
                    249,
                    115,
                    22,
                    0.10
                );
        }


        textarea.form-control {

            min-height: 90px;

            resize: vertical;
        }


        .btn {

            border: none;

            border-radius: 8px;

            padding: 11px 17px;

            font-size: 13px;

            font-weight: bold;

            cursor: pointer;

            transition: 0.2s;
        }


        .btn-primary {

            background: #f97316;

            color: white;
        }


        .btn-primary:hover {

            background: #ea580c;
        }


        .btn-edit {

            background: #fff7ed;

            color: #9a3412;

            border:
                1px solid #fed7aa;

            padding: 7px 11px;

            border-radius: 7px;

            font-size: 12px;

            font-weight: bold;

            cursor: pointer;

            transition: 0.2s;
        }


        .btn-edit:hover {

            background: #ffedd5;
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


        .date-text {

            color: #6b7280;

            font-size: 12px;
        }


        .empty {

            text-align: center;

            color: #9ca3af;

            padding: 30px;

            font-size: 13px;
        }


        /* =================================================
           MODAL
           ================================================= */

        .modal {

            display: none;

            position: fixed;

            inset: 0;

            background:
                rgba(
                    0,
                    0,
                    0,
                    0.45
                );

            z-index: 1000;

            align-items: center;

            justify-content: center;

            padding: 20px;
        }


        .modal.show {

            display: flex;
        }


        .modal-content {

            width: 100%;

            max-width: 500px;

            background: white;

            border-radius: 12px;

            box-shadow:
                0 10px 35px
                rgba(
                    0,
                    0,
                    0,
                    0.20
                );

            overflow: hidden;
        }


        .modal-header {

            display: flex;

            align-items: center;

            justify-content: space-between;

            padding:
                18px 22px;

            border-bottom:
                1px solid #e5e7eb;
        }


        .modal-title {

            color: #9a3412;

            font-size: 17px;

            font-weight: bold;
        }


        .close-btn {

            border: none;

            background: transparent;

            color: #6b7280;

            font-size: 24px;

            cursor: pointer;

            line-height: 1;
        }


        .close-btn:hover {

            color: #111827;
        }


        .modal-body {

            padding: 22px;
        }


        .modal-footer {

            display: flex;

            justify-content: flex-end;

            gap: 10px;

            padding:
                15px 22px;

            border-top:
                1px solid #e5e7eb;

            background: #f8fafc;
        }


        .btn-secondary {

            background: #e5e7eb;

            color: #374151;
        }


        .btn-secondary:hover {

            background: #d1d5db;
        }


        /* =================================================
           RESPONSIVE
           ================================================= */

        @media (
            max-width: 1100px
        ) {

            .summary-grid {

                grid-template-columns:
                    repeat(3, 1fr);
            }

            .card-grid {

                grid-template-columns: 1fr;
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


            .content {

                padding: 20px;
            }


            .summary-grid {

                grid-template-columns:
                    repeat(2, 1fr);
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


            .topbar {

                padding: 0 15px;

                height: auto;

                min-height: 75px;
            }


            .content {

                padding: 15px;
            }


            .admin-info > div:first-child {

                display: none;
            }
        }

    </style>

<link rel="stylesheet" href="admin-theme.css">

<link rel="stylesheet" href="tmu-updates.css?v=20261019b">
<script src="tmu-updates.js?v=20261019" defer></script>
<style>
/* Self-contained organization search layout. */
#tqTodaSearchBar{display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:10px 16px;margin:16px 0;padding:0 0 14px;border-bottom:1px solid #e2e8f0;box-sizing:border-box;min-width:0;width:100%}
#tqTodaSearchBar .tq-toda-search-field{flex:1 1 260px;min-width:0;max-width:440px}
#tqTodaSearchBar label{display:block;margin:0 0 7px;color:#334155;font:600 13px Arial,Helvetica,sans-serif}
#tqTodaSearchBar .tq-toda-search-input{display:flex;align-items:center;gap:9px;box-sizing:border-box;width:100%;min-width:0;height:40px;padding:0 12px;border:1px solid #cbd5e1;border-radius:8px;background:#fff}
#tqTodaSearchBar .tq-toda-search-input>svg{display:block;width:17px!important;height:17px!important;min-width:17px;max-width:17px;flex:0 0 17px;color:#64748b;fill:none;stroke:currentColor}
#tqTodaSearchBar #tqTodaListSearch{display:block;box-sizing:border-box;flex:1 1 0;width:100%;min-width:0;height:38px;margin:0;padding:8px 0!important;border:0!important;border-radius:0;background:transparent!important;box-shadow:none!important;outline:none;color:#1f2937;font:14px Arial,Helvetica,sans-serif}
#tqTodaSearchBar .tq-toda-search-input:focus-within{border-color:#f97316;outline:2px solid #ffedd5;outline-offset:1px}
#tqTodaSearchBar #tqTodaListCount{display:block;flex:0 1 auto;min-width:0;margin:0;padding:0 0 11px;color:#64748b;font:13px Arial,Helvetica,sans-serif;white-space:normal}
@media(max-width:600px){#tqTodaSearchBar .tq-toda-search-field{flex-basis:100%;max-width:none}#tqTodaSearchBar #tqTodaListCount{padding:0}}
</style>
<style>
#tqTodaGrid{display:block!important;width:100%;min-width:0}
#tqTodaGrid>.card{width:100%;box-sizing:border-box;min-width:0}
.tq-toda-add-toolbar{display:flex;justify-content:flex-end;margin:0 0 14px}
.tq-open-add{display:inline-flex;align-items:center;justify-content:center;min-height:38px;padding:10px 14px;border:1px solid #f97316;border-radius:8px;background:#f97316;color:white;font:600 14px Arial,Helvetica,sans-serif;cursor:pointer}
#tqAddTodaDialog{box-sizing:border-box;width:min(520px,92vw);max-width:92vw;max-height:88vh;margin:auto;padding:0;border:0;border-radius:12px;background:#fff;color:#1f2937;box-shadow:0 20px 60px rgba(0,0,0,.25);overflow:auto}
#tqAddTodaDialog:not([open]){display:none!important}
#tqAddTodaDialog::backdrop{background:rgba(15,23,42,.6)}
#tqAddTodaDialog>.card{box-sizing:border-box;width:100%;margin:0;padding:22px;border:0;border-radius:12px;box-shadow:none}
.tq-add-dialog-header{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:8px}
#tqAddTodaTitle{margin:0;font-size:20px;color:#9a3412;line-height:1.35}
#tqAddTodaDialog .tq-add-close{display:inline-flex;align-items:center;justify-content:center;flex:0 0 32px;width:32px;height:32px;padding:0;border:1px solid #fed7aa;border-radius:7px;background:#fff7ed;color:#c2410c;font-size:18px;cursor:pointer}
#tqAddTodaDialog .tq-add-cancel{margin-left:8px;padding:10px 15px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#475569;font:600 14px Arial,Helvetica,sans-serif;cursor:pointer}
#tqAddTodaDialog input,#tqAddTodaDialog textarea{box-sizing:border-box;max-width:100%}
#tqTodaListTable .btn-edit{display:inline-flex!important;align-items:center;justify-content:center;box-sizing:border-box;width:30px!important;height:30px!important;min-width:30px!important;min-height:30px!important;margin:0;padding:0!important;border:1px solid #fdba74;border-radius:7px;background:#fff7ed;color:#c2410c;cursor:pointer;vertical-align:middle}
#tqTodaListTable .btn-edit>svg{display:block;width:14px!important;height:14px!important;min-width:14px;max-width:14px;flex:0 0 14px;stroke:currentColor;fill:none}
#tqTodaListTable .btn-edit:hover{background:#ffedd5;border-color:#f97316}
#tqTodaListTable .btn-edit:focus-visible,.tq-open-add:focus-visible,#tqAddTodaDialog button:focus-visible{outline:2px solid #f97316;outline-offset:3px}
</style>
<!-- Shared TMU header and navigation; refresh versions when the shared files change. -->
<script src="admin-header.js?v=<?= (int) @filemtime(__DIR__.'/admin-header.js') ?>" data-tq-header="1" defer></script>
<script src="admin-nav.js?v=<?= (int) @filemtime(__DIR__.'/admin-nav.js') ?>" defer></script>
<style>
#tqTodaSearchBar{display:flex;align-items:end;gap:12px;flex-wrap:wrap}#tqTodaSearchBar .tq-toda-search-field{flex:1 1 280px;min-width:200px}.tq-toda-sort-field{flex:0 1 180px}.tq-toda-sort-field label{display:block;font:600 13px Arial;color:#475569;margin-bottom:6px}.tq-toda-sort-field select{width:100%;height:40px;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#334155;font:14px Arial}#tqTodaListTable td{font-size:16px;line-height:1.5}#tqTodaListTable th{font-size:14px}#tqTodaListTable td strong{font-weight:600}.tq-toda-filter-card{cursor:pointer;border:1px solid transparent}.tq-toda-filter-card[aria-pressed=true]{border-color:#f97316;background:#fff7ed}.tq-toda-filter-card:focus-visible{outline:3px solid #fed7aa;outline-offset:2px}#tqTodaListCount{color:#64748b;font:14px Arial;white-space:nowrap}
</style><style>.tq-delete-toda-form{display:inline-block;margin:0 0 0 6px;vertical-align:middle}.tq-delete-toda-button{display:inline-flex;align-items:center;justify-content:center;gap:5px;min-height:32px;padding:6px 9px;border:1px solid #fecaca;border-radius:7px;background:#fef2f2;color:#b91c1c;font:600 13px Arial,Helvetica,sans-serif;cursor:pointer}.tq-delete-toda-button:hover{background:#fee2e2}.tq-delete-toda-button:focus-visible{outline:2px solid #dc2626;outline-offset:2px}</style></head>


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

        <a href="toda.php" class="active" aria-current="page">
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
                    TODA Organizations
                </div>

                <div class="page-subtitle">
                    Manage Tricycle Operators and Drivers Association organizations
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


            <!-- =================================================
                 ALERTS
                 ================================================= -->

            <?php if ($errorMessage !== ""): ?>

                <div class="alert alert-error">

                    <?= e($errorMessage) ?>

                </div>

            <?php endif; ?>


            <?php if ($successMessage !== ""): ?>

                <div class="alert alert-success">

                    <?= e($successMessage) ?>

                </div>

            <?php endif; ?>


            <!-- =================================================
                 SUMMARY
                 ================================================= -->

            <div class="summary-grid">


                <div class="summary-card tq-toda-filter-card" role="button" tabindex="0" data-toda-filter="all" aria-pressed="true">

                    <div class="summary-label">
                        Total TODA Organizations
                    </div>

                    <div class="summary-value">
                        <?= number_format($totalTodas) ?>
                    </div>

                    <div class="summary-small">
                        All registered TODA organizations
                    </div>

                </div>


                <div class="summary-card tq-toda-filter-card" role="button" tabindex="0" data-toda-filter="active" aria-pressed="false">

                    <div class="summary-label">
                        Active TODA
                    </div>

                    <div class="summary-value">
                        <?= number_format($activeTodas) ?>
                    </div>

                    <div class="summary-small">
                        Currently active organizations
                    </div>

                </div>


                <div class="summary-card tq-toda-filter-card" role="button" tabindex="0" data-toda-filter="inactive" aria-pressed="false">

                    <div class="summary-label">
                        Inactive TODA
                    </div>

                    <div class="summary-value">
                        <?= number_format($inactiveTodas) ?>
                    </div>

                    <div class="summary-small">
                        Currently inactive organizations
                    </div>

                </div>

            </div>


            <!-- =================================================
                 ADD + LIST
                 ================================================= -->

            <div class="card-grid" id="tqTodaGrid">


                <!-- =================================================
                     ADD TODA
                     ================================================= -->

<div class="tq-toda-add-toolbar"><button type="button" id="tqOpenAddToda" class="tq-open-add" aria-haspopup="dialog">＋ Add TODA Organization</button></div>
<dialog id="tqAddTodaDialog" class="tq-add-toda-dialog" aria-labelledby="tqAddTodaTitle" data-open-on-error="<?= ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_toda' && $errorMessage !== '') ? '1' : '0' ?>">
<div class="card">

                    <div class="tq-add-dialog-header"><h2 class="card-title" id="tqAddTodaTitle">Add TODA Organization</h2><button type="button" id="tqCloseAddToda" class="tq-add-close" aria-label="Close Add TODA Organization">✕</button></div>

                    <div class="card-description">
                        Register a new TODA organization.
                    </div>


                    <form
                        method="POST"
                        action=""
                    >
<input type="hidden" name="tq_csrf" value="<?= tqEscape(tqCsrf()) ?>">

                        <input
                            type="hidden"
                            name="action"
                            value="add_toda"
                        >


                        <div class="form-group">

                            <label
                                class="form-label"
                                for="name"
                            >
                                TODA Name
                            </label>

                            <input
                                type="text"
                                id="name"
                                name="name"
                                class="form-control"
                                value="<?= e($name) ?>"
                                placeholder="Enter TODA organization name"
                                required
                            >

                        </div>


                        <div class="form-group">

                            <label
                                class="form-label"
                                for="address"
                            >Terminal Address</label>

                            <textarea
                                id="address"
                                name="address"
                                class="form-control"
                                placeholder="Enter TODA terminal address"
                            ><?= e($address) ?></textarea>

                        </div>


                        <button
                            type="submit"
                            class="btn btn-primary"
                        >
                            Add TODA
                        </button>

<button type="button" id="tqCancelAddToda" class="tq-add-cancel">Cancel</button>
                    </form>

                </div>
</dialog>



                <!-- =================================================
                     TODA LIST
                     ================================================= -->

                <div class="card">

                    <div class="card-title">
                        TODA Organizations
                    </div>

                    <div class="card-description">
                        View and manage registered TODA organizations.
                    </div>


                    <div id="tqTodaSearchBar"><div class="tq-toda-search-field"><label for="tqTodaListSearch">Search organizations</label>
<div class="tq-toda-search-input"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/></svg>
<input id="tqTodaListSearch" type="search" placeholder="Search TODA name or terminal address" autocomplete="off" aria-controls="tqTodaListTable"></div></div>
<div class="tq-toda-sort-field"><label for="tqTodaSort">Sort by</label><select id="tqTodaSort"><option value="newest">Newest first</option><option value="oldest">Oldest first</option><option value="az">Name A–Z</option><option value="za">Name Z–A</option></select></div><div class="tq-toda-sort-field"><label for="tqTodaStatus">Status</label><select id="tqTodaStatus"><option value="all">All organizations</option><option value="active">Active</option><option value="inactive">Inactive</option></select></div><span id="tqTodaListCount" role="status" aria-live="polite"></span></div>
<div class="table-wrapper">

                        <table id="tqTodaListTable">

                            <thead>

                                <tr>

                                    <th>
                                        TODA Name
                                    </th>

                                    <th>Terminal Address</th>

                                    <th>
                                        Status
                                    </th>

                                    <th>
                                        Created
                                    </th>

                                    <th>
                                        Action
                                    </th>

                                </tr>

                            </thead>


                            <tbody>


                                <?php if (empty($todas)): ?>

                                    <tr>

                                        <td
                                            colspan="5"
                                            class="empty"
                                        >
                                            No TODA organizations found.
                                        </td>

                                    </tr>


                                <?php else: ?>


                                    <?php foreach ($todas as $toda): ?>

                                        <?php

                                        $isActive =
                                            (
                                                $toda["status"] === true ||
                                                $toda["status"] === "true" ||
                                                $toda["status"] === 1 ||
                                                $toda["status"] === "1"
                                            );

                                        ?>


                                        <tr data-org-row data-toda-status="<?=$isActive?'active':'inactive'?>" data-toda-created="<?=e($toda['created_at']??'')?>">


                                            <td>

                                                <strong>
                                                    <?= e(
                                                        $toda["name"]
                                                        ?? ""
                                                    ) ?>
                                                </strong>

                                            </td>


                                            <td>

                                                <?= e(
                                                    $toda["address"]
                                                    ?? "—"
                                                ) ?>

                                            </td>


                                            <td>

                                                <?php if ($isActive): ?>

                                                    <span class="status-active">
                                                        Active
                                                    </span>

                                                <?php else: ?>

                                                    <span class="status-inactive">
                                                        Inactive
                                                    </span>

                                                <?php endif; ?>

                                            </td>


                                            <td>

                                                <span class="date-text">

                                                    <?php

                                                    if (
                                                        !empty(
                                                            $toda["created_at"]
                                                        )
                                                    ) {

                                                        echo e(
                                                            date(
                                                                "M d, Y",
                                                                strtotime(
                                                                    $toda["created_at"]
                                                                )
                                                            )
                                                        );

                                                    } else {

                                                        echo "—";
                                                    }

                                                    ?>

                                                </span>

                                            </td>


                                            <td>

                                                <button
                                                    type="button"
                                                    class="btn-edit" title="Edit TODA organization" aria-label="Edit TODA organization"
                                                    data-id="<?= e($toda["id"]) ?>"
                                                    data-name="<?= e($toda["name"] ?? "") ?>"
                                                    data-address="<?= e($toda["address"] ?? "") ?>"
                                                    data-status="<?= $isActive ? "1" : "0" ?>"
                                                    onclick="openEditModal(this)"
                                                ><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m16 3 5 5-12 12-6 1 1-6Z"/><path d="m14 5 5 5"/></svg></button>
<form method="post" action="toda.php" class="tq-delete-toda-form" data-delete-toda-name="<?=e($toda['name']??'this organization')?>">
<input type="hidden" name="tq_csrf" value="<?=tqEscape(tqCsrf())?>">
<input type="hidden" name="action" value="delete_toda"><input type="hidden" name="id" value="<?=e($toda['id'])?>">
<button type="submit" class="tq-delete-toda-button" aria-label="Delete <?=e($toda['name']??'TODA organization')?>"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7M14 10v7"/></svg><span>Delete</span></button>
</form>


                                            </td>


                                        </tr>


                                    <?php endforeach; ?>


                                <?php endif; ?>


                            </tbody>

                        </table>

                    </div>

                </div>


            </div>


        </section>

    </main>

</div>


<!-- =====================================================
     EDIT MODAL
     ===================================================== -->

<div
    class="modal"
    id="editModal"
>


    <div class="modal-content">


        <form
            method="POST"
            action=""
        >
<input type="hidden" name="tq_csrf" value="<?= tqEscape(tqCsrf()) ?>">


            <input
                type="hidden"
                name="action"
                value="edit_toda"
            >


            <input
                type="hidden"
                name="id"
                id="editId"
            >


            <!-- =================================================
                 MODAL HEADER
                 ================================================= -->

            <div class="modal-header">

                <div class="modal-title">
                    Edit TODA Organization
                </div>


                <button
                    type="button"
                    class="close-btn"
                    onclick="closeEditModal()"
                >
                    ×
                </button>

            </div>


            <!-- =================================================
                 MODAL BODY
                 ================================================= -->

            <div class="modal-body">


                <div class="form-group">

                    <label
                        class="form-label"
                        for="editName"
                    >
                        TODA Name
                    </label>

                    <input
                        type="text"
                        name="name"
                        id="editName"
                        class="form-control"
                        required
                    >

                </div>


                <div class="form-group">

                    <label
                        class="form-label"
                        for="editAddress"
                    >Terminal Address</label>

                    <textarea
                        name="address"
                        id="editAddress"
                        class="form-control"
                    ></textarea>

                </div>


                <div class="form-group">

                    <label
                        class="form-label"
                        for="editStatus"
                    >
                        Status
                    </label>

                    <select
                        name="status"
                        id="editStatus"
                        class="form-control"
                        required
                    >

                        <option value="1">
                            Active
                        </option>

                        <option value="0">
                            Inactive
                        </option>

                    </select>

                </div>


            </div>


            <!-- =================================================
                 MODAL FOOTER
                 ================================================= -->

            <div class="modal-footer">

                <button
                    type="button"
                    class="btn btn-secondary"
                    onclick="closeEditModal()"
                >
                    Cancel
                </button>


                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    Save Changes
                </button>

            </div>


        </form>

    </div>

</div>


<script>

// =====================================================
// OPEN EDIT MODAL
// =====================================================

function openEditModal(button)
{

    const id =
        button.getAttribute(
            "data-id"
        );


    const name =
        button.getAttribute(
            "data-name"
        );


    const address =
        button.getAttribute(
            "data-address"
        );


    const status =
        button.getAttribute(
            "data-status"
        );


    document.getElementById(
        "editId"
    ).value =
        id;


    document.getElementById(
        "editName"
    ).value =
        name;


    document.getElementById(
        "editAddress"
    ).value =
        address;


    document.getElementById(
        "editStatus"
    ).value =
        status;


    document.getElementById(
        "editModal"
    ).classList.add(
        "show"
    );

}


// =====================================================
// CLOSE EDIT MODAL
// =====================================================

function closeEditModal()
{

    document.getElementById(
        "editModal"
    ).classList.remove(
        "show"
    );

}


// =====================================================
// CLOSE WHEN CLICKING OUTSIDE MODAL
// =====================================================

document.getElementById(
    "editModal"
).addEventListener(
    "click",
    function(event)
    {

        if (
            event.target === this
        ) {

            closeEditModal();

        }

    }
);


// =====================================================
// ESC KEY
// =====================================================

document.addEventListener(
    "keydown",
    function(event)
    {

        if (
            event.key === "Escape"
        ) {

            closeEditModal();

        }

    }
);

</script>


<script>
(function () {
    const dialog = document.getElementById('tqAddTodaDialog');
    const trigger = document.getElementById('tqOpenAddToda');
    if (!dialog || !trigger) return;
    function open() {
        if (!dialog.open) dialog.showModal();
        const input = dialog.querySelector('input[name="name"]');
        if (input) input.focus();
    }
    function close() { dialog.close(); trigger.focus(); }
    trigger.addEventListener('click', open);
    document.getElementById('tqCloseAddToda').addEventListener('click', close);
    document.getElementById('tqCancelAddToda').addEventListener('click', close);
    dialog.addEventListener('click', function (event) {
        if (event.target !== dialog) return;
        const box = dialog.getBoundingClientRect();
        if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) close();
    });
    if (dialog.dataset.openOnError === '1') open();
})();
</script>
<script>
(()=>{const table=document.getElementById('tqTodaListTable'),search=document.getElementById('tqTodaListSearch'),sort=document.getElementById('tqTodaSort'),status=document.getElementById('tqTodaStatus'),count=document.getElementById('tqTodaListCount');if(!table||!search)return;const body=table.tBodies[0],rows=Array.from(body.querySelectorAll('[data-org-row]')),cards=Array.from(document.querySelectorAll('[data-toda-filter]'));const empty=document.createElement('tr');empty.innerHTML='<td colspan="5" class="empty">No organizations match your filters.</td>';body.appendChild(empty);const collator=new Intl.Collator(undefined,{sensitivity:'base',numeric:true});function update(){const term=search.value.trim().toLowerCase();let visible=0;rows.sort((a,b)=>{const name=collator.compare(a.cells[0].textContent.trim(),b.cells[0].textContent.trim());if(sort.value==='az')return name;if(sort.value==='za')return -name;const ad=Date.parse(a.dataset.todaCreated)||0,bd=Date.parse(b.dataset.todaCreated)||0;return(sort.value==='oldest'?ad-bd:bd-ad)||name;});for(const row of rows){body.appendChild(row);const text=(row.cells[0].textContent+' '+row.cells[1].textContent).toLowerCase();row.hidden=!(text.includes(term)&&(status.value==='all'||row.dataset.todaStatus===status.value));if(!row.hidden)visible++;}body.appendChild(empty);empty.hidden=visible>0||rows.length===0;count.textContent=visible+' of '+rows.length+' organizations';cards.forEach(card=>card.setAttribute('aria-pressed',String(card.dataset.todaFilter===status.value)));}cards.forEach(card=>{function apply(){status.value=card.dataset.todaFilter;update();}card.addEventListener('click',apply);card.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();apply();}});});search.addEventListener('input',update);sort.addEventListener('change',update);status.addEventListener('change',update);update();})();
</script><script>document.querySelectorAll('.tq-delete-toda-form').forEach(form=>form.addEventListener('submit',event=>{const name=form.dataset.deleteTodaName||'this organization';if(!window.confirm('Delete '+name+' permanently? Only organizations with no linked records can be deleted. Use Deactivate to keep its records.'))event.preventDefault();}));</script></body>

</html>
