<?php

namespace WPBakeryDivi5Converter\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Maps `WPBakeryDivi5Converter\Converter\Handlers\HeadingConverter` to
 * `includes/converter/handlers/class-heading-converter.php`. Classes directly
 * under the root namespace (Plugin) resolve to includes/helpers/.
 */
class Autoloader {
    public static function register(): void {
        spl_autoload_register( [ self::class, 'autoload' ] );
    }

    public static function autoload( string $class ): void {
        $prefix = 'WPBakeryDivi5Converter\\';

        if ( ! str_starts_with( $class, $prefix ) || str_starts_with( $class, $prefix . 'Pro\\' ) ) {
            return;
        }

        $relative_class = ltrim( substr( $class, strlen( $prefix ) ), '\\' );
        $parts          = explode( '\\', $relative_class );
        $class_name     = array_pop( $parts );
        $directory      = WBDC_PLUGIN_DIR . 'includes/';

        if ( ! empty( $parts ) ) {
            $directory .= implode( '/', array_map( 'strtolower', $parts ) ) . '/';
        }

        $file_name = 'class-' . strtolower( preg_replace( '/([a-z0-9])([A-Z])/', '$1-$2', $class_name ) ) . '.php';
        $path      = $directory . $file_name;

        if ( ! file_exists( $path ) && empty( $parts ) ) {
            $path = WBDC_PLUGIN_DIR . 'includes/helpers/' . $file_name;
        }

        if ( file_exists( $path ) ) {
            require_once $path;
        }
    }
}

Autoloader::register();
