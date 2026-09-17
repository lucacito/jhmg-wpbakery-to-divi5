=== JHMG Converter For WPBakery to Divi 5 ===
Contributors: lucaslopvet
Tags: divi migration, page builder converter, wpbakery to divi, divi 5, shortcode converter
Requires at least: 5.9
Tested up to: 7.1
Stable tag: 1.2.0
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Convert WPBakery pages into native Divi 5 layouts. Check the result before anything is written, convert any number of pages, undo any run.

== Description ==

Convert pages built with WPBakery Page Builder into native Divi 5 block layouts — sections become sections, rows become rows, columns keep their widths, and every element WPBakery registers reaches a Divi 5 module with its content, links, images, colours, typography, spacing and backgrounds carried across.

This plugin is not affiliated with, endorsed by or connected to WPBakery Page Builder or its makers. "WPBakery" and "WPBakery Page Builder" are trademarks of their respective owners, used here only to say what this plugin converts.

If your WPBakery pages are on this site, pick them from a list — one page or all of them, no export needed. Converting from another site? Export it there from Tools → Export (WPBakery keeps its layout inside the page content, so the export holds it) and upload the file here.

Before you convert, click **Check selected pages** to see a conversion report: the structure the conversion will produce, laid out as an outline, and everything that could not be carried over, by name. Nothing is written until you click Convert. Converting rewrites the page you picked, so it keeps its address, its publish date, its custom fields and everything linking to it — it is the same page, now built in Divi 5. Undo puts the WPBakery version back with one click. Prefer a copy? Tick one box and you get a separate Divi draft instead, with the original left exactly as it is.

### What converts

Counted by `scripts/element-coverage.php` straight off the converter's own handler registry: **75 of the 75 elements WPBakery registers** reach a handler written for them (38 exact, 31 approximate, 6 read by their parent element), plus **23 template-only and vendor tags**. Beyond WPBakery's own set, this plugin ships dedicated handlers for **31 theme and add-on elements** — Ronneby × 23, Sliders × 2, Ultimate Addons × 6 — and keeps every other theme element rather than dropping it.

**Structure** — `vc_section`, `vc_row`, `vc_row_inner`, `vc_column`, `vc_column_inner`: full-width and stretched rows, backgrounds, overlays, gradients, videos, parallax where Divi has it, padding, minimum height, equal-height columns, and every WPBakery column width (twelfths and fifths alike) mapped exactly onto Divi's grid.

**Content elements** — `vc_basic_grid`, `vc_btn`, `vc_column_text`, `vc_copyright`, `vc_cta`, `vc_custom_heading`, `vc_empty_space`, `vc_facebook`, `vc_flickr`, `vc_gallery`, `vc_gmaps`, `vc_goo_maps`, `vc_googleplus`, `vc_hoverbox`, `vc_icon`, `vc_images_carousel`, `vc_line_chart`, `vc_masonry_grid`, `vc_masonry_media_grid`, `vc_media_grid`, `vc_message`, `vc_pie`, `vc_pinterest`, `vc_posts_slider`, `vc_pricing_table`, `vc_progress_bar`, `vc_raw_html`, `vc_raw_js`, `vc_round_chart`, `vc_separator`, `vc_single_image`, `vc_text_separator`, `vc_toggle`, `vc_tta_accordion`, `vc_tta_pageable`, `vc_tta_section`, `vc_tta_tabs`, `vc_tta_toggle`, `vc_tta_toggle_section`, `vc_tta_tour`, `vc_tweetmeme`, `vc_video`, `vc_zigzag`.

**WordPress widgets** — `vc_widget_sidebar`, `vc_wp_archives`, `vc_wp_calendar`, `vc_wp_categories`, `vc_wp_custommenu`, `vc_wp_links`, `vc_wp_meta`, `vc_wp_pages`, `vc_wp_posts`, `vc_wp_recentcomments`, `vc_wp_rss`, `vc_wp_search`, `vc_wp_tagcloud`, `vc_wp_text`. Menus and search become Divi's own modules; the rest are rendered on this site and kept as static HTML so the page still looks right.

**Deprecated elements** — `vc_button`, `vc_button2`, `vc_cta_button`, `vc_cta_button2`, `vc_tabs`, `vc_tab`, `vc_tour`, `vc_accordion`, `vc_accordion_tab`. Pages built before WPBakery 9.0 still hold these, so they are read and converted into the modern equivalent before anything else runs.

**9.0 additions** — `vc_flexbox_container`, `vc_flexbox_container_item`, `vc_grid_container`, `vc_grid_container_item`, the flex and grid containers WPBakery 9 introduced.

