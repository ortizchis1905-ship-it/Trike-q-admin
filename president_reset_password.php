<?php
require_once __DIR__ . '/config/supabase.php';
// Change this to your HTTPS project URL before deployment; no trailing slash.

header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
function presidentRecoveryEscape($v) {return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');}
function presidentRecoveryCsrf() {
    if (empty($_SESSION['president_recovery_csrf'])) $_SESSION['president_recovery_csrf']=bin2hex(random_bytes(32));
    return $_SESSION['president_recovery_csrf'];
}
function presidentRecoveryCheckCsrf() {
    return isset($_POST['csrf']) && is_string($_POST['csrf']) && hash_equals(presidentRecoveryCsrf(), $_POST['csrf']);
}
function presidentRecoveryRequest($path,$method='GET',$data=null,$token=null) {
    global $SUPABASE_URL;
    return trikeqSupabaseHttp(rtrim($SUPABASE_URL,'/').'/auth/v1/'.$path,$method,$data,$token);
}
function presidentRecoveryStart($token) {
    if (!is_string($token) || $token==='' || strlen($token)>16000) return false;
    $r=presidentRecoveryRequest('user','GET',null,$token);
    if (!$r['success'] || empty($r['data']['id'])) return false;
    // Isolated password-setting session: this NEVER grants TMU access or creates membership.
    session_regenerate_id(true);
    $_SESSION['president_password_recovery']=['token'=>$token,'user_id'=>$r['data']['id'],'expires'=>time()+900];
    unset($_SESSION['president_pending_recovery']);
    return true;
}
function presidentRecoveryPageStart($title,$subtitle) {
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.presidentRecoveryEscape($title).' | TRIKE-Q</title><style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:#f8fafc;color:#1f2937;font-family:Arial,Helvetica,sans-serif}.card{width:100%;max-width:460px;background:white;border:1px solid #e5e7eb;border-radius:12px;padding:28px;box-shadow:0 2px 8px rgba(0,0,0,.06)}.brand{font-size:27px;font-weight:800;color:#f97316;margin-bottom:6px}.office{font-size:13px;color:#6b7280;margin-bottom:24px}h1{font-size:23px;color:#9a3412;margin:0 0 10px}p{font-size:14px;line-height:1.6;margin:0 0 16px}label{display:block;font-size:14px;font-weight:bold;margin:14px 0 6px}input{width:100%;padding:12px;border:1px solid #d1d5db;border-radius:8px;font:14px Arial;color:#1f2937}input:focus{outline:2px solid #fed7aa;border-color:#f97316}button{width:100%;margin-top:18px;padding:12px;border:0;border-radius:8px;background:#f97316;color:white;font:700 14px Arial;cursor:pointer}button:hover{background:#ea580c}button:disabled{opacity:.6;cursor:wait}a{color:#9a3412;font-size:14px}.links{margin-top:20px;text-align:center}.message{padding:12px;border:1px solid #bbf7d0;border-radius:8px;background:#f0fdf4;color:#166534;margin-bottom:16px}.error{border-color:#fecaca;background:#fef2f2;color:#991b1b}small{display:block;margin-top:8px;font-size:13px;line-height:1.5;color:#6b7280}@media(max-width:500px){body{padding:15px}.card{padding:22px}}
</style></head><body><main class="card"><div class="brand">TRIKE-Q</div><div class="office">TODA President Account</div><h1>'.presidentRecoveryEscape($title).'</h1><p>'.presidentRecoveryEscape($subtitle).'</p>';
}
function presidentRecoveryPageEnd() {echo '</main></body></html>';}


$error='';$success=$_SESSION['president_password_set_notice'] ?? '';unset($_SESSION['president_password_set_notice']);
// Custom email-template links are staged first, and consumed only after the user clicks Continue.
if (isset($_GET['token_hash'])) {
    $type=$_GET['type'] ?? '';$hash=$_GET['token_hash'];
    unset($_SESSION['president_password_recovery'],$_SESSION['president_pending_recovery']);
    if (is_string($hash) && preg_match('/^[a-f0-9]{32,128}$/i',$hash) && in_array($type,['recovery'],true)) {
        $_SESSION['president_pending_recovery']=['token_hash'=>$hash,'type'=>$type,'expires'=>time()+900];
    } else $_SESSION['president_password_set_notice']='Invalid link. Request a new password reset email.';
    header('Location: president_reset_password.php');exit;
}
if (!empty($_SESSION['president_password_recovery']) && $_SESSION['president_password_recovery']['expires']<time()) unset($_SESSION['president_password_recovery']);
if (!empty($_SESSION['president_pending_recovery']) && $_SESSION['president_pending_recovery']['expires']<time()) unset($_SESSION['president_pending_recovery']);
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action=$_POST['action'] ?? '';
    if (!presidentRecoveryCheckCsrf()) $error='Your form expired. Refresh and try again.';
    elseif($action==='verify_link') {
        $pending=$_SESSION['president_pending_recovery'] ?? null;
        if (!$pending) $error='This link expired. Request a new email.';
        else {
            $r=presidentRecoveryRequest('verify','POST',['token_hash'=>$pending['token_hash'],'type'=>$pending['type']]);
            if (!$r['success'] || empty($r['data']['access_token']) || !presidentRecoveryStart($r['data']['access_token'])) {
                unset($_SESSION['president_pending_recovery']);$error='This link is invalid, expired or already used. Request a new email.';
            } else {header('Location: president_reset_password.php');exit;}
        }
    }
    elseif($action==='accept_fragment') {
        // Compatibility with default Supabase ConfirmationURL emails (implicit flow).
        unset($_SESSION['president_password_recovery']);
        if (!in_array($_POST['type'] ?? '',['recovery'],true) || !presidentRecoveryStart($_POST['access_token'] ?? '')) $error='The link could not be verified. Request a new email.';
        else {header('Location: president_reset_password.php');exit;}
    }
    elseif($action==='set_password') {
        $session=$_SESSION['president_password_recovery'] ?? null;
        $password=$_POST['password'] ?? '';$confirm=$_POST['confirm_password'] ?? '';
        if (!$session) $error='Open a fresh password reset link first.';
        elseif (!is_string($password) || strlen($password)<12 || strlen($password)>256) $error='Use a password between 12 and 256 characters.';
        elseif ($password!==$confirm) $error='The passwords do not match.';
        else {
            $r=presidentRecoveryRequest('user','PUT',['password'=>$password],$session['token']);
            if (!$r['success']) {
                $error='Unable to update the password. '.($r['error'] ?? 'Request a new link and try again.');
                if($r['status']===401) unset($_SESSION['president_password_recovery']);
            } else {
                presidentRecoveryRequest('logout?scope=global','POST',null,$session['token']);
                unset($_SESSION['president_password_recovery'],$_SESSION['president_pending_recovery']);
                session_regenerate_id(true);$_SESSION['president_recovery_csrf']=bin2hex(random_bytes(32));
                $_SESSION['president_password_set_notice']='Your password has been saved. Log in with your email and new password.';
                header('Location: president_reset_password.php');exit;
            }
        }
    }
}
presidentRecoveryPageStart('Set Your Password','Choose a new password for your TODA President account.');
if($error) echo '<p class="message error" role="alert">'.presidentRecoveryEscape($error).'</p>';
if($success) echo '<p class="message" role="status">'.presidentRecoveryEscape($success).'</p>';
?>
<p id="link-status" role="status" hidden></p>
<form id="fragment-form" method="post" hidden><input type="hidden" name="csrf" value="<?= presidentRecoveryEscape(presidentRecoveryCsrf()) ?>"><input type="hidden" name="action" value="accept_fragment"><input type="hidden" name="type" id="fragment-type"><input type="hidden" name="access_token" id="fragment-token"></form>
<div id="normal-content">
<?php if(!empty($_SESSION['president_password_recovery'])): ?>
<form method="post"><input type="hidden" name="csrf" value="<?= presidentRecoveryEscape(presidentRecoveryCsrf()) ?>"><input type="hidden" name="action" value="set_password"><label for="password">New password</label><input id="password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="256" required><small>Use at least 12 characters.</small><label for="confirm-password">Confirm password</label><input id="confirm-password" name="confirm_password" type="password" autocomplete="new-password" minlength="12" maxlength="256" required><button type="submit">Save password</button></form>
<?php elseif(!empty($_SESSION['president_pending_recovery'])): ?>
<form method="post"><input type="hidden" name="csrf" value="<?= presidentRecoveryEscape(presidentRecoveryCsrf()) ?>"><input type="hidden" name="action" value="verify_link"><p>Continue to verify your email link and set your password.</p><button type="submit">Continue</button></form>
<?php elseif(!$success): ?><p>Open the newest password reset email to set your password.</p><p>To request another link, open TRIKE-Q, choose TODA President Login, then Forgot Password.</p><?php endif; ?>
<div class="links"><p>Return to the TRIKE-Q mobile app and log in as TODA President.</p></div></div>
<script>
(()=>{
 const params=new URLSearchParams(location.hash.slice(1));
 if(!location.hash && !location.search) return;
 // Remove credentials and link errors from the visible URL before submitting anything.
 history.replaceState(null,'',location.pathname);
 const token=params.get('access_token'),type=params.get('type');
 const status=document.getElementById('link-status');
 if(params.has('error') || params.has('error_description')) {status.hidden=false;status.textContent='This email link is invalid or expired. Request a new reset link.';return;}
 if(!token) return;
 if(!['recovery'].includes(type)){status.hidden=false;status.textContent='Use a password reset link. A magic login link does not complete this password setup.';return;}
 document.getElementById('normal-content').hidden=true;status.hidden=false;status.textContent='Verifying your email link…';
 document.getElementById('fragment-type').value=type;document.getElementById('fragment-token').value=token;document.getElementById('fragment-form').submit();
})();
</script>
<?php presidentRecoveryPageEnd(); ?>
