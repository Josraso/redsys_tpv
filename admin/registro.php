<?php
// CSV export ANTES de cualquier output HTML
if (isset($_GET['export'])) {
    require_once __DIR__ . '/../lib/db.php';
    require_once __DIR__ . '/../lib/Auth.php';
    Auth::requireLogin();

    $fs = isset($_GET['status'])   ? $_GET['status']   : '';
    $fc = isset($_GET['concept'])  ? $_GET['concept']  : '';
    $fd = isset($_GET['date'])     ? $_GET['date']     : '';
    $fq = trim(isset($_GET['q'])   ? $_GET['q']        : '');
    $fa = isset($_GET['archived']) ? (int)$_GET['archived'] : 0;

    $w = array('1=1'); $p = array();
    if ($fs) { $w[] = 'status=?';           $p[] = $fs; }
    if ($fc) { $w[] = 'concept_name=?';     $p[] = $fc; }
    if ($fd) { $w[] = 'DATE(created_at)=?'; $p[] = $fd; }
    if ($fq) {
        $w[] = '(customer_name LIKE ? OR customer_email LIKE ? OR order_ref LIKE ?)';
        $p[] = "%$fq%"; $p[] = "%$fq%"; $p[] = "%$fq%";
    }
    $w[] = 'archived=?'; $p[] = $fa;
    $ws   = implode(' AND ', $w);
    $rows = db()->prepare("SELECT * FROM transactions WHERE $ws ORDER BY created_at DESC LIMIT 10000");
    $rows->execute($p);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="pagos_' . date('Ymd_His') . '.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, array('Fecha','Nombre','Email','Concepto','Importe EUR','Referencia','Autorizacion Redsys','Email enviado','Estado'), ';');
    while ($r = $rows->fetch()) {
        fputcsv($out, array(
            $r['created_at'],
            $r['customer_name'],
            $r['customer_email'],
            $r['concept_name'],
            number_format((float)$r['amount'], 2, ',', '.'),
            $r['order_ref'],
            $r['redsys_auth'] ?: '',
            $r['email_sent'] ? 'Si' : 'No',
            $r['status']
        ), ';');
    }
    fclose($out);
    exit;
}

// AJAX: reenviar email
if (isset($_POST['action']) && $_POST['action'] === 'resend' && !empty($_POST['ajax'])) {
    require_once __DIR__ . '/../lib/db.php';
    require_once __DIR__ . '/../lib/Auth.php';
    require_once __DIR__ . '/../lib/Mailer.php';
    Auth::requireLogin();
    Auth::checkCsrf();

    $txId = (int)(isset($_POST['tx_id']) ? $_POST['tx_id'] : 0);
    $st   = db()->prepare('SELECT * FROM transactions WHERE id=? AND status="ok"');
    $st->execute(array($txId));
    $tx = $st->fetch();

    if (!$tx || empty($tx['customer_email'])) {
        echo json_encode(array('ok' => false, 'msg' => 'Transaccion no encontrada o sin email'));
    } else {
        $mailer  = new Mailer();
        $subject = str_replace(array('{concepto}','{nombre}'), array($tx['concept_name'],$tx['customer_name']),
                    getSetting('mail_subject_cliente', 'Confirmacion de pago - {concepto}'));
        $result  = $mailer->send($tx['customer_email'], $tx['customer_name'], $subject, Mailer::templateCliente($tx));
        if ($result['ok']) {
            db()->prepare('UPDATE transactions SET email_sent=1, email_error=NULL WHERE id=?')->execute(array($txId));
            Auth::logAction('email_resend', 'tx_id=' . $txId . ' to=' . $tx['customer_email']);
            echo json_encode(array('ok' => true, 'msg' => 'Email reenviado a ' . $tx['customer_email']));
        } else {
            echo json_encode(array('ok' => false, 'msg' => 'Error: ' . $result['error']));
        }
    }
    header('Content-Type: application/json');
    exit;
}

