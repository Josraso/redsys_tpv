<?php
// Incluir al inicio de cada pagina del admin
// Requiere $pageTitle definido antes
require_once __DIR__ . '/../lib/Auth.php';
Auth::requireLogin();
$siteName  = getSetting('site_name', 'TPV');
$adminName = Auth::adminName();
$cur       = basename($_SERVER['PHP_SELF']);

function badge($status)
{
    if ($status === 'ok')        return '<span class="badge ok">OK</span>';
    if ($status === 'error')     return '<span class="badge err">Error</span>';
    if ($status === 'pending')   return '<span class="badge pend">Pendiente</span>';
    if ($status === 'cancelled') return '<span class="badge err">Cancelado</span>';
    return htmlspecialchars($status);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?php echo htmlspecialchars(isset($pageTitle)?$pageTitle:'Admin'); ?> &mdash; <?php echo htmlspecialchars($siteName); ?></title>
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f4f4f6;color:#1a1a1a;min-height:100vh;}
    a{text-decoration:none;color:inherit;}
    .layout{display:flex;min-height:100vh;}
    .sb{width:210px;background:#1a1a1a;color:#fff;display:flex;flex-direction:column;flex-shrink:0;}
    .sb-head{padding:20px 18px 16px;border-bottom:1px solid #2a2a2a;}
    .sb-site{font-size:14px;font-weight:700;}
    .sb-sub{font-size:11px;color:#888;margin-top:2px;}
    .sb-nav{flex:1;padding:8px 0;}
    .sb-lbl{font-size:10px;color:#555;text-transform:uppercase;letter-spacing:.08em;padding:14px 18px 4px;}
    .sb-a{display:block;padding:9px 18px;color:#999;font-size:13px;transition:color .15s,background .15s;}
    .sb-a:hover,.sb-a.on{color:#fff;background:#2a2a2a;}
    .sb-foot{padding:14px 18px;border-top:1px solid #2a2a2a;}
    .sb-foot .u{font-size:12px;color:#888;margin-bottom:6px;}
    .sb-foot a{font-size:12px;color:#888;}
    .sb-foot a:hover{color:#fff;}
    .main{flex:1;display:flex;flex-direction:column;min-width:0;}
    .topbar{background:#fff;border-bottom:1px solid #e8e8e8;padding:14px 24px;
            display:flex;align-items:center;justify-content:space-between;}
    .topbar h1{font-size:17px;font-weight:600;}
    .topbar a{font-size:13px;color:#888;}
    .content{padding:24px;flex:1;}
    .card{background:#fff;border-radius:10px;border:1px solid #e8e8e8;padding:20px 22px;margin-bottom:16px;}
    .card-t{font-size:11px;font-weight:600;color:#999;text-transform:uppercase;letter-spacing:.06em;margin-bottom:14px;}
    .field{margin-bottom:12px;}
    .field label{font-size:13px;color:#555;display:block;margin-bottom:4px;}
    .field input,.field select,.field textarea{width:100%;border:1px solid #ddd;border-radius:7px;padding:9px 11px;font-size:13px;outline:none;}
    .field input:focus,.field select:focus{border-color:#1a1a1a;}
    .field textarea{resize:vertical;min-height:70px;}
    .r2{display:flex;gap:12px;}.r2>.field,.r2>div{flex:1;}
    .r3{display:flex;gap:12px;}.r3>.field,.r3>div{flex:1;}
    .btn{background:#1a1a1a;color:#fff;border:none;border-radius:7px;padding:9px 20px;font-size:13px;font-weight:600;cursor:pointer;}
    .btn:hover{opacity:.85;}
    .btn-o{background:#fff;color:#1a1a1a;border:1px solid #ddd;border-radius:7px;padding:8px 16px;font-size:13px;cursor:pointer;display:inline-block;}
    .btn-o:hover{background:#f5f5f5;}
    .btn-s{padding:5px 11px;font-size:12px;border-radius:6px;cursor:pointer;border:1px solid #ddd;background:#fff;color:#555;display:inline-block;}
    .btn-s:hover{background:#f5f5f5;}
    .badge{display:inline-flex;align-items:center;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:600;}
    .badge.ok{background:#e6f9ee;color:#1a7a3a;}
    .badge.err{background:#fff1f0;color:#c0392b;}
    .badge.pend{background:#fffbeb;color:#8a5a00;}
    table{width:100%;border-collapse:collapse;font-size:13px;}
    th{text-align:left;padding:8px 11px;font-size:11px;color:#999;font-weight:600;
       border-bottom:1px solid #e8e8e8;text-transform:uppercase;letter-spacing:.04em;}
    td{padding:10px 11px;border-bottom:1px solid #f5f5f5;}
    tr:last-child td{border-bottom:none;}
    tr:hover td{background:#fafafa;}
    .alert{padding:11px 15px;border-radius:8px;font-size:13px;margin-bottom:14px;}
    .alert.ok{background:#e6f9ee;color:#1a7a3a;border:1px solid #b7e4c7;}
    .alert.err{background:#fff1f0;color:#c0392b;border:1px solid #fcc;}
    .toggle{position:relative;display:inline-block;width:36px;height:20px;}
    .toggle input{opacity:0;width:0;height:0;}
    .sl{position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;background:#ccc;border-radius:20px;transition:.2s;}
    .sl:before{position:absolute;content:"";height:14px;width:14px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.2s;}
    input:checked+.sl{background:#1a1a1a;}
    input:checked+.sl:before{transform:translateX(16px);}
    .metrics{display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap;}
    .metric{background:#fff;border:1px solid #e8e8e8;border-radius:10px;padding:16px;flex:1;min-width:110px;}
    .metric-l{font-size:11px;color:#999;font-weight:600;text-transform:uppercase;letter-spacing:.04em;margin-bottom:5px;}
    .metric-v{font-size:22px;font-weight:700;}
    hr{border:none;border-top:1px solid #f0f0f0;margin:16px 0;}
    /* Modo oscuro */
    body.dark{background:#0f0f0f;color:#e0e0e0;}
    body.dark .sb{background:#0a0a0a;}
    body.dark .sb-a:hover,body.dark .sb-a.on{background:#1a1a1a;}
    body.dark .main{background:#0f0f0f;}
    body.dark .topbar{background:#1a1a1a;border-color:#2a2a2a;}
    body.dark .card{background:#1a1a1a;border-color:#2a2a2a;}
    body.dark table tr:hover td{background:#252525;}
    body.dark td{border-color:#2a2a2a;}
    body.dark th{border-color:#2a2a2a;color:#666;}
    body.dark .field input,body.dark .field select,body.dark .field textarea{background:#252525;border-color:#333;color:#e0e0e0;}
    body.dark .metric{background:#1a1a1a;border-color:#2a2a2a;}
    body.dark .btn-o,.dark .btn-s{background:#252525;border-color:#333;color:#ccc;}
    body.dark input[type=text],body.dark input[type=email],body.dark input[type=number],
    body.dark input[type=password],body.dark input[type=url],body.dark input[type=date],body.dark select{background:#252525;border-color:#333;color:#e0e0e0;}
    .dark-toggle{background:none;border:1px solid #444;border-radius:20px;padding:4px 12px;
                 font-size:12px;cursor:pointer;color:#aaa;display:flex;align-items:center;gap:6px;}
    .dark-toggle:hover{border-color:#888;color:#fff;}
  </style>
  <script>
    // Aplicar modo oscuro ANTES del render para evitar flash
    if(localStorage.getItem('darkMode')==='1') document.documentElement.classList.add('dark-pre');
  </script>
  <style>.dark-pre body{background:#0f0f0f;}</style>
</head>
<body>
<div class="layout">
  <div class="sb">
    <div class="sb-head">
      <div class="sb-site"><?php echo htmlspecialchars($siteName); ?></div>
      <div class="sb-sub">Administracion</div>
    </div>
    <nav class="sb-nav">
      <div class="sb-lbl">Principal</div>
      <a href="index.php"    class="sb-a <?php echo $cur==='index.php'?'on':''; ?>">Inicio</a>
      <a href="concepts.php" class="sb-a <?php echo $cur==='concepts.php'?'on':''; ?>">Conceptos</a>
      <a href="registro.php" class="sb-a <?php echo $cur==='registro.php'?'on':''; ?>">Registro pagos</a>
      <a href="envio_masivo.php" class="sb-a <?php echo $cur==='envio_masivo.php'?'on':''; ?>">Envio masivo</a>
      <div class="sb-lbl">Configuracion</div>
      <a href="logo.php" class="sb-a <?php echo $cur==='logo.php'?'on':''; ?>">Logos</a>
      <a href="settings.php" class="sb-a <?php echo $cur==='settings.php'?'on':''; ?>">General</a>
      <a href="redsys.php"   class="sb-a <?php echo $cur==='redsys.php'?'on':''; ?>">Redsys</a>
      <a href="emails.php"   class="sb-a <?php echo $cur==='emails.php'?'on':''; ?>">Emails</a>
    </nav>
    <div class="sb-foot">
      <div class="u"><?php echo htmlspecialchars($adminName); ?></div>
      <a href="logout.php">Cerrar sesion</a>
    </div>
  </div>
  <div class="main">
    <div class="topbar">
      <h1><?php echo htmlspecialchars(isset($pageTitle)?$pageTitle:''); ?></h1>
      <div style="display:flex;align-items:center;gap:12px;">
        <a href="../index.php" target="_blank" style="font-size:13px;color:#888;">Ver pagina pago &rarr;</a>
        <button class="dark-toggle" onclick="toggleDark()" id="dark-btn">🌙 Oscuro</button>
      </div>
    </div>
    <div class="content">
<script>
var _csrf = '<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>';
function toggleDark(){
  var isDark=document.body.classList.toggle('dark');
  localStorage.setItem('darkMode',isDark?'1':'0');
  document.getElementById('dark-btn').textContent=isDark?'☀️ Claro':'🌙 Oscuro';
}
(function(){
  if(localStorage.getItem('darkMode')==='1'){
    document.body.classList.add('dark');
    var btn=document.getElementById('dark-btn');
    if(btn) btn.textContent='☀️ Claro';
  }
})();
</script>
