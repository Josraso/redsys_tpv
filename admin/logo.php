<?php
// Upload AJAX - antes del HTML
if (!empty($_POST['ajax']) && isset($_FILES['logo'])) {
    require_once __DIR__ . '/../lib/db.php';
    require_once __DIR__ . '/../lib/Auth.php';
    Auth::requireLogin();

    $type      = isset($_POST['type']) ? $_POST['type'] : 'site'; // site | concept
    $conceptId = (int)(isset($_POST['concept_id']) ? $_POST['concept_id'] : 0);
    $file      = $_FILES['logo'];
    $allowed   = array('image/jpeg','image/png','image/gif','image/webp','image/svg+xml');

    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(array('ok'=>false,'msg'=>'Error de subida: codigo '.$file['error']));
        exit;
    }
    if (!in_array($file['type'], $allowed)) {
        echo json_encode(array('ok'=>false,'msg'=>'Formato no permitido. Usa JPG, PNG, GIF, WebP o SVG.'));
        exit;
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        echo json_encode(array('ok'=>false,'msg'=>'El archivo no puede superar 2MB.'));
        exit;
    }

    // Extension
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext === 'svg+xml') $ext = 'svg';

    // Nombre de archivo
    if ($type === 'concept' && $conceptId > 0) {
        $filename = 'concept_' . $conceptId . '.' . $ext;
        $settingKey = 'logo_concept_' . $conceptId;
    } else {
        $filename = 'site_logo.' . $ext;
        $settingKey = 'logo_site';
    }

    $destDir = __DIR__ . '/../assets/img/';
    if (!is_dir($destDir)) mkdir($destDir, 0755, true);

    // Borrar logos anteriores del mismo tipo
    foreach (glob($destDir . ($type === 'concept' ? 'concept_'.$conceptId.'.*' : 'site_logo.*')) as $old) {
        @unlink($old);
    }

    $dest = $destDir . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        echo json_encode(array('ok'=>false,'msg'=>'No se pudo guardar el archivo. Comprueba permisos de assets/img/'));
        exit;
    }

    // Guardar ruta en settings
    $relPath = 'assets/img/' . $filename;
    setSetting($settingKey, $relPath);

    header('Content-Type: application/json');
    echo json_encode(array('ok'=>true,'msg'=>'Logo guardado correctamente.','path'=>$relPath,'ts'=>time()));
    exit;
}

// Borrar logo AJAX
if (!empty($_POST['ajax']) && isset($_POST['delete_logo'])) {
    require_once __DIR__ . '/../lib/db.php';
    require_once __DIR__ . '/../lib/Auth.php';
    Auth::requireLogin();

    $key  = $_POST['delete_logo'];
    $path = getSetting($key);
    if ($path) {
        $full = __DIR__ . '/../' . $path;
        if (file_exists($full)) @unlink($full);
        setSetting($key, '');
    }
    header('Content-Type: application/json');
    echo json_encode(array('ok'=>true));
    exit;
}

$pageTitle = 'Logos';
require_once __DIR__ . '/_header.php';

$concepts  = db()->query('SELECT * FROM concepts ORDER BY sort_order ASC, id ASC')->fetchAll();
$siteLogo  = getSetting('logo_site');

