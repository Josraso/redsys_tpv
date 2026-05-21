<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/RedsysAPI.php';
$siteName = getSetting('site_name','TPV');
$ord  = preg_replace('/[^A-Za-z0-9]/','',isset($_GET['Ds_Order'])?$_GET['Ds_Order']:'');
$resp = preg_replace('/[^0-9]/','',isset($_GET['Ds_Response'])?$_GET['Ds_Response']:'9999');
$txt  = RedsysAPI::getResponseText($resp);
?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pago no completado &mdash; <?php echo htmlspecialchars($siteName); ?></title>
<style>*{box-sizing:border-box;margin:0;padding:0;}body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f4f4f6;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;}
.card{background:#fff;border-radius:12px;border:1px solid #e8e8e8;padding:40px;max-width:420px;width:100%;text-align:center;}
.icon{font-size:48px;margin-bottom:14px;}.h1{font-size:21px;font-weight:700;color:#c0392b;margin-bottom:10px;}
p{font-size:14px;color:#555;line-height:1.6;margin-bottom:10px;}
.ebox{background:#fff1f0;border-radius:8px;padding:13px 16px;font-size:14px;color:#c0392b;margin:14px 0;}
.btn{display:inline-block;margin-top:22px;padding:12px 28px;background:#1a1a1a;color:#fff;border-radius:8px;font-size:14px;font-weight:600;text-decoration:none;}</style>
</head><body><div class="card">
  <div class="icon">&#10007;</div>
  <div class="h1">Pago no completado</div>
  <p>No se ha realizado ningun cargo.</p>
  <div class="ebox"><?php echo htmlspecialchars($txt); ?></div>
  <?php if($ord): ?><p style="font-size:12px;color:#aaa;">Ref: <?php echo htmlspecialchars($ord); ?></p><?php endif; ?>
  <p style="margin-top:12px;">Puedes intentarlo de nuevo.</p>
  <a href="../index.php" class="btn">Volver a intentarlo</a>
</div></body></html>