// AJAX: archivar / desarchivar
if (isset($_POST['action']) && $_POST['action'] === 'archive' && !empty($_POST['ajax'])) {
    require_once __DIR__ . '/../lib/db.php';
    require_once __DIR__ . '/../lib/Auth.php';
    Auth::requireLogin();
    Auth::checkCsrf();

    $txId = (int)(isset($_POST['tx_id']) ? $_POST['tx_id'] : 0);
    $st   = db()->prepare('SELECT archived FROM transactions WHERE id=?');
    $st->execute(array($txId));
    $row  = $st->fetch();

    if (!$row) {
        echo json_encode(array('ok' => false, 'msg' => 'No encontrado'));
    } else {
        $newVal = $row['archived'] ? 0 : 1;
        db()->prepare('UPDATE transactions SET archived=? WHERE id=?')->execute(array($newVal, $txId));
        Auth::logAction('tx_archive', 'tx_id=' . $txId . ' archived=' . $newVal);
        echo json_encode(array('ok' => true, 'archived' => $newVal));
    }
    header('Content-Type: application/json');
    exit;
}

// -- HTML normal --
$pageTitle = 'Registro de pagos';
require_once __DIR__ . '/_header.php';

$cleanMsg = '';

// Limpiar transacciones no completadas
if (isset($_POST['action']) && $_POST['action'] === 'clean_incomplete') {
    Auth::checkCsrf();
    $deleted  = db()->exec("DELETE FROM transactions WHERE status IN ('pending','error','cancelled')");
    $cleanMsg = array('ok', 'Eliminadas ' . $deleted . ' transacciones no completadas. Los pagos OK intactos.');
}

// Guardar nota interna
if (isset($_POST['action']) && $_POST['action'] === 'save_note') {
    Auth::checkCsrf();
    $txId = (int)$_POST['tx_id'];
    $note = trim(htmlspecialchars($_POST['note']));
    db()->prepare('UPDATE transactions SET notes=? WHERE id=?')->execute(array($note, $txId));
}

// Borrar TODOS los registros
if (isset($_POST['action']) && $_POST['action'] === 'delete_all') {
    Auth::checkCsrf();
    $deleted  = db()->exec("DELETE FROM transactions");
    Auth::logAction('delete_all_transactions', 'deleted=' . $deleted);
    $cleanMsg = array('ok', 'Borrados todos los registros (' . $deleted . ' transacciones). La base de datos esta limpia.');
}

// Filtros
$fs   = isset($_GET['status'])   ? $_GET['status']   : '';
$fc   = isset($_GET['concept'])  ? $_GET['concept']  : '';
$fd   = isset($_GET['date'])     ? $_GET['date']     : '';
$fq   = trim(isset($_GET['q'])   ? $_GET['q']        : '');
$fa   = isset($_GET['archived']) ? (int)$_GET['archived'] : 0; // 0=activos, 1=archivados
$page = max(1, (int)(isset($_GET['page']) ? $_GET['page'] : 1));
$pp   = 50;

$w = array('1=1'); $p = array();
if ($fs) { $w[] = 'status=?';           $p[] = $fs; }
if ($fc) { $w[] = 'concept_name=?';     $p[] = $fc; }
if ($fd) { $w[] = 'DATE(created_at)=?'; $p[] = $fd; }
if ($fq) {
    $w[] = '(customer_name LIKE ? OR customer_email LIKE ? OR order_ref LIKE ?)';
    $p[] = "%$fq%"; $p[] = "%$fq%"; $p[] = "%$fq%";
}
$w[] = 'archived=?'; $p[] = $fa;

$ws    = implode(' AND ', $w);
$total = db()->prepare("SELECT COUNT(*) FROM transactions WHERE $ws");
$total->execute($p);
$total  = (int)$total->fetchColumn();
$pages  = max(1, (int)ceil($total / $pp));
$offset = ($page - 1) * $pp;
$rows   = db()->prepare("SELECT * FROM transactions WHERE $ws ORDER BY created_at DESC LIMIT $pp OFFSET $offset");
$rows->execute($p);
$rows   = $rows->fetchAll();

