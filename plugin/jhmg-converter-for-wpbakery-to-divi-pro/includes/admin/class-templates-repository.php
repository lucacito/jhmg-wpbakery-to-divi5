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
 * A template is offered only when its content actually holds WPBakery
 * shortcodes — the same test the conversion itself makes, so nothing is listed
 * that could only fail.
 *
 * The query runs through an injectable runner so the class stays unit-testable
 * without a WordPress database.
 */

namespace WPBakeryDivi5Converter\Pro\Admin;

use WPBakeryDivi5Converter\Conversion\InstalledPostSource;
use WPBakeryDivi5Converter\Parsers\WPBakeryDocumentParser;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TemplatesRepository {

    /** A template library is a small thing; this is a listing cap, not a conversion cap. */
    const PER_PAGE = 100;

    /** @var callable(array):array */
    private $query_runner;

    public function __construct( ?callable $query_runner = null ) {
        $this->query_runner = $query_runner ?? [ $this, 'run_wp_query' ];
    }

    /** @return array<string,mixed> The WP_Query arguments this repository issues. */
    public function query_args(): array {
        return [
            'post_type'              => InstalledPostSource::LIBRARY_POST_TYPES,
            'post_status'            => [ 'publish', 'draft', 'private' ],
            'orderby'                => 'title',
            'order'                  => 'ASC',
            'posts_per_page'         => self::PER_PAGE,
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ];
    }

    /** @return array[] Rows: ['id','title','post_type','status']. */
    public function find(): array {
        $rows = [];

        foreach ( ( $this->query_runner )( $this->query_args() ) as $post ) {
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

    /** @return array<int,\WP_Post> */
    private function run_wp_query( array $args ): array {
        $query = new \WP_Query( $args );

        return $query->posts ?? [];
    }
}
