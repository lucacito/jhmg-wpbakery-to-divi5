# Divi 5 block schema (the target)

Read from **Divi 5.12.1** (`references/Divi.zip`). Every path below was checked against the source
before it was written, and every path this converter writes is listed here. Two abbreviations are
used for the extracted tree `Divi/includes/builder-5/`:

- **`VB/`** = `visual-builder/packages/module-library/src/components/`
- **`SRV/`** = `server/Packages/`

Do not invent block types or attribute shapes. If a path is not here, read the module's own
`module.json` (and its `server/Packages/ModuleLibrary/<Module>/<Module>Module.php`, which is what
actually consumes the attribute) before writing it.

## Storage

`post_content` holds WordPress block comments wrapped in `divi/placeholder`; each block carries
`builderVersion` (the running `ET_BUILDER_VERSION`), so Divi's own migrations leave the attributes
alone.

```
<!-- wp:divi/placeholder -->
<!-- wp:divi/section {"builderVersion":"5.12.1", ...} -->
<!-- wp:divi/row {...} --><!-- wp:divi/column {...} -->
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Hello"}}}} /-->
<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section -->
<!-- /wp:divi/placeholder -->
```

Post meta written by `Exporters\DiviExporter::export()` / `::save()`:

| Key | Value |
|---|---|
| `_et_pb_use_builder` | `on` |
| `_et_pb_use_divi_5` | `on` (Divi checks `=== 'on'`) |
| `_et_builder_version` | `VB\|Divi\|<version>` |
| `_wbdc_divi_data` | the intermediate block tree (JSON) |
| `_wbdc_conversion_report` | the report plus `unsupported` (JSON) |
| `_wbdc_import_source` | `direct` or `file_upload` |
| `_wbdc_source_post_id` | the WPBakery post it came from (direct conversions) |

HTML inside attribute JSON is stored with `<` as `<` (`JSON_HEX_TAG`). **Content and meta both
have to be `wp_slash()`ed**: `wp_update_post()` and `update_metadata()` each unslash their input, so
an unslashed value loses the backslash of every `\"` — the page renders `u003Cp` and the two JSON
metas stop being valid JSON. `save()` also clears Divi's per-post caches
(`ET_Core_PageResource::remove_static_resources()`, `_divi_dynamic_assets_cached_modules`,
`_divi_dynamic_assets_cached_feature_used`).

Nesting (`Exporters\DiviBlockSerializer`): section → row → column → modules; a column may also hold
a `divi/row` (nested rows) or a `divi/group` (flex/grid container). Rows hold columns only. A block
that turns up at the wrong depth is wrapped on the way out rather than written somewhere Divi will
not render it.

## Responsive attributes

Every value is `{breakpoint: {value: …}}` with `desktop`, `tablet`, `phone`. WPBakery's design
options have no breakpoints, so almost everything the converter writes is `desktop`; the exceptions
are a column's width (`flexType`), its offsets and its `vc_hidden-*` visibility, which come from
Bootstrap-style classes and are per breakpoint.

## Structural

