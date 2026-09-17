<?php

namespace WPBakeryDivi5Converter\Pro;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Plugin {
    private static ?Plugin $instance = null;

    public static function instance(): Plugin {
        return self::$instance ??= new self();
    }

    public function init(): void {
        // Priority 20: after the free plugin's own plugins_loaded hook (10).
        add_action( 'plugins_loaded', [ $this, 'register_hooks' ], 20 );
    }

    public function register_hooks(): void {
        if ( function_exists( 'load_plugin_textdomain' ) ) {
            load_plugin_textdomain( 'jhmg-converter-for-wpbakery-to-divi-pro', false, dirname( plugin_basename( WBDCP_PLUGIN_FILE ) ) . '/languages' );
        }

        if ( ! class_exists( \WPBakeryDivi5Converter\Plugin::class ) ) {
            add_action( 'admin_notices', [ $this, 'render_missing_free_notice' ] );
            return;
        }

        add_filter( 'wbdc_pro_active', '__return_true' );

        // The free committer asks this for every WPBakery template it is about
        // to write. An exporter another add-on already answered with is left
        // alone, which is why the filtered value is returned when it is set.
        add_filter( 'wbdc_library_exporter', static function ( $exporter ) {
            return $exporter ?? new Exporters\DiviLibraryExporter();
        } );

        $license = $this->license();
        add_filter( 'pre_set_site_transient_update_plugins', [ $license, 'inject_update' ] );

        if ( is_admin() ) {
            ( new Admin\ProPage( $license ) )->init();
            add_action( 'admin_init', static function () use ( $license ) { $license->refresh(); } );
            add_action( 'admin_notices', [ new Licensing\LicensePage( $license ), 'maybe_render_notice' ] );
        }
    }

    public function license(): Licensing\LicenseClient {
        return new Licensing\LicenseClient(
            WBDCP_PRODUCT_SLUG,
            WBDCP_PLUGIN_VERSION,
            WBDCP_API_BASE,
            plugin_basename( WBDCP_PLUGIN_FILE ),
            Admin\ProPage::MENU_SLUG,
            'https://divi5lab.com/plugins/wpbakery-to-divi-5',
            'wbdcp'
        );
    }

    public function render_missing_free_notice(): void {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__( 'JHMG Converter Pro requires the free "JHMG Converter For WPBakery to Divi 5" plugin. Please install and activate it.', 'jhmg-converter-for-wpbakery-to-divi-pro' );
        echo '</p></div>';
    }
}
