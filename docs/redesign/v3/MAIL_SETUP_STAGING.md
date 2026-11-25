# Staging mail setup (Hostinger SMTP) and delivery verification

Staging only (`/var/www/html/AI-PHASE3-TEST`, database `aihub_phase6_test`). Production is not touched. No credential is stored in Git or in the web directory.

## How settings are found (highest priority first)
1. Environment variables `AIHUB_SMTP_*`, `AIHUB_MAIL_FROM`, `AIHUB_MAIL_FROM_NAME`, `AIHUB_MAIL_TO`.
2. **Private mail file `/etc/aihubpedia/mail.php`** (or the path in `AIHUB_MAIL_CONFIG_FILE`). Outside the web root and Git. Its values win over the mail block of `config.local.php`; everything else in `config.local.php` (database, proxies...) is untouched.
3. `mail` block in `config.local.php`.
4. Database `site_settings` row `mail` (if the table has rows, `data/site.json` is ignored), then `data/site.json`.
The old Gmail values in 3 or 4 are not modified or deleted; they are simply overridden. Removing `/etc/aihubpedia/mail.php` returns to the previous behaviour.

All forms (contact, sponsorship/advertising, project/custom AI, consultation, partnership, tool submission, newsletter confirmation) and the cron retry use this one configuration.

## 1. Create the private file (you type the password; it is never echoed, logged or put on a command line)
```bash
sudo install -d -m 750 -o root -g www-data /etc/aihubpedia
read -rsp 'Hostinger mailbox password: ' MAILPW; echo
sudo env MAILPW="$MAILPW" php -r '
$c = ["mail" => ["smtp_host" => "smtp.hostinger.com", "smtp_port" => 587, "smtp_secure" => "tls",
  "smtp_user" => "contact@aihubpedia.com", "smtp_pass" => getenv("MAILPW"),
  "from_email" => "contact@aihubpedia.com", "from_name" => "AIHubpedia", "to_email" => "contact@aihubpedia.com"]];
file_put_contents("/etc/aihubpedia/mail.php", "<?php\nreturn " . var_export($c, true) . ";\n");'
unset MAILPW
sudo chown root:www-data /etc/aihubpedia/mail.php && sudo chmod 640 /etc/aihubpedia/mail.php
ls -l /etc/aihubpedia/mail.php
```
Change the mailbox password in Hostinger if it was ever shared in chat or e-mail, then re-run step 1 with the new one.

## 2. Verify (after the release is deployed to staging)
```bash
cd /var/www/html/AI-PHASE3-TEST
sudo -u www-data php scripts/mail_health.php --probe      # config source labels, then "ok" = SMTP connect + login worked (no mail sent)
sudo -u www-data php scripts/mail_send_test.php           # dry run
sudo -u www-data php scripts/mail_send_test.php --yes     # sends ONE message to contact@aihubpedia.com only
```
"ok" from the send test means the SMTP server accepted the message. **Delivery is confirmed only when it arrives in the contact@aihubpedia.com mailbox (check Inbox and Spam).** Then submit one real enquiry on https://staging.aihubpedia.com/inquiry.php with your own address and confirm: the owner notification arrives with the reference in the subject, and your address receives the acknowledgement.

## 3. Retry cron (every 5 minutes, as the web user)
```
*/5 * * * * www-data php /var/www/html/AI-PHASE3-TEST/scripts/mail_spool_flush.php >> /var/log/aihub_mail_spool.log 2>&1
```
Put it in `/etc/cron.d/aihub-staging-mail`. Failed messages wait in `data/mail_spool` (private, web-blocked) and are retried with back-off for up to 7 days.

## 4. If something fails
`mail_health.php --probe` returns a safe code: `smtp_connect` (port/firewall), `smtp_auth` (wrong login), `smtp_rejected` (sender/recipient refused), `not_configured`. Nothing is lost meanwhile: enquiries are stored first and the e-mails are spooled.

## Rollback
```bash
sudo mv /etc/aihubpedia/mail.php /etc/aihubpedia/mail.php.disabled     # back to the previous mail settings
cd /var/www/html/AI-PHASE3-TEST && git checkout --detach "$(cat "$HOME"/v3-staging-*/old-commit.txt | head -1)"   # code rollback
```
