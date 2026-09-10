<?php

namespace WPBakeryDivi5Converter\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Which parameters WPBakery itself declares for each of its elements.
 *
 * The table behind it is `data/wpbakery-params.json`, generated from
 * `references/js_composer.9.0.1.zip` and `js_composer.7.8.zip` by
 * `scripts/build-wpbakery-params.php` — every `param_name` an element's
 * `config/**` entry declares, in either era.
 *
 * It answers one question, and it is the question that decides how an
 * unclaimed attribute is reported (task-11-amendments §7). A field on a
 * WPBakery core element that no handler consumed is either:
 *
 * - **a gap in this converter** — WPBakery declares the field, a page can be
 *   built with it, and nothing here read it. That is a `skipped_settings`
 *   entry, which is the converter's own to-do list; or
 * - **somebody else's field** — a theme or plugin bolted it onto the element
 *   with `vc_add_param()`. `ThemeShortcodes::THEME_PARAMS` names those it has
 *   source for; this table catches the rest, because a name WPBakery does not
 *   declare for that tag cannot have come from WPBakery whoever did add it.
 *   That is a `not_carried_over` entry of kind `addon`.
 *
 * **An empty entry and a missing one are different states.** `[]` is a tag
 * whose config the generator read and which declares no parameters at all —
 * `vc_gitem_zone` and `vc_gitem_zone_b` are exactly that in 9.0.1
 * (`include/params/vc_grid_item/shortcodes/vc_gitem_zone.php`, a map with a
 * `'base'` and no `params`), though 7.8 gives both a set, so the shipped union
 * has none. Every key on such a tag is undeclared, and `declares()` says so.
 *
 * A tag with **no entry** is one this generator could not read, and guessing
 * that everything on it is a theme's would hide real gaps, so `declares()`
 * answers null and the caller keeps its old behaviour. Six core tags are in
 * that state, and none of them is a config file the generator missed:
 *
 * - `vc_acf`, `vc_gitem_acf`, `vc_gitem_wocommerce` — mapped inline in a
 *   vendor bridge (`include/classes/vendors/plugins/acf/shortcode.php`,
 *   `…/acf/grid-item-shortcodes.php`, `…/woocommerce/grid-item-shortcodes.php`),
 *   and only when that plugin is active;
 * - `vc_custom_field` — a render class with no `vc_map()` in the archive
 *   (`include/classes/shortcodes/vc-custom-field.php`);
 * - `vc_container_anchor` — not a mapped element at all: a helper that prints
 *   an anchor (`include/helpers/helpers.php:2377`);
 * - `vc_grid` — the runtime shortcode `vc_basic_grid` renders through; no
 *   `'base' => 'vc_grid'` exists in either archive.
 */
final class WPBakeryParams {

    /** @var array<string, array<int, string>>|null */
    private static ?array $table = null;

    /**
     * Whether WPBakery declares `$param` for `$tag`.
     *
     * @return bool|null False for every param of a tag the table holds as `[]`
     *   — that tag's config was read and declares nothing. Null only when the
     *   table has no entry for the tag at all.
     */
    public static function declares( string $tag, string $param ): ?bool {
        $declared = self::declaredFor( $tag );

        return $declared === null ? null : in_array( $param, $declared, true );
    }

    /**
     * @return array<int, string>|null Every parameter name declared for the tag —
     *   `[]` when its config declares none — or null when it is not in the table.
     */
    public static function declaredFor( string $tag ): ?array {
        $table = self::table();

        return isset( $table[ $tag ] ) && is_array( $table[ $tag ] ) ? $table[ $tag ] : null;
    }

    /** @return array<string, array<int, string>> */
    private static function table(): array {
        if ( self::$table === null ) {
            $file    = WBDC_PLUGIN_DIR . 'data/wpbakery-params.json';
            $decoded = is_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;

            self::$table = is_array( $decoded ) ? $decoded : [];
        }

        return self::$table;
    }
}
