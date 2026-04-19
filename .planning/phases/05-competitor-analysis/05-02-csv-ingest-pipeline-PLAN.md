---
phase: 05-competitor-analysis
plan: 02
type: execute
wave: 2
depends_on:
  - "05-01"
files_modified:
  - app/Domain/Competitor/Events/CompetitorPriceRecorded.php
  - app/Domain/Competitor/Events/CompetitorCsvIngested.php
  - app/Domain/Competitor/Services/EncodingDetector.php
  - app/Domain/Competitor/Services/DecimalFormatDetector.php
  - app/Domain/Competitor/Services/ColumnHeuristicDetector.php
  - app/Domain/Competitor/Services/PriceParser.php
  - app/Domain/Competitor/Services/CompetitorCsvRowWriter.php
  - app/Domain/Competitor/Services/CompetitorCsvParser.php
  - app/Domain/Competitor/Services/OrphanDetector.php
  - app/Domain/Competitor/Jobs/IngestCompetitorCsvJob.php
  - app/Domain/Competitor/Jobs/CompetitorCsvChunkJob.php
  - app/Domain/Competitor/Appliers/NewProductOpportunityApplier.php
  - app/Domain/Competitor/Console/Commands/CompetitorWatchCommand.php
  - app/Providers/AppServiceProvider.php
  - routes/console.php
  - docs/n8n-integration/README.md
  - storage/app/competitors/incoming/.gitkeep
  - storage/app/competitors/processing/.gitkeep
  - storage/app/competitors/archive/.gitkeep
  - storage/app/competitors/quarantine/.gitkeep
  - tests/Feature/Competitor/EncodingDetectorTest.php
  - tests/Feature/Competitor/DecimalFormatDetectorTest.php
  - tests/Feature/Competitor/ColumnHeuristicDetectorTest.php
  - tests/Feature/Competitor/PriceParserTest.php
  - tests/Feature/Competitor/CompetitorWatchCommandTest.php
  - tests/Feature/Competitor/IngestCompetitorCsvJobTest.php
  - tests/Feature/Competitor/CompetitorCsvChunkJobTest.php
  - tests/Feature/Competitor/OrphanDetectorDedupTest.php
  - tests/Feature/Competitor/StripVatReuseTest.php
  - tests/Fixtures/competitors/utf8_bom.csv
  - tests/Fixtures/competitors/windows1252.csv
  - tests/Fixtures/competitors/european_decimal.csv
  - tests/Fixtures/competitors/ambiguous_headers.csv
autonomous: true
requirements:
  - COMP-01
  - COMP-02
  - COMP-03
  - COMP-04
  - COMP-05
  - COMP-06
  - COMP-07