$cnames = db()->query("SELECT DISTINCT concept_name FROM transactions ORDER BY concept_name")->fetchAll(PDO::FETCH_COLUMN);
$sum    = db()->prepare("SELECT COALESCE(SUM(amount),0) as t, COUNT(*) as c FROM transactions WHERE $ws AND status='ok'");
$sum->execute($p);
$sum    = $sum->fetch();

// Contadores para los tabs activos/archivados
$countActive   = (int)db()->query("SELECT COUNT(*) FROM transactions WHERE archived=0")->fetchColumn();
$countArchived = (int)db()->query("SELECT COUNT(*) FROM transactions WHERE archived=1")->fetchColumn();

// QS base sin archived ni page para reusar en links de paginacion
$qsBase = http_build_query(array_filter(array('status'=>$fs,'concept'=>$fc,'date'=>$fd,'q'=>$fq)));
$qs     = $qsBase . ($qsBase ? '&' : '') . 'archived=' . $fa;
?>

<?php if ($cleanMsg): ?>
<div class="alert <?php echo $cleanMsg[0]; ?>"><?php echo htmlspecialchars($cleanMsg[1]); ?></div>
<?php endif; ?>

<!-- Tabs archivados/activos -->
<div style="display:flex;gap:0;margin-bottom:14px;border-bottom:2px solid #e8e8e8;">
  <a href="registro.php?<?php echo $qsBase; ?>&archived=0"
     style="padding:9px 18px;font-size:13px;font-weight:600;text-decoration:none;border-bottom:2px solid <?php echo $fa===0?'#1a1a1a':'transparent'; ?>;margin-bottom:-2px;color:<?php echo $fa===0?'#1a1a1a':'#888'; ?>;">
    Activos <span style="background:#f0f0f0;border-radius:20px;padding:1px 8px;font-size:11px;font-weight:600;margin-left:4px;"><?php echo $countActive; ?></span>
  </a>
  <a href="registro.php?<?php echo $qsBase; ?>&archived=1"
     style="padding:9px 18px;font-size:13px;font-weight:600;text-decoration:none;border-bottom:2px solid <?php echo $fa===1?'#1a1a1a':'transparent'; ?>;margin-bottom:-2px;color:<?php echo $fa===1?'#1a1a1a':'#888'; ?>;">
    Archivados <span style="background:#f0f0f0;border-radius:20px;padding:1px 8px;font-size:11px;font-weight:600;margin-left:4px;"><?php echo $countArchived; ?></span>
  </a>
</div>

<!-- Filtros -->
<div class="card" style="padding:14px 18px;">
  <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
    <input type="hidden" name="archived" value="<?php echo $fa; ?>">
    <div>
      <label style="font-size:11px;color:#999;display:block;margin-bottom:3px;">Buscar</label>
      <input type="text" name="q" value="<?php echo htmlspecialchars($fq); ?>"
             placeholder="Nombre, email, referencia..."
             style="width:190px;border:1px solid #ddd;border-radius:7px;padding:8px 10px;font-size:13px;">
    </div>
    <div>
      <label style="font-size:11px;color:#999;display:block;margin-bottom:3px;">Estado</label>
      <select name="status" style="border:1px solid #ddd;border-radius:7px;padding:8px;font-size:13px;">
        <option value="">Todos</option>
        <option value="ok"      <?php echo $fs==='ok'?'selected':''; ?>>OK</option>
        <option value="error"   <?php echo $fs==='error'?'selected':''; ?>>Error</option>
        <option value="pending" <?php echo $fs==='pending'?'selected':''; ?>>Pendiente</option>
      </select>
    </div>
    <div>
      <label style="font-size:11px;color:#999;display:block;margin-bottom:3px;">Concepto</label>
      <select name="concept" style="border:1px solid #ddd;border-radius:7px;padding:8px;font-size:13px;">
        <option value="">Todos</option>
        <?php foreach ($cnames as $cn): ?>
        <option value="<?php echo htmlspecialchars($cn); ?>" <?php echo $fc===$cn?'selected':''; ?>>
          <?php echo htmlspecialchars($cn); ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label style="font-size:11px;color:#999;display:block;margin-bottom:3px;">Fecha</label>
      <input type="date" name="date" value="<?php echo htmlspecialchars($fd); ?>"
             style="border:1px solid #ddd;border-radius:7px;padding:8px;font-size:13px;">
    </div>
    <button type="submit" class="btn" style="height:37px;">Filtrar</button>
    <a href="registro.php?archived=<?php echo $fa; ?>" class="btn-o" style="height:37px;line-height:20px;">Limpiar</a>
    <a href="registro.php?<?php echo $qs; ?>&export=1"
       class="btn-o" style="height:37px;line-height:20px;">
      &#8595; Exportar CSV
    </a>
  </form>
