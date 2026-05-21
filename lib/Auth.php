<?php
/**
 * Autenticacion admin
 * Compatible PHP 7.2+
 */

require_once __DIR__ . '/db.php';

class Auth
{
    const SESSION_KEY  = 'tpv_admin_user';
    const SESSION_TIME = 7200;
    const MAX_ATTEMPTS = 5;
    const LOCKOUT_MINS = 15;

    public static function start()
    {
        if (session_status() === PHP_SESSION_NONE) {
            $isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
            // PHP 7.2 compat: parametros separados; SameSite via path hack
            session_set_cookie_params(0, '/; SameSite=Lax', '', $isHttps, true);
            session_start();
        }
    }

    public static function check()
    {
        self::start();
        if (empty($_SESSION[self::SESSION_KEY])) return false;
        if (isset($_SESSION['tpv_last_activity'])
            && (time() - $_SESSION['tpv_last_activity']) > self::SESSION_TIME) {
            self::logout();
            return false;
        }
        $_SESSION['tpv_last_activity'] = time();
        return true;
    }

    public static function requireLogin()
    {
        if (!self::check()) {
            header('Location: login.php');
            exit;
        }
    }

    /**
     * @return string 'ok' | 'fail' | 'locked'
     */
    public static function login($username, $password)
    {
        self::start();
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';

        // Comprobar bloqueo por IP: max MAX_ATTEMPTS en LOCKOUT_MINS minutos
        try {
            $cutoff  = date('Y-m-d H:i:s', time() - self::LOCKOUT_MINS * 60);
            $stFails = db()->prepare(
                "SELECT COUNT(*) FROM admin_log WHERE action='login_fail' AND ip=? AND created_at >= ?"
            );
            $stFails->execute(array($ip, $cutoff));
            if ((int)$stFails->fetchColumn() >= self::MAX_ATTEMPTS) {
                return 'locked';
            }
        } catch (Exception $e) {
            // admin_log no existe todavia (primera instalacion): ignorar
        }

        $st = db()->prepare('SELECT * FROM admin_users WHERE username = ? AND active = 1');
        $st->execute(array($username));
        $user = $st->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION[self::SESSION_KEY]   = $user['id'];
            $_SESSION['tpv_admin_name']    = $user['name'];
            $_SESSION['tpv_last_activity'] = time();
            db()->prepare('UPDATE admin_users SET last_login = NOW() WHERE id = ?')
               ->execute(array($user['id']));
            db()->prepare('INSERT INTO admin_log (user_id, action, ip) VALUES (?,?,?)')
               ->execute(array($user['id'], 'login', $ip));
            return 'ok';
        }

        // Registrar intento fallido para el bloqueo por IP
        try {
            db()->prepare('INSERT INTO admin_log (user_id, action, ip) VALUES (?,?,?)')
               ->execute(array(null, 'login_fail', $ip));
        } catch (Exception $e) {}

        return 'fail';
    }

    public static function logout()
    {
        self::start();
        $_SESSION = array();
        session_destroy();
    }

    public static function userId()
    {
        return isset($_SESSION[self::SESSION_KEY]) ? (int)$_SESSION[self::SESSION_KEY] : null;
    }

    public static function adminName()
    {
        return isset($_SESSION['tpv_admin_name']) ? $_SESSION['tpv_admin_name'] : 'Admin';
    }

    // ── CSRF ─────────────────────────────────────────────────────────────────

    public static function csrfToken()
    {
        self::start();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function checkCsrf()
    {
        $token = isset($_POST['_csrf']) ? $_POST['_csrf']
               : (isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : '');
        if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
            http_response_code(403);
            die('Token de seguridad invalido. Recarga la pagina e intentalo de nuevo.');
        }
    }

    // ── Audit log ─────────────────────────────────────────────────────────────

    public static function logAction($action, $detail = '')
    {
        try {
            $userId = self::userId();
            $ip     = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
            $full   = $detail ? $action . ': ' . substr($detail, 0, 200) : $action;
            db()->prepare('INSERT INTO admin_log (user_id, action, ip) VALUES (?,?,?)')
               ->execute(array($userId, $full, $ip));
        } catch (Exception $e) {}
    }
}
