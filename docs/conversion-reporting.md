# Conversion reporting

Every conversion produces a report. It is shown on the check screen and the results screen and
stored as JSON in `_wbdc_conversion_report` on the new post. The keys are built by
`Converter\ConverterEngine::getReport()`; the `unsupported` list is returned beside it by
`convert()` and merged in by `Exporters\DiviExporter::export()`.

Read it back: `wp post meta get <id> _wbdc_conversion_report`.

```json
{
  "converted":           { "section": 3, "row": 3, "column": 6, "heading": 3, "text": 3, "button": 3, "code": 1 },
  "approximate":         { "blog": 1 },
  "approximate_matches": [ { "node_id": "vc_basic_grid-7", "tag": "vc_basic_grid", "matched_to": "PostsGridConverter" } ],
  "warnings":            [ "vc_single_image-3: attachment 1096 has no URL to fall back on" ],
  "skipped_settings":    [ "vc_progress_bar-2: customcolor" ],
  "unresolved_globals":  [ { "node_id": "vc_btn-4", "setting_key": "custom_background", "ref": "theme-accent" } ],
  "not_carried_over":    [ { "kind": "animation", "node_id": "vc_row-1", "detail": "css_animation: fadeInLeft" } ],
  "theme_elements":      { "Ronneby": 12, "Ultimate Addons": 6 },
  "static_copies":       [ "ultimate_heading-9" ],
  "custom_css_carried":  [ "vc_column-5" ],
  "unresolved_media":    [ { "node_id": "vc_gallery-2", "attachment_id": 1545 } ],
  "text_nodes":          2,
  "bracketed_text":      12,
  "quality":             { "module_coverage": 92, "settings_issues": 1 },
  "unsupported":         [ { "id": "vc_acf-3", "tag": "vc_acf" } ]
}
```

| Field | Shape | Meaning |
|---|---|---|
| `converted` | module short name ⇒ count | One Divi module produced by a source-verified handler (`logConverted()`). |
| `approximate` | module short name ⇒ count | The same, for a handler the registry marks `approximate: true`. |
| `approximate_matches` | `{node_id, tag, matched_to}` | One entry per **source element** an approximate handler claimed. Coverage counts source elements, not blocks, so this list — not `approximate` — is the denominator's approximate half. |
| `warnings` | strings, de-duplicated | Non-fatal issues: an empty row or column, a missing image source, a structural node with no handler, a handler that threw, and values WordPress read positionally rather than as `key="value"` pairs. |
| `skipped_settings` | `"node_id: key"`, de-duplicated | A field **WPBakery declares for that element** (`data/wpbakery-params.json`) that no handler consumed. This is the converter's own to-do list. |
| `unresolved_globals` | `{node_id, setting_key, ref}` | A colour name none of the WPBakery tables knows. The property is left unset, never guessed. |
| `not_carried_over` | `{kind, node_id, detail}`, de-duplicated | Something Divi cannot express, or something that belongs to a theme. Kinds below. |
| `theme_elements` | family label ⇒ count | How many elements of each theme/add-on family the page held (`Helpers\ThemeShortcodes`). |
| `static_copies` | node ids | Shortcodes rendered with `do_shortcode()` on this site and kept as HTML. They look like the live page and will **not** update when their plugin does. |
| `custom_css_carried` | node ids | Nodes whose design-options CSS could not be expressed as a Divi setting and was written to `css.desktop.value.mainElement` verbatim. Nothing was dropped. |
| `unresolved_media` | `{node_id, attachment_id}` | An attachment id with no URL: not on this site and not in the export's media map. |
| `text_nodes` | integer | Runs of text that sat between shortcodes and were kept as `divi/text` modules. |
| `bracketed_text` | integer | `[1]`-style tokens re-emitted as text — a footnote marker, a citation, a stray bracket. Counted, never a warning: a page full of them reads as "12 bracketed tokens kept as text" rather than as twelve separate findings. |
| `quality.module_coverage` | percent | `converted ÷ (converted + approximate_matches + unsupported)`. 100 when there was nothing to convert. |
| `quality.settings_issues` | integer | `count( skipped_settings )`. |
| `unsupported` | `{id, tag}` | A **core** WPBakery tag with no handler; each left a labelled placeholder. A theme's shortcode is never unsupported — it is handled, always. |

## `not_carried_over` kinds

All ten are in use. The admin renderer (`Admin\NotCarriedOverRenderer`) splits `addon` off into its
own "Theme and add-on features" block and labels the rest:

| kind | Label on screen |
|---|---|
| `animation` | Animations — removed |
| `visibility` | Visibility rules — removed; the element shows to everyone |
| `background` | Backgrounds that could only be approximated |
| `layout` | Layout options with no Divi equivalent |
| `hover` | Hover colours — the resting colours are kept |
| `interaction` | Click actions and interactive behaviour — needs rebuilding in Divi |
| `integration` | Third-party connections — reconnect them in the Divi module |
| `custom_code` | Custom CSS and JavaScript — copy it into Divi → Theme Options if it is still needed |
| `error` | Replaced by a placeholder because its handler failed; the original shortcode is preserved inside it |
| `addon` | (its own block) a theme's or add-on's element, or a parameter one of them added |

## What is *not* a skipped setting

`BaseWPBakeryConverter::logUnmappedSettings()` is called by every handler with every key it
consumed, so nothing is dropped in silence. What it does **not** report as a skipped setting:

