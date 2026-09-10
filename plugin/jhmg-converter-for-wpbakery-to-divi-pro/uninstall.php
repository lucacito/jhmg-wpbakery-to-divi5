<?php
/** Fires on plugin deletion. Removes the Pro add-on's own options; converted layouts are left alone. */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

foreach ( [ 'wbdcp_license_key', 'wbdcp_license_state', 'wbdcp_update_blocked' ] as $wbdcp_option ) {
    delete_option( $wbdcp_option );
}

/**
 * The licence client also caches each update check in a transient keyed by
 * product, version and key (`wbdcp_update_check_<md5>`), and it keeps no list
 * of them — so they are swept by name. Deleting them as options rather than
 * with a DELETE keeps the `alloptions` cache in step.
 */
global $wpdb;

if ( isset( $wpdb ) && is_object( $wpdb ) ) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one sweep at uninstall; there is no API for "every transient with this prefix".
    $wbdcp_leftovers = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like( '_transient_wbdcp_update_check_' ) . '%',
            $wpdb->esc_like( '_transient_timeout_wbdcp_update_check_' ) . '%'
        )
    );

    foreach ( (array) $wbdcp_leftovers as $wbdcp_option_name ) {
        delete_option( $wbdcp_option_name );
    }
}
