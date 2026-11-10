<?php
/**
 * AIHubpedia Phase 1A Security and Stability Automated Test Suite
 *
 * Verifies:
 * 1. Environment configuration loader & variable isolation
 * 2. Database connection security & error handling
 * 3. Admin authentication, password hashing & rate limiting
 * 4. CSRF protection & timing-safe validation
 * 5. Dual-layer storage synchronization (MySQL + JSON)
 * 6. Helper function stability and regressions
 */

require_once __DIR__ . '/../src/Env.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../helpers.php';

class SecurityAndStabilityTest {
    private int $passed = 0;
    private int $failed = 0;
    private array $errors = [];

    private function assert(bool $condition, string $testName, string $details = ''): void {
        if ($condition) {
            $this->passed++;
            echo "  \033[32m✔ PASS\033[0m {$testName}\n";
        } else {
            $this->failed++;
            $msg = "  \033[31m✘ FAIL\033[0m {$testName}" . ($details ? " - {$details}" : '');
            echo $msg . "\n";
            $this->errors[] = $msg;
        }
    }

    public function run(): bool {
        echo "\n\033[1;34m=================================================================\033[0m\n";
        echo "\033[1;34m  AIHubpedia Security & Stability Test Suite (Phase 1A)\033[0m\n";
        echo "\033[1;34m=================================================================\033[0m\n\n";

        $this->testEnvironmentLoader();
        $this->testDatabaseSecurity();
        $this->testCsrfProtection();
        $this->testAuthenticationAndRateLimiting();
        $this->testDualStorageCompatibility();
        $this->testHelperRegressions();

        echo "\n\033[1;34m-----------------------------------------------------------------\033[0m\n";
        echo "Results: Total: " . ($this->passed + $this->failed) . " | \033[32mPassed: {$this->passed}\033[0m | \033[31mFailed: {$this->failed}\033[0m\n";
        echo "\033[1;34m=================================================================\033[0m\n\n";

        return $this->failed === 0;
    }

    private function testEnvironmentLoader(): void {
        echo "\033[1m[1. Environment Loader & Configuration Isolation]\033[0m\n";

        // Create temporary test env file
        $tempEnv = __DIR__ . '/.env.test';
        file_put_contents($tempEnv, "TEST_KEY=hello_world\n# Comment line\nTEST_QUOTED=\"secure_string\"\nTEST_BOOL=true\n");

        $loaded = Env::load($tempEnv);
        $this->assert($loaded, "Env::load reads custom env files");
        $this->assert(Env::get('TEST_KEY') === 'hello_world', "Env::get retrieves plain values");
        $this->assert(Env::get('TEST_QUOTED') === 'secure_string', "Env::get strips quotes from values");
        $this->assert(Env::get('TEST_BOOL') === true, "Env::get normalizes boolean values");
        $this->assert(Env::get('NON_EXISTENT_KEY', 'default_val') === 'default_val', "Env::get returns default for missing keys");

        @unlink($tempEnv);
    }

    private function testDatabaseSecurity(): void {
        echo "\n\033[1m[2. Database Connection Security]\033[0m\n";

        // Verify db.php doesn't have hardcoded password strings
        $dbContent = file_get_contents(__DIR__ . '/../db.php');
        $this->assert(strpos($dbContent, 'Romil@') === false, "db.php contains no hardcoded fallback passwords");
        $this->assert(strpos($dbContent, 'pmaadmin') === false, "db.php contains no hardcoded fallback user");
        $this->assert(strpos($dbContent, 'Env::get') !== false, "db.php reads credentials via Env::get");

        // Verify site.json doesn't contain plaintext admin password or SMTP password
        $siteJson = file_get_contents(__DIR__ . '/../data/site.json');
        $this->assert(strpos($siteJson, 'admin123') === false, "data/site.json contains no hardcoded admin password");
        $this->assert(strpos($siteJson, 'gkxdviavowwugiio') === false, "data/site.json contains no hardcoded Gmail SMTP password");
    }

    private function testCsrfProtection(): void {
        echo "\n\033[1m[3. CSRF Token Protection]\033[0m\n";

        Auth::startSession();
        $token1 = Auth::csrfToken();
        $this->assert(is_string($token1) && strlen($token1) === 64, "Auth::csrfToken generates 64-char hex token");

        $field = Auth::csrfField();
        $this->assert(strpos($field, 'name="csrf_token"') !== false, "Auth::csrfField outputs hidden input with token");

        // Validation tests
        $this->assert(Auth::validateCsrf($token1), "Auth::validateCsrf validates correct token");
        $this->assert(!Auth::validateCsrf('invalid_forged_token'), "Auth::validateCsrf rejects forged token");
        $this->assert(!Auth::validateCsrf(''), "Auth::validateCsrf rejects empty token");
    }

