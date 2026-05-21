<?php
// Toggle y otras acciones AJAX — ANTES del header HTML
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax'])) {
    require_once __DIR__ . '/../lib/db.php';
    require_once __DIR__ . '/../lib/Auth.php';
    Auth::requireLogin();
    Auth::checkCsrf();

    $action = isset($_POST['action']) ? $_POST['action'] : '';
    if ($action === 'toggle') {
        $val = (int)$_POST['value'];
        $id  = (int)$_POST['id'];
        db()->prepare('UPDATE concepts SET active=? WHERE id=?')->execute(array($val, $id));
        Auth::logAction('concept_toggle', 'id=' . $id . ' active=' . $val);
        header('Content-Type: application/json');
        echo '{"ok":true}';
    }
    exit;
}

$pageTitle = 'Conceptos de cobro';
require_once __DIR__ . '/_header.php';

$msg = ''; $msgType = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::checkCsrf();
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'save') {
        $id       = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
        $name     = trim(htmlspecialchars(isset($_POST['name']) ? $_POST['name'] : ''));
        $desc     = trim(htmlspecialchars(isset($_POST['description']) ? $_POST['description'] : ''));
        $icon     = trim(isset($_POST['icon']) ? $_POST['icon'] : '');
        $isLibre  = (int)(isset($_POST['is_libre']) ? $_POST['is_libre'] : 0);
        $amount   = $isLibre ? null : round((float)str_replace(',', '.', isset($_POST['amount']) ? $_POST['amount'] : '0'), 2);
        $minAmt   = ($isLibre && isset($_POST['min_amount']) && $_POST['min_amount'] !== '')
                    ? round((float)str_replace(',', '.', $_POST['min_amount']), 2) : null;
        $maxAmt   = ($isLibre && isset($_POST['max_amount']) && $_POST['max_amount'] !== '')
                    ? round((float)str_replace(',', '.', $_POST['max_amount']), 2) : null;
        $prefix   = strtoupper(preg_replace('/[^A-Z0-9]/', '', isset($_POST['ref_prefix']) ? $_POST['ref_prefix'] : 'PAY'));
        if (!$prefix) $prefix = 'PAY';
        $sort          = (int)(isset($_POST['sort_order']) ? $_POST['sort_order'] : 0);
        $active        = (int)(isset($_POST['active']) ? $_POST['active'] : 0);
        $visibleIdx    = (int)(isset($_POST['visible_en_index']) ? $_POST['visible_en_index'] : 0);
        $fechaLim      = (isset($_POST['fecha_limite']) && $_POST['fecha_limite'] !== '') ? $_POST['fecha_limite'] : null;
        $maxPagos      = (isset($_POST['max_pagos']) && $_POST['max_pagos'] !== '') ? (int)$_POST['max_pagos'] : null;
        $urlOkCusRaw   = isset($_POST['url_ok_custom']) ? trim($_POST['url_ok_custom']) : '';
        $urlOkCus      = $urlOkCusRaw !== '' ? $urlOkCusRaw : null;

        if (!$name) {
            $msg = 'El nombre es obligatorio.'; $msgType = 'err';
        } elseif ($minAmt !== null && $maxAmt !== null && $minAmt > $maxAmt) {
            $msg = 'El importe minimo no puede ser mayor que el maximo.'; $msgType = 'err';
        } elseif ($urlOkCus !== null) {
            $scheme = parse_url($urlOkCus, PHP_URL_SCHEME);
            if (!in_array($scheme, array('http', 'https'))) {
                $msg = 'La URL de exito personalizada debe comenzar por https:// o http://.'; $msgType = 'err';
            }
        }

        if ($msgType !== 'err') {
            if ($id) {
                db()->prepare(
                    'UPDATE concepts SET name=?,description=?,icon=?,is_libre=?,amount=?,
                     min_amount=?,max_amount=?,ref_prefix=?,sort_order=?,active=?,visible_en_index=?,
                     fecha_limite=?,max_pagos=?,url_ok_custom=?,updated_at=NOW() WHERE id=?'
                )->execute(array(
                    $name, $desc, $icon, $isLibre, $amount,
                    $minAmt, $maxAmt, $prefix, $sort, $active, $visibleIdx,
                    $fechaLim, $maxPagos, $urlOkCus, $id
                ));
                Auth::logAction('concept_edit', $name);
                $msg = 'Concepto actualizado correctamente.';
            } else {
                db()->prepare(
                    'INSERT INTO concepts
                     (name,description,icon,is_libre,amount,min_amount,max_amount,
                      ref_prefix,sort_order,active,visible_en_index,fecha_limite,max_pagos,url_ok_custom)
                     VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                )->execute(array(
                    $name, $desc, $icon, $isLibre, $amount,
                    $minAmt, $maxAmt, $prefix, $sort, $active, $visibleIdx,
                    $fechaLim, $maxPagos, $urlOkCus
                ));
                Auth::logAction('concept_create', $name);
                $msg = 'Concepto creado correctamente.';
            }
        }
    }

    if ($action === 'delete') {
        $delId = (int)$_POST['id'];
        $delName = db()->prepare('SELECT name FROM concepts WHERE id=?');
        $delName->execute(array($delId));
        $delNameVal = $delName->fetchColumn();
        db()->prepare('DELETE FROM concepts WHERE id=?')->execute(array($delId));
        Auth::logAction('concept_delete', $delNameVal ?: 'id=' . $delId);
        $msg = 'Concepto eliminado.';
    }
}

