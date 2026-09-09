# references/

Not committed. The Docker environment and the schema documentation expect two archives here:

- `Divi.zip` — the Divi 5 theme from your Elegant Themes account (exact filename `Divi.zip`; the
  converter's attribute paths were verified against 5.12.1).
- `js_composer.7.8.zip` — WPBakery Page Builder 7.8, the `js_composer.zip` found inside the CodeCanyon
  download. A newer `js_composer.<version>.zip` may be dropped in; update `docker-compose.yml` and
  `docs/wpbakery-schema.md` to match.

`scripts/docker/setup_wp.sh` stops with instructions when either file is missing.
