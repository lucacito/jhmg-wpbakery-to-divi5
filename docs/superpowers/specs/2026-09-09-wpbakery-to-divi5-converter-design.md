# WPBakery Page Builder → Divi 5 Converter — Design

Date: 2026-09-09
Status: approved 2026-09-09 (revision 2: re-pinned to WPBakery 9.0.1, layouts corpus and Ultimate Addons added)
Modelled on: `../jhmg-beaver-to-divi5` (free 1.0.0 + Pro 1.0.0, prepared for directory review 2026-09-09);
intake and Theme Builder code lineage: `../jhmg-elementor-to-divi5`.
Source of truth for WPBakery: `references/js_composer.9.0.1.zip` (WPBakery Page Builder 9.0.1, 2026-08-05).
`references/js_composer.7.8.zip` (2024-07-22) is kept for the attribute forms that pages built before 9.0
still carry. Add-on source: `references/Ultimate_VC_Addons.zip` (Ultimate Addons for WPBakery 3.19.3).
Source of truth for Divi: `references/Divi.zip` (Divi 5.12.1).

## 1. Goal

A WordPress plugin pair that converts pages built with WPBakery Page Builder into native Divi 5 pages,
the way the Beaver Builder converter does for Beaver Builder:

- **Free** — `jhmg-converter-for-wpbakery-to-divi`: convert one WPBakery page per run (unlimited runs),
  either a page installed on this site or one page from an uploaded WordPress export; a "Check this page"
  report before anything is written; every run undoable; WordPress.org directory, Plugin Check clean.
- **Pro add-on** — `jhmg-converter-for-wpbakery-to-divi-pro`: convert many pages in one run, convert
  WPBakery's saved templates into the Divi Library, licensed updates from divi5lab.com.

The converter transforms WPBakery's **stored layout** — shortcodes in `post_content` plus two post-meta
CSS blobs — into Divi 5's **block attribute structure**. It never scrapes rendered HTML.

```
post_content  [vc_row][vc_column width="1/2"][vc_column_text]…[/vc_column_text][/vc_column][/vc_row]
        │  ShortcodeParser (WordPress shortcode grammar, pure PHP) + NodeTree
        ▼
nested node tree  (section? → row → column → element | row_inner → column_inner → element)
        │  ConverterEngine + registry of per-element handlers + StyleMapper (design options CSS → attrs)
        ▼
Divi 5 block tree  (divi/section → divi/row → divi/column → divi/*)
        │  DiviBlockSerializer + DiviExporter
        ▼
new Divi 5 page  (post_content = <!-- wp:divi/... --> blocks, Divi meta)
```

## 2. Naming, identifiers, extension points

| Thing | Free | Pro |
|---|---|---|
| Plugin dir / slug / text domain | `jhmg-converter-for-wpbakery-to-divi` | `jhmg-converter-for-wpbakery-to-divi-pro` |
| PHP namespace | `WPBakeryDivi5Converter\` | `WPBakeryDivi5Converter\Pro\` |
| Global prefix (hooks, options, transients, meta, constants, nonces, form fields) | `wbdc_` / `WBDC_` | `wbdcp_` / `WBDCP_` |
| Constants | `WBDC_PLUGIN_FILE`, `WBDC_PLUGIN_DIR`, `WBDC_PLUGIN_URL`, `WBDC_PLUGIN_VERSION` | `WBDCP_*`, `WBDCP_PRODUCT_SLUG = wpbakery-to-divi5-pro`, `WBDCP_API_BASE` |
| Admin screen | `tools.php?page=wbdc-converter` ("WPBakery → Divi 5") | `tools.php?page=wbdcp-pro` |
| Admin CSS classes | `wbdc-` | `wbdc-` (shared stylesheet) |
| Version | 1.0.0 | 1.0.0 |

Both prefixes have at least four characters: WordPress.org rejects shorter ones and Plugin Check
discards them. Nothing may be shortened later.

Hooks the free plugin exposes (Pro consumes them): `wbdc_loaded`, `wbdc_pro_active`,
`wbdc_direct_conversion_limit`, `wbdc_library_exporter`, `wbdc_layout_defaults`, `wbdc_theme_families`.

Post meta written on converted posts: `_wbdc_divi_data`, `_wbdc_conversion_report`,
`_wbdc_import_source` (`direct` | `file_upload`), `_wbdc_source_post_id`, plus the Divi meta
(`_et_pb_use_builder=on`, `_et_pb_use_divi_5=on`, `_et_builder_version`).

Options: `wbdc_import_history`, `wbdc_telemetry_consent`, `wbdc_telemetry_last_sent`,
`wbdc_divi_requirement_failed`, review-prompt user meta. Telemetry product id: `wpbakery-to-divi5`.
Product page: `https://divi5lab.com/plugins/wpbakery-to-divi-5`.

## 3. Source format: what WPBakery stores (verified against js_composer 9.0.1 and 7.8)

- **Builder flag:** post meta `_wpb_vc_js_status` = `"true"` (`Vc_Post_Admin::setJsStatus()`). Pages
  whose content contains `[vc_row` or `[vc_section` but lack the flag (imports, templates) are also
  offered, marked "flag missing" in the picker.
- **Layout:** `post_content` is the shortcode string. WPBakery renders it with WordPress's shortcode engine
  (`do_shortcode`), so the grammar is WordPress's: `[tag attr="v"]…[/tag]`, `[tag attr="v"]`,
  `[tag /]`; attributes as `shortcode_parse_atts()` reads them (double or single quotes, unquoted,
  positional). Same-name nesting cannot occur (WordPress itself cannot render it).
- **Hierarchy:** `vc_section`? → `vc_row` → `vc_column` → elements; a column may hold `vc_row_inner` →
  `vc_column_inner` → elements. `vc_tta_*` and `vc_toggle` hold elements as content (a tta section may
  even hold a `vc_row_inner`). `vc_column_text` content is HTML that may itself carry shortcodes.
- **Per-element design options:** the `css` attribute holds a complete CSS rule,
  `.vc_custom_1600000000000{margin-top: 20px !important;background-color: #f5f5f5 !important;}`
  (class name = `vc_custom_` + timestamp; `vc_shortcode_custom_css_class()` in `helpers_factory.php`).
  Properties the editor writes: `margin-*`, `padding-*`, `border-*-width`, `border-color`,
  `border-style`, `border-radius`, `background-color`, `background-image: url(…)`,
  `background-position`, `background-repeat`/`background-size` (the "background style" dropdown).
  Post meta `_wpb_shortcodes_custom_css` is only the concatenation of every element's rule, rebuilt on
  save (`Vc_Base::buildShortcodesCss`); the converter reads the attribute and uses the meta as a
  cross-check when the attribute is missing.
