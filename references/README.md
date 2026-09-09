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

`scripts/docker/setup_wp.sh` stops with instructions when `Divi.zip` or `js_composer.9.0.1.zip` is missing.
