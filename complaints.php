<?php
// =====================================================
// TRIKE-Q COMPLAINT & VIOLATION MONITORING
// =====================================================
session_start();
require_once __DIR__ . "/config/supabase.php";
require_once __DIR__ . '/tmu-tools.php';
tqRequireAdmin();
tqRequireCsrf();
header('Cache-Control: private, no-store, max-age=0');
// Evidence URLs are signed on demand using the current user's access token.
// The client supplies a complaint ID and kind, never a storage path or URL.
if (array_key_exists('tq_evidence', $_GET)) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    function tqComplaintEvidenceReply($status, $data) {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        tqComplaintEvidenceReply(405, ['error'=>'Use a GET request to view evidence.']);
    }
    $kind = is_string($_GET['tq_evidence'] ?? null) ? $_GET['tq_evidence'] : '';
    $evidenceId = is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
    if (!in_array($kind, ['photo','video'], true)
        || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $evidenceId)) {
        tqComplaintEvidenceReply(400, ['error'=>'Invalid complaint evidence request.']);
    }
    $verified = supabaseRequest('auth/v1/user');
    if (empty($verified['success']) && refreshSupabaseToken()) $verified = supabaseRequest('auth/v1/user');
    $evidenceUser = $verified['data']['id'] ?? null;
    if (empty($verified['success']) || !is_string($evidenceUser) || $evidenceUser === '') {
        tqComplaintEvidenceReply(401, ['error'=>'Your session expired. Sign in again to view evidence.']);
    }
    $membership = supabaseRequest('admin', 'GET', null, http_build_query([
        'select'=>'user_id,is_active', 'user_id'=>'eq.'.$evidenceUser, 'limit'=>1,
    ], '', '&', PHP_QUERY_RFC3986));
    if (empty($membership['success']) || ($membership['data'][0]['is_active'] ?? false) !== true) {
        tqComplaintEvidenceReply(403, ['error'=>'Active TMU administrator access is required.']);
    }
    $record = supabaseRequest('complaints', 'GET', null, http_build_query([
        'select'=>'evidence_path,video_evidence_path', 'id'=>'eq.'.$evidenceId, 'limit'=>1,
    ], '', '&', PHP_QUERY_RFC3986));
    if (empty($record['success'])) {
        tqComplaintEvidenceReply(502, ['error'=>'The complaint could not be loaded. Please try again.']);
    }
    $complaintEvidence = $record['data'][0] ?? null;
    if (!$complaintEvidence) tqComplaintEvidenceReply(404, ['error'=>'This complaint is no longer available.']);
    $path = $complaintEvidence[$kind === 'video' ? 'video_evidence_path' : 'evidence_path'] ?? null;
    $extension = $kind === 'video' ? 'mp4' : 'jpg';
    if (!is_string($path) || $path === '') {
        tqComplaintEvidenceReply(404, ['error'=>'No '.$kind.' evidence is attached to this complaint.']);
    }
    $uuidPattern = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
    if (!preg_match('~^'.$uuidPattern.'/'.$uuidPattern.'[.]'.$extension.'$~i', $path)) {
        tqComplaintEvidenceReply(422, ['error'=>'The saved evidence path is invalid. Contact your system administrator.']);
    }
    $base = rtrim($SUPABASE_URL, '/');
    if (!str_starts_with($base, 'https://') || !function_exists('trikeqSupabaseHttp')) {
        tqComplaintEvidenceReply(500, ['error'=>'The secure evidence connection is not configured.']);
    }
    $encodedPath = implode('/', array_map('rawurlencode', explode('/', $path)));
    $signed = trikeqSupabaseHttp(
        $base.'/storage/v1/object/sign/trikeq-complaint-evidence/'.$encodedPath,
        'POST', ['expiresIn'=>600], $_SESSION['access_token'] ?? null
    );
    if (empty($signed['success'])) {
        tqComplaintEvidenceReply(502, ['error'=>'Evidence could not be opened. The file may be missing or access may have expired. Try Reload.']);
    }
    $signedPath = $signed['data']['signedURL'] ?? $signed['data']['signedUrl'] ?? null;
    if (!is_string($signedPath) || $signedPath === '') {
        tqComplaintEvidenceReply(502, ['error'=>'The evidence service did not return a usable link.']);
    }
    if (str_starts_with($signedPath, '/object/sign/')) $url = $base.'/storage/v1'.$signedPath;
    elseif (str_starts_with($signedPath, '/storage/v1/object/sign/')) $url = $base.$signedPath;
    else $url = $signedPath;
    // Fail closed if storage returns a URL outside this project's evidence bucket.
    $expectedPrefix = $base.'/storage/v1/object/sign/trikeq-complaint-evidence/';
    if (!str_starts_with($url, $expectedPrefix)) {
        tqComplaintEvidenceReply(502, ['error'=>'The evidence service returned an unexpected link.']);
    }
    tqComplaintEvidenceReply(200, ['url'=>$url, 'kind'=>$kind, 'expires_in'=>600]);
}
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
// VARIABLES
// =====================================================
$message = $_SESSION['complaint_review_notice'] ?? '';
unset($_SESSION['complaint_review_notice']);
$error = '';
$reviewComplaintId = ''; $reviewStatus = ''; $reviewRemarks = ''; $reviewVersion = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reviewComplaintId = is_string($_POST['complaint_id'] ?? null) ? trim($_POST['complaint_id']) : '';
    $reviewStatus = is_string($_POST['status'] ?? null) ? trim($_POST['status']) : '';
    $reviewRemarks = is_string($_POST['remarks'] ?? null) ? trim($_POST['remarks']) : '';
    $reviewVersion = is_string($_POST['review_version'] ?? null) ? $_POST['review_version'] : '';
    $validVersion = filter_var($reviewVersion,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',$reviewComplaintId)
        || !in_array($reviewStatus,['Pending','Resolved','Dismissed'],true)) {
        $error = 'Invalid complaint update.';
    } elseif ($validVersion === false) {
        $error = 'Refresh this page after installing complaint_review_history.sql before reviewing a complaint.';
    } elseif (in_array($reviewStatus,['Resolved','Dismissed'],true) && $reviewRemarks === '') {
        $error = 'Describe the action taken or reason before resolving or dismissing a complaint.';
    } else {
        $updateResult = supabaseRequest('rpc/trikeq_review_complaint','POST',[
            'p_complaint_id'=>$reviewComplaintId,'p_status'=>$reviewStatus,
            'p_remarks'=>$reviewRemarks,'p_expected_version'=>$validVersion,
        ]);
        if (!empty($updateResult['success']) && $updateResult['data'] === true) {
            $_SESSION['complaint_review_notice'] = 'Complaint updated successfully.';
            $returnFilters = [];
            foreach (['status','toda','search'] as $key) if (isset($_GET[$key]) && is_string($_GET[$key]) && $_GET[$key] !== '') $returnFilters[$key] = $_GET[$key];
            $returnQuery = http_build_query($returnFilters,'','&',PHP_QUERY_RFC3986);
            header('Location: complaints.php'.($returnQuery !== ''?'?'.$returnQuery:''),true,303); exit;
        }
        $error = 'Unable to update complaint. '.($updateResult['error'] ?? 'Refresh the page to check whether your update was saved before retrying.');
    }
}
// =====================================================
// FETCH ADMIN INFORMATION
// =====================================================
$adminName = "TMU Admin";
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
// =====================================================
// FETCH COMPLAINTS
// =====================================================
$complaintsResult =
    supabaseRequest(
        "complaints",
        "GET",
        null,
        "select=*,drivers(name,tricycle_number,plate_number,profile_picture_url),toda_organizations(name)&order=created_at.desc"
    );
