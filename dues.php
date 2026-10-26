<?php

// =====================================================
// TRIKE-Q DUES MANAGEMENT
// =====================================================

session_start();

if (
    !isset($_SESSION["access_token"]) ||
    $_SESSION["access_token"] === ""
) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . "/config/supabase.php";

$message = "";
$messageType = "";
$adminName = "TMU Admin";

if (!empty($_SESSION["user_id"])) {
    $adminResult = supabaseRequest(
        "admin",
        "GET",
        null,
        "user_id=eq." . urlencode($_SESSION["user_id"]) . "&select=name"
    );

    if (
        $adminResult["status"] >= 200 &&
        $adminResult["status"] < 300 &&
        !empty($adminResult["data"][0]["name"])
    ) {
        $adminName = $adminResult["data"][0]["name"];
    }
}


// =====================================================
// ADD OR DELETE DUES
// =====================================================

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST["action"] ?? "";


    // =================================================
    // ADD DUE
    // =================================================

    if ($action === "add") {

        $driverId =
            trim($_POST["driver_id"] ?? "");

        $amount =
            trim($_POST["amount"] ?? "");

        $collectionDate =
            trim($_POST["collection_date"] ?? "");

        $remarks =
            trim($_POST["remarks"] ?? "");


        if (
            $driverId === "" ||
            $amount === "" ||
            $collectionDate === ""
        ) {

            $message =
                "Please complete all required fields.";

            $messageType =
                "error";

        } else {

            // =============================================
            // GET SELECTED DRIVER
            // =============================================

            $driverResult =
                supabaseRequest(
                    "drivers",
                    "GET",
                    null,
                    "select=id,name,toda_id&id=eq." .
                    urlencode($driverId)
                );


            if (
                $driverResult["status"] !== 200 ||
                empty($driverResult["data"])
            ) {

                $message =
                    "Selected driver could not be found.";

                $messageType =
                    "error";

            } else {

                $driver =
                    $driverResult["data"][0];


                $dueData = [

                    "driver_id" =>
                        $driver["id"],

                    "toda_id" =>
                        $driver["toda_id"] ?? null,

                    "amount" =>
                        (float)$amount,

                    "remarks" =>
                        $remarks,

                    "collection_date" =>
                        $collectionDate,

                    "created_by" =>
                        $_SESSION["user_id"] ?? null
                ];


                // =========================================
                // INSERT COLLECTION
                // =========================================

                $insertResult =
                    supabaseRequest(
                        "dues",
                        "POST",
                        $dueData
                    );


                if (
                    $insertResult["status"] >= 200 &&
                    $insertResult["status"] < 300
                ) {

                    header(
                        "Location: dues.php?success=added"
                    );

                    exit;

                } else {

                    $message =
                        "Unable to save collection.";

                    if (
                        isset(
                            $insertResult["data"]["message"]
                        )
                    ) {

                        $message .=
                            " " .
                            $insertResult["data"]["message"];
                    }

                    $messageType =
                        "error";
                }
            }
        }
    }


    // =================================================
    // DELETE DUE
    // =================================================

    if ($action === "delete") {

        $dueId =
            trim($_POST["due_id"] ?? "");


        if ($dueId === "") {

            $message =
                "Invalid collection record.";

            $messageType =
                "error";

        } else {

            $deleteResult =
                supabaseRequest(
                    "dues",
                    "DELETE",
                    null,
                    "id=eq." .
                    urlencode($dueId)
                );


            if (
                $deleteResult["status"] >= 200 &&
                $deleteResult["status"] < 300
            ) {

                header(
                    "Location: dues.php?success=deleted"
                );

                exit;

            } else {

                $message =
                    "Unable to delete collection.";

                if (
                    isset(
                        $deleteResult["data"]["message"]
                    )
                ) {

                    $message .=
                        " " .
                        $deleteResult["data"]["message"];
                }

                $messageType =
                    "error";
            }
        }
    }
}


// =====================================================
// SUCCESS MESSAGES
// =====================================================

