<?php
require_once __DIR__ . '/../lib/db.php';
$siteName = getSetting('site_name', 'TPV');

$ord = preg_replace('/[^A-Za-z0-9]/', '', isset($_GET['Ds_Order']) ? $_GET['Ds_Order'] : '');

// Fallback: recuperar referencia de sesion si Redsys no paso Ds_Order en la URL
if (!$ord) {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params(0, '/; SameSite=Lax', '', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on'), true);
        session_start();
    }
    if (!empty($_SESSION['last_order_ref'])) {
        $ord = preg_replace('/[^A-Za-z0-9]/', '', $_SESSION['last_order_ref']);
    }
}

// Buscar transaccion SIN filtrar por status (race condition: llega antes que notify.php)
$tx = null;
if ($ord) {
    $st = db()->prepare('SELECT * FROM transactions WHERE order_ref=? LIMIT 1');
    $st->execute(array($ord));
    $tx = $st->fetch() ?: null;
}

// Redirigir a URL personalizada solo si el pago esta confirmado
if ($tx && $tx['status'] === 'ok' && !empty($tx['concept_id'])) {
    $stc = db()->prepare('SELECT url_ok_custom FROM concepts WHERE id=?');
    $stc->execute(array($tx['concept_id']));
    $urlCustom = $stc->fetchColumn();
    if ($urlCustom && in_array(parse_url($urlCustom, PHP_URL_SCHEME), array('http', 'https'))) {
        header('Location: ' . $urlCustom . '?ref=' . urlencode($tx['order_ref']));
        exit;
    }
}

$isOk      = $tx && $tx['status'] === 'ok';
$isPending = $tx && $tx['status'] === 'pending';
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
    .icon-spin{width:64px;height:64px;background:#f5f5f5;border-radius:50%;display:flex;
               align-items:center;justify-content:center;margin:0 auto 18px;}
    .spinner{width:28px;height:28px;border:3px solid #e0e0e0;border-top-color:#1a1a1a;
             border-radius:50%;animation:spin .8s linear infinite;}
    @keyframes spin{to{transform:rotate(360deg);}}
    .h1{font-size:22px;font-weight:700;color:#1a1a1a;margin-bottom:8px;letter-spacing:-.02em;}
    .sub{font-size:14px;color:#777;line-height:1.6;}
    .amt{font-size:42px;font-weight:800;color:#1a7a3a;margin:18px 0 4px;letter-spacing:-.03em;}
    .amt-label{font-size:12px;color:#aaa;text-transform:uppercase;letter-spacing:.06em;margin-bottom:16px;}
    hr{border:none;border-top:1px solid #f0f0f0;margin:20px 0;}
    table{width:100%;border-collapse:collapse;font-size:13px;margin:0;text-align:left;}
    td{padding:9px 0;border-bottom:1px solid #f5f5f5;}
    td:first-child{color:#999;width:45%;}
    td:last-child{font-weight:600;text-align:right;}
    tr:last-child td{border-bottom:none;}
    .ref-badge{display:inline-block;background:#f5f5f5;border-radius:6px;padding:3px 10px;
               font-family:monospace;font-size:13px;font-weight:600;color:#1a1a1a;}
    .note-ok{background:#f0f9f4;border-radius:8px;padding:11px 14px;font-size:13px;
             color:#2d6a4f;margin-top:14px;border:1px solid #c3e6cb;text-align:left;}
    .note-pending{background:#fffbeb;border-radius:10px;padding:14px 16px;font-size:13px;
                  color:#8a5a00;margin-top:16px;border:1px solid #f0d98a;line-height:1.6;}
    .btn-justificante{display:inline-flex;align-items:center;justify-content:center;gap:8px;
                      width:100%;margin-top:24px;padding:15px 24px;border-radius:12px;
                      background:#1a7a3a;color:#fff;font-size:15px;font-weight:700;
                      text-decoration:none;letter-spacing:-.01em;
                      transition:opacity .15s,transform .1s;box-shadow:0 4px 16px rgba(26,122,58,.25);}
    .btn-justificante:hover{opacity:.88;}
    .btn-justificante:active{transform:scale(.97);}
  </style>
</head>
<body>
<div class="card">

  <?php if ($isOk): ?>
    <div class="icon-wrap">&#10003;</div>
    <div class="h1">Pago realizado</div>
    <p class="sub">Tu pago ha sido procesado correctamente.</p>
    <div class="amt"><?php echo number_format((float)$tx['amount'], 2, ',', '.'); ?> &euro;</div>
    <div class="amt-label">Total pagado</div>
    <hr>
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
    <a href="justificante.php?ref=<?php echo urlencode($tx['order_ref']); ?>"
       class="btn-justificante">
      &#128196; Descargar justificante de pago
    </a>

  <?php elseif ($isPending || $orderRef): ?>
    <div class="icon-spin"><div class="spinner"></div></div>
    <div class="h1">Procesando pago&hellip;</div>
    <p class="sub">Tu pago esta siendo confirmado.</p>
    <?php if ($orderRef): ?>
    <hr>
    <table>
      <tr><td>Referencia</td><td><span class="ref-badge"><?php echo htmlspecialchars($orderRef); ?></span></td></tr>
      <?php if ($tx && !empty($tx['concept_name'])): ?>
      <tr><td>Concepto</td><td><?php echo htmlspecialchars($tx['concept_name']); ?></td></tr>
      <?php endif; ?>
    </table>
    <?php endif; ?>
    <div class="note-pending">
      En breve recibiras un email con la confirmacion y el justificante de pago.
      Esta pagina se actualiza automaticamente&hellip;
    </div>
    <?php if ($orderRef): ?>
    <a href="justificante.php?ref=<?php echo urlencode($orderRef); ?>"
       class="btn-justificante" style="margin-top:20px;">
      &#128196; Descargar justificante de pago
    </a>
    <?php endif; ?>

  <?php else: ?>
    <div class="icon-wrap">&#10003;</div>
    <div class="h1">Pago realizado</div>
    <p class="sub">Tu pago ha sido procesado. Recibiras la confirmacion por email.</p>
  <?php endif; ?>

</div>

<?php if ($isPending): ?>
<script>
// Auto-refresh hasta 4 intentos mientras el pago este pendiente
var n = parseInt(sessionStorage.getItem('ok_polls') || '0');
if (n < 4) {
  sessionStorage.setItem('ok_polls', n + 1);
  setTimeout(function() { location.reload(); }, 2500);
}
</script>
<?php else: ?>
<script>sessionStorage.removeItem('ok_polls');</script>
<?php endif; ?>
</body>
</html>
