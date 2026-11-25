<?php
/**
 * ============================================================
 *  Xendit configuration (PRIVATE — server side only)
 * ============================================================
 *
 *  This file is NOT meant to be web-accessible. It is protected
 *  by config/.htaccess (Require all denied).
 *
 *  Secrets are read from, in order of priority:
 *    1. Real environment variables (set in Apache/OS/hosting panel)
 *    2. The project .env file (see .env.example) — for local dev only
 *
 *  NEVER hardcode live keys here and NEVER echo them to HTML,
 *  JavaScript, logs or API responses.
 *
 *  Required keys:
 *    XENDIT_SECRET_KEY      - Xendit API secret key (xnd_development_... for test)
 *    XENDIT_WEBHOOK_TOKEN   - Webhook verification token from Xendit dashboard
 *    XENDIT_BASE_URL        - Public base URL of this app, used for redirects
 *                             e.g. https://your-tunnel.example.com  (no trailing slash)
 *                             On plain localhost leave empty to auto-detect.
 *
 *  Optional:
 *    XENDIT_API_VERSION     - defaults to 2024-11-11
 *    XENDIT_CURRENCY        - defaults to PHP
 *    XENDIT_DEBUG           - "1" enables server error_log diagnostics
 */

if (!defined('XENDIT_CONFIG_LOADED')) {
    define('XENDIT_CONFIG_LOADED', true);

    // ---------------------------------------------------------
    // Minimal .env loader (no Composer dependency available)
    // ---------------------------------------------------------
    if (!function_exists('xendit_load_env_file')) {
        function xendit_load_env_file(string $path): void
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
                $val = trim(substr($line, $pos + 1));
                if ($key === '') {
                    continue;
                }
                // Strip surrounding quotes
                $len = strlen($val);
                if ($len >= 2) {
                    $first = $val[0];
                    $last = $val[$len - 1];
                    if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                        $val = substr($val, 1, -1);
                    }
                }
                // Real environment variables win over .env
                if (getenv($key) === false) {
                    putenv($key . '=' . $val);
                    $_ENV[$key] = $val;
                    $_SERVER[$key] = $val;
                }
            }
        }
    }

    // Only load .env for local development convenience.
    xendit_load_env_file(__DIR__ . '/../.env');

    if (!function_exists('xendit_env')) {
        function xendit_env(string $key, $default = null)
        {
            $val = getenv($key);
            if ($val === false || $val === '') {
                if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
                    return $_SERVER[$key];
                }
                if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
                    return $_ENV[$key];
                }
                return $default;
            }
            return $val;
        }
    }

    // ---------------------------------------------------------
    // Exposed accessors
    // ---------------------------------------------------------
    if (!function_exists('xendit_secret_key')) {
        function xendit_secret_key(): string
        {
            return (string)xendit_env('XENDIT_SECRET_KEY', '');
        }
    }

    if (!function_exists('xendit_webhook_token')) {
        function xendit_webhook_token(): string
        {
            return (string)xendit_env('XENDIT_WEBHOOK_TOKEN', '');
        }
    }

    if (!function_exists('xendit_api_version')) {
        function xendit_api_version(): string
        {
            return (string)xendit_env('XENDIT_API_VERSION', '2024-11-11');
        }
    }

    if (!function_exists('xendit_currency')) {
        function xendit_currency(): string
        {
            return strtoupper((string)xendit_env('XENDIT_CURRENCY', 'PHP'));
        }
    }

    if (!function_exists('xendit_debug')) {
        function xendit_debug(): bool
        {
            return xendit_env('XENDIT_DEBUG', '0') === '1';
        }
    }

    /**
     * Public base URL of this application (no trailing slash).
     * Falls back to auto-detection when XENDIT_BASE_URL is not set,
     * which is fine for localhost but will not receive webhooks.
     */
    if (!function_exists('xendit_base_url')) {
        function xendit_base_url(): string
        {
            $configured = trim((string)xendit_env('XENDIT_BASE_URL', ''));
            if ($configured !== '') {
                return rtrim($configured, '/');
            }

            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['SERVER_PORT'] ?? '') == 443);
            $scheme = $https ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

            // Directory of the app (works when the app is in a subfolder of htdocs)
            $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
            // Our payment endpoints live in the app root; if called from Admin/ go one up.
            if (substr($scriptDir, -6) === '/Admin') {
                $scriptDir = substr($scriptDir, 0, -6);
            }

            // Percent-encode each path segment (e.g. the space in "PBL updated")
            // so the generated return URLs are valid for Xendit and browsers.
            $encodedDir = implode('/', array_map('rawurlencode', explode('/', $scriptDir)));

            return $scheme . '://' . $host . rtrim($encodedDir, '/');
        }
    }

    /**
     * Base URL used to build the customer return URLs (success / cancel /
     * failure) that Xendit redirects the browser back to.
     *
     * Reads XENDIT_RETURN_URL_BASE from the environment/.env. When it is not
     * set it falls back to the app base (XENDIT_BASE_URL, or auto-detection),
     * which keeps local development working with no extra configuration.
     *
     * Value must be the directory that contains payments.php, with no
     * trailing slash. Path segments must be percent-encoded (use %20 for
     * spaces). Example:
     *     http://localhost/PBL%20updated/PBL
     */
    if (!function_exists('xendit_return_url_base')) {
        function xendit_return_url_base(): string
        {
            $configured = trim((string)xendit_env('XENDIT_RETURN_URL_BASE', ''));
            if ($configured !== '') {
                return rtrim($configured, '/');
            }
            return xendit_base_url();
        }
    }

    if (!function_exists('xendit_is_configured')) {
        function xendit_is_configured(): bool
        {
            return xendit_secret_key() !== '';
        }
    }

    if (!function_exists('xendit_webhook_configured')) {
        function xendit_webhook_configured(): bool
        {
            return xendit_webhook_token() !== '';
        }
    }
}
