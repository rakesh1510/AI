<?php
/**
 * AIHubpedia Authentication & Security Engine
 *
 * Implements:
 * - Secure Session Initialization with proper cookie parameters
 * - Bcrypt Password Verification & Hashing
 * - IP-based Sliding-Window Login Rate Limiting
 * - Cryptographically Secure CSRF Token Generation & Validation
 * - Timing-safe string comparisons
 */

require_once __DIR__ . '/Env.php';

if (!class_exists('Auth')) {
    class Auth {
        private const MAX_LOGIN_ATTEMPTS = 5;
        private const LOCKOUT_SECONDS = 900; // 15 minutes
        private const SESSION_MAX_IDLE = 7200; // 2 hours
        private const SESSION_MAX_LIFETIME = 28800; // 8 hours

        /**
         * Initialize secure session if not already started.
         */
        public static function startSession(): void {
            if (session_status() === PHP_SESSION_NONE) {
                if (!headers_sent()) {
                    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                        || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

                    session_set_cookie_params([
                        'lifetime' => 0,
                        'path' => '/',
                        'domain' => '',
                        'secure' => $isSecure,
                        'httponly' => true,
                        'samesite' => 'Lax'
                    ]);
                }

                @session_start();
            }

            // Check session expiry if admin is logged in
            if (!empty($_SESSION['admin_auth']['user'])) {
                $now = time();
                $loginTime = $_SESSION['admin_auth']['logged_in_at'] ?? $now;
                $lastActivity = $_SESSION['admin_auth']['last_activity'] ?? $now;

                if (($now - $loginTime) > self::SESSION_MAX_LIFETIME || ($now - $lastActivity) > self::SESSION_MAX_IDLE) {
                    self::adminLogout();
                } else {
                    $_SESSION['admin_auth']['last_activity'] = $now;
                }
            }
        }

        /**
         * Return the configured admin username.
         */
        public static function getAdminUsername(): string {
            return (string)Env::get('AIHUB_ADMIN_USERNAME', 'admin');
        }

        /**
         * Return the configured admin password hash.
         */
        public static function getAdminPasswordHash(): string {
            return (string)Env::get('AIHUB_ADMIN_PASSWORD_HASH', '');
        }

        /**
         * Check if current client IP is rate limited for admin login.
         * Returns array [is_blocked => bool, remaining_seconds => int, attempts => int]
         */
        public static function checkRateLimit(?string $ip = null): array {
            $ip = $ip ?: self::getClientIp();
            $data = self::loadRateLimitData();
            $ipData = $data[$ip] ?? ['attempts' => 0, 'first_fail' => 0, 'lockout_until' => 0];

            $now = time();
            if ($ipData['lockout_until'] > $now) {
                return [
                    'is_blocked' => true,
                    'remaining_seconds' => $ipData['lockout_until'] - $now,
                    'attempts' => $ipData['attempts']
                ];
            }

            // Reset if past lockout window
            if ($ipData['first_fail'] > 0 && ($now - $ipData['first_fail']) > self::LOCKOUT_SECONDS) {
                unset($data[$ip]);
                self::saveRateLimitData($data);
                return ['is_blocked' => false, 'remaining_seconds' => 0, 'attempts' => 0];
            }

            return [
                'is_blocked' => false,
                'remaining_seconds' => 0,
                'attempts' => (int)($ipData['attempts'] ?? 0)
            ];
        }

        /**
         * Record a failed login attempt for the client IP.
         */
        public static function recordFailedLogin(?string $ip = null): void {
            $ip = $ip ?: self::getClientIp();
            $data = self::loadRateLimitData();
            $now = time();

            if (!isset($data[$ip]) || ($now - ($data[$ip]['first_fail'] ?? 0)) > self::LOCKOUT_SECONDS) {
                $data[$ip] = [
                    'attempts' => 1,
                    'first_fail' => $now,
                    'lockout_until' => 0
                ];
            } else {
                $data[$ip]['attempts'] = ($data[$ip]['attempts'] ?? 0) + 1;
                if ($data[$ip]['attempts'] >= self::MAX_LOGIN_ATTEMPTS) {
                    $data[$ip]['lockout_until'] = $now + self::LOCKOUT_SECONDS;
                }
            }

            self::saveRateLimitData($data);
        }

        /**
         * Clear failed login attempts after successful authentication.
         */
        public static function clearRateLimit(?string $ip = null): void {
            $ip = $ip ?: self::getClientIp();
            $data = self::loadRateLimitData();
            if (isset($data[$ip])) {
                unset($data[$ip]);
                self::saveRateLimitData($data);
            }
        }

        /**
         * Authenticate admin credentials.
         */
        public static function adminLogin(string $username, string $password): array {
            self::startSession();

            $rateLimit = self::checkRateLimit();
            if ($rateLimit['is_blocked']) {
                $minutes = ceil($rateLimit['remaining_seconds'] / 60);
                return [
                    'success' => false,
                    'error' => "Too many failed login attempts. Account locked for {$minutes} minute(s)."
                ];
            }

            $expectedUser = self::getAdminUsername();
            $expectedHash = self::getAdminPasswordHash();

            // Check if password hash is configured
            if ($expectedHash === '') {
                return [
                    'success' => false,
                    'error' => 'Admin password hash not configured in environment (AIHUB_ADMIN_PASSWORD_HASH).'
                ];
            }

            // Verify username with timing-safe comparison
            $userMatches = hash_equals($expectedUser, $username);
            $passMatches = password_verify($password, $expectedHash);

            if (!$userMatches || !$passMatches) {
                self::recordFailedLogin();
                $remaining = max(0, self::MAX_LOGIN_ATTEMPTS - ($rateLimit['attempts'] + 1));
                $errMsg = 'Invalid username or password.';
                if ($remaining > 0) {
                    $errMsg .= " ({$remaining} attempt(s) remaining before lockout)";
                }
                return [
                    'success' => false,
                    'error' => $errMsg
                ];
            }

            // Success: clear rate limiting and regenerate session ID
            self::clearRateLimit();
            if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
                @session_regenerate_id(true);
            }

            $_SESSION['admin_auth'] = [
                'user' => $username,
                'logged_in_at' => time(),
                'last_activity' => time(),
                'ip' => self::getClientIp()
            ];

            return ['success' => true, 'error' => null];
        }

        /**
         * Check if admin is currently authenticated.
         */
        public static function isAdminLoggedIn(): bool {
            self::startSession();
            return !empty($_SESSION['admin_auth']['user']);
        }

        /**
         * Log out the admin user.
         */
        public static function adminLogout(): void {
            self::startSession();
            unset($_SESSION['admin_auth']);
            if (!headers_sent() && ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params["path"], $params["domain"],
                    $params["secure"], $params["httponly"]
                );
            }
            @session_destroy();
        }

        /**
         * Generate or retrieve CSRF token.
         */
        public static function csrfToken(): string {
            self::startSession();
            if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
                try {
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                } catch (\Throwable $e) {
                    $_SESSION['csrf_token'] = md5(uniqid((string)mt_rand(), true) . microtime());
                }
            }
            return $_SESSION['csrf_token'];
        }

        /**
         * Output hidden HTML input field with CSRF token.
         */
        public static function csrfField(): string {
            $token = htmlspecialchars(self::csrfToken(), ENT_QUOTES, 'UTF-8');
            return '<input type="hidden" name="csrf_token" value="' . $token . '">';
        }

        /**
         * Validate incoming CSRF token from POST or headers.
         */
        public static function validateCsrf(?string $token = null): bool {
            self::startSession();
            $expected = $_SESSION['csrf_token'] ?? '';
            if ($expected === '') {
                return false;
            }

            if ($token === null) {
                $token = $_POST['csrf_token'] ?? $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
            }

            if (!is_string($token) || $token === '') {
                return false;
            }

            return hash_equals($expected, $token);
        }

        /**
         * Get client IP address.
         */
        public static function getClientIp(): string {
            $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
            foreach ($headers as $header) {
                if (!empty($_SERVER[$header])) {
                    $ip = trim((string)$_SERVER[$header]);
                    if ($header === 'HTTP_X_FORWARDED_FOR') {
                        $parts = explode(',', $ip);
                        $ip = trim($parts[0]);
                    }
                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        return substr($ip, 0, 45);
                    }
                }
            }
            return '127.0.0.1';
        }

        /**
         * Load rate limiting file storage.
         */
        private static function loadRateLimitData(): array {
            $file = dirname(__DIR__) . '/data/.rate_limits.json';
            if (!file_exists($file)) {
                return [];
            }
            $raw = @file_get_contents($file);
            $data = json_decode((string)$raw, true);
            return is_array($data) ? $data : [];
        }

        /**
         * Save rate limiting file storage.
         */
        private static function saveRateLimitData(array $data): void {
            $file = dirname(__DIR__) . '/data/.rate_limits.json';
            @file_put_contents($file, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        }
    }
}

// Global procedural helper functions
if (!function_exists('admin_is_authenticated')) {
    function admin_is_authenticated(): bool {
        return Auth::isAdminLoggedIn();
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string {
        return Auth::csrfToken();
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string {
        return Auth::csrfField();
    }
}

if (!function_exists('csrf_validate')) {
    function csrf_validate(?string $token = null): bool {
        return Auth::validateCsrf($token);
    }
}
