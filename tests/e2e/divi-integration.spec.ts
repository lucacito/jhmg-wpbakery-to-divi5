import { test, expect } from '@playwright/test';
import fs from 'fs';
import path from 'path';
import {
  BASE,
  collectPageErrors,
  conversionReport,
  convertToNewPage,
  copyHelperScript,
  createWPBakeryPage,
  login,
  runScreenshotsDir,
  serveExternalRequestsLocally,
  wp,
} from './helpers';

/**
 * Does Divi 5 accept what this converter writes?
 *
 * Every case here goes the whole way: a WPBakery page is created on the site,
 * converted through the shipped pipeline, opened in the editor — where Divi
 * itself says whether it recognises the layout — and then read on the front
 * end, where the module has to be there and the page has to run without a
 * JavaScript error. Unit tests can only say the block tree is the shape we
 * meant; this says Divi agrees.
 */

type Fixture = { name: string; title: string; selector: string; note: string };

const fixtures: Fixture[] = [
  {
    name: 'wpbakery/custom-heading',
    title: 'Custom Heading Fixture (WPBakery)',
    selector: '.et_pb_heading h2:has-text("Section title")',
    note: 'the heading keeps its level',
  },
  {
    name: 'wpbakery/column-text',
    title: 'Column Text Fixture (WPBakery)',
    selector: '.et_pb_text_inner p:has-text("First paragraph.")',
    note: 'the text block keeps its paragraphs',
  },
  {
    // The fixture points at attachment 1096, which only exists as an import
    // sidecar; on this site the media library has no such image, so what is
    // proved here is that the module is still written and rendered rather than
    // dropped. `single-image-external` below carries a source that survives.
    name: 'wpbakery/single-image',
    title: 'Single Image Fixture (WPBakery)',
    selector: '.et_pb_image',
    note: 'an image whose attachment is missing still renders as a module',
  },
  {
    name: 'wpbakery/single-image-external',
    title: 'External Image Fixture (WPBakery)',
    selector: '.et_pb_image img[src="https://cdn.example.com/hero.png"]',
    note: 'the image source reaches the page',
  },
  {
    name: 'wpbakery/btn-flat',
    title: 'Button Fixture (WPBakery)',
    selector: 'a.et_pb_button[href="/shop"]:has-text("Order now")',
    note: 'the button keeps its text and its link',
  },
  {
    name: 'wpbakery/two-columns',
    title: 'Two Columns Fixture (WPBakery)',
    selector: '.et_pb_column + .et_pb_column .et_pb_text_inner p:has-text("Right")',
    note: 'the second column holds the second text',
  },
  {
    // Buttons WPBakery lays out inline become a nested flexed row, never
    // stacked blocks (CLAUDE.md); the nested row is the thing to assert.
    name: 'wpbakery/btn-inline-group',
    title: 'Inline Buttons Fixture (WPBakery)',
    selector: '.et_pb_row_nested a.et_pb_button:has-text("Learn more")',
    note: 'inline buttons sit together in a nested row',
  },
  {
    // Divi opens an accordion by position, so the first section is the open
    // one whatever `active_section` said (task-14-amendments §6).
    name: 'wpbakery/tta-accordion',
    title: 'Accordion Fixture (WPBakery)',
    selector: '.et_pb_accordion_item.et_pb_toggle_open h3:has-text("What is included?")',
    note: 'the accordion opens on its first section',
  },
  {
    // Ultimate Addons' info box is a real blurb since Task 9 — not a
    // placeholder (task-14-amendments §6).
    name: 'wpbakery/ult-info-box',
    title: 'Info Box Fixture (WPBakery)',
    selector: '.et_pb_blurb h4:has-text("Join Our Class")',
    note: 'the add-on info box becomes a blurb with its own title',
  },
];

test.describe.serial('Divi 5 renders converted WPBakery elements', () => {
  test.beforeAll(() => {
    fs.mkdirSync(runScreenshotsDir, { recursive: true });
    copyHelperScript('set-wpbakery-content.php');
    copyHelperScript('convert-to-new-page.php');
  });

  for (const fixture of fixtures) {
    test(`${fixture.name}: ${fixture.note}`, async ({ page }) => {
      const errors = collectPageErrors(page);
      await serveExternalRequestsLocally(page);

      const sourceId = createWPBakeryPage(fixture.name, fixture.title);
      const pageId = convertToNewPage(sourceId);

      // Divi's own verdict: its block placeholder in the editor says whether
      // it recognises the post as one of its layouts. The editor canvas is an
      // iframe, so the button is not in the outer document.
      await login(page);
      await page.goto(`${BASE}/wp-admin/post.php?post=${pageId}&action=edit`);
      const canvas = page.frameLocator('iframe[name="editor-canvas"]');
      await expect(canvas.locator('text=This Layout Is Built With Divi')).toBeVisible({ timeout: 45000 });
      await expect(
        canvas.locator('a:has-text("Edit With The Divi Builder"), button:has-text("Edit With The Divi Builder")').first()
      ).toBeVisible();

      await page.goto(`${BASE}/?page_id=${pageId}`);
      await page.waitForSelector('.et_pb_section', { timeout: 20000 });
      await expect(page.locator(fixture.selector).first()).toBeVisible();

      // Divi's stylesheets have to be on the page, or the layout is markup
      // with no design at all.
      const diviStylePresent = await page.evaluate(() =>
        Array.from(document.querySelectorAll('style[id], link[id]')).some(
          (el) => el.id.startsWith('et-') || el.id.startsWith('divi-')
        )
      );
      expect(diviStylePresent, 'Divi styles enqueued').toBe(true);

      await page.screenshot({
        path: path.join(runScreenshotsDir, `${path.basename(fixture.name)}-frontend.png`),
        fullPage: true,
      });
      expect(errors, 'front-end JavaScript errors').toEqual([]);
    });
  }
});

/**
 * The seeded page from a real theme export (task-14-amendments §2): a page
 * nobody wrote for this converter, opened in the builder people will actually
 * edit it in.
 */
test.describe('The Visual Builder opens a converted real-world page', () => {
  test('every section of the Ronneby export renders in the builder, with no console errors', async ({ page }) => {
    // The builder is a whole React application over a real page; it needs
    // longer than a front-end render.
    test.setTimeout(180 * 1000);

    const pageId = wp(
      `wp post list --post_type=page --post_status=any --name=ten-layout --field=ID --allow-root`
    )
      .trim()
      .split('\n')[0]
      .trim();
    test.skip(
      !/^\d+$/.test(pageId),
      'The Ronneby export is not seeded — run scripts/docker/setup_wp.sh with the exports mounted.'
    );

    const sections = Number(conversionReport(pageId).converted.section ?? 0);
    expect(sections, 'the report counted some sections').toBeGreaterThan(0);

    const errors = collectPageErrors(page);
    await serveExternalRequestsLocally(page);
    await login(page);

    await page.setViewportSize({ width: 1400, height: 900 });
    await page.goto(`${BASE}/?page_id=${pageId}&et_fb=1&PageSpeed=off`);

    const builder = page.frameLocator('iframe#et-vb-app-frame, iframe[name="et-vb-app-frame"]');
    await expect(builder.locator('.et_pb_section').first()).toBeVisible({ timeout: 150000 });
    await expect(builder.locator('.et_pb_section')).toHaveCount(sections);

    await page.screenshot({ path: path.join(runScreenshotsDir, 'ronneby-visual-builder.png') });
    expect(errors, 'Visual Builder JavaScript errors').toEqual([]);
  });
});