if (isset($_GET["success"])) {

    if ($_GET["success"] === "added") {

        $message =
            "Dues collection recorded successfully.";

        $messageType =
            "success";
    }


    if ($_GET["success"] === "deleted") {

        $message =
            "Dues record deleted successfully.";

        $messageType =
            "success";
    }
}


// =====================================================
// GET TODA ORGANIZATIONS
// =====================================================

$todaResult =
    supabaseRequest(
        "toda_organizations",
        "GET",
        null,
        "select=id,name&status=eq.true&order=name.asc"
    );

$todas = [];

if (
    $todaResult["status"] === 200 &&
    is_array($todaResult["data"])
) {

    $todas =
        $todaResult["data"];
}


// =====================================================
// GET DRIVERS
// =====================================================

$driverResult =
    supabaseRequest(
        "drivers",
        "GET",
        null,
        "select=id,name,toda_id&order=name.asc"
    );

$drivers = [];

if (
    $driverResult["status"] === 200 &&
    is_array($driverResult["data"])
) {

    $drivers =
        $driverResult["data"];
}


// =====================================================
// GET DUES
// =====================================================

$duesResult =
    supabaseRequest(
        "dues",
        "GET",
        null,
        "select=*,drivers(name),toda_organizations(name)&order=collection_date.desc,created_at.desc"
    );

$dues = [];

if (
    $duesResult["status"] === 200 &&
    is_array($duesResult["data"])
) {

    $dues =
        $duesResult["data"];
}


// =====================================================
// TOTALS
// =====================================================

$totalRecords =
    count($dues);

$totalCollected =
    0;

foreach ($dues as $due) {

    $totalCollected +=
        (float)(
            $due["amount"] ?? 0
        );
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
    Dues Management - TRIKE-Q TMU Admin
</title>


<style>

/* =====================================================
   GLOBAL
===================================================== */

* {
    box-sizing: border-box;
}

html {
    scroll-behavior: smooth;
}

body {

    margin: 0;

    font-family:
        Inter,
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        Arial,
        Helvetica,
        sans-serif;

    background: #f8fafc;

    color: #1f2937;

    font-size: 14px;

    line-height: 1.5;
}

button,
input,
textarea,
select {
    font-family: inherit;
}


/* =====================================================
   SIDEBAR
===================================================== */

.sidebar {

    position: fixed;

    left: 0;
    top: 0;
    bottom: 0;

    width: 250px;

    background: #1f1f1f;

    color: white;

    padding:
        24px 16px;

    overflow-y: auto;

    z-index: 100;
}

.logo {

    text-align: center;

    font-size: 25px;

    font-weight: 800;

    letter-spacing: 0.5px;

    color: #f97316;

    margin-bottom: 5px;
}

.logo-subtitle {

    text-align: center;

    font-size: 11px;

    font-weight: 600;

    letter-spacing: 1px;

    color: #9ca3af;

    margin-bottom: 30px;
}

.menu-title {

    font-size: 10px;

    font-weight: 700;

    color: #6b7280;

    text-transform: uppercase;

    letter-spacing: 1.2px;

    margin:
        20px 10px 8px;
}

.sidebar a {

    display: flex;

    align-items: center;

    gap: 10px;

    color: #d1d5db;

    text-decoration: none;

    padding:
        12px 13px;

    margin-bottom: 4px;

    border-radius: 8px;

    font-size: 13px;

    font-weight: 500;

    transition:
        background 0.2s,
        color 0.2s,
        transform 0.2s;
}

.sidebar a:hover {

    background: #2d2d2d;

    color: white;

    transform: translateX(2px);
}

.sidebar a.active {

    background: #f97316;

    color: white;

    font-weight: 700;

    box-shadow:
        0 4px 10px
        rgba(
            249,
            115,
            22,
            0.18
        );
}

.icon {

    width: 20px;

    text-align: center;

    font-size: 16px;

    flex-shrink: 0;
}


/* =====================================================
   MAIN
===================================================== */

.main {

    margin-left: 250px;

    width:
        calc(
            100% - 250px
        );

    min-height: 100vh;
}


/* =====================================================
   TOPBAR
===================================================== */

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

    font-weight: 800;

    color: #9a3412;

    letter-spacing: -0.3px;
}