must_haves:
  truths:
    - "A `.csv` file dropped into `storage/app/competitors/incoming/` with an mtime > 30s old is picked up by `competitor:watch` (registered in routes/console.php to run every 5 minutes) and dispatched to `IngestCompetitorCsvJob` on the `competitor-csv` queue"
    - "Filename regex `^[a-z0-9_-]{1,64}_\\d{4}-\\d{2}-\\d{2}\\.csv$` rejects traversal attempts; non-matching filenames move to `quarantine/` and write a `csv_parse_errors` row with `issue_type=invalid_filename`"
    - "Filename prefix before the first underscore resolves to a `Competitor` via `firstOrCreate(['slug' => $slug], ['status' => 'pending', 'name' => $slug])` — D-01 first-sighting auto-discovery"
    - "Before reading the file, `EncodingDetector::detect()` checks for BOM (`\\xEF\\xBB\\xBF` UTF-8, `\\xFF\\xFE` UTF-16LE, `\\xFE\\xFF` UTF-16BE), then `mb_detect_encoding` with fallback `[UTF-8, Windows-1252, ISO-8859-1]`; non-UTF-8 files convert via mb_convert_encoding to a scratch file under `processing/.tmp-{uuid}.csv`"
    - "On first ingest for a competitor: `ColumnHeuristicDetector::detect()` scans the header row for known patterns (SKU: sku|mpn|part_no|part number|part_number|product code|product_code; PRICE: price|rrp|cost|£|gbp|price_gbp|price_ex_vat|price_inc_vat), persists resolved indexes to `competitor_csv_mappings` row — OR moves the file to `quarantine/` + writes a `csv_parse_errors` row with `issue_type=ambiguous_mapping` if 0 or multiple candidates match per column"
    - "`DecimalFormatDetector::detect()` samples first 10 non-header price-column rows, counts comma-as-decimal (`1.234,56` or `56,78`) vs dot-as-decimal (`1,234.56` or `56.78`) occurrences, majority wins, persists to `competitor_csv_mappings.decimal_format`"
    - "`PriceParser::toGrossPennies(string $raw, string $decimalMode): ?int` strips `£`/`GBP`/`€`/whitespace, parses according to decimal mode, returns integer pennies gross OR null on unparseable; every null triggers a `csv_parse_errors` row with `issue_type=unparseable_price`"
    - "Gross-to-ex-VAT conversion uses `App\\Domain\\Pricing\\Services\\PriceCalculator::stripVat($grossPennies, 2000)` directly — NO duplicate VAT math (COMP-06). Grep for `/ 1.2\\b` in Competitor namespace returns zero matches (assertion in test)"
    - "`IngestCompetitorCsvJob` processes CSVs in chunks of 100 rows via `CompetitorCsvChunkJob` on `competitor-csv` queue (P5-A: generator-based simple-excel reader; constant memory)"
    - "Each valid row writes to `competitor_prices` AND fires `CompetitorPriceRecorded` event carrying {competitorId, sku, priceGrossPennies, priceExVatPennies, ingestRunId}"
    - "Orphan SKUs (after case-insensitive + trim-normalised `Product::whereRaw('LOWER(TRIM(sku)) = ?', [strtolower(trim(\\$csvSku))])` lookup) create a `new_product_opportunity` suggestion via `OrphanDetector::record()` — FIRST sighting creates the row; SUBSEQUENT competitors tracking same SKU do `updateOrCreate` keyed on (kind, sku) and increment `supporting_competitors` in evidence JSON (D-09 dedup)"
    - "`NewProductOpportunityApplier` is registered as a no-op stub against kind `new_product_opportunity` in `AppServiceProvider::boot` — Phase 6 replaces the body; Phase 5 ships it logging 'Phase 6 will wire supplier-request-list integration' and marking suggestion applied"
    - "Atomic file moves: rename `incoming/foo.csv` → `processing/foo.csv` BEFORE dispatching the job; on success move to `archive/{YYYY-MM-DD}/foo.csv`; on failure move to `quarantine/{YYYY-MM-DD}/foo.csv` with a `.error.json` sidecar"
    - "`CompetitorIngestRun` row created at job start with correlation_id from Context; `rows_total/written/errored/orphaned` incremented as chunks complete; `status` flips to `completed` or `failed` at run end; `competitor.last_ingest_at` updated on success"
    - "`docs/n8n-integration/README.md` documents the `{slug}_{YYYY-MM-DD}.csv.tmp → rename` convention, directory layout, column-name heuristics, decimal-format handling, first-time competitor UX"
  artifacts:
    - path: "app/Domain/Competitor/Events/CompetitorPriceRecorded.php"
      provides: "DomainEvent fired after every valid row write — triggers 05-03 MarginAnalyser listener"
      exports: ["competitorId","sku","priceGrossPennies","priceExVatPennies","ingestRunId"]
    - path: "app/Domain/Competitor/Services/EncodingDetector.php"
      provides: "BOM-first, mb_detect_encoding fallback, scratch-file conversion pipeline"
      min_lines: 40
    - path: "app/Domain/Competitor/Services/DecimalFormatDetector.php"
      provides: "10-row majority-wins comma-vs-dot decimal heuristic"
    - path: "app/Domain/Competitor/Services/PriceParser.php"
      provides: "String → integer gross pennies with decimal-mode switch; nullable on unparseable"
    - path: "app/Domain/Competitor/Services/OrphanDetector.php"
      provides: "Cross-competitor dedup via updateOrCreate on (kind, sku) + supporting_competitors counter"
    - path: "app/Domain/Competitor/Jobs/IngestCompetitorCsvJob.php"
      provides: "Per-file entry point; generator-based chunk dispatch; move-to-archive/quarantine on completion"
      min_lines: 100
    - path: "app/Domain/Competitor/Jobs/CompetitorCsvChunkJob.php"
      provides: "100-row chunk processor; writes competitor_prices + fires events + captures parse errors + orphan routing"
      min_lines: 60
    - path: "app/Domain/Competitor/Appliers/NewProductOpportunityApplier.php"
      provides: "No-op stub registered for kind=new_product_opportunity per D-08 Phase 6 placeholder"
    - path: "app/Domain/Competitor/Console/Commands/CompetitorWatchCommand.php"
      provides: "Scheduled every 5 min; glob incoming/*.csv; mtime >30s gate; filename regex; atomic rename to processing/"
      min_lines: 50
    - path: "docs/n8n-integration/README.md"
      provides: "Owner-facing documentation for the n8n CSV-drop convention"
      min_lines: 40
  key_links:
    - from: "app/Domain/Competitor/Services/CompetitorCsvRowWriter.php"
      to: "app/Domain/Pricing/Services/PriceCalculator.php"
      via: "stripVat call"
      pattern: "stripVat\\("
    - from: "app/Domain/Competitor/Jobs/CompetitorCsvChunkJob.php"
      to: "app/Domain/Competitor/Events/CompetitorPriceRecorded.php"
      via: "event dispatch per valid row"
      pattern: "new CompetitorPriceRecorded"
    - from: "app/Providers/AppServiceProvider.php"
      to: "app/Domain/Competitor/Appliers/NewProductOpportunityApplier.php"
      via: "SuggestionApplierResolver::register"
      pattern: "register\\('new_product_opportunity'"
    - from: "routes/console.php"
      to: "competitor:watch"
      via: "Schedule::command"
      pattern: "Schedule::command\\('competitor:watch'\\)"
---

<objective>
Ship the full file-to-DB ingest pipeline: watcher command + atomic move semantics + encoding detection + decimal-format detection + column heuristics + price parser (using Phase 3 `PriceCalculator::stripVat` verbatim) + chunked CSV reader + orphan suggestion producer (with D-09 cross-competitor dedup) + NewProductOpportunityApplier stub + the `CompetitorPriceRecorded` event that 05-03 subscribes to. Ship the n8n integration README so ops knows the file-drop contract.

