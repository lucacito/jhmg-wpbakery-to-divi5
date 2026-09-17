<?php

namespace WPBakeryDivi5Converter\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WPBakery icon classes → Divi 5 icon attribute values.
 *
 * Ported from the Beaver converter's
 * `BeaverDivi5Converter\Helpers\IconMap`
 * (plugin/jhmg-converter-for-beaver-builder-to-divi/includes/helpers/class-icon-map.php),
 * extended to accept WPBakery's own icon class vocabulary: Font Awesome 5
 * (`fas fa-adjust`, `far fa-envelope`, `fab fa-facebook-f`) exactly as
 * Beaver Builder writes it, plus Font Awesome 6's two-token family form
 * (`fa-solid fa-adjust`, `fa-regular fa-envelope`, `fa-brands fa-facebook-f`)
 * that WPBakery 9.0's icon picker can also emit. Anything else — Open Iconic
 * (`vc-oi vc-oi-dial`), Typicons, Entypo, Linecons, Monosocial, WPBakery's
 * own Pixel icon set — has no Divi equivalent and falls back to a star,
 * reported via `exact => false`.
 *
 * Divi 5 stores an icon as `{unicode, type, weight}` (that key order matters:
 * Divi's asset detector only enqueues Font Awesome when it sees
 * `"unicode"…"type":"fa"` in that order) and ships Font Awesome in its icon
 * library, so an FA class maps to the identical glyph via data/fa-icons.json
 * (generated from Divi's own iconList.json by scripts/build-fa-icon-map.php).
 */
final class IconMap {

    /** fa-star, solid — used whenever the source class has no Divi equivalent. */
    public const FALLBACK = [ 'unicode' => '&#xf005;', 'type' => 'fa', 'weight' => '900' ];

    /** FA5 single-token and FA6 two-token family classes → the FA5 letter code. */
    private const STYLE_TOKENS = [
        'fa'         => 'fa',
        'fas'        => 'fas',
        'far'        => 'far',
        'fab'        => 'fab',
        'fal'        => 'fal',
        'fad'        => 'fad',
        'fa-solid'   => 'fas',
        'fa-regular' => 'far',
        'fa-brands'  => 'fab',
        'fa-light'   => 'fal',
        'fa-thin'    => 'fal',
        'fa-duotone' => 'fad',
    ];

    private static ?array $map = null;

    /**
     * @return array{icon: array{unicode:string,type:string,weight:string}, exact: bool, name: string}
     */
    public static function fromClass( string $class ): array {
        $tokens = preg_split( '/\s+/', trim( $class ) ) ?: [];
        $name   = '';
        $prefix = '';

        foreach ( $tokens as $token ) {
            if ( isset( self::STYLE_TOKENS[ $token ] ) ) {
                $prefix = self::STYLE_TOKENS[ $token ];
                continue;
            }
            if ( str_starts_with( $token, 'fa-' ) ) {
                $name = substr( $token, 3 );
            }
        }

        if ( $name === '' ) {
            return [ 'icon' => self::FALLBACK, 'exact' => false, 'name' => trim( $class ) ];
        }

        $entry = self::map()[ strtolower( $name ) ] ?? null;
        if ( $entry === null ) {
            return [ 'icon' => self::FALLBACK, 'exact' => false, 'name' => $name ];
        }

        $weights = $entry['w'] ?? [ 900 ];
        $wanted  = in_array( $prefix, [ 'far', 'fab', 'fal' ], true ) ? 400 : 900;
        $weight  = in_array( $wanted, $weights, true ) ? $wanted : (int) reset( $weights );

        return [
            'icon'  => [ 'unicode' => (string) $entry['u'], 'type' => 'fa', 'weight' => (string) $weight ],
            'exact' => true,
            'name'  => $name,
        ];
    }

    public static function isFontAwesome( string $class ): bool {
        return (bool) preg_match( '/(^|\s)fa-[a-z0-9-]+/i', $class );
    }

    /** @return array<string,array{u:string,w:int[]}> */
    private static function map(): array {
        if ( self::$map === null ) {
            $file      = WBDC_PLUGIN_DIR . 'data/fa-icons.json';
            $decoded   = is_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
            self::$map = is_array( $decoded ) ? $decoded : [];
        }

        return self::$map;
    }
}
