<?php
/**
 * Fires on plugin deletion (not deactivation). Removes the plugin's options,
 * transients and user meta. Converted pages are the user's content and are
 * left alone.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

array_map(
    'delete_option',
    [ 'wbdc_import_history', 'wbdc_telemetry_consent', 'wbdc_telemetry_last_sent', 'wbdc_conversions_total' ]
);

/**
 * Four screens park their working state in a transient keyed by user or by run
 * — a preflight plan (`wbdc_direct_plan_ids_`), a batch (`wbdc_batch_`), an
 * upload's items (`wbdc_import_items_`) and a rollback notice
 * (`wbdc_rollback_notice_`) — and none of them keeps a list of the keys it
 * wrote, so they are swept by name. Deleting them as options rather than with
 * a DELETE keeps the `alloptions` cache in step.
 */
global $wpdb;

if ( isset( $wpdb ) && is_object( $wpdb ) ) {
    foreach ( [ 'wbdc_direct_plan_ids_', 'wbdc_batch_', 'wbdc_import_items_', 'wbdc_rollback_notice_' ] as $wbdc_prefix ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one sweep at uninstall; there is no API for "every transient with this prefix".
        $wbdc_leftovers = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like( '_transient_' . $wbdc_prefix ) . '%',
                $wpdb->esc_like( '_transient_timeout_' . $wbdc_prefix ) . '%'
            )
        );

        foreach ( (array) $wbdc_leftovers as $wbdc_option_name ) {
            delete_option( $wbdc_option_name );
        }
    }
}

delete_metadata( 'user', 0, 'wbdc_review_prompt_state', '', true );