Purpose: This is the producer end of the whole pipeline. When this plan ships, a CSV dropped in `storage/app/competitors/incoming/` becomes `competitor_prices` rows, orphan suggestions, and — via the `CompetitorPriceRecorded` event — the hook that 05-03's analyser subscribes to.

Output: 2 events, 7 services, 2 jobs, 1 applier stub, 1 command, 1 README, 4 CSV fixtures, 9 feature tests. Zero new composer packages (uses `spatie/simple-excel` already installed in Phase 2).
</objective>

<execution_context>
@C:/Users/sonny.tanda/.claude/get-shit-done/workflows/execute-plan.md
@C:/Users/sonny.tanda/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/phases/05-competitor-analysis/05-CONTEXT.md
@.planning/phases/05-competitor-analysis/05-RESEARCH.md
@.planning/phases/05-competitor-analysis/05-01-SUMMARY.md

# Patterns to replicate
@.planning/phases/02-supplier-sync/02-02-SUMMARY.md
@.planning/phases/02-supplier-sync/02-03-SUMMARY.md
@.planning/phases/04-bitrix24-crm-sync/04-03-SUMMARY.md

# Existing code Phase 5 consumes
@app/Domain/Pricing/Services/PriceCalculator.php
@app/Domain/Foundation/Events/DomainEvent.php
@app/Console/Commands/BaseCommand.php
@app/Domain/Suggestions/Models/Suggestion.php
@app/Domain/Suggestions/Contracts/SuggestionApplier.php
@app/Domain/Suggestions/Services/SuggestionApplierResolver.php
@app/Providers/AppServiceProvider.php
@routes/console.php

<interfaces>
<!-- Phase 3 public API Phase 5 consumes VERBATIM -->

From app/Domain/Pricing/Services/PriceCalculator.php (Phase 3 D-05):
```php
namespace App\Domain\Pricing\Services;

class PriceCalculator
{
    // USE THIS FOR COMP-06 — NEVER reimplement
    public function stripVat(int $grossPennies, int $vatBasisPoints = 2000): int;

    // For reference only — not needed in Phase 5
    public function computeFinalPrice(int $supplierPennies, int $marginBps, int $vatBps = 2000): int;
}
```

From app/Domain/Foundation/Events/DomainEvent.php (Phase 1 Plan 03):
```php
abstract class DomainEvent implements ShouldDispatchAfterCommit
{
    public readonly string $correlationId;
    public function __construct() { /* Context::get('correlation_id') with fallback */ }
}
```

From app/Console/Commands/BaseCommand.php (Phase 1 Plan 03):
```php
abstract class BaseCommand extends Command
{
    abstract protected function perform(): int;
    // handle() wraps perform() and threads correlation_id via Context::add
}
```

From app/Domain/Suggestions/Contracts/SuggestionApplier.php:
```php
interface SuggestionApplier
{
    public function supports(): array;          // return array of kind strings
    public function apply(Suggestion $s): array; // return result array for integration_events.response_body
}
```

From app/Domain/Suggestions/Services/SuggestionApplierResolver.php (Phase 1 D-17; Phase 4 registration shape):
```php
// Registered in AppServiceProvider::boot():
app(SuggestionApplierResolver::class)->register('kind_string', ApplierClass::class);
```

Spatie SimpleExcelReader (installed Phase 2 Plan 02):
```php
use Spatie\SimpleExcel\SimpleExcelReader;
$reader = SimpleExcelReader::create($path);
foreach ($reader->getRows() as $rowArray) { /* generator — constant memory */ }
```
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Events + Detection services (Encoding, DecimalFormat, ColumnHeuristic, PriceParser)</name>
  <read_first>
    - @.planning/phases/05-competitor-analysis/05-RESEARCH.md §2 (full encoding + decimal-format + price-parser code) §Pitfall P5-A §Pitfall P5-B
    - @app/Domain/Pricing/Services/PriceCalculator.php (stripVat signature — DO NOT duplicate; CALL it)
    - @app/Domain/Foundation/Events/DomainEvent.php (base class + correlation_id auto-fill)
    - @app/Domain/Foundation/Events/ — existing Phase 1–4 DomainEvent implementations for shape reference
    - @tests/Feature/Pricing/PriceCalculatorTest.php (Phase 3 — fixture style for bit-exact assertions)
  </read_first>
  <behavior>
    - Test: `EncodingDetector::detect` on UTF-8 BOM fixture returns 'UTF-8'
    - Test: `EncodingDetector::detect` on Windows-1252 fixture (`£` = byte `\xA3`) returns 'Windows-1252'
    - Test: `EncodingDetector::detect` on ambiguous fixture logs `competitor.encoding_detection_ambiguous` warning + returns 'UTF-8' fallback
    - Test: `DecimalFormatDetector::detect` on `['1.234,56', '56,78', '999,00']` (comma-decimal sample) returns 'comma'
    - Test: `DecimalFormatDetector::detect` on `['1,234.56', '56.78', '999.00']` (dot-decimal sample) returns 'dot'
    - Test: `DecimalFormatDetector::detect` on empty sample (edge case) returns 'dot' (default)
    - Test: `ColumnHeuristicDetector::detect(['Product Code', 'Price GBP', 'Stock'])` returns `['sku_column_index' => 0, 'price_column_index' => 1]`
    - Test: `ColumnHeuristicDetector::detect(['foo', 'bar', 'baz'])` returns `null` (ambiguous — 0 matches triggers quarantine)
    - Test: `ColumnHeuristicDetector::detect(['sku', 'mpn', 'price'])` returns `['sku_column_index' => 0, 'price_column_index' => 2]` (picks FIRST SKU candidate in precedence order)
    - Test: `PriceParser::toGrossPennies('£1,234.56', 'dot')` returns `123456`
    - Test: `PriceParser::toGrossPennies('1.234,56 GBP', 'comma')` returns `123456`
    - Test: `PriceParser::toGrossPennies('not a number', 'dot')` returns `null`
    - Test: `PriceParser::toGrossPennies('', 'dot')` returns `null`
    - Test: `PriceParser::toGrossPennies('89.99', 'dot')` returns `8999`
    - Test: `new CompetitorPriceRecorded(1, 'SKU-1', 8999, 7499, 5)` carries all fields + auto-fills correlation_id
    - Test: `new CompetitorCsvIngested(competitorId, runId, rowsWritten)` extends DomainEvent
    - Test (VAT reuse guardrail): Grep assertion — zero occurrences of `/\s1\.2\b/` in `app/Domain/Competitor/` PHP files (COMP-06 — never duplicate VAT math)
  </behavior>
  <action>
