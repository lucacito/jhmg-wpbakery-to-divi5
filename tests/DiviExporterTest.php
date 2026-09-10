<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Converter\ConverterEngine;
use WPBakeryDivi5Converter\Exporters\DiviExporter;

/**
 * The post a conversion writes: Divi's own metas, the converter's `_wbdc_*`
 * record of what it did, and slashed block content.
 */
final class DiviExporterTest extends TestCase {

    protected function setUp(): void {
        wbdc_test_reset_hooks();
    }

    public function test_export_produces_the_divi_meta(): void {
        $meta = ( new DiviExporter() )->export( [
            'divi'        => [ 'elements' => [] ],
            'unsupported' => [ [ 'id' => 'vc_flickr-1', 'tag' => 'vc_flickr' ] ],
            'report'      => [ 'converted' => [] ],
        ] );

        $this->assertSame( 'on', $meta['_et_pb_use_builder'] );
        $this->assertSame( 'on', $meta['_et_pb_use_divi_5'] );
        $this->assertSame( 'VB|Divi|5.0.0', $meta['_et_builder_version'] );
        $this->assertSame(
            [ [ 'id' => 'vc_flickr-1', 'tag' => 'vc_flickr' ] ],
            json_decode( $meta['_wbdc_conversion_report'], true )['unsupported']
        );
        $this->assertArrayHasKey( '_wbdc_divi_data', $meta );
    }

    public function test_save_writes_slashed_block_content_and_meta(): void {
        $converted = ( new ConverterEngine() )->convert( [
            'content' => (string) file_get_contents( __DIR__ . '/../fixtures/wpbakery/simple-row.txt' ),
        ] );
        $post_id = wp_insert_post( [ 'post_type' => 'page', 'post_content' => '' ] );

        $this->assertTrue( ( new DiviExporter() )->save( $post_id, $converted ) );

        $post = get_post( $post_id );
        $this->assertStringContainsString( '<!-- wp:divi/section', $post->post_content );
        $this->assertStringContainsString( 'Hello world', $post->post_content );
        $this->assertStringContainsString( '\\"builderVersion\\"', $post->post_content, 'content is slashed for wp_update_post' );
        $this->assertSame( 'on', get_post_meta( $post_id, '_et_pb_use_divi_5', true ) );
        $this->assertSame( 1, json_decode( get_post_meta( $post_id, '_wbdc_conversion_report', true ), true )['converted']['section'] );
    }

    public function test_save_keeps_the_json_meta_readable_when_the_report_quotes_a_value(): void {
        // `update_metadata()` unslashes what it is handed, so a JSON string
        // that is not wp_slash()ed loses every `\"` and stops being JSON. The
        // heading's link carries a title and a rel Divi's link has nowhere to
        // put, so this report quotes them — which is all it takes.
        $converted = ( new ConverterEngine() )->convert( [
            'content' => (string) file_get_contents( __DIR__ . '/../fixtures/wpbakery/custom-heading.txt' ),
        ] );
        $post_id = wp_insert_post( [ 'post_type' => 'page', 'post_content' => '' ] );

        $this->assertTrue( ( new DiviExporter() )->save( $post_id, $converted ) );

        $report = json_decode( (string) get_post_meta( $post_id, '_wbdc_conversion_report', true ), true );
        $this->assertIsArray( $report, 'the stored conversion report is still valid JSON' );
        $this->assertStringContainsString( '"About us"', (string) $report['not_carried_over'][0]['detail'] );
        $this->assertIsArray(
            json_decode( (string) get_post_meta( $post_id, '_wbdc_divi_data', true ), true ),
            'the stored block tree is still valid JSON'
        );
    }

    public function test_save_fails_without_writing_meta_when_the_post_cannot_be_updated(): void {
        $converted = ( new ConverterEngine() )->convert( [ 'content' => '[vc_row][vc_column][/vc_column][/vc_row]' ] );

        // 4242 is not in $GLOBALS['__test_posts'], so wp_update_post() fails.
        $this->assertFalse( ( new DiviExporter() )->save( 4242, $converted ) );
        $this->assertSame( '', get_post_meta( 4242, '_et_pb_use_divi_5', true ), 'a post that took no content is not marked as a Divi 5 page' );
    }

    public function test_save_records_the_source_post(): void {
        $converted = ( new ConverterEngine() )->convert( [ 'content' => '[vc_row][vc_column][/vc_column][/vc_row]' ] );
        $post_id   = wp_insert_post( [ 'post_type' => 'page', 'post_content' => '' ] );

        ( new DiviExporter() )->save( $post_id, $converted, 42 );

        $this->assertSame( 42, (int) get_post_meta( $post_id, '_wbdc_source_post_id', true ) );
    }
}
