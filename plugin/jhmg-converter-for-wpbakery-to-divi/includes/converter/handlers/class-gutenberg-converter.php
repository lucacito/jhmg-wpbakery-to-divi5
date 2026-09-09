<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `vc_gutenberg` → `divi/code`.
 *
 * The element holds block-editor markup verbatim (it is one of
 * `ShortcodeParser::RAW_CONTENT_TAGS`) and the template prints
 * `do_blocks( $content )` when `do_blocks="true"`, or the markup as it stands
 * otherwise.
 *
 * Amendment §4: on the site the page lives on the markup is rendered with
 * `do_blocks()` and kept as a static copy; from an export nothing can render it,
 * so the block comments are kept verbatim in a `divi/code` with a warning — a
 * page moved to Divi 5 has no `vc_gutenberg` to hand them back to, and losing
 * them would lose the content.
 */
class GutenbergConverter extends BaseWPBakeryConverter {

    public function convert( array $node ): array {
        $id      = (string) ( $node['id'] ?? uniqid( 'wbdc_gutenberg_' ) );
        $atts    = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];
        $content = (string) ( $node['content'] ?? '' );

        $style    = $this->mapStyle( 'generic', $node );
        $consumed = array_merge( $style['handled_keys'], [ 'do_blocks' ] );

        $options = $this->engine->options();
        $html    = $content;

        if ( $options['mode'] === 'direct' && $options['render_shortcodes'] && function_exists( 'do_blocks' ) ) {
            $rendered = (string) do_blocks( $content );
            if ( trim( $rendered ) !== '' ) {
                $html = $rendered;
                $this->engine->logStaticCopy( $id );
                $this->engine->logNotCarriedOver(
                    'integration',
                    $id,
                    'vc_gutenberg: the block markup was rendered on this site and kept as a static copy; edit it in the Divi code module from now on'
                );
            }
        } else {
            $this->engine->logWarning(
                "vc_gutenberg {$id} holds block-editor markup that nothing here can render; the blocks were kept verbatim in a code module."
            );
            $this->engine->logNotCarriedOver(
                'integration',
                $id,
                'vc_gutenberg: block-editor markup kept verbatim; rebuild it with Divi modules or paste it into a block-editor page'
            );
        }

        $this->engine->logConverted( 'code' );
        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        return $this->codeBlock( $id, trim( $html ), $style['divi_attrs'] );
    }
}
