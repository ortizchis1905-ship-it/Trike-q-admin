<?php
require_once __DIR__ . '/includes/auth-recovery.php';

// Recovery URLs may fall back to the shared entry page. Route only after
// Supabase verifies the token and the caller can read their own president row.
function routeVerifiedPresidentRecovery() {
    global $SUPABASE_URL;
    $recovery = $_SESSION['password_recovery'] ?? null;
    if (!is_array($recovery) || empty($recovery['token']) || empty($recovery['user_id'])) return;
    $userId = $recovery['user_id'];
    if (!is_string($userId) || !preg_match('/^[a-f0-9-]{36}$/i', $userId)) return;
    $url = rtrim($SUPABASE_URL, '/') . '/rest/v1/toda_president_accounts?' . http_build_query([
        'select' => 'user_id', 'user_id' => 'eq.' . $userId, 'limit' => '1'
    ]);
    $result = trikeqSupabaseHttp($url, 'GET', null, $recovery['token']);
    if (!$result['success'] || !is_array($result['data']) ||
        ($result['data'][0]['user_id'] ?? null) !== $userId) return;
    $_SESSION['president_password_recovery'] = $recovery;
    $_SESSION['president_recovery_csrf'] = bin2hex(random_bytes(32));
    unset($_SESSION['password_recovery'], $_SESSION['pending_recovery'],
        $_SESSION['president_pending_recovery'], $_SESSION['president_password_set_notice']);
    header('Location: president_reset_password.php');
    exit;
}

$error='';$success=$_SESSION['password_set_notice'] ?? '';unset($_SESSION['password_set_notice']);
// Custom email-template links are staged first, and consumed only after the user clicks Continue.
if (isset($_GET['token_hash'])) {
    $type=$_GET['type'] ?? '';$hash=$_GET['token_hash'];
    unset($_SESSION['password_recovery'],$_SESSION['pending_recovery']);
    if (is_string($hash) && preg_match('/^[a-f0-9]{32,128}$/i',$hash) && in_array($type,['recovery','invite'],true)) {
        $_SESSION['pending_recovery']=['token_hash'=>$hash,'type'=>$type,'expires'=>time()+900];
    } else $_SESSION['password_set_notice']='Invalid link. Request a new password reset email.';
    header('Location: reset_password.php');exit;
}
if (!empty($_SESSION['password_recovery']) && $_SESSION['password_recovery']['expires']<time()) unset($_SESSION['password_recovery']);
if (!empty($_SESSION['pending_recovery']) && $_SESSION['pending_recovery']['expires']<time()) unset($_SESSION['pending_recovery']);
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action=$_POST['action'] ?? '';
    if (!recoveryCheckCsrf()) $error='Your form expired. Refresh and try again.';
    elseif($action==='verify_link') {
        $pending=$_SESSION['pending_recovery'] ?? null;
        if (!$pending) $error='This link expired. Request a new email.';
        else {
            $r=recoveryRequest('verify','POST',['token_hash'=>$pending['token_hash'],'type'=>$pending['type']]);
            if (!$r['success'] || empty($r['data']['access_token']) || !recoveryStart($r['data']['access_token'])) {
                unset($_SESSION['pending_recovery']);$error='This link is invalid, expired or already used. Request a new email.';
            } else {routeVerifiedPresidentRecovery();header('Location: reset_password.php');exit;}
        }
    }
    elseif($action==='accept_fragment') {
        // Compatibility with default Supabase ConfirmationURL emails (implicit flow).
        unset($_SESSION['password_recovery']);
        if (!in_array($_POST['type'] ?? '',['recovery','invite'],true) || !recoveryStart($_POST['access_token'] ?? '')) $error='The link could not be verified. Request a new email.';
        else {routeVerifiedPresidentRecovery();header('Location: reset_password.php');exit;}
    }
    elseif($action==='set_password') {
        $session=$_SESSION['password_recovery'] ?? null;
        $password=$_POST['password'] ?? '';$confirm=$_POST['confirm_password'] ?? '';
        if (!$session) $error='Open a fresh password reset or invitation link first.';
        elseif (!is_string($password) || strlen($password)<12 || strlen($password)>256) $error='Use a password between 12 and 256 characters.';
        elseif ($password!==$confirm) $error='The passwords do not match.';
        else {
            $r=recoveryRequest('user','PUT',['password'=>$password],$session['token']);
            if (!$r['success']) {
                $error='Unable to update the password. '.($r['error'] ?? 'Request a new link and try again.');
                if($r['status']===401) unset($_SESSION['password_recovery']);
            } else {
                recoveryRequest('logout?scope=global','POST',null,$session['token']);
                unset($_SESSION['password_recovery'],$_SESSION['pending_recovery'],$_SESSION['access_token'],$_SESSION['refresh_token'],$_SESSION['user_id']);
                session_regenerate_id(true);$_SESSION['recovery_csrf']=bin2hex(random_bytes(32));
                $_SESSION['password_set_notice']='Your password has been saved. Log in with your email and new password.';
                header('Location: reset_password.php');exit;
            }
        }
    }
}
routeVerifiedPresidentRecovery();
// Before verification, this is a shared account recovery page.
$verified = !empty($_SESSION['password_recovery']);
ob_start();
recoveryPageStart('Set Your Password', $verified
    ? 'Choose a password for your administrator account.'
    : 'Verify your email link to reset your TRIKE-Q account password.');
