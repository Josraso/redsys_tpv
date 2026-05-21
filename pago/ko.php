<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/RedsysAPI.php';
$siteName = getSetting('site_name', 'TPV');

$ord  = preg_replace('/[^A-Za-z0-9]/', '', isset($_GET['Ds_Order'])    ? $_GET['Ds_Order']    : '');
$resp = preg_replace('/[^0-9]/',       '', isset($_GET['Ds_Response']) ? $_GET['Ds_Response'] : '9999');
$txt  = RedsysAPI::getResponseText($resp);

// Buscar la URL del concepto para el boton "Volver a intentarlo"
$conceptUrl = '../index.php';
$token = null;

if ($ord) {
    $stx = db()->prepare('SELECT concept_id FROM transactions WHERE order_ref=? LIMIT 1');
    $stx->execute(array($ord));
    $txRow = $stx->fetch();
    if ($txRow && !empty($txRow['concept_id'])) {
        $stc = db()->prepare('SELECT public_token FROM concepts WHERE id=?');
        $stc->execute(array($txRow['concept_id']));
        $token = $stc->fetchColumn() ?: null;
    }
}

// Fallback: leer token guardado en sesion justo antes del redireccion a Redsys
if (!$token) {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params(0, '/; SameSite=Lax', '', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on'), true);
        session_start();
    }
    $token = isset($_SESSION['last_concept_token']) ? $_SESSION['last_concept_token'] : null;
}

if ($token) {
    $conceptUrl = '../index.php?concept=' . urlencode($token);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Pago no completado &mdash; <?php echo htmlspecialchars($siteName); ?></title>
  <style>
    *{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f4f4f6;
         min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;}
    .card{background:#fff;border-radius:16px;border:1px solid #e8e8e8;padding:40px;max-width:420px;
          width:100%;text-align:center;box-shadow:0 4px 24px rgba(0,0,0,.06);animation:fadeUp .3s ease both;}
    @keyframes fadeUp{from{opacity:0;transform:translateY(10px);}to{opacity:1;transform:translateY(0);}}
    .icon-wrap{width:64px;height:64px;background:#fff1f0;border-radius:50%;display:flex;
               align-items:center;justify-content:center;margin:0 auto 18px;font-size:28px;color:#c0392b;}
    .h1{font-size:21px;font-weight:700;color:#1a1a1a;margin-bottom:8px;letter-spacing:-.02em;}
    p{font-size:14px;color:#777;line-height:1.6;margin-bottom:10px;}
    .ebox{background:#fff1f0;border:1px solid #fcc;border-radius:8px;padding:13px 16px;
          font-size:14px;color:#c0392b;margin:16px 0;font-weight:500;}
    .ref{font-size:12px;color:#aaa;margin-top:4px;}
    .btn{display:inline-flex;align-items:center;gap:6px;margin-top:22px;padding:13px 28px;
         background:#1a1a1a;color:#fff;border-radius:10px;font-size:14px;font-weight:600;
         text-decoration:none;transition:opacity .15s,transform .1s;}
    .btn:hover{opacity:.85;}
    .btn:active{transform:scale(.97);}
  </style>
</head>
<body>
<div class="card">
  <div class="icon-wrap">&#10007;</div>
  <div class="h1">Pago no completado</div>
  <p>No se ha realizado ningun cargo en tu tarjeta.</p>
  <div class="ebox"><?php echo htmlspecialchars($txt); ?></div>
  <?php if ($ord): ?>
  <p class="ref">Referencia: <strong><?php echo htmlspecialchars($ord); ?></strong></p>
  <?php endif; ?>
  <p>Puedes intentarlo de nuevo con los mismos datos.</p>
  <a href="<?php echo htmlspecialchars($conceptUrl); ?>" class="btn">
    &larr; Volver a intentarlo
  </a>
</div>
</body>
</html>
