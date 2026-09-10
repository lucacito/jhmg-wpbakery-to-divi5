<?php
/**
 * Opt-in, anonymous report of what this site's conversions met that has no
 * Divi 5 equivalent yet.
 *
 * Sends two things and nothing else: WPBakery element tags this converter has
 * no handler for, and the keys of the theme and add-on families the runs met
 * (task-12-amendments §4). No counts, no versions, no site identifier, no
 * URLs, no post content. Off by default; nothing leaves the site until the
 * user turns it on from the coverage panel.
 *
 * "Nothing else" includes the request headers: the `user-agent` is set
 * explicitly, because WordPress's default one carries the site's own URL.
 */

namespace WPBakeryDivi5Converter\Telemetry;

use WPBakeryDivi5Converter\History\ImportHistory;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CoverageTelemetry {

    const CONSENT_OPTION   = 'wbdc_telemetry_consent';
    const LAST_SENT_OPTION = 'wbdc_telemetry_last_sent';
    const QUERY_ACTION     = 'wbdc_telemetry_consent_set';
    const NONCE_ACTION     = 'wbdc_telemetry_consent';
    const PRODUCT          = 'wpbakery-to-divi5';
    const ENDPOINT         = 'https://divi5lab.com/api/plugin/coverage';
    const INTERVAL_DAYS    = 7;

    /** Replaces WordPress's default, which names the site. See `maybe_send()`. */
    const USER_AGENT = 'jhmg-converter-for-wpbakery-to-divi/' . WBDC_PLUGIN_VERSION;

    // Mirror the receiving endpoint's schema: widget_types: string(1..64)[1..100].
    const MAX_TYPE_LENGTH = 64;
    const MAX_TYPES       = 100;

    private ImportHistory $history;
    private string $today;

    public function __construct( ?ImportHistory $history = null, ?string $today = null ) {
        $this->history = $history ?? new ImportHistory();
        $this->today   = $today ?? gmdate( 'Y-m-d' );
    }

    public function init(): void {
        add_action( 'admin_init', [ $this, 'maybe_handle_consent' ] );
        // Never during a conversion: a slow endpoint must not delay the user's work.
        add_action( 'admin_init', [ $this, 'maybe_send' ] );
    }

    public function has_consent(): bool {
        return (string) get_option( self::CONSENT_OPTION, '' ) === '1';
    }

    public function due(): bool {
        $last = (string) get_option( self::LAST_SENT_OPTION, '' );

        if ( $last === '' ) {
            return true;
        }

        return $this->today >= gmdate( 'Y-m-d', (int) strtotime( $last . ' +' . self::INTERVAL_DAYS . ' days' ) );
    }

    /** @return array{product:string, widget_types:string[]} */
    public function payload(): array {
        $types = array_merge( array_column( $this->history->coverage(), 'type' ), $this->history->theme_families() );
        $types = array_values( array_unique( array_filter(
            $types,
            static fn( string $t ): bool => $t !== '' && strlen( $t ) <= self::MAX_TYPE_LENGTH
        ) ) );

        return [ 'product' => self::PRODUCT, 'widget_types' => array_slice( $types, 0, self::MAX_TYPES ) ];
    }

    public function maybe_send(): void {
        if ( ! $this->has_consent() || ! $this->due() ) {
            return;
        }

        $payload = $this->payload();
        if ( empty( $payload['widget_types'] ) ) {
            return;
        }

        wp_remote_post( self::ENDPOINT, [
            'timeout'    => 5,
            'blocking'   => false,
            'headers'    => [ 'content-type' => 'application/json' ],
            // WordPress's own default is `WordPress/<version>; <site url>`
            // (`wp-includes/class-wp-http.php`), which would put the site
            // address in the request header — the one thing the readme and the
            // consent line promise is never sent. This says what the client is
            // and nothing about who is running it.
            'user-agent' => self::USER_AGENT,
            'body'       => wp_json_encode( $payload ),
        ] );

        update_option( self::LAST_SENT_OPTION, $this->today );
    }

    public function maybe_handle_consent(): void {
        if ( ! isset( $_GET[ self::QUERY_ACTION ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce is verified below
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
            return;
        }

        update_option( self::CONSENT_OPTION, sanitize_key( wp_unslash( $_GET[ self::QUERY_ACTION ] ) ) === '1' ? '1' : '0' );
    }
}
