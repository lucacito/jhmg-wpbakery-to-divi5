<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;
use WPBakeryDivi5Converter\Helpers\PackedParams;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `vc_gmaps` (pre-9.0) and `vc_goo_maps` (9.0) → the map the source can
 * actually produce. Both are registered approximate.
 *
 * **`vc_gmaps`** stores a whole `<iframe>` in `link`, `textarea_safe`-encoded
 * (`vc_value_from_safe()` → `PackedParams::safeValue()`), and its template
 * rewrites the iframe's `height` from `size` before printing it verbatim
 * (`include/templates/shortcodes/vc_gmaps.php`). That iframe is a working,
 * key-free Google embed, so it is kept exactly as it is inside a `divi/code`.
 *
 * **`vc_goo_maps`** has `location`, `zoom` and `type` and builds the same kind
 * of embed itself (`WPBakeryShortCode_Vc_Goo_Maps::getIframeLink()`).
 * Divi's map module centres on latitude and longitude — `MapModule::render_callback()`
 * reads `map.innerContent.desktop.value.{lat,lng,zoom}` and puts them on
 * `data-center-lat` / `data-center-lng`; the `address` sub-name is the Visual
 * Builder's geocoder input and is never used on the front end. So:
 *
 * - a `location` that already **is** a coordinate pair converts to a real
 *   `divi/map` with a `divi/map-pin` on it (it needs a Google API key in Divi's
 *   options, which is reported);
 * - a `location` that is an **address** cannot be geocoded here, and a map
 *   centred on `0,0` would be worse than the embed WPBakery drew, so it keeps
 *   that embed in a `divi/code`.
 */
class GmapsConverter extends BaseWPBakeryConverter {

    /** `getIframeLink()`'s URL template, with its entities decoded. */
    const EMBED_URL = 'https://maps.google.com/maps?q=%1$s&t=%2$s&z=%3$d&output=embed&iwloc=near';

    /** `$type = 'm'` — the map-type default of both elements. */
    const DEFAULT_TYPE = 'm';

    /** `config/content/shortcode-vc-goo-maps.php`: `'value' => '10'`. */
    const DEFAULT_ZOOM = 10;

    public function convert( array $node ): array {
        $tag  = (string) ( $node['tag'] ?? '' );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        $blocks = $tag === 'vc_goo_maps' ? $this->gooMaps( $node, $atts ) : $this->gmaps( $node, $atts );

        $title = $this->titleBlock( $node );
        if ( $title !== null ) {
            $this->engine->logConverted( 'heading' );
            array_unshift( $blocks, $title );
        }

        return count( $blocks ) === 1 ? $blocks[0] : $blocks;
    }

    // -------------------------------------------------------------------------
    // vc_gmaps: an iframe someone pasted in
    // -------------------------------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    private function gmaps( array $node, array $atts ): array {
        $id = (string) ( $node['id'] ?? uniqid( 'wbdc_map_' ) );

        $style    = $this->mapStyle( 'generic', $node );
        $consumed = array_merge( $style['handled_keys'], [ 'link', 'size', 'title' ] );

        $link = trim( PackedParams::safeValue( $this->att( $atts, 'link' ) ) );

        if ( $link === '' ) {
            // `if ( '' === $link ) { return null; }` — WPBakery renders nothing.
            $this->engine->logWarning( "vc_gmaps {$id} has no map to show; nothing was written for it." );
            $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

            return [];
        }

        // `$size = str_replace( ['px', ' '], '', $size ); if ( is_numeric( $size ) ) { … }`
        $size = str_replace( [ 'px', ' ' ], '', $this->att( $atts, 'size' ) );
        $html = str_starts_with( $link, '<iframe' )
            ? $this->withHeight( $link, $size )
            : $this->fallbackIframe( $link, $size );

        $this->engine->logNotCarriedOver(
            'integration',
            $id,
            'vc_gmaps holds a Google Maps embed iframe; it is kept verbatim in a code module because Divi\'s map module needs an API key and coordinates this element does not carry'
        );

