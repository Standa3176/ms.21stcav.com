# T11 answers + retroactive-apply conditions (operator, 2026-08-07)

## Display Technology — drop product-types, map 4 real ones
DROP: Commercial Display, Interactive Flat Panel Display, Interactive Flat Panel, Video Wall Display,
Commercial Signage Display, Interactive Touch Display, Stretch Display, Non-Interactive, Commercial TV,
Digital, Flat Panel, Interactive Display, Interactive E-Board, Large Format Commercial Display.
MAP: Direct-lit LED→LCD, Direct LED→LCD, D-LED→LCD, "Direct View LED, Flip-Chip CoB"→Direct View LED.
TARGET ONLY the 11 real terms: LCD, LED, IPS, VA, TN, OLED, QLED, Direct View LED, NanoCell, Mini-LED,
MicroLED. (The other 9 cached terms are leaked product-types being removed at source — never map INTO them.)

## Max Load — populate BOTH (exact + band)
- `pa_max-load-kg` (exact, spec) — canonical `{n} kg` (space). "70kg"→"70 kg".
- `pa_max-load-band` (facet, 5 terms): Up to 10 kg / 11-25 kg / 26-50 kg / 51-100 kg / Over 100 kg.
```
kg<=10 → Up to 10 kg; <=25 → 11-25 kg; <=50 → 26-50 kg; <=100 → 51-100 kg; else Over 100 kg
```
Emit both global rows from a Max Load value.

## Lumens — 6 bands (2 retired)
Target: Under 3000 lumens / 3000-3999 / 4000-4999 / 5000-6999 / 7000-9999 / 10000+ lumens.
RETIRED (do NOT derive into): "3000-4999 lumens", "5000-9999 lumens".
```
lm>=10000 →10000+; >=7000 →7000-9999; >=5000 →5000-6999; >=4000 →4000-4999; >=3000 →3000-3999; else Under 3000
```
Exact figure → LOCAL "Brightness (lumens)" as `{n} ANSI lumens`.

## Projector Type (#3555) — 5 real terms (Installation, Business, Education, Portable, Home Cinema); MULTI-VALUE
NOT a value in the data — it's DERIVED from multi-signal heuristics (lumens, throw, lens, product name):
Installation = body-only/no-lens/interchangeable-lens OR ≥7000 lm; Education = short/UST at <7000 lm;
Business = standard throw 2500-7000 lm; Home Cinema = "home cinema"/"theatre" in NAME; Portable =
"portable"/"pico"/"mini" in name or <2000 lm. Do NOT infer "Installation" from the word in description
(155/280 false positives). → This is a T8-class inference engine, DEFERRED from T11.
(There is a stray 6th term in the taxonomy — operator to remove WP-side.)

## Two quick tweaks
- Touchscreen = boolean: any touchscreen value → Yes ("Multi-touch interactive touchscreen"→Yes). If a
  point-count/tech is present ("20-point PCAP") split 3-way: Touchscreen=Yes, Touch Points=20-point,
  Touch Technology=PCAP.
- Movement canonical "Full Motion": Full-motion/Full-Motion/"Full-Motion Articulating Arm"→Full Motion.
- GENERAL: add a normalised-key match across ALL attributes: `strtolower(preg_replace('/[^a-z0-9]/i','',$raw))`
  vs same-normalised cached term — catches case/hyphen/spacing variants generically.

## RETROACTIVE APPLY — two hard conditions (before scheduling)
1. **Must write TAXONOMIES, not local attributes.** (Our REST path already sends global-taxonomy
   `attributes:[{id,options}]` via WooAttributePayloadBuilder — compliant. The operator's warning is about
   WP CSV-importer/CRUD rebuilds storing local; bulk imports reverted 20,000+ links on live. Our id-based
   REST write is the correct form — must keep using it.)
2. **Live data takes precedence — ADD-ONLY, never overwrite.** The live catalogue was heavily populated in
   the last days (Connectivity ~1,850, Resolution ~2,470, Room Size 411, Max Load 753, projector brightness
   99%, Platform Certification 560). Where the resolver produces a value for an attribute a product ALREADY
   has on Woo, LEAVE IT — only write attributes that are ABSENT. Need a DRY-RUN report of would-ADD vs
   would-OVERWRITE counts before any live run. (This means the retroactive command must read each product's
   current Woo attributes and merge add-only — new logic, T12.)

## Ceiling
Normalisation alone tops out ~72-75%. The 17 empty facets (Platform Certification, Quick Release, Screen
Type, Throw Type, Touch Technology, Tensioning, Lens Shift, Noise Level, Noise Cancellation, Impedance, IP
Rating, Fire Rating, Speaker Type, Viewing Angle) were populated on live by MANUFACTURER LOOKUP, not text
parsing — extraction can't produce them. That's a T8/distributor-data problem, not a normaliser one.
