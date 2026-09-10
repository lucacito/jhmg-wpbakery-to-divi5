import { test, expect } from '@playwright/test';
import fs from 'fs';
import path from 'path';
import {
  BASE,
  convertToNewPage,
  copyHelperScript,
  createWPBakeryPage,
  rootDir,
  serveExternalRequestsLocally,
  trashPages,
} from './helpers';

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
 * The result is written to `test-results/module-spacing.json` only — a gated
 * run must leave the working tree clean. `docs/module-spacing.json` is the
 * reviewed copy `docs/box-model.md` cites: when the measurement changes, copy
 * the new file over it by hand and say why in the commit. A module whose
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
type Measured = { marginBottom: string; paddingBottom: string; drawnOn: string };
type Result = Measured & {
  fixture: string;
  written: Written;
  found: boolean;
  /** Whether the fixture measured writes a default trailing space for this type. */
  gated: boolean;
  fixturesTried: string[];
};

/** Module types no fixture in the corpus renders on this site. */
const KNOWN_NOT_RENDERED = [
  // Contact Form 7 is not installed here, so the module renders nothing.
  'contact-form-7',
  // The gallery fixtures name attachment ids that only exist as an import-time
  // media map; this site's media library has no such attachments.
  'gallery',
];

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

const expectedFor = new Map<string, Map<string, Written>>();

/** The spacing an expected fixture writes, per module type, read once. */
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

/** Does this fixture's golden give that module type a default trailing space? */
function writesDefault(fixture: string, type: string): boolean {
  const written = writtenFor(fixture).get(type);
  return !!written && (written.marginBottom !== '' || written.paddingBottom !== '');
}

/**
 * module type ⇒ the fixtures that emit it, best first.
 *
 * **A fixture that writes a default for the type comes first**, whatever else
 * is true of it. Sorting by size alone silently defeats the whole measurement:
 * `image` is emitted by both `single-image` (which writes the 35px default) and
 * `ronneby-single-image` (whose handler passes `'none'` and writes nothing), and
 * the alphabet picked the second — so `divi/image`, which is exactly the shape
 * that loses its margin (no `styleProps.spacing` in its `module.json`), would
 * have been measured on a page where there was nothing to lose. The same tie
 * decided `blurb`, `divider`, `map`, `accordion` and `heading`.
 *
 * Within that, the most focused fixture wins, so the page stays small and the
 * module is easy to find.
 */
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
        .sort(
          (a, b) =>
            Number(writesDefault(b, type)) - Number(writesDefault(a, type)) ||
            typesPerFixture.get(a)!.length - typesPerFixture.get(b)!.length ||
            a.localeCompare(b)
        )
        .slice(0, MAX_FIXTURES_PER_TYPE)
    );
  }

  return new Map(Array.from(candidates).sort(([a], [b]) => a.localeCompare(b)));
}

const candidates = candidateFixtures();

