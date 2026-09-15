# Conversion workflow

```
WPBakery post (post_content + 3 metas)           upload (.xml WXR / .txt / .html)
        │  InstalledPostSource                            │  WPBakeryImportParser
        └──────────────────────┬─────────────────────────┘
                               ▼
                     ConversionPreflight  ── writes nothing; one fresh ConverterEngine per item
                               │             (capped at `wbdc_direct_conversion_limit`)
                          ConversionPlan  ── blocks, serialized content, report, outline
                               │  (the "Check this page" screen renders this)
                               ▼
                     ConversionCommitter  ── the only class that creates posts
                               │
                     DiviExporter.save()  ── block content + Divi meta, both wp_slash()ed
                               │
                       ImportHistory      ── recorded per run, undoable via ImportRollback
```

Nothing is rendered or scraped — the engine reads the shortcode string and the three WPBakery metas
(`_wpb_shortcodes_custom_css`, `_wpb_post_custom_css`, `_wpb_vc_js_status`).

## Which post the conversion writes

Since 1.1.0 there are two answers, and the default changed.

| | in place (default) | `create_new` (opt in) |
|---|---|---|
| What is written | the post you picked | a second post beside it |
| Permalink, date, author, comments, custom fields | kept, because nothing moved | the copy carries everything except the permalink, which WordPress dedupes to `-2` |
| Post status | unchanged: a published post stays published | `post_status`, `draft` by default |
| Undo | restores `_wbdc_original_content` onto the post and removes what the conversion wrote; no Trash needed | trashes the post it created |
| Marked by | `_wbdc_converted_in_place` = `1` | `_wbdc_source_post_id` on the new post |

The reason for the default is the permalink: two published posts cannot share a slug, so a copy is
always at a new address, and the author is left redirecting the old one. Reported by a user who
converted a post and found it at `-2`.

**A WPBakery template is always copied**, whatever the caller asks for. A template is what the reader
builds pages from; rewriting it as a Divi page would take it away from them. Uploads are copies too,
for the obvious reason that a file is not a post.

`ConversionPreflight` still writes nothing in either mode, which is what makes "Check this page"
worth clicking.

`WPBakeryImportParser` accepts two upload formats, because there are two ways a WPBakery page leaves
the site it was built on: a **WordPress export (WXR)** — Tools → Export, or a theme's demo content —
whose attachments become the item's media map, and a **`.txt` / `.html`** file holding the
shortcodes themselves. There is no third format: WPBakery has no layout export of its own. One
upload parses at most `WPBakeryImportParser::MAX_ITEMS` (500) items, and reaching that ceiling is
recorded in the warnings rather than passed over.

## In the admin

Tools → **WPBakery → Divi 5** (`tools.php?page=wbdc-converter`, `manage_options`):

- **Convert a page already on this site** — pick a page from the picker, **Check this page**
  (`wbdc_direct_check`) → the report → **Convert to Divi 5** (`wbdc_direct_convert`) → results
  (Edit / View / Publish) → Recent conversions (Undo). Every submitted post ID is re-verified
  server-side as an existing post that really holds WPBakery content, and the selection is capped at
  `wbdc_direct_conversion_limit` (default **1** in free) — the rendered picker is never trusted on
  the way back in.
- **Upload a file** — `wbdc_import` → the options screen → `wbdc_import_convert` → the same results
  screen. Upload results are kept for one hour.

Pro adds Tools → **WPBakery → Divi 5 Pro** (`tools.php?page=wbdcp-pro`) with the Divi Library
templates tab and the licence tab.

## From the command line (Docker)

```bash
scripts/docker/setup_wp.sh                      # boot everything, seed and convert two pages
WP=$(docker compose ps -q wordpress)
docker exec -i $WP bash -lc "TEMPLATE=about-section wp eval-file /tmp/import-wpb-template.php --allow-root"   # → source id
docker exec -i $WP bash -lc "SOURCE_PAGE_ID=<id> wp eval-file /tmp/convert-to-new-page.php --allow-root"      # → new id
```

