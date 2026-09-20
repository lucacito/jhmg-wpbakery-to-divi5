<?php

namespace WPBakeryDivi5Converter\Converter\Ronneby;

use WPBakeryDivi5Converter\Helpers\Color;
use WPBakeryDivi5Converter\Helpers\RonnebyParams;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `dfd_heading` → `divi/heading`, plus a `divi/text` for the subtitle and a
 * `divi/divider` or `divi/image` for the delimiter.
 *
 * `modules/dfd_heading_module.php:483-560` prints up to three things inside one
 * wrapper: the title (the element's own content, in the tag
 * `title_font_options` names), the subtitle, and a delimiter that is a line, an
 * icon or an image. Divi has a module for each of the three and none for the
 * three together, so the element becomes the run of blocks it renders as —
 * in the order `include(<style template>)` prints them, which is title,
 * subtitle, delimiter for the styles the corpus uses.
 *
 * The second most common Ronneby element (4,045 across the 96 exports), and the
 * one whose typography carries the most: `title_font_options` and
 * `subtitle_font_options` are on every single one of them.
 *
 * **No default bottom margin** — `.dfd-heading-shortcode` and
 * `.dfd-heading-module-wrap` carry none in `visual-composer.css`, and
 * `.wpb_wrapper h*.widget-title{margin-bottom:0}` (`app.css`) takes the
 * theme's own heading margin off as well. The space around a Ronneby heading
 * comes from `heading_margin` / `subheading_margin`, which are mapped.
 */
class DfdHeadingConverter extends RonnebyConverter {

    /** `content_alignment` is a Bootstrap text class; Divi's font has `textAlign`. */
    const ALIGNMENTS = [
        'text-left'   => 'left',
        'text-center' => 'center',
        'text-right'  => 'right',
    ];

    /**
     * The 14 layout presets `dfd_build_shortcode_style_param()` offers
     * (`modules/dfd_heading_module.php:20-37`): they move the delimiter around
     * the title and stack the parts differently. Divi draws its own modules in
     * document order.
     */
    const REPORTED_LOOK = [
        'style'                => 'which of the 14 heading layouts draws the parts',
        'mobile_alignment'     => 'a different alignment on small screens',
        'alignment_resolution' => 'the width that alignment applies below',
    ];

    /** Attributes the corpus carries that Ronneby Core 1.5.74 does not map for this element. */
    const FOREIGN = [
        'title' => 'a heading title from another Ronneby build; 1.5.74 takes the title from the element\'s content',
    ];

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_dfd_heading_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        $style    = $this->mapStyle( 'heading', $node, 'none' );
        $attrs    = $style['divi_attrs'];
        $consumed = $style['handled_keys'];

        $align = self::ALIGNMENTS[ strtolower( $this->att( $atts, 'content_alignment' ) ) ] ?? null;
        $consumed[] = 'content_alignment';

        $blocks = [];

        $title = $this->title( $node, $atts, $id, $attrs, $align, $consumed );
        if ( $title !== null ) {
            $blocks[] = $title;
        }

        $subtitle = $this->subtitle( $atts, $id, $align, $consumed );
        if ( $subtitle !== null ) {
            $blocks[] = $subtitle;
        }

        $delimiter = $this->delimiter( $atts, $id, $consumed );
        if ( $delimiter !== null ) {
            $blocks[] = $delimiter;
        }

        $this->reportAnimation( $atts, $id, $consumed );
        $this->reportGroup( $atts, self::REPORTED_LOOK, 'layout', $id, 'WPBakery draws this heading with its own template', $consumed );
        $this->reportGroup( $atts, self::FOREIGN, 'addon', $id, 'dfd_heading carries an attribute this converter reads Ronneby Core 1.5.74 for', $consumed );

        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        if ( $blocks === [] ) {
            $this->engine->logWarning( "dfd_heading {$id} has no title, subtitle or delimiter; nothing was written for it." );

            return [];
        }

