# Value normalisation reference — 21CAV spec system (operator-supplied, 2026-08-07)

Canonical terms in `canonical-terms.json` (accompanies this). RULE-SOURCE for the maps; the LIVE
`woo_attribute_terms` cache remains the MATCH-AUTHORITY (resolve-don't-invent — never send a term not in
the live cache).

## 1. VESA — biggest single win (~45 products). ENUMERATE, don't just normalise.
A mount rated 200x200→600x400 physically supports the standard patterns in between, so storing only the
endpoints hides it from customers filtering on 400x400. Enumerate the standard patterns within the range.

```php
const VESA_STANDARD = [[50,50],[75,75],[100,100],[200,100],[200,200],[300,300],
    [400,200],[400,400],[600,400],[800,400],[800,600],[900,600],[1000,600]];
// strip 'mm', × → x, en/em-dash → '-'
// range "AxB to CxD" | "AxB - CxD" | "AxB and CxD": emit every VESA_STANDARD pattern P where
//   P.w in [minW,maxW] and P.h in [minH,maxH]; always include the stated endpoints; sort ascending;
//   join with ' / '. Single "AxB" → "AxB". "VESA 75, VESA 100" → "75x75 / 100x100".
```
Canonical output: lowercase `x`, no `mm`, patterns joined by ` / `. e.g.
`200×200 mm to 600×400 mm` → `200x200 / 300x300 / 400x200 / 400x400 / 600x400`.
Match the produced string to a cached VESA term; if absent, unmatched (safe).

## 2. Room Size — 4 terms, MULTI-VALUE, text-mapped (hyphen, not en-dash)
Canonical: `Huddle (2-4 people)`, `Small (4-6 people)`, `Medium (6-10 people)`, `Large (10+ people)`.
Text maps: Large Room / Extra Large Room / "Large to Extra-Large Meeting Rooms" → Large; Medium /
Medium/Large / "Medium (5-10 people)" → Medium; Small / "Small (1-4 people)" → Small; Focus Room / Phone
Booth / Huddle → Huddle. Multi-value expected (129/195 live carry >1 band; sort small→large).
APPLICABILITY: only room-serving products (video bars, room kits, cameras, speakerphones, conference
phones, mics) — NOT touch monitors, touch controllers, doc cameras, wireless presentation, accessories,
licences, services. Unset on those is correct.

## 3. Straight value maps
- **Shielding** (`U/UTP`,`S/FTP`): "U/UTP (Unshielded)"/UTP/Unshielded→U/UTP; SFTP/STP/"Double shielded"/
  "Fully shielded"/Shielded→S/FTP; Yes/No/Braided→drop.
- **Light Source** (`Laser`,`Lamp`,`LED`): Laser/Phosphor / "RGB True Laser" / DuraCore / "SOLID SHINE"→Laser;
  "RGB LED"/"4LED"→LED; UHP/UHE→Lamp. NEVER infer Lamp from the word "lamp" in copy — use model prefix
  (Acer PL, Optoma Z/UHZ, BenQ LK/LU/LH/LW, Epson EB-L/PU/PQ, Panasonic PT-R/F/VMZ/LMZ, ViewSonic LS,
  Vivitek DU/DH/DW → Laser; Acer PD → LED; Epson EB-W/X/U, BenQ MW/MX/MH/TH/TK, Optoma EH/EB/W/X,
  Acer S1/H6/P1/X1 → Lamp).
- **Colour** (canonical: Black,White,Grey,Silver,Graphite,Blue,Red,Green,Yellow,Charcoal,Chrome,Transparent):
  "Black (RAL 9005)"/"Black (B2AG)"/"Smooth Black"/"Matte Black"/"Black / Smooth Black"→Black; "Off White"/
  "Off-White"/"Smooth White"/"White (white grille)"→White; "Graphite Grey"→Graphite; "Two-Tone Grey"/
  "Grey / White"/"Dark Grey/Blue"/"Black/Grey"→Grey; "Silver/Black"→Silver. Two-tone → dominant colour.
- **Display Technology** (LED,LCD,IPS,VA,TN,OLED,QLED,Direct View LED,NanoCell,Mini-LED,MicroLED):
  "LED-backlit LCD"/"Direct LED-backlit LCD"/"Direct-lit LED-backlit LCD"→LCD; "Direct View LED, Flip-Chip
  CoB"→Direct View LED; Interactive Display/Commercial TV/"Large Format Commercial Display"/Digital→drop.
