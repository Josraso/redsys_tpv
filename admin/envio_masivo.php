<?php
// AJAX: enviar emails individuales
if (!empty($_POST['ajax']) && isset($_POST['action'])) {
    require_once __DIR__ . '/../lib/db.php';
    require_once __DIR__ . '/../lib/Auth.php';
    require_once __DIR__ . '/../lib/Mailer.php';
    Auth::requireLogin();

    if ($_POST['action'] === 'send_one') {
        $conceptId = (int)$_POST['concept_id'];
        $name      = trim(htmlspecialchars($_POST['name']));
        $email     = trim($_POST['email']);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(array('ok' => false, 'msg' => 'Email invalido: ' . $email));
            exit;
        }

        $st = db()->prepare('SELECT * FROM concepts WHERE id=? AND active=1');
        $st->execute(array($conceptId));
        $concept = $st->fetch();
        if (!$concept) {
            echo json_encode(array('ok' => false, 'msg' => 'Concepto no encontrado'));
            exit;
        }

        // Construir URL del concepto
        $proto  = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'];
        $base   = rtrim(str_replace('/admin', '', dirname($_SERVER['SCRIPT_NAME'])), '/');
        $url    = $proto . '://' . $host . $base . '/index.php?concept=' . $concept['public_token'];

        // Precio
        $precio = $concept['is_libre'] ? 'Importe libre' : number_format((float)$concept['amount'], 2, ',', '.') . ' EUR';

        // Email HTML
        $siteName = getSetting('site_name', 'TPV');
        $bodyHtml = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"></head>'
            . '<body style="font-family:Arial,sans-serif;background:#f5f5f5;margin:0;padding:20px;">'
            . '<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:8px;border:1px solid #e0e0e0;overflow:hidden;">'
            . '<div style="background:#1a1a1a;color:#fff;padding:24px 32px;">'
            . '<h1 style="margin:0;font-size:19px;">' . htmlspecialchars($siteName) . '</h1>'
            . '</div>'
            . '<div style="padding:28px 32px;">'
            . '<p>Hola <strong>' . htmlspecialchars($name ?: $email) . '</strong>,</p>'
            . '<p style="margin:14px 0;">Te enviamos el enlace de pago para:</p>'
            . '<div style="background:#f7f7f7;border-radius:8px;padding:16px;margin:16px 0;">'
            . '<div style="font-size:16px;font-weight:700;">' . htmlspecialchars($concept['name']) . '</div>'
            . ($concept['description'] ? '<div style="color:#666;margin-top:4px;">' . htmlspecialchars($concept['description']) . '</div>' : '')
            . '<div style="font-size:20px;font-weight:700;margin-top:10px;color:#1a1a1a;">' . $precio . '</div>'
            . '</div>'
            . '<div style="text-align:center;margin:24px 0;">'
            . '<a href="' . htmlspecialchars($url) . '" '
            . 'style="display:inline-block;background:#1a1a1a;color:#fff;text-decoration:none;'
            . 'padding:14px 32px;border-radius:8px;font-size:15px;font-weight:600;">'
            . 'Ir al pago &rarr;</a>'
            . '</div>'
            . '<p style="font-size:12px;color:#999;">O copia este enlace en tu navegador:<br>'
            . '<a href="' . htmlspecialchars($url) . '" style="color:#1a1a1a;">' . htmlspecialchars($url) . '</a></p>'
            . '</div>'
            . '<div style="padding:14px 32px;background:#fafafa;border-top:1px solid #f0f0f0;font-size:12px;color:#999;">'
            . htmlspecialchars(getSetting('mail_footer', ''))
            . '</div>'
            . '</div></body></html>';

        $subject = getSetting('mail_subject_envio', 'Tu enlace de pago - {concepto}');
        $subject = str_replace('{concepto}', $concept['name'], $subject);

        $mailer = new Mailer();
        $result = $mailer->send($email, $name ?: $email, $subject, $bodyHtml);

        header('Content-Type: application/json');
        echo json_encode(array(
            'ok'  => $result['ok'],
            'msg' => $result['ok'] ? 'Enviado a ' . $email : 'Error con ' . $email . ': ' . $result['error']
        ));
        exit;
    }
}