| Path | Written by | Divi source |
|---|---|---|
| row `module.advanced.columnStructure.desktop.value` | `RowConverter`, `RowInnerConverter` — the comma-joined list of the columns' `type` | `SRV/ModuleLibrary/Row/RowModule.php:73` |
| column `module.advanced.type.desktop.value` (`1_12 … 11_12`, `4_4`, `1_5 … 4_5`) | `ColumnWidths::type()` | `SRV/ModuleLibrary/Column/ColumnModule.php:307` → `et_pb_column_<type>` |
| column `module.decoration.sizing.{desktop,tablet,phone}.value.flexType` (`2_24 … 24_24`, `1_5 … 4_5`) | `ColumnWidths::flexType()` | `VB/column/module.json` → `module.settings.decoration.sizing`; `column/module-default-render-attributes.json` ships `flexType: "24_24"` |
| `module.decoration.sizing.desktop.value.{width,maxWidth,height,minHeight,alignment}` | rows, sections, dividers, video | `SRV/Module/Options/Sizing/SizingPresetAttrsMap.php`; `SRV/StyleLibrary/Declarations/Sizing/Sizing.php:184-232` for `alignment` |
| `module.decoration.layout.desktop.value.{display,flexDirection,flexWrap,justifyContent,alignItems,columnGap,rowGap,gridColumnWidths,gridColumnCount}` | rows, columns, sections, groups | `SRV/StyleLibrary/Declarations/Layout/Layout.php:68-160` (`--horizontal-gap`/`--vertical-gap` at :71-72, `flexDirection` :258-259, `justifyContent` :263, `alignItems` :267, `flexWrap` :271) |
| `module.decoration.spacing.{bp}.value.margin.left` | column offsets | `SRV/Module/Options/Spacing/SpacingPresetAttrsMap.php` |

`module.advanced.type` alone renders a column as `24_24` — `flexType` is what sizes it. A column's
`layout` never sets `display: block`. Gap values are written `"0px"`, never `"0"`: Divi tests them
for truthiness (`SRV/Module/Options/Layout/LayoutStyle.php`). Setting a row's `layout.columnGap`
also writes `--horizontal-gap-parent` on its columns, which keeps `et_flex_column_N_24`'s width calc
correct.

Divi 5.12.1 accepts a **non-preset** `columnStructure`: `5_12,7_12` was driven in the Visual Builder
and measured at 41.67 % / 58.33 % with no fallback (`task-7-report.md` §4).

## Decoration (any module)

