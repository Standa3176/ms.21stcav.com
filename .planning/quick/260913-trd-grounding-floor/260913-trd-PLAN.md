# 260913-trd — A grounding floor for auto-generated product content

**Branch:** `quick/260913-grounding-floor`
**Trigger:** reviewing the 25-SKU B-Tech pilot the operator ran on 2026-09-13.

## What the pilot showed

The run reported `No supplier description/spec column found — grounding on
title + identifiers only.` Where the supplier title carried real words the copy
was good. Where it was just a part number, the model did not decline — it
filled the shape with plausible nothing, **and invented the product type to do
it**:

    BT8421-PRO/B   -> "Pro Monitor Arm Accessory ... designed to complement
                       compatible B-Tech mounting solutions"
    BT5442         -> the identical sentence
    BTEBT7353      -> "Heavy-Duty Mounting Bracket"
    BT735 - Black  -> "Universal Flat-to-Wall TV Mount"     <- invented outright

That last one is the clearest: the source title is `BT735 - Black`, a bare code
(and a DIFFERENT code from the SKU being created, `BT7351/B`), and the output
asserts a specific product type with confidence.

Worse than no listing: it cannot convert, the assigned category is a guess, and
thin near-duplicate copy drags site-wide quality down across a catalogue we were
about to expand by 875 products.

## Change

`products:generate-drafts` skips a SKU when **both**:

- no supplier detail column was detected (nothing real to ground on), **and**
- the supplier title is not descriptive.

`titleIsDescriptive()` strips the identifiers we already hold (sku, mpn — both
punctuated and bare), then every remaining token containing a digit (other part
numbers, dimensions, model codes), then colour/variant stop-words, and asks
whether two real words survive.

Deliberately generous at two words: a false SKIP costs a listing we could have
had; a false PASS costs 2p and a draft a human still reviews. The floor is there
to catch the empty case, not to adjudicate quality.

`--allow-thin` overrides it. Skips are counted and reported, never silent.

## A correction worth recording

I first reported a second fault — bullet points concatenated without separators
(`"...floor stand systems50mm diameter pole..."`) — and advised stopping the
rollout over it. **That was wrong.** It is `strip_tags()` on the CLI's own
display line:

    $this->line('  short:    '.Str::limit(strip_tags(...), 90));

`<li>A</li><li>B</li>` prints as `AB` in a terminal. The stored HTML is intact
and the storefront renders it correctly. A display artefact read as a data
fault.

## Tests

`tests/Feature/ProductAutoCreate/GroundingFloorTest.php` — 14 tests, and every
fixture is a REAL title from the pilot run rather than an invented example, so
the thresholds are tuned against the data that motivated them.

Suites `tests/Feature/ProductAutoCreate tests/Architecture`: **485 passed, 1,741
assertions**. Pint clean, deptrac 0 violations.

## Next

Re-run the same 25 to see the floor working — roughly 50p:

    php artisan products:draft-from-suggestions --brands=B-Tech --limit=25 \
      --source-images --no-confirm

Expect several skipped with a stated reason, and the remainder to read like the
good ones already did (`BT4002/B V2`, `BT7056/C`, `BT8708/B`).

The deeper issue remains open and is worth its own look: **the supplier DB has
no description column detected at all** for this data. Fixing that would lift
content quality across every future auto-create far more than any prompt
tuning — the floor is a guard, not a substitute for real grounding.
