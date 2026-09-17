<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;
use WPBakeryDivi5Converter\Helpers\PackedParams;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `vc_cta` → `divi/cta` (the deprecated `vc_cta_button` / `vc_cta_button2`
 * arrive here as `vc_cta` after `AttributeNormaliser`).
 *
 * `include/templates/shortcodes/vc_cta.php` is a bordered box holding a
 * heading, a sub-heading, the body copy and a button — which is Divi's CTA
 * module exactly: `title.innerContent`, `content.innerContent` and the button
 * under `button.*` (`cta/module.json`). The one thing Divi has no separate
 * field for is the sub-heading, so `h4` becomes the first line of the content,
 * as an `<h4>` — where WPBakery itself renders it.
 *
 * The box's look comes from `WPBakeryShortCode_Vc_Cta::get_container_color_css()`
 * and `js_composer.min.css`:
 *
 * - `classic` (the default) is `background-color:#f7f7f7;border-color:#f0f0f0`;
 * - `flat` and `custom` paint `custom_background` (default `#f0f0f0`);
 * - `outline` is transparent with a 3 px `custom_border` (default `#d4d4d4`);
 * - `3d` paints the background and puts `box-shadow: 0 5px 0 <custom_border>`
 *   under it (default `#d4d4d4`);
 * - `shape` is the radius: square `0`, rounded `5px`, round `4em`.
 *
 * A palette `color` slug is already `custom_*` values here: `AttributeNormaliser`
 * runs WPBakery's own `convert_cta_classic_color_to_custom()` first.
 *
 * `.vc_do_cta3{padding:28px;margin-bottom:35px}` — the element's default design
 * options (`config/buttons/shortcode-vc-cta.php` `$default_values`, written to
 * the per-page sheet by `Vc_Base::get_css_from_shortcode_params()`), so the box
 * carries 28 px of padding and the 35 px `content` margin.
 */
class CtaConverter extends BaseWPBakeryConverter {

    /** `.vc_general.vc_cta3.vc_cta3-shape-<shape>{border-radius:…}`. */
    const SHAPES = [
        'square'  => '0px',
        'rounded' => '5px',
        'round'   => '4em',
    ];

    /** `$default_values` in `config/buttons/shortcode-vc-cta.php`. */
    const DEFAULT_PADDING = '28px';

    /** `.vc_general.vc_cta3.vc_cta3-style-classic{background-color:#f7f7f7;border-color:#f0f0f0}`. */
    const CLASSIC_BACKGROUND = '#f7f7f7';
    const CLASSIC_BORDER     = '#f0f0f0';

    /** `get_flat_style_css()` / `get_custom_style_css()`: `vc_get_css_color( 'background-color', '#f0f0f0' )`. */
    const DEFAULT_BACKGROUND = '#f0f0f0';

    /** `get_outline_style_css()` / `get_3d_style_css()`: `border-color: #d4d4d4` / `box-shadow: 0 5px 0 #d4d4d4`. */
    const DEFAULT_BORDER = '#d4d4d4';

    public function convert( array $node ): array {
        $id      = (string) ( $node['id'] ?? uniqid( 'wbdc_cta_' ) );
        $atts    = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];
        $content = (string) ( $node['content'] ?? '' );

        $style    = $this->mapStyle( 'cta', $node );
        $attrs    = $style['divi_attrs'];
        $consumed = array_merge( $style['handled_keys'], [ 'h2', 'h4', 'txt_align', 'el_width' ] );

        StyleMapper::write( $attrs, 'title.innerContent.desktop.value', $this->att( $atts, 'h2' ) );
        StyleMapper::write( $attrs, 'content.innerContent.desktop.value', $this->body( $atts, $content, $id ) );

        $this->look( $atts, $id, $attrs, $consumed );
        $this->alignment( $atts, $attrs );
        $this->width( $atts, $attrs );
        $this->fonts( $atts, $id, $attrs, $consumed );
        $this->button( $atts, $id, $attrs, $consumed );
        $this->icon( $atts, $id, $consumed );

