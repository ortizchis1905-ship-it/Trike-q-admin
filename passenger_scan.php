<?php
// Public passenger result page for a TRIKE-Q QR scan.
// It calls the restricted get_driver_qr_profile RPC and never exposes user_id.
require_once __DIR__ . "/config/supabase.php";

function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function validUuid($value)
{
    return is_string($value) && preg_match(
        "/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i",
        $value
    ) === 1;
}

$rawToken = trim((string)($_GET["token"] ?? ""));
if (str_starts_with(strtolower($rawToken), "trikeq:v1:")) {
    $rawToken = substr($rawToken, strlen("trikeq:v1:"));
}

$driver = null;
$errorMessage = "";

if (!validUuid($rawToken)) {
    $errorMessage = "Invalid or incomplete QR code.";
} else {
    $rpcUrl = rtrim($SUPABASE_URL, "/") . "/rest/v1/rpc/get_driver_qr_profile";
    $ch = curl_init($rpcUrl);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            "p_qr_token" => $rawToken
        ]),
        CURLOPT_HTTPHEADER => [
            "apikey: " . $SUPABASE_KEY,
            "Authorization: Bearer " . $SUPABASE_KEY,
            "Content-Type: application/json",
            "Accept: application/json"
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        $errorMessage = $curlError !== ""
            ? "Unable to contact the verification service."
            : "Unable to verify this QR code.";
    } else {
        $decoded = json_decode($response, true);
        if ($httpCode < 200 || $httpCode >= 300) {
            $errorMessage = is_array($decoded) && !empty($decoded["message"])
                ? (string)$decoded["message"]
                : "This QR code could not be verified.";
        } elseif (is_array($decoded) && !empty($decoded[0])) {
            $driver = $decoded[0];
        } else {
            $errorMessage = "This tricycle is not active or could not be found.";
        }
    }
}