$editId = (int)(isset($_GET['edit']) ? $_GET['edit'] : 0);
$edit   = null;
if ($editId) {
    $st = db()->prepare('SELECT * FROM concepts WHERE id=?');
    $st->execute(array($editId));
    $edit = $st->fetch();
}
$concepts = db()->query('SELECT * FROM concepts ORDER BY sort_order ASC, id ASC')->fetchAll();

$proto   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host    = $_SERVER['HTTP_HOST'];
$base    = rtrim(str_replace('/admin', '', dirname($_SERVER['SCRIPT_NAME'])), '/');
$baseUrl = $proto . '://' . $host . $base;

$pagosCount = array();
$rows = db()->query("SELECT concept_id, COUNT(*) as cnt FROM transactions WHERE status='ok' GROUP BY concept_id")->fetchAll();
foreach ($rows as $r) $pagosCount[(int)$r['concept_id']] = (int)$r['cnt'];

$fv = $edit ? $edit : array(
    'id'=>0,'name'=>'','description'=>'','icon'=>'','is_libre'=>0,'amount'=>'',
    'min_amount'=>'','max_amount'=>'','ref_prefix'=>'PAY','sort_order'=>0,'active'=>1,
    'visible_en_index'=>1,'fecha_limite'=>'','max_pagos'=>'','url_ok_custom'=>''
);
if ($msgType === 'err' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($fv as $k => $v) {
        if (isset($_POST[$k])) $fv[$k] = $_POST[$k];
    }
}
$isLibreVal  = (int)$fv['is_libre'];
$visIdxVal   = isset($fv['visible_en_index']) ? (int)$fv['visible_en_index'] : 1;
?>

<?php if ($msg): ?>
<div class="alert <?php echo $msgType; ?>"><?php echo htmlspecialchars($msg); ?></div>
<?php endif; ?>