</div>

<!-- Resumen + acciones -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px;">
  <p style="font-size:13px;color:#555;">
    <?php if ($sum['c'] > 0): ?>
      <strong><?php echo (int)$sum['c']; ?></strong> pagos OK &mdash;
      Total: <strong><?php echo number_format((float)$sum['t'],2,',','.'); ?> &euro;</strong>
      <?php if ($total > $sum['c']): ?>
        &nbsp;<span style="color:#aaa;">(<?php echo $total; ?> registros totales)</span>
      <?php endif; ?>
    <?php else: ?>
      Sin resultados para los filtros aplicados
    <?php endif; ?>
  </p>
  <?php if ($fa === 0): ?>
  <form method="POST" onsubmit="return confirm('Eliminar pendientes y errores? Los pagos OK no se tocan.');">
    <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
    <input type="hidden" name="action" value="clean_incomplete">
    <button type="submit" class="btn-s" style="color:#c00;border-color:#fcc;">
      Limpiar pendientes/errores
    </button>
  </form>
  <?php endif; ?>
</div>

<!-- Tabla -->
<div class="card">
  <div style="overflow-x:auto;">
  <table>
    <thead>
      <tr>
        <th>Fecha</th><th>Cliente</th><th>Concepto</th>
        <th>Importe</th><th>Referencia</th><th>Email</th><th>Nota</th><th>Estado</th><th>Acciones</th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($rows)): ?>
      <tr><td colspan="9" style="text-align:center;color:#aaa;padding:24px;">
        <?php echo $fa===1 ? 'No hay registros archivados' : 'Sin resultados'; ?>
      </td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $tx): ?>
      <tr id="row-<?php echo $tx['id']; ?>" <?php echo $tx['archived']?'style="opacity:.55;"':''; ?>>
        <td style="white-space:nowrap;"><?php echo date('d/m/Y H:i', strtotime($tx['created_at'])); ?></td>
        <td>
          <div style="font-weight:500;"><?php echo htmlspecialchars($tx['customer_name']); ?></div>
          <div style="font-size:11px;color:#aaa;"><?php echo htmlspecialchars($tx['customer_email']); ?></div>
        </td>
        <td><?php echo htmlspecialchars($tx['concept_name']); ?></td>
        <td style="font-weight:600;"><?php echo number_format((float)$tx['amount'],2,',','.'); ?> &euro;</td>
        <td><code style="font-size:11px;"><?php echo htmlspecialchars($tx['order_ref']); ?></code></td>
        <td style="text-align:center;">
          <?php if ($tx['status'] === 'ok'): ?>
            <span id="email-status-<?php echo $tx['id']; ?>">
              <?php if ($tx['email_sent']): ?>
                <span style="color:#1a7a3a;" title="Email enviado">&#10003;</span>
              <?php else: ?>
                <span style="color:#c00;" title="<?php echo htmlspecialchars($tx['email_error'] ?: 'No enviado'); ?>">&#10007;</span>
              <?php endif; ?>
            </span>
          <?php else: ?>&mdash;<?php endif; ?>
        </td>
        <td style="text-align:center;" data-note-row="<?php echo (int)$tx['id']; ?>">
          <span data-note-row="<?php echo (int)$tx['id']; ?>" class="note-cell">
          <?php if (!empty($tx['notes'])): ?>
            <span class="note-icon" title="<?php echo htmlspecialchars($tx['notes']); ?>"
                  style="cursor:pointer;font-size:15px;">&#128161;</span>
          <?php endif; ?>
          </span>
        </td>
        <td><?php echo badge($tx['status']); ?></td>
        <td style="white-space:nowrap;">
          <button class="btn-s" onclick="showDetail(<?php echo htmlspecialchars(json_encode($tx)); ?>)">Ver</button>
          <?php if ($tx['status'] === 'ok' && !$tx['archived']): ?>
          <button class="btn-s" onclick="resendEmail(<?php echo $tx['id']; ?>, this)"
                  title="Reenviar justificante al cliente">
            Reenviar
          </button>
          <?php endif; ?>
          <button class="btn-s" id="arch-btn-<?php echo $tx['id']; ?>"
                  onclick="archiveTx(<?php echo $tx['id']; ?>, this)"
                  title="<?php echo $tx['archived']?'Restaurar registro':'Archivar registro'; ?>"
                  style="<?php echo $tx['archived']?'color:#1a7a3a;border-color:#b7e4c7;':'color:#888;'; ?>">
            <?php echo $tx['archived'] ? 'Restaurar' : 'Archivar'; ?>
          </button>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <!-- Paginacion -->
  <?php if ($pages > 1): ?>
  <div style="display:flex;gap:5px;margin-top:14px;flex-wrap:wrap;align-items:center;">
    <?php if ($page > 1): ?>
    <a href="?<?php echo $qs; ?>&page=<?php echo $page-1; ?>"
       style="padding:5px 12px;border:1px solid #ddd;border-radius:5px;font-size:12px;text-decoration:none;color:#555;background:#fff;">&larr;</a>
    <?php endif; ?>
    <?php
    $pStart = max(1, $page - 3);
    $pEnd   = min($pages, $page + 3);
    if ($pStart > 1): ?><span style="font-size:12px;color:#aaa;padding:0 4px;">1 &hellip;</span><?php endif;
    for ($i = $pStart; $i <= $pEnd; $i++): ?>
      <a href="?<?php echo $qs; ?>&page=<?php echo $i; ?>"
         style="padding:5px 10px;border:1px solid <?php echo $i===$page?'#1a1a1a':'#ddd';?>;
                border-radius:5px;font-size:12px;text-decoration:none;
                color:<?php echo $i===$page?'#fff':'#555';?>;
                background:<?php echo $i===$page?'#1a1a1a':'#fff';?>;">
        <?php echo $i; ?>
      </a>
    <?php endfor;
    if ($pEnd < $pages): ?><span style="font-size:12px;color:#aaa;padding:0 4px;">&hellip; <?php echo $pages; ?></span><?php endif; ?>
    <?php if ($page < $pages): ?>
    <a href="?<?php echo $qs; ?>&page=<?php echo $page+1; ?>"
       style="padding:5px 12px;border:1px solid #ddd;border-radius:5px;font-size:12px;text-decoration:none;color:#555;background:#fff;">&rarr;</a>
    <?php endif; ?>
    <span style="font-size:12px;color:#aaa;margin-left:6px;">Pagina <?php echo $page; ?> de <?php echo $pages; ?> (<?php echo $total; ?> registros)</span>
  </div>
  <?php endif; ?>
