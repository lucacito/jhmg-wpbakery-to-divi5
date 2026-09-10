<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\History\ImportHistory;
use WPBakeryDivi5Converter\Telemetry\CoverageTelemetry;

/**
 * Opt-in, anonymous, and only ever element tags and theme family keys
 * (task-12-amendments §4). No content, no site address, nothing before consent.
 */
final class CoverageTelemetryTest extends TestCase {

    protected function setUp(): void {
        wbdc_test_reset_hooks();
        $GLOBALS['wbdc_test_http'] = [ 'queue' => [], 'log' => [] ];
        unset( $GLOBALS['__test_caps'] );
        $_GET = [];
    }

    private function history(): ImportHistory {
        $history = new ImportHistory();
        $history->record( 'r', [
            [
                'success'     => true,
                'post_id'     => 1,
                'unsupported' => [ [ 'tag' => 'vc_pie' ], [ 'tag' => 'vc_pie' ], [ 'tag' => 'vc_zigzag' ] ],
                'report'      => [ 'theme_elements' => [ 'Ronneby' => 3 ] ],
            ],
        ] );

        return $history;
    }

    public function test_nothing_leaves_the_site_without_consent_and_the_payload_is_tags_only(): void {
        $telemetry = new CoverageTelemetry( $this->history(), '2026-09-08' );

        $telemetry->maybe_send();
        $this->assertSame( [], $GLOBALS['wbdc_test_http']['log'], 'nothing leaves the site without consent' );

        update_option( CoverageTelemetry::CONSENT_OPTION, '1' );
        $telemetry->maybe_send();

        $this->assertCount( 1, $GLOBALS['wbdc_test_http']['log'] );
        $this->assertSame( CoverageTelemetry::ENDPOINT, $GLOBALS['wbdc_test_http']['log'][0]['url'] );
        $this->assertSame(
            [ 'product' => 'wpbakery-to-divi5', 'widget_types' => [ 'vc_pie', 'vc_zigzag', 'ronneby' ] ],
            json_decode( $GLOBALS['wbdc_test_http']['log'][0]['args']['body'], true )
        );
        $this->assertSame( '2026-09-08', get_option( CoverageTelemetry::LAST_SENT_OPTION ) );

        $telemetry->maybe_send();
        $this->assertCount( 1, $GLOBALS['wbdc_test_http']['log'], 'not due again for a week' );
        $this->assertTrue( ( new CoverageTelemetry( $this->history(), '2026-09-15' ) )->due() );
    }

    public function test_the_payload_holds_only_the_two_documented_keys(): void {
        $payload = ( new CoverageTelemetry( $this->history() ) )->payload();

        $this->assertSame( [ 'product', 'widget_types' ], array_keys( $payload ) );
        $this->assertSame( CoverageTelemetry::PRODUCT, $payload['product'] );
    }

    public function test_an_overlong_tag_is_dropped_and_the_list_is_capped(): void {
        $history = new ImportHistory();
        $unsupported = [ [ 'tag' => str_repeat( 'x', CoverageTelemetry::MAX_TYPE_LENGTH + 1 ) ] ];
        for ( $i = 0; $i < CoverageTelemetry::MAX_TYPES + 10; $i++ ) {
            $unsupported[] = [ 'tag' => 'vc_tag_' . $i ];
        }
        $history->record( 'r', [ [ 'success' => true, 'post_id' => 1, 'unsupported' => $unsupported ] ] );

        $types = ( new CoverageTelemetry( $history ) )->payload()['widget_types'];

        $this->assertCount( CoverageTelemetry::MAX_TYPES, $types );
        foreach ( $types as $type ) {
            $this->assertLessThanOrEqual( CoverageTelemetry::MAX_TYPE_LENGTH, strlen( $type ) );
        }
    }

    public function test_nothing_is_sent_when_there_is_nothing_to_report(): void {
        update_option( CoverageTelemetry::CONSENT_OPTION, '1' );

        ( new CoverageTelemetry( new ImportHistory(), '2026-09-08' ) )->maybe_send();

        $this->assertSame( [], $GLOBALS['wbdc_test_http']['log'] );
        $this->assertFalse( get_option( CoverageTelemetry::LAST_SENT_OPTION ) );
    }

    public function test_consent_is_set_only_with_the_capability_and_the_nonce(): void {
        $telemetry = new CoverageTelemetry();

        $_GET = [ CoverageTelemetry::QUERY_ACTION => '1', '_wpnonce' => 'wrong' ];
        $telemetry->maybe_handle_consent();
        $this->assertFalse( $telemetry->has_consent() );

        $GLOBALS['__test_caps'] = false;
        $_GET['_wpnonce']       = wp_create_nonce( CoverageTelemetry::NONCE_ACTION );
        $telemetry->maybe_handle_consent();
        $this->assertFalse( $telemetry->has_consent() );

        $GLOBALS['__test_caps'] = true;
        $telemetry->maybe_handle_consent();
        $this->assertTrue( $telemetry->has_consent() );

        $_GET[ CoverageTelemetry::QUERY_ACTION ] = '0';
        $telemetry->maybe_handle_consent();
        $this->assertFalse( $telemetry->has_consent() );

        $_GET = [];
    }
}
