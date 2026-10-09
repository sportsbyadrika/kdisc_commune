#!/usr/bin/env bash
#
# cPanel "Git™ Version Control" deployment for Commune — called by .cpanel.yml.
#
# Layout on the server:
#   REPO_PATH  /home/shooting/repositories/kdisc_commune              cPanel's clone (never served)
#   APP_PATH   /home/shooting/apps/commune                            the running app: code, vendor/, storage/, .env
#                                                                     (outside public_html, so .env, KYC documents
#                                                                     and issued PDFs can never be downloaded)
#   WEB_ROOT   /home/shooting/public_html/commune.kdiscmis.org.in     the subdomain's document root: only a tiny
#                                                                     index.php, .htaccess, assets/ and media/
#
# Steps: sync code → storage dirs → .env (first deploy only) → publish web root   (plain file copies, no PHP needed)
#        → find PHP 8.4 → composer → migrate (+ first seed) → app:check.
# Safe to re-run; never touches .env, storage/ or uploaded photos after the first deploy.
# Everything is also appended to ~/commune-deploy.log (cPanel's own log: ~/.cpanel/logs/vc_*_git_deploy.log).
#
# Overrides (environment): REPO_PATH, APP_PATH, WEB_ROOT, PHP_BIN, COMPOSER_BIN, SKIP_MIGRATE=1, DEPLOY_LOG

set -Eeuo pipefail
umask 022

# cPanel runs deployment tasks with a minimal environment: HOME and PATH may be missing.
HOME="${HOME:-$(getent passwd "$(id -un)" 2>/dev/null | cut -d: -f6)}"
HOME="${HOME:-/home/$(id -un)}"
export HOME PATH="${PATH:-/usr/local/bin:/usr/bin:/bin}:/usr/local/bin:/usr/bin:/bin"
DEPLOY_LOG="${DEPLOY_LOG:-$HOME/commune-deploy.log}"
# Log everything to DEPLOY_LOG too. cPanel runs tasks in jailshell/CageFS where /dev/fd is missing, so process
# substitution (exec > >(tee …)) fails there — re-run this script through a plain pipe instead.
if [ -z "${COMMUNE_DEPLOY_CHILD:-}" ]; then
    export COMMUNE_DEPLOY_CHILD=1
    if command -v tee >/dev/null 2>&1 && touch "$DEPLOY_LOG" 2>/dev/null; then
        set +e
        /bin/bash "${BASH_SOURCE[0]}" "$@" 2>&1 | tee -a "$DEPLOY_LOG"
        status=${PIPESTATUS[0]}
        exit "$status"
    fi
    exec /bin/bash "${BASH_SOURCE[0]}" "$@"
fi
printf '\n######## %s  deploy started by %s ########\n' "$(date '+%F %T')" "$(id -un)"

REPO_PATH="${REPO_PATH:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
APP_PATH="${APP_PATH:-$HOME/apps/commune}"
WEB_ROOT="${WEB_ROOT:-$HOME/public_html/commune.kdiscmis.org.in}"

log()  { printf '\n==> %s\n' "$*"; }
warn() { printf '\n!!  %s\n' "$*" >&2; }
die()  { printf '\nXX  %s\n' "$*" >&2; exit 1; }
trap 'die "Deployment failed at line $LINENO (see the messages above)."' ERR

# ── Sanity checks ──────────────────────────────────────────────────────────────────────────────────────────
for p in "$REPO_PATH" "$APP_PATH" "$WEB_ROOT"; do
    case "$p" in
        ''|/|"$HOME"|"$HOME/"|"$HOME/public_html"|"$HOME/public_html/") die "Refusing to deploy with an unsafe path: '$p'";;
    esac
