<?php

namespace WPBakeryDivi5Converter\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Divi 5 dynamic-content tokens.
 *
 * Divi 5 stores dynamic content as
 * `$variable({"type":"content","value":{"name":"post_title","settings":{}}})$`
 * and resolves the token wherever it appears inside a string
 * (DynamicData::get_variable_values), so it can be embedded in the middle of
 * a larger string rather than only standing alone.
 *
 * `token()` is used for the WPBakery attributes that have a direct Divi
 * dynamic-content equivalent: `vc_custom_heading source="post_title"` →
 * `token('post_title')`, `vc_copyright`'s current year → `token('current_date',
 * ['date_format' => 'custom', 'custom_date_format' => 'Y'])`, and
 * `vc_custom_field`'s `field_name` → `token('post_meta_key', ['meta_key' => …])`.
 *
 * Ported unchanged from the Beaver converter's
 * `BeaverDivi5Converter\Helpers\FieldConnections::token()`
 * (plugin/jhmg-converter-for-beaver-builder-to-divi/includes/helpers/class-field-connections.php).
 * WPBakery has no equivalent of Beaver Themer's `[wpbb ...]` field-connection
 * shortcode to translate, so only the token builder is ported.
 */
final class DynamicContent {

    /** A Divi 5 dynamic-content token for the given option and settings. */
    public static function token( string $name, array $settings = [] ): string {
        $json = json_encode(
            [ 'type' => 'content', 'value' => [ 'name' => $name, 'settings' => (object) $settings ] ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        return '$variable(' . $json . ')$';
    }
}
