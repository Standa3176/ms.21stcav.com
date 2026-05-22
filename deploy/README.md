# Deployment — ms.21stcav.com

Operator runbook for the MeetingStore Ops Laravel app on the 21stcav VPS, a
sibling install to `rams.21stcav.com`.

## TL;DR

```
LOCAL:  git push origin main
VPS:    sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh
```

That's the daily workflow once the **one-time setup** below is done.

## How this box actually serves the app (read this first)

The VPS runs **CentOS Web Panel (CWP) on CloudLinux**. That changes the rules
versus a vanilla nginx + php-fpm box, and the differences are the source of
every gotcha below:

- **Web path:** nginx (front) → **per-user CloudLinux php-fpm pool socket**
  `/opt/alt/php-fpm83/usr/var/sockets/stcav.sock`. That pool runs as user
  **`stcav`**, which is what makes `storage/` writes work. Do **not** point it
  at the shared `www` pool on `127.0.0.1:9000` — that runs as `nginx` and
  can't write `storage/` (every page that renders a view 500s with a
  `tempnam()` error, and the log stays empty).
- **vhosts are hand-maintained** in `/etc/nginx/conf.d/` (NOT
  `/etc/nginx/conf.d/vhosts/`). CWP regenerates the `vhosts/` directory on
  rebuilds; files placed directly in `conf.d/` survive and take precedence:
  - `ms.21stcav.com.conf`     → `:80`  (301 → HTTPS, keeps ACME path open)
  - `ms.21stcav.com.ssl.conf` → `:443` (the app)
  Both are in `deploy/nginx.conf` in this repo.
