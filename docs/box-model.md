# Box model: WPBakery's, not Divi's defaults

WPBakery paints its own box model on top of whatever the theme provides; Divi 5 has a different one.
The converter has to reproduce WPBakery's, so this document records what WPBakery actually renders,
what three hand-written Divi 5 documents render beside it, and which of them the converter follows.

Every number below was measured in Chromium at a **1280 px viewport** on the Docker site
(`scripts/docker/setup_wp.sh`, WordPress 7.1 · Divi 5.12.1 · WPBakery 9.0.1) by
`tests/e2e/box-model.spec.ts` (`BOX_MODEL=1 npx playwright test tests/e2e/box-model.spec.ts`).
The raw output is `test-results/box-model.json`; the screenshots are
`tests/e2e/screenshots/box-model-{wpbakery,divi-a,divi-b,divi-c}.png`.

The WPBakery page renders through Divi's page template with `_et_pb_page_layout = et_no_sidebar`, so
both subjects sit in the identical theme container: `.container` and `.et_pb_row` are both
`width: 80%; max-width: 1080px`, i.e. **1024 px starting at x = 128** at this viewport.

## The fixture

`fixtures/wpbakery/box-model.txt` — three rows: a plain two-column row (text + button ‖ text +
image); a filled three-column row with `gap="30"` and design-options CSS on the row and on two of
its columns; and a full-width row holding a text with `margin-bottom: 0`, a following text, and a
nested `vc_row_inner`. The generated design-options CSS lives in the sidecar
`fixtures/wpbakery/box-model.json` under `_wpb_shortcodes_custom_css`, exactly where WPBakery keeps
it — without it the `.vc_custom_…` classes are emitted but no rule exists.

What WPBakery's own CSS (`assets/css/js_composer.min.css`, 9.0.1) does with it:

| | CSS |
|---|---|
| Row | `.vc_row{margin-left:-15px;margin-right:-15px}` — the row bleeds 15 px past the container on each side |
| Column | `.vc_column-inner{padding-left:15px;padding-right:15px}`; design options paint on `.vc_column-inner` |
| Filled row | `.vc_row-has-fill>.vc_column_container>.vc_column-inner{padding-top:35px}` — **and the same 35 px on the columns of the row that follows** (`.vc_row-has-fill+.vc_row>.vc_column_container>.vc_column-inner`) |
| `gap="30"` | `.vc_column-gap-30{margin-left:-30px;margin-right:-30px}` and `>.vc_column_container{padding:15px}` |
| Element | `.wpb_button,.wpb_content_element,ul.wpb_thumbnails-fluid>li{margin-bottom:35px}` |

## The three candidates

All three are hand-written Divi 5 documents (`fixtures/box-model/divi-candidate-{a,b,c}.html`),
validated clean by the vendored `Divi5Validator`. Shared structure: a top-level `vc_row` becomes a
`divi/section` holding one `divi/row` (§5), the filled row's background and 40 px vertical padding go
on the section, columns carry `advanced.type` + `sizing.flexType`, and modules carry a 35 px bottom
margin.

**Candidate A** — the Bootstrap-style bleed: row `spacing.margin` `0 -15px` (`0 -30px` in the gapped
row) and `sizing.width` `calc(100% + 30px)` (`calc(100% + 60px)`), columns padding `0 15px`
(`50px 30px 15px 30px` in the gapped, filled row, i.e. `gap/2` carried as column padding), row
`columnGap`/`rowGap` `0px`.

**Candidate B** — candidate A without the row margin and width. Everything else identical.

**Candidate C** — the measured model:

- every top-level row: `sizing.width` `calc(var(--content-width, 80%) + 30px)`, `sizing.maxWidth`
  `calc(var(--content-max-width, 1080px) + 30px)`, `spacing.margin` `0px -15px 0px -15px`. The bleed
  is always 15 px, whatever the row's gap.
- `gap="30"` on the row, not on the columns: row `layout.columnGap` and `layout.rowGap` `30px`, row
  `spacing.padding` `15px 0px 15px 0px` (`gap/2` vertically); rows 1 and 3 keep gaps `0px` and
  padding `0`.
- columns: `spacing.padding` `0px 15px 0px 15px`, `35px 15px 0px 15px` in the filled row;
  `layout.rowGap` `0px`; no `gap/2` anywhere.
- modules: `module.decoration.spacing.margin.bottom` `35px`, and `0px` on the row-3 text whose
  WPBakery design options set `margin-bottom: 0`. The button keeps 35 px here so row 1 stays
  comparable with A and B — WPBakery's real `.vc_btn3-container` default is 21.74 px (22 px as
  rendered, see below).
- `divi/image`: `module.advanced.spacing.desktop.value.margin` bottom `35px` and
  `module.advanced.sizing.desktop.value.width` `400px`; **nothing under `module.decoration`** — see
  the findings below.
