<?php
/**
 * helper/sync.php
 * ------------------------------------------------------------------
 * Autenticacion y transporte para la sincronizacion offline -> online.
 *
 * El endpoint servidor es sync.php; el cliente es
 * ModelCron::PushSqlToRemoteServer() en _inc/model/cron.php.
 *
 * AMBOS LADOS USAN ESTE ARCHIVO, de modo que la firma se calcula y se
 * verifica con exactamente el mismo codigo.
 *
 * Protocolo v2 (firmado):
 *   POST sync.php
 *   {
 *     "username":  "pos-01",          // id del cliente
 *     "action":    "sync",
 *     "data":      "<json con [{sql, args}]>",
 *     "timestamp": 1730000000,
 *     "nonce":     "<hex aleatorio>",
 *     "signature": "<hex hmac-sha256>"
 *   }
 *
 *   canonical  = username \n action \n timestamp \n nonce \n sha256(data)
 *   signature  = hash_hmac('sha256', canonical, secret)
 *
 * Protocolo v1 (heredado, solo durante la transicion):
 *   { "username": "...", "password": "...", "action": "sync", "data": "..." }
 *
 * El v1 se conserva unicamente mientras SYNC_LEGACY_AUTH=true, para no
 * dejar fuera a los POS que aun no han sido actualizados. En produccion
 * hay que desactivarlo.
 */

// ---------------------------------------------------------------------------
// Configuracion
// ---------------------------------------------------------------------------

if (!function_exists('sync_clients')) {
    /**
     * Mapa de clientes autorizados: id => secreto.
     * Se configura con SYNC_CLIENTS="pos-01:secreto1,pos-02:secreto2".
     *
     * @return array
     */
    function sync_clients()
    {
        $map = array();
        $raw = (string) env_get('SYNC_CLIENTS', '');

        foreach (explode(',', $raw) as $pair) {
            $pair = trim($pair);
            if ($pair === '' || strpos($pair, ':') === false) {
                continue;
            }
            list($id, $secret) = explode(':', $pair, 2);
            $id = trim($id);
            $secret = trim($secret);
            if ($id !== '' && $secret !== '') {
                $map[$id] = $secret;
            }
        }

        return $map;
    }
}

if (!function_exists('sync_config')) {
    /**
     * Configuracion efectiva de la sincronizacion, leida del .env.
     *
     * @return array
     */
    function sync_config()
    {
        static $cfg = null;

        if ($cfg === null) {
            $ips = array();
            foreach (explode(',', (string) env_get('SYNC_ALLOWED_IPS', '')) as $ip) {
                $ip = trim($ip);
                if ($ip !== '') {
                    $ips[] = $ip;
                }
            }

            $cfg = array(
                'enabled'         => env_bool('SYNC_ENABLED', true),
                'legacy_auth'     => env_bool('SYNC_LEGACY_AUTH', true),
                'legacy_username' => (string) env_get('SYNC_LEGACY_USERNAME', ''),
                'legacy_password' => (string) env_get('SYNC_LEGACY_PASSWORD', ''),
                'require_https'   => env_bool('SYNC_REQUIRE_HTTPS', true),
                'allow_localhost' => env_bool('SYNC_ALLOW_LOCALHOST', false),
                'max_age'         => env_int('SYNC_MAX_AGE', 300),
                'max_payload'     => env_int('SYNC_MAX_PAYLOAD', 2097152),
                'max_statements'  => env_int('SYNC_MAX_STATEMENTS', 500),
                'allowed_ips'     => $ips,
                'clients'         => sync_clients(),
            );
        }

        return $cfg;
    }
}

// ---------------------------------------------------------------------------
// Firma
// ---------------------------------------------------------------------------

if (!function_exists('sync_canonical')) {
    /**
     * Construye la cadena canonica que se firma.
     *
     * @return string
     */
    function sync_canonical($username, $action, $timestamp, $nonce, $data)
    {
        return implode("\n", array(
            (string) $username,
            (string) $action,
            (string) $timestamp,
            (string) $nonce,
            hash('sha256', (string) $data),
        ));
    }
}

if (!function_exists('sync_sign')) {
    /**
     * Firma la cadena canonica con el secreto del cliente.
     *
     * @return string hex
     */
    function sync_sign($canonical, $secret)
    {
        return hash_hmac('sha256', $canonical, (string) $secret);
    }
}

