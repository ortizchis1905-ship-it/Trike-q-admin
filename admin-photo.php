<?php
require_once __DIR__ . '/tmu-tools.php';
tqRequireAdmin();
$photoToken = $_SESSION['access_token'];
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$id = $_GET['id'] ?? ''; $kind = $_GET['kind'] ?? '';
if (!is_string($id) || !preg_match('/^[a-f0-9-]{36}$/i',$id)) { http_response_code(400); exit; }
if ($kind === 'president') {
    $r=trikeqSupabaseHttp(rtrim($SUPABASE_URL,'/').'/rest/v1/toda_president_accounts?select=profile_object_path&user_id=eq.'.rawurlencode($id),'GET',null,$photoToken);
    $path=$r['data'][0]['profile_object_path'] ?? '';
    if (!is_string($path) || strpos($path,$id.'/')!==0) { http_response_code(404); exit; }
    $bucket='president-profiles';
} elseif ($kind === 'complaint') {
    $r=trikeqSupabaseHttp(rtrim($SUPABASE_URL,'/').'/rest/v1/complaints?select=evidence_path&id=eq.'.rawurlencode($id),'GET',null,$photoToken);
    $path=$r['data'][0]['evidence_path'] ?? ''; $bucket='trikeq-complaint-evidence';
} else { http_response_code(400); exit; }
$url = !empty($r['success']) ? tqSignedPhoto($bucket,$path) : '';
header('Cache-Control: private, no-store');
if (!$url) { http_response_code(404); exit; }
header('Location: '.$url, true, 302); exit;
