<?php

session_start();

date_default_timezone_set("Asia/Manila");

if (!isset($_SESSION["user_id"]) || empty($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . "/config/supabase.php";
require_once __DIR__ . '/tmu-tools.php';
tqRequireAdmin();

require_once __DIR__ . "/config/email.php";
if (empty($_SESSION['renewal_notice_csrf'])) $_SESSION['renewal_notice_csrf'] = bin2hex(random_bytes(32));

function tqRenewalBell($driverId, $eventId, $mode = 'manual') {
    $result = supabaseRequest('rpc/trikeq_admin_renewal_bell', 'POST', [
        'p_driver_id' => $driverId, 'p_event_id' => $eventId, 'p_mode' => $mode
    ]);
    if (empty($result['success'])) return ['success' => false, 'message' =>
        'Bell notification failed: ' . ($result['error'] ?? 'Run renewal_bell.sql and check TMU permissions.')];
    $data = $result['data'] ?? [];
    if (!is_array($data) || !isset($data['recipients'], $data['created'])) return [
        'success' => false, 'message' => 'The bell notification response could not be confirmed.'
    ];
    return ['success' => true, 'recipients' => (int)$data['recipients'], 'created' => (int)$data['created']];
}

function tqApprovedPresidentRecipients($todaId) {
    static $cache=[];
    if (!$todaId) return [];
    if (isset($cache[$todaId])) return $cache[$todaId];
    $r=supabaseRequest('rpc/trikeq_renewal_president_emails','POST',['p_toda_id'=>$todaId]);
    $rows=[];
    if (empty($r['success'])) $GLOBALS['tqRecipientLookupError']='Approved president account email lookup failed. Run renewal_president_emails.sql and check permissions. Driver and available president-driver emails will still be attempted.';
    if (!empty($r['success'])) foreach (($r['data']??[]) as $p) {
        if (filter_var($p['email']??'', FILTER_VALIDATE_EMAIL)) $rows[]=['email'=>$p['email'],'label'=>'TODA President '.$p['name']];
    }
    return $cache[$todaId]=$rows;
}


/* =========================================================
   FLASH MESSAGE
========================================================= */

function setFlashMessage($type, $message)
{
    $_SESSION["renewal_flash"] = [
        "type" => $type,
        "message" => $message
    ];
}

function getFlashMessage()
{
    if (!isset($_SESSION["renewal_flash"])) {
        return null;
    }

    $message = $_SESSION["renewal_flash"];
    unset($_SESSION["renewal_flash"]);

    return $message;
}


/* =========================================================
   HELPERS
========================================================= */

function isValidUuid($value)
{
    return preg_match(
        '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/',
        (string)$value
    ) === 1;
}


function h($value)
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        "UTF-8"
    );
}


function getDriverById($driverId)
{
    if (!isValidUuid($driverId)) {
        return null;
    }

    $result = supabaseRequest(
        "drivers",
        "GET",
        null,
        "id=eq." . urlencode($driverId) .
        "&select=*"
    );

    if (
        $result["status"] >= 200 &&
        $result["status"] < 300 &&
        !empty($result["data"]) &&
        isset($result["data"][0])
    ) {
        return $result["data"][0];
    }

    return null;
}

