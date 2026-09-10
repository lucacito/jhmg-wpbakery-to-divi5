<?php

namespace WPBakeryDivi5Converter\Converter\Ronneby;

use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `new_team_member` → `divi/team-member`.
 *
 * `modules/dfd-team-member.php:436-560` prints a portrait, a name, a job title,
 * a description and a row of social links — which is Divi's team member exactly:
 * `image.innerContent.desktop.value.url`, `name.innerContent`,
 * `position.innerContent`, `content.innerContent` and
 * `social.innerContent.desktop.value.{facebookUrl,twitterUrl,linkedinUrl}`
 * (`TeamMemberModule.php:685-790`).
 *
 * The social links are the difference in scale: Ronneby offers 41 networks as
 * one flat attribute each (`$this->social_networks`, lines 11-52 — `facebook`,
 * `dribbble`, `evernote`, …) and Divi's team member has three. The three
 * convert; the rest are reported with their URLs, because a team member is not
 * a social-follow module and turning it into one would lose the portrait.
 *
 * **No default bottom margin**: `.dfd-team-member` carries none.
 */
class NewTeamMemberConverter extends RonnebyConverter {

    /** The three networks `TeamMemberModule.php:765-769` draws. */
    const NETWORKS = [ 'facebook', 'twitter', 'linkedin' ];

    /**
     * Every other network `dfd-team-member.php:11-52` maps, so a URL in one of
     * them is claimed and reported rather than left as a skipped setting.
     */
    const OTHER_NETWORKS = [
        'deviantart', 'digg', 'dribbble', 'dropbox', 'evernote', 'flickr', 'foursquare',
        'google', 'instagram', 'last_fm', 'livejournal', 'picasa', 'pinterest', 'rss',
        'tumblr', 'vimeo', 'wordpress', 'youtube', 'px_500', 'mail', 'viewbug', 'vkontakte',
        'xing', 'spotify', 'houzz', 'skype', 'slideshare', 'bandcamp', 'soundcloud',
        'meerkat', 'periscope', 'snapchat', 'thecity', 'behance', 'microsoft_pinpoint', 'viadeo',
    ];

    /** The card's frame and its hover wash, drawn by the theme. */
    const REPORTED_LOOK = [
        'main_layout'          => 'which of the card layouts is drawn',
        'gradient_color1'      => 'the first stop of the wash over the portrait',
        'gradient_color2'      => 'its second stop',
        'full_width_overlay'   => 'stretching that wash across the card',
        'shadow'               => 'a drop shadow on the card',
        'shadow_style'         => 'whether that shadow is permanent or on hover',
        'soc_icons_hover'      => 'which of the social-icon hover animations is played',
        'line_width'           => 'the width of the rule under the name',
        'line_border'          => 'its thickness',
        'line_color'           => 'its colour',
        'line_hide'            => 'whether it is drawn at all',
        'team_member_img_width'  => 'the crop width of the portrait',
        'team_member_img_height' => 'its crop height',
        'title_t_heading'      => 'an editor section label',
        'subtitle_t_heading'   => 'an editor section label',
        'content_t_heading'    => 'an editor section label',
        'thumb_t_heading'      => 'an editor section label',
        'subtitle_h_heading'   => 'an editor section label',
        'subtitle_d_heading'   => 'an editor section label',
    ];

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_team_member_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        $style    = $this->mapStyle( 'generic', $node, 'none' );
        $attrs    = $style['divi_attrs'];
        $consumed = array_merge( $style['handled_keys'], [
            'team_member_name', 'team_member_job_position', 'team_member_description', 'team_member_photo', 'thumb_radius', 'tutorials',
        ] );

        StyleMapper::write( $attrs, 'name.innerContent.desktop.value', $this->att( $atts, 'team_member_name' ) );
        StyleMapper::write( $attrs, 'position.innerContent.desktop.value', $this->att( $atts, 'team_member_job_position' ) );
        StyleMapper::write(
            $attrs,
            'content.innerContent.desktop.value',
            trim( $this->nestedShortcodes( $this->editorHtml( $this->att( $atts, 'team_member_description' ) ), $id ) )
        );

        $this->portrait( $atts, $id, $attrs );
        $this->fonts( $atts, $id, $attrs, $consumed );
        $this->social( $atts, $id, $attrs, $consumed );
        $this->memberLink( $atts, $id, $attrs, $consumed );

        $this->reportAnimation( $atts, $id, $consumed );
        $this->reportGroup( $atts, self::REPORTED_LOOK, 'layout', $id, 'new_team_member draws its card with the theme\'s own classes', $consumed );