        $this->engine->logConverted( 'code' );
        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        return [ $this->codeBlock( $id, $html, $style['divi_attrs'] ) ];
    }

    /** `preg_replace( '/height="[0-9]*"/', 'height="' . $size . '"', $link )`. */
    private function withHeight( string $iframe, string $size ): string {
        if ( ! is_numeric( $size ) ) {
            return $iframe;
        }

        return (string) preg_replace( '/height="[0-9]*"/', 'height="' . $size . '"', $iframe );
    }

    /** The `else` branch of the template: a bare URL wrapped in an iframe. */
    private function fallbackIframe( string $link, string $size ): string {
        return '<iframe width="100%" height="' . esc_attr( $size ) . '" frameborder="0" scrolling="no" marginheight="0" marginwidth="0" src="'
            . esc_url( $link . '&t=' . self::DEFAULT_TYPE . '&z=14&output=embed' ) . '"></iframe>';
    }

    // -------------------------------------------------------------------------
    // vc_goo_maps: a location, a zoom and a map type
    // -------------------------------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    private function gooMaps( array $node, array $atts ): array {
        $id = (string) ( $node['id'] ?? uniqid( 'wbdc_map_' ) );

        $style    = $this->mapStyle( 'generic', $node );
        $consumed = array_merge( $style['handled_keys'], [ 'location', 'zoom', 'type', 'height', 'title' ] );

        $location = trim( $this->att( $atts, 'location' ) );
        if ( $location === '' ) {
            $this->engine->logWarning( "vc_goo_maps {$id} has no location; WPBakery renders nothing for it and neither does the converter." );
            $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

            return [];
        }

        $zoom        = $this->zoom( $atts );
        $coordinates = $this->coordinates( $location );

        if ( $coordinates === null ) {
            $this->engine->logNotCarriedOver(
                'integration',
                $id,
                sprintf(
                    'vc_goo_maps location "%s" is an address; Divi\'s map module centres on latitude and longitude, so WPBakery\'s own Google embed is kept in a code module instead',
                    $location
                )
            );

            $this->engine->logConverted( 'code' );
            $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

            return [ $this->codeBlock( $id, $this->iframe( $atts, $location, $zoom ), $style['divi_attrs'] ) ];
        }

        $attrs = $style['divi_attrs'];
        StyleMapper::write( $attrs, 'map.innerContent.desktop.value', [
            'address' => $location,
            'zoom'    => (string) $zoom,
            'lat'     => $coordinates['lat'],
            'lng'     => $coordinates['lng'],
        ] );

        $height = PackedParams::sizeWithUnit( $this->att( $atts, 'height' ) );
        if ( $height !== '' ) {
            StyleMapper::write( $attrs, 'module.decoration.sizing.desktop.value.height', $height );
        }

        if ( $this->att( $atts, 'type' ) !== '' && $this->att( $atts, 'type' ) !== self::DEFAULT_TYPE ) {
            $this->engine->logNotCarriedOver(
                'layout',
                $id,
                sprintf( 'vc_goo_maps type="%s" chooses the satellite or hybrid tiles; Divi\'s map always draws the road map', $this->att( $atts, 'type' ) )
            );
        }

        $this->engine->logNotCarriedOver(
            'integration',
            $id,
            'divi/map draws through the Google Maps JavaScript API: add a Google API key under Divi > Theme Options > General or the map stays blank'
        );

        $pin = $this->block( $id . '-pin', 'divi/map-pin', [
            'pin'   => [ 'innerContent' => [ 'desktop' => [ 'value' => [
                'address' => $location,
                'lat'     => $coordinates['lat'],
                'lng'     => $coordinates['lng'],
            ] ] ] ],
            'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => $location ] ] ],
        ] );

        $this->engine->logConverted( 'map' );
        $this->engine->logConverted( 'map-pin' );
        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        return [ $this->block( $id, 'divi/map', $attrs, [ $pin ] ) ];
    }

    /** `$zoom = absint( $atts['zoom'] )`, clamped to 0…22 as `getIframeLink()` clamps it. */
    private function zoom( array $atts ): int {
        $zoom = $this->att( $atts, 'zoom' );
        if ( ! is_numeric( trim( $zoom ) ) ) {
            return self::DEFAULT_ZOOM;
        }

        return max( 0, min( 22, (int) abs( (float) $zoom ) ) );
    }

    /**
     * "51.503324,-0.119543" — the coordinate form `vc_goo_maps`' own field
     * description offers ("an address, city, country, or coordinates").
     *
     * @return array{lat: string, lng: string}|null
     */
    private function coordinates( string $location ): ?array {
        if ( preg_match( '/^\s*(-?\d{1,3}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)\s*$/', $location, $m ) !== 1 ) {
            return null;
        }

        if ( abs( (float) $m[1] ) > 90 || abs( (float) $m[2] ) > 180 ) {
            return null;
        }

        return [ 'lat' => $m[1], 'lng' => $m[2] ];
    }

    /** `WPBakeryShortCode_Vc_Goo_Maps::getIframeLink()`, in an iframe as its template writes one. */
    private function iframe( array $atts, string $location, int $zoom ): string {
        $src = sprintf(
            self::EMBED_URL,
            rawurlencode( $location ),
            $this->att( $atts, 'type', self::DEFAULT_TYPE ),
            $zoom
        );

        $height = PackedParams::sizeWithUnit( $this->att( $atts, 'height' ) );

        return '<iframe loading="lazy" src="' . esc_url( $src ) . '" title="' . esc_attr( $location ) . '" aria-label="' . esc_attr( $location ) . '"'
            . ( $height === '' ? '' : ' height="' . esc_attr( rtrim( $height, 'px' ) ) . '"' )
            . ' width="100%"></iframe>';
    }
}
