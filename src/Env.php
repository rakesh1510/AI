<?php
/**
 * AIHubpedia Environment Loader
 * 
 * Lightweight, zero-dependency .env parser and environment accessor.
 * Supports comments, quotes, inline values, and existing system environment variables.
 */

if (!class_exists('Env')) {
    class Env {
        private static bool $loaded = false;

        /**
         * Load environment variables from a .env file into getenv(), $_ENV, and $_SERVER.
         */
        public static function load(?string $path = null): bool {
            if (self::$loaded && $path === null) {
                return true;
            }

            if ($path === null) {
                $path = dirname(__DIR__) . '/.env';
            }

            if (!file_exists($path) || !is_readable($path)) {
                return false;
            }

            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                return false;
            }

            foreach ($lines as $line) {
                $line = trim($line);

                // Skip comments and empty lines
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }

                // Parse KEY=VALUE
                $pos = strpos($line, '=');
                if ($pos === false) {
                    continue;
                }

                $key = trim(substr($line, 0, $pos));
                $value = trim(substr($line, $pos + 1));

                // Strip surrounding single or double quotes
                if (strlen($value) >= 2) {
                    $first = $value[0];
                    $last = $value[strlen($value) - 1];
                    if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                        $value = substr($value, 1, -1);
                    }
                }

                // Set into environment if not already set by system or overwrite
                if ($key !== '') {
                    if (!isset($_SERVER[$key])) {
                        $_SERVER[$key] = $value;
                    }
                    if (!isset($_ENV[$key])) {
                        $_ENV[$key] = $value;
                    }
                    putenv("{$key}={$value}");
                }
            }

            self::$loaded = true;
            return true;
        }

        /**
         * Retrieve an environment variable with a default fallback.
         */
        public static function get(string $key, mixed $default = null): mixed {
            self::load();

            $val = getenv($key);
            if ($val !== false) {
                return self::normalizeValue($val);
            }

            if (isset($_ENV[$key])) {
                return self::normalizeValue($_ENV[$key]);
            }

            if (isset($_SERVER[$key])) {
                return self::normalizeValue($_SERVER[$key]);
            }

            return $default;
        }

        /**
         * Normalize boolean and integer strings.
         */
        private static function normalizeValue(mixed $val): mixed {
            if (!is_string($val)) {
                return $val;
            }

            $lower = strtolower($val);
            if ($lower === 'true' || $lower === '(true)') {
                return true;
            }
            if ($lower === 'false' || $lower === '(false)') {
                return false;
            }
            if ($lower === 'null' || $lower === '(null)') {
                return null;
            }

            return $val;
        }
    }
}

// Procedural helper
if (!function_exists('env_get')) {
    function env_get(string $key, mixed $default = null): mixed {
        return Env::get($key, $default);
    }
}

// Auto-load .env on file include
Env::load();
