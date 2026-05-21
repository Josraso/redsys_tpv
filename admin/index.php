<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/_header.php';

$totalOk   = (float)db()->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE status='ok'")->fetchColumn();
$countOk   = (int)db()->query("SELECT COUNT(*) FROM transactions WHERE status='ok'")->fetchColumn();
$countErr  = (int)db()->query("SELECT COUNT(*) FROM transactions WHERE status='error'")->fetchColumn();
$todayOk   = (float)db()->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE status='ok' AND DATE(created_at)=CURDATE()")->fetchColumn();
$thisMonth = (float)db()->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE status='ok' AND YEAR(created_at)=YEAR(NOW()) AND MONTH(created_at)=MONTH(NOW())")->fetchColumn();

$recent    = db()->query("SELECT * FROM transactions ORDER BY created_at DESC LIMIT 8")->fetchAll();
$byConcept = db()->query("SELECT concept_name, COUNT(*) as cnt, SUM(amount) as total FROM transactions WHERE status='ok' GROUP BY concept_name ORDER BY total DESC")->fetchAll();

// Datos grafico ultimos 30 dias
$chartData = db()->query("SELECT DATE(created_at) as dia, COALESCE(SUM(amount),0) as total, COUNT(*) as cnt
    FROM transactions WHERE status='ok' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DATE(created_at) ORDER BY dia ASC")->fetchAll();

// Generar todos los dias (rellenar huecos)
$diasMap = array();
foreach ($chartData as $r) $diasMap[$r['dia']] = $r;
$chartLabels = array(); $chartTotales = array(); $chartCnts = array();
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $chartLabels[]  = date('d/m', strtotime($d));
    $chartTotales[] = isset($diasMap[$d]) ? (float)$diasMap[$d]['total'] : 0;
    $chartCnts[]    = isset($diasMap[$d]) ? (int)$diasMap[$d]['cnt']     : 0;
}
?>

<!-- Metricas -->
<div class="metrics">
  <div class="metric"><div class="metric-l">Total cobrado</div><div class="metric-v"><?php echo number_format($totalOk,2,',','.'); ?> &euro;</div></div>
  <div class="metric"><div class="metric-l">Este mes</div><div class="metric-v"><?php echo number_format($thisMonth,2,',','.'); ?> &euro;</div></div>
  <div class="metric"><div class="metric-l">Hoy</div><div class="metric-v"><?php echo number_format($todayOk,2,',','.'); ?> &euro;</div></div>
  <div class="metric"><div class="metric-l">Pagos OK</div><div class="metric-v"><?php echo $countOk; ?></div></div>
  <div class="metric"><div class="metric-l">Errores</div>
    <div class="metric-v" style="color:<?php echo $countErr>0?'#c0392b':'inherit'; ?>"><?php echo $countErr; ?></div>
  </div>
</div>

<!-- Grafico 30 dias -->
<div class="card">
  <div class="card-t">Ingresos ultimos 30 dias</div>
  <canvas id="chart-main" height="70"></canvas>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
<script>
(function(){
  var labels   = <?php echo json_encode($chartLabels); ?>;
  var totales  = <?php echo json_encode($chartTotales); ?>;
  var cnts     = <?php echo json_encode($chartCnts); ?>;
  new Chart(document.getElementById('chart-main'), {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [
        {
          label: 'EUR recaudado',
          data: totales,
          backgroundColor: 'rgba(26,122,58,.65)',
          borderRadius: 4,
          yAxisID: 'y'
        },
        {
          label: 'Pagos',
          data: cnts,
          type: 'line',
          borderColor: '#1a1a1a',
          backgroundColor: 'rgba(26,26,26,.08)',
          pointRadius: 3,
          fill: true,
          tension: .35,
          yAxisID: 'y2'
        }
      ]
    },
    options: {
      responsive: true,
      interaction: { mode: 'index', intersect: false },
      plugins: { legend: { position: 'top' } },
      scales: {
        y:  { type:'linear', position:'left',  beginAtZero:true, title:{display:true,text:'EUR'} },
        y2: { type:'linear', position:'right', beginAtZero:true, title:{display:true,text:'Pagos'}, grid:{drawOnChartArea:false} }
      }
    }
  });
})();
</script>

<div style="display:flex;gap:16px;flex-wrap:wrap;">
  <!-- Por concepto -->
  <?php if(!empty($byConcept)): ?>
  <div class="card" style="flex:1;min-width:260px;margin-bottom:0;">
    <div class="card-t">Por concepto</div>
    <table>
      <thead><tr><th>Concepto</th><th>Pagos</th><th>Total</th></tr></thead>
      <tbody>
      <?php foreach($byConcept as $r): ?>
        <tr>
          <td><?php echo htmlspecialchars($r['concept_name']); ?></td>
          <td><?php echo (int)$r['cnt']; ?></td>
          <td><?php echo number_format((float)$r['total'],2,',','.'); ?> &euro;</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Ultimas transacciones -->
  <div class="card" style="flex:2;min-width:300px;margin-bottom:0;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
      <div class="card-t" style="margin:0;">Ultimas transacciones</div>
      <a href="registro.php" style="font-size:13px;color:#888;">Ver todas &rarr;</a>
    </div>
    <table>
      <thead><tr><th>Fecha</th><th>Cliente</th><th>Concepto</th><th>Importe</th><th>Estado</th></tr></thead>
      <tbody>
      <?php if(empty($recent)): ?>
        <tr><td colspan="5" style="text-align:center;color:#aaa;padding:20px;">Sin transacciones aun</td></tr>
      <?php endif; ?>
      <?php foreach($recent as $tx): ?>
        <tr>
          <td style="white-space:nowrap;"><?php echo date('d/m/Y H:i',strtotime($tx['created_at'])); ?></td>
          <td>
            <div style="font-weight:500;"><?php echo htmlspecialchars($tx['customer_name']); ?></div>
            <div style="font-size:11px;color:#aaa;"><?php echo htmlspecialchars($tx['customer_email']); ?></div>
          </td>
          <td><?php echo htmlspecialchars($tx['concept_name']); ?></td>
          <td style="font-weight:600;"><?php echo number_format((float)$tx['amount'],2,',','.'); ?> &euro;</td>
          <td><?php echo badge($tx['status']); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/_footer.php'; ?>
