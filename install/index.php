<?php
if(session_status()===PHP_SESSION_NONE) session_start();

$cfg=__DIR__.'/../config.php';
if(file_exists($cfg)&&!isset($_GET['force'])){
    die('<div style="font-family:sans-serif;max-width:500px;margin:60px auto;padding:28px;border:1px solid #e8e8e8;border-radius:10px;">
    <h2>Ya instalado</h2><p style="margin-top:10px;">Elimina la carpeta <code>install/</code> por seguridad.</p>
    <p style="margin-top:12px;"><a href="../index.php">Pagina de pago</a> &nbsp;|&nbsp; <a href="../admin/login.php">Admin</a></p>
    <p style="font-size:12px;color:#aaa;margin-top:12px;">Para reinstalar: borra config.php y añade ?force=1</p></div>');
}

function h($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}

$step=(int)(isset($_GET['step'])?$_GET['step']:1);
$errors=array(); $ok=false;

// PASO 2: conectar BD
if($step===2&&$_SERVER['REQUEST_METHOD']==='POST'){
    $db=array(
        'host'=>trim(isset($_POST['db_host'])?$_POST['db_host']:'localhost'),
        'port'=>(int)(isset($_POST['db_port'])?$_POST['db_port']:3306),
        'name'=>trim(isset($_POST['db_name'])?$_POST['db_name']:''),
        'user'=>trim(isset($_POST['db_user'])?$_POST['db_user']:''),
        'pass'=>isset($_POST['db_pass'])?$_POST['db_pass']:'',
    );
    if(empty($db['name'])) $errors[]='Introduce el nombre de la base de datos';
    if(empty($db['user'])) $errors[]='Introduce el usuario';
    if(empty($errors)){
        try{
            $pdo=new PDO('mysql:host='.$db['host'].';port='.$db['port'].';charset=utf8mb4',$db['user'],$db['pass'],array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION));
            $safe=preg_replace('/[^a-zA-Z0-9_]/','_',$db['name']);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$safe` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $_SESSION['idb']=$db;
            header('Location: index.php?step=3');
            exit;
        }catch(PDOException $e){$errors[]='Error BD: '.$e->getMessage();}
    }
}

// PASO 3: instalar
if($step===3&&$_SERVER['REQUEST_METHOD']==='POST'){
    $db=!empty($_SESSION['idb'])?$_SESSION['idb']:array();
    // Fallback campos ocultos si sesion perdida
    if(empty($db['name'])&&!empty($_POST['_dn'])){
        $db=array('host'=>trim(isset($_POST['_dh'])?$_POST['_dh']:'localhost'),'port'=>(int)(isset($_POST['_dp'])?$_POST['_dp']:3306),'name'=>trim(isset($_POST['_dn'])?$_POST['_dn']:''),'user'=>trim(isset($_POST['_du'])?$_POST['_du']:''),'pass'=>isset($_POST['_dw'])?$_POST['_dw']:'');
    }
    $site  = trim(isset($_POST['site_name'])?$_POST['site_name']:'Mi TPV');
    $auser = trim(isset($_POST['admin_user'])?$_POST['admin_user']:'');
    $apass = isset($_POST['admin_pass'])?$_POST['admin_pass']:'';
    $aemail= trim(isset($_POST['admin_email'])?$_POST['admin_email']:'');

    if(empty($db['name'])) $errors[]='Datos de BD no disponibles. Rellena los campos de BD abajo.';
    if(empty($auser))      $errors[]='Introduce usuario admin';
    if(strlen($apass)<8)   $errors[]='Contrasena minimo 8 caracteres';
    if(!filter_var($aemail,FILTER_VALIDATE_EMAIL)) $errors[]='Email admin no valido';

    if(empty($errors)){
        try{
            $pdo=new PDO('mysql:host='.$db['host'].';port='.$db['port'].';dbname='.$db['name'].';charset=utf8mb4',$db['user'],$db['pass'],array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION));
            $pdo->exec("SET NAMES utf8mb4");
            $pdo->exec("SET SESSION sql_mode=''");

            $pdo->exec("CREATE TABLE IF NOT EXISTS `settings` (`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`key` VARCHAR(100) NOT NULL UNIQUE,`value` TEXT,`updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS `concepts` (`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`name` VARCHAR(200) NOT NULL,`description` VARCHAR(500) DEFAULT '',`icon` VARCHAR(20) DEFAULT '',`amount` DECIMAL(10,2) DEFAULT NULL,`is_libre` TINYINT(1) DEFAULT 0,`ref_prefix` VARCHAR(20) DEFAULT 'PAY',`active` TINYINT(1) DEFAULT 1,`sort_order` INT UNSIGNED DEFAULT 0,`fecha_limite` DATE DEFAULT NULL,`max_pagos` INT UNSIGNED DEFAULT NULL,`min_amount` DECIMAL(10,2) DEFAULT NULL,`max_amount` DECIMAL(10,2) DEFAULT NULL,`url_ok_custom` VARCHAR(500) DEFAULT NULL,`created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,`updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS `transactions` (`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`concept_id` INT UNSIGNED DEFAULT NULL,`concept_name` VARCHAR(200) DEFAULT '',`order_ref` VARCHAR(100) NOT NULL UNIQUE,`amount` DECIMAL(10,2) NOT NULL,`customer_name` VARCHAR(200) DEFAULT '',`customer_email` VARCHAR(300) DEFAULT '',`customer_ip` VARCHAR(45) DEFAULT '',`redsys_order` VARCHAR(100) DEFAULT NULL,`redsys_auth` VARCHAR(100) DEFAULT NULL,`redsys_response` VARCHAR(10) DEFAULT NULL,`redsys_merchant` TEXT,`status` ENUM('pending','ok','error','cancelled') DEFAULT 'pending',`email_sent` TINYINT(1) DEFAULT 0,`email_error` VARCHAR(500) DEFAULT NULL,`notes` TEXT DEFAULT NULL,`status_log` TEXT DEFAULT NULL,`created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,`updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,FOREIGN KEY (`concept_id`) REFERENCES `concepts`(`id`) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS `admin_users` (`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`username` VARCHAR(100) NOT NULL UNIQUE,`password_hash` VARCHAR(255) NOT NULL,`email` VARCHAR(300) NOT NULL,`name` VARCHAR(200) DEFAULT '',`active` TINYINT(1) DEFAULT 1,`last_login` DATETIME DEFAULT NULL,`created_at` DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS `admin_log` (`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`user_id` INT UNSIGNED DEFAULT NULL,`action` VARCHAR(200) DEFAULT '',`ip` VARCHAR(45) DEFAULT '',`created_at` DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->prepare('INSERT INTO admin_users (username,password_hash,email,name,active) VALUES(?,?,?,?,1) ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash),email=VALUES(email)')
               ->execute(array($auser,password_hash($apass,PASSWORD_BCRYPT),$aemail,$auser));

            $sets=array('site_name'=>$site,'mail_method'=>'sendmail','mail_from_email'=>$aemail,'mail_from_name'=>$site,'mail_admin_email'=>$aemail,'mail_admin_name'=>'Admin','mail_subject_cliente'=>'Confirmacion de pago - {concepto}','mail_subject_admin'=>'Nuevo pago - {concepto} - {nombre}','redsys_environment'=>'test','redsys_currency'=>'978','redsys_terminal'=>'1');
            $st=$pdo->prepare('INSERT INTO settings (`key`,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)');
            foreach($sets as $k=>$v) $st->execute(array($k,$v));

            $pdo->prepare('INSERT IGNORE INTO concepts (name,description,icon,amount,ref_prefix,active,sort_order) VALUES(?,?,?,?,?,1,0)')
               ->execute(array('Concepto de ejemplo','Edita o elimina desde el admin','',10.00,'PAY'));

            $c ="<?php\n// Generado ".date('Y-m-d H:i:s')."\n// NO subas este archivo\n\n";
            $c.="define('DB_HOST',".var_export($db['host'],true).");\n";
            $c.="define('DB_PORT',".(int)$db['port'].");\n";
            $c.="define('DB_NAME',".var_export($db['name'],true).");\n";
            $c.="define('DB_USER',".var_export($db['user'],true).");\n";
            $c.="define('DB_PASS',".var_export($db['pass'],true).");\n";
            if(file_put_contents($cfg,$c)===false) throw new Exception('No se pudo escribir config.php — comprueba permisos.');

            $ok=true;
            unset($_SESSION['idb']);
        }catch(Exception $e){$errors[]='Error: '.h($e->getMessage());}
    }
}

$sdb=isset($_SESSION['idb'])?$_SESSION['idb']:array();
$hasDb=!empty($sdb['name']);
?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Instalador TPV Redsys</title>
<style>*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f4f4f6;padding:40px 20px;}
.wrap{max-width:560px;margin:0 auto;}
.hdr{text-align:center;margin-bottom:28px;}.hdr h1{font-size:22px;font-weight:700;}.hdr p{font-size:13px;color:#888;margin-top:5px;}
.steps{display:flex;margin-bottom:26px;}
.st{flex:1;text-align:center;font-size:12px;color:#aaa;padding-bottom:9px;border-bottom:2px solid #e0e0e0;}
.st.on{color:#1a1a1a;font-weight:600;border-bottom-color:#1a1a1a;}.st.done{color:#1a7a3a;border-bottom-color:#1a7a3a;}
.card{background:#fff;border-radius:12px;border:1px solid #e8e8e8;padding:26px;margin-bottom:16px;}
.card h2{font-size:17px;font-weight:600;margin-bottom:7px;}.card>p{font-size:13px;color:#666;margin-bottom:16px;line-height:1.6;}
label{font-size:13px;color:#555;display:block;margin-bottom:4px;margin-top:11px;}
input[type=text],input[type=email],input[type=url],input[type=password],input[type=number]{width:100%;border:1px solid #ddd;border-radius:7px;padding:9px 11px;font-size:14px;outline:none;}
input:focus{border-color:#1a1a1a;}.r2{display:flex;gap:12px;}.r2>div{flex:1;}
.btn{display:inline-block;background:#1a1a1a;color:#fff;border:none;border-radius:8px;padding:10px 24px;font-size:14px;font-weight:600;cursor:pointer;text-decoration:none;margin-top:16px;}
.btn:hover{opacity:.85;}
.err{background:#fff1f0;border:1px solid #fcc;border-radius:8px;padding:11px 15px;font-size:13px;color:#c00;margin-bottom:14px;}
.err ul{margin:5px 0 0 15px;}
.ok-box{background:#e6f9ee;border:1px solid #b7e4c7;border-radius:8px;padding:20px;color:#1a7a3a;}
.ok-box h3{font-size:16px;margin-bottom:9px;}.ok-box ul{margin:7px 0 0 15px;line-height:2.2;}
.warn{background:#fffbeb;border:1px solid #f0d98a;border-radius:8px;padding:13px 15px;font-size:12px;color:#8a6a00;margin-top:14px;line-height:1.6;}
.req-r{display:flex;align-items:center;gap:9px;padding:7px 0;border-bottom:1px solid #f5f5f5;font-size:13px;}
.req-r:last-child{border:none;}.dot{width:10px;height:10px;border-radius:50%;flex-shrink:0;}
.g{background:#1a7a3a;}.rr{background:#c0392b;}
.hint{font-size:12px;color:#aaa;margin-top:4px;line-height:1.5;}
hr{border:none;border-top:1px solid #f0f0f0;margin:18px 0;}
code{background:#f5f5f5;border-radius:3px;padding:2px 5px;font-size:12px;}
</style></head><body>
<div class="wrap">
<div class="hdr"><h1>TPV Redsys</h1><p>Instalador &mdash; PHP <?php echo PHP_VERSION; ?></p></div>
<div class="steps">
  <div class="st <?php echo $step===1?'on':($step>1?'done':''); ?>">1. Requisitos</div>
  <div class="st <?php echo $step===2?'on':($step>2?'done':''); ?>">2. Base de datos</div>
  <div class="st <?php echo $step===3?'on':($step>3?'done':''); ?>">3. Configuracion</div>
  <div class="st <?php echo $ok?'done':''; ?>">4. Listo</div>
</div>

<?php if(!empty($errors)): ?><div class="err"><ul><?php foreach($errors as $e) echo '<li>'.$e.'</li>'; ?></ul></div><?php endif; ?>

<?php if($ok): ?>
<div class="ok-box">
  <h3>Instalacion completada</h3>
  <ul>
    <li><a href="../index.php" style="color:#1a7a3a;">Pagina de pago</a></li>
    <li><a href="../admin/login.php" style="color:#1a7a3a;">Panel de administracion</a></li>
  </ul>
</div>
<div class="warn"><strong>IMPORTANTE:</strong> Elimina o renombra la carpeta <code>install/</code> ahora mismo por seguridad.</div>

<?php elseif($step===1): ?>
<div class="card">
  <h2>Verificacion de requisitos</h2>
  <?php
  $chks=array(
    array('PHP 7.2+', PHP_VERSION_ID>=70200, 'PHP '.PHP_VERSION),
    array('PDO MySQL', extension_loaded('pdo_mysql'), ''),
    array('OpenSSL',   extension_loaded('openssl'),   ''),
    array('Escritura', is_writable(__DIR__.'/..'),     ''),
  );
  $allok=true;
  foreach($chks as $ch){
    if(!$ch[1]) $allok=false;
    echo '<div class="req-r"><div class="dot '.($ch[1]?'g':'rr').'"></div><span style="flex:1;">'.$ch[0].'</span>';
    if($ch[2]) echo '<span style="font-size:11px;color:#aaa;">'.h($ch[2]).'</span>';
    if(!$ch[1]) echo '<span style="font-size:12px;color:#c00;font-weight:600;">Requerido</span>';
    echo '</div>';
  }
  ?>
  <?php if($allok): ?><a href="index.php?step=2" class="btn">Continuar &rarr;</a><?php else: ?><p style="color:#c00;font-size:13px;margin-top:12px;">Corrige los errores antes de continuar.</p><?php endif; ?>
</div>

<?php elseif($step===2): ?>
<div class="card">
  <h2>Base de datos</h2>
  <p>Datos de MySQL/MariaDB. La BD se crea si no existe.</p>
  <form method="POST" action="index.php?step=2">
    <div class="r2"><div><label>Host</label><input type="text" name="db_host" value="<?php echo h(isset($_POST['db_host'])?$_POST['db_host']:'localhost'); ?>" placeholder="localhost"></div>
    <div style="max-width:110px;"><label>Puerto</label><input type="number" name="db_port" value="<?php echo h(isset($_POST['db_port'])?$_POST['db_port']:'3306'); ?>"></div></div>
    <label>Nombre BD *</label><input type="text" name="db_name" value="<?php echo h(isset($_POST['db_name'])?$_POST['db_name']:''); ?>" placeholder="tpv_redsys" required>
    <label>Usuario BD *</label><input type="text" name="db_user" value="<?php echo h(isset($_POST['db_user'])?$_POST['db_user']:''); ?>" placeholder="root" required autocomplete="off">
    <label>Contrasena BD</label><input type="password" name="db_pass" placeholder="(vacia si no tiene)">
    <button type="submit" class="btn">Conectar &rarr;</button>
  </form>
</div>

<?php elseif($step===3): ?>
<div class="card">
  <h2>Configuracion del sistema</h2>
  <form method="POST" action="index.php?step=3">
    <?php if($hasDb): ?>
    <input type="hidden" name="_dh" value="<?php echo h($sdb['host']); ?>">
    <input type="hidden" name="_dp" value="<?php echo h($sdb['port']); ?>">
    <input type="hidden" name="_dn" value="<?php echo h($sdb['name']); ?>">
    <input type="hidden" name="_du" value="<?php echo h($sdb['user']); ?>">
    <input type="hidden" name="_dw" value="<?php echo h($sdb['pass']); ?>">
    <?php else: ?>
    <div style="background:#fffbeb;border:1px solid #f0d98a;border-radius:8px;padding:14px;margin-bottom:14px;">
      <p style="font-size:13px;color:#8a6a00;font-weight:600;margin-bottom:8px;">Sesion perdida — introduce los datos de BD de nuevo</p>
      <div class="r2"><div><label>Host BD</label><input type="text" name="_dh" value="localhost"></div>
      <div style="max-width:110px;"><label>Puerto</label><input type="number" name="_dp" value="3306"></div></div>
      <label>Nombre BD</label><input type="text" name="_dn" placeholder="tpv_redsys" required>
      <label>Usuario BD</label><input type="text" name="_du" placeholder="root" autocomplete="off">
      <label>Contrasena BD</label><input type="password" name="_dw">
    </div>
    <?php endif; ?>

    <label>Nombre del sitio / negocio</label>
    <input type="text" name="site_name" value="<?php echo h(isset($_POST['site_name'])?$_POST['site_name']:'Mi TPV'); ?>" placeholder="Mi Negocio" required>

    <hr>
    <p style="font-size:13px;font-weight:600;color:#555;">Usuario administrador</p>
    <label>Usuario</label>
    <input type="text" name="admin_user" value="<?php echo h(isset($_POST['admin_user'])?$_POST['admin_user']:''); ?>" placeholder="admin" required autocomplete="off">
    <label>Contrasena (min. 8 caracteres)</label>
    <input type="password" name="admin_pass" required minlength="8" autocomplete="new-password">
    <label>Email admin</label>
    <input type="email" name="admin_email" value="<?php echo h(isset($_POST['admin_email'])?$_POST['admin_email']:''); ?>" placeholder="admin@dominio.com" required>

    <button type="submit" class="btn">Instalar &rarr;</button>
  </form>
</div>
<?php endif; ?>
</div></body></html>
