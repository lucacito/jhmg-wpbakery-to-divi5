# Real theme exports

WordPress exports (WXR) of complete, professionally built WPBakery sites. They are the
converter's hardest test input: whole pages with the elements themes actually ship — stretched
sections, nested rows, tta tabs, grids, theme shortcodes — rather than one-element demos.
`tests/ThirdPartyTemplateConversionTest.php` requires them to convert validator-clean with zero
skipped settings.

## ronneby/

96 demo exports from the **Ronneby** theme (ThemeForest `s0tt3MTV`, by BeautiThemes), one per
non-Elementor demo site. Three of them are committed as reviewed samples:

| File | Size | Why it is committed |
|---|---|---|
| `04_pages_demo.xml` | 1.3 MB | the "Pages" demo: 86 rows, 30 inner rows and the widest spread of element types of any demo |
| `15_tenth.xml` | 72 KB | a mid-sized one-page demo, small enough to read whole, with rows nested inside columns |
| `23_eighteenth.xml` | 54 KB | a small demo, likewise with nested rows |

The other 93 are gitignored: the folder is ~43 MB in full, and every file in it is reproducible
from the theme archive at any time. Re-extract them with

```sh
scripts/extract-ronneby-corpus.sh                    # references/themeforest-s0tt3MTV-…zip
scripts/extract-ronneby-corpus.sh path/to/theme.zip
```

That default path is **your own ThemeForest download**, placed by hand in `references/`, which is
gitignored in full — the archive is commercial, it is not in this repository, and no script can
fetch it. Without it the three committed samples are all there is, which is enough for the test
suite; the other 93 are for wider spot checks.

which copies every `Mainfiles/import/<demo>/content.xml` out of the archive to
`ronneby/<demo>.xml`, skips the demos whose name says Elementor (they hold no WPBakery
shortcodes), and never overwrites a file that is already there.

### Licence

The Ronneby demo content is the theme vendor's, distributed under ThemeForest's Regular Licence
with the theme purchase; it is neither ours nor GPL. It lives here as **local test input only**:
nothing from it is redistributed, and none of it ships in either plugin. The theme archive
itself is in `references/`, which is gitignored for the same reason. Anyone rebuilding this
corpus needs their own copy of the theme.
