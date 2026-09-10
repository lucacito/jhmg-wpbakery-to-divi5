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
- Divi appends the unit to gradient stop positions itself: write `position: "23"`, never `"23%"`, or
  the whole background declaration (image included) is dropped.
- Icon settings are written `unicode, type, weight` in that order: Divi's asset detector regex
  matches `"unicode"…"type":"fa"` and otherwise never enqueues Font Awesome.
- Colours are never invented. A palette name Divi cannot resolve is reported under
  `unresolved_globals`, not guessed at.
- Every handler calls `logUnmappedSettings()` with every key it consumed; nothing is dropped
  silently. Unknown keys switched off (`no|none|off|false`) are not "skipped settings".
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
  `.vc_toggle_content`, none on inner rows or `vc_custom_heading`), written at the path that module
  reads — `module.advanced.spacing` for `divi/image`, `module.decoration.spacing` elsewhere; check
  the module's own source before choosing, the same rule as every other attribute path. Three
  modules take that default as **`padding-bottom`** instead — `divi/blog`, `divi/charts`,
  `divi/circle-counter` (`GlobalSettingsResolver::PADDING_BOTTOM_MODULES`): Divi's own flex reset
  zeroes their margin at a specificity this converter's declaration cannot beat, measured by
  `BOX_MODEL=1 npx playwright test tests/e2e/module-spacing.spec.ts` (`docs/module-spacing.json`).
  Blocks built by a handler's `delegate()` are pieces of one element and get no default margins.
  All of it sits behind `wbdc_layout_defaults` (`GlobalSettingsResolver`).
- Expected fixtures are a reviewed specification, not a snapshot: run
  `php scripts/render-fixture.php fixtures/wpbakery/<name>.txt --report`, read the output, then
  `php scripts/update-expected.php <name>`.
- The free plugin's global prefix is `wbdc_` / `WBDC_` (hooks, options, transients, meta, constants,
  nonces, form fields); the Pro add-on uses `wbdcp_` / `WBDCP_`. WordPress.org rejects prefixes under
  four characters and Plugin Check discards them, so never shorten them. Admin CSS classes are
  `wbdc-`. Run `scripts/plugin-check.sh free` at zero findings before a release.
- Free converts one item per run (`wbdc_direct_conversion_limit` default 1); Pro raises it.
  Content handed to `wp_update_post()` / `wp_insert_post()` is `wp_slash()`ed, or the JSON escapes in
  Divi block attributes lose their backslashes and the page renders `u003Cp`.
- The version lives in three places for free (plugin header, `WBDC_PLUGIN_VERSION`, readme
  `Stable tag`) and two for Pro; the tests check they agree. `Tested up to` is read off the Docker
  container, never carried forward.
- Local site: `scripts/docker/setup_wp.sh` → **http://localhost:8020** (admin/admin), Divi 5.12.1 as
  the theme and WPBakery 9.0.1 (`js_composer`) as a plugin. 8000, 8001, 8010, 8080 and 8081 belong to
  the sibling projects; never reuse them.
- Real theme exports supplied by Lucas live in `wpbakery templates/*.xml`;
  `tests/ThirdPartyTemplateConversionTest.php` requires them to convert validator-clean with zero
  skipped settings.
- Before claiming anything works: `vendor/bin/phpunit`, and `npx playwright test` when the Docker
  site is up. Report failures with their output — never a summary of what should have happened.