.page-subtitle {

    margin-top: 3px;

    font-size: 13px;

    color: #6b7280;
}

.admin-info {

    display: flex;

    align-items: center;

    gap: 11px;
}

.admin-name {

    font-size: 14px;

    font-weight: 700;

    color: #1f2937;

    text-align: right;
}

.admin-role {

    font-size: 11px;

    color: #6b7280;

    text-align: right;

    margin-top: 1px;
}

.admin-avatar {

    width: 40px;

    height: 40px;

    border-radius: 50%;

    background: #f97316;

    color: white;

    display: flex;

    align-items: center;

    justify-content: center;

    font-weight: 800;

    font-size: 15px;

    box-shadow:
        0 3px 8px
        rgba(
            249,
            115,
            22,
            0.2
        );
}


/* =====================================================
   CONTENT
===================================================== */

.content {

    padding: 30px;
}


/* =====================================================
   MESSAGE
===================================================== */

.message {

    padding:
        14px 16px;

    border-radius: 10px;

    margin-bottom: 20px;

    font-size: 14px;

    font-weight: 600;
}

.message.success {

    background: #f0fdf4;

    border:
        1px solid #86efac;

    color: #166534;
}

.message.error {

    background: #fef2f2;

    border:
        1px solid #fca5a5;

    color: #991b1b;
}


/* =====================================================
   SUMMARY CARDS
===================================================== */

.cards {

    display: grid;

    grid-template-columns:
        repeat(2, 1fr);

    gap: 18px;

    margin-bottom: 25px;
}

.summary-card {

    background: white;

    border:
        1px solid #eef0f3;

    border-radius: 12px;

    padding: 21px 22px;

    box-shadow:
        0 2px 8px
        rgba(
            0,
            0,
            0,
            0.05
        );

    position: relative;

    overflow: hidden;
}

.summary-card::before {

    content: "";

    position: absolute;

    left: 0;
    top: 0;
    bottom: 0;

    width: 4px;

    background: #f97316;
}

.card-title {

    color: #64748b;

    font-size: 12px;

    font-weight: 700;

    text-transform: uppercase;

    letter-spacing: 0.5px;

    margin-bottom: 7px;
}

.card-value {

    font-size: 27px;

    line-height: 1.2;

    font-weight: 800;

    color: #9a3412;
}


/* =====================================================
   FORM CARD
===================================================== */

.form-container {

    background: white;

    border:
        1px solid #eef0f3;

    border-radius: 12px;

    padding: 25px;

    box-shadow:
        0 2px 8px
        rgba(
            0,
            0,
            0,
            0.05
        );

    margin-bottom: 25px;
}

.form-header {

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 20px;

    padding-bottom: 17px;

    margin-bottom: 23px;

    border-bottom:
        1px solid #e5e7eb;
}

.form-title {

    font-size: 18px;

    font-weight: 800;

    color: #9a3412;
}

.form-subtitle {

    margin-top: 4px;

    color: #6b7280;

    font-size: 12px;
}

.required-note {

    color: #64748b;

    font-size: 12px;
}

.required {

    color: #dc2626;

    font-weight: 800;
}


/* =====================================================
   FORM GRID
===================================================== */

.form-grid {

    display: grid;

    grid-template-columns:
        repeat(2, 1fr);

    gap: 18px;
}

.form-group {

    display: flex;

    flex-direction: column;
}

.form-group.full {

    grid-column:
        1 / -1;
}

label {

    font-size: 13px;

    font-weight: 700;

    color: #374151;

    margin-bottom: 7px;
}

input,
textarea {

    width: 100%;

    border:
        1px solid #d1d5db;

    border-radius: 8px;

    background: white;

    color: #1f2937;

    outline: none;

    font-size: 13px;

    padding:
        10px 12px;

    transition:
        border 0.2s,
        box-shadow 0.2s;
}

