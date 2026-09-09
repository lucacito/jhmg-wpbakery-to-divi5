# Divi 5 Deterministic Validator (vendored copy)

Copied from the sibling project `Divi 5 Deterministic Validator/src/` (same author),
at commit `594bc69fb0204fed74f47fa756272f327c11af6c` (2026-07-28). Used only by the
test suite as an output gate: every converted layout must be a valid Divi 5 block
document. Refresh by copying `src/*.php` over this directory.

## Local additions

- `divi/map-pin` (a leaf child) and `ALLOWED_CHILDREN['divi/map'] = ['divi/map-pin']`,
  added while implementing Task 8's `vc_goo_maps` handler. Divi 5.12.1's
  `module-library/src/components/map/module.json` declares
  `"childrenName": ["divi/map-pin"]` and `map-pin/module.json` is
  `"category": "child-module"`, so a map with pins is a valid document that this
  copy rejected. Fold the same two lines into the sibling project before the next
  refresh, or they will be lost.
