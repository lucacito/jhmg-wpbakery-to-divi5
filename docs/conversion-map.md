# Conversion map

What ships, tag by tag. This table is the registry (`Converter\Registry\ConverterRegistry::registerDefaults()`),
not the design spec: where the two differ the registry wins, and the row says so.

**Fidelity** is the registry's own `approximate` flag. *Exact* means every field the handler reads
was taken from the WPBakery source and Divi expresses the same thing; *approximate* means the
handler's output is a deliberate stand-in rather than the same object in Divi's vocabulary, and the
engine counts it separately (see [`conversion-reporting.md`](conversion-reporting.md)).

Design settings on any element go through the StyleMapper — see
[`divi5-schema.md`](divi5-schema.md) for every attribute path and
[`wpbakery-schema.md`](wpbakery-schema.md) for the `css` rule format. An element's optional `title`
becomes a `divi/heading` (h2) before the module, `el_id`/`el_class` go to `htmlAttributes`, and
`css_animation` is reported.

## Structure

| WPBakery | Divi 5 | Fidelity |
|---|---|---|
| `vc_section` | `divi/section` | exact; design options paint on the section |
| `vc_row` | `divi/section` holding one `divi/row` | exact; the row's background, padding and min-height are lifted to the section, the row keeps WPBakery's 15px bleed; `gap` → row `columnGap`/`rowGap`; `full_width` stretch variants → width `100%`, no max-width; `equal_height` / `columns_placement` / `content_placement` → `alignItems`; `rtl_reverse` → `flexDirection: row-reverse` only on an RTL site, reported otherwise |
| `vc_row_inner` | `divi/row` inside the column | exact (Divi 5 nests column → row → column); bleeds `calc(100% + 30px)` against its column |
| `vc_column`, `vc_column_inner` | `divi/column` | exact; twelfths and fifths map onto Divi's 24-grid (`flexType` + `type`), offsets become margins, `vc_hidden-*` becomes `disabledOn` |

## Content elements

| WPBakery | Divi 5 | Fidelity |
|---|---|---|
| `vc_column_text` | `divi/text` | exact; content through `wpautop` exactly as WPBakery renders it |
| `vc_custom_heading` | `divi/heading` | exact; `font_container` → level/align/size/line-height/colour, `google_fonts` → family/weight/italic, `source=post_title` → the `post_title` dynamic-content token |
| `vc_single_image` | `divi/image` (+ `divi/text` caption, + `divi/heading` title) | exact; `style` → radius/border/box-shadow, `onclick` → link or lightbox, `source=featured_image` → the `post_featured_image` dynamic-content token |
| `vc_btn` | `divi/button` | exact incl. the FA icon; hover colours reported |
| `vc_button`, `vc_button2` | `divi/button` | exact — normalised to `vc_btn` first |
| `vc_icon` | `divi/icon` | exact; `background_style` → module background + radius, other icon libraries reported |
| `vc_separator` | `divi/divider` | exact |
| `vc_zigzag` | `divi/divider` | **approximate** — Divi has no zigzag; a straight line stands in and the pattern is reported |
| `vc_text_separator` | `divi/heading` (h4) over `divi/divider` | **approximate** — WPBakery draws the rule *through* the title, Divi under it |
| `vc_empty_space` | `divi/divider` with the line hidden | exact; `height` → `sizing.height` |
| `vc_message` | `divi/text` | exact; the colour preset supplies text, border and background from the 25-row message-box table |
| `vc_toggle` | `divi/toggle` | exact; `open` → open state |
| `vc_copyright` | `divi/text` | exact; `prefix` + `©` + the `current_date` token (`Y`) + `postfix` |
| `vc_raw_html` | `divi/code` | exact; base64 content decoded, then held to the converting user's capability by `MarkupSanitiser` (see `conversion-reporting.md`) |
| `vc_raw_js` | — (no block) | never written; reported under `not_carried_over` kind `custom_code` with its length |
| `vc_gutenberg` | `divi/code` | **approximate** — `do_blocks()` on this site (a static copy), the block comments kept verbatim from an export |
| `vc_custom_field` | `divi/text` with the `post_meta_key` token | **approximate** — WPBakery resolves its meta token only inside a grid item |

