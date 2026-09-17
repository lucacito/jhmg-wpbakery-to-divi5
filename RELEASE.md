# Release checklist

| Plugin | Channel | Version source |
|---|---|---|
| `jhmg-converter-for-wpbakery-to-divi-5` (free) | WordPress.org SVN | plugin header + `WBDC_PLUGIN_VERSION` + readme `Stable tag` |
| `jhmg-converter-for-wpbakery-to-divi-pro` (Pro) | divi5lab.com | plugin header + `WBDCP_PLUGIN_VERSION` |

`tests/ReleaseMetadataTest.php` and `tests/ProPluginTest.php` fail when the three free places or the
two Pro places disagree.

**1.0.0 is the first release.** The post-meta slashing fix (`1a3a140` — `update_metadata()`
unslashes, so `_wbdc_conversion_report` and `_wbdc_divi_data` were stored as invalid JSON) landed
before it, so there is nothing in the wild to migrate and no upgrade routine to write.

---

1. Working tree clean; decide the version; update it in every place above; write the readme
   changelog and upgrade notice.

2. `npm test` — PHPUnit, the parser-parity check and Playwright green. Playwright drives the Docker
   site on **`localhost:8020`** (`scripts/docker/setup_wp.sh` builds it: WordPress + Divi 5.12.1 +
   WPBakery 9.0.1 + Plugin Check, Pro installed but deactivated).

3. Read `Tested up to` **off the container**, never carried forward:

   ```
   $ docker compose exec wordpress wp core version --allow-root
   7.1
   ```

   For 1.0.0 that is `Tested up to: 7.1` in `readme.txt`, against Divi 5.12.1, WPBakery 9.0.1 and
   Plugin Check 2.1.0.

4. **`scripts/plugin-check.sh free` — the release gate.** WordPress's Plugin Check must report
   **zero ERRORs** and exactly the three accepted `trademarked_term` WARNINGs below and nothing
   else. The review team runs the same tool.

   ```
   ### jhmg-converter-for-wpbakery-to-divi-5
   FILE: readme.txt
   line	column	type	code	message	docs
   0	0	WARNING	trademarked_term	The plugin name includes a restricted term. Your chosen plugin name - "JHMG Converter For WPBakery to Divi 5" - contains the restricted term "wp" which cannot be used at all in your plugin name.

   FILE: jhmg-converter-for-wpbakery-to-divi-5.php
   line	column	type	code	message	docs
   0	0	WARNING	trademarked_term	The plugin name includes a restricted term. Your chosen plugin name - "JHMG Converter For WPBakery to Divi 5" - contains the restricted term "wp" which cannot be used at all in your plugin name.
   0	0	WARNING	trademarked_term	The plugin slug includes a restricted term. Your plugin slug - "jhmg-converter-for-wpbakery-to-divi-5" - contains the restricted term "wp" which cannot be used at all in your plugin slug.
   ```

   All three are the **same term**, "wp", reported once for the plugin name in `readme.txt`, once
   for the plugin name in the plugin header, and once for the slug. Plugin Check's own trademark
   list marks "wp" as **"allowed, but shows a warning"** (`Trademarks_Check.php:141`), and the term
   is inherent to the word *WPBakery* — the plugin cannot name what it converts without it. The
   warnings are accepted; only ERRORs gate the release. Renaming the plugin to avoid them is
   Lucas's call and would have to happen before submission.

5. **`scripts/plugin-check.sh pro` — informational, not a gate.** The Pro add-on is self-hosted: it
   ships a licence-server updater and has no wordpress.org readme by design, so two of its findings
   are structural and permanent:

   ```
   ### jhmg-converter-for-wpbakery-to-divi-pro
   FILE: includes/class-plugin.php
   line	column	type	code	message	docs
   0	0	ERROR	plugin_updater_detected	Plugin Updater detected. These are not permitted in WordPress.org hosted plugins. Detected: site_transient_update_plugins

   FILE: readme.txt
   line	column	type	code	message	docs
   0	0	ERROR	no_plugin_readme	The plugin readme.txt does not exist.
   ```

   The run also reports, expectedly: two `update_modification_detected` WARNINGs
   (`pre_set_site_transient_update_plugins`, `_site_transient_update_plugins`), a
   `load_plugin_textdomain` discouraged-function WARNING (needed off-directory), a
   `plugin_header_requires_plugins_not_in_directory` WARNING for the free plugin's slug until it is
   published, and the same two `trademarked_term` "wp" WARNINGs on the Pro name and slug. None of
   these is a release blocker for a self-hosted plugin; the free plugin's gate in step 4 is
   unaffected.

