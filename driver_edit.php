<?php
// Compatibility entry for existing edit links and the profile upload redirect.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $_GET['edit'] = '1';
    require __DIR__ . '/driver_view.php';
    exit;
}
$id = is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
    http_response_code(400); exit('Invalid driver.');
}
$query = ['id'=>$id,'edit'=>'1'];
foreach (['photo_saved','photo_warning','saved'] as $flag) {
    if (isset($_GET[$flag])) $query[$flag] = '1';
}
header('Location: driver_view.php?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986), true, 302);
exit;
