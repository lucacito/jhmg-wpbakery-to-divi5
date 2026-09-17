<?php

namespace WPBakeryDivi5Converter\Converter\Ronneby;

use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `dfd_google_map` → `divi/map` › one `divi/map-pin` per marker.
 *
 * `modules/dfd_google_map.php:494-700` draws the map with gmap3: it splits
 * `map_markers` on a literal `\n` (line 529 — the separator is two characters,
 * because the string is single-quoted) and hands each line to the API as an
 * `address`, which Google geocodes in the browser. Divi's map centres on a
 * latitude and longitude instead (`map/module.json`,
 * `map.innerContent.desktop.value.{address,zoom,lat,lng}`, and the same on each
 * `divi/map-pin`), so an address that is not already a coordinate pair is
 * written as the pin's address and reported: the page keeps the place names and
 * the reader picks them on Divi's own map.
 *
 * `size` is the map's height and the three `*_size` fields are its height per
 * breakpoint (lines 505-527), which Divi holds on `module.decoration.sizing`.
 *
 * **No default bottom margin**: `.dfd-gmap-module` carries none.
 */
class DfdGoogleMapConverter extends RonnebyConverter {

    /** `'value' => 15` on the `zoom` dropdown; gmap3's own range is 0-22. */
    const DEFAULT_ZOOM = 15;

    /** What the theme's own script draws and Divi's map has no field for. */
    const REPORTED_LOOK = [
        'map_style'         => 'one of the theme\'s Snazzy Maps tile styles',
        'marker_image'      => 'a custom marker image',
        'enable_zoom'       => 'whether the zoom control is shown',
        'auto_pan'          => 'panning the map to the marker',
        'touch_on_mobile'   => 'whether touch drags the map',
        'x_pan'             => 'the horizontal offset of the centre',
        'y_pan'             => 'the vertical offset of the centre',
        'infobox_style'     => 'the info box beside the map',
        'infobox_heading'   => 'its heading',
        'infobox_alignment' => 'its alignment',
        'infobox_background' => 'its background',
        'infobox_color'     => 'its text colour',
        'info_text'         => 'its copy',
        'info_fields'       => 'its rows of contact details',
        'location_list'     => 'the per-marker tooltips of a newer Ronneby build',
        'content'           => 'the info box body',
        'title_font_options' => 'the info box typography',
        'title_google_fonts' => 'its Google font toggle',
        'title_custom_fonts' => 'its Google font',
    ];

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_dfd_map_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        $style    = $this->mapStyle( 'generic', $node, 'none' );
        $attrs    = $style['divi_attrs'];
        $consumed = array_merge( $style['handled_keys'], [ 'map_markers', 'zoom', 'tutorials' ] );

        $markers = $this->markers( $atts );
        $zoom    = $this->zoom( $atts );

        $this->height( $atts, $attrs, $consumed );
        $this->pickedIcon( $atts, $consumed );

        $centre = $markers === [] ? '' : $markers[0];

        StyleMapper::write( $attrs, 'map.innerContent.desktop.value', array_filter( [
            'address' => $centre,
            'zoom'    => (string) $zoom,
            'lat'     => $this->coordinates( $centre )['lat'] ?? '',
            'lng'     => $this->coordinates( $centre )['lng'] ?? '',
        ], static fn( string $value ): bool => $value !== '' ) );

        $pins = [];
        foreach ( $markers as $index => $marker ) {
            $coordinates = $this->coordinates( $marker );

            $pins[] = $this->block( $id . '-pin-' . ( $index + 1 ), 'divi/map-pin', [
                'pin'   => [ 'innerContent' => [ 'desktop' => [ 'value' => array_filter( [
                    'address' => $marker,
                    'lat'     => $coordinates['lat'] ?? '',
                    'lng'     => $coordinates['lng'] ?? '',
                ], static fn( string $value ): bool => $value !== '' ) ] ] ],
                'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => $marker ] ] ],
            ] );

            $this->engine->logConverted( 'map-pin' );

            if ( $coordinates === null ) {
                $this->engine->logNotCarriedOver(
                    'integration',
                    $id,
                    sprintf( 'the marker "%s" is an address the theme geocoded in the browser; Divi\'s map pin needs a latitude and longitude, so pick the place again on the module\'s own map', $marker )
                );
            }
        }

        $this->engine->logNotCarriedOver(
            'integration',
            $id,
            'divi/map draws through the Google Maps JavaScript API: add a Google API key under Divi > Theme Options > General or the map stays blank'
        );

        $this->reportAnimation( $atts, $id, $consumed );
        $this->reportGroup( $atts, self::REPORTED_LOOK, 'layout', $id, 'dfd_google_map draws its own tiles, markers and info box', $consumed );

        $this->engine->logConverted( 'map' );
        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        return $this->block( $id, 'divi/map', $attrs, $pins );
    }

    /**
     * `explode( '\n', $map_markers )` — a literal backslash-n, because the
     * module's separator is single-quoted (line 529). A newline is accepted
     * too, since an editor that typed one meant the same thing.
     *
     * @return string[]
     */
    private function markers( array $atts ): array {
        $raw = $this->att( $atts, 'map_markers' );

        return array_values( array_filter(
            array_map( 'trim', preg_split( '/\\\\n|\r\n|\n/', $raw ) ?: [] ),
            static fn( string $marker ): bool => $marker !== ''
        ) );
    }

    /** The `zoom` dropdown, clamped to the range Google's API accepts. */
    private function zoom( array $atts ): int {
        $zoom = trim( $this->att( $atts, 'zoom' ) );

        if ( ! is_numeric( $zoom ) ) {
            return self::DEFAULT_ZOOM;
        }

        return max( 0, min( 22, (int) $zoom ) );
    }

    /**
     * `size` is the map's height in pixels, and `enable_responsive` turns on
     * three per-breakpoint heights whose media queries are the theme's own
     * (lines 505-527): ≥1280, ≤1023 and ≤799.
     *
     * @param string[] $consumed
     */
    private function height( array $atts, array &$attrs, array &$consumed ): void {
        $consumed = array_merge( $consumed, [ 'size', 'enable_responsive', 'desktop_size', 'tablet_size', 'mobile_size' ] );

        $height = $this->pixels( $atts, 'size' );
        if ( $height !== '' ) {
            StyleMapper::write( $attrs, 'module.decoration.sizing.desktop.value.height', $height );
        }

        if ( ! $this->on( $atts, 'enable_responsive' ) && trim( $this->att( $atts, 'enable_responsive' ) ) !== 'enable' ) {
            return;
        }

        // The theme's `desktop_size` band is ≥1280px, which sits inside Divi's
        // one desktop breakpoint, so it is not written over `size`.
        foreach ( [ 'tablet_size' => 'tablet', 'mobile_size' => 'phone' ] as $key => $breakpoint ) {
            $value = $this->pixels( $atts, $key );
            if ( $value !== '' ) {
                StyleMapper::write( $attrs, "module.decoration.sizing.{$breakpoint}.value.height", $value );
            }
        }
    }

    /**
     * "51.503324,-0.119543" — the one marker form Divi's map can use as it
     * stands, read with `GmapsConverter`'s own pattern.
     *
     * @return array{lat: string, lng: string}|null
     */
    private function coordinates( string $marker ): ?array {
        if ( preg_match( '/^\s*(-?\d{1,3}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)\s*$/', $marker, $m ) !== 1 ) {
            return null;
        }

        if ( abs( (float) $m[1] ) > 90 || abs( (float) $m[2] ) > 180 ) {
            return null;
        }

        return [ 'lat' => $m[1], 'lng' => $m[2] ];
    }
}
