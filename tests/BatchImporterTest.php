<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Admin\BatchImporter;
use WPBakeryDivi5Converter\Parsers\WPBakeryImportParser;

/**
 * An upload, converted and written.
 *
 * The importer is a thin orchestrator over the preflight and the committer, so
 * what is tested here is the thing only it does: an upload is subject to the
 * same per-run limit as a direct conversion, and what the limit left out is
 * said out loud rather than dropped.
 */
final class BatchImporterTest extends TestCase {

    private const DIR = __DIR__ . '/../fixtures/wpbakery-import/';

    protected function setUp(): void {
        wbdc_test_reset_hooks();
        $GLOBALS['__test_posts']    = [];
        $GLOBALS['__test_postmeta'] = [];
    }

    /** @return array[] */
    private function items( string $fixture ): array {
        return ( new WPBakeryImportParser() )->parse( self::DIR . $fixture, $fixture );
    }

    public function test_the_free_plugin_converts_the_first_item_and_explains_the_rest(): void {
        $results = ( new BatchImporter() )->import( $this->items( 'two-pages.xml' ), [ 'post_status' => 'draft' ] );

        $this->assertCount( 2, $results );
        $this->assertTrue( $results[0]['success'] );
        $this->assertSame( 'Home', $results[0]['title'] );
        $this->assertSame( 'file_upload', get_post_meta( $results[0]['post_id'], '_wbdc_import_source', true ) );

        $this->assertTrue( $results[1]['skipped'] );
        $this->assertStringContainsString( '2 more pages', $results[1]['title'] );
        $this->assertStringContainsString( 'Pro add-on', $results[1]['error'] );
    }

    public function test_the_limit_filter_converts_everything(): void {
        add_filter( 'wbdc_direct_conversion_limit', fn(): int => PHP_INT_MAX );

        $results = ( new BatchImporter() )->import( $this->items( 'two-pages.xml' ) );

        $this->assertCount( 3, $results );
        $this->assertSame( 'post', get_post( $results[2]['post_id'] )->post_type, 'without a post_type option the item keeps its own type' );
        $this->assertStringContainsString(
            'Pro turns templates into Divi Library layouts',
            end( $results[1]['report']['warnings'] )
        );
    }

    public function test_a_post_type_option_overrides_every_items_own_type(): void {
        add_filter( 'wbdc_direct_conversion_limit', fn(): int => PHP_INT_MAX );

        $results = ( new BatchImporter() )->import( $this->items( 'two-pages.xml' ), [ 'post_type' => 'page' ] );

        foreach ( $results as $result ) {
            $this->assertSame( 'page', get_post( $result['post_id'] )->post_type );
        }
    }

    public function test_an_export_item_converts_with_its_media_map(): void {
        $results = ( new BatchImporter() )->import( $this->items( 'export.xml' ) );

        $this->assertTrue( $results[0]['success'] );
        $this->assertStringContainsString( 'hero-300x200.jpg', get_post( $results[0]['post_id'] )->post_content );
    }

    public function test_the_singular_message_is_used_for_one_left_over(): void {
        add_filter( 'wbdc_direct_conversion_limit', fn(): int => 2 );

        $results = ( new BatchImporter() )->import( $this->items( 'two-pages.xml' ) );

        $this->assertStringContainsString( '1 more page in this file was not converted', $results[2]['title'] );
    }
}
