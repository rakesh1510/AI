<?php
/**
 * AIHubpedia Authentication & Security Engine
 *
 * Implements:
 * - Secure Session Initialization with strict cookie flags (HttpOnly, SameSite, Secure)
 * - Bcrypt Password Verification & Hashing with timing-safe comparison
 * - Atomic Concurrency-Safe IP Rate Limiter with Trusted Proxy Verification
 * - Cryptographically Strict CSRF Token Generation & Validation (Fail-Closed)
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
                    'attempts' => (int)$ipData['attempts']
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
         * Record a failed login attempt with atomic file locking.
         */
        public static function recordFailedLogin(?string $ip = null): array {
            $ip = $ip ?: self::getClientIp();
            return self::mutateRateLimit(function(array &$data) use ($ip) {
                $now = time();
                $ipData = $data[$ip] ?? ['attempts' => 0, 'first_fail' => $now, 'lockout_until' => 0];

                if ($ipData['first_fail'] === 0 || ($now - $ipData['first_fail']) > self::LOCKOUT_SECONDS) {
                    $ipData['first_fail'] = $now;
                    $ipData['attempts'] = 0;
                }

                $ipData['attempts'] = ((int)$ipData['attempts']) + 1;

                if ($ipData['attempts'] >= self::MAX_LOGIN_ATTEMPTS) {
                    $ipData['lockout_until'] = $now + self::LOCKOUT_SECONDS;
                }

                $data[$ip] = $ipData;

                return [
                    'is_blocked' => ($ipData['attempts'] >= self::MAX_LOGIN_ATTEMPTS),
                    'attempts' => $ipData['attempts'],
                    'remaining_attempts' => max(0, self::MAX_LOGIN_ATTEMPTS - $ipData['attempts']),
                    'remaining_seconds' => max(0, ($ipData['lockout_until'] ?? 0) - $now)
                ];
            });
        }

        /**
         * Clear rate limit for client IP upon successful login.
         */
        public static function clearRateLimit(?string $ip = null): void {
            $ip = $ip ?: self::getClientIp();
            self::mutateRateLimit(function(array &$data) use ($ip) {
                if (isset($data[$ip])) {
                    unset($data[$ip]);
                }
                return true;
            });
        }

        /**
         * Authenticate admin with username and password.
         */
        public static function adminLogin(string $username, string $password): array {
            self::startSession();

            // 1. Check Rate Limiter first
            $rateCheck = self::checkRateLimit();
            if ($rateCheck['is_blocked']) {
                $mins = (int)ceil($rateCheck['remaining_seconds'] / 60);
                return [
                    'success' => false,
                    'error' => "Too many failed attempts. Login locked for {$mins} minute(s)."
                ];
            }

            // 2. Validate Username
            $expectedUser = self::getAdminUsername();
            $userMatches = hash_equals($expectedUser, $username);

            // 3. Validate Password Hash
            $hash = self::getAdminPasswordHash();
            $passwordValid = false;

            if ($hash !== '') {
                $passwordValid = password_verify($password, $hash);
            }

            if (!$userMatches || !$passwordValid) {
                $failResult = self::recordFailedLogin();
                $errMsg = "Invalid username or password.";
                if ($failResult['is_blocked']) {
                    $errMsg = "Account locked due to 5 consecutive failed attempts. Try again in 15 minutes.";
                } elseif ($failResult['remaining_attempts'] > 0) {
                    $errMsg .= " ({$failResult['remaining_attempts']} attempt(s) remaining before temporary lockout).";
                }
                return ['success' => false, 'error' => $errMsg];
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
         * Terminate admin session securely.
         */
        public static function adminLogout(): void {
            self::startSession();
            unset($_SESSION['admin_auth']);

            if (ini_get("session.use_cookies") && !headers_sent()) {
                $params = session_get_cookie_params();
                setcookie(
                    session_name(),
                    '',
                    time() - 42000,
                    $params["path"],
                    $params["domain"],
                    $params["secure"],
                    $params["httponly"]
                );
            }

            if (session_status() === PHP_SESSION_ACTIVE) {
                @session_destroy();
            }
        }

        /**
         * Generate or retrieve session CSRF token.
         * Fails strictly if cryptographically secure randomness is unavailable.
         */
        public static function csrfToken(): string {
            self::startSession();
            if (empty($_SESSION['csrf_token'])) {
                try {
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                } catch (\Throwable $e) {
                    throw new \RuntimeException("Cryptographically secure random number generator (random_bytes) is unavailable. Application halted for security.", 0, $e);
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
         * Get client IP address with verified reverse proxy support.
         */
        public static function getClientIp(): string {
            $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

            // Verify if REMOTE_ADDR is in configured trusted proxies
            $trustedProxiesStr = (string)Env::get('AIHUB_TRUSTED_PROXIES', '');
            $trustedProxies = array_filter(array_map('trim', explode(',', $trustedProxiesStr)));

            $isTrustedProxy = false;
            if (!empty($trustedProxies)) {
                foreach ($trustedProxies as $trusted) {
                    if ($trusted === '*' || $trusted === $remoteAddr || self::ipMatchesCidr($remoteAddr, $trusted)) {
                        $isTrustedProxy = true;
                        break;
                    }
                }
            }

            // Only inspect forwarded headers if connected through a verified trusted proxy
            if ($isTrustedProxy) {
                $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'];
                foreach ($headers as $header) {
                    if (!empty($_SERVER[$header])) {
                        $raw = trim((string)$_SERVER[$header]);
                        if ($header === 'HTTP_X_FORWARDED_FOR') {
                            $parts = array_map('trim', explode(',', $raw));
                            // Rightmost or leftmost valid IP
                            $candidate = $parts[0] ?? '';
                        } else {
                            $candidate = $raw;
                        }
                        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                            return substr($candidate, 0, 45);
                        }
                    }
                }
            }

            if (filter_var($remoteAddr, FILTER_VALIDATE_IP)) {
                return substr($remoteAddr, 0, 45);
            }

            return '127.0.0.1';
        }

        /**
         * Check if an IP matches a CIDR range.
         */
        private static function ipMatchesCidr(string $ip, string $cidr): bool {
            if (!str_contains($cidr, '/')) {
                return $ip === $cidr;
            }

            [$subnet, $mask] = explode('/', $cidr, 2);
            $mask = (int)$mask;

            $ipLong = ip2long($ip);
            $subnetLong = ip2long($subnet);

            if ($ipLong === false || $subnetLong === false || $mask < 0 || $mask > 32) {
                return false;
            }

            $bitmask = ~((1 << (32 - $mask)) - 1);
            return ($ipLong & $bitmask) === ($subnetLong & $bitmask);
        }

        /**
         * Resolve rate-limit storage path outside webroot.
         */
        public static function getRateLimitStoragePath(): string {
            $custom = (string)Env::get('AIHUB_RATE_LIMIT_PATH', '');
            if ($custom !== '') {
                return $custom;
            }

            // Default: outside public webroot in system temporary or storage directory
            $outsideDir = dirname(__DIR__, 2) . '/aihubpedia_data';
            if (is_dir($outsideDir) && is_writable($outsideDir)) {
                return $outsideDir . '/.rate_limits.json';
            }

            return sys_get_temp_dir() . '/.aihub_rate_limits.json';
        }

        /**
         * Mutate rate limit data with atomic file locking (flock LOCK_EX).
         */
        private static function mutateRateLimit(callable $callback): mixed {
            $file = self::getRateLimitStoragePath();
            $dir = dirname($file);
            if (!is_dir($dir)) {
                @mkdir($dir, 0750, true);
            }

            $fp = @fopen($file, 'c+');
            if (!$fp) {
                $data = [];
                return $callback($data);
            }

            flock($fp, LOCK_EX);
            rewind($fp);
            $content = stream_get_contents($fp);
            $data = json_decode((string)$content, true);
            if (!is_array($data)) {
                $data = [];
            }

            $result = $callback($data);

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($data, JSON_UNESCAPED_SLASHES));
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);

            return $result;
        }

        /**
         * Load rate limiting file storage (read-only with shared lock).
         */
        private static function loadRateLimitData(): array {
            $file = self::getRateLimitStoragePath();
            if (!file_exists($file)) {
                return [];
            }

            $fp = @fopen($file, 'r');
            if (!$fp) {
                return [];
            }

            flock($fp, LOCK_SH);
            $content = stream_get_contents($fp);
            flock($fp, LOCK_UN);
            fclose($fp);

            $data = json_decode((string)$content, true);
            return is_array($data) ? $data : [];
        }

        private static function saveRateLimitData(array $data): void {
            self::mutateRateLimit(function(array &$existing) use ($data) {
                $existing = $data;
                return true;
            });
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
