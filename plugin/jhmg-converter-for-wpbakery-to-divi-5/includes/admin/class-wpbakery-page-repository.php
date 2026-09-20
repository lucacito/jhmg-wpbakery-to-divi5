<?php
/**
 * Finds the WPBakery pages the direct-conversion picker lists.
 *
 * WPBakery keeps a page in its `post_content`, not in a layout record of its
 * own, so the question that decides whether a post is convertible is whether
 * its content holds `[vc_row` or `[vc_section` — the same test
 * `WPBakeryDocumentParser::isWPBakeryContent()` makes, and the same one
 * `InstalledPostSource` applies before it converts anything. `WP_Query` has no
 * argument for that, so the query carries a marker of its own and a
 * `posts_where` filter, added and removed around it, appends the two LIKEs.
 *
 * `_wpb_vc_js_status` is a **flag beside** the content, not the content:
 * WPBakery writes `true` while its editor is the active one and `false` when
 * the classic editor is, and a great many pages carry neither because the meta
 * was lost in an export or the page predates it. None of that changes whether
 * the shortcodes convert — so the flag decides a badge (`flag_missing`), never
 * whether a page is listed. Listing on the flag alone would hide convertible
 * pages, and adding a meta-keyed arm to the query could only add posts whose
 * content has nothing to convert.
 *
 * The query runs through an injectable runner so the class stays unit-testable
 * without a WordPress database.
 */

namespace WPBakeryDivi5Converter\Admin;

