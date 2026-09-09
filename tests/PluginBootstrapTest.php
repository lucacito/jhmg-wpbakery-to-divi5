<?php

use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Plugin;

final class PluginBootstrapTest extends TestCase {

    public function test_constants_are_defined(): void {
        $this->assertTrue( defined( 'WBDC_PLUGIN_DIR' ) );
        $this->assertSame( '1.0.0', WBDC_PLUGIN_VERSION );
        $this->assertStringEndsWith( 'jhmg-converter-for-wpbakery-to-divi/', WBDC_PLUGIN_DIR );
    }

    public function test_plugin_is_a_singleton(): void {
        $this->assertSame( Plugin::instance(), Plugin::instance() );
    }

    public function test_autoloader_resolves_nested_namespaces_to_kebab_case_files(): void {
        $this->assertTrue( class_exists( \WPBakeryDivi5Converter\Helpers\DiviRequirement::class ) );
    }

    public function test_wbdc_loaded_fires_after_hooks_are_registered(): void {
        wbdc_test_reset_hooks();
        $fired = false;
        add_action( 'wbdc_loaded', function () use ( &$fired ) { $fired = true; } );

        Plugin::instance()->register_hooks();

        $this->assertTrue( $fired );
    }

    public function test_vendored_divi5_validator_is_autoloadable(): void {
        $this->assertTrue( class_exists( \Divi5Validator\Validator::class ) );
    }
}
