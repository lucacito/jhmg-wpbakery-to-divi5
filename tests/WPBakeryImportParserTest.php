<?php

namespace WPBakeryDivi5Converter\Tests;

use WPBakeryDivi5Converter\Converter\ConverterEngine;
use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Parsers\WPBakeryImportParser;

/**
 * An uploaded file turned into conversion items.
 *
 * Two formats, because those are the two a WPBakery site can be handed over
 * as: a WordPress export (WXR), and the page's shortcodes pasted into a
 * `.txt`/`.html` file. Neither is a builder export — WPBakery has none — so
 * everything the converter needs has to be found in the post content and the
 * three metas beside it, plus the attachment items an export carries.
 */
final class WPBakeryImportParserTest extends TestCase {

    private const DIR = __DIR__ . '/../fixtures/wpbakery-import/';

    protected function setUp(): void {
        wbdc_test_reset_hooks();
        $GLOBALS['__test_posts']    = [];
        $GLOBALS['__test_postmeta'] = [];
    }

    private function parse( string $fixture ): array {
        return ( new WPBakeryImportParser() )->parse( self::DIR . $fixture, $fixture );
    }

    public function test_only_the_items_holding_a_wpbakery_layout_become_items(): void {
        $items = $this->parse( 'export.xml' );

        $this->assertCount( 1, $items, 'the plain post and the two attachments are not layouts' );
        $this->assertSame( 'News List', $items[0]['title'] );
        $this->assertSame( 'page', $items[0]['post_type'] );
        $this->assertSame( 'news-list', $items[0]['post_name'] );
        $this->assertSame( '', $items[0]['template_type'] );
        $this->assertSame( 'import', $items[0]['mode'] );
        $this->assertSame( '', $items[0]['error'] );
        $this->assertSame( [ 'kind' => 'upload', 'post_id' => null, 'file' => 'export.xml' ], $items[0]['source_ref'] );
        $this->assertStringStartsWith( '[vc_row]', $items[0]['content'] );
    }

    public function test_the_three_wpbakery_metas_travel_with_the_content(): void {
        $meta = $this->parse( 'export.xml' )[0]['meta'];

        $this->assertSame( 'true', $meta['_wpb_vc_js_status'] );
        $this->assertStringContainsString( 'padding-top: 40px', $meta['_wpb_shortcodes_custom_css'] );
        $this->assertSame( '.news-list h2 { letter-spacing: 1px; }', $meta['_wpb_post_custom_css'] );
    }

    public function test_the_attachment_map_reaches_the_conversion(): void {
        $item   = $this->parse( 'export.xml' )[0];
        $result = ( new ConverterEngine() )->convert(
            [ 'content' => $item['content'], 'meta' => $item['meta'] ],
            [ 'mode' => $item['mode'], 'attachments' => $item['attachments'] ]
        );

        $blocks = wp_json_encode( $result['divi'] );

        $this->assertStringContainsString( 'hero-300x200.jpg', $blocks, 'img_size="medium" resolves through the size map' );
        $this->assertSame(
            [ [ 'node_id' => 'vc_single_image-2', 'attachment_id' => 99 ] ],
            $result['report']['unresolved_media'],
            'an id the export does not carry is reported, not guessed at'
        );
    }

    /**
     * `vc4_templates` and `templatera` posts are WPBakery's own template
     * library. The Pro add-on turns them into Divi Library layouts; the free
     * plugin converts them as pages and says so (task-11-amendments §2).
     */
    public function test_a_template_post_is_marked_for_the_library_path(): void {
        $items = $this->parse( 'two-pages.xml' );

        $this->assertCount( 3, $items );
        $this->assertSame( 'library', $items[1]['template_type'] );
        $this->assertSame( 'page', $items[1]['post_type'] );
        $this->assertSame( 'vc4_templates', $items[1]['source_post_type'] );
    }

    public function test_a_post_keeps_its_type_and_a_page_keeps_its_own(): void {
        $items = $this->parse( 'two-pages.xml' );

        $this->assertSame( 'page', $items[0]['post_type'] );
        $this->assertSame( 'post', $items[2]['post_type'] );
        $this->assertSame( 'post', $items[2]['source_post_type'] );
    }

    public function test_a_text_upload_is_one_item_titled_from_the_file_name(): void {
        $items = $this->parse( 'page.txt' );

        $this->assertCount( 1, $items );
        $this->assertSame( 'Page', $items[0]['title'] );
        $this->assertSame( 'page', $items[0]['post_type'] );
        $this->assertSame( [], $items[0]['meta'] );
        $this->assertSame( [], $items[0]['attachments'] );
        $this->assertStringContainsString( 'Pasted from the editor', $items[0]['content'] );
        $this->assertSame( [ 'kind' => 'upload', 'post_id' => null, 'file' => 'page.txt' ], $items[0]['source_ref'] );
    }

    public function test_a_file_declaring_entities_is_refused(): void {
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessageMatches( '/DOCTYPE/' );

        $this->parse( 'entity.xml' );
    }

    public function test_an_empty_file_is_refused(): void {
        $tmp = (string) tempnam( sys_get_temp_dir(), 'wbdc' );
        file_put_contents( $tmp, "  \n" );

        try {
            $this->expectException( \RuntimeException::class );
            $this->expectExceptionMessage( 'Import file is empty.' );
            ( new WPBakeryImportParser() )->parse( $tmp, 'empty.txt' );
        } finally {
            unlink( $tmp );
        }
    }

    public function test_an_unreadable_file_is_refused(): void {
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'Import file is not readable.' );

        ( new WPBakeryImportParser() )->parse( self::DIR . 'nothing-here.xml', 'nothing-here.xml' );
    }

    public function test_a_file_that_is_neither_an_export_nor_wpbakery_content_is_refused(): void {
        $tmp = (string) tempnam( sys_get_temp_dir(), 'wbdc' );
        file_put_contents( $tmp, "Shopping list\n- milk\n" );

        try {
            ( new WPBakeryImportParser() )->parse( $tmp, 'notes.txt' );
            $this->fail( 'expected an exception' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'Unrecognised file', $e->getMessage() );
        } finally {
            unlink( $tmp );
        }
    }

    public function test_an_export_with_no_wpbakery_page_in_it_says_so(): void {
        $tmp = (string) tempnam( sys_get_temp_dir(), 'wbdc' );
        file_put_contents(
            $tmp,
            '<?xml version="1.0"?><rss xmlns:wp="http://wordpress.org/export/1.2/"><channel>'
            . '<item><title>Plain</title><wp:post_type><![CDATA[post]]></wp:post_type></item>'
            . '</channel></rss>'
        );

        try {
            ( new WPBakeryImportParser() )->parse( $tmp, 'plain.xml' );
            $this->fail( 'expected an exception' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'No WPBakery', $e->getMessage() );
        } finally {
            unlink( $tmp );
        }
    }
}
