import { test, expect } from '@playwright/test';
import fs from 'fs';
import path from 'path';
import {
  BASE,
  collectPageErrors,
  conversionReport,
  convertToNewPage,
  convertedPageFor,
  copyHelperScript,
  createWPBakeryPage,
  login,
  rootDir,
  runScreenshotsDir,
  serveExternalRequestsLocally,
  shellEscape,
  trashPages,
  wp,
} from './helpers';

const TOOLS = `${BASE}/wp-admin/tools.php?page=wbdc-converter`;

/**
 * Which front-end class a report's `converted` kind renders as. A nested row
 * is still an `.et_pb_row`, so the two kinds share a selector and their counts
 * add up.
 */
const MODULE_SELECTORS: Record<string, string> = {
  section: '.et_pb_section',
  row: '.et_pb_row',
  row_inner: '.et_pb_row',
  column: '.et_pb_column',
  heading: '.et_pb_heading',
  text: '.et_pb_text',
  image: '.et_pb_image',
  divider: '.et_pb_divider',
  button: '.et_pb_button',
  code: '.et_pb_code',
  blurb: '.et_pb_blurb',
  accordion: '.et_pb_accordion',
};

/** report `converted` counts folded onto the selectors they render as. */
function expectedCounts(converted: Record<string, number>): Map<string, number> {
  const wanted = new Map<string, number>();
  for (const [kind, count] of Object.entries(converted)) {
    const selector = MODULE_SELECTORS[kind];
    if (selector) wanted.set(selector, (wanted.get(selector) ?? 0) + Number(count));
  }
  return wanted;
}