function sendRenewalEmailToRecipients($recipients, $subject, $body)
{
    $valid = [];
    foreach ($recipients as $recipient) {
        $email = trim((string)($recipient['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $key = strtolower($email);
            $valid[$key] = isset($valid[$key]) ? $valid[$key] . ' / ' . trim((string)($recipient['label'] ?? $email)) : trim((string)($recipient['label'] ?? $email));
        }
    }
    if (!$valid) return ['success' => false, 'sent' => [], 'errors' => ['No valid recipient email address was found.']];
    if (!function_exists('sendEmail')) return ['success' => false, 'sent' => [], 'errors' => ['sendEmail() was not found in config/email.php.']];
    $sent = []; $errors = empty($GLOBALS['tqRecipientLookupError']) ? [] : [$GLOBALS['tqRecipientLookupError']];
    foreach ($valid as $email => $label) {
        try {
            $result = sendEmail($email, $subject, $body);
            $ok = $result === true || (is_array($result) && ($result['success'] ?? false) === true);
            if ($ok) $sent[] = ['email' => $email, 'label' => $label];
            else $errors[] = $label . ': ' . (is_array($result) ? ($result['message'] ?? $result['error'] ?? 'Email service error.') : 'Email service did not confirm delivery.');
        } catch (Throwable $e) { $errors[] = $label . ': ' . $e->getMessage(); }
    }
    return ['success' => count($sent) > 0, 'sent' => $sent, 'errors' => $errors];
}


/* =========================================================
   SAVE RENEWAL EMAIL LOG
========================================================= */

function saveRenewalLog(
    $driverId,
    $expiryDate,
    $reminderType,
    $message,
    $status,
    $messageId = null
) {
    if (!isValidUuid($driverId)) {
        return [
            "success" => false,
            "message" => "Invalid driver UUID."
        ];
    }

    $rpcData = [
        "p_driver_id" => $driverId,
        "p_expiry_date" => $expiryDate,
        "p_reminder_type" => $reminderType,
        "p_message" => $message,
        "p_status" => $status
    ];

    if ($messageId !== null && $messageId !== "") {
        $rpcData["p_message_id"] = $messageId;
    }

    $result = supabaseRequest(
        "rpc/log_renewal_email",
        "POST",
        $rpcData
    );

    if (
        $result["status"] >= 200 &&
        $result["status"] < 300
    ) {
        return [
            "success" => true,
            "message" => "Renewal log saved successfully."
        ];
    }

    $errorMessage = "Unable to save renewal log.";

    if (isset($result["data"]) && is_array($result["data"])) {

        if (isset($result["data"]["message"])) {
            $errorMessage = $result["data"]["message"];

        } elseif (isset($result["data"]["hint"])) {
            $errorMessage = $result["data"]["hint"];

        } elseif (isset($result["data"]["details"])) {
            $errorMessage = $result["data"]["details"];

        } elseif (isset($result["data"]["error"])) {
            $errorMessage = $result["data"]["error"];
        }
    }

    return [
        "success" => false,
        "message" => $errorMessage
    ];
}


/* =========================================================
   GET ADMIN INFORMATION
========================================================= */

$adminName = "TMU Administrator";
$adminEmail = "";

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
    !empty($adminResult["data"]) &&
    isset($adminResult["data"][0])
) {

    $admin = $adminResult["data"][0];

    if (!empty($admin["name"])) {
        $adminName = $admin["name"];
    }

    if (!empty($admin["email"])) {
        $adminEmail = $admin["email"];
    }
}


/* =========================================================
   MANUAL RENEW DRIVER
========================================================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["action"]) &&
    $_POST["action"] === "renew_driver"
) {

    $driverUuid = trim(
        $_POST["driver_id"] ?? ""
    );

    $newExpiryDate = trim(
        $_POST["new_expiry_date"] ?? ""
    );


    if (!isValidUuid($driverUuid)) {

        setFlashMessage(
            "error",
            "Invalid driver ID. Please try again."
        );

        header("Location: renewals.php");
        exit;
    }


    $newExpiry = DateTime::createFromFormat(
        "Y-m-d",
        $newExpiryDate
    );

    $dateErrors = DateTime::getLastErrors();

    if ($dateErrors === false) {
        $dateErrors = [
            "warning_count" => 0,
            "error_count" => 0
        ];
    }


    if (
        !$newExpiry ||
        $dateErrors["warning_count"] > 0 ||
        $dateErrors["error_count"] > 0 ||
        $newExpiry->format("Y-m-d") !== $newExpiryDate
    ) {

        setFlashMessage(
            "error",
            "Please provide a valid new expiry date."
        );

        header("Location: renewals.php");
        exit;
    }


    $todayDate = new DateTime(
        date("Y-m-d")
    );


    if ($newExpiry <= $todayDate) {

        setFlashMessage(
            "error",
            "The new franchise expiry date must be after today."
        );

        header("Location: renewals.php");
        exit;
    }


    $driver = getDriverById(
        $driverUuid
    );

    if (!$driver) {

        setFlashMessage(
            "error",
            "Driver record could not be found."
        );

        header("Location: renewals.php");
        exit;
    }


    $updateData = [
        "franchise_expiry_date" => $newExpiry->format("Y-m-d"),
        "last_renewal_date" => $todayDate->format("Y-m-d"),
        "renewal_status" => "Valid"
    ];

    $updateResult = supabaseRequest(
        "drivers",
        "PATCH",
        $updateData,
        "id=eq." . urlencode($driverUuid)
    );


    if (
        $updateResult["status"] >= 200 &&
        $updateResult["status"] < 300
    ) {

        $driverName = trim(
            $driver["name"] ?? "Driver"
        );

        setFlashMessage(
            "success",
            "Franchise renewal completed successfully for " .
            $driverName .
            ". New expiry date: " .
            $newExpiry->format("F d, Y") .
            "."
        );

    } else {

        $errorMessage =
            "Unable to renew the driver's franchise.";

        if (
            isset($updateResult["data"]) &&
            is_array($updateResult["data"])
        ) {

            if (isset($updateResult["data"]["message"])) {
                $errorMessage =
                    $updateResult["data"]["message"];

            } elseif (isset($updateResult["data"]["details"])) {
                $errorMessage =
                    $updateResult["data"]["details"];

            } elseif (isset($updateResult["data"]["hint"])) {
                $errorMessage =
                    $updateResult["data"]["hint"];

            } elseif (isset($updateResult["data"]["error"])) {
                $errorMessage =
                    $updateResult["data"]["error"];
            }
        }

        setFlashMessage(
            "error",
            "Failed to renew driver: " .
            $errorMessage
        );
    }


    header("Location: renewals.php");
    exit;
}


/* =========================================================
   MANUAL SEND EMAIL
========================================================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["action"]) &&
    $_POST["action"] === "send_email"
) {

    if (!isset($_POST['notice_csrf']) || !is_string($_POST['notice_csrf']) ||
        !hash_equals($_SESSION['renewal_notice_csrf'], $_POST['notice_csrf']) ||
        !is_string($_POST['notice_event_id'] ?? null) ||
        !preg_match('/^[a-f0-9]{32}$/', $_POST['notice_event_id'])) {
        setFlashMessage('error', 'This reminder form expired. Refresh the page and try again.');
        header('Location: renewals.php'); exit;
    }
    $noticeEventId = $_POST['notice_event_id'];

    $driverUuid = trim(
        $_POST["driver_id"] ?? ""
    );


    if (!isValidUuid($driverUuid)) {

        setFlashMessage(
            "error",
            "Invalid driver ID."
        );

        header("Location: renewals.php");
        exit;
    }


    $driver = getDriverById(
        $driverUuid
    );

    if (!$driver) {

        setFlashMessage(
            "error",
            "Driver record not found."
        );

        header("Location: renewals.php");
        exit;
    }


    $driverName = trim(
        $driver["name"] ?? "Driver"
    );
    $driverEmail = trim($driver["email"] ?? "");

    $todaId =
        $driver["toda_id"] ?? null;

    $todaName = "No TODA";

    $presidentName = "";
    $presidentEmail = "";


    /* ---------------------------------------------
       GET TODA
    --------------------------------------------- */

    if (
        $todaId &&
        isValidUuid($todaId)
    ) {

        $todaResult = supabaseRequest(
            "toda_organizations",
            "GET",
            null,
            "id=eq." .
            urlencode($todaId) .
            "&select=*"
        );

        if (
            $todaResult["status"] >= 200 &&
            $todaResult["status"] < 300 &&
            !empty($todaResult["data"]) &&
            isset($todaResult["data"][0])
        ) {

            $toda = $todaResult["data"][0];

            if (!empty($toda["name"])) {
                $todaName = $toda["name"];
            }
        }
    }


    /* ---------------------------------------------
       FIND TODA PRESIDENT
    --------------------------------------------- */

    $presidentResult = null;

    if (
        $todaId &&
        isValidUuid($todaId)
    ) {

        $presidentResult = supabaseRequest(
            "drivers",
            "GET",
            null,
            "toda_id=eq." .
            urlencode($todaId) .
            "&position=eq.President" .
            "&select=id,name,email"
        );
    }


    if (
        $presidentResult &&
        $presidentResult["status"] >= 200 &&
        $presidentResult["status"] < 300 &&
        !empty($presidentResult["data"])
    ) {

        $president =
            $presidentResult["data"][0];

        $presidentName =
            trim(
                $president["name"] ?? ""
            );

        $presidentEmail =
            trim(
                $president["email"] ?? ""
            );
    }


    $manualRecipients = [
        ["email" => $driverEmail, "label" => "Driver " . $driverName],
        ["email" => $presidentEmail, "label" => "TODA President " . ($presidentName ?: $presidentEmail)]
    ];
    $manualRecipients = array_merge($manualRecipients, tqApprovedPresidentRecipients($todaId));
    /* ---------------------------------------------
       EXPIRY
    --------------------------------------------- */

    $expiryDate =
        $driver["franchise_expiry_date"] ?? null;

    if ($expiryDate) {

        $expiryObject =
            new DateTime($expiryDate);

        $expiryDisplay =
            $expiryObject->format("F d, Y");

    } else {

        $expiryDisplay =
            "No expiry date";
    }


    /* ---------------------------------------------
       EMAIL
    --------------------------------------------- */

    $subject =
        "TRIKE-Q Franchise Renewal Reminder - " .
        $driverName;


    $emailBody = "
        <html>
        <body style=\"font-family: Arial, sans-serif; line-height: 1.6; color: #333;\">

            <h2 style=\"color:#f97316;\">
                Franchise Renewal Reminder
            </h2>

            <p>
                Good day,
            </p>

            <p>
                This is a reminder regarding the franchise renewal
                of a driver registered under your TODA organization.
            </p>

            <table cellpadding=\"8\" cellspacing=\"0\" border=\"0\">

                <tr>
                    <td><strong>Driver Name:</strong></td>
                    <td>" . h($driverName) . "</td>
                </tr>

                <tr>
                    <td><strong>TODA Organization:</strong></td>
                    <td>" . h($todaName) . "</td>
                </tr>

                <tr>
                    <td><strong>MTOP Number:</strong></td>
                    <td>" . h($driver["mtop_number"] ?? "N/A") . "</td>
                </tr>

                <tr>
                    <td><strong>Tricycle Number:</strong></td>
                    <td>" . h($driver["tricycle_number"] ?? "N/A") . "</td>
                </tr>

                <tr>
                    <td><strong>Franchise Expiry:</strong></td>
                    <td>" . h($expiryDisplay) . "</td>
                </tr>

            </table>

            <p>
                Please coordinate with the concerned driver and
                process the necessary franchise renewal requirements.
            </p>

            <p>
                Thank you.
            </p>

            <p>
                <strong>TRIKE-Q TMU Administration</strong>
            </p>

        </body>
        </html>
    ";


    /* ---------------------------------------------
       SEND EMAIL
    --------------------------------------------- */

    // Persist the bell independently of SMTP delivery and email availability.
    $bell = tqRenewalBell($driverUuid, $noticeEventId);
    $delivery = sendRenewalEmailToRecipients($manualRecipients, $subject, $emailBody);
    $parts = []; $warnings = [];
    if (!empty($delivery['sent'])) {
        $parts[] = 'Email sent to: ' . implode(', ', array_map(function ($recipient) {
            return $recipient['label'] . ' (' . $recipient['email'] . ')';
        }, $delivery['sent'])) . '.';
        $log = saveRenewalLog($driverUuid, $expiryDate, 'manual', strip_tags($emailBody), 'sent');
        if (empty($log['success'])) $warnings[] = 'Email was sent, but its log could not be saved.';
    }
    foreach (($delivery['errors'] ?? []) as $deliveryError) $warnings[] = $deliveryError;
    if (!empty($bell['success']) && $bell['recipients'] > 0) {
        $parts[] = $bell['created'] > 0
            ? 'Bell notification saved for ' . $bell['created'] . ' approved TODA President account(s).'
            : 'The bell notification for this action was already saved; no duplicate was created.';
    } elseif (!empty($bell['success'])) {
        $warnings[] = 'No approved president account in an active TODA was available for bell delivery.';
    } else {
        $warnings[] = $bell['message'];
    }
    setFlashMessage($warnings ? ($parts ? 'warning' : 'error') : 'success',
        implode(' ', array_merge($parts, $warnings)));

    header("Location: renewals.php");
    exit;
}


/* =========================================================
   GET TODA PRESIDENTS
========================================================= */

$todaPresidents = [];

$presidentListResult = supabaseRequest(
    "drivers",
    "GET",
    null,
    "position=eq.President" .
    "&select=id,name,email,toda_id"
);

if (
    $presidentListResult["status"] >= 200 &&
    $presidentListResult["status"] < 300 &&
    !empty($presidentListResult["data"])
) {

    foreach (
        $presidentListResult["data"]
        as $president
    ) {

        $presidentTodaId =
            $president["toda_id"] ?? null;

        if (
            $presidentTodaId &&
            isValidUuid($presidentTodaId)
        ) {

            $todaPresidents[$presidentTodaId] = [
                "name" =>
                    $president["name"] ?? "President",

                "email" =>
                    $president["email"] ?? ""
            ];
        }
    }
}


/* =========================================================
   GET DRIVERS
========================================================= */

$drivers = [];

$driversResult = tqFetchAll("drivers", "select=*,toda_organizations(name)&order=name.asc,id.asc");

