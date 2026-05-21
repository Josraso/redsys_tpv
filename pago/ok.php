<?php
require_once __DIR__ . '/../lib/db.php';
$siteName = getSetting('site_name', 'TPV');

$ord = preg_replace('/[^A-Za-z0-9]/', '', isset($_GET['Ds_Order']) ? $_GET['Ds_Order'] : '');

// Buscar transaccion SIN filtrar por status: puede llegar antes que notify.php (race condition)
$tx = null;
if ($ord) {
    $st = db()->prepare('SELECT * FROM transactions WHERE order_ref=? LIMIT 1');
    $st->execute(array($ord));
    $tx = $st->fetch() ?: null;
}

// Buscar concepto para: url_ok_custom, public_token (para el boton "Volver")
$conceptUrl = '../index.php';
if ($tx && !empty($tx['concept_id'])) {
    $stc = db()->prepare('SELECT url_ok_custom, public_token FROM concepts WHERE id=?');
    $stc->execute(array($tx['concept_id']));
    $concept = $stc->fetch() ?: null;
    if ($concept) {
        if (!empty($concept['public_token'])) {
            $conceptUrl = '../index.php?concept=' . urlencode($concept['public_token']);
        }
        // Redirigir a URL personalizada solo si el pago esta confirmado
        if ($tx['status'] === 'ok' && !empty($concept['url_ok_custom'])
            && in_array(parse_url($concept['url_ok_custom'], PHP_URL_SCHEME), array('http', 'https'))) {
            header('Location: ' . $concept['url_ok_custom'] . '?ref=' . urlencode($tx['order_ref']));
            exit;
        }
    }
}

$isOk      = $tx && $tx['status'] === 'ok';
$isPending = $tx && $tx['status'] === 'pending';
// Referencia: del registro o del parametro GET (siempre disponible)
$orderRef  = $tx ? $tx['order_ref'] : $ord;
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Pago confirmado &mdash; <?php echo htmlspecialchars($siteName); ?></title>
  <style>
    *{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f4f4f6;
         min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;}
    .card{background:#fff;border-radius:16px;border:1px solid #e8e8e8;padding:40px;max-width:440px;
          width:100%;text-align:center;box-shadow:0 4px 24px rgba(0,0,0,.06);animation:fadeUp .3s ease both;}
    @keyframes fadeUp{from{opacity:0;transform:translateY(10px);}to{opacity:1;transform:translateY(0);}}
    .icon-wrap{width:64px;height:64px;background:#e6f9ee;border-radius:50%;display:flex;
               align-items:center;justify-content:center;margin:0 auto 18px;font-size:28px;color:#1a7a3a;}
    .h1{font-size:22px;font-weight:700;color:#1a1a1a;margin-bottom:8px;letter-spacing:-.02em;}
    .sub{font-size:14px;color:#777;line-height:1.6;margin-bottom:0;}
    .amt{font-size:42px;font-weight:800;color:#1a7a3a;margin:18px 0 4px;letter-spacing:-.03em;}
    .amt-label{font-size:12px;color:#aaa;text-transform:uppercase;letter-spacing:.06em;margin-bottom:16px;}
    table{width:100%;border-collapse:collapse;font-size:13px;margin:16px 0;text-align:left;}
    td{padding:9px 0;border-bottom:1px solid #f0f0f0;}
    td:first-child{color:#999;width:45%;}
    td:last-child{font-weight:600;text-align:right;font-size:13px;}
    .ref-badge{display:inline-block;background:#f5f5f5;border-radius:6px;padding:3px 10px;
               font-family:monospace;font-size:13px;font-weight:600;color:#1a1a1a;}
    .note-ok{background:#f0f9f4;border-radius:8px;padding:11px 14px;font-size:13px;
             color:#2d6a4f;margin-top:12px;border:1px solid #c3e6cb;}
    .note-pending{background:#fffbeb;border-radius:8px;padding:11px 14px;font-size:13px;
                  color:#8a5a00;margin-top:12px;border:1px solid #f0d98a;}
    .btns{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-top:24px;}
    .btn{display:inline-flex;align-items:center;gap:6px;padding:12px 22px;border-radius:10px;
         font-size:14px;font-weight:600;text-decoration:none;transition:opacity .15s,transform .1s;}
    .btn:hover{opacity:.85;}
    .btn:active{transform:scale(.97);}
    .btn-green{background:#1a7a3a;color:#fff;}
    .btn-dark{background:#1a1a1a;color:#fff;}
    .divider{border:none;border-top:1px solid #f0f0f0;margin:20px 0;}
  </style>
</head>
<body>
<div class="card">
  <div class="icon-wrap">&#10003;</div>
  <div class="h1">Pago realizado</div>
  <p class="sub">Tu pago ha sido procesado correctamente.</p>

  <?php if ($isOk): ?>
    <div class="amt"><?php echo number_format((float)$tx['amount'], 2, ',', '.'); ?> &euro;</div>
    <div class="amt-label">Total pagado</div>
    <hr class="divider">
    <table>
      <tr><td>Concepto</td><td><?php echo htmlspecialchars($tx['concept_name']); ?></td></tr>
      <tr><td>Referencia</td><td><span class="ref-badge"><?php echo htmlspecialchars($tx['order_ref']); ?></span></td></tr>
      <tr><td>Fecha</td><td><?php echo date('d/m/Y H:i', strtotime($tx['created_at'])); ?></td></tr>
      <?php if (!empty($tx['redsys_auth'])): ?>
      <tr><td>Autorizacion</td><td><?php echo htmlspecialchars($tx['redsys_auth']); ?></td></tr>
      <?php endif; ?>
    </table>
    <?php if (!empty($tx['customer_email']) && $tx['email_sent']): ?>
    <div class="note-ok">
      &#10003; Confirmacion enviada a <strong><?php echo htmlspecialchars($tx['customer_email']); ?></strong>
    </div>
    <?php endif; ?>

  <?php elseif ($orderRef): ?>
    <!-- Estado pending: notify.php todavia no ha actualizado el estado -->
    <hr class="divider" style="margin-top:16px;">
    <table>
      <tr><td>Referencia</td><td><span class="ref-badge"><?php echo htmlspecialchars($orderRef); ?></span></td></tr>
      <?php if ($tx && !empty($tx['concept_name'])): ?>
      <tr><td>Concepto</td><td><?php echo htmlspecialchars($tx['concept_name']); ?></td></tr>
      <?php endif; ?>
    </table>
    <div class="note-pending">
      Tu pago esta siendo procesado. En breve recibiras confirmacion por email con todos los detalles.
    </div>

  <?php endif; ?>

  <div class="btns">
    <?php if ($isOk && !empty($tx['order_ref'])): ?>
    <a href="justificante.php?ref=<?php echo urlencode($tx['order_ref']); ?>"
       class="btn btn-green" target="_blank" rel="noopener">
      &#128196; Descargar justificante
    </a>
    <?php endif; ?>
    <a href="<?php echo htmlspecialchars($conceptUrl); ?>" class="btn btn-dark">
      &larr; Volver
    </a>
  </div>
</div>
</body>
</html>