- nested row: a `divi/row` inside the column with `sizing.width` `calc(100% + 30px)`,
  `sizing.maxWidth` `none`, `spacing.margin` `0px -15px 0px -15px`, padding `0`, gaps `0px`; its two
  columns `advanced.type` `1_2`, `flexType` `12_24`, padding `0px 15px 0px 15px`.

## Measurements (1280 px viewport)

`container left edge` is the theme container's left edge (128 px). `content left edge` is the first
module's content-box left edge in the row's first column; `content inset` is the difference between
the two — **WPBakery's is 0: its content sits flush with the container.** `column content gap` is the
horizontal distance between column one's and column two's module content boxes; `column box gap` the
distance between the two painted column backgrounds; `column top padding` the distance from the
column box's top edge to its first module.

| measurement | WPBakery | A | B | **C** |
|---|---|---|---|---|
| container left edge | 128 | 128 | 128 | 128 |
| **row 1** row box left / width | 113 / 1054 | 100 / 1080 | 128 / 1024 | **113 / 1054** |
| **row 1** content left edge | 128 | 115 | 143 | **128** |
| **row 1** content inset | **0** | −13 | +15 | **0** |
| **row 1** column content gap | 30 | 30 | 30 | **30** |
| **row 1** module gap (text → button) | 35 | 35 | 35 | **35** |
| **row 1** row height | 493.8 | 244.8 | 244.8 | 279.8 |
| **row 2** row box left / width | 98 / 1084 | 100 / 1080 | 128 / 1024 | 113 / 1054 |
| **row 2** content left edge | 128 | 130 | 158 | **128** |
| **row 2** content inset | **0** | +2 | +30 | **0** |
| **row 2** column content gap | 60 | 60 | 60 | **60** |
| **row 2** column box gap | 30 | 0 | 0 | **30** |
| **row 2** column top padding | 50 | 50 | 50 | 35 |
| **row 2** row height | 203.8 | 203.8 | 203.8 | **203.8** |
| **row 3** row box left / width | 113 / 1054 | 100 / 1080 | 128 / 1024 | **113 / 1054** |
| **row 3** content left edge | 128 | 115 | 143 | **128** |
| **row 3** content inset | **0** | −13 | +15 | **0** |
| **row 3** column top padding | 35 | 0 | 0 | 0 |
| **row 3** row height | 176.39 | 141.39 | 141.39 | 141.39 |
| zero-margin text → next text | **0** | 0 | 0 | **0** |
| nested row box left / width | 113 / 1054 | 100 / 1080 | 143 / 994 | **113 / 1054** |
| nested content left − parent content left | **0** | 0 | +15 | **0** |
| image module computed `margin-bottom` | 35px | 0px | 0px | **35px** |

## The decision

**Candidate C.** It reproduces WPBakery's geometry exactly on every measurement that describes the
box model: content flush with the container in all three rows (inset 0), 30 px between column
contents in a plain row and 60 px in a `gap="30"` row, 30 px between the painted column backgrounds
of the filled row, 35 px between modules, 0 px where the source sets `margin-bottom: 0`, the filled
row's height to the pixel, and a nested row whose content lines up with its parent column's content
(offset 0) at the same 113 / 1054 box as WPBakery's.

The bleed is written with Divi's own `:root` layout custom properties and their plain fallbacks:

```
module.decoration.sizing.desktop.value.width    = calc(var(--content-width, 80%) + 30px)
module.decoration.sizing.desktop.value.maxWidth = calc(var(--content-max-width, 1080px) + 30px)
module.decoration.spacing.desktop.value.margin  = 0px -15px 0px -15px
```

`--content-width` and `--content-max-width` are Divi's, defined in `Divi/style-static.min.css`
(`:root{--content-width:80%;--content-max-width:1080px;--section-padding:56px;…}`) and consumed by
Divi's own row rule with the same plain-value fallback pattern
(`.et_pb_row:not([class*=et_flex_column]){width:80%;width:var(--content-width);max-width:1080px;max-width:var(--content-max-width);margin:auto}`),
so an unknown property degrades to Divi's shipped defaults rather than to `auto`. Measured at two
viewports on the probe that led here: `rowLeft 113 / rowWidth 1054 / inset 0` at 1280 px and
`245 / 1110 / 0` at 1600 px.

**Why not A or B.** Candidate A's `margin: 0 -15px` + `width: calc(100% + 30px)` cannot express the
bleed: Divi's `.et_pb_row` keeps `max-width: 1080px`, the calc resolves against the section's full
width (1280 + 30 px), the clamp cuts it back, and *both* rows land at exactly 1080 px whatever bleed
was asked for — 13 px outside the container in row 1, 2 px inside it in row 2 — with the error
moving with the viewport (above ~1350 px the clamp stops biting and A degenerates into B). Equal
negative margins on a flex item Divi centres with `align-items: center` cancel out, so the margin
alone moves nothing. Candidate B has no bleed at all: every row is inset 15 px (30 px in the gapped
row), and its nested row compounds the error to a 994 px box inset a further 15 px from its parent's
content. Both are kept in `fixtures/box-model/` as the documented rejected paths.

## Deltas candidate C does not close

