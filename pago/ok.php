<?php
require_once __DIR__ . '/../lib/db.php';
$siteName = getSetting('site_name','TPV');
$ord = preg_replace('/[^A-Za-z0-9]/','',isset($_GET['Ds_Order'])?$_GET['Ds_Order']:'');
$tx=null;
if($ord){$st=db()->prepare('SELECT * FROM transactions WHERE order_ref=? LIMIT 1');$st->execute(array($ord));$tx=$st->fetch()?:null;}

// Redirigir a URL personalizada si el concepto la tiene configurada
if ($tx && $tx['status']==='ok' && !empty($tx['concept_id'])) {
    $stc = db()->prepare('SELECT url_ok_custom FROM concepts WHERE id=?');
    $stc->execute(array($tx['concept_id']));
    $urlCustom = $stc->fetchColumn();
    if ($urlCustom) {
        header('Location: ' . $urlCustom . '?ref=' . urlencode($tx['order_ref']));
        exit;
    }
}
?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pago confirmado &mdash; <?php echo htmlspecialchars($siteName); ?></title>
<style>*{box-sizing:border-box;margin:0;padding:0;}body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f4f4f6;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;}
.card{background:#fff;border-radius:12px;border:1px solid #e8e8e8;padding:40px;max-width:420px;width:100%;text-align:center;}
.icon{font-size:48px;margin-bottom:14px;}.h1{font-size:22px;font-weight:700;color:#1a7a3a;margin-bottom:8px;}
p{font-size:14px;color:#555;line-height:1.6;margin-bottom:10px;}.amt{font-size:36px;font-weight:800;color:#1a7a3a;margin:14px 0;}
table{width:100%;border-collapse:collapse;font-size:13px;margin:16px 0;text-align:left;}
td{padding:8px 0;border-bottom:1px solid #f0f0f0;}td:first-child{color:#888;}td:last-child{font-weight:600;text-align:right;}
.note{background:#f0f9f4;border-radius:8px;padding:11px 14px;font-size:13px;color:#2d6a4f;margin-top:10px;}
.btn{display:inline-block;margin-top:22px;padding:12px 28px;background:#1a1a1a;color:#fff;border-radius:8px;font-size:14px;font-weight:600;text-decoration:none;}</style>
</head><body><div class="card">
  <div class="icon">&#10003;</div>
  <div class="h1">Pago realizado</div>
  <p>Tu pago ha sido procesado correctamente.</p>
  <?php if($tx&&$tx['status']==='ok'): ?>
  <div class="amt"><?php echo number_format((float)$tx['amount'],2,',','.'); ?> &euro;</div>
  <table>
    <tr><td>Concepto</td><td><?php echo htmlspecialchars($tx['concept_name']); ?></td></tr>
    <tr><td>Referencia</td><td><?php echo htmlspecialchars($tx['order_ref']); ?></td></tr>
    <tr><td>Fecha</td><td><?php echo date('d/m/Y H:i',strtotime($tx['created_at'])); ?></td></tr>
  </table>
  <?php if(!empty($tx['customer_email'])&&$tx['email_sent']): ?>
  <div class="note">Email de confirmacion enviado a <strong><?php echo htmlspecialchars($tx['customer_email']); ?></strong></div>
  <?php endif; ?>
  <?php else: ?>
  <p style="color:#888;">Referencia: <strong><?php echo htmlspecialchars($ord); ?></strong></p>
  <?php endif; ?>
  <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-top:22px;">
  <?php if ($tx && !empty($tx['order_ref'])): ?>
  <a href="justificante.php?ref=<?php echo urlencode($tx['order_ref']); ?>"
     class="btn" style="background:#1a7a3a;" target="_blank">&#128196; Descargar justificante</a>
  <?php endif; ?>
  <a href="../index.php" class="btn">Volver</a>
  </div>
</div></body></html>