done
[ -f "$REPO_PATH/composer.json" ] && [ -f "$REPO_PATH/public/index.php" ] || die "REPO_PATH does not look like the Commune repository: $REPO_PATH"
case "$WEB_ROOT/" in "$APP_PATH/"*) die "WEB_ROOT must not be inside APP_PATH";; esac
case "$APP_PATH/" in "$HOME/public_html/"*) die "APP_PATH must be outside public_html (it holds .env, KYC documents and PDFs)";; esac

# ── 1. Sync code into APP_PATH ─────────────────────────────────────────────────────────────────────────────
log "Syncing code: $REPO_PATH → $APP_PATH"
mkdir -p "$APP_PATH"
EXCLUDES=(/.git/ /.github/ /.env /vendor/ /node_modules/ /storage/ /public/media/uploads/ /tests/ /.phpunit.cache/ /.phpunit.result.cache)
if command -v rsync >/dev/null 2>&1; then
    args=(-a --delete)
    for e in "${EXCLUDES[@]}"; do args+=(--exclude "$e"); done
    rsync "${args[@]}" "$REPO_PATH/" "$APP_PATH/"
else
    warn "rsync not found — copying with tar (files deleted from the repo are not removed from $APP_PATH)"
    args=()
    for e in "${EXCLUDES[@]}"; do args+=(--exclude ".${e%/}"); done
    (cd "$REPO_PATH" && tar -cf - "${args[@]}" .) | (cd "$APP_PATH" && tar -xf -)
fi
chmod 750 "$APP_PATH/bin/console"

# ── 2. Writable directories (never synced, never deleted) ──────────────────────────────────────────────────
log "Preparing storage/"
for d in storage/logs storage/sessions storage/cache storage/uploads/kyc storage/pdf storage/exports storage/mail \
         storage/imports public/media/uploads; do
    mkdir -p "$APP_PATH/$d"
done
chmod 750 "$APP_PATH/storage" "$APP_PATH/storage/uploads" "$APP_PATH/storage/uploads/kyc" "$APP_PATH/storage/pdf"
chmod 700 "$APP_PATH/storage/imports"

# ── 3. .env — created once from the template, then left alone ──────────────────────────────────────────────
ENV_FILE="$APP_PATH/.env"
FIRST_ENV=0
if [ ! -f "$ENV_FILE" ]; then
    log "Creating $ENV_FILE from .env.production"
    cp "$REPO_PATH/.env.production" "$ENV_FILE"
    chmod 600 "$ENV_FILE"
    key="base64:$(head -c 32 /dev/urandom | base64 | tr -d '\n')"
    sed -i "s|^APP_KEY=.*$|APP_KEY=${key}|" "$ENV_FILE"
    FIRST_ENV=1
    warn "A new APP_KEY was generated in $ENV_FILE — BACK IT UP OFFLINE now (it encrypts Aadhaar numbers)."
fi
chmod 600 "$ENV_FILE"
grep -q '^APP_KEY=base64:' "$ENV_FILE" || die "APP_KEY is empty in $ENV_FILE. Run: $PHP $APP_PATH/bin/console key:generate and paste the line."

# ── 4. Publish the web root ────────────────────────────────────────────────────────────────────────────────
log "Publishing web root: $WEB_ROOT"
mkdir -p "$WEB_ROOT"

# Front controller: a two-line wrapper that runs the real one in APP_PATH (its __DIR__ locates the app).
cat > "$WEB_ROOT/index.php.new" <<PHP
<?php
// Generated by deploy/cpanel/deploy.sh — do not edit; it is overwritten on every deploy.
// The application (code, .env, storage) lives outside public_html, in ${APP_PATH}.
return require '${APP_PATH}/public/index.php';
PHP
mv -f "$WEB_ROOT/index.php.new" "$WEB_ROOT/index.php"

# .htaccess: the app's rules, plus any cPanel-generated blocks (MultiPHP Manager's PHP-version handler, etc.)
# found in the existing file — dropping them would silently switch the site to the server's default PHP.
CPANEL_BLOCKS=""
if [ -f "$WEB_ROOT/.htaccess" ]; then
    CPANEL_BLOCKS="$(awk '/BEGIN cPanel-generated/{keep=1} keep{print} /END cPanel-generated/{keep=0}' "$WEB_ROOT/.htaccess")"
