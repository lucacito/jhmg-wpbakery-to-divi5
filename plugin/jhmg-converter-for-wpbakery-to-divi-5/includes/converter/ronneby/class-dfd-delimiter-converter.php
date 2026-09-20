<?php

namespace WPBakeryDivi5Converter\Converter\Ronneby;

use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `dfd_delimiter` → `divi/divider`, with a `divi/text` before it for the
 * `with-text` style and a `divi/image` instead of it for `with-image`.
 *
 * `modules/dfd-delimiter.php:386-606` draws one of five things between two
 * halves of a rule: an arrow in a circle, a plain line, an icon, a word, or a
 * picture. The rule itself is `border-bottom-color` / `-width` / `-style` from
 * `delim_line_color`, `delimiter_height` and `delimiter_border_style` (lines
 * 434-443), which is exactly Divi's `divider.advanced.line`
 * (`divider/module.json`, the paths `SeparatorConverter` settled in Task 8).
 *
 * What Divi's divider has nothing for is the thing in the middle: the circled
 * arrow and the icon are drawn from the theme's own icon font inside
 * `.center-arrow`, so those two styles keep the line and report the ornament.
 *
 * **No default bottom margin**: `.dfd-delimier-main-wrapper` /
 * `.dfd-delimier-wrapper` (the theme's own spelling) carry none.
 */
class DfdDelimiterConverter extends RonnebyConverter {

    /** `delimiter_style` (`modules/dfd-delimiter.php:26-58`). */
    const STYLE_ARROW = 'dfd-delimiter-with-arrow';
    const STYLE_LINE  = 'dfd-delimiter-with-line';
    const STYLE_ICON  = 'dfd-delimiter-with-icon';
    const STYLE_TEXT  = 'dfd-delimiter-with-text';
    const STYLE_IMAGE = 'dfd-delimiter-with-image';

    /** The ring and its icon: drawn on `.center-arrow`, which Divi's divider has no part for. */
    const REPORTED_ORNAMENT = [
        'icon_size'                     => 'the size of the glyph in the middle',
        'icon_color'                    => 'its colour',
        'icon_hover_color'              => 'its hover colour',
        'content_bg_color'              => 'the fill of the ring around it',
        'content_bg_hover_color'        => 'that fill on hover',
        'delim_circle_line_color'       => 'the ring\'s border colour',
        'delim_hover_circle_line_color' => 'that border on hover',
        'show_icon'                     => 'whether the picker\'s icon replaces the built-in one',
    ];

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_dfd_delimiter_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        $style    = $this->mapStyle( 'generic', $node, 'none' );
        $attrs    = $style['divi_attrs'];
        $consumed = array_merge( $style['handled_keys'], [ 'delimiter_style' ] );

        // `'value' => 'dfd-delimiter-with-arrow'` (line 58).
        $delimiter = trim( $this->att( $atts, 'delimiter_style', self::STYLE_ARROW ) );

        $this->alignment( $atts, $attrs, $consumed );
        $this->reportAnimation( $atts, $id, $consumed );

        $blocks = [];

        if ( $delimiter === self::STYLE_IMAGE ) {
            $image = $this->image( $atts, $id, $attrs, $consumed );
            $this->claimUnused( $atts, $id, $delimiter, $consumed );
            $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

            return $image ?? [];
        }

        if ( $delimiter === self::STYLE_TEXT ) {
            $text = $this->text( $atts, $id, $consumed );
            if ( $text !== null ) {
                $blocks[] = $text;
            }
        }

        $this->line( $atts, $id, $attrs, $consumed );
        $this->engine->logConverted( 'divider' );
        $blocks[] = $this->block( $id, 'divi/divider', $attrs );

        $this->claimUnused( $atts, $id, $delimiter, $consumed );
        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        return count( $blocks ) === 1 ? $blocks[0] : $blocks;
    }

    /**
     * The rule: `border-bottom-color`, `-width` and `-style` on `.line`
     * (`dfd-delimiter.php:434-443`).
     *
     * @param string[] $consumed
     */
    private function line( array $atts, string $id, array &$attrs, array &$consumed ): void {
        $consumed = array_merge( $consumed, [ 'delim_line_color', 'delimiter_height', 'delimiter_border_style' ] );

        StyleMapper::write( $attrs, 'divider.advanced.line.desktop.value.show', 'on' );

        $color = $this->color( $atts, 'delim_line_color', $id );
        if ( $color !== null ) {
            StyleMapper::write( $attrs, 'divider.advanced.line.desktop.value.color', $color );
        }

        $weight = $this->pixels( $atts, 'delimiter_height' );
        if ( $weight !== '' ) {
            StyleMapper::write( $attrs, 'divider.advanced.line.desktop.value.weight', $weight );
        }

        $style = strtolower( trim( $this->att( $atts, 'delimiter_border_style' ) ) );
        if ( $style !== '' ) {
            StyleMapper::write( $attrs, 'divider.advanced.line.desktop.value.style', $style );
        }
    }

    /**
     * `align_image` — `text-align` on the wrapper (line 480), which is where
     * Divi's divider takes its own alignment.
     *
     * @param string[] $consumed
     */
    private function alignment( array $atts, array &$attrs, array &$consumed ): void {
        $consumed[] = 'align_image';

        $align = strtolower( trim( $this->att( $atts, 'align_image' ) ) );
        if ( in_array( $align, [ 'left', 'center', 'right' ], true ) ) {
            StyleMapper::write( $attrs, 'module.decoration.sizing.desktop.value.alignment', $align );
        }
    }

    /**
     * `dfd-delimiter-with-text`: the word between the two halves of the rule,
     * in the typography `title_font_options` carries (lines 490-513).
     *
     * @param string[] $consumed
     */
    private function text( array $atts, string $id, array &$consumed ): ?array {
        $consumed = array_merge( $consumed, [ 'text_delimiter', 'text_color', 'text_bg_color' ] );

        // `$text_delimiter = empty($text_delimiter) ? "Delimiter text" : …`
        $text = trim( $this->att( $atts, 'text_delimiter', 'Delimiter text' ) );

        $attrs = [];

        $this->applyFontSet(
            $atts,
            [ 'options' => 'title_font_options', 'toggle' => 'use_google_fonts', 'family' => 'custom_fonts' ],
            'content.decoration.bodyFont.body.font',
            $id,
            $attrs,
            $consumed
        );
        $this->applyResponsiveText( $atts, 'delimiter_text_responsive', 'content.decoration.bodyFont.body.font', $id, $attrs, $consumed );

        $color = $this->color( $atts, 'text_color', $id );
        if ( $color !== null ) {
            StyleMapper::write( $attrs, 'content.decoration.bodyFont.body.font.desktop.value.color', $color );
        }

        // `background-color: …; padding: 8px;` (lines 505-509).
        $background = $this->color( $atts, 'text_bg_color', $id );
        if ( $background !== null ) {
            StyleMapper::write( $attrs, 'module.decoration.background.desktop.value.color', $background );
            StyleMapper::write( $attrs, 'module.decoration.spacing.desktop.value.padding', self::box( '8px', '8px', '8px', '8px' ) );
        }

        StyleMapper::write( $attrs, 'content.innerContent.desktop.value', $text );

        $this->engine->logConverted( 'text' );

        return $this->block( $id . '-text', 'divi/text', $attrs );
    }

    /**
     * `dfd-delimiter-with-image`: the whole delimiter is one picture
     * (lines 563-582), so it becomes an image module rather than a divider.
     *
     * @param string[] $consumed
     */
    private function image( array $atts, string $id, array $attrs, array &$consumed ): ?array {
        $consumed[] = 'image';
        $consumed[] = 'repeat_image';

        $attachment = (int) preg_replace( '/[^\d]/', '', $this->att( $atts, 'image' ) );
        $url        = $attachment > 0 ? $this->attachmentUrl( $attachment, 'full', $id ) : null;

        if ( $url === null ) {
            $this->engine->logWarning( "dfd_delimiter {$id} is an image delimiter with no picture the converter could resolve; nothing was written for it." );

            return null;
        }

        if ( $this->att( $atts, 'repeat_image' ) === 'show' ) {
            $this->engine->logNotCarriedOver(
                'background',
                $id,
                'repeat_image tiles the delimiter picture across the row as a background; Divi\'s image module draws it once'
            );
        }

        StyleMapper::write( $attrs, 'image.innerContent.desktop.value.src', $url );
        StyleMapper::write( $attrs, 'image.innerContent.desktop.value.id', (string) $attachment );

        $align = strtolower( trim( $this->att( $atts, 'align_image' ) ) );
        if ( in_array( $align, [ 'left', 'center', 'right' ], true ) ) {
            StyleMapper::write( $attrs, 'module.advanced.align.desktop.value', $align );
        }

        $this->engine->logConverted( 'image' );

        return $this->block( $id, 'divi/image', $attrs );
    }

    /**
     * The fields the form fills whichever style is chosen, and the ornament in
     * the middle of the rule.
     *
     * @param string[] $consumed
     */
    private function claimUnused( array $atts, string $id, string $delimiter, array &$consumed ): void {
        $this->pickedIcon( $atts, $consumed );

        // Reported only for the two styles that actually draw an ornament.
        if ( in_array( $delimiter, [ self::STYLE_ARROW, self::STYLE_ICON, '' ], true ) ) {
            $this->reportGroup(
                $atts,
                self::REPORTED_ORNAMENT,
                'layout',
                $id,
                sprintf( 'delimiter_style="%s" draws a glyph in a circle in the middle of the rule; Divi\'s divider is the rule alone', $delimiter !== '' ? $delimiter : self::STYLE_ARROW ),
                $consumed
            );

            $icon = $this->att( $atts, 'icon_' . $this->att( $atts, 'icon_font' ) );
            if ( $icon !== '' ) {
                $this->engine->logNotCarriedOver( 'layout', $id, sprintf( 'the delimiter\'s icon is "%s"; add an icon module beside the divider to keep it', $icon ) );
            }

            return;
        }

        $consumed = array_merge( $consumed, array_keys( self::REPORTED_ORNAMENT ) );

        // The text and image styles fill the typography fields too.
        $consumed = array_merge( $consumed, [ 'text_delimiter', 'text_color', 'text_bg_color', 'title_font_options', 'use_google_fonts', 'custom_fonts', 'delimiter_text_responsive', 'image', 'repeat_image' ] );
    }
}
