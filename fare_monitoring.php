<?php
$barangay = trim((string)($_GET["barangay"] ?? "Estancia"));
if ($barangay === "") {
    $barangay = "Estancia";
}
function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Fare Monitoring | TRIKE-Q</title>
<style>*{box-sizing:border-box}body{margin:0;background:#f8fafc;color:#1f2937;font-family:Arial,Helvetica,sans-serif}.top{background:#111827;color:#fff;padding:22px 18px;text-align:center}.brand{font-size:26px;font-weight:800;color:#f97316}.subtitle{font-size:12px;color:#cbd5e1;margin-top:5px}.wrap{max-width:620px;margin:26px auto;padding:0 15px}.card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:24px;box-shadow:0 5px 18px rgba(15,23,42,.07)}h1{font-size:22px;text-align:center;margin:0 0 8px}.location{text-align:center;color:#9a3412;font-weight:700;font-size:14px;margin-bottom:22px}.fare-table{width:100%;border-collapse:collapse;font-size:14px}.fare-table th{background:#fff7ed;color:#9a3412;text-align:left;font-size:12px;padding:11px 9px;border-bottom:2px solid #fed7aa}.fare-table td{padding:11px 9px;border-bottom:1px solid #e5e7eb}.fare-table td:last-child{text-align:right;color:#ea580c;font-weight:800;white-space:nowrap}.notice{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;border-radius:9px;padding:14px;font-size:12px;line-height:1.6;text-align:center;margin-top:18px}.back{display:block;text-align:center;margin-top:20px;color:#9a3412;text-decoration:none;font-size:13px}</style>
</head>
<body><header class="top"><div class="brand">TRIKE-Q</div><div class="subtitle">Fare Transportation Monitoring</div></header>
<main class="wrap"><section class="card"><h1>Fare Monitoring</h1><div class="location">To: NISU Main / NISU West Campus / Market<br>Barangay of <?= e($barangay) ?></div>
<table class="fare-table"><thead><tr><th>Destination</th><th>Fare</th></tr></thead><tbody>
<tr><td>Pa-on</td><td>₱15</td></tr><tr><td>Daculan, Tubungan</td><td>₱25</td></tr><tr><td>Daculan</td><td>₱20</td></tr><tr><td>Gogo Purok 1, 6, 2</td><td>₱25</td></tr><tr><td>Gogo Purok 4 &amp; 4</td><td>₱30</td></tr><tr><td>Tacbuyan</td><td>₱20</td></tr><tr><td>Bulaquena</td><td>₱15</td></tr><tr><td>Bayuyuan Purok 1, 2 &amp; 3</td><td>₱20</td></tr><tr><td>Bayuyuan Purok 4 (Tubungan)</td><td>₱25</td></tr><tr><td>Pani-an</td><td>₱25</td></tr><tr><td>Malbog</td><td>₱35</td></tr><tr><td>Sta. Ana</td><td>₱40</td></tr><tr><td>Lonoy</td><td>₱25</td></tr><tr><td>Lumbia</td><td>₱25</td></tr><tr><td>Calapdan</td><td>₱25</td></tr><tr><td>Jolog</td><td>₱35</td></tr><tr><td>Tanza</td><td>₱30</td></tr><tr><td>Daan Banwa</td><td>₱20</td></tr><tr><td>Botongon</td><td>₱15</td></tr><tr><td>Cano-an</td><td>₱20</td></tr><tr><td>Tabuan</td><td>₱20</td></tr><tr><td>San Roque</td><td>₱30</td></tr><tr><td>Poblacion Zone 1, Zone 4, Zone III</td><td>₱15</td></tr><tr><td>Balasan</td><td>₱40</td></tr>
</tbody></table><div class="notice">Fare schedule transcribed from the provided Barangay Fare Transportation Monitoring image.</div><a class="back" href="javascript:history.back()">← Back to driver verification</a></section></main>
</body></html>
