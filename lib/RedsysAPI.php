<?php
/**
 * Libreria Redsys SHA-256
 * Basada en la API oficial de Redsys (redsysHMAC256_API_PHP)
 * Compatible PHP 7.2+
 */
class RedsysAPI
{
    private $vars = array();

    public function setParameter($key, $value)
    {
        $this->vars[(string)$key] = (string)$value;
    }

    public function getParameter($key)
    {
        return isset($this->vars[$key]) ? $this->vars[$key] : '';
    }

    public function createMerchantParameters()
    {
        return base64_encode(json_encode($this->vars));
    }

    public function getDecodedMerchantParameters($data)
    {
        $decoded = json_decode(base64_decode(strtr($data, '-_', '+/')), true);
        return is_array($decoded) ? array_change_key_case($decoded, CASE_UPPER) : array();
    }

    /**
     * Genera la firma para enviar a Redsys
     * Algoritmo exacto de la libreria oficial Redsys
     */
    public function generateMerchantSignature($key, $merchantParameters, $order)
    {
        // 1. Decodificar la clave base64 (igual que la libreria oficial)
        $key = base64_decode($key);

        // 2. Diversificar la clave con el numero de pedido usando 3DES
        //    CON padding manual a multiplos de 8 y OPENSSL_NO_PADDING
        //    (este es el paso que estaba mal antes)
        $key = $this->encrypt3DES($order, $key);

        // 3. HMAC-SHA256 del bloque de parametros con la clave diversificada
        return base64_encode(hash_hmac('sha256', $merchantParameters, $key, true));
    }

    public function validateResponse($key, $merchantParameters, $signatureReceived, $order)
    {
        $calculated = $this->generateMerchantSignature($key, $merchantParameters, $order);
        // Normalizar base64url a base64 estandar
        $received   = str_replace(array('-', '_'), array('+', '/'), $signatureReceived);
        return hash_equals(strtoupper($calculated), strtoupper($received));
    }

    /**
     * 3DES exactamente como en la libreria oficial Redsys:
     * - Padding manual con \0 hasta multiplo de 8
     * - OPENSSL_RAW_DATA | OPENSSL_NO_PADDING (sin padding automatico PKCS7)
     */
    private function encrypt3DES($data, $key)
    {
        $iv = "\0\0\0\0\0\0\0\0";

        // Padding manual a multiplo de 8 con bytes nulos
        if (strlen($data) % 8) {
            $data = str_pad($data, strlen($data) + 8 - strlen($data) % 8, "\0");
        }

        return openssl_encrypt(
            $data,
            'DES-EDE3-CBC',
            $key,
            OPENSSL_RAW_DATA | OPENSSL_NO_PADDING,
            $iv
        );
    }

    public static function isResponseOk($responseCode)
    {
        $str  = ltrim((string)$responseCode, '0');
        $code = ($str === '') ? 0 : (int)$str;
        return ($code >= 0 && $code <= 99);
    }

    public static function getResponseText($responseCode)
    {
        $str  = ltrim((string)$responseCode, '0');
        $code = ($str === '') ? 0 : (int)$str;
        if ($code >= 0 && $code <= 99) return 'Pago autorizado';
        $map = array(
            101=>'Tarjeta caducada',
            102=>'Tarjeta bajo sospecha de fraude',
            104=>'Operacion no permitida',
            116=>'Saldo insuficiente',
            118=>'Tarjeta no registrada',
            129=>'CVV incorrecto',
            180=>'Tarjeta ajena al servicio',
            184=>'Error autenticacion titular',
            190=>'Denegado sin motivo',
            191=>'Fecha caducidad erronea',
            904=>'Comercio no registrado en FUC',
            909=>'Error de sistema',
            913=>'Pedido repetido',
            9915=>'Cancelado por el usuario',
        );
        return isset($map[$code]) ? $map[$code] : 'Error (cod. '.$responseCode.')';
    }

    /**
     * Genera referencia valida para Redsys:
     * - Entre 4 y 12 caracteres
     * - Solo alfanumericos
     * - DEBE empezar por digito
     */
    public static function generateOrderRef($prefix = '')
    {
        // Siempre empieza por numero — timestamp 6 digitos + 4 aleatorios
        $ts   = str_pad((string)(time() % 999999), 6, '0', STR_PAD_LEFT);
        $rand = strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
        return $ts . $rand;
    }
}
