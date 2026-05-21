<?php
/**
 * Conexion BD + settings
 * Todas las rutas con __DIR__ — funciona en cualquier ubicacion
 */

// Config siempre relativo a este archivo
$_tpv_config = __DIR__ . '/../config.php';
if (!file_exists($_tpv_config)) {
    die('<h2>Falta config.php</h2><p>Ejecuta el instalador: <a href="install/">install/</a></p>');
}
require_once $_tpv_config;

function db()
{
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    try {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, array(
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ));
    } catch (PDOException $e) {
        die('<div style="font-family:sans-serif;padding:30px;background:#fff1f0;border:1px solid #fcc;border-radius:8px;max-width:600px;margin:40px auto;">'
          . '<h2>Error de conexion a la base de datos</h2>'
          . '<p>' . htmlspecialchars($e->getMessage()) . '</p>'
          . '<p style="margin-top:12px;">Edita <code>config.php</code> con los datos correctos de tu base de datos.</p>'
          . '<p style="font-size:12px;color:#888;margin-top:8px;">'
          . 'Host: <code>' . DB_HOST . '</code> | '
          . 'BD: <code>' . DB_NAME . '</code> | '
          . 'Usuario: <code>' . DB_USER . '</code> | '
          . 'Pass: <code>' . (DB_PASS === '' ? 'vacia' : 'definida') . '</code>'
          . '</p></div>');
    }
    return $pdo;
}

function runMigrations()
{
    try {
        $pdo = db();

        // transactions: notes
        if (empty($pdo->query("SHOW COLUMNS FROM transactions LIKE 'notes'")->fetchAll()))
            $pdo->exec("ALTER TABLE transactions ADD COLUMN `notes` TEXT DEFAULT NULL AFTER `email_error`");

        // concepts: fecha_limite, max_pagos, min_amount, max_amount, url_ok_custom
        $cols = array_column($pdo->query("SHOW COLUMNS FROM concepts")->fetchAll(), 'Field');
        if (!in_array('fecha_limite', $cols))
            $pdo->exec("ALTER TABLE concepts ADD COLUMN `fecha_limite` DATE DEFAULT NULL AFTER `active`");
        if (!in_array('max_pagos', $cols))
            $pdo->exec("ALTER TABLE concepts ADD COLUMN `max_pagos` INT UNSIGNED DEFAULT NULL AFTER `fecha_limite`");
        if (!in_array('min_amount', $cols))
            $pdo->exec("ALTER TABLE concepts ADD COLUMN `min_amount` DECIMAL(10,2) DEFAULT NULL AFTER `max_pagos`");
        if (!in_array('max_amount', $cols))
            $pdo->exec("ALTER TABLE concepts ADD COLUMN `max_amount` DECIMAL(10,2) DEFAULT NULL AFTER `min_amount`");
        if (!in_array('url_ok_custom', $cols))
            $pdo->exec("ALTER TABLE concepts ADD COLUMN `url_ok_custom` VARCHAR(500) DEFAULT NULL AFTER `max_amount`");

        // transactions: status_log
        if (!in_array('status_log', array_column($pdo->query("SHOW COLUMNS FROM transactions")->fetchAll(), 'Field')))
            $pdo->exec("ALTER TABLE transactions ADD COLUMN `status_log` TEXT DEFAULT NULL AFTER `notes`");

        // Asegurar site_base_url en settings si no existe
        $existing = $pdo->query("SELECT value FROM settings WHERE `key`='site_base_url'")->fetchColumn();
        if (!$existing && isset($_SERVER['HTTP_HOST'])) {
            $proto = (isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']==='on')?'https':'http';
            $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME']);
            $dir = dirname($script);
            foreach (array('/pago','/admin','/lib','/install') as $seg) {
                if (substr($dir,-strlen($seg))===$seg) { $dir=substr($dir,0,strlen($dir)-strlen($seg)); break; }
            }
            $base = $proto.'://'.$_SERVER['HTTP_HOST'].rtrim($dir,'/');
            $pdo->prepare("INSERT IGNORE INTO settings (`key`,value) VALUES ('site_base_url',?)")->execute(array($base));
        }
    } catch (Exception $e) {
        // Ignorar si tabla no existe (durante instalacion)
    }
}

// Ejecutar migraciones automaticamente
runMigrations();

function getSetting($key, $default = '')
{
    static $cache = array();
    if (!isset($cache[$key])) {
        $st = db()->prepare('SELECT value FROM settings WHERE `key` = ?');
        $st->execute(array($key));
        $val = $st->fetchColumn();
        $cache[$key] = ($val !== false) ? $val : $default;
    }
    return $cache[$key];
}

function setSetting($key, $value)
{
    static $cache = array();
    $cache[$key] = $value;
    db()->prepare(
        'INSERT INTO settings (`key`, value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)'
    )->execute(array($key, $value));
}
