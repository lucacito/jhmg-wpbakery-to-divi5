import { test, expect } from '@playwright/test';
import fs from 'fs';
import path from 'path';
import { BASE, copyHelperScript, createWPBakeryPage, rootDir, screenshotsDir, shellEscape, wp } from './helpers';

/**
 * Measures WPBakery's box model against three hand-written Divi 5 candidates, so
 * the converter can reproduce WPBakery's geometry instead of Divi's defaults.
 * The numbers land in test-results/box-model.json and in docs/box-model.md.
 *
 * Opt-in: BOX_MODEL=1 npx playwright test tests/e2e/box-model.spec.ts
 */
const ENABLED = process.env.BOX_MODEL === '1';

type Measured = {
  containerLeft: number;
  rows: {
    outerLeft: number;
    outerWidth: number;
    outerHeight: number;
    rowLeft: number;
    rowWidth: number;
    contentLeft: number;
    contentInset: number;
    columnContentGap: number;
    columnBoxGap: number;
    columnTopPadding: number;
  }[];
  moduleGap: number | null;
  zeroMarginGap: number | null;
  nestedRowLeft: number | null;
  nestedRowWidth: number | null;
  nestedContentOffset: number | null;
  imageMarginBottom: string | null;
};

const SELECTORS = {
  wpbakery: {
    row: '.wpb-content-wrapper > .vc_row',
    outer: 'self',
    column: ':scope > .wpb_column.vc_column_container',
    columnBox: '.vc_column-inner',
    module: ':scope > .vc_column-inner > .wpb_wrapper > *',
    nestedRow: '.vc_row.vc_inner',
    nestedModule: '.wpb_text_column',
    imageModule: '.wpb_single_image',
  },
  divi: {
    row: '.et_pb_section > .et_pb_row',
    outer: 'section',
    column: ':scope > .et_pb_column',
    columnBox: 'self',
    module: ':scope > .et_pb_module',
    nestedRow: '.et_pb_row',
    nestedModule: '.et_pb_module',
    imageModule: '.et_pb_image',
  },
};

async function measure(page: import('@playwright/test').Page, kind: 'wpbakery' | 'divi'): Promise<Measured> {
  return page.evaluate((args) => {
    const sel = args.selectors;
    const geom = (el: Element) => {
      const r = el.getBoundingClientRect();
      const cs = getComputedStyle(el);
      const num = (v: string) => parseFloat(v) || 0;
      return {
        left: r.left,
        right: r.right,
        top: r.top + window.scrollY,
        bottom: r.bottom + window.scrollY,
        width: r.width,
        height: r.height,
        contentLeft: r.left + num(cs.borderLeftWidth) + num(cs.paddingLeft),
        contentRight: r.right - num(cs.borderRightWidth) - num(cs.paddingRight),
        contentTop: r.top + window.scrollY + num(cs.borderTopWidth) + num(cs.paddingTop),
      };
    };
    const round = (n: number) => Math.round(n * 100) / 100;

    // Divi's theme container: 80% of the viewport, capped at 1080px, centred.
    // `.container` (WPBakery page) and `.et_pb_row` (Divi page) both use it.
    const docWidth = document.documentElement.clientWidth;
    const containerLeft = round((docWidth - Math.min(docWidth * 0.8, 1080)) / 2);

    const rows = Array.from(document.querySelectorAll(sel.row));
    const out: any[] = [];
    let moduleGap: number | null = null;
    let zeroMarginGap: number | null = null;
    let nestedRowLeft: number | null = null;
    let nestedRowWidth: number | null = null;
    let nestedContentOffset: number | null = null;

    const modulesOf = (col: Element) => Array.from(col.querySelectorAll(sel.module));
    const boxOf = (col: Element) => (sel.columnBox === 'self' ? col : col.querySelector(sel.columnBox) || col);

    rows.forEach((row, rowIndex) => {
      const outer = sel.outer === 'section' ? row.closest('.et_pb_section') || row : row;
      const columns = Array.from(row.querySelectorAll(sel.column));

      const firstCol = columns[0];
      const firstModules = firstCol ? modulesOf(firstCol) : [];
      const secondCol = columns[1];
      const secondModules = secondCol ? modulesOf(secondCol) : [];

      const contentLeft = firstModules[0] ? round(geom(firstModules[0]).contentLeft) : NaN;
      const columnContentGap =
        firstModules[0] && secondModules[0]
          ? round(geom(secondModules[0]).contentLeft - geom(firstModules[0]).contentRight)
          : NaN;
      const columnBoxGap =
        firstCol && secondCol ? round(geom(boxOf(secondCol)).left - geom(boxOf(firstCol)).right) : NaN;
      const columnTopPadding =
        firstCol && firstModules[0] ? round(geom(firstModules[0]).top - geom(firstCol).top) : NaN;

      if (rowIndex === 0 && firstModules.length > 1) {
        moduleGap = round(geom(firstModules[1]).top - geom(firstModules[0]).bottom);
      }

      // Row 3 holds the zero-margin text, the text after it, and a nested row.
      if (rowIndex === 2 && firstCol) {
        if (firstModules.length > 1) {
          zeroMarginGap = round(geom(firstModules[1]).top - geom(firstModules[0]).bottom);
        }
        const nested = firstCol.querySelector(sel.nestedRow);
        if (nested) {
          nestedRowLeft = round(geom(nested).left);
          nestedRowWidth = round(geom(nested).width);
          const innerModule = nested.querySelector(sel.nestedModule);
          if (innerModule && firstModules[0]) {
            nestedContentOffset = round(geom(innerModule).contentLeft - geom(firstModules[0]).contentLeft);
          }
        }
      }

      out.push({
        outerLeft: round(geom(outer).left),
        outerWidth: round(geom(outer).width),
        outerHeight: round(geom(outer).height),
        rowLeft: round(geom(row).left),
        rowWidth: round(geom(row).width),
        contentLeft,
        contentInset: round(contentLeft - containerLeft),
        columnContentGap,
        columnBoxGap,
        columnTopPadding,
      });
    });

    const imageModule = document.querySelector(sel.imageModule);
    const imageMarginBottom = imageModule ? getComputedStyle(imageModule).marginBottom : null;

    return { containerLeft, rows: out, moduleGap, zeroMarginGap, nestedRowLeft, nestedRowWidth, nestedContentOffset, imageMarginBottom };
  }, { selectors: SELECTORS[kind] });
}