if (
    $driversResult["status"] >= 200 &&
    $driversResult["status"] < 300 &&
    !empty($driversResult["data"])
) {

    $drivers = $driversResult["data"];
}


/* =========================================================
   GET RENEWAL LOGS
========================================================= */

$renewalLogs = [];

$logsResult = supabaseRequest(
    "renewal_sms_logs",
    "GET",
    null,
    "select=id,driver_id,expiry_date,reminder_type,message,message_id,status,sent_at" .
    "&order=sent_at.desc"
);

if (
    $logsResult["status"] >= 200 &&
    $logsResult["status"] < 300 &&
    !empty($logsResult["data"])
) {

    $renewalLogs =
        $logsResult["data"];
}


/* =========================================================
   BUILD AUTOMATIC EMAIL LOOKUP
========================================================= */

$automaticLogLookup = [];

foreach ($renewalLogs as $log) {

    $logDriverId =
        $log["driver_id"] ?? "";

    $logExpiry =
        $log["expiry_date"] ?? "";

    if (
        !isValidUuid($logDriverId) ||
        $logExpiry === ""
    ) {
        continue;
    }

    $key =
        strtolower($logDriverId) .
        "|" .
        $logExpiry;

    $automaticLogLookup[$key] =
        $log;
}


/* =========================================================
   CURRENT DATE
========================================================= */

$today =
    new DateTime(
        date("Y-m-d")
    );


/* =========================================================
   PROCESS DRIVER STATUS
========================================================= */

$totalDrivers = 0;
$validDrivers = 0;
$dueSoonDrivers = 0;
$expiredDrivers = 0;

$processedDrivers = [];


foreach ($drivers as $driver) {

    /*
     * IMPORTANT:
     * Always use drivers.id as the UUID.
     */
    $driverUuid =
        $driver["id"] ?? "";

    if (!isValidUuid($driverUuid)) {
        continue;
    }


    $totalDrivers++;


    $expiryDate =
        $driver["franchise_expiry_date"] ?? null;

    $lastRenewalDate =
        $driver["last_renewal_date"] ?? null;


    $status =
        "No Expiry Date";

    $daysRemaining =
        null;

    $expiryDisplay =
        "No expiry date";


    if (
        $expiryDate !== null &&
        $expiryDate !== ""
    ) {

        try {

            $expiry =
                new DateTime($expiryDate);

            $expiryDisplay =
                $expiry->format("F d, Y");

            $difference =
                $today->diff($expiry);


            if ($expiry < $today) {

                $daysRemaining =
                    -1 * $difference->days;

                $status =
                    "Expired";

                $expiredDrivers++;

            } else {

                $daysRemaining =
                    $difference->days;

                if ($daysRemaining <= 30) {

                    $status =
                        "Due Soon";

                    $dueSoonDrivers++;

                } else {

                    $status =
                        "Valid";

                    $validDrivers++;
                }
            }

        } catch (Throwable $e) {

            $status =
                "No Expiry Date";
        }
    }


    /* ---------------------------------------------
       TODA INFORMATION
    --------------------------------------------- */

    $todaName =
        "No TODA";

    $todaId =
        $driver["toda_id"] ?? null;


    if (
        isset($driver["toda_organizations"]) &&
        is_array($driver["toda_organizations"])
    ) {

        if (
            isset(
                $driver["toda_organizations"]["name"]
            )
        ) {

            $todaName =
                $driver["toda_organizations"]["name"];

        } elseif (
            isset(
                $driver["toda_organizations"][0]["name"]
            )
        ) {

            $todaName =
                $driver["toda_organizations"][0]["name"];
        }
    }


    /* ---------------------------------------------
       PRESIDENT
    --------------------------------------------- */

    $presidentName =
        "No President";

    $presidentEmail =
        "";


    if (
        $todaId &&
        isset($todaPresidents[$todaId])
    ) {

        $presidentName =
            $todaPresidents[$todaId]["name"];

        $presidentEmail =
            $todaPresidents[$todaId]["email"];
    }


    /* ---------------------------------------------
       CONTACT
    --------------------------------------------- */

    $phoneNumber =
        trim(
            $driver["phone_number"] ?? ""
        );

    if ($phoneNumber === "") {
        $phoneNumber =
            "No contact number";
    }


    /* ---------------------------------------------
       LAST RENEWAL
    --------------------------------------------- */

    if (
        $lastRenewalDate !== null &&
        $lastRenewalDate !== ""
    ) {

        try {

            $lastRenewalObject =
                new DateTime(
                    $lastRenewalDate
                );

            $lastRenewalDisplay =
                $lastRenewalObject->format(
                    "F d, Y"
                );

        } catch (Throwable $e) {

            $lastRenewalDisplay =
                $lastRenewalDate;
        }

    } else {

        $lastRenewalDisplay =
            "Not yet renewed";
    }


    /* ---------------------------------------------
       AUTOMATIC REMINDER
    --------------------------------------------- */

    if (
        $expiryDate !== null &&
        $expiryDate !== ""
    ) {

        try {

            $expiryForEmail =
                new DateTime($expiryDate);

            $daysUntilExpiry =
                (int)$today->diff(
                    $expiryForEmail
                )->format("%r%a");


            if ($daysUntilExpiry <= 30) {

                $automaticKey =
                    strtolower($driverUuid) .
                    "|" .
                    $expiryDate;


                if (
                    !isset(
                        $automaticLogLookup[
                            $automaticKey
                        ]
                    )
                ) {

                    // Same automatic reminder reaches the app even if no email is available.
                    $automaticBell = tqRenewalBell($driverUuid, '', 'automatic');
                    if (empty($automaticBell['success'])) {
                        error_log('TRIKE-Q automatic renewal bell failed for driver ' . $driverUuid);
                    }

                    $automaticPresidentEmail =
                        trim(
                            $todaPresidents[$todaId]["email"]
                            ?? ""
                        );

                    $automaticPresidentName =
                        trim(
                            $todaPresidents[$todaId]["name"]
                            ?? "President"
                        );

                    $automaticRecipients = [
                        ["email" => trim($driver["email"] ?? ""), "label" => "Driver " . ($driver["name"] ?? "Driver")],
                        ["email" => $automaticPresidentEmail, "label" => "TODA President " . $automaticPresidentName]
                    ];
                    $automaticRecipients = array_merge($automaticRecipients, tqApprovedPresidentRecipients($todaId));
                    $hasAutomaticRecipient = false;
                    foreach ($automaticRecipients as $recipient) {
                        if (filter_var(trim((string)($recipient["email"] ?? "")), FILTER_VALIDATE_EMAIL)) { $hasAutomaticRecipient = true; break; }
                    }

                    if ($hasAutomaticRecipient) {

                        if ($daysUntilExpiry < 0) {

                            $automaticReminderType =
                                "expired";

                            $automaticSubject =
                                "TRIKE-Q Franchise Expired - " .
                                (
                                    $driver["name"]
                                    ?? "Driver"
                                );

                            $automaticText =
                                "Franchise of " .
                                (
                                    $driver["name"]
                                    ?? "Driver"
                                ) .
                                " has expired on " .
                                $expiryDisplay .
                                ".";

                        } else {

                            $automaticReminderType =
                                "due_soon";

                            $automaticSubject =
                                "TRIKE-Q Franchise Renewal Reminder - " .
                                (
                                    $driver["name"]
                                    ?? "Driver"
                                );

                            $automaticText =
                                "Franchise of " .
                                (
                                    $driver["name"]
                                    ?? "Driver"
                                ) .
                                " will expire on " .
                                $expiryDisplay .
                                ".";
                        }


                        $automaticBody = "
                            <html>
                            <body style=\"font-family: Arial, sans-serif; line-height: 1.6; color: #333;\">

                                <h2 style=\"color:#f97316;\">
                                    Franchise Renewal Notification
                                </h2>

                                <p>
                                    Good day " .
                                    h(
                                        $automaticPresidentName
                                    ) .
                                    ",
                                </p>

                                <p>
                                    This is an automatic notification
                                    regarding a franchise under your TODA organization.
                                </p>

                                <table cellpadding=\"8\" cellspacing=\"0\" border=\"0\">

                                    <tr>
                                        <td><strong>Driver Name:</strong></td>
                                        <td>" .
                                        h(
                                            $driver["name"]
                                            ?? "Driver"
                                        ) .
                                        "</td>
                                    </tr>

                                    <tr>
                                        <td><strong>TODA:</strong></td>
                                        <td>" .
                                        h($todaName) .
                                        "</td>
                                    </tr>

                                    <tr>
                                        <td><strong>MTOP Number:</strong></td>
                                        <td>" .
                                        h(
                                            $driver["mtop_number"]
                                            ?? "N/A"
                                        ) .
                                        "</td>
                                    </tr>

                                    <tr>
                                        <td><strong>Tricycle Number:</strong></td>
                                        <td>" .
                                        h(
                                            $driver["tricycle_number"]
                                            ?? "N/A"
                                        ) .
                                        "</td>
                                    </tr>

                                    <tr>
                                        <td><strong>Franchise Expiry:</strong></td>
                                        <td>" .
                                        h($expiryDisplay) .
                                        "</td>
                                    </tr>

                                </table>

                                <p>" .
                                    h($automaticText) .
                                "</p>

                                <p>
                                    Please coordinate with the concerned driver
                                    regarding the necessary renewal process.
                                </p>

                                <p>
                                    Thank you.
                                </p>

                                <p>
                                    <strong>
                                        TRIKE-Q TMU Administration
                                    </strong>
                                </p>

                            </body>
                            </html>
                        ";


                        $automaticDelivery = sendRenewalEmailToRecipients($automaticRecipients, $automaticSubject, $automaticBody);
                        $automaticSent = $automaticDelivery["success"];
                        $automaticEmailError = implode("; ", $automaticDelivery["errors"] ?? []);


                        if ($automaticSent) {

                            saveRenewalLog(
                                $driverUuid,
                                $expiryDate,
                                $automaticReminderType,
                                strip_tags(
                                    $automaticBody
                                ),
                                "sent"
                            );

                        } else {

                            saveRenewalLog(
                                $driverUuid,
                                $expiryDate,
                                $automaticReminderType,
                                strip_tags(
                                    $automaticBody
                                ),
                                "failed"
                            );
                        }
                    }
                }
            }

        } catch (Throwable $e) {
            // Prevent automatic email errors from breaking page rendering.
        }
    }


    $processedDrivers[] = [

        "data" =>
            $driver,

        "id" =>
            $driverUuid,

        "name" =>
            $driver["name"]
            ?? "Unnamed Driver",

        "phone" =>
            $phoneNumber,

        "mtop" =>
            $driver["mtop_number"]
            ?? "N/A",

        "tricycle" =>
            $driver["tricycle_number"]
            ?? "N/A",

        "toda" =>
            $todaName,

        "toda_id" =>
            $todaId,

        "president" =>
            $presidentName,

        "president_email" =>
            $presidentEmail,

        "last_renewal" =>
            $lastRenewalDisplay,

        "expiry" =>
            $expiryDisplay,

        "expiry_raw" =>
            $expiryDate,

        "days" =>
            $daysRemaining,

        "status" =>
            $status
    ];
}


