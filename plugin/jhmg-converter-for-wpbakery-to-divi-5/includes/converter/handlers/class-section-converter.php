<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;
use WPBakeryDivi5Converter\Converter\ConverterEngine;
use WPBakeryDivi5Converter\Helpers\PackedParams;
use WPBakeryDivi5Converter\StyleMapper\GlobalSettingsResolver;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `vc_section` → `divi/section`, and the home of the top-level rows that have
 * no section of their own.
 *
 * Two shapes arrive here. An explicit `vc_section` is a band the page author
 * drew: it paints its own background and holds however many rows it holds, so
 * it becomes one Divi section. An **implicit** section is `NodeTree`'s wrapper
 * around a run of top-level rows — which is most WPBakery pages, since the
 * editor still writes bare rows. Each of those rows paints its own full-bleed
 * band, so each becomes its own `divi/section` holding one `divi/row`
 * (spec §5): one section per row, not one section for the run, or two rows
 * with different backgrounds would end up sharing one.
 *
 * Either way this is where the `vc_row-has-fill` run is walked, because the
 * 35 px of column top padding a filled row causes lands on the *next* row's
 * columns as well, and only the owner of the run knows what "next" means.
 */
class SectionConverter extends BaseWPBakeryConverter {

    /** `vertical_content_position` ⇒ Divi section `justifyContent`. */
    private const CONTENT_POSITION = [
        'top'    => 'flex-start',
        'middle' => 'center',
        'bottom' => 'flex-end',
    ];

    /** Attributes this handler consumes beyond the StyleMapper's. */
    private const SECTION_KEYS = [
        'full_width', 'full_height', 'min_height', 'vertical_content_position',
        'parallax', 'parallax_image', 'parallax_speed_bg', 'parallax_speed_video',
        'video_bg', 'video_bg_url', 'video_bg_parallax',
    ];

    public function convert( array $node ): array {
        $rows = RowConverter::markFilled( $this->rowNodes( $node ) );

        return empty( $node['implicit'] )
            ? $this->explicitSection( $node, $rows )
            : $this->sectionPerRow( $node, $rows );
    }

    /**
     * A section the author drew: its own design options, holding every row it
     * held.
     *
     * @param array<int,array<string,mixed>> $rows
     */
    private function explicitSection( array $node, array $rows ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_section_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];
        $row  = new RowConverter( $this->engine );

        $style    = $this->mapStyle( 'section', $node );
        $settings = $this->deepMergeSettings( $style['divi_attrs'], $this->heightAttrs( $atts ) );
        $settings = $this->deepMergeSettings( $settings, $this->contentPosition( $atts ) );
        $settings = $row->parallaxBackground( $id, $atts, $settings );
        $settings = self::fillSectionPadding(
            $settings,
            ! empty( $node['filled'] ) || ! empty( $node['after_filled'] ) || RowConverter::isFilled( $node )
        );
        $row->reportBackgrounds( $id, $atts );

        $stretch = $this->stretch( $atts );

        $blocks = [];
        foreach ( $rows as $row_node ) {
            if ( $stretch !== '' ) {
                $row_node['section_stretch'] = $stretch;
            }
            foreach ( ConverterEngine::asList( $this->engine->convertNode( $row_node ) ) as $block ) {
                $blocks[] = $block;
            }
        }
        if ( $blocks === [] ) {
            $blocks[] = $this->emptyRow( $id );
        }

        $this->engine->logConverted( 'section' );
        $this->logUnmappedSettings( $id, $atts, array_merge( $style['handled_keys'], self::SECTION_KEYS ), (string) ( $node['tag'] ?? '' ) );

