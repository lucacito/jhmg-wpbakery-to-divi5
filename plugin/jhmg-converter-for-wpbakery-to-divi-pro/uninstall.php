<?php
/** Fires on plugin deletion. Removes the Pro add-on's own options; converted layouts are left alone. */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

foreach ( [ 'wbdcp_license_key', 'wbdcp_license_state', 'wbdcp_update_blocked' ] as $wbdcp_option ) {
    delete_option( $wbdcp_option );
}
