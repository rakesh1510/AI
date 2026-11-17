# Manual Hostinger / server security checklist

**For the site owner. None of these steps were performed by the development work** (no access to Hostinger, DNS, SSL, SSH or the production database was requested or used). Do them on staging first, one at a time, and keep a note of the previous value so each change can be reversed.

## 1. Rotate everything that was ever committed
- [ ] Database password (the old fallback is in git history) – change it in the hosting panel, then store the new one only as `AIHUB_DB_USER`/`AIHUB_DB_PASS` environment variables or in an untracked `config.local.php` (see below).
- [ ] Gmail/SMTP app password that was in `data/site.json` – revoke it at the mail provider, create a new one, store as `AIHUB_SMTP_USER`/`AIHUB_SMTP_PASS`.
- [ ] Old admin password (`admin123` / the one in `site.json`) – treat as compromised. Create a new one and set `AIHUB_ADMIN_PASS_HASH` (generate with `php -r 'echo password_hash(trim(fgets(STDIN)), PASSWORD_DEFAULT), "\n";'`).
- [ ] Decide whether to purge git history (required if the repository is or will be public).

## 2. Configuration the new code expects
Set as environment variables, or in `config.local.php` next to `helpers.php` (untracked, returns an array; Apache must not serve it – it is blocked in `.htaccess`):
```php
<?php return [
  'db' => ['host' => 'localhost', 'name' => '…', 'user' => '…', 'pass' => '…'],
  'form_secret' => '<64+ random hex chars>',
  'analytics_salt' => '<random>',
  'admin_pass_hash' => '<password_hash output>',
  'trusted_proxies' => '<comma separated proxy IPs/CIDRs>',
  'proxy_header' => 'X-Forwarded-For',        // or CF-Connecting-IP if behind Cloudflare
  'mail' => ['smtp_host' => '…', 'smtp_user' => '…', 'smtp_pass' => '…', 'from_email' => '…', 'to_email' => '…'],
];
```
- [ ] **Deploy order matters:** set the database credentials *before* deploying this code, otherwise the site silently falls back to the JSON catalogue (no error, but no votes, leads or newsletter).
- [ ] `AIHUB_TRUSTED_PROXIES`: list only the addresses of proxies you control (for Cloudflare use the ranges published at cloudflare.com/ips, kept up to date). If you leave it empty the site ignores forwarded headers, which is safe but means all visitors behind a CDN look like one address and share rate limits.
- [ ] `AIHUB_FORM_SECRET`: set explicitly so tokens survive deployments and multiple servers.
- [ ] `AIHUB_RATE_DIR`: a writable directory outside the web root (default is the system temp dir).
- [ ] `AIHUB_SECURITY_LOG`: a file outside the web root (default: PHP error log). Review it weekly.

## 3. Web server
- [ ] Confirm `.htaccess` is honoured (`AllowOverride All`) or port the rules to nginx/LiteSpeed config: deny `/data`, `/database`, `/scripts`, `/tests`, `/vendor`, `config.local.php`, `*.sql`, `*.log`, `*.bak`, the install/import/compare maintenance scripts, and the library files listed in `.htaccess`.
- [ ] Better: move `data/` and `database/` outside the document root.
- [ ] Delete install/import scripts from the production document root after use.
- [ ] Verify from outside: `curl -I https://staging…/data/site.json`, `/install-database.php`, `/config.local.php`, `/security.php`, `/.git/config` must all return 404/403.
- [ ] File permissions: PHP files not writable by the web user; only the specific data/rate/log directories writable; `data/.form_secret` mode 0600.
- [ ] Disable directory listing and `display_errors` in production PHP settings (`display_errors=0`, `log_errors=1`).
- [ ] PHP version: use a currently supported release.

## 4. TLS, DNS and headers (hosting panel)
- [ ] Force HTTPS and enable a valid certificate with auto-renew.
- [ ] After confirming everything works on HTTPS, enable HSTS (start with a short max-age; do **not** add `preload` until sure).
- [ ] Review DNS: only expected A/AAAA/MX/TXT records; add SPF, DKIM and DMARC for the mail domain before sending any mail.
- [ ] Look for any redirect rule that forwards to external domains (the brief mentions `/signup-redirect`; no such route exists in the code, so check hosting-panel redirects and `.htaccess` on the server for anything similar and remove or restrict it).
- [ ] Consider a Content-Security-Policy in report-only mode first (inline styles/scripts are still used, so an enforcing policy needs code changes).

## 5. Database
- [ ] Use a dedicated least-privilege MySQL user for the site (SELECT/INSERT/UPDATE/DELETE on the site schema only; no DDL in production).
- [ ] Apply `database/phase3_business.sql` and the Phase 4 SQL (when released) from a migration session, never from the web.
- [ ] Take a backup before each migration and test a restore.

## 6. Accounts and monitoring
- [ ] Enable 2FA on the hosting account, domain registrar, GitHub and mail accounts.
- [ ] Restrict SSH to keys; disable password login; keep the VPS patched.
- [ ] Set up uptime monitoring and a log review routine (web server error log + `AIHUB_SECURITY_LOG`).
- [ ] Legal: privacy policy and Impressum must describe leads, newsletter, optional analytics and the operating company before any form is promoted.

## 7. Verification after each change
Run the staged smoke test: home, a tool page, search, compare, `/business.php` (submit a test lead to a mailbox you own), newsletter double opt-in, admin login, and `php tests/security_http_test.php <staging-url>` from a trusted machine (it creates test comments/ratings, so use a staging copy only).
