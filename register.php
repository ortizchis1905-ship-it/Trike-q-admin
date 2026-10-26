<?php

// =====================================================
// TRIKE-Q REGISTER DRIVER
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
require_once __DIR__ . '/includes/driver-photo.php';
require_once __DIR__ . '/tmu-tools.php';
require_once __DIR__ . '/includes/driver-fields.php';
tqRequireAdmin();
tqRequireCsrf();
$extraValues = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : [];

$message = "";
$messageType = "";


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
// FORM VALUES
// =====================================================

$firstName = "";
$lastName = "";
$contact = "";

$street = "";
$barangay = "";
$city = "";
$province = "";

$status = "Active";
$position = "Member";
$email = "";

$engineNumber = "";
$chassisNumber = "";
$tricycleNumber = "";
$plateNumber = "";
$motorcycleBrand = "";
$mtopNumber = "";

$todaId = "";

$lastRenewalDate = "";
$franchiseExpiryDate = "";


// =====================================================
// LOAD TODA ORGANIZATIONS
// =====================================================

$todaResult = supabaseRequest(
    "toda_organizations",
    "GET",
    null,
    "select=id,name&status=eq.true&order=name.asc"
);

$todas = [];

if (
    $todaResult["status"] >= 200 &&
    $todaResult["status"] < 300 &&
    is_array($todaResult["data"])
) {
    $todas = $todaResult["data"];
}


// =====================================================
// REGISTER DRIVER
// =====================================================

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // =================================================
    // GET FORM VALUES
    // =================================================

    $firstName =
        trim($_POST["first_name"] ?? "");

    $lastName =
        trim($_POST["last_name"] ?? "");

    $contact =
        trim($_POST["phone_number"] ?? "");


    // =================================================
    // SEPARATED ADDRESS
    // =================================================

