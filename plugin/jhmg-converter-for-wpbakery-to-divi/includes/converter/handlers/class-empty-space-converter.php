<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;
use WPBakeryDivi5Converter\Helpers\PackedParams;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `vc_empty_space` → `divi/divider` with the line switched off.
 *
 * The element is nothing but its height: `include/templates/shortcodes/vc_empty_space.php`
 * prints `<div class="vc_empty_space" style="height: …">`, with the height run
 * through `wpb_format_with_css_unit()` and falling back to `0px`. Divi's divider
 * is the module whose height can be set with no line drawn
 * (`divider.advanced.line.desktop.value.show = 'off'`,
 * `module.decoration.sizing.desktop.value.height`).
 *
 * No default bottom margin: the element *is* the space (amendment §2).
 */
class EmptySpaceConverter extends BaseWPBakeryConverter {

    /** `config/content/shortcode-vc-empty-space.php`: `'std' => '32'`. */
    const DEFAULT_HEIGHT = '32';

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_space_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        // The element is the space; `mapStyle()` would add a bottom margin on
        // top of it.
        $style    = $this->mapStyle( 'generic', $node, 'none' );
        $attrs    = $style['divi_attrs'];
        $consumed = array_merge( $style['handled_keys'], [ 'height' ] );

        $height = PackedParams::sizeWithUnit( $this->att( $atts, 'height', self::DEFAULT_HEIGHT ) );
        if ( $height === '' ) {
            $height = '0px';
        }

        StyleMapper::write( $attrs, 'divider.advanced.line.desktop.value.show', 'off' );
        StyleMapper::write( $attrs, 'module.decoration.sizing.desktop.value.height', $height );

        $this->engine->logConverted( 'divider' );
        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        return $this->block( $id, 'divi/divider', $attrs );
    }
}
