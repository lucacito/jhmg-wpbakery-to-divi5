# WPBakery Page Builder schema (as stored)

Verified against WPBakery Page Builder **9.0.1** (`references/js_composer.9.0.1.zip`) and **7.8**
(`references/js_composer.7.8.zip`), plus **Ultimate Addons for WPBakery 3.19.3**
(`references/Ultimate_VC_Addons.zip`) and **Ronneby Core 1.5.74** (`references/ronneby-core.zip`).
Nothing here is inferred from rendered HTML: the converter reads `post_content` and three post metas
and never scrapes a page.

The authoritative per-tag parameter list is the generated table
`plugin/jhmg-converter-for-wpbakery-to-divi-5/data/wpbakery-params.json` — see
[§ The parameter table](#the-parameter-table).

## Storage

| Where | What |
|---|---|
| `post_content` | The shortcode string. This is the layout; there is no serialized blob. |
| post meta `_wpb_shortcodes_custom_css` | The design-options stylesheet the editor compiles from every element's `css` attribute — one stylesheet for the whole post. |
| post meta `_wpb_post_custom_css` | The CSS typed into the page-settings box. |
| post meta `_wpb_vc_js_status` | `'true'` when the post was last saved in the frontend editor. |
| post types `vc4_templates`, `templatera` | WPBakery's own template library and the Templatera add-on's, both listed on the same "Templates" screen. Each holds a fragment meant to be reused rather than a page, so `InstalledPostSource::LIBRARY_POST_TYPES` marks them `library` and Pro routes them to the Divi Library. |
| post type `vc_grid_item` | Grid-item templates (the layout a `vc_basic_grid` renders each post with). WPBakery's, but not read by this converter — a grid's item template is reported, not converted. |

All three metas are written by `Vc_Post_Admin::save()`
(`include/classes/editors/class-vc-post-admin.php`). The converter reads them through
`Parsers\WPBakeryDocumentParser::parse()`, whose constants name them
(`CUSTOM_CSS_META`, `PAGE_CSS_META`, `JS_STATUS_META`).

A post counts as a WPBakery page when its content matches `/\[vc_(row|section)\b/`
(`WPBakeryDocumentParser::BUILDER_CONTENT_REGEX`). `\b` keeps `vc_row_inner` out: an inner row on
its own is a fragment, not a page. The `_wpb_vc_js_status` flag is *not* the gate — a page saved in
the backend editor has no flag and still converts; the admin list uses it only for a "flag missing"
badge (`Admin\WPBakeryPageRepository`).

## Node

`Parsers\ShortcodeParser::parse()` reads the string with WordPress's own
`get_shortcode_regex()` (with `($tagregexp)` replaced by `[a-zA-Z0-9_-]+`, so a tag the site has not
registered is still seen), and `Parsers\NodeTree::build()` gives every node a kind:

```php
[ 'id' => 'vc_row-3', 'tag' => 'vc_row', 'kind' => 'row',   // section|row|row_inner|column|column_inner|element|text
  'atts' => [ 'full_width' => 'stretch_row', 'css' => '.vc_custom_1{…}' ],  // already normalised to 9.0.1 names
  'content' => '', 'children' => [ /* nodes */ ], 'notes' => [ /* normaliser notes */ ] ]
```

Hierarchy: `vc_section → vc_row → vc_column → elements`, and `vc_column → vc_row_inner →
vc_column_inner → elements`. A row may sit at the top level with no section.

**Raw-content tags** (`ShortcodeParser::RAW_CONTENT_TAGS`) hold HTML rather than child shortcodes,
so their inner text is kept verbatim instead of being parsed: `vc_column_text`, `vc_message`,
`vc_cta`, `vc_toggle`, `vc_hoverbox`, `vc_raw_html`, `vc_raw_js`, `vc_wp_text`, `vc_gutenberg`,
`vc_pricing_table`, `vc_cta_button2`.

A run of text between shortcodes is a `#text` node and becomes a `divi/text` module
(`Handlers\TextNodeConverter`); a `[1]`-style bracket token nobody registered is re-emitted as text
and counted under `bracketed_text`, never reported as a failure.

## The two attribute eras, and the normaliser

Handlers are written to the **9.0.1** attribute names. `Parsers\AttributeNormaliser::normalise()`
folds the pre-9.0 forms onto them before any handler runs, so a handler only ever sees one shape.
It is a port, method for method, of WPBakery's own two upgrade paths:

- `WPBakeryShortCode_Vc_Btn::convertAttributesToButton3()`
  (`include/classes/shortcodes/vc-btn.php`) for the button-1/button-2 attributes;
- `Wpb_Template_Attributes_Migration` and `Wpb_Attributes_Migration_Abstract`
  (`include/classes/migrations/`), which WPBakery hooks onto `shortcode_atts_<tag>`.

Two deliberate departures from the originals: a migrated source attribute is **unset** rather than
left beside its replacement (WPBakery can keep both because its filters run on merged
`shortcode_atts()` output; here a stale key would be reported as an unmapped setting), and no rule
renames a `style` except `gradient` → `gradient-custom`, which the migration itself does.

### Deprecated tags (`AttributeNormaliser::DEPRECATED_TAGS`)

| Old tag | Becomes | Note |
|---|---|---|
| `vc_button`, `vc_button2` | `vc_btn` | through `convertAttributesToButton3()` |
| `vc_cta_button`, `vc_cta_button2` | `vc_cta` | a box of text with a button in it; `call_text` becomes the content, the button half lands on the integrated `btn_` params |
| `vc_tabs` | `vc_tta_tabs` | `interval` has no 9.0.1 equivalent and is noted |
| `vc_tour` | `vc_tta_tour` | |
| `vc_tab`, `vc_accordion_tab` | `vc_tta_section` | |
| `vc_accordion` | `vc_tta_accordion` | |

`vc_gmaps` is deliberately absent: it is deprecated in 9.0.1 but has no replacement tag.

### Migration rules by tag (`AttributeNormaliser::migrate()`)

Each rule keeps the name of the WPBakery callback it ports and runs in that class's own
registration order.

| Tag(s) | Rules |
|---|---|
| `vc_basic_grid`, `vc_masonry_grid`, `vc_media_grid`, `vc_masonry_media_grid` | `convert_grid_element_width_to_items_per_row`, `normalize_grid_gap_to_px`, `migrate_inline_dropdown_color_to_custom` (`filter_color`, `arrows_color`, `paging_color`), `convert_integrated_btn` (`btn_`) |
| `vc_tta_tabs`, `vc_tta_tour`, `vc_tta_accordion`, `vc_tta_pageable` | `convert_tta_tabs_pagination_color_to_custom` (not on the accordion), `keep_tta_color_slug`, `convert_tta_no_fill_to_fill_content_area`, `update_tta_pageable_autoplay_default` |
| `vc_btn` | the five `convert_btn_*_dropdown_color_to_custom` rules, then `convert_btn_gradient_style_to_gradient_custom` |
| `vc_cta` | icon colour + icon background colour, the four `convert_cta_*_color_to_custom`, `convert_cta_el_width_dropdown_to_range`, `convert_cta_add_button_dropdown_to_toggle`, `convert_cta_add_icon_dropdown_to_toggle`, `convert_integrated_btn` (`btn_`) |
| `vc_icon` | `convert_dropdown_color_to_custom`, `convert_background_dropdown_color_to_custom` |
| `vc_zigzag` | `convert_dropdown_color_to_custom` |
| `vc_separator` | `convert_separator_dropdown_color_to_custom` |
| `vc_text_separator` | separator colour + icon colour + icon background colour |
| `vc_pie` | `convert_pie_chart_color_to_custom` |
| `vc_progress_bar` | `convert_progress_bar_bgcolor_to_custom`, `convert_progress_bar_values_color_to_custom`, `convert_progress_bar_options_checkbox_to_toggles` |
| `vc_round_chart` | stroke colour, legend colour, values colour, `migrate_chart_style_custom_to_flat` |
| `vc_line_chart` | values colour, `migrate_chart_style_custom_to_flat` |
| `vc_hoverbox` | `convert_hoverbox_background_color_to_custom`, `convert_integrated_btn` (`hover_btn_`) |
| `vc_pricing_table` | `convert_integrated_btn` (`btn_`) |
| `vc_section` | `convert_section_content_placement_to_vertical_content_position` |
| `vc_wp_archives` | `migrate_wp_archives_options_to_type_and_count` |
| `vc_wp_rss` | `migrate_wp_rss_options_to_toggles` |
| `vc_gitem_image` | `convert_border_dropdown_color_to_custom` |
| `vc_gitem_post_categories` | `convert_category_dropdown_color_to_custom` |
| `vc_posts_slider` | `convert_posts_slider_count_textfield_to_number` |
| `vc_images_carousel` | `convert_images_carousel_speed_to_numeric` |

Every rule that touches a colour goes through `Helpers\Color`, whose tables are read from
`vc_convert_vc_color()`, `VcSharedLibrary` and the migration itself. There is not one hex literal in
`AttributeNormaliser`. A slug none of those tables knows is left exactly as it was and recorded in
the node's `notes`.

The pre-9.0 button-1 `color` values are Bootstrap-2 class names
(`VcSharedLibrary::get_color_arr()`): `wpb_button` → `default`, `btn-primary` → `primary`,
`btn-info` → `info`, `btn-success` → `success`, `btn-warning` → `warning`, `btn-danger` → `danger`,
`btn-inverse` → `inverse`. Sizes: `wpb_regularsize` → `md`, `btn-large` → `lg`, `btn-small` → `sm`,
`btn-mini` → `xs`. Styles: `rounded`/`square`/`round` → `flat` + that shape, `outlined` /
`square_outlined` → `outline`.

## Packed parameter formats

WPBakery packs several unrelated widget shapes into one attribute string. `Helpers\PackedParams`
is a port of the 9.0.1 function that reads each at render time; 7.8's
`vc_parse_multi_attribute()` is byte-identical, so both eras decode the same way.

| Attribute shape | Example | Decoder | WPBakery source |
|---|---|---|---|
| Link | `url:https%3A%2F%2Fx.com\|title:Go\|target:_blank\|rel:nofollow` | `PackedParams::link()` | `vc_build_link()` (`include/params/vc_link/vc_link.php`) over `vc_parse_multi_attribute()`; a value with no `url:` key is a bare URL (`include/params/href/href.php`) |
| Font container | `tag:h2\|font_size:36\|text_align:center\|color:%23333` | `PackedParams::fontContainer()` | `Vc_Font_Container::_vc_font_container_parse_attributes()` |
| Google fonts | `font_family:Roboto%3A400%2C700\|font_style:400 regular:400:normal` | `PackedParams::googleFonts()` | `Vc_Google_Fonts::_vc_google_fonts_parse_attributes()` + the family/style split in `WPBakeryShortCode_Vc_Custom_Heading::getStyles()` |
| Param group | url-encoded JSON | `PackedParams::paramGroup()` | `json_decode( urldecode( … ) )`, WPBakery's own `param_group` encoding |
| Safe value | base64 | `PackedParams::safeValue()` | `vc_value_from_safe()` |
| Raw HTML | base64 | `PackedParams::rawHtml()` | `include/templates/shortcodes/vc_raw_html.php` |
| Size + unit | `20`, `20px`, `1.5em` | `PackedParams::sizeWithUnit()` | `wpb_format_with_css_unit()`; units from `VcSharedLibrary::get_css_units()` — `px\|%\|in\|cm\|mm\|em\|rem\|ex\|pt\|pc\|vw\|vh\|vmin\|vmax` |

Unlike the WPBakery original, `sizeWithUnit()` returns `''` rather than a fabricated `0px` for
unparseable input, so a caller can tell "no size" from "zero". `Helpers\Size::withUnit()` /
`::number()` handle the plain string case (WPBakery has no `_unit` sibling attribute the way Beaver
Builder does).

## The `css` design-options rule

The design-options panel writes exactly one CSS rule into the element's `css` attribute:

```
.vc_custom_1536822948242{margin-top: 40px !important;background-color: #f5f5f5 !important;}
```

`Helpers\CssRuleParser::parse()` reads the class name and the declaration list the same way
`vc_shortcode_custom_css_class()` (`include/helpers/helpers.php`) reads the class: `([^}]*)` stops
at the **first** closing brace, `!important` is stripped, and browser-hack prefixes
(`*background-color`, `_zoom`) are dropped. The whole stylesheet for the post also lives in
`_wpb_shortcodes_custom_css`; without it the `.vc_custom_…` classes are emitted with no rule behind
them, which is why the fixture sidecars carry it.

`CssRuleParser::imageRef()` reads the `?id=<attachment id>` suffix WPBakery's own WXR importer
appends to a background-image URL when it remaps attachment URLs
(`…/importer/class-vc-wxr-parser-plugin.php`, `remapAttachmentUrls()`).

**There are no breakpoints in the panel** — one rule per element, so everything the StyleMapper
writes is `desktop`. The only per-breakpoint source in the whole converter is a column's
`offset` attribute (`vc_col-xs-*`, `vc_col-sm-*`, `vc_col-md-*`, `vc_col-lg-*`,
`vc_hidden-*`), read by `StyleMapper\ColumnWidths::offsets()`. See
[`docs/divi5-schema.md`](divi5-schema.md) for the paths every declaration maps to.

## The parameter table

`data/wpbakery-params.json` is generated by `scripts/build-wpbakery-params.php` from both archives
and is the authoritative answer to "does WPBakery declare this field for this element?".

```
$ php scripts/build-wpbakery-params.php
read js_composer.9.0.1.zip: 89 tags; js_composer.7.8.zip: 84 tags
wrote …/data/wpbakery-params.json (91 tags, 1171 parameter names)
```

It reads `config/**` (the element configs `config/lean-map.php` maps a tag to, containers and
deprecated elements included), `include/params/vc_grid_item/shortcodes/` (9.0.1) and
`include/params/vc_grid_item/shortcodes.php` (7.8's single-file, 16-base map), plus the
`vc_add_param()` loop in `class-wpb-template-attributes-migration.php` — the only place
`vc_gitem_image.border_color` and `vc_gitem_post_categories.category_color` are declared. Three
indirections are resolved rather than lost: a function or method the config calls that itself
declares `param_name`s (`get_design_options_tab()`, `vc_btn_element_params()`,
`VcGridsCommon::getBasicAtts()`); `vc_map_integrate_shortcode()`, which is how `vc_cta` comes to
carry `btn_title` and `h2_font_container`; and a variable holding either, resolved two levels deep
where a `'params' =>` names it.

`Helpers\WPBakeryParams::declares( $tag, $param )` answers **three** states, and the difference
matters:

- `true` — WPBakery declares it. An unread declared field is a gap in this converter and is
  reported as a **skipped setting**.
- `false` — WPBakery's config was read and does not declare it, so somebody else added it with
  `vc_add_param()`. Reported under `not_carried_over` kind **`addon`**, never as a skipped setting.
- `null` — the tag has **no entry**: its parameters could not be read at all, so the converter
  keeps its old behaviour rather than guessing. Six core tags are in that state, and none is a
  config file the generator missed:

  | Tag | Why |
  |---|---|
  | `vc_acf`, `vc_gitem_acf`, `vc_gitem_wocommerce` | mapped inline in a vendor bridge (`include/classes/vendors/plugins/…`), and only when that plugin is active |
  | `vc_custom_field` | a render class with no `vc_map()` in the archive (`include/classes/shortcodes/vc-custom-field.php`) |
  | `vc_container_anchor` | not a mapped element: a helper that prints an anchor (`include/helpers/helpers.php`) |
  | `vc_grid` | the runtime shortcode `vc_basic_grid` renders through; no `'base' => 'vc_grid'` exists in either archive |

An **empty** entry (`[]`) and a missing one are different states: `[]` is a tag whose config was
read and declares nothing at all, and every key on such a tag is undeclared.

## Theme parameters on core elements

A theme does not only register elements of its own — it bolts fields onto WPBakery's rows, columns
and text blocks with `vc_add_param()`, and those fields end up in the shortcode of every page the
theme built. `Helpers\ThemeShortcodes::THEME_PARAMS` names the ones this project has source for,
keyed by family and then by tag, and they are reported under `addon` with the family named rather
than as skipped settings.

| Family | Source | Tags it extends |
|---|---|---|
| Ronneby | Ronneby Core 1.5.74 — `inc/vc_custom/dfd_vc_addons.php:901-1729`, `inc/vc_custom/dfd_vc_background/**`, `…/old_modules/Dfd_Override_Parallax.php` | `vc_row` (incl. the whole background panel), `vc_row_inner`, `vc_column`, `vc_column_inner`, `vc_column_text`, `vc_accordion`, `vc_tour`, `vc_video` |
| Ultimate Addons | Ultimate VC Addons 3.19.3 — `modules/ultimate_parallax.php` ("Ultimate Row Backgrounds", 68 live `vc_add_param( 'vc_row', … )` calls) | `vc_row` |

The table is keyed by param name, not by what the page proves is installed — an export carries no
plugin list — which is why the report says the field *matches* a theme's table rather than asserting
that theme added it. A registration that is commented out in the source is deliberately absent, so
an older build's field is still reported as a genuine gap. `wbdc_theme_params` lets a site add
another theme's table.

## Colour tables

`Helpers\Color`. Colours are never invented: a name none of these tables knows resolves to `null`
so the caller can report it under `unresolved_globals`.

### `Color::PALETTE` — the 17-name picker every WPBakery version ships

Read from `vc_convert_vc_color()` (`include/helpers/helpers.php`), cross-checked against
`VcSharedLibrary::$colors_hash`. The canonical key is underscored (`mulled_wine`); a shortcode may
write either spelling.

| | | | |
|---|---|---|---|
| `blue` `#5472d2` | `turquoise` `#00c1cf` | `pink` `#fe6c61` | `violet` `#8d6dc4` |
| `peacoc` `#4cadc9` | `chino` `#cec2ab` | `mulled_wine` `#50485b` | `vista_blue` `#75d69c` |
| `orange` `#f7be68` | `sky` `#5aa1e3` | `green` `#6dab3c` | `juicy_pink` `#f4524d` |
| `sandy_brown` `#f79468` | `purple` `#b97ebb` | `black` `#2a2a2a` | `grey` `#ebebeb` |
| `white` `#ffffff` | | | |

### `Color::BUTTON_LEGACY` — `$btn_solid_colors`' background/text pair

The seven slugs `vc_btn`'s classic style uses: `default` `#f7f7f7`/`#333`, `primary`
`#0088cc`/`#fff`, `info` `#58b9da`/`#fff`, `success` `#6ab165`/`#fff`, `warning` `#ff9900`/`#fff`,
`danger` `#ff675b`/`#fff`, `inverse` `#555555`/`#fff`.

### `Color::BUTTON_MIGRATION`

The rows WPBakery's own 9.0 migration writes when it turns a legacy **button** `color` dropdown into
colorpicker attributes: `bg`, `text`, `hover_bg`, `hover_text` from
`VcSharedLibrary::$btn_solid_colors`, plus `shadow` from `$btn_3d_colors` (a `darken(@bg, 11%)` of
the same background). **24 rows** — the 17 palette names plus the 7 classic Bootstrap-2 slugs.
`$btn_outline_colors` needs no table of its own: every one of its rows is
`[text = bg, border = bg, hover text = text]` of the row here, checked against all 24 slugs.

### `Color::CTA_MIGRATION`

A different table with a different shape, for `vc_cta`'s own `color` dropdown: `text`, `bg`,
`heading`, `shadow` — **no hover pair** — read from `VcSharedLibrary::$cta_colors`, which is what
`Wpb_Attributes_Migration_Abstract::apply_cta_flat_color()`, `apply_cta_3d_color()` and
`apply_cta_outline_color()` consume. `text` is the box's body text (the `text_color` attribute
`WPBakeryShortCode_Vc_Cta` still honours), `bg` its background, `heading` its `custom_text`, and
`shadow` the 3d style's box shadow. **18 rows** — the 17 palette names plus `classic`, the style's
own default, which is not a palette colour (`#f0f0f0` background, `#666` heading).

### `Color::MESSAGE_BOX` — 25 text/border/background triples

Every `.vc_color-<name>.vc_message_box{…}` rule in `assets/css/js_composer.min.css` (identical in
7.8 and 9.0.1): the 17 palette names, the four `color` dropdown presets `info` / `success` /
`warning` / `danger`, and their four `alert-*` classic counterparts
(`config/content/shortcode-vc-message.php`). All 25 are real, addressable `color` /
`message_box_color` values.

| name | text | border | background |
|---|---|---|---|
| `blue` | `#364a8a` | `#c5cff0` | `#edf1fa` |
| `turquoise` | `#085b61` | `#c6ecee` | `#ebfcfd` |
| `pink` | `#d82e21` | `#ffd8d6` | `#fff0ef` |
| `violet` | `#5e4a81` | `#d4c8e9` | `#f0ecf7` |
| `peacoc` | `#366a79` | `#c2e3ec` | `#e9f5f8` |
| `chino` | `#978258` | `#e5ded2` | `#f7f5f2` |
| `mulled_wine` | `#1e1b22` | `#d0ccd6` | `#eae8ed` |
| `vista_blue` | `#3e8e5e` | `#bcebcf` | `#e3f7eb` |
| `orange` | `#c3811c` | `#fbe1ba` | `#fef6eb` |
| `sky` | `#2a6194` | `#bedaf4` | `#eaf3fb` |
| `green` | `#3e562b` | `#c2e1a9` | `#eaf5e2` |
| `juicy_pink` | `#a3231f` | `#fbc7c5` | `#fef5f5` |
| `sandy_brown` | `#c3501c` | `#fbceba` | `#fef1eb` |
| `purple` | `#886389` | `#e3cbe3` | `#f5ecf5` |
| `black` | `#fff` | `#2a2a2a` | `#3c3c3c` |
| `grey` | `#858585` | `#d2d2d2` | `#ebebeb` |
| `white` | `#b3b3b3` | `#e6e6e6` | `#fff` |
| `info` | `#5e7f96` | `#cfebfe` | `#dff2fe` |
| `success` | `#5e7f96` | `#cfebfe` | `#e6fdf8` |
| `warning` | `#9d8967` | `#ffeccc` | `#fff4e2` |
| `danger` | `#a85959` | `#fedede` | `#fdeaea` |
| `alert-info` | `#31708f` | `#bce8f1` | `#d9edf7` |
| `alert-success` | `#3c763d` | `#d6e9c6` | `#dff0d8` |
| `alert-warning` | `#8a6d3b` | `#faebcc` | `#fcf8e3` |
| `alert-danger` | `#a94442` | `#ebccd1` | `#f2dede` |

`Color::PROGRESS_BAR_LEGACY` is `resolve_progress_bar_color()`'s classic `bar_*` map, with
`PROGRESS_BAR_TEXT` `#ffffff`, `PROGRESS_BAR_TEXT_DARK` `#666666` and `PROGRESS_BAR_TEXT_SHADOW`
`#00000040`.

## Size and shape tables

Every value below is a line of `assets/css/js_composer.min.css`, not a guess.

**Button size** (`.vc_btn3-size-<size>`, `Handlers\BtnConverter::SIZES`); the `outline` style adds a
2px border and takes one pixel off each padding side to keep the same box
(`BtnConverter::OUTLINE_PADDING`).

| size | font-size | padding | padding (outline) |
|---|---|---|---|
| `xs` | 11px | 8px 12px | 7px 11px |
| `sm` | 12px | 11px 16px | 10px 15px |
| `md` | 14px | 14px 20px | 13px 19px |
| `lg` | 16px | 18px 25px | 17px 24px |

**Icon size** (`.vc_icon_element-size-<size> .vc_icon_element-icon{font-size:…}`,
`Handlers\IconConverter::SIZES`), in the `em` the stylesheet writes, against the
`.vc_icon_element{font-size:14px}` base WPBakery pins itself: `xs` 1.2em, `sm` 1.6em, `md` 2.15em,
`lg` 2.85em, `xl` 5em. The converter resolves them to pixels because Divi's icon has no such base.

**Shape → border-radius**, per element (each from that element's own rule):

| element | `square` | `rounded` | `round` |
|---|---|---|---|
| `vc_btn` (`.vc_btn3-shape-<shape>`) | 0px | 5px | 2em |
| `vc_cta` (`.vc_general.vc_cta3.vc_cta3-shape-<shape>`) | 0px | 5px | 4em |
| `vc_message` (`.vc_message_box-<style>`) | 0px | 5px | 4em |
| `vc_hoverbox` (`.vc-hoverbox-shape--<shape>`) | 0px | 5px | 50% |

`vc_message`'s box padding is `1em 1em 1em 4em` (`.vc_message_box`); `vc_cta`'s is 28px
(`$default_values` in `config/buttons/shortcode-vc-cta.php`), and its classic background is
`#f7f7f7`. `vc_hoverbox`'s default hover background is `#ebebeb`.

## Box model

WPBakery's box model lives entirely in `assets/css/js_composer.min.css` — there is no global layout
settings option the way Beaver Builder has one. It was measured side by side with Divi on the
Docker site and is recorded in **[`docs/box-model.md`](box-model.md)**, with the raw measurements in
`docs/box-model.json` and the per-module spacing in `docs/module-spacing.json`. The short version,
as shipped in `StyleMapper\GlobalSettingsResolver::DEFAULTS`:

| Key | Value | The WPBakery rule |
|---|---|---|
| `section_padding` | `0px` | WPBakery contributes none (Divi's own default is 56px) |
| `row_width` | `calc(var(--content-width, 80%) + 30px)` | `.vc_row{margin-left:-15px;margin-right:-15px}` |
| `row_max_width` | `calc(var(--content-max-width, 1080px) + 30px)` | same |
| `row_margin_x` | `-15px` | same |
| `nested_row_width` | `calc(100% + 30px)` | a `vc_row_inner` bleeds inside its column |
| `nested_row_max_width` | `none` | same |
| `column_padding_x` | `15px` | `.vc_column-inner{padding-left:15px;padding-right:15px}` |
| `filled_column_padding_top` | `35px` | `.vc_row-has-fill>.vc_column_container>.vc_column-inner{padding-top:35px}` — and `.vc_row-has-fill+.vc_row>…` |

### Default bottom margin, per element kind

`module_margins` in the same constant. A default margin is licensed by a CSS rule on the element's
wrapper, never assumed; an explicit `margin-bottom` in the element's own `css` always wins, because
WPBakery emits it `!important`.

| kind | value | The rule that licenses it | Elements |
|---|---|---|---|
| `content` | `35px` | `.wpb_button,.wpb_content_element,ul.wpb_thumbnails-fluid>li{margin-bottom:35px}` (byte 103311), `.vc_icon_element`, `.vc_toggle:last-of-type` | every element whose config sets `'element_default_class' => 'wpb_content_element'`: the galleries and grids, `vc_progress_bar`, `vc_pie`, the charts, `vc_posts_slider`, `vc_tweetmeme`, `vc_flickr`, `vc_widget_sidebar`, every `vc_wp_*`, `vc_cta` (28px padding + `margin-bottom:35px` in `$default_values`) |
| `button` | `22px` | `.vc_do_btn{margin-bottom:22px}` in WPBakery's per-page default sheet, overriding the stylesheet's 21.73913043px `.vc_btn3-container` | `vc_btn` |
| `message` | `21.74px` | `.vc_message_box` | `vc_message` |
| `toggle` | `21.74px` | `.vc_toggle_content`; the last toggle of a run takes `content` | `vc_toggle` |
| `tta` | `21.74px` | `.vc_tta-container{margin-bottom:21.73913043px}` in `assets/css/js_composer_tta.min.css` — the tta family carries no `.wpb_content_element` at all | `vc_tta_accordion`, `_tabs`, `_tour`, `_pageable`, `_toggle` |
| `social` | `21.74px` | `.entry-content .twitter-share-button,.fb_like,…,.wpb_googleplus,.wpb_pinterest,…{margin-bottom:21.73913043px}` at byte 103390 — after the 35px rule, equal specificity, so it wins where it matches | `vc_facebook` (`fb_like`), `vc_pinterest`, `vc_googleplus` only — `vc_tweetmeme`'s wrapper is `vc_tweetmeme-element` and `vc_flickr`'s is `wpb_flickr_widget` |
| `ult_video` | `20px` | Ultimate Addons `assets/css/video_module.css` `.ult-video{margin:20px}` (all four sides; the handler writes the other three) | `ultimate_video` |
| `inner_row` | `0px` | measured 0px on the nested row | **No handler passes this kind.** It is the constant's record of the measurement, kept so a site can raise it through `wbdc_layout_defaults`; a `vc_row_inner` never reaches `fillModuleMarginBottom()` at all, because `mapStyle()` skips the kinds `section`, `row`, `column` and `group` before it. |
| *(none)* | — | no rule on the wrapper | The handler passes `'none'` to `mapStyle()`, which skips the default outright: `vc_pricing_table` (`$default_values` has no `margin-bottom`), `vc_hoverbox` (`.vc-hoverbox-wrapper`), `contact-form-7`, `vc_custom_heading`, `vc_zigzag`, `vc_empty_space`, `vc_copyright`, `vc_custom_field`, `just_icon` (`.ult-just-icon-wrapper`), **and every Ronneby element**. `vc_flexbox_container`, `vc_grid_container` and `ult_content_box` get none for the other reason: they are the `group` kind, which `mapStyle()` skips. |

`moduleMarginBottom()` with an unknown kind returns the `content` value. All of it sits behind the
`wbdc_layout_defaults` filter (`array_replace_recursive`, so a site can override one key).

**Every Ronneby element takes `none`**, for one reason rather than twenty-two: Ronneby Core
registers its elements with `add_shortcode()` and renders them from its own PHP, so WPBakery's
`.wpb_content_element` class is never printed, and none of the theme's own stylesheets
(`dfd-ronneby/assets/css/{app,visual-composer,mobile-responsive}.css`, `ronneby-core/**/*.css`)
gives those wrappers a bottom margin. The corpus corroborates it: the 96 demo exports carry
**13,689 `dfd_spacer`** elements, because Ronneby spaces its content with explicit spacers.

## Add-on and theme element fields

### Ultimate Addons for WPBakery 3.19.3

`Helpers\UltimateFields` decodes the three packed shapes every one of its elements uses
(`references/Ultimate_VC_Addons.zip`, `modules/*.php`):

- **Media** (`icon_img`, `custom_thumb`, `bg_image`) — `id^4282|url^https://…|caption^null|alt^null|title^…`, `key^value` pairs joined with `|`, the literal string `null` where the attachment has no value (the `ult_img_single` param).
- **Responsive number** (`title_font_size`, `desc_font_line_height`, `play_size`) — `desktop:20px;tablet:18px;mobile:16px;`. Only the desktop band has a Divi equivalent that is not a guess about breakpoints.
- **Icon** — the Font Icon Manager's stored class, `Defaults-database` or `Defaults-edit pencil-square-o`: icon set, a dash, then the icon's own name (a Font Awesome 4 name for the `Defaults` set).

The six elements with handlers are `bsf-info-box`, `just_icon`, `stat_counter`,
`ultimate_pricing`, `ultimate_video`, `ult_content_box`
(`modules/ultimate_{info_box,just_icon,stats_counter,pricing_tables,videos,content_box}.php`); all
are registered `approximate: true`, because the source read for them is 3.19.3 while a live site may
run a newer build.

### DFD Ronneby (Ronneby Core 1.5.74)

`Helpers\RonnebyParams` decodes the theme's own packed shapes:

- **Font options** (`title_font_options`, `subtitle_font_options`, `font_options`, `number_font_options`, `tab_title_font_options`) — `tag:h3|font_size:45|line_height:40|color:%23333333|letter_spacing:2`. Declared in `params/param_font_container.php:321-339`, but the semantics are the render side's (`_crum_parse_text_shortcode_params()`, `dfd_vc_addons.php:155-243`): split on `|` then `:`, decode nothing but `%23` → `#` and `%2C` → `,` on `color`, and emit every numeric field in **pixels**. The three `font_style_*` flags are `font-style:italic`, `font-weight:bold`, `text-decoration:underline`.
- **Responsive text** (`title_responsive`, `content_responsive`, `delimiter_text_responsive`) — `font_size_desktop:40|font_size_tablet:30|line_height_mobile:26`, read by `Dfd_Resposive_Text_Param::responsive_css()`. Its bands are media queries, not Divi breakpoints: desktop 1024–1279px, tablet 800–1023px, mobile ≤799px.
- **Param group** (`dfd_social_networks`, `list_fields`, `info_fields`, `location_list`) — WPBakery's own `param_group`, i.e. `json_decode( urldecode( … ) )`.
- **Spacer sizes** — four plain numbers with four resolutions (`modules/dfd_spacer.php:44-126`), applied by the theme's own script.

The demo corpus carries attribute names from more than one build of the theme on the same tag
(`upload_image` beside `image`, `main_style` beside `style`), which is why all 23 Ronneby handlers
are registered approximate.

## Which family a non-core tag belongs to

`Helpers\ThemeShortcodes::FAMILIES` (filterable through `wbdc_theme_families`). Exact tags win over
prefixes, and anything neither rule claims is its own family, "Other shortcodes" — a real answer,
not a failure. Families whose `kind` is `addon` are a theme's own elements; `integration` is a
plugin that renders a form or a shop.

| Family | Matched by |
|---|---|
| The Retailer | 24 exact tags (The Retailer Extender 10.0.6) |
| Ronneby | prefix `dfd_` + 22 exact tags (`announcement`, `piecharts`, `rotate_box`, `tooltip`, …) |
| Ultimate Addons | prefixes `ultimate_`, `ult_`, `bsf-`, `bsf_`, `info_list`, `icon_counter`, `just_icon`, `stat_counter` |
| Massive Addons | `mpc_` |
| Salient | `nectar_`, `fancy_box`, `image_with_animation`, `divider_line`, `milestone`, … |
| Bridge | `qode_`, `no_` |
| The7 | `dt_` |
| Jupiter | `mk_` |
| Templatera | `templatera` |
| WooCommerce (integration) | `products`, `product_category`, `product_page`, `add_to_cart`, `woocommerce_` |
| Contact Form 7 (integration) | `contact-form-7`, `contact-form` |
| Gravity Forms (integration) | `gravityforms`, `gravityform` |
| Sliders (integration) | `rev_slider`, `layerslider`, `smartslider3`, `metaslider` |

## Bundled and corpus content

- `fixtures/wpbakery-templates/*.txt` — WPBakery's own bundled templates (`config/templates.php`), 75 files, extracted by `scripts/wpb-templates-to-fixtures.php`.
- `fixtures/wpbakery-layouts/*.txt` — the 35 layouts of the free "Layouts for WPBakery" plugin, fetched by `scripts/fetch-layouts-corpus.php`.
- `wpbakery templates/ronneby/*.xml` — 96 real WordPress exports, one per WPBakery demo of the DFD Ronneby ThemeForest package (510 WPBakery documents, 44,278 elements). Three are committed; the rest are re-extractable with `scripts/extract-ronneby-corpus.sh`.

`php scripts/element-coverage.php` reports what each corpus holds and which handler claims each tag.