$picture = is_array($driver) ? trim((string)($driver["profile_picture_url"] ?? "")) : "";
$driverName = is_array($driver) ? (string)($driver["driver_name"] ?? "") : "";
$tricycleNumber = is_array($driver) ? (string)($driver["tricycle_number"] ?? "") : "";
$todaName = is_array($driver) ? (string)($driver["toda_name"] ?? "Not assigned") : "";
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>TRIKE-Q Driver Verification</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f8fafc;color:#1f2937;font-family:Arial,Helvetica,sans-serif}.top{background:#111827;color:#fff;padding:22px 18px;text-align:center}.brand{font-size:26px;font-weight:800;color:#f97316}.subtitle{font-size:12px;color:#cbd5e1;margin-top:5px}.wrap{max-width:520px;margin:26px auto;padding:0 15px}.card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:24px;box-shadow:0 5px 18px rgba(15,23,42,.07)}.verified{text-align:center;color:#047857;font-size:13px;font-weight:700;margin-bottom:18px}.profile{display:block;width:150px;height:150px;object-fit:cover;border-radius:50%;margin:0 auto 18px;border:4px solid #fed7aa;background:#fff7ed}.initial{display:flex;align-items:center;justify-content:center;width:150px;height:150px;border-radius:50%;margin:0 auto 18px;border:4px solid #fed7aa;background:#fff7ed;color:#c2410c;font-size:54px;font-weight:800}.name{text-align:center;font-size:23px;margin:0 0 5px;color:#111827}.toda{text-align:center;color:#9a3412;font-size:14px;font-weight:700;margin-bottom:20px}.details{border-top:1px solid #e5e7eb}.row{padding:14px 0;border-bottom:1px solid #e5e7eb}.label{font-size:11px;color:#64748b;font-weight:700;margin-bottom:5px}.value{font-size:15px;overflow-wrap:anywhere}.error{text-align:center;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:18px;border-radius:10px;line-height:1.6}.notice{font-size:11px;line-height:1.6;color:#64748b;text-align:center;margin-top:20px}@media(max-width:400px){.card{padding:18px}.name{font-size:20px}}
.actions{display:grid;gap:10px;margin-top:18px}.action-button{display:block;width:100%;border:0;border-radius:9px;padding:13px 14px;font:inherit;font-size:13px;font-weight:700;text-align:center;text-decoration:none;cursor:pointer;background:#f97316;color:#fff}.action-button.secondary{background:#fff7ed;color:#9a3412;border:1px solid #fed7aa}.action-button.dark{background:#1f2937;color:#fff}.action-button.green{background:#047857;color:#fff}.action-message{display:none;margin-top:12px;padding:11px;border-radius:8px;background:#ecfdf5;color:#047857;font-size:12px;text-align:center;line-height:1.5}.mini-form{display:none;margin-top:10px;padding:14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:9px}.mini-form label{display:block;font-size:11px;font-weight:700;color:#475569;margin:9px 0 5px}.mini-form select,.mini-form textarea{width:100%;padding:9px;border:1px solid #cbd5e1;border-radius:7px;font:inherit;font-size:12px;background:#fff}.mini-form textarea{min-height:75px;resize:vertical}.mini-form button{margin-top:10px;border:0;border-radius:7px;padding:9px 12px;background:#f97316;color:#fff;font-size:12px;font-weight:700;cursor:pointer}
</style>
</head>
<body>
<header class="top"><div class="brand">TRIKE-Q</div><div class="subtitle">Driver Verification</div></header>
<main class="wrap">
<?php if ($driver !== null): ?>
<section class="card">
<div class="verified">✓ VERIFIED ACTIVE TRICYCLE</div>
<?php if ($picture !== ""): ?><img class="profile" src="<?= e($picture) ?>" alt="Profile picture of <?= e($driverName) ?>">
<?php else: ?><div class="initial" aria-label="Driver profile placeholder"><?= e(strtoupper(substr(trim($driverName) ?: "D", 0, 1))) ?></div><?php endif; ?>
<h1 class="name"><?= e($driverName) ?></h1>
<div class="toda"><?= e($todaName) ?></div>
<div class="details">
<div class="row"><div class="label">TRICYCLE NUMBER</div><div class="value"><?= e($tricycleNumber) ?></div></div>
<div class="row"><div class="label">CONTACT NUMBER</div><div class="value"><?= e($driver["phone_number"] ?? "Not available") ?></div></div>
<div class="row"><div class="label">ADDRESS</div><div class="value"><?= e($driver["address"] ?? "Not available") ?></div></div>
</div>
</section>
<section class="card actions" aria-label="Passenger actions">
<a class="action-button secondary" href="fare_monitoring.php?barangay=Estancia">🚕 View Fare Monitoring · Brgy. of Estancia</a>
<button class="action-button secondary" type="button" id="rating-toggle">★ Rate this driver</button>
<form class="mini-form" id="rating-form"><label for="rating">Rating</label><select id="rating" required><option value="">Choose a rating</option><option>5 - Excellent</option><option>4 - Good</option><option>3 - Average</option><option>2 - Poor</option><option>1 - Very poor</option></select><label for="rating-comment">Comment (optional)</label><textarea id="rating-comment" maxlength="500" placeholder="Share your experience"></textarea><button type="submit">Submit Rating</button></form>
<button class="action-button secondary" type="button" id="complaint-toggle">⚠ File a complaint</button>
<form class="mini-form" id="complaint-form"><label for="complaint-type">Complaint type</label><select id="complaint-type" required><option value="">Choose a type</option><option>Overcharging</option><option>Unsafe driving</option><option>Harassment</option><option>Refusal to transport</option><option>Other</option></select><label for="complaint-message">Details</label><textarea id="complaint-message" maxlength="1000" required placeholder="Describe what happened"></textarea><button type="submit">Submit Complaint</button></form>
<button class="action-button green" type="button" id="board-button">✓ Confirm and Board</button>
<a class="action-button dark" href="passenger_scan.php">▣ Scan Another QR</a>
</section>
<div class="action-message" id="action-message" role="status"></div>
<p class="notice">This page shows the limited information approved for passenger verification. Driver account identifiers are not displayed.</p>
<?php else: ?><section class="card"><div class="error"><?= e($errorMessage) ?></div></section><?php endif; ?>
</main>
<?php if ($driver !== null): ?><script>
(function(){
    var message=document.getElementById('action-message');
    var token=<?= json_encode($rawToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    function show(text, error){message.textContent=text;message.style.display='block';message.style.background=error?'#fef2f2':'#ecfdf5';message.style.color=error?'#991b1b':'#047857';}
    async function send(action, data, button){
        button.disabled=true;
        try{
            var response=await fetch('passenger_action.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(Object.assign({action:action,token:token},data||{}))});
            var result=await response.json();
            if(!response.ok || !result.success){throw new Error(result.message||'The action could not be saved.');}
            show(result.message,false);
            return true;
        }catch(error){show(error.message||'The action could not be saved.',true);button.disabled=false;return false;}
    }
    document.getElementById('rating-toggle').addEventListener('click',function(){document.getElementById('rating-form').style.display='block';document.getElementById('complaint-form').style.display='none';});
    document.getElementById('complaint-toggle').addEventListener('click',function(){document.getElementById('complaint-form').style.display='block';document.getElementById('rating-form').style.display='none';});
    document.getElementById('rating-form').addEventListener('submit',async function(e){e.preventDefault();var form=this;var button=form.querySelector('button');var value=document.getElementById('rating').value;if(!value){show('Choose a rating first.',true);return;}if(await send('rating',{rating:Number(value.split(' ')[0]),comment:document.getElementById('rating-comment').value},button)){form.style.display='none';}});
    document.getElementById('complaint-form').addEventListener('submit',async function(e){e.preventDefault();var form=this;var button=form.querySelector('button');if(await send('complaint',{type:document.getElementById('complaint-type').value,message:document.getElementById('complaint-message').value},button)){form.style.display='none';}});
    document.getElementById('board-button').addEventListener('click',async function(){var button=this;if(await send('boarding',{},button)){button.textContent='✓ Boarding Confirmed';}});
}());
</script><?php endif; ?>
</body>
</html>
