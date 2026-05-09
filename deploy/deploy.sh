#!/usr/bin/env bash
#
# Daily deploy script — runs on the VPS, idempotent + safe to re-run.
#
# Usage:
#   sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh
#
# What it does:
#   1. git pull --ff-only (no merge commits on prod)
#   2. composer install --no-dev (production deps only)
#   3. php artisan migrate --force (run any new migrations)
#   4. php artisan config:cache + route:cache + view:cache (perf)
#   5. php artisan horizon:terminate (supervisor restarts the worker so it
#      picks up code changes)
#   6. chown to stcav:nginx so PHP-FPM can read everything
#   7. Print the deployed git short SHA for your records
#

set -euo pipefail

PROJECT_ROOT="/home/stcav/ms.21stcav.com"
WEB_GROUP="${WEB_GROUP:-nginx}"   # override with WEB_GROUP=apache ./deploy.sh if needed

cd "$PROJECT_ROOT"

# Sanity: refuse to run as root (file-ownership pitfall)
if [[ "$(id -un)" == "root" ]]; then
    echo "ERROR: do not run deploy.sh as root — switch with 'sudo -u stcav -i' first."
    exit 1
fi

echo "==> deploy.sh starting at $(date -Iseconds)"

# ── 1. Pull latest main ────────────────────────────────────────────────────
echo "==> git pull"
git fetch origin main
git reset --hard origin/main      # match origin exactly; discard any local edits

# ── 2. Composer (prod only, optimised autoloader) ─────────────────────────
echo "==> composer install"
composer install --no-dev --optimize-autoloader --no-interaction

# ── 3. Migrate (--force = required in non-interactive mode) ───────────────
echo "==> migrate"
php artisan migrate --force

# ── 4. Rebuild caches ──────────────────────────────────────────────────────
echo "==> caches"
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# ── 5. Bounce queue worker so it loads new code ───────────────────────────
echo "==> horizon:terminate (supervisor will respawn)"
php artisan horizon:terminate || echo "  (horizon not running — supervisor will start it)"

# ── 6. Storage symlink (idempotent — Laravel skips if already linked) ─────
php artisan storage:link >/dev/null 2>&1 || true

# ── 7. File ownership (storage + cache must be writable by web user) ──────
echo "==> chown storage + bootstrap/cache to ${USER}:${WEB_GROUP}"
sudo chown -R "${USER}:${WEB_GROUP}" storage bootstrap/cache 2>/dev/null \
    || chmod -R 775 storage bootstrap/cache
# (chown needs sudo. If sudo isn't allowed for stcav, the chmod fallback
#  keeps things working as long as the initial setup-vps.sh got ownership
#  right.)

# ── 8. Done ────────────────────────────────────────────────────────────────
SHA=$(git rev-parse --short HEAD)
SUBJECT=$(git log -1 --pretty=%s)
echo ""
echo "==> Deployed ${SHA} — \"${SUBJECT}\""
echo "==> Done at $(date -Iseconds)"
