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
- `divi/charts` (a leaf module, valid inside a column), added while implementing Task 9's
  `vc_round_chart` / `vc_line_chart` handler. Divi 5.12.1's
  `module-library/src/components/charts/module.json` is `"category": "module"` with
  `"childrenName": []`, and its server side is
  `server/Packages/ModuleLibrary/Charts/ChartsModule.php`. Fold the same two lines into the
  sibling project before the next refresh, or they will be lost.
- `divi/post-slider` and `divi/contact-form-7` (leaf modules, valid inside a column), added while
  implementing Task 9's `vc_posts_slider` and `contact-form-7` handlers. Both are
  `"category": "module"` with no children in Divi 5.12.1's
  `module-library/src/components/{post-slider,contact-form-7}/module.json`. Fold these into the
  sibling project before the next refresh, or they will be lost.
- `Validator::validate()` / `validateContent()` take an optional second `$ignore` argument —
  a list of `self::E_*` violation codes to drop from the result before it comes back. Added
  in Task 8's fix round 1 so `ConverterFixtureTest` can ignore `E_MULTIPLE_H1`: the controller
  ruled that a heading keeps the author's level (`task-8-fix-round-1.md`, R1) rather than
  having the converter demote a second `<h1>` on the page, so a handful of expected fixtures
  legitimately carry more than one `<h1>` and the render-critical/hierarchy passes must still
  run and fail on everything else. Task 10's corpus tests use the same ignore list. Fold this
  into the sibling project before the next refresh, or it will be lost.
