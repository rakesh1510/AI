# V3 staging deployment, verification and rollback

**Scope: staging only.** Directory `/var/www/html/AI-PHASE3-TEST`, database `aihub_phase6_test`, URL https://staging.aihubpedia.com.
**Never touched by any command below:** production (`/var/www/html/AI`, database `aihub`), any database content or schema, Apache/PHP/hosting configuration, DNS, credentials, logs, analytics data, existing backups.
No command below uses `git clean`, `git reset`, `git checkout -- <path>`, `git stash`, `rm`, a database client or a service restart. Run them one at a time and send the output of each step back for review before continuing.

This release needs **no database change and no new configuration**. It adds code, CSS/JS (already built and committed), fonts and WebP artwork. AI Universe stays hidden: it is not in the sitemap, navigation or footer, and its pages send `noindex, nofollow`; its code, routes and data are unchanged. The switch to list it publicly again is `AIHUB_UNIVERSE_PUBLIC=1` (leave it unset).

Set these once per SSH session (the commit ID is the one given in the hand-over message):

```bash
export STG=/var/www/html/AI-PHASE3-TEST
export PROD=/var/www/html/AI
export NEW=<final-commit-id-from-the-hand-over-message>
export BR=feat/aihubpedia-premium-3d-ui-v3
export STAMP=$(date +%Y%m%d-%H%M%S)
export WORK=$HOME/v3-staging-$STAMP         # records and baselines live OUTSIDE the web root
mkdir -p "$WORK" && echo "$WORK"
```

## Step 1. Confirm you are in staging and check its Git state (read-only)
```bash
cd "$STG" && pwd -P
test "$(pwd -P)" = "$STG" && echo "OK: staging" || echo "STOP: not the staging directory"
git status --short | head -60            # untracked backup files and local edits are expected; they are left alone
git branch --show-current                # empty output means detached HEAD (expected)
git rev-parse HEAD
git log -1 --format='%H %an %ad %s' --date=iso
git remote -v
git status --short | wc -l
```
Stop and report if `pwd -P` is not `/var/www/html/AI-PHASE3-TEST`.

## Step 2. Record the rollback point and local differences
```bash
cd "$STG"
git rev-parse HEAD            | tee "$WORK/old-commit.txt"
git status --porcelain=v1     > "$WORK/status-before.txt"
git diff                      > "$WORK/local-tracked-changes.patch"     # tracked files edited on this server (config, .htaccess, runtime data)
git diff --name-only          | sort | tee "$WORK/local-modified-files.txt"
git ls-files --others --exclude-standard | sort > "$WORK/untracked-before.txt"
wc -l "$WORK"/*.txt "$WORK/local-tracked-changes.patch"
```
`old-commit.txt` is what you roll back to. Keep the whole `$WORK` folder.

## Step 3. Baseline of the staging protection (before any change)
```bash
curl -sI https://staging.aihubpedia.com/ | tee "$WORK/headers-noauth-before.txt" | head -8      # unauthenticated: expect 401 + WWW-Authenticate
# authenticated: replace USER; curl asks for the password
curl -sI -u USER https://staging.aihubpedia.com/ | tee "$WORK/headers-auth-before.txt" | head -12
curl -s  -u USER https://staging.aihubpedia.com/robots.txt | tee "$WORK/robots-before.txt" | head -5
```
Note whether the responses contain `WWW-Authenticate`, `X-Robots-Tag: noindex` or a `<meta name="robots" content="noindex…">` tag. Those must be identical after deployment.

## Step 4. Timestamped backup (read-only on the site, writes only to your home)
```bash
du -sh "$STG"                       # check the size first
mkdir -p "$HOME/backups"
tar -czf "$HOME/backups/staging-AI-PHASE3-TEST-$STAMP.tgz" -C "$(dirname "$STG")" "$(basename "$STG")"
ls -lh "$HOME/backups/staging-AI-PHASE3-TEST-$STAMP.tgz"
tar -tzf "$HOME/backups/staging-AI-PHASE3-TEST-$STAMP.tgz" | head -3
df -h "$HOME" | tail -1
```
Existing backups are never touched: the archive name carries a timestamp. If disk space is short, stop and report.

