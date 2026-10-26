<?php
// Shared TMU helpers. Keep this file in the PHP project root.
require_once __DIR__ . '/config/supabase.php';
function tqEscape($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function tqRequireAdmin() {
    static $admin = null;
    if ($admin !== null) return $admin;
    $id = $_SESSION['user_id'] ?? '';
    if (empty($_SESSION['access_token']) || !is_string($id) || !preg_match('/^[a-f0-9-]{36}$/i', $id)) {
        header('Location: login.php'); exit;
    }
    $result = supabaseRequest('admin', 'GET', null, 'select=*&user_id=eq.' . rawurlencode($id));
    $http = (int)($result['status'] ?? 0);
    $data = $result['data'] ?? [];
    $row = is_array($data) ? ($data[0] ?? null) : null;
    // HTTP success and the exact signed-in account row are both mandatory.
    // Older helpers may omit the convenience success flag or return string status codes.
    if ($http >= 200 && $http < 300 && is_array($row) && ($row['user_id'] ?? '') === $id) {
        $_SESSION['admin_role'] = 'tmu_admin';
        $_SESSION['role'] = 'tmu_admin';
        $admin = $row; $admin['role'] = 'tmu_admin';
        return $admin;
    }
    $detail = is_string($result['error'] ?? null) ? trim($result['error']) : '';
    if ($detail === '' && is_array($data)) {
        foreach (['message','error_description','error','hint','details'] as $field) {
            if (is_string($data[$field] ?? null) && trim($data[$field]) !== '') { $detail = trim($data[$field]); break; }
        }
    }
    if ($http === 0) {
        $reason = 'The server could not complete the Supabase connection. Your session may still be valid.';
        if ($detail === '') $detail = 'No HTTP response was received. Check PHP cURL connectivity and TLS certificate settings.';
        http_response_code(503);
    } elseif ($http === 401) {
        $reason = 'Supabase rejected this session. Sign out, then sign in again.';
        http_response_code(401);
    } elseif ($http === 403) {
        $reason = 'Supabase denied the administrator lookup. The database permissions need checking.';
        http_response_code(403);
    } elseif ($http >= 200 && $http < 300) {
        $reason = 'No matching administrator record was returned for this signed-in account. It may be missing or hidden by database permissions.';
        http_response_code(403);
    } else {
        $reason = 'Supabase could not verify administrator access. The HTTP status and request error below identify the failure.';
        http_response_code(503);
    }
    // Never print request headers, credentials, full responses or driver records.
    $detail = preg_replace('/[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{10,}/', '[token removed]', $detail);
    $retry = rawurlencode(basename($_SERVER['SCRIPT_NAME'] ?? 'dashboard.php'));
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>TMU access check</title>';
    echo '<body style="margin:0;padding:24px;background:#f8fafc;font-family:Arial,Helvetica,sans-serif;color:#334155"><main style="max-width:650px;margin:40px auto;background:white;padding:24px;border:1px solid #e2e8f0;border-radius:12px">';
    echo '<h1 style="font-size:23px;color:#9a3412">Unable to verify TMU administrator access</h1><p>' . tqEscape($reason) . '</p>';
    echo '<p><strong>Supabase HTTP status: ' . $http . '</strong></p>';
    if ($detail !== '') echo '<p style="overflow-wrap:anywhere">' . tqEscape($detail) . '</p>';
    echo '<p><a style="color:#c2410c" href="' . tqEscape($retry) . '">Retry</a> &nbsp; <a style="color:#c2410c" href="logout.php">Sign out and sign in again</a></p></main></body></html>';
    exit;
}
function tqCsrf() {
    if (empty($_SESSION['tmu_settings_csrf'])) $_SESSION['tmu_settings_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['tmu_settings_csrf'];
}
function tqRequireCsrf() {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;
    $token = $_POST['tq_csrf'] ?? '';
    if (!is_string($token) || !hash_equals(tqCsrf(), $token)) {
        http_response_code(403); exit('Your form expired. Refresh the page and try again.');
    }
}
function tqFetchAll($table, $query) {
    $rows = []; $offset = 0;
    do {
        $result = supabaseRequest($table, 'GET', null, $query . '&limit=500&offset=' . $offset);
        if (empty($result['success']) || !is_array($result['data'])) return $result;
        $page = $result['data']; $rows = array_merge($rows, $page); $offset += count($page);
    } while (count($page) === 500);
    return ['success'=>true, 'status'=>200, 'data'=>$rows, 'error'=>null];
}
function tqStorageRequest($path, $method, $body = null, $contentType = 'application/json') {
    global $SUPABASE_URL, $SUPABASE_KEY;
    $headers = ['apikey: ' . $SUPABASE_KEY, 'Authorization: Bearer ' . ($_SESSION['access_token'] ?? ''), 'Content-Type: ' . $contentType];
    $ch = curl_init(rtrim($SUPABASE_URL, '/') . '/storage/v1/' . ltrim($path, '/'));
    if ($ch === false) return ['success'=>false,'data'=>[]];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_HTTPHEADER=>$headers, CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>30]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? json_encode($body) : $body);
    $response = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['success'=>$response !== false && $status>=200 && $status<300,'data'=>json_decode((string)$response,true) ?: [],'status'=>$status];
}
function tqSignedPhoto($bucket, $path) {
    global $SUPABASE_URL;
    if (!is_string($path) || $path === '' || strpos($path, '..') !== false || strpos($path, '://') !== false) return '';
    $encoded = implode('/', array_map('rawurlencode', explode('/', $path)));
    $r = tqStorageRequest('object/sign/' . rawurlencode($bucket) . '/' . $encoded, 'POST', ['expiresIn'=>300]);
    $url = $r['data']['signedURL'] ?? $r['data']['signedUrl'] ?? '';
    if (!$r['success'] || !is_string($url) || $url === '') return '';
    return rtrim($SUPABASE_URL, '/') . '/storage/v1/' . ltrim($url, '/');
}
function tqSaveSystemLogo($file) {
    tqRequireAdmin();
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Choose a logo image and try again.');
    $tmp = $file['tmp_name'] ?? '';
    if (!is_string($tmp) || !is_uploaded_file($tmp) || filesize($tmp)>2*1024*1024) throw new RuntimeException('Choose an image under 2 MB.');
    $info = getimagesize($tmp);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $types = ['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'];
    if (!$info || !isset($types[$mime]) || $info[0]>8192 || $info[1]>8192) throw new RuntimeException('Use a PNG, JPEG or WebP image, at most 8192 pixels per side.');
    tqLogoCacheDirectory();
    $bytes = file_get_contents($tmp);
    $name = 'logo-' . bin2hex(random_bytes(16)) . '.' . $types[$mime];
    $r = tqStorageRequest('object/system-branding/' . $name, 'POST', $bytes, $mime);
    if (!$r['success']) throw new RuntimeException('Logo upload failed. Run tmu_updates.sql and try again.');
    global $SUPABASE_URL;
    $url = rtrim($SUPABASE_URL, '/') . '/storage/v1/object/public/system-branding/' . $name;
    $save = supabaseRequest('trikeq_branding','PATCH',['logo_url'=>$url,'updated_at'=>gmdate('c')],'id=eq.1');
    if (empty($save['success']) || empty($save['data'])) throw new RuntimeException('The image uploaded but the logo setting was not saved. Check the branding table and policies.');
    tqCacheLogoBytes($bytes, $mime, $url);
}