$street =
        trim($_POST["street"] ?? "");

    $barangay =
        trim($_POST["barangay"] ?? "");

    $city =
        trim($_POST["city"] ?? "");

    $province =
        trim($_POST["province"] ?? "");


    // =================================================
    // OTHER INFORMATION
    // =================================================

    $status = 'Active'; // New registrations use the default status.

    $position =
        trim(
            $_POST["position"]
            ?? "Member"
        );

    $email =
        trim(
            $_POST["email"]
            ?? ""
        );


    // =================================================
    // VEHICLE INFORMATION
    // =================================================

    $engineNumber =
        trim(
            $_POST["engine_number"]
            ?? ""
        );

    $chassisNumber =
        trim(
            $_POST["chassis_number"]
            ?? ""
        );

    $tricycleNumber =
        trim(
            $_POST["tricycle_number"]
            ?? ""
        );

    $plateNumber =
        trim(
            $_POST["plate_number"]
            ?? ""
        );

    $motorcycleBrand =
        trim(
            $_POST["motorcycle_brand"]
            ?? ""
        );

    $mtopNumber =
        trim(
            $_POST["mtop_number"]
            ?? ""
        );


    // =================================================
    // TODA
    // =================================================

    $todaId =
        trim(
            $_POST["toda_id"]
            ?? ""
        );


    // =================================================
    // RENEWAL
    // =================================================

    $lastRenewalDate =
        trim(
            $_POST["last_renewal_date"]
            ?? ""
        );

    $franchiseExpiryDate =
        trim(
            $_POST["franchise_expiry_date"]
            ?? ""
        );


    // =================================================
    // VALIDATION
    // =================================================

    $validationErrors = [];
    $extraData = [];
    try { $extraData = tqDriverExtraValues($_POST); }
    catch (RuntimeException $exception) { $validationErrors[] = $exception->getMessage(); }
    if (!preg_match('/^09[0-9]{9}$/', $contact)) $validationErrors[] = 'Use an 11-digit contact number starting with 09.';
    if (!in_array($status, ['Active','Inactive'], true)) $validationErrors[] = 'Invalid driver status.';
    if (!in_array($todaId, array_column($todas, 'id'), true)) $validationErrors[] = 'Choose an active TODA organization.';
    $registrationPhoto = null;
    try { $registrationPhoto = tqValidateDriverPhoto($_FILES['profile_picture'] ?? null); }
    catch (RuntimeException $e) { $validationErrors[] = $e->getMessage(); }


    // -------------------------------------------------
    // NAME
    // -------------------------------------------------

    if ($firstName === "") {

        $validationErrors[] =
            "First name is required.";
    }

    if ($lastName === "") {

        $validationErrors[] =
            "Last name is required.";
    }


    // -------------------------------------------------
    // CONTACT
    // -------------------------------------------------

    if ($contact === "") {

        $validationErrors[] =
            "Contact number is required.";
    }


    // -------------------------------------------------
    // ADDRESS
    // -------------------------------------------------

    // -------------------------------------------------
    // VEHICLE
    // -------------------------------------------------

    if ($engineNumber === "") {

        $validationErrors[] =
            "Engine number is required.";
    }

    if ($chassisNumber === "") {

        $validationErrors[] =
            "Chassis number is required.";
    }

    if ($tricycleNumber === "") {

        $validationErrors[] =
            "Tricycle number is required.";
    }

    if ($plateNumber === "") {

        $validationErrors[] =
            "Plate number is required.";
    }

    if ($motorcycleBrand === "") {

        $validationErrors[] =
            "Motorcycle brand is required.";
    }

    if ($mtopNumber === "") {

        $validationErrors[] =
            "MTOP number is required.";
    }


    // -------------------------------------------------
    // TODA
    // -------------------------------------------------

    if ($todaId === "") {

        $validationErrors[] =
            "TODA organization is required.";
    }


    // -------------------------------------------------
    // POSITION
    // -------------------------------------------------

    if (
        !in_array(
            $position,
            [
                "President",
                "Member"
            ],
            true
        )
    ) {

        $validationErrors[] =
            "Invalid TODA position.";

        $position = "Member";
    }


    // -------------------------------------------------
    // DRIVER EMAIL
    // -------------------------------------------------

    if ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $validationErrors[] = "Please enter a valid driver email address.";
    }


    // -------------------------------------------------
    // EXPIRY DATE
    // -------------------------------------------------

    if ($franchiseExpiryDate === "") {

        $validationErrors[] =
            "Franchise / MTOP expiry date is required.";
    }


    // =================================================
    // CREATE COMBINED NAME
    // =================================================

    $fullName =
        trim(
            $firstName .
            " " .
            $lastName
        );


    // =================================================
    // CREATE COMBINED ADDRESS
    // =================================================

    $address = $extraData['address'] ?? '';

    // =================================================
    // IF VALID
    // =================================================

    if (empty($validationErrors)) {


        // =============================================
        // GENERATE APPLICATION NUMBER
        // =============================================

        // Application number is assigned atomically by the database trigger.
        $driverData = [

            "name" =>
                $fullName,

            "address" =>
                $address,

            "phone_number" =>
                $contact,

            "tricycle_number" =>
                $tricycleNumber,

            "plate_number" =>
                $plateNumber,

            "mtop_number" =>
                $mtopNumber,

"status" =>
                $status,

            "engine_number" =>
                $engineNumber,

            "chassis_number" =>
                $chassisNumber,

            "motorcycle_brand" =>
                $motorcycleBrand,

            "toda_id" =>
                $todaId,

            "position" =>
                $position,

            "email" => $email !== "" ? $email : null,

            "last_renewal_date" =>
                $lastRenewalDate !== ""
                    ? $lastRenewalDate
                    : null,

            "franchise_expiry_date" =>
                $franchiseExpiryDate,

            "renewal_status" =>
                "Valid"
        ];


        // =============================================
        // INSERT DRIVER
        // =============================================

        $driverData = array_merge($driverData, $extraData);

        $insertResult =
            supabaseRequest(
                "drivers",
                "POST",
                $driverData
            );


        // =============================================
        // SUCCESS
        // =============================================

        if (
            $insertResult["status"] >= 200 &&
            $insertResult["status"] < 300
        ) {

            if ($registrationPhoto !== null) {
                $registeredId=$insertResult['data'][0]['id']??null;
                if (!$registeredId) { header('Location: drivers.php?photo_warning=1'); exit; }
                try { tqSaveDriverPhoto($registeredId,$registrationPhoto); }
                catch (RuntimeException $e) { header('Location: driver_edit.php?id='.urlencode($registeredId).'&photo_warning=1'); exit; }
            }
            header("Location: register.php?success=registered");

            exit;

        }


        // =============================================
        // ERROR
        // =============================================

        $message =
            "Unable to register the driver.";

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


    // =================================================
    // VALIDATION ERROR MESSAGE
    // =================================================

    else {

        $message =
            implode(
                " ",
                $validationErrors
            );

        $messageType =
            "error";
    }
}


