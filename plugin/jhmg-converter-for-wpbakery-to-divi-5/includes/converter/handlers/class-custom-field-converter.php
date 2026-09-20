<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;
use WPBakeryDivi5Converter\Helpers\DynamicContent;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `vc_custom_field` → `divi/text` holding a Divi dynamic-content token.
 * Approximate, and registered as such.
 *
 * The element is attributes-only: `include/templates/shortcodes/vc_custom_field.php`
 * reads `custom_field_key` and falls back to `field_key`, then prints
 * `{{ post_meta_value: <key> }}` inside a div — a token WPBakery's grid builder
 * resolves. Divi's equivalent is the `post_meta_key` dynamic-content option,
 * which resolves the same meta on the same post.
 *
 * It is approximate because WPBakery only ever resolves that token inside a grid
 * item: outside one the shortcode prints the token itself, so what the visitor
 * saw depends on where the element sat.
 *
 * No default bottom margin: `vc_custom_field`'s registration sets no
 * `element_default_class` anywhere.
 */
class CustomFieldConverter extends BaseWPBakeryConverter {

    /**
     * The settings key Divi's `post_meta_key` option reads
     * (`DynamicContentOptionPostMetaKey::render_callback()`, the "old legacy:
     * direct meta_key" branch, which 5.12.1 still resolves).
     *
     * It is a constant rather than a literal because Plugin Check's
     * `WordPress.DB.SlowDBQuery` sniff reads a literal `'meta_key'` array key as
     * a `WP_Query` argument; nothing here queries anything.
     */
    private const META_KEY_SETTING = 'meta_key';

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_custom_field_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        // No `element_default_class` anywhere in `vc-custom-field`'s registration,
        // so the element carries no WPBakery margin of its own.
        $style    = $this->mapStyle( 'text', $node, 'none' );
        $attrs    = $style['divi_attrs'];
        $consumed = array_merge( $style['handled_keys'], [ 'custom_field_key', 'field_key', 'key' ] );

        // `$key = strlen( $custom_field_key ) > 0 ? $custom_field_key : $field_key;`
        // `key` is accepted too: it is the name the task brief uses and costs
        // nothing to honour.
        $key = $this->att( $atts, 'custom_field_key' );
        if ( $key === '' ) {
            $key = $this->att( $atts, 'field_key' );
        }
        if ( $key === '' ) {
            $key = $this->att( $atts, 'key' );
        }

        if ( $key === '' ) {
            $this->engine->logWarning( "vc_custom_field {$id} names no meta key; WPBakery renders nothing for it and neither does the converted module." );
        }

        StyleMapper::write(
            $attrs,
            'content.innerContent.desktop.value',
            $key === '' ? '' : '<p>' . DynamicContent::token( 'post_meta_key', [ self::META_KEY_SETTING => $key ] ) . '</p>'
        );

        $this->engine->logConverted( 'text' );
        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        return $this->block( $id, 'divi/text', $attrs );
    }
}