- **Page custom CSS:** post meta `_wpb_post_custom_css` (`modules/custom-css`, printed in `wp_head`).
- **Packed parameters:** `vc_link` fields (`link`, `btn_link`) are `url:…|title:…|target:…|rel:…`
  with each value `rawurlencode`d (`vc_build_link` → `vc_parse_multi_attribute`).
  `font_container` is `tag:h2|font_size:30px|text_align:left|color:%23ffffff|line_height:1.2`.
  `google_fonts` is `font_family:Montserrat%3Aregular%2C700|font_style:400%20regular%3A400%3Anormal`
  (family before the first `:`; style = `<label>:<weight>:<normal|italic>`); `use_theme_fonts=yes`
  disables it. `param_group` values (`vc_progress_bar values`, chart `values`) are
  `urlencode(json_encode([...]))` (`vc_param_group_parse_atts`). `textarea_safe` values (`vc_gmaps
  link`) are `#E-8_` + base64 of rawurlencoded text, in which the sequences backtick-brace-backtick,
  backtick-close-brace-backtick and double-backtick stand in for `[`, `]` and the double quote
  (`vc_value_from_safe`). `vc_raw_html` / `vc_raw_js` content is
  `base64_encode(rawurlencode(html))`. `textarea_html` content (`vc_column_text`, `vc_message`,
  `vc_cta`, `vc_toggle`, `vc_hoverbox`) is stored verbatim and rendered through `wpautop`
  (`wpb_js_remove_wpautop($content, true)`).
- **Images:** `image`, `images`, `parallax_image` are attachment ids (`images` comma-separated);
  `custom_src` / `custom_srcs` are URLs; `img_size` is a registered size name or `WxH`.
- **Two attribute eras.** WPBakery 9.0 replaced palette dropdowns with colour pickers, checkboxes with
  toggles and several dropdowns with numbers, and reads pages saved before 9.0 through
  `shortcode_atts_<tag>` filters in `WPB_Template_Attributes_Migration`
  (`include/classes/migrations/`): palette names become custom hex colours on `vc_btn` (`color`,
  `gradient_color_1/2` → `custom_*`, `gradient` → `gradient-custom`), `vc_cta`, `vc_icon`, `vc_separator`,
  `vc_text_separator`, `vc_zigzag`, `vc_pie`, `vc_progress_bar` (`bgcolor`, per-bar `color`), charts,
  `vc_hoverbox`, tta elements and grids; `vc_progress_bar options` → `striped`/`animated` toggles;
  grid `element_width` → `items_per_row`; `vc_section content_placement` → `vertical_content_position`;
  `vc_cta add_button` dropdown → toggle + `btn_position`; `vc_wp_archives options` → `type` + `count`;
  `vc_wp_rss options` → `item_*` toggles; tta `no_fill*` → `fill_content_area`. The converter's
  `AttributeNormaliser` applies the same rules, so both eras reach the handlers under the 9.0.1 names;
  handlers are written to 9.0.1 names only. Toggle values are `true`/`yes`/`1`; numbers are bare px.
- **Named colour palette** (`vc_convert_vc_color()` in `helpers_api.php`, unchanged in 9.0.1, also used by
  pie, hoverbox and the 9.0 migration; identical hex values in the CSS for buttons, icons and separators):
  blue `#5472d2`, turquoise `#00c1cf`, pink `#fe6c61`, violet `#8d6dc4`, peacoc `#4cadc9`,
  chino `#cec2ab`, mulled_wine `#50485b`, vista_blue `#75d69c`, orange `#f7be68`, sky `#5aa1e3`,
  green `#6dab3c`, juicy_pink `#f4524d`, sandy_brown `#f79468`, purple `#b97ebb`, black `#2a2a2a`,
  grey `#ebebeb`, white `#ffffff`. Legacy Bootstrap button colours (`vc_btn3-color-*` in
  `js_composer.min.css`): default `#f7f7f7`/text `#333`, primary `#08c`, info `#58b9da`,
  success `#6ab165`, warning `#f90`, danger `#ff675b`, inverse `#555`. Message boxes use their own
  text/border/background triples per colour (also read from the CSS; table in `docs/wpbakery-schema.md`).
- **Element sizes** (`xs|sm|md|lg|xl` for buttons, icons, toggles) and button shapes resolve to the
  pixel values in `js_composer.min.css`; the schema doc tabulates them.
- **9.0 additions:** rows and sections take `min_height` (px number) and rows a `row_title`;
  `vc_flexbox_container` › `vc_flexbox_container_item` (`gap` → CSS `--gap`; `.vc_flexbox_container{display:flex;
  flex-wrap:wrap;margin:0 -15px}`, items `flex:1 0 auto`) and `vc_grid_container` › `vc_grid_container_item`
  (`columns`, `rows`, `row_gap`, `col_gap` → CSS grid `repeat(N, minmax(0,1fr))`), both registered by
  `WPB_Lean_Map_Hooks`; `vc_goo_maps` (`location`, `height`, `zoom`, `type` → the Google embed iframe
  `https://maps.google.com/maps?q=…&t=…&z=…&output=embed&iwloc=near`) replaces `vc_gmaps`, which stays
  registered as deprecated; `vc_copyright` (`prefix`, `postfix`, `align`; prints `prefix © <year> postfix`);
  `vc_gutenberg` gained `do_blocks`; `vc_btn` gained `custom_hover_background/text/border` and
  `custom_border`; tta elements gained `active_color`, `outline_color`, `active_title_color`,
  `inactive_title_color`; `vc_progress_bar` gained `add_text_shadow`/`text_shadow_color`; Font Awesome 6
  icon names (`fa-solid fa-…`) alongside FA5 (`fas fa-…`).
- **Templates:** WPBakery 9.0.1 still ships the 14 default templates as shortcode strings in `config/templates.php`
  (Landing Page, Call to Action Page, Feature List, Description Page, Service List, Product Page, FAQ
  section, About section, About with features, Three image description, News list, Product description,
  Description with accordion, Two column list). User templates live in the `vc4_templates` post type
  (Templatera add-on: `templatera`), content in `post_content`.
- **Exports:** WordPress WXR carries the shortcodes in `content:encoded`, the metas in `wp:postmeta`,
  and attachments as `wp:post_type = attachment` items with `wp:attachment_url`, so image ids can be
  resolved from the export itself.
