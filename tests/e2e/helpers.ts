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
  return execFileSync('docker', ['exec', '-i', getContainerId(), 'bash', '-lc', command], { cwd: rootDir, encoding: 'utf8' });
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

export async function login(page: import('@playwright/test').Page): Promise<void> {
  await page.goto(`${BASE}/wp-login.php`);
  await page.fill('input#user_login', 'admin');
  await page.fill('input#user_pass', 'admin');
  await page.click('input#wp-submit');
  await page.waitForURL(/wp-admin/, { timeout: 20000 });
}
