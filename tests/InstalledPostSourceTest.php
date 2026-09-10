<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Conversion\InstalledPostSource;

/**
 * Conversion input read off a post on this site.
 *
 * Strictly read-only, and the test says so twice over: the source post is
 * never written to, and everything the converter gets is `post_content` plus
 * the three metas WPBakery writes beside it. A conversion always creates a new
 * post, so a conversion nobody wanted can never cost somebody their WPBakery
 * original.
 */
final class InstalledPostSourceTest extends TestCase {

    protected function setUp(): void {
        wbdc_test_reset_hooks();
        $GLOBALS['__test_posts']    = [];
        $GLOBALS['__test_postmeta'] = [];
    }

    private function seed( int $id, string $title = 'Home', string $type = 'page', string $content = '' ): void {
        $GLOBALS['__test_posts'][ $id ] = (object) [
            'ID'           => $id,
            'post_title'   => $title,
            'post_name'    => strtolower( str_replace( ' ', '-', $title ) ),
            'post_type'    => $type,
            'post_status'  => 'publish',
            'post_content' => $content !== '' ? $content : '[vc_row][vc_column][vc_column_text]Hi[/vc_column_text][/vc_column][/vc_row]',
        ];
        update_post_meta( $id, '_wpb_vc_js_status', 'true' );
        update_post_meta( $id, '_wpb_shortcodes_custom_css', '.vc_custom_1{color:red}' );
    }

    public function test_a_wpbakery_page_is_read_with_its_content_and_metas(): void {
        $this->seed( 10, 'Landing' );

        $items = ( new InstalledPostSource( [ 10 ] ) )->items();

        $this->assertCount( 1, $items );
        $this->assertSame( 'Landing', $items[0]['title'] );
        $this->assertSame( 'page', $items[0]['post_type'] );
        $this->assertSame( 'landing', $items[0]['post_name'] );
        $this->assertSame( '', $items[0]['template_type'] );
        $this->assertSame( 'direct', $items[0]['mode'] );
        $this->assertSame( '', $items[0]['error'] );
        $this->assertStringStartsWith( '[vc_row]', $items[0]['content'] );
        $this->assertSame( 'true', $items[0]['meta']['_wpb_vc_js_status'] );
        $this->assertSame( '.vc_custom_1{color:red}', $items[0]['meta']['_wpb_shortcodes_custom_css'] );
        $this->assertSame( '', $items[0]['meta']['_wpb_post_custom_css'] );
        $this->assertSame( [], $items[0]['attachments'], 'on this site the media library answers, not a map' );
        $this->assertSame( [ 'kind' => 'installed', 'post_id' => 10, 'file' => null ], $items[0]['source_ref'] );
    }

    /** Only the three metas WPBakery writes; nothing else on the post is read. */
    public function test_no_meta_but_wpbakerys_own_three_is_carried(): void {
        $this->seed( 11 );
        update_post_meta( 11, '_thumbnail_id', '77' );
        update_post_meta( 11, 'dfd_animation_style', 'fade' );

        $meta = ( new InstalledPostSource( [ 11 ] ) )->items()[0]['meta'];

        $this->assertSame(
            [ '_wpb_shortcodes_custom_css', '_wpb_post_custom_css', '_wpb_vc_js_status' ],
            array_keys( $meta )
        );
    }

    public function test_a_post_with_no_wpbakery_content_is_refused_by_name(): void {
        $GLOBALS['__test_posts'][12] = (object) [
            'ID'           => 12,
            'post_title'   => 'Classic',
            'post_name'    => 'classic',
            'post_type'    => 'page',
            'post_content' => '<p>Written in the block editor.</p>',
        ];

        $items = ( new InstalledPostSource( [ 12 ] ) )->items();

        $this->assertSame( 'No WPBakery layout found on that page.', $items[0]['error'] );
        $this->assertSame( 'Classic', $items[0]['title'], 'the reader still needs to know which page it was' );
        $this->assertSame( '', $items[0]['content'] );
    }

    public function test_a_post_that_is_gone_is_reported_rather_than_thrown(): void {
        $items = ( new InstalledPostSource( [ 999 ] ) )->items();

        $this->assertSame( 'That page no longer exists.', $items[0]['error'] );
        $this->assertSame( 999, $items[0]['source_ref']['post_id'] );
    }

    /**
     * WPBakery's own template library. The Pro add-on puts these in the Divi
     * Library; the free plugin converts them as pages (task-11-amendments §2).
     */
    public function test_template_post_types_are_marked_for_the_library_path(): void {
        $this->seed( 13, 'Saved Row', 'vc4_templates' );
        $this->seed( 14, 'Old Template', 'templatera' );
        $this->seed( 15, 'Blog post', 'post' );

        $items = ( new InstalledPostSource( [ 13, 14, 15 ] ) )->items();

        $this->assertSame( 'library', $items[0]['template_type'] );
        $this->assertSame( 'page', $items[0]['post_type'] );
        $this->assertSame( 'vc4_templates', $items[0]['source_post_type'] );
        $this->assertSame( 'library', $items[1]['template_type'] );
        $this->assertSame( '', $items[2]['template_type'] );
        $this->assertSame( 'post', $items[2]['post_type'] );
    }

    /** A page whose builder flag was never written still converts: the content is the truth. */
    public function test_a_missing_builder_flag_does_not_stop_a_page_converting(): void {
        $this->seed( 16 );
        delete_post_meta( 16, '_wpb_vc_js_status' );

        $items = ( new InstalledPostSource( [ 16 ] ) )->items();

        $this->assertSame( '', $items[0]['error'] );
        $this->assertSame( '', $items[0]['meta']['_wpb_vc_js_status'] );
    }

    public function test_reading_a_post_writes_nothing_to_it(): void {
        $this->seed( 17 );
        $post_before = (array) get_post( 17 );
        $meta_before = $GLOBALS['__test_postmeta'];

        ( new InstalledPostSource( [ 17 ] ) )->items();

        $this->assertSame( $post_before, (array) get_post( 17 ) );
        $this->assertSame( $meta_before, $GLOBALS['__test_postmeta'] );
    }
}