input {

    height: 43px;
}

input::placeholder,
textarea::placeholder {

    color: #9ca3af;
}

input:focus,
textarea:focus {

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

textarea {

    min-height: 90px;

    resize: vertical;
}


/* =====================================================
   DRIVER SELECTOR
===================================================== */

.driver-wrapper {

    position: relative;

    width: 100%;
}

.driver-selector {

    display: flex;

    width: 100%;
}

.driver-selector input {

    border-radius:
        8px 0 0 8px;

    border-right: none;

    flex: 1;
}

.driver-dropdown-btn {

    width: 46px;

    height: 43px;

    padding: 0;

    border: none;

    border-radius:
        0 8px 8px 0;

    background: #f97316;

    color: white;

    font-size: 15px;

    cursor: pointer;

    transition: background 0.2s;
}

.driver-dropdown-btn:hover {

    background: #ea580c;
}

.driver-list {

    position: absolute;

    left: 0;
    right: 0;

    top: calc(100% + 5px);

    background: white;

    border:
        1px solid #d1d5db;

    border-radius: 8px;

    max-height: 230px;

    overflow-y: auto;

    z-index: 1000;

    box-shadow:
        0 8px 20px
        rgba(
            0,
            0,
            0,
            0.12
        );

    display: none;

    padding: 5px;
}

.driver-option {

    padding:
        10px 12px;

    border-radius: 6px;

    cursor: pointer;

    font-size: 13px;

    font-weight: 500;

    color: #374151;

    transition:
        background 0.15s,
        color 0.15s;
}

.driver-option:hover {

    background: #fff7ed;

    color: #c2410c;
}

.no-driver {

    padding: 12px;

    color: #6b7280;

    font-size: 13px;

    text-align: center;
}


/* =====================================================
   HELP TEXT
===================================================== */

.help-text {

    margin-top: 5px;

    color: #9ca3af;

    font-size: 11px;
}


/* =====================================================
   BUTTON
===================================================== */

.button-area {

    margin-top: 23px;

    padding-top: 20px;

    border-top:
        1px solid #e5e7eb;

    display: flex;

    justify-content: flex-end;
}

.btn-orange {

    background: #f97316;

    color: white;

    border: none;

    padding:
        11px 22px;

    border-radius: 8px;

    font-size: 13px;

    font-weight: 700;

    cursor: pointer;

    transition:
        background 0.2s,
        transform 0.2s,
        box-shadow 0.2s;
}

.btn-orange:hover {

    background: #ea580c;

    transform: translateY(-1px);

    box-shadow:
        0 4px 10px
        rgba(
            249,
            115,
            22,
            0.20
        );
}


/* =====================================================
   COLLECTION TABLE CARD
===================================================== */

.table-container {

    background: white;

    border:
        1px solid #eef0f3;

    border-radius: 12px;

    padding: 25px;

    box-shadow:
        0 2px 8px
        rgba(
            0,
            0,
            0,
            0.05
        );

    overflow-x: auto;
}

.table-header {

    display: flex;

    align-items: center;

    justify-content: space-between;

    margin-bottom: 18px;

    gap: 15px;
}

.table-title {

    font-size: 18px;

    font-weight: 800;

    color: #9a3412;
}

.table-description {

    color: #6b7280;

    font-size: 12px;

    margin-top: 3px;
}

.record-count {

    background: #fff7ed;

    color: #c2410c;

    border:
        1px solid #fed7aa;

    padding:
        5px 9px;

    border-radius: 20px;

    font-size: 11px;

    font-weight: 700;

    white-space: nowrap;
}


/* =====================================================
   TABLE
===================================================== */

table {

    width: 100%;

    border-collapse: collapse;

    min-width: 850px;
}

thead th {

    background: #fff7ed;

    color: #9a3412;

    text-align: left;

    padding:
        12px 13px;

    font-size: 11px;

    font-weight: 800;

    text-transform: uppercase;

    letter-spacing: 0.4px;

    border-bottom:
        2px solid #fed7aa;

    white-space: nowrap;
}

tbody td {

    padding:
        13px;

    border-bottom:
        1px solid #edf0f2;

    font-size: 13px;

    color: #374151;

    vertical-align: middle;
}

tbody tr {

    transition:
        background 0.15s;
}

tbody tr:hover {

    background: #fffaf5;
}

.driver-name {

    font-weight: 700;

    color: #1f2937;
}

.toda-name {

    color: #4b5563;

    font-size: 12px;
}

.amount {

    font-weight: 800;

    color: #ea580c;

    white-space: nowrap;
}

.date-value {

    color: #4b5563;

    white-space: nowrap;
}

.remarks {

    max-width: 250px;

    color: #6b7280;

    font-size: 12px;

    line-height: 1.5;
}


/* =====================================================
   DELETE BUTTON
===================================================== */

.delete-btn {

    background: #fee2e2;

    color: #b91c1c;

    border:
        1px solid #fecaca;

    padding:
        7px 11px;

    border-radius: 7px;

    font-size: 11px;

    font-weight: 700;

    cursor: pointer;

    transition:
        background 0.2s,
        color 0.2s;
}

.delete-btn:hover {

    background: #dc2626;

    color: white;
}


/* =====================================================
   EMPTY STATE
===================================================== */

.empty-state {

    text-align: center;

    padding:
        45px 20px;

    color: #6b7280;
}

.empty-icon {

    font-size: 30px;

    margin-bottom: 10px;

    color: #f97316;
}

.empty-title {

    font-size: 14px;

    font-weight: 700;

    color: #374151;

    margin-bottom: 4px;
}

.empty-text {

    font-size: 12px;

    color: #9ca3af;
}


/* =====================================================
   RESPONSIVE
===================================================== */

@media (max-width: 1000px) {

    .sidebar {

        width: 220px;
    }

    .main {

        margin-left: 220px;

        width:
            calc(
                100% - 220px
            );
    }

    .content {

        padding: 22px;
    }

}


@media (max-width: 800px) {

    .cards {

        grid-template-columns: 1fr;
    }

    .form-grid {

        grid-template-columns: 1fr;
    }

    .form-group.full {

        grid-column: auto;
    }

    .topbar {

        padding:
            0 20px;
    }

}


@media (max-width: 650px) {

    .sidebar {

        position: relative;

        width: 100%;

        height: auto;

        padding-bottom: 18px;
    }

    .main {

        margin-left: 0;

        width: 100%;
    }

    .topbar {

        height: auto;

        min-height: 75px;

        padding:
            15px;

        align-items: flex-start;
    }

    .admin-info {

        display: none;
    }

    .content {

        padding: 15px;
    }

    .form-container,
    .table-container {

        padding: 18px;
    }

    .form-header {

        display: block;
    }

    .required-note {

        margin-top: 8px;
    }

    .button-area {

        justify-content: stretch;
    }

    .btn-orange {

        width: 100%;
    }

    .table-header {

        align-items: flex-start;
    }

}

</style>

<link rel="stylesheet" href="admin-theme.css">

<!-- Shared TMU header and navigation; refresh versions when the shared files change. -->
<script src="admin-header.js?v=<?= (int) @filemtime(__DIR__.'/admin-header.js') ?>" data-tq-header="1" defer></script>
<script src="admin-nav.js?v=<?= (int) @filemtime(__DIR__.'/admin-nav.js') ?>" defer></script>
</head>


<body>


<!-- =====================================================
     SIDEBAR
===================================================== -->

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

        <a href="toda_feedback.php">
            <span class="icon">▤</span>
            TODA Feedback
        </a>

        <a href="dues.php" class="active" aria-current="page">
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


<!-- =====================================================
     MAIN
===================================================== -->

<main class="main">


    <!-- =================================================
         TOPBAR
    ================================================== -->

    <header class="topbar">

        <div>

            <div class="page-title">
                Dues Management
            </div>

            <div class="page-subtitle">
                Record and monitor driver dues and collection transactions
            </div>

        </div>


        <div class="admin-info">

            <div>

                <div class="admin-name">
                    <?= htmlspecialchars($adminName, ENT_QUOTES, "UTF-8") ?>
                </div>

                <div class="admin-role">
                    TMU Admin
                </div>

            </div>


            <div class="admin-avatar">
                <?= htmlspecialchars(strtoupper(substr(trim($adminName), 0, 1)), ENT_QUOTES, "UTF-8") ?>
            </div>

        </div>

    </header>


    <!-- =================================================
         CONTENT
    ================================================== -->

    <section class="content">


        <!-- =================================================
             MESSAGE
        ================================================== -->

        <?php if ($message !== ""): ?>

            <div
                class="message <?= htmlspecialchars(
                    $messageType
                ) ?>"
            >

                <?= htmlspecialchars(
                    $message
                ) ?>

            </div>

        <?php endif; ?>


        <!-- =================================================
             SUMMARY CARDS
        ================================================== -->

        <div class="cards">


            <div class="summary-card">

                <div class="card-title">
                    Total Collection Records
                </div>

                <div class="card-value">
                    <?= $totalRecords ?>
                </div>

            </div>


            <div class="summary-card">

                <div class="card-title">
                    Total Amount Collected
                </div>

                <div class="card-value">
                    ₱<?= number_format(
                        $totalCollected,
                        2
                    ) ?>
                </div>

            </div>


        </div>


        <!-- =================================================
             ADD COLLECTION
        ================================================== -->

        <div class="form-container">


            <div class="form-header">

                <div>

                    <div class="form-title">
                        Record Dues Collection
                    </div>

                    <div class="form-subtitle">
                        Enter the driver's collection information below.
                    </div>

                </div>


                <div class="required-note">

                    <span class="required">*</span>
                    Required fields

                </div>

            </div>


            <form
                method="POST"
                id="duesForm"
            >


                <input
                    type="hidden"
                    name="action"
                    value="add"
                >


                <input
                    type="hidden"
                    name="driver_id"
                    id="driver_id"
                    value=""
                >


                <div class="form-grid">


                    <!-- DRIVER -->

                    <div class="form-group full">

                        <label>

                            Driver Name
                            <span class="required">*</span>

                        </label>


                        <div class="driver-wrapper">

                            <div class="driver-selector">

                                <input
                                    type="text"
                                    id="driver_search"
                                    placeholder="Search or select a driver..."
                                    autocomplete="off"
                                    required
                                >


                                <button
                                    type="button"
                                    class="driver-dropdown-btn"
                                    id="driverDropdownButton"
                                    aria-label="Show drivers"
                                >
                                    ▼
                                </button>

                            </div>


                            <div
                                class="driver-list"
                                id="driverList"
                            >


                                <?php foreach ($drivers as $driver): ?>

                                    <div
                                        class="driver-option"
                                        data-id="<?= htmlspecialchars(
                                            $driver["id"]
                                        ) ?>"
                                        data-name="<?= htmlspecialchars(
                                            $driver["name"]
                                        ) ?>"
                                    >

                                        <?= htmlspecialchars(
                                            $driver["name"]
                                        ) ?>

                                    </div>

                                <?php endforeach; ?>


                            </div>

                        </div>


                        <div class="help-text">
                            Type the driver's name or use the dropdown button to select a registered driver.
                        </div>

                    </div>


                    <!-- AMOUNT -->

                    <div class="form-group">

                        <label>

                            Amount
                            <span class="required">*</span>

                        </label>


                        <input
                            type="number"
                            name="amount"
                            step="0.01"
                            min="0"
                            placeholder="Enter collection amount"
                            required
                        >


                        <div class="help-text">
                            Enter the amount collected from the driver.
                        </div>

                    </div>


                    <!-- DATE -->

                    <div class="form-group">

                        <label>

                            Collection Date
                            <span class="required">*</span>

                        </label>


                        <input
                            type="date"
                            name="collection_date"
                            value="<?= date("Y-m-d") ?>"
                            required
                        >


                        <div class="help-text">
                            Date when the collection was received.
                        </div>

                    </div>


                    <!-- REMARKS -->

                    <div class="form-group full">

                        <label>
                            Remarks
                        </label>


                        <textarea
                            name="remarks"
                            placeholder="Enter additional remarks or notes..."
                        ></textarea>


                        <div class="help-text">
                            Optional. Add any useful information about this collection.
                        </div>

                    </div>


                </div>


                <!-- BUTTON -->

                <div class="button-area">

                    <button
                        type="submit"
                        class="btn-orange"
                    >
                        Record Collection
                    </button>

                </div>


            </form>

        </div>


        <!-- =================================================
             COLLECTION RECORDS
        ================================================== -->

        <div class="table-container">


            <div class="table-header">

                <div>

                    <div class="table-title">
                        Collection Records
                    </div>

                    <div class="table-description">
                        View recorded dues collections and their associated drivers.
                    </div>

                </div>


                <div class="record-count">

                    <?= $totalRecords ?>

                    <?= $totalRecords === 1
                        ? "Record"
                        : "Records"
                    ?>

                </div>

            </div>


            <table>

                <thead>

                    <tr>

                        <th>
                            Driver Name
                        </th>

                        <th>
                            TODA Organization
                        </th>

                        <th>
                            Amount
                        </th>

                        <th>
                            Collection Date
                        </th>

                        <th>
                            Remarks
                        </th>

                        <th>
                            Action
                        </th>

                    </tr>

                </thead>


                <tbody>


                    <?php if (empty($dues)): ?>

                        <tr>

                            <td
                                colspan="6"
                                class="empty-state"
                            >

                                <div class="empty-icon">
                                    ₱
                                </div>

                                <div class="empty-title">
                                    No collection records found
                                </div>

                                <div class="empty-text">
                                    Recorded dues collections will appear here.
                                </div>

                            </td>

                        </tr>


                    <?php else: ?>


                        <?php foreach ($dues as $due): ?>

                            <tr>


                                <!-- DRIVER -->

                                <td>

                                    <div class="driver-name">

                                        <?= htmlspecialchars(
                                            $due["drivers"]["name"]
                                            ?? "Unknown Driver"
                                        ) ?>

                                    </div>

                                </td>


                                <!-- TODA -->

                                <td>

                                    <div class="toda-name">

                                        <?= htmlspecialchars(
                                            $due["toda_organizations"]["name"]
                                            ?? "No TODA"
                                        ) ?>

                                    </div>

                                </td>


                                <!-- AMOUNT -->

                                <td>

                                    <div class="amount">

                                        ₱<?= number_format(
                                            (float)(
                                                $due["amount"] ?? 0
                                            ),
                                            2
                                        ) ?>

                                    </div>

                                </td>


                                <!-- DATE -->

                                <td>

                                    <div class="date-value">

                                        <?= htmlspecialchars(
                                            $due["collection_date"]
                                            ?? "-"
                                        ) ?>

                                    </div>

                                </td>


                                <!-- REMARKS -->

                                <td>

                                    <div class="remarks">

                                        <?= htmlspecialchars(
                                            $due["remarks"]
                                            ?? "-"
                                        ) ?>

                                    </div>

                                </td>


                                <!-- ACTION -->

                                <td>


                                    <form
                                        method="POST"
                                        onsubmit="return confirm('Are you sure you want to delete this dues record?');"
                                    >


                                        <input
                                            type="hidden"
                                            name="action"
                                            value="delete"
                                        >


                                        <input
                                            type="hidden"
                                            name="due_id"
                                            value="<?= htmlspecialchars(
                                                $due["id"]
                                            ) ?>"
                                        >


                                        <button
                                            type="submit"
                                            class="delete-btn"
                                        >
                                            Delete
                                        </button>


                                    </form>


                                </td>


                            </tr>

                        <?php endforeach; ?>


                    <?php endif; ?>


                </tbody>

            </table>


        </div>


    </section>

