# AIHubpedia Production Deployment & Security Operations Guide

This guide details the deployment, environment configuration, database backup, verification, and rollback procedures for **Phase 1A: Security & Stability Foundation**.

---

## 1. Pre-Deployment Environment Verification Checklist

Before deploying this release to production, ensure the server environment satisfies the following requirements:

- [ ] **PHP Runtime:** PHP 8.1+ (Tested on PHP 8.2+).
- [ ] **Required PHP Extensions:** `pdo_mysql`, `openssl`, `mbstring`, `json`, `session`, `ctype`, `filter`.
- [ ] **File Permissions:**
  - Webroot (`/var/www/html/AI`): Readable by web server (`www-data`).
  - Data directory (`/var/www/html/AI/data`): Writable by web server (`chmod 775` or `chmod 770`).
  - Secrets directory (`/var/www/aihubpedia_secrets`): Restrictive permissions (`chmod 700`, `.env` file `chmod 600`).
- [ ] **Web Server Protection:**
  - Ensure `.env` and `.git` files cannot be downloaded via HTTP/HTTPS.

---

## 2. Production Database Pre-Deployment Backup

Always create a full database snapshot before deploying application or schema changes:

```bash
# 1. Create a timestamped backup directory
mkdir -p /var/backups/aihubpedia

# 2. Export full MySQL database snapshot
mysqldump -u root -p --single-transaction --quick --routines --triggers aihub > /var/backups/aihubpedia/aihub_pre_deploy_$(date +%Y%m%d_%H%M%S).sql

# 3. Snapshot flat-file JSON stores
cp -r /var/www/html/AI/data /var/backups/aihubpedia/data_backup_$(date +%Y%m%d_%H%M%S)
```

---

## 3. Environment Configuration (`.env`)

Place your production `.env` file **outside the public webroot** (e.g., in `/var/www/aihubpedia_secrets/.env` or specify via `AIHUB_ENV_PATH`):

```bash
# Create secrets directory outside webroot
mkdir -p /var/www/aihubpedia_secrets
chmod 700 /var/www/aihubpedia_secrets

# Copy and edit environment template
cp /var/www/html/AI/.env.example /var/www/aihubpedia_secrets/.env
chmod 600 /var/www/aihubpedia_secrets/.env
chown -R www-data:www-data /var/www/aihubpedia_secrets
```

### Production `.env` Variables:

```env
# Application
AIHUB_ENV=production
AIHUB_DEBUG=false
AIHUB_BASE_URL=https://aihubpedia.com
AIHUB_SITE_NAME=AIHubpedia

# Database Connection (No insecure root defaults)
AIHUB_DB_HOST=localhost
AIHUB_DB_PORT=3306
AIHUB_DB_NAME=aihub
AIHUB_DB_USER=aihub_prod_user
AIHUB_DB_PASS=YourComplexGeneratedPassword32Chars!

# Admin Authentication (Generate hash using: php -r "echo password_hash('YourPassword', PASSWORD_BCRYPT);")
AIHUB_ADMIN_USERNAME=admin
AIHUB_ADMIN_PASSWORD_HASH=$2y$10$YourGeneratedBcryptHashHere

# Reverse Proxy Security (Set to your Cloudflare/Nginx IP/CIDR; or leave empty for direct connections)
AIHUB_TRUSTED_PROXIES=127.0.0.1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16

# Rate Limiting Storage Path (outside webroot)
AIHUB_RATE_LIMIT_PATH=/var/www/aihubpedia_secrets/.rate_limits.json

# Transactional Email (PHPMailer SMTP)
AIHUB_MAIL_TO=contact@aihubpedia.com
AIHUB_MAIL_FROM=contact@aihubpedia.com
AIHUB_MAIL_FROM_NAME="AIHubpedia"
AIHUB_SMTP_HOST=smtp.gmail.com
AIHUB_SMTP_PORT=587
AIHUB_SMTP_SECURE=tls
AIHUB_SMTP_USER=contact@aihubpedia.com
AIHUB_SMTP_PASS=YourNewRotatedGoogleAppPassword
```

---

## 4. Deployment Steps

```bash
# 1. SSH into production server
ssh user@your-server-ip

# 2. Navigate to project root
cd /var/www/html/AI

# 3. Fetch and switch to the release branch
git fetch origin fix/aihubpedia-security-foundation
git checkout fix/aihubpedia-security-foundation

# 4. Run automated test suite to verify server compatibility
php tests/SecurityAndStabilityTest.php

# 5. Reload PHP-FPM / Web Server (if caching bytecode via OPcache)
sudo systemctl reload php8.2-fpm
sudo systemctl reload nginx # or sudo systemctl reload apache2
```

---

## 5. Post-Deployment Smoke Verification

1. **Public Site:** Open `https://aihubpedia.com/` and confirm tools load cleanly.
2. **Admin Portal:** Visit `https://aihubpedia.com/admin.php` and verify:
   - Login form is rendered with CSRF token.
   - Successful login with your bcrypt credentials redirects to the admin submissions queue.
   - Submissions display with unique IDs (e.g. `sub_...`).
   - Logging out clears the session completely.
3. **Tool Submission:** Submit a test tool on `submit.php` and verify it appears in the admin queue.
4. **Contact Form:** Send a test inquiry on `contact.php` to verify SMTP connectivity.

---

## 6. Tested Rollback Plan

If any critical issue arises during deployment:

```bash
# 1. Checkout previous stable feature branch immediately
git checkout feature/aihubpedia-update

# 2. Reload PHP-FPM
sudo systemctl reload php8.2-fpm

# 3. (Optional) Restore database backup if any schema change occurred
# mysql -u root -p aihub < /var/backups/aihubpedia/aihub_pre_deploy_YYYYMMDD_HHMMSS.sql
```

*Note: Phase 1A strictly maintains 100% backward compatibility with `tools.json` and `submissions.json`. No data loss or schema teardown is required during rollback.*