$pageTitle = 'Envio masivo';
require_once __DIR__ . '/_header.php';

$concepts = db()->query('SELECT * FROM concepts WHERE active=1 ORDER BY sort_order ASC, id ASC')->fetchAll();

// Construir URL base para preview
$proto  = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'];
$base   = rtrim(str_replace('/admin', '', dirname($_SERVER['SCRIPT_NAME'])), '/');
$baseUrl = $proto . '://' . $host . $base;
?>

<div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start;">

<!-- COLUMNA IZQUIERDA: configuracion -->
<div style="flex:1;min-width:280px;">

  <div class="card">
    <div class="card-t">Concepto a enviar</div>
    <div class="field">
      <label>Selecciona el concepto</label>
      <select id="sel-concept" onchange="updatePreview()" style="width:100%;border:1px solid #ddd;border-radius:7px;padding:9px;font-size:13px;">
        <option value="">-- Elige un concepto --</option>
        <?php foreach ($concepts as $c): ?>
        <option value="<?php echo (int)$c['id']; ?>"
                data-name="<?php echo htmlspecialchars($c['name']); ?>"
                data-precio="<?php echo $c['is_libre'] ? 'Libre' : number_format((float)$c['amount'],2,',','.').' EUR'; ?>"
                data-url="<?php echo htmlspecialchars($baseUrl . '/index.php?concept=' . $c['public_token']); ?>">
          <?php echo htmlspecialchars($c['icon'] . ' ' . $c['name']); ?>
          (<?php echo $c['is_libre'] ? 'Libre' : number_format((float)$c['amount'],2,',','.') . ' EUR'; ?>)
        </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label>Asunto del email</label>
      <input type="text" id="email-subject"
             value="<?php echo htmlspecialchars(getSetting('mail_subject_envio','Tu enlace de pago - {concepto}')); ?>"
             placeholder="Tu enlace de pago - {concepto}">
      <p style="font-size:11px;color:#aaa;margin-top:3px;">Variable disponible: <code>{concepto}</code></p>
    </div>
  </div>

  <!-- Preview URL y QR -->
  <div class="card" id="preview-card" style="display:none;">
    <div class="card-t">Link y QR del concepto</div>
    <div id="preview-url" style="font-size:12px;word-break:break-all;margin-bottom:12px;
         background:#f5f5f5;padding:8px 10px;border-radius:6px;"></div>
    <div style="display:flex;gap:8px;">
      <button class="btn-s" onclick="copyUrl()">Copiar link</button>
      <a id="qr-download" href="#" download="qr.svg" class="btn-s">Descargar QR</a>
    </div>
    <div style="margin-top:14px;text-align:center;">
      <div id="qr-container" style="display:inline-block;padding:12px;background:#fff;border:1px solid #e0e0e0;border-radius:8px;"></div>
    </div>
  </div>

</div>

