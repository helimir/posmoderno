<?php
/**
 * config/env.php
 * ------------------------------------------------------------------
 * Cargador de variables de entorno para POS Moderno.
 *
 * Lee el archivo .env de la raiz del proyecto y deja cada variable
 * disponible mediante getenv(), $_ENV y $_SERVER.
 *
 * Reglas de formato soportadas en .env:
 *   CLAVE=valor
 *   CLAVE="valor con espacios"
 *   CLAVE='valor literal'
 *   # comentario
 *
 * Las variables ya definidas en el entorno real del sistema tienen
 * prioridad (no se sobreescriben), lo que permite definir valores
 * distintos en el servidor sin tocar el archivo.
 */

// Raiz fisica del proyecto: este archivo vive en <raiz>/_inc/config/,
// por lo que hay que subir dos niveles (config -> _inc -> raiz).
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(dirname(__DIR__)));
}

if (!function_exists('env_load')) {
    /**
     * Carga el archivo .env una sola vez.
     *
     * @param string|null $path Ruta al archivo .env. Por defecto, la raiz del proyecto.
     * @return bool true si el archivo se leyo, false si no existe.
     */
    function env_load($path = null)
    {
        static $loaded = false;

        if ($loaded) {
            return true;
        }
        $loaded = true; // evita reintentos dentro de la misma peticion

        if ($path === null) {
            $path = APP_ROOT . DIRECTORY_SEPARATOR . '.env';
        }

        if (!is_file($path) || !is_readable($path)) {
            return false;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return false;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            // Ignorar vacias y comentarios
            if ($line === '' || $line[0] === '#' || $line[0] === ';') {
                continue;
            }

            $separator = strpos($line, '=');
            if ($separator === false) {
                continue;
            }

            $key   = trim(substr($line, 0, $separator));
            $value = trim(substr($line, $separator + 1));

            if ($key === '') {
                continue;
            }

            // Quitar comillas envolventes
            $first = substr($value, 0, 1);
            if (($first === '"' || $first === "'") && substr($value, -1) === $first) {
                $value = substr($value, 1, -1);
            } else {
                // Comentario al final de la linea sin comillas: "valor # nota"
                $hash = strpos($value, ' #');
                if ($hash !== false) {
                    $value = rtrim(substr($value, 0, $hash));
                }
            }

            // El entorno del sistema manda
            if (getenv($key) === false) {
                putenv($key . '=' . $value);
                $_ENV[$key]    = $value;
                $_SERVER[$key] = $value;
            }
        }

        return true;
    }
}

if (!function_exists('env_get')) {
    /**
     * Obtiene una variable de entorno (de .env o del sistema).
     *
     * @param string $key     Nombre de la variable.
     * @param mixed  $default Valor devuelto si no existe o esta vacia.
     * @return mixed
     */
    function env_get($key, $default = null)
    {
        $value = getenv($key);

        if ($value === false) {
            if (array_key_exists($key, $_ENV)) {
                $value = $_ENV[$key];
            } elseif (array_key_exists($key, $_SERVER)) {
                $value = $_SERVER[$key];
            } else {
                $value = null;
            }
        }

        if ($value === null || $value === '') {
            return $default;
        }

        return $value;
    }
}

if (!function_exists('env_bool')) {
    /**
     * Interpreta una variable de entorno como booleano.
     *
     * @param string $key
     * @param bool   $default
     * @return bool
     */
    function env_bool($key, $default = false)
    {
        $value = env_get($key);

        if ($value === null) {
            return $default;
        }

        return in_array(
            strtolower(trim((string) $value)),
            array('1', 'true', 'yes', 'on', 'si', 'sí'),
            true
        );
    }
}

if (!function_exists('env_int')) {
    /**
     * Interpreta una variable de entorno como entero.
     *
     * @param string $key
     * @param int    $default
     * @return int
     */
    function env_int($key, $default = 0)
    {
        $value = env_get($key);

        if ($value === null || !is_numeric($value)) {
            return $default;
        }

        return (int) $value;
    }
}

// Carga automatica al incluir este archivo
env_load();
