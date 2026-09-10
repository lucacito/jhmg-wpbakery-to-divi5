<?php
/**
 * The licence client against the queued-HTTP stub. Soft enforcement: a lapsed
 * or missing licence gates update delivery and admin notices, never a
 * conversion feature.
 */

use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Pro\Licensing\LicenseClient;
use WPBakeryDivi5Converter\Pro\Licensing\LicensePage;

final class LicenseClientTest extends TestCase {

    protected function setUp(): void {
        wbdc_test_reset_hooks();
        $GLOBALS['wbdc_test_http'] = [ 'queue' => [], 'log' => [] ];
    }

    private function client(): LicenseClient {
        return new LicenseClient(
            'wpbakery-to-divi5-pro',
            '1.0.0',
            'https://license.test',
            'jhmg-converter-for-wpbakery-to-divi-pro/jhmg-converter-for-wpbakery-to-divi-pro.php',
            'wbdcp-pro',
            'https://divi5lab.com/plugins/wpbakery-to-divi-5',
            'wbdcp'
        );
    }

    public function test_activation_stores_the_key_and_state_under_the_wbdcp_prefix(): void {
        wbdc_test_http_queue( [ 'code' => 200, 'body' => [ 'status' => 'active', 'expires' => '2027-09-09' ] ] );

        $result = $this->client()->activate( 'KEY-123' );

        $this->assertTrue( $result['ok'] );
        $this->assertSame( 'KEY-123', get_option( 'wbdcp_license_key' ) );
        $this->assertSame( 'active', get_option( 'wbdcp_license_state' )['status'] );
        $this->assertSame( 'https://license.test/api/license/activate', $GLOBALS['wbdc_test_http']['log'][0]['url'] );
        $this->assertSame( 'wpbakery-to-divi5-pro', json_decode( $GLOBALS['wbdc_test_http']['log'][0]['args']['body'], true )['product'] );
    }

    public function test_a_rejected_key_is_reported_and_nothing_is_stored(): void {
        wbdc_test_http_queue( [ 'code' => 404, 'body' => [ 'error' => 'invalid_key' ] ] );

        $result = $this->client()->activate( 'BAD' );

        $this->assertFalse( $result['ok'] );
        $this->assertSame( 'invalid_key', $result['error'] );
        $this->assertNull( $this->client()->get_key() );
    }

    public function test_a_forced_refresh_revalidates_and_stores_the_verdict(): void {
        update_option( 'wbdcp_license_key', 'KEY-123' );
        update_option( 'wbdcp_license_state', [ 'status' => 'active', 'expires' => null, 'checked_at' => time() ] );
        wbdc_test_http_queue( [ 'code' => 200, 'body' => [ 'status' => 'expired', 'expires' => '2025-01-01' ] ] );

        $this->client()->refresh( true );

        $this->assertSame( 'expired', get_option( 'wbdcp_license_state' )['status'] );
        $this->assertSame( 'https://license.test/api/license/validate', $GLOBALS['wbdc_test_http']['log'][0]['url'] );
    }

    public function test_a_fresh_state_is_not_revalidated_on_every_admin_load(): void {
        update_option( 'wbdcp_license_key', 'KEY-123' );
        update_option( 'wbdcp_license_state', [ 'status' => 'active', 'expires' => null, 'checked_at' => time() ] );

        $this->client()->refresh();

        $this->assertSame( [], $GLOBALS['wbdc_test_http']['log'] );
    }

    public function test_a_server_blip_never_downgrades_the_stored_verdict(): void {
        update_option( 'wbdcp_license_key', 'KEY-123' );
        update_option( 'wbdcp_license_state', [ 'status' => 'active', 'expires' => null, 'checked_at' => 0 ] );
        wbdc_test_http_queue( [ 'code' => 503, 'body' => [] ] );

        $this->client()->refresh();

        $this->assertSame( 'active', get_option( 'wbdcp_license_state' )['status'] );
    }

    public function test_update_check_injects_a_package_only_when_the_server_offers_one(): void {
        $basename = 'jhmg-converter-for-wpbakery-to-divi-pro/jhmg-converter-for-wpbakery-to-divi-pro.php';

        update_option( 'wbdcp_license_key', 'KEY-123' );
        wbdc_test_http_queue( [ 'code' => 200, 'body' => [ 'update' => true, 'version' => '1.1.0', 'package' => 'https://license.test/pro-1.1.0.zip' ] ] );
        $transient = $this->client()->inject_update( (object) [ 'response' => [] ] );
        $this->assertSame( '1.1.0', $transient->response[ $basename ]->new_version );

        wbdc_test_reset_hooks();
        $GLOBALS['wbdc_test_http'] = [ 'queue' => [], 'log' => [] ];
        update_option( 'wbdcp_license_key', 'KEY-123' );
        wbdc_test_http_queue( [ 'code' => 200, 'body' => [ 'update' => true, 'version' => '1.1.0' ] ] );
        $blocked = $this->client()->inject_update( (object) [ 'response' => [] ] );
        $this->assertSame( [], $blocked->response );
        $this->assertSame( '1.1.0', get_option( 'wbdcp_update_blocked' ) );
    }

    public function test_deactivation_clears_every_wbdcp_option(): void {
        update_option( 'wbdcp_license_key', 'KEY-123' );
        update_option( 'wbdcp_license_state', [ 'status' => 'active' ] );
        update_option( 'wbdcp_update_blocked', '1.1.0' );
        wbdc_test_http_queue( [ 'code' => 200, 'body' => [ 'ok' => true ] ] );

        $this->client()->deactivate();

        $this->assertFalse( get_option( 'wbdcp_license_key' ) );
        $this->assertFalse( get_option( 'wbdcp_license_state' ) );
        $this->assertFalse( get_option( 'wbdcp_update_blocked' ) );
    }

    public function test_the_licence_tab_asks_for_a_key_and_then_shows_a_masked_one(): void {
        $page = new LicensePage( $this->client() );

        $this->assertStringContainsString( 'name="wbdcp_license_key"', $page->markup() );

        update_option( 'wbdcp_license_key', 'KEY-1234-5678' );
        update_option( 'wbdcp_license_state', [ 'status' => 'active', 'expires' => '2027-09-09' ] );
        $active = $page->markup();

        $this->assertStringNotContainsString( 'KEY-1234-5678', $active );
        $this->assertStringContainsString( 'active', $active );
        $this->assertStringContainsString( 'wbdcp_action=deactivate_license', $active );
    }
}