<!-- COLUMNA DERECHA: destinatarios -->
<div style="flex:2;min-width:320px;">
  <div class="card">
    <div class="card-t">Destinatarios</div>

    <!-- Tabs de entrada -->
    <div style="display:flex;gap:0;margin-bottom:16px;border-bottom:1px solid #e8e8e8;">
      <button onclick="switchTab('manual')" id="tab-manual"
              style="padding:8px 16px;border:none;background:none;font-size:13px;cursor:pointer;
                     border-bottom:2px solid #1a1a1a;font-weight:600;">
        Escribir a mano
      </button>
      <button onclick="switchTab('paste')" id="tab-paste"
              style="padding:8px 16px;border:none;background:none;font-size:13px;cursor:pointer;
                     border-bottom:2px solid transparent;color:#888;">
        Pegar lista
      </button>
      <button onclick="switchTab('csv')" id="tab-csv"
              style="padding:8px 16px;border:none;background:none;font-size:13px;cursor:pointer;
                     border-bottom:2px solid transparent;color:#888;">
        Importar CSV
      </button>
    </div>

    <!-- Tab: manual -->
    <div id="panel-manual">
      <div id="manual-rows">
        <div class="manual-row" style="display:flex;gap:8px;margin-bottom:8px;">
          <input type="text" placeholder="Nombre" class="r-name"
                 style="flex:1;border:1px solid #ddd;border-radius:7px;padding:8px 10px;font-size:13px;">
          <input type="email" placeholder="email@dominio.com" class="r-email"
                 style="flex:2;border:1px solid #ddd;border-radius:7px;padding:8px 10px;font-size:13px;">
          <button onclick="removeRow(this)" style="border:1px solid #fcc;background:none;border-radius:7px;
                  padding:0 10px;color:#c00;cursor:pointer;font-size:16px;">&times;</button>
        </div>
      </div>
      <button onclick="addRow()" class="btn-s" style="margin-top:4px;">+ Anadir fila</button>
    </div>

    <!-- Tab: pegar -->
    <div id="panel-paste" style="display:none;">
      <p style="font-size:12px;color:#888;margin-bottom:8px;">
        Pega una lista. Cada linea: <code>nombre,email</code> o solo <code>email</code>
      </p>
      <textarea id="paste-area" rows="8" placeholder="Juan Garcia,juan@email.com&#10;Maria Lopez,maria@email.com&#10;otro@email.com"
                style="width:100%;border:1px solid #ddd;border-radius:7px;padding:10px;font-size:13px;resize:vertical;"
                oninput="parsePaste()"></textarea>
      <p style="font-size:12px;color:#aaa;margin-top:6px;" id="paste-count">0 destinatarios detectados</p>
    </div>

    <!-- Tab: CSV -->
    <div id="panel-csv" style="display:none;">
      <p style="font-size:12px;color:#888;margin-bottom:8px;">
        CSV con columnas <code>nombre</code> y <code>email</code> (o solo <code>email</code>).
        Separador: coma o punto y coma.
      </p>
      <input type="file" id="csv-file" accept=".csv,.txt"
             onchange="loadCSV(this)"
             style="font-size:13px;">
      <p style="font-size:12px;color:#aaa;margin-top:8px;" id="csv-count">Sin archivo cargado</p>
    </div>

    <!-- Lista resultante -->
    <div id="recipients-preview" style="display:none;margin-top:14px;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
        <div style="font-size:13px;font-weight:600;" id="rec-count-lbl">0 destinatarios</div>
        <button onclick="clearAll()" class="btn-s" style="color:#c00;">Limpiar todo</button>
      </div>
      <div id="rec-list" style="max-height:180px;overflow-y:auto;border:1px solid #e8e8e8;
           border-radius:7px;font-size:12px;"></div>
    </div>
  </div>

  <!-- Boton enviar -->
  <div class="card" style="padding:16px 20px;">
    <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
      <button onclick="startSend()" id="btn-send" class="btn" style="min-width:160px;">
        Enviar emails
      </button>
      <div id="send-status" style="font-size:13px;color:#888;"></div>
    </div>

    <!-- Barra de progreso -->
    <div id="progress-wrap" style="display:none;margin-top:14px;">
      <div style="background:#f0f0f0;border-radius:20px;height:8px;overflow:hidden;">
        <div id="progress-bar" style="background:#1a7a3a;height:100%;width:0%;transition:width .3s;border-radius:20px;"></div>
      </div>
      <div id="progress-log" style="margin-top:10px;max-height:200px;overflow-y:auto;
           font-size:12px;font-family:monospace;background:#f9f9f9;border-radius:7px;padding:8px;"></div>
    </div>
  </div>