        return $this->block( $id, 'divi/section', $settings, $blocks );
    }

    /**
     * The implicit wrapper: one section per row, each taking that row's
     * background, padding, visibility and height, because that is the band
     * WPBakery painted.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function sectionPerRow( array $node, array $rows ): array {
        if ( $rows === [] ) {
            $id = (string) ( $node['id'] ?? 'vc_section' );
            $this->engine->logWarning( "Empty section kept: {$id} held no rows; an empty row stands in for it." );
            $this->engine->logConverted( 'section' );

            return [ $this->block( $id . '-section', 'divi/section', self::sectionResetSettings(), [ $this->emptyRow( $id ) ] ) ];
        }

        $sections = [];

        foreach ( $rows as $row_node ) {
            $id     = (string) ( $row_node['id'] ?? uniqid( 'wbdc_row_' ) );
            $lifted = ( new RowConverter( $this->engine ) )->sectionAttrs( $row_node );

            $row_node['lifted'] = true;

            $blocks = ConverterEngine::asList( $this->engine->convertNode( $row_node ) );
            if ( $blocks === [] ) {
                // Nothing a row handler produced: it has no section to live in.
                $this->engine->logWarning( "empty row {$id} dropped: its handler produced no block to put in a section." );

                continue;
            }

            $this->engine->logConverted( 'section' );
            $sections[] = $this->block( $id . '-section', 'divi/section', $lifted['divi_attrs'], $blocks );
        }

        return $sections;
    }

    /**
     * A section's children are rows. `NodeTree` guarantees it for a parsed
     * document; anything else that arrives gets a row of its own, and says so.
     *
     * @return array<int,array<string,mixed>>
     */
    private function rowNodes( array $node ): array {
        $rows    = [];
        $pending = [];

        foreach ( $node['children'] ?? [] as $child ) {
            if ( ! is_array( $child ) ) {
                continue;
            }
            if ( in_array( $child['kind'] ?? '', [ 'row', 'row_inner' ], true ) ) {
                if ( $pending !== [] ) {
                    $rows[]  = $this->wrapInRow( $node, $pending, count( $rows ) );
                    $pending = [];
                }
                $rows[] = $child;

                continue;
            }
            $pending[] = $child;
        }

        if ( $pending !== [] ) {
            $rows[] = $this->wrapInRow( $node, $pending, count( $rows ) );
        }

        return $rows;
    }

    /** @param array<int,array<string,mixed>> $children */
    private function wrapInRow( array $node, array $children, int $index ): array {
        $id = (string) ( $node['id'] ?? 'vc_section' );
        $this->engine->logWarning( "Content sat directly in section {$id}; it was wrapped in a row and a column." );

        return [
            'id'       => $id . '-row-' . ( $index + 1 ),
            'tag'      => 'vc_row',
            'kind'     => 'row',
            'atts'     => [],
            'content'  => '',
            'children' => [ [
                'id'       => $id . '-col-' . ( $index + 1 ),
                'tag'      => 'vc_column',
                'kind'     => 'column',
                'atts'     => [ 'width' => '1/1' ],
                'content'  => '',
                'children' => $children,
                'notes'    => [],
                'implicit' => true,
            ] ],
            'notes'    => [],
            'implicit' => true,
        ];
    }

    private function emptyRow( string $id ): array {
        return $this->block(
            $id . '-row',
            'divi/row',
            self::containerRowSettings(),
            [ $this->block( $id . '-col', 'divi/column', self::fullWidthColumnSettings() ) ]
        );
    }

    // -------------------------------------------------------------------------

    /** `full_height` → 100vh; an explicit `min_height` wins, as it does in WPBakery. */
    private function heightAttrs( array $atts ): array {
        $min_height = PackedParams::sizeWithUnit( $this->att( $atts, 'min_height' ) );

        if ( $min_height === '' && $this->on( $atts, 'full_height' ) ) {
            $min_height = '100vh';
        }

        return $min_height === ''
            ? []
            : [ 'module' => [ 'decoration' => [ 'sizing' => [ 'desktop' => [ 'value' => [ 'minHeight' => $min_height ] ] ] ] ] ];
    }

    /**
     * `vertical_content_position` is `justify-content` on the section itself
     * in WPBakery (`.vc_section.vc_section-o-content-*` on a `flex-flow:
     * column nowrap` box), which is what Divi's section layout option writes.
     */
    private function contentPosition( array $atts ): array {
        $position = $this->att( $atts, 'vertical_content_position' );

        if ( ! isset( self::CONTENT_POSITION[ $position ] ) ) {
            return [];
        }

        return [ 'module' => [ 'decoration' => [ 'layout' => [ 'desktop' => [ 'value' => [
            'display'        => 'flex',
            'flexDirection'  => 'column',
            'justifyContent' => self::CONTENT_POSITION[ $position ],
        ] ] ] ] ] ];
    }

    /**
     * How far a section stretches its rows.
     *
     * WPBakery marks a stretched section `data-vc-full-width`, and
     * `stretch_row_content` also `data-vc-stretch-content`, which drops the
     * section's own `padding-left/right: 15px`
     * (`.vc_section[data-vc-stretch-content]`) so its rows run edge to edge.
     * A Divi section is already full-bleed with no padding of its own, so
     * `stretch_row` needs nothing extra and the content variants hand their
     * rows the same geometry a row's own `full_width` would.
     *
     * The dropdown offers only `stretch_row` and `stretch_row_content`
     * (`config/containers/shortcode-vc-section.php`), but a hand-edited
     * document can carry the row's `_no_spaces` value, so it is honoured.
     */
    private function stretch( array $atts ): string {
        return match ( $this->att( $atts, 'full_width' ) ) {
            'stretch_row_content'           => 'content',
            'stretch_row_content_no_spaces' => 'no_spaces',
            default                         => '',
        };
    }

    /**
     * WPBakery contributes no section padding of its own — except the 35px on
     * top of a section that paints a background or border, and on the section
     * right after it (`.vc_section.vc_section-has-fill`,
     * `.vc_section.vc_section-has-fill + .vc_section`, and the same across the
     * full-width spacer div; `js_composer.min.css`). Every other blank side is
     * zero, and a side the design options set keeps its value.
     */
    public static function fillSectionPadding( array $attrs, bool $filled = false ): array {
        $default = GlobalSettingsResolver::sectionPadding();
        $padding = $attrs['module']['decoration']['spacing']['desktop']['value']['padding'] ?? [];
        $padding = is_array( $padding ) ? $padding : [];

        foreach ( [ 'top', 'right', 'bottom', 'left' ] as $side ) {
            if ( ! isset( $padding[ $side ] ) || $padding[ $side ] === '' ) {
                $padding[ $side ] = $side === 'top' && $filled ? GlobalSettingsResolver::filledColumnPaddingTop() : $default;
            }
        }

        StyleMapper::write(
            $attrs,
            'module.decoration.spacing.desktop.value.padding',
            $padding + [ 'syncVertical' => 'off', 'syncHorizontal' => 'off' ]
        );

        return $attrs;
    }
}