// ---------------------------------------------------------------------------
// Proteccion anti-replay
// ---------------------------------------------------------------------------

if (!function_exists('sync_nonce_dir')) {
    function sync_nonce_dir()
    {
        return DIR_STORAGE . 'sync-nonces';
    }
}

if (!function_exists('sync_nonce_consume')) {
    /**
     * Registra un nonce. Devuelve false si ya se uso (replay) o si el
     * almacenamiento no esta disponible (fail closed).
     *
     * @return bool true si el nonce es nuevo.
     */
    function sync_nonce_consume($nonce, $max_age)
    {
        $nonce = preg_replace('/[^a-f0-9]/i', '', (string) $nonce);
        if ($nonce === '') {
            return false;
        }

        $dir = sync_nonce_dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            return false; // fail closed: sin cache no se puede garantizar anti-replay
        }

        // Limpieza de nonces vencidos.
        $files = glob($dir . '/*.nonce');
        if ($files) {
            $expired = time() - (max(60, (int) $max_age) * 3);
            foreach ($files as $file) {
                if (@filemtime($file) < $expired) {
                    @unlink($file);
                }
            }
        }

        // fopen con modo 'x' es una creacion exclusiva y atomica: si el
        // archivo ya existe, otro request con el mismo nonce llego antes.
        $handle = @fopen($dir . '/' . $nonce . '.nonce', 'x');
        if ($handle === false) {
            return false;
        }
        fwrite($handle, (string) time());
        fclose($handle);

        return true;
    }
}

// ---------------------------------------------------------------------------
// Verificacion (lado servidor)
// ---------------------------------------------------------------------------

if (!function_exists('sync_verify')) {
    /**
     * Verifica la peticion contra la configuracion vigente.
     *
     * @param array $post  Cuerpo ya decodificado.
     * @param array $cfg   Configuracion (sync_config()).
     * @return array array('ok' => bool, 'client' => string, 'reason' => string, 'mode' => string)
     */
    function sync_verify($post, $cfg)
    {
        $username = isset($post['username']) ? (string) $post['username'] : '';
        $action   = isset($post['action']) ? (string) $post['action'] : '';
        $data     = isset($post['data']) ? (string) $post['data'] : '';
        $ts       = isset($post['timestamp']) ? (string) $post['timestamp'] : '';
        $nonce    = isset($post['nonce']) ? (string) $post['nonce'] : '';
        $sig      = isset($post['signature']) ? (string) $post['signature'] : '';

        $has_signature = ($ts !== '' && $nonce !== '' && $sig !== '');

        // --- Ruta firmada (v2) ---
        if ($has_signature) {
            if (!isset($cfg['clients'][$username])) {
                return array('ok' => false, 'reason' => 'unknown_client');
            }

            if (!preg_match('/^\d{10}$/', $ts)) {
                return array('ok' => false, 'reason' => 'bad_timestamp');
            }

            $age = abs(time() - (int) $ts);
            if ($age > (int) $cfg['max_age']) {
                return array('ok' => false, 'reason' => 'expired_request');
            }

            $expected = sync_sign(
                sync_canonical($username, $action, $ts, $nonce, $data),
                $cfg['clients'][$username]
            );

            if (!hash_equals($expected, $sig)) {
                return array('ok' => false, 'reason' => 'bad_signature');
            }

            // El nonce se consume solo despues de validar la firma, para que
            // una peticion no autenticada no pueda agotar los nonces ajenos.
            if (!sync_nonce_consume($nonce, $cfg['max_age'])) {
                return array('ok' => false, 'reason' => 'replayed_request');
            }

            return array('ok' => true, 'client' => $username, 'mode' => 'signed');
        }

        // --- Ruta heredada (v1), solo durante la transicion ---
        if (!$cfg['legacy_auth']) {
            return array('ok' => false, 'reason' => 'signature_required');
        }

        if ($cfg['legacy_username'] === '' || $cfg['legacy_password'] === '') {
            return array('ok' => false, 'reason' => 'legacy_auth_not_configured');
        }

        $password = isset($post['password']) ? (string) $post['password'] : '';
        if (!hash_equals($cfg['legacy_username'], $username) || !hash_equals($cfg['legacy_password'], $password)) {
            return array('ok' => false, 'reason' => 'bad_credentials');
        }

        return array('ok' => true, 'client' => $username, 'mode' => 'legacy');
    }
}

