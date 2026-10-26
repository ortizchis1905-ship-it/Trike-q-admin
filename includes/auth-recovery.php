<?php
require_once __DIR__ . '/../config/supabase.php';
// Change this to your HTTPS project URL before deployment; no trailing slash.
$TRIKEQ_APP_URL = 'http://localhost/trike-q-admin';
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
function recoveryEscape($v) {return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');}
function recoveryCsrf() {
    if (empty($_SESSION['recovery_csrf'])) $_SESSION['recovery_csrf']=bin2hex(random_bytes(32));
    return $_SESSION['recovery_csrf'];
}
function recoveryCheckCsrf() {
    return isset($_POST['csrf']) && is_string($_POST['csrf']) && hash_equals(recoveryCsrf(), $_POST['csrf']);
}
function recoveryRequest($path,$method='GET',$data=null,$token=null) {
    global $SUPABASE_URL;
    return trikeqSupabaseHttp(rtrim($SUPABASE_URL,'/').'/auth/v1/'.$path,$method,$data,$token);
}
function recoveryStart($token) {
    if (!is_string($token) || $token==='' || strlen($token)>16000) return false;
    $r=recoveryRequest('user','GET',null,$token);
    if (!$r['success'] || empty($r['data']['id'])) return false;
    // Isolated password-setting session: this NEVER grants TMU access or creates membership.
    session_regenerate_id(true);
    $_SESSION['password_recovery']=['token'=>$token,'user_id'=>$r['data']['id'],'expires'=>time()+900];
    unset($_SESSION['pending_recovery']);
    return true;
}
function recoveryPageStart($title,$subtitle) {
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.recoveryEscape($title).' | TRIKE-Q</title><style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:#f8fafc;color:#1f2937;font-family:Arial,Helvetica,sans-serif}.card{width:100%;max-width:460px;background:white;border:1px solid #e5e7eb;border-radius:12px;padding:28px;box-shadow:0 2px 8px rgba(0,0,0,.06)}.brand{font-size:27px;font-weight:800;color:#f97316;margin-bottom:6px}.office{font-size:13px;color:#6b7280;margin-bottom:24px}h1{font-size:23px;color:#9a3412;margin:0 0 10px}p{font-size:14px;line-height:1.6;margin:0 0 16px}label{display:block;font-size:14px;font-weight:bold;margin:14px 0 6px}input{width:100%;padding:12px;border:1px solid #d1d5db;border-radius:8px;font:14px Arial;color:#1f2937}input:focus{outline:2px solid #fed7aa;border-color:#f97316}button{width:100%;margin-top:18px;padding:12px;border:0;border-radius:8px;background:#f97316;color:white;font:700 14px Arial;cursor:pointer}button:hover{background:#ea580c}button:disabled{opacity:.6;cursor:wait}a{color:#9a3412;font-size:14px}.links{margin-top:20px;text-align:center}.message{padding:12px;border:1px solid #bbf7d0;border-radius:8px;background:#f0fdf4;color:#166534;margin-bottom:16px}.error{border-color:#fecaca;background:#fef2f2;color:#991b1b}small{display:block;margin-top:8px;font-size:13px;line-height:1.5;color:#6b7280}@media(max-width:500px){body{padding:15px}.card{padding:22px}}
</style></head><body><main class="card"><div class="brand">TRIKE-Q</div><div class="office">TMU Administrator Account</div><h1>'.recoveryEscape($title).'</h1><p>'.recoveryEscape($subtitle).'</p>';
}
function recoveryPageEnd() {echo '</main></body></html>';}
