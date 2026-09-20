<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Admin\BatchImporter;
use WPBakeryDivi5Converter\Parsers\WPBakeryImportParser;

/**
 * An upload, converted and written.
 *
 * The importer is a thin orchestrator over the preflight and the committer, so
 * what is tested here is that every page in an upload is converted in one run.
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

    public function test_every_item_in_the_file_converts_in_one_run(): void {
        $results = ( new BatchImporter() )->import( $this->items( 'two-pages.xml' ), [ 'post_status' => 'draft' ] );

        $this->assertCount( 3, $results );
        foreach ( $results as $result ) {
            $this->assertTrue( $result['success'] );
            $this->assertArrayNotHasKey( 'skipped', $result );
        }
        $this->assertSame( 'Home', $results[0]['title'] );
        $this->assertSame( 'file_upload', get_post_meta( $results[0]['post_id'], '_wbdc_import_source', true ) );
        $this->assertSame( 'post', get_post( $results[2]['post_id'] )->post_type, 'without a post_type option the item keeps its own type' );
        $this->assertSame( 'WPBakery template imported as a page draft', end( $results[1]['report']['warnings'] ) );
    }

    public function test_a_post_type_option_overrides_every_items_own_type(): void {
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
}