<!-- Formulario crear/editar -->
<div class="card">
  <div class="card-t"><?php echo $edit ? 'Editar concepto' : 'Nuevo concepto'; ?></div>
  <form method="POST" action="concepts.php">
    <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?php echo (int)$fv['id']; ?>">
    <input type="hidden" name="active" value="0">
    <input type="hidden" name="visible_en_index" value="0">

    <div class="r2">
      <div class="field"><label>Nombre *</label>
        <input type="text" name="name" required
               value="<?php echo htmlspecialchars($fv['name']); ?>"
               placeholder="Inscripcion carrera">
      </div>
      <div class="field" style="max-width:110px;"><label>Icono/emoji</label>
        <input type="text" name="icon"
               value="<?php echo htmlspecialchars($fv['icon']); ?>"
               placeholder="🏁">
      </div>
    </div>

    <div class="field"><label>Descripcion visible al cliente</label>
      <input type="text" name="description"
             value="<?php echo htmlspecialchars($fv['description']); ?>"
             placeholder="Texto descriptivo">
    </div>

    <!-- Importe -->
    <div style="background:#f9f9f9;border:1px solid #eee;border-radius:8px;padding:14px;margin-bottom:12px;">
      <div style="font-size:12px;font-weight:600;color:#888;text-transform:uppercase;letter-spacing:.05em;margin-bottom:10px;">Importe</div>
      <input type="hidden" name="is_libre" id="is-libre-val" value="<?php echo $isLibreVal; ?>">
      <div style="display:flex;gap:20px;margin-bottom:12px;">
        <label style="display:flex;align-items:center;gap:7px;font-size:13px;cursor:pointer;">
          <input type="radio" name="_tipo" value="fijo"
                 <?php echo !$isLibreVal ? 'checked' : ''; ?>
                 onchange="setLibre(0)">
          <strong>Fijo</strong>
        </label>
        <label style="display:flex;align-items:center;gap:7px;font-size:13px;cursor:pointer;">
          <input type="radio" name="_tipo" value="libre"
                 <?php echo $isLibreVal ? 'checked' : ''; ?>
                 onchange="setLibre(1)">
          <strong>Libre</strong>
          <span style="color:#888;font-size:12px;">(el cliente introduce el importe)</span>
        </label>
      </div>
      <div id="bloque-fijo" style="display:<?php echo $isLibreVal ? 'none' : 'block'; ?>;">
        <div class="field" style="max-width:200px;"><label>Importe (EUR)</label>
          <input type="number" name="amount" step="0.01" min="0"
                 value="<?php echo $fv['amount'] !== null && $fv['amount'] !== '' ? (float)$fv['amount'] : ''; ?>"
                 placeholder="45.00">
        </div>
      </div>
      <div id="bloque-libre" style="display:<?php echo $isLibreVal ? 'block' : 'none'; ?>;">
        <div class="r3">
          <div class="field"><label>Importe minimo (EUR)</label>
            <input type="number" name="min_amount" step="0.01" min="0"
                   value="<?php echo $fv['min_amount'] !== null && $fv['min_amount'] !== '' ? (float)$fv['min_amount'] : ''; ?>"
                   placeholder="Ej: 5.00">
          </div>
          <div class="field"><label>Importe maximo (EUR)</label>
            <input type="number" name="max_amount" step="0.01" min="0"
                   value="<?php echo $fv['max_amount'] !== null && $fv['max_amount'] !== '' ? (float)$fv['max_amount'] : ''; ?>"
                   placeholder="Ej: 500.00">
          </div>
          <div class="field"><label style="color:#aaa;">Dejar vacio = sin limite</label>
            <input type="text" disabled style="background:#f5f5f5;color:#aaa;" value="(opcional)">
          </div>
        </div>
      </div>
    </div>

    <!-- Control de acceso -->
    <div style="background:#f9f9f9;border:1px solid #eee;border-radius:8px;padding:14px;margin-bottom:12px;">
      <div style="font-size:12px;font-weight:600;color:#888;text-transform:uppercase;letter-spacing:.05em;margin-bottom:10px;">Control de acceso (opcional)</div>
      <div class="r3">
        <div class="field">
          <label>Fecha limite</label>
          <input type="date" name="fecha_limite"
                 value="<?php echo htmlspecialchars($fv['fecha_limite'] ?: ''); ?>">
          <p style="font-size:11px;color:#aaa;margin-top:3px;">Se cierra automaticamente al llegar esta fecha</p>
        </div>
        <div class="field">
          <label>Max. pagos / plazas</label>
          <input type="number" name="max_pagos" min="1"
                 value="<?php echo $fv['max_pagos'] ? (int)$fv['max_pagos'] : ''; ?>"
                 placeholder="Ej: 30">
          <p style="font-size:11px;color:#aaa;margin-top:3px;">Al alcanzarlo se cierra automaticamente</p>
        </div>
        <div class="field">
          <label>URL exito personalizada</label>
          <input type="url" name="url_ok_custom"
                 value="<?php echo htmlspecialchars($fv['url_ok_custom'] ?: ''); ?>"
                 placeholder="https://tudominio.com/gracias">
          <p style="font-size:11px;color:#aaa;margin-top:3px;">Debe empezar por https://</p>
        </div>
      </div>
    </div>

    <!-- Opciones avanzadas -->
    <div class="r3">
      <div class="field"><label>Prefijo referencia (max 4)</label>
        <input type="text" name="ref_prefix" maxlength="4"
               value="<?php echo htmlspecialchars($fv['ref_prefix'] ?: 'PAY'); ?>"
               style="width:80px;" placeholder="PAY">
      </div>
      <div class="field"><label>Orden (menor = primero)</label>
        <input type="number" name="sort_order" min="0"
               value="<?php echo (int)$fv['sort_order']; ?>"
               style="width:80px;">
      </div>
      <div class="field" style="display:flex;flex-direction:column;justify-content:flex-end;gap:6px;padding-bottom:2px;">
        <label style="display:flex;align-items:center;gap:7px;margin-top:22px;cursor:pointer;font-size:13px;">
          <input type="checkbox" name="active" value="1"
                 <?php echo $fv['active'] ? 'checked' : ''; ?>>
          Activo
        </label>
        <label style="display:flex;align-items:center;gap:7px;cursor:pointer;font-size:13px;">
          <input type="checkbox" name="visible_en_index" value="1"
                 <?php echo $visIdxVal ? 'checked' : ''; ?>>
          Visible en pagina principal
        </label>
      </div>
    </div>

    <div style="margin-top:8px;display:flex;gap:10px;">
      <button type="submit" class="btn"><?php echo $edit ? 'Guardar cambios' : 'Crear concepto'; ?></button>
      <?php if ($edit): ?>
        <a href="concepts.php" class="btn-o">Cancelar</a>
      <?php endif; ?>
    </div>
  </form>