- **Shortcode catalogue:** `config/lean-map.php` registers 69 shortcodes in 9.0.1 (7.8's list minus
  `vc_button2`/`vc_cta_button2`, plus `vc_copyright`/`vc_goo_maps`); `WPB_Lean_Map_Hooks` adds the four
  container elements; templates exist for a few more (`vc_gutenberg`, `vc_custom_field`, `vc_woocommerce`,
  `rev_slider_vc`, `layerslider_vc`). The union of both versions' lists (75 tags) is what the converter
  handles (§6).
- **Real-layout corpus:** the free "Layouts for WPBakery" plugin (Techeshta, GPL-2.0, in `references/`)
  serves 35 complete WPBakery layouts from a public REST API
  (`https://www.layoutsforwpbakery.com/wp-json/layoutsforwpbakery/v1/templates`, `…/template/byid/?id=N`).
  They use `vc_section` (251), `vc_row`/`vc_row_inner` (481), columns (1010), `vc_custom_heading` (821),
  `vc_column_text` (471), `vc_single_image` (455), `vc_btn` (184), `vc_separator`, `vc_icon`, tta, toggles,
  galleries, hoverboxes, a carousel, a video, a map, a progress bar, and six Ultimate Addons elements
  (`bsf-info-box` 7, `just_icon` 6, `stat_counter` 4, `ultimate_pricing` 3, `ultimate_video` 2,
  `ult_content_box` 1) plus `contact-form-7` (4). Their design options cover margins, paddings, borders
  per side, radius, background colour/image/position/repeat/size (image URLs in the
  `url(https://…/file.jpg?id=106)` form). This is the smoke-test corpus (`fixtures/wpbakery-layouts/`),
  downloaded once by `scripts/fetch-layouts-corpus.php`, committed, credited in the fixtures README and
  never shipped in the plugin zip.

Full field reference, written from the source: `docs/wpbakery-schema.md`.

## 4. Parsing model

- `ShortcodeParser::parse( string $content ): array` — a port of WordPress's shortcode grammar
  (`get_shortcode_regex` semantics for *any* tag name, `shortcode_parse_atts` semantics for
  attributes, entity-encoded quotes normalised the way WordPress does before matching), producing
  nodes `['tag','atts','content','children','id']`. A tag is a container when a matching close tag
  follows; its content is parsed recursively. Text between elements that is not whitespace becomes a
  `text` node (kept, reported as "content outside an element"). `vc_column_text` content is **not**
  parsed into children (it is HTML); nested shortcodes inside it are handled at render time (§7).
  Pure PHP, no WordPress dependency, so PHPUnit runs without WordPress; when WordPress is loaded the
  behaviour is identical by construction (the tests compare against WordPress's own functions in the
  Docker site).
- `WPBakeryDocumentParser::parse( string $content, array $meta ): array` → `['nodes'=>…,
  'custom_css'=>…, 'page_css'=>…, 'builder_flag'=>bool]`. Deprecated element names are normalised
  here through WPBakery's own upgrade rules (`vc_button`/`vc_button2`/`vc_cta_button`/`vc_cta_button2`
  → `vc_btn` attributes exactly as `WPBakeryShortCode_Vc_Btn::convertAttributesToButton3()` does;
  `vc_tabs`/`vc_tour`/`vc_tab`/`vc_accordion`/`vc_accordion_tab` → the tta equivalents with their
  original attribute names carried, `title` → `title`, `tab_id` → `tab_id`, `interval` reported).
- `NodeTree::build()` normalises structure: a top-level `vc_row` is wrapped in an implicit section
  node; a top-level element that is not a row/section is wrapped in section → row → full column and
  reported; a `vc_row` whose children are not columns gets an implicit full-width column (reported);
  `vc_row_inner` at top level is treated as `vc_row`.
