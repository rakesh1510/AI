<?php
/**
 * AIHubpedia Environment Loader
 *
 * Lightweight, zero-dependency .env parser and environment accessor.
 * Supports:
 * - Explicit configuration path outside public webroot (AIHUB_ENV_PATH)
 * - Strict preservation of system environment variables
 * - Comments, quotes, inline values, and boolean/null normalization
 * - Test isolation reset capabilities
 */

if (!class_exists('Env')) {
    class Env {
        private static bool $loaded = false;
        private static ?string $loadedPath = null;

        /**
         * Reset loader state (primarily for automated testing isolation).
         */
        public static function reset(): void {
            self::$loaded = false;
            self::$loadedPath = null;
        }

        /**
         * Resolve the effective .env file path.
         */
        public static function resolvePath(?string $customPath = null): ?string {
            if ($customPath !== null && file_exists($customPath) && is_readable($customPath)) {
                return $customPath;
            }

            // 1. Check explicit environment override
            $envPath = getenv('AIHUB_ENV_PATH') ?: ($_SERVER['AIHUB_ENV_PATH'] ?? null);
            if ($envPath && file_exists($envPath) && is_readable($envPath)) {
                return $envPath;
            }

            // 2. Check standard outside-webroot candidate (/var/www/aihubpedia_secrets/.env or parent dir)
            $parentEnv = dirname(__DIR__, 2) . '/aihubpedia_secrets/.env';
            if (file_exists($parentEnv) && is_readable($parentEnv)) {
                return $parentEnv;
            }

            // 3. Check project root .env
            $rootEnv = dirname(__DIR__) . '/.env';
            if (file_exists($rootEnv) && is_readable($rootEnv)) {
                return $rootEnv;
            }

            return null;
        }

        /**
         * Load environment variables from a .env file into getenv(), $_ENV, and $_SERVER.
         * System environment variables take precedence unless $overwrite is true.
         */
        public static function load(?string $path = null, bool $overwrite = false): bool {
            if (self::$loaded && $path === null && !$overwrite) {
                return true;
            }

            $resolved = self::resolvePath($path);
            if ($resolved === null) {
                return false;
            }

            $lines = file($resolved, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
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

                if ($key === '') {
                    continue;
                }

                // Strip surrounding single or double quotes
                if (strlen($value) >= 2) {
                    $first = $value[0];
                    $last = $value[strlen($value) - 1];
                    if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                        $value = substr($value, 1, -1);
                    }
                }

                // Preserve system environment precedence unless overwrite is requested
                $systemVal = getenv($key);
                $isSystemSet = ($systemVal !== false);

                if (!$isSystemSet || $overwrite) {
                    putenv("{$key}={$value}");
                    $_ENV[$key] = $value;
                    $_SERVER[$key] = $value;
                } elseif (!isset($_ENV[$key])) {
                    $_ENV[$key] = $systemVal;
                }
            }

            self::$loaded = true;
            self::$loadedPath = $resolved;
            return true;
        }

        /**
         * Retrieve an environment variable with a default fallback.
         */
        public static function get(string $key, mixed $default = null): mixed {
            if (!self::$loaded) {
                self::load();
            }

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
         * Normalize boolean and null string representations.
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

        public static function getLoadedPath(): ?string {
            return self::$loadedPath;
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
