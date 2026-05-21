<?php
// Detectar AJAX ANTES de incluir el header (que genera HTML)
$isAjax = !empty($_POST['ajax']);

if ($isAjax) {
    require_once __DIR__ . '/../lib/db.php';
    require_once __DIR__ . '/../lib/Auth.php';
    require_once __DIR__ . '/../lib/Mailer.php';
    Auth::requireLogin();

    $testTo   = trim(isset($_POST['test_email']) ? $_POST['test_email'] : '');
    $testName = trim(isset($_POST['test_name'])  ? $_POST['test_name']  : 'Test');
    if (!filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
        $result = array('ok' => false, 'msg' => 'Email de destino no valido');
    } else {
        $mailer = new Mailer();
        $html   = '<div style="font-family:Arial,sans-serif;padding:30px;">'
                . '<h2>Email de prueba</h2>'
                . '<p>Funciona correctamente. Enviado el ' . date('d/m/Y H:i:s') . '</p>'
                . '</div>';
        $res = $mailer->send($testTo, $testName, 'Email de prueba - TPV Redsys', $html);
        $result = array(
            'ok'  => $res['ok'],
            'msg' => $res['ok']
                ? 'Email enviado correctamente a ' . $testTo . '. Revisa bandeja de entrada y spam.'
                : 'Error: ' . $res['error']
        );
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result);
    exit;
}

$pageTitle = 'Configuracion emails';
require_once __DIR__ . '/_header.php';
require_once __DIR__ . '/../lib/Mailer.php';

$msg = ''; $msgType = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'save') {
        // Guardar todos menos smtp_pass
        $keys = array('mail_method','mail_from_email','mail_from_name',
                      'mail_admin_email','mail_admin_name',
                      'mail_subject_cliente','mail_subject_admin','mail_footer',
                      'smtp_host','smtp_port','smtp_user','smtp_secure');
        foreach ($keys as $k) {
            setSetting($k, trim(isset($_POST[$k]) ? $_POST[$k] : ''));
        }
        // Contrasena SMTP: solo guardar si se ha introducido algo
        $newPass = isset($_POST['smtp_pass']) ? $_POST['smtp_pass'] : '';
        if ($newPass !== '') {
            setSetting('smtp_pass', $newPass);
            $msg = 'Configuracion guardada (contrasena SMTP actualizada).';
        } else {
            $msg = 'Configuracion guardada (contrasena SMTP sin cambios).';
        }
    }

    if ($action === 'test') {
        $testTo   = trim(isset($_POST['test_email']) ? $_POST['test_email'] : '');
        $testName = trim(isset($_POST['test_name'])  ? $_POST['test_name']  : 'Test');

        if (!filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
            $msg = 'Email de destino no valido.';
            $msgType = 'err';
        } else {
            $mailer = new Mailer();
            $html   = '<div style="font-family:Arial,sans-serif;padding:30px;max-width:500px;">'
                    . '<h2 style="color:#1a1a1a;">Email de prueba</h2>'
                    . '<p style="color:#555;margin-top:10px;">Si recibes este mensaje el envio de emails funciona correctamente.</p>'
                    . '<p style="color:#999;font-size:12px;margin-top:20px;">Enviado el ' . date('d/m/Y H:i:s') . '</p>'
                    . '</div>';
            $result = $mailer->send($testTo, $testName, 'Email de prueba — TPV Redsys', $html);
            if ($result['ok']) {
                $msg     = '&#10003; Email enviado correctamente a ' . htmlspecialchars($testTo) . '. Revisa bandeja de entrada y spam.';
                $msgType = 'ok';
            } else {
                $msg     = '&#10007; Error al enviar: ' . htmlspecialchars($result['error']);
                $msgType = 'err';
            }
        }

        // Respuesta AJAX
        // (AJAX manejado arriba antes del header HTML)
    }
}

$method  = getSetting('mail_method', 'sendmail');
$hasPass = getSetting('smtp_pass') !== '';
$passLen = strlen(getSetting('smtp_pass'));
?>

<?php if ($msg): ?>
<div class="alert <?php echo $msgType; ?>"><?php echo $msg; ?></div>
<?php endif; ?>

<form method="POST">
<input type="hidden" name="action" value="save">

