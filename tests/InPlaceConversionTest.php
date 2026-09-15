<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Conversion\ConversionCommitter;
use WPBakeryDivi5Converter\Conversion\ConversionPreflight;
use WPBakeryDivi5Converter\Conversion\InstalledPostSource;
use WPBakeryDivi5Converter\History\ImportHistory;
use WPBakeryDivi5Converter\History\ImportRollback;

/**
 * Converting the post you picked, rather than a copy of it.
 *
 * A copy cannot hold the permalink: two published posts cannot share a slug,
 * so the copy always ends up at `-2` and the author is left doing a redirect.
 * Converting the post itself keeps the slug, the date, the comments and every
 * link pointing at it, and the original shortcodes are kept on the post so the
 * run can still be undone. Creating a new post stays available, by choice.
 */
final class InPlaceConversionTest extends TestCase {

    private const CONTENT = '[vc_row][vc_column][vc_custom_heading text="Roasted daily"][vc_column_text]Hello.[/vc_column_text][/vc_column][/vc_row]';

    protected function setUp(): void {
        wbdc_test_reset_hooks();
        $GLOBALS['__test_posts']        = [];
        $GLOBALS['__test_postmeta']     = [];
        $GLOBALS['__test_object_terms'] = [];
        $GLOBALS['__test_trashed']      = [];
    }

    private function seed( int $id = 42 ): void {
        $GLOBALS['__test_posts'][ $id ] = (object) [
            'ID'           => $id,
            'post_title'   => 'Roasted daily',
            'post_name'    => 'roasted-daily',
            'post_type'    => 'post',
            'post_status'  => 'publish',
            'post_content' => self::CONTENT,
            'post_date'    => '2024-03-09 08:30:00',
            'post_author'  => 5,
        ];
        update_post_meta( $id, '_wpb_vc_js_status', 'true' );
        update_post_meta( $id, 'hero_heading', 'Ships in 48h' );
    }

    /** @param array<string,mixed> $options */
    private function convert( array $options = [], int $id = 42 ): array {
        $plan = ( new ConversionPreflight() )->run( new InstalledPostSource( [ $id ] ) );

        return ( new ConversionCommitter() )->commit( $plan, $options );
    }

    // --- the default: the post you picked ------------------------------------

    public function test_the_post_you_picked_becomes_the_divi_post(): void {
        $this->seed();
        $before = count( $GLOBALS['__test_posts'] );

        $results = $this->convert();

        $this->assertTrue( $results[0]['success'] );
        $this->assertSame( 42, (int) $results[0]['post_id'], 'the post you picked is the post you get' );
        $this->assertTrue( $results[0]['in_place'] );
        $this->assertCount( $before, $GLOBALS['__test_posts'], 'nothing else was created' );

        $post = get_post( 42 );
        $this->assertStringContainsString( 'wp:divi/heading', $post->post_content );
        $this->assertSame( 'on', get_post_meta( 42, '_et_pb_use_divi_5', true ) );
    }

    public function test_the_post_keeps_its_permalink_status_and_everything_else(): void {
        $this->seed();

        $this->convert();

        $post = get_post( 42 );
        $this->assertSame( 'roasted-daily', $post->post_name, 'the permalink is the whole point' );
        $this->assertSame( 'publish', $post->post_status, 'a published post stays published' );
        $this->assertSame( '2024-03-09 08:30:00', $post->post_date );
        $this->assertSame( 5, (int) $post->post_author );
        $this->assertSame( 'Ships in 48h', get_post_meta( 42, 'hero_heading', true ), 'custom fields never moved, so nothing could be lost' );
    }

    public function test_the_original_shortcodes_are_kept_so_the_run_can_be_undone(): void {
        $this->seed();

        $this->convert();

        $this->assertSame( self::CONTENT, get_post_meta( 42, '_wbdc_original_content', true ) );
        $this->assertSame( '1', (string) get_post_meta( 42, '_wbdc_converted_in_place', true ) );
    }

    // --- undo ----------------------------------------------------------------