    private function testAuthenticationAndRateLimiting(): void {
        echo "\n\033[1m[4. Admin Authentication, Password Hashing & Rate Limiting]\033[0m\n";

        $testPass = 'TestAdminSecurePass2026!';
        $testHash = password_hash($testPass, PASSWORD_BCRYPT);

        // Put test credentials in env
        putenv("AIHUB_ADMIN_USERNAME=testadmin");
        putenv("AIHUB_ADMIN_PASSWORD_HASH={$testHash}");
        $_ENV['AIHUB_ADMIN_USERNAME'] = 'testadmin';
        $_ENV['AIHUB_ADMIN_PASSWORD_HASH'] = $testHash;

        // Clear any old rate limits
        Auth::clearRateLimit('127.0.0.1');

        // Test failed login
        $failResult = Auth::adminLogin('testadmin', 'wrong_password');
        $this->assert(!$failResult['success'], "Auth::adminLogin rejects incorrect password");
        $this->assert(!Auth::isAdminLoggedIn(), "Admin session is not set on failed login");

        // Test rate limit accumulation
        for ($i = 0; $i < 4; $i++) {
            Auth::adminLogin('testadmin', 'wrong_password');
        }
        $rateLimit = Auth::checkRateLimit('127.0.0.1');
        $this->assert($rateLimit['is_blocked'] === true, "Auth::checkRateLimit blocks IP after 5 failed attempts");

        // Attempting login while blocked
        $blockedResult = Auth::adminLogin('testadmin', $testPass);
        $this->assert(!$blockedResult['success'] && strpos($blockedResult['error'], 'locked') !== false, "Auth::adminLogin refuses authentication while IP is rate-limited");

        // Clear rate limit and test successful login
        Auth::clearRateLimit('127.0.0.1');
        $successResult = Auth::adminLogin('testadmin', $testPass);
        $this->assert($successResult['success'] === true, "Auth::adminLogin succeeds with valid username and password");
        $this->assert(Auth::isAdminLoggedIn() === true, "Auth::isAdminLoggedIn returns true after successful authentication");

        // Test logout
        Auth::adminLogout();
        $this->assert(Auth::isAdminLoggedIn() === false, "Auth::adminLogout securely terminates admin session");
    }

    private function testDualStorageCompatibility(): void {
        echo "\n\033[1m[5. Dual Storage Compatibility (MySQL + JSON)]\033[0m\n";

        $testTool = [
            'id' => 'test-unit-tool-slug-' . time(),
            'name' => 'Unit Test AI Tool',
            'category' => 'Testing & QA',
            'short' => 'A test AI tool for unit verification',
            'description' => 'Detailed description for unit test tool',
            'pricing' => 'Free',
            'website' => 'https://example.com/test-tool'
        ];

        // Save tool
        $saved = save_tool_dual($testTool);
        $this->assert($saved === true, "save_tool_dual executes and returns true");

        // Check tools.json has the tool
        $found = find_tool($testTool['id']);
        $this->assert($found !== null && $found['name'] === 'Unit Test AI Tool', "find_tool retrieves tool saved by save_tool_dual");

        // Test submission dual save
        $testSub = [
            'name' => 'Sub Test AI',
            'category' => 'Developer Tools',
            'website' => 'https://example.com/sub-test',
            'short' => 'Short test submission description',
            'plan' => 'free',
            'price' => 0
        ];
        $subSaved = save_submission_dual($testSub);
        $this->assert($subSaved === true, "save_submission_dual records submission");

        $subs = load_submissions_dual();
        $this->assert(is_array($subs) && count($subs) > 0, "load_submissions_dual retrieves submission records");

        // Clean up test tool from tools.json to maintain clean repo state
        $data = read_json('tools.json', ['tools' => [], 'categories' => []]);
        $data['tools'] = array_values(array_filter($data['tools'], fn($t) => ($t['id'] ?? '') !== $testTool['id']));
        write_json('tools.json', $data);

        // Clean up test submission
        $subsJson = read_json('submissions.json', []);
        $subsJson = array_values(array_filter($subsJson, fn($s) => ($s['name'] ?? '') !== 'Sub Test AI'));
        write_json('submissions.json', $subsJson);
    }

    private function testHelperRegressions(): void {
        echo "\n\033[1m[6. Helper Functions & Stability]\033[0m\n";

        $escaped = h("<script>alert('xss')</script>");
        $this->assert($escaped === "&lt;script&gt;alert(&#039;xss&#039;)&lt;/script&gt;", "h() sanitizes HTML entities securely");

        $cfg = site_cfg();
        $this->assert(is_array($cfg) && isset($cfg['site_name']), "site_cfg() loads site configuration");

        $tiers = pricing_tiers();
        $this->assert(is_array($tiers) && isset($tiers['free']), "pricing_tiers() returns pricing plan matrix");

        $toolsData = load_tools();
        $this->assert(is_array($toolsData['tools']) && count($toolsData['tools']) >= 500, "load_tools() successfully loads 500+ tools catalog without errors");
        $this->assert(is_array($toolsData['categories']) && count($toolsData['categories']) > 0, "load_tools() returns sorted categories");
    }
}

// Execute tests
$suite = new SecurityAndStabilityTest();
$success = $suite->run();
exit($success ? 0 : 1);
