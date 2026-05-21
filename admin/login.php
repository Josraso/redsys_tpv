<?php
require_once __DIR__ . '/../lib/Auth.php';

Auth::start();
if (Auth::check()) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim(isset($_POST['username']) ? $_POST['username'] : '');
    $pass = isset($_POST['password']) ? $_POST['password'] : '';
    if (Auth::login($user, $pass)) {
        header('Location: index.php');
        exit;
    }
    $error = 'Usuario o contrasena incorrectos.';
    sleep(1);
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
    .card{background:#fff;border-radius:12px;border:1px solid #e8e8e8;padding:40px;width:340px;}
    h1{font-size:20px;font-weight:700;margin-bottom:6px;}
    p{font-size:13px;color:#888;margin-bottom:28px;}
    label{font-size:13px;color:#555;display:block;margin-bottom:4px;}
    input{width:100%;border:1px solid #ddd;border-radius:8px;padding:10px 12px;font-size:14px;
          margin-bottom:14px;outline:none;}
    input:focus{border-color:#1a1a1a;}
    .btn{width:100%;background:#1a1a1a;color:#fff;border:none;border-radius:8px;
         padding:12px;font-size:14px;font-weight:600;cursor:pointer;}
    .btn:hover{opacity:.85;}
    .error{background:#fff1f1;border:1px solid #fcc;border-radius:8px;
           padding:10px 14px;font-size:13px;color:#c00;margin-bottom:16px;}
  </style>
</head>
<body>
<div class="card">
  <h1><?php echo htmlspecialchars($siteName); ?></h1>
  <p>Panel de administracion</p>
  <?php if ($error): ?>
    <div class="error"><?php echo htmlspecialchars($error); ?></div>
  <?php endif; ?>
  <form method="POST">
    <label>Usuario</label>
    <input type="text" name="username" required autofocus autocomplete="username">
    <label>Contrasena</label>
    <input type="password" name="password" required autocomplete="current-password">
    <button type="submit" class="btn">Entrar</button>
  </form>
</div>
</body>
</html>
