<?php
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/RedsysAPI.php';

$siteName   = getSetting('site_name', 'TPV');
$siteFooter = getSetting('site_footer', '');
$error      = '';

// URL base del sitio (para logos etc)
$proto   = (isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']==='on')?'https':'http';
$host    = $_SERVER['HTTP_HOST'];
$baseUrl = $proto.'://'.$host.rtrim(str_replace('/index.php','',dirname($_SERVER['SCRIPT_NAME'])),'/');

// Si viene ?concept=ID -> mostrar SOLO ese concepto
// Si no viene parametro  -> mostrar TODOS los activos
$conceptId = isset($_GET['concept']) ? (int)$_GET['concept'] : 0;

if ($conceptId > 0) {
    $st = db()->prepare('SELECT * FROM concepts WHERE active=1 AND id=?');
    $st->execute(array($conceptId));
    $concepts = $st->fetchAll();
    $soloConcepto = true;
} else {
    $concepts = db()->query('SELECT * FROM concepts WHERE active=1 ORDER BY sort_order ASC, id ASC')->fetchAll();
    $soloConcepto = false;
}

// Filtrar conceptos cerrados (fecha limite o plazas completas)
function conceptoDisponible($c) {
    if ($c['fecha_limite'] && $c['fecha_limite'] < date('Y-m-d')) return false;
    if ($c['max_pagos']) {
        $cnt = db()->prepare("SELECT COUNT(*) FROM transactions WHERE concept_id=? AND status='ok'");
        $cnt->execute(array($c['id']));
        if ((int)$cnt->fetchColumn() >= (int)$c['max_pagos']) return false;
    }
    return true;
}
$conceptCerrado = false;
$conceptCerradoMsg = '';
if ($soloConcepto && !empty($concepts)) {
    $c0 = $concepts[0];
    if (!conceptoDisponible($c0)) {
        $conceptCerrado = true;
        if ($c0['fecha_limite'] && $c0['fecha_limite'] < date('Y-m-d')) {
            $conceptCerradoMsg = 'El plazo de este concepto ha finalizado el ' . date('d/m/Y', strtotime($c0['fecha_limite'])) . '.';
        } else {
            $conceptCerradoMsg = 'Las plazas de este concepto estan completas.';
        }
    }
}
// En modo multi, filtrar los cerrados
if (!$soloConcepto) {
    $concepts = array_values(array_filter($concepts, 'conceptoDisponible'));
}

// Calcular logo AQUI - ya tenemos $soloConcepto y $concepts definidos
if ($soloConcepto && !empty($concepts)) {
    // Logo especifico del concepto, con fallback al logo general
    $logoKey  = 'logo_concept_' . (int)$concepts[0]['id'];
    $logoPath = getSetting($logoKey);
    if (!$logoPath) $logoPath = getSetting('logo_site');
} else {
    $logoPath = getSetting('logo_site');
}
$logoUrl = ($logoPath && $baseUrl) ? $baseUrl . '/' . $logoPath : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postConceptId = (int)(isset($_POST['concept_id']) ? $_POST['concept_id'] : 0);
    $customAmt     = trim(isset($_POST['custom_amount']) ? $_POST['custom_amount'] : '');
    $custName      = trim(htmlspecialchars(isset($_POST['customer_name']) ? $_POST['customer_name'] : '', ENT_QUOTES));
    $custEmail     = filter_var(trim(isset($_POST['customer_email']) ? $_POST['customer_email'] : ''), FILTER_VALIDATE_EMAIL);

    if (!$custName || !$custEmail) {
        $error = 'Introduce tu nombre y un email valido.';
    } else {
        $st = db()->prepare('SELECT * FROM concepts WHERE id=? AND active=1');
        $st->execute(array($postConceptId));
        $concept = $st->fetch();
        if (!$concept) {
            $error = 'Concepto no valido.';
        } else {
            if ($concept['is_libre']) {
                $amount = round((float)str_replace(',', '.', $customAmt), 2);
                if ($amount < 0.01) {
                    $error = 'El importe debe ser mayor que 0.';
                } elseif ($concept['min_amount'] && $amount < (float)$concept['min_amount']) {
                    $error = 'El importe minimo es ' . number_format((float)$concept['min_amount'],2,',','.') . ' EUR.';
                } elseif ($concept['max_amount'] && $amount > (float)$concept['max_amount']) {
                    $error = 'El importe maximo es ' . number_format((float)$concept['max_amount'],2,',','.') . ' EUR.';
                }
            } else {
                $amount = (float)$concept['amount'];
            }
            if (!$error && $amount < 0.01) {
                $error = 'El importe debe ser mayor que 0.';
            } else {
                // Generar referencia unica
                $orderRef = RedsysAPI::generateOrderRef();
                for ($i = 0; $i < 10; $i++) {
                    $chk = db()->prepare('SELECT COUNT(*) FROM transactions WHERE order_ref=?');
                    $chk->execute(array($orderRef));
                    if ((int)$chk->fetchColumn() === 0) break;
                    $orderRef = RedsysAPI::generateOrderRef();
                }

                db()->prepare(
                    'INSERT INTO transactions (concept_id,concept_name,order_ref,amount,customer_name,customer_email,customer_ip,status)
                     VALUES (?,?,?,?,?,?,?,"pending")'
                )->execute(array(
                    $concept['id'], $concept['name'], $orderRef, $amount,
                    $custName, $custEmail,
                    isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : ''
                ));

                $r = new RedsysAPI();
                $r->setParameter('DS_MERCHANT_AMOUNT',           (string)(int)round($amount * 100));
                $r->setParameter('DS_MERCHANT_ORDER',            $orderRef);
                $r->setParameter('DS_MERCHANT_MERCHANTCODE',     getSetting('redsys_fuc'));
                $r->setParameter('DS_MERCHANT_CURRENCY',         getSetting('redsys_currency', '978'));
                $r->setParameter('DS_MERCHANT_TRANSACTIONTYPE',  '0');
                $r->setParameter('DS_MERCHANT_TERMINAL',         getSetting('redsys_terminal', '1'));
                $r->setParameter('DS_MERCHANT_MERCHANTURL',      getSetting('redsys_url_notify'));
                $r->setParameter('DS_MERCHANT_URLOK',            getSetting('redsys_url_ok'));
                $r->setParameter('DS_MERCHANT_URLKO',            getSetting('redsys_url_ko'));
                $r->setParameter('DS_MERCHANT_CONSUMERLANGUAGE', '001');
                $r->setParameter('DS_MERCHANT_PRODUCTDESCRIPTION', substr($concept['name'], 0, 125));
                $r->setParameter('DS_MERCHANT_TITULAR',          $custName);

                $params = $r->createMerchantParameters();
                $sig    = $r->generateMerchantSignature(getSetting('redsys_secret_key'), $params, $orderRef);
                $url    = getSetting('redsys_environment', 'test') === 'prod'
                    ? 'https://sis.redsys.es/sis/realizarPago'
                    : 'https://sis-t.redsys.es:25443/sis/realizarPago';
                ?>
                <!DOCTYPE html><html><head><meta charset="UTF-8"><title>Redirigiendo...</title></head>
                <body style="font-family:sans-serif;text-align:center;padding:60px;color:#444;">
                <p>Redirigiendo al pago seguro...</p>
                <form id="rf" method="POST" action="<?php echo htmlspecialchars($url); ?>">
                  <input type="hidden" name="Ds_SignatureVersion"   value="HMAC_SHA256_V1">
                  <input type="hidden" name="Ds_MerchantParameters" value="<?php echo htmlspecialchars($params); ?>">
                  <input type="hidden" name="Ds_Signature"          value="<?php echo htmlspecialchars($sig); ?>">
                </form>
                <script>document.getElementById('rf').submit();</script>
                </body></html>
                <?php
                exit;
            }
        }
    }
}