$complaints = [];
if (
    $complaintsResult["status"] >= 200 &&
    $complaintsResult["status"] < 300 &&
    is_array(
        $complaintsResult["data"]
    )
) {
    $complaints =
        $complaintsResult["data"];
}
// Read private contacts with the current user JWT; Supabase RLS permits active TMU only.
// Names and phone numbers are never copied into the president-readable complaints table.
$tqPrivateContacts = [];
$tqContactError = '';
$tqComplaintIds = [];
foreach ($complaints as $tqRow) {
    $tqRowId = $tqRow['id'] ?? '';
    if (is_string($tqRowId) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $tqRowId)) $tqComplaintIds[] = $tqRowId;
}
foreach (array_chunk(array_values(array_unique($tqComplaintIds)), 100) as $tqIdChunk) {
    $tqContactResult = supabaseRequest('complaint_passenger_contacts','GET',null,http_build_query([
        'select'=>'complaint_id,full_name,contact_number',
        'complaint_id'=>'in.('.implode(',', $tqIdChunk).')',
        'limit'=>count($tqIdChunk),
    ], '', '&', PHP_QUERY_RFC3986));
    if (empty($tqContactResult['success']) || !is_array($tqContactResult['data'])) {
        $tqContactError = 'Unable to load private complainant details. Refresh this page and check your TMU session.';
        break;
    }
    foreach ($tqContactResult['data'] as $tqContact) {
        if (isset($tqContact['complaint_id'])) $tqPrivateContacts[$tqContact['complaint_id']] = $tqContact;
    }
}
unset($tqComplaintIds,$tqIdChunk,$tqContactResult,$tqContact,$tqRow,$tqRowId);

// Complaint filters. Existing Under Review records are Pending after retirement.
foreach ($complaints as &$tqComplaint) { if (($tqComplaint['status'] ?? '') === 'Under Review') $tqComplaint['status'] = 'Pending'; }
unset($tqComplaint);
$tqAllComplaints = $complaints;
$tqStatus = isset($_GET['status']) && is_string($_GET['status']) ? $_GET['status'] : '';
if (!in_array($tqStatus, ['', 'Pending', 'Resolved', 'Dismissed'], true)) $tqStatus = '';
$tqToda = isset($_GET['toda']) && is_string($_GET['toda']) ? $_GET['toda'] : '';
$tqSearch = isset($_GET['search']) && is_string($_GET['search']) ? trim($_GET['search']) : '';
$tqTodaOptions = [];
foreach ($tqAllComplaints as $c) { $name = $c['toda_organizations']['name'] ?? ''; if ($name !== '') $tqTodaOptions[$name] = $name; }
natcasesort($tqTodaOptions);
$complaints = array_values(array_filter($tqAllComplaints, function ($c) use ($tqStatus, $tqToda, $tqSearch, $tqPrivateContacts) {
    $name = $c['toda_organizations']['name'] ?? '';
    $contact = $tqPrivateContacts[$c['id']] ?? [];
    $text = implode(' ', [$c['drivers']['name'] ?? $c['driver_name'] ?? '', $c['drivers']['tricycle_number'] ?? '', $name, $c['complaint_type'] ?? '', $c['message'] ?? '', $contact['full_name'] ?? '', $contact['contact_number'] ?? '', $c['complainant'] ?? '']);
    return ($tqStatus === '' || ($c['status'] ?? 'Pending') === $tqStatus) && ($tqToda === '' || $name === $tqToda) && ($tqSearch === '' || stripos($text, $tqSearch) !== false);
}));
// =====================================================
// STATISTICS
// =====================================================
$totalComplaints =
    count($complaints);
