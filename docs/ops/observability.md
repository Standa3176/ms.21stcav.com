# Agent Observability — self-hosted Langfuse runbook

**Owner:** Ops (alongside `cutover-handover.md` + `shield-regeneration.md`)
**Phase:** 8 Plan 02 (AGNT-08)
**Stack:** `docker-compose.langfuse.yml` + nginx reverse proxy at `lf.ops.meetingstore.co.uk`

## Overview

Phase 8 ships a self-hosted Langfuse stack on the same VPS that already runs
`ops.meetingstore.co.uk`. Self-hosting is the v2.0 default for two reasons:

1. **EU data residency** — agent traces include margin numbers, customer free-text
   chatbot inputs (Phase 14), and other commercially-sensitive payloads we
   don't want flowing through `cloud.langfuse.com` (US-region by default).
2. **Bus-factor mitigation** — the `mliviu79/laravel-langfuse-prism` shim has
   ~115 installs and a single maintainer. If it breaks, ops can swap to the
   custom-OTel fallback (see §7) without service migration.

The stack is **six containers** (langfuse-web, langfuse-worker, clickhouse,
postgres, redis, minio). Every host port binds to `127.0.0.1` only; the public
ingress is **nginx → admin HTTP basic auth → 127.0.0.1:3000**.

The mliviu79 shim auto-instruments Prism HTTP traffic and pushes the Langfuse
trace_id onto `Illuminate\Support\Facades\Context`; `ClaudeClient` reads it back
into `AgentRun.langfuse_trace_id` (Plan 04 wires the AgentRun row).

## 1. Bootstrap (one-time)

```bash
# 1. Provision the env file (NEVER commit secrets)
cd /opt/meetingstore-ops
cp .env.langfuse.example .env.langfuse        # operator creates the example separately
chmod 600 .env.langfuse

# 2. Generate 6 secrets — paste into .env.langfuse
for var in NEXTAUTH_SECRET SALT ENCRYPTION_KEY POSTGRES_PASSWORD CLICKHOUSE_PASSWORD REDIS_AUTH MINIO_ROOT_PASSWORD; do
  echo "$var=$(openssl rand -hex 32)"
done

# 3. NEXTAUTH_URL points at the public reverse-proxy URL
echo "NEXTAUTH_URL=https://lf.ops.meetingstore.co.uk" >> .env.langfuse

# 4. Bring up the stack
docker compose -f docker-compose.langfuse.yml --env-file .env.langfuse up -d

# 5. Wait for healthchecks
docker compose -f docker-compose.langfuse.yml ps      # all 6 should report (healthy)

# 6. Configure nginx — minimal site block:
#
#    server {
#        server_name lf.ops.meetingstore.co.uk;
#        listen 443 ssl http2;       # certbot manages the cert
#        auth_basic "MeetingStore Ops — Langfuse";
#        auth_basic_user_file /etc/nginx/.langfuse-htpasswd;
#        client_max_body_size 50M;
#        location / {
#            proxy_pass http://127.0.0.1:3000;
#            proxy_set_header Host $host;
#            proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
#            proxy_set_header X-Forwarded-Proto $scheme;
#        }
#    }
#
#    Generate the htpasswd: htpasswd -c /etc/nginx/.langfuse-htpasswd ops

# 7. Visit https://lf.ops.meetingstore.co.uk → create first user (admin)
#    Org name: 21st Century AV / MeetingStore Ops
#    Project:  meetingstore-ops

# 8. Disable open signup AFTER the admin user is created (T-08-02-04 EoP):
#    Edit .env.langfuse — set AUTH_DISABLE_SIGNUP=true
docker compose -f docker-compose.langfuse.yml --env-file .env.langfuse up -d langfuse-web

# 9. In the Langfuse UI: Settings → API Keys → Create new key pair
#    Paste pk-lf-... + sk-lf-... into MeetingStore Ops .env:
#      LANGFUSE_PUBLIC_KEY=pk-lf-...
#      LANGFUSE_SECRET_KEY=sk-lf-...

# 10. Restart Horizon so the shim picks up the new keys
php artisan horizon:terminate
```

## 2. Day-to-day operations

| Action | Command |
|--------|---------|
| Start stack | `docker compose -f docker-compose.langfuse.yml --env-file .env.langfuse up -d` |
| Stop (preserve data) | `docker compose -f docker-compose.langfuse.yml down` |
| Tail logs | `docker compose -f docker-compose.langfuse.yml logs -f langfuse-web langfuse-worker` |
| Container health | `docker compose -f docker-compose.langfuse.yml ps` |
| Restart langfuse-web only | `docker compose -f docker-compose.langfuse.yml restart langfuse-web` |
| Disk usage | `docker system df -v \| grep langfuse_` |
| **Wipe all data (DESTRUCTIVE)** | `docker compose -f docker-compose.langfuse.yml down -v` |

Containers all use `restart: unless-stopped` so a VPS reboot brings them back automatically.

## 3. Retention policy

**Default:** indefinite per project. Configured **in the UI** at Project Settings →
Data Retention. Minimum allowed: 3 days.