$cjs = array();
foreach ($concepts as $c) {
    $cjs[] = array('id' => (int)$c['id'], 'libre' => (bool)$c['is_libre'], 'amount' => (float)($c['amount'] ? $c['amount'] : 0));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?php echo htmlspecialchars($soloConcepto && !empty($concepts) ? $concepts[0]['name'].' — '.$siteName : 'Pago seguro — '.$siteName); ?></title>
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f4f4f6;color:#1a1a1a;min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:20px;}
    .wrap{width:100%;max-width:460px;}
    .logo{text-align:center;margin-bottom:24px;}
    .logo h1{font-size:20px;font-weight:600;}
    .logo p{font-size:13px;color:#888;margin-top:3px;}
    .card{background:#fff;border-radius:12px;border:1px solid #e8e8e8;padding:24px;margin-bottom:14px;}
    .slbl{font-size:11px;font-weight:600;color:#999;text-transform:uppercase;letter-spacing:.06em;margin-bottom:11px;}
    /* Concepto unico — se muestra como bloque fijo, no seleccionable */
    .concept-fixed{background:#f7f7f7;border-radius:8px;padding:14px 16px;margin-bottom:4px;}
    .concept-fixed .name{font-size:15px;font-weight:600;}
    .concept-fixed .desc{font-size:13px;color:#666;margin-top:3px;}
    .concept-fixed .price{font-size:17px;font-weight:700;margin-top:8px;color:#1a1a1a;}
    /* Lista de conceptos */
    .opt{border:1px solid #e5e5e5;border-radius:8px;padding:11px 14px;margin-bottom:8px;cursor:pointer;display:flex;justify-content:space-between;align-items:center;transition:border-color .15s;}
    .opt:hover{border-color:#aaa;background:#fafafa;}
    .opt.on{border:2px solid #1a1a1a;background:#fafafa;}
    .opt input{display:none;}
    .opt-n{font-size:14px;font-weight:500;}
    .opt-d{font-size:12px;color:#888;margin-top:2px;}
    .opt-p{font-size:14px;font-weight:600;flex-shrink:0;margin-left:10px;}
    .libre-w{margin-top:10px;display:none;}
    .libre-w label{font-size:13px;color:#555;display:block;margin-bottom:5px;}
    .libre-w input{width:100%;font-size:22px;text-align:center;font-weight:600;border:1px solid #ddd;border-radius:8px;padding:10px;}
    .amtbox{background:#f7f7f7;border-radius:8px;padding:14px;text-align:center;margin:14px 0;}
    .amtbox .l{font-size:12px;color:#888;margin-bottom:3px;}
    .amtbox .v{font-size:32px;font-weight:700;}
    label{font-size:13px;color:#555;display:block;margin-bottom:4px;}
    input[type=text],input[type=email]{width:100%;border:1px solid #ddd;border-radius:8px;padding:10px 12px;font-size:14px;margin-bottom:11px;outline:none;}
    input:focus{border-color:#1a1a1a;}
    .error{background:#fff1f1;border:1px solid #fcc;border-radius:8px;padding:11px 14px;font-size:13px;color:#c00;margin-bottom:14px;}
    .pay-btn{width:100%;background:#1a1a1a;color:#fff;border:none;border-radius:8px;padding:14px;font-size:15px;font-weight:600;cursor:pointer;}
    .pay-btn:hover{opacity:.85;}
    .foot2{display:flex;align-items:center;justify-content:center;gap:5px;font-size:11px;color:#aaa;margin-top:11px;}
    .rdot{width:7px;height:7px;background:#c0392b;border-radius:50%;}
    .sitefooter{text-align:center;font-size:11px;color:#bbb;margin-top:12px;line-height:1.6;}
    .empty{text-align:center;color:#888;padding:20px;}
  </style>
</head>
<body>
<div class="wrap">
  <div class="logo">
    <?php if ($logoUrl): ?>
      <img src="<?php echo htmlspecialchars($logoUrl); ?>" alt="<?php echo htmlspecialchars($siteName); ?>"
           style="max-height:80px;max-width:260px;object-fit:contain;margin-bottom:10px;display:block;margin-left:auto;margin-right:auto;">
    <?php else: ?>
      <h1><?php echo htmlspecialchars($siteName); ?></h1>
    <?php endif; ?>
    <p>Pago seguro con tarjeta</p>
  </div>

  <?php if ($error): ?>
    <div class="error"><?php echo htmlspecialchars($error); ?></div>
  <?php endif; ?>

  <?php if ($conceptCerrado): ?>
    <div class="card" style="text-align:center;padding:32px;">
      <div style="font-size:40px;margin-bottom:12px;">🔒</div>
      <div style="font-size:17px;font-weight:600;margin-bottom:8px;">Concepto cerrado</div>
      <div style="font-size:14px;color:#666;"><?php echo htmlspecialchars($conceptCerradoMsg); ?></div>
    </div>
  <?php elseif (empty($concepts)): ?>
    <div class="card"><div class="empty">
      <?php echo $soloConcepto ? 'Este concepto de pago no esta disponible.' : 'No hay conceptos de pago disponibles.'; ?>
    </div></div>
  <?php elseif(!$soloConcepto && empty($concepts)): ?>
    <div class="card"><div class="empty">No hay conceptos de pago disponibles.</div></div>
  <?php else: ?>
  <form method="POST">

    <?php if ($soloConcepto): ?>
    <!-- MODO CONCEPTO UNICO: mostrar solo ese, sin selector -->
    <?php $c = $concepts[0]; ?>
    <input type="hidden" name="concept_id" value="<?php echo (int)$c['id']; ?>">
    <div class="card">
      <div class="concept-fixed">
        <div class="name"><?php echo htmlspecialchars($c['icon'] . ' ' . $c['name']); ?></div>
        <?php if ($c['description']): ?>
        <div class="desc"><?php echo htmlspecialchars($c['description']); ?></div>
        <?php endif; ?>
        <?php if (!$c['is_libre']): ?>
        <div class="price"><?php echo number_format((float)$c['amount'], 2, ',', '.'); ?> &euro;</div>
        <?php endif; ?>
      </div>
      <?php if ($c['is_libre']): ?>
      <div style="margin-top:12px;">
        <label>Importe a pagar</label>
        <input type="number" name="custom_amount" id="camt"
               min="<?php echo $c['min_amount']?(float)$c['min_amount']:'0.01'; ?>"
               max="<?php echo $c['max_amount']?(float)$c['max_amount']:''; ?>"
               step="0.01" placeholder="0,00" oninput="updAmt(this.value)">
        <?php if($c['min_amount']||$c['max_amount']): ?>
        <p style="font-size:12px;color:#888;margin-top:5px;">
          <?php if($c['min_amount']&&$c['max_amount']): ?>
            Importe entre <?php echo number_format((float)$c['min_amount'],2,',','.'); ?> y <?php echo number_format((float)$c['max_amount'],2,',','.'); ?> EUR
          <?php elseif($c['min_amount']): ?>
            Importe minimo: <?php echo number_format((float)$c['min_amount'],2,',','.'); ?> EUR
          <?php else: ?>
            Importe maximo: <?php echo number_format((float)$c['max_amount'],2,',','.'); ?> EUR
          <?php endif; ?>
        </p>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <?php if (!$c['is_libre']): ?>
    <div class="amtbox">
      <div class="l">Total a pagar</div>
      <div class="v"><?php echo number_format((float)$c['amount'], 2, ',', '.'); ?> &euro;</div>
    </div>
    <?php else: ?>
    <div class="amtbox" id="amtbox" style="display:none;">
      <div class="l">Total a pagar</div>
      <div class="v" id="amtval">&mdash;</div>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <!-- MODO TODOS: selector de concepto -->
    <div class="card">
      <div class="slbl">Selecciona el concepto</div>
      <?php foreach ($concepts as $idx => $c):
        $isOn = ($idx === 0); ?>
      <label class="opt <?php echo $isOn ? 'on' : ''; ?>"
             onclick="selOpt(this,<?php echo (int)$c['id']; ?>,<?php echo $c['is_libre'] ? 'true' : 'false'; ?>,<?php echo (float)($c['amount'] ? $c['amount'] : 0); ?>)">
        <input type="radio" name="concept_id" value="<?php echo (int)$c['id']; ?>" <?php echo $isOn ? 'checked' : ''; ?>>
        <div>
          <div class="opt-n"><?php echo htmlspecialchars($c['icon'] . ' ' . $c['name']); ?></div>
          <?php if ($c['description']): ?><div class="opt-d"><?php echo htmlspecialchars($c['description']); ?></div><?php endif; ?>
        </div>
        <div class="opt-p"><?php echo $c['is_libre'] ? 'Libre' : number_format((float)$c['amount'], 2, ',', '.') . ' &euro;'; ?></div>
      </label>
      <?php endforeach; ?>

      <div class="libre-w" id="libre-w">
        <label>Importe</label>
        <input type="number" name="custom_amount" id="camt" min="0.01" step="0.01" placeholder="0,00" oninput="updAmt(this.value)">
      </div>
    </div>

    <div class="amtbox" id="amtbox" style="display:none;">
      <div class="l">Total a pagar</div>
      <div class="v" id="amtval">&mdash;</div>
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="slbl">Tus datos</div>
      <label>Nombre completo</label>
      <input type="text" name="customer_name" placeholder="Juan Garcia"
             value="<?php echo htmlspecialchars(isset($_POST['customer_name']) ? $_POST['customer_name'] : ''); ?>" required>
      <label>Email (recibiras el justificante)</label>
      <input type="email" name="customer_email" placeholder="juan@email.com"
             value="<?php echo htmlspecialchars(isset($_POST['customer_email']) ? $_POST['customer_email'] : ''); ?>" required>
    </div>

    <button type="submit" class="pay-btn">Pagar ahora &rarr;</button>
    <div class="foot2"><div class="rdot"></div> Procesado por Redsys &middot; SSL</div>
  </form>
  <?php endif; ?>

  <?php if ($siteFooter): ?>
  <div class="sitefooter"><?php echo nl2br(htmlspecialchars($siteFooter)); ?></div>
  <?php endif; ?>
</div>

<script>
var cjs = <?php echo json_encode($cjs); ?>;

function selOpt(el, id, libre, amt) {
  document.querySelectorAll('.opt').forEach(function(o) { o.classList.remove('on'); });
  el.classList.add('on');
  el.querySelector('input').checked = true;
  var lw  = document.getElementById('libre-w');
  var ab  = document.getElementById('amtbox');
  var av  = document.getElementById('amtval');
  if (libre) {
    if (lw) lw.style.display = 'block';
    if (ab) ab.style.display = 'none';
  } else {
    if (lw) lw.style.display = 'none';
    if (ab) { ab.style.display = 'block'; av.textContent = amt.toFixed(2).replace('.', ',') + ' \u20ac'; }
  }
}

function updAmt(v) {
  var n = parseFloat(v);
  var ab = document.getElementById('amtbox');
  var av = document.getElementById('amtval');
  if (ab && av) {
    if (!isNaN(n) && n > 0) { ab.style.display = 'block'; av.textContent = n.toFixed(2).replace('.', ',') + ' \u20ac'; }
    else ab.style.display = 'none';
  }
}

// Init primer concepto en modo multi
(function() {
  var f = document.querySelector('.opt');
  if (!f || cjs.length === 0) return;
  selOpt(f, cjs[0].id, cjs[0].libre, cjs[0].amount);
})();
</script>
</body>
</html>
