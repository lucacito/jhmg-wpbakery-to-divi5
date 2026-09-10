<?php

namespace WPBakeryDivi5Converter\Converter;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Divi blocks rendered back to plain HTML.
 *
 * WPBakery's composite elements are containers: a `vc_tta_section` holds rows,
 * columns and elements, and the editor writes them as nested shortcodes. Divi's
 * accordion item and tab hold a single `content.innerContent` string
 * (`accordion-item/module.json`, `tab/module.json`) — Divi 5.12.1 also allows
 * child modules inside an accordion item, but not inside a tab, and the two
 * have to behave the same way or a converted tour and a converted accordion
 * would carry different content.
 *
 * So the section's children are converted by their own handlers first — every
 * field is mapped by the source-verified handler that owns it — and the
 * resulting blocks are rendered to the HTML those two modules can hold. What
 * survives is the text, the headings, the pictures, the buttons, the rules and
 * the column layout; what does not is named in a comment and reported, never
 * dropped in silence.
 */
class ContentFlattener {

    /** The class the flattened button and row carry, so a site can style them back. */
    const BUTTON_CLASS = 'wbdc-flat-button';
    const ROW_CLASS    = 'wbdc-flat-row';

    /** Divi's own row gutter, the gap between the flattened columns. */
    const ROW_GAP = '30px';

    private ConverterEngine $engine;

    public function __construct( ConverterEngine $engine ) {
        $this->engine = $engine;
    }

    /**
     * @param array<int, array<string,mixed>> $blocks Blocks from `ConverterEngine::convertChildren()`.
     * @param string                          $node_id The node the content belongs to, for the report.
     */
    public function toHtml( array $blocks, string $node_id ): string {
        $html = '';

        foreach ( $blocks as $block ) {
            if ( ! is_array( $block ) ) {
                continue;
            }
            $html .= $this->blockHtml( $block, $node_id );
        }

        return trim( $html );
    }

    private function blockHtml( array $block, string $node_id ): string {
        $name     = (string) ( $block['name'] ?? '' );
        $settings = is_array( $block['settings'] ?? null ) ? $block['settings'] : [];

        switch ( $name ) {
            case 'divi/text':
            case 'divi/code':
                // A theme shortcode's static copy or placeholder is already
                // HTML: it goes in verbatim (amendment §2).
                return trim( (string) $this->read( $settings, 'content.innerContent.desktop.value' ) );

            case 'divi/heading':
                return $this->heading( $settings );

            case 'divi/image':
                return $this->image( $settings );

            case 'divi/button':
                return $this->button( $settings );

            case 'divi/divider':
                return '<hr>';

            case 'divi/row':
            case 'divi/row-inner':
                return $this->row( $block, $node_id );

            case 'divi/column':
            case 'divi/column-inner':
                // A bare column with no row around it: its children, in order.
                return $this->toHtml( is_array( $block['elements'] ?? null ) ? $block['elements'] : [], $node_id );
        }

        return $this->notRepresentable( $name, $node_id );
    }

    // -------------------------------------------------------------------------
    // The blocks that have an HTML equivalent
    // -------------------------------------------------------------------------

    private function heading( array $settings ): string {
        $text  = trim( (string) $this->read( $settings, 'title.innerContent.desktop.value' ) );
        $level = (string) $this->read( $settings, 'title.decoration.font.font.desktop.value.headingLevel' );

        if ( preg_match( '/^h[1-6]$/', $level ) !== 1 ) {
            $level = 'h2';
        }

        return $text === '' ? '' : '<' . $level . '>' . $text . '</' . $level . '>';
    }

    private function image( array $settings ): string {
        $src = trim( (string) $this->read( $settings, 'image.innerContent.desktop.value.src' ) );
        if ( $src === '' ) {
            return '';
        }

        $alt = (string) $this->read( $settings, 'image.innerContent.desktop.value.alt' );
        $img = '<img src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '">';

        $link = trim( (string) $this->read( $settings, 'image.innerContent.desktop.value.linkUrl' ) );
        if ( $link === '' ) {
            return $img;
        }

        $target = $this->read( $settings, 'image.innerContent.desktop.value.linkTarget' ) === 'on'
            ? ' target="_blank"'
            : '';

        return '<a href="' . esc_url( $link ) . '"' . $target . '>' . $img . '</a>';
    }

    private function button( array $settings ): string {
        $text = trim( (string) $this->read( $settings, 'button.innerContent.desktop.value.text' ) );
        $url  = trim( (string) $this->read( $settings, 'button.innerContent.desktop.value.linkUrl' ) );

        if ( $text === '' && $url === '' ) {
            return '';
        }

        $target = $this->read( $settings, 'button.innerContent.desktop.value.linkTarget' ) === 'on'
            ? ' target="_blank"'
            : '';

        return '<a class="' . self::BUTTON_CLASS . '" href="' . esc_url( $url ) . '"' . $target . '>' . esc_html( $text ) . '</a>';
    }

    /**
     * A nested row as the flexbox it renders as, with each column's width
     * taken from the `flexType` the `ColumnConverter` wrote (`10_24` → 41.67 %).
     * Divi's own 30 px gutter is the gap.
     */
    private function row( array $block, string $node_id ): string {
        $columns = '';

        foreach ( is_array( $block['elements'] ?? null ) ? $block['elements'] : [] as $column ) {
            if ( ! is_array( $column ) ) {
                continue;
            }

            $inner = $this->toHtml( is_array( $column['elements'] ?? null ) ? $column['elements'] : [], $node_id );
            $width = $this->columnWidth( is_array( $column['settings'] ?? null ) ? $column['settings'] : [] );

            $columns .= '<div style="flex:0 0 ' . $width . '%">' . $inner . '</div>';
        }

        if ( $columns === '' ) {
            return '';
        }

        return '<div class="' . self::ROW_CLASS . '" style="display:flex;gap:' . self::ROW_GAP . '">' . $columns . '</div>';
    }

    /** `module.decoration.sizing.desktop.value.flexType` (`N_24`) as a percentage. */
    private function columnWidth( array $settings ): string {
        $flex = (string) $this->read( $settings, 'module.decoration.sizing.desktop.value.flexType' );

        if ( preg_match( '/^(\d+)_(\d+)$/', $flex, $m ) !== 1 || (int) $m[2] === 0 ) {
            return '100';
        }

        return rtrim( rtrim( number_format( (int) $m[1] / (int) $m[2] * 100, 2, '.', '' ), '0' ), '.' );
    }

    // -------------------------------------------------------------------------
    // Everything else
    // -------------------------------------------------------------------------

    private function notRepresentable( string $name, string $node_id ): string {
        $label = $name === '' ? 'a module' : $name;

        $this->engine->logNotCarriedOver(
            'layout',
            $node_id,
            sprintf(
                '%s sits inside a tab or accordion item, which holds HTML rather than modules in Divi 5.12; a comment marks where it was',
                $label
            )
        );

        return '<!-- wbdc: ' . esc_html( $label ) . ' not representable inside this item -->';
    }

    private function read( array $settings, string $dot_path ): mixed {
        $current = $settings;
        foreach ( explode( '.', $dot_path ) as $key ) {
            if ( ! is_array( $current ) || ! array_key_exists( $key, $current ) ) {
                return null;
            }
            $current = $current[ $key ];
        }

        return is_array( $current ) ? null : $current;
    }
}
