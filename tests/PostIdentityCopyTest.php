<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Conversion\ConversionCommitter;
use WPBakeryDivi5Converter\Conversion\ConversionPreflight;
use WPBakeryDivi5Converter\Conversion\InstalledPostSource;

/**
 * What a converted post keeps of the post it came from.
 *
 * A conversion creates a new post, so everything the old post *was* — its
 * custom fields, its featured image, its categories, the day it was published,
 * who wrote it — has to be carried over deliberately or it is silently lost.
 * Reported by a user who converted a post and found his ACF fields empty.
 */
final class PostIdentityCopyTest extends TestCase {

    private const CONTENT = '[vc_row][vc_column][vc_column_text]Hello there.[/vc_column_text][/vc_column][/vc_row]';

    protected function setUp(): void {
        wbdc_test_reset_hooks();
        $GLOBALS['__test_posts']        = [];
        $GLOBALS['__test_postmeta']     = [];
        $GLOBALS['__test_object_terms'] = [];
        $GLOBALS['__test_taxonomies']   = [ 'post' => [ 'category', 'post_tag' ], 'page' => [] ];
    }

    /** A published post with the trimmings a real one carries. */
    private function seedPost( int $id = 42 ): void {
        $GLOBALS['__test_posts'][ $id ] = (object) [
            'ID'             => $id,
            'post_title'     => 'Ships in 48 hours',
            'post_name'      => 'ships-in-48-hours',
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'post_content'   => self::CONTENT,
            'post_date'      => '2024-03-09 08:30:00',
            'post_date_gmt'  => '2024-03-09 07:30:00',
            'post_author'    => 5,
            'post_excerpt'   => 'The short version.',
            'menu_order'     => 3,
            'post_parent'    => 11,
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
        ];
        update_post_meta( $id, 'hero_heading', 'Ships in 48h' );
        update_post_meta( $id, '_hero_heading', 'field_60a1b2c3' );
        update_post_meta( $id, '_thumbnail_id', '91' );
        update_post_meta( $id, '_wp_page_template', 'templates/wide.php' );
        // Left behind on purpose: WPBakery's own, the editor's, Divi's, ours.
        update_post_meta( $id, '_wpb_vc_js_status', 'true' );
        update_post_meta( $id, '_wpb_shortcodes_custom_css', '.vc_custom_1{color:red}' );
        update_post_meta( $id, '_edit_lock', '1710000000:5' );
        update_post_meta( $id, '_et_pb_use_builder', 'off' );
        update_post_meta( $id, '_wbdc_source_post_id', 999 );
        wp_set_object_terms( $id, [ 3, 7 ], 'category' );
        wp_set_object_terms( $id, [ 12 ], 'post_tag' );
    }

    private function convert( int $id = 42 ): int {
        $plan    = ( new ConversionPreflight() )->run( new InstalledPostSource( [ $id ] ) );
        $results = ( new ConversionCommitter() )->commit( $plan, [ 'post_status' => 'draft' ] );
        $this->assertTrue( $results[0]['success'], (string) ( $results[0]['error'] ?? '' ) );
        return (int) $results[0]['post_id'];
    }

    public function test_the_new_post_keeps_the_custom_fields_the_old_one_had(): void {
        $this->seedPost();
        $new = $this->convert();

        $this->assertSame( 'Ships in 48h', get_post_meta( $new, 'hero_heading', true ) );
        $this->assertSame( 'field_60a1b2c3', get_post_meta( $new, '_hero_heading', true ), 'ACF stores its field key in an underscored twin' );
        $this->assertSame( '91', get_post_meta( $new, '_thumbnail_id', true ), 'the featured image is a meta value like any other' );
        $this->assertSame( 'templates/wide.php', get_post_meta( $new, '_wp_page_template', true ) );
    }

