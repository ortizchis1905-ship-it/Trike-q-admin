<?php
require_once __DIR__ . '/config/supabase.php';
require_once __DIR__ . '/tmu-tools.php';
tqRequireAdmin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); header('Allow: POST'); exit('Use Update Master Excel in Settings.');
}
tqRequireCsrf();
// Fixed local paths only. No submitted commands, paths, or server keys.
$php = 'C:/xampp/php/php.exe';
$script = 'C:/TRIKE-Q-Private/daily-export.php';
$config = 'C:/TRIKE-Q-Private/export-config.php';
try {
    foreach ([$php, $script, $config] as $path) {
        if (!is_file($path)) throw new RuntimeException('Export setup is incomplete. Check the three installed export files and PHP location.');
    }
    if (!function_exists('proc_open')) throw new RuntimeException('PHP proc_open is unavailable. Enable it for this local export action.');
    set_time_limit(300);
    $pipes = [];
    $process = proc_open([$php, $script, $config, '--force'], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, 'C:/TRIKE-Q-Private', null, ['bypass_shell'=>true]);
    if (!is_resource($process)) throw new RuntimeException('Unable to start the workbook update.');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0 || strpos((string)$output, 'SUCCESS:') === false) {
        if (strpos((string)$output, 'Another export is running') !== false) throw new RuntimeException('Another workbook update is already running. Wait for it to finish.');
        if (stripos((string)$errors, 'Cannot replace master workbook') !== false) throw new RuntimeException('Close TRIKE-Q_Master.xlsx in Excel, then try again. The previous workbook was retained.');
        throw new RuntimeException('Workbook update could not be completed. Check the private export configuration, internet connection and folder permissions. The previous workbook was retained.');
    }
    $_SESSION['master_export_notice'] = 'Master Excel updated successfully: C:\\TRIKE-Q-Exports\\TRIKE-Q_Master.xlsx';
} catch (Throwable $exception) {
    $_SESSION['master_export_error'] = $exception->getMessage();
}
header('Location: settings.php', true, 303); exit;
