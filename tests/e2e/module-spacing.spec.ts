import { test, expect } from '@playwright/test';
import fs from 'fs';
import path from 'path';
import { BASE, convertToNewPage, copyHelperScript, createWPBakeryPage, rootDir, serveExternalRequestsLocally } from './helpers';

/**
 * What actually reaches the page when the converter writes WPBakery's default
 * trailing space on a module.
 *
 * The converter reproduces WPBakery's per-element bottom margin
 * (`GlobalSettingsResolver::moduleMarginBottom()`), but Divi only writes a
 * module's spacing declaration `!important` when that module's `module.json`
 * says so (`attributes.module.styleProps.spacing.important`). Where it does
 * not, Divi's own stylesheet wins and the margin the converter asked for never
 * lands — and the only way to know which modules those are is to render one of
 * each and read the computed style.
 *
 * The result goes to `test-results/module-spacing.json` and to
 * `docs/module-spacing.json`, which `docs/box-model.md` cites. A module whose
 * trailing space measures `0px` while the converter asked for one fails this
 * spec: its handler has to write `padding-bottom` instead.
 *
 * Opt-in, like the box-model measurement it belongs beside:
 *   BOX_MODEL=1 npx playwright test tests/e2e/module-spacing.spec.ts
 */
const ENABLED = process.env.BOX_MODEL === '1';

/**
 * Divi block name ⇒ the class prefix its module renders with, read from each
 * module's own `module.json` (`d4Shortcode`) in Divi 5.12.1. Divi always
 * writes the indexed form (`et_pb_divider_0`) and only sometimes the bare one,
 * so both are matched. Only the types this converter emits are listed;
 * `section`, `row` and `column` are structure, not modules, and carry no
 * default element margin.
 */
const MODULE_CLASSES: Record<string, string> = {
  accordion: 'et_pb_accordion',
  'accordion-item': 'et_pb_accordion_item',
  blog: 'et_pb_blog',
  blurb: 'et_pb_blurb',
  button: 'et_pb_button',
  charts: 'et_pb_charts',
  'circle-counter': 'et_pb_circle_counter',
  code: 'et_pb_code',
  'contact-form-7': 'et_pb_contact_form_7',
  'countdown-timer': 'et_pb_countdown_timer',
  counter: 'et_pb_counter',
  counters: 'et_pb_counters',
  cta: 'et_pb_cta',
  divider: 'et_pb_divider',
  gallery: 'et_pb_gallery',
  group: 'et_pb_group',
  heading: 'et_pb_heading',
  icon: 'et_pb_icon',
  'icon-list': 'et_pb_icon_list',
  'icon-list-item': 'et_pb_icon_list_item',
  image: 'et_pb_image',
  map: 'et_pb_map',
  'map-pin': 'et_pb_map_pin',
  menu: 'et_pb_menu',
  'number-counter': 'et_pb_number_counter',
  'post-slider': 'et_pb_post_slider',
  'pricing-table': 'et_pb_pricing_table',
  'pricing-tables': 'et_pb_pricing_tables',
  search: 'et_pb_search',
  sidebar: 'et_pb_sidebar',
  slide: 'et_pb_slide',
  slider: 'et_pb_slider',
  'social-media-follow': 'et_pb_social_media_follow',
  'social-media-follow-network': 'et_pb_social_media_follow_network',
  tab: 'et_pb_tab',
  tabs: 'et_pb_tabs',
  'team-member': 'et_pb_team_member',
  testimonial: 'et_pb_testimonial',
  text: 'et_pb_text',
  toggle: 'et_pb_toggle',
  video: 'et_pb_video',
};

/**
 * How many fixtures to try for a module type before giving up on it. A module
 * can be missing from a page for reasons that have nothing to do with spacing
 * — a gallery whose attachments are not in this site's media library, or a
 * `vc_btn` with `custom_onclick` that WPBakery's own `wpb_remove_custom_html`
 * strips out of `post_content` on save — so a second and third fixture are
 * tried before the type is recorded as not rendered here.
 */
const MAX_FIXTURES_PER_TYPE = 3;

type Written = { marginBottom: string; paddingBottom: string };
type Result = {
  fixture: string;
  written: Written;
  marginBottom: string;
  paddingBottom: string;
  found: boolean;
  fixturesTried: string[];
};

function read(node: any, dotPath: string): any {
  return dotPath.split('.').reduce((carry, key) => (carry == null ? undefined : carry[key]), node);
}

/**
 * The trailing space the converter wrote on the first block of each module
 * type in an expected fixture. `divi/image` reads its spacing from
 * `module.advanced`, everything else from `module.decoration`
 * (docs/box-model.md).
 */
