#!/usr/bin/env bash
# PHP syntax check for every first-party PHP file (vendor excluded).
cd "$(dirname "$0")/.."
fail=0; n=0
while IFS= read -r f; do
  n=$((n+1))
  out=$(php -l "$f" 2>&1) || { echo "$out"; fail=$((fail+1)); }
  # no output before <?php (breaks headers, sitemaps and redirects)
  if [ "$(head -c 5 "$f")" != "<?php" ] && grep -q "^<?php" "$f" && [ "$(head -c1 "$f" | od -An -c | tr -d ' ')" = '\n' ]; then echo "Leading whitespace: $f"; fail=$((fail+1)); fi
done < <(find . -name '*.php' -not -path './vendor/*' -not -path './.git/*' | sort)
echo "Linted $n files, $fail problems"
exit $fail
