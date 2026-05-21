<?php
/**
 * Descarga PDF del justificante de pago
 * Genera HTML que el navegador puede imprimir/guardar como PDF
 * No requiere libreria externa
 */
require_once __DIR__ . '/../lib/db.php';

$ord = preg_replace('/[^A-Za-z0-9]/','', isset($_GET['ref'])?$_GET['ref']:'');
if (!$ord) { http_response_code(404); die('Referencia no encontrada'); }

$st = db()->prepare('SELECT * FROM transactions WHERE order_ref=? AND status="ok" LIMIT 1');
$st->execute(array($ord));
$tx = $st->fetch();
if (!$tx) { http_response_code(404); die('Pago no encontrado'); }

$siteName = getSetting('site_name','TPV');
$footer   = getSetting('mail_footer','');
$amount   = number_format((float)$tx['amount'],2,',','.').' EUR';
$date     = date('d/m/Y H:i', strtotime($tx['created_at']));

// Logo
$proto   = (isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']==='on')?'https':'http';
$baseUrl = $proto.'://'.$_SERVER['HTTP_HOST'].rtrim(str_replace('/pago','',dirname($_SERVER['SCRIPT_NAME'])),'/');
$logoPath = getSetting('logo_concept_'.(int)$tx['concept_id']) ?: getSetting('logo_site');
$logoUrl  = $logoPath ? $baseUrl.'/'.$logoPath : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Justificante <?php echo htmlspecialchars($ord); ?></title>
  <style>
    @media print {
      .no-print { display:none !important; }
      body { margin:0; }
    }
    * { box-sizing:border-box; margin:0; padding:0; }
    body { font-family:Arial,sans-serif; color:#1a1a1a; background:#fff; padding:40px; max-width:600px; margin:0 auto; }
    .no-print { text-align:center; margin-bottom:24px; }
    .btn-print { background:#1a1a1a; color:#fff; border:none; border-radius:8px;
                 padding:10px 24px; font-size:14px; cursor:pointer; margin-right:8px; }
    .btn-back  { background:#fff; color:#1a1a1a; border:1px solid #ddd; border-radius:8px;
                 padding:10px 24px; font-size:14px; cursor:pointer; }
    .header { border-bottom:2px solid #1a1a1a; padding-bottom:20px; margin-bottom:24px; display:flex; justify-content:space-between; align-items:flex-end; }
    .header .logo-area img { max-height:60px; max-width:180px; }
    .header .logo-area h1 { font-size:20px; }
    .header .doc-info { text-align:right; }
    .doc-info .label { font-size:11px; color:#888; text-transform:uppercase; letter-spacing:.05em; }
    .doc-info .value { font-size:14px; font-weight:600; }
    .doc-info .date  { font-size:12px; color:#888; margin-top:4px; }
    .ok-badge { background:#e6f9ee; color:#1a7a3a; border-radius:6px; padding:6px 14px;
                font-size:13px; font-weight:600; display:inline-block; margin:16px 0; }
    .section-title { font-size:11px; color:#888; text-transform:uppercase; letter-spacing:.05em;
                     margin:20px 0 10px; }
    table { width:100%; border-collapse:collapse; }
    td { padding:10px 0; border-bottom:1px solid #f0f0f0; font-size:14px; }
    td:first-child { color:#666; width:45%; }
    td:last-child   { font-weight:500; }
    .total-row td   { font-size:18px; font-weight:700; border-bottom:none; padding-top:16px; }
    .footer { margin-top:32px; padding-top:16px; border-top:1px solid #e0e0e0; font-size:11px; color:#aaa; }
    .watermark { color:#e0e0e0; font-size:11px; margin-top:8px; }
  </style>
</head>
<body>

<div class="no-print">
  <button class="btn-print" onclick="window.print()">🖨️ Imprimir / Guardar PDF</button>
  <button class="btn-back" onclick="history.back()">← Volver</button>
</div>

<div class="header">
  <div class="logo-area">
    <?php if($logoUrl): ?>
      <img src="<?php echo htmlspecialchars($logoUrl); ?>" alt="<?php echo htmlspecialchars($siteName); ?>">
    <?php else: ?>
      <h1><?php echo htmlspecialchars($siteName); ?></h1>
    <?php endif; ?>
  </div>
  <div class="doc-info">
    <div class="label">Justificante de pago</div>
    <div class="value"><?php echo htmlspecialchars($ord); ?></div>
    <div class="date"><?php echo $date; ?></div>
  </div>
</div>

<span class="ok-badge">✓ Pago confirmado</span>

<div class="section-title">Datos del pago</div>
<table>
  <tr><td>Concepto</td><td><?php echo htmlspecialchars($tx['concept_name']); ?></td></tr>
  <tr><td>Referencia</td><td><?php echo htmlspecialchars($tx['order_ref']); ?></td></tr>
  <tr><td>Cod. autorizacion</td><td><?php echo htmlspecialchars($tx['redsys_auth']?:'—'); ?></td></tr>
  <tr><td>Fecha y hora</td><td><?php echo $date; ?></td></tr>
  <tr class="total-row"><td>Total pagado</td><td><?php echo $amount; ?></td></tr>
</table>

<div class="section-title">Datos del pagador</div>
<table>
  <tr><td>Nombre</td><td><?php echo htmlspecialchars($tx['customer_name']); ?></td></tr>
  <tr><td>Email</td><td><?php echo htmlspecialchars($tx['customer_email']); ?></td></tr>
</table>

<div class="footer">
  <?php if($footer): ?><p><?php echo nl2br(htmlspecialchars($footer)); ?></p><?php endif; ?>
  <p class="watermark">Procesado por Redsys · <?php echo htmlspecialchars($siteName); ?> · <?php echo date('Y'); ?></p>
</div>

</body>
</html>