**Template-only and vendor tags** — `vc_gutenberg`, `vc_custom_field`, `rev_slider_vc`, `layerslider_vc`, `contact-form-7` and WooCommerce's eighteen shortcodes (`woocommerce_cart`, `woocommerce_checkout`, `woocommerce_order_tracking`, `woocommerce_my_account`, `recent_products`, `featured_products`, `product`, `products`, `add_to_cart`, `add_to_cart_url`, `product_page`, `product_category`, `product_categories`, `sale_products`, `best_selling_products`, `top_rated_products`, `product_attribute`, `related_products`). Product grids become Divi's Shop module; the rest keep their shortcode inside a Divi module, so they carry on working.

**Theme and add-on elements** — a WPBakery site usually runs a ThemeForest theme whose own elements outnumber WPBakery's on the page. None of them is ever dropped. Converting a page on this site renders it with the theme active and keeps the result as static HTML, exactly as the page looks today; converting from an export leaves a labelled placeholder holding the shortcode and its text. Either way the report names the family and the count — "Ronneby × 12" — rather than a list of tags nobody recognises. The families it recognises by name are The Retailer, Ronneby, Ultimate Addons, Massive Addons, Salient, Bridge, The7, Jupiter, Templatera, WooCommerce, Contact Form 7, Gravity Forms and Sliders; anything else is counted as "Other shortcodes", which is an answer rather than a failure.

**Ultimate Addons for WPBakery** — six of its elements convert into real Divi modules rather than static copies: `ult_content_box`, `bsf-info-box`, `just_icon`, `ultimate_pricing`, `stat_counter` and `ultimate_video`. Ronneby's own element set (23 of them) and the Revolution Slider and LayerSlider bridges convert the same way.

**Design settings** — WPBakery's Design Options CSS is read rule by rule: margins and padding (with units and tablet/phone values), background colours, images, gradients and overlays, borders, radius and shadows, typography (family, weight, size, line height, letter spacing, alignment, transform, decoration), text colours, minimum heights, widths, alignment, custom IDs and classes, and responsive visibility. Anything with no Divi setting is carried as custom CSS on the module rather than lost. Font Awesome icons become the identical Divi icon.

**Raw HTML and Raw JS** — a Raw HTML element becomes a Divi Code module. Its markup is held to what your account may publish: an administrator with the `unfiltered_html` capability gets it as written, anyone else gets it through WordPress's own HTML filter, and the report names every tag or attribute that was removed. The same filter covers every other converted module. A Raw JS element is never converted; the report lists it so you can decide whether the page still needs it.

**Reported, not silently lost** — animations, visibility rules, click actions, third-party connections, hover colours, backgrounds that could only be approximated, global colours that could not be resolved, images whose attachment is not on this site, and any WPBakery setting this converter did not map. A field a theme bolted onto a WPBakery element is reported separately, as a theme feature rather than as a converter gap, because that is what it is.

### Features

* Convert pages straight from your WPBakery site — pick one, several or all of them from a list
* Check before converting: see the structure and exactly what will not carry over
* Upload a WordPress export, or a .txt / .html file of shortcodes, and convert every page in it
* Every element WPBakery registers, both the 9.0 names and the older ones
* WPBakery templates are converted too, as page drafts
* A detailed per-page report, one-click undo for every run