**Events (`app/Domain/Competitor/Events/`):**

1. `CompetitorPriceRecorded extends DomainEvent`:
   ```php
   public function __construct(
       public readonly int $competitorId,
       public readonly string $sku,
       public readonly int $priceGrossPennies,
       public readonly int $priceExVatPennies,
       public readonly int $ingestRunId,
   ) { parent::__construct(); }
   ```

2. `CompetitorCsvIngested extends DomainEvent`:
   ```php
   public function __construct(
       public readonly int $competitorId,
       public readonly int $ingestRunId,
       public readonly string $filename,
       public readonly int $rowsTotal,
       public readonly int $rowsWritten,
       public readonly int $rowsErrored,
       public readonly int $rowsOrphaned,
   ) { parent::__construct(); }
   ```

**Services (`app/Domain/Competitor/Services/`):**

3. `EncodingDetector::detect(string $path): string` — implement exactly per RESEARCH §2:
   - Read first 4096 bytes
   - BOM sniff: `\xEF\xBB\xBF` → 'UTF-8'; `\xFF\xFE` → 'UTF-16LE'; `\xFE\xFF` → 'UTF-16BE'
   - `mb_detect_encoding($bytes, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], strict: true)`
   - Fallback: `Log::warning('competitor.encoding_detection_ambiguous', ['path' => $path, 'first_bytes_hex' => bin2hex(substr($bytes, 0, 32))]);` return `'UTF-8'`
   - Additional method `convertToUtf8(string $sourcePath, string $detectedEncoding): string` — if `$detectedEncoding !== 'UTF-8'`, `mb_convert_encoding` + write to `storage_path('app/competitors/processing/.tmp-' . Str::uuid() . '.csv')` + return scratch path; else return `$sourcePath` unchanged.

4. `DecimalFormatDetector::detect(iterable $sampleRows, int $priceColIdx): string` — per RESEARCH §2:
   - Skip first (header) row
   - Sample up to 10 non-empty values from `$priceColIdx`
   - Strip `£`/`$`/`€`/`GBP`/whitespace (case-insensitive)
   - Count comma-as-decimal matches: `preg_match('/^\d{1,3}(\.\d{3})*,\d{1,2}$/', $value)` OR `preg_match('/^\d+,\d{1,2}$/', $value)`
   - Count dot-as-decimal matches: `preg_match('/^\d{1,3}(,\d{3})*\.\d{1,2}$/', $value)` OR `preg_match('/^\d+\.\d{1,2}$/', $value)`
   - Return `'comma'` if comma count > dot count, else `'dot'` (default when tied or empty sample)
   - Use `CompetitorCsvMapping::FORMAT_*` constants from 05-01

5. `ColumnHeuristicDetector::detect(array $headerRow): ?array` — returns `['sku_column_index' => int, 'price_column_index' => int]` or `null` if ambiguous:
   - SKU patterns (in precedence order): `['sku', 'mpn', 'part_no', 'part number', 'part_number', 'product code', 'product_code']`
   - PRICE patterns (in precedence order): `['price', 'rrp', 'cost', '£', 'gbp', 'price_gbp', 'price_ex_vat', 'price_inc_vat']`
   - For each header: `strtolower(trim($h))` and match against each pattern list
   - Return `null` if 0 candidates found for either column (quarantine trigger per D-04)
   - Return the FIRST matching index for each category (precedence)
   - If multiple columns match the SAME category for the FIRST pattern tier → `null` (ambiguous) — e.g. two columns both labelled `sku` and `mpn` with ambiguity across tiers (nuance: first candidate in precedence wins; but exact duplicate column names at tier 0 trigger null)