function tqLogoCacheDirectory() {
    $dir = __DIR__ . '/assets/system-branding';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) throw new RuntimeException('Cannot create assets/system-branding. Give Apache write access to the assets folder.');
    if (!is_writable($dir)) throw new RuntimeException('The assets/system-branding folder is not writable. Give Apache write access and save the logo again.');
    return $dir;
}
function tqCacheLogoBytes($bytes, $mime, $url) {
    $types = ['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'];
    if (!isset($types[$mime])) throw new RuntimeException('Unsupported logo image type.');
    $dir = tqLogoCacheDirectory(); $name = 'logo-' . hash('sha256', $bytes) . '.' . $types[$mime];
    $target = $dir . '/' . $name;
    if (!is_file($target)) {
        $temp = tempnam($dir, 'logo-');
        if ($temp === false) throw new RuntimeException('Cannot create a logo cache file.');
        if (file_put_contents($temp, $bytes, LOCK_EX) === false || !rename($temp, $target)) { @unlink($temp); throw new RuntimeException('Cannot save the local logo image.'); }
    }
    $meta = json_encode(['file'=>$name,'mime'=>$mime,'url'=>$url], JSON_UNESCAPED_SLASHES);
    $temp = tempnam($dir, 'meta-');
    if ($temp === false) throw new RuntimeException('Cannot create the logo settings file.');
    $metaTarget = $dir . '/current.json';
    if (file_put_contents($temp, $meta, LOCK_EX) === false) { @unlink($temp); throw new RuntimeException('Cannot save the logo settings file.'); }
    // Windows does not always replace an existing target with rename().
    if (!@rename($temp, $metaTarget)) {
        if (file_put_contents($metaTarget, $meta, LOCK_EX) === false) { @unlink($temp); throw new RuntimeException('Cannot update the logo settings file.'); }
        @unlink($temp);
    }
}
function tqCachedLogo() {
    $dir = __DIR__ . '/assets/system-branding'; $metaPath = $dir . '/current.json';
    if (!is_file($metaPath)) return null;
    $data = json_decode((string)file_get_contents($metaPath), true);
    if (!is_array($data) || !preg_match('/^logo-[a-f0-9]{64}\.(png|jpg|webp)$/', $data['file'] ?? '')) return null;
    $file = $dir . '/' . $data['file'];
    if (!is_file($file) || !is_readable($file)) return null;
    $info = getimagesize($file);
    if (!$info || !in_array($info['mime'] ?? '', ['image/png','image/jpeg','image/webp'], true)) return null;
    return ['file'=>$file,'mime'=>$info['mime']];
}
