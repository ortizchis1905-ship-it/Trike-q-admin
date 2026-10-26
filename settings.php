<?php
session_start();

require_once __DIR__ . "/config/supabase.php";
require_once __DIR__ . '/tmu-tools.php';
tqRequireAdmin();
tqRequireCsrf();


$serviceConfig = __DIR__ . "/config/supabase_service.php";
if (is_file($serviceConfig)) {
    require_once $serviceConfig;
}

/* =========================================================
   AUTH CHECK
========================================================= */
if (empty($_SESSION["access_token"]) || empty($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

/* =========================================================
   HELPERS
========================================================= */
function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function isEnabled($value)
{
    return (
        $value === true ||
        $value === 1 ||
        $value === "1" ||
        $value === "true"
    );
}

function adminServiceRoleConfigured()
{
    global $SUPABASE_SERVICE_ROLE_KEY;

    if (
        !isset($SUPABASE_SERVICE_ROLE_KEY) ||
        !is_string($SUPABASE_SERVICE_ROLE_KEY)
    ) {
        return false;
    }

    $key = trim($SUPABASE_SERVICE_ROLE_KEY);

    return $key !== "" &&
        $key !== "PUT_YOUR_SERVICE_ROLE_KEY_HERE" &&
        strpos($key, "secret") !== 0;
}

function uploadAdminProfilePicture($userId, $file)
{
    global $SUPABASE_URL, $SUPABASE_SERVICE_ROLE_KEY;

    if (!adminServiceRoleConfigured()) {
        return [
            "success" => false,
            "url" => "",
            "error" => "Service role key is not configured. Add config/supabase_service.php first."
        ];
    }

    $uploadError = (int)($file["error"] ?? UPLOAD_ERR_NO_FILE);
    $fileSize = (int)($file["size"] ?? 0);
    $tmpName = $file["tmp_name"] ?? "";

    if ($uploadError === UPLOAD_ERR_NO_FILE) {
        return ["success" => true, "url" => "", "error" => ""];
    }

    if ($uploadError !== UPLOAD_ERR_OK) {
        return ["success" => false, "url" => "", "error" => "The profile picture upload failed. Please choose the image again."];
    }

    if ($fileSize <= 0 || $fileSize > 5 * 1024 * 1024) {
        return ["success" => false, "url" => "", "error" => "The profile picture must be smaller than 5 MB."];
    }

    if (!is_uploaded_file($tmpName)) {
        return ["success" => false, "url" => "", "error" => "The uploaded image could not be verified."];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($tmpName);
    $mimeToExtension = [
        "image/jpeg" => "jpg",
        "image/png" => "png",
        "image/webp" => "webp"
    ];

    if (!isset($mimeToExtension[$mimeType])) {
        return ["success" => false, "url" => "", "error" => "Only JPG, PNG, and WEBP images are allowed."];
    }

    $extension = $mimeToExtension[$mimeType];
    $objectPath = $userId . "/profile-" . bin2hex(random_bytes(12)) . "." . $extension;
    $storageUrl = rtrim($SUPABASE_URL, "/") . "/storage/v1/object/admin-profiles/" . $objectPath;
    $imageBytes = file_get_contents($tmpName);

    if ($imageBytes === false) {
        return ["success" => false, "url" => "", "error" => "Unable to read the uploaded image."];
    }

    $ch = curl_init($storageUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_POSTFIELDS => $imageBytes,
        CURLOPT_HTTPHEADER => [
            "apikey: " . trim($SUPABASE_SERVICE_ROLE_KEY),
            "Authorization: Bearer " . trim($SUPABASE_SERVICE_ROLE_KEY),
            "Content-Type: " . $mimeType,
            "x-upsert: true"
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30
    ]);

    $storageResponse = curl_exec($ch);
    $storageError = curl_error($ch);
    $storageStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($storageResponse === false || $storageStatus < 200 || $storageStatus >= 300) {
        return [
            "success" => false,
            "url" => "",
            "error" => $storageError !== "" ? $storageError : "Storage upload failed. HTTP status: " . $storageStatus
        ];
    }

    return [
        "success" => true,
        "url" => rtrim($SUPABASE_URL, "/") . "/storage/v1/object/public/admin-profiles/" . $objectPath,
        "error" => ""
    ];
}

/* =========================================================
   DEFAULT SYSTEM SETTINGS
========================================================= */
$settings = [
    "system_name"             => "TRIKE-Q",
    "office_name"             => "TMU",
    "contact_number"          => "",
    "office_address"          => "",
    "official_email"          => "",

    "email_notifications"     => true,
    "renewal_reminders"       => true,
    "dues_reminders"          => true,
    "complaint_notifications" => true,

    "renewal_reminder_days"   => 30,
    "renewal_grace_days"      => 7,
    "session_timeout"         => 30,
    "login_protection"        => true
];

/* =========================================================
   ADMIN DEFAULTS
========================================================= */
$adminName  = "TMU Administrator";
$adminEmail = "";
$adminProfilePictureUrl = "";
$adminRole = "tmu_admin";

/* =========================================================
   MESSAGES
========================================================= */
$successMessage = $_SESSION["master_export_notice"] ?? "";
unset($_SESSION["master_export_notice"]);
$errorMessage = $_SESSION["master_export_error"] ?? "";
unset($_SESSION["master_export_error"]);

/* =========================================================
   CURRENT USER ID
========================================================= */
$userId = $_SESSION["user_id"];

/* =========================================================
   LOAD ADMIN PROFILE
   THIS IS THE SINGLE SOURCE OF TRUTH FOR THE PROFILE
========================================================= */
$adminResult = supabaseRequest(
    "admin",
    "GET",
    null,
    "select=name,email,phoneNumber,user_id,profile_picture_url,role&user_id=eq." . urlencode($userId)
);

if (
    !empty($adminResult["success"]) &&
    !empty($adminResult["data"]) &&
    is_array($adminResult["data"])
) {
    $admin = $adminResult["data"][0] ?? [];

    $loadedName = trim((string)($admin["name"] ?? ""));
    $loadedEmail = trim((string)($admin["email"] ?? ""));
    $loadedProfilePicture = trim((string)($admin["profile_picture_url"] ?? ""));

    if ($loadedName !== "") {
        $adminName = $loadedName;
    }

    $adminEmail = $loadedEmail;
    $adminProfilePictureUrl = $loadedProfilePicture;
    $adminRole = "tmu_admin";
}

$adminRoleLabel = "TMU Administrator";

$canEditSystemSettings = true;

/* =========================================================
   LOAD SYSTEM SETTINGS
========================================================= */
$settingsResult = supabaseRequest(
    "system_settings",
    "GET",
    null,
    "select=*&id=eq.1"
);

if (
    !empty($settingsResult["success"]) &&
    !empty($settingsResult["data"]) &&
    is_array($settingsResult["data"])
) {
    $settings = array_merge(
        $settings,
        $settingsResult["data"][0]
    );
}

/* =========================================================
   POST ACTIONS
========================================================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST["action"] ?? "";

    /* =====================================================
       SAVE ADMIN PROFILE
    ===================================================== */
    if ($action === 'save_logo') {
        try { tqSaveSystemLogo($_FILES['system_logo'] ?? null); $successMessage = 'System logo updated successfully.'; }
        catch (RuntimeException $exception) { $errorMessage = $exception->getMessage(); }
    }
    elseif ($action === "save_profile") {

        $newAdminName  = trim($_POST["admin_name"] ?? "");
        $newAdminEmail = trim($_POST["admin_email"] ?? $adminEmail);

        if ($newAdminName === "") {

            $errorMessage = "Admin name is required.";

        } elseif (
            $newAdminEmail !== "" &&
            !filter_var($newAdminEmail, FILTER_VALIDATE_EMAIL)
        ) {

            $errorMessage = "Please enter a valid email address.";

        } else {

            $uploadedProfileUrl = "";

            if (
                isset($_FILES["admin_profile_picture"]) &&
                is_array($_FILES["admin_profile_picture"]) &&
                (int)($_FILES["admin_profile_picture"]["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
            ) {
                $uploadResult = uploadAdminProfilePicture(
                    $userId,
                    $_FILES["admin_profile_picture"]
                );

                if (empty($uploadResult["success"])) {
                    $errorMessage = $uploadResult["error"]
                        ?? "Unable to upload admin profile picture.";
                } else {
                    $uploadedProfileUrl = trim((string)($uploadResult["url"] ?? ""));
                }
            }

            $profileData = [
                "name"  => $newAdminName,
                "email" => $newAdminEmail
            ];

            if ($uploadedProfileUrl !== "") {
                $profileData["profile_picture_url"] = $uploadedProfileUrl;
            }

            if ($errorMessage !== "") {
                $saveProfile = [
                    "success" => false,
                    "error" => $errorMessage
                ];
            } else {
                $saveProfile = supabaseRequest(
                    "admin",
                    "PATCH",
                    $profileData,
                    "user_id=eq." . urlencode($userId)
                );
            }

            if (!empty($saveProfile["success"])) {

                /*
                 * Update the values immediately so the
                 * current page, sidebar, header and profile
                 * card all display the new information.
                 */
                $adminName  = $newAdminName;
                $adminEmail = $newAdminEmail;

                if ($uploadedProfileUrl !== "") {
                    $adminProfilePictureUrl = $uploadedProfileUrl;
                }

                $successMessage =
                    "Admin profile updated successfully.";

            } else {

                $errorMessage = $saveProfile["error"]
                    ?? "Unable to update admin profile.";
            }
        }
    }

    /* =====================================================
       SAVE SYSTEM SETTINGS
    ===================================================== */
    elseif ($action === "save_system_settings") {

        if (!$canEditSystemSettings) {

            $errorMessage =
                "TMU administrators can update system settings.";

        } else {

            $updatedSettings = [
                "system_name" => trim($_POST["system_name"] ?? ""),
                "office_name" => trim($_POST["office_name"] ?? ""),
                "contact_number" => trim($_POST["contact_number"] ?? ""),
                "office_address" => trim($_POST["office_address"] ?? ""),
                "official_email" => trim($_POST["official_email"] ?? ""),
                "email_notifications" => isset($_POST["email_notifications"]),
                "renewal_reminders" => isset($_POST["renewal_reminders"]),
                "dues_reminders" => isset($_POST["dues_reminders"]),
                "complaint_notifications" => isset($_POST["complaint_notifications"]),
                "renewal_reminder_days" => (int)($_POST["renewal_reminder_days"] ?? 30),
                "renewal_grace_days" => (int)($_POST["renewal_grace_days"] ?? 7),
                "session_timeout" => (int)($_POST["session_timeout"] ?? 30),
                "login_protection" => isset($_POST["login_protection"])
            ];

            if ($updatedSettings["system_name"] === "") {
                $errorMessage = "System name is required.";
            } elseif ($updatedSettings["office_name"] === "") {
                $errorMessage = "Office name is required.";
            } elseif (
                $updatedSettings["official_email"] !== "" &&
                !filter_var($updatedSettings["official_email"], FILTER_VALIDATE_EMAIL)
            ) {
                $errorMessage = "Please enter a valid official email address.";
            } elseif ($updatedSettings["renewal_reminder_days"] < 1) {
                $errorMessage = "Renewal reminder days must be at least 1.";
            } elseif ($updatedSettings["renewal_grace_days"] < 0) {
                $errorMessage = "Renewal grace period cannot be negative.";
            } elseif ($updatedSettings["session_timeout"] < 5) {
                $errorMessage = "Session timeout must be at least 5 minutes.";
            } else {

                $saveSettings = supabaseRequest(
                    "system_settings",
                    "PATCH",
                    $updatedSettings,
                    "id=eq.1"
                );

                if (!empty($saveSettings["success"])) {
                    $settings = array_merge($settings, $updatedSettings);
                    $successMessage = "System settings updated successfully.";
                } else {
                    $errorMessage = $saveSettings["error"]
                        ?? "Unable to update system settings.";
                }
            }
        }
    }

    /* =====================================================
       SAVE ACCOUNT SECURITY
    ===================================================== */
    elseif ($action === "save_security") {

        $currentPassword = $_POST["current_password"] ?? "";
        $newEmail = trim($_POST["new_email"] ?? $adminEmail);
        $newPassword = $_POST["new_password"] ?? "";
        $confirmPassword = $_POST["confirm_password"] ?? "";

        $changeEmail = $newEmail !== "" && $newEmail !== $adminEmail;
        $changePassword = $newPassword !== "" || $confirmPassword !== "";

        if (!$changeEmail && !$changePassword) {

            $errorMessage = "Enter a new email or new password to update account security.";

        } elseif ($currentPassword === "") {

            $errorMessage = "Current password is required to update account security.";

        } elseif ($changeEmail && !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {

            $errorMessage = "Please enter a valid login email address.";

        } elseif ($changePassword && strlen($newPassword) < 6) {

            $errorMessage = "New password must be at least 6 characters.";

        } elseif ($changePassword && $newPassword !== $confirmPassword) {

            $errorMessage = "New password and confirmation password do not match.";

        } else {

            $verifyResult = supabaseRequest(
                "auth/v1/token?grant_type=password",
                "POST",
                [
                    "email" => $adminEmail,
                    "password" => $currentPassword
                ],
                null,
                false
            );

            if (empty($verifyResult["success"])) {

                $errorMessage = "Current password is incorrect.";

            } else {

                $securityPayload = [];

                if ($changeEmail) {
                    $securityPayload["email"] = $newEmail;
                }

                if ($changePassword) {
                    $securityPayload["password"] = $newPassword;
                }

                $securityResult = supabaseRequest(
                    "auth/v1/user",
                    "PUT",
                    $securityPayload
                );

                if (!empty($securityResult["success"])) {

                    if ($changeEmail) {
                        supabaseRequest(
                            "admin",
                            "PATCH",
                            ["email" => $newEmail],
                            "user_id=eq." . urlencode($userId)
                        );

                        $adminEmail = $newEmail;
                    }

                    $successMessage = "Account security updated successfully.";

                } else {

                    $errorMessage = $securityResult["error"]
                        ?? "Unable to update account security.";

                }
            }
        }
    }

    /* =====================================================
       CHANGE PASSWORD
    ===================================================== */
    elseif ($action === "change_password") {

        $currentPassword = $_POST["current_password"] ?? "";
        $newPassword     = $_POST["new_password"] ?? "";
        $confirmPassword = $_POST["confirm_password"] ?? "";

        if (
            $currentPassword === "" ||
            $newPassword === "" ||
            $confirmPassword === ""
        ) {

            $errorMessage =
                "Please complete all password fields.";

        } elseif (strlen($newPassword) < 6) {

            $errorMessage =
                "New password must be at least 6 characters.";

        } elseif ($newPassword !== $confirmPassword) {

            $errorMessage =
                "New password and confirmation password do not match.";

        } else {

            /* VERIFY CURRENT PASSWORD */
            $verifyPayload = [
                "email"    => $adminEmail,
                "password" => $currentPassword
            ];

            $verifyResult = supabaseRequest(
                "auth/v1/token?grant_type=password",
                "POST",
                $verifyPayload,
                null,
                false
            );

            if (empty($verifyResult["success"])) {

                $errorMessage =
                    "Current password is incorrect.";

            } else {

                /* UPDATE PASSWORD */
                $passwordPayload = [
                    "password" => $newPassword
                ];

                $passwordResult = supabaseRequest(
                    "auth/v1/user",
                    "PUT",
                    $passwordPayload
                );

                if (!empty($passwordResult["success"])) {

                    $successMessage =
                        "Password changed successfully.";

                } else {

                    $errorMessage =
                        $passwordResult["error"]
                        ?? "Unable to change password.";
                }
            }
        }
    }

    /* =====================================================
       CHANGE LOGIN EMAIL
    ===================================================== */
    elseif ($action === "change_email") {

        $currentPassword = $_POST["email_current_password"] ?? "";
        $newEmail        = trim($_POST["new_email"] ?? "");

        if (
            $currentPassword === "" ||
            $newEmail === ""
        ) {

            $errorMessage =
                "Please complete all email fields.";

        } elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {

            $errorMessage =
                "Please enter a valid email address.";

        } else {

            /* VERIFY CURRENT PASSWORD */
            $verifyPayload = [
                "email"    => $adminEmail,
                "password" => $currentPassword
            ];

            $verifyResult = supabaseRequest(
                "auth/v1/token?grant_type=password",
                "POST",
                $verifyPayload,
                null,
                false
            );

            if (empty($verifyResult["success"])) {

                $errorMessage =
                    "Current password is incorrect.";

            } else {

                /* UPDATE AUTH EMAIL */
                $emailPayload = [
                    "email" => $newEmail
                ];

                $emailResult = supabaseRequest(
                    "auth/v1/user",
                    "PUT",
                    $emailPayload
                );

                if (!empty($emailResult["success"])) {

                    /* UPDATE ADMIN PROFILE EMAIL */
                    $profileEmailResult = supabaseRequest(
                        "admin",
                        "PATCH",
                        [
                            "email" => $newEmail
                        ],
                        "user_id=eq." . urlencode($userId)
                    );

                    $adminEmail = $newEmail;

                    $successMessage =
                        "Login email updated successfully. " .
                        "Supabase may require email confirmation " .
                        "before the new address becomes active.";

                } else {

                    $errorMessage =
                        $emailResult["error"]
                        ?? "Unable to change login email.";
                }
            }
        }
    }
}

/* =========================================================
   ADMIN INITIAL
========================================================= */
$adminInitial = strtoupper(
    substr(
        trim($adminName) !== ""
            ? trim($adminName)
            : "A",
        0,
        1
    )
);
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Settings | TRIKE-Q TMU Admin</title>

<style>

* {
    box-sizing: border-box;
}

html,
body {
    margin: 0;
    padding: 0;
    font-family: Arial, Helvetica, sans-serif;
    background: #f8fafc;
    color: #1f2937;
}

body {
    min-height: 100vh;
}

/* =========================================================
   SIDEBAR
========================================================= */

.sidebar {
    position: fixed;
    left: 0;
    top: 0;
    bottom: 0;

    width: 250px;

    background: #111827;
    color: #f9fafb;

    padding: 22px 16px;

    z-index: 1000;

    overflow-y: auto;

    border-right: 1px solid #263244;

    display: flex;
    flex-direction: column;
}

.logo-area {
    padding: 4px 10px 22px;
}

.logo {
    font-size: 25px;
    font-weight: 800;
    color: #f97316;
    letter-spacing: .5px;
}

.logo-subtitle {
    font-size: 11px;
    color: #94a3b8;
    margin-top: 3px;
    letter-spacing: .8px;
}

.nav-section {
    margin-top: 18px;
}

.nav-title {
    font-size: 10px;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 1px;
    padding: 0 10px 8px;
    font-weight: 700;
}

.nav-link {
    display: flex;
    align-items: center;
    gap: 11px;

    padding: 11px 12px;
    margin: 3px 0;

    border-radius: 8px;

    color: #e2e8f0;
    text-decoration: none;

    font-size: 14px;

    transition: .2s ease;
}

.nav-link:hover {
    background: #253047;
    color: #ffffff;
}

.nav-link.active {
    background: #f97316;
    color: #ffffff;
    font-weight: 700;
}

.nav-icon {
    width: 20px;
    text-align: center;
    font-size: 15px;
    color: #cbd5e1;
}

.nav-link.active .nav-icon {
    color: #ffffff;
}

.sidebar-bottom {
    margin-top: 25px;
}

/* =========================================================
   SIDEBAR ADMIN PROFILE
========================================================= */

.sidebar-profile {
    margin-top: auto;
    padding-top: 18px;
}

.sidebar-profile-box {
    display: flex;
    align-items: center;
    gap: 11px;

    padding: 11px;

    border-radius: 10px;

    background: #1e293b;
    border: 1px solid #334155;

    text-decoration: none;

    transition: .2s ease;
}

.sidebar-profile-box:hover {
    background: #253047;
    border-color: #475569;
}

.sidebar-avatar {
    width: 38px;
    height: 38px;

    border-radius: 50%;

    background: #f97316;
    color: #ffffff;

    display: flex;
    align-items: center;
    justify-content: center;

    font-size: 14px;
    font-weight: 800;

    flex-shrink: 0;
}

.sidebar-profile-text {
    min-width: 0;
}

.sidebar-admin-name {
    color: #ffffff;

    font-size: 12px;
    font-weight: 700;

    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.sidebar-admin-role {
    color: #94a3b8;

    font-size: 10px;

    margin-top: 2px;
}

/* =========================================================
   MAIN
========================================================= */

.main {
    margin-left: 250px;
    min-height: 100vh;
}

/* =========================================================
   TOPBAR
========================================================= */

.topbar {
    height: 75px;

    background: #ffffff;

    border-bottom: 1px solid #e5e7eb;

    display: flex;
    align-items: center;
    justify-content: space-between;

    padding: 0 30px;

    position: sticky;
    top: 0;

    z-index: 900;
}

.page-heading h1 {
    margin: 0;
    color: #9a3412;
    font-size: 23px;
}

.page-heading p {
    margin: 4px 0 0;
    color: #6b7280;
    font-size: 12px;
}

.admin-top {
    display: flex;
    align-items: center;
    gap: 10px;
}

.admin-avatar {
    width: 38px;
    height: 38px;

    border-radius: 50%;

    background: #fff7ed;
    border: 1px solid #fed7aa;

    color: #ea580c;

    display: flex;
    align-items: center;
    justify-content: center;

    font-weight: 800;
    font-size: 14px;
    overflow: hidden;
}

.admin-top-text strong {
    display: block;
    font-size: 13px;
    color: #111827;
}

.admin-top-text span {
    display: block;
    font-size: 11px;
    color: #6b7280;
    margin-top: 2px;
}

/* =========================================================
   CONTENT
========================================================= */

.content {
    padding: 28px 30px 40px;
    max-width: 1400px;
}

/* =========================================================
   ALERTS
========================================================= */

.alert {
    border-radius: 10px;
    padding: 13px 16px;
    margin-bottom: 20px;
    font-size: 13px;
    border: 1px solid;
}

.alert.success {
    background: #ecfdf5;
    border-color: #a7f3d0;
    color: #047857;
}

.alert.error {
    background: #fef2f2;
    border-color: #fecaca;
    color: #b91c1c;
}

/* =========================================================
   GRID
========================================================= */

.settings-toolbar {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-bottom: 14px;
}

.settings-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 20px;
}

.settings-grid > form {
    display: contents;
}

.settings-card {
    background: #fff;
    border: 2px solid #e5e7eb;
    border-radius: 12px;
    padding: 22px;
    box-shadow: 0 3px 12px rgba(0, 0, 0, .04);
}

.settings-card.full {
    grid-column: 1 / -1;
}

.profile-email-field {
    display: none;
}

.view-panel {
    display: block;
}

.edit-panel {
    display: block;
}

body.settings-editing .view-panel {
    display: none;
}

body:not(.settings-editing) .edit-panel {
    display: none;
}

body:not(.settings-editing) .btn-row button[type="submit"] {
    display: none;
}

body:not(.settings-editing) input:not([type="hidden"]),
body:not(.settings-editing) textarea,
body:not(.settings-editing) select {
    border-color: transparent;
    background: transparent;
    box-shadow: none;
    padding: 0;
    cursor: default;
}

body:not(.settings-editing) input:not([type="hidden"]):not([type="checkbox"]):not([type="file"]),
body:not(.settings-editing) textarea,
body:not(.settings-editing) select {
    min-height: 0;
    color: #111827;
    font-weight: 700;
}

body:not(.settings-editing) textarea {
    min-height: 0;
    resize: none;
}

body:not(.settings-editing) .form-grid {
    gap: 0 20px;
}

body:not(.settings-editing) .form-group {
    margin-bottom: 0;
    padding: 10px 0;
    border-bottom: 1px solid #f1f5f9;
}

body:not(.settings-editing) .form-group label {
    margin-bottom: 4px;
    color: #6b7280;
}

body.settings-editing input:not([type="hidden"]):not([type="file"]),
body.settings-editing textarea,
body.settings-editing select {
    background: #ffffff;
}

.card-header {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 20px;
}

.card-icon {
    width: 38px;
    height: 38px;

    border-radius: 9px;

    background: #fff7ed;
    color: #ea580c;

    display: flex;
    align-items: center;
    justify-content: center;

    font-size: 17px;

    flex-shrink: 0;
}

.card-header h2 {
    margin: 0;
    font-size: 16px;
    color: #111827;
}

.card-header p {
    margin: 4px 0 0;
    color: #6b7280;
    font-size: 12px;
    line-height: 1.5;
}

/* =========================================================
   PROFILE
========================================================= */

.profile-box {
    display: flex;
    align-items: center;
    gap: 15px;

    padding: 14px;

    background: #f8fafc;
    border: 1px solid #e5e7eb;

    border-radius: 10px;

    margin-bottom: 18px;
}

.profile-avatar {
    width: 58px;
    height: 58px;

    border-radius: 50%;

    background: #fff7ed;
    color: #ea580c;

    border: 2px solid #fed7aa;

    display: flex;
    align-items: center;
    justify-content: center;

    font-size: 21px;
    font-weight: 800;
    overflow: hidden;
}

.profile-avatar img,
.admin-avatar img {
    width: 100%;
    height: 100%;
    border-radius: 50%;
    object-fit: cover;
    display: block;
}

.profile-info strong {
    display: block;
    font-size: 15px;
    color: #111827;
}

.profile-info span {
    display: block;
    font-size: 12px;
    color: #6b7280;
    margin-top: 3px;
}

/* =========================================================
   FORMS
========================================================= */

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
}

.form-group {
    margin-bottom: 13px;
}

.form-group.full {
    grid-column: 1 / -1;
}

.form-group label {
    display: block;

    font-size: 12px;
    font-weight: 700;

    color: #374151;

    margin-bottom: 6px;
}

input,
select,
textarea {
    width: 100%;

    border: 1px solid #d1d5db;
    border-radius: 8px;

    padding: 10px 11px;

    font-family: inherit;
    font-size: 13px;

    color: #111827;
    background: #fff;

    outline: none;

    transition: .2s ease;
}

input:focus,
select:focus,
textarea:focus {
    border-color: #f97316;
    box-shadow: 0 0 0 3px rgba(249, 115, 22, .10);
}

textarea {
    resize: vertical;
    min-height: 78px;
}

input[readonly],
textarea[readonly],
select:disabled {
    background: #f8fafc;
    color: #6b7280;
    cursor: default;
}

/* =========================================================
   BUTTONS
========================================================= */

.btn-row {
    display: flex;
    align-items: center;
    gap: 9px;
    margin-top: 5px;
}

.btn {
    border: 0;
    border-radius: 8px;

    padding: 10px 15px;

    font-size: 12px;
    font-weight: 700;

    cursor: pointer;

    transition: .2s ease;
}

.btn-primary {
    background: #f97316;
    color: #fff;
}

.btn-primary:hover {
    background: #ea580c;
}

.btn-secondary {
    background: #f3f4f6;
    color: #374151;
}

.btn-secondary:hover {
    background: #e5e7eb;
}

/* =========================================================
   TOGGLE
========================================================= */

.option-list {
    display: flex;
    flex-direction: column;
    gap: 11px;
}

.option-item {
    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 15px;

    padding: 12px 13px;

    border: 1px solid #e5e7eb;
    border-radius: 9px;
}

.option-text strong {
    display: block;
    font-size: 12px;
    color: #111827;
}

.option-text span {
    display: block;
    margin-top: 3px;
    font-size: 11px;
    color: #6b7280;
}

.switch {
    position: relative;
    width: 40px;
    height: 22px;
    flex-shrink: 0;
}

.switch input {
    opacity: 0;
    width: 0;
    height: 0;
}

.slider {
    position: absolute;
    inset: 0;

    background: #d1d5db;

    border-radius: 999px;

    cursor: pointer;

    transition: .2s;
}

.slider:before {
    content: "";

    position: absolute;

    width: 16px;
    height: 16px;

    left: 3px;
    top: 3px;

    background: #fff;

    border-radius: 50%;

    transition: .2s;

    box-shadow: 0 1px 3px rgba(0,0,0,.2);
}

.switch input:checked + .slider {
    background: #f97316;
}

.switch input:checked + .slider:before {
    transform: translateX(18px);
}

/* =========================================================
   STATUS
========================================================= */

.status-list {
    display: flex;
    flex-direction: column;
    gap: 11px;
}

.status-row {
    display: flex;
    justify-content: space-between;
    align-items: center;

    padding: 11px 13px;

    border: 1px solid #e5e7eb;
    border-radius: 9px;
}

.status-label {
    font-size: 12px;
    color: #374151;
}

.status-value {
    display: inline-flex;
    align-items: center;
    gap: 6px;

    font-size: 11px;
    font-weight: 700;

    color: #047857;
}

.status-dot {
    width: 7px;
    height: 7px;

    background: #10b981;

    border-radius: 50%;
}

/* =========================================================
   INFO NOTE
========================================================= */

.info-note {
    background: #fff7ed;
    border: 1px solid #fed7aa;

    color: #9a3412;

    border-radius: 9px;

    padding: 11px 13px;

    font-size: 11px;
    line-height: 1.5;

    margin-top: 10px;
}

/* =========================================================
   SECURITY WARNING
========================================================= */

.security-note {
    background: #fef2f2;
    border: 1px solid #fecaca;

    color: #991b1b;

    border-radius: 9px;

    padding: 11px 13px;

    font-size: 11px;
    line-height: 1.5;

    margin-top: 12px;
}

/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 1100px) {

    .settings-grid {
        grid-template-columns: 1fr;
    }

    .settings-card.full {
        grid-column: auto;
    }
}

@media (max-width: 800px) {

    .sidebar {
        width: 220px;
    }

    .main {
        margin-left: 220px;
    }

    .topbar {
        padding: 0 20px;
    }

    .content {
        padding: 22px 20px 35px;
    }

    .form-grid {
        grid-template-columns: 1fr;
    }

    .form-group.full {
        grid-column: auto;
    }
}

@media (max-width: 600px) {

    .sidebar {
        position: relative;
        width: 100%;
        height: auto;
    }

    .main {
        margin-left: 0;
    }

    .topbar {
        height: auto;
        min-height: 70px;
        padding: 14px 16px;
    }

    .content {
        padding: 18px 14px 30px;
    }

    .admin-top-text {
        display: none;
    }

    .settings-card {
        padding: 17px;
    }

    .profile-box {
        align-items: flex-start;
    }

    .sidebar-profile {
        margin-top: 20px;
    }
}

/* =========================================================
   READABLE VIEW MODE
========================================================= */

body:not(.settings-editing) .settings-card {
    padding: 24px !important;
}

body:not(.settings-editing) .card-header h2 {
    font-size: 20px !important;
    line-height: 1.25 !important;
    color: #9a3412 !important;
}

body:not(.settings-editing) .card-header p {
    font-size: 14px !important;
    line-height: 1.45 !important;
}

body:not(.settings-editing) .profile-box {
    padding: 18px !important;
}

body:not(.settings-editing) .profile-info strong {
    font-size: 19px !important;
    line-height: 1.25 !important;
}

body:not(.settings-editing) .profile-info span {
    font-size: 14px !important;
    line-height: 1.35 !important;
}

body:not(.settings-editing) .form-group {
    padding: 13px 0 !important;
}

body:not(.settings-editing) .form-group label,
body:not(.settings-editing) .status-label {
    font-size: 14px !important;
    line-height: 1.35 !important;
    color: #475569 !important;
}

body:not(.settings-editing) .settings-card input:not([type="hidden"]):not([type="checkbox"]):not([type="file"]),
body:not(.settings-editing) .settings-card textarea,
body:not(.settings-editing) .settings-card select {
    border: 0 !important;
    border-radius: 0 !important;
    background: transparent !important;
    box-shadow: none !important;
    padding: 0 !important;
    min-height: 0 !important;
    height: auto !important;
    font-size: 17px !important;
    line-height: 1.35 !important;
    font-weight: 800 !important;
    color: #111827 !important;
    appearance: none !important;
    -webkit-appearance: none !important;
}

body:not(.settings-editing) .status-row {
    padding: 15px 16px !important;
}

body:not(.settings-editing) .status-value {
    font-size: 16px !important;
    line-height: 1.35 !important;
    color: #111827 !important;
}

body:not(.settings-editing) .option-item {
    padding: 15px 16px !important;
}

body:not(.settings-editing) .option-text strong {
    font-size: 16px !important;
    line-height: 1.3 !important;
}

body:not(.settings-editing) .option-text span {
    font-size: 14px !important;
    line-height: 1.35 !important;
}

body:not(.settings-editing) .info-note,
body:not(.settings-editing) .security-note {
    font-size: 14px !important;
    line-height: 1.45 !important;
}

</style>

<link rel="stylesheet" href="admin-theme.css">


<link rel="stylesheet" href="tmu-updates.css?v=20261019b">
<script src="tmu-updates.js?v=20261019" defer></script>

<style>
#systemLogoCard{align-self:start;min-width:0;padding:20px;border:1px solid #e5e7eb}
#systemLogoCard .card-header{margin-bottom:16px}
#systemLogoCard .card-header h2{font-size:18px;font-weight:600}
#systemLogoCard .card-header p{font-size:14px;line-height:1.5}
#systemLogoCard .system-logo-layout{display:flex;align-items:center;gap:16px}
#systemLogoCard .system-logo-frame{width:116px;height:116px;flex:0 0 116px;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;display:flex;align-items:center;justify-content:center;padding:10px}
#systemLogoCard .system-logo-frame img{display:block;width:96px;height:96px;max-width:100%;object-fit:contain;margin:0}
#systemLogoCard .system-logo-copy{min-width:0}
#systemLogoCard .system-logo-copy strong{font-size:16px;font-weight:600;color:#334155}
#systemLogoCard .system-logo-copy p{font-size:14px;line-height:1.5;color:#64748b;margin:6px 0 0}
#systemLogoCard form{margin-top:16px;padding-top:16px;border-top:1px solid #e2e8f0}
#systemLogoCard label{display:block;font-size:14px;font-weight:600;margin-bottom:8px}
#systemLogoCard input[type=file]{display:block;width:100%;max-width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:8px;background:white;font:14px Arial,Helvetica,sans-serif}
#systemLogoCard input[type=file]::file-selector-button{padding:8px 10px;margin-right:10px;border:1px solid #fed7aa;border-radius:6px;background:#fff7ed;color:#9a3412;cursor:pointer}
#systemLogoCard #systemLogoFileStatus{margin:8px 0 0;font-size:14px;line-height:1.5;overflow-wrap:anywhere;color:#64748b}
#systemLogoCard .btn-row{margin-top:12px}
@media(max-width:420px){#systemLogoCard .system-logo-layout{align-items:flex-start;gap:12px}#systemLogoCard .system-logo-frame{width:88px;height:88px;flex-basis:88px}#systemLogoCard .system-logo-frame img{width:68px;height:68px}}
</style>
<style>
#settingsEditButton{display:inline-flex!important;align-items:center;justify-content:center;width:40px!important;height:40px!important;min-width:40px!important;padding:0!important;border-radius:8px!important}
#settingsEditButton[hidden]{display:none!important}
#settingsEditButton:focus-visible{outline:3px solid #fdba74;outline-offset:3px}
/* Export controls are separate from the Settings view/edit controls. */
#csvExportCard{display:flex;align-items:center;justify-content:space-between;gap:24px;margin-bottom:16px;padding:20px 24px;background:#fff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 2px 8px #00000008}
#csvExportCard .tq-csv-heading{display:flex;align-items:center;gap:12px;min-width:0}
#csvExportCard .tq-csv-icon{display:grid;place-items:center;width:42px;height:42px;flex-shrink:0;border-radius:10px;background:#f0fdf4;color:#15803d}
#csvExportCard h2{margin:0 0 5px;font-size:20px;color:#9a3412;line-height:1.3}
#csvExportCard p{margin:0;font-size:13px;color:#64748b;line-height:1.5}
#csvExportCard .tq-csv-form{display:flex;align-items:flex-end;gap:10px;margin:0;flex-shrink:0}
#csvExportCard .tq-csv-form>div{width:260px}
#csvExportCard label{display:block;margin-bottom:6px;font-size:14px;color:#334155;font-weight:400}
body #csvExportCard #csvDataset{display:block!important;pointer-events:auto!important;width:100%!important;height:42px!important;min-height:42px!important;padding:9px 12px!important;border:1px solid #cbd5e1!important;border-radius:8px!important;background:#fff!important;color:#334155!important;font:400 14px Arial,Helvetica,sans-serif!important;appearance:auto!important;-webkit-appearance:menulist!important;box-shadow:none!important;opacity:1!important;cursor:pointer}
body #csvExportCard .tq-csv-download{display:inline-flex!important;align-items:center;justify-content:center;gap:8px;height:42px!important;min-height:42px;padding:9px 16px!important;border:1px solid #15803d!important;border-radius:8px!important;background:#16a34a!important;background-image:none!important;color:#fff!important;font:600 14px Arial,Helvetica,sans-serif!important;white-space:nowrap;cursor:pointer;box-shadow:none!important}
body #csvExportCard .tq-csv-download:hover{background:#15803d!important}
#csvExportCard :focus-visible{outline:3px solid #86efac;outline-offset:3px}
@media(max-width:1100px){#csvExportCard{align-items:stretch;flex-direction:column;gap:14px}#csvExportCard .tq-csv-form>div{width:auto;flex:1}}
@media(max-width:540px){#csvExportCard{padding:18px}#csvExportCard .tq-csv-form{flex-direction:column;align-items:stretch}#csvExportCard .tq-csv-form>div{width:100%}}
</style>
<!-- Shared TMU header and navigation; refresh versions when the shared files change. -->
<script src="admin-header.js?v=<?= (int) @filemtime(__DIR__.'/admin-header.js') ?>" data-tq-header="1" defer></script>
<script src="admin-nav.js?v=<?= (int) @filemtime(__DIR__.'/admin-nav.js') ?>" defer></script>
</head>

<body data-admin-role="<?= e($adminRole) ?>">

<!-- =======================================================
     SIDEBAR
======================================================= -->

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

        <a href="settings.php" class="active" aria-current="page">
            <span class="icon">⚙</span>
            Settings
        </a>
    <div class="menu-title">Account</div>
        <a href="logout.php">
            <span class="icon">⇥</span>
            Logout
        </a>

</aside>


<!-- =======================================================
     MAIN
======================================================= -->

<main class="main">


    <!-- TOPBAR -->

    <header class="topbar">

        <div class="page-heading">

            <h1>
                Settings
            </h1>

            <p>
                Manage your TRIKE-Q administrator account and system preferences.
            </p>

        </div>


        <div class="admin-top" data-tq-legacy-account>

            <div class="admin-avatar">
                <?php if ($adminProfilePictureUrl !== ""): ?>
                    <img
                        src="<?= e($adminProfilePictureUrl) ?>"
                        alt="<?= e($adminName) ?> profile picture"
                    >
                <?php else: ?>
                    <?= e($adminInitial) ?>
                <?php endif; ?>
            </div>


            <div class="admin-top-text">

                <strong>
                    <?= e($adminName) ?>
                </strong>

                <span>
                    <?= e($adminRoleLabel) ?>
                </span>

            </div>

        </div>

    </header>


    <!-- =====================================================
         CONTENT
    ====================================================== -->

    <section class="content">


        <?php if ($successMessage !== ""): ?>

            <div class="alert success">
                <?= e($successMessage) ?>
            </div>

        <?php endif; ?>


        <?php if ($errorMessage !== ""): ?>

            <div class="alert error">
                <?= e($errorMessage) ?>
            </div>

        <?php endif; ?>


        <div class="settings-toolbar">
            <button
                type="button"
                class="btn btn-primary"
                id="settingsEditButton"
                title="Edit Settings"
                aria-label="Edit Settings"
            >
                <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m16 3 5 5M4 20l4-1 13-13a2.12 2.12 0 0 0-3-3L5 16l-1 4Z"/></svg>
            </button>

            <button
                type="button"
                class="btn btn-secondary"
                id="settingsCancelButton"
                hidden
            >
                Cancel
            </button>
        </div>


        <section id="csvExportCard" class="tq-csv-card" aria-labelledby="csvExportHeading">
            <div class="tq-csv-heading"><div class="tq-csv-icon" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3v12m-5-5 5 5 5-5M5 16v5h14v-5"/></svg></div><div><h2 id="csvExportHeading">Master Excel Workbook</h2><p>Update the existing workbook with the latest records.</p><p><strong>C:\TRIKE-Q-Exports\TRIKE-Q_Master.xlsx</strong></p></div></div>
            <form method="POST" action="update-master-export.php" class="tq-csv-form" id="masterExportForm">
                <input type="hidden" name="tq_csrf" value="<?= tqEscape(tqCsrf()) ?>">
                <button type="submit" class="btn tq-csv-download"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3v12m-5-5 5 5 5-5M5 16v5h14v-5"/></svg><span>Update Master Excel</span></button>
            </form>
        </section>
        <script>document.getElementById('masterExportForm').addEventListener('submit',function(){const button=this.querySelector('button');button.disabled=true;button.querySelector('span').textContent='Updating…';});</script>

        <div class="settings-grid">


            <!-- =================================================
                 ADMIN PROFILE
            ================================================== -->

            <div class="settings-card">

                <div class="card-header">

                    <div class="card-icon">
                        👤
                    </div>

                    <div>

                        <h2>
                            Admin Profile
                        </h2>

                        <p>
                            Update the administrator information displayed throughout the system.
                        </p>

                    </div>

                </div>


                <div class="profile-box">

                    <div class="profile-avatar">
                        <?php if ($adminProfilePictureUrl !== ""): ?>
                            <img
                                src="<?= e($adminProfilePictureUrl) ?>"
                                alt="<?= e($adminName) ?> profile picture"
                            >
                        <?php else: ?>
                            <?= e($adminInitial) ?>
                        <?php endif; ?>
                    </div>


                    <div class="profile-info">

                        <strong>
                            <?= e($adminName) ?>
                        </strong>

                        <span>
                            <?= $adminEmail !== ""
                                ? e($adminEmail)
                                : "No email address configured"
                            ?>
                        </span>

                    </div>

                </div>


                <form method="POST" enctype="multipart/form-data" class="edit-panel">
<input type="hidden" name="tq_csrf" value="<?= tqEscape(tqCsrf()) ?>">

                    <input
                        type="hidden"
                        name="action"
                        value="save_profile"
                    >


                    <div class="form-grid">

                        <div class="form-group">

                            <label for="admin_name">
                                Admin Name
                            </label>

                            <input
                                type="text"
                                id="admin_name"
                                name="admin_name"
                                value="<?= e($adminName) ?>"
                                placeholder="Administrator name"
                                required
                            >

                        </div>


                        <div class="form-group profile-email-field">

                            <label for="admin_email">
                                Admin Email
                            </label>

                            <input
                                type="email"
                                id="admin_email"
                                name="admin_email"
                                value="<?= e($adminEmail) ?>"
                                placeholder="admin@example.com"
                            >

                        </div>

                        <div class="form-group full">

                            <label for="admin_profile_picture">
                                Profile Picture
                            </label>

                            <input
                                type="file"
                                id="admin_profile_picture"
                                name="admin_profile_picture"
                                accept="image/jpeg,image/png,image/webp"
                            >

                        </div>

                    </div>


                    <div class="btn-row">

                        <button
                            type="submit"
                            class="btn btn-primary"
                        >
                            Save Profile
                        </button>

                    </div>

                </form>

            </div>


            <!-- =================================================
                 ACCOUNT SECURITY
            ================================================== -->

            <div class="settings-card">

                <div class="card-header">

                    <div class="card-icon">
                        🔐
                    </div>

                    <div>

                        <h2>
                            Account Security
                        </h2>

                        <p>
                            Update login email or password in one secure form.
                        </p>

                    </div>

                </div>


                <div class="status-list view-panel">

                    <div class="status-row">
                        <span class="status-label">
                            Login Email
                        </span>

                        <span class="status-value">
                            <?= $adminEmail !== "" ? e($adminEmail) : "No email configured" ?>
                        </span>
                    </div>

                    <div class="status-row">
                        <span class="status-label">
                            Password
                        </span>

                        <span class="status-value">
                            Protected
                        </span>
                    </div>

                </div>


                <form method="POST" class="edit-panel">
<input type="hidden" name="tq_csrf" value="<?= tqEscape(tqCsrf()) ?>">

                    <input
                        type="hidden"
                        name="action"
                        value="save_security"
                    >


                    <div class="form-group">

                        <label for="security_current_password">
                            Current Password
                        </label>

                        <input
                            type="password"
                            id="security_current_password"
                            name="current_password"
                            placeholder="Required when changing email or password"
                        >

                    </div>


                    <div class="form-grid">

                        <div class="form-group">

                            <label for="security_new_email">
                                Login Email
                            </label>

                            <input
                                type="email"
                                id="security_new_email"
                                name="new_email"
                                value="<?= e($adminEmail) ?>"
                                placeholder="Login email"
                            >

                        </div>


                        <div class="form-group">

                            <label for="security_new_password">
                                New Password
                            </label>

                            <input
                                type="password"
                                id="security_new_password"
                                name="new_password"
                                placeholder="Leave blank to keep current password"
                            >

                        </div>


                        <div class="form-group">

                            <label for="security_confirm_password">
                                Confirm New Password
                            </label>

                            <input
                                type="password"
                                id="security_confirm_password"
                                name="confirm_password"
                                placeholder="Confirm new password"
                            >

                        </div>

                    </div>


                    <div class="info-note">
                        Leave password fields blank if you only want to change the login email.
                    </div>


                    <div class="btn-row">

                        <button
                            type="submit"
                            class="btn btn-primary"
                        >
                            Save Account Security
                        </button>

                    </div>

                </form>

            </div>


            <!-- =================================================
                 SYSTEM INFORMATION
            ================================================== -->

<section class="settings-card" id="systemLogoCard" aria-labelledby="systemLogoHeading">
    <div class="card-header"><div><h2 id="systemLogoHeading">System Logo</h2><p>Shared logo for the sidebar and login pages.</p></div></div>
    <div class="system-logo-layout">
        <div class="system-logo-frame"><img id="systemLogoPreview" src="system-logo.php?v=<?= time() ?>" alt="Current system logo" width="96" height="96"></div>
        <div class="system-logo-copy"><strong id="systemLogoCaption">Current system logo</strong><p>PNG, JPEG or WebP · Maximum 2 MB</p><p class="view-panel">Select Edit Settings to change the logo.</p></div>
    </div>
    <form id="systemLogoForm" method="POST" enctype="multipart/form-data" class="edit-panel">
        <input type="hidden" name="tq_csrf" value="<?= tqEscape(tqCsrf()) ?>"><input type="hidden" name="action" value="save_logo">
        <label for="systemLogo">Choose a new logo</label>
        <input id="systemLogo" type="file" name="system_logo" accept="image/png,image/jpeg,image/webp" required aria-describedby="systemLogoFileStatus">
        <p id="systemLogoFileStatus" role="status" aria-live="polite">No new image selected.</p>
        <div class="btn-row"><button class="btn btn-primary" type="submit">Save Logo</button></div>
    </form>
</section>
            <form method="POST">
<input type="hidden" name="tq_csrf" value="<?= tqEscape(tqCsrf()) ?>">

                <input
                    type="hidden"
                    name="action"
                    value="save_system_settings"
                >

            <div class="settings-card">

                <div class="card-header">

                    <div class="card-icon">
                        ▣
                    </div>

                    <div>

                        <h2>
                            System Information
                        </h2>

                        <p>
                            Basic information about the TRIKE-Q system.
                        </p>

                    </div>

                </div>


                <div class="form-grid">

                    <div class="form-group">

                        <label for="system_name">
                            System Name
                        </label>

                        <input
                            type="text"
                            id="system_name"
                            name="system_name"
                            value="<?= e($settings["system_name"]) ?>"
                            <?= $canEditSystemSettings ? "" : "readonly" ?>
                        >

                    </div>


                    <div class="form-group">

                        <label for="office_name">
                            Office Name
                        </label>

                        <input
                            type="text"
                            id="office_name"
                            name="office_name"
                            value="<?= e($settings["office_name"]) ?>"
                            <?= $canEditSystemSettings ? "" : "readonly" ?>
                        >

                    </div>

                </div>


                <div class="info-note">

                    <?= $canEditSystemSettings
                        ? "TMU administrators can update these organization settings here."
                        : "TMU administrators can update these organization settings."
                    ?>

                </div>

            </div>


            <!-- =================================================
                 OFFICE DETAILS
            ================================================== -->

            <div class="settings-card">

                <div class="card-header">

                    <div class="card-icon">
                        🏢
                    </div>

                    <div>

                        <h2>
                            Office Details
                        </h2>

                        <p>
                            Current contact information registered for the system.
                        </p>

                    </div>

                </div>


                <div class="form-group">

                    <label for="contact_number">
                        Contact Number
                    </label>

                    <input
                        type="text"
                        id="contact_number"
                        name="contact_number"
                        value="<?= e($settings["contact_number"]) ?>"
                        <?= $canEditSystemSettings ? "" : "readonly" ?>
                    >

                </div>


                <div class="form-group">

                    <label for="official_email">
                        Official Email
                    </label>

                    <input
                        type="email"
                        id="official_email"
                        name="official_email"
                        value="<?= e($settings["official_email"]) ?>"
                        <?= $canEditSystemSettings ? "" : "readonly" ?>
                    >

                </div>


                <div class="form-group">

                    <label for="office_address">
                        Office Address
                    </label>

                    <textarea
                        id="office_address"
                        name="office_address"
                        <?= $canEditSystemSettings ? "" : "readonly" ?>
                    ><?= e($settings["office_address"]) ?></textarea>

                </div>

            </div>


            <!-- =================================================
                 NOTIFICATION SETTINGS
            ================================================== -->

            <div class="settings-card">

                <div class="card-header">

                    <div class="card-icon">
                        🔔
                    </div>

                    <div>

                        <h2>
                            Notification Settings
                        </h2>

                        <p>
                            Notification preferences currently configured for TRIKE-Q.
                        </p>

                    </div>

                </div>


                <div class="option-list">


                    <div class="option-item">

                        <div class="option-text">

                            <strong>
                                Email Notifications
                            </strong>

                            <span>
                                Enable system email notifications.
                            </span>

                        </div>


                        <label class="switch">

                            <input
                                type="checkbox"
                                name="email_notifications"
                                <?= isEnabled($settings["email_notifications"]) ? "checked" : "" ?>
                                <?= $canEditSystemSettings ? "" : "disabled" ?>
                            >

                            <span class="slider"></span>

                        </label>

                    </div>


                    <div class="option-item">

                        <div class="option-text">

                            <strong>
                                Renewal Reminders
                            </strong>

                            <span>
                                Send reminders for upcoming franchise renewals.
                            </span>

                        </div>


                        <label class="switch">

                            <input
                                type="checkbox"
                                name="renewal_reminders"
                                <?= isEnabled($settings["renewal_reminders"]) ? "checked" : "" ?>
                                <?= $canEditSystemSettings ? "" : "disabled" ?>
                            >

                            <span class="slider"></span>

                        </label>

                    </div>


                    <div class="option-item">

                        <div class="option-text">

                            <strong>
                                Dues Reminders
                            </strong>

                            <span>
                                Enable reminders for outstanding dues.
                            </span>

                        </div>


                        <label class="switch">

                            <input
                                type="checkbox"
                                name="dues_reminders"
                                <?= isEnabled($settings["dues_reminders"]) ? "checked" : "" ?>
                                <?= $canEditSystemSettings ? "" : "disabled" ?>
                            >

                            <span class="slider"></span>

                        </label>

                    </div>


                    <div class="option-item">

                        <div class="option-text">

                            <strong>
                                Complaint Notifications
                            </strong>

                            <span>
                                Enable complaint and violation notifications.
                            </span>

                        </div>


                        <label class="switch">

                            <input
                                type="checkbox"
                                name="complaint_notifications"
                                <?= isEnabled($settings["complaint_notifications"]) ? "checked" : "" ?>
                                <?= $canEditSystemSettings ? "" : "disabled" ?>
                            >

                            <span class="slider"></span>

                        </label>

                    </div>

                </div>

            </div>


            <!-- =================================================
                 RENEWAL SETTINGS
            ================================================== -->

            <div class="settings-card">

                <div class="card-header">

                    <div class="card-icon">
                        ↻
                    </div>

                    <div>

                        <h2>
                            Renewal Settings
                        </h2>

                        <p>
                            Current franchise renewal reminder configuration.
                        </p>

                    </div>

                </div>


                <div class="form-grid">

                    <div class="form-group">

                        <label for="renewal_reminder_days">
                            Reminder Before Expiry
                        </label>

                        <input
                            type="number"
                            id="renewal_reminder_days"
                            name="renewal_reminder_days"
                            min="1"
                            value="<?= e($settings["renewal_reminder_days"]) ?>"
                            <?= $canEditSystemSettings ? "" : "readonly" ?>
                        >

                    </div>


                    <div class="form-group">

                        <label for="renewal_grace_days">
                            Grace Period
                        </label>

                        <input
                            type="number"
                            id="renewal_grace_days"
                            name="renewal_grace_days"
                            min="0"
                            value="<?= e($settings["renewal_grace_days"]) ?>"
                            <?= $canEditSystemSettings ? "" : "readonly" ?>
                        >

                    </div>

                </div>


                <div class="info-note">

                    <?= $canEditSystemSettings
                        ? "Adjust how early renewal reminders appear and how many grace days are shown."
                        : "TMU administrators can adjust renewal reminder configuration."
                    ?>

                </div>

            </div>


            <!-- =================================================
                 SECURITY SETTINGS
            ================================================== -->

            <div class="settings-card">

                <div class="card-header">

                    <div class="card-icon">
                        🛡
                    </div>

                    <div>

                        <h2>
                            Security
                        </h2>

                        <p>
                            Current administrator security configuration.
                        </p>

                    </div>

                </div>


                <div class="form-group">

                    <label for="session_timeout">
                        Session Timeout
                    </label>

                    <input
                        type="number"
                        id="session_timeout"
                        name="session_timeout"
                        min="5"
                        value="<?= e($settings["session_timeout"]) ?>"
                        <?= $canEditSystemSettings ? "" : "readonly" ?>
                    >

                </div>


                <div class="option-item">

                    <div class="option-text">

                        <strong>
                            Login Protection
                        </strong>

                        <span>
                            Additional protection for administrator login.
                        </span>

                    </div>


                    <label class="switch">

                        <input
                            type="checkbox"
                            name="login_protection"
                            <?= isEnabled($settings["login_protection"]) ? "checked" : "" ?>
                            <?= $canEditSystemSettings ? "" : "disabled" ?>
                        >

                        <span class="slider"></span>

                    </label>

                </div>


                <div class="security-note">

                    Never share your administrator password or login credentials with other users.

                </div>

                <?php if ($canEditSystemSettings): ?>

                    <div class="btn-row" style="margin-top:18px;">

                        <button
                            type="submit"
                            class="btn btn-primary"
                        >
                            Save System Settings
                        </button>

                    </div>

                <?php endif; ?>

            </div>

            </form>


            <!-- =================================================
                 SYSTEM STATUS
            ================================================== -->

            <div class="settings-card full">

                <div class="card-header">

                    <div class="card-icon">
                        ✓
                    </div>

                    <div>

                        <h2>
                            System Status
                        </h2>

                        <p>
                            Current status of the main TRIKE-Q services.
                        </p>

                    </div>

                </div>


                <div class="status-list">


                    <div class="status-row">

                        <span class="status-label">
                            Supabase Database
                        </span>

                        <span class="status-value">

                            <span class="status-dot"></span>

                            Connected

                        </span>

                    </div>


                    <div class="status-row">

                        <span class="status-label">
                            Authentication
                        </span>

                        <span class="status-value">

                            <span class="status-dot"></span>

                            Active

                        </span>

                    </div>


                    <div class="status-row">

                        <span class="status-label">
                            Admin Session
                        </span>

                        <span class="status-value">

                            <span class="status-dot"></span>

                            Active

                        </span>

                    </div>


                    <div class="status-row">

                        <span class="status-label">
                            TRIKE-Q System
                        </span>

                        <span class="status-value">

                            <span class="status-dot"></span>

                            Operational

                        </span>

                    </div>

                </div>

            </div>

        </div>

    </section>

</main>

<script>
(function () {
    const editButton = document.getElementById("settingsEditButton");
    const cancelButton = document.getElementById("settingsCancelButton");

    if (!editButton || !cancelButton) {
        return;
    }

    const controls = Array.from(
        document.querySelectorAll(
            ".settings-grid input:not([type='hidden']), " +
            ".settings-grid textarea, " +
            ".settings-grid select, " +
            ".settings-grid button[type='submit']"
        )
    );

    controls.forEach((control) => {
        const form = control.closest("form");
        const action = form
            ? form.querySelector("input[name='action']")
            : null;

        control.dataset.systemOnly = action && action.value === "save_system_settings"
            ? "1"
            : "0";
    });

    const makeReadOnly = (control, readOnly) => {
        const tagName = control.tagName.toLowerCase();
        const type = (control.getAttribute("type") || "").toLowerCase();

        if (type === "submit") {
            control.hidden = readOnly;
            control.disabled = readOnly;
            return;
        }

        if (type === "checkbox" || type === "file" || tagName === "select") {
            control.disabled = readOnly;
            return;
        }

        control.readOnly = readOnly;
    };

    const setEditMode = (editing) => {
        document.body.classList.toggle("settings-editing", editing);
        editButton.hidden = editing;
        cancelButton.hidden = !editing;

        controls.forEach((control) => {
            makeReadOnly(control, !editing);
        });
    };

    editButton.addEventListener("click", () => setEditMode(true));

    cancelButton.addEventListener("click", () => {
        document
            .querySelectorAll(".settings-grid form")
            .forEach((form) => form.reset());

        setEditMode(false);
    });

    setEditMode(false);
})();
</script>


<script>
(function () {
    const input = document.getElementById('systemLogo');
    const form = document.getElementById('systemLogoForm');
    const preview = document.getElementById('systemLogoPreview');
    const status = document.getElementById('systemLogoFileStatus');
    const caption = document.getElementById('systemLogoCaption');
    if (!input || !form || !preview) return;
    const original = preview.src;
    let objectUrl = null;
    function release() { if (objectUrl) URL.revokeObjectURL(objectUrl); objectUrl = null; }
    function resetPreview() { release(); preview.src = original; caption.textContent = 'Current system logo'; status.textContent = 'No new image selected.'; input.setCustomValidity(''); }
    input.addEventListener('change', function () {
        resetPreview();
        const file = input.files && input.files[0];
        if (!file) return;
        let message = '';
        if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type)) message = 'Choose a PNG, JPEG or WebP image.';
        else if (file.size > 2 * 1024 * 1024) message = 'Choose an image no larger than 2 MB.';
        if (message) { input.setCustomValidity(message); status.textContent = message; input.reportValidity(); return; }
        objectUrl = URL.createObjectURL(file);
        preview.src = objectUrl;
        caption.textContent = 'New logo preview';
        status.textContent = file.name + ' — select Save Logo to apply.';
    });
    form.addEventListener('reset', resetPreview);
    window.addEventListener('pagehide', release);
})();
</script>
</body>

</html>