$pendingCount = 0;
$reviewCount = 0;
$resolvedCount = 0;
$dismissedCount = 0;
foreach (
    $complaints
    as $complaint
) {
    $complaintStatus =
        $complaint["status"]
        ?? "Pending";
    if (
        $complaintStatus ===
        "Pending"
    ) {
        $pendingCount++;
    } elseif (
        $complaintStatus ===
        "Under Review"
    ) {
        $reviewCount++;
    } elseif (
        $complaintStatus ===
        "Resolved"
    ) {
        $resolvedCount++;
    } elseif (
        $complaintStatus ===
        "Dismissed"
    ) {
        $dismissedCount++;
    }
}
// =====================================================
// ERROR FROM FETCH
// =====================================================
if (
    $complaintsResult["status"] < 200 ||
    $complaintsResult["status"] >= 300
) {
    $error =
        "Unable to load passenger complaints.";
    if (
        isset(
            $complaintsResult["data"]["message"]
        )
    ) {
        $error .=
            " " .
            $complaintsResult["data"]["message"];
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
        Complaint Monitoring - TRIKE-Q TMU Admin
    </title>
    <style>
        /* =================================================
           GLOBAL
           ================================================= */
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family:
                Inter,
                "Segoe UI",
                Roboto,
                Helvetica,
                Arial,
                sans-serif;
            background: #f8fafc;
            color: #1f2937;
            line-height: 1.5;
        }
        /* =================================================
           LAYOUT
           ================================================= */
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
            padding:
                24px 16px;
            z-index: 100;
        }
        .logo {
            text-align: center;
            font-size: 25px;
            font-weight: 800;
            letter-spacing: 0.5px;
            color: #f97316;
            margin-bottom: 4px;
        }
        .logo-subtitle {
            text-align: center;
            font-size: 11px;
            font-weight: 500;
            letter-spacing: 0.8px;
            color: #9ca3af;
            margin-bottom: 30px;
            text-transform: uppercase;
        }
        .menu-title {
            font-size: 10px;
            font-weight: 700;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            margin:
                22px 10px
                9px;
        }
        .sidebar a {
            display: flex;
            align-items: center;
            gap: 11px;
            color: #d1d5db;
            text-decoration: none;
            padding:
                11px 13px;
            margin-bottom: 4px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            transition:
                background 0.2s,
                color 0.2s;
        }
        .sidebar a:hover {
            background: #2d2d2d;
            color: white;
        }
        .sidebar a.active {
            background: #f97316;
            color: white;
            font-weight: 700;
            box-shadow:
                0 3px 8px
                rgba(
                    249,
                    115,
                    22,
                    0.22
                );
        }
        .icon {
            width: 20px;
            min-width: 20px;
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
            font-weight: 800;
            letter-spacing:
                -0.3px;
            color: #9a3412;
        }
        .page-subtitle {
            margin-top: 3px;
            font-size: 12px;
            color: #64748b;
            font-weight: 400;
        }
        .admin-info {
            display: flex;
            align-items: center;
            gap: 11px;
        }
        .admin-name {
            font-size: 13px;
            font-weight: 700;
            color: #1f2937;
            text-align: right;
        }
        .admin-role {
            font-size: 11px;
            color: #64748b;
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
            justify-content: center;
            align-items: center;
            font-size: 15px;
            font-weight: 800;
            box-shadow:
                0 2px 6px
                rgba(
                    249,
                    115,
                    22,
                    0.25
                );
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
            padding:
                14px 16px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 13px;
            font-weight: 500;
        }
        .success {
            background: #f0fdf4;
            border:
                1px solid #bbf7d0;
            color: #166534;
        }
        .error {
            background: #fef2f2;
            border:
                1px solid #fecaca;
            color: #991b1b;
        }
        /* =================================================
           INTRO CARD
           ================================================= */
        .intro-card {
            background: white;
            border-radius: 12px;
            padding:
                20px 22px;
            margin-bottom: 22px;
            border-left:
                4px solid #f97316;
            box-shadow:
                0 2px 8px
                rgba(
                    0,
                    0,
                    0,
                    0.05
                );
        }
        .intro-title {
            color: #9a3412;
            font-size: 16px;
            font-weight: 750;
            margin-bottom: 4px;
        }
        .intro-text {
            color: #64748b;
            font-size: 13px;
            line-height: 1.6;
        }
        /* =================================================
           STATISTICS
           ================================================= */
        .stats {
            display: grid;
            grid-template-columns:
                repeat(
                    4,
                    1fr
                );
            gap: 18px;
            margin-bottom: 25px;
        }
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
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
        .stat-card:hover {
            transform:
                translateY(-2px);
            box-shadow:
                0 5px 14px
                rgba(
                    0,
                    0,
                    0,
                    0.08
                );
        }
        .stat-label {
            color: #64748b;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 7px;
        }
        .stat-number {
            font-size: 28px;
            line-height: 1;
            font-weight: 800;
            color: #9a3412;
        }
        .stat-description {
            margin-top: 7px;
            color: #94a3b8;
            font-size: 11px;
        }
        /* =================================================
           STATUS COLORS
           ================================================= */
        .stat-card.pending {
            border-left-color:
                #f59e0b;
        }
        .stat-card.review {
            border-left-color:
                #3b82f6;
        }
        .stat-card.resolved {
            border-left-color:
                #22c55e;
        }
        /* =================================================
           MAIN CARD
           ================================================= */
        .card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            box-shadow:
                0 2px 8px
                rgba(
                    0,
                    0,
                    0,
                    0.06
                );
        }
        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .card-title {
            color: #9a3412;
            font-size: 17px;
            font-weight: 750;
            margin-bottom: 3px;
        }
        .card-description {
            color: #64748b;
            font-size: 12px;
        }
        .record-count {
            background: #fff7ed;
            color: #9a3412;
            border:
                1px solid #fed7aa;
            border-radius: 20px;
            padding:
                6px 11px;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
        }
        /* =================================================
           TABLE
           ================================================= */
        .table-container {
            width: 100%;
            overflow-x: auto;
            border:
                1px solid #e5e7eb;
            border-radius: 9px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1450px;
        }
        thead th {
            background: #fff7ed;
            color: #7c2d12;
            padding:
                13px 12px;
            text-align: left;
            font-size: 11px;
            font-weight: 750;
            text-transform: uppercase;
            letter-spacing:
                0.35px;
            border-bottom:
                1px solid #fed7aa;
            white-space: nowrap;
        }
        tbody td {
            padding:
                14px 12px;
            border-bottom:
                1px solid #eef2f7;
            vertical-align: top;
            font-size: 12px;
            color: #475569;
            line-height: 1.5;
        }
        tbody tr:last-child td {
            border-bottom: none;
        }
        tbody tr:hover td {
            background: #fffaf5;
        }
        .driver-name {
            color: #1f2937;
            font-weight: 700;
            font-size: 12px;
        }
        .secondary-text {
            color: #94a3b8;
            font-size: 11px;
            margin-top: 2px;
        }
        .complaint-message {
            max-width: 260px;
            color: #475569;
            line-height: 1.55;
            white-space: normal;
            word-break: break-word;
        }
        .violation-type {
            display: inline-block;
            background: #fff7ed;
            color: #9a3412;
            border:
                1px solid #fed7aa;
            padding:
                5px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
        }
        /* =================================================
           STATUS BADGES
           ================================================= */
        .status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding:
                5px 9px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 750;
            white-space: nowrap;
        }
        .status::before {
            content: "";
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: currentColor;
        }
        .pending {
            background: #fef3c7;
            color: #92400e;
        }
        .review {
            background: #dbeafe;
            color: #1e40af;
        }
        .resolved {
            background: #dcfce7;
            color: #166534;
        }
        .dismissed {
            background: #e5e7eb;
            color: #374151;
        }
        /* =================================================
           UPDATE FORM
           ================================================= */
        .update-form {
            display: flex;
            flex-direction: column;
            gap: 8px;
            min-width: 205px;
        }
        .form-label {
            font-size: 10px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing:
                0.3px;
        }
        .update-form select,
        .update-form textarea {
            width: 100%;
            padding:
                9px 10px;
            border:
                1px solid #dbe1e8;
            border-radius: 7px;
            background: #fff;
            color: #334155;
            font-family: inherit;
            font-size: 12px;
            outline: none;
            transition:
                border-color 0.2s,
                box-shadow 0.2s;
        }
        .update-form select:focus,
        .update-form textarea:focus {
            border-color:
                #f97316;
            box-shadow:
                0 0 0 3px
                rgba(
                    249,
                    115,
                    22,
                    0.10
                );
        }
        .update-form textarea {
            min-height: 72px;
            resize: vertical;
            line-height: 1.5;
        }
        .update-form textarea::placeholder {
            color: #9ca3af;
        }
        .update-btn {
            background: #f97316;
            color: white;
            border: none;
            padding:
                9px 12px;
            border-radius: 7px;
            cursor: pointer;
            font-family: inherit;
            font-size: 12px;
            font-weight: 700;
            transition:
                background 0.2s,
                transform 0.1s;
        }
        .update-btn:hover {
            background: #ea580c;
        }
        .update-btn:active {
            transform:
                translateY(1px);
        }
        /* =================================================
           EMPTY STATE
           ================================================= */
        .empty {
            text-align: center;
            padding:
                55px 20px;
            color: #64748b;
        }
        .empty-icon {
            width: 50px;
            height: 50px;
            margin:
                0 auto 12px;
            border-radius: 50%;
            background: #fff7ed;
            color: #f97316;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
        }
        .empty-title {
            color: #334155;
            font-size: 14px;
            font-weight: 700;
            margin-bottom: 4px;
        }
        .empty-text {
            color: #94a3b8;
            font-size: 12px;
        }
        /* =================================================
           RESPONSIVE
           ================================================= */
        @media (
            max-width: 1100px
        ) {
            .stats {
                grid-template-columns:
                    repeat(
                        2,
                        1fr
                    );
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
            .topbar {
                padding:
                    0 20px;
            }
        }
        @media (
            max-width: 600px
        ) {
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
            .stats {
                grid-template-columns:
                    1fr;
            }
            .content {
                padding: 15px;
            }
            .topbar {
                height: auto;
                min-height: 75px;
                padding:
                    15px;
                gap: 15px;
            }
            .admin-info {
                display: none;
            }
        }
    </style>
<link rel="stylesheet" href="admin-theme.css">

<link rel="stylesheet" href="tmu-updates.css?v=20261019b">
<script src="tmu-updates.js?v=20261019" defer></script>
<style>
/* Complaint controls are self-contained so old shared CSS cannot misalign them. */
#tqComplaintFilters{display:grid;grid-template-columns:minmax(180px,2fr) minmax(130px,1fr) minmax(160px,1fr) auto;align-items:end;gap:12px;margin:0;padding:16px 20px;background:#f8fafc;border-top:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;box-sizing:border-box}
#tqComplaintFilters>div{min-width:0}
#tqComplaintFilters label{display:block;margin:0 0 6px;color:#334155;font:600 13px Arial,Helvetica,sans-serif}
#tqComplaintFilters input,#tqComplaintFilters select{display:block;box-sizing:border-box;width:100%;min-width:0;height:40px;margin:0;padding:9px 11px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#1f2937;font:14px Arial,Helvetica,sans-serif}
#tqComplaintFilters input:focus,#tqComplaintFilters select:focus{outline:2px solid #fed7aa;outline-offset:1px;border-color:#f97316}
#tqComplaintFilters .tq-complaint-filter-actions{display:flex;align-items:center;gap:8px;height:40px}
#tqComplaintFilters .tq-apply-complaint-filter,#tqComplaintFilters .tq-reset-complaint-filter{display:inline-flex;align-items:center;justify-content:center;box-sizing:border-box;height:40px;min-width:68px;margin:0;padding:9px 14px;border:1px solid #f97316;border-radius:8px;text-decoration:none;font:600 14px Arial,Helvetica,sans-serif;white-space:nowrap;cursor:pointer}
#tqComplaintFilters .tq-apply-complaint-filter{background:#f97316;color:#fff}
#tqComplaintFilters .tq-reset-complaint-filter{background:#fff;color:#c2410c;border-color:#fed7aa}
#tqComplaintFilters .tq-apply-complaint-filter:hover{background:#ea580c}
#tqComplaintFilters .tq-reset-complaint-filter:hover{background:#fff7ed}
#tqComplaintTable .tq-view-photo{display:inline-flex;align-items:center;justify-content:center;gap:6px;box-sizing:border-box;min-height:34px;margin:0;padding:8px 11px;background:#fff7ed;color:#c2410c;border:1px solid #fdba74;border-radius:8px;font:600 13px Arial,Helvetica,sans-serif;text-decoration:none;white-space:nowrap;transition:background .15s,border-color .15s}
#tqComplaintTable .tq-view-photo svg{display:block;width:16px!important;height:16px!important;min-width:16px;max-width:16px;flex:0 0 16px;stroke:currentColor;fill:none}
#tqComplaintTable .tq-view-photo:hover{background:#ffedd5;border-color:#f97316}
#tqComplaintTable .tq-view-photo:focus-visible{outline:2px solid #f97316;outline-offset:3px}
@media(max-width:1050px){#tqComplaintFilters{grid-template-columns:repeat(2,minmax(0,1fr))}#tqComplaintFilters .tq-complaint-search{grid-column:1/-1}#tqComplaintFilters .tq-complaint-filter-actions{grid-column:1/-1;justify-content:flex-end}}
@media(max-width:560px){#tqComplaintFilters{grid-template-columns:1fr;padding:14px;gap:12px}#tqComplaintFilters .tq-complaint-filter-actions>*{flex:1}}
</style>
<style>
#tqComplaintTable .tq-complainant-contact{display:block;margin-top:5px;color:#475569;font:400 14px/1.5 Arial,Helvetica,sans-serif;text-decoration:none;white-space:nowrap}
#tqComplaintTable .tq-complainant-contact:hover{color:#c2410c;text-decoration:underline}
#tqComplaintTable .tq-complainant-note{margin-top:3px;color:#64748b;font:400 13px/1.5 Arial,Helvetica,sans-serif}
#tqComplaintTable .tq-contact-error{color:#b91c1c}
/* Scoped evidence controls preserve the existing complaint table and theme. */
#tqComplaintTable .tq-evidence-actions{display:flex;flex-wrap:wrap;align-items:center;gap:6px}
#tqComplaintTable button.tq-view-photo{cursor:pointer}
dialog.tq-evidence-dialog{position:fixed;inset:0;width:calc(100% - 32px);max-width:920px;max-height:90vh;max-height:90dvh;margin:auto!important;padding:0!important;border:0;border-radius:12px;background:#fff;color:#334155;font-family:Arial,Helvetica,sans-serif;box-shadow:0 20px 70px #0005;overflow:auto}
dialog.tq-evidence-dialog:not([open]){display:none!important}
.tq-evidence-dialog::backdrop{background:rgba(15,23,42,.72)}
.tq-evidence-dialog .tq-evidence-head{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:16px 20px;border-bottom:1px solid #e2e8f0}
.tq-evidence-dialog h2{margin:0;color:#9a3412;font:600 20px/1.4 Arial,Helvetica,sans-serif;overflow-wrap:anywhere}
.tq-evidence-dialog button,.tq-evidence-dialog a{font:600 14px Arial,Helvetica,sans-serif}
.tq-evidence-dialog .tq-evidence-close{width:38px;height:38px;flex:0 0 38px;border:1px solid #fed7aa;border-radius:8px;background:#fff7ed;color:#c2410c;font-size:25px;cursor:pointer}
.tq-evidence-dialog .tq-evidence-body{padding:16px 20px}
.tq-evidence-dialog .tq-evidence-status{margin:0 0 12px;font:15px/1.5 Arial,Helvetica,sans-serif;color:#475569}
.tq-evidence-dialog .tq-evidence-status.is-error{padding:12px;border-radius:8px;background:#fef2f2;color:#b91c1c}
.tq-evidence-dialog img,.tq-evidence-dialog video{display:block;max-width:100%;width:100%;max-height:65vh;object-fit:contain;margin:0 auto;background:#0f172a;border-radius:8px}
.tq-evidence-dialog img{background:#f8fafc}
.tq-evidence-dialog [hidden]{display:none!important}
.tq-evidence-dialog .tq-evidence-footer{display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:14px 20px;border-top:1px solid #e2e8f0}
.tq-evidence-dialog .tq-evidence-footer button,.tq-evidence-dialog .tq-evidence-footer a{display:inline-flex;align-items:center;justify-content:center;min-height:38px;padding:8px 13px;border:1px solid #fed7aa;border-radius:8px;background:#fff7ed;color:#c2410c;text-decoration:none;cursor:pointer}
.tq-evidence-dialog :focus-visible{outline:2px solid #f97316;outline-offset:3px}
body.tq-evidence-open{overflow:hidden}
@media(max-width:560px){.tq-evidence-dialog .tq-evidence-head,.tq-evidence-dialog .tq-evidence-body,.tq-evidence-dialog .tq-evidence-footer{padding:14px}.tq-evidence-dialog h2{font-size:18px}}
</style>
<style>
/* Compact complaint update action; native details preserves keyboard toggling. */
#tqComplaintTable .tq-complaint-update{min-width:195px}
#tqComplaintTable .tq-complaint-update-toggle{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:38px;margin:0;padding:8px 11px;border:1px solid #fdba74;border-radius:8px;background:#fff7ed;color:#c2410c;font:600 14px/20px Arial,Helvetica,sans-serif;white-space:nowrap;list-style:none;cursor:pointer;transition:background .15s,border-color .15s}
#tqComplaintTable .tq-complaint-update-toggle::-webkit-details-marker{display:none}
#tqComplaintTable .tq-complaint-update-toggle::marker{content:''}
#tqComplaintTable .tq-complaint-update-toggle svg{display:block;width:16px;height:16px;flex:0 0 16px}
#tqComplaintTable .tq-update-chevron{transition:transform .15s}
#tqComplaintTable .tq-complaint-update[open] .tq-update-chevron{transform:rotate(180deg)}
#tqComplaintTable .tq-complaint-update-toggle:hover,#tqComplaintTable .tq-complaint-update[open]>.tq-complaint-update-toggle{background:#ffedd5;border-color:#f97316}
#tqComplaintTable .tq-complaint-update-toggle:focus-visible,#tqComplaintTable .tq-complaint-update-save:focus-visible{outline:2px solid #f97316;outline-offset:3px}
#tqComplaintTable .tq-complaint-update .update-form{gap:8px;margin-top:10px;padding:12px;min-width:220px;max-width:270px;border:1px solid #fed7aa;border-radius:9px;background:#fffaf5}
#tqComplaintTable .tq-complaint-update .form-label{font-size:13px;line-height:18px;text-transform:none;letter-spacing:0;color:#475569}
#tqComplaintTable .tq-complaint-update select,#tqComplaintTable .tq-complaint-update textarea{font-size:14px}
#tqComplaintTable .tq-complaint-update-save{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:38px;padding:9px 12px;border:1px solid #f97316;border-radius:8px;background:#f97316;color:#fff;font:600 14px/20px Arial,Helvetica,sans-serif;cursor:pointer}
#tqComplaintTable .tq-complaint-update-save svg{width:17px;height:17px;flex:0 0 17px}
#tqComplaintTable .tq-complaint-update-save:hover{background:#ea580c;border-color:#ea580c}
</style>
<style>
#tqComplaintTable .tq-complaint-driver{display:flex;align-items:center;gap:10px;min-width:175px}
#tqComplaintTable .tq-driver-avatar{display:inline-flex;align-items:center;justify-content:center;flex:0 0 44px;width:44px;height:44px;margin:0;padding:0;border:2px solid #ffedd5;border-radius:50%;overflow:hidden;background:#fff7ed;color:#c2410c;vertical-align:middle}
#tqComplaintTable button.tq-driver-avatar{cursor:pointer}
#tqComplaintTable button.tq-driver-avatar:hover{border-color:#f97316;box-shadow:0 0 0 2px #fff7ed}
#tqComplaintTable button.tq-driver-avatar:focus-visible{outline:2px solid #f97316;outline-offset:3px}
#tqComplaintTable .tq-driver-avatar img{display:block;width:100%;height:100%;object-fit:cover}
#tqComplaintTable .tq-driver-avatar [hidden]{display:none!important}
#tqComplaintTable .tq-driver-initial{display:flex;align-items:center;justify-content:center;width:100%;height:100%;font:600 18px Arial,Helvetica,sans-serif;text-transform:uppercase}
#tqComplaintTable .tq-driver-avatar:disabled{cursor:default}
</style>
<meta name="referrer" content="no-referrer">

<style>#tqComplaintTable .tq-review-help{display:block;color:#64748b;font:12px/18px Arial,Helvetica,sans-serif}</style>
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
        <a href="drivers.php">
            <span class="icon">♙</span>
            Driver
        </a>
        <a href="register.php">
            <span class="icon">＋</span>
            Register
        </a>
        <a href="complaints.php" class="active" aria-current="page">
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
                    Complaint & Violation Monitoring
                </div>
                <div class="page-subtitle">
                    Review and manage passenger complaints received through TRIKE-Q.
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
            <!-- =================================================
                 MESSAGES
                 ================================================= -->
            <?php if ($message !== ""): ?>
                <div class="alert success">
                    ✓
                    <?= htmlspecialchars(
                        $message
                    ) ?>
                </div>
            <?php endif; ?>
            <?php if ($error !== ""): ?>
                <div class="alert error">
                    ⚠
                    <?= htmlspecialchars(
                        $error
                    ) ?>
                </div>
            <?php endif; ?>
            <!-- =================================================
                 INTRODUCTION
                 ================================================= -->
            <div class="intro-card">
                <div class="intro-title">
                    Passenger Complaint Monitoring
                </div>
                <div class="intro-text">
                    Complaints submitted by passengers through
                    the TRIKE-Q mobile application appear here.
                    TMU Admin can review the complaint details,
                    update its status, and add administrative remarks.
                </div>
            </div>
            <!-- =================================================
                 STATISTICS
                 ================================================= -->
            <div class="stats">
                <div class="stat-card">
                    <div class="stat-label">
                        Total Complaints
                    </div>
                    <div class="stat-number">
                        <?= number_format(
                            $totalComplaints
                        ) ?>
                    </div>
                    <div class="stat-description">
                        All received complaints
                    </div>
                </div>
                <div class="stat-card pending">
                    <div class="stat-label">
                        Pending
                    </div>
                    <div class="stat-number">
                        <?= number_format(
                            $pendingCount
                        ) ?>
                    </div>
                    <div class="stat-description">
                        Waiting for review
                    </div>
                </div>
<div class="stat-card resolved">
                    <div class="stat-label">
                        Resolved
                    </div>
                    <div class="stat-number">
                        <?= number_format(
                            $resolvedCount
                        ) ?>
                    </div>
                    <div class="stat-description">
                        Complaints completed
                    </div>
                </div>
<div class="stat-card"><div class="stat-label">Dismissed</div><div class="stat-number"><?= number_format($dismissedCount) ?></div><div class="stat-description">Complaints dismissed after review</div></div>
            </div>
            <!-- =================================================
                 COMPLAINT TABLE
                 ================================================= -->
            <div class="card">
                <div class="card-header">
                    <div>
                        <div class="card-title">
                            Passenger Complaints
                        </div>
                        <div class="card-description">
                            Review complaint information and update its current status.
                        </div>
                    </div>
                    <div class="record-count">
                        <?= number_format(
                            $totalComplaints
                        ) ?>
                        Record<?= $totalComplaints == 1 ? "" : "s" ?>
                    </div>
                </div>
                <?php if ($tqContactError): ?><div class="alert error" role="alert"><?= tqEscape($tqContactError) ?></div><?php endif; ?>
                <form method="GET" id="tqComplaintFilters" aria-label="Filter complaints">
<div class="tq-complaint-search"><label for="complaintSearch">Search complaints</label><input type="search" id="complaintSearch" name="search" value="<?= tqEscape($tqSearch) ?>" placeholder="Driver, complainant, contact or complaint" autocomplete="off"></div>
<div><label for="complaintStatus">Status</label><select id="complaintStatus" name="status"><?php foreach ([''=>'All statuses','Pending'=>'Pending','Resolved'=>'Resolved','Dismissed'=>'Dismissed'] as $value=>$label): ?><option value="<?= tqEscape($value) ?>" <?= $tqStatus === $value ? 'selected' : '' ?>><?= tqEscape($label) ?></option><?php endforeach; ?></select></div>
<div><label for="complaintToda">TODA organization</label><select id="complaintToda" name="toda"><option value="">All TODAs</option><?php foreach ($tqTodaOptions as $option): ?><option value="<?= tqEscape($option) ?>" <?= $tqToda === $option ? 'selected' : '' ?>><?= tqEscape($option) ?></option><?php endforeach; ?></select></div>
<div class="tq-complaint-filter-actions"><button type="submit" class="tq-apply-complaint-filter">Filter</button><a href="complaints.php" class="tq-reset-complaint-filter">Reset</a></div>
</form>
<div class="table-container">
                    <?php if (empty($complaints)): ?>
                        <div class="empty">
                            <div class="empty-icon">
                                ✓
                            </div>
                            <div class="empty-title">
                                No complaints received
                            </div>
                            <div class="empty-text">
                                Passenger complaints will appear here when submitted through the mobile application.
                            </div>
                        </div>
                    <?php else: ?>
                        <table id="tqComplaintTable">
                            <thead>
                                <tr>
                                    <th>
                                        Received
                                    </th>
                                    <th>
                                        Incident Date
                                    </th>
                                    <th>
                                        Driver
                                    </th>
                                    <th>
                                        Tricycle No.
                                    </th>
                                    <th>
                                        Plate No.
                                    </th>
                                    <th>
                                        TODA
                                    </th>
                                    <th>
                                        Violation
                                    </th>
                                    <th>
                                        Complainant
                                    </th>
                                    <th>
                                        Complaint Details
                                    </th>
                                    <th>Evidence</th>
<th>
                                        Current Status
                                    </th>
                                    <th>
                                        Admin Update
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (
                                    $complaints
                                    as $complaint
                                ): ?>
                                    <?php
                                    $status =
                                        $complaint["status"]
                                        ?? "Pending";
                                    $statusClass =
                                        "pending";
                                    if (
                                        $status ===
                                        "Under Review"
                                    ) {
                                        $statusClass =
                                            "review";
                                    }
                                    if (
                                        $status ===
                                        "Resolved"
                                    ) {
                                        $statusClass =
                                            "resolved";
                                    }
                                    if (
                                        $status ===
                                        "Dismissed"
                                    ) {
                                        $statusClass =
                                            "dismissed";
                                    }
                                    $driverName =
                                        $complaint["drivers"]["name"]
                                        ??
                                        $complaint["driver_name"]
                                        ??
                                        "-";
                                    $tricycleNumber =
                                        $complaint["drivers"]["tricycle_number"]
                                        ??
                                        "-";
                                    $plateNumber =
                                        $complaint["drivers"]["plate_number"]
                                        ??
                                        "-";
                                    $todaName =
                                        $complaint["toda_organizations"]["name"]
                                        ??
                                        "-";
                                    $tqReporter = $tqPrivateContacts[$complaint['id']] ?? null;
                                    $tqLegacyComplainant = trim((string)($complaint['complainant'] ?? ''));
                                    $tqGenericComplainants = ['', '-', 'Passenger QR scan', 'Passenger', 'Anonymous'];
                                    $tqHasLegacyName = !in_array(strtolower($tqLegacyComplainant), array_map('strtolower',$tqGenericComplainants),true);
                                    $complainant = $tqReporter ? $tqReporter['full_name'] : ($tqHasLegacyName ? $tqLegacyComplainant : null);
                                    $tqReporterPhone = $tqReporter['contact_number'] ?? null;
                                    $complaintType =
                                        $complaint["complaint_type"]
                                        ??
                                        "-";
                                    $messageText =
                                        $complaint["message"]
                                        ??
                                        "-";
                                    ?>
                                    <tr>
                                        <!-- RECEIVED -->
                                        <td>
                                            <?php
                                            $createdAt =
                                                $complaint["created_at"]
                                                ?? null;
                                            if ($createdAt) {
                                                echo htmlspecialchars(
                                                    date(
                                                        "M d, Y",
                                                        strtotime(
                                                            $createdAt
                                                        )
                                                    )
                                                );
                                                echo "<div class='secondary-text'>";
                                                echo htmlspecialchars(
                                                    date(
                                                        "h:i A",
                                                        strtotime(
                                                            $createdAt
                                                        )
                                                    )
                                                );
                                                echo "</div>";
                                            } else {
                                                echo "-";
                                            }
                                            ?>
                                        </td>
                                        <!-- INCIDENT DATE -->
                                        <td>
                                            <?php
                                            $incidentDate =
                                                $complaint["incident_date"]
                                                ?? null;
                                            if ($incidentDate) {
                                                echo htmlspecialchars(
                                                    date(
                                                        "M d, Y",
                                                        strtotime(
                                                            $incidentDate
                                                        )
                                                    )
                                                );
                                            } else {
                                                echo '<span class="tq-complainant-note">Not provided</span>';
                                            }
                                            ?>
                                        </td>
                                        <!-- DRIVER -->
                                        <td>
                                            <?php
                                            $tqDriverPhoto = $complaint['drivers']['profile_picture_url'] ?? null;
                                            if (!is_string($tqDriverPhoto) || !filter_var($tqDriverPhoto,FILTER_VALIDATE_URL) || strtolower((string)parse_url($tqDriverPhoto,PHP_URL_SCHEME)) !== 'https') $tqDriverPhoto = null;
                                            $tqDriverInitial = function_exists('mb_substr') ? mb_substr(trim($driverName),0,1,'UTF-8') : substr(trim($driverName),0,1);
                                            if ($tqDriverInitial === '' || $tqDriverInitial === '-') $tqDriverInitial = '?';
                                            ?>
                                            <div class="tq-complaint-driver">
                                            <?php if ($tqDriverPhoto): ?>
                                                <button type="button" class="tq-driver-avatar" data-tq-complaint-profile data-profile-url="<?= tqEscape($tqDriverPhoto) ?>" data-evidence-title="<?= tqEscape('Driver profile — '.$driverName) ?>" aria-label="<?= tqEscape('View '.$driverName.' profile picture') ?>">
                                                    <img src="<?= tqEscape($tqDriverPhoto) ?>" alt="<?= tqEscape($driverName.' profile picture') ?>" loading="lazy" referrerpolicy="no-referrer" onerror="this.hidden=true;this.nextElementSibling.hidden=false;this.closest('button').disabled=true;">
                                                    <span class="tq-driver-initial" hidden aria-hidden="true"><?= tqEscape($tqDriverInitial) ?></span>
                                                </button>
                                            <?php else: ?>
                                                <span class="tq-driver-avatar tq-driver-avatar-fallback" aria-hidden="true"><span class="tq-driver-initial"><?= tqEscape($tqDriverInitial) ?></span></span>
                                            <?php endif; ?>
                                                <div class="driver-name"><?= tqEscape($driverName) ?></div>
                                            </div>
                                        </td>
                                        <!-- TRICYCLE -->
                                        <td>
                                            <?= htmlspecialchars(
                                                $tricycleNumber
                                            ) ?>
                                        </td>
                                        <!-- PLATE -->
                                        <td>
                                            <?= htmlspecialchars(
                                                $plateNumber
                                            ) ?>
                                        </td>
                                        <!-- TODA -->
                                        <td>
                                            <?= htmlspecialchars(
                                                $todaName
                                            ) ?>
                                        </td>
                                        <!-- VIOLATION -->
                                        <td>
                                            <span class="violation-type">
                                                <?= htmlspecialchars(
                                                    $complaintType
                                                ) ?>
                                            </span>
                                        </td>
                                        <!-- COMPLAINANT: private details are shown to TMU only -->
                                        <td>
                                            <?php if ($complainant !== null): ?>
                                            <div class="driver-name"><?= tqEscape($complainant) ?></div>
                                            <?php if ($tqReporterPhone): ?><a class="tq-complainant-contact" href="tel:<?= tqEscape($tqReporterPhone) ?>"><?= tqEscape($tqReporterPhone) ?></a>
                                            <?php else: ?><div class="tq-complainant-note">Contact number not recorded</div><?php endif; ?>
                                            <?php elseif ($tqContactError): ?>
                                            <div class="tq-complainant-note tq-contact-error">Details unavailable — refresh page</div>
                                            <?php else: ?>
                                            <div class="tq-complainant-note">Not recorded</div>
                                            <div class="tq-complainant-note">No saved name/contact for this complaint</div>
                                            <?php endif; ?>
                                        </td>
                                        <!-- COMPLAINT -->
                                        <td>
                                            <div class="complaint-message">
                                                <?= nl2br(
                                                    htmlspecialchars(
                                                        $messageText
                                                    )
                                                ) ?>
                                            </div>
                                        </td>
<td><div class="tq-evidence-actions">
<?php if (!empty($complaint['evidence_path'])): ?>
<button type="button" class="tq-view-photo" data-tq-complaint-media="photo" data-complaint-id="<?= tqEscape($complaint['id']) ?>" data-evidence-title="<?= tqEscape('Complaint photo — '.$driverName) ?>" aria-label="View complaint photo evidence"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg><span>View Photo</span></button>
<?php endif; ?>
<?php if (!empty($complaint['video_evidence_path'])): ?>
<button type="button" class="tq-view-photo tq-view-video" data-tq-complaint-media="video" data-complaint-id="<?= tqEscape($complaint['id']) ?>" data-evidence-title="<?= tqEscape('Complaint video — '.$driverName) ?>" aria-label="View complaint video evidence"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="3"/><path d="m10 8 6 4-6 4Z"/></svg><span>View Video</span></button>
<?php endif; ?>
<?php if (empty($complaint['evidence_path']) && empty($complaint['video_evidence_path'])): ?>—<?php endif; ?>
</div></td>
                                        <!-- STATUS -->
                                        <td>
                                            <span
                                                class="status <?= $statusClass ?>"
                                            >
                                                <?= htmlspecialchars(
                                                    $status
                                                ) ?>
                                            </span>
                                        </td>
                                        <!-- ADMIN UPDATE -->
                                        <td>
                                            <?php
$reviewFormFailed = $error !== '' && $reviewComplaintId === $complaint['id'];
$reviewFormStatus = $reviewFormFailed ? $reviewStatus : $status;
$reviewFormRemarks = $reviewFormFailed ? $reviewRemarks : ($complaint['remarks'] ?? '');
$reviewFormVersion = $reviewFormFailed ? $reviewVersion : ($complaint['tmu_review_version'] ?? '');
?>
<details class="tq-complaint-update" <?=$reviewFormFailed?'open':''?>><summary class="tq-complaint-update-toggle"><svg class="tq-update-pen" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m16 3 5 5-12 12-6 1 1-6Z"/><path d="m14 5 5 5"/></svg><span>Update complaint</span><svg class="tq-update-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></summary>
                                            <form
                                                method="POST"
                                                class="update-form"
                                            >
<input type="hidden" name="tq_csrf" value="<?= tqEscape(tqCsrf()) ?>">
<input type="hidden" name="review_version" value="<?=tqEscape($reviewFormVersion)?>">
                                                <input
                                                    type="hidden"
                                                    name="complaint_id"
                                                    value="<?= htmlspecialchars(
                                                        $complaint["id"]
                                                    ) ?>"
                                                >
                                                <label class="form-label">
                                                    Status
                                                </label>
                                                <select
                                                    name="status"
                                                    required
                                                >
                                                    <option
                                                        value="Pending"
                                                        <?= $reviewFormStatus === "Pending"
                                                            ? "selected"
                                                            : "" ?>
                                                    >
                                                        Pending
                                                    </option>
                                                    <option
                                                        value="Resolved"
                                                        <?= $reviewFormStatus === "Resolved"
                                                            ? "selected"
                                                            : "" ?>
                                                    >
                                                        Resolved
                                                    </option>
                                                    <option
                                                        value="Dismissed"
                                                        <?= $reviewFormStatus === "Dismissed"
                                                            ? "selected"
                                                            : "" ?>
                                                    >
                                                        Dismissed
                                                    </option>
                                                </select>
                                                <label class="form-label">
                                                    Action Taken / Remarks
                                                </label>
                                                <textarea
                                                    name="remarks"
                                                    maxlength="2000"
                                                    <?=in_array($reviewFormStatus,['Resolved','Dismissed'],true)?'required':''?>
                                                    placeholder="Add notes or action taken..."
                                                ><?= htmlspecialchars(
                                                    $reviewFormRemarks
                                                ) ?></textarea>
                                                <small class="tq-review-help">Remarks are required for Resolved and Dismissed. Use Pending while investigating.</small>
                                                <button type="submit" class="update-btn tq-complaint-update-save"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg><span>Update Complaint</span></button>
                                            </form>
                                            </details>

                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </main>
</div>
<dialog id="tqEvidenceDialog" class="tq-evidence-dialog" aria-labelledby="tqEvidenceTitle">
  <div class="tq-evidence-head"><h2 id="tqEvidenceTitle">Complaint evidence</h2><button type="button" class="tq-evidence-close" id="tqEvidenceClose" aria-label="Close evidence viewer">×</button></div>
  <div class="tq-evidence-body">
    <p id="tqEvidenceStatus" class="tq-evidence-status" role="status" aria-live="polite">Loading evidence…</p>
    <img id="tqEvidencePhoto" alt="Complaint photo evidence" referrerpolicy="no-referrer" hidden>
    <video id="tqEvidenceVideo" controls playsinline preload="metadata" hidden aria-label="Complaint video evidence"></video>
  </div>
  <div class="tq-evidence-footer"><button type="button" id="tqEvidenceReload">Reload</button><a id="tqEvidenceOpen" target="_blank" rel="noopener noreferrer" hidden>Open file in new tab</a></div>
</dialog>
<script>
(function () {
    'use strict';
    var dialog = document.getElementById('tqEvidenceDialog');
    if (!dialog) return;
    var title = document.getElementById('tqEvidenceTitle');
    var status = document.getElementById('tqEvidenceStatus');
    var image = document.getElementById('tqEvidencePhoto');
    var video = document.getElementById('tqEvidenceVideo');
    var openFile = document.getElementById('tqEvidenceOpen');
    var reload = document.getElementById('tqEvidenceReload');
    var controller, generation = 0, current, trigger;
    function resetMedia() {
        video.pause(); video.removeAttribute('src'); video.load(); video.hidden = true;
        image.removeAttribute('src'); image.hidden = true;
        openFile.removeAttribute('href'); openFile.hidden = true;
    }
    function cleanup() {
        generation++;
        if (controller) controller.abort();
        resetMedia(); current = null;
        document.body.classList.remove('tq-evidence-open');
        if (trigger && document.contains(trigger)) trigger.focus();
    }
    function close() {
        if (typeof dialog.close === 'function') dialog.close();
        else { dialog.removeAttribute('open'); cleanup(); }
    }
    async function load() {
        if (!current) return;
        var request = ++generation;
        if (controller) controller.abort();
        controller = new AbortController();
        resetMedia(); status.classList.remove('is-error');
        status.textContent = 'Loading evidence…'; status.hidden = false;
        if (current.kind === 'profile') {
            try {
                var profileUrl = new URL(current.url);
                if (profileUrl.protocol !== 'https:') throw new Error('This driver photo URL is invalid.');
                image.onerror = function () {
                    if (request !== generation) return;
                    status.classList.add('is-error'); status.hidden = false;
                    status.textContent = 'This driver photo could not be loaded. Try Reload.';
                };
                image.alt = title.textContent; image.src = profileUrl.href; image.hidden = false;
                openFile.href = profileUrl.href; openFile.hidden = false;
                status.textContent = 'Driver profile picture';
            } catch (error) {
                status.classList.add('is-error'); status.textContent = error.message;
            }
            return;
        }
        image.alt = 'Complaint photo evidence';
        var url = new URL('complaints.php', window.location.href);
        url.searchParams.set('tq_evidence', current.kind);
        url.searchParams.set('id', current.id);
        try {
            var response = await fetch(url.href, {
                credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
                headers: { Accept: 'application/json' }
            });
            if (!(response.headers.get('content-type') || '').includes('application/json')) {
                throw new Error('Your session may have expired. Close this viewer and sign in again.');
            }
            var data = await response.json();
            if (request !== generation) return;
            if (!response.ok || !data.url) throw new Error(data.error || 'Evidence could not be loaded. Try Reload.');
            var mediaUrl = new URL(data.url);
            if (mediaUrl.protocol !== 'https:') throw new Error('An invalid evidence link was returned.');
            openFile.href = mediaUrl.href; openFile.hidden = false;
            var fail = function () {
                if (request !== generation) return;
                status.classList.add('is-error'); status.hidden = false;
                status.textContent = 'This file could not be displayed. Try Reload, or open the file in a new tab. Video playback depends on your browser’s supported codecs.';
            };
            if (current.kind === 'video') {
                video.onerror = fail; video.src = mediaUrl.href; video.hidden = false; video.load();
                status.textContent = 'Use the video controls to play, pause, adjust sound, or enter fullscreen.';
            } else {
                image.onerror = fail; image.src = mediaUrl.href; image.hidden = false;
                status.textContent = 'Complaint photo evidence';
            }
        } catch (error) {
            if (request !== generation || error.name === 'AbortError') return;
            status.classList.add('is-error'); status.hidden = false;
            status.textContent = error.message || 'Evidence could not be loaded. Try Reload.';
        }
    }
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-tq-complaint-media], [data-tq-complaint-profile]');
        if (!button) return;
        event.preventDefault();
        trigger = button;
        current = button.hasAttribute('data-tq-complaint-profile')
            ? { kind: 'profile', url: button.dataset.profileUrl }
            : { kind: button.dataset.tqComplaintMedia, id: button.dataset.complaintId };
        title.textContent = button.dataset.evidenceTitle || 'Complaint evidence';
        if (typeof dialog.showModal === 'function') dialog.showModal();
        else dialog.setAttribute('open', '');
        document.body.classList.add('tq-evidence-open');
        document.getElementById('tqEvidenceClose').focus();
        void load();
    });
    document.getElementById('tqEvidenceClose').addEventListener('click', close);
    dialog.addEventListener('cancel', function (event) { event.preventDefault(); close(); });
    dialog.addEventListener('close', cleanup);
    dialog.addEventListener('click', function (event) { if (event.target === dialog) close(); });
    reload.addEventListener('click', function () { void load(); });
}());
</script>
<script>
(function(){
 'use strict';
 document.querySelectorAll('.tq-complaint-update form').forEach(function(form){
  var select=form.querySelector('select[name="status"]'),remarks=form.querySelector('textarea[name="remarks"]');
  if(!select||!remarks)return;
  var sync=function(){remarks.required=['Resolved','Dismissed'].includes(select.value);};
  select.addEventListener('change',sync);sync();
 });
})();

</script></body>
</html>
