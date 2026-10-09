#!/usr/bin/env bash
#
# Build deploy/vendor/ — the production Composer dependencies, committed to git, so shared hosting (cPanel) never
# needs to run `composer install`. deploy/cpanel/deploy.sh copies it to the app's vendor/.
#
# Run after ANY change to composer.json / composer.lock, then commit deploy/vendor:
#     bin/build-vendor-bundle.sh && git add -A deploy/vendor
# tests/Unit/VendorBundleTest fails while the bundle is out of date.

set -Eeuo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
ROOT="$PWD"
OUT="$ROOT/deploy/vendor"
COMPOSER="${COMPOSER_BIN:-$(command -v composer || true)}"
[ -n "$COMPOSER" ] || { echo "composer not found (set COMPOSER_BIN)" >&2; exit 1; }

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
cp composer.json composer.lock "$TMP/"

echo "==> composer install --no-dev (in $TMP)"
# Built without the app's own files, so the optimised classmap only lists vendor classes; App\ and Database\Seeds\
# still load through PSR-4 (relative to the vendor dir, so the paths are right once copied to <app>/vendor).
(cd "$TMP" && "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts --prefer-dist)

echo "==> Pruning tests, docs and VCS data"
(
    cd "$TMP/vendor"
    find . -name .git -type d -prune -exec rm -rf {} +
    rm -rf bin
    for pkg in */*/; do
        for d in tests test Tests docs doc samples examples infra .github benchmarks bin; do
            [ -d "$pkg$d" ] && rm -rf "${pkg:?}$d"
        done
        find "$pkg" -maxdepth 1 -type f \( \( -name '*.md' ! -iname 'LICENSE*' \) -o -name 'phpunit.xml*' -o -name 'phpstan*' \
            -o -name 'phpcs.xml*' -o -name 'phpmd.xml*' -o -name 'phpdoc.xml*' -o -name 'mkdocs*.yml' -o -name '*.toml' \
            -o -name 'properdocs.yml' -o -name 'composer.lock' -o -name '.gitattributes' -o -name '.gitignore' -o -name '.editorconfig' \) -delete
    done
)

echo "==> Checking the autoloader"
php -r '
    $v = $argv[1] . "/vendor/composer"; $missing = [];
    foreach ((require "$v/autoload_files.php") + (require "$v/autoload_classmap.php") as $k => $f) {
        $own = str_starts_with($f, $argv[1] . "/app/") || str_starts_with($f, $argv[1] . "/database/")
            || str_contains($f, "/../../app/") || str_contains($f, "/../../database/");
        if (!$own && !is_file($f)) { $missing[] = "$k => $f"; }
    }
    if ($missing) { fwrite(STDERR, "Pruned files are still referenced:\n" . implode("\n", $missing) . "\n"); exit(1); }' "$TMP"

hash="$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["content-hash"];' composer.lock)"
printf 'Production Composer dependencies for composer.lock content-hash %s\nRebuild with bin/build-vendor-bundle.sh — do not edit by hand.\n' "$hash" > "$TMP/vendor/BUNDLE.txt"

echo "==> Writing $OUT"
mkdir -p "$OUT"
rsync -a --delete "$TMP/vendor/" "$OUT/"
echo "Done: $(du -sh "$OUT" | cut -f1), $(find "$OUT" -type f | wc -l) files. Commit deploy/vendor."
