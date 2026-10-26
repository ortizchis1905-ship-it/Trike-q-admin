<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/config/supabase.php";


require_once __DIR__ . '/includes/admin-shell.php';
function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function validUuid($value)
{
    return is_string($value) && preg_match(
        "/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i",
        $value
    ) === 1;
}

function queryString($values)
{
    return http_build_query($values, "", "&", PHP_QUERY_RFC3986);
}

function pageError($status, $message)
{
    http_response_code($status);
    echo "<!doctype html><html lang=\"en\"><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>Profile Upload</title>";
    echo "<body style=\"font-family:Arial;padding:30px\"><h1>Profile Upload</h1><p>" . e($message) . "</p><a href=\"drivers.php\">Back to Driver Management</a></body></html>";
    exit;
}

if (empty($_SESSION["access_token"]) || !validUuid($_SESSION["user_id"] ?? null)) {
    header("Location: login.php");
    exit;
}

$userId = $_SESSION["user_id"];
$adminResult = supabaseRequest(
    "admin",
    "GET",
    null,
    queryString([
        "select" => "user_id,name",
        "user_id" => "eq." . $userId
    ])
);

if (empty($adminResult["success"])) {
    pageError(503, "Unable to verify admin access. Please sign in again.");
}

$admin = $adminResult["data"][0] ?? null;
if (!is_array($admin) || ($admin["user_id"] ?? "") !== $userId) {
    pageError(403, "Only TMU administrators can upload driver profile pictures here.");
}

$driverId = $_GET["id"] ?? $_POST["driver_id"] ?? "";
if (!validUuid($driverId)) {
    pageError(400, "Invalid driver record. Open this page from Driver Management.");
}

$driverResult = supabaseRequest(
    "drivers",
    "GET",
    null,
    queryString([
        "select" => "id,name,tricycle_number,plate_number,profile_picture_url",
        "id" => "eq." . $driverId
    ])
);

$driver = $driverResult["data"][0] ?? null;
if (empty($driverResult["success"]) || !is_array($driver)) {
    pageError(404, "Driver record was not found.");
}

$message = "";
$error = "";
if (empty($_SESSION['driver_photo_csrf'])) $_SESSION['driver_photo_csrf'] = bin2hex(random_bytes(32));

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!hash_equals($_SESSION['driver_photo_csrf'], (string)($_POST['photo_csrf'] ?? ''))) {
        $error = 'Form expired. Refresh and try again.';
    } elseif (!isset($_FILES["profile_picture"]) || !is_array($_FILES["profile_picture"])) {
        $error = "Please select an image.";
    } else {
        $file = $_FILES["profile_picture"];
        $uploadError = (int)($file["error"] ?? UPLOAD_ERR_NO_FILE);
        $fileSize = (int)($file["size"] ?? 0);
        $tmpName = $file["tmp_name"] ?? "";

        if ($uploadError !== UPLOAD_ERR_OK) {
            $error = "The image upload failed. Please choose the image again.";
        } elseif ($fileSize <= 0 || $fileSize > 5 * 1024 * 1024) {
            $error = "The profile picture must be smaller than 5 MB.";
        } elseif (!is_uploaded_file($tmpName)) {
            $error = "The uploaded image could not be verified.";
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($tmpName);
            $mimeToExtension = [
                "image/jpeg" => "jpg",
                "image/png" => "png",
                "image/webp" => "webp"
            ];

            if (!isset($mimeToExtension[$mimeType])) {
                $error = "Only JPG, PNG, and WEBP images are allowed.";
            } else {
                $extension = $mimeToExtension[$mimeType];
                $objectPath = $driverId . "/profile-" . bin2hex(random_bytes(12)) . "." . $extension;
                $storageUrl = rtrim($SUPABASE_URL, "/") . "/storage/v1/object/driver-profiles/" . $objectPath;
                $imageBytes = file_get_contents($tmpName);

                $ch = curl_init($storageUrl);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CUSTOMREQUEST => "POST",
                    CURLOPT_POSTFIELDS => $imageBytes,
                    CURLOPT_HTTPHEADER => [
                        "apikey: " . $SUPABASE_KEY,
                        "Authorization: Bearer " . trim($_SESSION["access_token"]),
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
                    $error = $storageError !== ""
                        ? $storageError
                        : "Storage upload failed. HTTP status: " . $storageStatus;
                } else {
                    $publicUrl = rtrim($SUPABASE_URL, "/") . "/storage/v1/object/public/driver-profiles/" . $objectPath;
                    $saveResult = supabaseRequest(
                        "drivers",
                        "PATCH",
                        ["profile_picture_url" => $publicUrl],
                        "id=eq." . $driverId
                    );

                    $saved = $saveResult["data"][0] ?? null;
                    if (empty($saveResult["success"]) || !is_array($saved) || ($saved["id"] ?? "") !== $driverId) {
                        $error = $saveResult["error"] ?? "The image uploaded, but the driver record was not updated.";
                    } else {
                        $driver["profile_picture_url"] = $publicUrl;
                        $message = "Driver profile picture uploaded successfully.";
                        if (($_POST['return_to_edit'] ?? '') === '1') {
                            header('Location: driver_edit.php?id='.urlencode($driverId).'&photo_saved=1');
                            exit;
                        }
                    }
                }
            }
        }
    }
}

