<?php

namespace WPBakeryDivi5Converter\Pro\Licensing;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The License tab: activate, deactivate, re-check. Soft enforcement — this
 * only informs the site owner about licence and update status; it never
 * disables a conversion feature.
 */
class LicensePage {

    const NONCE_NAME        = 'wbdcp_license_nonce';
    const NONCE_ACTION      = 'wbdcp_license';
    const SAVE_ACTION       = 'wbdcp_save_license';
    const DEACTIVATE_ACTION = 'wbdcp_deactivate_license';
    const REFRESH_ACTION    = 'wbdcp_refresh_license';

    public function __construct( private LicenseClient $license ) {}

    public function maybe_render_notice(): void {
        $this->license->status_notice();
    }

    /** Called from ProPage::handle_post(). */
    public function handle_post(): void {
        $action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked per branch
        if ( $action === self::SAVE_ACTION ) {
            $this->handle_activate();
            return;
        }
        $wbdcp_action = isset( $_GET['wbdcp_action'] ) ? sanitize_key( wp_unslash( $_GET['wbdcp_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- each branch checks its own nonce
        if ( $wbdcp_action === 'deactivate_license' ) {
            $this->handle_deactivate();
        } elseif ( $wbdcp_action === 'refresh_license' ) {
            $this->handle_refresh();
        }
    }

    private function handle_activate(): void {
        $this->require_admin();
        check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );
        $key    = isset( $_POST['wbdcp_license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['wbdcp_license_key'] ) ) : '';
        $result = $key !== '' ? $this->license->activate( $key ) : [ 'ok' => false, 'error' => 'invalid_request' ];
        $this->redirect( [
            'wbdcp_notice' => $result['ok'] ? 'license_activated' : 'license_error',
            'wbdcp_error'  => $result['ok'] ? '' : (string) ( $result['error'] ?? 'unknown' ),
        ] );
    }

    private function handle_deactivate(): void {
        $this->require_admin();
        check_admin_referer( self::DEACTIVATE_ACTION );
        $this->license->deactivate();
        $this->redirect( [ 'wbdcp_notice' => 'license_deactivated' ] );
    }

    private function handle_refresh(): void {
        $this->require_admin();
        check_admin_referer( self::REFRESH_ACTION );
        $this->license->refresh( true );
        $this->redirect( [ 'wbdcp_notice' => 'license_refreshed' ] );
    }

    private function require_admin(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'jhmg-converter-for-wpbakery-to-divi-pro' ) );
        }
    }

    protected function redirect( array $args ): void {
        wp_safe_redirect( add_query_arg(
            array_merge( [ 'page' => \WPBakeryDivi5Converter\Pro\Admin\ProPage::MENU_SLUG, 'tab' => 'license' ], $args ),
            admin_url( 'tools.php' )
        ) );
        exit;
    }

    /**
     * A key shown back to its owner, enough of it to recognise and not enough
     * to use. Anything eight characters or shorter is masked whole: keeping
     * four from each end of a short key shows the reader the whole thing.
     */
    public static function mask( string $key ): string {
        if ( strlen( $key ) > 8 ) {
            return substr( $key, 0, 4 ) . str_repeat( '•', strlen( $key ) - 8 ) . substr( $key, -4 );
        }

        return str_repeat( '•', strlen( $key ) );
    }

    /** The tab body. Returns a string. */
    public function markup(): string {
        $key    = $this->license->get_key();
        $state  = $this->license->get_state();
        $status = (string) ( $state['status'] ?? '' );
        $base   = admin_url( 'tools.php?page=' . \WPBakeryDivi5Converter\Pro\Admin\ProPage::MENU_SLUG . '&tab=license' );

        $html = '<div class="wbdc-card"><h2>' . esc_html__( 'Licence', 'jhmg-converter-for-wpbakery-to-divi-pro' ) . '</h2>';
        $html .= '<p class="description">' . esc_html__( 'Your licence unlocks automatic updates and support. Conversion features work regardless.', 'jhmg-converter-for-wpbakery-to-divi-pro' ) . '</p>';

        if ( $key ) {
            $html .= '<p><strong>' . esc_html__( 'Status:', 'jhmg-converter-for-wpbakery-to-divi-pro' ) . '</strong> ' . esc_html( $status !== '' ? $status : 'unknown' );
            if ( ! empty( $state['expires'] ) ) {
                $html .= ' — ' . esc_html( sprintf( /* translators: %s: expiry date */ __( 'expires %s', 'jhmg-converter-for-wpbakery-to-divi-pro' ), (string) $state['expires'] ) );
            }
            $html .= '</p><p><code>' . esc_html( self::mask( $key ) ) . '</code></p>';
            $html .= '<p><a class="button" href="' . esc_url( $base . '&wbdcp_action=refresh_license&_wpnonce=' . wp_create_nonce( self::REFRESH_ACTION ) ) . '">' . esc_html__( 'Check again', 'jhmg-converter-for-wpbakery-to-divi-pro' ) . '</a> ';
            $html .= '<a class="button-link-delete" href="' . esc_url( $base . '&wbdcp_action=deactivate_license&_wpnonce=' . wp_create_nonce( self::DEACTIVATE_ACTION ) ) . '">' . esc_html__( 'Deactivate on this site', 'jhmg-converter-for-wpbakery-to-divi-pro' ) . '</a></p>';
        } else {
            $html .= '<form method="post">' . wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME, true, false );
            $html .= '<input type="hidden" name="action" value="' . esc_attr( self::SAVE_ACTION ) . '">';
            $html .= '<p><label for="wbdcp_license_key"><strong>' . esc_html__( 'Licence key', 'jhmg-converter-for-wpbakery-to-divi-pro' ) . '</strong></label><br><input type="text" id="wbdcp_license_key" name="wbdcp_license_key" class="regular-text" required></p>';
            $html .= '<p><button type="submit" class="button button-primary">' . esc_html__( 'Activate', 'jhmg-converter-for-wpbakery-to-divi-pro' ) . '</button></p></form>';
        }

        return $html . '</div>';
    }
}
