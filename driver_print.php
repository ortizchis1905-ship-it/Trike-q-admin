<?php
$id = is_string($_GET['id'] ?? null)
    ? $_GET['id']
    : '';

if (!preg_match(
    '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
    $id
)) {
    http_response_code(400);
    exit('Invalid driver. Open Print from Driver Management.');
}

header(
    'Location: driver_view.php?id=' .
    rawurlencode($id) .
    '&print=1',
    true,
    302
);
exit;