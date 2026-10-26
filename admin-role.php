<?php
require_once __DIR__ . '/tmu-tools.php';
$admin=tqRequireAdmin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
echo json_encode(['success'=>true,'role'=>'tmu_admin','label'=>'TMU Administrator','name'=>$admin['name'] ?? 'TMU Admin','profile_picture_url'=>$admin['profile_picture_url'] ?? '', 'admin'=>$admin],JSON_UNESCAPED_SLASHES);
