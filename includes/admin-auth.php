<?php
require_once __DIR__ . '/../config/supabase.php';
function tqCsrfToken() {
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}
function tqCheckCsrf() {
    return isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) && hash_equals(tqCsrfToken(), $_POST['csrf_token']);
}
function tqCurrentAdmin() {
    if (empty($_SESSION['access_token'])) return null;
    $auth = supabaseRequest('auth/v1/user');
    if (!$auth['success'] && $auth['status'] === 401 && refreshSupabaseToken()) $auth = supabaseRequest('auth/v1/user');
    if (!$auth['success'] || empty($auth['data']['id'])) return null;
    $id = $auth['data']['id'];
    $r = supabaseRequest('admin', 'GET', null, 'select=user_id,name,email,phoneNumber,role,is_active&user_id=eq.' . urlencode($id));
    $a = $r['success'] ? ($r['data'][0] ?? null) : null;
    if (!$a || $a['is_active'] !== true || !in_array($a['role'], ['super_admin','tmu_admin'], true)) return null;
    $_SESSION['user_id'] = $id;
    $a['auth_email'] = $auth['data']['email'] ?? '';
    return $a;
}
function tqRequireAdmin($superOnly = false) {
    $a = tqCurrentAdmin();
    if (!$a) {
        unset($_SESSION['access_token'], $_SESSION['refresh_token'], $_SESSION['user_id']);
        header('Location: login.php'); exit;
    }
    if ($superOnly && $a['role'] !== 'super_admin') {
        http_response_code(403); exit('Super Admin access is required.');
    }
    return $a;
}