test.describe.serial('WPBakery default element spacing, per Divi module type', () => {
  test.skip(!ENABLED, 'Set BOX_MODEL=1 to run the module spacing measurement.');
  test.use({ viewport: { width: 1280, height: 1400 } });

  const results: Record<string, Result> = {};
  const converted = new Map<string, string>();
  /** Everything this file made, trashed at the end so the picker stays short. */
  const created: string[] = [];

  test.beforeAll(() => {
    fs.mkdirSync(path.join(rootDir, 'test-results'), { recursive: true });
    copyHelperScript('set-wpbakery-content.php');
    copyHelperScript('convert-to-new-page.php');
  });

  test.afterAll(() => trashPages(created));

  /** Converts a fixture once per run and returns the converted page id. */
  function pageFor(fixture: string): string {
    if (!converted.has(fixture)) {
      const sourceId = createWPBakeryPage(`wpbakery/${fixture}`, `Spacing ${fixture} (WPBakery)`);
      const pageId = convertToNewPage(sourceId);
      created.push(sourceId, pageId);
      converted.set(fixture, pageId);
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

        const wanted = writtenFor(fixture).get(type);
        const measured = await page.evaluate(
          ({ className, want }: { className: string; want: string }) => {
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

            // Some modules route their own spacing onto inner elements through
            // `propertySelectors` in their `module.json` — `divi/pricing-tables`
            // puts its padding on `.et_pb_pricing_heading` and its siblings — so
            // "nothing on the module box" is not the same as "nothing on the
            // page". When the box has none, the value the converter asked for is
            // looked for inside before calling it lost.
            const near = (a: string, b: string) => Math.abs(parseFloat(a) - parseFloat(b)) < 0.5;
            let drawnOn = '';
            if (want && style.marginBottom === '0px' && style.paddingBottom === '0px') {
              const inner = Array.from(root.querySelectorAll('*')).find((node) => {
                const s = getComputedStyle(node);
                return near(s.paddingBottom, want) || near(s.marginBottom, want);
              });
              if (inner) {
                drawnOn = '.' + (inner.className || inner.tagName.toLowerCase()).toString().trim().split(/\s+/)[0];
              }
            }

            return { marginBottom: style.marginBottom, paddingBottom: style.paddingBottom, drawnOn };
          },
          { className: MODULE_CLASSES[type], want: wanted?.paddingBottom || wanted?.marginBottom || '' }
        );

        if (measured) {
          results[type] = {
            fixture,
            written: writtenFor(fixture).get(type) ?? { marginBottom: '', paddingBottom: '' },
            ...measured,
            found: true,
            gated: writesDefault(fixture, type),
            fixturesTried: tried,
          };

          // A computed style, not an empty string: proof the element really was
          // found and read rather than defaulted past.
          expect(measured.marginBottom, `${type} margin-bottom is a computed length`).toMatch(/^-?[\d.]+px$/);
          expect(measured.paddingBottom, `${type} padding-bottom is a computed length`).toMatch(/^-?[\d.]+px$/);
          return;
        }
      }

      results[type] = {
        fixture: '',
        written: writtenFor(fixtures[0]).get(type) ?? { marginBottom: '', paddingBottom: '' },
        marginBottom: '',
        paddingBottom: '',
        drawnOn: '',
        found: false,
        gated: false,
        fixturesTried: tried,
      };

      // Not rendering is a real answer for a handful of modules and a
      // regression for any other, so it is asserted rather than recorded.
      // Soft, so the run still reaches the summary and writes the table.
      expect
        .soft(KNOWN_NOT_RENDERED, `${type} rendered on none of ${tried.join(', ')} — see docs/conversion-workflow.md`)
        .toContain(type);
    });
  }

  test('every module the converter gives a default trailing space keeps it on the page', () => {
    // test-results/ only: a gated run must leave the working tree clean.
    // docs/module-spacing.json is updated by hand from this file.
    fs.writeFileSync(
      path.join(rootDir, 'test-results', 'module-spacing.json'),
      JSON.stringify({ viewport: 1280, results }, null, 2)
    );

    const missing = Object.entries(results).filter(([, r]) => !r.found);
    if (missing.length > 0) {
      console.log(
        `module-spacing: not rendered on this site: ${missing.map(([type]) => type).join(', ')} ` +
          '(a missing attachment, a plugin that is not installed, or content WPBakery strips on save)'
      );
    }

    // A type no fixture in the corpus gives a default to has nothing to lose,
    // so it is reported rather than gated — naming it, because "no finding"
    // and "nothing was looked at" are different answers.
    const ungated = Object.entries(results).filter(([, r]) => r.found && !r.gated);
    if (ungated.length > 0) {
      console.log(
        `module-spacing: measured but not gated (no fixture writes a default trailing space for them): ${ungated
          .map(([type]) => type)
          .join(', ')}`
      );
    }

    // The finding the Task 2 ruling asked for: a module whose default trailing
    // space is dropped by Divi has to write padding-bottom instead. Padding as
    // well as margin, so a module that loses the padding too is caught.
    const routed = Object.entries(results).filter(([, r]) => r.gated && r.drawnOn !== '');
    if (routed.length > 0) {
      console.log(
        `module-spacing: drawn on an inner element, not the module box: ${routed
          .map(([type, r]) => `${type} → ${r.drawnOn}`)
          .join(', ')}`
      );
    }

    const dropped = Object.entries(results).filter(
      ([, r]) => r.gated && r.marginBottom === '0px' && r.paddingBottom === '0px' && r.drawnOn === ''
    );
    expect(
      dropped.map(
        ([type, r]) =>
          `${type} (${r.fixture}): wrote margin-bottom "${r.written.marginBottom}" padding-bottom "${r.written.paddingBottom}", measured 0px on both`
      ),
      'Divi kept every default trailing space the converter wrote'
    ).toEqual([]);

    // And the measurement actually covered the corpus.
    expect(Object.keys(results).length, 'every module type in the corpus was measured').toBe(candidates.size);
  });
});
