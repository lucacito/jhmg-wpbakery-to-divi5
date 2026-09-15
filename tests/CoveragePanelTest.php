<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Admin\CoveragePanel;
use WPBakeryDivi5Converter\History\ImportHistory;
use WPBakeryDivi5Converter\Telemetry\CoverageTelemetry;

final class CoveragePanelTest extends TestCase {

    protected function setUp(): void {
        wbdc_test_reset_hooks();
        $GLOBALS['__test_posts']    = [];
        $GLOBALS['__test_postmeta'] = [];
    }

    public function test_nothing_is_shown_before_the_first_run(): void {
        $this->assertSame( '', ( new CoveragePanel( new ImportHistory() ) )->markup() );
    }

    public function test_it_lists_the_gaps_the_consent_line_and_the_undo_controls(): void {
        $history = new ImportHistory();
        $history->record( 'run-x', [ [ 'success' => true, 'post_id' => 9, 'unsupported' => [ [ 'tag' => 'vc_pie' ] ] ] ] );

        $html = ( new CoveragePanel( $history ) )->markup();

        $this->assertStringContainsString( '<code>vc_pie</code>', $html );
        $this->assertStringContainsString( 'Share these element names', $html );
        $this->assertStringContainsString( CoverageTelemetry::QUERY_ACTION, $html );
        $this->assertStringContainsString( 'wbdc_rollback=run-x', $html );
        $this->assertStringContainsString( 'Recent conversions', $html );
    }

    public function test_a_clean_run_says_everything_converted(): void {
        $history = new ImportHistory();
        $history->record( 'run-y', [ [ 'success' => true, 'post_id' => 4 ] ] );

        $html = ( new CoveragePanel( $history ) )->markup();

        $this->assertStringContainsString( 'Everything converted', $html );
        $this->assertStringContainsString( 'Recent conversions', $html );
    }

    public function test_an_undone_run_offers_no_second_undo(): void {
        $history = new ImportHistory();
        $history->record( 'run-z', [ [ 'success' => true, 'post_id' => 5 ] ] );
        $history->mark_rolled_back( 'run-z' );

        $html = ( new CoveragePanel( $history ) )->markup();

        $this->assertStringContainsString( 'Undone', $html );
        $this->assertStringNotContainsString( 'wbdc_rollback=run-z', $html );
    }

    public function test_the_consent_line_changes_once_sharing_is_on(): void {
        $history = new ImportHistory();
        $history->record( 'run-w', [ [ 'success' => true, 'post_id' => 6, 'unsupported' => [ [ 'tag' => 'vc_pie' ] ] ] ] );
        update_option( CoverageTelemetry::CONSENT_OPTION, '1' );

        $html = ( new CoveragePanel( $history ) )->markup();

        $this->assertStringContainsString( 'Stop sharing', $html );
        $this->assertStringContainsString( 'No site address, no content, no personal data', $html );
    }

    public function test_an_in_place_run_is_undoable_even_when_the_trash_is_off(): void {
        $history = new ImportHistory();
        $history->record( 'run-inplace', [ [ 'success' => true, 'post_id' => 9, 'in_place' => true ] ] );

        $html = ( new CoveragePanel( $history ) )->markup();

        // Putting a page back needs no Trash, so the offer stands either way,
        // and it must not promise to trash anything.
        $this->assertStringContainsString( 'wbdc_rollback=run-inplace', $html );
        $this->assertStringContainsString( 'back the way', $html );
        $this->assertStringNotContainsString( 'to the Trash?', $html );
    }

    public function test_a_copy_run_still_says_it_will_trash_what_it_made(): void {
        $history = new ImportHistory();
        $history->record( 'run-copy', [ [ 'success' => true, 'post_id' => 9, 'in_place' => false ] ] );

        $html = ( new CoveragePanel( $history ) )->markup();

        $this->assertStringContainsString( 'to the Trash?', $html );
    }
}
