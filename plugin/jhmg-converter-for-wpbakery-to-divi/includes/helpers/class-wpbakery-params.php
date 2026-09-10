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
 * A tag the table has no entry for is **unknown**, not "declares nothing":
 * eight core tags are registered from `include/classes/shortcodes/` rather
 * than from a config file, so the generator cannot read their params, and
 * guessing that everything on them is a theme's would hide real gaps.
 * `declares()` answers null for those and the caller keeps its old behaviour.
 */
final class WPBakeryParams {

    /** @var array<string, array<int, string>>|null */
    private static ?array $table = null;

    /**
     * Whether WPBakery declares `$param` for `$tag`.
     *
     * @return bool|null Null when the table knows nothing about the tag.
     */
    public static function declares( string $tag, string $param ): ?bool {
        $declared = self::declaredFor( $tag );

        return $declared === null ? null : in_array( $param, $declared, true );
    }

    /**
     * @return array<int, string>|null Every parameter name declared for the tag, or null when it is not in the table.
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
