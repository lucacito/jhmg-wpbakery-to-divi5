<?php
/**
 * Finds the WPBakery templates on this site.
 *
 * WPBakery keeps its saved templates as posts of its own library types —
 * `vc4_templates` (WPBakery's own "Templates" screen) and `templatera` (the
 * Templatera add-on's, which the same screen lists). Both hold a fragment
 * meant to be reused rather than a page, which is what the Divi Library is
 * for; `InstalledPostSource::LIBRARY_POST_TYPES` is the one place those two
 * names live.
 *
 * A template counts only when its content actually holds WPBakery shortcodes —
 * the same test the conversion itself makes, so nothing is listed that could
 * only fail. That test is `WPBakeryPageRepository`'s `posts_where` filter,
 * borrowed rather than copied: it puts the two content LIKEs into the SQL, so
 * `found_posts` is a true total and the screen's "Showing 1–20 of 43" means
 * what it says. The PHP check in `find()` is belt and braces for a caller that
 * runs the query some other way.
 *
 * Paged, like the free picker: a template library can be larger than one
 * screen, and a silent cap would leave the tail unconvertible with nothing in
 * the UI saying so.
 *
 * The query runs through an injectable runner so the class stays unit-testable
 * without a WordPress database.
 */

namespace WPBakeryDivi5Converter\Pro\Admin;

use WPBakeryDivi5Converter\Admin\WPBakeryPageRepository;
use WPBakeryDivi5Converter\Conversion\InstalledPostSource;
use WPBakeryDivi5Converter\Parsers\WPBakeryDocumentParser;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TemplatesRepository {

    /** Rows per screen — the free picker's page size. */
    const PER_PAGE = 20;

    /** @var callable(array):array */
    private $query_runner;

    /** Total matching templates, from the last `find()`. */
    private int $found = 0;

    public function __construct( ?callable $query_runner = null ) {
        $this->query_runner = $query_runner ?? [ $this, 'run_wp_query' ];
    }

    /**
     * @param array<string,mixed> $args per_page, paged.
     * @return array<string,mixed> The WP_Query arguments this repository issues.
     */
    public function query_args( array $args = [] ): array {
        $per_page = (int) ( $args['per_page'] ?? self::PER_PAGE );
        $paged    = max( 1, (int) ( $args['paged'] ?? 1 ) );

        return [
            'post_type'                       => InstalledPostSource::LIBRARY_POST_TYPES,
            'post_status'                     => [ 'publish', 'draft', 'private' ],
            'orderby'                         => 'title',
            'order'                           => 'ASC',
            'posts_per_page'                  => $per_page > 0 ? $per_page : self::PER_PAGE,
            'paged'                           => $paged,
            // found_posts is the whole point of the pager, so no_found_rows stays off.
            'update_post_meta_cache'          => false,
            'update_post_term_cache'          => false,
            'suppress_filters'                => false,
            WPBakeryPageRepository::QUERY_FLAG => true,
        ];
    }

    /**
     * @param array<string,mixed> $args per_page, paged.
     * @return array[] Rows: ['id','title','post_type','status'].
     */
    public function find( array $args = [] ): array {
        $result      = ( $this->query_runner )( $this->query_args( $args ) );
        $posts       = is_array( $result['posts'] ?? null ) ? $result['posts'] : [];
        $this->found = max( 0, (int) ( $result['found'] ?? 0 ) );

        $rows = [];

        foreach ( $posts as $post ) {
            $id      = (int) ( $post->ID ?? 0 );
            $type    = (string) ( $post->post_type ?? '' );
            $content = (string) ( $post->post_content ?? '' );

            if ( $id <= 0 || ! in_array( $type, InstalledPostSource::LIBRARY_POST_TYPES, true ) ) {
                continue;
            }
            if ( ! WPBakeryDocumentParser::isWPBakeryContent( $content ) ) {
                continue;
            }

            $title = trim( (string) ( $post->post_title ?? '' ) );

            $rows[] = [
                'id'        => $id,
                'title'     => $title !== '' ? $title : __( '(no title)', 'jhmg-converter-for-wpbakery-to-divi-pro' ),
                'post_type' => $type,
                'status'    => (string) ( $post->post_status ?? '' ),
            ];
        }

        return $rows;
    }

    /** Total matching templates across every page, from the last `find()`. */
    public function found(): int {
        return $this->found;
    }

    /** Whether a page after this one exists. */
    public function has_next_page( int $paged, int $per_page = self::PER_PAGE ): bool {
        $per_page = $per_page > 0 ? $per_page : self::PER_PAGE;

        return $this->found > max( 1, $paged ) * $per_page;
    }

    /**
     * @param array<string,mixed> $args
     * @return array{posts: array, found: int}
     */
    private function run_wp_query( array $args ): array {
        add_filter( 'posts_where', [ WPBakeryPageRepository::class, 'filter_where' ], 10, 2 );

        // The filter comes off however the query ends: leaving it on would
        // narrow every later query on the request to WPBakery content.
        try {
            $query = new \WP_Query( $args );
            $posts = is_array( $query->posts ?? null ) ? $query->posts : [];
            $found = (int) ( $query->found_posts ?? count( $posts ) );
        } finally {
            remove_filter( 'posts_where', [ WPBakeryPageRepository::class, 'filter_where' ], 10 );
        }

        return [ 'posts' => $posts, 'found' => $found ];
    }
}
