<?php
// Always return JSON, including missing-file and PHP failures.
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');
$addressResponseSent = false;
function tqAddressJson(array $data, int $status = 200): void {
    global $addressResponseSent;
    $addressResponseSent = true;
    while (ob_get_level() > 0) ob_end_clean();
    header_remove('Location');
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
register_shutdown_function(function (): void {
    global $addressResponseSent;
    if (!$addressResponseSent) {
        $last = error_get_last();
        $fatal = $last && in_array($last['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR], true);
        tqAddressJson(['error' => $fatal
            ? 'PHP stopped while loading the address service. Check the latest entry in C:/xampp/apache/logs/error.log.'
            : 'Address loading stopped before a response was returned. Check the Supabase configuration file.'], 500);
    }
});
set_error_handler(function (int $severity, string $message): bool {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity);
});
try {
    foreach (['config/supabase.php', 'includes/driver-fields.php', 'includes/psgc-addresses.json'] as $relative) {
        if (!is_file(__DIR__ . '/' . $relative)) {
            tqAddressJson(['error' => 'Missing file: ' . $relative . '. Put it in the matching project folder.'], 500);
        }
    }
    require_once __DIR__ . '/config/supabase.php';
    require_once __DIR__ . '/includes/driver-fields.php';
    if (empty($_SESSION['access_token'])) tqAddressJson(['error' => 'Your session expired. Log in to TMU again, then reload Register Driver.'], 401);
    $auth = supabaseRequest('auth/v1/user');
    if (empty($auth['success']) && function_exists('refreshSupabaseToken') && refreshSupabaseToken()) $auth = supabaseRequest('auth/v1/user');
    $uid = $auth['data']['id'] ?? '';
    if (empty($auth['success']) || !is_string($uid) || $uid === '') tqAddressJson(['error' => 'Unable to verify your login. Sign out and log in again.'], 401);
    $admin = supabaseRequest('admin', 'GET', null, http_build_query(['select'=>'user_id,is_active','user_id'=>'eq.'.$uid], '', '&', PHP_QUERY_RFC3986));
    if (empty($admin['success'])) tqAddressJson(['error' => 'Unable to read your TMU administrator record. Check the admin table permissions.'], 403);
    if (empty($admin['data'][0]['user_id']) || ($admin['data'][0]['is_active'] ?? null) !== true) tqAddressJson(['error' => 'An active TMU administrator account is required.'], 403);
    $level = tqDriverText($_GET, 'level');
    $parent = tqDriverText($_GET, 'parent');
    if (!in_array($level, ['provinces','cities','barangays'], true) || ($level !== 'provinces' && !preg_match('/^[0-9]{10}$/', $parent))) tqAddressJson(['error' => 'Choose a valid address level and parent code.'], 400);
    tqAddressJson(tqDriverGeography($level, $parent));
} catch (Throwable $exception) {
    error_log('TRIKE-Q address service: ' . $exception->getMessage());
    $message = $exception instanceof RuntimeException
        ? $exception->getMessage()
        : 'PHP could not load the address service. Check the latest entry in C:/xampp/apache/logs/error.log.';
    tqAddressJson(['error' => $message], 500);
}