fi
cp "$APP_PATH/public/.htaccess" "$WEB_ROOT/.htaccess.new"
if [ -n "$CPANEL_BLOCKS" ]; then
    printf '\n%s\n' "$CPANEL_BLOCKS" >> "$WEB_ROOT/.htaccess.new"
fi
mv -f "$WEB_ROOT/.htaccess.new" "$WEB_ROOT/.htaccess"

cp -f "$APP_PATH/public/favicon.svg" "$WEB_ROOT/favicon.svg"

# Static assets (built CSS/JS, vendored libraries) and the committed media placeholders.
copy_dir() { # copy_dir SRC DEST [exclude…]
    local src="$1" dest="$2"; shift 2
    [ -L "$dest" ] && rm -f "$dest"
    mkdir -p "$dest"
    if command -v rsync >/dev/null 2>&1; then
        local a=(-a --delete); for e in "$@"; do a+=(--exclude "$e"); done
        rsync "${a[@]}" "$src/" "$dest/"
    else
        local a=(); for e in "$@"; do a+=(--exclude "./${e#/}"); done
        (cd "$src" && tar -cf - "${a[@]}" .) | (cd "$dest" && tar -xf -)
    fi
}
copy_dir "$APP_PATH/public/assets" "$WEB_ROOT/assets"
copy_dir "$APP_PATH/public/media"  "$WEB_ROOT/media" /uploads/ /uploads

# Layout photos are written by the app to APP_PATH/public/media/uploads — serve them through a symlink.
if [ -e "$WEB_ROOT/media/uploads" ] && [ ! -L "$WEB_ROOT/media/uploads" ]; then
    # A real directory here (e.g. from a manual upload): move its files into the app before linking.
    cp -an "$WEB_ROOT/media/uploads/." "$APP_PATH/public/media/uploads/" 2>/dev/null || true
    rm -rf "$WEB_ROOT/media/uploads"
fi
ln -sfn "$APP_PATH/public/media/uploads" "$WEB_ROOT/media/uploads"

# ── 5. PHP 8.4+ (cPanel EasyApache paths first; /usr/local/bin/php may be an older default) ───────────────────
find_php() {
    local c
    for c in "${PHP_BIN:-}" /opt/cpanel/ea-php85/root/usr/bin/php /opt/cpanel/ea-php84/root/usr/bin/php \
             /opt/alt/php85/usr/bin/php /opt/alt/php84/usr/bin/php /usr/local/bin/ea-php85 /usr/local/bin/ea-php84 \
             /usr/local/bin/php /usr/bin/php "$(command -v php 2>/dev/null || true)"; do
        [ -n "$c" ] && [ -x "$c" ] || continue
        if "$c" -r 'exit(PHP_VERSION_ID >= 80400 ? 0 : 1);' >/dev/null 2>&1; then echo "$c"; return 0; fi
    done
    return 1
}
PHP="$(find_php)" || die "Files are published, but PHP 8.4 or newer was not found for the remaining steps (composer, migrations).
    Install ea-php84 (WHM → EasyApache 4) or alt-php84 (CloudLinux), or add PHP_BIN=/path/to/php to .cpanel.yml."
log "PHP: $PHP ($("$PHP" -r 'echo PHP_VERSION;'))"

missing="$("$PHP" -r '$m=[]; foreach (["pdo_mysql","sodium","mbstring","intl","gd","zip","fileinfo","dom","xml","xmlreader","xmlwriter","simplexml","zlib","iconv","ctype","openssl"] as $e) { if (!extension_loaded($e)) $m[]=$e; } echo implode(" ", $m);')"
[ -z "$missing" ] || die "Files are published, but PHP extensions are missing for $PHP: $missing (WHM → EasyApache 4 → PHP Extensions, e.g. ea-php84-php-intl)"

