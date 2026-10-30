<?php
/**
 * config/env.php
 * ------------------------------------------------------------------
 * Environment variable loader for POS Moderno.
 *
 * Reads the .env file from the project root and makes every variable
 * available through getenv(), $_ENV and $_SERVER.
 *
 * Supported format in .env:
 *   KEY=value
 *   KEY="value with spaces"
 *   KEY='literal value'
 *   # comment
 *
 * Variables already defined in the real system environment take
 * precedence (they are not overwritten), so values can differ per server
 * without touching the file.
 */

// Physical project root: this file lives in <root>/_inc/config/, so the
// path has to go up two levels (config -> _inc -> root).
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(dirname(__DIR__)));
}

if (!function_exists('env_load')) {
    /**
     * Loads the .env file once.
     *
     * @param string|null $path Path to the .env file. Defaults to the project root.
     * @return bool true if the file was read, false if it does not exist.
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
                // Trailing comment on an unquoted value: "value # note"
                $hash = strpos($value, ' #');
                if ($hash !== false) {
                    $value = rtrim(substr($value, 0, $hash));
                }
            }

            // The system environment wins
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
     * Gets an environment variable (from .env or the system).
     *
     * @param string $key     Variable name.
     * @param mixed  $default Returned if it does not exist or is empty.
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
     * Interprets an environment variable as a boolean.
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
     * Interprets an environment variable as an integer.
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

// Loaded automatically when this file is included
env_load();
