<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;
use WPBakeryDivi5Converter\Helpers\Color;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `vc_message` → `divi/text` painted as the message box.
 *
 * `.vc_message_box` is a 1px-bordered box with `padding: 1em 1em 1em 4em` (the
 * left inset makes room for the icon) whose three colours — text, border,
 * background — come from `.vc_color-<name>.vc_message_box`
 * (`assets/css/js_composer.min.css`, the table in `Color::MESSAGE_BOX`). The
 * shape is `message_box_style`/`style`:
 * `.vc_message_box-rounded{border-radius:5px}`,
 * `.vc_message_box-round{border-radius:4em}`, and
 * `.vc_message_box-outline`/`-solid-icon{border-width:2px}`.
 *
 * Divi's text module holds all of it: the body colour on
 * `content.decoration.bodyFont.body.font`, the box on
 * `module.decoration.{background,border,spacing}`.
 */
class MessageConverter extends BaseWPBakeryConverter {

    /** `.vc_message_box-<style>{border-radius:…}`; `square` has no rule and is 0. */
    const SHAPES = [
        'rounded' => '5px',
        'square'  => '0px',
        'round'   => '4em',
    ];

    /** `.vc_message_box{padding:1em 1em 1em 4em}`. */
    const PADDING = [ '1em', '1em', '1em', '4em' ];

    /** The `color` dropdown's default when neither colour attribute is set. */
    const DEFAULT_COLOR = 'info';

    public function convert( array $node ): array {
        $id      = (string) ( $node['id'] ?? uniqid( 'wbdc_message_' ) );
        $atts    = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];
        $content = (string) ( $node['content'] ?? '' );

        $style    = $this->mapStyle( 'text', $node, 'message' );
        $attrs    = $style['divi_attrs'];
        $consumed = array_merge( $style['handled_keys'], [ 'color', 'message_box_color', 'message_box_style', 'style', 'icon_type' ] );

        $this->colours( $atts, $id, $attrs );
        $this->shape( $atts, $id, $attrs );
        $this->icon( $atts, $id, $consumed );

        $html = $this->nestedShortcodes( $this->editorHtml( $content ), $id );
        StyleMapper::write( $attrs, 'content.innerContent.desktop.value', trim( $html ) );

        $this->engine->logConverted( 'text' );
        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        return $this->block( $id, 'divi/text', $attrs );
    }

    /**
     * `$color` (the preset dropdown) wins over `message_box_color` (the 9.0
     * colorpicker), exactly as the template's if/elseif does, and a value
     * neither the table nor a hex can explain is reported, never guessed at.
     */
    private function colours( array $atts, string $id, array &$attrs ): void {
        $preset = strtolower( trim( $this->att( $atts, 'color' ) ) );
        $key    = 'color';

        if ( $preset === '' ) {
            $preset = strtolower( trim( $this->att( $atts, 'message_box_color' ) ) );
            $key    = 'message_box_color';
        }
        if ( $preset === '' ) {
            $preset = self::DEFAULT_COLOR;
            $key    = 'color';
        }

        // The table keys the four `alert-*` presets with a hyphen and the 17
        // palette names with an underscore, so both spellings are tried.
        $triple = Color::MESSAGE_BOX[ $preset ] ?? ( Color::MESSAGE_BOX[ str_replace( '-', '_', $preset ) ] ?? null );

        if ( $triple === null ) {
            // 9.0's colorpicker: a hex value, whose box colours the shortcode
            // class derives by lightening and darkening it.
            $hex = Color::normalize( $preset );
            if ( $hex === null ) {
                $this->engine->logUnresolvedGlobal( $id, $key, $preset );

                return;
            }

            $triple = [
                'text'   => $hex,
                'border' => Color::withOpacity( $hex, 0.3 ),
                'bg'     => Color::withOpacity( $hex, 0.15 ),
            ];
            $this->engine->logNotCarriedOver(
                'layout',
                $id,
                sprintf(
                    'vc_message %s="%s" is a custom colour WPBakery lightens by 85%% for the background and 70%% for the border; the converter uses the same colour at 15%% and 30%% opacity',
                    $key,
                    $preset
                )
            );
        }

        StyleMapper::write( $attrs, 'module.decoration.background.desktop.value.color', $triple['bg'] );
        StyleMapper::write( $attrs, 'content.decoration.bodyFont.body.font.desktop.value.color', $triple['text'] );
        StyleMapper::write( $attrs, 'module.decoration.border.desktop.value.styles.all', [
            'width' => $this->borderWidth( $atts ),
            'style' => 'solid',
            'color' => $triple['border'],
        ] );
    }

    /** `.vc_message_box-outline`, `.vc_message_box-solid-icon{border-width:2px}`. */
    private function borderWidth( array $atts ): string {
        $box = strtolower( $this->att( $atts, 'message_box_style', 'standard' ) );

        return in_array( $box, [ 'outline', 'solid-icon' ], true ) ? '2px' : '1px';
    }

    private function shape( array $atts, string $id, array &$attrs ): void {
        $shape  = strtolower( $this->att( $atts, 'style', 'rounded' ) );
        $radius = self::SHAPES[ $shape ] ?? self::SHAPES['rounded'];

        StyleMapper::write( $attrs, 'module.decoration.border.desktop.value.radius', [
            'topLeft'     => $radius,
            'topRight'    => $radius,
            'bottomRight' => $radius,
            'bottomLeft'  => $radius,
        ] );

        if ( $this->read( $attrs, 'module.decoration.spacing.desktop.value.padding' ) === null ) {
            StyleMapper::write( $attrs, 'module.decoration.spacing.desktop.value.padding', self::box( ...self::PADDING ) );
        }

        $box = strtolower( $this->att( $atts, 'message_box_style', 'standard' ) );
        if ( $box !== 'standard' && $box !== '' ) {
            $this->engine->logNotCarriedOver(
                'layout',
                $id,
                sprintf(
                    'vc_message message_box_style="%s" recolours the box (a solid fill, an icon panel or a drop shadow); the converter keeps the standard colours',
                    $box
                )
            );
        }
    }

    /**
     * Every message box shows an icon in the 4em gutter — one the `color`
     * preset chooses, or the one `icon_<library>` names. Divi's text module has
     * no icon slot, so the padding stays and the icon is reported.
     *
     * @param string[] $consumed
     */
    private function icon( array $atts, string $id, array &$consumed ): void {
        $library    = $this->att( $atts, 'icon_type', 'fontawesome' );
        $consumed[] = 'icon_' . $library;

        $this->engine->logNotCarriedOver(
            'layout',
            $id,
            'vc_message shows an icon in the left gutter of the box; Divi\'s text module has no icon, so the space is kept but the icon is not'
        );
    }
}