**Phase 8 default per CONTEXT D-08 / Claude's Discretion:**

- Trace data: **90 days** (covers the Filament AgentRun deep-link window — runs
  older than 90d see a "trace expired" badge instead)
- Cost-aggregate retention: **1 year** (lighter table, fine to keep longer)

Programmatic retention setting depends on Langfuse v3's still-evolving public
API; runbook updates land here once the endpoint stabilises (Open Q1 from
RESEARCH §Open Questions). For now: configure in UI post-bootstrap.

## 4. Backup posture (operator-managed)

Volumes to back up:

| Volume | Backup method | Cadence |
|--------|---------------|---------|
| `langfuse_postgres_data` | `docker compose exec postgres pg_dump -U postgres postgres \| gzip > /backup/langfuse-postgres-$(date +%Y%m%d).sql.gz` | weekly |
| `langfuse_clickhouse_data` | volume snapshot via VPS provider (data is already ClickHouse-compressed) | weekly |
| `langfuse_minio_data` | volume snapshot — blob storage for events / media / batch exports | weekly |
| `langfuse_redis_data` | NOT backed up — internal queue, regenerable | n/a |

Restore: stop stack, replace volume contents, `up -d`.

Postgres dumps are the only **structurally-essential** restore path; ClickHouse
volume snapshots cover trace history but the cost-aggregate tables in Postgres
are the authoritative billing record.

## 5. Disk thresholds

- **Initial allocation:** 2 GB (acceptable per CONTEXT)
- **Alarm:** total stack volume size > 5 GB → operator action required (review retention, run prune)
- **Hard ceiling:** 10 GB → consider promoting to dedicated VPS partition

**Capacity projection** (90-day retention, 100 traces/day average):

```
9000 stored × ~50 KB avg (trace + spans + events) = ~450 MB ClickHouse
+ Postgres aggregates    = ~50 MB
+ MinIO event blobs      = ~200 MB
─────────────────────────────────
total ~700 MB at steady state
```

The 5 GB alarm gives ~6× headroom.

## 6. Custom-OTel fallback path (shim contingency)

**Trigger:** mliviu79/laravel-langfuse-prism shim breaks (single maintainer,
115 installs — flagged risk in RESEARCH).

**Switchover procedure:**

1. Edit `config/agents.php` — uncomment the `'custom_otel'` block (Plan 02
   ships the block already commented-out so the swap is one-uncomment).
2. Set `AGENTS_OBSERVABILITY_DRIVER=custom-otel` in `.env`.
3. `php artisan horizon:terminate` to recycle the workers with the new driver.
4. Verify a fresh agent run produces an `integration_events` row with
   `channel=langfuse-otel-fallback` instead of going through the shim.

The custom-OTel exporter (~150 LOC, deferred to v2.1 if implementation needed)
logs trace JSON to a `langfuse-otel-fallback` log channel and POSTs it directly
to Langfuse's OTel ingest endpoint (`/api/public/otel/v1/traces`). Trade-off:
loses the shim's automatic span enrichment but keeps trace_id propagation.

**Swap-back when the shim is fixed:** reverse steps 1-3.

## 7. Trace-id Context plumbing (Q2 RETIREMENT)

The mliviu79 shim populates `Context::get('langfuse_trace_id')` after each
Prism call via a Laravel HTTP middleware. `ClaudeClient::extractLangfuseTraceId()`
reads this back and stores it on `AgentRun.langfuse_trace_id` for the deep-link
from Filament `AgentRunResource` → Langfuse trace UI.

**Open Question Q2 (RESOLVED):** the path is verified by Plan 02 Task 2
integration test (`tests/Feature/Agents/ClaudeClientTest.php` — "Q2 retirement"
case). The test seeds `Context::add('langfuse_trace_id', 'test-trace-12345')`
before invoking `Prism::fake()` and asserts the value round-trips into
`ClaudeResponse->langfuseTraceId`.

**Fallback path if the shim fails to populate Context:** read the trace_id
from the Prism HTTP response's `X-Langfuse-Trace-Id` header via a Prism HTTP
middleware. Pseudocode:

```php
Prism::middleware(function ($request, $next) {
    $response = $next($request);
    if ($traceId = $response->header('X-Langfuse-Trace-Id')) {
        Context::add('langfuse_trace_id', $traceId);
    }
    return $response;
});
```

This middleware is NOT wired in v2.0 — the shim's Context push is the primary
path. Wire only if the shim breaks AND the custom-OTel swap is too disruptive.

## 8. Alert thresholds (operator wires post-deploy)

These are operator decisions — wire into your existing monitoring stack
(Sentry / Pulse / VPS provider alerts) post-bootstrap:

| Signal | Suggested threshold | Action |
|--------|---------------------|--------|
| VPS data-volume disk usage | > 80% | Review retention; run `agents:prune-archive` if AgentRuns are oversized |
| `langfuse-web` container restart count | > 2 in 1h | Investigate logs; check Postgres connectivity |
| Healthcheck failures (any container) | > 3 in 5 min | Page ops |
| Trace ingestion latency | > 5s langfuse-worker queue backlog | Review ClickHouse insert rate; consider ClickHouse instance bump |
| Public ingress 401s | > 50/min | Probable basic-auth brute force — rotate `htpasswd` + alert ops |
| Anthropic API spend (per `agents.monthly_ceiling_pence`) | > 80% of cap | BudgetGuard fires its own kill-switch suggestion at 100%; this is the early-warning |