# ── 6. Composer (production dependencies only) ─────────────────────────────────────────────────────────────
find_composer() {
    local c
    for c in "${COMPOSER_BIN:-}" /opt/cpanel/composer/bin/composer /usr/local/bin/composer \
             "$(command -v composer 2>/dev/null || true)" "$APP_PATH/composer.phar"; do
        [ -n "$c" ] && [ -f "$c" ] && { echo "$c"; return 0; }
    done
    return 1
}
if ! COMPOSER="$(find_composer)"; then
    log "Composer not found — downloading composer.phar (checksum verified)"
    "$PHP" -r '
        $phar = file_get_contents("https://getcomposer.org/download/latest-stable/composer.phar");
        $sum  = trim((string) file_get_contents("https://getcomposer.org/download/latest-stable/composer.phar.sha256"));
        if ($phar === false || $sum === "" || !hash_equals(strtolower(substr($sum, 0, 64)), hash("sha256", $phar))) { fwrite(STDERR, "composer download/checksum failed\n"); exit(1); }
        file_put_contents($argv[1], $phar);' "$APP_PATH/composer.phar"
    COMPOSER="$APP_PATH/composer.phar"
fi
log "Composer install ($COMPOSER)"
# Run Composer with the same PHP 8.4 binary (its own shebang may point at an older PHP).
(cd "$APP_PATH" && "$PHP" "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist)

# ── 7. Database: pending migrations, plus the reference data on the very first install ──────────────────────
if grep -Eq '^DB_PASSWORD=(CHANGE_ME)?[[:space:]]*$' "$ENV_FILE"; then
    warn "Database credentials are not set yet in $ENV_FILE.
    1. cPanel → MySQL® Databases: create database + user (ALL PRIVILEGES).
    2. Edit DB_*, MAIL_* in $ENV_FILE.
    3. Deploy again (cPanel → Git™ Version Control → Manage → Pull or Deploy → Deploy HEAD Commit).
    The files are published; migrations were skipped."
elif [ "${SKIP_MIGRATE:-0}" = "1" ]; then
    warn "SKIP_MIGRATE=1 — migrations skipped."
else
    log "Running migrations"
    "$PHP" "$APP_PATH/bin/console" migrate
    if [ ! -f "$APP_PATH/storage/.seeded" ]; then
        log "First install: seeding reference data (centre, floors, seats, prices, facilities, settings)"
        "$PHP" "$APP_PATH/bin/console" db:seed
        date -u +%FT%TZ > "$APP_PATH/storage/.seeded"
        warn "Create the first Centre Manager over SSH (cPanel → Terminal):
    $PHP $APP_PATH/bin/console user:create --name=\"Full Name\" --email=you@example.org --role=centre_manager"
    fi
fi

# ── 8. Health report (informational) ───────────────────────────────────────────────────────────────────────
log "app:check"
"$PHP" "$APP_PATH/bin/console" app:check || warn "app:check reported problems — see above (cron and mail warnings are expected until they are set up)."

cat <<EOF

==> Deployed $(cd "$REPO_PATH" && git rev-parse --short HEAD 2>/dev/null || echo '?') to https://commune.kdiscmis.org.in
    App: $APP_PATH    Web root: $WEB_ROOT

    Cron jobs (cPanel → Cron Jobs), if not added yet:
    */15 * * * *  cd $APP_PATH && $PHP bin/console bookings:tick   >> storage/logs/cron.log 2>&1
    */10 * * * *  cd $APP_PATH && $PHP bin/console holds:cleanup   >> storage/logs/cron.log 2>&1
    7    * * * *  cd $APP_PATH && $PHP bin/console imports:cleanup >> storage/logs/cron.log 2>&1
EOF
if [ "$FIRST_ENV" = "1" ]; then
    warn "First deploy: fill in $ENV_FILE (DB_*, MAIL_*) and deploy again."
fi
exit 0