    public function test_the_new_post_keeps_its_date_author_and_placement(): void {
        $this->seedPost();
        $post = get_post( $this->convert() );

        $this->assertSame( '2024-03-09 08:30:00', $post->post_date, 'a new post dated today loses the archive order and the permalink date' );
        $this->assertSame( '2024-03-09 07:30:00', $post->post_date_gmt );
        $this->assertSame( 5, (int) $post->post_author );
        $this->assertSame( 'The short version.', $post->post_excerpt );
        $this->assertSame( 3, (int) $post->menu_order );
        $this->assertSame( 11, (int) $post->post_parent );
        $this->assertSame( 'closed', $post->comment_status );
        $this->assertSame( 'closed', $post->ping_status );
    }

    public function test_the_new_post_keeps_its_categories_and_tags(): void {
        $this->seedPost();
        $new = $this->convert();

        $this->assertSame( [ '3', '7' ], $GLOBALS['__test_object_terms'][ $new ]['category'] ?? [] );
        $this->assertSame( [ '12' ], $GLOBALS['__test_object_terms'][ $new ]['post_tag'] ?? [] );
    }

    public function test_the_builders_own_bookkeeping_is_left_behind(): void {
        $this->seedPost();
        $new = $this->convert();

        foreach ( [ '_wpb_vc_js_status', '_wpb_shortcodes_custom_css', '_edit_lock' ] as $key ) {
            $this->assertSame( '', get_post_meta( $new, $key, true ), "$key belongs to the page it came from" );
        }
        // Divi's and ours are written by the conversion, never copied from the source.
        $this->assertSame( 'on', get_post_meta( $new, '_et_pb_use_builder', true ) );
        $this->assertSame( 42, get_post_meta( $new, '_wbdc_source_post_id', true ) );
    }

    public function test_a_meta_value_with_backslashes_survives_the_copy(): void {
        $this->seedPost();
        $raw = 'C:\\Users\\"quoted"\\path';
        // Slashed on the way in, the way WordPress expects a caller to write a
        // literal backslash, so the source really holds one.
        update_post_meta( 42, 'tricky', wp_slash( $raw ) );
        $this->assertSame( $raw, get_post_meta( 42, 'tricky', true ), 'the source post holds the backslashes' );

        $new = $this->convert();

        $this->assertSame( $raw, get_post_meta( $new, 'tricky', true ), 'add_post_meta() unslashes, so the copy has to slash' );
    }

    public function test_every_value_of_a_repeated_key_is_copied(): void {
        $this->seedPost();
        add_post_meta( 42, 'gallery_id', '11' );
        add_post_meta( 42, 'gallery_id', '12' );

        $new = $this->convert();

        $this->assertSame( [ '11', '12' ], get_post_meta( $new, 'gallery_id', false ) );
    }

    public function test_a_page_does_not_take_a_posts_taxonomies(): void {
        $this->seedPost();
        $GLOBALS['__test_posts'][42]->post_type = 'page';

        $new = $this->convert();

        $this->assertArrayNotHasKey( 'category', $GLOBALS['__test_object_terms'][ $new ] ?? [] );
        $this->assertSame( 'Ships in 48h', get_post_meta( $new, 'hero_heading', true ), 'meta still comes along' );
    }

    public function test_a_site_can_turn_the_copy_off(): void {
        $this->seedPost();
        add_filter( 'wbdc_copy_source_identity', '__return_false' );

        $new = $this->convert();

        $this->assertSame( '', get_post_meta( $new, 'hero_heading', true ) );
        $this->assertSame( 'direct', get_post_meta( $new, '_wbdc_import_source', true ), 'the conversion itself is unaffected' );
    }

    public function test_an_uploaded_file_has_no_source_post_to_copy_from(): void {
        $item = [
            'title'      => 'From a file',
            'post_type'  => 'page',
            'post_name'  => '',
            'content'    => self::CONTENT,
            'meta'       => [],
            'mode'       => 'import',
            'error'      => '',
            'source_ref' => [ 'kind' => 'upload', 'post_id' => null, 'file' => 'export.xml' ],
        ];
        $plan    = ( new ConversionPreflight() )->run( new FakeWPBakerySource( [ $item ] ) );
        $results = ( new ConversionCommitter() )->commit( $plan );

        $this->assertTrue( $results[0]['success'] );
        $this->assertSame( 'file_upload', get_post_meta( (int) $results[0]['post_id'], '_wbdc_import_source', true ) );
    }
}
