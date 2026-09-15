<?php
/**
 * Carries a post's identity onto the post a conversion creates.
 *
 * A conversion never edits the WPBakery original; it writes a new post beside
 * it. That keeps the original safe, but it means everything the post *was* —
 * its custom fields, its featured image, its categories, the day it was
 * published, who wrote it — starts empty unless it is copied deliberately.
 * Reported by a user whose ACF fields came out blank.
 *
 * What is left behind is as deliberate as what is taken: WPBakery's own
 * bookkeeping (`_wpb_*`) describes a page built in a builder this post no
 * longer uses, Divi's (`_et_pb_*`) is written by the conversion itself, the
 * editor's (`_edit_lock`, `_edit_last`) belongs to whoever had the old post
 * open, and ours (`_wbdc_*`) records where this post came from.
 *
 * The slug is the one thing that cannot come along: WordPress will not give
 * two posts the same one while both exist, so the new post gets `-2`.
 */

namespace WPBakeryDivi5Converter\Conversion;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PostIdentityCopier {

    /** Post columns worth carrying. The title, content, type and status are the conversion's own business. */
    const COPIED_FIELDS = [
        'post_date',
        'post_date_gmt',
        'post_author',
        'post_excerpt',
        'menu_order',
        'post_parent',
        'comment_status',
        'ping_status',
    ];

    /** Meta whose key starts with one of these describes the old post, not this one. */
    const SKIPPED_META_PREFIXES = [ '_wbdc_', '_wpb_', '_et_pb_', '_et_builder', '_edit_', '_wp_old_', '_wp_trash_' ];

    /** Meta WordPress uses to schedule work on the old post. */
    const SKIPPED_META_KEYS = [ '_pingme', '_encloseme', '_wp_desired_post_slug' ];

    /** `add_filter( 'wbdc_copy_source_identity', '__return_false' )` to convert into a bare post. */
    const ENABLED_FILTER = 'wbdc_copy_source_identity';

    /** `apply_filters( 'wbdc_copied_meta_keys', array $keys, int $source_id, int $new_post_id )`. */
    const META_KEYS_FILTER = 'wbdc_copied_meta_keys';

    public function enabled(): bool {
        if ( ! function_exists( 'apply_filters' ) ) {
            return true;
        }

        // Literal so Plugin Check can read the hook name; keep in step with self::ENABLED_FILTER.
        return (bool) apply_filters( 'wbdc_copy_source_identity', true );
    }

    /**
     * The `wp_insert_post()` arguments that make the new post sit where the old
     * one sat: same date, same author, same place in the tree.
     *
     * @return array<string,mixed>
     */
    public function fields( object $source ): array {
        $fields = [];

        foreach ( self::COPIED_FIELDS as $field ) {
            if ( isset( $source->{$field} ) && $source->{$field} !== '' ) {
                $fields[ $field ] = $source->{$field};
            }
        }

        return $fields;
    }

    /** Everything that is not a post column: custom fields and taxonomy terms. */
    public function copy( object $source, int $new_post_id, string $new_post_type ): void {
        $this->copyMeta( (int) ( $source->ID ?? 0 ), $new_post_id );
        $this->copyTerms( $source, $new_post_id, $new_post_type );
    }

    private function copyMeta( int $source_id, int $new_post_id ): void {
        if ( $source_id <= 0 ) {
            return;
        }

        $all  = get_post_meta( $source_id );
        $keys = is_array( $all ) ? array_keys( $all ) : [];
        $keys = array_values( array_filter( $keys, fn( $key ): bool => $this->isCopyable( (string) $key ) ) );

        if ( function_exists( 'apply_filters' ) ) {
            // Literal for Plugin Check; keep in step with self::META_KEYS_FILTER.
            $keys = (array) apply_filters( 'wbdc_copied_meta_keys', $keys, $source_id, $new_post_id );
        }

        foreach ( $keys as $key ) {
            $key = (string) $key;

            // Read key by key. get_post_meta() without a key hands back values
            // as they sit in the database — serialized — and unslashing one of
            // those on the way back in breaks its length prefixes.
            foreach ( get_post_meta( $source_id, $key, false ) as $value ) {
                // add_post_meta() unslashes what it is given, the same rule the
                // content and the title follow, so every value is slashed here
                // or a path like C:\Users arrives as C:Users.
                add_post_meta( $new_post_id, $key, wp_slash( $value ) );
            }
        }
    }

    private function copyTerms( object $source, int $new_post_id, string $new_post_type ): void {
        $source_id   = (int) ( $source->ID ?? 0 );
        $source_type = (string) ( $source->post_type ?? '' );

        if ( $source_id <= 0 || $source_type === '' || ! function_exists( 'get_object_taxonomies' ) ) {
            return;
        }

        // Only what both post types have: a conversion may be asked to write a
        // page, and a page has nowhere to put a category.
        $taxonomies = array_intersect(
            (array) get_object_taxonomies( $source_type ),
            (array) get_object_taxonomies( $new_post_type )
        );

        foreach ( $taxonomies as $taxonomy ) {
            // By id, never by slug: assigning a hierarchical term by slug
            // creates a new term named after the slug instead of reusing the
            // category the post was actually in.
            $term_ids = wp_get_object_terms( $source_id, (string) $taxonomy, [ 'fields' => 'ids' ] );

            if ( is_wp_error( $term_ids ) || ! is_array( $term_ids ) || $term_ids === [] ) {
                continue;
            }

            wp_set_object_terms( $new_post_id, array_map( 'intval', $term_ids ), (string) $taxonomy, false );
        }
    }

    private function isCopyable( string $key ): bool {
        if ( in_array( $key, self::SKIPPED_META_KEYS, true ) ) {
            return false;
        }

        foreach ( self::SKIPPED_META_PREFIXES as $prefix ) {
            if ( str_starts_with( $key, $prefix ) ) {
                return false;
            }
        }

        return true;
    }
}