test.describe.serial('The whole workflow, on one of WPBakery\'s own templates', () => {
  let sourceId: string;
  /** Everything this file made, trashed at the end so the picker stays short. */
  const created: string[] = [];

  test.beforeAll(() => {
    fs.mkdirSync(runScreenshotsDir, { recursive: true });
    copyHelperScript('import-wpb-template.php');
    copyHelperScript('convert-to-new-page.php');
    copyHelperScript('set-wpbakery-content.php');
    sourceId = wp(`TEMPLATE=about-section wp eval-file /tmp/import-wpb-template.php --allow-root`).trim();
    expect(sourceId).toMatch(/^\d+$/);
    created.push(sourceId);
  });

  test.afterAll(() => trashPages(created));

  test('the Tools screen lists the page, checks it, converts it, publishes it and records the run', async ({ page }) => {
    await login(page);
    await page.goto(TOOLS);
    await expect(page.locator('.wbdc-wrap > h1')).toContainText('WPBakery to Divi 5 Converter');

    // Free offers a radio and Pro a checkbox — same field, one of the two.
    await page.check(
      `input[name="wbdc_post_ids"][value="${sourceId}"], input[name="wbdc_post_ids[]"][value="${sourceId}"]`
    );
    await page.click('button:has-text("Check this page")');
    await page.waitForURL(/action=direct_report/);

    // The report is the promise the conversion then has to keep: how much of
    // the page reached a Divi module, and the structure it will produce.
    await expect(page.locator('.wbdc-direct-report')).toContainText('WPBakery elements reached a Divi module');
    await expect(page.locator('.wbdc-outline-node--section').first()).toBeVisible();
    await page.screenshot({ path: path.join(runScreenshotsDir, 'workflow-report.png'), fullPage: true });

    // Nothing has been written yet: the source page is still WPBakery's.
    expect(convertedPageFor(sourceId), 'checking a page writes nothing').toBe('');

    await page.click('button:has-text("Convert to Divi 5")');
    await page.waitForURL(/action=batch_result/);
    await expect(page.locator('.wbdc-summary-stat--ok')).toContainText('1 converted');
    await expect(page.locator('.wbdc-status--converted').first()).toBeVisible();
    await page.screenshot({ path: path.join(runScreenshotsDir, 'workflow-results.png'), fullPage: true });

    // The id of *this* run, so the Undo assertion below cannot be satisfied by
    // a run someone made yesterday.
    const importId = new URL(page.url()).searchParams.get('import_id') ?? '';
    expect(importId, 'the result screen names the run it just made').not.toBe('');
    created.push(convertedPageFor(sourceId));

    // A direct conversion lands as a draft; the result screen offers to
    // publish it, and that is the button a reader actually presses.
    await page.click('a.button:has-text("Publish")');
    await page.waitForURL(/action=batch_result/);
    await expect(page.locator('.wbdc-published-label').first()).toBeVisible();

    // The source page is never modified.
    const sourceContent = wp(`wp post get ${shellEscape(sourceId)} --field=content --allow-root`);
    expect(sourceContent).toContain('[vc_row]');

    // And *this* run can be undone from the landing page: the Undo link carries
    // the run's own id (`ImportRollback::QUERY_ACTION`).
    await page.goto(TOOLS);
    const undo = page.locator(`a.button[href*="wbdc_rollback=${importId}"]`);
    await expect(undo).toHaveCount(1);
    await expect(undo).toBeVisible();
    await expect(undo).toHaveText('Undo');
  });

  test('the converted page renders every module the report counted, without JS errors', async ({ page }) => {
    const errors = collectPageErrors(page);
    await serveExternalRequestsLocally(page);

    const newId = convertedPageFor(sourceId);
    expect(newId).toMatch(/^\d+$/);

    const report = conversionReport(newId);
    expect(report.unsupported, 'nothing in this template is unsupported').toEqual([]);
    expect(report.skipped_settings, 'no setting was skipped').toEqual([]);

    await page.goto(`${BASE}/?page_id=${newId}`);
    await page.waitForSelector('.et_pb_section', { timeout: 20000 });

    // The source is one `vc_row`, so the page is one section.
    const sourceRows = (wp(`wp post get ${shellEscape(sourceId)} --field=content --allow-root`).match(/\[vc_row[\s\]]/g) ?? [])
      .length;
    expect(sourceRows).toBe(1);
    await expect(page.locator('.et_builder_inner_content .et_pb_section')).toHaveCount(sourceRows);

    for (const [selector, count] of expectedCounts(report.converted)) {
      await expect(page.locator(`.et_builder_inner_content ${selector}`), `${selector} count`).toHaveCount(count);
    }

    // Two absolute expectations beside the report-derived ones, so a report
    // that counted nothing could not agree with a page that rendered nothing.
    // (This template carries no button; `btn-flat` and `btn-inline-group` in
    // divi-integration.spec.ts hold the `.et_pb_button` floor.)
    expect(await page.locator('.et_builder_inner_content .et_pb_image').count(), 'images on the page').toBeGreaterThanOrEqual(1);
    expect(await page.locator('.et_builder_inner_content .et_pb_text').count(), 'text blocks on the page').toBeGreaterThanOrEqual(1);

    await page.screenshot({ path: path.join(runScreenshotsDir, 'about-section-frontend.png'), fullPage: true });
    expect(errors, 'front-end JavaScript errors').toEqual([]);
  });
});

/**
 * A theme's own element that this converter has no handler for takes one of
 * two paths, and which one depends on where the page came from — not on the
 * element (spec §7, task-14-amendments §6):
 *
 *  - **on the site it lives on**, the plugin that registers it is right there,
 *    so it is rendered and kept as a static copy that looks like the live page;
 *  - **from a file**, nothing can render it, so it becomes a labelled
 *    placeholder that keeps the tag, its text and its position.
 *
 * Ultimate Addons plays the part of the theme add-on here. Ronneby Core cannot:
 * it switches itself off unless the DFD Ronneby theme is the active one, and
 * Divi is (docs/conversion-workflow.md).
 */
