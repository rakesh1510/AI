# V3 staging deployment and rollback (do not run without owner approval)

Nothing here has been run. Host, user and path are **placeholders**: the repository does not record the real values, and none were invented. Production is never touched: every command targets the staging document root only.

```bash
# ---- variables: fill in from your hosting panel / ~/.ssh/config ----
export STG_HOST="<staging-ssh-host>"       # e.g. the SSH host for staging.aihubpedia.com
export STG_USER="<ssh-user>"
export STG_ROOT="<absolute path of the STAGING document root>"   # must be the staging site, never the production root
export BRANCH="feat/aihubpedia-premium-3d-ui-v3"
export STAMP="$(date +%Y%m%d-%H%M%S)"
```

## 0. Safety checks
```bash
ssh "$STG_USER@$STG_HOST" "test -d '$STG_ROOT' && echo ROOT_OK; cd '$STG_ROOT' && pwd && ls | head -5"
# Confirm by eye that this is STAGING (Basic-auth protected, noindex) and not the production root. Stop if unsure.
```

## 1. Back up the current staging code (code only; the database is not touched)
```bash
ssh "$STG_USER@$STG_HOST" "mkdir -p ~/backups && cd '$STG_ROOT'/.. && tar --exclude='*/data/*.log' -czf ~/backups/staging-code-$STAMP.tgz \"\$(basename '$STG_ROOT')\" && ls -lh ~/backups/staging-code-$STAMP.tgz"
```
Existing backups are never deleted or overwritten (the file name carries a timestamp).

## 2. Build the release locally and upload
```bash
git fetch origin "$BRANCH" && git checkout "$BRANCH"
php scripts/build_assets.php --check          # must print "Built assets are up to date."
# upload code + assets; keep staging-only files (data/, config, .htaccess/.htpasswd, uploads) untouched:
rsync -avz --dry-run --delete-after \
  --exclude='.git' --exclude='tests/' --exclude='docs/' --exclude='assets3d/' --exclude='node_modules/' --exclude='.claude/' \
  --exclude='data/' --exclude='.htpasswd' --exclude='.env*' --exclude='*.log' \
  ./ "$STG_USER@$STG_HOST:$STG_ROOT/"
# Review the dry-run list. Then run the same command without --dry-run.
```
New runtime files in this release: `art.php`, `services_catalog.php`, `directory.php`, `assets/art/*.webp`, `assets/fonts/*`, `assets/css/premium*.css`, rebuilt `assets/css/site.min.css`, `assets/js/*.min.js`, `assets/build.json`. No database migration and no schema change are needed.
`assets3d/` (Blender sources) and `tests/` are not uploaded.

## 3. Staging smoke test (behind the existing Basic auth)
```bash
curl -s -u "$USER:$PASS" -o /dev/null -w "%{http_code} home\n"   https://staging.aihubpedia.com/
for p in categories.php compare.php guides.php business.php advertise.php services.php contact.php submit.php leaderboard.php recommend.php privacy.php 404.php; do
  curl -s -u "$USER:$PASS" -o /dev/null -w "%{http_code} $p\n" "https://staging.aihubpedia.com/$p"; done
curl -sI -u "$USER:$PASS" https://staging.aihubpedia.com/assets/art/hero_knot.webp | head -3     # expect 200, image/webp
curl -sI -u "$USER:$PASS" https://staging.aihubpedia.com/assets/fonts/inter-latin-wght-normal.woff2 | head -3
```
Then, in a browser: header and More menu, theme toggle, search ("/" shortcut), enquiry forms with **test data only** (check the owner notification path first), recommendation wizard, outbound click through `/redirect.php`, privacy dialog. Staging must remain `noindex` and behind Basic auth.

## Rollback
```bash
# A. Restore the code from the backup taken in step 1 (replace STAMP with the real one)
ssh "$STG_USER@$STG_HOST" "cd '$STG_ROOT'/.. && tar -xzf ~/backups/staging-code-<STAMP>.tgz && echo restored"
# B. Or redeploy the previous branch the same way:  git checkout feat/aihubpedia-premium-3d-ui-v2  (then repeat step 2)
# C. Purge any CDN/browser cache for /assets/css/site.min.css and /assets/js/*.min.js if one is configured.
```
No database change was made, so there is nothing to roll back in the database.
