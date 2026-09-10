<?php
/**
 * Undo a conversion run by moving the posts it created to the trash.
 *
 *  - It trashes, never deletes — and only when trash is actually available.
 *    With `EMPTY_TRASH_DAYS` at 0, core's wp_trash_post() deletes permanently,
 *    so this class detects that first and skips the whole run.
 *  - It only touches posts still carrying the `_wbdc_import_source` meta this
 *    plugin wrote. A post the user has replaced or adopted by hand is skipped.
 *  - It never touches the WPBakery original: a conversion only ever creates a
 *    new post, so there is nothing of the source in a run's post ids.
 */

namespace WPBakeryDivi5Converter\History;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ImportRollback {

    const QUERY_ACTION            = 'wbdc_rollback';
    const NONCE_ACTION            = 'wbdc_rollback_import';
    const NOTICE_TRANSIENT_PREFIX = 'wbdc_rollback_notice_';

    private ImportHistory $history;

    public function __construct( ?ImportHistory $history = null ) {
        $this->history = $history ?? new ImportHistory();
    }

    public function init(): void {
        add_action( 'admin_init', [ $this, 'maybe_handle_request' ] );
        add_action( 'admin_notices', [ $this, 'render_notice' ] );
    }

    /** @return array{trashed:int, skipped:int, trash_unavailable:bool} */
    public function rollback( string $import_id ): array {
        $run = $this->history->find( $import_id );

        if ( $run === null ) {
            return [ 'trashed' => 0, 'skipped' => 0, 'trash_unavailable' => false ];
        }

        if ( ! self::trash_available() ) {
            return [ 'trashed' => 0, 'skipped' => count( (array) ( $run['post_ids'] ?? [] ) ), 'trash_unavailable' => true ];
        }

        $trashed = 0;
        $skipped = 0;

        foreach ( (array) ( $run['post_ids'] ?? [] ) as $post_id ) {
            if ( (string) get_post_meta( (int) $post_id, '_wbdc_import_source', true ) === '' ) {
                $skipped++;
                continue;
            }

            if ( wp_trash_post( (int) $post_id ) ) {
                $trashed++;
            } else {
                $skipped++;
            }
        }

        $this->history->mark_rolled_back( $import_id );

        return [ 'trashed' => $trashed, 'skipped' => $skipped, 'trash_unavailable' => false ];
    }

    public static function trash_available(): bool {
        return ! ( defined( 'EMPTY_TRASH_DAYS' ) && (int) EMPTY_TRASH_DAYS === 0 );
    }

    public function maybe_handle_request(): void {
        if ( empty( $_GET[ self::QUERY_ACTION ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce is verified below
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
            return;
        }

        $result = $this->rollback( sanitize_key( wp_unslash( $_GET[ self::QUERY_ACTION ] ) ) );
        set_transient( $this->notice_transient_key(), $result, MINUTE_IN_SECONDS );
    }

    public function render_notice(): void {
        $result = get_transient( $this->notice_transient_key() );

        if ( ! is_array( $result ) ) {
            return;
        }

        delete_transient( $this->notice_transient_key() );
        echo $this->notice_markup( $result ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in notice_markup()
    }

    /** @param array<string,mixed> $result */
    public function notice_markup( array $result ): string {
        if ( ! empty( $result['trash_unavailable'] ) ) {
            return '<div class="notice notice-warning is-dismissible wbdc-rollback-notice"><p>'
                . esc_html__( 'Undo did not run: this site is configured to empty the Trash immediately, so nothing could be trashed. No pages were changed.', 'jhmg-converter-for-wpbakery-to-divi' )
                . '</p></div>';
        }

        $trashed = (int) ( $result['trashed'] ?? 0 );
        $skipped = (int) ( $result['skipped'] ?? 0 );
        $parts   = [];

        if ( $trashed > 0 ) {
            $parts[] = esc_html( sprintf(
                /* translators: %d: number of pages moved to the Trash. */
                _n(
                    '%d page was moved to the Trash. You can restore it from Pages → Trash if needed.',
                    '%d pages were moved to the Trash. You can restore them from Pages → Trash if needed.',
                    $trashed,
                    'jhmg-converter-for-wpbakery-to-divi'
                ),
                $trashed
            ) );
        }
        if ( $skipped > 0 ) {
            $parts[] = esc_html( sprintf(
                /* translators: %d: number of pages skipped. */
                _n(
                    '%d page was skipped because it is no longer owned by this plugin (already gone, or replaced since the conversion).',
                    '%d pages were skipped because they are no longer owned by this plugin (already gone, or replaced since the conversion).',
                    $skipped,
                    'jhmg-converter-for-wpbakery-to-divi'
                ),
                $skipped
            ) );
        }
        if ( empty( $parts ) ) {
            $parts[] = esc_html__( 'Nothing to undo: this run had already been undone or had no pages left to trash.', 'jhmg-converter-for-wpbakery-to-divi' );
        }

        return '<div class="notice notice-success is-dismissible wbdc-rollback-notice"><p>' . implode( ' ', $parts ) . '</p></div>';
    }

    private function notice_transient_key(): string {
        return self::NOTICE_TRANSIENT_PREFIX . get_current_user_id();
    }
}
