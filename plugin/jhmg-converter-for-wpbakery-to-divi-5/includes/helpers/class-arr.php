<?php

namespace WPBakeryDivi5Converter\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Array helpers the declared minimums do not provide: array_is_list() is
 * PHP 8.1, and WordPress only polyfills it from 6.5, while this plugin
 * declares PHP 8.0 and WordPress 5.9.
 */
final class Arr {

    /** True for [] and for arrays whose keys are exactly 0, 1, 2, … in order. */
    public static function isList( array $array ): bool {
        if ( $array === [] ) {
            return true;
        }
        return array_keys( $array ) === range( 0, count( $array ) - 1 );
    }
}