</div>

<!-- Lista de conceptos -->
<div class="card">
  <div class="card-t">Conceptos existentes</div>
  <div style="overflow-x:auto;">
  <table>
    <thead>
      <tr><th>Activo</th><th>Index</th><th>Concepto</th><th>Importe</th><th>Pagos OK</th><th>Plazas</th><th>Fecha limite</th><th>Link directo</th><th>Acciones</th></tr>
    </thead>
    <tbody>
    <?php if (empty($concepts)): ?>
      <tr><td colspan="9" style="text-align:center;color:#aaa;padding:18px;">No hay conceptos creados</td></tr>
    <?php endif; ?>
    <?php foreach ($concepts as $c):
      $pagosOk  = isset($pagosCount[(int)$c['id']]) ? $pagosCount[(int)$c['id']] : 0;
      $cerrado  = false; $cerradoTxt = '';
      if ($c['fecha_limite'] && $c['fecha_limite'] < date('Y-m-d')) { $cerrado = true; $cerradoTxt = 'Fecha limite'; }
      if ($c['max_pagos'] && $pagosOk >= (int)$c['max_pagos']) { $cerrado = true; $cerradoTxt = 'Plazas completas'; }
      $visIdx = isset($c['visible_en_index']) ? (int)$c['visible_en_index'] : 1;
    ?>
      <tr style="<?php echo $cerrado ? 'opacity:.65' : ''; ?>">
        <td>
          <label class="toggle">
            <input type="checkbox" <?php echo $c['active'] ? 'checked' : ''; ?>
                   onchange="toggleC(<?php echo (int)$c['id']; ?>, this.checked)">
            <span class="sl"></span>
          </label>
        </td>
        <td style="text-align:center;">
          <?php if ($visIdx): ?>
            <span style="color:#1a7a3a;font-size:14px;" title="Visible en index">&#10003;</span>
          <?php else: ?>
            <span style="color:#ccc;font-size:14px;" title="Oculto en index">&mdash;</span>
          <?php endif; ?>
        </td>
        <td>
          <div style="font-weight:500;"><?php echo htmlspecialchars($c['icon'] . ' ' . $c['name']); ?></div>
          <div style="font-size:11px;color:#aaa;"><?php echo htmlspecialchars($c['description']); ?></div>
          <?php if ($cerrado): ?>
            <span style="font-size:10px;background:#fff1f0;color:#c00;border-radius:4px;padding:1px 6px;"><?php echo $cerradoTxt; ?></span>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($c['is_libre']): ?>
            <em style="color:#888;">Libre</em>
            <?php if ($c['min_amount'] || $c['max_amount']): ?>
              <div style="font-size:11px;color:#aaa;">
                <?php echo $c['min_amount'] ? number_format((float)$c['min_amount'],2,',','.') . ' min' : ''; ?>
                <?php echo ($c['min_amount'] && $c['max_amount']) ? ' / ' : ''; ?>
                <?php echo $c['max_amount'] ? number_format((float)$c['max_amount'],2,',','.') . ' max' : ''; ?>
              </div>
            <?php endif; ?>
          <?php else: ?>
            <?php echo number_format((float)$c['amount'], 2, ',', '.'); ?> &euro;
          <?php endif; ?>
        </td>
        <td>
          <strong><?php echo $pagosOk; ?></strong>
          <?php if ($c['max_pagos']): ?>
            <span style="color:#888;font-size:11px;"> / <?php echo (int)$c['max_pagos']; ?></span>
            <?php $pct = $c['max_pagos'] > 0 ? min(100, round($pagosOk / $c['max_pagos'] * 100)) : 0; ?>
            <div style="background:#f0f0f0;border-radius:4px;height:4px;margin-top:4px;width:60px;">
              <div style="background:<?php echo $pct >= 100 ? '#c0392b' : ($pct >= 70 ? '#e67e22' : '#1a7a3a'); ?>;
                          height:4px;border-radius:4px;width:<?php echo $pct; ?>%;"></div>
            </div>
          <?php endif; ?>
        </td>
        <td style="font-size:12px;">
          <?php if ($c['max_pagos']): ?>
            <?php $quedan = max(0, (int)$c['max_pagos'] - $pagosOk); ?>
            <span style="color:<?php echo $quedan <= 0 ? '#c0392b' : ($quedan <= 3 ? '#e67e22' : '#1a7a3a'); ?>;">
              <?php echo $quedan; ?> libre<?php echo $quedan !== 1 ? 's' : ''; ?>
            </span>
          <?php else: ?>
            <span style="color:#aaa;">&infin;</span>
          <?php endif; ?>
        </td>
        <td style="font-size:12px;">
          <?php echo $c['fecha_limite'] ? date('d/m/Y', strtotime($c['fecha_limite'])) : '<span style="color:#aaa;">&mdash;</span>'; ?>
        </td>
        <td>
          <input type="text" readonly
                 onclick="this.select();document.execCommand('copy');showToast('Link copiado', true);"
                 value="<?php echo htmlspecialchars($baseUrl . '/index.php?concept=' . $c['id']); ?>"
                 style="font-size:11px;width:180px;cursor:pointer;border:1px solid #ddd;border-radius:5px;padding:4px 6px;"
                 title="Clic para copiar el link">
        </td>
        <td style="white-space:nowrap;">
          <a href="concepts.php?edit=<?php echo (int)$c['id']; ?>" class="btn-s">Editar</a>
          <a href="concepto_stats.php?id=<?php echo (int)$c['id']; ?>" class="btn-s">Stats</a>
          <form method="POST" action="concepts.php" style="display:inline;"
                onsubmit="return confirm('Eliminar este concepto? Los pagos existentes no se borran.')">
            <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>">
            <button type="submit" class="btn-s" style="color:#c00;border-color:#fcc;">Eliminar</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<div id="toast-c" style="display:none;position:fixed;bottom:24px;right:24px;background:#1a1a1a;
     color:#fff;padding:12px 20px;border-radius:8px;font-size:13px;z-index:9999;
     box-shadow:0 4px 20px rgba(0,0,0,.3);transition:opacity .2s;"></div>

<script>
function setLibre(v) {
    document.getElementById('is-libre-val').value   = v;
    document.getElementById('bloque-fijo').style.display   = v ? 'none'  : 'block';
    document.getElementById('bloque-libre').style.display  = v ? 'block' : 'none';
}

function toggleC(id, active) {
    var fd = new FormData();
    fd.append('action', 'toggle');
    fd.append('ajax', '1');
    fd.append('id', id);
    fd.append('value', active ? 1 : 0);
    fd.append('_csrf', _csrf);
    fetch('concepts.php', { method: 'POST', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(d){ if(d.ok) showToast(active ? 'Activado' : 'Desactivado', true); });
}

function showToast(msg, ok) {
    var t = document.getElementById('toast-c');
    t.textContent = msg;
    t.style.background = ok ? '#1a7a3a' : '#c0392b';
    t.style.display = 'block';
    t.style.opacity = '1';
    setTimeout(function() { t.style.opacity='0'; setTimeout(function(){t.style.display='none';t.style.opacity='1';},200); }, 2500);
}
</script>

<?php require_once __DIR__ . '/_footer.php'; ?>