use WPBakeryDivi5Converter\Conversion\InstalledPostSource;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPBakeryPageRepository {

    /** WPBakery's own marker for "the builder is the active editor on this post". */
    const ENABLED_META = '_wpb_vc_js_status';

    /** The value WPBakery writes there when its editor is on. */
    const ENABLED_VALUE = 'true';

    /**
     * The query var this repository's own query carries, so the `posts_where`
     * filter never touches anybody else's query.
     */
    const QUERY_FLAG = 'wbdc_wpbakery_content';

    /** The two openers `WPBakeryDocumentParser::isWPBakeryContent()` looks for. */
    const CONTENT_MARKERS = [ '[vc_row', '[vc_section' ];

    const PER_PAGE = 20;

    /** @var callable(array):array */
    private $query_runner;

    public function __construct( ?callable $query_runner = null ) {
        $this->query_runner = $query_runner ?? [ $this, 'run_wp_query' ];
    }

    /**
     * Every public post type, plus WPBakery's own template library types.
     *
     * WPBakery can be switched on for any post type from its Role Manager, and
     * it stores nothing that says which — the content is the only evidence — so
     * the listing spans every public type rather than a configured subset.
     *
     * @return string[]
     */
    public static function post_types(): array {
        $public = function_exists( 'get_post_types' ) ? get_post_types( [ 'public' => true ], 'names' ) : [];
        $public = is_array( $public ) ? array_values( array_map( 'strval', $public ) ) : [];

        return array_values( array_unique( array_merge(
            [ 'page', 'post' ],
            $public,
            InstalledPostSource::LIBRARY_POST_TYPES
        ) ) );
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed> The WP_Query arguments this repository issues.
     */
    public function query_args( array $args = [] ): array {
        $per_page = (int) ( $args['per_page'] ?? self::PER_PAGE );
        $paged    = max( 1, (int) ( $args['paged'] ?? 1 ) );
        $search   = trim( (string) ( $args['search'] ?? '' ) );

        $query = [
            'post_type'        => self::post_types(),
            'post_status'      => [ 'publish', 'draft', 'pending', 'private', 'future' ],
            'orderby'          => 'modified',
            'order'            => 'DESC',
            'posts_per_page'   => $per_page > 0 ? $per_page : self::PER_PAGE,
            'paged'            => $paged,
            'suppress_filters' => false,
            self::QUERY_FLAG   => true,
        ];

        // An explicit offset drives WP_Query's SQL OFFSET directly, so a caller
        // probing for one extra row can raise posts_per_page without inflating
        // the offset every later page is computed from.
        if ( isset( $args['offset'] ) ) {
            $query['offset'] = max( 0, (int) $args['offset'] );
        }
        if ( $search !== '' ) {
            $query['s'] = $search;
        }

        return $query;
    }

    /**
     * @param array<string,mixed> $args
     * @return array[] Rows: ['id','title','post_type','status','modified','converted','flag_missing'].
     */
    public function find( array $args = [] ): array {
        $posts = [];

        foreach ( ( $this->query_runner )( $this->query_args( $args ) ) as $post ) {
            $id = (int) ( $post->ID ?? 0 );
            if ( $id > 0 ) {
                $posts[ $id ] = $post;
            }
        }

        if ( $posts === [] ) {
            return [];
        }

        // Both badges are answered once for the whole page of rows rather than
        // once per row: twenty rows used to mean twenty `get_posts()` calls and
        // twenty meta reads.
        $ids       = array_keys( $posts );
        $converted = $this->already_converted( $ids );
        $flagged   = $this->flagged( $ids );

        $rows = [];
        foreach ( $posts as $id => $post ) {
            $title  = trim( (string) ( $post->post_title ?? '' ) );
            $rows[] = [
                'id'           => $id,
                'title'        => $title !== '' ? $title : __( '(no title)', 'jhmg-converter-for-wpbakery-to-divi-5' ),
                'post_type'    => (string) ( $post->post_type ?? '' ),
                'status'       => (string) ( $post->post_status ?? '' ),
                'modified'     => (string) ( $post->post_modified ?? '' ),
                'converted'    => in_array( $id, $converted, true ),
                'flag_missing' => ! in_array( $id, $flagged, true ),
            ];
        }

        return $rows;
    }

    /**
     * Which of these posts some other post records as its conversion source.
     * Informational only — one query for the whole page of rows.
     *
     * @param int[] $source_post_ids
     * @return int[] The subset that has been converted before.
     */
    private function already_converted( array $source_post_ids ): array {
        $found = get_posts( [
            'post_type'      => 'any',
            'post_status'    => 'any',
            'meta_key'       => '_wbdc_source_post_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_value'     => array_map( 'strval', $source_post_ids ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            'meta_compare'   => 'IN',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ] );

        $found = array_map( 'intval', is_array( $found ) ? $found : [] );

        // Primes the meta cache for the whole page in one query, so the reads
        // below cost nothing — the same as `flagged()` does.
        if ( $found !== [] && function_exists( 'update_meta_cache' ) ) {
            update_meta_cache( 'post', $found );
        }

        $converted = [];
        foreach ( $found as $post_id ) {
            $source = (int) get_post_meta( $post_id, '_wbdc_source_post_id', true );
            if ( in_array( $source, $source_post_ids, true ) ) {
                $converted[] = $source;
            }
        }

        return $converted;
    }

    /**
     * Which of these posts carry WPBakery's flag switched on. A badge, never a
     * filter — see the class docblock.
     *
     * @param int[] $post_ids
     * @return int[]
     */
    private function flagged( array $post_ids ): array {
        // Primes the meta cache for the whole page in one query, so the reads
        // below cost nothing (and cost nothing at all in the unit tests, which
        // have no cache and answer from an array).
        if ( function_exists( 'update_meta_cache' ) ) {
            update_meta_cache( 'post', $post_ids );
        }

        $flagged = [];
        foreach ( $post_ids as $post_id ) {
            if ( (string) get_post_meta( $post_id, self::ENABLED_META, true ) === self::ENABLED_VALUE ) {
                $flagged[] = $post_id;
            }
        }

        return $flagged;
    }

    /**
     * `posts_where` for this repository's query only.
     *
     * @param string $where
     * @param mixed  $query The WP_Query being built.
     */
    public static function filter_where( $where, $query ): string {
        global $wpdb;

        $where = (string) $where;

        if ( ! is_object( $query ) || ! method_exists( $query, 'get' ) || ! $query->get( self::QUERY_FLAG ) ) {
            return $where;
        }
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
            return $where;
        }

        $clauses = [];
        foreach ( self::CONTENT_MARKERS as $marker ) {
            $clauses[] = $wpdb->prepare( "{$wpdb->posts}.post_content LIKE %s", '%' . $wpdb->esc_like( $marker ) . '%' );
        }

        return $where . ' AND ( ' . implode( ' OR ', $clauses ) . ' ) ';
    }

    /**
     * @param array<string,mixed> $args
     * @return array Post objects.
     */
    private function run_wp_query( array $args ): array {
        add_filter( 'posts_where', [ self::class, 'filter_where' ], 10, 2 );

        // The filter comes off however the query ends: leaving it on would
        // narrow every later query on the request to WPBakery content.
        try {
            $query = new \WP_Query( $args );
            $posts = is_array( $query->posts ?? null ) ? $query->posts : [];
        } finally {
            remove_filter( 'posts_where', [ self::class, 'filter_where' ], 10 );
        }

        return $posts;
    }
}
