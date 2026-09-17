<?php

namespace WPBakeryDivi5Converter\Exporters;

use WPBakeryDivi5Converter\Helpers\DiviRequirement;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Writes a converted layout onto a post: the block content plus the metas Divi
 * 5 reads to recognise the page as its own, and the `_wbdc_*` record of what
 * this conversion did — the block tree, the report, and which post it came
 * from, so the admin panel can show it and a rollback can undo it.
 */
class DiviExporter {

    private DiviBlockSerializer $serializer;

    public function __construct( ?DiviBlockSerializer $serializer = null ) {
        $this->serializer = $serializer ?? new DiviBlockSerializer();
    }

    /** @return array<string,string> Post meta key ⇒ value. */
    public function export( array $divi_data ): array {
        $meta = [];

        $meta['_et_pb_use_builder']  = 'on';
        $meta['_et_builder_version'] = sprintf(
            'VB|Divi|%s',
            defined( 'ET_BUILDER_VERSION' ) ? ET_BUILDER_VERSION : DiviRequirement::MINIMUM_DIVI_VERSION
        );
        $meta['_wbdc_divi_data'] = (string) wp_json_encode( $divi_data );

        if ( isset( $divi_data['report'] ) ) {
            $meta['_wbdc_conversion_report'] = (string) wp_json_encode( array_merge(
                $divi_data['report'],
                [ 'unsupported' => $divi_data['unsupported'] ?? [] ]
            ) );
        }

        // Must be the string 'on': Divi checks === 'on' to recognise a Divi 5 post.
        $meta['_et_pb_use_divi_5'] = 'on';

        return $meta;
    }

    /**
     * @return bool False when the post could not be written — the meta is not
     *   written either, so a half-converted post never claims to be a Divi 5
     *   page it has no content for.
     */
    public function save( int $post_id, array $divi_data, int $source_post_id = 0 ): bool {
        $meta         = $this->export( $divi_data );
        $post_content = $this->serializer->serialize( $divi_data );

        // wp_update_post() unslashes its input; without wp_slash() the JSON
        // escapes inside block attributes lose their backslashes and the page
        // renders "u003Cp" where a paragraph should be.
        $updated = wp_update_post( [
            'ID'           => $post_id,
            'post_content' => wp_slash( $post_content ),
        ], true );

        if ( is_wp_error( $updated ) || ! $updated ) {
            return false;
        }

        // `update_metadata()` unslashes what it is given, exactly as
        // `wp_update_post()` does, so a value that is not slashed on the way in
        // comes back with its backslashes gone. For the two JSON metas that
        // means every `\"` inside `_wbdc_divi_data` and
        // `_wbdc_conversion_report` is eaten and the stored string is no longer
        // valid JSON — a report whose only crime was quoting a WPBakery
        // attribute value cannot be read back at all.
        foreach ( $meta as $key => $value ) {
            update_post_meta( $post_id, $key, wp_slash( $value ) );
        }

        if ( $source_post_id > 0 ) {
            update_post_meta( $post_id, '_wbdc_source_post_id', $source_post_id );
        }

        // Divi caches static CSS and the dynamic-assets module list per post;
        // without this the previous document's stylesheet is served.
        if ( class_exists( 'ET_Core_PageResource' ) ) {
            \ET_Core_PageResource::remove_static_resources( $post_id, 'all' );
        }
        delete_post_meta( $post_id, '_divi_dynamic_assets_cached_modules' );
        delete_post_meta( $post_id, '_divi_dynamic_assets_cached_feature_used' );

        return true;
    }
}
