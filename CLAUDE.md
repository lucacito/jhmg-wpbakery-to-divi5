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
  (and the deprecated tags: `vc_button`/`vc_button2`/`vc_cta_button` → `vc_btn`, `vc_tabs`/`vc_tour`/
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
  defaults. Section padding `0` unless the row's design options set one; row padding `0`; column
  padding `0 15px` (plus `gap/2` on all sides when the row sets `gap`, plus `35px` on top when the
  row is filled); row `columnGap`/`rowGap` `0px` (the string `"0px"`, never `"0"`). Divi's
  `.et_pb_row` keeps `max-width: 1080px`, so WPBakery's `-15px` row bleed cannot be reproduced with
  `width: calc(100% + 30px)` — the converted content is inset 15 px per side instead, and that is the
  accepted approximation. Default module spacing goes on the column's `rowGap` (35px), not on
  per-module margins: Divi emits the text module's margin `!important` but not the image module's,
  where `flex_grid.css` overrides it to `0`. Blocks built by a handler's `delegate()` are pieces of
  one element and get no default margins. Section padding `0` also drops the first row under Divi's
  fixed header (measured: content at y=224, header bottom at y=271) — the theme offset a WPBakery
  page gets from `.container` is a separate decision. All of it sits behind `wbdc_layout_defaults`
  (`GlobalSettingsResolver`).
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
