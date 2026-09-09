# Box model: WPBakery's, not Divi's defaults

WPBakery paints its own box model on top of whatever the theme provides; Divi 5 has a different one.
The converter has to reproduce WPBakery's, so this document records what WPBakery actually renders,
what two hand-written Divi 5 documents render beside it, and which of the two the converter follows.

Every number below was measured in Chromium at a **1280 px viewport** on the Docker site
(`scripts/docker/setup_wp.sh`, WordPress 7.1 · Divi 5.12.1 · WPBakery 9.0.1) by
`tests/e2e/box-model.spec.ts` (`BOX_MODEL=1 npx playwright test tests/e2e/box-model.spec.ts`).
The raw output is `test-results/box-model.json`; the screenshots are
`tests/e2e/screenshots/box-model-{wpbakery,divi-a,divi-b}.png`.

The WPBakery page renders through Divi's page template with `_et_pb_page_layout = et_no_sidebar`, so
both subjects sit in the identical theme container: `.container` and `.et_pb_row` are both
`width: 80%; max-width: 1080px`, i.e. **1024 px starting at x = 128** at this viewport.

## The fixture

`fixtures/wpbakery/box-model.txt` — one plain two-column row (text + button ‖ text + image) and one
filled three-column row with `gap="30"` and design-options CSS on the row and on two of its columns
(the generated CSS lives in the sidecar `fixtures/wpbakery/box-model.json` under
`_wpb_shortcodes_custom_css`, exactly where WPBakery keeps it):

```
[vc_row][vc_column width="1/2"][vc_column_text]Left text block.[/vc_column_text][vc_btn title="Left button" style="flat" color="blue"][/vc_column][vc_column width="1/2"][vc_column_text]Right text block.[/vc_column_text][vc_single_image source="external_link" custom_src="…/themes/Divi/images/logo.png" external_img_size="400x300"][/vc_column][/vc_row][vc_row gap="30" css=".vc_custom_1700000000001{padding-top: 40px !important;padding-bottom: 40px !important;background-color: #f1f5f9 !important;}"][vc_column width="1/3" css=".vc_custom_1700000000002{background-color: #ffffff !important;}"]…
```

What WPBakery's own CSS (`assets/css/js_composer.min.css`, 9.0.1) does with it:

| | CSS |
|---|---|
| Row | `.vc_row{margin-left:-15px;margin-right:-15px}` — the row bleeds 15 px past the container on each side |
| Column | `.vc_column-inner{padding-left:15px;padding-right:15px}`; design options paint on `.vc_column-inner` |
| Filled row | `.vc_row-has-fill>.vc_column_container>.vc_column-inner{padding-top:35px}` |
| `gap="30"` | `.vc_column-gap-30{margin-left:-30px;margin-right:-30px}` and `>.vc_column_container{padding:15px}` |
| Element | `.wpb_content_element{margin-bottom:35px}` |

## The two candidates

Both are hand-written Divi 5 documents (`fixtures/box-model/divi-candidate-{a,b}.html`), validated
clean by the vendored `Divi5Validator`. They are identical except for the row bleed. Shared settings:

- `divi/section` — `spacing.padding` `0` on the plain row; `40px` top/bottom plus
  `background.color #f1f5f9` on the filled row (§5: a top-level `vc_row` becomes a section holding one row).
- `divi/row` — `spacing.padding` `0`; `layout` `{columnGap: "0px", rowGap: "0px"}`;
  `advanced.columnStructure` `"1_2,1_2"` / `"1_3,1_3,1_3"`.
