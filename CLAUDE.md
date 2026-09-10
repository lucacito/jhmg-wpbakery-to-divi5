# WPBakery Page Builder → Divi 5 Converter — working notes

- Never scrape HTML. Read the post's `post_content` (the shortcode string) and the metas
  `_wpb_vc_js_status`, `_wpb_shortcodes_custom_css`, `_wpb_post_custom_css` only. Never modify the
  source post — a conversion always creates a new post.
- WPBakery field names come from the source, not from memory: `references/js_composer.9.0.1.zip`
  (`config/**`, `include/templates/shortcodes/*.php`, `include/classes/shortcodes/*.php`,
  `include/params/**`, `include/classes/migrations/class-wpb-template-attributes-migration.php`),
  `references/js_composer.7.8.zip` for the pre-9.0 forms, `references/Ultimate_VC_Addons.zip`
  (`modules/*.php`) for the add-on. What they map to is recorded in `docs/wpbakery-schema.md`.
- Handlers are written to the 9.0.1 attribute names; `AttributeNormaliser` converts the pre-9.0 forms
  (and the deprecated tags: `vc_button`/`vc_button2` → `vc_btn`, `vc_cta_button` → `vc_cta`, `vc_tabs`/`vc_tour`/
  `vc_accordion` → the tta equivalents) before any handler runs. Both eras go through the normaliser.
- Divi attribute paths only as documented in `docs/divi5-schema.md`, read from the Divi source
  (`references/Divi.zip` is Divi 5.12.1): unzip it and check
  `Divi/includes/builder-5/visual-builder/packages/module-library/src/components/<module>/module.json`
  and `server/Packages/StyleLibrary/Declarations/`. Do not invent block types or attribute shapes.
- Paths Divi 5.12.1 rejects, learned the hard way — all four are in `docs/divi5-schema.md`:
  leftover custom CSS is `css.desktop.value.mainElement`, never `css.desktop.value.main` (5.12.1
  defines `before`/`mainElement`/`after`/`freeForm` only; `main` was the 5.7.4 name in the Beaver
  repo); there is no gradient `type` of `radial` (`GradientUtils` knows `linear`, `conic`,
  `elliptical`, `circular` and silently falls through to `linear`); `divi/image` reads spacing and
  sizing from `module.advanced.*`, not `module.decoration.*`, and has no `module.decoration.border`;
  `divi/button` has neither `module.decoration.border` nor `module.decoration.background`.
- Divi 5.12.1 accepts a non-preset `columnStructure`: `5_12,7_12` was driven in the Visual Builder
  and measured at 41.67 % / 58.33 % with the right `et_flex_column_{10,14}_24` classes and no
  console errors. No preset fallback is needed — write the real structure.
- Documentation of record: `docs/wpbakery-schema.md` (source parameters, colour/size tables, the
  normaliser), `docs/divi5-schema.md` (every Divi path written), `docs/conversion-map.md` (tag →
  module, with fidelity), `docs/conversion-workflow.md` (pipeline, admin, Pro↔free coupling),
  `docs/conversion-reporting.md` (every report key), `docs/box-model.md` (the measurements). A new
  handler updates the map and the schema in the same commit.
- Divi appends the unit to gradient stop positions itself: write `position: "23"`, never `"23%"`, or
  the whole background declaration (image included) is dropped.
- Icon settings are written `unicode, type, weight` in that order: Divi's asset detector regex
  matches `"unicode"…"type":"fa"` and otherwise never enqueues Font Awesome.
- Colours are never invented. A palette name Divi cannot resolve is reported under
  `unresolved_globals`, not guessed at.
- Every handler calls `logUnmappedSettings()` with every key it consumed; nothing is dropped
  silently. Unknown keys switched off (`no|none|off|false`) are not "skipped settings". **A consumed
  key is either written to a Divi attribute or reported — never merely claimed.**
- Only a field WPBakery itself declares for that element is a "skipped setting". A field a theme
  bolted on with `vc_add_param()` (`ThemeShortcodes::THEME_PARAMS`) and a field WPBakery never
  declared for the tag (`data/wpbakery-params.json`, `WPBakeryParams::declares()` → false) are
  reported under `not_carried_over` kind `addon`, with the family named when the page has exactly
  one. `declares()` → **null** (six core tags whose params cannot be read) is not "declares
  nothing": it changes nothing, so a real gap is never hidden.
