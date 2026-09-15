<?php
/**
 * Undo a conversion run, which means one of two things.
 *
 *  - A post converted **in place** is put back: its WPBakery shortcodes are
 *    restored from `_wbdc_original_content` and every meta the conversion
 *    wrote is removed. The post itself is never trashed — it is the reader's
 *    post, and it was theirs before the conversion touched it.
 *  - A post the conversion **created** is trashed, never deleted, and only
 *    when trash is actually available. With `EMPTY_TRASH_DAYS` at 0, core's
 *    wp_trash_post() deletes permanently, so those are skipped instead.
 *
 * Either way it only touches posts still carrying the `_wbdc_import_source`
 * meta this plugin wrote: a post the reader has since replaced or adopted by
 * hand is left alone.
 */

namespace WPBakeryDivi5Converter\History;

use WPBakeryDivi5Converter\Conversion\ConversionCommitter;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ImportRollback {

    const QUERY_ACTION            = 'wbdc_rollback';
    const NONCE_ACTION            = 'wbdc_rollback_import';
    const NOTICE_TRANSIENT_PREFIX = 'wbdc_rollback_notice_';

    /** Everything a conversion writes onto a post, removed when the run is undone. */
    const CONVERSION_META = [
        '_et_pb_use_builder',
        '_et_pb_use_divi_5',
        '_et_builder_version',
        '_wbdc_divi_data',
        '_wbdc_conversion_report',
        '_wbdc_import_source',
        '_wbdc_source_post_id',
        ConversionCommitter::META_IN_PLACE,
        ConversionCommitter::META_ORIGINAL_CONTENT,
    ];

    private ImportHistory $history;

    public function __construct( ?ImportHistory $history = null ) {
        $this->history = $history ?? new ImportHistory();
    }

    public function init(): void {
        add_action( 'admin_init', [ $this, 'maybe_handle_request' ] );
        add_action( 'admin_notices', [ $this, 'render_notice' ] );
    }

    /** @return array{trashed:int, restored:int, skipped:int, trash_unavailable:bool} */
    public function rollback( string $import_id ): array {
        $run = $this->history->find( $import_id );

        if ( $run === null ) {
            return [ 'trashed' => 0, 'restored' => 0, 'skipped' => 0, 'trash_unavailable' => false ];
        }

        $trashed           = 0;
        $restored          = 0;
        $skipped           = 0;
        $trash_unavailable = false;

        foreach ( (array) ( $run['post_ids'] ?? [] ) as $post_id ) {
            $post_id = (int) $post_id;

            if ( (string) get_post_meta( $post_id, '_wbdc_import_source', true ) === '' ) {
                $skipped++;
                continue;
            }

            // Restoring a post needs no trash, so an in-place run is undoable
            // on a site that empties the trash immediately.
            if ( (string) get_post_meta( $post_id, ConversionCommitter::META_IN_PLACE, true ) === '1' ) {
                if ( $this->restore( $post_id ) ) {
                    $restored++;
                } else {
                    $skipped++;
                }
                continue;
            }

            if ( ! self::trash_available() ) {
                $trash_unavailable = true;
                $skipped++;
                continue;
            }

            if ( wp_trash_post( $post_id ) ) {
                $trashed++;
            } else {
                $skipped++;
            }
        }

        $this->history->mark_rolled_back( $import_id );

        return [ 'trashed' => $trashed, 'restored' => $restored, 'skipped' => $skipped, 'trash_unavailable' => $trash_unavailable ];
    }

    /**
     * Puts a post converted in place back the way it was: the shortcodes it
     * held, and none of the metadata the conversion added. Anything else on the
     * post — its own custom fields, its terms — was never touched, so there is
     * nothing to put back.
     */
    private function restore( int $post_id ): bool {
        $original = (string) get_post_meta( $post_id, ConversionCommitter::META_ORIGINAL_CONTENT, true );

        if ( $original === '' ) {
            return false;
        }

        // wp_update_post() unslashes, the same rule the conversion followed on
        // the way in.
        $updated = wp_update_post( [ 'ID' => $post_id, 'post_content' => wp_slash( $original ) ], true );

        if ( is_wp_error( $updated ) || ! $updated ) {
            return false;
        }

        foreach ( self::CONVERSION_META as $key ) {
            delete_post_meta( $post_id, $key );
        }

        return true;
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
        // A run that also put pages back has changed something, so the
        // trash-unavailable warning must not claim otherwise.
        if ( ! empty( $result['trash_unavailable'] ) && (int) ( $result['restored'] ?? 0 ) === 0 ) {
            return '<div class="notice notice-warning is-dismissible wbdc-rollback-notice"><p>'
                . esc_html__( 'Undo did not run: this site is configured to empty the Trash immediately, so nothing could be trashed. No pages were changed.', 'jhmg-converter-for-wpbakery-to-divi' )
                . '</p></div>';
        }

        $trashed  = (int) ( $result['trashed'] ?? 0 );
        $restored = (int) ( $result['restored'] ?? 0 );
        $skipped  = (int) ( $result['skipped'] ?? 0 );
        $parts    = [];

        if ( $restored > 0 ) {
            $parts[] = esc_html( sprintf(
                /* translators: %d: number of pages put back the way they were. */
                _n(
                    '%d page was put back: its WPBakery content is exactly as it was before the conversion.',
                    '%d pages were put back: their WPBakery content is exactly as it was before the conversion.',
                    $restored,
                    'jhmg-converter-for-wpbakery-to-divi'
                ),
                $restored
            ) );
        }

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