- **The gapped row's row box: 113 / 1054 against WPBakery's 98 / 1084.** Expected. WPBakery widens
  the row's own bleed to `15 + gap/2` and pads every column container by `gap/2`; C leaves the bleed
  at 15 px and gives the gap to the row's `columnGap`. WPBakery's row box in a gapped row is
  invisible — the boxes a reader sees are the `.vc_column-inner` boxes, and those match: 331.3 px
  wide, 30 px apart, the first at x = 113, content 60 px apart starting at x = 128, row height 203.8.
  Recorded, not chased.
- **The gapped row's column top padding: 35 against WPBakery's 50.** The same accounting. The two
  numbers measure different boxes: WPBakery's `.vc_column_container` carries the `gap/2` = 15 px that
  C moves onto the row, so the *painted* box's own top padding is 35 px in both.
- **Row 3's column top padding: 0 against WPBakery's 35, and its height 141.39 against 176.39.**
  A row that *follows* a filled row also gets `.vc_column-inner{padding-top:35px}`
  (`.vc_row-has-fill+.vc_row>.vc_column_container>.vc_column-inner`). No candidate models it, and the
  whole 35 px delta is exactly that rule. The converter must carry 35 px of top padding onto the
  columns of the row after a filled row as well as onto the filled row's own.
- **Row 1's height: 279.8 against WPBakery's 493.8 — image sizing, not box model.**
  `module.advanced.sizing.desktop.value.width: 400px` sizes the *module* (measured 400 × 186) but not
  the `<img>`, which stays at its natural 186 × 186; WPBakery stretches the `<img>` to the width
  `external_img_size` declares (module 497 × 400). The switch is
  `module.advanced.sizing.desktop.value.forceFullwidth`
  (`ModuleLibrary/Image/ImageModule.php` ~315/357 and
  `ModuleLibrary/Image/Styles/Sizing/SizingStyleTraits/StyleDeclarationTrait.php`); it is not written
  here and belongs to the image mapping in Task 8. Row 2 and row 3, which hold only text, match.

## Additional measured findings

- **Write each module's spacing at the path that module reads.** `divi/image` takes its spacing from
  `module.advanced.spacing` and its sizing from `module.advanced.sizing`
  (`ModuleLibrary/Image/ImageModule.php`: the `divi/image-spacing` component with
  `'attr' => $attrs['module']['advanced']['spacing']` and `'important' => [desktop.value.margin => true]`,
  `divi/image-sizing` with `$attrs['module']['advanced']['sizing']`, plus the preset resolver that
  maps `module.decoration.spacing` → `module.advanced.spacing` for `divi/image`). Candidates A and B
  wrote `module.decoration.spacing`/`.sizing`, so Divi emitted a generic
  `.et_pb_image_0{margin-bottom:35px;width:400px}` without `!important`, which loses to
  `.et_flex_column>.et_pb_module{margin-bottom:unset}` — measured computed margin `0px`.
  Candidate C writes `module.advanced.spacing` and measures `35px`. Text and button margins survive
  at `module.decoration.spacing` because their `module.json` declares
  `styleProps.spacing.important: true`
  (`module-library/src/components/text/module.json`). Check the module's own source before choosing
  the path; do not assume `module.decoration` everywhere.
- **WPBakery's default bottom margin is per element, not global** (`js_composer.min.css`, 9.0.1):

  | element | margin-bottom |
  |---|---|
  | `.wpb_button`, `.wpb_content_element`, `ul.wpb_thumbnails-fluid > li` | 35px |
  | `.vc_icon_element` | 35px |
  | `.vc_toggle:last-of-type` | 35px |
  | `.vc_btn3-container` | 21.73913043px (rendered 22px — WPBakery emits `.vc_do_btn{margin-bottom:22px}` in its per-page "default" stylesheet, measured on the fixture) |
  | `.vc_message_box` | 21.73913043px |
  | `.vc_toggle_content` | 21.73913043px |
  | `vc_row_inner`, `vc_custom_heading` | none (measured `0px` on the nested row) |

- Setting a row's `layout.columnGap` also writes `--horizontal-gap-parent` on its columns
  (`Module/Options/Layout/LayoutStyle.php`), so `et_flex_column_N_24`'s width calc stays correct.
  Write `"0px"`, never `"0"` — Divi tests the value for truthiness.
- Divi's grid: `:root{--content-width:80%;--content-max-width:1080px;--section-padding:56px;
  --row-gutter-horizontal:5.5%;--row-gutter-vertical:40px;--module-gutter:30px}`. A converted page
  must override the gutters, or Divi's own show through.
- **Docker observation, not a converter rule:** with section padding `0` the first row renders behind
  Divi's fixed header on this site (first module at y = 224, `#main-header` bottom at y = 271). The
  header is unusually tall here because Divi's 186 px logo outgrows `#page-container`'s padding; the
  WPBakery page clears it (y = 342) through the page template's `.container{padding-top:58px}` and
  the printed page title. WPBakery contributes no section padding of its own, so the converter adds
  no top offset.