- Tag→module rulings that source settled against the plan: `vc_round_chart`/`vc_line_chart` →
  `divi/charts` (Divi 5.12.1's Chart.js module, data at `chart.innerContent…data = {columns, rows}`),
  not a text placeholder; `vc_woocommerce` is a **wrapper template, not a shortcode** — the handler
  registers the 18 tags of `Vc_Vendor_Woocommerce::WC_SHORTCODES` → `divi/code`; `vc_cta_button` →
  `vc_cta`, not `vc_btn`.
- `active_section` / an active tab cannot be honoured: `accordion-item` / `tabs` expose
  `openToggle` / `activeTab` as styling selectors only, and Divi opens the first child by position.
  Report it under `not_carried_over` kind `layout`.
- Flattened accordion/tab children are **not counted as converted**
  (`ConverterEngine::withConvertedCountSuppressed()`): `ContentFlattener` throws those blocks away
  after rendering them to HTML, so counting them would inflate `quality.module_coverage`. Only the
  count is suppressed — warnings, `not_carried_over`, static copies, skipped settings and theme
  counts still record, because they describe the source.
- Blog and post-slider categories are written **only when they resolve** (`categoryIds()`):
  WPBakery stores names or slugs, Divi wants term ids, and an unresolvable one is reported.
- Column widths: WPBakery's twelfths and fifths all map exactly onto Divi 5.12's 24-grid
  (`module.decoration.sizing.{bp}.value.flexType`, `1_12 → 2_24 … 1_1 → 24_24`, fifths as `1_5…4_5`)
  plus `module.advanced.type`; the row's `columnStructure` is the comma-joined `type` list.
  `module.advanced.type` alone renders `24_24`. A column's `layout` never sets `display: block`.
- Divi 5 nests column → row → column. Anything that must sit inline (button groups, icon rows) goes
  in a nested flexed row, not in stacked blocks.
- **Box model** (measured on the Docker site, `docs/box-model.md`): reproduce WPBakery's, not Divi's
  defaults. Section padding `0` unless the row's design options set one. Every top-level row carries
  WPBakery's 15 px bleed — `sizing.width` `calc(var(--content-width, 80%) + 30px)`, `sizing.maxWidth`
  `calc(var(--content-max-width, 1080px) + 30px)`, `spacing.margin` `0px -15px 0px -15px` — the same
  Divi `:root` properties and plain fallbacks Divi's own row rule uses, so an unknown property
  degrades to Divi's shipped width instead of to `auto`; the bleed stays 15 px whatever the gap.
  A nested row uses `calc(100% + 30px)` with `maxWidth: none` and the same `0 -15px` margin.
  Row padding `0`, except `gap` N, which goes on the row: `layout.columnGap` and `layout.rowGap`
  `Npx` plus `spacing.padding` `N/2px 0px N/2px 0px` — never as column padding. Columns keep padding
  `0 15px` (plus `35px` on top when the row is filled **and** on the columns of the row that follows
  a filled row) and `layout.rowGap` `0px`. Gaps are written `"0px"`, never `"0"` — Divi tests the
  value for truthiness. Default element spacing is a module bottom margin taken from
  `js_composer.min.css` per element (35 px `.wpb_content_element`/`.vc_icon_element`, buttons 22px
  (`.vc_do_btn`, which overrides the stylesheet's 21.74px), 21.74 px `.vc_message_box`/
  `.vc_toggle_content`, none on inner rows, `vc_custom_heading` or any Ronneby element — the full
  per-kind table is in `docs/wpbakery-schema.md`), written at the path that module
  reads — `module.advanced.spacing` for `divi/image`, `module.decoration.spacing` elsewhere; check
  the module's own source before choosing, the same rule as every other attribute path. Three
  modules take that default as **`padding-bottom`** instead — `divi/blog`, `divi/charts`,
  `divi/circle-counter` (`GlobalSettingsResolver::PADDING_BOTTOM_MODULES`): Divi's own flex reset
  zeroes their margin at a specificity this converter's declaration cannot beat, measured by
  `BOX_MODEL=1 npx playwright test tests/e2e/module-spacing.spec.ts` (`docs/module-spacing.json`,
  updated by hand from `test-results/`). On those three an **author's** own vertical margin from
  `css` moves to padding too when the module has no background or border of its own; when it has one,
  padding would stretch that background into the gap, so the margin is kept as written and reported
  under `not_carried_over` kind `layout` — never dropped in silence.
  Blocks built by a handler's `delegate()` are pieces of one element and get no default margins.
  All of it sits behind `wbdc_layout_defaults` (`GlobalSettingsResolver`).
- Expected fixtures are a reviewed specification, not a snapshot: run
  `php scripts/render-fixture.php fixtures/wpbakery/<name>.txt --report`, read the output, then
  `php scripts/update-expected.php <name>`.
- Fixtures cut from a corpus export are cut by `scripts/cut-corpus-element.php` through `WxrReader`,
  so they hold the content **as the XML parser yields it** — CRLF normalised to LF per XML 1.0
  §2.11, which is what WordPress's own importer sees. "Byte for byte" means that; the exports under
  `wpbakery templates/` are never edited.
- Corpus noise is listed, not failed. A `[1]`-style bracket token nobody registered is re-emitted as
  text and counted under `bracketed_text` — never a warning, never a failure. `test.sh` runs
  `scripts/docker/parser-parity.php` when the container is up: it re-reads every corpus document
  with WordPress's own `do_shortcode()` regex and compares the tag stream to `ShortcodeParser`'s,
  printing one `parity ok: N documents …, M shortcodes` line. A divergence from WordPress is a
  failure; a token neither treats as a shortcode is not.
- The vendored validator (`tests/support/divi5-validator/`) was taught the block types this
  converter emits that it did not know — `divi/map-pin` (plus `ALLOWED_CHILDREN['divi/map']`),
  `divi/charts`, `divi/post-slider`, `divi/contact-form-7` — each with its `module.json` citation in
  that directory's `README.md` so the sibling project can absorb it. Its `E_MULTIPLE_H1` rule is on
  the corpus tests' ignore list: headings keep the author's level, because a converter never edits
  content semantics.
- The free plugin's global prefix is `wbdc_` / `WBDC_` (hooks, options, transients, meta, constants,
  nonces, form fields); the Pro add-on uses `wbdcp_` / `WBDCP_`. WordPress.org rejects prefixes under
  four characters and Plugin Check discards them, so never shorten them. Admin CSS classes are
  `wbdc-`. Run `scripts/plugin-check.sh free` before a release: **zero ERRORs**, and exactly three
  accepted `trademarked_term` "wp" WARNINGs — the plugin name in `readme.txt`, the plugin name in
  the header, the slug — because Plugin Check marks "wp" as "allowed, but shows a warning" and the
  term is inherent to *WPBakery*. `RELEASE.md` lists all three verbatim with that reason.
  `scripts/plugin-check.sh pro` is informational: the Pro add-on is self-hosted, so its updater and
  its missing `readme.txt` are two structural ERRORs, also quoted in `RELEASE.md`.
- Free converts one item per run (`wbdc_direct_conversion_limit` default 1); Pro raises it. Pro
  consumes four free contracts and nothing else: `wbdc_pro_active`, `wbdc_direct_conversion_limit`,
  `wbdc_library_exporter( null, $item, $options )` — asked once per item, so an exporter can return
  null for one and let the free path take over — and `WPBakeryPageRepository::filter_where()` /
  `::QUERY_FLAG`, the one source of truth for the content-match SQL. Changing any of them changes
  Pro. `ConversionCommitter`'s `convert_templates = false` **skips** a library item with a reason
  (`skipped => true`, `template_type => 'library'`), never converts it silently.
- Everything handed to `wp_update_post()` / `wp_insert_post()` **and to `update_post_meta()`** is
  `wp_slash()`ed: both unslash their input, so an unslashed value loses the backslash of every `\"`
  — the page renders `u003Cp` and `_wbdc_conversion_report` / `_wbdc_divi_data` stop being valid
  JSON.
- The version lives in three places for free (plugin header, `WBDC_PLUGIN_VERSION`, readme
  `Stable tag`) and two for Pro; the tests check they agree. `Tested up to` is read off the Docker
  container, never carried forward.
- Local site: `scripts/docker/setup_wp.sh` → **http://localhost:8020** (admin/admin), Divi 5.12.1 as
  the theme and WPBakery 9.0.1 (`js_composer`) as a plugin. 8000, 8001, 8010, 8080 and 8081 belong to
  the sibling projects; never reuse them.
- Real theme exports live in `wpbakery templates/ronneby/*.xml` (96 files, 3 committed, the rest
  re-extractable with `scripts/extract-ronneby-corpus.sh`);
  `tests/ThirdPartyTemplateConversionTest.php` requires every one on disk to convert
  validator-clean with zero skipped settings.
- Before claiming anything works: `vendor/bin/phpunit`, and `npx playwright test` when the Docker
  site is up. Report failures with their output — never a summary of what should have happened.