- **SSL = certbot `certonly --webroot`** (Let's Encrypt). Do **NOT** use
  `certbot --nginx` (its plugin fights CWP) and do **NOT** rely on CWP AutoSSL
  (its ACME challenge dir isn't what our `:80` vhost serves).
- **Document root** is `…/ms.21stcav.com/public` in the nginx vhosts. The
  CWP-generated apache vhost points at the repo root and is shadowed/unused.

## One-time setup (≈45 min)

Run as root unless noted. Replace `46.202.141.242` with the VPS IP if it ever
changes.

```bash
# 1. Pre-flight — confirm the toolchain RAMS already uses
php -v            # 8.2+ (stcav's CloudLinux selector pool is 8.3)
composer --version
redis-cli ping    # PONG — install if missing: dnf install redis -y; systemctl enable --now redis
which supervisorctl
dig +short ms.21stcav.com   # must resolve to the VPS IP

# 2. Clone as stcav (NOT root)
sudo -u stcav -i
cd /home/stcav
git clone https://github.com/Standa3176/ms.21stcav.com.git
cd ms.21stcav.com
cp deploy/.env.production.example .env
php artisan key:generate          # then edit .env: DB_*, REDIS_*, MAIL_*, APP_URL=https://ms.21stcav.com, APP_DEBUG=false
composer install --no-dev --optimize-autoloader
php artisan filament:assets       # publish panel CSS/JS (see deploy.sh note)
php artisan migrate --force
php artisan db:seed --force        # seeds roles + permissions + reference data
exit                               # back to root

# 3. Create the first admin user (the seeder creates admin@meetingstore.co.uk
#    with the weak password 'password' — create your own instead, or rotate it).
#    Roles are already seeded, so just create the user and assign 'admin':
sudo -u stcav php /home/stcav/ms.21stcav.com/artisan tinker --execute="\$u=\App\Models\User::firstOrCreate(['email'=>'admin@meetingstore.co.uk'],['name'=>'Ops Admin']); \$u->password='A-STRONG-PASSWORD'; \$u->email_verified_at=now(); \$u->save(); \$u->assignRole('admin'); echo 'admin ready';"

# 4. Let the nginx user reach the per-user fpm socket, then wire the vhosts.
#    The socket is root:nobody 0660, so nginx must be in group nobody:
usermod -aG nobody nginx
# split deploy/nginx.conf into the two files (or copy the relevant blocks):
#   /etc/nginx/conf.d/ms.21stcav.com.conf       (the :80 server block)
#   /etc/nginx/conf.d/ms.21stcav.com.ssl.conf   (the :443 server block)
nginx -t && systemctl restart nginx   # restart (not reload) so the group change applies

# 5. SSL — webroot issuance (auto-renews via certbot's timer + deploy-hook):
certbot certonly --webroot -w /home/stcav/ms.21stcav.com/public \
  -d ms.21stcav.com --agree-tos -m webmaster@21stcav.com \
  --deploy-hook "systemctl reload nginx"
systemctl reload nginx

# 6. Horizon supervisor (as root):
cp /home/stcav/ms.21stcav.com/deploy/supervisor.conf /etc/supervisord.d/meetingstore-horizon.ini
supervisorctl reread && supervisorctl update
supervisorctl status meetingstore-horizon    # RUNNING

# 7. Cron (as stcav):
sudo -u stcav crontab -e   # paste deploy/crontab.txt ; verify: crontab -l
```

Verify: `https://ms.21stcav.com/admin/login` shows a padlock + the Filament
login, and `http://…` 301-redirects to it.

## Daily deploy workflow

```
# LOCAL (Windows PowerShell):
git push origin main

# REMOTE (SSH to VPS):
sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh
```

`deploy.sh` is idempotent. It pulls main, runs composer install, **publishes
Filament assets**, migrates, rebuilds caches, and bounces Horizon. The
`filament:assets` step is load-bearing — without it a Filament version bump
leaves the panel/dashboard styled by stale CSS.

Optional one-liner for your PowerShell `$PROFILE`:

```powershell
function deploy-meetingstore {
    git push origin main
    if ($LASTEXITCODE -eq 0) { ssh stcav@ms.21stcav.com '/home/stcav/ms.21stcav.com/deploy/deploy.sh' }
}
```

## Rollback

```bash
sudo -u stcav -i
cd /home/stcav/ms.21stcav.com
git log --oneline -5
git checkout <good-commit-sha>
./deploy/deploy.sh
```

Rolls forward code, NOT the DB. If a migration changed schema you also need a
careful `migrate:rollback`.

## Where prod secrets live

- **`.env`** — APP_KEY, DB, Redis, Mail, `APP_URL=https://ms.21stcav.com`,
  `APP_DEBUG=false`. Never committed.
- **`integration_credentials` DB table** — Anthropic, Woo, Bitrix, Supplier,
  Langfuse keys; managed via `/admin/integration-credentials`. Encrypted at rest.
- **`alert_recipients` DB table** — digest/alert distribution; via `/admin/alert-recipients`.

## Troubleshooting

**HTTPS shows the CWP test page / cert warning** — there's no `:443` vhost or no
cert for the domain, so it falls to CWP's default. Confirm
`/etc/nginx/conf.d/ms.21stcav.com.ssl.conf` exists and
`/etc/letsencrypt/live/ms.21stcav.com/` has `fullchain.pem`; re-issue with the
`certonly --webroot` command above.

**500 `tempnam(): file created in the system's temporary directory`** — PHP is
running as the wrong user and can't write `storage/`. Check the `:9000`/socket
the vhost uses: `ss -ltnp | grep 9000` and `ps -eo user,cmd | grep 'php-fpm: pool'`.
The `fastcgi_pass` must point at the **stcav** per-user socket, not the `nginx`
`www` pool. Confirm `nginx` is in group `nobody` (`id nginx`).

**502 Bad Gateway** — nginx can't open the per-user socket. `usermod -aG nobody
nginx && systemctl restart nginx`. If stcav's PHP version changed in the
CloudLinux selector, the socket path moved (`php-fpm83` → other) — update
`fastcgi_pass` in both vhosts.

**Login form returns 405 / dashboard widgets look broken (tall cards, text
wrapping narrow)** — stale Filament assets. Run `php artisan filament:assets`
(now in deploy.sh) + `php artisan view:clear`, then **hard-refresh** the browser
(the CSS is cached `immutable` for 30 days, so a normal reload keeps the stale
file). A greedy `location ~* \.js$ { ... =404; }` also breaks Livewire's
`/livewire/livewire.js` — the vhost falls non-file `.js` through to PHP instead.

**Can't log in / "no such user"** — a fresh DB has no users. Run the admin-create
tinker one-liner from step 3.

**500 after migrate** — `tail -100 storage/logs/laravel.log`. Usually a missing
env var or `config:cache` skew; re-run `deploy.sh`.

**Horizon not running** — `supervisorctl status meetingstore-horizon`; logs at
`/var/log/meetingstore-horizon.log`.
