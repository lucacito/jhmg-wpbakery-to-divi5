# Layouts for WPBakery — layout corpus

The 35 `.txt` files beside this README are the layouts published by
**[Layouts for WPBakery](https://wordpress.org/plugins/layouts-for-wpbakery/)**, a free plugin by
Techeshta (contributors: techeshta, alkesh7, vastarpara, hadihirpara), licensed **GPL-2.0-or-later**.
Each file is the `template` string of one layout, byte for byte: a WPBakery shortcode document.

They are here as test input only. The converter is tested against real pages rather than
hand-written snippets, and these are the largest set of current, freely licensed WPBakery
layouts there is. No layout, image or copy from them ships in the plugin.

Fetched from the plugin's own public API:

- index: `https://www.layoutsforwpbakery.com/wp-json/layoutsforwpbakery/v1/templates`
- layout: `https://www.layoutsforwpbakery.com/wp-json/layoutsforwpbakery/v1/template/byid/?id=<id>`

Last fetched: 2026-09-09 (UTC).

Regenerate with `php scripts/fetch-layouts-corpus.php`; it only writes files that are not
already there, so an existing layout is never silently rewritten.
