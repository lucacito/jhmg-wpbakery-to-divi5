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

    /**
     * One fresh update check with `$body` as the server's 200 reply, so each
     * case starts with an empty update-check transient rather than the one the
     * case before it cached.
     *
     * @param mixed $body
     */
    private function injectUpdate( $body ): object {
        wbdc_test_reset_hooks();
        $GLOBALS['wbdc_test_http'] = [ 'queue' => [], 'log' => [] ];
        update_option( 'wbdcp_license_key', 'KEY-123' );
        wbdc_test_http_queue( [ 'code' => 200, 'body' => $body ] );

        return $this->client()->inject_update( (object) [ 'response' => [] ] );
    }

    public function test_a_garbage_but_200_update_reply_injects_nothing(): void {
        // Everything WordPress would download and unpack over the plugin
        // directory on our say-so. A 200 alone is not a reason to say so.
        $replies = [
            'a body that is not an object'        => 'nope',
            'an update flagged with nothing else' => [ 'update' => true ],
            'a package that is not a string'      => [ 'update' => true, 'version' => '1.1.0', 'package' => [ 'https://license.test/pro.zip' ] ],
            "a package on somebody else's host"   => [ 'update' => true, 'version' => '1.1.0', 'package' => 'https://evil.test/pro-1.1.0.zip' ],
            'a package on a look-alike host'      => [ 'update' => true, 'version' => '1.1.0', 'package' => 'https://license.test.evil.test/pro-1.1.0.zip' ],
            'a plain-http package'                => [ 'update' => true, 'version' => '1.1.0', 'package' => 'http://license.test/pro-1.1.0.zip' ],
            'a package that is not a URL'         => [ 'update' => true, 'version' => '1.1.0', 'package' => 'pro-1.1.0.zip' ],
            'a version that is not newer'         => [ 'update' => true, 'version' => '1.0.0', 'package' => 'https://license.test/pro-1.0.0.zip' ],
            'a version that is not a string'      => [ 'update' => true, 'version' => 1.1, 'package' => 'https://license.test/pro-1.1.0.zip' ],
        ];

        foreach ( $replies as $label => $body ) {
            $this->assertSame( [], $this->injectUpdate( $body )->response, $label );
        }
    }

    public function test_a_well_formed_reply_from_the_licence_host_still_updates(): void {
        $basename = 'jhmg-converter-for-wpbakery-to-divi-pro/jhmg-converter-for-wpbakery-to-divi-pro.php';

        $transient = $this->injectUpdate(
            [ 'update' => true, 'version' => '1.1.0', 'package' => 'https://license.test/pro-1.1.0.zip' ]
        );

        $this->assertSame( '1.1.0', $transient->response[ $basename ]->new_version );
        $this->assertSame( 'https://license.test/pro-1.1.0.zip', $transient->response[ $basename ]->package );
        $this->assertSame( 'https://divi5lab.com/plugins/wpbakery-to-divi-5', $transient->response[ $basename ]->url );
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

    public function test_a_short_key_is_masked_whole(): void {
        // substr(0,4) . substr(-4) hands back the entire key when it is eight
        // characters or shorter — the two halves overlap.
        $this->assertSame( '••••••••', LicensePage::mask( 'KEY-1234' ) );
        $this->assertSame( '•••••', LicensePage::mask( 'SHORT' ) );
        $this->assertSame( '', LicensePage::mask( '' ) );
        $this->assertSame( 'KEY-•5678', LicensePage::mask( 'KEY-15678' ) );

        $page = new LicensePage( $this->client() );
        update_option( 'wbdcp_license_key', 'KEY-1234' );
        update_option( 'wbdcp_license_state', [ 'status' => 'active' ] );

        $this->assertStringNotContainsString( 'KEY-1234', $page->markup() );
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