// Keep the current filters and notification processing; sort only the display rows.
// Due within 30 days first, then overdue, valid, and records without a usable date.
usort($processedDrivers, static function ($a, $b) {
    $priority = ['Due Soon'=>0, 'Expired'=>1, 'Valid'=>2, 'No Expiry Date'=>3];
    $group = ($priority[$a['status']] ?? 3) <=> ($priority[$b['status']] ?? 3);
    if ($group !== 0) return $group;
    $days = ($a['days'] ?? PHP_INT_MAX) <=> ($b['days'] ?? PHP_INT_MAX);
    if ($days !== 0) return $days;
    $name = strnatcasecmp((string)$a['name'], (string)$b['name']);
    return $name !== 0 ? $name : strcmp((string)$a['id'], (string)$b['id']);
});

$flashMessage =
    getFlashMessage();

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
        Franchise Renewals | TRIKE-Q TMU Admin
    </title>

    <style>

        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            padding: 0;

            font-family:
                Arial,
                Helvetica,
                sans-serif;

            background: #f8fafc;
            color: #1f2937;
        }


        /* =====================================================
           SIDEBAR - MATCHED TO REPORTS.PHP
        ===================================================== */

        .sidebar {
            position: fixed;

            top: 0;
            left: 0;
            bottom: 0;

            width: 250px;

            background: #1f1f1f;
            color: white;

            padding: 24px 16px;

            overflow-y: auto;

            z-index: 1000;
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


        /* =====================================================
           MAIN
        ===================================================== */

        .main {
            margin-left: 250px;

            min-height: 100vh;

            width:
                calc(100% - 250px);
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

        .topbar-title {
            font-size: 15px;
            font-weight: 700;
        }

        .admin-box {
            display: flex;

            align-items: center;

            gap: 10px;
        }

        .admin-avatar {
            width: 40px;
            height: 40px;

            border-radius: 50%;

            background: #fff7ed;
            color: #f97316;

            display: flex;

            align-items: center;
            justify-content: center;

            font-weight: 800;
        }

        .admin-info strong {
            display: block;

            font-size: 13px;
        }

        .admin-info span {
            display: block;

            font-size: 11px;

            color: #71717a;

            margin-top: 2px;
        }


        /* =====================================================
           CONTENT
        ===================================================== */

        .content {
            padding: 30px;
        }

        .page-heading {
            margin-bottom: 24px;
        }

        .page-heading h1 {
            margin: 0;

            font-size: 27px;

            color: #111827;
        }

        .page-heading p {
            margin: 7px 0 0;

            color: #6b7280;

            font-size: 14px;
        }


        /* =====================================================
           FLASH
        ===================================================== */

        .alert {
            padding: 13px 16px;

            border-radius: 9px;

            margin-bottom: 20px;

            font-size: 13px;

            border: 1px solid;
        }

        .alert.success {
            background: #ecfdf5;
            color: #047857;
            border-color: #a7f3d0;
        }

        .alert.error {
            background: #fef2f2;
            color: #b91c1c;
            border-color: #fecaca;
        }

        .alert.warning {
            background: #fffbeb;
            color: #b45309;
            border-color: #fde68a;
        }


        /* =====================================================
           SUMMARY FILTER CARDS
        ===================================================== */

        .summary-grid {
            display: grid;

            grid-template-columns:
                repeat(4, minmax(0, 1fr));

            gap: 16px;

            margin-bottom: 22px;
        }

        .filter-card {
            width: 100%;

            text-align: left;

            background: white;

            border:
                1px solid #e5e7eb;

            border-radius: 12px;

            padding: 19px;

            box-shadow:
                0 2px 8px
                rgba(15, 23, 42, .04);

            cursor: pointer;

            transition:
                transform .15s ease,
                border-color .15s ease,
                box-shadow .15s ease,
                background .15s ease;
        }

        .filter-card:hover {
            transform: translateY(-1px);

            border-color: #fdba74;

            box-shadow:
                0 5px 15px
                rgba(15, 23, 42, .07);
        }

        .filter-card.active {
            border-color: #f97316;

            box-shadow:
                0 0 0 2px
                rgba(249,115,22,.12);

            background: #fffaf5;
        }

        .filter-card-top {
            display: flex;

            align-items: center;

            justify-content: space-between;
        }

        .filter-icon {
            width: 38px;
            height: 38px;

            display: flex;

            align-items: center;
            justify-content: center;

            border-radius: 9px;

            background: #fff7ed;

            color: #f97316;

            font-size: 18px;
        }

        .filter-label {
            margin-top: 14px;

            font-size: 12px;

            color: #6b7280;
        }

        .filter-number {
            margin-top: 4px;

            font-size: 25px;

            font-weight: 800;

            color: #111827;
        }

        .filter-help {
            margin-top: 5px;

            font-size: 10px;

            color: #9ca3af;
        }


        /* =====================================================
           EMAIL INFORMATION BOX
        ===================================================== */

        .email-info {
            display: flex;

            align-items: flex-start;

            gap: 13px;

            background: #fff7ed;

            border:
                1px solid #fed7aa;

            border-radius: 10px;

            padding: 15px 17px;

            margin-bottom: 20px;
        }

        .email-info-icon {
            width: 34px;
            height: 34px;

            flex: 0 0 34px;

            display: flex;

            align-items: center;
            justify-content: center;

            background: #f97316;

            color: white;

            border-radius: 8px;
        }

        .email-info strong {
            display: block;

            margin-bottom: 3px;

            font-size: 13px;
        }

        .email-info p {
            margin: 0;

            color: #7c2d12;

            font-size: 12px;

            line-height: 1.5;
        }


        /* =====================================================
           TABLE CARD
        ===================================================== */

        .table-card {
            background: white;

            border:
                1px solid #e5e7eb;

            border-radius: 12px;

            overflow: hidden;

            box-shadow:
                0 2px 8px
                rgba(15, 23, 42, .04);
        }

        .table-header {
            padding: 17px 20px;

            display: flex;

            align-items: center;

            justify-content: space-between;

            gap: 15px;

            border-bottom:
                1px solid #e5e7eb;
        }

        .table-header h2 {
            margin: 0;

            font-size: 16px;
        }

        .table-header p {
            margin: 4px 0 0;

            color: #6b7280;

            font-size: 11px;
        }

        .search-wrapper {
            position: relative;

            width: 290px;
        }

        .search-wrapper span {
            position: absolute;

            left: 12px;

            top: 50%;

            transform:
                translateY(-50%);

            color: #9ca3af;
        }

        .search-input {
            width: 100%;

            padding:
                9px
                12px
                9px
                34px;

            border:
                1px solid #d1d5db;

            border-radius: 8px;

            outline: none;

            font-size: 12px;
        }

        .search-input:focus {
            border-color: #f97316;

            box-shadow:
                0 0 0 3px
                rgba(249,115,22,.10);
        }


        /* =====================================================
           TABLE
        ===================================================== */

        .table-scroll {
            width: 100%;

            overflow-x: auto;
        }

        table {
            width: 100%;

            min-width: 1250px;

            border-collapse: collapse;
        }

        th {
            background: #f9fafb;

            color: #6b7280;

            font-size: 10px;

            font-weight: 700;

            text-transform: uppercase;

            letter-spacing: .5px;

            padding:
                12px
                13px;

            text-align: left;

            white-space: nowrap;

            border-bottom:
                1px solid #e5e7eb;
        }

        td {
            padding: 13px;

            font-size: 12px;

            border-bottom:
                1px solid #f1f5f9;

            vertical-align: middle;
        }

        tbody tr:hover {
            background: #fafafa;
        }

        tbody tr:last-child td {
            border-bottom: none;
        }

        .driver-name {
            font-weight: 700;

            color: #111827;
        }

        .driver-sub {
            margin-top: 3px;

            color: #9ca3af;

            font-size: 10px;
        }


        /* =====================================================
           STATUS
        ===================================================== */

        .status-badge {
            display: inline-flex;

            align-items: center;

            padding:
                5px
                9px;

            border-radius: 999px;

            font-size: 10px;

            font-weight: 700;

            white-space: nowrap;
        }

        .status-valid {
            background: #ecfdf5;
            color: #047857;
        }

        .status-due {
            background: #fff7ed;
            color: #c2410c;
        }

        .status-expired {
            background: #fef2f2;
            color: #b91c1c;
        }

        .status-none {
            background: #f3f4f6;
            color: #6b7280;
        }


        /* =====================================================
           DAYS
        ===================================================== */

        .days {
            font-weight: 700;
        }

        .days.valid {
            color: #047857;
        }

        .days.due {
            color: #ea580c;
        }

        .days.expired {
            color: #dc2626;
        }


        /* =====================================================
           ACTIONS
        ===================================================== */

        .actions {
            display: flex;

            align-items: center;

            gap: 6px;
        }

        .action-btn {
            border: none;

            border-radius: 7px;

            padding:
                7px
                9px;

            font-size: 11px;

            font-weight: 700;

            cursor: pointer;

            white-space: nowrap;
        }

        .renew-btn {
            background: #f97316;
            color: white;
        }

        .renew-btn:hover {
            background: #ea580c;
        }

        .email-btn {
            background: #f1f5f9;
            color: #334155;
        }

        .email-btn:hover {
            background: #e2e8f0;
        }

        .empty-row {
            text-align: center;

            padding: 40px !important;

            color: #9ca3af;

            font-size: 13px;
        }


        /* =====================================================
           MODAL
        ===================================================== */

        .modal-overlay {
            position: fixed;

            inset: 0;

            background:
                rgba(15,23,42,.55);

            display: none;

            align-items: center;
            justify-content: center;

            padding: 20px;

            z-index: 2000;
        }

        .modal-overlay.show {
            display: flex;
        }

        .modal {
            width: 100%;

            max-width: 470px;

            background: white;

            border-radius: 14px;

            box-shadow:
                0 25px 60px
                rgba(0,0,0,.20);

            overflow: hidden;
        }

        .modal-header {
            display: flex;

            align-items: center;

            justify-content: space-between;

            padding:
                18px
                20px;

            border-bottom:
                1px solid #e5e7eb;
        }

        .modal-header h3 {
            margin: 0;

            font-size: 17px;
        }

        .modal-close {
            width: 30px;
            height: 30px;

            border: none;

            background: #f3f4f6;

            color: #4b5563;

            border-radius: 7px;

            cursor: pointer;

            font-size: 17px;
        }

        .modal-close:hover {
            background: #e5e7eb;
        }

        .modal-body {
            padding: 20px;
        }

        .renew-driver-info {
            background: #f8fafc;

            border:
                1px solid #e5e7eb;

            border-radius: 9px;

            padding: 13px;

            margin-bottom: 18px;
        }

        .renew-driver-info strong {
            display: block;

            color: #111827;

            font-size: 14px;
        }

        .renew-driver-info span {
            display: block;

            margin-top: 4px;

            color: #6b7280;

            font-size: 11px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;

            margin-bottom: 7px;

            font-size: 12px;

            font-weight: 700;

            color: #374151;
        }

        .form-control {
            width: 100%;

            padding:
                10px
                12px;

            border:
                1px solid #d1d5db;

            border-radius: 8px;

            outline: none;

            font-size: 13px;
        }

        .form-control:focus {
            border-color: #f97316;

            box-shadow:
                0 0 0 3px
                rgba(249,115,22,.10);
        }

        .modal-footer {
            display: flex;

            justify-content: flex-end;

            gap: 8px;

            padding:
                15px
                20px;

            border-top:
                1px solid #e5e7eb;

            background: #fafafa;
        }

        .modal-btn {
            border: none;

            border-radius: 8px;

            padding:
                9px
                15px;

            cursor: pointer;

            font-size: 12px;

            font-weight: 700;
        }

        .cancel-btn {
            background: #e5e7eb;

            color: #374151;
        }

        .confirm-btn {
            background: #f97316;

            color: white;
        }

        .confirm-btn:hover {
            background: #ea580c;
        }


        /* =====================================================
           RESPONSIVE
        ===================================================== */

        @media (max-width: 1100px) {

            .summary-grid {
                grid-template-columns:
                    repeat(2, minmax(0, 1fr));
            }

        }


        @media (max-width: 800px) {

            .sidebar {
                width: 210px;
            }

            .main {
                margin-left: 210px;

                width:
                    calc(100% - 210px);
            }

            .content {
                padding: 20px;
            }

            .table-header {
                align-items: flex-start;

                flex-direction: column;
            }

            .search-wrapper {
                width: 100%;
            }

        }


        @media (max-width: 600px) {

            .sidebar {
                position: static;

                width: 100%;

                height: auto;
            }

            .main {
                margin-left: 0;

                width: 100%;
            }

            .topbar {
                padding:
                    0
                    16px;
            }

            .content {
                padding: 16px;
            }

            .summary-grid {
                grid-template-columns: 1fr;
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
/* Renewal-specific controls remain aligned even with older shared styles. */
#tqRenewalHeader{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
#tqRenewalTools{display:flex!important;align-items:center!important;justify-content:flex-end;flex-wrap:nowrap!important;gap:8px;min-width:0;margin-left:auto}
#tqRenewalTools .search-wrapper{display:flex;align-items:center;gap:7px;box-sizing:border-box;width:260px;max-width:100%;min-width:0;height:34px;margin:0;padding:0 10px;border:1px solid #cbd5e1;border-radius:7px;background:#fff;color:#64748b}
#tqRenewalTools .search-wrapper>svg{display:block;width:16px!important;height:16px!important;flex:0 0 16px;fill:none;stroke:currentColor}
#tqRenewalTools #renewalSearch{box-sizing:border-box;width:100%;min-width:0;height:32px;margin:0;padding:6px 0;border:0;border-radius:0;background:transparent;box-shadow:none;outline:none;color:#334155;font:13px Arial,Helvetica,sans-serif}
#tqRenewalTools .search-wrapper:focus-within{border-color:#f97316;outline:2px solid #ffedd5}
#tqRenewalTools .tq-csv-button{display:inline-flex!important;align-items:center;justify-content:center;gap:6px;box-sizing:border-box;flex:0 0 auto;height:34px!important;min-height:34px!important;margin:0;padding:7px 10px!important;border:1px solid #86efac!important;border-radius:7px;background:#f0fdf4!important;color:#15803d!important;font:600 12px Arial,Helvetica,sans-serif;line-height:18px;white-space:nowrap;cursor:pointer}
#tqRenewalTools .tq-csv-button:hover{background:#dcfce7!important;border-color:#22c55e!important}
#tqRenewalTools .tq-csv-button>svg{display:block;width:15px!important;height:15px!important;flex:0 0 15px;fill:none;stroke:#15803d!important}
#renewalTable .actions{display:flex;align-items:center;gap:6px}
#renewalTable .actions form{display:inline-flex!important;align-items:center;flex:0 0 auto;margin:0;padding:0}
#renewalTable button.email-btn.tq-icon-button{display:inline-flex!important;align-items:center!important;justify-content:center!important;box-sizing:border-box;width:30px!important;height:30px!important;min-width:30px!important;min-height:30px!important;max-width:30px;max-height:30px;flex:0 0 30px;margin:0;padding:0!important;border:1px solid #fdba74!important;border-radius:6px;background:#fff7ed!important;color:#c2410c!important;line-height:1;cursor:pointer;vertical-align:middle}
#renewalTable button.email-btn.tq-icon-button>svg{display:block;width:15px!important;height:15px!important;min-width:15px;max-width:15px;max-height:15px;flex:0 0 15px;fill:none!important;stroke:currentColor}
#renewalTable button.email-btn.tq-icon-button:hover{background:#ffedd5!important;border-color:#f97316!important}
#renewalTable button.email-btn.tq-icon-button:focus-visible,#tqRenewalTools .tq-csv-button:focus-visible{outline:2px solid #f97316;outline-offset:3px}
@media(max-width:650px){#tqRenewalTools{width:100%;margin-left:0;justify-content:flex-start}#tqRenewalTools .search-wrapper{width:auto;flex:1 1 0}#tqRenewalTools .tq-csv-button{padding:7px 8px!important;font-size:11px}}
</style>
<!-- Shared TMU header and navigation; refresh versions when the shared files change. -->
<script src="admin-header.js?v=<?= (int) @filemtime(__DIR__.'/admin-header.js') ?>" data-tq-header="1" defer></script>
<script src="admin-nav.js?v=<?= (int) @filemtime(__DIR__.'/admin-nav.js') ?>" defer></script>
<style id="renewal-driver-photo-style">
#renewalTable .tq-renewal-driver-cell{display:flex;align-items:center;gap:10px;min-width:190px}
#renewalTable .tq-renewal-avatar{display:inline-flex;align-items:center;justify-content:center;flex:0 0 44px;width:44px;height:44px;margin:0;padding:0;overflow:hidden;border:1px solid #fed7aa;border-radius:50%;background:#fff7ed;color:#9a3412;cursor:zoom-in}
#renewalTable .tq-renewal-avatar img{display:block;width:100%;height:100%;object-fit:cover}
#renewalTable .tq-renewal-avatar [hidden]{display:none!important}
#renewalTable .tq-renewal-initial{font:600 18px Arial,Helvetica,sans-serif;text-transform:uppercase}
#renewalTable .tq-renewal-avatar-empty,#renewalTable .tq-renewal-avatar:disabled{cursor:default}
#renewalTable .tq-renewal-avatar:focus-visible{outline:3px solid #f97316;outline-offset:3px}
#tqRenewalPhotoViewer{position:fixed!important;inset:0!important;transform:none!important;margin:0!important;width:100vw!important;height:100vh!important;height:100dvh!important;max-width:none!important;max-height:none!important;padding:16px!important;border:0!important;border-radius:0!important;background:transparent!important;box-shadow:none!important;box-sizing:border-box!important}
#tqRenewalPhotoViewer[open]{display:flex!important;align-items:center!important;justify-content:center!important;overflow:hidden!important}
#tqRenewalPhotoViewer::backdrop{background:rgba(15,23,42,.72)}
#tqRenewalPhotoViewer .tq-renewal-photo-panel{width:min(680px,100%);max-height:calc(100dvh - 32px);overflow:auto;margin:0;padding:18px;border:1px solid #e2e8f0;border-radius:12px;background:#fff;box-shadow:0 12px 40px #0003;box-sizing:border-box}
#tqRenewalPhotoViewer .tq-renewal-photo-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}
#tqRenewalPhotoViewer h2{margin:0;color:#9a3412;font:600 19px/26px Arial,Helvetica,sans-serif;overflow-wrap:anywhere}
#tqRenewalPhotoViewer button{flex-shrink:0;min-height:38px;padding:8px 14px;border:0;border-radius:8px;background:#f97316;color:#fff;font:600 14px Arial,Helvetica,sans-serif;cursor:pointer}
#tqRenewalPhotoViewer img{display:block;width:100%;height:auto;max-height:70vh;max-height:70dvh;object-fit:contain;object-position:center;background:#f8fafc;border-radius:8px}
#tqRenewalPhotoViewer p{margin:0;padding:12px;border-radius:8px;background:#fef2f2;color:#991b1b;font:14px/21px Arial,Helvetica,sans-serif}
#tqRenewalPhotoViewer [hidden]{display:none!important}
@media print{#tqRenewalPhotoViewer{display:none!important}}
</style>
</head>

<body>


<!-- =========================================================
     SIDEBAR - SAME MENU AS REPORTS.PHP
========================================================= -->

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
        <a href="renewals.php" class="active" aria-current="page">
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


<!-- =========================================================
     MAIN
========================================================= -->

<main class="main">


    <!-- =====================================================
         TOPBAR
    ===================================================== -->

    <header class="topbar">

        <div class="topbar-title">
            Franchise Renewal Management
        </div>


        <div class="admin-box">

            <div class="admin-avatar">

                <?php

                echo strtoupper(
                    substr(
                        $adminName,
                        0,
                        1
                    )
                );

                ?>

            </div>


            <div class="admin-info">

                <strong>
                    <?php
                    echo h($adminName);
                    ?>
                </strong>

                <span>
                    TMU Administrator
                </span>

            </div>

        </div>

    </header>


    <!-- =====================================================
         CONTENT
    ===================================================== -->

    <section class="content">


        <div class="page-heading">

            <h1>
                Franchise Renewals
            </h1>

            <p>
                Monitor franchise expiry dates and manage driver renewal notifications.
            </p>

        </div>


        <!-- =================================================
             FLASH
        ================================================= -->

        <?php if ($flashMessage): ?>

            <div
                class="alert <?php echo h($flashMessage["type"]); ?>"
                id="flashAlert"
            >

                <?php
                echo h(
                    $flashMessage["message"]
                );
                ?>

            </div>

        <?php endif; ?>


        <!-- =================================================
             SUMMARY FILTERS
        ================================================= -->

        <div class="summary-grid">


            <button
                type="button"
                class="filter-card active"
                data-filter="all"
                onclick="setRenewalFilter('all', this)"
            >

                <div class="filter-card-top">

                    <div>

                        <div class="filter-label">
                            Total Drivers
                        </div>

                        <div class="filter-number">
                            <?php echo $totalDrivers; ?>
                        </div>

                    </div>

                    <div class="filter-icon">
                        ♙
                    </div>

                </div>

                <div class="filter-help">
                    Show all drivers
                </div>

            </button>


            <button
                type="button"
                class="filter-card"
                data-filter="valid"
                onclick="setRenewalFilter('valid', this)"
            >

                <div class="filter-card-top">

                    <div>

                        <div class="filter-label">
                            Valid
                        </div>

                        <div class="filter-number">
                            <?php echo $validDrivers; ?>
                        </div>

                    </div>

                    <div class="filter-icon">
                        ✓
                    </div>

                </div>

                <div class="filter-help">
                    More than 30 days remaining
                </div>

            </button>


            <button
                type="button"
                class="filter-card"
                data-filter="due"
                onclick="setRenewalFilter('due', this)"
            >

                <div class="filter-card-top">

                    <div>

                        <div class="filter-label">
                            Due Soon
                        </div>

                        <div class="filter-number">
                            <?php echo $dueSoonDrivers; ?>
                        </div>

                    </div>

                    <div class="filter-icon">
                        !
                    </div>

                </div>

                <div class="filter-help">
                    Expires within 30 days
                </div>

            </button>


            <button
                type="button"
                class="filter-card"
                data-filter="expired"
                onclick="setRenewalFilter('expired', this)"
            >

                <div class="filter-card-top">

                    <div>

                        <div class="filter-label">
                            Expired
                        </div>

                        <div class="filter-number">
                            <?php echo $expiredDrivers; ?>
                        </div>

                    </div>

                    <div class="filter-icon">
                        !
                    </div>

                </div>

                <div class="filter-help">
                    Franchise already expired
                </div>

            </button>

        </div>


        <!-- =================================================
             EMAIL INFO
        ================================================= -->

        <div class="email-info">

            <div class="email-info-icon">
                ✉
            </div>

            <div>

                <strong>
                    Renewal Email Notifications
                </strong>

                <p>
                    Renewal notifications are sent to both the driver
                    and the President of the driver's TODA organization
                    when valid email addresses are available. The email icon action also saves an in-app reminder for approved TODA President accounts.
                </p>

            </div>

        </div>


        <!-- =================================================
             TABLE
        ================================================= -->

        <div class="table-card">


            <div class="table-header" id="tqRenewalHeader">

                <div>

                    <h2>
                        Driver Franchise Records
                    </h2>

                    <p>
                        Due within 30 days first, nearest expiry first. Click a summary card to filter.
                    </p>

                </div>


<div class="tq-renewal-tools" id="tqRenewalTools"><div class="search-wrapper">
<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/></svg><input type="search" id="renewalSearch" class="search-input" placeholder="Search driver, TODA, MTOP..." autocomplete="off">
</div><button type="button" class="tq-button tq-csv-button" data-csv-table="renewalTable" data-csv-name="franchise-renewals"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3v12m-5-5 5 5 5-5M4 16v5h16v-5"/></svg><span>Download CSV</span></button></div>
</div>

            <div class="table-scroll">

                <table id="renewalTable">

                    <thead>

                        <tr>

                            <th>
                                Driver Name
                            </th>

                            <th>
                                Contact
                            </th>
                            <th>Driver Email Address</th>

                            <th>
                                MTOP Number
                            </th>

                            <th>
                                Tricycle Number
                            </th>

                            <th>
                                TODA
                            </th>

                            <th>
                                TODA President
                            </th>

                            <th>
                                Last Renewal
                            </th>

                            <th>
                                Expiry Date
                            </th>

                            <th>
                                Days Remaining
                            </th>

                            <th>
                                Status
                            </th>

                            <th data-no-export>
                                Actions
                            </th>

                        </tr>

                    </thead>


                    <tbody id="renewalTableBody">


                        <?php if (empty($processedDrivers)): ?>

                            <tr>

                                <td
                                    colspan="12"
                                    class="empty-row"
                                >
                                    No driver records found.
                                </td>

                            </tr>

                        <?php else: ?>


                            <?php foreach ($processedDrivers as $row): ?>

                                <?php

                                $status =
                                    $row["status"];

                                if (
                                    $status === "Due Soon"
                                ) {

                                    $statusFilter =
                                        "due";

                                } elseif (
                                    $status === "Expired"
                                ) {

                                    $statusFilter =
                                        "expired";

                                } elseif (
                                    $status === "Valid"
                                ) {

                                    $statusFilter =
                                        "valid";

                                } else {

                                    $statusFilter =
                                        "none";
                                }


                                if (
                                    $status === "Valid"
                                ) {

                                    $statusClass =
                                        "status-valid";

                                } elseif (
                                    $status === "Due Soon"
                                ) {

                                    $statusClass =
                                        "status-due";

                                } elseif (
                                    $status === "Expired"
                                ) {

                                    $statusClass =
                                        "status-expired";

                                } else {

                                    $statusClass =
                                        "status-none";
                                }


                                if (
                                    $row["days"] !== null
                                ) {

                                    if (
                                        $row["days"] < 0
                                    ) {

                                        $daysText =
                                            abs(
                                                $row["days"]
                                            ) .
                                            " days overdue";

                                        $daysClass =
                                            "expired";

                                    } else {

                                        $daysText =
                                            $row["days"] .
                                            " days";

                                        if (
                                            $row["days"] <= 30
                                        ) {

                                            $daysClass =
                                                "due";

                                        } else {

                                            $daysClass =
                                                "valid";
                                        }
                                    }

                                } else {

                                    $daysText =
                                        "—";

                                    $daysClass =
                                        "";
                                }

                                ?>


                                <tr
                                    class="driver-row"
                                    data-status="<?php
                                    echo h(
                                        $statusFilter
                                    );
                                    ?>"
                                >


                                    <?php
                                    $renewalPhoto = (string)($row['data']['profile_picture_url'] ?? '');
                                    if (!filter_var($renewalPhoto,FILTER_VALIDATE_URL) || !str_starts_with($renewalPhoto,'https://')) $renewalPhoto = '';
                                    $renewalInitial = function_exists('mb_substr') ? mb_substr(trim($row['name']),0,1,'UTF-8') : substr(trim($row['name']),0,1);
                                    $renewalInitial = $renewalInitial !== '' ? $renewalInitial : '?';
                                    ?>
                                    <td data-csv-value="<?=h($row['name'])?>">
                                        <div class="tq-renewal-driver-cell">
                                            <?php if($renewalPhoto !== ''): ?>
                                            <button type="button" class="tq-renewal-avatar" data-renewal-photo data-photo-url="<?=h($renewalPhoto)?>" data-driver-name="<?=h($row['name'])?>" aria-label="<?=h('View '.$row['name'].' profile picture')?>">
                                                <img src="<?=h($renewalPhoto)?>" alt="" loading="lazy" referrerpolicy="no-referrer">
                                                <span class="tq-renewal-initial" hidden><?=h($renewalInitial)?></span>
                                            </button>
                                            <?php else: ?>
                                            <span class="tq-renewal-avatar tq-renewal-avatar-empty" aria-label="No profile picture"><span class="tq-renewal-initial"><?=h($renewalInitial)?></span></span>
                                            <?php endif; ?>
                                            <span class="driver-name"><?=h($row['name'])?></span>
                                        </div>
                                    </td>


                                    <td>

                                        <?php
                                        echo h(
                                            $row["phone"]
                                        );
                                        ?>

                                    </td>
                                    <td><?= h($row['data']['email'] ?? 'No email') ?></td>


                                    <td>

                                        <?php
                                        echo h(
                                            $row["mtop"]
                                        );
                                        ?>

                                    </td>


                                    <td>

                                        <?php
                                        echo h(
                                            $row["tricycle"]
                                        );
                                        ?>

                                    </td>


                                    <td>

                                        <strong>
                                            <?php
                                            echo h(
                                                $row["toda"]
                                            );
                                            ?>
                                        </strong>

                                    </td>


                                    <td>

                                        <div
                                            class="driver-name"
                                        >
                                            <?php
                                            echo h(
                                                $row["president"]
                                            );
                                            ?>
                                        </div>

                                        <?php if (
                                            !empty(
                                                $row["president_email"]
                                            )
                                        ): ?>

                                            <div
                                                class="driver-sub"
                                            >
                                                <?php
                                                echo h(
                                                    $row["president_email"]
                                                );
                                                ?>
                                            </div>

                                        <?php endif; ?>

                                    </td>


                                    <td>

                                        <?php
                                        echo h(
                                            $row["last_renewal"]
                                        );
                                        ?>

                                    </td>


                                    <td>

                                        <strong>
                                            <?php
                                            echo h(
                                                $row["expiry"]
                                            );
                                            ?>
                                        </strong>

                                    </td>


                                    <td>

                                        <span
                                            class="days <?php echo h($daysClass); ?>"
                                        >
                                            <?php
                                            echo h(
                                                $daysText
                                            );
                                            ?>
                                        </span>

                                    </td>


                                    <td>

                                        <span
                                            class="status-badge <?php echo h($statusClass); ?>"
                                        >
                                            <?php
                                            echo h(
                                                $status
                                            );
                                            ?>
                                        </span>

                                    </td>


                                    <td>

                                        <div class="actions">


                                            <button
                                                type="button"
                                                class="action-btn renew-btn"
                                                data-driver-id="<?php echo h($row["id"]); ?>"
                                                data-driver-name="<?php echo h($row["name"]); ?>"
                                                data-toda-name="<?php echo h($row["toda"]); ?>"
                                                data-current-expiry="<?php echo h($row["expiry"]); ?>"
                                                onclick="openRenewModal(this)"
                                            >
                                                ↻ Renew
                                            </button>


                                            <form
                                                method="POST"
                                                style="display:inline-flex;align-items:center;margin:0;"
                                                onsubmit="return confirm('Send renewal emails to available driver/president addresses and create a TODA President bell notification?');"
                                            >

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="send_email"
                                                >
                                                <input type="hidden" name="notice_csrf" value="<?= h($_SESSION['renewal_notice_csrf']) ?>">
                                                <input type="hidden" name="notice_event_id" value="<?= h(bin2hex(random_bytes(16))) ?>">

                                                <input
                                                    type="hidden"
                                                    name="driver_id"
                                                    value="<?php echo h($row["id"]); ?>"
                                                >

                                                <button
                                                    type="submit"
                                                    class="action-btn email-btn tq-icon-button" title="Send renewal email and bell notification" aria-label="Send renewal email and bell notification"
                                                >
                                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 6 9 7 9-7"/></svg>
                                                </button>

                                            </form>


                                        </div>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </section>

</main>


<!-- =========================================================
     RENEW MODAL
========================================================= -->

<div
    class="modal-overlay"
    id="renewModal"
>

    <div
        class="modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="renewModalTitle"
    >


        <div class="modal-header">

            <h3 id="renewModalTitle">
                Renew Franchise
            </h3>

            <button
                type="button"
                class="modal-close"
                onclick="closeRenewModal()"
                aria-label="Close"
            >
                ×
            </button>

        </div>


        <form
            method="POST"
            id="renewForm"
        >

            <div class="modal-body">


                <input
                    type="hidden"
                    name="action"
                    value="renew_driver"
                >


                <input
                    type="hidden"
                    name="driver_id"
                    id="renewDriverId"
                >


                <div class="renew-driver-info">

                    <strong id="renewDriverName">
                        Driver
                    </strong>

                    <span>
                        TODA:

                        <strong
                            id="renewTodaName"
                            style="display:inline-flex;align-items:center;margin:0;"
                        >
                            —
                        </strong>
                    </span>

                    <span>
                        Current expiry:

                        <strong
                            id="renewCurrentExpiry"
                            style="display:inline-flex;align-items:center;margin:0;"
                        >
                            —
                        </strong>
                    </span>

                </div>


                <div class="form-group">

                    <label
                        for="newExpiryDate"
                    >
                        New Franchise Expiry Date
                    </label>

                    <input
                        type="date"
                        name="new_expiry_date"
                        id="newExpiryDate"
                        class="form-control"
                        required
                    >

                </div>

            </div>


            <div class="modal-footer">

                <button
                    type="button"
                    class="modal-btn cancel-btn"
                    onclick="closeRenewModal()"
                >
                    Cancel
                </button>

                <button
                    type="submit"
                    class="modal-btn confirm-btn"
                >
                    Confirm Renewal
                </button>

            </div>

        </form>

    </div>

</div>


<script>

/* =========================================================
   RENEWAL FILTER
========================================================= */

let currentRenewalFilter = "all";


function setRenewalFilter(
    filter,
    selectedCard
) {

    currentRenewalFilter =
        filter;


    document
        .querySelectorAll(".filter-card")
        .forEach(function(card) {

            card.classList.remove(
                "active"
            );

        });


    if (selectedCard) {

        selectedCard.classList.add(
            "active"
        );

    }


    applyRenewalFilters();

}


/* =========================================================
   SEARCH + FILTER
========================================================= */

function applyRenewalFilters()
{

    const searchInput =
        document.getElementById(
            "renewalSearch"
        );

    const searchValue =
        searchInput
            ? searchInput.value
                .toLowerCase()
                .trim()
            : "";


    const rows =
        document.querySelectorAll(
            ".driver-row"
        );


    rows.forEach(function(row) {

        const rowStatus =
            (
                row.dataset.status ||
                ""
            ).toLowerCase();


        const rowText =
            row.innerText
                .toLowerCase();


        const matchesFilter =
            currentRenewalFilter === "all" ||
            rowStatus ===
                currentRenewalFilter;


        const matchesSearch =
            searchValue === "" ||
            rowText.includes(
                searchValue
            );


        row.style.display =
            (
                matchesFilter &&
                matchesSearch
            )
            ? ""
            : "none";

    });

}


/* =========================================================
   SEARCH EVENT
========================================================= */

const renewalSearch =
    document.getElementById(
        "renewalSearch"
    );


if (renewalSearch) {

    renewalSearch.addEventListener(
        "input",
        function() {

            applyRenewalFilters();

        }
    );

}


/* =========================================================
   OPEN RENEW MODAL
========================================================= */

function openRenewModal(button)
{

    if (!button) {
        return;
    }


    const driverId =
        button.dataset.driverId ||
        "";

    const driverName =
        button.dataset.driverName ||
        "Driver";

    const todaName =
        button.dataset.todaName ||
        "No TODA";

    const currentExpiry =
        button.dataset.currentExpiry ||
        "No expiry date";


    if (!driverId) {

        alert(
            "Unable to identify this driver."
        );

        return;
    }


    document.getElementById(
        "renewDriverId"
    ).value =
        driverId;


    document.getElementById(
        "renewDriverName"
    ).textContent =
        driverName;


    document.getElementById(
        "renewTodaName"
    ).textContent =
        todaName;


    document.getElementById(
        "renewCurrentExpiry"
    ).textContent =
        currentExpiry;


    const tomorrow =
        new Date();

    tomorrow.setDate(
        tomorrow.getDate() + 1
    );


    const year =
        tomorrow.getFullYear();

    const month =
        String(
            tomorrow.getMonth() + 1
        ).padStart(
            2,
            "0"
        );

    const day =
        String(
            tomorrow.getDate()
        ).padStart(
            2,
            "0"
        );


    const minimumDate =
        year +
        "-" +
        month +
        "-" +
        day;


    const newExpiryDate =
        document.getElementById(
            "newExpiryDate"
        );


    newExpiryDate.min =
        minimumDate;

    newExpiryDate.value =
        "";


    document
        .getElementById(
            "renewModal"
        )
        .classList.add(
            "show"
        );


    setTimeout(function() {

        newExpiryDate.focus();

    }, 100);

}


/* =========================================================
   CLOSE MODAL
========================================================= */

function closeRenewModal()
{

    document
        .getElementById(
            "renewModal"
        )
        .classList.remove(
            "show"
        );

}


/* =========================================================
   CLICK OUTSIDE MODAL
========================================================= */

document
    .getElementById(
        "renewModal"
    )
    .addEventListener(
        "click",
        function(event) {

            if (
                event.target === this
            ) {

                closeRenewModal();

            }

        }
    );


/* =========================================================
   ESC KEY
========================================================= */

document.addEventListener(
    "keydown",
    function(event) {

        if (
            event.key === "Escape"
        ) {

            closeRenewModal();

        }

    }
);


/* =========================================================
   RENEW FORM VALIDATION
========================================================= */

document
    .getElementById(
        "renewForm"
    )
    .addEventListener(
        "submit",
        function(event) {

            const driverId =
                document.getElementById(
                    "renewDriverId"
                ).value.trim();


            const newExpiry =
                document.getElementById(
                    "newExpiryDate"
                ).value;


            if (!driverId) {

                event.preventDefault();

                alert(
                    "Driver ID is missing."
                );

                return;
            }


            if (!newExpiry) {

                event.preventDefault();

                alert(
                    "Please select a new expiry date."
                );

                return;
            }


            const selectedDate =
                new Date(
                    newExpiry +
                    "T00:00:00"
                );


            const today =
                new Date();

            today.setHours(
                0,
                0,
                0,
                0
            );


            if (
                selectedDate <= today
            ) {

                event.preventDefault();

                alert(
                    "The new expiry date must be after today."
                );

                return;
            }


            const driverName =
                document.getElementById(
                    "renewDriverName"
                ).textContent;


            const confirmed =
                confirm(
                    "Confirm franchise renewal for " +
                    driverName +
                    "?"
                );


            if (!confirmed) {

                event.preventDefault();

            }

        }
    );


/* =========================================================
   AUTO-HIDE FLASH
========================================================= */

const flashAlert =
    document.getElementById(
        "flashAlert"
    );


if (flashAlert) {

    setTimeout(function() {

        flashAlert.style.transition =
            "opacity .4s ease";

        flashAlert.style.opacity =
            "0";


        setTimeout(function() {

            if (
                flashAlert.parentNode
            ) {

                flashAlert.parentNode
                    .removeChild(
                        flashAlert
                    );

            }

        }, 450);

    }, 6000);

}


/* =========================================================
   INITIAL FILTER
========================================================= */

applyRenewalFilters();

</script>

<dialog id="tqRenewalPhotoViewer" aria-labelledby="tqRenewalPhotoTitle">
<div class="tq-renewal-photo-panel">
<div class="tq-renewal-photo-toolbar"><h2 id="tqRenewalPhotoTitle">Driver Profile Picture</h2><button type="button" data-close-renewal-photo>Close</button></div>
<img data-renewal-full-photo alt="Driver profile picture" referrerpolicy="no-referrer">
<p data-renewal-photo-error role="alert" hidden>The profile picture could not be loaded. Try again or update the driver’s photo from Driver Management.</p>
</div>
</dialog>
<script>
(function(){
    const dialog=document.getElementById('tqRenewalPhotoViewer');
    if(!dialog)return;
    const image=dialog.querySelector('[data-renewal-full-photo]');
    const error=dialog.querySelector('[data-renewal-photo-error]');
    const title=document.getElementById('tqRenewalPhotoTitle');
    let trigger=null;
    document.querySelectorAll('[data-renewal-photo]').forEach(button=>{
        const thumbnail=button.querySelector('img');
        const fallback=button.querySelector('.tq-renewal-initial');
        const unavailable=()=>{thumbnail.hidden=true;fallback.hidden=false;button.disabled=true;};
        thumbnail.addEventListener('error',unavailable);
        if(thumbnail.complete && !thumbnail.naturalWidth)unavailable();
    });
    document.addEventListener('click',event=>{
        const button=event.target.closest?.('[data-renewal-photo]');
        if(!button || button.disabled)return;
        if(typeof dialog.showModal!=='function'){window.open(button.dataset.photoUrl,'_blank','noopener,noreferrer');return;}
        trigger=button;error.hidden=true;image.hidden=false;
        title.textContent=button.dataset.driverName || 'Driver Profile Picture';
        image.alt=title.textContent+' profile picture';image.src=button.dataset.photoUrl;
        if(!dialog.open)dialog.showModal();
    });
    image.addEventListener('error',()=>{image.hidden=true;error.hidden=false;});
    dialog.querySelector('[data-close-renewal-photo]').addEventListener('click',()=>dialog.close());
    dialog.addEventListener('click',event=>{if(event.target===dialog)dialog.close();});
    dialog.addEventListener('close',()=>{image.removeAttribute('src');if(trigger?.isConnected)trigger.focus();});
})();
</script>
</body>
</html>