$adminName = trim((string)($admin["name"] ?? "")) ?: "TMU Admin";
$currentPicture = trim((string)($driver["profile_picture_url"] ?? ""));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Upload Driver Profile | TRIKE-Q</title>
<style>
*{box-sizing:border-box}body{margin:0;font-family:Arial,Helvetica,sans-serif;background:#f8fafc;color:#1f2937}.sidebar{position:fixed;inset:0 auto 0 0;width:250px;background:#111827;color:#fff;padding:24px 16px}.logo{font-size:25px;font-weight:800;color:#f97316}.subtitle{font-size:11px;color:#94a3b8;margin-top:5px}.menu-title{font-size:10px;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;margin:25px 12px 8px}.sidebar a{display:block;color:#e2e8f0;text-decoration:none;padding:12px;border-radius:8px;font-size:14px}.sidebar a:hover{background:#253047}.main{margin-left:250px}.topbar{padding:22px 30px;background:#fff;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between}.content{padding:28px 30px;max-width:850px}.card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:25px}.muted{font-size:12px;color:#64748b;line-height:1.6}.alert{padding:13px 16px;border-radius:9px;margin-bottom:18px;font-size:13px}.success{background:#ecfdf5;color:#047857}.error{background:#fef2f2;color:#b91c1c}.preview{width:180px;height:180px;object-fit:cover;border-radius:12px;border:1px solid #e2e8f0;background:#f8fafc;margin:18px 0}.field{margin:18px 0}.field label{display:block;font-size:12px;font-weight:700;margin-bottom:7px}input[type=file]{width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:8px;background:white}button{border:0;border-radius:8px;padding:11px 16px;background:#f97316;color:#fff;font-weight:700;cursor:pointer}.back{display:inline-block;margin-bottom:18px;color:#9a3412;text-decoration:none;font-size:13px}@media(max-width:600px){.sidebar{position:static;width:100%}.main{margin-left:0}.topbar,.content{padding:20px}.card{padding:19px}}
</style>
<link rel="stylesheet" href="assets/css/admin-theme.css">
</head>
<body>
<?php tqAdminSidebar('drivers', $adminName); ?>
<main class="main"><?php tqAdminTopbar('Upload Driver Profile Picture', 'Upload a picture for the registered driver.', $adminName); ?><section class="content"><a class="back" href="drivers.php">← Back to Driver Management</a>
<?php if ($message !== ""): ?><div class="alert success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ""): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
<div class="card"><h2><?= e($driver["name"]) ?></h2><p class="muted">Tricycle number: <?= e($driver["tricycle_number"]) ?> · Plate number: <?= e($driver["plate_number"]) ?></p>
<?php if ($currentPicture !== ""): ?><img class="preview" src="<?= e($currentPicture) ?>" alt="Current profile picture"><?php endif; ?>
<form method="POST" enctype="multipart/form-data"><input type="hidden" name="photo_csrf" value="<?= e($_SESSION['driver_photo_csrf']) ?>"><input type="hidden" name="driver_id" value="<?= e($driverId) ?>"><div class="field"><label for="profile_picture">Profile picture</label><input id="profile_picture" name="profile_picture" type="file" accept="image/jpeg,image/png,image/webp" required><p class="muted">JPG, PNG, or WEBP. Maximum size: 5 MB.</p></div><button type="submit">Upload Profile Picture</button></form></div></section></main>
</body></html>
