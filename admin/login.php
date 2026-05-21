<?php
require_once __DIR__ . '/../lib/Auth.php';

Auth::start();
if (Auth::check()) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user   = trim(isset($_POST['username']) ? $_POST['username'] : '');
    $pass   = isset($_POST['password']) ? $_POST['password'] : '';
    $result = Auth::login($user, $pass);
    if ($result === 'ok') {
        header('Location: index.php');
        exit;
    }
    if ($result === 'locked') {
        $error = 'Demasiados intentos fallidos. Espera ' . Auth::LOCKOUT_MINS . ' minutos e intentalo de nuevo.';
    } else {
        $error = 'Usuario o contrasena incorrectos.';
        sleep(1);
    }
}

$siteName = getSetting('site_name', 'TPV');
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Admin &mdash; <?php echo htmlspecialchars($siteName); ?></title>
  <style>
    *{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
         background:#f4f4f6;min-height:100vh;display:flex;align-items:center;justify-content:center;}
    .card{background:#fff;border-radius:14px;border:1px solid #e8e8e8;padding:44px 40px;width:360px;
          box-shadow:0 4px 24px rgba(0,0,0,.06);}
    .logo{text-align:center;margin-bottom:28px;}
    .logo-icon{width:44px;height:44px;background:#1a1a1a;border-radius:10px;display:inline-flex;
               align-items:center;justify-content:center;margin-bottom:12px;}
    .logo-icon svg{width:22px;height:22px;fill:#fff;}
    h1{font-size:19px;font-weight:700;margin-bottom:4px;color:#1a1a1a;}
    p{font-size:13px;color:#999;margin-bottom:0;}
    .fields{margin-top:26px;}
    label{font-size:12px;font-weight:600;color:#666;text-transform:uppercase;letter-spacing:.04em;
          display:block;margin-bottom:5px;}
    input{width:100%;border:1px solid #e0e0e0;border-radius:8px;padding:11px 13px;font-size:14px;
          margin-bottom:14px;outline:none;transition:border-color .15s,box-shadow .15s;background:#fff;}
    input:focus{border-color:#1a1a1a;box-shadow:0 0 0 3px rgba(26,26,26,.08);}
    .btn{width:100%;background:#1a1a1a;color:#fff;border:none;border-radius:8px;
         padding:13px;font-size:14px;font-weight:600;cursor:pointer;transition:opacity .15s,transform .1s;}
    .btn:hover{opacity:.88;}
    .btn:active{transform:scale(.98);}
    .btn:disabled{opacity:.5;cursor:not-allowed;}
    .error{background:#fff1f1;border:1px solid #fcc;border-radius:8px;
           padding:11px 14px;font-size:13px;color:#c00;margin-bottom:16px;
           display:flex;align-items:center;gap:8px;}
    .error::before{content:'⚠';font-size:14px;}
    .locked{background:#fffbeb;border:1px solid #f0d98a;color:#8a5a00;}
    .locked::before{content:'🔒';}
  </style>
</head>
<body>
<div class="card">
  <div class="logo">
    <div class="logo-icon">
      <svg viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
    </div>
    <h1><?php echo htmlspecialchars($siteName); ?></h1>
    <p>Panel de administracion</p>
  </div>
  <?php if ($error): ?>
    <div class="error <?php echo strpos($error, 'Demasiados') !== false ? 'locked' : ''; ?>">
      <?php echo htmlspecialchars($error); ?>
    </div>
  <?php endif; ?>
  <form method="POST" id="lf" class="fields">
    <label>Usuario</label>
    <input type="text" name="username" required autofocus autocomplete="username"
           placeholder="usuario">
    <label>Contrasena</label>
    <input type="password" name="password" required autocomplete="current-password"
           placeholder="••••••••">
    <button type="submit" class="btn" id="sbtn">Entrar &rarr;</button>
  </form>
</div>
<script>
document.getElementById('lf').addEventListener('submit', function() {
  var btn = document.getElementById('sbtn');
  btn.disabled = true;
  btn.textContent = 'Entrando...';
});
</script>
</body>
</html>