6. `PriceParser::toGrossPennies(string $raw, string $decimalMode): ?int` — per RESEARCH §2:
   - `$clean = preg_replace('/[£$€]|GBP|\s/iu', '', trim($raw))`
   - Return null if `$clean === ''`
   - If `$decimalMode === 'comma'`: `$clean = str_replace('.', '', $clean)`; `$clean = str_replace(',', '.', $clean)`
   - Else: `$clean = str_replace(',', '', $clean)`
   - Return null if `!is_numeric($clean)`
   - Return `(int) round(((float) $clean) * 100)` — last + only rounding step

**Test fixtures (`tests/Fixtures/competitors/`):**

- `utf8_bom.csv`: first 3 bytes = `\xEF\xBB\xBF`, then `sku,price\nABC-1,89.99\n`
- `windows1252.csv`: `sku,price\n` then a row with `£` as byte `\xA3` (write via `file_put_contents(tests/Fixtures/competitors/windows1252.csv, "sku,price\nABC-1,\xA389.99\n")`)
- `european_decimal.csv`: `sku;price\nABC-1;1.234,56\nABC-2;999,00\nABC-3;56,78\n` (semicolon delimiter — spatie/simple-excel auto-detects)
- `ambiguous_headers.csv`: `foo,bar,baz\n1,2,3\n` (no SKU/price candidate matches → ColumnHeuristicDetector returns null)

**Tests (`tests/Feature/Competitor/`):**