<!-- Metodo de envio -->
<div class="card">
  <div class="card-t">Metodo de envio</div>
  <div style="display:flex;gap:28px;margin-bottom:16px;">
    <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
      <input type="radio" name="mail_method" value="sendmail"
             <?php echo $method === 'sendmail' ? 'checked' : ''; ?>
             onchange="document.getElementById('smtp-f').style.display='none'">
      <strong>Sendmail</strong>
      <span style="color:#888;font-size:12px;">(usa el servidor de correo del hosting)</span>
    </label>
    <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
      <input type="radio" name="mail_method" value="smtp"
             <?php echo $method === 'smtp' ? 'checked' : ''; ?>
             onchange="document.getElementById('smtp-f').style.display='block'">
      <strong>SMTP externo</strong>
    </label>
  </div>

  <div id="smtp-f" style="display:<?php echo $method === 'smtp' ? 'block' : 'none'; ?>;
       background:#f9f9f9;border:1px solid #eee;border-radius:8px;padding:16px;">
    <div class="r3">
      <div class="field">
        <label>Host SMTP</label>
        <input type="text" name="smtp_host"
               value="<?php echo htmlspecialchars(getSetting('smtp_host')); ?>"
               placeholder="mail.tudominio.com">
      </div>
      <div class="field">
        <label>Puerto</label>
        <input type="number" name="smtp_port"
               value="<?php echo htmlspecialchars(getSetting('smtp_port', '587')); ?>">
      </div>
      <div class="field">
        <label>Cifrado</label>
        <select name="smtp_secure">
          <option value="tls" <?php echo getSetting('smtp_secure','tls')==='tls'?'selected':''; ?>>TLS — puerto 587</option>
          <option value="ssl" <?php echo getSetting('smtp_secure')==='ssl'?'selected':''; ?>>SSL — puerto 465</option>
          <option value=""    <?php echo getSetting('smtp_secure')===''  ?'selected':''; ?>>Sin cifrado</option>
        </select>
      </div>
    </div>
    <div class="r2">
      <div class="field">
        <label>Usuario SMTP</label>
        <input type="text" name="smtp_user"
               value="<?php echo htmlspecialchars(getSetting('smtp_user')); ?>"
               placeholder="correo@tudominio.com" autocomplete="off">
      </div>
      <div class="field">
        <label>
          Contrasena SMTP
          <?php if ($hasPass): ?>
            <span style="color:#1a7a3a;font-weight:600;font-size:11px;">
              &nbsp;&#10003; Guardada (<?php echo $passLen; ?> chars)
            </span>
          <?php else: ?>
            <span style="color:#c0392b;font-weight:600;font-size:11px;">&nbsp;&#9888; No configurada</span>
          <?php endif; ?>
        </label>
        <input type="text" name="smtp_pass"
               placeholder="<?php echo $hasPass ? 'Dejar vacio para mantener la actual' : 'Introduce la contrasena'; ?>"
               autocomplete="new-password"
               style="font-family:monospace;">
        <p style="font-size:11px;color:#aaa;margin-top:3px;">
          Escribe la contrasena en texto plano. Si lo dejas vacio se mantiene la guardada.
        </p>
      </div>
    </div>
  </div>
</div>

<!-- Remitente -->
<div class="card">
  <div class="card-t">Remitente y destinatario admin</div>
  <div class="r2">
    <div class="field">
      <label>Email remitente (From)</label>
      <input type="email" name="mail_from_email"
             value="<?php echo htmlspecialchars(getSetting('mail_from_email')); ?>"
             placeholder="noreply@tudominio.com">
    </div>
    <div class="field">
      <label>Nombre remitente</label>
      <input type="text" name="mail_from_name"
             value="<?php echo htmlspecialchars(getSetting('mail_from_name')); ?>"
             placeholder="Mi TPV">
    </div>
  </div>
  <div class="r2">
    <div class="field">
      <label>Email del administrador <span style="color:#888;font-size:11px;">(recibe aviso en cada pago)</span></label>
      <input type="email" name="mail_admin_email"
             value="<?php echo htmlspecialchars(getSetting('mail_admin_email')); ?>"
             placeholder="admin@tudominio.com">
    </div>
    <div class="field">
      <label>Nombre administrador</label>
      <input type="text" name="mail_admin_name"
             value="<?php echo htmlspecialchars(getSetting('mail_admin_name', 'Admin')); ?>">
    </div>
  </div>
