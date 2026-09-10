<?php

namespace WPBakeryDivi5Converter\Pro;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Maps `WPBakeryDivi5Converter\Pro\Admin\ProPage` to
 * `includes/admin/class-pro-page.php` — the free plugin's rule, under the Pro
 * namespace, which the free autoloader deliberately skips.
 */
class Autoloader {
    public static function register(): void {
        spl_autoload_register( [ self::class, 'autoload' ] );
    }

    public static function autoload( string $class ): void {
        $prefix = 'WPBakeryDivi5Converter\\Pro\\';
        if ( ! str_starts_with( $class, $prefix ) ) {
            return;
        }
        $parts      = explode( '\\', substr( $class, strlen( $prefix ) ) );
        $class_name = array_pop( $parts );
        $directory  = WBDCP_PLUGIN_DIR . 'includes/' . ( empty( $parts ) ? '' : implode( '/', array_map( 'strtolower', $parts ) ) . '/' );
        $path       = $directory . 'class-' . strtolower( preg_replace( '/([a-z0-9])([A-Z])/', '$1-$2', $class_name ) ) . '.php';
        if ( file_exists( $path ) ) {
            require_once $path;
        }
    }
}

Autoloader::register();
