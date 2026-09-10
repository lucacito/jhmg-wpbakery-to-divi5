<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Admin\WPBakeryPageRepository;

/**
 * What the picker lists.
 *
 * WPBakery keeps the page in `post_content`, so — unlike a builder that stores
 * a layout record — the only reliable question is "does this post hold
 * `[vc_row` or `[vc_section`". `_wpb_vc_js_status` is a flag beside it, and a
 * page whose flag is missing converts exactly as well as one whose flag is
 * there, so the flag decides a badge rather than the listing.
 */
final class WPBakeryPageRepositoryTest extends TestCase {

    protected function setUp(): void {
        wbdc_test_reset_hooks();
        $GLOBALS['__test_posts']      = [];
        $GLOBALS['__test_postmeta']   = [];
        $GLOBALS['__test_post_types'] = [ 'post' => 'post', 'page' => 'page', 'product' => 'product' ];
    }

    private function post( int $id, string $title, string $content = '[vc_row][/vc_row]' ): object {
        $post = (object) [
            'ID'            => $id,
            'post_title'    => $title,
            'post_type'     => 'page',
            'post_status'   => 'publish',
            'post_modified' => '2026-09-0' . $id . ' 00:00:00',
            'post_content'  => $content,
        ];

        $GLOBALS['__test_posts'][ $id ] = $post;

        return $post;
    }

    public function test_post_types_are_every_public_type_plus_wpbakerys_template_types(): void {
        $this->assertSame(
            [ 'page', 'post', 'product', 'vc4_templates', 'templatera' ],
            WPBakeryPageRepository::post_types()
        );
    }

    public function test_query_args_carry_the_search_the_page_and_the_content_match_marker(): void {
        $args = ( new WPBakeryPageRepository( fn(): array => [] ) )
            ->query_args( [ 'search' => 'home', 'paged' => 2, 'per_page' => 21, 'offset' => 20 ] );

        $this->assertSame( WPBakeryPageRepository::post_types(), $args['post_type'] );
        $this->assertSame( 'home', $args['s'] );
        $this->assertSame( 21, $args['posts_per_page'] );
        $this->assertSame( 20, $args['offset'] );
        $this->assertSame( 2, $args['paged'] );
        $this->assertSame( 'modified', $args['orderby'] );
        $this->assertTrue( $args[ WPBakeryPageRepository::QUERY_FLAG ], 'the query announces itself to the posts_where filter' );
    }

    public function test_the_where_filter_matches_rows_and_sections_and_only_on_this_querys_own_query(): void {
        $ours  = new \WP_Query( [ WPBakeryPageRepository::QUERY_FLAG => true ] );
        $other = new \WP_Query( [] );

        $where = WPBakeryPageRepository::filter_where( ' AND 1=1', $ours );

        $this->assertSame( 2, substr_count( $where, 'post_content LIKE' ) );

        // Each marker is escaped as $wpdb would escape it: an unescaped
        // underscore is a LIKE wildcard, so `[vc_row` would also match `[vcXrow`.
        foreach ( WPBakeryPageRepository::CONTENT_MARKERS as $marker ) {
            $this->assertStringContainsString(
                $GLOBALS['wpdb']->prepare( 'LIKE %s', '%' . $GLOBALS['wpdb']->esc_like( $marker ) . '%' ),
                $where,
                $marker
            );
        }

        $this->assertStringStartsWith( ' AND 1=1', $where );
        $this->assertSame( ' AND 1=1', WPBakeryPageRepository::filter_where( ' AND 1=1', $other ), 'no other query is touched' );
    }

    public function test_rows_badge_an_already_converted_page_and_a_missing_wpbakery_flag(): void {
        $flagged   = $this->post( 5, 'Home' );
        $unflagged = $this->post( 6, '' );
        update_post_meta( 5, '_wpb_vc_js_status', 'true' );

        $copy = wp_insert_post( [ 'post_type' => 'page', 'post_title' => 'Converted copy' ] );
        update_post_meta( $copy, '_wbdc_source_post_id', 5 );

        $rows = ( new WPBakeryPageRepository( fn(): array => [ $flagged, $unflagged ] ) )->find();

        $this->assertTrue( $rows[0]['converted'] );
        $this->assertFalse( $rows[0]['flag_missing'] );
        $this->assertSame( 'page', $rows[0]['post_type'] );

        $this->assertFalse( $rows[1]['converted'] );
        $this->assertTrue( $rows[1]['flag_missing'], 'content holds a row but WPBakery never wrote its flag' );
        $this->assertSame( '(no title)', $rows[1]['title'] );
    }

    public function test_a_flag_switched_off_still_lists_and_still_badges(): void {
        $post = $this->post( 7, 'Classic editor' );
        update_post_meta( 7, '_wpb_vc_js_status', 'false' );

        $rows = ( new WPBakeryPageRepository( fn(): array => [ $post ] ) )->find();

        $this->assertCount( 1, $rows, 'the shortcodes are still there, so the page still converts' );
        $this->assertTrue( $rows[0]['flag_missing'] );
    }

    public function test_an_empty_page_of_rows_asks_no_badge_questions_at_all(): void {
        $GLOBALS['__test_get_posts_calls'] = 0;

        $this->assertSame( [], ( new WPBakeryPageRepository( fn(): array => [] ) )->find() );
        $this->assertSame( 0, $GLOBALS['__test_get_posts_calls'], 'no rows, no reverse lookup' );
    }

    /**
     * Twenty rows used to mean twenty `get_posts()` calls and twenty meta
     * reads. Both badges are now answered once for the whole page.
     */
    public function test_the_badges_cost_one_query_however_many_rows_there_are(): void {
        $posts = [];
        for ( $id = 10; $id < 30; $id++ ) {
            $posts[] = $this->post( $id, 'Page ' . $id );
            update_post_meta( $id, '_wpb_vc_js_status', 'true' );
        }

        $copy = wp_insert_post( [ 'post_type' => 'page', 'post_title' => 'Copy of 12' ] );
        update_post_meta( $copy, '_wbdc_source_post_id', 12 );

        $GLOBALS['__test_get_posts_calls'] = 0;
        $rows = ( new WPBakeryPageRepository( fn(): array => $posts ) )->find();

        $this->assertCount( 20, $rows );
        $this->assertSame( 1, $GLOBALS['__test_get_posts_calls'], 'one reverse lookup for the whole page of rows' );
        $this->assertSame( [ 12 ], array_column( array_filter( $rows, static fn( array $r ): bool => $r['converted'] ), 'id' ) );
        $this->assertSame( [], array_filter( $rows, static fn( array $r ): bool => $r['flag_missing'] ) );
    }

    public function test_a_row_without_an_id_is_skipped_rather_than_listed_as_zero(): void {
        $rows = ( new WPBakeryPageRepository( fn(): array => [ (object) [ 'post_title' => 'Ghost' ] ] ) )->find();

        $this->assertSame( [], $rows );
    }
}