$openingHtml = ob_get_clean();
if (!$verified) $openingHtml = str_replace('TMU Administrator Account', 'Account Recovery', $openingHtml);
echo $openingHtml;
if($error) echo '<p class="message error" role="alert">'.recoveryEscape($error).'</p>';
if($success) echo '<p class="message" role="status">'.recoveryEscape($success).'</p>';
?>
<p id="link-status" role="status" hidden></p>
<form id="fragment-form" method="post" hidden><input type="hidden" name="csrf" value="<?= recoveryEscape(recoveryCsrf()) ?>"><input type="hidden" name="action" value="accept_fragment"><input type="hidden" name="type" id="fragment-type"><input type="hidden" name="access_token" id="fragment-token"></form>
<div id="normal-content">
<?php if(!empty($_SESSION['password_recovery'])): ?>
<form method="post"><input type="hidden" name="csrf" value="<?= recoveryEscape(recoveryCsrf()) ?>"><input type="hidden" name="action" value="set_password"><label for="password">New password</label><input id="password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="256" required><small>Use at least 12 characters.</small><label for="confirm-password">Confirm password</label><input id="confirm-password" name="confirm_password" type="password" autocomplete="new-password" minlength="12" maxlength="256" required><button type="submit">Save password</button></form>
<?php elseif(!empty($_SESSION['pending_recovery'])): ?>
<form method="post"><input type="hidden" name="csrf" value="<?= recoveryEscape(recoveryCsrf()) ?>"><input type="hidden" name="action" value="verify_link"><p>Continue to verify your email link and set your password.</p><button type="submit">Continue</button></form>
<?php elseif(!$success): ?><p>Open the newest password reset or invitation email to set your password.</p><p>Request a new reset link from the login screen where you normally sign in.</p><?php endif; ?>
<div class="links"><?php if (!empty($_SESSION['password_recovery']) || $success): ?><a href="login.php">Back to Login</a><?php else: ?><p>After verification, TODA Presidents continue to their mobile account reset page.</p><?php endif; ?></div></div>
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
 if(!['recovery','invite'].includes(type)){status.hidden=false;status.textContent='Use a password reset or invitation link. A magic login link does not complete this password setup.';return;}
 document.getElementById('normal-content').hidden=true;status.hidden=false;status.textContent='Verifying your email link…';
 document.getElementById('fragment-type').value=type;document.getElementById('fragment-token').value=token;document.getElementById('fragment-form').submit();
})();
</script>
<?php recoveryPageEnd(); ?>
