<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Parsers\WxrReader;

/**
 * The one WXR parser in the repository.
 *
 * A WordPress export is the only thing a WPBakery site can be handed over as —
 * there is no builder-specific export format — so this reader is what stands
 * between a 5 MB theme demo and the converter. It reads posts, their
 * `content:encoded`, their `wp:postmeta` and the attachment items the pages
 * name by id, and it refuses anything that declares a DOCTYPE or an entity,
 * which a real export never does and an attack always does.
 */
final class WxrReaderTest extends TestCase {

    private const DIR = __DIR__ . '/../fixtures/wpbakery-import/';

    private const CORPUS = __DIR__ . '/../wpbakery templates/ronneby/15_tenth.xml';

    protected function setUp(): void {
        wbdc_test_reset_hooks();
    }

    private function read( string $fixture ): array {
        return ( new WxrReader() )->read( (string) file_get_contents( self::DIR . $fixture ) );
    }

    public function test_every_item_carries_its_post_fields(): void {
        $items = $this->read( 'export.xml' );

        $this->assertCount( 4, $items, 'every <item> is returned; filtering is the import parser\'s job' );
        $this->assertSame( 'News List', $items[0]['title'] );
        $this->assertSame( 7, $items[0]['post_id'] );
        $this->assertSame( 'page', $items[0]['post_type'] );
        $this->assertSame( 'news-list', $items[0]['post_name'] );
        $this->assertSame( 'publish', $items[0]['status'] );
        $this->assertStringStartsWith( '[vc_row]', $items[0]['content'] );
    }

    /**
     * `]]>` cannot appear inside a CDATA section, so an exporter writing a
     * text block that ends with one has to close the section and open another
     * around it. Both halves are one value.
     */
    public function test_a_cdata_section_split_around_a_bracket_run_is_read_as_one_value(): void {
        $items = $this->read( 'export.xml' );

        $this->assertStringContainsString( 'because it ends with ]]> and CDATA cannot hold that', $items[0]['content'] );
    }

    public function test_post_meta_is_read_and_comment_meta_is_not(): void {
        $meta = $this->read( 'export.xml' )[0]['meta'];

        $this->assertSame( 'true', $meta['_wpb_vc_js_status'] );
        $this->assertStringContainsString( 'padding-top: 40px', $meta['_wpb_shortcodes_custom_css'] );
        $this->assertSame( '.news-list h2 { letter-spacing: 1px; }', $meta['_wpb_post_custom_css'] );
        $this->assertArrayNotHasKey( 'rating', $meta, 'a comment\'s meta is not the post\'s meta' );
    }

    public function test_attachment_items_carry_their_url(): void {
        $items = $this->read( 'export.xml' );

        $this->assertSame( 'attachment', $items[2]['post_type'] );
        $this->assertSame( 42, $items[2]['post_id'] );
        $this->assertSame( 'https://source.example/wp-content/uploads/2024/01/hero.jpg', $items[2]['attachment_url'] );
    }

    /**
     * A named size cannot be resolved from one URL per id, so the map carries
     * the sizes `_wp_attachment_metadata` lists, each relative to the
     * attachment's own directory (task-11-amendments §5).
     */
    public function test_the_attachment_map_carries_the_sizes_the_metadata_lists(): void {
        $map = WxrReader::attachments( $this->read( 'export.xml' ) );

        $this->assertSame( [ 42, 43 ], array_keys( $map ) );
        $this->assertSame( 'https://source.example/wp-content/uploads/2024/01/hero.jpg', $map[42]['url'] );
        $this->assertSame(
            'https://source.example/wp-content/uploads/2024/01/hero-300x200.jpg',
            $map[42]['sizes']['medium']
        );
        $this->assertSame(
            'https://source.example/wp-content/uploads/2024/01/hero-1024x683.jpg',
            $map[42]['sizes']['large']
        );
        $this->assertSame( [], $map[43]['sizes'], 'an attachment with no metadata still has its URL' );
    }

    public function test_a_doctype_or_entity_declaration_is_refused(): void {
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessageMatches( '/DOCTYPE/' );

        $this->read( 'entity.xml' );
    }

