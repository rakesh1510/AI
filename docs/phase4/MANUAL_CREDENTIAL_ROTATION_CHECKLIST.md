# Manual checklist: rotating historical credentials (owner action)

**Nothing in this repository rotates or revokes credentials, and Git history was NOT modified or rewritten.** The code no longer contains the secrets in its current files (verified by `tests/security_inventory_test.php`, which scans every tracked file), but they remain in Git history and in every branch and pull-request diff created before Phase 4. Treat them as compromised.

## 1. What is exposed (verified by searching history; the values are intentionally not repeated here)
| Secret | Where it lives in history | First commit | Also present at the tip of |
|---|---|---|---|
| Database password and user name (hard-coded fallback in `db.php`) | `db.php` | `3ceb49d` (2026-03-21, initial commit); changed in `b7c5f42` | `main`, `feat/aihubpedia-seo-performance-v1`, `feat/aihubpedia-global-growth-v2`, `feat/aihubpedia-premium-ux-v3` |
| Gmail/SMTP user and **app password** | `data/site.json` (`mail.smtp_user`, `mail.smtp_pass`) | `3ceb49d` | same four branches |
| Admin password (`admin_pass`) and the printed default `admin123` | `data/site.json`, `admin.php`, `README.txt` | `3ceb49d` / `b7c5f42` | same four branches |
| Demo account password (public by design, now hashed) | `data/users.json` | `3ceb49d` | `main` |
| Owner's personal mail address used as sender/recipient | `data/site.json` | `3ceb49d` | not a secret, but personal data in a possibly public repository |
Check yourself (prints commit ids only): `git log --all --oneline -S'<value>' -- db.php data/site.json admin.php`. If the repository is **public**, or ever was, assume the values were harvested.

## 2. Rotation order (do this BEFORE deploying Phase 4)
1. **Decide the exposure.** Is `rakesh1510/AI` public? Who has access? Are forks/clones possible? If public: rotate everything below today.
2. **Database (Hostinger panel):** create a *new* MySQL user with a long random password restricted to the site database; grant only `SELECT, INSERT, UPDATE, DELETE` (migrations use a separate admin session). Then change the password of or delete the old user. Do this from the hosting panel, not from code.
3. **Update the site configuration** with the new values as environment variables or an untracked `config.local.php` (see DEPLOYMENT_CHECKLIST.md). Confirm the site works (votes, comments, leads) with the *new* user before removing the old one.
4. **Gmail/SMTP:** in the Google account, revoke the existing app password (Security → App passwords), create a new one for AIHubpedia (or move to a transactional email provider with a restricted API key). Store it as `AIHUB_SMTP_PASS`. Send one test lead to a mailbox you own.
5. **Admin access:** generate a new strong password; create the hash with `php -r 'echo password_hash(trim(fgets(STDIN)), PASSWORD_DEFAULT), "\n";'`; set `AIHUB_ADMIN_PASS_HASH`. The old `admin_pass` value and `admin123` are void: the code ignores `admin_pass` entirely.
6. **Form signing secret and analytics salt:** set `AIHUB_FORM_SECRET` and `AIHUB_ANALYTICS_SALT` (64+ random hex characters each). Changing them invalidates open forms (harmless).
7. **Other places the same passwords may be reused:** hosting account, phpMyAdmin, FTP/SSH, email account, any password manager entry. Change any that match.
8. **Review access logs** (web server, database, mail account) for unfamiliar activity since March 2026: logins to phpMyAdmin or the admin page, bulk mail, database exports.
9. **Enable 2FA** on GitHub, the mail account, the hosting account and the domain registrar.

## 3. Removing secrets from history (optional, destructive: needs your decision)
Rotation makes the old values useless; purging history only reduces future exposure and is not required if everything is rotated. If you still want to purge:
- Work on a **fresh mirror clone**, never on your working copy: `git clone --mirror https://github.com/rakesh1510/AI.git`.
- Use `git filter-repo --replace-text expressions.txt` (preferred) or BFG Repo-Cleaner with a file listing the secret strings; review the result with `git log --all -S'<value>'`.
- Force-pushing rewrites every branch and **breaks** open pull requests (#3, #5), forks and local clones; coordinate first, and expect to recreate PRs.
- GitHub keeps cached views and PR diffs: contact GitHub Support to purge cached data; ask any collaborators to re-clone.
- Rotation (section 2) must be complete first; a purge never makes an exposed secret safe again.
- This work did not and will not do this automatically.

## 4. After rotation: verify
- [ ] Old database user can no longer log in; new user works with least privilege.
- [ ] Old Gmail app password revoked; a new test email arrives.
- [ ] `/admin.php` rejects the old password and `?pass=` URLs; the new hashed password works.
- [ ] `php tests/security_inventory_test.php` still passes in your checkout (no secret has been committed again).
- [ ] No secrets in screenshots, tickets or chat logs.