- `divi/column` — `advanced.type` `1_2` / `1_3`, `sizing.flexType` `12_24` / `8_24`;
  `spacing.padding` `0 15px` on the plain row and `50px 30px 15px 30px` on the gapped, filled row
  (15 px inset + `gap/2`, plus the filled row's 35 px on top); `layout.rowGap` `0px`;
  `background.color #ffffff` on the two filled columns.
- modules — `spacing.margin.bottom` `35px`; the image also carries `sizing.width 400px`
  (WPBakery renders the `<img>` at the width `external_img_size` declares).

| | candidate A | candidate B |
|---|---|---|
| row `spacing.margin` | `0 -15px` (plain) / `0 -30px` (gapped) | none |
| row `sizing.width` | `calc(100% + 30px)` / `calc(100% + 60px)` | none |

## Measurements (1280 px viewport)

`container left edge` is the theme container's left edge (128 px). `content left edge` is the first
module's content-box left edge in the row's first column; `content inset` is the difference between
the two — **WPBakery's is 0: its content sits flush with the container.** `column content gap` is the
horizontal distance between column one's and column two's module content boxes; `column box gap` the
distance between the two painted column backgrounds; `column top padding` the distance from the
column box's top edge to its first module.

| measurement | WPBakery | candidate A | candidate B | chosen |
|---|---|---|---|---|
| container left edge | 128 | 128 | 128 | — |
| **row 1** row box left / width | 113 / 1054 | 100 / 1080 | 128 / 1024 | B |
| **row 1** content left edge | 128 | 115 | 143 | B |
| **row 1** content inset | **0** | −13 | +15 | B (+15) |
| **row 1** column content gap | 30 | 30 | 30 | both |
| **row 1** module gap (text → button) | 35 | 35 | 35 | both |
| **row 1** row height | 493.8 | 244.8 | 244.8 | see note |
| **row 2** row box left / width | 98 / 1084 | 100 / 1080 | 128 / 1024 | B |
| **row 2** content left edge | 128 | 130 | 158 | B |
| **row 2** content inset | **0** | +2 | +30 | B (+30) |
| **row 2** column content gap | 60 | 60 | 60 | both |
| **row 2** column box gap | 30 | 0 | 0 | neither |
| **row 2** column top padding | 50 | 50 | 50 | both |
| **row 2** row height | 203.8 | 203.8 | 203.8 | both |

Row 1's height difference is not a box-model difference: WPBakery stretches the `<img>` to the width
`external_img_size` declares (400 × 400 for this 186 × 186 source), Divi 5 renders it at its natural
size inside the 400 px module. Row 2, which holds only text modules, matches to the pixel.

## The decision

**Candidate B.** Candidate A's negative row margin plus `width: calc(100% + 30px)` does not
reproduce WPBakery's bleed, because Divi's `.et_pb_row` keeps `max-width: 1080px`: the calc resolves
against the section's full width (1280 px + 30 px), the clamp cuts it back to 1080 px, and both rows
end up the same 1080 px wide regardless of the bleed asked for — 13 px *outside* the container in row
1 and 2 px inside it in row 2, and the error changes with the viewport (above ~1350 px the clamp
stops biting and A degenerates into B). Equal negative margins on a flex item that Divi centres with
`align-items: center` cancel out, so the margin alone moves nothing. Candidate B is stable and
predictable: every measurement that does not depend on the bleed — 30 px between column contents,
60 px in the gapped row, 35 px between modules, 50 px above the first module in the filled row, and
the filled row's height to the pixel — matches WPBakery exactly, and the whole content block is
inset by a uniform 15 px per side (30 px in a `gap="30"` row) instead of being flush. That inset is
the approximation the converter accepts.

## Deltas neither candidate closes

- **The 15 px bleed.** A probe (not adopted) closes it exactly: `sizing.width`
  `calc(var(--content-width) + 30px)` with `sizing.maxWidth` `calc(var(--content-max-width) + 30px)`
  and `spacing.margin` `0 -15px` measured `rowLeft 113 / rowWidth 1054 / inset 0` at 1280 px and
  `245 / 1110 / 0` at 1600 px — WPBakery's geometry at both. It reads Divi's own `:root` custom
  properties (`flex_grid.css`), and an unknown property degrades back to candidate B's geometry, but
  it couples converter output to Divi's internal variable names, so it is recorded here rather than
  adopted. Revisit in Task 6 (`GlobalSettingsResolver`).
- **Painted column backgrounds in a gapped row.** WPBakery paints on `.vc_column-inner`, inside the
  column container's `gap/2` padding, so the filled boxes stand 30 px apart; a Divi column's
  background fills its whole track, so the boxes touch (measured 30 vs 0). Carrying `gap/2` as column
  *margin* with Divi's own `layout.columnGap` instead of as padding would close it; not measured.

## Additional measured findings

- **A module's `module.decoration.spacing.margin` is not reliable for every module type.** Divi
  emits the text module's margin with `!important` (`.et_pb_text_0{margin-bottom:35px!important}`)
  but the image module's without it (`.et_pb_image_0{margin-bottom:35px}`), where
  `.et_pb_section .et_pb_row .et_flex_column>.et_pb_module{margin-bottom:0}` from `flex_grid.css`
  outranks it — the measured computed value on the image module is `0px`.
  The column's `layout.rowGap` is reliable: a probe with `rowGap: 35px` and no module margins
  measured 35 px between the text module and the image. Default module spacing therefore belongs on
  the column's `rowGap`, not on per-module margins; WPBakery's trailing 35 px below the last element
  in a column is lost that way and is not reproduced.
- **Section padding `0` puts the first row under Divi's fixed header.** Measured: the first module
  starts at y = 224 on both Divi candidates while `#main-header`'s bottom edge is at y = 271, so the
  top of the page renders behind the nav (visible in the screenshots). The WPBakery page clears it
  (first module at y = 342) because Divi's page template gives `.container` `padding-top: 58px` and
  prints the page title, neither of which a builder page has. WPBakery contributes no section
  padding of its own, so this is a theme offset the converter has to decide about separately —
  `wbdc_layout_defaults` is the place for it.
- Setting a row's `layout.columnGap` also writes `--horizontal-gap-parent` on its columns
  (`Module/Options/Layout/LayoutStyle.php`), so `et_flex_column_N_24`'s width calc stays correct.
  Write `"0px"`, never `"0"` — Divi tests the value for truthiness.
- Divi's grid: `:root{--content-width:80%;--content-max-width:1080px;--section-padding:56px;
  --row-gutter-horizontal:5.5%;--row-gutter-vertical:40px;--module-gutter:30px}`. A converted page
  must override the last three, or Divi's own gutters show through.
