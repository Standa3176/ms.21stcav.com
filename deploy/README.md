# Deployment — ms.21stcav.com

Operator runbook for getting the MeetingStore Ops Laravel app live on the
existing 21stcav VPS as a sibling install to `rams.21stcav.com`.

## TL;DR

```
LOCAL:  git push origin main
VPS:    sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh
```

That's the daily workflow once the **one-time setup** below is done.

## Layout

```
/home/stcav/
├── rams.21stcav.com/          ← existing RAMS install (untouched)
└── ms.21stcav.com/            ← new MeetingStore Ops install
    ├── deploy/
    │   ├── README.md          ← this file
    │   ├── deploy.sh          ← daily deploy (idempotent)
    │   ├── setup-vps.sh       ← one-time bootstrap
    │   ├── nginx.conf         ← vhost — copy to /etc/nginx/conf.d/
    │   ├── supervisor.conf    ← Horizon worker — copy to /etc/supervisord.d/
    │   ├── crontab.txt        ← add these lines to `crontab -e` for stcav
    │   └── .env.production.example
    ├── app/  config/  routes/  ...
    └── .env                   ← created from .env.production.example, NEVER committed
```

## One-time setup (≈45 min)

Pre-flight checks (run as root, confirm before continuing):

```bash
# 1. PHP 8.2+ — RAMS already uses this VPS so it's likely set; confirm:
php -v
# Expect: PHP 8.2 or higher

# 2. MySQL — same:
mysql --version

# 3. Composer — same:
composer --version

# 4. Redis (THIS MAY NOT BE INSTALLED — RAMS doesn't use it):
redis-cli ping
# Expect: PONG. If "command not found":
#   dnf install redis -y           (RHEL/AlmaLinux)
#   apt install redis-server -y    (Debian/Ubuntu)
#   systemctl enable --now redis

# 5. Supervisor (probably already there for any queue worker):
which supervisorctl
# If missing: dnf install supervisor -y && systemctl enable --now supervisord

# 6. DNS — ms.21stcav.com → VPS public IP (set in your DNS panel; A record).
dig +short ms.21stcav.com
# Expect: the same IP as rams.21stcav.com
```

Then bootstrap the install:

```bash
# 1. Clone (as stcav, not root):
sudo -u stcav -i
cd /home/stcav
git clone https://github.com/Standa3176/ms.21stcav.com.git

# 2. Run the one-time setup:
cd ms.21stcav.com
./deploy/setup-vps.sh

# 3. Edit .env with REAL credentials:
nano .env
# Fill in: APP_KEY (generated for you), DB_*, REDIS_*, MAIL_*, etc.
# Most integration creds (Anthropic / Bitrix / Woo / Supplier) live in the
# `integration_credentials` DB table managed via /admin — set them via UI
# rather than .env (Phase 09.1).

# 4. Wire nginx (as root):
exit       # back to root
cp /home/stcav/ms.21stcav.com/deploy/nginx.conf /etc/nginx/conf.d/ms.21stcav.com.conf
nginx -t && systemctl reload nginx

# 5. SSL (as root):
certbot --nginx -d ms.21stcav.com

# 6. Wire Horizon supervisor (as root):
cp /home/stcav/ms.21stcav.com/deploy/supervisor.conf /etc/supervisord.d/meetingstore-horizon.ini
supervisorctl reread && supervisorctl update
supervisorctl status meetingstore-horizon
# Expect: RUNNING

# 7. Wire cron (as stcav):
sudo -u stcav crontab -e
# Paste the contents of deploy/crontab.txt
# Verify with: sudo -u stcav crontab -l
```

That's it — first deploy is live.

## Daily deploy workflow

```
# LOCAL (Windows PowerShell):
cd "C:\Users\sonny.tanda\Documents\1 - Laravel Projects\meetingstore-ops-app"
git add -A
git commit -m "your change"
git push origin main

# REMOTE (SSH to VPS):
sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh
```

`deploy.sh` is idempotent and safe to re-run. It pulls main, runs composer
install, migrates, rebuilds caches, and bumps Horizon (supervisor restarts it).

To make this a one-liner from local, add to your PowerShell `$PROFILE`:

```powershell
function deploy-meetingstore {
    git push origin main
    if ($LASTEXITCODE -eq 0) {
        ssh stcav@rams.21stcav.com '/home/stcav/ms.21stcav.com/deploy/deploy.sh'
    }
}
```

Then just type `deploy-meetingstore` after each commit.

## Rollback

```bash
sudo -u stcav -i
cd /home/stcav/ms.21stcav.com
git log --oneline -5                     # find the last good commit
git checkout <good-commit-sha>
./deploy/deploy.sh
```

Note: rolls forward your code, NOT your DB. If a migration changed schema,
you also need a `migrate:rollback` (be careful — that drops columns).

## Where prod secrets live

- **`.env`** — server-wide stuff: APP_KEY, DB connection, Redis, Mail
- **`integration_credentials` DB table** — Anthropic, Woo, Bitrix, Supplier,
  Langfuse keys; managed via `/admin/integration-credentials` UI (Phase 09.1)
- **`alert_recipients` DB table** — who gets sync digests, weekly digests,
  competitor alerts, etc.; managed via `/admin/alert-recipients`

`integration_credentials` is encrypted-at-rest in the DB — even an
unauthorised file read of the DB doesn't leak the keys.

## Troubleshooting

**Crons not firing?** Check the OS-level entry first:
```bash
sudo -u stcav crontab -l | grep schedule:run
# Must contain: * * * * * cd /home/stcav/ms.21stcav.com && php artisan schedule:run >> /dev/null 2>&1
```

**Horizon not running?** `supervisorctl status meetingstore-horizon`. If
DOWN: `tail -100 /var/log/meetingstore-horizon.log`.

**Permissions errors after deploy?** The deploy script chowns to
stcav:nginx (or stcav:apache). If your web user is different, edit
`deploy/deploy.sh` and `deploy/nginx.conf`.

**500 errors after migrate?** `tail -100 storage/logs/laravel.log`. Common:
missing env var, `php artisan config:cache` was skipped, or a model points
at a column the migration hasn't created yet (re-run deploy.sh).