        $this->engine->logConverted( 'cta' );
        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        return $this->block( $id, 'divi/cta', $attrs );
    }

    /**
     * `<h4>` above the body copy, exactly where `vc_cta.php` prints it —
     * inside `.vc_cta3-content-header`, immediately after the `h2`.
     */
    private function body( array $atts, string $content, string $id ): string {
        $html = trim( $this->nestedShortcodes( $this->editorHtml( $content ), $id ) );

        $sub = trim( $this->att( $atts, 'h4' ) );
        if ( $sub !== '' ) {
            $html = '<h4>' . $sub . '</h4>' . $html;
        }

        return $html;
    }

    /**
     * The five `style` branches, with the defaults WPBakery's own template
     * fills in when the colorpickers are blank.
     *
     * @param string[] $consumed
     */
    private function look( array $atts, string $id, array &$attrs, array &$consumed ): void {
        $consumed = array_merge( $consumed, [ 'style', 'shape', 'custom_background', 'custom_text', 'custom_border', 'color', 'text_color' ] );

        $style = strtolower( $this->att( $atts, 'style', 'classic' ) );

        $radius = self::SHAPES[ strtolower( $this->att( $atts, 'shape', 'rounded' ) ) ] ?? self::SHAPES['rounded'];
        StyleMapper::write( $attrs, 'module.decoration.border.desktop.value.radius', self::radius( $radius ) );

        StyleMapper::write( $attrs, 'module.decoration.spacing.desktop.value.padding', $this->padding( $attrs ) );

        $background = $this->color( $atts, 'custom_background', $id );
        $border     = $this->color( $atts, 'custom_border', $id );
        $text       = $this->color( $atts, 'custom_text', $id );

        switch ( $style ) {
            case 'outline':
                StyleMapper::write( $attrs, 'module.decoration.background.desktop.value.color', 'rgba(0,0,0,0)' );
                $this->border( $attrs, $border ?? self::DEFAULT_BORDER, '3px' );
                break;

            case '3d':
                StyleMapper::write( $attrs, 'module.decoration.background.desktop.value.color', $background ?? self::DEFAULT_BACKGROUND );
                StyleMapper::write( $attrs, 'module.decoration.boxShadow.desktop.value', [
                    'style'      => 'preset1',
                    'horizontal' => '0px',
                    'vertical'   => '5px',
                    'blur'       => '0px',
                    'spread'     => '0px',
                    'position'   => 'outer',
                    'color'      => $border ?? self::DEFAULT_BORDER,
                ] );
                break;

            case 'flat':
            case 'custom':
                StyleMapper::write( $attrs, 'module.decoration.background.desktop.value.color', $background ?? self::DEFAULT_BACKGROUND );
                if ( $border !== null ) {
                    $this->border( $attrs, $border, '1px' );
                }
                break;

            default:
                StyleMapper::write( $attrs, 'module.decoration.background.desktop.value.color', $background ?? self::CLASSIC_BACKGROUND );
                $this->border( $attrs, $border ?? self::CLASSIC_BORDER, '1px' );
        }

        if ( $text !== null ) {
            StyleMapper::write( $attrs, 'title.decoration.font.font.desktop.value.color', $text );
        }

        // `get_text_css()` ("B.C prior 9.0"): a pre-9.0 call to action put its
        // body colour in `text_color`, painted inline on `.vc_cta3-content`.
        $body = $this->color( $atts, 'text_color', $id );
        if ( $body !== null ) {
            StyleMapper::write( $attrs, 'content.decoration.bodyFont.body.font.desktop.value.color', $body );
        }
    }

    private function border( array &$attrs, string $color, string $width ): void {
        StyleMapper::write( $attrs, 'module.decoration.border.desktop.value.styles.all', [
            'width' => $width,
            'style' => 'solid',
            'color' => $color,
        ] );
    }

    /** The 28 px the default design options paint, unless the element's own `css` set a side. */
    private function padding( array $attrs ): array {
        $padding = $this->read( $attrs, 'module.decoration.spacing.desktop.value.padding' );
        $padding = is_array( $padding ) ? $padding : [];

        foreach ( [ 'top', 'right', 'bottom', 'left' ] as $side ) {
            if ( ! isset( $padding[ $side ] ) || $padding[ $side ] === '' ) {
                $padding[ $side ] = self::DEFAULT_PADDING;
            }
        }

        return $padding + [ 'syncVertical' => 'off', 'syncHorizontal' => 'off' ];
    }

    /** `.vc_cta3-align-<txt_align> .vc_cta3-content{text-align:…}` → the module's text orientation. */
    private function alignment( array $atts, array &$attrs ): void {
        $align = strtolower( $this->att( $atts, 'txt_align', 'left' ) );

        if ( in_array( $align, [ 'left', 'center', 'right', 'justify' ], true ) ) {
            StyleMapper::write( $attrs, 'module.advanced.text.text.desktop.value.orientation', $align );
        }
    }

    /** `get_section_width_css()`: `width: <el_width>%` on the section around the box. */
    private function width( array $atts, array &$attrs ): void {
        $width = trim( $this->att( $atts, 'el_width' ) );

        if ( preg_match( '/^\d+$/', $width ) === 1 && (int) $width > 0 && (int) $width < 100 ) {
            StyleMapper::write( $attrs, 'module.decoration.sizing.desktop.value.width', $width . '%' );
        }
    }

    /**
     * `use_custom_fonts_h2` hands the heading to `vc_custom_heading` under an
     * `h2_` prefix (`vc_map_integrate_shortcode`); Divi's CTA title takes the
     * same font settings, so the packed `font_container` and `google_fonts`
     * are applied to `title.decoration.font.font`. The sub-heading is inside
     * the content string and has no field of its own, so `h4_*` is reported.
     *
     * @param string[] $consumed
     */
    private function fonts( array $atts, string $id, array &$attrs, array &$consumed ): void {
        $mapper = new StyleMapper();

        foreach ( [ 'h2', 'h4' ] as $prefix ) {
            $consumed[] = 'use_custom_fonts_' . $prefix;

            foreach ( array_keys( $atts ) as $key ) {
                if ( is_string( $key ) && str_starts_with( $key, $prefix . '_' ) ) {
                    $consumed[] = $key;
                }
            }

            if ( ! $this->on( $atts, 'use_custom_fonts_' . $prefix ) ) {
                continue;
            }

            if ( $prefix === 'h4' ) {
                $this->engine->logNotCarriedOver(
                    'layout',
                    $id,
                    'the sub-heading\'s custom font (h4_font_container / h4_google_fonts); Divi\'s CTA has one title field, so the sub-heading is a plain <h4> inside the body'
                );

                continue;
            }

            $mapper->applyFontContainer(
                PackedParams::fontContainer( $this->att( $atts, 'h2_font_container' ) ),
                'title.decoration.font.font',
                $attrs
            );
            $mapper->applyGoogleFonts(
                PackedParams::googleFonts( $this->att( $atts, 'h2_google_fonts' ) ),
                'title.decoration.font.font',
                $attrs
            );
        }
    }

    /**
     * `add_button` + the `btn_*` field set, mapped by `BtnConverter` itself
     * (`config/buttons/shortcode-vc-cta.php:68` integrates `vc_btn` under that
     * prefix, and `cta/module.json`'s `button` attribute has the same shape as
     * `button/module.json`'s).
     *
     * @param string[] $consumed
     */
    private function button( array $atts, string $id, array &$attrs, array &$consumed ): void {
        $consumed[] = 'add_button';
        $consumed[] = 'btn_position';

        $button     = BtnConverter::embeddedButton( $this->engine, $atts, 'btn_', $id );
        $consumed   = array_merge( $consumed, $button['consumed'] );

        if ( ! $this->on( $atts, 'add_button' ) || $button['settings'] === [] ) {
            return;
        }

        $attrs['button'] = $this->deepMergeSettings( is_array( $attrs['button'] ?? null ) ? $attrs['button'] : [], $button['settings'] );

        $position = strtolower( $this->att( $atts, 'btn_position', 'bottom' ) );
        if ( $position !== 'bottom' ) {
            $this->engine->logNotCarriedOver(
                'layout',
                $id,
                sprintf( 'btn_position="%s" puts the button beside or above the copy; Divi\'s CTA always places it under the content', $position )
            );
        }
    }

    /**
     * `add_icon` + the `i_*` field set (`vc_icon` integrated the same way).
     * Divi's CTA has no icon at all.
     *
     * @param string[] $consumed
     */
    private function icon( array $atts, string $id, array &$consumed ): void {
        $consumed[] = 'add_icon';
        $consumed[] = 'i_on_border';

        foreach ( array_keys( $atts ) as $key ) {
            if ( is_string( $key ) && str_starts_with( $key, 'i_' ) ) {
                $consumed[] = $key;
            }
        }

        if ( ! $this->on( $atts, 'add_icon' ) ) {
            return;
        }

        $library = $this->att( $atts, 'i_type', 'fontawesome' );

        $this->engine->logNotCarriedOver(
            'layout',
            $id,
            sprintf(
                'vc_cta add_icon draws a %s icon (%s) beside the copy; Divi\'s CTA module has no icon — add a blurb or an icon module next to it',
                $library,
                $this->att( $atts, 'i_icon_' . $library )
            )
        );
    }
}