- Every node gets a stable id (`<tag>-<n>` in document order, or the element's `el_id`), used by the
  report and the fixtures.

## 5. Structural mapping

| WPBakery | Divi 5 | Notes |
|---|---|---|
| `vc_section` | `divi/section` | `full_width`, `full_height`, `content_placement`, backgrounds, `el_id`/`el_class`, design options live here. |
| `vc_row` at top level | `divi/section` holding one `divi/row` | The section takes the row's background/design options (WPBakery paints them on the row, which is full-bleed when stretched); the row takes gap/equal-height/placement. |
| `vc_row` inside `vc_section` | `divi/row` | |
| `vc_row_inner` | `divi/row` inside the column | Divi 5 allows column → row → column nesting (verified in the validator project and used by the Beaver converter). |
| `vc_column`, `vc_column_inner` | `divi/column` | width and responsiveness below. |
| `vc_tta_section` / `vc_toggle` content | HTML of the item | children flattened by `ContentFlattener` (§6). |
| empty row / column | emitted with a warning | never dropped silently. |
| `disable_element=yes` on any node | `module.decoration.disabledOn` on for all three breakpoints | WPBakery renders nothing; the content is kept but hidden, reported under `visibility`. |

**Column widths.** WPBakery widths are twelfths plus fifths (`1/12 … 11/12, 1/1, 1/5 … 4/5`;
`wpb_translateColumnWidthToSpan()`). Divi 5.12 sizes flex columns with
`module.decoration.sizing.{bp}.value.flexType` on a 24-grid, and its shipped grid CSS defines
`et_flex_column_N_24` for every N from 1 to 24 plus `n_5` (`flex_grid.css`). Every WPBakery width
therefore maps exactly, and the flex-group fallback the Beaver converter needed is not required:

| width | `flexType` | `module.advanced.type` |
|---|---|---|
| 1/12 · 1/6 · 1/4 · 1/3 | `2_24` · `4_24` · `6_24` · `8_24` | `1_12` · `1_6` · `1_4` · `1_3` |
| 5/12 · 1/2 · 7/12 | `10_24` · `12_24` · `14_24` | `5_12` · `1_2` · `7_12` |
| 2/3 · 3/4 · 5/6 · 11/12 · 1/1 | `16_24` · `18_24` · `20_24` · `22_24` · `24_24` | `2_3` · `3_4` · `5_6` · `11_12` · `4_4` |
| 1/5 · 2/5 · 3/5 · 4/5 | `1_5` · `2_5` · `3_5` · `4_5` | same |

(Divi's own migration table, `MigrationUtils::map_flex_type_to_column_type`, names `5_12` and `7_12`,
so the legacy `type` values are Divi's, not invented.) The row's `columnStructure` is the comma-joined
`type` list; whether the Visual Builder accepts a non-preset structure such as `5_12,7_12` is verified
on the Docker site in the first implementation task, and if it does not, `columnStructure` falls back
to the nearest preset while `flexType` keeps the true width (the front end renders from `flexType`).
A column's `module.decoration.layout` never sets `display: block` (that switches Divi back to block
sizing). Columns whose widths do not sum to 12/12 wrap onto the next line in WPBakery; Divi rows wrap
flex children the same way, and the report notes the wrap.

**Responsive `offset` classes** (`vc_col-{lg|md|xs}-N`, `vc_col-{size}-offset-N`, `vc_hidden-{xs|sm|md|lg}`).
WPBakery breakpoints are Bootstrap 3's: xs < 768, sm 768–991, md 992–1199, lg ≥ 1200. Divi's:
phone < 768, tablet 768–980, desktop ≥ 981.

| offset class | Divi |
|---|---|
| `width` (= `vc_col-sm-N`) | desktop and tablet `flexType` (phone stacks, as WPBakery does) |
| `vc_col-xs-N` | phone `flexType` |
| `vc_col-md-N` | desktop `flexType` |
| `vc_col-lg-N` | desktop `flexType` (wins over md; when md and lg differ the md value is reported — Divi has no 992–1199 band) |
| `vc_col-{size}-offset-N` | `module.decoration.spacing.{bp}.value.margin.left` = N/12 % at that breakpoint |
| `vc_hidden-xs` · `vc_hidden-sm` · `vc_hidden-md`+`vc_hidden-lg` | `disabledOn` phone · tablet · desktop (only one of md/lg → desktop hidden and reported as approximate) |

**Row options.** `full_width`: `stretch_row` → nothing extra (a Divi section is already full-bleed;
the row keeps the content width); `stretch_row_content` → row width 100 %, no max width;
`stretch_row_content_no_spaces` → the same plus zero column side padding. `full_height` → section
`minHeight: 100vh`; `columns_placement` and `equal_height` → row layout `alignItems`
(top/middle/bottom/stretch); `content_placement` (sections: `vertical_content_position`) → column layout `justifyContent` (WPBakery
applies it to `.vc_column-inner`, a flex column). `min_height` (9.0) → section/row `minHeight` in px.
`row_title` is editor-only and acknowledged. `gap` → see §9. `rtl_reverse` → row `flexDirection: row-reverse`.
`video_bg`/`video_bg_url`: WPBakery row video backgrounds are YouTube only and Divi's background video
takes mp4/webm files, so they are reported under `background` and the parallax image, when set, becomes
the background image. `parallax` + `parallax_image` → background image with Divi's parallax enabled;
the `-fade` variant and `parallax_speed_bg` are reported. `css_animation` →
reported (`animation`). `el_id`, `el_class` → `module.advanced.htmlAttributes`.

## 6. Element mapping

Every shortcode WPBakery 7.8 or 9.0.1 registers (75 tags) has a handler; the table lists them by Divi target.
"Exact" means every field the handler reads is taken from the 7.8 source. Divi block names are checked
against the 5.12.1 module list (`module-library/src/components/*/module.json`) and every attribute
path against that file before it is written.

| WPBakery | Divi 5 | Content carried |
|---|---|---|
| `vc_column_text` | `divi/text` | content through `wpautop` exactly as WPBakery renders it; `el_id`/`el_class`; design options |
| `vc_custom_heading` | `divi/heading` | `text`; `font_container` → `headingLevel` (tag), `textAlign`, `size`, `lineHeight`, `color`; `google_fonts` → family, weight, italic (ignored when `use_theme_fonts=yes`); `link` → module link; `source=post_title` → Divi dynamic content `post_title` token |
| `vc_single_image` | `divi/image` (+ `divi/text` caption when `add_caption=yes`; + `divi/heading` when `title` set) | `image` id or `custom_src` (+`external_img_size`), `img_size` → that size's URL when the attachment is resolvable, else full; `alignment`; `style` (`vc_box_rounded` → radius, `vc_box_border`/`vc_box_outline` → border in `border_color`, `vc_box_shadow*` → box shadow, `*_circle*` → 50 % radius, `_3d` reported); `onclick`: `custom_link`+`link`+`img_link_target` → link, `link_image`/`img_link_large` → lightbox, `zoom` → lightbox + reported; alt/title from the attachment |
| `vc_btn` (and the deprecated `vc_button`, `vc_button2`, `vc_cta_button`, `vc_cta_button2` after WPBakery's own attribute upgrade) | `divi/button` | `title`, `link` (url/title/target/rel); `style` flat/modern/classic/3d → palette background + white text (`grey`/`white`/`default` keep their dark text; after the 9.0 normaliser these arrive as `custom_background`/`custom_text` hex values), `outline`/`outline-custom` → transparent background, palette border and text; `custom` → `custom_background`/`custom_text`/`custom_border`; `custom_hover_*` reported (`hover`); `gradient`/`gradient-custom` → button background gradient; `shape` rounded/square/round → radius 5px/0/50px (values from the CSS); `size` xs–lg → font size + padding from the CSS; `align` → alignment, `inline` → nested flexed row for consecutive inline buttons; `button_block` → width 100 %; `add_icon` + `i_type=fontawesome` + `i_icon_fontawesome` → icon `unicode, type, weight` via the FA map, other icon libraries reported; `custom_onclick` reported (`interaction`) |
| `vc_icon` | `divi/icon` | `icon_fontawesome` (other libraries reported), `color`/`custom_color`, `size` xs–xl → font size from the CSS, `align`, `link`; `background_style` + `background_color`/`custom_background_color` → module background + radius (`rounded`/`circle`/`boxed`/`outline` approximated, reported) |
| `vc_separator` | `divi/divider` | `color`/`accent_color`, `style` solid/dotted/dashed/double, `border_width` → weight, `el_width` % → width, `align` |
| `vc_zigzag` | `divi/divider` | colour, width, alignment; the zigzag pattern reported (`layout`) |
| `vc_text_separator` | `divi/heading` (`h4`, `title_align`) over `divi/divider` | `add_icon` reported |
| `vc_empty_space` | `divi/divider` with the line hidden | `height` (unit parsing as WPBakery's template) → `module.decoration.sizing.height` |
| `vc_message` | `divi/text` | content; `color`/`message_box_color` preset → text, border and background colours from the message-box table; `message_box_style`/`style` → border radius/outline; icon reported |
| `vc_toggle` | `divi/toggle` | `title`, content, `open=true` → open state; `style`/`color`/`size` reported |
| `vc_tta_accordion` › `vc_tta_section` (legacy `vc_accordion` › `vc_accordion_tab`) | `divi/accordion` › `divi/accordion-item` | item `title`, item content (flattened), `active_section` → that item open; `collapsible_all`, `c_icon`, style/shape/colour reported |
| `vc_tta_tabs`, `vc_tta_tour`, `vc_tta_pageable` › `vc_tta_section` (legacy `vc_tabs`, `vc_tour` › `vc_tab`) | `divi/tabs` › `divi/tab` | as above; `tour` (vertical) and `pageable` reported (`layout`) |
| `vc_tta_toggle` › `vc_tta_toggle_section` | `divi/tabs` with two tabs | reported approximate |
| `vc_gallery` | `divi/gallery` | `images` → `galleryIds`; `type=image_grid` → grid, others → slider; `onclick=link_image` → lightbox; `img_size`; `custom_srcs` (external URLs) → one `divi/image` per URL in a flexed row, reported |
| `vc_media_grid`, `vc_masonry_media_grid` | `divi/gallery` grid | `include` ids; `element_width` → columns; grid item template reported (`integration`) |
| `vc_images_carousel` | `divi/slider` › `divi/slide` | one slide per image (image as slide background, `onclick` link); `slides_per_view` > 1, `partial_view`, `mode` reported |
| `vc_video` | `divi/video` (YouTube, Vimeo, media file) else `divi/code` holding the URL as an `[embed]` | `link`, `el_width` → width, `align`; `el_aspect`, `title` → heading |
| `vc_gmaps` (deprecated in 9.0) | `divi/code` | the decoded iframe; `size` → iframe height |
| `vc_goo_maps` (9.0) | `divi/code` | the Google embed iframe built exactly as `WPBakeryShortCode_Vc_Goo_Maps::getIframeLink()` does from `location`, `type`, `zoom`; `height` |
| `vc_copyright` (9.0) | `divi/text` | `prefix` + `©` + Divi dynamic content `current_date` (format `Y`) + `postfix`; `align` |
| `vc_flexbox_container` › `vc_flexbox_container_item` (9.0) | `divi/group` (flex, wrap, `gap`) › one `divi/group` per item holding its converted children | items keep `flex: 1 0 auto` via a flexType-free width; margin `0 -15px` as WPBakery |
| `vc_grid_container` › `vc_grid_container_item` (9.0) | `divi/group` with Divi's grid layout (`columns`, `row_gap`, `col_gap`) › one `divi/group` per item | grid attribute names read from `group/module.json` at implementation; if Divi 5.12's grid layout cannot express it, flex wrap with `flexType` = 24/columns per item, reported (`layout`) |
| `vc_raw_html`, `vc_raw_js` | `divi/code` | decoded content verbatim |
| `vc_progress_bar` | `divi/counters` › `divi/counter` | one bar per `values` entry: `label`, `value` (+`units`), per-bar `color`/`customcolor`, `bgcolor`/`custombgcolor` track; `options` striped/animated reported |
| `vc_pie` | `divi/circle-counter` | `value`, `label_value`, `units` (`%` → percent sign), `color`/`custom_color`, `title` |
| `vc_round_chart`, `vc_line_chart` | labelled placeholder (`divi/code`) that keeps the series as a text table | reported (`integration`) |
| `vc_cta` | `divi/cta` | `h2` → title, `h4` → first line of content, content, `add_button` + `btn_*` (vc_btn fields with prefix `btn_`) → CTA button text/link/style, `color`/`custom_background`/`custom_text`/`style`/`shape`/`txt_align`/`el_width`; `add_icon` + `i_*` reported |
| `vc_hoverbox` | `divi/blurb` (+ `divi/button` when `hover_add_button`) | `image`, `primary_title` → title, `hover_title` + content → body, `hover_background_color` → module background; the flip reported (`interaction`) |
| `vc_pricing_table` | `divi/pricing-tables` › `divi/pricing-table` | `heading`, `subheading`, `currency`+`price`, `period`, content list, `markers_color`, `add_button` + `btn_*` |
| `vc_basic_grid`, `vc_masonry_grid` | `divi/blog` | `post_type`, `items_per_page`/`max_items` → per page, `orderby`/`order`, `taxonomies` → categories when resolvable, `element_width` → columns; grid item template, filter, paging reported |
| `vc_posts_slider` | `divi/post-slider` | `count`, `posttypes`, `categories`, `orderby`/`order`, `slides_content` |
| `vc_widget_sidebar` | `divi/sidebar` | `sidebar_id` → area |
| `vc_wp_custommenu` | `divi/menu` | `nav_menu` term id; `title` → heading |
| `vc_wp_search` | `divi/search` | `title` → heading |
| `vc_wp_text` | `divi/text` | `title` → heading, content |
| `vc_wp_archives`, `vc_wp_calendar`, `vc_wp_categories`, `vc_wp_links`, `vc_wp_meta`, `vc_wp_pages`, `vc_wp_posts`, `vc_wp_recentcomments`, `vc_wp_rss`, `vc_wp_tagcloud` | the WordPress widget rendered with `the_widget()` into `divi/code` (static copy, reported); a labelled placeholder from an export | widget class per shortcode from the templates (`WP_Widget_Archives` …) |
| `vc_facebook`, `vc_tweetmeme`, `vc_pinterest`, `vc_googleplus`, `vc_flickr` | labelled placeholder | reported (`integration`) |
| `vc_gutenberg` | `do_blocks()` into `divi/code` on this site; placeholder keeping the text from an export | reported static copy |
| `vc_custom_field` | `divi/text` with Divi dynamic content `post_meta_key` | approximate |
| `vc_woocommerce` (9.0 template; wraps a WooCommerce shortcode) | `divi/code` holding the WooCommerce shortcode | reported (`integration`) |
| `contact-form-7` | `divi/contact-form-7` (Divi 5.12 ships this module) | form `id`; `title` |
| `bsf-info-box` (Ultimate Addons) | `divi/blurb` | icon/image, title, description, read-more link and button; fields from `modules/ultimate_info_box.php` |
| `just_icon` (Ultimate Addons) | `divi/icon` | icon, colour, size, background style, link (`modules/ultimate_just_icon.php`) |
| `stat_counter` (Ultimate Addons) | `divi/number-counter` | value, prefix/suffix, title, colours (`modules/ultimate_stats_counter.php`) |
| `ultimate_pricing` (Ultimate Addons) | `divi/pricing-tables` › `divi/pricing-table` | heading, sub-heading, price, features list, button (`modules/ultimate_pricing_tables.php`) |
| `ultimate_video` (Ultimate Addons) | `divi/video` | URL, thumbnail, play-button styling reported (`modules/ultimate_videos.php`) |
| `ult_content_box` (Ultimate Addons) | `divi/group` with background, border, padding | children converted inside (`modules/ultimate_content_box.php`) |
| `rev_slider_vc`, `rev_slider`, `layerslider_vc`, `layerslider` | labelled placeholder naming the slider alias | reported (`integration`) |
| anything else | see §7 | |

Every WPBakery element's optional `title` (WPBakery prints it as an `h2.wpb_heading` above the
element) becomes a `divi/heading` (h2) placed before the module. `el_id`/`el_class` go to
`htmlAttributes`; `css_animation` is reported; the `css` design options go through the StyleMapper (§8).

**ContentFlattener** (accordion items, tabs, toggles hold HTML, not blocks): converts the section's
children to Divi blocks first, then renders those blocks to plain HTML — text as is, headings as
`<hN>`, images as `<img>`, buttons as `<a class="…">`, dividers as `<hr>`, nested rows as `<div>`s with
inline flex widths; anything else is replaced by a labelled comment and reported (`layout`).

## 7. Theme and add-on shortcodes

Most WPBakery sites run a ThemeForest theme (Salient, Bridge, The7, Jupiter, BeTheme, Enfold-style
kits) and add-ons (Ultimate Addons for WPBakery `ultimate_*`/`ult_*`/`bsf_*`/`info_list`, Massive
Addons `mpc_*`, Salient `nectar_*`/`fancy_box`/`image_with_animation`/`divider_line`/`milestone`,
Bridge `qode_*`/`no_*`, The7 `dt_*`, Jupiter `mk_*`, Templatera `templatera`, WooCommerce
`products`/`product_category`, Contact Form 7 `contact-form-7`, Gravity Forms `gravityform`).
`Helpers\ThemeShortcodes` classifies any non-core tag into a family by prefix table (filterable through
`wbdc_theme_families`), and unknown tags are their own family ("other").

Handling is uniform and never silent:

- **Converting a page on this site:** the shortcode is rendered with `do_shortcode()` (the theme and
  its add-ons are active, so it renders exactly as the live page does) into a `divi/code` block, a
  static copy. The report lists it once per node under `not_carried_over` kind `addon` naming the family
  and tag, and counts families in `theme_elements`. Design options (`css`) on it are still mapped.
- **Converting from an export:** a labelled placeholder (`divi/code` with a comment naming the tag and
  a `div` holding the text content, tags stripped) keeps the text and position; reported the same way.
- Nested shortcodes inside `vc_column_text` HTML get the same two behaviours in place (rendered on-site,
  left as text and reported from an export).
- The six Ultimate Addons elements the layouts corpus uses get real handlers from the add-on's source
  (§6, registered `approximate: true` because the source is 3.19.3 while sites run newer builds); once
  the two real theme exports arrive, every further add-on element they contain that has a natural Divi
  equivalent gets one too, with single-element fixtures cut from the exports. The generic path above
  stays for everything else.

## 8. Design settings (StyleMapper)

`StyleMapper::map( string $kind, array $atts, array $context ): ['divi_attrs','handled_keys']`,
`$kind` ∈ `section | row | column | heading | text | image | button | icon | blurb | cta | counter | generic`.

| WPBakery | Divi 5 path |
|---|---|
| `css` rule `margin-*` / `padding-*` | `module.decoration.spacing.desktop.value.{margin,padding}` |
| `css` `border-*-width`, `border-color`, `border-style` | `module.decoration.border.desktop.value.styles.{all|top…}` |
| `css` `border-radius` | `…border.desktop.value.radius` (all corners) |
| `css` `background-color` | `module.decoration.background.desktop.value.color` |
| `css` `background-image: url(…)`, `background-position`, `background-repeat`, `background-size` | `…background.desktop.value.image.{url,position,repeat,size}` (`background-style: stretch` → `cover`, `cover`/`contain`/`no-repeat`/`repeat` as named; the URL usually carries `?id=<attachment>`: the id is resolved on this site or through the export's attachment map, else the URL is kept as is; a bare `url(123)` is an attachment id) |
| `css` `background` shorthand | split into colour and image parts |
| `css` `*background-color` and other IE hacks | dropped silently (WPBakery's own output artefacts) |
| any other `css` declaration | the module's custom CSS `css.desktop.value.main`, verbatim, counted under `custom_css_carried` |
| `!important` | stripped |
| `font_container` (`vc_custom_heading`) | `title.decoration.font.font.desktop.value.{size,lineHeight,textAlign,color,headingLevel}` |
| `google_fonts` | `…font.desktop.value.{family,weight,style:[italic]}` |
| button `style`/`color`/`custom_*`/`gradient_*`/`shape`/`size` | `button.decoration.{background,border,font,spacing}` (see §6) |
| palette names anywhere | `Color::fromPalette()` → hex; unknown names are reported under `unresolved_globals` (never invented) |
| `el_id`, `el_class` | `module.advanced.htmlAttributes.desktop.value.{id,class}` |
| `css_animation` | reported (`animation`) |
| `disable_element` | `module.decoration.disabledOn` |
| row `gap`, `equal_height`, `content_placement`, `columns_placement`, `rtl_reverse`, `full_height`, `full_width` | §5 |
| video/parallax backgrounds | §5 |

Everything is desktop-only except column widths and visibility: WPBakery's design options have no
breakpoints. Gradient stop positions are written without units; icon settings as `unicode, type,
weight` in that order; colours as `#rrggbb` or `rgba()` pass-through.

## 9. Box model: WPBakery's, not Divi's defaults

Measured in `assets/css/js_composer.min.css` (7.8):

| WPBakery | CSS |
|---|---|
| Row | `.vc_row{margin-left:-15px;margin-right:-15px}`; no vertical padding |
| Column | container `padding:0`; `.vc_column-inner{padding-left:15px;padding-right:15px}`; design options paint on `.vc_column-inner`, so filled columns touch each other and bleed 15 px past the container edge, and their content sits 15 px inside |
| Filled row (`vc_row-has-fill`, i.e. background/border set) | `.vc_column-inner{padding-top:35px}` on its columns and on the columns of the row that follows |
| Column gap `gap=N` | `.vc_row{margin: 0 -(15+N/2)px}` and `.vc_column_container{padding: N/2 px}` on all four sides |
| Element | `.wpb_content_element{margin-bottom:35px}` (buttons and images included; themes often change it) |
| Container width, section padding | none of WPBakery's: they come from the theme |

Proposed Divi output (to be confirmed by side-by-side screenshots on the Docker site before the
StyleMapper is written — the first implementation task — and recorded in `CLAUDE.md`):

| Divi 5 | Value |
|---|---|
| `divi/section` padding | `0` top/bottom unless the row's design options set one (Divi's default is 54 px) |
| `divi/row` | padding `0`; margin `0 -15px` and width `calc(100% + 30px)` when Divi accepts it (exact WPBakery bleed), otherwise plain width and the 15 px inset documented; `columnGap 0`, `rowGap 0` |
| `divi/column` | padding `0 15px` (+ `gap/2` on all sides when the row sets `gap`); `35px` top padding when the row is filled; design options on the column |
| modules | margin-bottom `35px` when the element's design options set none (the column's `rowGap` is 0); blocks built by a handler's `delegate()` are pieces of one element and get no default margins |
| row max-width | Divi's default; the theme's container width is unknown from the data (`wbdc_layout_defaults` filter lets a site set one) |

All of these sit behind the `wbdc_layout_defaults` filter (`GlobalSettingsResolver`).

## 10. Engine, registry, reporting

Same shape as the Beaver engine:

- `ConverterEngine::convert( array $document, array $options )` accepts the parsed document; `$options`
  carries `mode` (`direct` | `import`), `attachments` (id → URL map from a WXR), `page_css`.
  Returns `['divi'=>['elements'=>[…]], 'unsupported'=>[…], 'report'=>[…]]`.
- `ConverterRegistry` keys: `structure:<tag>` for `vc_section`, `vc_row`, `vc_row_inner`, `vc_column`,
  `vc_column_inner`; `element:<tag>` for everything else; `approximate: true` for handlers whose
  behaviour is an approximation by design (charts, social, hoverbox, tta_toggle, zigzag, add-on handlers);
  `defaultConverter()` returns `ThemeShortcodeConverter` (§7), which is also the generic fallback.
- `BaseWPBakeryConverter` helpers: `mapStyle()`, `att()`, `link()` (packed link), `palette()`,
  `attachmentUrl()` (site or export map), `codeBlock()`, `headingBlock()` (for `title`),
  `delegate()`, `deepMergeSettings()`, `logUnmappedSettings()` — every handler calls it with every key it
  consumed; nothing is dropped silently.
- Report: `converted` (per Divi module), `approximate` + `approximate_matches`, `warnings`,
  `skipped_settings` (`"<node>: <key>"`; excluded: empty values, `css_animation=""`, WPBakery's
  scaffolding keys `i_icon_openiconic`… when `add_icon` is off, `img_link_large=""`), `unresolved_globals`
  (palette names Divi cannot resolve), `not_carried_over` (kinds `animation`, `visibility`, `background`,
  `interaction`, `integration`, `custom_code`, `addon`, `layout`), `theme_elements` (family → count),
  `static_copies`, `custom_css_carried`, `unresolved_media` (attachment ids not found), `text_nodes`,
  `quality` (`module_coverage` = converted ÷ (converted + approximate + unsupported)).
- Page custom CSS (`_wpb_post_custom_css`) is carried into the report under `custom_code`, not emitted
  (same policy as the Beaver converter's layout CSS/JS).

## 11. Intake

- **Direct** (`InstalledPostSource`): posts with `_wpb_vc_js_status = "true"`, plus posts whose
  content matches `/\[vc_(row|section)\b/` (flag missing → badge), across every public post type ∪
  `vc4_templates` ∪ `templatera`. Never modifies the source post.
- **Upload** (`WPBakeryImportParser`): WXR XML (every `<item>` whose `content:encoded` carries
  WPBakery shortcodes; `wp:postmeta` read for the CSS metas; attachment items collected into the id →
  URL map; DOM parsing with `LIBXML_NONET`, documents containing `<!DOCTYPE` or `<!ENTITY` refused) and
  plain `.txt`/`.html` files holding a shortcode string (one item). The free plugin converts the first
  item and reports truncation; Pro converts all (`wbdc_direct_conversion_limit` governs both paths).

## 12. Admin flow (free)

Tools → WPBakery → Divi 5, ported from the Beaver plugin with renamed copy: picker (search, paging,
"already converted" and "flag missing" badges) → **Check this page** → report (outline, element counts,
theme elements by family, could-not-convert list, not-carried-over) → **Convert to Divi 5** → result
screen (view/edit/publish links, issues) → `ImportHistory` (25 runs) with one-click **Undo** (trash
only, ownership-guarded); upload form (XML / txt / html, post type + status); coverage panel with
opt-in weekly telemetry of unknown element tags (theme families included, never content); review prompt
after three clean runs; Pro card (`AdminPage::PRO_PRICE` = the divi5lab.com listing). `DiviRequirement`
hides every control and explains itself when Divi 5 is missing.

## 13. Pro add-on

WPBakery has no header/footer layouts (theme headers are theme options), so the Beaver Pro's Themer
export has no counterpart. Pro instead ships:

- unlimited pages per run (`wbdc_direct_conversion_limit` → unlimited) and a "convert every WPBakery page
  on this site" action;
- **Templates → Divi Library**: `vc4_templates` and `templatera` posts converted into `et_pb_layout`
  library items (`layout_type = layout`, `_et_pb_built_for_post_type = page`; the exact meta set is read
  from Divi's `et_pb_create_layout()` at implementation), keyed by
  `_wbdcp_library_source` so re-converting updates instead of duplicating (`DiviLibraryExporter`,
  registered through `wbdc_library_exporter`);
- the licence tab backed by the canonical JHMG `LicenseClient` (soft enforcement: notices and update
  delivery only), product `wpbakery-to-divi5-pro`, option prefix `wbdcp`.

## 14. divi5lab.com (`../layoutlab`)

- `lib/license-server/core.ts`: `PLUGIN_PRODUCTS` += `wpbakery-to-divi5-pro`; `PRODUCT_TITLES` += "JHMG
  Converter For WPBakery to Divi 5 Pro" (tests updated).
- `app/api/checkout/route.ts` `PRICE_ENV` += `env.STRIPE_PRICE_WPB2DIVI_PRO`; `lib/env` and `.env.example`
  document it; `scripts/stripe-plugin-products.ts` and `scripts/reprice-plugins.ts` gain the product at
  2500 ¢/year (the scripts are not run without permission).
- `lib/coverage/schema.ts` product enum += `wpbakery-to-divi5`.
- Product page `app/(marketing)/plugins/wpbakery-to-divi-5/page.tsx` modelled on the Beaver page
  (`lib/site/wpbakery-element-mappings.ts`, `tests/wpbakery-page.test.tsx`), free plugin served from
  `public/downloads/` until wordpress.org approves; nav (`lib/nav/menu-data.ts`, its test now counts five),
  `ProductDoors`, `/plugins` index, footer.
- Everything is committed on a branch; pushing, deploying and creating Stripe objects wait for an
  explicit go-ahead.

## 15. Testing

- **PHPUnit** (WordPress function stubs in `tests/bootstrap.php`, ported): `ShortcodeParserTest`
  (grammar cases incl. entity quotes, self-closing, nested containers, stray text, WPBakery's real
  template strings), `WPBakeryDocumentParserTest` (deprecated-element upgrades, metas), `NodeTreeTest`,
  `ColorTest` (palette), `PackedParamsTest` (link, font_container, google_fonts, param_group, safe
  values, raw html), `CssRuleParserTest`, `StyleMapperTest`, `ColumnWidthTest` (every width and offset
  class), per-handler fixture tests (`fixtures/wpbakery/*.txt` → `fixtures/divi/*.json`, reviewed
  goldens via `scripts/render-fixture.php` + `scripts/update-expected.php`), `ThemeShortcodesTest`,
  `ContentFlattenerTest`, `ConverterEngineTest`/`ConversionReportTest`, `RegisteredShortcodesTest`
  (pins the 69 lean-map shortcodes + the template-only four: each has a handler), preflight
  write-nothing, committer, repository, direct-conversion page, import history/rollback, telemetry,
  release metadata (version in three places, readme disclosures, prefix ≥ 4 chars), Pro tests
  (licence client, library exporter dedupe).
- **Bundled-template smoke tests:** the 14 default templates (`fixtures/wpbakery-templates/*.txt`,
  extracted by `scripts/wpb-templates-to-fixtures.php`) convert without exception, keep every heading,
  text and button string, and pass the vendored Divi 5 Deterministic Validator
  (`tests/support/divi5-validator/`).
- **Layouts corpus:** the 35 Layouts for WPBakery layouts (`fixtures/wpbakery-layouts/*.txt`) convert
  validator-clean with zero skipped settings and every heading, text and button string kept
  (`LayoutsCorpusConversionTest`); `unresolved_media` entries are expected there (the attachment ids
  belong to the source site) and are the only report noise allowed.
- **Real-world exports:** `wpbakery templates/*.xml` (the theme exports you supply; committed, like the
  Beaver repo's `beaver templates/`) must convert validator-clean with zero skipped settings and no unlabelled
  placeholder (`ThirdPartyTemplateConversionTest`; incomplete, not green, while the folder is empty).
- **Playwright** against the Docker site (`localhost:8020`): fixture pages converted via WP-CLI helpers;
  Divi recognises the page; front end shows sections, headings, buttons, images; report meta has the
  expected counts; no JS errors; a `box-model.spec.ts` that screenshots the WPBakery source page and the
  Divi result side by side for the §9 decision.
- Before any claim of success: `vendor/bin/phpunit`, `npx playwright test` when the site is up,
  `scripts/plugin-check.sh free` at zero findings.

## 16. Local environment

`docker-compose.yml`: `wordpress:php8.3-apache` on port **8020** (8000, 8001, 8010, 8080, 8081 are
taken by the sibling projects) + `mysql:8.0`. Mounts both plugin dirs, `fixtures/`,
`references/Divi.zip` and `references/js_composer.9.0.1.zip` (both gitignored; Ultimate Addons is not
installed by default, since the 3.19.3 build predates current WordPress, and the corpus' add-on elements are
exercised through the export path).
`scripts/docker/setup_wp.sh` installs WP-CLI, core (admin/admin), Divi from the zip (activated),
WPBakery from the zip (activated), Plugin Check, both converters, then seeds a bundled template page and
converts it. Helpers via `wp eval-file`: `set-wpbakery-content.php` (fixture → `post_content` + flag),
`import-wpb-template.php` (bundled template by name), `convert-run.php`, `convert-to-new-page.php`.
`scripts/plugin-check.sh [free|pro|all]` as in the Beaver repo. `Tested up to` is read off the container.

## 17. WordPress.org listing

`wporg-assets/`: three 1280 px screenshots from the Docker site (Pro deactivated), banners
772×250 / 1544×500, icons 128 / 256 + SVG in the Elementor/Beaver motif (source mark → arrow → D5) in
WPBakery's teal brand colour fading to Divi purple; `scripts/build-submission-zip.sh` produces the
submission zip without `.DS_Store`. `readme.txt` states both counts (elements mapped to a real Divi module
vs. placeholders), never the sum.

## 18. Out of scope for 1.0.0

Grid Builder item templates (`vc_gitem_*`, `vc_grid_item` posts); WPBakery AI/SEO/custom-layout
modules; Divi 5's optional `tabletWide`/`phoneWide` breakpoints; page custom CSS/JS written into Divi
page settings (reported only); theme typography and container widths that are not in the data; pixel
visual preview; Divi 4 output; theme header/footer builders.

## 19. Decisions taken autonomously — please confirm or overrule

1. **Pro scope** (§13): unlimited batch + Templates → Divi Library + licence. The Beaver Pro's Theme
   Builder exporter is dropped because WPBakery has nothing to feed it.
2. **Price/product**: `wpbakery-to-divi5-pro` at $25/yr, `STRIPE_PRICE_WPB2DIVI_PRO`, matching the
   other converters.
3. **Column widths** use the 24-grid `flexType` directly for every twelfth (§5); the kickoff assumed a
   flex-group fallback for 5/12, 7/12 and 11/12, which Divi 5.12's own CSS makes unnecessary.
4. **Box-model targets** (§9) are proposals; the screenshot pass decides and `CLAUDE.md` records it.
5. **WPBakery version**: pinned to 9.0.1 (August 2026), with 7.8 kept for the pre-9.0 attribute forms
   that most live sites still carry; both eras are normalised with WPBakery's own migration rules.
6. **Docker port 8020**; fixtures as `.txt` shortcode files with an optional `.json` sidecar for metas.
7. **Theme elements**: static copy on-site / labelled placeholder from an export for every non-core
   shortcode; real handlers for the six Ultimate Addons elements in the layouts corpus and for the add-on
   elements present in the two exports you supply.
8. **Layouts corpus**: the 35 free layouts are fetched from the plugin's public API and committed as test
   fixtures with credit; they are never shipped in the plugin zip. Say so if you would rather keep them out
   of the repository.
