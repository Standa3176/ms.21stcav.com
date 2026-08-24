---
quick_id: 260824-vkc
slug: woo-create-resume-after-throttle
date: 2026-08-24
status: complete
---

# Summary — a throttled Woo create now resumes instead of stranding the product

## The gap this closes

`260822-rmo` fixed the Woo write throttle for the review/approve path
(`PublishProductJob`) and added a **preflight** deferral to `CreateWooProductJob`.
The preflight only guards the job's *entry*. `handle()` then creates the local
`Product` row **before** the Woo POST, and that POST stayed unguarded — so when
the write window closed in the gap between the two:

1. local `Product` row created ([CreateWooProductJob.php:162](../../../app/Domain/ProductAutoCreate/Jobs/CreateWooProductJob.php))
2. `$woo->post('/products')` throws `WooWriteThrottleException`
3. the retry hits the AUTO-08 duplicate gate, which matches the job's **own**
   half-created row via `ProductMatcher::existsNormalised()`, records
   `reason: 'duplicate'`, and returns
4. product stays local-only **forever**, `woo_product_id = null`

That is exactly the symptom reported on 2026-08-23 — *"under Suggestions I have
sent products to be created directly in the Woo store but they do not appear"*.

Step 3 also broke **Replay**. `AutoCreateRetryApplier` re-dispatches this same
job, so *every* failure occurring after the local create replayed straight into
`duplicate`. Replay only ever worked for failures happening before the create
(`supplier_not_found`, taxonomy unresolved).

Found by review after the 260822-rmo work shipped: the fix was carried to the
sibling write path but not to the job's own POST. Same class of miss as the
`push-status-to-woo` pacing gap earlier the same day.

## What changed

All in `CreateWooProductJob`. The three parts are useless individually — the
guard is only safe *because* the gate can resume, and the gate can only resume
*because* the row is reused.

**1. A resume-aware duplicate gate.** `findResumableOrphan()` distinguishes
"someone already stocks this part" from "this is my own row from a run that died
after creating it". Deliberately narrow — a row qualifies only when all three
hold:

- `woo_product_id IS NULL` — anything with an id is live, and re-POSTing it
  really would duplicate it
- it came from the pipeline, via the canonical `autoCreated()` scope (260606-mx9:
  `whereNotNull` is vacuous because the column defaults to `'manual'`, and
  `AutoCreatedPredicateTest` fails CI if that predicate returns)
- **no alternative supplier code maps this SKU to a different product**

That third clause is what keeps `260823-clp` intact. If an alias says the part is
already stocked under another product, the orphan is itself the mistake and
resuming would put a second listing of one physical part on the storefront.
Refuse, and let the duplicate gate do its job.

**2. The orphan row is reused, not duplicated.** Compiled content is regenerated
from supplier data on every run, so a wholesale re-fill would silently revert
copy an operator edited in the review inbox — and a resumed row has by definition
been sitting in that inbox, visible and editable. `resumeOrphan()` fills blanks
only, always refreshes `buy_price` (supplier cost genuinely moves between the
failed run and the retry), and leaves every populated operator-facing field
alone. The Woo payload now reads from `$product` rather than `$compiled`, so what
gets pushed matches the row; for a fresh create the two are identical, so the
normal path is byte-for-byte unchanged.

A resumed row also keeps a brand/category the operator assigned by hand. Without
that carry-over, automatic resolution returning `null` would wipe the assignment
and re-park the row — which is precisely what Replay is normally invoked to act
on.

**3. The POST is guarded.** `WooWriteThrottleException` → `releaseForWooThrottle`,
a deferral rather than a consumed attempt.

## Tests

8 cases (39 assertions), all green — `CreateWooProductResumeTest`:

throttled POST defers and the retry completes the job (the case the review
specified) · a resume reuses the same row, never forks a second · three
consecutive throttles still converge · a part genuinely already on Woo is still
refused · a legacy `manual` row is still refused however incomplete it looks · an
alias pointing at another product blocks the resume · operator-edited copy
survives and is what gets pushed · a hand-assigned brand/category survives and
un-parks the row.

Regression sweep: **483 passed, 0 failed** across `tests/Feature/ProductAutoCreate`,
`WooWriteThrottleReleaseTest`, `SupplierSkuAliasTest` and all of
`tests/Architecture`. deptrac 0 violations. Pint pass.

The existing AUTO-08 duplicate test stays green for a non-obvious reason worth
recording: `ProductFactory` assigns a real `woo_product_id` by default, so its
fixture is a live listing rather than an orphan. `LEGACY-01` in the new suite
covers the case the factory does *not* produce.

## Not fixed by this

**Rows already stranded are not retro-repaired.** The fix changes what happens on
the next run, not what happened on previous ones. Existing orphans are
recoverable three ways, all of which now work:

- Approve from the review inbox — `PublishProductJob` path B creates on Woo
- `php artisan products:publish-drafts --dry-run` then without the flag
- Replay from Suggestions, which this fix repairs

To size the backlog first:

```bash
sudo -u stcav php artisan tinker --execute="\App\Domain\Products\Models\Product::query()->autoCreated()->whereNull('woo_product_id')->selectRaw('auto_create_status, count(*) c')->groupBy('auto_create_status')->get()->each(fn(\$r)=>print(\$r->auto_create_status.' = '.\$r->c.PHP_EOL));"
```

Note that `needs_brand_or_category_assignment` rows in that count are **parked by
design**, not stranded — they are waiting on a human, and always were.

The ~387 auto-create drafts remain explicitly out of scope per the 2026-08-23
instruction not to approve or publish them as part of throttle work.