        $this->engine->logConverted( 'team-member' );
        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        return $this->block( $id, 'divi/team-member', $attrs );
    }

    /** `wp_get_attachment_image_src( $team_member_photo, 'large' )` (line 559). */
    private function portrait( array $atts, string $id, array &$attrs ): void {
        $attachment = (int) preg_replace( '/[^\d]/', '', $this->att( $atts, 'team_member_photo' ) );
        if ( $attachment <= 0 ) {
            return;
        }

        $url = $this->attachmentUrl( $attachment, 'large', $id );
        if ( $url !== null ) {
            StyleMapper::write( $attrs, 'image.innerContent.desktop.value.url', $url );
        }

        // `style="border-radius:<thumb_radius>px"` on the portrait (line 556).
        $radius = $this->pixels( $atts, 'thumb_radius' );
        if ( $radius !== '' ) {
            StyleMapper::write( $attrs, 'image.decoration.border.desktop.value.radius', self::radius( $radius ) );
        }
    }

    /** @param string[] $consumed */
    private function fonts( array $atts, string $id, array &$attrs, array &$consumed ): void {
        $this->applyFontSet(
            $atts,
            [ 'options' => 'title_font_options', 'toggle' => 'use_google_fonts', 'family' => 'custom_fonts' ],
            'name.decoration.font.font',
            $id,
            $attrs,
            $consumed,
            true
        );

        // The subtitle and the description have no Google-font pair of their
        // own on this element (`dfd-team-member.php:519, 549` pass none).
        $this->applyFontSet( $atts, [ 'options' => 'subtitle_font_options' ], 'position.decoration.font.font', $id, $attrs, $consumed );
        $this->applyFontSet( $atts, [ 'options' => 'font_options' ], 'content.decoration.bodyFont.body.font', $id, $attrs, $consumed );
    }

    /**
     * The social links: three go on the module, the rest are reported.
     *
     * `is_email( $url )` makes the module write a `mailto:` (line 481), which is
     * kept here for the same reason.
     *
     * @param string[] $consumed
     */
    private function social( array $atts, string $id, array &$attrs, array &$consumed ): void {
        $consumed = array_merge( $consumed, self::NETWORKS, self::OTHER_NETWORKS );

        $links = [];
        foreach ( self::NETWORKS as $network ) {
            $url = trim( $this->att( $atts, $network ) );
            if ( $url !== '' ) {
                $links[ $network . 'Url' ] = $this->mailto( $url );
            }
        }

        if ( $links !== [] ) {
            StyleMapper::write( $attrs, 'social.innerContent.desktop.value', $links );
        }

        $others = [];
        foreach ( self::OTHER_NETWORKS as $network ) {
            $url = trim( $this->att( $atts, $network ) );
            if ( $url !== '' ) {
                $others[] = $network . '="' . $url . '"';
            }
        }

        if ( $others === [] ) {
            return;
        }

        $this->engine->logNotCarriedOver(
            'integration',
            $id,
            'Divi\'s team member holds a Facebook, a Twitter and a LinkedIn link; these accounts have no field on it and were not carried over: ' . implode( ', ', $others )
        );
    }

    /**
     * `enable_custom_link` + `apply_link_to` — the card links to the member's
     * own page from the title, the portrait or both (lines 466-472, 499-505).
     * Divi's team member has one module link.
     *
     * @param string[] $consumed
     */
    private function memberLink( array $atts, string $id, array &$attrs, array &$consumed ): void {
        $consumed = array_merge( $consumed, [ 'enable_custom_link', 'apply_link_to', 'custtom_link_url' ] );

        if ( ! $this->on( $atts, 'enable_custom_link' ) ) {
            return;
        }

        $link = $this->link( $atts, 'custtom_link_url' );
        if ( $link === [] ) {
            return;
        }

        $this->reportLinkExtras( $atts, 'custtom_link_url', $id, true );

        StyleMapper::write( $attrs, 'module.advanced.link.desktop.value', [
            'url'    => $link['url'],
            'target' => ( $link['target'] ?? '' ) === '_blank' ? 'on' : 'off',
        ] );

        $applies = strtolower( trim( $this->att( $atts, 'apply_link_to' ) ) );
        if ( $applies !== '' && $applies !== 'both-title-and-image' ) {
            $this->engine->logNotCarriedOver(
                'layout',
                $id,
                sprintf( 'apply_link_to="%s" links only that part of the card; Divi\'s team member links the whole module', $applies )
            );
        }
    }

    /**
     * `if(is_email( ${$soc_network} )) { … 'mailto:' … }`
     * (`dfd-team-member.php:481`): a plain address is a mail link, not a URL.
     *
     * WordPress's own `is_email()` is used where it is loaded; from a export
     * the same shape is matched here, so a converted mail link is a mail link
     * either way.
     */
    private function mailto( string $url ): string {
        $is_email = function_exists( 'is_email' )
            ? (bool) is_email( $url )
            : preg_match( '/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $url ) === 1;

        return $is_email ? 'mailto:' . $url : $url;
    }
}
