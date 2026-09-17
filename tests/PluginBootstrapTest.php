<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Plugin;

final class PluginBootstrapTest extends TestCase {

    public function test_constants_are_defined(): void {
        $this->assertTrue( defined( 'WBDC_PLUGIN_DIR' ) );
        $this->assertSame( '1.1.0', WBDC_PLUGIN_VERSION );
        $this->assertStringEndsWith( 'jhmg-converter-for-wpbakery-to-divi-5/', WBDC_PLUGIN_DIR );
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

    /**
     * Deleting the plugin takes its own rows with it — the options, the user
     * meta, and the transients four screens park their working state in. A
     * transient is two option rows (`_transient_` and `_transient_timeout_`)
     * and none of the four keeps a list of the keys it wrote, so they are
     * swept by prefix; anything that is not this plugin's stays.
     */
    public function test_uninstall_sweeps_the_plugins_options_transients_and_user_meta(): void {
        wbdc_test_reset_hooks();

        $GLOBALS['__test_options'] = [
            'wbdc_import_history'                 => [ 'runs' => [] ],
            'wbdc_telemetry_consent'              => 'yes',
            'wbdc_telemetry_last_sent'            => 12345,
            'wbdc_conversions_total'              => 7,
            '_transient_wbdc_direct_plan_ids_1'   => [ 3 ],
            '_transient_timeout_wbdc_direct_plan_ids_1' => 12345,
            '_transient_wbdc_batch_abc'           => [ 'items' => [] ],
            '_transient_timeout_wbdc_batch_abc'   => 12345,
            '_transient_wbdc_import_items_1'      => [ 'items' => [] ],
            '_transient_timeout_wbdc_import_items_1' => 12345,
            '_transient_wbdc_rollback_notice_1'   => 'undone',
            '_transient_timeout_wbdc_rollback_notice_1' => 12345,
            '_transient_someone_elses_cache'      => 'kept',
            'blogname'                            => 'kept',
        ];
        $GLOBALS['__test_user_meta'] = [ 1 => [ 'wbdc_review_prompt_state' => 'dismissed', 'nickname' => 'kept' ] ];

        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            define( 'WP_UNINSTALL_PLUGIN', 'jhmg-converter-for-wpbakery-to-divi-5/jhmg-converter-for-wpbakery-to-divi-5.php' );
        }

        require WBDC_PLUGIN_DIR . 'uninstall.php';

        $this->assertSame(
            [ '_transient_someone_elses_cache', 'blogname' ],
            array_keys( $GLOBALS['__test_options'] )
        );
        $this->assertSame( [ 1 => [ 'nickname' => 'kept' ] ], $GLOBALS['__test_user_meta'] );
    }
}