## Composites

| WPBakery | Divi 5 | Fidelity |
|---|---|---|
| `vc_tta_accordion` › `vc_tta_section` | `divi/accordion` › `divi/accordion-item` | exact |
| `vc_accordion` › `vc_accordion_tab` | as above | exact — normalised to the tta tags first |
| `vc_tta_tabs` › `vc_tta_section` | `divi/tabs` › `divi/tab` | exact |
| `vc_tabs` › `vc_tab` | as above | exact — normalised first |
| `vc_tta_tour`, `vc_tour` | `divi/tabs` | **approximate** — a tour stacks its controls down one side; Divi draws one horizontal strip |
| `vc_tta_pageable` | `divi/tabs` | **approximate** — a pageable container replaces the tabs with dots |
| `vc_tta_toggle` › `vc_tta_toggle_section` | `divi/tabs` with one tab per section | **approximate** — Divi has no two-state switch module; a tab strip is the same behaviour drawn differently |

A panel holds blocks in WPBakery and HTML in Divi, so `Converter\ContentFlattener` converts the
children and renders the resulting blocks back to HTML. Anything it cannot render becomes a
labelled comment, reported under `layout`. **Flattened children are not counted as converted**
(`ConverterEngine::withConvertedCountSuppressed()`): the blocks are read and thrown away, and
counting them would inflate `quality.module_coverage` with modules that do not exist.

`active_section` (and the equivalent active tab) is **not expressible** in Divi 5.12.1:
`accordion-item` / `tabs` `module.json` expose `openToggle` / `activeTab` as styling selectors only,
and `AccordionItemModuleUtils::get_toggle_class_name()` / `TabModule::_should_be_active_tab()` open
the first child by position. It is reported under `not_carried_over` kind `layout`.

## Galleries, carousels, counters, charts

| WPBakery | Divi 5 | Fidelity |
|---|---|---|
| `vc_gallery` | `divi/gallery` (grid or slider) | exact; external `custom_srcs` become one `divi/image` per URL in a nested flexed row |
| `vc_media_grid`, `vc_masonry_media_grid` | `divi/gallery` grid | **approximate** — the grid-item template and masonry packing have no Divi equivalent |
| `vc_images_carousel` | `divi/slider` › `divi/slide` | exact; one slide per image |
| `vc_progress_bar` | `divi/counters` › `divi/counter` | exact; one bar per `values` entry |
| `vc_pie` | `divi/circle-counter` | exact |
| `vc_round_chart`, `vc_line_chart` | **`divi/charts`** | **approximate** — Chart.js on both sides, but the stroke, legend and animation styling is WPBakery's own. *The spec's table said a `divi/code` text placeholder; Divi 5.12.1 ships a real chart module and the registry uses it.* |

`vc_line_chart`'s `x_values` / `y_values` are split on **`;`**, which is what
`include/templates/shortcodes/vc_line_chart.php` does.

## Boxes, lists, queries

| WPBakery | Divi 5 | Fidelity |
|---|---|---|
| `vc_cta` | `divi/cta` | exact; `btn_*` → the CTA's integrated button |
| `vc_cta_button`, `vc_cta_button2` | `divi/cta` | exact — normalised to `vc_cta` first (`call_text` is the box, so a `vc_btn` would lose it) |
| `vc_hoverbox` | `divi/blurb` (+ `divi/button`) | **approximate** — a flip card flattened into one blurb; the flip reported |
| `vc_pricing_table` | `divi/pricing-tables` › `divi/pricing-table` | exact |
| `vc_basic_grid`, `vc_masonry_grid` | `divi/blog` | **approximate** — the query converts, the grid-item template is WPBakery's own |
| `vc_posts_slider` | `divi/post-slider` | **approximate** |

Blog and post-slider **categories are written only when they resolve**
(`categoryIds()`): WPBakery stores category *names* or slugs, Divi wants term ids, so a name the
site cannot resolve is reported rather than guessed at.

## Maps, video, containers