test.describe.serial('WPBakery box model versus three Divi 5 candidates', () => {
  test.skip(!ENABLED, 'Set BOX_MODEL=1 to run the box-model measurement.');
  test.use({ viewport: { width: 1280, height: 1400 } });

  const results: Record<string, Measured> = {};
  const pages: Record<string, string> = {};

  test.beforeAll(() => {
    fs.mkdirSync(screenshotsDir, { recursive: true });
    fs.mkdirSync(path.join(rootDir, 'test-results'), { recursive: true });
    copyHelperScript('set-wpbakery-content.php');
    copyHelperScript('set-divi-content.php');

    pages.wpbakery = createWPBakeryPage('wpbakery/box-model', 'Box Model Source (WPBakery)');
    // Divi gives builder pages no sidebar; give the WPBakery page the same
    // template so both measure against the identical theme container.
    wp(`wp post meta update ${shellEscape(pages.wpbakery)} _et_pb_page_layout et_no_sidebar --allow-root`);

    for (const candidate of ['a', 'b', 'c']) {
      const id = wp(
        `wp post create --post_type=page --post_status=publish --post_title='Box Model Candidate ${candidate.toUpperCase()} (Divi 5)' --porcelain --allow-root`
      ).trim();
      wp(
        `HTML_PATH=/var/www/html/fixtures/box-model/divi-candidate-${candidate}.html PAGE_ID=${shellEscape(id)} wp eval-file /tmp/set-divi-content.php --allow-root`
      );
      pages[`divi-${candidate}`] = id;
    }
  });

  const cases: { key: string; kind: 'wpbakery' | 'divi'; ready: string }[] = [
    { key: 'wpbakery', kind: 'wpbakery', ready: '.wpb-content-wrapper > .vc_row' },
    { key: 'divi-a', kind: 'divi', ready: '.et_pb_section > .et_pb_row' },
    { key: 'divi-b', kind: 'divi', ready: '.et_pb_section > .et_pb_row' },
    { key: 'divi-c', kind: 'divi', ready: '.et_pb_section > .et_pb_row' },
  ];

  for (const subject of cases) {
    test(`measures ${subject.key}`, async ({ page }) => {
      await page.goto(`${BASE}/?page_id=${pages[subject.key]}`);
      await page.waitForSelector(subject.ready, { timeout: 30000 });
      await page.waitForLoadState('networkidle');
      results[subject.key] = await measure(page, subject.kind);
      await page.screenshot({ path: path.join(screenshotsDir, `box-model-${subject.key}.png`), fullPage: true });

      const measured = results[subject.key];
      expect(measured.rows.length, 'all three rows were found').toBe(3);
      expect(measured.moduleGap, 'the gap between the first two modules was measured').not.toBeNull();
      expect(measured.nestedRowLeft, 'the nested row was found').not.toBeNull();
    });
  }

  test.afterAll(() => {
    fs.writeFileSync(
      path.join(rootDir, 'test-results', 'box-model.json'),
      JSON.stringify({ pages, viewport: 1280, results }, null, 2)
    );
    // eslint-disable-next-line no-console
    console.log(JSON.stringify(results, null, 2));
  });
});
