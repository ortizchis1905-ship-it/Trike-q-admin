<?php
require_once __DIR__ . '/tmu-tools.php';
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
$cache = tqCachedLogo();
if ($cache) {
    header('Content-Type: ' . $cache['mime']);
    header('Content-Length: ' . filesize($cache['file']));
    readfile($cache['file']); exit;
}
// The RPC exposes only public branding, even if another table policy blocks anon SELECT.
$r = supabaseRequest('rpc/trikeq_public_logo','POST',(object)[],null,false);
$url = !empty($r['success']) && is_string($r['data']) ? $r['data'] : '';
if ($url === '') {
    $r = supabaseRequest('trikeq_branding','GET',null,'select=logo_url&id=eq.1',false);
    $url = $r['data'][0]['logo_url'] ?? '';
}
$prefix = rtrim($SUPABASE_URL,'/') . '/storage/v1/object/public/system-branding/';
if (is_string($url) && strpos($url,$prefix)===0 && !preg_match('/[\r\n]/',$url)) {
    $bytes = ''; $ch = curl_init($url);
    if ($ch !== false) {
        curl_setopt_array($ch,[CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_WRITEFUNCTION=>function($handle,$chunk) use (&$bytes) { if(strlen($bytes)+strlen($chunk)>2097152)return 0; $bytes.=$chunk; return strlen($chunk); }]);
        $ok=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
        $info=$ok!==false && $status===200 ? @getimagesizefromstring($bytes) : false;
        if ($info && in_array($info['mime'] ?? '',['image/png','image/jpeg','image/webp'],true)) {
            try { tqCacheLogoBytes($bytes,$info['mime'],$url); } catch (RuntimeException $exception) { /* Still serve the verified public image. */ }
            header('Content-Type: '.$info['mime']); header('Content-Length: '.strlen($bytes)); echo $bytes; exit;
        }
    }
}
$fallback=__DIR__.'/assets/default-logo.png';
if (is_file($fallback)) { header('Content-Type: image/png'); header('Content-Length: '.filesize($fallback)); readfile($fallback); exit; }
http_response_code(404);
exit;