- `EncodingDetectorTest`, `DecimalFormatDetectorTest`, `ColumnHeuristicDetectorTest`, `PriceParserTest` — one file per service, exhaustive cases per `<behavior>`.
- `StripVatReuseTest`: asserts via `Grep` (or `shell_exec('grep -rn')` wrapped in the test) that `app/Domain/Competitor/` PHP files contain ZERO occurrences of `/ 1.2` or `/ 1.20` or `stripvat` without namespace qualification (they MUST call `App\Domain\Pricing\Services\PriceCalculator::stripVat` — asserted by grepping for the import AND the call).
  </action>
  <verify>
    <automated>php vendor/bin/pest tests/Feature/Competitor/EncodingDetectorTest.php tests/Feature/Competitor/DecimalFormatDetectorTest.php tests/Feature/Competitor/ColumnHeuristicDetectorTest.php tests/Feature/Competitor/PriceParserTest.php tests/Feature/Competitor/StripVatReuseTest.php --stop-on-failure</automated>
  </verify>
  <done>4 detection services exist and pass unit tests against 4 CSV fixtures covering UTF-8 BOM, Windows-1252, European decimals, and ambiguous headers; `PriceParser` returns null on unparseable input (no silent zero); `StripVatReuseTest` green — zero duplicate VAT math in Competitor domain; 2 events exist extending DomainEvent.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: CSV Row Writer + OrphanDetector + Chunk Job + Ingest Job + Watcher Command</name>
  <read_first>
    - @.planning/phases/05-competitor-analysis/05-RESEARCH.md §1 (watcher) §5 (chunking) §6 (quarantine) §7 (orphan handling) §14 (n8n README)
    - @app/Domain/Sync/Jobs/SyncChunkJob.php (Phase 2 Plan 03 — chunk job shape for reference)
    - @app/Domain/Sync/Console/Commands/SyncSupplierCommand.php (Phase 2 Plan 03 — BaseCommand extension shape)
    - @app/Domain/Suggestions/Models/Suggestion.php — kind, payload, evidence column shapes
    - @.planning/phases/01-foundation/01-04-SUMMARY.md — SuggestionApplier registration pattern
    - @routes/console.php — current schedule entries; append `competitor:watch` hourly pattern
    - @app/Providers/AppServiceProvider.php — current `boot()` body; append SuggestionApplierResolver::register call
  </read_first>
  <behavior>
    - Test: `OrphanDetector::record($competitor, 'ORPHAN-001', 10000)` creates a Suggestion with kind='new_product_opportunity', evidence={sku: 'ORPHAN-001', supporting_competitors: 1, first_seen_at: ..., competitor_sightings: [{competitor_id: X, name: ..., price_ex_vat_pennies: 10000, recorded_at: ...}]}
    - Test: calling `OrphanDetector::record` twice with DIFFERENT competitors but SAME sku → only ONE Suggestion row exists; supporting_competitors = 2; competitor_sightings array has 2 entries (D-09 dedup)
    - Test: calling `OrphanDetector::record` twice with SAME competitor + same SKU → supporting_competitors stays at 1 (don't double-count same competitor)
    - Test: `NewProductOpportunityApplier::supports()` returns `['new_product_opportunity']`
    - Test: `NewProductOpportunityApplier::apply($suggestion)` marks suggestion status='applied', appends note containing 'Phase 6 will wire supplier-request-list integration', returns array with `phase_5_stub: true`
    - Test: `CompetitorCsvRowWriter::write($run, $mapping, ['ABC-1', '89.99'])` where Product with sku ABC-1 exists → writes 1 CompetitorPrice row, increments `$run->rows_written`, fires CompetitorPriceRecorded event
    - Test: `CompetitorCsvRowWriter::write($run, $mapping, ['ORPHAN-1', '49.99'])` where no Product with matching SKU → creates new_product_opportunity suggestion via OrphanDetector; increments `$run->rows_orphaned`
    - Test: `CompetitorCsvRowWriter::write($run, $mapping, ['ABC-1', 'garbage'])` (unparseable price) → creates csv_parse_errors row with issue_type=unparseable_price; increments `$run->rows_errored`
    - Test: `CompetitorCsvChunkJob::dispatch($runId, $mapping, [['ABC-1', '89.99'], ['ABC-2', '99.99']])` processes on competitor-csv queue; persists 2 CompetitorPrice rows after Queue::fake flush
    - Test: `IngestCompetitorCsvJob` against utf8_bom.csv fixture with pre-existing Competitor + Product → writes competitor_prices row + fires CompetitorPriceRecorded + moves file from processing/ to archive/
    - Test: `IngestCompetitorCsvJob` against ambiguous_headers.csv fixture (no csv_mapping exists) → moves file to quarantine/, writes csv_parse_errors row with issue_type=ambiguous_mapping, does NOT dispatch chunk jobs
    - Test: `CompetitorWatchCommand` with a file whose mtime > now (not > 30s old) → does NOT move file; no jobs dispatched
    - Test: `CompetitorWatchCommand` with a file mtime 31s old + valid filename → moves to processing/; dispatches IngestCompetitorCsvJob on competitor-csv queue
    - Test: `CompetitorWatchCommand` with filename `../../etc_passwd.csv` (traversal attempt) → rejected by regex; moved to quarantine/; csv_parse_errors issue_type=invalid_filename
    - Test: `CompetitorWatchCommand` with an UNKNOWN competitor slug `brandnew_2026-04-21.csv` → creates Competitor row with slug=brandnew, status=pending (D-01)
    - Test: duplicate CSV ingestion (same file ingested twice) → SECOND run hits (competitor_id, sku, recorded_at) unique constraint — row skipped + logged as duplicate, NOT errored; `rows_errored` unaffected
  </behavior>
  <action>
**`CompetitorCsvRowWriter`** (`app/Domain/Competitor/Services/`):
Signature: `write(CompetitorIngestRun $run, array $mapping, array $row): void`
- Extract `$sku` from `$row[$mapping['sku_column_index']]` (trim); `$priceRaw` from `$row[$mapping['price_column_index']]`
- Skip empty SKU: increment `$run->rows_errored`, create CsvParseError(issue_type=invalid_sku_format)
- Call `$priceParser->toGrossPennies($priceRaw, $mapping['decimal_format'])` → if null: `$run->rows_errored++`, CsvParseError(issue_type=unparseable_price, raw_line=implode(',', $row)); return
- Lookup Product: `Product::whereRaw('LOWER(TRIM(sku)) = ?', [strtolower(trim($sku))])->first()`
  - If NULL: call `$orphanDetector->record($run->competitor, $sku, $grossPennies)`; `$run->rows_orphaned++`; return
- Call `$priceCalc->stripVat($grossPennies, 2000)` for ex-VAT
- Try CompetitorPrice::create() — on `UniqueConstraintViolationException` (or whatever Laravel 12 throws on dup index): log info + return (idempotent re-ingest); do NOT error
- Dispatch `event(new CompetitorPriceRecorded(competitorId: $run->competitor_id, sku: $sku, priceGrossPennies: $grossPennies, priceExVatPennies: $exVatPennies, ingestRunId: $run->id))`
- Increment `$run->rows_written` — use `->increment('rows_written')` for atomic DB-level counter

**`OrphanDetector`** (`app/Domain/Competitor/Services/`):
Signature: `record(Competitor $competitor, string $sku, int $priceGrossPennies): Suggestion`
Logic:
- `Suggestion::where('kind', 'new_product_opportunity')->whereJsonContains('evidence->sku', $sku)->first()` — find existing row by SKU in evidence JSON
- If not found: `Suggestion::create(['kind' => 'new_product_opportunity', 'status' => 'pending', 'evidence' => ['sku' => $sku, 'supporting_competitors' => 1, 'competitor_sightings' => [['competitor_id' => $competitor->id, 'name' => $competitor->name, 'price_gross_pennies' => $priceGrossPennies, 'recorded_at' => now()->toIso8601String()]]], 'payload' => ['sku' => $sku]])`
- If found: check if this `competitor->id` already in `evidence.competitor_sightings` array; if NOT, append + `supporting_competitors = count(competitor_sightings)`; save
- If already seen from same competitor: no-op (idempotent)

**`NewProductOpportunityApplier`** (`app/Domain/Competitor/Appliers/`):
```php
class NewProductOpportunityApplier implements SuggestionApplier
{
    public function supports(): array { return ['new_product_opportunity']; }
    public function apply(Suggestion $suggestion): array
    {
        Log::info('new_product_opportunity.stub_applied', [
            'suggestion_id' => $suggestion->id,
            'sku' => data_get($suggestion->evidence, 'sku'),
            'note' => 'Phase 6 will wire supplier-request-list integration',
        ]);
        return ['phase_5_stub' => true, 'sku' => data_get($suggestion->evidence, 'sku')];
    }
}
```
Register in `AppServiceProvider::boot()`:
```php
app(SuggestionApplierResolver::class)->register('new_product_opportunity', NewProductOpportunityApplier::class);
```

**`CompetitorCsvChunkJob`** (`app/Domain/Competitor/Jobs/`):
- Implements `ShouldQueue`
- Constructor: `public function __construct(public int $ingestRunId, public array $mapping, public array $rows) {}`
- `public string $queue = 'competitor-csv'` (set in constructor via `$this->onQueue('competitor-csv')` per Phase 1 P05 pattern to avoid PHP 8.4 trait collision)
- `public int $timeout = 120; public int $tries = 2;`
- `handle(CompetitorCsvRowWriter $writer): void` — loads run by id; foreach row → `$writer->write($run, $mapping, $row)`

**`IngestCompetitorCsvJob`** (`app/Domain/Competitor/Jobs/`):
- Implements `ShouldQueue`; `$queue = 'competitor-csv'`; `$timeout = 600`; `$tries = 2`
- Constructor: `public function __construct(public string $processingPath, public int $competitorId) {}`
- `handle(ColumnHeuristicDetector $cols, DecimalFormatDetector $decimal, EncodingDetector $encoding, CompetitorCsvParser $parser): void`:
  1. Load competitor
  2. Start `CompetitorIngestRun` with correlation_id from Context
  3. `$detectedEnc = $encoding->detect($path); $readPath = $encoding->convertToUtf8($path, $detectedEnc);`
  4. Check existing `competitor_csv_mappings` for this competitor:
     - If EXISTS: use saved mapping (fast path)
     - If NOT: open reader, grab header + first 10 rows, run `$cols->detect($header)`; if null → quarantine + CsvParseError(ambiguous_mapping); return; else run `$decimal->detect($sampleRows, $priceColIdx)`, persist `CompetitorCsvMapping` row
  5. Open full reader, buffer 100 rows at a time, dispatch `CompetitorCsvChunkJob` per buffer (onQueue('competitor-csv'))
  6. After all chunks dispatched, dispatch a final chunk if buffer non-empty
  7. Use `Bus::batch([...CompetitorCsvChunkJob])->onQueue('competitor-csv')->finally(...)->then(...)->catch(...)->dispatch()`:
     - `->then(fn ($batch) => ...)`: Atomically move file from `processing/{fname}` to `archive/{YYYY-MM-DD}/{fname}`, update `competitor.last_ingest_at`, mark run `completed`, fire `CompetitorCsvIngested` event
     - `->catch(fn ($batch, $e) => ...)`: Move file to `quarantine/{YYYY-MM-DD}/{fname}` with `.error.json` sidecar (exception class + message + trace_summary), write `csv_parse_errors` row with `issue_type=chunk_batch_failed` + context carrying the batch id + counts, mark run `failed` + `error_message`
  8. On outer try/catch (pre-batch exception, e.g. encoding/mapping failure before batch dispatched): direct quarantine path — move file + write parse error + mark run failed
- **Locked: `Bus::batch` with atomic `->then()`/`->catch()` callbacks.** NOT chain-terminal. Rationale: Bus::batch gives atomic 'all chunks done → archive move' semantics with guaranteed finalise-once behaviour; on ANY chunk failure the `->catch()` runs EXACTLY once with batch-level error summary. Chain-terminal loses this — a mid-chain failure leaves the file in processing/ with no cleanup signal.

**`CompetitorWatchCommand`** (`app/Domain/Competitor/Console/Commands/`):
Extends `BaseCommand`. Signature: `competitor:watch`.
`perform(): int`:
1. `$incomingDir = Storage::disk('local')->path('competitors/incoming')`
2. For each `glob("{$incomingDir}/*.csv")`:
   - Skip if `str_ends_with($path, '.tmp')` (defensive; glob already excludes these)
   - Skip if `filemtime($path) > now()->subSeconds(30)->timestamp` (mtime gate)
   - Windows-only: `$fh = @fopen($path, 'r+'); if (!$fh || !flock($fh, LOCK_EX | LOCK_NB)) { @fclose($fh); continue; } flock($fh, LOCK_UN); fclose($fh);` — skip if held open by another process
   - Validate filename against `config('competitor.filename_regex')` (which is `^[a-z0-9_-]{1,64}_\d{4}-\d{2}-\d{2}\.csv$`) — if non-match:
     - `CsvParseError::create(['filename' => basename($path), 'issue_type' => 'invalid_filename', 'context' => ['path' => $path]])`
     - Move to `quarantine/{date}/` via `rename`
     - continue
   - Parse slug from `preg_match('/^([a-z0-9_-]+)_(\d{4}-\d{2}-\d{2})\.csv$/', basename($path), $m)`
   - Resolve Competitor via `firstOrCreate(['slug' => $m[1]], ['name' => $m[1], 'status' => Competitor::STATUS_PENDING])`
   - Atomic rename to `processing/{basename}` — if rename fails (another worker), continue silently
   - Dispatch `IngestCompetitorCsvJob::dispatch($processingPath, $competitor->id)->onQueue('competitor-csv')`
3. Return 0

**Schedule registration** in `routes/console.php`:
```php
Schedule::command('competitor:watch')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer();
```

**Directory `.gitkeep` seeds** (4 files): `storage/app/competitors/{incoming,processing,archive,quarantine}/.gitkeep`

**`docs/n8n-integration/README.md`**: Write per RESEARCH §14 verbatim (filename convention, atomic write, column/encoding/decimal expectations, first-time competitor UX, troubleshooting).

**Tests** under `tests/Feature/Competitor/`:
- `OrphanDetectorDedupTest` — 2-competitor dedup scenario
- `CompetitorWatchCommandTest` — 4 scenarios (valid file, mtime-fresh skip, traversal filename, unknown-slug auto-create Competitor)
- `IngestCompetitorCsvJobTest` — happy path (utf8_bom.csv) + ambiguous path (ambiguous_headers.csv quarantine)
- `CompetitorCsvChunkJobTest` — Queue::fake + 2-row batch → 2 CompetitorPrice rows + 2 CompetitorPriceRecorded events
  </action>
  <verify>
    <automated>php vendor/bin/pest tests/Feature/Competitor/OrphanDetectorDedupTest.php tests/Feature/Competitor/CompetitorWatchCommandTest.php tests/Feature/Competitor/IngestCompetitorCsvJobTest.php tests/Feature/Competitor/CompetitorCsvChunkJobTest.php --stop-on-failure && test -f docs/n8n-integration/README.md && php artisan schedule:list 2>/dev/null | grep -q competitor:watch</automated>
  </verify>
  <done>End-to-end watcher→job→row flow verified by integration test using utf8_bom.csv fixture; orphan dedup verified with 2-competitor scenario; quarantine path verified with ambiguous_headers.csv; filename-traversal regex rejects `../` attempt; `docs/n8n-integration/README.md` exists and documents the full contract; `competitor:watch` appears in `php artisan schedule:list`; `NewProductOpportunityApplier` registered in AppServiceProvider and invokable via ApplySuggestionJob.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| n8n → Laravel filesystem | n8n drops CSV files; Laravel is consumer. Untrusted filename + contents. |
| CSV cell → DB | Untrusted string → parsed number / SKU → persisted row. |
| Suggestion payload → PricingRule update | (05-03 scope, NOT this plan) — admin approves, rule updates. |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-05-02-01 | Tampering | CompetitorWatchCommand filename handling | mitigate | Regex whitelist `^[a-z0-9_-]{1,64}_\d{4}-\d{2}-\d{2}\.csv$` validated BEFORE any file operation; `../` and other traversal rejected → quarantine. |
| T-05-02-02 | Denial of Service | CSV ingest memory | mitigate | `spatie/simple-excel` generator + 100-row CompetitorCsvChunkJob; constant memory regardless of file size. Test asserts 50k-row file can be dispatched without OOM. |
| T-05-02-03 | Tampering | CSV formula injection (`=HYPERLINK(...)`) | mitigate | Price column parsed as numeric via PriceParser; SKU column stored as plain string; formulas never evaluated (no cell.type=FORMULA interpretation). |
| T-05-02-04 | Tampering | CSV SQL injection payload in cells | mitigate | Eloquent parameter binding on every CompetitorPrice::create; no raw SQL concat. |
| T-05-02-05 | Information Disclosure | Scratch file leak (encoding conversion) | accept | Scratch files in `processing/.tmp-{uuid}.csv` inherit process umask; deleted after chunk job completes. No PII in competitor prices (public-data territory). |
| T-05-02-06 | Elevation of Privilege | NewProductOpportunityApplier stub over-permissive | accept | Applier is a logger/stub; marks suggestion applied with no actual state mutation beyond audit trail. Phase 6 will add real authz checks when the real applier ships. |
| T-05-02-07 | Denial of Service | Watcher dispatches endless chunk jobs from corrupt file | mitigate | Per-run `rows_total` counted upfront; hard cap 50k rows via `if ($rowsTotal > 50000) { quarantine + error }` in IngestCompetitorCsvJob. |
</threat_model>

<verification>
- `php vendor/bin/pest tests/Feature/Competitor/` all green
- `php artisan schedule:list | grep competitor:watch` shows the every-5-minute entry
- `grep -rn "/ 1\\.2\\b\\|/ 1\\.20\\b" app/Domain/Competitor/` returns zero matches (VAT math reuse discipline)
- A locally-dropped fixture `storage/app/competitors/incoming/test_2026-04-21.csv` (with valid SKU that matches a seeded Product) is picked up on next `php artisan competitor:watch` invocation and flows to `archive/` within one job cycle
- `docs/n8n-integration/README.md` exists + passes markdownlint on baseline rules
- `php artisan tinker --execute="app(\\App\\Domain\\Suggestions\\Services\\SuggestionApplierResolver::class)->resolve('new_product_opportunity');"` returns an instance
- Zero Phase 1-4 test regressions
</verification>

<success_criteria>
- Tests: all 9 new Pest files pass
- An integration test drops a 3-row CSV fixture, runs the watcher + job chain, asserts: 3 CompetitorPrice rows created (or 2 + 1 orphan suggestion), 1 CompetitorIngestRun row status=completed, file ends up in archive/
- Schedule entry registered
- Applier stub registered and callable
- `PricingRuleChanged` event NOT yet added (deferred to 05-03)
- `MarginAnalyser` NOT yet shipped (deferred to 05-03)
</success_criteria>

<output>
Create `.planning/phases/05-competitor-analysis/05-02-SUMMARY.md` covering:
- Actual chunk size chosen (if different from 100)
- Whether Bus::batch was used or a chain-terminal "finalise" job
- Any spatie/simple-excel quirks discovered with delimiter auto-detection
- Mapping-table pre-existing behaviour (first-ingest vs saved-mapping branches)
- The exact CompetitorPriceRecorded field shape (so 05-03 listener matches)
- Flag if any Windows-specific file-locking quirk surfaced in dev testing
</output>