| WPBakery | Divi 5 | Fidelity |
|---|---|---|
| `vc_gmaps` | `divi/code` holding the stored `<iframe>` | **approximate** — the element *is* a whole key-free Google embed (`textarea_safe`-encoded in `link`), and Divi's map centres on lat/lng, so the working embed is kept |
| `vc_goo_maps` | `divi/map` › `divi/map-pin` when `location` is a coordinate pair; `divi/code` with the same embed when it is an address | **approximate** — `MapModule` reads `lat`/`lng` (`address` is the Visual Builder's geocoder input only), nothing here can geocode, and a map centred on `0,0` would be worse than the embed; a real map needs a Google API key in Divi's options, which is reported |
| `vc_video` | `divi/video`, or `divi/code` for a URL Divi cannot play | exact for YouTube, Vimeo and media files (Divi's own `et_pb_check_oembed_provider()` list); anything else keeps the embed and is reported |
| `vc_flexbox_container` › `vc_flexbox_container_item` | `divi/group` (flex, wrap, gap) › one `divi/group` per item | exact — Divi 5.12.1 has the flex layout, so this is not the fallback |
| `vc_grid_container` › `vc_grid_container_item` | `divi/group` on Divi's own grid layout | exact — `display: grid`, `gridColumnWidths: equal`, `gridColumnCount` plus the gaps; WPBakery's `--grid-rows` has no equivalent and is reported |

## Widgets, social, sliders, WooCommerce, forms

| WPBakery | Divi 5 | Fidelity |
|---|---|---|
| `vc_widget_sidebar` | `divi/sidebar` | exact |
| `vc_wp_custommenu` | `divi/menu` | exact |
| `vc_wp_search` | `divi/search` | exact |
| `vc_wp_text` | `divi/text` | exact |
| `vc_wp_archives`, `vc_wp_calendar`, `vc_wp_categories`, `vc_wp_links`, `vc_wp_meta`, `vc_wp_pages`, `vc_wp_posts`, `vc_wp_recentcomments`, `vc_wp_rss`, `vc_wp_tagcloud` | the widget rendered with `the_widget()` into `divi/code` (a static copy) on this site; a labelled placeholder from an export | **approximate** — one class per tag from the templates (`WP_Widget_Archives` …) |
| `vc_facebook`, `vc_tweetmeme`, `vc_pinterest`, `vc_googleplus`, `vc_flickr` | `divi/code` placeholder | **approximate** — a third-party script, not a Divi module |
| `rev_slider_vc`, `rev_slider`, `layerslider_vc`, `layerslider` | `divi/code` placeholder naming the deck | **approximate** — the slides live in the slider plugin's own tables |
| the 18 WooCommerce shortcodes | `divi/code` holding the shortcode | **approximate** |
| `contact-form-7` | `divi/contact-form-7` | exact — Divi 5.12.1 ships the module |

**`vc_woocommerce` is not a shortcode.** It is a wrapper template
(`Vc_Vendor_Woocommerce`), so the handler registers the 18 tags of
`Vc_Vendor_Woocommerce::WC_SHORTCODES` instead — `woocommerce_cart`, `woocommerce_checkout`,
`woocommerce_order_tracking`, `woocommerce_my_account`, `recent_products`, `featured_products`,
`product`, `products`, `add_to_cart`, `add_to_cart_url`, `product_page`, `product_category`,
`product_categories`, `sale_products`, `best_selling_products`, `top_rated_products`,
`product_attribute`, `related_products` — each kept in a `divi/code` block, which is how WPBakery
itself renders them. *The spec's table named `vc_woocommerce`; the registry follows the source.*
Divi 5.12.1 does ship `components/woocommerce/*` modules (`[products]` ↔ `divi/shop`); mapping onto
them is out of scope for 1.0.0.

## Ultimate Addons for WPBakery

All six are registered **approximate** by design: the source read for them is 3.19.3 while a live
site may run a newer build.

| Ultimate Addons | Divi 5 |
|---|---|
| `bsf-info-box` | `divi/blurb` (+ `divi/button`) |
| `just_icon` | `divi/icon`, or `divi/image` for a custom image icon |
| `stat_counter` | `divi/number-counter` |
| `ultimate_pricing` | `divi/pricing-tables` › `divi/pricing-table` |
| `ultimate_video` | `divi/video` |
| `ult_content_box` | `divi/group` with background, border and padding; children converted inside |

## DFD Ronneby

23 handlers, every one **approximate**: the source is Ronneby Core 1.5.74, and the demo corpus
carries attribute names from more than one build of the theme on the same tag (`upload_image`
beside `image`, `main_style` beside `style`).

| Ronneby | Divi 5 |
|---|---|
| `dfd_spacer` | `divi/divider` (line hidden) |
| `dfd_heading` | `divi/heading` + `divi/text` + `divi/divider` |
| `dfd_single_image` | `divi/image` |
| `dfd_button` | `divi/button` |
| `dfd_delimiter` | `divi/divider` (+ `divi/text`, or `divi/image`) |
| `dfd_info_box` | `divi/blurb` (+ `divi/button`) |
| `dfd_icon_list` › `dfd_icon_list_item` | `divi/icon-list` › `divi/icon-list-item` |
| `dfd_google_map` | `divi/map` › `divi/map-pin` |
| `dfd_accordion` | `divi/accordion` |
| `dfd_tta_tabs`, `dfd_tta_tour` | `divi/tabs` |
| `dfd_blog_posts` | `divi/blog` |
| `dfd_new_social_accounts` | `divi/social-media-follow` › `divi/social-media-follow-network` |
| `announcement` | `divi/blurb` |
| `info_banner` | `divi/cta` |
| `new_team_member` | `divi/team-member` |
| `new_testimonials` | `divi/testimonial` |
| `facts` | `divi/number-counter` + `divi/text` |
| `progressbar` | `divi/counters` › `divi/counter` |
| `piecharts` | `divi/circle-counter` + `divi/text` |
| `countdown` | `divi/countdown-timer` |
| `videoplayer` | `divi/video` + `divi/heading` + `divi/text` |

`dfd_icon_list_item` on its own is one list item and is read by its parent. Every other Ronneby tag
(`price_list`, `dfd_carousel`, `rotate_box`, `tooltip`, `woocomposer_*`, …) keeps the theme-family
path below — nothing is dropped.

## Anything else

| | |
|---|---|
| a theme's or add-on's shortcode, on this site (`mode: direct`) | rendered with `do_shortcode()` into a `divi/code` block — a **static copy**; reported under `static_copies` + `theme_elements` |
| the same, from an uploaded file (`mode: import`) | a **labelled placeholder** keeping the tag, its text and its position; reported under `not_carried_over` kind `addon` + `theme_elements` |
| a core WPBakery tag with no handler | a labelled placeholder, listed under `unsupported` |
| a `[1]`-style bracket token nobody registered | re-emitted as text, counted under `bracketed_text` — not a failure |

`Handlers\ThemeShortcodeConverter` is not a rescue path: spec §7 makes it the whole of the
theme/add-on story, and it is why nothing is ever dropped. See
[`conversion-workflow.md`](conversion-workflow.md).

## Coverage as shipped

`php scripts/element-coverage.php` at `d76abab`:

```
Coverage
  Registered WPBakery tags mapped   75 / 75   (38 exact, 31 approximate, 6 read by their parent element)
  Template-only and vendor tags     23 / 23   (the WPBakery render templates and WooCommerce's 18)
  Theme / add-on tags seen          73 in 3 families, 30 of them converted by a handler of ours
  Theme / add-on handlers shipped   31          (Ronneby × 23, Sliders × 2, Ultimate Addons × 6)
```

The six "read by their parent element" tags are `vc_tta_section`, `vc_tta_toggle_section`,
`vc_tab`, `vc_accordion_tab`, `vc_flexbox_container_item` and `vc_grid_container_item`.
`ConverterRegistry::CORE_TAGS` is the separate list of every tag WPBakery 9.0.1 itself registers; it
is what tells a core element with no handler (which belongs in `unsupported`) from a theme's
shortcode (which never does).