</main>


<!-- =====================================================
     SEARCHABLE DRIVER DROPDOWN
===================================================== -->

<script>

const searchInput =
    document.getElementById(
        "driver_search"
    );

const dropdownButton =
    document.getElementById(
        "driverDropdownButton"
    );

const driverList =
    document.getElementById(
        "driverList"
    );

const driverIdInput =
    document.getElementById(
        "driver_id"
    );

const driverOptions =
    Array.from(
        document.querySelectorAll(
            ".driver-option"
        )
    );


/* =====================================================
   SHOW DROPDOWN
===================================================== */

function showDriverList() {

    driverList.style.display =
        "block";
}


/* =====================================================
   HIDE DROPDOWN
===================================================== */

function hideDriverList() {

    driverList.style.display =
        "none";
}


/* =====================================================
   FILTER DRIVERS
===================================================== */

function filterDrivers() {

    const searchValue =
        searchInput.value
            .toLowerCase()
            .trim();

    let visibleCount =
        0;


    driverOptions.forEach(
        function(option) {

            const driverName =
                option.dataset.name
                    .toLowerCase();


            if (
                driverName.includes(
                    searchValue
                )
            ) {

                option.style.display =
                    "block";

                visibleCount++;

            } else {

                option.style.display =
                    "none";
            }

        }
    );


    let noDriverMessage =
        document.getElementById(
            "noDriverMessage"
        );


    if (visibleCount === 0) {

        if (!noDriverMessage) {

            noDriverMessage =
                document.createElement(
                    "div"
                );

            noDriverMessage.id =
                "noDriverMessage";

            noDriverMessage.className =
                "no-driver";

            noDriverMessage.textContent =
                "No driver found.";

            driverList.appendChild(
                noDriverMessage
            );
        }

    } else {

        if (noDriverMessage) {

            noDriverMessage.remove();
        }
    }
}