- **Material**: Aluminum/"Aluminum alloy"/"Aluminium and steel"→Aluminium(UK); "Cold-Rolled Steel"/
  "Stainless steel"/"Powder-coated steel"/"Steel/aluminum"→Steel; "ABS plastic"→Plastic; "ABS polycarbonate
  and metal"→Polycarbonate.
- **Mount Type** (Wall,Ceiling,Desk,Tabletop,Floor Standing,Trolley / Cart,Pole,Rack,Clip,DIN Rail,Tripod,
  Rail): "Corner Mount"/Mullion/"In-Window Wire Mount"/"Swivel Mount"/"Universal Projector Mount"→Wall;
  "Fixed Height Mobile Stand"→Floor Standing. **Fixed / "Fixed / Low Profile" / "Fixed, Low Profile" /
  "Full Motion – Tilt, Swivel, 3 Pivots" are MOVEMENT values → route to the Movement attribute, NOT Mount.**
- **Movement** (`Tilt & Swivel`,`Fixed`,`Tilt`,`Swivel`,`Full Motion`): Fixed/Tilt/Swivel/"Tilt & Swivel"/
  "Full Motion" — accept from Mount-labelled values AND from the `Motion Type` label. "Full Motion – Tilt,
  Swivel, 3 Pivots"→Full Motion.
- **Length** (unit-suffixed, `m`): "0.6 m"→"0.6m", "2 m"→"2m"; use `m` not `metres` where a bare-m term exists.
- **Connectivity** (multi-value; 28 terms): Network/"Network (LAN)"/"IP with integrated radio"→Ethernet +
  IP / Network; Cat5e/"Quick Disconnect (QD) to RJ"→Ethernet; "3.5mm Stereo Jack"→3.5mm Audio; "TosLink
  (Optical)"→(Optical Audio — NOT a current term → stays unmatched unless operator adds it); Fibre Optic/5G/
  Terminal→drop. Bearer implies mode: add `Wired` alongside HDMI/USB/Ethernet, `Wireless` alongside Wi-Fi/
  Bluetooth/DECT.

## 4. Label aliases to ADD
- `Max Load Capacity`(26)/`Max Weight Capacity`(7)/`Load Rating`/`Weight Capacity` → **Max Load**
- `Backlight Technology`(17)/`Backlight Type`(6)/`Display Type`(105)/`Panel Technology` → **Display Technology**
- `Motion Type`(9) → **Movement**
- `Touch`(9) → **Touchscreen**
- `Cable Type`(13) → **Cable Category** ONLY when the value is Cat5e/Cat6/Cat6a/Cat7/Cat8

## 4b. Do NOT alias (leave local / handle elsewhere)
- `Connector A`/`Connector B` — deliberately separate cable ends; merging loses info on ~300 cables.
- `Screen Size Range`(51)/`Compatible Screen Size`(17) — mount's SUPPORTED size, not the product's own
  Display Size Band. Keep separate (would pollute the Displays size facet).
- `Brand`(772) → product_brand taxonomy, not an attribute.
- `EAN`(54) → native WooCommerce GTIN field; drop from attributes (a barcode = one term per product).
- `MPN`,`Model`,`Part Number`,`RRP` → spec-table only, never facets.

## 5. Zero-fill facets
Will fill once maps land: VESA, Movement, Shielding, Room Size. Genuinely ABSENT from draft data (need
manufacturer/distributor lookup, extraction won't help): Platform Certification, Touchscreen, Quick Release,
Screen Type, Throw Type, Touch Technology, Tensioning, Lens Shift, Noise Level, Noise Cancellation,
Impedance, IP Rating, Fire Rating, Speaker Type, Viewing Angle.

## 6. Structural notes / DISCREPANCIES to reconcile vs live cache
- `pa_brightness-cdm2` is a taxonomy holding the EXACT cd/m² figure for the spec table (not the facet); the
  facet is `pa_brightness-nits` (4 bands). Keep exact LOCAL per D1.
- Doc says `pa_brightness-lumens` holds SIX bands (Under 3000 / 3000-3999 / 4000-4999 / 5000-6999 /
  7000-9999 / 10000+). Our prod cache (2026-07-28) showed FOUR. **Reconcile: re-sync cache; match live.**
- Doc VESA list (~63 combos here) vs our cache sync (~32). **Re-sync cache before relying on VESA matching.**
- Impedance live terms are mojibake ('Î©' = double-encoded Ω) → WP-side encoding cleanup.