// URL base para mostrar imagenes
$proto   = (isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']==='on')?'https':'http';
$host    = $_SERVER['HTTP_HOST'];
$base    = rtrim(str_replace('/admin','',dirname($_SERVER['SCRIPT_NAME'])),'/');
$baseUrl = $proto . '://' . $host . $base;
?>

<style>
.logo-box{border:2px dashed #e0e0e0;border-radius:12px;padding:20px;text-align:center;
          transition:border-color .2s;cursor:pointer;position:relative;min-height:120px;
          display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;}
.logo-box:hover{border-color:#1a1a1a;}
.logo-box.has-logo{border-style:solid;border-color:#e0e0e0;}
.logo-box img{max-height:80px;max-width:100%;object-fit:contain;border-radius:6px;}
.logo-box .upload-hint{font-size:12px;color:#aaa;}
.logo-box input[type=file]{position:absolute;top:0;left:0;width:100%;height:100%;
                            opacity:0;cursor:pointer;}
.logo-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;}
.logo-item{background:#fff;border:1px solid #e8e8e8;border-radius:10px;padding:16px;}
.logo-item-name{font-size:13px;font-weight:500;margin-bottom:10px;
                overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.logo-item-status{font-size:11px;color:#aaa;margin-top:8px;text-align:center;}
.delete-btn{display:none;position:absolute;top:6px;right:6px;background:#fff;
            border:1px solid #fcc;border-radius:6px;padding:2px 8px;font-size:12px;
            color:#c00;cursor:pointer;z-index:2;}
.logo-box:hover .delete-btn{display:block;}
</style>

<!-- Logo del sitio -->
<div class="card">
  <div class="card-t">Logo principal del sitio</div>
  <p style="font-size:13px;color:#555;margin-bottom:16px;">
    Aparece en la cabecera de la pagina de pago, en el admin y en los emails.
    Formatos: JPG, PNG, WebP, SVG. Max 2MB. Recomendado: fondo transparente (PNG/SVG).
  </p>
  <div style="max-width:300px;">
    <div class="logo-box <?php echo $siteLogo?'has-logo':''; ?>" id="site-logo-box"
         ondragover="event.preventDefault()" ondrop="handleDrop(event,'site',0)">
      <?php if ($siteLogo): ?>
        <img src="<?php echo htmlspecialchars($baseUrl.'/'.$siteLogo.'?ts='.time()); ?>"
             id="site-logo-img" alt="Logo sitio">
        <button class="delete-btn" onclick="deleteLogo('logo_site','site-logo-box',event)">Eliminar</button>
      <?php else: ?>
        <div style="font-size:32px;">🖼️</div>
        <div class="upload-hint">Haz clic o arrastra la imagen aqui</div>
      <?php endif; ?>
      <input type="file" accept="image/*" onchange="uploadLogo(this,'site',0,'site-logo-box')">
    </div>
    <div id="site-logo-status" class="logo-item-status"></div>
  </div>
</div>

<!-- Logos por concepto -->
<div class="card">
  <div class="card-t">Logo por concepto</div>
  <p style="font-size:13px;color:#555;margin-bottom:16px;">
    Si un concepto tiene su propio logo, se usa en su pagina de pago y en el email de confirmacion.
    Si no tiene, se usa el logo principal.
  </p>
  <?php if (empty($concepts)): ?>
    <p style="color:#aaa;font-size:13px;">No hay conceptos creados aun. Crea conceptos primero.</p>
  <?php else: ?>
  <div class="logo-grid">
    <?php foreach ($concepts as $c):
      $key      = 'logo_concept_' . $c['id'];
      $logoPath = getSetting($key);
      $boxId    = 'concept-logo-box-' . $c['id'];
    ?>
    <div class="logo-item">
      <div class="logo-item-name" title="<?php echo htmlspecialchars($c['name']); ?>">
        <?php echo htmlspecialchars($c['icon'].' '.$c['name']); ?>
      </div>
      <div class="logo-box <?php echo $logoPath?'has-logo':''; ?>" id="<?php echo $boxId; ?>"
           ondragover="event.preventDefault()" ondrop="handleDrop(event,'concept',<?php echo (int)$c['id']; ?>)">
        <?php if ($logoPath): ?>
          <img src="<?php echo htmlspecialchars($baseUrl.'/'.$logoPath.'?ts='.time()); ?>"
               alt="Logo <?php echo htmlspecialchars($c['name']); ?>">
          <button class="delete-btn" onclick="deleteLogo('<?php echo $key; ?>','<?php echo $boxId; ?>',event)">Eliminar</button>
        <?php else: ?>
          <div style="font-size:24px;">🖼️</div>
          <div class="upload-hint" style="font-size:11px;">Clic o arrastra</div>
        <?php endif; ?>
        <input type="file" accept="image/*"
               onchange="uploadLogo(this,'concept',<?php echo (int)$c['id']; ?>,'<?php echo $boxId; ?>')">
      </div>
      <div id="status-<?php echo $boxId; ?>" class="logo-item-status"></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<script>
function uploadLogo(input, type, conceptId, boxId) {
    var file = input.files[0];
    if (!file) return;
    doUpload(file, type, conceptId, boxId);
}

function handleDrop(event, type, conceptId) {
    event.preventDefault();
    var file = event.dataTransfer.files[0];
    if (!file) return;
    // Determinar boxId
    var boxId = type === 'site' ? 'site-logo-box' : 'concept-logo-box-' + conceptId;
    doUpload(file, type, conceptId, boxId);
}

function doUpload(file, type, conceptId, boxId) {
    var statusEl = document.getElementById(type === 'site' ? 'site-logo-status' : 'status-' + boxId);
    if (statusEl) { statusEl.textContent = 'Subiendo...'; statusEl.style.color = '#888'; }

    var fd = new FormData();
    fd.append('ajax', '1');
    fd.append('logo', file);
    fd.append('type', type);
    fd.append('concept_id', conceptId);

    fetch('logo.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (statusEl) {
                statusEl.textContent = d.msg;
                statusEl.style.color = d.ok ? '#1a7a3a' : '#c0392b';
                setTimeout(function() { statusEl.textContent = ''; }, 3000);
            }
            if (d.ok) {
                // Actualizar preview inmediatamente
                var box = document.getElementById(boxId);
                var imgSrc = '../' + d.path + '?ts=' + d.ts;
                box.classList.add('has-logo');
                var deleteKey = type === 'site' ? 'logo_site' : 'logo_concept_' + conceptId;
                box.innerHTML = '<img src="' + imgSrc + '" style="max-height:80px;max-width:100%;object-fit:contain;border-radius:6px;">'
                    + '<button class="delete-btn" onclick="deleteLogo(\'' + deleteKey + '\',\'' + boxId + '\',event)">Eliminar</button>'
                    + '<input type="file" accept="image/*" onchange="uploadLogo(this,\'' + type + '\',' + conceptId + ',\'' + boxId + '\')">';
            }
        })
        .catch(function() {
            if (statusEl) { statusEl.textContent = 'Error de conexion'; statusEl.style.color = '#c00'; }
        });
}

function deleteLogo(settingKey, boxId, event) {
    event.stopPropagation();
    if (!confirm('Eliminar este logo?')) return;

    var fd = new FormData();
    fd.append('ajax', '1');
    fd.append('delete_logo', settingKey);

    fetch('logo.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.ok) {
                var box = document.getElementById(boxId);
                box.classList.remove('has-logo');
                box.innerHTML = '<div style="font-size:24px;">&#128444;&#65039;</div>'
                    + '<div class="upload-hint" style="font-size:' + (boxId === 'site-logo-box' ? '12' : '11') + 'px;">Clic o arrastra</div>'
                    + '<input type="file" accept="image/*" onchange="uploadLogo(this,\''
                    + (settingKey === 'logo_site' ? 'site' : 'concept') + '\','
                    + (settingKey.replace('logo_concept_','') === 'logo_site' ? '0' : settingKey.replace('logo_concept_',''))
                    + ',\'' + boxId + '\')">';
            }
        });
}
</script>

<?php require_once __DIR__ . '/_footer.php'; ?>