/* =====================================================
   INPUT FOCUS
===================================================== */

searchInput.addEventListener(
    "focus",
    function() {

        showDriverList();

        filterDrivers();

    }
);


/* =====================================================
   INPUT SEARCH
===================================================== */

searchInput.addEventListener(
    "input",
    function() {

        /*
         * Clear the selected driver ID
         * when the admin changes the text.
         */
        driverIdInput.value =
            "";

        showDriverList();

        filterDrivers();

    }
);


/* =====================================================
   DROPDOWN BUTTON
===================================================== */

dropdownButton.addEventListener(
    "click",
    function(event) {

        event.stopPropagation();

        if (
            driverList.style.display ===
            "block"
        ) {

            hideDriverList();

        } else {

            showDriverList();

            filterDrivers();
        }

    }
);


/* =====================================================
   SELECT DRIVER
===================================================== */

driverOptions.forEach(
    function(option) {

        option.addEventListener(
            "click",
            function() {

                const driverId =
                    option.dataset.id;

                const driverName =
                    option.dataset.name;


                searchInput.value =
                    driverName;

                driverIdInput.value =
                    driverId;


                hideDriverList();

            }
        );

    }
);


/* =====================================================
   CLOSE DROPDOWN OUTSIDE
===================================================== */

document.addEventListener(
    "click",
    function(event) {

        const wrapper =
            document.querySelector(
                ".driver-wrapper"
            );


        if (
            !wrapper.contains(
                event.target
            )
        ) {

            hideDriverList();

        }

    }
);


/* =====================================================
   FORM VALIDATION
===================================================== */

document.getElementById(
    "duesForm"
).addEventListener(
    "submit",
    function(event) {

        if (
            driverIdInput.value === ""
        ) {

            event.preventDefault();

            alert(
                "Please select a driver from the dropdown."
            );

            searchInput.focus();

        }

    }
);

</script>


</body>

</html>