    /**
     * The refusal is about the prolog, which is where a declaration has to be
     * to mean anything. A page that *quotes* one — a raw-HTML element holding
     * a document, a tutorial about HTML — is content like any other, and
     * refusing the whole export over it would be a false alarm on a real site.
     */
    public function test_a_doctype_quoted_inside_a_page_is_content_not_a_declaration(): void {
        $items = ( new WxrReader() )->read( self::wxr(
            '[vc_row][vc_column][vc_raw_html]<!DOCTYPE html><html lang="en"></html>[/vc_raw_html][/vc_column][/vc_row]'
        ) );

        $this->assertCount( 1, $items );
        $this->assertStringContainsString( '<!DOCTYPE html>', $items[0]['content'] );
    }

    public function test_a_declaration_before_the_root_element_is_still_refused(): void {
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessageMatches( '/DOCTYPE/' );

        ( new WxrReader() )->read(
            '<?xml version="1.0"?><!DOCTYPE rss [<!ENTITY x SYSTEM "file:///etc/passwd">]>'
            . '<rss xmlns:wp="http://wordpress.org/export/1.2/"><channel></channel></rss>'
        );
    }

    /** One well-formed export around one page's content. */
    private static function wxr( string $content ): string {
        return '<?xml version="1.0" encoding="UTF-8" ?>'
            . '<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wp="http://wordpress.org/export/1.2/">'
            . '<channel><item>'
            . '<title><![CDATA[A page]]></title>'
            . '<content:encoded><![CDATA[' . $content . ']]></content:encoded>'
            . '<wp:post_id>3</wp:post_id><wp:post_type><![CDATA[page]]></wp:post_type>'
            . '</item></channel></rss>';
    }

    /**
     * An attachment URL with no path at all cannot say what directory a named
     * size's file sits in, and `(int) strrpos()` would silently answer "the
     * first character".
     */
    public function test_an_attachment_url_with_no_path_resolves_no_sizes(): void {
        $map = WxrReader::attachments( [
            [
                'post_type'      => 'attachment',
                'post_id'        => 5,
                'attachment_url' => 'hero.jpg',
                'meta'           => [ '_wp_attachment_metadata' => serialize( [ 'sizes' => [ 'medium' => [ 'file' => 'hero-300x200.jpg' ] ] ] ) ],
            ],
        ] );

        $this->assertSame( 'hero.jpg', $map[5]['url'] );
        $this->assertSame( [], $map[5]['sizes'] );
    }

    public function test_malformed_xml_is_refused(): void {
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessageMatches( '/well-formed/' );

        ( new WxrReader() )->read( '<?xml version="1.0"?><rss><channel><item>' );
    }

    /**
     * The real thing: a DFD Ronneby demo export. One WPBakery page, seventeen
     * attachments, and the page's design-options stylesheet in its postmeta —
     * the numbers task-11-amendments §1 asks this test to pin.
     */
    public function test_a_real_theme_export_reads_end_to_end(): void {
        if ( ! is_readable( self::CORPUS ) ) {
            $this->markTestSkipped( '15_tenth.xml is not in this checkout' );
        }

        $items = ( new WxrReader() )->read( (string) file_get_contents( self::CORPUS ) );

        $this->assertCount( 18, $items );

        $pages = array_values( array_filter( $items, static fn( array $i ): bool => $i['post_type'] === 'page' ) );
        $this->assertCount( 1, $pages );
        $this->assertSame( 'Tenth layout', $pages[0]['title'] );
        $this->assertSame( 7, $pages[0]['post_id'] );
        $this->assertStringContainsString( '[vc_row', $pages[0]['content'] );
        $this->assertStringContainsString( '.vc_custom_', $pages[0]['meta']['_wpb_shortcodes_custom_css'] );

        $map = WxrReader::attachments( $items );
        $this->assertCount( 17, $map );
        $this->assertSame( 'https://rnbtheme.com/tenth/wp-content/uploads/sites/11/2017/04/olen.jpg', $map[12]['url'] );
        $this->assertSame(
            'https://rnbtheme.com/tenth/wp-content/uploads/sites/11/2017/04/olen-300x224.jpg',
            $map[12]['sizes']['medium']
        );
    }
}
