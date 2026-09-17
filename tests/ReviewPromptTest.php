<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Admin\ReviewPrompt;

/**
 * The ask happens only once the plugin has demonstrably done its job, and
 * never after a run that failed.
 */
final class ReviewPromptTest extends TestCase {

    protected function setUp(): void {
        wbdc_test_reset_hooks();
        $GLOBALS['__test_user_meta'] = [];
        unset( $GLOBALS['__test_caps'] );
        $_GET = [];
    }

    public function test_it_asks_after_three_clean_conversions_and_respects_dismissal(): void {
        $prompt = new ReviewPrompt( '2026-09-08' );
        $clean  = [ [ 'success' => true ] ];

        $prompt->record_run( [ [ 'success' => true ], [ 'success' => true ] ] );
        $this->assertFalse( $prompt->should_ask( $clean ) );

        $prompt->record_run( $clean );
        $this->assertTrue( $prompt->should_ask( $clean ) );
        $this->assertFalse( $prompt->should_ask( [ [ 'success' => false ] ] ), 'never after a failure' );
        $this->assertTrue(
            $prompt->should_ask( [ [ 'success' => true ], [ 'success' => false, 'skipped' => true ] ] ),
            'the free-limit row is not a failure'
        );

        $prompt->snooze();
        $this->assertFalse( $prompt->should_ask( $clean ) );
        $this->assertTrue( ( new ReviewPrompt( '2026-09-22' ) )->should_ask( $clean ) );

        $prompt->set_state( ReviewPrompt::STATE_DONE );
        $this->assertFalse( ( new ReviewPrompt( '2027-01-01' ) )->should_ask( $clean ) );
        $this->assertStringContainsString( 'Converted 3 pages so far.', $prompt->markup() );
    }

    public function test_a_run_with_no_successes_never_moves_the_counter(): void {
        $prompt = new ReviewPrompt( '2026-09-08' );
        $prompt->record_run( [ [ 'success' => false, 'skipped' => true ] ] );

        $this->assertSame( 0, $prompt->conversion_count() );
    }

    public function test_without_the_capability_it_never_asks(): void {
        $prompt = new ReviewPrompt( '2026-09-08' );
        $prompt->record_run( [ [ 'success' => true ], [ 'success' => true ], [ 'success' => true ] ] );

        $GLOBALS['__test_caps'] = false;
        $this->assertFalse( $prompt->should_ask( [ [ 'success' => true ] ] ) );
    }

    public function test_the_threshold_is_filterable(): void {
        add_filter( 'wbdc_review_prompt_threshold', fn(): int => 1 );
        $prompt = new ReviewPrompt( '2026-09-08' );
        $prompt->record_run( [ [ 'success' => true ] ] );

        $this->assertSame( 1, $prompt->threshold() );
        $this->assertTrue( $prompt->should_ask( [ [ 'success' => true ] ] ) );
    }

    public function test_the_review_url_points_at_this_plugins_own_slug(): void {
        $this->assertSame(
            'https://wordpress.org/support/plugin/jhmg-converter-for-wpbakery-to-divi-5/reviews/#new-post',
            ReviewPrompt::REVIEW_URL
        );
    }

    public function test_the_response_handler_needs_the_capability_and_a_valid_nonce(): void {
        $prompt = new ReviewPrompt( '2026-09-08' );

        $_GET = [ ReviewPrompt::QUERY_ACTION => 'done', '_wpnonce' => 'wrong' ];
        $prompt->maybe_handle_response();
        $this->assertSame( '', get_user_meta( get_current_user_id(), ReviewPrompt::USER_META_KEY, true ) );

        $GLOBALS['__test_caps'] = false;
        $_GET['_wpnonce']       = wp_create_nonce( ReviewPrompt::NONCE_ACTION );
        $prompt->maybe_handle_response();
        $this->assertSame(
            '',
            get_user_meta( get_current_user_id(), ReviewPrompt::USER_META_KEY, true ),
            'without manage_options nothing is written, nonce or no nonce'
        );

        $GLOBALS['__test_caps'] = true;
        $prompt->maybe_handle_response();
        $this->assertSame( ReviewPrompt::STATE_DONE, get_user_meta( get_current_user_id(), ReviewPrompt::USER_META_KEY, true ) );

        $_GET = [];
    }

    public function test_the_markup_offers_all_three_answers(): void {
        $prompt = new ReviewPrompt( '2026-09-08' );
        $prompt->record_run( [ [ 'success' => true ] ] );

        $html = $prompt->markup();

        $this->assertStringContainsString( 'wbdc_review_action=review', $html );
        $this->assertStringContainsString( 'wbdc_review_action=later', $html );
        $this->assertStringContainsString( 'wbdc_review_action=done', $html );
        $this->assertStringContainsString( 'wbdc-review-prompt', $html );
    }
}