        return count( $blocks ) === 1 ? $blocks[0] : $blocks;
    }

    /**
     * The title: the element's own content through `wpb_js_remove_wpautop()`
     * (`dfd_heading_module.php:485`), in the tag and typography
     * `title_font_options` carries.
     *
     * @param string[] $consumed
     */
    private function title( array $node, array $atts, string $id, array $attrs, ?string $align, array &$consumed ): ?array {
        $text = trim( $this->rawContent( $node ) );
        if ( $text === '' ) {
            // The typography fields are filled whether or not the element has
            // a title to print.
            $consumed = array_merge( $consumed, [ 'title_font_options', 'title_google_fonts', 'title_custom_fonts', 'title_responsive', 'heading_margin' ] );

            return null;
        }

        $this->applyFontSet(
            $atts,
            [ 'options' => 'title_font_options', 'toggle' => 'title_google_fonts', 'family' => 'title_custom_fonts' ],
            'title.decoration.font.font',
            $id,
            $attrs,
            $consumed,
            true
        );

        $this->applyResponsiveText( $atts, 'title_responsive', 'title.decoration.font.font', $id, $attrs, $consumed );

        if ( $align !== null ) {
            StyleMapper::write( $attrs, 'title.decoration.font.font.desktop.value.textAlign', $align );
        }

        $this->margin( $atts, 'heading_margin', $attrs, $consumed );

        // `wpb_js_remove_wpautop( $content )` — with `$autop` left false, which
        // is `do_shortcode( shortcode_unautop( $content ) )` and adds no
        // paragraphs (include/helpers/helpers.php); the title is one line of
        // text inside the tag the module prints.
        StyleMapper::write( $attrs, 'title.innerContent.desktop.value', $this->nestedShortcodes( $text, $id ) );

        $this->engine->logConverted( 'heading' );

        return $this->block( $id, 'divi/heading', $attrs );
    }

    /**
     * The subtitle, which the module prints as a second block of copy in its
     * own tag (`dfd_heading_module.php:490`) — a `divi/text`, because Divi's
     * heading module holds one string.
     *
     * @param string[] $consumed
     */
    private function subtitle( array $atts, string $id, ?string $align, array &$consumed ): ?array {
        $consumed[] = 'subtitle';

        $text = trim( $this->att( $atts, 'subtitle' ) );
        if ( $text === '' ) {
            // The typography fields are filled whether or not there is a
            // subtitle to print.
            $consumed = array_merge( $consumed, [ 'subtitle_font_options', 'subtitle_google_fonts', 'subtitle_custom_fonts', 'subtitle_responsive', 'subheading_margin' ] );

            return null;
        }

        $attrs = [];

        $this->applyFontSet(
            $atts,
            [ 'options' => 'subtitle_font_options', 'toggle' => 'subtitle_google_fonts', 'family' => 'subtitle_custom_fonts' ],
            'content.decoration.bodyFont.body.font',
            $id,
            $attrs,
            $consumed
        );

        $this->applyResponsiveText( $atts, 'subtitle_responsive', 'content.decoration.bodyFont.body.font', $id, $attrs, $consumed );

        if ( $align !== null ) {
            StyleMapper::write( $attrs, 'content.decoration.bodyFont.body.font.desktop.value.textAlign', $align );
        }

        $this->margin( $atts, 'subheading_margin', $attrs, $consumed );

        // `wp_kses( $subtitle, array('br' => array()) )` — the module allows a
        // line break and nothing else.
        StyleMapper::write( $attrs, 'content.innerContent.desktop.value', $text );

        $this->engine->logConverted( 'text' );

        return $this->block( $id . '-subtitle', 'divi/text', $attrs );
    }

    /**
     * The delimiter. `enable_delimiter` defaults to `yes`
     * (`dfd_heading_module.php:114-123`), and `delimiter_style` chooses between
     * a line whose CSS is in `delimiter_settings`, an icon, and an image
     * (lines 494-560).
     *
     * @param string[] $consumed
     */
    private function delimiter( array $atts, string $id, array &$consumed ): ?array {
        $consumed = array_merge( $consumed, [ 'enable_delimiter', 'delimiter_style', 'delimiter_settings', 'delimiter_margin' ] );

        // The icon fields the delimiter's `icon` style uses, filled by the form
        // whichever style is selected.
        $icon_fields = [
            'icon_type', 'icon', 'icon_size', 'icon_color', 'icon_style', 'icon_color_bg',
            'icon_border_style', 'icon_color_border', 'icon_border_size', 'icon_border_radius',
            'icon_border_spacing', 'icon_img', 'img_width', 'delimiter_image',
        ];
        $consumed    = array_merge( $consumed, $icon_fields );

        // `if($enable_delimiter == 'yes')`, with `vc_map_get_attributes()`
        // filling the param's own `'value' => 'yes'` when the attribute is absent.
        $enabled = array_key_exists( 'enable_delimiter', $atts )
            ? strtolower( trim( (string) $atts['enable_delimiter'] ) ) === 'yes'
            : true;

        if ( ! $enabled ) {
            return null;
        }

        $style = strtolower( trim( $this->att( $atts, 'delimiter_style' ) ) );

        if ( $style === 'icon' ) {
            $this->engine->logNotCarriedOver(
                'layout',
                $id,
                sprintf(
                    'the heading\'s delimiter is an icon (%s) in a ring; Divi\'s divider draws a line — add an icon module under the heading',
                    $this->att( $atts, 'icon' ) !== '' ? $this->att( $atts, 'icon' ) : $this->att( $atts, 'icon_img' )
                )
            );

            return null;
        }

        if ( $style === 'image' ) {
            return $this->delimiterImage( $atts, $id );
        }

        return $this->delimiterLine( $atts, $id );
    }

    /** `$delimiter_style == 'image'`: `wp_get_attachment_image_src($delimiter_image,'full')`. */
    private function delimiterImage( array $atts, string $id ): ?array {
        $attachment = (int) preg_replace( '/[^\d]/', '', $this->att( $atts, 'delimiter_image' ) );
        $url        = $attachment > 0 ? $this->attachmentUrl( $attachment, 'full', $id ) : null;

        if ( $url === null ) {
            return null;
        }

        $attrs = [];
        StyleMapper::write( $attrs, 'image.innerContent.desktop.value.src', $url );
        StyleMapper::write( $attrs, 'image.innerContent.desktop.value.id', (string) $attachment );

        $margin = $this->declarationMargin( $this->att( $atts, 'delimiter_margin' ) );
        if ( $margin !== null ) {
            StyleMapper::write( $attrs, 'module.advanced.spacing.desktop.value.margin', $margin );
        }

        $this->engine->logConverted( 'image' );

        return $this->block( $id . '-delimiter', 'divi/image', $attrs );
    }

    /**
     * The default delimiter: a `<div class="dfd-heading-delimiter">` whose
     * whole appearance is the CSS in `delimiter_settings`
     * (`dfd_heading_module.php:585`, `'value' => 'margin-top:10px;margin-bottom:10px;'`).
     * A fragment that draws no border draws nothing, so nothing is written for it.
     */
    private function delimiterLine( array $atts, string $id ): ?array {
        $declarations = RonnebyParams::declarations( $this->att( $atts, 'delimiter_settings' ) );

        $width = $declarations['border-bottom-width'] ?? '';
        if ( trim( $width ) === '' || trim( $width ) === '0' || trim( $width ) === '0px' ) {
            return null;
        }

        $attrs = [];
        StyleMapper::write( $attrs, 'divider.advanced.line.desktop.value.show', 'on' );
        StyleMapper::write( $attrs, 'divider.advanced.line.desktop.value.weight', $width );

        $line_style = strtolower( trim( $declarations['border-bottom-style'] ?? 'solid' ) );
        StyleMapper::write( $attrs, 'divider.advanced.line.desktop.value.style', $line_style !== '' ? $line_style : 'solid' );

        $color = Color::normalize( $declarations['border-bottom-color'] ?? '' );
        if ( $color !== null ) {
            StyleMapper::write( $attrs, 'divider.advanced.line.desktop.value.color', $color );
        }

        if ( isset( $declarations['width'] ) && trim( $declarations['width'] ) !== '' ) {
            StyleMapper::write( $attrs, 'module.decoration.sizing.desktop.value.width', trim( $declarations['width'] ) );
        }

        $margin = $this->declarationMargin( $this->att( $atts, 'delimiter_margin' ) !== ''
            ? $this->att( $atts, 'delimiter_margin' )
            : $this->att( $atts, 'delimiter_settings' ) );
        if ( $margin !== null ) {
            StyleMapper::write( $attrs, 'module.decoration.spacing.desktop.value.margin', $margin );
        }

        $this->engine->logConverted( 'divider' );

        return $this->block( $id . '-delimiter', 'divi/divider', $attrs );
    }

    /**
     * A `dfd_margins` fragment (`margin-top:20px;margin-bottom:30px;`) on the
     * block's own spacing.
     *
     * @param string[] $consumed
     */
    private function margin( array $atts, string $key, array &$attrs, array &$consumed ): void {
        $consumed[] = $key;

        $margin = $this->declarationMargin( $this->att( $atts, $key ) );
        if ( $margin !== null ) {
            StyleMapper::write( $attrs, 'module.decoration.spacing.desktop.value.margin', $margin );
        }
    }

    /** @return array<string,string>|null */
    private function declarationMargin( string $raw ): ?array {
        $declarations = RonnebyParams::declarations( $raw );

        $sides = [];
        foreach ( [ 'top', 'right', 'bottom', 'left' ] as $side ) {
            $sides[ $side ] = trim( $declarations[ 'margin-' . $side ] ?? '' );
        }

        if ( implode( '', $sides ) === '' ) {
            return null;
        }

        return self::box( $sides['top'], $sides['right'], $sides['bottom'], $sides['left'] );
    }
}
