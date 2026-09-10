import { execFileSync } from 'child_process';
import path from 'path';

export const rootDir = path.resolve(__dirname, '..', '..');
export const screenshotsDir = path.join(rootDir, 'tests', 'e2e', 'screenshots');
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
