# 260728-fwx — Deferred / out-of-scope items

## Pre-existing test helper name collision (NOT introduced by T9)

Running `tests/Unit/ProductAutoCreate` and `tests/Feature/ProductAutoCreate` in
the SAME pest process aborts with:

```
PHP Fatal error: Cannot redeclare function makeResolver()
  (previously declared in tests/Feature/ProductAutoCreate/Services/ProductBrandTermResolverTest.php:56)
  in tests/Unit/ProductAutoCreate/SpecTaxonomyResolverTest.php:41
```

- Both files declare a global `function makeResolver()` (Pest file-scope helpers
  leak into the global namespace). `ProductBrandTermResolverTest` predates the
  spec-taxonomy work; `SpecTaxonomyResolverTest`'s helper was added in T2.
- The clash only manifests when a Unit file and a Feature file are loaded
  together in one run — the T2/T3 dir sweeps stayed within `Feature/` so never
  hit it. It is **not** a T9 regression: neither file was touched by T9, and both
  helpers already exist on `main`.
- **Fix (deferred):** rename one helper (e.g. the brand test's to
  `makeBrandResolver()`) or wrap them in closures. Out of scope for T9 (SCOPE
  BOUNDARY: only fix issues the current task's changes cause). T9 tests verified
  by running the Unit and Feature sweeps separately — both green.