Other helpers under `scripts/docker/`: `set-wpbakery-content.php` (write a fixture onto a page),
`set-divi-content.php` (write a hand-written Divi document), `convert-run.php` (convert in place),
`import-wpb-template.php` (also takes `WXR=<file>` to seed a page from an export),
`parser-parity.php` (check the shortcode parser against WordPress's own regex).

## Output post

| Key | Value |
|---|---|
| `post_content` | `<!-- wp:divi/placeholder -->…` block markup |
| `_et_pb_use_builder`, `_et_pb_use_divi_5` | `on` |
| `_et_builder_version` | `VB\|Divi\|<version>` |
| `_wbdc_divi_data` | the intermediate block tree (JSON) |
| `_wbdc_conversion_report` | the report plus `unsupported` (JSON) |
| `_wbdc_import_source` | `direct` or `file_upload` |
| `_wbdc_source_post_id` | the WPBakery post it came from (direct conversions) |

`ImportRollback` only touches posts still carrying `_wbdc_import_source`, so it can never delete a
page the converter did not create.

## What the new post keeps of the old one

The conversion writes a new post, so everything the old post *was* would start empty unless it is
carried over. `Conversion\PostIdentityCopier` carries it (a user converted a post and found his ACF
fields blank):

| Carried | Left behind |
|---|---|
| Every custom field, ACF included, each value of a repeated key, slashed on the way in | `_wpb_*` — WPBakery's own bookkeeping, describing a builder this post no longer uses |
| `_thumbnail_id` (the featured image) and `_wp_page_template`, which are meta like any other | `_et_pb_*` / `_et_builder*` — Divi's, which the conversion writes itself |
| Categories, tags and any other taxonomy **both** post types share, assigned **by term id** so a hierarchical term is reused rather than recreated from its slug | `_edit_lock`, `_edit_last` — whoever had the old post open |
| `post_date`, `post_date_gmt`, `post_author`, `post_excerpt`, `menu_order`, `post_parent`, `comment_status`, `ping_status` | `_wbdc_*` — ours, recording where this post came from |

Two filters adjust it: `wbdc_copy_source_identity` (false converts into a bare post) and
`wbdc_copied_meta_keys( array $keys, int $source_id, int $new_post_id )`.

The **permalink** is the one thing that cannot come along. The new post is created as a draft and
`wp_unique_post_slug()` leaves a draft's `post_name` alone, so it holds the original's slug right up
until it is published — at which point WordPress appends `-2`, because the original still holds it.
Whoever converts has to decide which of the two keeps the permalink, and redirect the other. An
upload has no source post at all, so nothing is copied for it.

## Pro and free: what couples them

Pro is an add-on, not a fork. It depends on the free plugin at four named points, and a change to
any of them affects Pro:

| Free | Pro uses it for |
|---|---|
| filter `wbdc_pro_active` | Pro returns true; the free admin screens drop their upsell and unlock the Pro-only copy |
| filter `wbdc_direct_conversion_limit` | Pro raises the per-run cap to `PHP_INT_MAX` |
| filter `wbdc_library_exporter( null, array $item, array $options )` | `ConversionCommitter` asks it once **per item**, so Pro's `DiviLibraryExporter` can answer null for one (a lapsed licence, a template type it does not handle) and let the free path take over. A library item that falls through gets a warning saying so |
| `Admin\WPBakeryPageRepository::filter_where()` + `::QUERY_FLAG` | Pro's `Admin\TemplatesRepository` reuses them rather than repeating the content-match SQL, so the "is this a WPBakery layout?" query has one source of truth |

`ConversionCommitter`'s `convert_templates` option is the other half: when it is `false` a library
item is **skipped with a reason** (`'skipped' => true` and `template_type => 'library'` on the
result) rather than converted silently. Pro's templates screen sets it to `true`, because every item
on that screen is a WPBakery template.

The licence client (`Pro\Licensing\LicenseClient`) is the canonical divi5lab client with the
namespace and the six text-domain literals changed and nothing else — `RELEASE.md` records the
`diff` that proves it.

## The Docker site, and what it can prove

`scripts/docker/setup_wp.sh` builds the site the Playwright suite drives:
WordPress with **Divi 5.12.1** as the theme and **WPBakery Page Builder 9.0.1** as a plugin, both
converter plugins mounted from `plugin/`, Plugin Check, and — when the archive is in `references/` —
one real WPBakery add-on. It seeds two pages through the shipped pipeline: one of WPBakery's own
bundled templates (`fixtures/wpbakery-templates/about-section.txt`, converted through
`ConversionPreflight` → `ConversionCommitter`, exactly as the Tools screen does), and one page from a
real theme export (`wpbakery templates/ronneby/15_tenth.xml`, through `WPBakeryImportParser`).

Pro is installed but left **deactivated**: the free plugin converts one page per run and its screens
are worded around that, and the e2e suite is the free plugin's evidence. Switch Pro on by hand to
work on it.

## The two paths a theme element takes

An element this converter has no handler for is never dropped
(`Converter\Handlers\ThemeShortcodeConverter`). Which of two things happens to it depends on where
the page came from, not on the element:

| where the page came from | what happens | what the report says |
|---|---|---|
| a post on this site (`mode: direct`) | the plugin that registers the shortcode is active, so `do_shortcode()` renders it and the HTML is kept in a `divi/code` block — a **static copy** that looks like the live page and will not update when its plugin does | `static_copies`, plus the family under `theme_elements` |
| an uploaded file (`mode: import`) | nothing can render it, so it becomes a **labelled placeholder** keeping the tag, its text and its position | `not_carried_over` with kind `addon`, plus `theme_elements` |

`tests/e2e/conversion-workflow.spec.ts` proves both on the real site with the same shortcode.

## Why Ronneby Core is not the add-on the suite uses

The obvious candidate was **Ronneby Core** — it registers the `dfd_*` elements the committed exports
are full of, and `references/ronneby-core.zip` is already on disk. It cannot play the part, and the
reason is in the plugin, not in this converter:

- `ronneby-core.php` line 16 requires `get_template_directory() . '/inc/helpers.php'` before it
  defines anything. With Divi as the active theme that file does not exist, and **the plugin fatals
  on activation** (`Fatal error: Failed opening required
  '/var/www/html/wp-content/themes/Divi/inc/helpers.php'`).
- Even past that, `Dfd_Ronneby_Core_Plugin::init()` loads no components at all unless
  `wp_get_theme()->get('Name')` contains `DFD Ronneby` **and** the site option
  `dfd_ronneby_theme_activated` is `active`; otherwise it only prints an admin notice, "Ronneby Core
  plugin is enabled but not effective. It requires Ronneby theme installed and activated in order to
  work." Verified on the site: with the theme-helper class stubbed so the plugin could activate,
  **not one** `dfd_heading`, `dfd_spacer`, `dfd_single_image`, `dfd_button`, `dfd_info_box`,
  `price_list`, `dfd_carousel`, `rotate_box`, `piecharts`, `facts` or `announcement` was registered.

So on a Divi site nothing of Ronneby's renders, and its `ronneby-core.zip` mount was dropped from
`docker-compose.yml`. This is a fact about that theme's packaging; it changes nothing about the
conversion, which reads shortcodes rather than rendered HTML — a Ronneby export converts identically
either way, and its unhandled elements simply take the placeholder path, which is the correct one for
a page that arrived as a file.

What a Ronneby site actually gets, then, is this: the **23 tags with handlers of their own**
(`ConverterRegistry::registerRonneby()`, listed in `docs/conversion-map.md`) become real Divi
modules in either mode, because they are converted from the shortcode and need nothing rendered.
The rest take the two paths in the table above — **static copies** when the conversion runs on the
Ronneby site itself, where the theme is active and `do_shortcode()` renders them; **labelled
placeholders** from an export, where nothing can. The Docker site does not install Ronneby Core, so
the e2e static-copy case uses Ultimate Addons instead.

**Ultimate Addons for WPBakery** (`references/Ultimate_VC_Addons.zip`) is used instead. It is a real
WPBakery add-on that runs under any theme, `setup_wp.sh` installs it when the archive is present and
switches on only the one module the suite needs (through the plugin's own `ultimate_modules` option),
and `ultimate_heading` is a genuine theme-family element with no handler here — so the static-copy and
placeholder paths are exercised against a plugin's real output. The suite skips that case when the
archive is absent.

## What the site cannot prove

- **Contact Form 7** is not installed, so `divi/contact-form-7` renders nothing here.
- Fixtures that name attachment ids (`vc_single_image image="1096"`, `vc_gallery images="1545,…"`)
  carry those only as an import-time media map; this site's media library has no such attachments, so
  the module is written with an empty source. `single-image-external` covers the source that survives.
- WPBakery's own `wpb_remove_custom_html` strips a `vc_btn` with `custom_onclick`, and every element
  in `wpb_get_elements_with_custom_html()` (`vc_gmaps`, `vc_raw_html`, `vc_raw_js`, …), out of
  `post_content` on save unless the saving user has `unfiltered_html` — and WP-CLI saves as no user.
  Seeding those fixtures through `wp_update_post()` therefore stores a shorter page than the fixture
  file holds. Nothing to fix: it is WPBakery protecting its own content, and the converter is tested
  on those elements by the PHPUnit corpus instead.