## 9. Threat-model anchors

These mitigations address the Plan 02 STRIDE register:

- **T-08-02-01** (ANTHROPIC_API_KEY leakage): IntegrationLogger redacts the
  `authorization` header before persistence; the API key never appears in
  AgentRun rows or Langfuse traces.
- **T-08-02-02** (Spoofing — Langfuse public ingress): nginx + admin HTTP basic
  auth at `lf.ops.meetingstore.co.uk`; HTTPS via certbot; no anonymous access.
- **T-08-02-04** (EoP — first-user-becomes-org-owner): bootstrap step 8 sets
  `AUTH_DISABLE_SIGNUP=true` after the admin user is created.
- **T-08-02-05** (Customer free-text in chatbot inputs): 90-day retention
  (D-08); Plan 05 ships `agents:gdpr-purge-langfuse` for GDPR erasure flagging;
  `AgentRun` row stores structured summary only (no raw payload).
- **T-08-02-06** (docker-compose port binding): all ports prefixed
  `127.0.0.1:` — verified by Task 3 architectural test (grep against `0.0.0.0:`
  in the compose file fails the build if found).

## GDPR purge of Langfuse traces (Q1 RESOLVED)

**Phase 8 Plan 05 Task 2** — Open Question Q1 from `08-RESEARCH.md` is RESOLVED:
the deployed Langfuse OSS image's per-trace deletion API is undocumented as of
research date (2026-04-24). Phase 8 ships `agents:gdpr-purge-langfuse` as a
STUB with a v2.1 TODO; below documents the upgrade paths.

### v2.0 stub behaviour

The command currently logs the trace IDs that would be flagged for deletion to
`storage/logs/laravel.log` with key `agents:gdpr-purge-langfuse: flagged for
deletion`. No upstream API call is made. Operators run it after every
`gdpr:erase-bitrix-customer` invocation to capture the trace-id list for
manual follow-up:

```bash
php artisan agents:gdpr-purge-langfuse \
    --customer-email=alice@example.com \
    --gdpr-log-ulid={uuid-from-gdpr_erasure_log} \
    --dry-run
```

### v2.1 upgrade path (TODO-V21-LANGFUSE-API)

Three options under evaluation (in priority order):

1. **Langfuse REST API** — inspect the deployed image's actual endpoint:
   - `PATCH /api/public/projects/{id}` (retention override)
   - `DELETE /api/public/traces/{id}` (when stable)
   - Validate against the `lf.ops.meetingstore.co.uk` deploy with a probe
     request before swapping the stub command's `Log::info` for a Guzzle
     POST/DELETE call.

2. **Direct ClickHouse SQL** — MEDIUM-confidence fallback if the REST surface
   never stabilises:

   ```bash
   docker compose -f docker-compose.langfuse.yml exec clickhouse \
       clickhouse-client --query \
       "DELETE FROM traces WHERE id IN ({list-of-trace-ids})"
   ```

   Caveats: bypasses Langfuse's own audit trail; requires container shell
   access (only ops with VPS root can run); CASCADES into observations table
   via FK so trace summaries vanish too.

3. **Manual UI workaround** — for one-off GDPR requests, ops navigates to the
   Langfuse trace view at `https://lf.ops.meetingstore.co.uk/project/{id}/traces/{trace_id}`
   and clicks the delete icon. Tedious for >5 traces; acceptable for the
   v2.0 GDPR-erasure-frequency baseline (estimated ≤ 1 customer/quarter).

### Current operator runbook (until v2.1 lands)

After running `gdpr:erase-bitrix-customer --email=alice@example.com`:

1. Run `agents:gdpr-purge-langfuse --customer-email=alice@example.com
   --gdpr-log-ulid={correlation_id from above command}` to capture the
   trace-ID list.
2. Inspect `storage/logs/laravel.log` for the `flagged for deletion` entries.
3. For each trace_id, navigate to the Langfuse UI and delete by hand, OR
   if more than 5 traces, use the ClickHouse SQL path (option 2 above).
4. Document the manual cleanup in `gdpr_erasure_log.notes` for the
   corresponding row (operator-facing audit trail).

The stub command writes the list to logs precisely so this manual cleanup is
traceable; v2.1 closes the loop.

## Appendix: Quick links

- Prism: <https://prismphp.com>
- Langfuse self-hosted Docker: <https://langfuse.com/self-hosting/docker-compose>
- mliviu79/laravel-langfuse-prism: <https://packagist.org/packages/mliviu79/laravel-langfuse-prism>
- Phase 8 RESEARCH §Self-Hosted Langfuse Docker Deployment (lines 786-855)
- Phase 8 CONTEXT §Claude's Discretion (Langfuse Docker placement)
