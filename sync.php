<?php
/**
 * sync.php
 * ------------------------------------------------------------------
 * Servidor de sincronizacion offline -> online.
 *
 * Recibe las operaciones que los POS sucursales generan en
 * storage/logs/sql.txt y las aplica en la base de datos del servidor
 * maestro. Es el lado servidor del push que hace
 * ModelCron::PushSqlToRemoteServer().
 *
 * Autenticacion: dos rutas durante la transicion.
 *   v2 (firmada):  HMAC-SHA256 sobre un canonico, con timestamp y nonce.
 *   v1 (heredada): usuario y contrasena planos, solo si SYNC_LEGACY_AUTH.
 * La v1 se desactiva con SYNC_LEGACY_AUTH=false una vez que todos los POS
 * sucursales fueron actualizados.
 *
 * Ver _inc/helper/sync.php para el protocolo y la configuracion.
 */

require_once("config.php");
require_once("_inc/helper/common.php");
require_once("_inc/helper/file.php");
require_once("_inc/helper/network.php");
require_once("_inc/helper/sync.php");

$cfg = sync_config();

// ---------------------------------------------------------------------------
// 1. Interruptores globales
// ---------------------------------------------------------------------------

if (!$cfg['enabled']) {
    sync_respond('error', 'Synchronization is disabled on this server', 'invalid', 403);
}

if (sync_is_localhost() && !$cfg['allow_localhost']) {
    sync_respond('error', 'Invalid Action', 'invalid', 403);
}

if ($cfg['require_https'] && !sync_is_https()) {
    sync_log('rejected: request over plain HTTP from ' . sync_client_ip());
    sync_respond('error', 'HTTPS is required', 'invalid', 403);
}

if (!empty($cfg['allowed_ips'])) {
    $client_ip = sync_client_ip();
    if (!in_array($client_ip, $cfg['allowed_ips'], true)) {
        sync_log('rejected: IP not allowed: ' . $client_ip);
        sync_respond('error', 'Invalid Action', 'invalid', 403);
    }
}

// ---------------------------------------------------------------------------
// 2. Cuerpo de la peticion
// ---------------------------------------------------------------------------

$raw_body = file_get_contents('php://input');

if ($raw_body === false || trim($raw_body) === '') {
    sync_respond('error', 'Invalid Action', 'invalid', 400);
}

if (strlen($raw_body) > $cfg['max_payload']) {
    sync_log('rejected: payload too large: ' . strlen($raw_body));
    sync_respond('error', 'Payload too large', 'invalid', 413);
}

$post_data = json_decode($raw_body, true);

if (!is_array($post_data)) {
    sync_respond('error', 'Invalid Action', 'invalid', 400);
}

// ---------------------------------------------------------------------------
// 3. Autenticacion
// ---------------------------------------------------------------------------

$auth = sync_verify($post_data, $cfg);

if (!$auth['ok']) {
    sync_log('auth failed (' . $auth['reason'] . ') from ' . sync_client_ip());
    sync_respond('error', 'Invalid Action', 'invalid', 401);
}

// ---------------------------------------------------------------------------
// 4. Accion
// ---------------------------------------------------------------------------

$action = isset($post_data['action']) ? (string) $post_data['action'] : '';

if ($action !== 'sync') {
    sync_respond('error', 'Invalid Action', 'invalid', 400);
}

// ---------------------------------------------------------------------------
// 5. Carga de operaciones
// ---------------------------------------------------------------------------

$query_data = isset($post_data['data']) ? json_decode((string) $post_data['data'], true) : null;

if (!is_array($query_data) || count($query_data) === 0) {
    sync_respond('error', 'Nothing to sync', 'invalid', 400);
}

if (count($query_data) > $cfg['max_statements']) {
    sync_log('rejected: too many statements: ' . count($query_data));
    sync_respond('error', 'Too many statements', 'invalid', 413);
}

// Cada operacion debe ser una sentencia DML simple con sus argumentos.
foreach ($query_data as $operation) {
    if (!is_array($operation) || !isset($operation['sql'])) {
        sync_respond('error', 'Invalid Action', 'invalid', 400);
    }

    if (!sync_sql_allowed($operation['sql'])) {
        sync_log('rejected: statement not allowed: ' . substr((string) $operation['sql'], 0, 120));
        sync_respond('error', 'Invalid Action', 'invalid', 403);
    }

    if (isset($operation['args']) && !is_array($operation['args'])) {
        sync_respond('error', 'Invalid Action', 'invalid', 400);
    }
}

// ---------------------------------------------------------------------------
// 6. Aplicacion
// ---------------------------------------------------------------------------

try {
    $db = pdo_start();
} catch (PDOException $e) {
    // No se filtra el detalle del error al cliente: queda en el log del servidor.
    sync_log('database connection error: ' . $e->getMessage());
    sync_respond('error', 'Database Connection Error', 'invalid', 500);
}

try {
    // Todo el lote es atomico: si una sentencia falla, no queda nada a medias.
    $db->beginTransaction();

    foreach ($query_data as $operation) {
        $statement = $db->prepare($operation['sql']);
        $statement->execute(isset($operation['args']) ? $operation['args'] : array());
    }

    $db->commit();

    sync_log('sync ok: ' . count($query_data) . ' statement(s) from ' . $auth['client'] . ' [' . $auth['mode'] . ']');

    sync_respond('success', 'sync successfully done', 'sync');
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    sync_log('sync failed from ' . $auth['client'] . ': ' . $e->getMessage());

    sync_respond('error', 'Sync failed', 'sync', 500);
}