</div>

<!-- Zona peligrosa -->
<div class="card" style="border:1px solid #fcc;margin-top:8px;">
  <div class="card-t" style="color:#c0392b;">Zona de peligro</div>
  <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:center;">
    <div style="flex:1;min-width:220px;">
      <div style="font-size:13px;font-weight:600;margin-bottom:3px;">Borrar todos los registros</div>
      <div style="font-size:12px;color:#888;">Elimina permanentemente todas las transacciones. Util para empezar desde cero tras pruebas.</div>
    </div>
    <button onclick="deleteAllRecords()"
            style="background:#c0392b;color:#fff;border:none;border-radius:7px;padding:9px 18px;font-size:13px;font-weight:600;cursor:pointer;white-space:nowrap;">
      &#9888; Borrar todos los registros
    </button>
  </div>
</div>

<form method="POST" id="form-delete-all" style="display:none;">
  <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
  <input type="hidden" name="action" value="delete_all">
</form>

<!-- Modal detalle -->
<div id="modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;
     background:rgba(0,0,0,.45);z-index:999;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:12px;padding:26px;max-width:520px;width:90%;
              max-height:85vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2);">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
      <strong style="font-size:15px;">Detalle de transaccion</strong>
      <button onclick="closeModal()" style="border:none;background:none;font-size:22px;cursor:pointer;color:#888;">&times;</button>
    </div>
    <div id="modal-body"></div>
    <div style="margin-top:16px;padding-top:16px;border-top:1px solid #f0f0f0;">
      <label style="font-size:12px;color:#888;display:block;margin-bottom:6px;">Nota interna (solo visible en el admin)</label>
      <textarea id="modal-note" rows="3"
                style="width:100%;border:1px solid #ddd;border-radius:7px;padding:8px;font-size:13px;resize:vertical;"></textarea>
      <button onclick="saveNote()" class="btn-s" style="margin-top:8px;">Guardar nota</button>
      <span id="note-msg" style="font-size:12px;color:#1a7a3a;margin-left:8px;display:none;">Guardada</span>
    </div>
  </div>