Optional read-only database dump (does not modify the database; skip if you have no `~/.my.cnf` or equivalent):
```bash
mysqldump --single-transaction --no-tablespaces aihub_phase6_test | gzip > "$HOME/backups/aihub_phase6_test-$STAMP.sql.gz" && ls -lh "$HOME/backups/aihub_phase6_test-$STAMP.sql.gz"
```
The dump is only a safety copy. **Do not import it or any other file into any database.**

## Step 5. Fetch the V3 branch (no checkout yet)
```bash
cd "$STG"
git fetch origin "$BR"
git cat-file -t "$NEW"                              # must print: commit
git log -1 --format='%H%n%an%n%ad%n%s' --date=iso "$NEW"
git branch -r --contains "$NEW" | head -3           # must list origin/feat/aihubpedia-premium-3d-ui-v3
git rev-parse "origin/$BR"                          # expected to equal $NEW
```
If `git fetch` cannot reach GitHub from the server, stop and report; do not work around it with copies of files.

## Step 6. Compatibility and configuration review (read-only)
```bash
cd "$STG"
OLD=$(cat "$WORK/old-commit.txt")
git diff --stat "$OLD" "$NEW" | tail -3
git diff --name-only "$OLD" "$NEW" | sort > "$WORK/files-changed-by-release.txt"
wc -l "$WORK/files-changed-by-release.txt"

echo "--- 1. files edited locally on staging AND changed by this release (must be EMPTY, or reviewed by hand) ---"
comm -12 "$WORK/local-modified-files.txt" "$WORK/files-changed-by-release.txt"

echo "--- 2. configuration and runtime-data files in the release (expected: nothing under data/, no .htaccess, no database/) ---"
grep -E '^(data/|\.htaccess|database/|composer\.|config|\.env)' "$WORK/files-changed-by-release.txt" || echo "none"

echo "--- 3. untracked files that the release would ADD at the same path (checkout would refuse; expected: none) ---"
git diff --name-only --diff-filter=A "$OLD" "$NEW" | sort | comm -12 - "$WORK/untracked-before.txt" || true

echo "--- 4. PHP version and required extensions ---"
php -v | head -1
php -m | grep -E -i '^(pdo_mysql|mbstring|json|openssl|curl|fileinfo)$'

echo "--- 5. syntax-check every PHP file the release adds or changes (read-only, from the fetched objects) ---"
mkdir -p "$WORK/lint" && for f in $(git diff --name-only --diff-filter=AM "$OLD" "$NEW" | grep '\.php$' | grep -v '^tests/'); do
  git show "$NEW:$f" > "$WORK/lint/x.php" && php -l "$WORK/lint/x.php" >/dev/null 2>&1 || echo "LINT FAIL: $f"; done; echo "lint done"
```
Expected: block 1 empty, block 2 prints `none`, block 3 empty, no `LINT FAIL`. The release changes no `.htaccess`, no `data/` file and no SQL: staging's own `.htaccess` (Basic auth), configuration, credentials and runtime data therefore stay exactly as they are. **If any block prints something unexpected, stop and send the output.**

## Step 7. Deploy the verified commit
```bash
cd "$STG"
git checkout --detach "$NEW"
git rev-parse HEAD                      # must equal $NEW
git status --short | head -30
diff <(git ls-files --others --exclude-standard | sort) "$WORK/untracked-before.txt" && echo "untracked files unchanged"
git diff --name-only | sort | diff - "$WORK/local-modified-files.txt" && echo "local edits unchanged"
```
`git checkout --detach` refuses to run if it would overwrite a local change, so a conflict fails safely with no change made. It keeps untracked files (backups) and locally modified files.
If PHP OPcache has `validate_timestamps=0` on this server, new code is only picked up after a PHP-FPM reload; that is a hosting action, so ask before doing it. Check first: `php -r 'var_dump(ini_get("opcache.validate_timestamps"));'` (CLI value; the FPM value may differ).
Assets are already built and committed. Confirm: `php scripts/build_assets.php --check` should print `Built assets are up to date.`

