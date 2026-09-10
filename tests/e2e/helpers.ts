import { execFileSync } from 'child_process';
import path from 'path';

export const rootDir = path.resolve(__dirname, '..', '..');
/** Committed evidence: the box-model measurement's screenshots live here. */
export const screenshotsDir = path.join(rootDir, 'tests', 'e2e', 'screenshots');

/**
 * Where the specs that run on every `npm test` put their screenshots.
 * `test-results/` is gitignored and wiped at the start of each run, so a
 * routine run leaves the working tree clean — a screenshot of a passing page is
 * a debugging aid, not a specification the way the box-model images are.
 */
export const runScreenshotsDir = path.join(rootDir, 'test-results', 'screenshots');
export const BASE = process.env.PLAYWRIGHT_BASE_URL || 'http://localhost:8020';

const KNOWN_DIVI_NOISE = ['Transition was skipped', 'ResizeObserver loop'];
export function isDiviNoise(msg: string): boolean {
  return KNOWN_DIVI_NOISE.some((s) => msg.includes(s));
}

export function shellEscape(value: string): string {
  return `'${value.replace(/'/g, `'\\''`)}'`;
}

export function getContainerId(): string {
  const id = execFileSync('docker', ['compose', 'ps', '-q', 'wordpress'], { cwd: rootDir, encoding: 'utf8' }).trim();
  if (!id) throw new Error('WordPress container is not running. Run scripts/docker/setup_wp.sh first.');
  return id;
}

export function copyHelperScript(name: string): void {
  execFileSync('docker', ['cp', path.join(rootDir, 'scripts', 'docker', name), `${getContainerId()}:/tmp/${name}`], { cwd: rootDir, stdio: 'inherit' });
}

export function wp(command: string): string {
  // stderr is captured rather than inherited: a plugin on the site can be
  // noisy with PHP deprecations that say nothing about the conversion. A
  // command that actually fails still throws, with its stderr on the error.
  return execFileSync('docker', ['exec', '-i', getContainerId(), 'bash', '-lc', command], {
    cwd: rootDir,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
  });
}

/** Creates a page carrying a WPBakery fixture (path relative to fixtures/, no .txt). */
export function createWPBakeryPage(fixture: string, title: string): string {
  const id = wp(`wp post create --post_type=page --post_status=publish --post_title=${shellEscape(title)} --porcelain --allow-root`).trim();
  wp(`FIXTURE=${shellEscape(fixture)} PAGE_ID=${shellEscape(id)} wp eval-file /tmp/set-wpbakery-content.php --allow-root`);
  return id;
}

export function convertToNewPage(sourceId: string): string {
  const id = wp(`SOURCE_PAGE_ID=${shellEscape(sourceId)} wp eval-file /tmp/convert-to-new-page.php --allow-root`).trim();
  if (!id) throw new Error('convert-to-new-page.php returned no page id');
  return id;
}

/**
 * Moves the posts a spec created to the trash.
 *
 * Every spec here makes pages, and left behind they pile up in the picker the
 * Tools screen renders twenty rows at a time — so a later run's assertion on
 * "the page I just made" would depend on it still being on page one. Each spec
 * keeps its own list and empties it in `afterAll`; the list is per spec rather
 * than shared, so one file's cleanup can never reach into another's pages.
 * Trash, not delete: the picker ignores trashed posts, and a failed run's
 * evidence is still there to look at.
 */
export function trashPages(ids: string[]): void {
  const real = ids.filter((id) => /^\d+$/.test(id));
  if (real.length === 0) return;
  wp(`wp post delete ${real.join(' ')} --allow-root`);
}

/** The conversion report the exporter stamped on a converted post. */
export function conversionReport(postId: string): any {
  const raw = wp(`wp post meta get ${shellEscape(postId)} _wbdc_conversion_report --allow-root`).trim();
  if (!raw) throw new Error(`post ${postId} carries no _wbdc_conversion_report`);
  return JSON.parse(raw);
}

/** The id of the page a source page was converted into, or '' when there is none. */
export function convertedPageFor(sourceId: string): string {
  return wp(
    `wp post list --post_type=page --post_status=any --meta_key=_wbdc_source_post_id --meta_value=${shellEscape(sourceId)} --field=ID --allow-root`
  )
    .trim()
    .split('\n')[0]
    .trim();
}

const TRANSPARENT_GIF = Buffer.from('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', 'base64');

/**
 * Answers every off-site request from the test itself.
 *
 * Real WPBakery content points at the site it was built on — a theme demo's
 * CDN, an image on `example.com` — and letting the browser try to reach those
 * would make the suite depend on the network and fill the console with load
 * failures that say nothing about the conversion. They are served locally
 * instead, so "no console errors" means what it says.
 */
export async function serveExternalRequestsLocally(page: import('@playwright/test').Page): Promise<void> {
  await page.route('**/*', (route) => {
    const host = new URL(route.request().url()).hostname;
    if (host === 'localhost' || host === '127.0.0.1') {
      return route.continue();
    }
    return route.request().resourceType() === 'image'
      ? route.fulfill({ status: 200, contentType: 'image/gif', body: TRANSPARENT_GIF })
      : route.fulfill({ status: 200, contentType: 'text/plain', body: '' });
  });
}

/**
 * Collects uncaught exceptions and console errors, minus Divi's own known
 * noise. Returned array fills as the page runs; assert on it at the end.
 */
export function collectPageErrors(page: import('@playwright/test').Page): string[] {
  const errors: string[] = [];
  page.on('pageerror', (err) => {
    if (!isDiviNoise(err.message)) errors.push(`pageerror: ${err.message}`);
  });
  page.on('console', (message) => {
    if (message.type() === 'error' && !isDiviNoise(message.text())) errors.push(`console: ${message.text()}`);
  });
  return errors;
}

export async function login(page: import('@playwright/test').Page): Promise<void> {
  await page.goto(`${BASE}/wp-login.php`);
  await page.fill('input#user_login', 'admin');
  await page.fill('input#user_pass', 'admin');
  await page.click('input#wp-submit');
  await page.waitForURL(/wp-admin/, { timeout: 20000 });
}
