<?php
/**
 * Fires on plugin deletion (not deactivation). Removes the plugin's options
 * and user meta. Converted pages are the user's content and are left alone.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

array_map(
    'delete_option',
    [ 'wbdc_import_history', 'wbdc_telemetry_consent', 'wbdc_telemetry_last_sent', 'wbdc_divi_requirement_failed', 'wbdc_conversions_total' ]
);

delete_metadata( 'user', 0, 'wbdc_review_prompt_state', '', true );
