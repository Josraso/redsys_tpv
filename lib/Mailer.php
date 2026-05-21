<?php
/**
 * Mailer - sendmail / SMTP
 * Compatible PHP 7.2+
 */
require_once __DIR__ . '/db.php';

class Mailer
{
    private $method;
    private $smtp;
    private $fromEmail;
    private $fromName;

    public function __construct()
    {
        $this->method    = getSetting('mail_method', 'sendmail');
        $this->fromEmail = getSetting('mail_from_email', '');
        $this->fromName  = getSetting('mail_from_name', 'TPV');
        $this->smtp = array(
            'host'   => getSetting('smtp_host'),
            'port'   => (int)getSetting('smtp_port', '587'),
            'user'   => getSetting('smtp_user'),
            'pass'   => getSetting('smtp_pass'),
            'secure' => getSetting('smtp_secure', 'tls'),
        );
    }

    public function send($to, $toName, $subject, $bodyHtml)
    {
        // Validaciones previas comunes
        if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return array('ok' => false, 'error' => 'Email de destino no valido: ' . $to);
        }
        if (empty($this->fromEmail)) {
            return array('ok' => false, 'error' => 'Configura el email remitente en Admin -> Emails');
        }

        if ($this->method === 'smtp') {
            return $this->sendSmtp($to, $toName, $subject, $bodyHtml);
        }
        return $this->sendNative($to, $toName, $subject, $bodyHtml);
    }

    // ── SENDMAIL ─────────────────────────────────────────────────────────────

    private function sendNative($to, $toName, $subject, $bodyHtml)
    {
        // Comprobar que mail() existe y que sendmail esta configurado en php.ini
        if (!function_exists('mail')) {
            return array('ok' => false, 'error' => 'La funcion mail() no existe en este servidor PHP');
        }

        $sendmailPath = ini_get('sendmail_path');
        if (empty($sendmailPath)) {
            return array('ok' => false, 'error' => 'sendmail_path no configurado en php.ini. Usa SMTP en su lugar.');
        }

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: =?UTF-8?B?" . base64_encode($this->fromName) . "?= <{$this->fromEmail}>\r\n";
        $headers .= "Reply-To: {$this->fromEmail}\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";

        $subjectEncoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        // Quitar supresion de errores para capturar bien
        set_error_handler(function() {});
        $ok = mail($to, $subjectEncoded, $bodyHtml, $headers);
        restore_error_handler();

        if (!$ok) {
            $lastErr = error_get_last();
            $errMsg  = ($lastErr && !empty($lastErr['message']))
                ? $lastErr['message']
                : 'mail() devolvio false. Comprueba sendmail_path en php.ini o usa SMTP.';
            return array('ok' => false, 'error' => $errMsg);
        }

        return array('ok' => true, 'error' => '');
    }

    // ── SMTP ─────────────────────────────────────────────────────────────────

    private function sendSmtp($to, $toName, $subject, $bodyHtml)
    {
        if (empty($this->smtp['host'])) {
            return array('ok' => false, 'error' => 'Host SMTP no configurado');
        }
        if (empty($this->smtp['user'])) {
            return array('ok' => false, 'error' => 'Usuario SMTP no configurado');
        }
        if (empty($this->smtp['pass'])) {
            return array('ok' => false, 'error' => 'Contrasena SMTP no configurada');
        }

        // Usar PHPMailer si esta disponible
        if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            return $this->sendPHPMailer($to, $toName, $subject, $bodyHtml);
        }
        return $this->sendSocket($to, $toName, $subject, $bodyHtml);
    }

    private function sendPHPMailer($to, $toName, $subject, $bodyHtml)
    {
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = $this->smtp['host'];
            $mail->Port       = $this->smtp['port'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $this->smtp['user'];
            $mail->Password   = $this->smtp['pass'];
            $mail->SMTPSecure = ($this->smtp['secure'] === 'ssl') ? 'ssl' : 'tls';
            $mail->CharSet    = 'UTF-8';
            $mail->setFrom($this->fromEmail, $this->fromName);
            $mail->addAddress($to, $toName);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $bodyHtml;
            $mail->AltBody = strip_tags($bodyHtml);
            $mail->send();
            return array('ok' => true, 'error' => '');
        } catch (Exception $e) {
            return array('ok' => false, 'error' => 'PHPMailer: ' . $e->getMessage());
        }
    }

    private function sendSocket($to, $toName, $subject, $bodyHtml)
    {
        $host   = $this->smtp['host'];
        $port   = $this->smtp['port'];
        $secure = $this->smtp['secure'];

        try {
            $ctx = stream_context_create(array(
                'ssl' => array('verify_peer' => false, 'verify_peer_name' => false)
            ));

            if ($secure === 'ssl') {
                $conn = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
            } else {
                $conn = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 15);
            }

            if (!$conn) {
                return array('ok' => false, 'error' => "No se pudo conectar a {$host}:{$port} — {$errstr} ({$errno})");
            }

            stream_set_timeout($conn, 15);

            $read = function() use ($conn) {
                $line = fgets($conn, 1024);
                return $line !== false ? trim($line) : '';
            };

            $write = function($cmd) use ($conn) {
                fwrite($conn, $cmd . "\r\n");
            };

            $banner = $read();
            if (substr($banner, 0, 3) !== '220') {
                fclose($conn);
                return array('ok' => false, 'error' => "Banner SMTP inesperado: {$banner}");
            }

            $write("EHLO " . (gethostname() ?: 'localhost'));
            $ehloResp = '';
            while ($line = fgets($conn, 1024)) {
                $ehloResp .= $line;
                if (isset($line[3]) && $line[3] === ' ') break;
            }

            if ($secure === 'tls') {
                $write("STARTTLS");
                $tlsResp = $read();
                if (substr($tlsResp, 0, 3) !== '220') {
                    fclose($conn);
                    return array('ok' => false, 'error' => "STARTTLS fallo: {$tlsResp}");
                }
                if (!stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    fclose($conn);
                    return array('ok' => false, 'error' => 'No se pudo establecer TLS');
                }
                $write("EHLO " . (gethostname() ?: 'localhost'));
                while ($line = fgets($conn, 1024)) {
                    if (isset($line[3]) && $line[3] === ' ') break;
                }
            }

            $write("AUTH LOGIN");
            $r1 = $read();
            if (substr($r1, 0, 3) !== '334') {
                fclose($conn);
                return array('ok' => false, 'error' => "AUTH LOGIN rechazo: {$r1}");
            }

            $write(base64_encode($this->smtp['user']));
            $r2 = $read();
            if (substr($r2, 0, 3) !== '334') {
                fclose($conn);
                return array('ok' => false, 'error' => "Usuario SMTP rechazado: {$r2}");
            }

            $write(base64_encode($this->smtp['pass']));
            $r3 = $read();
            if (substr($r3, 0, 3) !== '235') {
                fclose($conn);
                return array('ok' => false, 'error' => "Autenticacion SMTP fallida: {$r3} — revisa usuario/contrasena");
            }

            $write("MAIL FROM:<{$this->fromEmail}>");
            $r4 = $read();
            if (substr($r4, 0, 3) !== '250') {
                fclose($conn);
                return array('ok' => false, 'error' => "MAIL FROM rechazado: {$r4}");
            }

            $write("RCPT TO:<{$to}>");
            $r5 = $read();
            if (substr($r5, 0, 3) !== '250') {
                fclose($conn);
                return array('ok' => false, 'error' => "RCPT TO rechazado: {$r5}");
            }

            $write("DATA");
            $r6 = $read();
            if (substr($r6, 0, 3) !== '354') {
                fclose($conn);
                return array('ok' => false, 'error' => "DATA rechazado: {$r6}");
            }

            $boundary = md5(uniqid('', true));
            $msg  = "From: =?UTF-8?B?" . base64_encode($this->fromName) . "?= <{$this->fromEmail}>\r\n";
            $msg .= "To: =?UTF-8?B?" . base64_encode($toName ?: $to) . "?= <{$to}>\r\n";
            $msg .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
            $msg .= "MIME-Version: 1.0\r\n";
            $msg .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n\r\n";
            $msg .= "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n";
            $msg .= strip_tags($bodyHtml) . "\r\n";
            $msg .= "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n";
            $msg .= $bodyHtml . "\r\n";
            $msg .= "--{$boundary}--\r\n\r\n.";

            fwrite($conn, $msg . "\r\n");
            $r7 = $read();
            fwrite($conn, "QUIT\r\n");
            fclose($conn);

            if (substr($r7, 0, 3) !== '250') {
                return array('ok' => false, 'error' => "Mensaje rechazado por el servidor: {$r7}");
            }

            return array('ok' => true, 'error' => '');

        } catch (Exception $e) {
            return array('ok' => false, 'error' => 'SMTP: ' . $e->getMessage());
        }
    }

    // ── Plantillas ────────────────────────────────────────────────────────────

    /**
     * Construye la URL absoluta del justificante de forma robusta
     * La URL base se guarda en settings durante la instalacion
     */
    public static function getSiteBaseUrl()
    {
        // Primero intentar desde settings (guardada en la instalacion)
        $base = getSetting('site_base_url');
        if ($base) return rtrim($base, '/');

        // Fallback: construir desde SERVER si esta disponible
        if (isset($_SERVER['HTTP_HOST'])) {
            $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            // SCRIPT_NAME puede ser /pago/notify.php, /admin/emails.php, etc.
            // Necesitamos la raiz: quitar el ultimo segmento de directorio
            $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME']);
            // Subir hasta la raiz buscando index.php o quitando carpetas conocidas
            $dir = dirname($script);
            foreach (array('/pago', '/admin', '/lib', '/install') as $seg) {
                if (substr($dir, -strlen($seg)) === $seg) {
                    $dir = substr($dir, 0, strlen($dir) - strlen($seg));
                    break;
                }
            }
            return $proto . '://' . $_SERVER['HTTP_HOST'] . rtrim($dir, '/');
        }
        return '';
    }

    public static function justificanteBtn($orderRef)
    {
        if (empty($orderRef)) return '';
        $base = self::getSiteBaseUrl();
        if (!$base) return '';
        $url = $base . '/pago/justificante.php?ref=' . urlencode($orderRef);
        return '<div style="text-align:center;margin-top:16px;">'
             . '<a href="' . htmlspecialchars($url) . '" '
             . 'style="display:inline-block;background:#1a7a3a;color:#fff;text-decoration:none;'
             . 'padding:10px 22px;border-radius:8px;font-size:13px;font-weight:600;">'
             . '&#128196; Descargar justificante</a>'
             . '</div>';
    }

    public static function templateCliente($tx)
    {
        $siteName   = getSetting('site_name', 'TPV');
        $footerTxt  = getSetting('mail_footer', '');
        $amount     = number_format((float)$tx['amount'], 2, ',', '.') . ' EUR';
        $date       = date('d/m/Y H:i', strtotime(isset($tx['created_at']) ? $tx['created_at'] : 'now'));
        // Logo: primero buscar el del concepto, luego el del sitio
        $conceptId  = isset($tx['concept_id']) ? (int)$tx['concept_id'] : 0;
        $logoPath   = $conceptId ? getSetting('logo_concept_'.$conceptId) : '';
        if (!$logoPath) $logoPath = getSetting('logo_site');
        // Construir URL absoluta del logo para email (los emails necesitan URL absoluta)
        $logoHtml   = '';
        if ($logoPath) {
            // Intentar construir URL desde SERVER si esta disponible
            $baseUrl = (isset($_SERVER['HTTP_HOST'])
                ? ((isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']==='on'?'https':'http').'://'.$_SERVER['HTTP_HOST'])
                : '') . rtrim(str_replace('/lib','', dirname($_SERVER['SCRIPT_NAME'] ?? '/lib/Mailer.php')),'/');
            $logoUrl  = $baseUrl . '/' . $logoPath;
            $logoHtml = '<div style="text-align:center;padding:16px 32px 0;">'
                      . '<img src="' . htmlspecialchars($logoUrl) . '" alt="' . htmlspecialchars($siteName) . '" '
                      . 'style="max-height:60px;max-width:200px;object-fit:contain;"></div>';
        }

        return '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><style>'
             . 'body{font-family:Arial,sans-serif;background:#f5f5f5;margin:0;padding:20px;}'
             . '.wrap{max-width:560px;margin:0 auto;background:#fff;border-radius:8px;border:1px solid #e0e0e0;overflow:hidden;}'
             . '.head{background:#1a1a1a;color:#fff;padding:24px 32px;}'
             . '.head h1{margin:0;font-size:19px;font-weight:600;}'
             . '.body{padding:24px 32px;}'
             . '.ok{display:inline-block;background:#e6f9ee;color:#1a7a3a;border-radius:20px;padding:4px 14px;font-size:13px;font-weight:600;margin-bottom:16px;}'
             . 'table{width:100%;border-collapse:collapse;font-size:14px;}'
             . 'td{padding:9px 0;border-bottom:1px solid #f0f0f0;}'
             . 'td:first-child{color:#666;width:42%;}'
             . 'td:last-child{font-weight:600;text-align:right;}'
             . '.foot{padding:16px 32px;background:#fafafa;border-top:1px solid #f0f0f0;font-size:12px;color:#999;}'
             . '</style></head><body>'
             . '<div class="wrap">'
             . $logoHtml
             . '<div class="head"><h1>' . htmlspecialchars($siteName) . '</h1></div>'
             . '<div class="body">'
             . '<p>Hola <strong>' . htmlspecialchars($tx['customer_name']) . '</strong>,</p>'
             . '<p style="margin:10px 0;">Hemos recibido tu pago correctamente.</p>'
             . '<span class="ok">&#10003; Pago confirmado</span>'
             . '<table>'
             . '<tr><td>Concepto</td><td>' . htmlspecialchars($tx['concept_name']) . '</td></tr>'
             . '<tr><td>Referencia</td><td>' . htmlspecialchars($tx['order_ref']) . '</td></tr>'
             . '<tr><td>Fecha</td><td>' . $date . '</td></tr>'
             . '<tr><td>Total pagado</td><td style="font-size:18px;">' . $amount . '</td></tr>'
             . '</table>'
             . '<p style="margin-top:20px;font-size:13px;color:#666;">Guarda este email como justificante de pago.</p>'
             . self::justificanteBtn($tx['order_ref'])
             . '</div>'
             . '<div class="foot">' . nl2br(htmlspecialchars($footerTxt)) . '</div>'
             . '</div></body></html>';
    }

    public static function templateAdmin($tx)
    {
        $siteName = getSetting('site_name', 'TPV');
        $amount   = number_format((float)$tx['amount'], 2, ',', '.') . ' EUR';
        $date     = date('d/m/Y H:i', strtotime(isset($tx['created_at']) ? $tx['created_at'] : 'now'));
        $auth     = isset($tx['redsys_auth']) && $tx['redsys_auth'] ? $tx['redsys_auth'] : '-';

        return '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><style>'
             . 'body{font-family:Arial,sans-serif;background:#f5f5f5;margin:0;padding:20px;}'
             . '.wrap{max-width:560px;margin:0 auto;background:#fff;border-radius:8px;border:1px solid #e0e0e0;overflow:hidden;}'
             . '.head{background:#1a1a1a;color:#fff;padding:20px 32px;}'
             . '.head h1{margin:0;font-size:17px;}'
             . '.body{padding:24px 32px;}'
             . 'table{width:100%;border-collapse:collapse;font-size:14px;}'
             . 'td{padding:9px 0;border-bottom:1px solid #f0f0f0;}'
             . 'td:first-child{color:#666;width:45%;}'
             . 'td:last-child{font-weight:600;text-align:right;}'
             . '</style></head><body>'
             . '<div class="wrap">'
             . '<div class="head"><h1>Nuevo pago recibido &mdash; ' . htmlspecialchars($siteName) . '</h1></div>'
             . '<div class="body"><table>'
             . '<tr><td>Cliente</td><td>' . htmlspecialchars($tx['customer_name']) . '</td></tr>'
             . '<tr><td>Email</td><td>' . htmlspecialchars($tx['customer_email']) . '</td></tr>'
             . '<tr><td>Concepto</td><td>' . htmlspecialchars($tx['concept_name']) . '</td></tr>'
             . '<tr><td>Importe</td><td style="font-size:16px;">' . $amount . '</td></tr>'
             . '<tr><td>Referencia</td><td>' . htmlspecialchars($tx['order_ref']) . '</td></tr>'
             . '<tr><td>Autorizacion Redsys</td><td>' . htmlspecialchars($auth) . '</td></tr>'
             . '<tr><td>IP cliente</td><td>' . htmlspecialchars(isset($tx['customer_ip']) ? $tx['customer_ip'] : '-') . '</td></tr>'
             . '<tr><td>Fecha</td><td>' . $date . '</td></tr>'
             . '</table></div></div></body></html>';
    }
}