## Step 8. Verify staging
```bash
cd "$STG"
echo "--- files present ---"
ls -l assets/art/hero_knot.webp assets/fonts/inter-latin-wght-normal.woff2 assets/css/site.min.css art.php services_catalog.php | cut -c1-110
php scripts/build_assets.php --check | tail -1
echo "--- protection must be unchanged ---"
curl -sI https://staging.aihubpedia.com/ > "$WORK/headers-noauth-after.txt"; diff <(grep -v -i '^\(date\|etag\|last-modified\|content-length\|set-cookie\)' "$WORK/headers-noauth-before.txt") <(grep -v -i '^\(date\|etag\|last-modified\|content-length\|set-cookie\)' "$WORK/headers-noauth-after.txt") && echo "unauthenticated response identical (still 401)"
echo "--- authenticated checks: replace USER and enter the password at the prompt ---"
for p in "" categories.php compare.php guides.php business.php advertise.php services.php contact.php submit.php leaderboard.php top.php recommend.php solutions.php blog.php about.php methodology.php disclosure.php privacy.php terms.php directory.php tool.php?id=chatgpt "category.php?name=Coding" "search.php?q=transcribe" favorites.php 404.php; do
  printf "%s " "$p"; curl -s -u USER -o /dev/null -w "%{http_code}\n" "https://staging.aihubpedia.com/$p"; done
curl -sI -u USER https://staging.aihubpedia.com/assets/art/hero_knot.webp | head -4        # 200, image/webp
curl -sI -u USER https://staging.aihubpedia.com/assets/fonts/space-grotesk-latin-wght-normal.woff2 | head -4
echo "--- indexing protection and AI Universe ---"
curl -sI -u USER https://staging.aihubpedia.com/ > "$WORK/headers-auth-after.txt"
diff <(grep -i 'x-robots-tag\|www-authenticate' "$WORK/headers-auth-before.txt") <(grep -i 'x-robots-tag\|www-authenticate' "$WORK/headers-auth-after.txt") && echo "same robots/auth headers as before"
curl -s  -u USER https://staging.aihubpedia.com/ | grep -o '<meta name="robots"[^>]*>' | head -1
curl -s  -u USER https://staging.aihubpedia.com/sitemap.xml | grep -c -i universe     # expect 0
curl -s  -u USER https://staging.aihubpedia.com/ | grep -c -i 'universe'               # expect 0 (no public link)
curl -sI -u USER https://staging.aihubpedia.com/universe.php | grep -i 'x-robots-tag'  # expect: noindex, nofollow
curl -s  -u USER https://staging.aihubpedia.com/robots.txt | grep -i universe
echo "--- errors ---"
ls -t /var/log/apache2/*error*log 2>/dev/null | head -2      # if you can read the Apache error log, check its newest entries (read-only)
tail -n 40 "$STG"/data/*.log 2>/dev/null | tail -40
```
Staging is expected to stay behind Basic auth with `noindex`: if either headers diff differs from the baseline, treat it as a stop condition and roll back.

Manual browser checks (you): header and More menu, theme toggle (light/dark), search and the `/` shortcut, one enquiry form with test data, recommendation wizard (with and without JavaScript), an outbound "Open official website" click (served through `/redirect.php`), privacy dialog, mobile layouts, and the 52 pages from `tests/public_pages.json`.

## Rollback (any problem in steps 7–8)
```bash
cd "$STG"
OLD=$(cat "$WORK/old-commit.txt"); echo "rolling back to $OLD"
git checkout --detach "$OLD"
git rev-parse HEAD                                              # must equal the recorded old commit
git status --porcelain=v1 | diff - "$WORK/status-before.txt" && echo "working tree state identical to before the release"
curl -s -u USER -o /dev/null -w "%{http_code}\n" https://staging.aihubpedia.com/
```
That fully reverts the code: nothing else was changed (no database, config, data, logs or backups).
Last resort only if the checkout itself fails: extract the backup into a **new, empty** directory and compare; never extract over the live directory.
```bash
mkdir -p "$HOME/restore-$STAMP" && tar -xzf "$HOME/backups/staging-AI-PHASE3-TEST-$STAMP.tgz" -C "$HOME/restore-$STAMP"
diff -rq "$HOME/restore-$STAMP/AI-PHASE3-TEST" "$STG" | head -40      # review before copying anything, and ask before any copy
```
