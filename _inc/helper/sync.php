<?php
/**
 * helper/sync.php
 * ------------------------------------------------------------------
 * Authentication and transport for the offline -> online sync.
 *
 * The server endpoint is sync.php; the client is
 * ModelCron::PushSqlToRemoteServer() en _inc/model/cron.php.
 *
 * BOTH SIDES USE THIS FILE, so the signature is computed and
 * verified with exactly the same code.
 *
 * Protocolo v2 (firmado):
 *   POST sync.php
 *   {
 *     "username":  "pos-01",          // client id
 *     "action":    "sync",
 *     "data":      "<json with [{sql, args}]>",
 *     "timestamp": 1730000000,
 *     "nonce":     "<hex aleatorio>",
 *     "signature": "<hex hmac-sha256>"
 *   }
 *
 *   canonical  = username \n action \n timestamp \n nonce \n sha256(data)
 *   signature  = hash_hmac('sha256', canonical, secret)
 *
 * Protocol v1 (legacy, only during the transition):
 *   { "username": "...", "password": "...", "action": "sync", "data": "..." }
 *
 * v1 is kept only while SYNC_LEGACY_AUTH=true, so that POS
 * terminals that have not been updated yet are not left out. In production
 * it must be disabled.
 */

// ---------------------------------------------------------------------------
// Configuracion
// ---------------------------------------------------------------------------

if (!function_exists('sync_clients')) {
    /**
     * Map of authorized clients: id => secret.
     * Configured with SYNC_CLIENTS="pos-01:secret1,pos-02:secret2".
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

if (!function_exists('sync_legacy_previous')) {
    /**
     * Legacy credentials still accepted during the migration
     * window, in "user:secret" format separated by commas.
     *
     * This is what allows rotating the master credential without cutting off
     * POS terminals that have not been updated yet. Once the whole fleet has
     * migrated, this variable is emptied and SYNC_LEGACY_AUTH is set to false.
     *
     * @return array user => secret
     */
    function sync_legacy_previous()
    {
        $pairs = array();

        foreach (explode(',', (string) env_get('SYNC_LEGACY_PREVIOUS', '')) as $pair) {
            $pair = trim($pair);
            if ($pair === '' || strpos($pair, ':') === false) {
                continue;
            }
            list($user, $secret) = explode(':', $pair, 2);
            $user = trim($user);
            $secret = trim($secret);
            if ($user !== '' && $secret !== '') {
                $pairs[$user] = $secret;
            }
        }

        return $pairs;
    }
}

if (!function_exists('sync_config')) {
    /**
     * Effective sync configuration, read from the .env.
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
                'legacy_previous' => sync_legacy_previous(),
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
     * Builds the canonical string that gets signed.
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
     * Signs the canonical string with the client secret.
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
     * Registers a nonce. Returns false if it was already used (replay) or if
     * storage is unavailable (fail closed).
     *
     * @return bool true if the nonce is new.
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

        // Prune expired nonces.
        $files = glob($dir . '/*.nonce');
        if ($files) {
            $expired = time() - (max(60, (int) $max_age) * 3);
            foreach ($files as $file) {
                if (@filemtime($file) < $expired) {
                    @unlink($file);
                }
            }
        }

        // fopen with mode 'x' is an exclusive, atomic create: if the
        // file already exists, another request with the same nonce got here first.
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
// Verification (server side)
// ---------------------------------------------------------------------------

if (!function_exists('sync_verify')) {
    /**
     * Verifies the request against the current configuration.
     *
     * @param array $post  Already decoded body.
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

            // The nonce is consumed only after the signature is validated, so that
            // an unauthenticated request cannot exhaust other clients' nonces.
            if (!sync_nonce_consume($nonce, $cfg['max_age'])) {
                return array('ok' => false, 'reason' => 'replayed_request');
            }

            return array('ok' => true, 'client' => $username, 'mode' => 'signed');
        }

        // --- Legacy route (v1), only during the transition ---
        if (!$cfg['legacy_auth']) {
            return array('ok' => false, 'reason' => 'signature_required');
        }

        if ($cfg['legacy_username'] === '' || $cfg['legacy_password'] === '') {
            return array('ok' => false, 'reason' => 'legacy_auth_not_configured');
        }

        $password = isset($post['password']) ? (string) $post['password'] : '';

        // Credencial vigente.
        if (hash_equals($cfg['legacy_username'], $username)
            && hash_equals($cfg['legacy_password'], $password)) {
            return array('ok' => true, 'client' => $username, 'mode' => 'legacy');
        }

        // Previous credentials: accepted only during the migration
        // window, configured in SYNC_LEGACY_PREVIOUS.
        if (isset($cfg['legacy_previous'][$username])
            && hash_equals($cfg['legacy_previous'][$username], $password)) {
            return array('ok' => true, 'client' => $username, 'mode' => 'legacy-previous');
        }

        return array('ok' => false, 'reason' => 'bad_credentials');
    }
}

// ---------------------------------------------------------------------------
// Validation of the received SQL
// ---------------------------------------------------------------------------

if (!function_exists('sync_sql_allowed')) {
    /**
     * Only DML is allowed. The legitimate client only produces
     * INSERT, UPDATE and DELETE, because that is all Database::execute()
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

        // Ignore leading comments to detect the real keyword.
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
     * Sends a signed request to the sync server.
     *
     * @param string $url
     * @param array  $payload  Unsigned body (username, action, data).
     * @param string $client_id
     * @param string $secret
     * @return object|null Decoded response, or null if there was none.
     */
    function sync_post($url, $payload, $client_id, $secret)
    {
        // The client id is part of the signed canonical string, so it must
        // ALWAYS travel in the body. If it is omitted the server cannot
        // resolve the secret and rejects the request.
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
// Response helpers
// ---------------------------------------------------------------------------

if (!function_exists('sync_respond')) {
    /**
     * Uniform JSON response. Keeps the keys the existing client expects
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
     * Sync log at storage/logs/sync.log.
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
     * Client IP (supports reverse proxies).
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
     * Detects HTTPS, including behind a reverse proxy.
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
