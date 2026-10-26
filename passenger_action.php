<?php
// Same-origin endpoint for passenger rating, complaint, and boarding actions.
require_once __DIR__ . "/config/supabase.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");

function responseJson($status, $data)
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    responseJson(405, ["success" => false, "message" => "POST is required."]);
}

$raw = file_get_contents("php://input");
$input = json_decode($raw ?: "", true);
if (!is_array($input)) {
    responseJson(400, ["success" => false, "message" => "Invalid request data."]);
}

$action = is_string($input["action"] ?? null) ? trim($input["action"]) : "";
$token = is_string($input["token"] ?? null) ? trim($input["token"]) : "";
if (str_starts_with(strtolower($token), "trikeq:v1:")) {
    $token = substr($token, strlen("trikeq:v1:"));
}

if (!preg_match(
    "/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i",
    $token
)) {
    responseJson(400, ["success" => false, "message" => "Invalid QR token."]);
}

$rpcName = "";
$payload = ["p_qr_token" => $token];

if ($action === "rating") {
    $rating = filter_var($input["rating"] ?? null, FILTER_VALIDATE_INT);
    $comment = is_string($input["comment"] ?? null) ? trim($input["comment"]) : "";
    if ($rating === false || $rating < 1 || $rating > 5) {
        responseJson(400, ["success" => false, "message" => "Choose a rating from 1 to 5."]);
    }
    if (strlen($comment) > 500) {
        responseJson(400, ["success" => false, "message" => "Rating comment is too long."]);
    }
    $rpcName = "submit_driver_rating";
    $payload["p_rating"] = $rating;
    $payload["p_comment"] = $comment;
} elseif ($action === "complaint") {
    $type = is_string($input["type"] ?? null) ? trim($input["type"]) : "";
    $message = is_string($input["message"] ?? null) ? trim($input["message"]) : "";
    if ($type === "" || $message === "") {
        responseJson(400, ["success" => false, "message" => "Complaint type and details are required."]);
    }
    if (strlen($type) > 100 || strlen($message) > 2000) {
        responseJson(400, ["success" => false, "message" => "Complaint details are too long."]);
    }
    $rpcName = "submit_driver_complaint";
    $payload["p_complaint_type"] = $type;
    $payload["p_message"] = $message;
} elseif ($action === "boarding") {
    $rpcName = "confirm_driver_boarding";
} else {
    responseJson(400, ["success" => false, "message" => "Unsupported passenger action."]);
}

global $SUPABASE_URL, $SUPABASE_KEY;
$url = rtrim($SUPABASE_URL, "/") . "/rest/v1/rpc/" . $rpcName;
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => [
        "apikey: " . $SUPABASE_KEY,
        "Authorization: Bearer " . $SUPABASE_KEY,
        "Content-Type: application/json",
        "Accept: application/json"
    ],
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 20
]);
$result = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($result === false) {
    responseJson(503, ["success" => false, "message" => "Verification service is unavailable."]);
}

$decoded = json_decode($result, true);
if ($httpCode < 200 || $httpCode >= 300) {
    $message = is_array($decoded) && is_string($decoded["message"] ?? null)
        ? $decoded["message"]
        : "The passenger action could not be saved.";
    responseJson($httpCode >= 400 && $httpCode < 500 ? $httpCode : 502, [
        "success" => false,
        "message" => $message
    ]);
}

responseJson(200, [
    "success" => true,
    "message" => $action === "rating"
        ? "Rating submitted successfully."
        : ($action === "complaint"
            ? "Complaint submitted successfully."
            : "Boarding confirmed successfully.")
]);
