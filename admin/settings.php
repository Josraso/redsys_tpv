<?php
$pageTitle = 'Configuracion general';
require_once __DIR__ . '/_header.php';
$msg=''; $msgType='ok';
if($_SERVER['REQUEST_METHOD']==='POST'){
    $action=isset($_POST['action'])?$_POST['action']:'';
    if($action==='general'){
        setSetting('site_name',     trim(htmlspecialchars(isset($_POST['site_name'])    ?$_POST['site_name']    :'')));
        setSetting('site_footer',   trim(htmlspecialchars(isset($_POST['site_footer'])  ?$_POST['site_footer']  :'')));
        setSetting('site_base_url', rtrim(trim(isset($_POST['site_base_url'])?$_POST['site_base_url']:''), '/'));
        $msg='Configuracion guardada.';
    }
    if($action==='password'){
        $cur=isset($_POST['cur'])?$_POST['cur']:'';
        $new=isset($_POST['new'])?$_POST['new']:'';
        $new2=isset($_POST['new2'])?$_POST['new2']:'';
        $st=db()->prepare('SELECT password_hash FROM admin_users WHERE id=?');
        $st->execute(array(Auth::userId()));
        $hash=$st->fetchColumn();
        if(!password_verify($cur,$hash)){$msg='Contrasena actual incorrecta.';$msgType='err';}
        elseif(strlen($new)<8){$msg='Minimo 8 caracteres.';$msgType='err';}
        elseif($new!==$new2){$msg='Las contrasenas no coinciden.';$msgType='err';}
        else{db()->prepare('UPDATE admin_users SET password_hash=? WHERE id=?')->execute(array(password_hash($new,PASSWORD_BCRYPT),Auth::userId()));$msg='Contrasena cambiada.';}
    }
}
?>
<?php if($msg): ?><div class="alert <?php echo $msgType; ?>"><?php echo $msg; ?></div><?php endif; ?>
<div class="card">
  <div class="card-t">General</div>
  <form method="POST"><input type="hidden" name="action" value="general">
    <div class="field"><label>Nombre del sitio</label><input type="text" name="site_name" value="<?php echo htmlspecialchars(getSetting('site_name','TPV')); ?>"></div>
    <div class="field"><label>URL base del sitio <span style="color:#aaa;font-size:11px;">(sin barra final, ej: https://josraso.es)</span></label>
      <input type="url" name="site_base_url" value="<?php echo htmlspecialchars(getSetting('site_base_url')); ?>" placeholder="https://tusitio.com">
    </div>
    <div class="field"><label>Pie de pagina publica</label><textarea name="site_footer"><?php echo htmlspecialchars(getSetting('site_footer')); ?></textarea></div>
    <button type="submit" class="btn">Guardar</button>
  </form>
</div>
<div class="card">
  <div class="card-t">Cambiar contrasena</div>
  <form method="POST"><input type="hidden" name="action" value="password">
    <div class="field"><label>Contrasena actual</label><input type="password" name="cur" required style="max-width:300px;"></div>
    <div class="r2" style="max-width:480px;">
      <div class="field"><label>Nueva contrasena</label><input type="password" name="new" required minlength="8"></div>
      <div class="field"><label>Repetir nueva</label><input type="password" name="new2" required minlength="8"></div>
    </div>
    <button type="submit" class="btn">Cambiar</button>
  </form>
</div>
<?php require_once __DIR__ . '/_footer.php'; ?>
