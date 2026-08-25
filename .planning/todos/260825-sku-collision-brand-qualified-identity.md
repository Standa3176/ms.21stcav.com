# TODO — brand-qualified product identity (SKU collisions)

**Raised:** 2026-08-25, from the live CP4 incident
**Status:** open, not scheduled
**Blocks:** stocking any two products that share a manufacturer part number

## The problem, concretely

`CP4` is a real part number for **two different products**:

| | Product | Cost | Sells for |
|---|---|---|---|
| Ours | Unicol / AVM ceiling mount | £24.96 (Unicol), £30.28 (Northamber) | ~£40 |
| AVITDirect's | Crestron CP4 control processor | — | ~£1,748 |

Neither feed is wrong. The **string** collides, and the catalogue treats a SKU
string as a product's identity.

Before the 2026-08-09 margin ceiling existed, this priced our £25 mount at
**£1,517.99** — exactly AVITDirect's £1,518.00 less the 1p `beat_by_pennies`.
That price was live on the storefront for weeks.

## What has been done (260825-h2r)

`competitor_match_exclusions` stops a named competitor's row pricing a given
key. It fixes the **pricing** half and nothing else. It is a patch on each
collision as someone spots it, not a fix for the class.

## What is still broken

**Crestron CP4 can never be stocked**, and it is worth being precise about why —
the exclusion is NOT the cause:

1. `OrphanDetector::record()` fires a `new_product_opportunity` only when a
   competitor SKU **matches no Product**. Our mount owns `CP4`, so the Crestron
   looks already-stocked and has never surfaced. This predates the exclusion.
2. Making the exclusion feed the detector does not help either. The whole
   pipeline is keyed on the SKU string: an opportunity recorded as `sku=CP4`
   applies into `CreateWooProductJob('CP4')`, hits the AUTO-08 duplicate gate
   against our mount, and dies. You would get an opportunity nobody can action.

So the shortest honest statement is: **a SKU string cannot identify a product,
and the catalogue assumes it can.**

## Options, roughly ascending in cost

1. **Manual disambiguated SKUs.** Add the Crestron by hand as `CRESTRON-CP4`.
   Works today, no code. Every future collision is manual and someone has to
   notice it first.
2. **Brand-qualified matching in the opportunity path.** Treat `(brand, sku)`
   as identity where the competitor feed carries a brand. Many feeds do not, so
   this is partial by nature.
3. **Brand-qualified identity throughout** — products, competitor matching,
   supplier matching, the duplicate gate. Correct, and a large piece of work
   touching AUTO-08, `ProductMatcher`, `SkuMatcher` and the alias table.

## Prompts worth answering before choosing

- How often does this actually happen? The exclusion table is the instrument:
  if it stays at one or two rows, option 1 is proportionate. If it grows past a
  handful, that is the argument for option 3.
- Short SKUs collide far more readily. `CP4` is four characters. Is there a
  length below which a competitor match should require brand or MPN
  corroboration before it may set a price?
- The reverse case exists too: our **supplier** feeds could carry a homonym and
  hand a product the wrong cost. Nothing guards that today — the equivalent of
  `competitor_match_exclusions` for supplier rows does not exist.

## Related

- `260825-h2r` — competitor match exclusions (the pricing patch)
- `260823-clp` — alternative supplier SKUs (aliases; a wrong alias feeds cost)
- `2026-08-09` — margin ceiling, which caught this and held the line for weeks
