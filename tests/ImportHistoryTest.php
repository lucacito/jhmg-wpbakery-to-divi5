<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\History\ImportHistory;

/**
 * The durable record of recent runs.
 *
 * The results themselves live in a one-hour transient; the coverage panel
 * needs the element tags across runs and Undo needs the post ids long after
 * that hour, so both read from here instead.
 */
final class ImportHistoryTest extends TestCase {

    protected function setUp(): void {
        wbdc_test_reset_hooks();
        $GLOBALS['__test_posts']    = [];
        $GLOBALS['__test_postmeta'] = [];
    }

    public function test_it_records_runs_newest_first_and_caps_them(): void {
        $history = new ImportHistory();
        for ( $i = 1; $i <= 27; $i++ ) {
            $history->record( "run-{$i}", [ [ 'success' => true, 'post_id' => $i, 'unsupported' => [ [ 'id' => 'vc_pie-1', 'tag' => 'vc_pie' ] ] ] ] );
        }

        $runs = $history->all();

        $this->assertCount( ImportHistory::MAX_RUNS, $runs );
        $this->assertSame( 'run-27', $runs[0]['id'] );
        $this->assertSame( [ 27 ], $runs[0]['post_ids'] );
        $this->assertSame( [ 'vc_pie' ], $runs[0]['unsupported'] );
        $this->assertSame( 25, $history->coverage()[0]['runs'] );
        $this->assertNull( $history->find( 'run-1' ), 'the oldest runs were evicted' );
    }

    public function test_it_reads_the_tag_of_an_unsupported_wpbakery_element(): void {
        $this->assertSame(
            [ 'vc_pie', 'vc_zigzag' ],
            ImportHistory::element_types( [
                [ 'unsupported' => [ [ 'id' => 'a', 'tag' => 'vc_pie' ], [ 'id' => 'b', 'tag' => 'vc_pie' ] ] ],
                [ 'unsupported' => [ [ 'id' => 'c', 'tag' => 'vc_zigzag' ] ] ],
            ] )
        );
    }

    /** Theme families are recorded by key, never by the element's own tag or its content. */
    public function test_it_records_theme_families_by_key(): void {
        $history = new ImportHistory();
        $history->record( 'run-a', [
            [ 'success' => true, 'post_id' => 3, 'report' => [ 'theme_elements' => [ 'Ronneby' => 4, 'Ultimate Addons' => 1, 'Nobody At All' => 2 ] ] ],
        ] );

        $this->assertSame( [ 'ronneby', 'ultimate-addons', 'other' ], $history->all()[0]['theme_families'] );
        $this->assertSame( [ 'ronneby', 'ultimate-addons', 'other' ], $history->theme_families() );
    }

    public function test_it_ignores_the_skipped_row_when_counting_failures(): void {
        $history = new ImportHistory();
        $history->record( 'r', [ [ 'success' => true, 'post_id' => 3 ], [ 'success' => false, 'skipped' => true, 'post_id' => 0 ] ] );

        $this->assertSame( 1, $history->all()[0]['succeeded'] );
        $this->assertSame( 0, $history->all()[0]['failed'] );
    }

    public function test_coverage_ranks_by_how_many_runs_an_element_appeared_in(): void {
        $history = new ImportHistory();
        $history->record( 'r1', [ [ 'success' => true, 'post_id' => 1, 'unsupported' => [ [ 'tag' => 'vc_pie' ], [ 'tag' => 'vc_zigzag' ] ] ] ] );
        $history->record( 'r2', [ [ 'success' => true, 'post_id' => 2, 'unsupported' => [ [ 'tag' => 'vc_pie' ] ] ] ] );

        $coverage = $history->coverage();

        $this->assertSame( 'vc_pie', $coverage[0]['type'] );
        $this->assertSame( 2, $coverage[0]['runs'] );
        $this->assertSame( 'vc_zigzag', $coverage[1]['type'] );
        $this->assertNotSame( '', $coverage[0]['last_seen'] );
    }

    public function test_marking_a_run_rolled_back_sticks(): void {
        $history = new ImportHistory();
        $history->record( 'run-b', [ [ 'success' => true, 'post_id' => 9 ] ] );

        $history->mark_rolled_back( 'run-b' );

        $this->assertTrue( $history->find( 'run-b' )['rolled_back'] );
    }

    public function test_a_corrupt_option_reads_as_no_history(): void {
        update_option( ImportHistory::OPTION, 'not an array' );

        $this->assertSame( [], ( new ImportHistory() )->all() );
    }

    public function test_it_records_how_the_run_converted(): void {
        $history = new ImportHistory();
        $history->record( 'in-place-run', [ [ 'success' => true, 'post_id' => 9, 'in_place' => true ] ] );
        $history->record( 'copy-run', [ [ 'success' => true, 'post_id' => 10, 'in_place' => false ] ] );

        // Undo has to know which it was: one puts a page back, the other trashes
        // a page it made, and only the second one needs the Trash at all.
        $this->assertTrue( $history->find( 'in-place-run' )['in_place'] );
        $this->assertFalse( $history->find( 'copy-run' )['in_place'] );
    }
}
