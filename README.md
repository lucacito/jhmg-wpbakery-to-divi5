# WPBakery Page Builder → Divi 5 Converter

Two WordPress plugins that convert WPBakery Page Builder pages into native Divi 5 block pages:

- `plugin/jhmg-converter-for-wpbakery-to-divi` — free (wordpress.org)
- `plugin/jhmg-converter-for-wpbakery-to-divi-pro` — Pro add-on (divi5lab.com)

The converter reads the post's shortcode string and the three WPBakery metas and never scrapes
rendered HTML; the source post is never modified, and a conversion always creates a new post.

Spec: `docs/superpowers/specs/2026-09-09-wpbakery-to-divi5-converter-design.md`.
Docs: [`docs/wpbakery-schema.md`](docs/wpbakery-schema.md),
[`docs/divi5-schema.md`](docs/divi5-schema.md),
[`docs/conversion-map.md`](docs/conversion-map.md),
[`docs/conversion-workflow.md`](docs/conversion-workflow.md),
[`docs/conversion-reporting.md`](docs/conversion-reporting.md),
[`docs/box-model.md`](docs/box-model.md).

## Develop

```bash
composer install && npm install
vendor/bin/phpunit                      # unit + fixture + corpus tests (no WordPress needed)
scripts/docker/setup_wp.sh              # WordPress + Divi 5.12.1 + WPBakery 9.0.1 on http://localhost:8020 (admin/admin)
npx playwright test                     # end-to-end against the container
npm test                                # all three: PHPUnit, the parser-parity check, Playwright
```

Ports 8000, 8001, 8010, 8080 and 8081 belong to sibling projects — this one is **8020**.

`npm test` (`test.sh`) also runs **`scripts/docker/parser-parity.php`** inside the container when it
is up: it re-reads every corpus document with WordPress's own `do_shortcode()` regex and compares
the tag stream to `ShortcodeParser`'s, so a divergence between this parser and WordPress's is a test
failure rather than a surprise on a live site. A bracket token neither of them treats as a shortcode
is **listed, never a failure**. A passing run prints one line:

```
parity ok: 138 documents (75 wpbakery-templates, 35 wpbakery-layouts, 3 ronneby exports, 28 ronneby export pages), 6258 shortcodes
```

Without the container the step is skipped and says so.

## `references/`

Not committed (gitignored). `scripts/docker/setup_wp.sh` stops with instructions when `Divi.zip` or
`js_composer.9.0.1.zip` is missing. See [`references/README.md`](references/README.md) for the full
list and where each archive comes from:

| Archive | Why |
|---|---|
| `Divi.zip` | Divi 5.12.1 — the target schema and the Docker theme |
| `js_composer.9.0.1.zip` | WPBakery 9.0.1 — the schema source and the Docker plugin |
| `js_composer.7.8.zip` | WPBakery 7.8 — the pre-9.0 attribute forms most live pages still carry |
| `Ultimate_VC_Addons.zip` | Ultimate Addons 3.19.3 — the six add-on handlers, and the e2e static-copy case |
| `layouts-for-wpbakery.1.1.5.zip` | the free Layouts for WPBakery plugin — source of the 35-layout smoke corpus |
| `themeforest-…-ronneby-….zip`, `ronneby-core.zip` | DFD Ronneby 3.5.74 + Ronneby Core 1.5.74 — the real-world corpus and the source for the 23 Ronneby handlers |
| `themeforest-…-the-retailer-….zip` | The Retailer 10.0.13 — the source for that theme family's tag list |

Both `data/wpbakery-params.json` and `data/fa-icons.json` are generated from these archives
(`scripts/build-wpbakery-params.php`, `scripts/build-fa-icon-map.php`) and committed, so the tests
run without them.

## Fixtures and corpora

- `fixtures/wpbakery/*.txt` (+ optional `<name>.json` sidecar holding `{meta, mode, attachments}`)
  → `fixtures/divi/*.json`: 127 hand-reviewed input/expected pairs. **The expected file is a
  reviewed specification, not a snapshot.** Regenerate one only after reading
  `php scripts/render-fixture.php fixtures/wpbakery/<name>.txt --report`, then
  `php scripts/update-expected.php <name>`.
- `fixtures/wpbakery-templates/*.txt`: WPBakery's own **75** bundled templates
  (`config/templates.php`, extracted by `scripts/wpb-templates-to-fixtures.php`).
- `fixtures/wpbakery-layouts/*.txt`: the **35** layouts of the free Layouts for WPBakery plugin
  (`scripts/fetch-layouts-corpus.php`, with credit in that directory's `README.md`).
- `fixtures/wpbakery-import/`: the upload formats — `export.xml`, `two-pages.xml`, `entity.xml`,
  `page.txt`.
- `fixtures/box-model/`: the three hand-written Divi 5 documents Task 2 measured against WPBakery.
- `wpbakery templates/ronneby/*.xml`: **96** real WordPress exports, one per WPBakery demo of the
  DFD Ronneby ThemeForest package — 510 WPBakery documents, 44,278 elements. Three are committed
  (43 MB of third-party demo content does not belong in git); re-extract the rest with
  `scripts/extract-ronneby-corpus.sh`. `tests/ThirdPartyTemplateConversionTest.php` requires every
  export on disk to convert validator-clean with zero skipped settings.

## Coverage

```bash
php scripts/element-coverage.php            # what each corpus holds, and which handler claims each tag
php scripts/element-coverage.php --unseen   # also list the registered tags no corpus document holds
```

At `d76abab`:

```
Coverage
  Registered WPBakery tags mapped   75 / 75   (38 exact, 31 approximate, 6 read by their parent element)
  Template-only and vendor tags     23 / 23   (the WPBakery render templates and WooCommerce's 18)
  Theme / add-on tags seen          73 in 3 families, 30 of them converted by a handler of ours
  Theme / add-on handlers shipped   31          (Ronneby × 23, Sliders × 2, Ultimate Addons × 6)
```

## Adding an element handler

1. Read the element's fields from **WPBakery's own source**, never from memory:
   `config/**`, `include/templates/shortcodes/<tag>.php`, `include/classes/shortcodes/<tag>.php`,
   `include/params/**` in `references/js_composer.9.0.1.zip` (and `7.8` for the older form). The
   authoritative field list per tag is `data/wpbakery-params.json`.
2. Create `includes/converter/handlers/class-<name>-converter.php` extending `BaseWPBakeryConverter`.
   Write to the **9.0.1** attribute names — `AttributeNormaliser` has already folded the pre-9.0
   forms and the deprecated tags onto them.
3. In `convert()`: call `mapStyle()`, write the Divi content keys (paths only as documented in
   [`docs/divi5-schema.md`](docs/divi5-schema.md), read from the Divi source), call `logConverted()`,
   and pass **every key you consumed** to `logUnmappedSettings()`. A consumed key is either written
   or reported — never merely claimed.
4. Register it in `ConverterRegistry::registerDefaults()` (`approximate: true` when the output is a
   deliberate stand-in rather than the same object in Divi's vocabulary).
5. Add `fixtures/wpbakery/<name>.txt`, review
   `php scripts/render-fixture.php fixtures/wpbakery/<name>.txt --report`, generate the expected file
   with `php scripts/update-expected.php <name>`, and run `vendor/bin/phpunit`.
6. Add the row to `docs/conversion-map.md` and any new Divi path to `docs/divi5-schema.md`.

## Release

`RELEASE.md`. The free plugin's gate is `scripts/plugin-check.sh free`;
`scripts/build-submission-zip.sh` builds the wordpress.org submission zip from `wporg-assets/`.