</div>
</div>

<script>
// ── Estado global ───────────────────────────────────────────────────────────
var recipients = [];  // [{name:'', email:''}]
var currentConceptId = 0;
var currentConceptUrl = '';
var sending = false;

// ── QR (puro JS, sin libreria externa) ──────────────────────────────────────
// Generamos el QR usando la API de QR de goqr.me o generamos SVG simple
function generateQR(url) {
    var container = document.getElementById('qr-container');
    // Usar img con API publica de QR
    var img = document.createElement('img');
    img.src = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' + encodeURIComponent(url);
    img.width = 180;
    img.height = 180;
    img.style.display = 'block';
    img.alt = 'QR de pago';
    container.innerHTML = '';
    container.appendChild(img);

    // Link de descarga
    document.getElementById('qr-download').href =
        'https://api.qrserver.com/v1/create-qr-code/?size=400x400&format=svg&data=' + encodeURIComponent(url);
}

function copyUrl() {
    var el = document.createElement('textarea');
    el.value = currentConceptUrl;
    document.body.appendChild(el);
    el.select();
    document.execCommand('copy');
    document.body.removeChild(el);
    showToast('Link copiado al portapapeles', true);
}

function updatePreview() {
    var sel = document.getElementById('sel-concept');
    var opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) {
        document.getElementById('preview-card').style.display = 'none';
        currentConceptId  = 0;
        currentConceptUrl = '';
        return;
    }
    currentConceptId  = parseInt(opt.value);
    currentConceptUrl = opt.getAttribute('data-url');
    document.getElementById('preview-url').textContent = currentConceptUrl;
    document.getElementById('preview-card').style.display = 'block';
    generateQR(currentConceptUrl);
}

// ── Tabs ────────────────────────────────────────────────────────────────────
function switchTab(tab) {
    var tabs = ['manual','paste','csv'];
    tabs.forEach(function(t) {
        document.getElementById('panel-' + t).style.display = t === tab ? 'block' : 'none';
        var btn = document.getElementById('tab-' + t);
        btn.style.borderBottomColor = t === tab ? '#1a1a1a' : 'transparent';
        btn.style.fontWeight        = t === tab ? '600' : '400';
        btn.style.color             = t === tab ? '#1a1a1a' : '#888';
    });
    if (tab === 'manual') buildFromManual();
}

// ── Tab Manual ───────────────────────────────────────────────────────────────
function addRow() {
    var container = document.getElementById('manual-rows');
    var div = document.createElement('div');
    div.className = 'manual-row';
    div.style.cssText = 'display:flex;gap:8px;margin-bottom:8px;';
    div.innerHTML = '<input type="text" placeholder="Nombre" class="r-name" '
        + 'style="flex:1;border:1px solid #ddd;border-radius:7px;padding:8px 10px;font-size:13px;" oninput="buildFromManual()">'
        + '<input type="email" placeholder="email@dominio.com" class="r-email" '
        + 'style="flex:2;border:1px solid #ddd;border-radius:7px;padding:8px 10px;font-size:13px;" oninput="buildFromManual()">'
        + '<button onclick="removeRow(this)" style="border:1px solid #fcc;background:none;border-radius:7px;'
        + 'padding:0 10px;color:#c00;cursor:pointer;font-size:16px;">&times;</button>';
    container.appendChild(div);
}

function removeRow(btn) {
    btn.parentElement.remove();
    buildFromManual();
}

document.getElementById('manual-rows').querySelectorAll('input').forEach(function(i) {
    i.addEventListener('input', buildFromManual);
});

function buildFromManual() {
    var rows = document.querySelectorAll('#manual-rows .manual-row');
    var list = [];
    rows.forEach(function(row) {
        var email = row.querySelector('.r-email').value.trim();
        var name  = row.querySelector('.r-name').value.trim();
        if (email) list.push({name: name, email: email});
    });
    recipients = list;
    renderRecipients();
}