test.describe.serial('A theme element with no handler: static copy here, placeholder from a file', () => {
  const FIXTURE = 'wpbakery/ultimate-heading';
  const uploadPath = path.join(rootDir, 'fixtures', 'wpbakery', 'ultimate-heading.txt');
  /** Everything this describe made, trashed at the end. */
  const created: string[] = [];

  test.beforeAll(() => {
    fs.mkdirSync(runScreenshotsDir, { recursive: true });
    copyHelperScript('set-wpbakery-content.php');
    copyHelperScript('convert-to-new-page.php');
  });

  test.afterAll(() => trashPages(created));

  test('on this site the element is rendered and kept as a static copy', async ({ page }) => {
    const active = wp(`wp plugin list --field=name --status=active --allow-root`).includes('Ultimate_VC_Addons');
    test.skip(!active, 'Ultimate Addons is not installed — see references/README.md.');

    const errors = collectPageErrors(page);
    await serveExternalRequestsLocally(page);

    const sourceId = createWPBakeryPage(FIXTURE, 'Theme Element (WPBakery)');
    const newId = convertToNewPage(sourceId);
    created.push(sourceId, newId);

    const report = conversionReport(newId);
    expect(report.theme_elements, 'the family is named, not the tag').toEqual({ 'Ultimate Addons': 1 });
    expect(Object.keys(report.static_copies), 'one element was copied as rendered HTML').toHaveLength(1);
    expect(report.unsupported, 'a theme element is not a converter gap').toEqual([]);

    await page.goto(`${BASE}/?page_id=${newId}`);
    await page.waitForSelector('.et_pb_section', { timeout: 20000 });
    await expect(page.locator('.et_pb_code .uvc-heading h3')).toHaveText('Static copy heading');
    await expect(page.locator('.wbdc-unconverted-element'), 'not a placeholder here').toHaveCount(0);

    await page.screenshot({ path: path.join(runScreenshotsDir, 'theme-element-static-copy.png'), fullPage: true });
    expect(errors, 'front-end JavaScript errors').toEqual([]);
  });

  test('the same shortcode uploaded as a file becomes a labelled placeholder', async ({ page }) => {
    const errors = collectPageErrors(page);
    await serveExternalRequestsLocally(page);

    await login(page);
    await page.goto(TOOLS);
    await page.setInputFiles('#wbdc_import_file', uploadPath);
    await page.click('button:has-text("Read this file")');
    await page.waitForURL(/action=import_options/);
    await expect(page.locator('.wbdc-import-options')).toContainText('page');

    await page.selectOption('#wbdc_post_status', 'publish');
    await page.click('button:has-text("Convert Now")');
    await page.waitForURL(/action=batch_result/);
    await expect(page.locator('.wbdc-summary-stat--ok')).toContainText('1 converted');
    await page.screenshot({ path: path.join(runScreenshotsDir, 'upload-results.png'), fullPage: true });

    const importedId = wp(
      `wp post list --post_type=page --post_status=any --meta_key=_wbdc_import_source --meta_value=file_upload --field=ID --orderby=ID --order=DESC --posts_per_page=1 --allow-root`
    )
      .trim()
      .split('\n')[0]
      .trim();
    expect(importedId).toMatch(/^\d+$/);
    created.push(importedId);

    const report = conversionReport(importedId);
    expect(report.theme_elements).toEqual({ 'Ultimate Addons': 1 });
    expect(report.static_copies, 'nothing can be rendered from a file').toEqual([]);
    const addon = (report.not_carried_over as { kind: string; detail: string }[]).filter((e) => e.kind === 'addon');
    expect(addon, 'the theme element is reported as an add-on element').toHaveLength(1);
    expect(addon[0].detail).toContain('ultimate_heading');

    await page.goto(`${BASE}/?page_id=${importedId}`);
    await page.waitForSelector('.et_pb_section', { timeout: 20000 });
    await expect(
      page.locator('.wbdc-unconverted-element[data-wpbakery-element="ultimate_heading"]')
    ).toContainText('Kept exactly as its own plugin renders it.');
    await expect(page.locator('.uvc-heading'), 'a file cannot be rendered into a static copy').toHaveCount(0);

    await page.screenshot({ path: path.join(runScreenshotsDir, 'theme-element-placeholder.png'), fullPage: true });
    expect(errors, 'front-end JavaScript errors').toEqual([]);
  });
});