6. **Licence-client provenance.** `Pro\Licensing\LicenseClient` is the canonical divi5lab client
   with the namespace and the text-domain literals changed and **nothing else**. Prove it before
   every release, so a future client update can be re-applied the same way:

   ```bash
   diff /Users/Lucas/Documents/JHMG-Local/layoutlab/lib/license-server/php-client/class-license-client.php \
        plugin/jhmg-converter-for-wpbakery-to-divi-pro/includes/licensing/class-license-client.php
   ```

   It may print exactly four hunks: the `namespace` line
   (`namespace ElementorDivi5Converter\Pro\Licensing;` →
   `namespace WPBakeryDivi5Converter\Pro\Licensing;`) and three pairs of `__()` calls whose text
   domain changes from `jhmg-converter-for-elementor-to-divi-pro` to
   `jhmg-converter-for-wpbakery-to-divi-pro` — six literals, same strings. Line numbers move with
   every client update; the shape does not. Anything else in the diff is drift and has to be
   reconciled before shipping. (The canonical copy currently carries the Elementor project's
   namespace and domain because that was the last port; the client logic is identical.)

7. `npm run i18n` when user-facing strings changed (`npm run i18n:free`, `npm run i18n:pro`).

8. **Free — build and submit.** Build the wordpress.org submission zip with
   `scripts/build-submission-zip.sh` — it reads the version off the plugin header, writes
   `dist/jhmg-converter-for-wpbakery-to-divi-5-<version>.zip` and prints the path and its SHA-256. The
   listing assets it goes with (icon, banners, screenshots) live in `wporg-assets/`, with the
   submission notes and the render commands in `wporg-assets/README.md`. Then:

   ```bash
   rsync -a --delete --exclude='.DS_Store' --exclude='.svn' \
     plugin/jhmg-converter-for-wpbakery-to-divi-5/ wporg-svn/trunk/
   svn status                     # review
   svn add --force trunk
   svn cp trunk tags/<version>
   svn ci -m "Release <version>"
   ```

   `wporg-svn/` is gitignored; recreate it with
   `svn co https://plugins.svn.wordpress.org/jhmg-converter-for-wpbakery-to-divi-5/ wporg-svn`.
   Assets go in `wporg-svn/assets/`, not in `trunk/`.

9. **Pro — build and publish.** Zip `plugin/jhmg-converter-for-wpbakery-to-divi-pro/` (no
   `.DS_Store`), upload to divi5lab.com, then confirm
   `/api/plugin/update-check?product=wpbakery-to-divi5-pro` serves the new `version` and a `package`
   URL, and that a clean site with a valid licence receives the update.

10. `AdminPage::PRO_PRICE` (`$25/yr`) must match the divi5lab.com listing at
    `https://divi5lab.com/plugins/wpbakery-to-divi-5`; nothing enforces it.

---

## Version and metadata facts

| | |
|---|---|
| Requires at least | WordPress 5.9 |
| Requires PHP | 8.0 |
| Converted output requires | Divi ≥ 5.0.0 (`Helpers\DiviRequirement`) |
| Pro requires | the free plugin (`Requires Plugins: jhmg-converter-for-wpbakery-to-divi-5`) |
| Pro product slug | `wpbakery-to-divi5-pro` (`WBDCP_PRODUCT_SLUG`) |
| Licence API base | `https://divi5lab.com` (`WBDCP_API_BASE`) |

Prefixes are `wbdc_` / `WBDC_` (free) and `wbdcp_` / `WBDCP_` (Pro) on every hook, option,
transient, post meta, constant, nonce and form field; admin CSS classes are `wbdc-`.
WordPress.org rejects prefixes under four characters and Plugin Check discards them, so **never
shorten them**.