// ── Tab Pegar ────────────────────────────────────────────────────────────────
function parsePaste() {
    var text  = document.getElementById('paste-area').value;
    var lines = text.split('\n');
    var list  = [];
    lines.forEach(function(line) {
        line = line.trim();
        if (!line) return;
        var parts = line.split(/[,;]\s*/);
        var name  = '', email = '';
        if (parts.length >= 2) {
            // Determinar cual es email
            if (parts[1].indexOf('@') !== -1) { name = parts[0]; email = parts[1]; }
            else if (parts[0].indexOf('@') !== -1) { email = parts[0]; name = parts[1]; }
            else { name = parts[0]; email = parts[1]; }
        } else {
            email = parts[0];
        }
        email = email.trim();
        if (email.indexOf('@') !== -1) list.push({name: name.trim(), email: email});
    });
    recipients = list;
    document.getElementById('paste-count').textContent = list.length + ' destinatarios detectados';
    renderRecipients();
}

// ── Tab CSV ───────────────────────────────────────────────────────────────────
function loadCSV(input) {
    var file = input.files[0];
    if (!file) return;
    var reader = new FileReader();
    reader.onload = function(e) {
        var text  = e.target.result;
        var lines = text.split(/\r?\n/);
        var list  = [];
        var isFirst = true;
        lines.forEach(function(line) {
            line = line.trim();
            if (!line) return;
            var parts = line.split(/[,;]/);
            if (isFirst) {
                // Detectar si es cabecera
                var lower = parts.map(function(p) { return p.toLowerCase().trim(); });
                if (lower.indexOf('email') !== -1 || lower.indexOf('nombre') !== -1 || lower.indexOf('name') !== -1) {
                    isFirst = false; return; // skip header
                }
            }
            isFirst = false;
            var name  = '', email = '';
            if (parts.length >= 2) {
                if (parts[1].indexOf('@') !== -1) { name = parts[0].trim(); email = parts[1].trim(); }
                else if (parts[0].indexOf('@') !== -1) { email = parts[0].trim(); name = parts[1].trim(); }
                else { name = parts[0].trim(); email = parts[1].trim(); }
            } else {
                email = parts[0].trim();
            }
            // Quitar comillas
            email = email.replace(/^["']|["']$/g, '').trim();
            name  = name.replace(/^["']|["']$/g, '').trim();
            if (email.indexOf('@') !== -1) list.push({name: name, email: email});
        });
        recipients = list;
        document.getElementById('csv-count').textContent = list.length + ' destinatarios cargados de ' + file.name;
        renderRecipients();
    };
    reader.readAsText(file, 'UTF-8');
}

// ── Render lista ──────────────────────────────────────────────────────────────
function renderRecipients() {
    var el = document.getElementById('recipients-preview');
    var list = document.getElementById('rec-list');
    var lbl  = document.getElementById('rec-count-lbl');
    if (recipients.length === 0) { el.style.display = 'none'; return; }
    el.style.display = 'block';
    lbl.textContent  = recipients.length + ' destinatario' + (recipients.length !== 1 ? 's' : '');
    var html = '';
    recipients.forEach(function(r, i) {
        html += '<div style="display:flex;justify-content:space-between;padding:6px 10px;'
              + (i % 2 === 0 ? 'background:#f9f9f9;' : '') + '">'
              + '<span>' + (r.name ? escH(r.name) + ' &mdash; ' : '') + '<em>' + escH(r.email) + '</em></span>'
              + '<button onclick="removeRecipient(' + i + ')" style="border:none;background:none;color:#c00;cursor:pointer;font-size:14px;">&times;</button>'
              + '</div>';
    });
    list.innerHTML = html;
}

function removeRecipient(idx) {
    recipients.splice(idx, 1);
    renderRecipients();
}

function clearAll() {
    recipients = [];
    document.getElementById('paste-area').value = '';
    document.getElementById('csv-file').value   = '';
    document.getElementById('paste-count').textContent = '0 destinatarios detectados';
    document.getElementById('csv-count').textContent   = 'Sin archivo cargado';
    document.querySelectorAll('#manual-rows .manual-row').forEach(function(r, i) {
        if (i > 0) r.remove();
        else { r.querySelector('.r-name').value = ''; r.querySelector('.r-email').value = ''; }
    });
    renderRecipients();
}

function escH(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ── Envio ────────────────────────────────────────────────────────────────────
function startSend() {
    if (sending) return;
    if (!currentConceptId) { alert('Selecciona un concepto primero'); return; }
    if (recipients.length === 0) { alert('Anade al menos un destinatario'); return; }
    if (!confirm('Enviar ' + recipients.length + ' email(s)?')) return;

    sending = true;
    var btn = document.getElementById('btn-send');
    btn.disabled    = true;
    btn.textContent = 'Enviando...';

    document.getElementById('progress-wrap').style.display = 'block';
    document.getElementById('progress-log').innerHTML = '';
    document.getElementById('send-status').textContent = '';

    sendNext(0, 0, 0);
}

function sendNext(idx, ok, fail) {
    var bar    = document.getElementById('progress-bar');
    var log    = document.getElementById('progress-log');
    var status = document.getElementById('send-status');
    var total  = recipients.length;

    bar.style.width = Math.round((idx / total) * 100) + '%';
    status.textContent = idx + ' / ' + total + ' procesados (' + ok + ' OK, ' + fail + ' errores)';

    if (idx >= total) {
        bar.style.width = '100%';
        bar.style.background = fail > 0 ? '#e67e22' : '#1a7a3a';
        status.textContent = 'Completado: ' + ok + ' enviados, ' + fail + ' errores';
        document.getElementById('btn-send').disabled = false;
        document.getElementById('btn-send').textContent = 'Enviar emails';
        sending = false;
        return;
    }

    var r  = recipients[idx];
    var fd = new FormData();
    fd.append('ajax', '1');
    fd.append('action', 'send_one');
    fd.append('concept_id', currentConceptId);
    fd.append('name', r.name);
    fd.append('email', r.email);

    fetch('envio_masivo.php', { method: 'POST', body: fd })
        .then(function(res) { return res.json(); })
        .then(function(d) {
            var line = document.createElement('div');
            line.style.padding = '3px 0';
            line.style.borderBottom = '1px solid #eee';
            line.style.color = d.ok ? '#1a7a3a' : '#c0392b';
            line.textContent = (d.ok ? '✓' : '✗') + ' ' + d.msg;
            log.appendChild(line);
            log.scrollTop = log.scrollHeight;
            sendNext(idx + 1, ok + (d.ok ? 1 : 0), fail + (d.ok ? 0 : 1));
        })
        .catch(function(err) {
            var line = document.createElement('div');
            line.style.color = '#c0392b';
            line.textContent = '✗ Error de red con ' + r.email;
            log.appendChild(line);
            sendNext(idx + 1, ok, fail + 1);
        });
}

// ── Toast ────────────────────────────────────────────────────────────────────
function showToast(msg, ok) {
    var t = document.getElementById('toast');
    if (!t) {
        t = document.createElement('div');
        t.id = 'toast';
        t.style.cssText = 'position:fixed;bottom:24px;right:24px;padding:12px 20px;'
            + 'border-radius:8px;font-size:13px;z-index:9999;color:#fff;box-shadow:0 4px 20px rgba(0,0,0,.3);';
        document.body.appendChild(t);
    }
    t.style.background = ok ? '#1a7a3a' : '#c0392b';
    t.textContent = msg;
    t.style.display = 'block';
    setTimeout(function() { t.style.display = 'none'; }, 3000);
}
</script>

<?php require_once __DIR__ . '/_footer.php'; ?>