function writtenSpacing(elements: any[], into: Map<string, Written>): void {
  for (const element of elements ?? []) {
    const type = String(element.name ?? '').replace(/^divi\//, '');
    if (MODULE_CLASSES[type] && !into.has(type)) {
      const base = type === 'image' ? 'module.advanced.spacing' : 'module.decoration.spacing';
      const value = read(element.settings ?? {}, `${base}.desktop.value`) ?? {};
      into.set(type, {
        marginBottom: String(value?.margin?.bottom ?? ''),
        paddingBottom: String(value?.padding?.bottom ?? ''),
      });
    }
    writtenSpacing(element.elements ?? [], into);
  }
}

/** module type ⇒ the fixtures that emit it, most focused first. */
function candidateFixtures(): Map<string, string[]> {
  const dir = path.join(rootDir, 'fixtures', 'divi');
  const typesPerFixture = new Map<string, string[]>();

  for (const file of fs.readdirSync(dir).filter((f) => f.endsWith('.json'))) {
    const raw = fs.readFileSync(path.join(dir, file), 'utf8');
    typesPerFixture.set(
      path.basename(file, '.json'),
      Array.from(new Set(Array.from(raw.matchAll(/"name"\s*:\s*"divi\/([^"]+)"/g), (m) => m[1])))
    );
  }

  const candidates = new Map<string, string[]>();
  for (const [fixture, types] of typesPerFixture) {
    for (const type of types) {
      if (MODULE_CLASSES[type]) candidates.set(type, [...(candidates.get(type) ?? []), fixture]);
    }
  }

  for (const [type, fixtures] of candidates) {
    candidates.set(
      type,
      fixtures
        .sort((a, b) => typesPerFixture.get(a)!.length - typesPerFixture.get(b)!.length || a.localeCompare(b))
        .slice(0, MAX_FIXTURES_PER_TYPE)
    );
  }

  return new Map(Array.from(candidates).sort(([a], [b]) => a.localeCompare(b)));
}

const candidates = candidateFixtures();
const expectedFor = new Map<string, Map<string, Written>>();

function writtenFor(fixture: string): Map<string, Written> {
  if (!expectedFor.has(fixture)) {
    const written = new Map<string, Written>();
    writtenSpacing(
      JSON.parse(fs.readFileSync(path.join(rootDir, 'fixtures', 'divi', `${fixture}.json`), 'utf8')).divi?.elements ?? [],
      written
    );
    expectedFor.set(fixture, written);
  }
  return expectedFor.get(fixture)!;
}

test.describe.serial('WPBakery default element spacing, per Divi module type', () => {
  test.skip(!ENABLED, 'Set BOX_MODEL=1 to run the module spacing measurement.');
  test.use({ viewport: { width: 1280, height: 1400 } });

  const results: Record<string, Result> = {};
  const converted = new Map<string, string>();

  test.beforeAll(() => {
    fs.mkdirSync(path.join(rootDir, 'test-results'), { recursive: true });
    copyHelperScript('set-wpbakery-content.php');
    copyHelperScript('convert-to-new-page.php');
  });

  /** Converts a fixture once per run and returns the converted page id. */
  function pageFor(fixture: string): string {
    if (!converted.has(fixture)) {
      const sourceId = createWPBakeryPage(`wpbakery/${fixture}`, `Spacing ${fixture} (WPBakery)`);
      converted.set(fixture, convertToNewPage(sourceId));
    }
    return converted.get(fixture)!;
  }

  for (const [type, fixtures] of candidates) {
    test(`measures ${type}`, async ({ page }) => {
      await serveExternalRequestsLocally(page);

      const tried: string[] = [];

      for (const fixture of fixtures) {
        tried.push(fixture);
        await page.goto(`${BASE}/?page_id=${pageFor(fixture)}`);
        await page.waitForSelector('div.et_builder_inner_content', { timeout: 30000 });
        await page.waitForLoadState('networkidle');

        const measured = await page.evaluate((className: string) => {
          const pattern = new RegExp(`^${className}(_\\d+)?$`);
          const el = Array.from(document.querySelectorAll('div.et_builder_inner_content *')).find((node) =>
            Array.from(node.classList).some((c) => pattern.test(c))
          );
          if (!el) return null;

          // Divi puts a module's own spacing on its wrapper when it renders one
          // (`et_pb_button_module_wrapper`), so the element to read is the
          // outermost one the module produced.
          let root: Element = el;
          while (root.parentElement && /_module_wrapper\b/.test(root.parentElement.className)) {
            root = root.parentElement;
          }
          const style = getComputedStyle(root);
          return { marginBottom: style.marginBottom, paddingBottom: style.paddingBottom };
        }, MODULE_CLASSES[type]);

        if (measured) {
          results[type] = {
            fixture,
            written: writtenFor(fixture).get(type) ?? { marginBottom: '', paddingBottom: '' },
            ...measured,
            found: true,
            fixturesTried: tried,
          };
          return;
        }
      }

      results[type] = {
        fixture: '',
        written: writtenFor(fixtures[0]).get(type) ?? { marginBottom: '', paddingBottom: '' },
        marginBottom: '',
        paddingBottom: '',
        found: false,
        fixturesTried: tried,
      };
      expect(results[type].found, `${type} did not render on any of ${tried.join(', ')}`).toBe(false);
    });
  }

  test('every module the converter gives a default margin keeps it on the page', () => {
    fs.writeFileSync(
      path.join(rootDir, 'test-results', 'module-spacing.json'),
      JSON.stringify({ viewport: 1280, results }, null, 2)
    );
    fs.writeFileSync(
      path.join(rootDir, 'docs', 'module-spacing.json'),
      JSON.stringify({ viewport: 1280, results }, null, 2)
    );

    const missing = Object.entries(results).filter(([, r]) => !r.found);
    if (missing.length > 0) {
      console.log(
        `module-spacing: not rendered on this site: ${missing.map(([type]) => type).join(', ')} ` +
          '(a missing attachment, a plugin that is not installed, or content WPBakery strips on save)'
      );
    }

    // The finding the Task 2 ruling asked for: a module whose default trailing
    // space is dropped by Divi has to write padding-bottom instead.
    const dropped = Object.entries(results).filter(
      ([, r]) => r.found && r.written.marginBottom !== '' && r.marginBottom === '0px' && r.paddingBottom === '0px'
    );
    expect(
      dropped.map(([type, r]) => `${type}: wrote margin-bottom ${r.written.marginBottom}, measured 0px`),
      'Divi kept every default trailing space the converter wrote'
    ).toEqual([]);
  });
});