</div>

<!-- Asuntos -->
<div class="card">
  <div class="card-t">Asuntos y pie de email</div>
  <p style="font-size:12px;color:#aaa;margin-bottom:12px;">
    Variables disponibles: <code>{concepto}</code> <code>{nombre}</code>
  </p>
  <div class="field">
    <label>Asunto email al cliente</label>
    <input type="text" name="mail_subject_cliente"
           value="<?php echo htmlspecialchars(getSetting('mail_subject_cliente', 'Confirmacion de pago - {concepto}')); ?>">
  </div>
  <div class="field">
    <label>Asunto email al administrador</label>
    <input type="text" name="mail_subject_admin"
           value="<?php echo htmlspecialchars(getSetting('mail_subject_admin', 'Nuevo pago - {concepto} - {nombre}')); ?>">
  </div>
  <div class="field">
    <label>Pie del email al cliente</label>
    <textarea name="mail_footer"><?php echo htmlspecialchars(getSetting('mail_footer')); ?></textarea>
  </div>
</div>

<button type="submit" class="btn" style="margin-bottom:24px;">Guardar configuracion</button>
</form>

<!-- Test de envio -->
<div class="card">
  <div class="card-t">Verificar funcionamiento</div>
  <p style="font-size:13px;color:#555;margin-bottom:14px;">
    Envia un email de prueba con la configuracion actual. El resultado exacto aparece aqui abajo.
  </p>
  <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
    <div class="field" style="margin:0;">
      <label>Email de destino</label>
      <input type="email" id="te" placeholder="tuemail@dominio.com"
             style="width:240px;border:1px solid #ddd;border-radius:7px;padding:9px 11px;font-size:13px;">
    </div>
    <button class="btn-o" onclick="testMail()" id="tb" style="height:37px;">
      Enviar prueba
    </button>
  </div>

  <!-- Resultado del test — siempre visible una vez enviado -->
  <div id="test-result" style="display:none;margin-top:14px;padding:14px 16px;border-radius:8px;font-size:13px;line-height:1.6;"></div>

  <!-- Info del metodo actual -->
  <div style="margin-top:12px;padding:10px 14px;background:#f5f5f5;border-radius:7px;font-size:12px;color:#888;">
    Metodo actual: <strong><?php echo $method === 'smtp' ? 'SMTP (' . htmlspecialchars(getSetting('smtp_host')) . ':' . htmlspecialchars(getSetting('smtp_port','587')) . ')' : 'Sendmail (' . ini_get('sendmail_path') . ')'; ?></strong>
    &nbsp;&middot;&nbsp; Remitente: <strong><?php echo htmlspecialchars(getSetting('mail_from_email') ?: 'no configurado'); ?></strong>
  </div>
</div>

<script>
function testMail() {
  var email = document.getElementById('te').value.trim();
  var btn   = document.getElementById('tb');
  var res   = document.getElementById('test-result');

  if (!email) { alert('Introduce un email de destino'); return; }

  btn.disabled = true;
  btn.textContent = 'Enviando...';
  res.style.display = 'none';

  var fd = new FormData();
  fd.append('action', 'test');
  fd.append('test_email', email);
  fd.append('test_name', 'Test TPV');
  fd.append('ajax', '1');

  fetch('emails.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      res.style.display = 'block';
      if (d.ok) {
        res.style.background = '#e6f9ee';
        res.style.color      = '#1a7a3a';
        res.style.border     = '1px solid #b7e4c7';
      } else {
        res.style.background = '#fff1f0';
        res.style.color      = '#c0392b';
        res.style.border     = '1px solid #fcc';
      }
      res.innerHTML = '<strong>' + (d.ok ? 'OK' : 'ERROR') + '</strong> &mdash; ' + d.msg;
      btn.disabled = false;
      btn.textContent = 'Enviar prueba';
    })
    .catch(function(err) {
      res.style.display = 'block';
      res.style.background = '#fff1f0';
      res.style.color = '#c0392b';
      res.style.border = '1px solid #fcc';
      res.innerHTML = '<strong>ERROR</strong> &mdash; Fallo en la llamada AJAX: ' + err;
      btn.disabled = false;
      btn.textContent = 'Enviar prueba';
    });
}
</script>

<?php require_once __DIR__ . '/_footer.php'; ?>