    public function test_undo_puts_the_wpbakery_page_back(): void {
        $this->seed();
        $results = $this->convert();

        $history = new ImportHistory();
        $history->record( 'run1', $results );
        $outcome = ( new ImportRollback( $history ) )->rollback( 'run1' );

        $this->assertSame( 1, $outcome['restored'] );
        $this->assertSame( 0, $outcome['trashed'], 'the post you picked is never trashed' );

        $post = get_post( 42 );
        // The stub keeps what wp_update_post() was handed; core unslashes it,
        // which is the same comparison ConversionPipelineTest makes. The
        // end-to-end spec asserts the real thing against the database.
        $this->assertSame( self::CONTENT, wp_unslash( $post->post_content ), 'the shortcodes are back' );
        $this->assertSame( 'publish', $post->post_status );
        $this->assertSame( '', get_post_meta( 42, '_et_pb_use_divi_5', true ), 'and it is no longer a Divi post' );
        $this->assertSame( '', get_post_meta( 42, '_wbdc_original_content', true ) );
        $this->assertSame( 'Ships in 48h', get_post_meta( 42, 'hero_heading', true ), 'undo touches only what the conversion wrote' );
        $this->assertSame( [], $GLOBALS['__test_trashed'] );
    }

    public function test_undo_still_trashes_the_posts_a_copy_run_created(): void {
        $this->seed();
        $results = $this->convert( [ 'create_new' => true ] );
        $new_id  = (int) $results[0]['post_id'];

        $history = new ImportHistory();
        $history->record( 'run2', $results );
        $outcome = ( new ImportRollback( $history ) )->rollback( 'run2' );

        $this->assertSame( 1, $outcome['trashed'] );
        $this->assertSame( 0, $outcome['restored'] );
        $this->assertContains( $new_id, $GLOBALS['__test_trashed'] );
        $this->assertSame( self::CONTENT, get_post( 42 )->post_content, 'the source was never touched by that run' );
    }

    // --- the opt-in copy ------------------------------------------------------

    public function test_asking_for_a_new_post_leaves_the_original_alone(): void {
        $this->seed();

        $results = $this->convert( [ 'create_new' => true, 'post_status' => 'draft' ] );

        $new_id = (int) $results[0]['post_id'];
        $this->assertNotSame( 42, $new_id );
        $this->assertFalse( $results[0]['in_place'] );
        $this->assertSame( self::CONTENT, get_post( 42 )->post_content, 'the WPBakery original is untouched' );
        $this->assertSame( 'draft', get_post( $new_id )->post_status );
        $this->assertSame( 'Ships in 48h', get_post_meta( $new_id, 'hero_heading', true ), 'the copy still carries the identity' );
    }

    public function test_an_uploaded_file_always_creates_a_post(): void {
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
        $plan = ( new ConversionPreflight() )->run( new FakeWPBakerySource( [ $item ] ) );

        $results = ( new ConversionCommitter() )->commit( $plan );

        $this->assertTrue( $results[0]['success'] );
        $this->assertFalse( $results[0]['in_place'], 'there is no post to convert, only a file' );
        $this->assertSame( 'file_upload', get_post_meta( (int) $results[0]['post_id'], '_wbdc_import_source', true ) );
    }

    public function test_a_source_post_that_has_gone_is_reported_not_guessed(): void {
        $plan = ( new ConversionPreflight() )->run( new InstalledPostSource( [ 999 ] ) );

        $results = ( new ConversionCommitter() )->commit( $plan );

        $this->assertFalse( $results[0]['success'] );
    }

    /**
     * A WPBakery template is a library item, not a page. Without a Pro exporter
     * it becomes a page draft holding everything it had — but the template
     * itself is left standing, because rewriting a library item as a Divi page
     * would destroy the thing the reader builds their pages from.
     */
    public function test_a_wpbakery_template_is_never_rewritten_in_place(): void {
        $GLOBALS['__test_posts'][60] = (object) [
            'ID'           => 60,
            'post_title'   => 'Hero template',
            'post_name'    => 'hero-template',
            'post_type'    => 'vc4_templates',
            'post_status'  => 'publish',
            'post_content' => self::CONTENT,
        ];

        $plan    = ( new ConversionPreflight() )->run( new InstalledPostSource( [ 60 ] ) );
        $results = ( new ConversionCommitter() )->commit( $plan );

        $this->assertTrue( $results[0]['success'] );
        $this->assertSame( 'library', $results[0]['template_type'] );
        $this->assertFalse( $results[0]['in_place'], 'a template is copied, never converted where it stands' );
        $this->assertNotSame( 60, (int) $results[0]['post_id'] );
        $this->assertSame( self::CONTENT, get_post( 60 )->post_content, 'the template is untouched' );
    }
}
