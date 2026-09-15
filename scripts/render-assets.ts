/**
 * Builds the wordpress.org listing assets in `wporg-assets/`.
 *
 * Two modes, both driven by the Playwright Chromium the e2e suite already
 * installs — there is no second image toolchain in this repo:
 *
 *   render       (default) rasterises the committed SVG sources to the exact
 *                pixel sizes the plugin directory asks for. The SVG is the
 *                source of truth; the PNGs are never edited by hand.
 *   screenshots  re-takes `screenshot-1..3.png` off the Docker site at 1280px
 *                wide, logged in as admin, in the order the captions in
 *                readme.txt describe. It drives the shipped UI — pick a page,
 *                check it, convert it — so a screenshot can never show a
 *                screen the plugin does not actually render.
 *
 * The screenshots mode needs the site from scripts/docker/setup_wp.sh up, with
 * WPBakery pages on it; it converts one of them for real and prints the ids of
 * the pages that run created so the caller can trash them again.
 *
 * Usage:
 *   npm run assets
 *   npm run assets -- screenshots
 *   WBDC_SHOT_PAGE='Coffee shop' npm run assets -- screenshots
 *
 * `playwright.config.ts` points its testDir at tests/e2e, so this file is not
 * collected as a spec.
 */

import { chromium, type Browser, type Page } from '@playwright/test';
import { mkdirSync, readFileSync } from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const rootDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const assetsDir = path.join(rootDir, 'wporg-assets');
const BASE = process.env.PLAYWRIGHT_BASE_URL || 'http://localhost:8020';
/** The page screenshots 2 and 3 are taken of; it must already be on the site. */
const SHOT_PAGE = process.env.WBDC_SHOT_PAGE || 'Coffee shop';

type Target = { svg: string; out: string; width: number; height: number };

/** Every PNG wordpress.org serves, and the SVG each one is rasterised from. */
const TARGETS: Target[] = [
  { svg: 'icon.svg', out: 'icon-256x256.png', width: 256, height: 256 },
  { svg: 'icon.svg', out: 'icon-128x128.png', width: 128, height: 128 },
  { svg: 'banner-772x250.svg', out: 'banner-772x250.png', width: 772, height: 250 },
  { svg: 'banner-1544x500.svg', out: 'banner-1544x500.png', width: 1544, height: 500 },
];

/**
 * The SVG inlined in a page with no margins and no scrollbars, sized in CSS so
 * the viewBox scales to the target: a viewport-sized screenshot is then exactly
 * width × height, whatever the SVG's own width/height attributes say.
 */
function wrap(svg: string, width: number, height: number): string {
  return `<!doctype html><meta charset="utf-8"><style>
    html,body{margin:0;padding:0;overflow:hidden;background:transparent}
    svg{display:block;width:${width}px;height:${height}px}
  </style>${svg}`;
}

async function render(page: Page): Promise<void> {
  for (const target of TARGETS) {
    const svg = readFileSync(path.join(assetsDir, target.svg), 'utf8');
    await page.setViewportSize({ width: target.width, height: target.height });
    await page.setContent(wrap(svg, target.width, target.height), { waitUntil: 'load' });
    await page.screenshot({ path: path.join(assetsDir, target.out) });
    console.log(`${target.out}  ${target.width}x${target.height}  (from ${target.svg})`);
  }
}

async function login(page: Page): Promise<void> {
  await page.goto(`${BASE}/wp-login.php`);
  await page.fill('input#user_login', 'admin');
  await page.fill('input#user_pass', 'admin');
  await page.click('input#wp-submit');
  await page.waitForURL(/wp-admin/, { timeout: 20000 });
}

async function shoot(page: Page, name: string): Promise<void> {
  const file = path.join(assetsDir, name);
  // WordPress 7.1 animates admin navigation with a cross-document view
  // transition. A screenshot taken while it is running catches the outgoing and
  // incoming screens on top of each other, so wait it out before shooting.
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(1500);
  await page.screenshot({ path: file, fullPage: true });
  console.log(`${name}  ${page.viewportSize()?.width}px wide  ${page.url()}`);
}

/**
 * Clicks away the dismissible admin notices other plugins put on every screen
 * — WPBakery's own licence banner among them. They are dismissed the way a user
 * dismisses them (the × posts back), so the listing shows this plugin's screen
 * rather than somebody else's advertisement.
 */
async function dismissNotices(page: Page): Promise<void> {
  const buttons = page.locator('#wpbody-content .notice-dismiss');
  for (let i = (await buttons.count()) - 1; i >= 0; i--) {
    await buttons.nth(i).click();
  }
  await page.waitForTimeout(500);
}

/**
 * Screenshots 2, 3 and 1, in that order: the landing screen only lists a run to
 * undo once a run has happened, and caption 1 promises exactly that.
 */
async function screenshots(page: Page): Promise<void> {
  await page.setViewportSize({ width: 1280, height: 900 });
  await login(page);

  const converter = `${BASE}/wp-admin/tools.php?page=wbdc-converter`;
  await page.goto(converter);
  await dismissNotices(page);

  const row = page.locator('.wbdc-direct-table tr', { hasText: SHOT_PAGE });
  if ((await row.count()) === 0) {
    throw new Error(`No WPBakery page called "${SHOT_PAGE}" in the picker. Seed it first.`);
  }
  await row.first().locator('input[type="radio"], input[type="checkbox"]').check();
  await page.getByRole('button', { name: 'Check this page' }).click();
  await page.waitForSelector('.wbdc-direct-report');
  await shoot(page, 'screenshot-2.png');

  await page.getByRole('button', { name: 'Convert to Divi 5' }).click();
  await page.waitForSelector('.wbdc-batch-table');
  await shoot(page, 'screenshot-3.png');

  // Whatever the run created, so the caller can put the site back as it was.
  const created = await page.locator('.wbdc-batch-table a[href*="post="]').evaluateAll((links) =>
    Array.from(new Set(links.map((a) => new URL((a as HTMLAnchorElement).href).searchParams.get('post')).filter(Boolean)))
  );
  console.log(`pages created by this run: ${created.join(' ') || 'none'}`);

  await page.goto(converter);
  await page.waitForSelector('.wbdc-direct-table');
  await shoot(page, 'screenshot-1.png');

  // Converting is in place by default, so this run changed a real page on the
  // site. Undo it: the listing screenshot is already taken, and the site is
  // left as it was found. The link asks for confirmation.
  const undo = page.locator('a.button:has-text("Undo")').first();
  if ((await undo.count()) > 0) {
    page.once('dialog', (d) => d.accept());
    await undo.click();
    await page.waitForLoadState('domcontentloaded');
    console.log('the run shown in the screenshots has been undone');
  }
}

async function main(): Promise<void> {
  const mode = process.argv[2] || 'render';
  if (mode !== 'render' && mode !== 'screenshots') {
    console.error('usage: render-assets.ts [render|screenshots]');
    process.exit(2);
  }

  mkdirSync(assetsDir, { recursive: true });
  const browser: Browser = await chromium.launch();
  const page = await browser.newPage({ deviceScaleFactor: 1 });
  try {
    await (mode === 'render' ? render(page) : screenshots(page));
  } finally {
    await browser.close();
  }
}

main().catch((error) => {
  // The whole error, not just its message: a Playwright failure says where it
  // happened in the stack, and a one-line message hides it.
  console.error(error);
  process.exit(1);
});
