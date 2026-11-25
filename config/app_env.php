<?php
/**
 * Centralized environment variable loading for local development.
 * Real OS/host environment variables take precedence over .env values.
 */
if (!function_exists('app_load_env_file')) {
    function app_load_env_file(string $path): void
    {
        if (!is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));

            if ($key === '') {
                continue;
            }

            $len = strlen($value);
            if ($len >= 2) {
                $first = $value[0];
                $last = $value[$len - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            if (getenv($key) === false) {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }
}

if (!function_exists('app_env')) {
    function app_env(string $key, $default = null)
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
                return $_SERVER[$key];
            }
            if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
                return $_ENV[$key];
            }
            return $default;
        }

        return $value;
    }
}

app_load_env_file(__DIR__ . '/../.env');
