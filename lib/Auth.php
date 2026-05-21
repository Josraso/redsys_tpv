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

    public static function start()
    {
        if (session_status() === PHP_SESSION_NONE) {
            // PHP 7.2: session_set_cookie_params NO acepta array, usar parametros separados
            session_set_cookie_params(0, '/');
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
            // Ruta relativa pura — sin BASE_URL, sin rutas absolutas
            // Desde admin/cualquier_pagina.php -> admin/login.php
            header('Location: login.php');
            exit;
        }
    }

    public static function login($username, $password)
    {
        self::start();
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
               ->execute(array($user['id'], 'login', isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : ''));
            return true;
        }
        return false;
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
}