A separate [Pro add-on](https://divi5lab.com/plugins/wpbakery-to-divi-5), sold and hosted outside WordPress.org, adds its own Divi Library exporter (WPBakery templates become Divi Library layouts rather than page drafts) and priority support. Nothing in this plugin is limited or locked without it.

### Step by step

1. Install and activate this plugin on your Divi 5 site.
2. Go to **Tools → WPBakery → Divi 5**.
3. Tick the pages you want to convert and click **Check selected pages**.
4. Read the report, then click **Convert to Divi 5** — each page is rewritten in Divi 5 where it stands (or tick the box for a separate draft).
5. Review it in the Divi Builder. Undo puts the WPBakery version back.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/jhmg-converter-for-wpbakery-to-divi-5`, or install through the Plugins screen.
2. Activate the plugin. Divi 5.0 or newer must be active for the converter to run.
3. Go to Tools → WPBakery → Divi 5 to begin.

== Frequently Asked Questions ==

= Do I need WPBakery Page Builder installed? =

Only to pick pages from the list on this site, and to have theme elements copied as static HTML rather than left as placeholders. To convert pages from another site, export them there and upload the export file here — WPBakery is not needed on the destination.

= Will this change my WPBakery pages? =

Yes, and that is the point: the page you pick is converted where it stands, so it keeps its address, its publish date, its author, its comments, its custom fields and every link pointing at it. Nothing moves, so nothing can be lost on the way. Before anything is written you get the full report, and afterwards Undo puts the WPBakery content back exactly as it was — the original shortcodes are kept on the page for precisely that.

If you would rather not touch the original, tick **Convert into a new draft instead** on the report screen. You then get a separate Divi draft and the original stays as it is. That draft carries the original's custom fields (ACF included), featured image, page template, categories and tags, publish date, author and excerpt. The one thing it cannot carry is the permalink, because two published posts cannot share one: publishing the draft beside the original makes WordPress append `-2`, so decide which of the two keeps the address and redirect the other.

WPBakery templates are never converted in place. A template is what you build pages from, so it is always copied.

= A page of mine is missing from the list. Why? =

The list shows every post whose content holds `[vc_row` or `[vc_section`, across every public post type plus WPBakery's own template types. A page WPBakery built but that no longer holds those shortcodes has nothing left to convert. If WPBakery's own flag is missing from a page the list still shows it, badged, because the shortcodes are what convert.

= What happens to elements the converter does not know? =

Nothing is dropped. A theme or add-on element is rendered on this site and kept as static HTML, or — converting from an export, where nothing can render it — left as a labelled placeholder holding its shortcode. Both are listed in the report and on the coverage panel so you know what to rebuild by hand.

= Does it need Divi 5? =

Yes. The converter writes Divi 5 block content. On Divi 4 or without Divi it explains itself and does nothing.

== Screenshots ==

1. The converter — pick an installed WPBakery page or upload an export; every run is listed and can be undone with one click
2. The conversion report shown before anything is written: the structure it will produce, everything that will not carry over, and the choice between converting the page itself or a new draft
3. Conversion results — what each page converted to and the notes worth reading, with links to edit and view it

== External services ==

This plugin can optionally send a short report to divi5lab.com so that the most
commonly missing WPBakery elements get built first.

* **Service:** divi5lab.com coverage endpoint — https://divi5lab.com/api/plugin/coverage
* **What is sent:** two fields — `widget_types` (the names of WPBakery element tags
  your conversions could not convert, for example `vc_pie`, together with the theme
  family keys those pages used, for example `ronneby`) and `product` (a fixed
  identifier for this plugin). Nothing else — no site address, no page content,
  no personal data, no licence or account information.
* **When:** at most once a week, and only after you explicitly turn sharing on from
  the Conversion coverage panel. Sharing is opt-in, off by default, and nothing is sent until you enable it.
* **Turning it off:** use "Stop sharing" on the same panel at any time.
* Terms: https://divi5lab.com/license — Privacy policy: https://divi5lab.com/license#privacy

== Changelog ==

= 1.2.0 =
* Convert any number of pages in one run, from this site or from an upload. There is no per-run limit.
* Converted markup is held to what your account may publish: users without the `unfiltered_html` capability get it through WordPress's HTML filter, and the report names what was removed. Raw JS elements are reported, never converted.
* The plugin folder and text domain are now `jhmg-converter-for-wpbakery-to-divi-5`.

= 1.1.0 =
* Conversions now rewrite the page you picked, so it keeps its permalink, publish date, author, comments and custom fields. Suggested by a user who pointed out that a converted post at a new address means a 301 nobody asked for.
* The original WPBakery shortcodes are kept on the page, and Undo puts them back. Undo no longer needs the Trash for these runs.
* "Convert into a new draft instead" on the report screen keeps the old behaviour, original untouched.
* WPBakery templates are always copied, never converted in place.

= 1.0.1 =
* The converted post now keeps the original's custom fields (ACF included), featured image, page template, categories and tags, publish date, author, excerpt and menu order. Reported by a user whose ACF fields came out blank.
* WPBakery's own metadata, Divi's and the editor's are deliberately left behind; `wbdc_copy_source_identity` and `wbdc_copied_meta_keys` adjust what is carried.

= 1.0.0 =
* First release: convert WPBakery Page Builder pages into native Divi 5 block layouts
* Convert directly from installed pages, with a check-before-convert report and one-click undo
* Upload WordPress exports, or a .txt / .html file of WPBakery shortcodes
* Every element WPBakery registers, in both the 9.0 and the pre-9.0 attribute forms
* Theme and add-on elements kept as static HTML or as labelled placeholders, counted by family
* Font Awesome icons map to the identical Divi icons

== Upgrade Notice ==

= 1.2.0 =
Convert as many pages as you like in one run. Raw JS elements are no longer copied into Divi; the report lists them.

= 1.1.0 =
Conversions now change the page you pick rather than making a second one, so it keeps its address and everything linking to it. Undo restores the WPBakery version. Tick the box on the report screen for the old behaviour.

= 1.0.1 =
Converted posts now keep their custom fields, featured image, taxonomy terms, date and author. Pages converted with 1.0.0 were created without them.

= 1.0.0 =
First release.
