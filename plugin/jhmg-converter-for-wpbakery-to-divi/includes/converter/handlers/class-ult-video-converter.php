<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;
use WPBakeryDivi5Converter\Helpers\UltimateFields;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `ultimate_video` (Ultimate Addons) → `divi/video`, registered approximate.
 *
 * `modules/ultimate_videos.php` embeds a YouTube or Vimeo player behind a
 * thumbnail with a play button drawn over it. Divi's video module is the same
 * two things: `video.innerContent.desktop.value.src` for the URL and
 * `.thumbnailSrc` for the poster (`video/module.json`,
 * `VideoModule::render_callback()`), with its own play button.
 *
 * What has no Divi field is the player's own options — the play button's
 * source, size and colours, the subscribe bar, and YouTube's `modestbranding`,
 * `rel` and privacy-mode switches — and they are reported.
 */
class UltVideoConverter extends BaseWPBakeryConverter {

    /** The player options the add-on passes straight to YouTube or Vimeo. */
    const REPORTED_PLAYER = [
        'yt_autoplay'          => 'autoplay',
        'yt_sugg_video'        => 'YouTube\'s suggested videos at the end',
        'yt_play_control'      => 'the player controls',
        'yt_mute_control'      => 'starting muted',
        'yt_modest_branding'   => 'YouTube\'s modest branding',
        'yt_privacy_mode'      => 'YouTube\'s privacy-enhanced mode',
        'vimeo_autoplay'       => 'autoplay',
        'vimeo_loop'           => 'looping',
        'vimeo_intro_title'    => 'Vimeo\'s title overlay',
        'vimeo_intro_portrait' => 'Vimeo\'s author portrait',
        'vimeo_intro_byline'   => 'Vimeo\'s byline',
        'vimeo_control_color'  => 'Vimeo\'s control colour',
        'yt_start_time'        => 'a start time',
        'yt_stop_time'         => 'a stop time',
        'vimeo_start_time'     => 'a start time',
        'aspect_ratio'         => 'a fixed aspect ratio',
        'enable_sub_bar'       => 'the YouTube subscribe bar',
    ];

    /** The play-button fields, all drawn by the add-on's own stylesheet. */
    const REPORTED_BUTTON = [
        'play_source'      => 'the play button\'s source',
        'play_icon'        => 'its glyph',
        'play_image'       => 'its image',
        'play_size'        => 'its size',
        'icon_color'       => 'its colour',
        'icon_hover_color' => 'its hover colour',
        'default_color'    => 'the default button colour',
        'default_hover_color' => 'the default button hover colour',
        'hover_animation'  => 'its hover animation',
        'overlay_color'    => 'the overlay over the thumbnail',
    ];

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_ult_video_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        $style    = $this->mapStyle( 'generic', $this->withCss( $node, 'css_video_design' ) );
        $attrs    = $style['divi_attrs'];
        $consumed = array_merge(
            $style['handled_keys'],
            [ 'css_video_design', 'video_setting', 'video_type', 'u_video_url', 'vimeo_video_url', 'video_option', 'playb', 'thum_over', 'thumbnail', 'custom_thumb', 'default_thumb', 'yt_sb_bar', 'yt_sb_setting', 'chanel_id_name', 'yt_channel_name', 'yt_channel_id', 'yt_channel_text', 'show_sub_count', 'yt_text_color', 'yt_background_color', 'padding', 'subscribe_bar_responsive' ],
            array_keys( self::REPORTED_PLAYER ),
            array_keys( self::REPORTED_BUTTON )
        );

        foreach ( array_keys( $atts ) as $key ) {
            if ( is_string( $key ) && str_starts_with( $key, 'main_video_' ) ) {
                $consumed[] = $key;
            }
        }

        $url = trim( $this->att( $atts, 'u_video_url', $this->att( $atts, 'vimeo_video_url' ) ) );

        if ( $url === '' ) {
            $this->engine->logWarning( "ultimate_video {$id} names no video; nothing was written for it." );
            $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

            return [];
        }

        StyleMapper::write( $attrs, 'video.innerContent.desktop.value.src', $url );

        $this->thumbnail( $atts, $id, $attrs );
        $this->report( $atts, $id );

        $this->engine->logConverted( 'video' );
        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        return $this->block( $id, 'divi/video', $attrs );
    }

    /**
     * `thumbnail` is `default` (one of YouTube's own crops, named in
     * `default_thumb`) or `custom` (`custom_thumb`, an `ult_img_single` value).
     * Only the custom one is a URL Divi can be given; the default crop is
     * YouTube's, which Divi fetches itself.
     */
    private function thumbnail( array $atts, string $id, array &$attrs ): void {
        if ( strtolower( trim( $this->att( $atts, 'thumbnail' ) ) ) !== 'custom' ) {
            return;
        }

        $image = UltimateFields::media( $this->att( $atts, 'custom_thumb' ) );
        $url   = $image['url'] ?? '';

        if ( $url !== '' ) {
            StyleMapper::write( $attrs, 'video.innerContent.desktop.value.thumbnailSrc', $url );

            return;
        }

        if ( isset( $image['id'] ) ) {
            $this->engine->logUnresolvedMedia( $id, (int) $image['id'] );
        }
    }

    private function report( array $atts, string $id ): void {
        foreach ( [ 'player' => self::REPORTED_PLAYER, 'button' => self::REPORTED_BUTTON ] as $group => $table ) {
            $described = [];

            foreach ( $table as $key => $what ) {
                $value = trim( $this->att( $atts, $key ) );
                if ( $value !== '' && $value !== 'none' ) {
                    $described[] = $key . '="' . $value . '" (' . $what . ')';
                }
            }

            if ( $described === [] ) {
                continue;
            }

            $this->engine->logNotCarriedOver(
                'addon',
                $id,
                $group === 'player'
                    ? 'ultimate_video passes its own player options to the embed: ' . implode( ', ', $described ) . '; Divi\'s video module embeds the URL as it stands'
                    : 'ultimate_video draws its own play button: ' . implode( ', ', $described ) . '; Divi\'s video module draws its own'
            );
        }

        if ( $this->on( $atts, 'enable_sub_bar' ) ) {
            $this->engine->logNotCarriedOver(
                'integration',
                $id,
                'ultimate_video shows a YouTube subscribe bar under the player, which needs the YouTube Data API; Divi has no equivalent'
            );
        }
    }
}
