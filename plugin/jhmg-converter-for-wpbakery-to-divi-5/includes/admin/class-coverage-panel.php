<?php
/**
 * Read-only summary of what this site's conversions could not convert, with
 * the recent runs and their Undo controls. Lives on the plugin's Tools page.
 */

namespace WPBakeryDivi5Converter\Admin;

use WPBakeryDivi5Converter\History\ImportHistory;
use WPBakeryDivi5Converter\History\ImportRollback;
use WPBakeryDivi5Converter\Telemetry\CoverageTelemetry;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CoveragePanel {

    private ImportHistory $history;

    public function __construct( ?ImportHistory $history = null ) {
        $this->history = $history ?? new ImportHistory();
    }

    public function render(): void {
        echo $this->markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in markup()
    }

    public function markup(): string {
        $runs = $this->history->all();

        if ( empty( $runs ) ) {
            return '';
        }

        $coverage = $this->history->coverage();

        if ( empty( $coverage ) ) {
            return '<div class="wbdc-card wbdc-card--success"><h2>' . esc_html__( 'Conversion coverage', 'jhmg-converter-for-wpbakery-to-divi-5' ) . '</h2><p>'
                . esc_html__( 'Everything converted. No WPBakery elements were left unsupported across your recent conversions.', 'jhmg-converter-for-wpbakery-to-divi-5' )
                . '</p></div>' . $this->runs_table();
        }

        $rows = '';
        foreach ( $coverage as $item ) {
            $rows .= sprintf(
                '<tr><td><code>%1$s</code></td><td>%2$d</td><td>%3$s</td></tr>',
                esc_html( (string) $item['type'] ),
                (int) $item['runs'],
                esc_html( (string) $item['last_seen'] )
            );
        }

        return sprintf(
            '<div class="wbdc-card"><h2>%1$s</h2><p class="description">%2$s</p><table class="widefat striped"><thead><tr><th>%3$s</th><th>%4$s</th><th>%5$s</th></tr></thead><tbody>%6$s</tbody></table>%7$s</div>',
            esc_html__( 'Conversion coverage', 'jhmg-converter-for-wpbakery-to-divi-5' ),
            esc_html__( 'WPBakery elements from your recent conversions that have no Divi 5 equivalent yet. These need rebuilding by hand. A theme or add-on element is not listed here: it is kept, as static HTML or as a labelled placeholder, and named in each page\'s own report.', 'jhmg-converter-for-wpbakery-to-divi-5' ),
            esc_html__( 'Element', 'jhmg-converter-for-wpbakery-to-divi-5' ),
            esc_html__( 'Runs affected', 'jhmg-converter-for-wpbakery-to-divi-5' ),
            esc_html__( 'Last seen', 'jhmg-converter-for-wpbakery-to-divi-5' ),
            $rows,
            $this->consent_line()
        ) . $this->runs_table();
    }

    private function consent_line(): string {
        $telemetry = new CoverageTelemetry( $this->history );
        $on        = $telemetry->has_consent();
        $url       = add_query_arg( CoverageTelemetry::QUERY_ACTION, $on ? '0' : '1' ) . '&_wpnonce=' . wp_create_nonce( CoverageTelemetry::NONCE_ACTION );

        return sprintf(
            '<p class="description">%1$s <a href="%2$s">%3$s</a></p>',
            $on
                ? esc_html__( 'Sharing this list of element names with divi5lab so these gaps get prioritised. No site address, no content, no personal data.', 'jhmg-converter-for-wpbakery-to-divi-5' )
                : esc_html__( 'Help prioritise these elements? Sharing sends only the element names above and the theme families they came from, once a week. No site address, no content, no personal data.', 'jhmg-converter-for-wpbakery-to-divi-5' ),
            esc_url( $url ),
            $on
                ? esc_html__( 'Stop sharing', 'jhmg-converter-for-wpbakery-to-divi-5' )
                : esc_html__( 'Share these element names', 'jhmg-converter-for-wpbakery-to-divi-5' )
        );
    }

    private function runs_table(): string {
        $rows            = '';
        $trash_available = ImportRollback::trash_available();

        foreach ( $this->history->all() as $run ) {
            $in_place = ! empty( $run['in_place'] );
            $count    = count( (array) ( $run['post_ids'] ?? [] ) );

            if ( ! empty( $run['rolled_back'] ) ) {
                $undo = esc_html__( 'Undone', 'jhmg-converter-for-wpbakery-to-divi-5' );
            } elseif ( ! $trash_available && ! $in_place ) {
                // Only a run that made pages needs somewhere to put them.
                $undo = esc_html__( 'Undo is unavailable because this site empties the trash immediately.', 'jhmg-converter-for-wpbakery-to-divi-5' );
            } else {
                $confirm = $in_place
                    ? sprintf(
                        /* translators: %d: number of pages this run converted. */
                        __( 'Put the %d page(s) this run converted back the way they were? The WPBakery content returns and the Divi version is discarded.', 'jhmg-converter-for-wpbakery-to-divi-5' ),
                        $count
                    )
                    : sprintf(
                        /* translators: %d: number of pages this run created. */
                        __( 'Move the %d page(s) this run created to the Trash?', 'jhmg-converter-for-wpbakery-to-divi-5' ),
                        $count
                    );
                $undo = sprintf(
                    '<a href="%1$s" class="button button-small" onclick="%2$s">%3$s</a>',
                    esc_url( add_query_arg( ImportRollback::QUERY_ACTION, (string) ( $run['id'] ?? '' ) ) . '&_wpnonce=' . wp_create_nonce( ImportRollback::NONCE_ACTION ) ),
                    esc_attr( sprintf( "return confirm('%s');", addslashes( $confirm ) ) ),
                    esc_html__( 'Undo', 'jhmg-converter-for-wpbakery-to-divi-5' )
                );
            }

            $rows .= sprintf(
                '<tr><td>%1$s</td><td>%2$d</td><td>%3$s</td></tr>',
                esc_html( (string) ( $run['at'] ?? '' ) ),
                count( (array) ( $run['post_ids'] ?? [] ) ),
                $undo
            );
        }

        return sprintf(
            '<div class="wbdc-card"><h2>%1$s</h2><p class="description">%2$s</p><table class="widefat striped"><thead><tr><th>%3$s</th><th>%4$s</th><th></th></tr></thead><tbody>%5$s</tbody></table></div>',
            esc_html__( 'Recent conversions', 'jhmg-converter-for-wpbakery-to-divi-5' ),
            esc_html__( 'Undo puts a converted page back the way it was, with its WPBakery content returned. A run that made new drafts instead has those drafts moved to the trash. Either way it skips pages that are no longer linked to this plugin (already gone, or replaced) — editing a page does not exempt it from Undo.', 'jhmg-converter-for-wpbakery-to-divi-5' ),
            esc_html__( 'When', 'jhmg-converter-for-wpbakery-to-divi-5' ),
            esc_html__( 'Pages', 'jhmg-converter-for-wpbakery-to-divi-5' ),
            $rows
        );
    }
}