</div>

<div id="toast" style="display:none;position:fixed;bottom:24px;right:24px;
     background:#1a1a1a;color:#fff;padding:12px 20px;border-radius:8px;
     font-size:13px;z-index:9999;box-shadow:0 4px 20px rgba(0,0,0,.3);"></div>

<script>
var currentTxId = null;

function showDetail(tx) {
  currentTxId = tx.id;
  var rows = [
    ['ID', tx.id],
    ['Referencia', tx.order_ref],
    ['Nombre', tx.customer_name],
    ['Email', tx.customer_email],
    ['Concepto', tx.concept_name],
    ['Importe', parseFloat(tx.amount).toFixed(2).replace('.', ',') + ' EUR'],
    ['Estado', tx.status],
    ['Autorizacion Redsys', tx.redsys_auth || '-'],
    ['Cod. respuesta', tx.redsys_response || '-'],
    ['IP cliente', tx.customer_ip || '-'],
    ['Email enviado', tx.email_sent ? 'Si' : 'No'],
    ['Error email', tx.email_error || '-'],
    ['Archivado', tx.archived ? 'Si' : 'No'],
    ['Fecha', fmtDate(tx.created_at)],
    ['Actualizado', fmtDate(tx.updated_at)],
    ['Historial estados', (tx.status_log||'').replace(/\n/g,'<br>')]
  ];
  var h = '<table style="width:100%;font-size:13px;border-collapse:collapse;">';
  for (var i = 0; i < rows.length; i++) {
    h += '<tr><td style="padding:7px 0;color:#888;width:42%;border-bottom:1px solid #f5f5f5;">' + rows[i][0] + '</td>'
       + '<td style="padding:7px 0;font-weight:500;border-bottom:1px solid #f5f5f5;word-break:break-all;font-size:12px;">' + (rows[i][1] || '-') + '</td></tr>';
  }
  h += '</table>';
  document.getElementById('modal-body').innerHTML = h;
  document.getElementById('modal-note').value = tx.notes || '';
  document.getElementById('note-msg').style.display = 'none';
  document.getElementById('modal').style.display = 'flex';
}

function closeModal() {
  document.getElementById('modal').style.display = 'none';
}

function saveNote() {
  if (!currentTxId) return;
  var note = document.getElementById('modal-note').value;
  var fd = new FormData();
  fd.append('action', 'save_note');
  fd.append('tx_id', currentTxId);
  fd.append('note', note);
  fd.append('_csrf', _csrf);
  fetch('registro.php', { method: 'POST', body: fd })
    .then(function() {
      document.getElementById('note-msg').style.display = 'inline';
      setTimeout(function() { document.getElementById('note-msg').style.display = 'none'; }, 2000);
      updateNoteIcon(currentTxId, note);
    });
}

