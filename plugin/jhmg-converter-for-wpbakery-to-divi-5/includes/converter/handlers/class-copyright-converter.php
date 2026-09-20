<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;
use WPBakeryDivi5Converter\Helpers\DynamicContent;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `vc_copyright` (9.0) → `divi/text`.
 *
 * The template is one paragraph: `prefix` + `&copy;` + `gmdate( 'Y' )` +
 * `postfix`, aligned by `wpb_copyright_element-align-<align>`
 * (`include/templates/shortcodes/vc_copyright.php`). Freezing the year into the
 * page would leave every converted site showing the year of its conversion, so
 * it becomes Divi's `current_date` dynamic-content token with a custom format
 * of `Y` — which is what `gmdate( 'Y' )` prints (spec §6).
 *
 * No default bottom margin: `element_default_class` is `wpb_copyright_element`,
 * not `wpb_content_element`, and `.wpb_copyright_element` carries no margin
 * rule in `js_composer.min.css`.
 */
class CopyrightConverter extends BaseWPBakeryConverter {

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_copyright_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        // `wpb_copyright_element` has no margin rule of its own
        // (`js_composer.min.css`) — `element_default_class` is
        // `wpb_copyright_element`, not `wpb_content_element`.
        $style    = $this->mapStyle( 'text', $node, 'none' );
        $attrs    = $style['divi_attrs'];
        $consumed = array_merge( $style['handled_keys'], [ 'prefix', 'postfix', 'align' ] );

        $year = DynamicContent::token( 'current_date', [
            'date_format'        => 'custom',
            'custom_date_format' => 'Y',
        ] );

        StyleMapper::write(
            $attrs,
            'content.innerContent.desktop.value',
            '<p>' . $this->att( $atts, 'prefix' ) . '&copy; ' . $year . $this->att( $atts, 'postfix' ) . '</p>'
        );

        $align = strtolower( $this->att( $atts, 'align', 'left' ) );
        if ( in_array( $align, [ 'left', 'center', 'right', 'justify' ], true ) ) {
            StyleMapper::write( $attrs, 'content.decoration.bodyFont.body.font.desktop.value.textAlign', $align );
        }

        $this->engine->logConverted( 'text' );
        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        return $this->block( $id, 'divi/text', $attrs );
    }
}
