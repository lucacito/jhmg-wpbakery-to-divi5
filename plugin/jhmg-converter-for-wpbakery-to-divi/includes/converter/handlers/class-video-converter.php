<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `vc_video` → `divi/video`, or `divi/code` for a URL Divi cannot play.
 *
 * WPBakery hands the URL to WordPress: `$wp_embed->run_shortcode( '[embed]…[/embed]' )`
 * (`include/templates/shortcodes/vc_video.php`), so anything oEmbed knows plays.
 * Divi's video module does the same for its own `src`
 * (`VideoModule::render_callback()` calls `et_pb_check_oembed_provider()` and
 * falls back to a YouTube embed), so a YouTube, Vimeo or media-file URL is a
 * straight `video.innerContent.desktop.value.src`.
 *
 * For anything else the safe conversion is the embed itself: on this site
 * `wp_oembed_get()` can produce the HTML the visitor saw, and from an export the
 * URL is kept as a link so nothing is lost. Both go in a `divi/code`, reported.
 */
class VideoConverter extends BaseWPBakeryConverter {

    /** Hosts Divi's own video module resolves (`VideoHTMLController`, `et_pb_check_oembed_provider`). */
    const PLAYABLE_HOSTS = [
        'youtube.com',
        'youtu.be',
        'www.youtube.com',
        'm.youtube.com',
        'youtube-nocookie.com',
        'www.youtube-nocookie.com',
        'vimeo.com',
        'www.vimeo.com',
        'player.vimeo.com',
    ];

    /** `.wpb_video_widget.vc_video-aspect-ratio-<value> .wpb_video_wrapper{padding-top:…}`. */
    const ASPECT_RATIOS = [
        '169'  => '16:9',
        '43'   => '4:3',
        '235'  => '2.35:1',
        '916'  => '9:16',
        '34'   => '3:4',
        '1235' => '1:2.35',
    ];

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_video_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        $style    = $this->mapStyle( 'generic', $node );
        $attrs    = $style['divi_attrs'];
        $consumed = array_merge( $style['handled_keys'], [ 'link', 'el_width', 'align', 'el_aspect', 'title' ] );

        $link = trim( $this->att( $atts, 'link' ) );

        if ( $link === '' ) {
            // `if ( '' === $link ) { return null; }` — WPBakery renders nothing.
            $this->engine->logWarning( "vc_video {$id} has no video URL; nothing was written for it." );
            $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

            return [];
        }

        $this->geometry( $atts, $id, $attrs );

        $blocks = [];

        $title = $this->titleBlock( $node );
        if ( $title !== null ) {
            $this->engine->logConverted( 'heading' );
            $blocks[] = $title;
        }

        $blocks[] = $this->playable( $link )
            ? $this->videoBlock( $id, $link, $attrs )
            : $this->embedBlock( $id, $link, $attrs );

        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        return count( $blocks ) === 1 ? $blocks[0] : $blocks;
    }

    /** @return array<string,mixed> */
    private function videoBlock( string $id, string $link, array $attrs ): array {
        StyleMapper::write( $attrs, 'video.innerContent.desktop.value.src', $link );

        $this->engine->logConverted( 'video' );

        return $this->block( $id, 'divi/video', $attrs );
    }

    /**
     * A URL Divi's video module does not resolve. On the site the page lives on,
     * `wp_oembed_get()` answers with the provider's own player HTML — the same
     * thing the visitor saw; from an export the URL is kept as a link.
     *
     * @return array<string,mixed>
     */
    private function embedBlock( string $id, string $link, array $attrs ): array {
        $options = $this->engine->options();
        $html    = '';

        if ( $options['mode'] === 'direct' && $options['render_shortcodes'] && function_exists( 'wp_oembed_get' ) ) {
            $embed = wp_oembed_get( $link );
            if ( is_string( $embed ) && trim( $embed ) !== '' ) {
                $html = $embed;
                $this->engine->logStaticCopy( $id );
            }
        }

        if ( $html === '' ) {
            $html = '<p><a href="' . esc_url( $link ) . '">' . esc_html( $link ) . '</a></p>';
        }

        $this->engine->logNotCarriedOver(
            'integration',
            $id,
            sprintf( 'vc_video link %s is not a provider Divi\'s video module plays; the embed was kept in a code module', $link )
        );

        $this->engine->logConverted( 'code' );

        return $this->codeBlock( $id, $html, $attrs );
    }

    /** A YouTube or Vimeo URL, or a media file Divi can put in a `<video>`. */
    private function playable( string $link ): bool {
        if ( preg_match( '/\.(mp4|webm|ogv|m4v|mov)(\?|#|$)/i', $link ) === 1 ) {
            return true;
        }

        $host = strtolower( (string) ( wp_parse_url( $link, PHP_URL_HOST ) ?: '' ) );

        return in_array( $host, self::PLAYABLE_HOSTS, true );
    }

    /** `el_width` (a percentage), `align` and the aspect ratio the wrapper forces. */
    private function geometry( array $atts, string $id, array &$attrs ): void {
        $width = trim( $this->att( $atts, 'el_width' ) );
        if ( preg_match( '/^\d+$/', $width ) === 1 && (int) $width > 0 && (int) $width < 100 ) {
            StyleMapper::write( $attrs, 'module.decoration.sizing.desktop.value.width', $width . '%' );
        }

        $align = strtolower( $this->att( $atts, 'align', 'left' ) );
        if ( in_array( $align, [ 'left', 'center', 'right' ], true ) ) {
            StyleMapper::write( $attrs, 'module.decoration.sizing.desktop.value.alignment', $align );
        }

        $aspect = trim( $this->att( $atts, 'el_aspect' ) );
        if ( $aspect !== '' && $aspect !== '169' ) {
            $this->engine->logNotCarriedOver(
                'layout',
                $id,
                sprintf(
                    'vc_video el_aspect="%s" (%s) reshapes the player; Divi\'s video module keeps its 16:9 frame',
                    $aspect,
                    self::ASPECT_RATIOS[ $aspect ] ?? $aspect
                )
            );
        }
    }
}
