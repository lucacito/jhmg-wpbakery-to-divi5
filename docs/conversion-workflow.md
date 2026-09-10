# The conversion workflow, and what the Docker site can prove about it

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
