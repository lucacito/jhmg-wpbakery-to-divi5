<?php

namespace WPBakeryDivi5Converter\Converter\Ronneby;

use WPBakeryDivi5Converter\Helpers\PackedParams;
use WPBakeryDivi5Converter\Helpers\RonnebyParams;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `dfd_new_social_accounts` → `divi/social-media-follow` › one
 * `divi/social-media-follow-network` per account.
 *
 * `modules/dfd_new_social_accoun_module.php:80-143` is a `param_group` of
 * `{ dfd_social_networks_sel, soc_url }` pairs, where the first is the CSS class
 * of the theme's own icon font and the second a `vc_link`. Divi's module is the
 * same list, addressed by network slug:
 * `socialNetwork.innerContent.desktop.value.{title,link}` on each child, with
 * `icon.advanced.{color,size}` and `module.decoration.background` for the disc
 * (`social-media-follow-item/module.json` — which declares the block
 * `divi/social-media-follow-network`, amendment §1 — and
 * `SocialMediaFollowItemModule.php:355-560` for the slug table).
 *
 * Ronneby offers 41 networks and Divi 50, but they are not the same 50: Digg,
 * Dropbox, Evernote, LiveJournal, Picasa, WordPress, 500px, ViewBug, Slideshare,
 * Meerkat, The City, Microsoft Pinpoint, Viadeo and a plain mail link have no
 * Divi network, so those accounts are reported with their URLs rather than
 * written as some other network's icon.
 *
 * **No default bottom margin**: `.dfd-new-social-icons` carries none.
 */
class DfdSocialAccountsConverter extends RonnebyConverter {

    /**
     * Ronneby's stored icon class ⇒ Divi's network slug.
     *
     * Left: the values of the `dfd_social_networks_sel` dropdown
     * (`dfd_new_social_accoun_module.php:88-131`) plus `soc_icon-twitter-3`,
     * which older exports carry. Right: the keys of
     * `SocialMediaFollowItemModule::get_social_networks()`. A network with no
     * entry here has no Divi equivalent.
     */
    const NETWORKS = [
        'dfd-added-icon-tiktok-icon'          => 'tiktok',
        'soc_icon-deviantart'                 => 'deviantart',
        'soc_icon-dribbble'                   => 'dribbble',
        'soc_icon-facebook'                   => 'facebook',
        'soc_icon-flickr'                     => 'flikr',
        'soc_icon-foursquare_2'               => 'foursquare',
        'soc_icon-google__x2B_'               => 'google',
        'soc_icon-instagram'                  => 'instagram',
        'soc_icon-last_fm'                    => 'last_fm',
        'soc_icon-linkedin'                   => 'linkedin',
        'soc_icon-pinterest'                  => 'pinterest',
        'soc_icon-rss'                        => 'rss',
        'soc_icon-tumblr'                     => 'tumblr',
        'dfd-added-icon-twitter-x-logo'       => 'twitter',
        'soc_icon-twitter-3'                  => 'twitter',
        'soc_icon-vimeo'                      => 'vimeo',
        'soc_icon-youtube'                    => 'youtube',
        'soc_icon-rus-vk-02'                  => 'vk',
        'dfd-added-font-icon-b_Xing-icon_bl'  => 'xing',
        'dfd-added-font-icon-c_spotify-512-black' => 'spotify',
        'dfd-added-font-icon-houzz-dark-icon' => 'houzz',
        'dfd-added-font-icon-skype'           => 'skype',
        'dfd-added-font-icon-bandcamp-logo'   => 'bandcamp',
        'dfd-added-font-icon-soundcloud-logo' => 'soundcloud',
        'dfd-added-font-icon-periscope-logo'  => 'periscope',
        'dfd-added-font-icon-Snapchat-logo'   => 'snapchat',
        'soc_icon-behance'                    => 'behance',
        'dfd-added-font-icon-tripadvisor'     => 'tripadvisor',
    ];

    /** `info_alignment` ⇒ the module's text orientation. */
    const ALIGNMENTS = [
        'text-left'   => 'left',
        'text-center' => 'center',
        'text-right'  => 'right',
    ];

    /** The theme's own hover choreography and frame. */
    const REPORTED_LOOK = [
        'main_style'           => 'which of the twelve icon styles is drawn',
        'main_layout'          => 'the layout of an older Ronneby build',
        'sliding_direction'    => 'the direction the hover fill slides from',
        'icon_margin'          => 'the gap between the icons',
        'general_border_width' => 'a border around the whole row',
        'general_border_color' => 'its colour',
    ];

    /** Colours the module paints only under the cursor. */
    const REPORTED_HOVER = [
        'customizable_hover_colors'   => 'whether the hover colours are the theme\'s or these',
        'icon_hover_color'            => 'the icon colour',
        'icon_hover_background_color' => 'the disc behind it',
    ];

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_dfd_social_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        $style    = $this->mapStyle( 'generic', $node, 'none' );
        $attrs    = $style['divi_attrs'];
        $consumed = array_merge( $style['handled_keys'], [ 'dfd_social_networks', 'tutorials' ] );

        $consumed[] = 'info_alignment';
        $align      = self::ALIGNMENTS[ strtolower( $this->att( $atts, 'info_alignment' ) ) ] ?? null;
        if ( $align !== null ) {
            StyleMapper::write( $attrs, 'module.advanced.text.text.desktop.value.orientation', $align );
        }

        $item_settings = $this->itemSettings( $atts, $id, $consumed );
        $networks      = $this->networks( $atts, $id, $item_settings, $consumed );

