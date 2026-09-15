# WordPress.org listing assets

Files for the plugin directory's SVN `assets/` folder (separate from the plugin itself; committed
after approval alongside `trunk/`). Screenshot numbers match the `== Screenshots ==` captions in
`plugin/jhmg-converter-for-wpbakery-to-divi/readme.txt`.

- `icon.svg`, `banner-772x250.svg`, `banner-1544x500.svg` — the drawings, and the only thing edited
  by hand. The motif is the one the Beaver Builder and Elementor listings use: source mark → arrow →
  Divi 5 disc, on a gradient from the source builder's brand colour to Divi purple.
- `icon-128x128.png`, `icon-256x256.png`, `banner-772x250.png`, `banner-1544x500.png` — renders of
  those SVGs at exactly those pixel sizes. Never edited; re-render instead.
- `screenshot-1.png` … `screenshot-3.png` — 1280px wide, full-page, taken from the Docker site.

## Colours

| Role | Hex | Where it comes from |
|---|---|---|
| WPBakery teal (gradient start, brand side) | `#00c1cf` | `js_composer/assets/css/js_composer.min.css` in `references/js_composer.9.0.1.zip`: `.vc_btn-turquoise,a.vc_btn-turquoise,button.vc_btn-turquoise{background-color:#00c1cf;…}` — "turquoise" is WPBakery's own palette name for it (the same hex the converter records in `Color::PALETTE`), and it is the accent WPBakery uses throughout its shortcode styles. |
| Dark teal (the "WPB" disc) | `#085b61` | The same stylesheet, WPBakery's dark turquoise: `.vc_color-turquoise.vc_message_box{background-color:#ebfcfd;border-color:#c6ecee;color:#085b61}`. White text on it clears WCAG AA. |
| Divi purple (gradient end) | `#7a2ee6` | Divi's brand purple, as on the sibling listings. |
| Divi disc / badge | `#6b21a8`, `#3b0764` | Unchanged from the sibling listings' motif. |

`assets/images/` inside the WPBakery zip holds no brand colour at all — it is jQuery UI furniture,
social icons and a spinner (checked pixel by pixel) — so the teal was sampled from the stylesheet
next to it rather than guessed.

## Commands

```bash
npm run assets                 # SVG → PNG at exact sizes (Playwright Chromium)
npm run assets -- screenshots  # re-take screenshot-1..3.png off the Docker site
scripts/plugin-check.sh free   # the release gate; see the submission notes below
scripts/build-submission-zip.sh  # dist/jhmg-converter-for-wpbakery-to-divi-<version>.zip + SHA-256
```

`npm run assets` runs `scripts/render-assets.ts` on Node's own type stripping — no TypeScript
runner is installed for it. `playwright.config.ts` collects only `tests/e2e`, so the script is never
picked up as a spec.

## What the screenshots show

Taken on the Docker site from `scripts/docker/setup_wp.sh` at http://localhost:8020, logged in as
admin, viewport 1280px wide, with the Pro add-on installed but **deactivated**:

| Component | Version |
|---|---|
| WordPress | 7.1 (`wp core version`) |
| Divi (theme) | 5.12.1 |
| WPBakery Page Builder (`js_composer`) | 9.0.1 |
| Ultimate Addons for WPBakery (`Ultimate_VC_Addons`) | 3.19.3 |
| Plugin Check | 2.1.0 |
| This plugin | 1.1.0 |

Procedure, so the shots can be reproduced:

1. Seed pages from `fixtures/wpbakery-layouts/` (the Layouts for WPBakery corpus) with
   `wp post create` + `scripts/docker/set-wpbakery-content.php`.
2. Choose the page the report and results shots are taken of, and make sure it is on the picker's
   first screen — the picker orders by modified date, so
   `wp post update <id> --post_modified=<now> --post_modified_gmt=<now>` brings it to the top. A
   mid-sized layout reads best: `13-nectar-cafe` (53 modules) fits its whole report on one screen,
   where a 108-module page produces 4,000px of outline that says nothing at listing scale.
3. `wp option delete wbdc_import_history` and `wp option update wbdc_conversions_total 0`, so
   "Recent conversions" shows this run rather than a pile of test runs, and the review prompt does
   not appear in frame.
4. `WBDC_SHOT_PAGE='<page title>' npm run assets -- screenshots`. It logs in, dismisses the other
   plugins' admin notices, then shoots 2 (the check report), 3 (the results screen) and 1 (the
   landing screen) in that order — the landing screen only has a run to undo once a run has
   happened, which is what caption 1 promises.
5. Since 1.1.0 converting rewrites the page it was given, so the run changes a real page. The
   script undoes its own run at the end (accepting the confirmation the Undo link asks for) and
   says so, leaving the site as it found it. Check that it did: the page's content should be
   shortcodes again and `_et_pb_use_divi_5` gone.

## Submission notes

`scripts/plugin-check.sh free` is the gate: **0 ERRORs**. It reports exactly three WARNINGs, all of
them the same one, and all three are accepted by standing decision — Plugin Check classes "wp" as a
term that is allowed but warned about, and the plugin name and slug are the ones the product ships
under:

```
FILE: readme.txt
line	column	type	code	message	docs
0	0	WARNING	trademarked_term	The plugin name includes a restricted term. Your chosen plugin name - "JHMG Converter For WPBakery to Divi 5" - contains the restricted term "wp" which cannot be used at all in your plugin name.	


FILE: jhmg-converter-for-wpbakery-to-divi.php
line	column	type	code	message	docs
0	0	WARNING	trademarked_term	The plugin name includes a restricted term. Your chosen plugin name - "JHMG Converter For WPBakery to Divi 5" - contains the restricted term "wp" which cannot be used at all in your plugin name.	
0	0	WARNING	trademarked_term	The plugin slug includes a restricted term. Your plugin slug - "jhmg-converter-for-wpbakery-to-divi" - contains the restricted term "wp" which cannot be used at all in your plugin slug.
```

The "wp" being flagged is the one inside **WPBakery** — the builder this plugin converts, named in
the plugin name and slug so a user can find it. readme.txt says in its `== Description ==` that the
plugin is not affiliated with or endorsed by WPBakery Page Builder, and the name is descriptive of
what it does, which is what the directory asks for. If the review team asks for a different name,
only the name and slug change; nothing in the code depends on them.

### The slug the directory assigns

The sibling Beaver Builder plugin was submitted as `jhmg-converter-for-beaver-builder-to-divi` and
the directory assigned it the permalink `jhmg-converter-for-beaver-builder-to-divi-5`, after which
the plugin folder, main file and text domain were renamed to match. Expect the same here: if the
permalink comes back as `jhmg-converter-for-wpbakery-to-divi-5`, rename the folder, the main file
and the text domain, and update the download link on divi5lab.com
(`lib/site/free-downloads.ts`).

`Tested up to` in readme.txt is `7.1`, read off this container with
`docker compose exec -T wordpress wp core version --allow-root` at the time of the release, never
carried forward from a previous one.