The paths are **per kind**, because three modules do not put their options where the rest do
(`StyleMapper`'s own table):

| kind | spacing | sizing | border | background |
|---|---|---|---|---|
| `image` | `module.advanced.spacing` | `module.advanced.sizing` | `image.decoration.border` | `module.decoration.background` |
| `button` | `module.decoration.spacing` | `button.decoration.sizing` | `button.decoration.border` | `button.decoration.background` |
| everything else | `module.decoration.spacing` | `module.decoration.sizing` | `module.decoration.border` | `module.decoration.background` |

`divi/image` reads spacing from `module.advanced.spacing` (with `important` on the margin) and
sizing from `module.advanced.sizing` — `SRV/ModuleLibrary/Image/ImageModule.php:1049-1051, 1073-1076`
(the `divi/image-spacing` and `divi/image-sizing` components). A margin written at
`module.decoration.spacing` renders without `!important` and loses to
`.et_flex_column>.et_pb_module{margin-bottom:unset}` — measured, see
[`docs/box-model.md`](box-model.md). `divi/image` has no `module.decoration.border`; `divi/button`
has neither `module.decoration.border` nor `module.decoration.background`.

| Path | Value | Divi source |
|---|---|---|
| `…spacing.{bp}.value.margin` / `.padding` | `{top,right,bottom,left,syncVertical,syncHorizontal}` (CSS lengths) | `SRV/Module/Options/Spacing/SpacingPresetAttrsMap.php` |
| `…background.{bp}.value.color` | CSS colour | `SRV/Module/Options/Background/BackgroundPresetAttrsMap.php` |
| `…background.{bp}.value.image.{url,position,repeat,size}` | | same map; `SRV/StyleLibrary/Declarations/Background/Traits/StyleDeclarationTrait.php:352-425`, `…/Utils/BackgroundStyleUtils.php` |
| `…background.{bp}.value.image.parallax.{enabled,method}` | `method: 'on'` is true (JS) parallax, anything else is CSS parallax | `BackgroundPresetAttrsMap.php:87,92`; `SRV/…/BackgroundParallaxScriptData.php:219,229` |
| `…background.desktop.value.gradient` | `{enabled:"on", type, direction:"135deg" \| directionRadial, stops:[{color, position:"23"}], overlaysImage:"on"}` | `SRV/StyleLibrary/Utils/GradientUtils.php:700-848` |
| `…border.{bp}.value.styles.{all\|top\|right\|bottom\|left}` | `{style, color, width}` | `SRV/Module/Options/Border/BorderPresetAttrsMap.php`; `SRV/StyleLibrary/Declarations/Border/Border.php:83-167` |
| `…border.{bp}.value.radius` | `{topLeft, topRight, bottomRight, bottomLeft}` | `Border.php:69-79` (`$valid_radius`) |
| `…boxShadow.{bp}.value` | `{style:"preset1", position, color, horizontal, vertical, blur, spread}` | `SRV/StyleLibrary/Declarations/BoxShadow/BoxShadow.php:110-149` — a `style` other than `none` is required |
| `module.decoration.disabledOn.{desktop,tablet,phone}.value` | `"on"` hides the module at that breakpoint | `SRV/Module/Options/DisabledOn/DisabledOnStyle.php:117` — a selector is emitted only for a breakpoint whose value is `'on'`, so the visible ones are written `'off'` explicitly to stop Divi's breakpoint inheritance |
| `module.advanced.htmlAttributes.desktop.value.{id,class}` | from `el_id` / `el_class` | `SRV/Module/Options/IdClasses/IdClassesPresetAttrsMap.php` |
| `module.advanced.link.desktop.value.{url,target}` | `target` is `'on'`/`'off'`, not `_blank` | `SRV/Module/Options/Link/LinkUtils.php:80-87` |
| `module.advanced.text.text.desktop.value.orientation` | `left\|center\|right` | `SRV/Module/Options/Text/TextPresetAttrsMap.php:39-42` |
| `css.desktop.value.mainElement` | leftover custom CSS the mapper could not express | `SRV/ModuleLibrary/Text/TextPresetAttrsMap.php:2331` (`css__mainElement`); `VB/<module>/conversion-outline.json` → `"main_element": "css.*.mainElement"` |

**`css.desktop.value.mainElement`, not `css.desktop.value.main`.** Divi 5.12.1 defines
`before` / `mainElement` / `after` / `freeForm` only; `main` was the 5.7.4 name the Beaver
converter used.

Two Divi facts that are easy to get wrong:

- **Gradient stop positions are bare numbers.** `GradientUtils::gradient_style_declaration()` builds
  `"{$color} {$position}{$unit}"` and `_sanitize_gradient_stop_position()` rejects anything that is
  not `^-?(\d+|\d+\.\d+|\.\d+)$`. A `%` drops the whole `background-image` declaration — the
  image with it. Write `position: "23"`.
- **There is no gradient `type` of `radial`.** The switch (`GradientUtils.php:805-821`) knows
  `linear`, `conic`, `elliptical`, `circular`; anything else falls through to `linear`. A CSS
  `radial-gradient(...)` therefore becomes `elliptical`, or `circular` when the shape is `circle`.

## Fonts

`<font path>.{bp}.value` = `{family, weight, size, lineHeight, letterSpacing, textAlign, color,
style:[italic|uppercase|…], headingLevel}` — sub-names from
`SRV/Module/Options/Font/FontPresetAttrsMap.php`, emitted by
`SRV/StyleLibrary/Declarations/Font/Font.php:489-537, 693`.

`StyleMapper::FONT_PATH` per node kind:

| kind | path | module.json |
|---|---|---|
| heading | `title.decoration.font.font` | `VB/heading/module.json` → `title.settings.decoration.font` (+ `fields.headingLevel.render`) |
| text | `content.decoration.bodyFont.body.font` | `VB/text/module.json` → `content.settings.decoration.bodyFont` (+ `content.styleProps.bodyFont.important.body.font`) |
| button | `button.decoration.font.font` | `VB/button/module.json` → `button.styleProps.font.important.font` |
| blurb / cta | `title.decoration.font.font` | `VB/{blurb,cta}/module.json` |
| counter | `number.decoration.font.font` | `VB/{number-counter,circle-counter}/module.json` |

**Font Awesome.** Divi ships FA5 in its icon list
(`icon-library/src/components/icon-font/iconList.json`), keyed by FA name with weights 900 (solid)
and 400 (regular/brands). `data/fa-icons.json` is built from it by `scripts/build-fa-icon-map.php`.
Icon settings must be written **`unicode, type, weight` in that order**: Divi's asset detector regex
matches `"unicode"…"type":"fa"` and otherwise never enqueues Font Awesome.

## Dynamic content

Divi 5 stores dynamic content as a token it resolves wherever it appears inside a string
(`DynamicData::get_variable_values`), so it can sit in the middle of a larger string:

```
$variable({"type":"content","value":{"name":"post_title","settings":{}}})$
```

`Helpers\DynamicContent::token( $name, $settings )` builds it. The three the converter emits:

| WPBakery | Token |
|---|---|
| `vc_custom_heading source="post_title"` | `post_title` |
| `vc_copyright` (the current year) | `current_date` with `{date_format: "custom", custom_date_format: "Y"}` |
| `vc_custom_field field_name="x"` | `post_meta_key` with `{meta_key: "x"}` |

## Modules and their content keys

Every path below is written by a handler in this plugin. The module.json named is the file that
declares it.

| Block | Paths written | Notes / server-side reader |
|---|---|---|
| `divi/section` | `module.decoration.{spacing,sizing,background,border,layout}` | `VB/section/module.json`; `module.styleProps.spacing.selector = {{selector}}.et_pb_section`, `important: true` |
| `divi/row`, nested `divi/row` | `module.advanced.columnStructure`, `module.decoration.{spacing,sizing,layout,background,border}` | `VB/row/module.json`; `module.styleProps.sizing.important.desktop.value.{width,max-width,margin-left,margin-right} = true` |
| `divi/column` | `module.advanced.type`, `module.decoration.sizing.{bp}.value.flexType`, `module.decoration.{spacing,layout,background,border,disabledOn}` | `VB/column/module.json` |
| `divi/group` | `module.decoration.layout.desktop.value.{display,flexWrap,columnGap,rowGap,gridColumnWidths,gridColumnCount}`, `module.decoration.{background,border,boxShadow,spacing,sizing}`, `module.advanced.link` | `VB/group/module.json`; `GroupModule.php:310-315` reads `display` to add `et_grid_group`; `Layout.php` turns `gridColumnWidths: equal` + `gridColumnCount` into `grid-template-columns: repeat(var(--column-count), minmax(0,1fr))` |
| `divi/heading` | `title.innerContent.desktop.value`, `title.decoration.font.font.…{headingLevel,size,lineHeight,letterSpacing,color,family,weight,style,textAlign}`, `module.advanced.link.desktop.value.{url,target}` | `VB/heading/module.json`; `HeadingModule.php:71` |
| `divi/text` | `content.innerContent.desktop.value` (HTML), `content.decoration.bodyFont.body.font.…{color,textAlign,…}` | `VB/text/module.json` |
| `divi/code` | `content.innerContent.desktop.value` (raw HTML) | `VB/code/module.json` |
| `divi/image` | `image.innerContent.desktop.value.{src,alt,id,linkUrl,linkTarget,rel}`, `image.advanced.lightbox`, `image.decoration.{border.radius,border.styles.all,boxShadow}`, `module.advanced.{align,sizing.width,sizing.forceFullwidth,spacing}` | `VB/image/module.json`; `ModuleElements.php:1407-1413, 1509-1511, 1620-1626`; `ImagePresetAttrsMap.php:55-58` (`rel`); `ImageModule.php:96, 141-145, 315-320, 357-372` |
| `divi/button` | `button.innerContent.desktop.value.{text,linkUrl,linkTarget,rel}`, `button.decoration.{font.font,background.color,background.gradient,border.radius,border.styles.all,boxShadow,spacing.padding,sizing.width}`, `button.decoration.button.desktop.value.icon.{enable,settings,placement}`, `module.advanced.alignment` | `VB/button/module.json`; `ButtonModule.php:290-291, 299, 312-324` (`linkTarget` `'on'` → `_blank`; `rel` an array joined by spaces; `icon.settings` through `Utils::process_font_icon()`) |
| `divi/icon` | `icon.innerContent.desktop.value` (`{unicode,type,weight}`) + `.url`, `.target`, `icon.advanced.{color,size,align}` | `VB/icon/module.json`; `icon/module-default-render-attributes.json` (`size` is a `divi/range` on `font-size`; link `target` defaults `"off"`) |
| `divi/divider` | `divider.advanced.line.desktop.value.{show,color,style,weight}`, `module.decoration.sizing.{bp}.value.{width,height,alignment}` | `VB/divider/module.json`; `DividerModule.php:83-86, 512-549` (`border-top-width` from `weight`) |
| `divi/toggle` | `title.innerContent.desktop.value`, `content.innerContent.desktop.value`, `module.advanced.open.desktop.value` (`on`/`off`) | `VB/toggle/module.json`; `ToggleModule.php:96-102, 583-596` (title is `elementType: heading`, `tagName: h5`) |
| `divi/accordion` › `divi/accordion-item` | parent: `openToggle.decoration.{background.color,font.font.color}`, `closedToggle.decoration.{background.color,font.font.color}`; item: `title.innerContent`, `title.decoration.font.font.…headingLevel`, `content.innerContent` | `VB/{accordion,accordion-item}/module.json` |
| `divi/tabs` › `divi/tab` | parent: `tab.decoration.{background.color,font.font.color}`, `activeTab.decoration.{background.color,font.font.color}`; tab: `title.innerContent`, `content.innerContent` | `VB/{tabs,tab}/module.json` |
| `divi/gallery` | `image.advanced.galleryIds.desktop.value`, `module.advanced.{fullwidth,postsNumber,auto,autoSpeed}`, `pagination.advanced.showPagination`, `galleryGrid.decoration.layout.desktop.value.{display,gridColumnWidths,gridColumnCount,columnGap,rowGap}` | `VB/gallery/module.json`; `GalleryModule.php:159-162, 752-758` |
| `divi/slider` › `divi/slide` | slider: `module.advanced.{auto,autoSpeed}`, `arrows.advanced.show`, `pagination.advanced.show`; slide: `module.decoration.background.desktop.value.image.{url,size}`, `module.advanced.link.desktop.value.{url,target}` | `VB/{slider,slide}/module.json` |
| `divi/video` | `video.innerContent.desktop.value.{src,thumbnailSrc}`, `thumbnail.innerContent.desktop.value.src`, `module.decoration.sizing` | `VB/video/module.json`; `VideoModule.php:129-190, 240-250, 395` (oEmbed provider, then the YouTube fallback) |
| `divi/blurb` | `imageIcon.innerContent.desktop.value.{useIcon,icon,src}`, `imageIcon.advanced.{color,placement}`, `imageIcon.decoration.{sizing.width,background,border.radius,border.styles.all}`, `title.innerContent.desktop.value.{text,url,target}`, `title.decoration.font.font`, `content.innerContent`, `content.decoration.bodyFont.body.font`, `module.advanced.{link,text.text.orientation}` | `VB/blurb/module.json`; `BlurbModule.php:735-1067, 1246-1247` |
| `divi/cta` | `title.innerContent`, `title.decoration.font.font`, `content.innerContent`, `content.decoration.bodyFont.body.font`, `button.*` (the `divi/button` sub-tree), `module.decoration.background.desktop.value.{color,image.url,gradient}`, `module.advanced.{link,text.text.orientation}` | `VB/cta/module.json` |
| `divi/icon-list` › `divi/icon-list-item` | list: `icon.advanced.{color,size}`, `listItem.decoration.font.font`; item: `icon.innerContent.desktop.value` (`{unicode,type,weight}`), `content.innerContent`, `module.advanced.link.desktop.value.{url,target}` | `VB/{icon-list,icon-list-item}/module.json`; `IconListModule.php:246-272`, `IconListItemModule.php:78-345` |
| `divi/number-counter` | `number.innerContent`, `number.advanced.enablePercentSign`, `number.decoration.font.font`, `title.innerContent`, `title.decoration.font.font` | `VB/number-counter/module.json` |
| `divi/circle-counter` | `number.innerContent`, `number.advanced.percentSign`, `number.decoration.font.font`, `title.innerContent`, `title.decoration.font.font`, `circle.advanced.{color,background}` | `VB/circle-counter/module.json`; `CircleCounterModule.php:521, 731` |
| `divi/counters` › `divi/counter` | parent: `barProgress.advanced.usePercentages`, `barCounter.decoration.background.desktop.value.color` (the track), `title.decoration.font.font`, `barProgress.decoration.font.font`; counter: `title.innerContent`, `barProgress.innerContent` (percent), `barProgress.decoration.background.desktop.value.color` (the fill) | `VB/{counters,counter}/module.json` |
| `divi/charts` | `chart.innerContent.desktop.value.data` (`{columns, rows}`), `chart.advanced.config.desktop.value.{type,showLegend,showTooltip}`, `chart.advanced.legend.layout.desktop.value.position`, `chart.advanced.legend.markers.desktop.value.color` | `VB/charts/module.json`; `SRV/ModuleLibrary/Charts/ChartsModule.php` — see [the data shape](#divicharts-data-shape) |
| `divi/pricing-tables` › `divi/pricing-table` | parent: module decoration only; item: `title.innerContent`, `subtitle.innerContent`, `price.innerContent`, `currencyFrequency.innerContent.desktop.value.{currency,per}`, `content.innerContent` (`<ul>`), `content.advanced.bulletColor`, `button.innerContent.desktop.value.{text,linkUrl,linkTarget}`, `module.advanced.featured`, and the five font paths | `VB/{pricing-tables,pricing-table}/module.json`; `PricingTablesItemModule.php:418-500` |
| `divi/blog` | `post.advanced.{type,number,offset,categories,showExcerpt}`, `meta.advanced.{showAuthor,showCategories,showDate,showComments}`, `readMore.advanced.enable`, `pagination.advanced.enable`, `blogGrid.decoration.layout.desktop.value.{display,gridColumnWidths,gridColumnCount,columnGap,rowGap}`, `title.decoration.font.font` | `VB/blog/module.json`; `BlogModule.php` |
| `divi/post-slider` | `post.advanced.{number,categories,orderby,contentSource}`, `module.advanced.{auto,autoSpeed}` | `VB/post-slider/module.json`. `content.advanced.showOnMobile` is a mobile-visibility toggle, not a content switch, so nothing is written to it |
| `divi/map` › `divi/map-pin` | map: `map.innerContent.desktop.value.{address,zoom,lat,lng}`; pin: `pin.innerContent.desktop.value.{address,lat,lng}`, `title.innerContent.desktop.value` | `VB/map/module.json` declares `"childrenName": ["divi/map-pin"]`; `VB/map-pin/module.json` is `"category": "child-module"`; `MapModule.php:162-165, 220-226`, `MapItem/MapItemModule.php:56-58, 156` |
| `divi/social-media-follow` › `divi/social-media-follow-network` | `socialNetwork.innerContent.desktop.value.{title,link}`, `icon.advanced.{color,size}`, `module.decoration.{background.color,border.radius,border.styles.all}`, `module.advanced.text.text.orientation` | the child block is declared by the **`social-media-follow-item/`** folder (`SocialMediaFollowItemModule.php:77, 355-560`); the field's `link` has `defaultValue: '#'` |
| `divi/team-member` | `name.innerContent`, `name.decoration.font.font` (the one place `headingLevel` renders on this module), `position.innerContent`, `position.decoration.font.font`, `content.innerContent`, `image.innerContent.desktop.value.url`, `image.decoration.border.desktop.value.radius`, `social.innerContent.desktop.value.{facebookUrl,twitterUrl,linkedinUrl}`, `module.advanced.link` | `VB/team-member/module.json`; `TeamMemberModule.php:685-790` |
| `divi/testimonial` | `author.innerContent`, `jobTitle.innerContent`, `content.innerContent`, `portrait.innerContent.desktop.value.src`, `portrait.decoration.sizing.desktop.value.width`, `module.decoration.background.desktop.value.color` | `VB/testimonial/module.json`; `TestimonialModule.php:125, 760`. **The portrait has no border group** — a source `thumb_radius` is reported instead |
| `divi/countdown-timer` | `content.advanced.dateTime.desktop.value`, `number.decoration.font.font`, `separator.decoration.font.font`, `label.decoration.font.font`, `module.decoration.background.desktop.value.color` | `VB/countdown-timer/module.json`; `CountdownTimerModule.php:362` |
| `divi/sidebar` | `sidebar.innerContent.desktop.value.area` | `VB/sidebar/module.json` |
| `divi/menu` | `menu.advanced.menuId.desktop.value` (nav_menu term id) | `VB/menu/module.json` |
| `divi/search` | module decoration only | `VB/search/module.json` |
| `divi/contact-form-7` | `form.advanced.formId.desktop.value` | `VB/contact-form-7/module.json` |

### `divi/charts` data shape

`chart.innerContent.desktop.value.data`, read from `SRV/ModuleLibrary/Charts/ChartsModule.php` (the
line numbers are the 5.12.1 file):

```php
[
  'columns' => [
    [ 'label' => 'Label', 'visible' => true, 'role' => 'category' ],
    [ 'label' => 'Value', 'visible' => true, 'role' => 'value' ],                      // round chart
    [ 'label' => 'Sales', 'visible' => true, 'role' => 'series', 'color' => '#5472d2' ], // line chart
  ],
  'rows' => [
    [ 'cells' => [ 'One', '60' ], 'color' => '#5472d2' ],   // round chart: colour per row
    [ 'cells' => [ 'Jan', '10', '5' ] ],                    // line chart: colour on the column
  ],
]
```

| lines | what they establish |
|---|---|
| 282–317 `_build_chart_config()` | `data.columns` / `data.rows`; `chart.advanced.config`'s `type` |
| 1364–1376 `_resolve_mode_value()` | the `desktop.value` unwrapping |
| 561–588 `_normalize_chart_columns()` | a column is `{label, visible, role?}`, index = position |
| 616–618, 628–641 | valid roles `category\|value\|series\|x\|y\|size`; only *visible* columns with a persisted role count |
| 684–745, 762–786 | `pie`/`doughnut`/`polarArea` take one `category` + one `value`; `line`/`area`/`bar`/`radar` one `category` + ≥1 `series` |
| 1387–1393, 1471–1479, 1426–1433, 1490–1497 | a row is `{cells: [...]}`, positional; `row['color']` is the slice colour, `columns[i]['color']` the series colour; a hex string is accepted as it stands |
| 796–801, 875, 835 → 886 | `showLegend` / `showTooltip` (`'off' !== …`), the legend position (Chart.js, default `top`), the legend marker colour |

A row or column with no colour gets none and Divi's `DefaultPalette` fills it.

## Vendored validator

`tests/support/divi5-validator/` is a copy of the sibling Divi 5 Deterministic Validator, taught the
block types this converter emits that it did not know: `divi/charts`, `divi/post-slider`,
`divi/contact-form-7` and `divi/map-pin`, each with its `module.json` citation in that directory's
`README.md`. Its `E_MULTIPLE_H1` rule is on the ignore list in the corpus tests, because headings
keep the author's level — a converter never edits content semantics.
