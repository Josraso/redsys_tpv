<?php
$pageTitle = 'Configuracion Redsys';
require_once __DIR__ . '/_header.php';
$msg=''; $msgType='ok';
if($_SERVER['REQUEST_METHOD']==='POST'){
    foreach(array('redsys_fuc','redsys_terminal','redsys_currency','redsys_environment','redsys_url_notify','redsys_url_ok','redsys_url_ko') as $k)
        setSetting($k, trim(isset($_POST[$k])?$_POST[$k]:''));
    // Guardar clave SIEMPRE si el campo no esta vacio
    $newKey = trim(isset($_POST['redsys_secret_key'])?$_POST['redsys_secret_key']:'');
    if($newKey !== '') {
        setSetting('redsys_secret_key', $newKey);
        $msg='Configuracion Redsys guardada (clave actualizada).';
    } else {
        $msg='Configuracion Redsys guardada (clave sin cambios).';
    }
    $msgType='ok';
}
$env        = getSetting('redsys_environment','test');
$currentKey = getSetting('redsys_secret_key','');
$keyLen     = strlen($currentKey);
$keyMasked  = $keyLen > 0
    ? str_repeat('*', max(0,$keyLen-4)) . substr($currentKey,-4)
    : '';

$proto=(isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']==='on')?'https':'http';
$host=$_SERVER['HTTP_HOST'];
$base=rtrim(str_replace('/admin','',dirname($_SERVER['SCRIPT_NAME'])),'/');
$autoNotify="$proto://$host$base/pago/notify.php";
$autoOk="$proto://$host$base/pago/ok.php";
$autoKo="$proto://$host$base/pago/ko.php";
?>
<?php if($msg): ?><div class="alert <?php echo $msgType; ?>"><?php echo $msg; ?></div><?php endif; ?>
<form method="POST">
<div class="card">
  <div class="card-t">Credenciales</div>
  <div class="r3">
    <div class="field"><label>FUC (numero de comercio)</label>
      <input type="text" name="redsys_fuc" value="<?php echo htmlspecialchars(getSetting('redsys_fuc')); ?>" placeholder="999008881" required>
    </div>
    <div class="field"><label>Terminal</label>
      <input type="number" name="redsys_terminal" value="<?php echo htmlspecialchars(getSetting('redsys_terminal','1')); ?>" min="1">
    </div>
    <div class="field"><label>Moneda</label>
      <select name="redsys_currency">
        <option value="978" <?php echo getSetting('redsys_currency','978')==='978'?'selected':''; ?>>978 - Euro</option>
      </select>
    </div>
  </div>

  <div class="field">
    <label>Clave secreta SHA-256
      <?php if($keyLen > 0): ?>
        <span style="color:#1a7a3a;font-weight:600;font-size:12px;">
          &nbsp;&#10003; Guardada (<?php echo $keyLen; ?> chars): <code><?php echo htmlspecialchars($keyMasked); ?></code>
        </span>
      <?php else: ?>
        <span style="color:#c0392b;font-weight:600;font-size:12px;">&nbsp;&#9888; NO hay clave guardada</span>
      <?php endif; ?>
    </label>
    <input type="text" name="redsys_secret_key"
           placeholder="Pega aqui la clave SHA-256 de tu banco"
           autocomplete="off"
           style="font-family:monospace;"
           value="">
    <p style="font-size:12px;color:#aaa;margin-top:4px;">
      Dejalo vacio para mantener la clave actual. En entorno de pruebas: <code>sq7HjrUOBfKmC576ILgskD5srU870gJ7</code>
    </p>
  </div>

  <div class="field">
    <label>Entorno</label>
    <div style="display:flex;gap:20px;margin-top:4px;">
      <label style="display:flex;align-items:center;gap:7px;font-size:13px;cursor:pointer;">
        <input type="radio" name="redsys_environment" value="test" <?php echo $env==='test'?'checked':''; ?>>
        <strong>Pruebas</strong> &mdash; sis-t.redsys.es
      </label>
      <label style="display:flex;align-items:center;gap:7px;font-size:13px;cursor:pointer;">
        <input type="radio" name="redsys_environment" value="prod" <?php echo $env==='prod'?'checked':''; ?>>
        <strong>Produccion</strong> <span style="color:#c0392b;">&mdash; dinero real</span>
      </label>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-t">URLs de respuesta</div>
  <p style="font-size:12px;color:#aaa;margin-bottom:12px;">Configura estas mismas URLs en el TPV virtual de tu banco.</p>
  <div class="field"><label>URL notificacion silenciosa (POST)</label>
    <div style="display:flex;gap:8px;">
      <input type="url" name="redsys_url_notify" value="<?php echo htmlspecialchars(getSetting('redsys_url_notify',$autoNotify)); ?>">
      <button type="button" class="btn-s" onclick="document.querySelector('[name=redsys_url_notify]').value='<?php echo htmlspecialchars($autoNotify); ?>'">Auto</button>
    </div>
  </div>
  <div class="r2">
    <div class="field"><label>URL OK</label>
      <div style="display:flex;gap:8px;">
        <input type="url" name="redsys_url_ok" value="<?php echo htmlspecialchars(getSetting('redsys_url_ok',$autoOk)); ?>">
        <button type="button" class="btn-s" onclick="document.querySelector('[name=redsys_url_ok]').value='<?php echo htmlspecialchars($autoOk); ?>'">Auto</button>
      </div>
    </div>
    <div class="field"><label>URL KO</label>
      <div style="display:flex;gap:8px;">
        <input type="url" name="redsys_url_ko" value="<?php echo htmlspecialchars(getSetting('redsys_url_ko',$autoKo)); ?>">
        <button type="button" class="btn-s" onclick="document.querySelector('[name=redsys_url_ko]').value='<?php echo htmlspecialchars($autoKo); ?>'">Auto</button>
      </div>
    </div>
  </div>
</div>

<div class="card" style="background:#fffbeb;border-color:#f0d98a;">
  <div class="card-t" style="color:#8a6a00;">Tarjetas de prueba Redsys</div>
  <table style="font-size:13px;">
    <tr><th>Numero</th><th>Caducidad</th><th>CVV</th><th>Resultado</th></tr>
    <tr><td><code>4548812049400004</code></td><td>12/34</td><td>123</td><td style="color:#1a7a3a;font-weight:600;">OK</td></tr>
    <tr><td><code>1234567890123456</code></td><td>12/34</td><td>123</td><td style="color:#c0392b;">Denegado</td></tr>
    <tr><td><code>4548814479727229</code></td><td>12/34</td><td>123</td><td style="color:#1a7a3a;font-weight:600;">OK con 3DS</td></tr>
  </table>
</div>

<button type="submit" class="btn">Guardar configuracion Redsys</button>
</form>
<?php require_once __DIR__ . '/_footer.php'; ?>
