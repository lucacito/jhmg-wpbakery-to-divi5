<?php

namespace WPBakeryDivi5Converter\Pro\Exporters;

use WPBakeryDivi5Converter\Exporters\DiviExporter;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Writes a converted WPBakery template into the Divi Library.
 *
 * A WPBakery template is a fragment meant to be reused, so it belongs in the
 * Divi Library (`et_pb_layout`) rather than as another page draft. What a
 * library layout is made of is read from Divi 5.12.1's own creation path, not
 * from memory — `et_pb_create_layout()` in `includes/builder/shortcode-core.php`
 * and the Divi 5 controller that calls it:
 *
 *   post_type   `et_pb_layout`      ET_BUILDER_LAYOUT_POST_TYPE — shortcode-core.php:743,
 *                                   defined in includes/builder/post/type/Layout.php:4
 *   post_status `publish`           shortcode-core.php:740 (default) and :715 (the caller)
 *   meta        `_et_pb_built_for_post_type`  shortcode-core.php:700;
 *                                   value `page` — DiviLibraryController.php:2103
 *   meta        `_et_pb_template_type`        shortcode-core.php:701;
 *                                   value `layout` — DiviLibraryController.php:642-643
 *   term        `layout_type` = `layout`      shortcode-core.php:707. The library's own
 *                                   query lists everything whose layout_type is NOT one of
 *                                   section/row/module/… (shortcode-core.php:169-176), so
 *                                   this is the term that makes a layout a whole layout.
 *   term        `scope` = `not_global`        shortcode-core.php:706;
 *                                   value — DiviLibraryController.php:2108
 *   meta        `_et_pb_use_divi_5` = `on`    builder-5/…/DiviLibrary/DiviLibraryController.php:732
 *                                   — what makes Divi 5 read the block content
 *   meta        `_et_pb_use_builder` = `on`   builder-5/…/SyncToServer/SyncToServerController.php:405
 *                                   — the legacy flag Divi 4-era code still reads
 *   meta        `_et_builder_version`         includes/builder/functions.php:4992 (`VB|Divi|<version>`)
 *
 * The last three, the block content and the `_wbdc_*` conversion record are the
 * free plugin's `DiviExporter`'s job, so they are written exactly as they are
 * on a converted page — `wp_slash()`ed content included.
 *
 * Two of Divi's own `tax_input` members are deliberately not written:
 * `module_width` is read only when the layout type is `module`
 * (shortcode-core.php:335-341, core.php:4964), and `layout_location` is not a
 * registered taxonomy in Divi 5.12.1 (`get_taxonomy( 'layout_location' )`
 * returns null on the reference install), so setting it would be a no-op.
 */
class DiviLibraryExporter {

    /** Divi's own layout post type; the constant is not defined until the theme loads. */
    const LAYOUT_POST_TYPE = 'et_pb_layout';

    /** Which conversion produced this layout, so a second run updates it instead of stacking another. */
    const SOURCE_META = '_wbdcp_library_source';

    private DiviExporter $exporter;

    public function __construct( ?DiviExporter $exporter = null ) {
        $this->exporter = $exporter ?? new DiviExporter();
    }

    /**
     * Create or update the Divi Library layout for one converted template.
     *
     * @param array<string,mixed> $item      The plan item the committer is about to write.
     * @param array<string,mixed> $divi_data ['divi' => blocks, 'report' => …, 'unsupported' => …].
     * @return int The layout's post ID, or 0 when it could not be written.
     */
    public function export( array $item, array $divi_data ): int {
        $title      = trim( (string) ( $item['title'] ?? '' ) );
        $title      = $title !== '' ? $title : __( 'Imported Template', 'jhmg-converter-for-wpbakery-to-divi-pro' );
        $source_key = $this->sourceKey( $item, $title );

        $post_id = $this->upsertLayout( $title, $source_key );
        if ( $post_id === 0 ) {
            return 0;
        }

        // Content, _et_pb_use_builder, _et_pb_use_divi_5, _et_builder_version and
        // the _wbdc_ conversion record — the same writer a converted page uses.
        if ( ! $this->exporter->save( $post_id, $divi_data ) ) {
            return 0;
        }

        update_post_meta( $post_id, '_et_pb_built_for_post_type', 'page' );
        update_post_meta( $post_id, '_et_pb_template_type', 'layout' );

        if ( function_exists( 'wp_set_object_terms' ) ) {
            wp_set_object_terms( $post_id, 'layout', 'layout_type' );
            wp_set_object_terms( $post_id, 'not_global', 'scope' );
        }

        return $post_id;
    }

    /**
     * A stable identity for "the same template, converted again".
     *
     * Without one, every re-run is a fresh `wp_insert_post()` and the Divi
     * Library fills with copies of the same layout that the customer then has
     * to tell apart by hand. A template already on this site keys on its post
     * ID; one that arrived in an export file has no ID here, so it keys on the
     * file it came from and its title — precisely what a re-import of the same
     * file reproduces.
     *
     * @param array<string,mixed> $item
     */
    private function sourceKey( array $item, string $title ): string {
        $source_ref = is_array( $item['source_ref'] ?? null ) ? $item['source_ref'] : [];
        $post_id    = (int) ( $source_ref['post_id'] ?? 0 );

        if ( ( $source_ref['kind'] ?? '' ) === 'installed' && $post_id > 0 ) {
            return 'post-' . $post_id;
        }

        $file = (string) ( $source_ref['file'] ?? '' );
        $slug = function_exists( 'sanitize_title' ) ? sanitize_title( $title ) : strtolower( trim( $title ) );

        return 'file-' . md5( $file . '|' . ( $slug !== '' ? $slug : 'untitled' ) );
    }

    /** The layout previously created for this source, or 0. */
    private function findBySourceKey( string $source_key ): int {
        $found = get_posts( [
            'post_type'              => self::LAYOUT_POST_TYPE,
            'post_status'            => 'any',
            'posts_per_page'         => 1,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_key'               => self::SOURCE_META,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            'meta_value'             => $source_key,
        ] );

        return ! empty( $found ) ? (int) $found[0] : 0;
    }

    /**
     * The layout post that will hold the converted content — reused when this
     * source has been converted before.
     *
     * Divi gates several internal checks on publish status, and
     * `et_pb_create_layout()` hardcodes `publish` (shortcode-core.php:715, :740),
     * so a layout that somehow went to draft is brought back.
     */
    private function upsertLayout( string $title, string $source_key ): int {
        $existing = $this->findBySourceKey( $source_key );

        if ( $existing > 0 ) {
            wp_update_post( [ 'ID' => $existing, 'post_title' => $title, 'post_status' => 'publish' ] );

            return $existing;
        }

        $post_id = wp_insert_post( [
            'post_type'    => self::LAYOUT_POST_TYPE,
            'post_title'   => $title,
            'post_status'  => 'publish',
            'post_content' => '',
        ] );

        if ( is_wp_error( $post_id ) || (int) $post_id === 0 ) {
            return 0;
        }

        $post_id = (int) $post_id;

        // Stamped before the content is written, not after: a run that fails
        // half way is then found and finished by the next one rather than
        // leaving an untracked empty layout behind for every retry.
        update_post_meta( $post_id, self::SOURCE_META, $source_key );

        return $post_id;
    }
}
