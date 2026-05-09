#!/usr/bin/env bash
#
# One-time VPS bootstrap — run after the initial git clone.
#
# Usage:
#   sudo -u stcav -i
#   cd /home/stcav/ms.21stcav.com
#   ./deploy/setup-vps.sh
#
# What it does:
#   1. composer install
#   2. .env scaffold from .env.production.example (won't overwrite if exists)
#   3. APP_KEY generation
#   4. Storage symlink
#   5. Database creation hint (we don't auto-CREATE DATABASE; manual MySQL step)
#   6. Migrate + seed the default pricing tiers (Phase 3 — port of legacy
#      WP plugin's 35/28/22% margin tiers)
#   7. shield:generate for Filament resource permissions
#   8. Print next-step checklist (nginx, supervisor, cron)
#

set -euo pipefail

PROJECT_ROOT="/home/stcav/ms.21stcav.com"
cd "$PROJECT_ROOT"

if [[ "$(id -un)" == "root" ]]; then
    echo "ERROR: do not run as root — 'sudo -u stcav -i' first."
    exit 1
fi

echo "==> setup-vps.sh — one-time bootstrap"
echo ""

# ── 1. Composer install ────────────────────────────────────────────────────
echo "==> composer install"
composer install --no-dev --optimize-autoloader --no-interaction

# ── 2. .env scaffold (preserve existing) ──────────────────────────────────
if [[ -f ".env" ]]; then
    echo "==> .env exists — leaving it alone"
else
    echo "==> .env does not exist — copying .env.production.example"
    cp deploy/.env.production.example .env
    echo ""
    echo "    ┌─────────────────────────────────────────────────────────────┐"
    echo "    │  EDIT .env NOW with real credentials before continuing:    │"
    echo "    │    nano .env                                               │"
    echo "    │  Required:  DB_*, REDIS_*, MAIL_*                          │"
    echo "    │  Optional (managed via Filament admin Phase 09.1):         │"
    echo "    │    Anthropic, Woo, Bitrix, Supplier, Langfuse              │"
    echo "    └─────────────────────────────────────────────────────────────┘"
    echo ""
    read -p "Press Enter once .env is filled in to continue..."
fi

# ── 3. APP_KEY ────────────────────────────────────────────────────────────
if grep -q "^APP_KEY=base64:" .env 2>/dev/null; then
    echo "==> APP_KEY already set"
else
    echo "==> php artisan key:generate"
    php artisan key:generate --force
fi

# ── 4. Storage symlink ────────────────────────────────────────────────────
echo "==> php artisan storage:link"
php artisan storage:link || true

# ── 5. DB sanity ──────────────────────────────────────────────────────────
echo "==> Verifying DB connection (php artisan db:show)"
if ! php artisan db:show --json >/dev/null 2>&1; then
    echo ""
    echo "    ┌─────────────────────────────────────────────────────────────┐"
    echo "    │  DB connection failed.                                      │"
    echo "    │  Create the database manually then re-run this script:     │"
    echo "    │    mysql -u root -p                                        │"
    echo "    │    > CREATE DATABASE meetingstore_ops                      │"
    echo "    │      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;     │"
    echo "    │    > CREATE USER 'meetingstore'@'localhost'                │"
    echo "    │      IDENTIFIED BY '<strong-password>';                    │"
    echo "    │    > GRANT ALL ON meetingstore_ops.*                       │"
    echo "    │      TO 'meetingstore'@'localhost';                        │"
    echo "    │    > FLUSH PRIVILEGES;                                     │"
    echo "    │  Then update .env DB_DATABASE/DB_USERNAME/DB_PASSWORD.     │"
    echo "    └─────────────────────────────────────────────────────────────┘"
    exit 1
fi
echo "    OK — DB reachable"

# ── 6. Migrate + seed default tiers ──────────────────────────────────────
echo "==> php artisan migrate --force"
php artisan migrate --force

echo "==> Seed default pricing tiers (Phase 3 — legacy 35/28/22% margins)"
php artisan db:seed --class="Database\\Seeders\\Phase3\\DefaultPricingTierSeeder" --force

echo "==> Seed roles & permissions"
php artisan db:seed --class="Database\\Seeders\\RolePermissionSeeder" --force || true

# ── 7. Filament Shield (Resource policies) ───────────────────────────────
if php artisan list 2>/dev/null | grep -q "shield:safe-regenerate"; then
    echo "==> php artisan shield:safe-regenerate"
    php artisan shield:safe-regenerate --force || echo "  (shield regenerate failed — re-run manually after creating an admin user)"
else
    echo "==> php artisan shield:generate (fallback)"
    php artisan shield:generate --all --panel=admin || true
fi

# ── 8. Caches ──────────────────────────────────────────────────────────────
echo "==> caches"
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# ── 9. Done ────────────────────────────────────────────────────────────────
echo ""
echo "════════════════════════════════════════════════════════════════════"
echo "  setup-vps.sh complete"
echo ""
echo "  Next (as root):"
echo "    1. cp deploy/nginx.conf /etc/nginx/conf.d/ms.21stcav.com.conf"
echo "       nginx -t && systemctl reload nginx"
echo "    2. certbot --nginx -d ms.21stcav.com"
echo "    3. cp deploy/supervisor.conf /etc/supervisord.d/meetingstore-horizon.ini"
echo "       supervisorctl reread && supervisorctl update"
echo ""
echo "  Next (as stcav):"
echo "    4. crontab -e   # paste contents of deploy/crontab.txt"
echo "    5. php artisan tinker"
echo "       >>> User::factory()->create(['email'=>'you@example.com'])->assignRole('admin')"
echo ""
echo "  Then visit: https://ms.21stcav.com/admin"
echo "════════════════════════════════════════════════════════════════════"