function resendEmail(txId, btn) {
  if (!confirm('Reenviar el justificante al cliente?')) return;
  btn.disabled = true; btn.textContent = '...';
  var fd = new FormData();
  fd.append('action', 'resend');
  fd.append('tx_id', txId);
  fd.append('ajax', '1');
  fd.append('_csrf', _csrf);
  fetch('registro.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      showToast(d.msg, d.ok);
      if (d.ok) {
        var el = document.getElementById('email-status-' + txId);
        if (el) el.innerHTML = '<span style="color:#1a7a3a;" title="Email enviado">&#10003;</span>';
      }
      btn.disabled = false; btn.textContent = 'Reenviar';
    })
    .catch(function() {
      showToast('Error de conexion', false);
      btn.disabled = false; btn.textContent = 'Reenviar';
    });
}

function archiveTx(txId, btn) {
  var isArchived = btn.textContent.trim() === 'Restaurar';
  var msg = isArchived ? 'Restaurar este registro?' : 'Archivar este registro? Ya no se mostrara en la vista principal.';
  if (!confirm(msg)) return;
  btn.disabled = true; btn.textContent = '...';
  var fd = new FormData();
  fd.append('action', 'archive');
  fd.append('tx_id', txId);
  fd.append('ajax', '1');
  fd.append('_csrf', _csrf);
  fetch('registro.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (d.ok) {
        // Quitar la fila de la vista actual
        var row = document.getElementById('row-' + txId);
        if (row) {
          row.style.transition = 'opacity .3s';
          row.style.opacity = '0';
          setTimeout(function() { row.remove(); }, 320);
        }
        showToast(d.archived ? 'Registro archivado' : 'Registro restaurado', true);
      } else {
        showToast('Error: ' + d.msg, false);
        btn.disabled = false;
        btn.textContent = isArchived ? 'Restaurar' : 'Archivar';
      }
    })
    .catch(function() {
      showToast('Error de conexion', false);
      btn.disabled = false;
      btn.textContent = isArchived ? 'Restaurar' : 'Archivar';
    });
}

function deleteAllRecords() {
  if (!confirm('ATENCION: Vas a borrar TODOS los registros de pago.\n\nEsta accion no se puede deshacer.')) return;
  var typed = prompt('Para confirmar escribe exactamente la palabra:\n\nBORRAR');
  if (typed === null) return;
  if (typed.trim() !== 'BORRAR') {
    alert('Texto incorrecto. Operacion cancelada.');
    return;
  }
  document.getElementById('form-delete-all').submit();
}

function showToast(msg, ok) {
  var t = document.getElementById('toast');
  t.textContent = msg;
  t.style.background = ok ? '#1a7a3a' : '#c0392b';
  t.style.display = 'block';
  setTimeout(function() { t.style.display = 'none'; }, 3500);
}

function updateNoteIcon(txId, note) {
  var iconEl = document.querySelector('.note-cell[data-note-row="' + txId + '"]');
  if (iconEl) {
    if (note.trim()) {
      iconEl.innerHTML = '<span class="note-icon" title="' + note.replace(/"/g,"'") + '" style="cursor:pointer;font-size:15px;">&#128161;</span>';
    } else {
      iconEl.innerHTML = '';
    }
  }
}

function fmtDate(s) {
  if (!s) return '-';
  var m = s.match(/^(\d{4})-(\d{2})-(\d{2})[\sT](\d{2}):(\d{2})/);
  if (m) return m[3] + '/' + m[2] + '/' + m[1] + ' ' + m[4] + ':' + m[5];
  return s;
}

document.getElementById('modal').addEventListener('click', function(e) {
  if (e.target === this) closeModal();
});
</script>

<?php require_once __DIR__ . '/_footer.php'; ?>
