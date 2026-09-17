<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;
use WPBakeryDivi5Converter\Converter\ConverterEngine;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `vc_facebook`, `vc_tweetmeme`, `vc_pinterest`, `vc_googleplus` and
 * `vc_flickr` → a labelled placeholder, registered approximate.
 *
 * Every one of them prints a third-party widget script that renders itself in
 * the visitor's browser — Facebook's `fb-like`, X's `twitter-share-button`,
 * Pinterest's `pin-it` button, the retired Google+ badge, and Flickr's
 * `badge_code_*` script (`include/templates/shortcodes/vc_facebook.php` and its
 * siblings). Divi's `divi/social-media-follow` is a set of links to profiles,
 * not a share widget, so nothing here converts to it: the element is kept as a
 * placeholder that names the button and its settings, and reported under
 * `integration` (spec §6).
 *
 * On the site the page lives on the shortcode still renders — WPBakery is
 * active there — so `staticCopy()` keeps the live markup instead.
 *
 * **Spacing.** Every one of the five carries `.wpb_content_element` and its
 * 35 px (byte 103311 of `js_composer.min.css`), but the rule at byte 103390 —
 * `.entry-content .twitter-share-button,.fb_like,.twitter-share-button,
 * .wpb_accordion .wpb_content_element,.wpb_googleplus,.wpb_pinterest,
 * .wpb_tab .wpb_content_element{margin-bottom:21.73913043px}` — comes later at
 * equal specificity and wins wherever it matches. It matches the wrapper of
 * three of them, so those take the `social` kind and the other two `content`
 * (`NARROW_MARGIN`).
 */
class SocialConverter extends BaseWPBakeryConverter {

    /** The five elements and the network each one belongs to. */
    const NETWORKS = [
        'vc_facebook'   => 'Facebook',
        'vc_tweetmeme'  => 'X (Twitter)',
        'vc_pinterest'  => 'Pinterest',
        'vc_googleplus' => 'Google+',
        'vc_flickr'     => 'Flickr',
    ];

    /**
     * The three whose wrapper class the 21.74 px rule names.
     * `vc_facebook.php:39` writes `fb_like`, `vc_pinterest.php:53`
     * `wpb_pinterest` and `vc_googleplus.php:51` `wpb_googleplus` — all three
     * in that selector list. `vc_tweetmeme` puts `twitter-share-button` on the
     * anchor inside and wraps it in `vc_tweetmeme-element`, and `vc_flickr`'s
     * wrapper is `wpb_flickr_widget`, so neither matches and both keep the
     * 35 px `.wpb_content_element` margin.
     */
    const NARROW_MARGIN = [ 'vc_facebook', 'vc_pinterest', 'vc_googleplus' ];

    private string $tag;

    public function __construct( ConverterEngine $engine, string $tag = '' ) {
        parent::__construct( $engine );
        $this->tag = $tag;
    }

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_social_' ) );
        $tag  = $this->tag !== '' ? $this->tag : (string) ( $node['tag'] ?? '' );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        $network = self::NETWORKS[ $tag ] ?? $tag;
        $style   = $this->mapStyle( 'generic', $node, in_array( $tag, self::NARROW_MARGIN, true ) ? 'social' : 'content' );

        // `staticCopy()` reports it under the kind given here, once, whichever
        // branch it takes.
        $copy = $this->staticCopy( $node, '' );

        $this->engine->logNotCarriedOver(
            'integration',
            $id,
            $copy['static']
                ? sprintf( '%s draws the %s widget from that network\'s own script; it was rendered here and kept as a static copy', $tag, $network )
                : sprintf( '%s draws the %s widget from that network\'s own script, which Divi has no module for; a labelled placeholder keeps its place', $tag, $network )
        );

        if ( $tag === 'vc_googleplus' ) {
            $this->engine->logWarning( 'vc_googleplus: Google+ shut down in 2019, so this button renders nothing wherever it is kept.' );
        }

        $this->engine->logConverted( 'code' );
        // Every attribute is a widget setting and went into the copy or the
        // placeholder verbatim.
        $this->logUnmappedSettings( $id, $atts, array_keys( $atts ), $tag );

        $blocks = [ $this->codeBlock( $id, $copy['html'], $style['divi_attrs'] ) ];

        $title = $this->titleBlock( $node );
        if ( $title !== null ) {
            $this->engine->logConverted( 'heading' );
            array_unshift( $blocks, $title );
        }

        return count( $blocks ) === 1 ? $blocks[0] : $blocks;
    }
}