// =====================================================
// SUCCESS MESSAGE
// =====================================================

if (
    isset($_GET["success"]) &&
    $_GET["success"] === "registered"
) {

    $message =
        "Registration completed successfully.";

    $messageType =
        "success";
}


// =====================================================
// GET ADMIN INFORMATION
// =====================================================

$adminName =
    "TMU Admin";

if (
    isset($_SESSION["user_id"]) &&
    $_SESSION["user_id"] !== ""
) {

    $adminResult =
        supabaseRequest(
            "admin",
            "GET",
            null,
            "user_id=eq." .
            urlencode(
                $_SESSION["user_id"]
            ) .
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
        Register Driver - TRIKE-Q TMU Admin
    </title>

    <style>

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {

            --orange: #f97316;
            --orange-dark: #ea580c;
            --orange-deep: #9a3412;
            --orange-light: #fff7ed;
            --orange-border: #fed7aa;

            --sidebar: #1f1f1f;
            --sidebar-hover: #2d2d2d;

            --text-main: #1f2937;
            --text-heading: #111827;
            --text-label: #374151;
            --text-muted: #6b7280;
            --text-light: #9ca3af;

            --border: #d1d5db;
            --border-light: #e5e7eb;

            --background: #f8fafc;
            --white: #ffffff;

            --success-bg: #ecfdf5;
            --success-border: #86efac;
            --success-text: #166534;

            --error-bg: #fef2f2;
            --error-border: #fca5a5;
            --error-text: #991b1b;

            --shadow:
                0 2px 10px rgba(0, 0, 0, 0.06);
        }

        body {

            font-family:
                Arial,
                Helvetica,
                sans-serif;

            background:
                var(--background);

            color:
                var(--text-main);

            font-size: 14px;

            line-height: 1.5;
        }

        button,
        input,
        select {
            font-family: inherit;
        }

        /* =====================================================
           LAYOUT
        ===================================================== */

        .layout {

            display: flex;

            min-height: 100vh;
        }

        /* =====================================================
           SIDEBAR
        ===================================================== */

        .sidebar {

            width: 250px;

            background:
                var(--sidebar);

            color: white;

            position: fixed;

            top: 0;
            left: 0;
            bottom: 0;

            overflow-y: auto;

            padding:
                24px 16px;

            z-index: 100;
        }

        .logo {

            text-align: center;

            font-size: 25px;

            font-weight: bold;

            color:
                var(--orange);

            margin-bottom: 5px;
        }

        .logo-subtitle {

            text-align: center;

            font-size: 12px;

            color:
                #9ca3af;

            margin-bottom: 30px;
        }

        .menu-title {

            font-size: 11px;

            color:
                #6b7280;

            text-transform: uppercase;

            letter-spacing: 1px;

            margin:
                20px 10px 8px;
        }

        .sidebar a {

            display: flex;

            align-items: center;

            gap: 10px;

            color:
                #d1d5db;

            text-decoration: none;

            padding:
                12px 13px;

            margin-bottom: 4px;

            border-radius: 8px;

            font-size: 14px;

            transition: 0.2s;
        }

        .sidebar a:hover {

            background:
                var(--sidebar-hover);

            color: white;
        }

        .sidebar a.active {

            background:
                var(--orange);

            color: white;

            font-weight: bold;
        }

        .icon {

            width: 20px;

            text-align: center;

            font-size: 16px;
        }

        /* =====================================================
           MAIN
        ===================================================== */

        .main {

            margin-left: 250px;

            width:
                calc(100% - 250px);

            min-height: 100vh;
        }

        /* =====================================================
           TOPBAR
        ===================================================== */

        .topbar {

            height: 75px;

            background:
                white;

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

            color:
                var(--orange-deep);
        }

        .page-subtitle {

            margin-top: 4px;

            font-size: 13px;

            color:
                var(--text-muted);
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

            background:
                var(--orange);

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

            color:
                var(--text-muted);
        }

        /* =====================================================
           CONTENT
        ===================================================== */

        .content {

            padding:
                30px;
        }

        /* =====================================================
           MESSAGE
        ===================================================== */

        .message {

            padding:
                14px 16px;

            border-radius:
                10px;

            margin-bottom:
                20px;

            font-size:
                14px;

            display:
                flex;

            align-items:
                center;

            gap:
                10px;
        }

        .message.success {

            background:
                var(--success-bg);

            border:
                1px solid var(--success-border);

            color:
                var(--success-text);
        }

        .message.error {

            background:
                var(--error-bg);

            border:
                1px solid var(--error-border);

            color:
                var(--error-text);
        }

        /* =====================================================
           FORM CONTAINER
        ===================================================== */

        .form-container {

            background:
                white;

            border-radius:
                12px;

            padding:
                28px;

            box-shadow:
                var(--shadow);
        }

        .form-header {

            display:
                flex;

            align-items:
                flex-start;

            justify-content:
                space-between;

            gap:
                20px;

            margin-bottom:
                28px;

            padding-bottom:
                20px;

            border-bottom:
                1px solid var(--border-light);
        }

        .form-header-title {

            font-size:
                19px;

            font-weight:
                bold;

            color:
                var(--orange-deep);
        }

        .form-header-text {

            font-size:
                13px;

            color:
                var(--text-muted);

            margin-top:
                5px;
        }

        .required-note {

            font-size:
                12px;

            color:
                var(--text-muted);

            white-space:
                nowrap;
        }

        .required {

            color:
                #dc2626;

            font-weight:
                bold;
        }

        /* =====================================================
           SECTIONS
        ===================================================== */

        .section {

            margin-bottom:
                24px;
        }

        .section:last-of-type {

            margin-bottom:
                0;
        }

        .section-title {

            display:
                flex;

            align-items:
                center;

            color:
                var(--orange-deep);

            font-size:
                16px;

            font-weight:
                bold;

            padding-bottom:
                10px;

            margin-bottom:
                15px;

            border-bottom:
                2px solid var(--orange-border);
        }

        .section-title::before {

            content:
                "";

            width:
                4px;

            height:
                19px;

            background:
                var(--orange);

            border-radius:
                3px;

            margin-right:
                9px;
        }

        /* =====================================================
           FORM GRID
        ===================================================== */

        .form-grid {

            display:
                grid;

            grid-template-columns:
                repeat(
                    2,
                    minmax(
                        0,
                        1fr
                    )
                );

            gap:
                14px 18px;
        }

        .form-group {

            display:
                flex;

            flex-direction:
                column;

            min-width:
                0;
        }

        /* =====================================================
           LABELS
        ===================================================== */

        label {

            font-size:
                12px;

            font-weight:
                bold;

            color:
                var(--text-label);

            margin-bottom:
                5px;
        }

        /* =====================================================
           INPUTS
        ===================================================== */

        input,
        select {

            width:
                100%;

            height:
                40px;

            padding:
                8px 11px;

            border:
                1px solid var(--border);

            border-radius:
                7px;

            font-size:
                13px;

            color:
                var(--text-main);

            background:
                white;

            outline:
                none;

            transition:
                0.2s;
        }

        input::placeholder {

            color:
                #9ca3af;
        }

        input:hover,
        select:hover {

            border-color:
                #9ca3af;
        }

        input:focus,
        select:focus {

            border-color:
                var(--orange);

            box-shadow:
                0 0 0 3px
                rgba(
                    249,
                    115,
                    22,
                    0.10
                );
        }

        input:disabled {

            background:
                #f3f4f6;

            color:
                #6b7280;

            cursor:
                not-allowed;
        }

        select {

            cursor:
                pointer;
        }

        /* =====================================================
           HELP TEXT
        ===================================================== */

        .help-text {

            font-size:
                10px;

            line-height:
                1.35;

            color:
                var(--text-light);

            margin-top:
                4px;
        }

        /* =====================================================
           RADIO
        ===================================================== */

        .radio-group {

            min-height:
                40px;

            display:
                flex;

            align-items:
                center;

            gap:
                28px;
        }

        .radio-item {

            display:
                flex;

            flex-direction:
                row;

            align-items:
                center;

            gap:
                8px;

            font-size:
                13px;

            font-weight:
                500;

            color:
                var(--text-main);

            margin:
                0;

            cursor:
                pointer;
        }

        .radio-item input {

            width:
                17px;

            height:
                17px;

            margin:
                0;

            accent-color:
                var(--orange);

            cursor:
                pointer;
        }

        /* =====================================================
           PRESIDENT EMAIL
        ===================================================== */

        #president-email-group {

            display:
                none;
        }

        /* =====================================================
           AUTO FIELD
        ===================================================== */

        .auto-field {

            position:
                relative;
        }

        .auto-field input {

            padding-right:
                70px;
        }

        .auto-badge {

            position:
                absolute;

            right:
                10px;

            bottom:
                10px;

            background:
                var(--orange-light);

            color:
                #c2410c;

            border:
                1px solid var(--orange-border);

            border-radius:
                5px;

            padding:
                3px 7px;

            font-size:
                10px;

            font-weight:
                bold;
        }

        /* =====================================================
           BUTTONS
        ===================================================== */

        .button-area {

            margin-top:
                25px;

            padding-top:
                20px;

            border-top:
                1px solid var(--border-light);

            display:
                flex;

            justify-content:
                flex-end;

            gap:
                10px;
        }

        .btn-orange,
        .btn-secondary {

            min-height:
                42px;

            padding:
                10px 20px;

            border-radius:
                8px;

            font-size:
                13px;

            font-weight:
                bold;

            cursor:
                pointer;

            transition:
                0.2s;
        }

        .btn-orange {

            background:
                var(--orange);

            color:
                white;

            border:
                1px solid var(--orange);
        }

        .btn-orange:hover {

            background:
                var(--orange-dark);

            border-color:
                var(--orange-dark);
        }

        .btn-secondary {

            display:
                inline-flex;

            align-items:
                center;

            justify-content:
                center;

            background:
                white;

            color:
                var(--text-label);

            border:
                1px solid var(--border);

            text-decoration:
                none;
        }

        .btn-secondary:hover {

            background:
                #f9fafb;

            border-color:
                #9ca3af;
        }

        /* =====================================================
           RESPONSIVE
        ===================================================== */

        @media (max-width: 850px) {

            .sidebar {

                width:
                    210px;
            }

            .main {

                margin-left:
                    210px;

                width:
                    calc(
                        100% - 210px
                    );
            }

            .content {

                padding:
                    20px;
            }

            .form-grid {

                grid-template-columns:
                    1fr;
            }
        }

        @media (max-width: 600px) {

            .layout {

                display:
                    block;
            }

            .sidebar {

                position:
                    relative;

                width:
                    100%;

                height:
                    auto;
            }

            .main {

                margin-left:
                    0;

                width:
                    100%;
            }

            .topbar {

                padding:
                    15px;

                height:
                    auto;

                min-height:
                    75px;
            }

            .content {

                padding:
                    15px;
            }

            .form-container {

                padding:
                    18px;
            }

            .form-header {

                display:
                    block;
            }

            .required-note {

                margin-top:
                    8px;
            }

            .admin-info {

                display:
                    none;
            }

            .button-area {

                flex-direction:
                    column;
            }

            .btn-orange,
            .btn-secondary {

                width:
                    100%;
            }

            .radio-group {

                gap:
                    20px;
            }
        }

        /* =====================================================
           SCROLLBAR
        ===================================================== */

        ::-webkit-scrollbar {

            width:
                8px;

            height:
                8px;
        }

        ::-webkit-scrollbar-track {

            background:
                #f1f5f9;
        }

        ::-webkit-scrollbar-thumb {

            background:
                #cbd5e1;

            border-radius:
                10px;
        }

        ::-webkit-scrollbar-thumb:hover {

            background:
                #94a3b8;
        }

    </style>

<link rel="stylesheet" href="admin-theme.css">

<link rel="stylesheet" href="assets/driver-fields.css"><script src="assets/driver-fields.js" defer></script>
<!-- Shared TMU header and navigation; refresh versions when the shared files change. -->
<script src="admin-header.js?v=<?= (int) @filemtime(__DIR__.'/admin-header.js') ?>" data-tq-header="1" defer></script>
<script src="admin-nav.js?v=<?= (int) @filemtime(__DIR__.'/admin-nav.js') ?>" defer></script>
</head>

<body>

<div class="layout">

    <!-- =================================================
         SIDEBAR
    ================================================== -->

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

        <a href="register.php" class="active" aria-current="page">
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
    ================================================== -->

    <main class="main">

        <header class="topbar">

            <div>

                <div class="page-title">
                    Register Driver
                </div>

                <div class="page-subtitle">
                    Register drivers and TODA officers with their organization information
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
        ================================================== -->

        <section class="content">

            <?php if ($message !== ""): ?>

                <div
                    class="message <?= e($messageType) ?>"
                >

                    <?php if ($messageType === "success"): ?>

                        <strong>✓</strong>

                    <?php else: ?>

                        <strong>!</strong>

                    <?php endif; ?>

                    <span>
                        <?= e($message) ?>
                    </span>

                </div>

            <?php endif; ?>


            <div class="form-container">

                <div class="form-header">

                    <div>

                        <div class="form-header-title">
                            Registration Form
                        </div>

                        <div class="form-header-text">
                            Enter the person's official registration information below.
                        </div>

                    </div>

                    <div class="required-note">

                        <span class="required">*</span>
                        Required fields

                    </div>

                </div>


                <form
                    method="POST"
                    action=""
                    enctype="multipart/form-data"
                >
                    <input type="hidden" name="tq_csrf" value="<?= tqEscape(tqCsrf()) ?>">
                    <div class="section">
                        <label for="registration_photo">Profile Picture (Optional)</label>
                        <input id="registration_photo" type="file" name="profile_picture" accept="image/jpeg,image/png,image/webp">
                        <p>JPG, PNG or WEBP, up to 5 MB. You can also add a photo later through Edit Driver.</p>
                    </div>

                    <!-- =================================================
                         DRIVER INFORMATION
                    ================================================== -->

                    <div class="section">

                        <div class="section-title">
                            Driver Information
                        </div>

                        <div class="form-grid">

                            <!-- FIRST NAME -->

                            <div class="form-group">

                                <label for="first_name">

                                    First Name
                                    <span class="required">*</span>

                                </label>

                                <input
                                    type="text"
                                    id="first_name"
                                    name="first_name"
                                    value="<?= e($firstName) ?>"
                                    placeholder="Enter first name"
                                    autocomplete="given-name"
                                    required
                                >

                            </div>


                            <!-- LAST NAME -->

                            <div class="form-group">

                                <label for="last_name">

                                    Last Name
                                    <span class="required">*</span>

                                </label>

                                <input
                                    type="text"
                                    id="last_name"
                                    name="last_name"
                                    value="<?= e($lastName) ?>"
                                    placeholder="Enter last name"
                                    autocomplete="family-name"
                                    required
                                >

                            </div>


                            <!-- CONTACT -->

                            <div class="form-group">

                                <label for="phone_number">

                                    Contact Number
                                    <span class="required">*</span>

                                </label>

                                <input
                                    type="text"
                                    id="phone_number"
                                    name="phone_number"
                                    value="<?= e($contact) ?>"
                                    placeholder="e.g. 09XXXXXXXXX"
                                    autocomplete="tel"
                                    required
                                >

                            </div>


                            <!-- BIRTHDATE -->
                            <div class="form-group">
                                <label for="birthdate">Birthdate <span class="required">*</span></label>
                                <input type="date" id="birthdate" name="birthdate"
                                    value="<?= e(is_string($extraValues['birthdate'] ?? null) ? $extraValues['birthdate'] : '') ?>"
                                    max="<?= e((new DateTimeImmutable('yesterday', new DateTimeZone('Asia/Manila')))->format('Y-m-d')) ?>"
                                    autocomplete="bday" required>
                            </div>

                            <div class="form-group full tq-driver-extra"><?php tqDriverFields($extraValues, false); ?></div>

                            <!-- TODA POSITION -->

                            <div class="form-group">

                                <label>
                                    TODA Position
                                </label>

                                <div class="radio-group">

                                    <label class="radio-item">

                                        <input
                                            type="radio"
                                            name="position"
                                            value="President"
                                            <?= $position === "President"
                                                ? "checked"
                                                : "" ?>
                                        >

                                        <span>
                                            President
                                        </span>

                                    </label>

                                    <label class="radio-item">

                                        <input
                                            type="radio"
                                            name="position"
                                            value="Member"
                                            <?= $position === "Member"
                                                ? "checked"
                                                : "" ?>
                                        >

                                        <span>
                                            Member
                                        </span>

                                    </label>

                                </div>

                                <div class="help-text">
                                    Add an email address if the driver has one.
                                </div>

                            </div>


                            <!-- PRESIDENT EMAIL -->

                            <div
                                class="form-group"
                                id="president-email-group"
                            >

                                <label for="president-email">

                                    Driver Email Address
                                    <span class="optional">(Optional)</span>

                                </label>

                                <input
                                    type="email"
                                    id="president-email"
                                    name="email"
                                    value="<?= e($email) ?>"
                                    placeholder="driver@example.com"
                                    autocomplete="email"
                                >

                                <div class="help-text">
                                    Leave blank if the driver has no email address.
                                </div>

                            </div>

                        </div>

                    </div>


                    <!-- =================================================
                         TRICYCLE INFORMATION
                    ================================================== -->

                    <div class="section">

                        <div class="section-title">
                            Tricycle Information
                        </div>

                        <div class="form-grid">

                            <div class="form-group">

                                <label for="tricycle_number">

                                    Tricycle Number
                                    <span class="required">*</span>

                                </label>

                                <input
                                    type="text"
                                    id="tricycle_number"
                                    name="tricycle_number"
                                    value="<?= e($tricycleNumber) ?>"
                                    placeholder="Enter tricycle number"
                                    required
                                >

                            </div>


                            <div class="form-group">

                                <label for="plate_number">

                                    Plate Number
                                    <span class="required">*</span>

                                </label>

                                <input
                                    type="text"
                                    id="plate_number"
                                    name="plate_number"
                                    value="<?= e($plateNumber) ?>"
                                    placeholder="Enter plate number"
                                    required
                                >

                            </div>


                            <div class="form-group">

                                <label for="engine_number">

                                    Engine Number
                                    <span class="required">*</span>

                                </label>

                                <input
                                    type="text"
                                    id="engine_number"
                                    name="engine_number"
                                    value="<?= e($engineNumber) ?>"
                                    placeholder="Enter engine number"
                                    required
                                >

                            </div>


                            <div class="form-group">

                                <label for="chassis_number">

                                    Chassis Number
                                    <span class="required">*</span>

                                </label>

                                <input
                                    type="text"
                                    id="chassis_number"
                                    name="chassis_number"
                                    value="<?= e($chassisNumber) ?>"
                                    placeholder="Enter chassis number"
                                    required
                                >

                            </div>


                            <div class="form-group">

                                <label for="motorcycle_brand">

                                    Motorcycle Brand
                                    <span class="required">*</span>

                                </label>

                                <input
                                    type="text"
                                    id="motorcycle_brand"
                                    name="motorcycle_brand"
                                    value="<?= e($motorcycleBrand) ?>"
                                    placeholder="e.g. Honda, Yamaha, Suzuki"
                                    required
                                >

                            </div>


                            <div class="form-group">

                                <label for="mtop_number">

                                    MTOP Number
                                    <span class="required">*</span>

                                </label>

                                <input
                                    type="text"
                                    id="mtop_number"
                                    name="mtop_number"
                                    value="<?= e($mtopNumber) ?>"
                                    placeholder="Enter MTOP number"
                                    required
                                >

                            </div>

                        </div>

                    </div>


                    <!-- =================================================
                         TODA INFORMATION
                    ================================================== -->

                    <div class="section">

                        <div class="section-title">
                            TODA Information
                        </div>

                        <div class="form-grid">

                            <div class="form-group">

                                <label for="toda_id">

                                    TODA Organization
                                    <span class="required">*</span>

                                </label>

                                <select
                                    id="toda_id"
                                    name="toda_id"
                                    required
                                >

                                    <option value="">
                                        Select TODA Organization
                                    </option>

                                    <?php foreach ($todas as $toda): ?>

                                        <option
                                            value="<?= e(
                                                $toda["id"]
                                            ) ?>"
                                            <?= $todaId == $toda["id"]
                                                ? "selected"
                                                : "" ?>
                                        >

                                            <?= e(
                                                $toda["name"]
                                            ) ?>

                                        </option>

                                    <?php endforeach; ?>

                                </select>

                                <?php if (empty($todas)): ?>

                                    <div class="help-text">
                                        No active TODA organizations are available. Add one from TODA Organizations first.
                                    </div>

                                <?php else: ?>

                                    <div class="help-text">
                                        Select the active TODA organization where this person is registered.
                                    </div>

                                <?php endif; ?>

                            </div>


                            <div class="form-group auto-field">

                                <label for="application_display">
                                    Application Number
                                </label>

                                <input
                                    type="text"
                                    id="application_display"
                                    value="Automatically Generated"
                                    disabled
                                >

                                <span class="auto-badge">
                                    AUTO
                                </span>

                                <div class="help-text">
                                    TRIKE-Q automatically generates the application number after registration.
                                </div>

                            </div>

                        </div>

                    </div>


                    <!-- =================================================
                         RENEWAL INFORMATION
                    ================================================== -->

                    <div class="section">

                        <div class="section-title">
                            Franchise / MTOP Renewal
                        </div>

                        <div class="form-grid">

                            <div class="form-group">

                                <label for="last_renewal_date">
                                    Last Renewal Date
                                </label>

                                <input
                                    type="date"
                                    id="last_renewal_date"
                                    name="last_renewal_date"
                                    value="<?= e(
                                        $lastRenewalDate
                                    ) ?>"
                                >

                                <div class="help-text">
                                    Enter the most recent franchise / MTOP renewal date.
                                </div>

                            </div>


                            <div class="form-group">

                                <label for="franchise_expiry_date">

                                    Franchise / MTOP Expiry Date
                                    <span class="required">*</span>

                                </label>

                                <input
                                    type="date"
                                    id="franchise_expiry_date"
                                    name="franchise_expiry_date"
                                    value="<?= e(
                                        $franchiseExpiryDate
                                    ) ?>"
                                    required
                                >

                                <div class="help-text">
                                    Used by TRIKE-Q to monitor renewal status and reminders.
                                </div>

                            </div>


                            <div class="form-group">

                                <label for="renewal_status">
                                    Renewal Status
                                </label>

                                <input
                                    type="text"
                                    id="renewal_status"
                                    value="Valid"
                                    disabled
                                >

                                <div class="help-text">
                                    The system will use the expiry date when determining the renewal status.
                                </div>

                            </div>

                        </div>

                    </div>


                    <!-- =================================================
                         BUTTONS
                    ================================================== -->

                    <div class="button-area">

                        <a
                            href="drivers.php"
                            class="btn-secondary"
                        >
                            Cancel
                        </a>

                        <button
                            type="submit"
                            class="btn-orange"
                        >
                            Register Driver
                        </button>

                    </div>

                </form>

            </div>

        </section>

    </main>

</div>


<script>

// =====================================================
// DRIVER EMAIL FIELD
// =====================================================

document.addEventListener(
    "DOMContentLoaded",
    function () {

        const positionInputs =
            document.querySelectorAll(
                'input[name="position"]'
            );

        const emailGroup =
            document.getElementById(
                "president-email-group"
            );

        const emailInput =
            document.getElementById(
                "president-email"
            );


        function updatePresidentEmail() {

            const selected =
                document.querySelector(
                    'input[name="position"]:checked'
                );


            if (!selected) {

                emailGroup.style.display = "flex";

                emailInput.required = false;

                return;
            }


            if (
                selected.value ===
                "President"
            ) {

                emailGroup.style.display =
                    "flex";

                emailInput.required = false;

            } else {

                emailGroup.style.display = "flex";

                emailInput.required = false;

            }
        }


        positionInputs.forEach(
            function (input) {

                input.addEventListener(
                    "change",
                    updatePresidentEmail
                );

            }
        );


        updatePresidentEmail();

    }
);

</script>

</body>

</html>
