<?php
// Place beside complaints.php in trike-q-admin. Uses the existing Supabase helper.
require_once __DIR__ . '/config/supabase.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
function photoEscape($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
if (empty($_SESSION['access_token'])) { header('Location: login.php'); exit; }
$userResult = supabaseRequest('auth/v1/user');
if ($userResult['status'] === 401 && refreshSupabaseToken()) $userResult = supabaseRequest('auth/v1/user');
$userId = $userResult['data']['id'] ?? '';
if (!$userResult['success'] || !$userId) { header('Location: login.php'); exit; }
$admin = supabaseRequest('admin', 'GET', null, 'select=user_id&user_id=eq.' . rawurlencode($userId) . '&is_active=eq.true');
if (!$admin['success'] || empty($admin['data'])) { http_response_code(403); exit('Active TMU administrator access required.'); }
$id = trim((string)($_GET['id'] ?? ''));
$offset = max(0, (int)($_GET['offset'] ?? 0));
$query = 'select=id,driver_name,complaint_type,message,status,created_at,evidence_path&evidence_path=not.is.null&order=created_at.desc,id.desc&limit=50&offset=' . $offset;
if ($id !== '') {
    if (!preg_match('/^[0-9a-f-]{36}$/i', $id)) { http_response_code(400); exit('Invalid complaint.'); }
    $query = 'select=id,driver_name,complaint_type,message,status,created_at,evidence_path&id=eq.' . rawurlencode($id) . '&limit=1';
}
$result = supabaseRequest('complaints', 'GET', null, $query);
$rows = $result['success'] && is_array($result['data']) ? $result['data'] : [];
$error = $result['success'] ? '' : ($result['error'] ?? 'Unable to load complaints.');
$imageUrl = '';
if ($id !== '' && !empty($rows[0]['evidence_path'])) {
    $path = $rows[0]['evidence_path'];
    if (preg_match('/^[0-9a-f-]{36}\/[0-9a-f-]{36}\.jpg$/', $path)) {
        $url = rtrim($SUPABASE_URL, '/') . '/storage/v1/object/sign/trikeq-complaint-evidence/' . implode('/', array_map('rawurlencode', explode('/', $path)));
        $signed = trikeqSupabaseHttp($url, 'POST', ['expiresIn' => 120], $_SESSION['access_token']);
        $relative = $signed['data']['signedURL'] ?? $signed['data']['signedUrl'] ?? '';
        if ($signed['success'] && strpos($relative, '/object/sign/') === 0) $imageUrl = rtrim($SUPABASE_URL, '/') . '/storage/v1' . $relative;
        else $error = 'Unable to open the supporting photo. ' . ($signed['error'] ?? 'Refresh and try again.');
    } else $error = 'Invalid stored photo path.';
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Complaint Photos · TRIKE-Q</title>
<style>*{box-sizing:border-box}body{margin:0;background:#f8fafc;color:#0f172a;font:16px/1.6 Arial,sans-serif}header{background:#f97316;color:white;padding:16px 24px;font-size:23px;font-weight:bold}main{max-width:1000px;margin:20px auto;padding:0 16px}nav{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px}a{color:#9a3412;font-weight:600}article{background:white;padding:22px;border:1px solid #e2e8f0;border-radius:14px;margin-bottom:14px}h1{font-size:26px}h2{font-size:20px;margin:0}p{white-space:pre-wrap;overflow-wrap:anywhere}.meta{color:#475569;font-size:14px}.error{color:#991b1b;background:#fef2f2;padding:14px;border-radius:10px}img{max-width:100%;max-height:75vh;display:block;margin:18px auto;border-radius:10px}.button{display:inline-block;padding:10px 16px;border:1px solid #f97316;border-radius:8px;text-decoration:none}</style></head><body>
<header>TRIKE-Q · TMU Admin</header><main><nav><a href="dashboard.php">Dashboard</a><a href="complaints.php">Complaints</a><a href="complaint_photos.php">Complaint Photos</a></nav>
<h1>Complaint Supporting Photos</h1><p class="meta">Photos support your review; they do not automatically verify a complaint.</p>
<?php if ($error): ?><p class="error"><?= photoEscape($error) ?></p><?php endif; ?>
<?php if (!$rows && !$error): ?><article>No matching complaints with photos.</article><?php endif; ?>
<?php foreach ($rows as $row): ?><article><h2><?= photoEscape($row['complaint_type'] ?? 'Complaint') ?></h2><div class="meta"><?= photoEscape($row['driver_name'] ?? 'Driver') ?> · <?= photoEscape($row['status'] ?? '') ?> · <?= photoEscape($row['created_at'] ?? '') ?></div><p><?= photoEscape($row['message'] ?? '') ?></p>
<?php if ($id === ''): ?><a class="button" href="complaint_photos.php?id=<?= photoEscape($row['id']) ?>">View Supporting Photo</a>
<?php elseif ($imageUrl): ?><img src="<?= photoEscape($imageUrl) ?>" alt="Passenger-submitted complaint photo"><p class="meta">This image link expires after two minutes. Refresh this page to reopen it.</p><?php endif; ?></article><?php endforeach; ?>
<?php if ($id === ''): ?><nav><?php if ($offset > 0): ?><a href="?offset=<?= max(0, $offset-50) ?>">Previous</a><?php endif; ?><?php if (count($rows) === 50): ?><a href="?offset=<?= $offset+50 ?>">Next</a><?php endif; ?></nav><?php endif; ?>
</main></body></html>