- Keys the StyleMapper owns and reports itself: `css`, `el_id`, `el_class`, `css_animation`, `disable_element`.
- Editor-only labels and generated ids: `row_title`, `flexbox_container_title`, `grid_container_title`, `section_index`, `tab_id`.
- Empty values (`''`, `null`, `[]`, `false`).
- Toggles that are **off** — `no`, `none`, `off`, `false`. An unknown key switched off is not a skipped setting.
- An **inert icon-library field**: WPBakery keeps one `icon_<library>` field per library on every element that takes an icon, and only the one the element's `type` / `icon_type` / `i_type` selects is drawn. The other eight hold a leftover glyph name and are not settings anybody skipped.
- `vc_text_separator`'s `layout`, which names an editor preset rather than a rendered difference.
- **Theme parameters on a core element** — a field a theme bolted on with `vc_add_param()` and `ThemeShortcodes::THEME_PARAMS` has source for. One `addon` entry per node naming every such field, with the family: *"… a parameter added by a theme (matches Ronneby's table of the fields it bolts onto WPBakery elements with vc_add_param)"*.
- **Parameters WPBakery never declared** for that tag (`WPBakeryParams::declares()` returns `false`). One `addon` entry per node: *"… parameters added by a theme or plugin (not declared by WPBakery). The only theme or add-on family on this page is Ronneby"*. The family is named only when exactly one `kind: addon` family's elements are on the page (`ConverterEngine::detectedFamily()`, computed off the whole tree before the walk, so an attribution made on the first row already knows about the last); with two it ends "They are drawn by whatever added it, and have no Divi equivalent".
- **Values WordPress read positionally.** `shortcode_parse_atts()` gives a numbered key to anything that is not a `key="value"` pair, and no WPBakery element takes positional attributes, so these go into a warning naming the element rather than into `skipped_settings`: nothing here ignored a setting, the source never held one under a name.

A key WPBakery **does** declare and no handler read is a skipped setting — that distinction is the
whole point of `data/wpbakery-params.json`, and it is why "unknown" (`declares()` → `null`, six core
tags) is kept apart from "declares nothing" (`[]`).

**A consumed key is either written or reported — never merely claimed.** A handler that passes a key
to `logUnmappedSettings()` has either mapped it onto a Divi attribute or filed it under
`not_carried_over`.

## Counting rules

- **Flattened children are not counted.** `ContentFlattener` converts a tab's or an accordion item's children only to render them back to HTML; the blocks are thrown away. `ConverterEngine::withConvertedCountSuppressed()` suppresses `logConverted()` for that call so `quality.module_coverage` is not inflated with modules that do not exist. Only the count is suppressed: warnings, `not_carried_over`, static copies, skipped settings, unresolved media and theme-element counts all still record, because they describe the source, which is real whatever happened to the blocks.
- **One approximate element may emit several blocks.** `approximate` counts blocks; `approximate_matches` counts source elements, and that is what coverage uses. All of that element's blocks are counted, including the ones it emits after converting its children.
- **Approximate does not spread downwards.** The flag is scoped to the node the registry marked (`ConverterEngine::convertNode()` saves and restores it): a container matched approximately converts its children in place, and an exact module inside it — a `divi/button` in an `ult_content_box` — is counted under `converted`.
- **Blocks a handler builds through `delegate()`** are pieces of one element: they carry no default margin and are not separately counted.

## Error boundary

The engine catches `\Throwable` per node (`ConverterEngine::handlerFailed()`): it logs a warning
naming the node id, the tag and the exception, records `not_carried_over` kind `error`, and falls
back to the labelled placeholder every unconvertible element gets. **The page always converts.**

## Markup the converter does not publish

`ConverterEngine` passes the finished block tree through `Converter\MarkupSanitiser`, always and
for every user. There is no option and no capability that turns it off: `unfiltered_html` does not
exempt an administrator, and an `unfiltered_html` key in the options array is not read. WordPress's
own kses cannot see markup once it is JSON-escaped in a block attribute, so every string holding `<`
in every block's settings goes through `wp_kses()` with the `post` list plus `iframe`
(`MarkupSanitiser::allowedHtml()`; the iframe keeps the attributes core allows on any element, read
off `div`, plus the embed attributes, and never `srcdoc`). A `<script>` or `<style>` element is
removed with its contents, so the code inside does not stay behind as page text.

A block that lost a tag or attribute gets one `not_carried_over` kind `custom_code` entry naming
them: *"markup the converter does not publish was removed (onclick, &lt;script&gt;); add it to the
page through Divi > Theme Options > Integration if it is still needed"*. Normalisation kses does on
its own (quoting, entities) is not reported — tags and attributes are compared by count.

`vc_raw_js` is never written either: one `custom_code` entry, *"Raw JS element (N characters of
script) not converted; if the page still needs it, add it through Divi > Theme Options >
Integration"*.

The suite runs without WordPress, so `tests/bootstrap.php` stubs `wp_kses_allowed_html( 'post' )`
from `tests/support/kses-allowed-post.json`, a dump of core's own list.
`scripts/docker/kses-parity.php` (run by `test.sh` when the container is up) compares that file
with the container's WordPress tag by tag and attribute by attribute, and asserts that core still
excludes `script`, `style` and `iframe` and still carries the `data-*` wildcard — a drifted stub
would mean the whole suite tests a filter no site uses.

## Page-level custom CSS

The post's `_wpb_post_custom_css` has no per-module home, so it is reported once as
`not_carried_over` kind `custom_code` with its length and the instruction to paste it into
Divi → Theme Options → Custom CSS.
