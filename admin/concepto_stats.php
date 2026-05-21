<?php
$pageTitle = 'Estadisticas del concepto';
require_once __DIR__ . '/_header.php';

$id = (int)(isset($_GET['id'])?$_GET['id']:0);
if (!$id) { header('Location: concepts.php'); exit; }

$st = db()->prepare('SELECT * FROM concepts WHERE id=?');
$st->execute(array($id));
$concept = $st->fetch();
if (!$concept) { header('Location: concepts.php'); exit; }

// Estadisticas
$stats = db()->prepare("SELECT
    COUNT(*) as total_pagos,
    COALESCE(SUM(amount),0) as total_eur,
    COALESCE(AVG(amount),0) as media_eur,
    COALESCE(MIN(amount),0) as min_eur,
    COALESCE(MAX(amount),0) as max_eur,
    COUNT(CASE WHEN email_sent=1 THEN 1 END) as emails_ok
    FROM transactions WHERE concept_id=? AND status='ok'");
$stats->execute(array($id));
$stats = $stats->fetch();

$ultimoPago = db()->prepare("SELECT * FROM transactions WHERE concept_id=? AND status='ok' ORDER BY created_at DESC LIMIT 1");
$ultimoPago->execute(array($id));
$ultimoPago = $ultimoPago->fetch();

// Pagos por dia (ultimos 30 dias)
$porDia = db()->prepare("SELECT DATE(created_at) as dia, COUNT(*) as cnt, SUM(amount) as total
    FROM transactions WHERE concept_id=? AND status='ok' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DATE(created_at) ORDER BY dia ASC");
$porDia->execute(array($id));
$porDia = $porDia->fetchAll();

// Ultimas transacciones
$ultimas = db()->prepare("SELECT * FROM transactions WHERE concept_id=? ORDER BY created_at DESC LIMIT 15");
$ultimas->execute(array($id));
$ultimas = $ultimas->fetchAll();

// Plazas
$pagosOk = (int)$stats['total_pagos'];
$maxPagos = $concept['max_pagos'] ? (int)$concept['max_pagos'] : null;
$quedan   = $maxPagos ? max(0, $maxPagos - $pagosOk) : null;
?>

<div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;">
  <a href="concepts.php" style="font-size:13px;color:#888;">&larr; Volver a conceptos</a>
  <span style="color:#ddd;">|</span>
  <span style="font-size:15px;font-weight:600;"><?php echo htmlspecialchars($concept['icon'].' '.$concept['name']); ?></span>
  <?php if(!$concept['active']): ?>
    <span class="badge err">Inactivo</span>
  <?php endif; ?>
</div>

<!-- Metricas principales -->
<div class="metrics">
  <div class="metric">
    <div class="metric-l">Total recaudado</div>
    <div class="metric-v"><?php echo number_format((float)$stats['total_eur'],2,',','.'); ?> &euro;</div>
  </div>
  <div class="metric">
    <div class="metric-l">Pagos OK</div>
    <div class="metric-v"><?php echo $pagosOk; ?></div>
  </div>
  <div class="metric">
    <div class="metric-l">Importe medio</div>
    <div class="metric-v"><?php echo number_format((float)$stats['media_eur'],2,',','.'); ?> &euro;</div>
  </div>
  <div class="metric">
    <div class="metric-l">Emails enviados</div>
    <div class="metric-v"><?php echo (int)$stats['emails_ok']; ?></div>
  </div>
</div>

<!-- Info del concepto + plazas -->
<div style="display:flex;gap:16px;margin-bottom:16px;flex-wrap:wrap;">
  <div class="card" style="flex:1;min-width:220px;margin-bottom:0;">
    <div class="card-t">Configuracion</div>
    <table style="font-size:13px;">
      <tr><td style="color:#888;padding:6px 0;width:50%;border-bottom:1px solid #f5f5f5;">Importe</td>
          <td style="font-weight:500;border-bottom:1px solid #f5f5f5;">
            <?php if($concept['is_libre']): ?>
              Libre
              <?php if($concept['min_amount']||$concept['max_amount']): ?>
                (<?php echo ($concept['min_amount']?number_format((float)$concept['min_amount'],2,',','.').' EUR min':''); ?>
                <?php echo ($concept['max_amount']?' &mdash; '.number_format((float)$concept['max_amount'],2,',','.').' EUR max':''); ?>)
              <?php endif; ?>
            <?php else: ?>
              <?php echo number_format((float)$concept['amount'],2,',','.'); ?> EUR
            <?php endif; ?>
          </td></tr>
      <tr><td style="color:#888;padding:6px 0;border-bottom:1px solid #f5f5f5;">Fecha limite</td>
          <td style="border-bottom:1px solid #f5f5f5;"><?php echo $concept['fecha_limite']?date('d/m/Y',strtotime($concept['fecha_limite'])):'Sin limite'; ?></td></tr>
      <tr><td style="color:#888;padding:6px 0;border-bottom:1px solid #f5f5f5;">Max plazas</td>
          <td style="border-bottom:1px solid #f5f5f5;"><?php echo $maxPagos??'Sin limite'; ?></td></tr>
      <tr><td style="color:#888;padding:6px 0;">URL OK custom</td>
          <td style="font-size:11px;"><?php echo $concept['url_ok_custom']?htmlspecialchars($concept['url_ok_custom']):'Generica'; ?></td></tr>
    </table>
  </div>

  <?php if($maxPagos): ?>
  <div class="card" style="min-width:200px;margin-bottom:0;text-align:center;">
    <div class="card-t">Plazas</div>
    <div style="font-size:48px;font-weight:700;color:<?php echo $quedan<=0?'#c0392b':($quedan<=3?'#e67e22':'#1a7a3a'); ?>;">
      <?php echo $quedan; ?>
    </div>
    <div style="font-size:13px;color:#888;">disponibles de <?php echo $maxPagos; ?></div>
    <div style="background:#f0f0f0;border-radius:8px;height:10px;margin:12px 0;overflow:hidden;">
      <?php $pct=min(100,round($pagosOk/$maxPagos*100)); ?>
      <div style="background:<?php echo $quedan<=0?'#c0392b':($quedan<=3?'#e67e22':'#1a7a3a'); ?>;height:10px;width:<?php echo $pct; ?>%;border-radius:8px;"></div>
    </div>
    <div style="font-size:12px;color:#aaa;"><?php echo $pct; ?>% ocupado</div>
  </div>
  <?php endif; ?>

  <?php if($ultimoPago): ?>
  <div class="card" style="min-width:200px;margin-bottom:0;">
    <div class="card-t">Ultimo pago</div>
    <div style="font-size:13px;"><strong><?php echo htmlspecialchars($ultimoPago['customer_name']); ?></strong></div>
    <div style="font-size:12px;color:#888;"><?php echo htmlspecialchars($ultimoPago['customer_email']); ?></div>
    <div style="font-size:20px;font-weight:700;margin:8px 0;"><?php echo number_format((float)$ultimoPago['amount'],2,',','.'); ?> &euro;</div>
    <div style="font-size:12px;color:#aaa;"><?php echo date('d/m/Y H:i',strtotime($ultimoPago['created_at'])); ?></div>
  </div>
  <?php endif; ?>
</div>

<!-- Grafico por dia -->
<?php if(!empty($porDia)): ?>
<div class="card">
  <div class="card-t">Actividad ultimos 30 dias</div>
  <canvas id="chart-dia" height="80"></canvas>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
<script>
(function(){
  var data = <?php echo json_encode($porDia); ?>;
  var labels = data.map(function(r){ var d=r.dia.split('-'); return d[2]+'/'+d[1]; });
  var totales = data.map(function(r){ return parseFloat(r.total); });
  var cnts    = data.map(function(r){ return parseInt(r.cnt); });
  new Chart(document.getElementById('chart-dia'), {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [
        { label: 'EUR recaudado', data: totales, backgroundColor: 'rgba(26,122,58,.7)', yAxisID: 'y' },
        { label: 'Pagos', data: cnts, type: 'line', borderColor: '#1a1a1a', backgroundColor: 'rgba(26,26,26,.1)', yAxisID: 'y2', tension: .3 }
      ]
    },
    options: {
      responsive: true,
      plugins: { legend: { position: 'top' } },
      scales: {
        y:  { type:'linear', position:'left',  title:{display:true,text:'EUR'} },
        y2: { type:'linear', position:'right', title:{display:true,text:'Pagos'}, grid:{drawOnChartArea:false} }
      }
    }
  });
})();
</script>
<?php endif; ?>

<!-- Tabla ultimas transacciones -->
<div class="card">
  <div style="display:flex;justify-content:space-between;margin-bottom:14px;">
    <div class="card-t" style="margin:0;">Ultimas transacciones</div>
    <a href="registro.php?concept=<?php echo htmlspecialchars(urlencode($concept['name'])); ?>" style="font-size:13px;color:#888;">Ver todas &rarr;</a>
  </div>
  <div style="overflow-x:auto;">
  <table>
    <thead><tr><th>Fecha</th><th>Cliente</th><th>Importe</th><th>Estado</th></tr></thead>
    <tbody>
    <?php foreach($ultimas as $tx): ?>
      <tr>
        <td><?php echo date('d/m/Y H:i',strtotime($tx['created_at'])); ?></td>
        <td>
          <div><?php echo htmlspecialchars($tx['customer_name']); ?></div>
          <div style="font-size:11px;color:#aaa;"><?php echo htmlspecialchars($tx['customer_email']); ?></div>
        </td>
        <td style="font-weight:600;"><?php echo number_format((float)$tx['amount'],2,',','.'); ?> &euro;</td>
        <td><?php echo badge($tx['status']); ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<?php require_once __DIR__ . '/_footer.php'; ?>
