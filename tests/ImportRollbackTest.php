<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\History\ImportHistory;
use WPBakeryDivi5Converter\History\ImportRollback;

/**
 * Undo trashes, never deletes, and only what this plugin still owns.
 */
final class ImportRollbackTest extends TestCase {

    protected function setUp(): void {
        wbdc_test_reset_hooks();
        $GLOBALS['__test_posts']       = [];
        $GLOBALS['__test_postmeta']    = [];
        $GLOBALS['__test_trashed']     = [];
        $GLOBALS['__test_trash_fails'] = [];
        unset( $GLOBALS['__test_caps'] );
    }

    public function test_it_trashes_only_posts_the_plugin_still_owns(): void {
        wp_insert_post( [ 'post_type' => 'page', 'ID' => 300 ] );
        wp_insert_post( [ 'post_type' => 'page', 'ID' => 301 ] );
        update_post_meta( 300, '_wbdc_import_source', 'direct' );

        $history = new ImportHistory();
        $history->record( 'run-a', [ [ 'success' => true, 'post_id' => 300 ], [ 'success' => true, 'post_id' => 301 ] ] );

        $result = ( new ImportRollback( $history ) )->rollback( 'run-a' );

        $this->assertSame( [ 'trashed' => 1, 'skipped' => 1, 'trash_unavailable' => false ], $result );
        $this->assertSame( [ 300 ], $GLOBALS['__test_trashed'] );
        $this->assertTrue( $history->find( 'run-a' )['rolled_back'] );
    }

    public function test_a_post_that_refuses_to_trash_counts_as_skipped(): void {
        wp_insert_post( [ 'post_type' => 'page', 'ID' => 310 ] );
        update_post_meta( 310, '_wbdc_import_source', 'file_upload' );
        $GLOBALS['__test_trash_fails'] = [ 310 ];

        $history = new ImportHistory();
        $history->record( 'run-c', [ [ 'success' => true, 'post_id' => 310 ] ] );

        $this->assertSame(
            [ 'trashed' => 0, 'skipped' => 1, 'trash_unavailable' => false ],
            ( new ImportRollback( $history ) )->rollback( 'run-c' )
        );
    }

    public function test_rollback_of_an_unknown_run_does_nothing(): void {
        $this->assertSame(
            [ 'trashed' => 0, 'skipped' => 0, 'trash_unavailable' => false ],
            ( new ImportRollback() )->rollback( 'nope' )
        );
    }

    public function test_notice_markup(): void {
        $rollback = new ImportRollback();

        $this->assertStringContainsString( '2 pages were moved to the Trash', $rollback->notice_markup( [ 'trashed' => 2, 'skipped' => 0, 'trash_unavailable' => false ] ) );
        $this->assertStringContainsString( 'empty the Trash immediately', $rollback->notice_markup( [ 'trashed' => 0, 'skipped' => 3, 'trash_unavailable' => true ] ) );
        $this->assertStringContainsString( 'Nothing to undo', $rollback->notice_markup( [ 'trashed' => 0, 'skipped' => 0, 'trash_unavailable' => false ] ) );
        $this->assertStringContainsString( 'wbdc-rollback-notice', $rollback->notice_markup( [ 'trashed' => 1 ] ) );
    }

    public function test_the_request_handler_needs_the_capability_and_the_nonce(): void {
        wp_insert_post( [ 'post_type' => 'page', 'ID' => 320 ] );
        update_post_meta( 320, '_wbdc_import_source', 'direct' );
        $history = new ImportHistory();
        $history->record( 'run-d', [ [ 'success' => true, 'post_id' => 320 ] ] );

        $rollback = new ImportRollback( $history );

        $_GET = [ ImportRollback::QUERY_ACTION => 'run-d', '_wpnonce' => 'wrong' ];
        $rollback->maybe_handle_request();
        $this->assertSame( [], $GLOBALS['__test_trashed'], 'a bad nonce changes nothing' );

        $GLOBALS['__test_caps']            = false;
        $_GET['_wpnonce']                  = wp_create_nonce( ImportRollback::NONCE_ACTION );
        $rollback->maybe_handle_request();
        $this->assertSame( [], $GLOBALS['__test_trashed'], 'without manage_options nothing is trashed' );

        $GLOBALS['__test_caps'] = true;
        $rollback->maybe_handle_request();
        $this->assertSame( [ 320 ], $GLOBALS['__test_trashed'] );

        $_GET = [];
    }
}