        $this->reportAnimation( $atts, $id, $consumed );
        $this->reportGroup( $atts, self::REPORTED_LOOK, 'layout', $id, 'dfd_new_social_accounts draws its icons with the theme\'s own classes', $consumed );
        $this->reportGroup( $atts, self::REPORTED_HOVER, 'hover', $id, 'dfd_new_social_accounts repaints its icons under the cursor; set these on the Divi module\'s hover state', $consumed );

        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        if ( $networks === [] ) {
            $this->engine->logWarning( "dfd_new_social_accounts {$id} holds no accounts Divi has a network for; nothing was written for it." );

            return [];
        }

        $this->engine->logConverted( 'social-media-follow' );

        return $this->block( $id, 'divi/social-media-follow', $attrs, $networks );
    }

    /**
     * The look every icon shares: `icon_color`, `icon_font_size`,
     * `icon_background_color` and the border `icon_border` switches on
     * (lines 176-260). Divi holds all four on the child, so they are built once
     * and copied onto each one.
     *
     * @param string[] $consumed
     * @return array<string,mixed>
     */
    private function itemSettings( array $atts, string $id, array &$consumed ): array {
        $consumed = array_merge( $consumed, [
            'icon_color', 'icon_font_size', 'icon_background_color',
            'icon_border', 'border_width', 'border_color', 'border_radius',
        ] );

        $settings = [];

        $color = $this->color( $atts, 'icon_color', $id );
        if ( $color !== null ) {
            StyleMapper::write( $settings, 'icon.advanced.color.desktop.value', $color );
        }

        $size = $this->pixels( $atts, 'icon_font_size' );
        if ( $size !== '' ) {
            StyleMapper::write( $settings, 'icon.advanced.size.desktop.value', $size );
        }

        $background = $this->color( $atts, 'icon_background_color', $id );
        if ( $background !== null ) {
            StyleMapper::write( $settings, 'module.decoration.background.desktop.value.color', $background );
        }

        $radius = $this->pixels( $atts, 'border_radius' );
        if ( $radius !== '' ) {
            StyleMapper::write( $settings, 'module.decoration.border.desktop.value.radius', self::radius( $radius ) );
        }

        // `'value' => array('ic_border')` — the border fields only apply when
        // the switch is on (lines 237-256).
        if ( trim( $this->att( $atts, 'icon_border' ) ) === 'ic_border' ) {
            $border = $this->color( $atts, 'border_color', $id );
            $width  = $this->pixels( $atts, 'border_width' );

            if ( $border !== null || $width !== '' ) {
                StyleMapper::write( $settings, 'module.decoration.border.desktop.value.styles.all', array_filter( [
                    'width' => $width,
                    'style' => 'solid',
                    'color' => $border ?? '',
                ], static fn( string $value ): bool => $value !== '' ) );
            }
        }

        return $settings;
    }

    /**
     * One child per account, from the `dfd_social_networks` param group or from
     * the flat `soc_account_<code>` attributes an older build wrote.
     *
     * @param array<string,mixed> $item_settings
     * @param string[]            $consumed
     * @return array<int, array<string,mixed>>
     */
    private function networks( array $atts, string $id, array $item_settings, array &$consumed ): array {
        $accounts = [];

        foreach ( RonnebyParams::items( $this->att( $atts, 'dfd_social_networks' ) ) as $row ) {
            $accounts[] = [
                'icon' => trim( (string) ( $row['dfd_social_networks_sel'] ?? '' ) ),
                'url'  => PackedParams::link( (string) ( $row['soc_url'] ?? '' ) )['url'],
            ];
        }

        // The older build's flat fields: `soc_account_fb="url:…"`, one per
        // network, with no icon class of their own.
        $legacy = [];
        foreach ( array_keys( $atts ) as $key ) {
            if ( is_string( $key ) && str_starts_with( $key, 'soc_account_' ) ) {
                $consumed[] = $key;
                $url        = PackedParams::link( $this->att( $atts, $key ) )['url'];
                if ( $url !== '' ) {
                    $legacy[] = $key . '="' . $url . '"';
                }
            }
        }

        if ( $legacy !== [] ) {
            $this->engine->logNotCarriedOver(
                'addon',
                $id,
                'this element stores its accounts in an older Ronneby build\'s per-network fields (' . implode( ', ', $legacy )
                    . '), which name the network by a two-letter code rather than by an icon; add them to the Divi module by hand'
            );
        }

        $blocks  = [];
        $unknown = [];
        $index   = 0;

        foreach ( $accounts as $account ) {
            if ( $account['icon'] === '' ) {
                continue;
            }

            $network = self::NETWORKS[ $account['icon'] ] ?? null;
            if ( $network === null ) {
                $unknown[] = $account['icon'] . ( $account['url'] !== '' ? ' (' . $account['url'] . ')' : '' );

                continue;
            }

            $index++;
            $settings = $item_settings;
            StyleMapper::write( $settings, 'socialNetwork.innerContent.desktop.value', [
                'title' => $network,
                // `defaultValue: '#'` on the field (`social-media-follow-item/module.json`).
                'link'  => $account['url'] !== '' ? $account['url'] : '#',
            ] );

            $this->engine->logConverted( 'social-media-follow-network' );
            $blocks[] = $this->block( $id . '-network-' . $index, 'divi/social-media-follow-network', $settings );
        }

        if ( $unknown !== [] ) {
            $this->engine->logNotCarriedOver(
                'integration',
                $id,
                'Divi has no network for ' . implode( ', ', $unknown ) . '; those accounts were left out rather than drawn as another network'
            );
        }

        return $blocks;
    }
}
