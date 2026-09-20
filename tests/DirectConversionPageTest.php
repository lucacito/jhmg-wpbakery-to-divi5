<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Admin\DirectConversionPage;

/**
 * "Convert a page already on this site."
 *
 * The safety property is the point: the rendered picker is never trusted on
 * the way back in — every submitted id is re-verified as a post that really
 * holds WPBakery content.
 */
final class DirectConversionPageTest extends TestCase {

    private const CONTENT = '[vc_row][vc_column][vc_custom_heading text="Welcome" font_container="tag:h2|text_align:left"]'
        . '[vc_column_text]Hello there.[/vc_column_text][/vc_column][/vc_row]';

    protected function setUp(): void {
        wbdc_test_reset_hooks();
        $GLOBALS['__test_posts']    = [];
        $GLOBALS['__test_postmeta'] = [];
    }

    private function seed( int $id, string $title = 'Home', bool $wpbakery = true ): int {
        $GLOBALS['__test_posts'][ $id ] = (object) [
            'ID'            => $id,
            'post_title'    => $title,
            'post_name'     => 'home',
            'post_type'     => 'page',
            'post_status'   => 'publish',
            'post_modified' => '2026-09-01 00:00:00',
            'post_content'  => $wpbakery ? self::CONTENT : '<p>Just a page.</p>',
        ];
        if ( $wpbakery ) {
            update_post_meta( $id, '_wpb_vc_js_status', 'true' );
        }

        return $id;
    }

    public function test_it_reads_and_verifies_every_selected_id(): void {
        $this->seed( 201 );
        $this->seed( 202, 'B' );
        $this->seed( 203, 'Plain', false );
        $page = new DirectConversionPage();

        $this->assertSame(
            [ 201, 202 ],
            $page->verified_post_ids( [ 'wbdc_post_ids' => [ '201', '202', '203', 'abc', '-1', '999', '201', [ 'x' ] ] ] )
        );
        $this->assertSame( [ 202 ], $page->verified_post_ids( [ 'wbdc_post_ids' => '202' ] ) );
    }

    /** A page WPBakery never flagged still converts; the flag is a badge, not a gate. */
    public function test_a_page_whose_wpbakery_flag_is_missing_is_still_convertible(): void {
        $id = $this->seed( 204, 'Unflagged' );
        delete_post_meta( $id, '_wpb_vc_js_status' );

        $this->assertSame( [ 204 ], ( new DirectConversionPage() )->verified_post_ids( [ 'wbdc_post_ids' => [ '204' ] ] ) );
    }

    public function test_convert_converts_the_post_you_picked_and_keeps_it_published(): void {
        $id      = $this->seed( 220 );
        $results = ( new DirectConversionPage() )->convert( [ $id ] );

        $this->assertTrue( $results[0]['success'] );
        $this->assertSame( $id, (int) $results[0]['post_id'], 'the post you picked is the post you get' );
        $this->assertTrue( $results[0]['in_place'] );
        $this->assertSame( 'publish', get_post( $id )->post_status, 'a published post stays published' );
        $this->assertStringContainsString( 'wp:divi/', get_post( $id )->post_content );
        $this->assertSame( 'direct', get_post_meta( $results[0]['post_id'], '_wbdc_import_source', true ) );
        $this->assertSame( 'direct', $results[0]['mode'], 'the result screen words theme elements by this' );
    }

    public function test_check_handler_stashes_the_selection_and_redirects(): void {
        $this->seed( 230 );
        $page = new class() extends DirectConversionPage {
            /** @var string[] */
            public array $redirects = [];
            protected function redirect( string $location ): void {
                $this->redirects[] = $location;
            }
            /** @param array<string,mixed> $post */
            public function check( array $post ): void {
                $this->handle_check( $post );
            }
        };

        $page->check( [ 'wbdc_post_ids' => [ '230' ] ] );

        $this->assertSame( [ 230 ], get_transient( DirectConversionPage::PLAN_IDS_TRANSIENT_PREFIX . get_current_user_id() ) );
        $this->assertStringContainsString( 'action=direct_report', $page->redirects[0] );
    }

    public function test_convert_handler_records_history_and_redirects_to_the_result_screen(): void {
        $this->seed( 240 );
        $page = new class() extends DirectConversionPage {
            /** @var string[] */
            public array $redirects = [];
            protected function redirect( string $location ): void {
                $this->redirects[] = $location;
            }
            /** @param array<string,mixed> $post */
            public function go( array $post ): void {
                $this->handle_convert( $post );
            }
        };

        $page->go( [ 'wbdc_post_ids' => [ '240' ] ] );

        $this->assertStringContainsString( 'action=batch_result', $page->redirects[0] );
        $runs = get_option( 'wbdc_import_history' );
        $this->assertCount( 1, $runs );
        $this->assertCount( 1, $runs[0]['post_ids'] );
        $this->assertSame( 1, get_option( 'wbdc_conversions_total' ) );
    }

    public function test_an_empty_selection_never_writes_a_junk_history_entry(): void {
        $page = new class() extends DirectConversionPage {
            protected function redirect( string $location ): void {}
            /** @param array<string,mixed> $post */
            public function go( array $post ): void {
                $this->handle_convert( $post );
            }
        };

        $this->expectException( \RuntimeException::class );
        $page->go( [ 'wbdc_post_ids' => [ '999' ] ] );
    }

    public function test_planning_writes_nothing(): void {
        $id     = $this->seed( 250, 'Landing' );
        $before = count( $GLOBALS['__test_posts'] );

        $plan = ( new DirectConversionPage() )->plan_for( [ $id ] );

        $this->assertSame( 1, $plan->count() );
        $this->assertCount( $before, $GLOBALS['__test_posts'] );
        $this->assertSame( 'direct', $plan->items()[0]['mode'] );
    }

    public function test_convert_can_be_asked_for_a_new_draft_instead(): void {
        $id      = $this->seed( 221 );
        $before  = (array) get_post( $id );
        $results = ( new DirectConversionPage() )->convert( [ $id ], [ 'create_new' => true ] );

        $this->assertTrue( $results[0]['success'] );
        $this->assertNotSame( $id, (int) $results[0]['post_id'] );
        $this->assertFalse( $results[0]['in_place'] );
        $this->assertSame( 'draft', get_post( $results[0]['post_id'] )->post_status );
        $this->assertSame( $before, (array) get_post( $id ), 'the WPBakery original is untouched by a copy run' );
        $this->assertSame( 221, get_post_meta( $results[0]['post_id'], '_wbdc_source_post_id', true ) );
    }
}
