<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;
use WPBakeryDivi5Converter\Converter\ConverterEngine;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The ten `vc_wp_*` elements that are a WordPress core widget and nothing else.
 *
 * Every one of their templates is the same four lines
 * (`include/templates/shortcodes/vc_wp_archives.php` and its nine siblings): a
 * `.vc_wp_<name>.wpb_content_element` wrapper, `$type = '<WP widget class>'`,
 * a `$wp_widget_factory` guard and `the_widget( $type, $atts )`. Divi has no
 * module for any of them, so spec §7's two behaviours apply:
 *
 * - **on the site the page lives on** the widget is rendered with `the_widget()`
 *   and kept in a `divi/code` block — a static copy, reported as such, because
 *   it will not refresh when the site's posts do;
 * - **from an export** nothing can render it, so a labelled placeholder keeps
 *   the widget's name and its settings.
 *
 * `vc_wp_custommenu`, `vc_wp_search` and `vc_wp_text` are not here: each has a
 * real Divi module (`divi/menu`, `divi/search`, `divi/text`).
 *
 * `.wpb_content_element` — the 35 px `content` margin.
 */
class WpWidgetConverter extends BaseWPBakeryConverter {

    /** Shortcode ⇒ the `$type` its template hands `the_widget()`. */
    const WIDGETS = [
        'vc_wp_archives'       => 'WP_Widget_Archives',
        'vc_wp_calendar'       => 'WP_Widget_Calendar',
        'vc_wp_categories'     => 'WP_Widget_Categories',
        'vc_wp_links'          => 'WP_Widget_Links',
        'vc_wp_meta'           => 'WP_Widget_Meta',
        'vc_wp_pages'          => 'WP_Widget_Pages',
        'vc_wp_posts'          => 'WP_Widget_Recent_Posts',
        'vc_wp_recentcomments' => 'WP_Widget_Recent_Comments',
        'vc_wp_rss'            => 'WP_Widget_RSS',
        'vc_wp_tagcloud'       => 'WP_Widget_Tag_Cloud',
    ];

    private string $tag;

    public function __construct( ConverterEngine $engine, string $tag = '' ) {
        parent::__construct( $engine );
        $this->tag = $tag;
    }

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_widget_' ) );
        $tag  = $this->tag !== '' ? $this->tag : (string) ( $node['tag'] ?? '' );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        $widget = self::WIDGETS[ $tag ] ?? '';

        $style = $this->mapStyle( 'generic', $node );
        $html  = $this->render( $widget, $tag, $atts, $id );

        $this->engine->logConverted( 'code' );
        // Every attribute is a widget setting and went into the render or the
        // placeholder verbatim.
        $this->logUnmappedSettings( $id, $atts, array_keys( $atts ), $tag );

        $blocks = [ $this->codeBlock( $id, $html, $style['divi_attrs'] ) ];

        $title = $this->titleBlock( $node );
        if ( $title !== null ) {
            $this->engine->logConverted( 'heading' );
            array_unshift( $blocks, $title );
        }

        return count( $blocks ) === 1 ? $blocks[0] : $blocks;
    }

    /**
     * `the_widget( $type, $atts, [] )` on this site, a labelled placeholder
     * from an export.
     *
     * `the_widget()` echoes, so it is captured the same way the template does
     * (`ob_start()` … `ob_get_clean()`).
     */
    private function render( string $widget, string $tag, array $atts, string $id ): string {
        $options    = $this->engine->options();
        $can_render = $widget !== '' && $options['mode'] === 'direct' && $options['render_shortcodes'] && function_exists( 'the_widget' );

        if ( $can_render ) {
            ob_start();
            the_widget( $widget, $atts, [] );
            $html = (string) ob_get_clean();

            if ( trim( $html ) !== '' ) {
                $this->engine->logStaticCopy( $id );
                $this->engine->logNotCarriedOver(
                    'integration',
                    $id,
                    sprintf( '%s renders the WordPress %s widget; it was rendered here and kept as a static copy, so it will not refresh when the site does', $tag, $widget )
                );

                return '<div class="vc_' . esc_attr( substr( $tag, 3 ) ) . '">' . $html . '</div>';
            }
        }

        $this->engine->logNotCarriedOver(
            'integration',
            $id,
            sprintf( '%s renders the WordPress %s widget, which Divi has no module for; a labelled placeholder keeps its place — add the widget to a sidebar and use Divi\'s sidebar module, or rebuild it', $tag, $widget )
        );

        return '<!-- wpbakery element: ' . esc_html( $tag ) . ' (' . esc_html( $widget ) . ') -->'
            . '<div class="wbdc-unconverted-element" data-wpbakery-element="' . esc_attr( $tag ) . '">'
            . esc_html( $this->settingsSummary( $atts ) )
            . '</div>';
    }

    /** The widget's own settings, so the placeholder says what it was showing. */
    private function settingsSummary( array $atts ): string {
        $parts = [];

        foreach ( $atts as $key => $value ) {
            if ( ! is_string( $key ) || in_array( $key, [ 'css', 'el_id', 'el_class', 'css_animation' ], true ) ) {
                continue;
            }
            if ( ! is_scalar( $value ) || trim( (string) $value ) === '' ) {
                continue;
            }
            $parts[] = $key . ': ' . (string) $value;
        }

        return implode( ', ', $parts );
    }
}
