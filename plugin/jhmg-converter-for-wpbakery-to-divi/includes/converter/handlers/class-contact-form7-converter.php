<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `contact-form-7` → `divi/contact-form-7`.
 *
 * Divi 5.12.1 ships a Contact Form 7 module of its own
 * (`module-library/src/components/contact-form-7/module.json`,
 * `server/Packages/ModuleLibrary/ContactForm7/`), which renders a form by id —
 * `form.advanced.formId.desktop.value`, the same `id` attribute the plugin's
 * own shortcode takes. So the form itself carries over untouched; only its
 * styling changes hands, from the theme's CSS to the module's own fields.
 *
 * `contact-form-7` is not a WPBakery element, so it has no `css` design
 * options and no `.wpb_content_element` margin of its own: it sits in the
 * column with whatever the theme gives it, which is the `content` default.
 */
class ContactForm7Converter extends BaseWPBakeryConverter {

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_cf7_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        $style = $this->mapStyle( 'generic', $node );
        $attrs = $style['divi_attrs'];

        $form = trim( $this->att( $atts, 'id' ) );

        if ( $form === '' ) {
            // Without an id there is no form to point at; the shortcode text
            // is kept so nothing is lost.
            $this->engine->logWarning( "contact-form-7 {$id} names no form id; it was kept as a code block." );
            $this->engine->logConverted( 'code' );
            $this->logUnmappedSettings( $id, $atts, array_keys( $atts ), (string) ( $node['tag'] ?? '' ) );

            return $this->codeBlock( $id, $this->originalShortcode( $node ), $attrs );
        }

        StyleMapper::write( $attrs, 'form.advanced.formId.desktop.value', $form );

        $this->engine->logNotCarriedOver(
            'integration',
            $id,
            sprintf( 'contact-form-7 id="%s" points at a form in the Contact Form 7 plugin; the plugin has to stay active for Divi\'s module to render it', $form )
        );

        $this->engine->logConverted( 'contact-form-7' );

        // `title` is the form's own name in the plugin's list, not a heading
        // WPBakery prints above the element, so it is claimed and not rendered.
        $this->logUnmappedSettings(
            $id,
            $atts,
            array_merge( $style['handled_keys'], [ 'id', 'title', 'html_class', 'html_id', 'html_name' ] ),
            (string) ( $node['tag'] ?? '' )
        );

        return $this->block( $id, 'divi/contact-form-7', $attrs );
    }
}