// ---------------------------------------------------------------------------
// Validacion del SQL recibido
// ---------------------------------------------------------------------------

if (!function_exists('sync_sql_allowed')) {
    /**
     * Solo se admite DML. El cliente legitimo unicamente produce
     * INSERT, UPDATE y DELETE, porque eso es lo unico que Database::execute()
     * registra en storage/logs/sql.txt.
     *
     * @return bool
     */
    function sync_sql_allowed($sql)
    {
        $sql = trim((string) $sql);
        if ($sql === '') {
            return false;
        }

        // Rechaza sentencias apiladas ("...; DROP TABLE ...").
        $body = rtrim($sql, " \t\n\r");
        if (substr($body, -1) === ';') {
            $body = rtrim(substr($body, 0, -1), " \t\n\r");
        }
        if (strpos($body, ';') !== false) {
            return false;
        }

        // Ignora comentarios iniciales para detectar la palabra clave real.
        $clean = preg_replace('/^(?:\s*\/\*.*?\*\/|\s*--[^\n]*|\s*#[^\n]*)*/s', '', $sql);

        if (!preg_match('/^([A-Za-z]+)/', $clean, $match)) {
            return false;
        }

        return in_array(strtoupper($match[1]), array('INSERT', 'UPDATE', 'DELETE'), true);
    }
}

// ---------------------------------------------------------------------------
// Transporte (lado cliente)
// ---------------------------------------------------------------------------

if (!function_exists('sync_post')) {
    /**
     * Envia una peticion firmada al servidor de sincronizacion.
     *
     * @param string $url
     * @param array  $payload  Cuerpo sin firmar (username, action, data).
     * @param string $client_id
     * @param string $secret
     * @return object|null Respuesta decodificada, o null si no hubo respuesta.
     */
    function sync_post($url, $payload, $client_id, $secret)
    {
        // El id del cliente forma parte del canonico firmado, asi que debe
        // viajar SIEMPRE en el cuerpo. Si se omite, el servidor no puede
        // resolver el secreto y rechaza la peticion.
        $payload['username'] = $client_id;
        $payload['timestamp'] = time();
        $payload['nonce'] = bin2hex(random_bytes(16));
        $payload['signature'] = sync_sign(
            sync_canonical(
                $client_id,
                isset($payload['action']) ? $payload['action'] : '',
                $payload['timestamp'],
                $payload['nonce'],
                isset($payload['data']) ? $payload['data'] : ''
            ),
            $secret
        );

        $body = json_encode($payload);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_ENCODING, 'gzip');
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json; charset=UTF-8',
            'Content-Length: ' . strlen($body),
        ));

        $result = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($result === false || $result === '') {
            return null;
        }

        return json_decode($result);
    }
}

// ---------------------------------------------------------------------------
// Utilidades de respuesta
// ---------------------------------------------------------------------------

if (!function_exists('sync_respond')) {
    /**
     * Respuesta JSON uniforme. Mantiene las claves que espera el cliente
     * existente: status, message, for.
     */
    function sync_respond($status, $message, $for, $http_code = 200)
    {
        if (!headers_sent()) {
            http_response_code((int) $http_code);
            header('Content-Type: application/json; charset=UTF-8');
        }
        echo json_encode(array(
            'status'  => $status,
            'message' => $message,
            'for'     => $for,
        ));
        exit();
    }
}

if (!function_exists('sync_log')) {
    /**
     * Log de sincronizacion en storage/logs/sync.log.
     */
    function sync_log($message)
    {
        $dir = DIR_LOG;
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $line = date('Y-m-d H:i:s') . ' - ' . $message . "\n";
        @file_put_contents($dir . 'sync.log', $line, FILE_APPEND);
    }
}

if (!function_exists('sync_client_ip')) {
    /**
     * IP del cliente (soporta proxy inverso).
     */
    function sync_client_ip()
    {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($parts[0]);
        }
        return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    }
}

if (!function_exists('sync_is_https')) {
    /**
     * Detecta HTTPS, incluyendo detras de un proxy inverso.
     */
    function sync_is_https()
    {
        if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            return true;
        }
        if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
            return true;
        }
        return false;
    }
}

if (!function_exists('sync_is_localhost')) {
    function sync_is_localhost()
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        return ($ip === '::1' || strpos($ip, '127.') === 0);
    }
}
