# references/

Not committed. The Docker environment and the schema documentation expect these archives here:

- `Divi.zip` — the Divi 5 theme from your Elegant Themes account (exact filename `Divi.zip`; the
  converter's attribute paths were verified against 5.12.1).
- `js_composer.9.0.1.zip` — WPBakery Page Builder 9.0.1, the `js_composer.zip` found inside the CodeCanyon
  download. This is the schema source and the version the Docker site installs.
- `js_composer.7.8.zip` — WPBakery 7.8, kept for the pre-9.0 attribute forms (palette-name colours,
  checkbox values) that most live pages still carry.
- `Ultimate_VC_Addons.zip` — Ultimate Addons for WPBakery 3.19.3, the source for the add-on element
  handlers (`bsf-info-box`, `just_icon`, `stat_counter`, `ultimate_pricing`, `ultimate_video`,
  `ult_content_box`).
- `layouts-for-wpbakery.1.1.5.zip` — the free Layouts for WPBakery plugin; its public API is where
  `scripts/fetch-layouts-corpus.php` downloads the 35-layout smoke corpus from.
- `themeforest-KBeonkGF-the-retailer-…-wordpress-theme.zip` — The Retailer 10.0.13 (Get Bowtied), a
  WPBakery-based WooCommerce theme. The "installable WordPress file" download: it holds the theme only,
  no demo pages. Its WPBakery elements live in the separate "The Retailer Extender" plugin
  (`https://getbowtied.github.io/repository/plugins/the-retailer-extender/the-retailer-extender.zip`,
  10.0.6), which is the source for the "The Retailer" theme family in `Helpers\ThemeShortcodes`. The
  theme's demo-content WXR belongs in `wpbakery templates/` once obtained.
- `themeforest-s0tt3MTV-ronneby-highperformance-wordpress-theme.zip` — DFD Ronneby 3.5.74 (full
  ThemeForest package). `Mainfiles/import/<demo>/content.xml` holds one WordPress export per demo:
  96 WPBakery demos with 398 WPBakery-built pages (the 14 `*_elementor_*` demos are skipped). Those
  exports are the real-world corpus under `wpbakery templates/ronneby/`. The package bundles
  `js_composer_9.0.1.zip` and Slider Revolution; the theme's own `dfd_*` elements live in the
  separate "Ronneby Core" plugin, which the package does not include.
- `ronneby-core.zip` — the Ronneby Core plugin, downloaded from DFD's update server with the theme's
  purchase code (the theme's TGM screen does the same). It defines every `dfd_*` element and the
  unprefixed Ronneby elements (`announcement`, `new_team_member`, `price_list`, …) the demo exports
  use, and is the source the Ronneby element handlers are written from.

`scripts/docker/setup_wp.sh` stops with instructions when `Divi.zip` or `js_composer.9.0.1.zip` is missing.
