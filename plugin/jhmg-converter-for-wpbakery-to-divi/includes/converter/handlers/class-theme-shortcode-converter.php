<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;
use WPBakeryDivi5Converter\Converter\ConverterEngine;
use WPBakeryDivi5Converter\Helpers\Autop;
use WPBakeryDivi5Converter\Helpers\ThemeShortcodes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Everything the registry has no handler for — which on a real WPBakery site
 * is most of the page (spec §7).
 *
 * Three outcomes, and none of them is "dropped":
 *
 * - **A bracketed token that is not a shortcode at all** — `[1]`, a footnote
 *   marker, a stray bracket in body copy. WordPress would print it as text
 *   because nothing registers it; the parser here turns any `[token]` into a
 *   node, so it is turned back into the text it was (amendment §5).
 * - **On the site the page lives on**, the theme and its add-ons are active,
 *   so the shortcode is rendered with `do_shortcode()` and the HTML kept in a
 *   `divi/code` block. It looks exactly like the live page; it is a static
 *   copy, and the report says so, because it will not update when its plugin
 *   does.
 * - **From an export**, nothing can render it, so a labelled placeholder keeps
 *   the tag name, the text it carried and its position in the page.
 *
 * Either way the family is counted (`Salient: 12`) and the element's own
 * design options are still mapped onto the block.
 */
class ThemeShortcodeConverter extends BaseWPBakeryConverter {

    private string $tag;

    public function __construct( ConverterEngine $engine, string $tag = '' ) {
        parent::__construct( $engine );
        $this->tag = $tag;
    }

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_code_' ) );
        $tag  = $this->tag !== '' ? $this->tag : (string) ( $node['tag'] ?? '' );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        if ( $this->isBracketedText( $tag, $node ) ) {
            return $this->bracketedText( $id, $node );
        }

        $is_core = ThemeShortcodes::isCore( $tag );
        $reason  = '';

        if ( $is_core ) {
            // Already recorded under `unsupported` by the engine; a second
            // entry under "not carried over" would say the same thing twice.
            $this->engine->logWarning(
                "{$tag} has no Divi 5 equivalent in this release; it was kept as a labelled placeholder (id: {$id})."
            );
        } else {
            $family = ThemeShortcodes::family( $tag );
            $reason = $family['kind'];
            $this->engine->logThemeElement( $family['label'], $id, $tag );
        }

        $copy = $this->staticCopy( $node, $reason );

        // The element's own design options still apply to the block that
        // stands in for it — but not WPBakery's default element margin: the
        // rendered HTML brings its own spacing from the theme's stylesheet.
        $style = $this->mapStyle( 'generic', array_merge( $node, [ 'delegated' => true ] ) );

        $this->engine->logConverted( 'code' );

        // Every attribute went into the copy or the placeholder verbatim, so
        // there is nothing here that was dropped.
        $this->logUnmappedSettings( $id, $atts, array_keys( $atts ), $tag );

        return $this->codeBlock( $id, $copy['html'], $style['divi_attrs'] );
    }

    /**
     * A `[token]` that no plugin could ever have registered: a bare number, or
     * a name that does not start with a letter. WordPress prints those as
     * literal text; only this converter's own parser turned it into a node.
     */
    private function isBracketedText( string $tag, array $node ): bool {
        if ( ! empty( $node['atts'] ) || ! empty( $node['children'] ) || trim( (string) ( $node['content'] ?? '' ) ) !== '' ) {
            return false;
        }

        return preg_match( '/^\d+$/', $tag ) === 1 || preg_match( '/^[A-Za-z][A-Za-z0-9_-]*$/', $tag ) !== 1;
    }

    private function bracketedText( string $id, array $node ): array {
        $source = $this->originalShortcode( $node );

        $this->engine->logWarning( 'bracketed text kept: ' . $source );
        $this->engine->logTextNode();
        $this->engine->logConverted( 'text' );

        return $this->block( $id, 'divi/text', [
            'content' => [ 'innerContent' => [ 'desktop' => [ 'value' => trim( Autop::apply( $source ) ) ] ] ],
        ] );
    }
}
