<?php
/**
 * "Convert a page already on this site": pick an installed WPBakery page,
 * check what the conversion will produce, then convert it.
 *
 * Two safety properties are enforced here rather than in markup:
 *  - The rendered picker is never trusted on the way back in. Every submitted
 *    post ID is re-verified as an existing post that really holds WPBakery
 *    content — the same test the conversion itself makes.
 *  - The selection is capped server-side at `wbdc_direct_conversion_limit`.
 */

namespace WPBakeryDivi5Converter\Admin;

use WPBakeryDivi5Converter\Conversion\ConversionPlan;
use WPBakeryDivi5Converter\Conversion\ConversionPreflight;
use WPBakeryDivi5Converter\Conversion\InstalledPostSource;
use WPBakeryDivi5Converter\Helpers\DiviRequirement;
use WPBakeryDivi5Converter\History\ImportHistory;
use WPBakeryDivi5Converter\Parsers\WPBakeryDocumentParser;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DirectConversionPage {

    const CHECK_ACTION   = 'wbdc_direct_check';
    const CONVERT_ACTION = 'wbdc_direct_convert';
    const CHECK_NONCE    = 'wbdc_direct_check_nonce';
    const CONVERT_NONCE  = 'wbdc_direct_convert_nonce';

    /** Ticked, the conversion writes a separate draft and leaves the page alone. */
    const CREATE_NEW_FIELD = 'wbdc_create_new';
    const CAPABILITY     = 'manage_options';

    /** The form field the picker submits. */
    const IDS_FIELD = 'wbdc_post_ids';

    /** A checked selection lives here (per user) between the check step and the report render. */
    const PLAN_IDS_TRANSIENT_PREFIX = 'wbdc_direct_plan_ids_';

    private WPBakeryPageRepository $repo;

    public function __construct( ?WPBakeryPageRepository $repo = null ) {
        $this->repo = $repo ?? new WPBakeryPageRepository();
    }

    public function init(): void {
        add_action( 'admin_init', [ $this, 'maybe_handle_request' ] );
    }

    /**
     * Sanitise and verify a submitted selection without capping it, so the
     * check handler can tell whether a selection will be truncated.
     *
     * @param array<string,mixed> $request
     * @return int[] Post IDs that exist, hold WPBakery content, and are deduplicated.
     */
    public function verified_post_ids( array $request ): array {
        $raw = $request[ self::IDS_FIELD ] ?? [];
        if ( ! is_array( $raw ) ) {
            $raw = [ $raw ];
        }

        $ids = [];
        foreach ( $raw as $value ) {
            if ( ! is_scalar( $value ) || ! ctype_digit( (string) $value ) ) {
                continue;
            }

            $id = (int) $value;
            if ( $id <= 0 || in_array( $id, $ids, true ) || ! $this->is_wpbakery_post( $id ) ) {
                continue;
            }

            $ids[] = $id;
        }

        return $ids;
    }

    /** @return int[] Verified and capped. */
    public function selected_post_ids( array $request ): array {
        return array_slice( $this->verified_post_ids( $request ), 0, ConversionPreflight::limit() );
    }

    /**
     * A dry run over the selection. Writes nothing.
     *
     * @param int[] $post_ids
     */
    public function plan_for( array $post_ids ): ConversionPlan {
        return ( new ConversionPreflight() )->run( new InstalledPostSource( $post_ids ) );
    }

    /**
     * Convert the selection.
     *
     * By default each page is converted where it stands, so it keeps its
     * address and everything pointing at it; `create_new` writes a separate
     * draft and leaves the original untouched. Either way the run is undoable.
     *
     * @param int[]               $post_ids
     * @param array<string,mixed> $options
     * @return array[]
     */
    public function convert( array $post_ids, array $options = [] ): array {
        return ( new BatchImporter() )->importPlan(
            $this->plan_for( $post_ids ),
            array_merge( [ 'post_status' => 'draft' ], $options )
        );
    }

    /**
     * The flag WPBakery writes is not the test: the shortcodes are. A page
     * whose `_wpb_vc_js_status` was lost converts exactly as well as one whose
     * flag is there.
     */
    private function is_wpbakery_post( int $post_id ): bool {
        $post = get_post( $post_id );

        return $post !== null && WPBakeryDocumentParser::isWPBakeryContent( (string) ( $post->post_content ?? '' ) );
    }

    /**
     * The page picker. Renders, never echoes.
     *
     * @param array<string,mixed> $args
     */
    public function render_picker( array $args = [] ): string {
        $limit    = ConversionPreflight::limit();
        $search   = trim( (string) ( $args['search'] ?? '' ) );
        $paged    = max( 1, (int) ( $args['paged'] ?? 1 ) );
        $per_page = WPBakeryPageRepository::PER_PAGE;

        // One extra row reveals whether a next page exists; the offset stays on the real page size.
        $rows     = $this->repo->find( [
            'search'   => $search,
            'paged'    => $paged,
            'per_page' => $per_page + 1,
            'offset'   => ( $paged - 1 ) * $per_page,
        ] );
        $has_next = count( $rows ) > $per_page;
        if ( $has_next ) {
            $rows = array_slice( $rows, 0, $per_page );
        }

        $html = $this->render_search_box( $search );

        if ( empty( $rows ) ) {
            return $html . '<p class="wbdc-direct-empty">'
                . esc_html__( 'No WPBakery pages found on this site. If your pages live elsewhere, use the file upload below.', 'jhmg-converter-for-wpbakery-to-divi' )
                . '</p>';
        }

        $input_type = $limit > 1 ? 'checkbox' : 'radio';
        $name       = $limit > 1 ? self::IDS_FIELD . '[]' : self::IDS_FIELD;

        $html .= '<form method="post" class="wbdc-direct-picker">';
        $html .= wp_nonce_field( self::CHECK_ACTION, self::CHECK_NONCE, true, false );
        $html .= '<input type="hidden" name="action" value="' . esc_attr( self::CHECK_ACTION ) . '">';
        $html .= '<table class="widefat wbdc-direct-table"><tbody>';

        foreach ( $rows as $row ) {
            $html .= '<tr><td><label><input type="' . esc_attr( $input_type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $row['id'] ) . '"> '
                . '<strong>' . esc_html( $row['title'] ) . '</strong></label>';
            $html .= ' <span class="wbdc-direct-meta">' . esc_html( $row['post_type'] . ' · ' . $row['status'] . ' · ' . $row['modified'] ) . '</span>';

            if ( ! empty( $row['converted'] ) ) {
                $html .= ' <span class="wbdc-badge-converted">' . esc_html__( 'already converted', 'jhmg-converter-for-wpbakery-to-divi' ) . '</span>';
            }
            if ( ! empty( $row['flag_missing'] ) ) {
                $html .= ' <span class="wbdc-badge-flag" title="'
                    . esc_attr__( 'This page holds WPBakery shortcodes but WPBakery is not the active editor on it. It converts just the same.', 'jhmg-converter-for-wpbakery-to-divi' )
                    . '">' . esc_html__( 'WPBakery flag missing', 'jhmg-converter-for-wpbakery-to-divi' ) . '</span>';
            }

            $html .= '</td></tr>';
        }

        $html .= '</tbody></table>';
        $html .= '<p><button type="submit" class="button button-primary">' . esc_html__( 'Check this page', 'jhmg-converter-for-wpbakery-to-divi' ) . '</button></p>';

        if ( $limit === 1 ) {
            $html .= '<p class="description">'
                . esc_html__( 'Free converts one page at a time, as many times as you like. Pro converts your whole site in one run.', 'jhmg-converter-for-wpbakery-to-divi' )
                . '</p>';
        }

        $html .= '</form>';

        return $html . $this->render_pager( $search, $paged, $has_next );
    }

    private function render_search_box( string $search ): string {
        return '<form method="get" class="wbdc-direct-search">'
            . '<input type="hidden" name="page" value="' . esc_attr( AdminPage::MENU_SLUG ) . '">'
            . '<label class="screen-reader-text" for="wbdc-direct-search-input">' . esc_html__( 'Search WPBakery pages', 'jhmg-converter-for-wpbakery-to-divi' ) . '</label>'
            . '<input type="search" id="wbdc-direct-search-input" name="wbdc_s" value="' . esc_attr( $search ) . '" placeholder="'
            . esc_attr__( 'Search by title…', 'jhmg-converter-for-wpbakery-to-divi' ) . '">'
            . '<button type="submit" class="button">' . esc_html__( 'Search', 'jhmg-converter-for-wpbakery-to-divi' ) . '</button></form>';
    }

    private function render_pager( string $search, int $paged, bool $has_next ): string {
        if ( $paged <= 1 && ! $has_next ) {
            return '';
        }

        $base = [ 'page' => AdminPage::MENU_SLUG ];
        if ( $search !== '' ) {
            $base['wbdc_s'] = $search;
        }

        $html = '<p class="wbdc-direct-pager">';
        if ( $paged > 1 ) {
            $html .= '<a class="button" href="' . esc_url( add_query_arg( $base + [ 'paged' => $paged - 1 ], admin_url( 'tools.php' ) ) ) . '">&laquo; '
                . esc_html__( 'Previous', 'jhmg-converter-for-wpbakery-to-divi' ) . '</a> ';
        }
        if ( $has_next ) {
            $html .= '<a class="button" href="' . esc_url( add_query_arg( $base + [ 'paged' => $paged + 1 ], admin_url( 'tools.php' ) ) ) . '">'
                . esc_html__( 'Next', 'jhmg-converter-for-wpbakery-to-divi' ) . ' &raquo;</a>';
        }

        return $html . '</p>';
    }

    /** The conversion report: structure, losses and a Convert button. Never renders pixels. */
    public function render_report( ConversionPlan $plan ): string {
        $html  = '<div class="wbdc-direct-report"><h2>' . esc_html__( 'Conversion report', 'jhmg-converter-for-wpbakery-to-divi' ) . '</h2>';
        $html .= '<p class="description">' . esc_html__( 'Nothing has been written yet. This is what the conversion will produce.', 'jhmg-converter-for-wpbakery-to-divi' ) . '</p>';

        if ( $plan->truncated() ) {
            $html .= '<div class="notice notice-info inline"><p>'
                . esc_html__( 'Only the first page was checked. Converting several pages in one run is a Pro feature.', 'jhmg-converter-for-wpbakery-to-divi' )
                . '</p></div>';
        }

        $ids = [];
        foreach ( $plan->items() as $item ) {
            $html .= '<h3>' . esc_html( (string) $item['title'] ) . '</h3>';

            if ( $item['error'] !== '' ) {
                $html .= '<div class="notice notice-error inline"><p>' . esc_html( (string) $item['error'] ) . '</p></div>';
                continue;
            }

            if ( ! empty( $item['source_ref']['post_id'] ) ) {
                $ids[] = (int) $item['source_ref']['post_id'];
            }

            $html .= ReportRenderer::summary( (array) ( $item['report'] ?? [] ), (array) ( $item['unsupported'] ?? [] ) );
            $html .= OutlineRenderer::render( (array) ( $item['outline'] ?? [] ) );
            $html .= ReportRenderer::details(
                (array) ( $item['report'] ?? [] ),
                (array) ( $item['unsupported'] ?? [] ),
                (string) ( $item['mode'] ?? 'import' )
            );
        }

        if ( ! empty( $ids ) ) {
            $html .= '<form method="post" class="wbdc-direct-convert">' . wp_nonce_field( self::CONVERT_ACTION, self::CONVERT_NONCE, true, false );
            $html .= '<input type="hidden" name="action" value="' . esc_attr( self::CONVERT_ACTION ) . '">';
            foreach ( $ids as $id ) {
                $html .= '<input type="hidden" name="' . esc_attr( self::IDS_FIELD ) . '[]" value="' . esc_attr( (string) $id ) . '">';
            }
            $html .= '<p class="wbdc-convert-choice"><label><input type="checkbox" name="' . esc_attr( self::CREATE_NEW_FIELD ) . '" value="1"> '
                . esc_html__( 'Convert into a new draft instead, and leave this page as it is', 'jhmg-converter-for-wpbakery-to-divi' )
                . '</label></p>';
            $html .= '<p><button type="submit" class="button button-primary">' . esc_html__( 'Convert to Divi 5', 'jhmg-converter-for-wpbakery-to-divi' ) . '</button></p>';
            $html .= '<p class="description">' . esc_html__( 'The page keeps its address, its date and everything linking to it, because it is the same page. Undo puts the WPBakery version back. Tick the box above and you get a separate draft instead, at a new address.', 'jhmg-converter-for-wpbakery-to-divi' ) . '</p></form>';
        }

        return $html . '</div>';
    }

    public function maybe_handle_request(): void {
        if ( ! DiviRequirement::is_satisfied() ) {
            return;
        }

        $action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- each branch verifies its own nonce
        if ( $action !== self::CHECK_ACTION && $action !== self::CONVERT_ACTION ) {
            return;
        }
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'You do not have permission to do that.', 'jhmg-converter-for-wpbakery-to-divi' ) );
        }

        if ( $action === self::CHECK_ACTION ) {
            check_admin_referer( self::CHECK_ACTION, self::CHECK_NONCE );
            $this->handle_check( (array) wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every value is sanitised in verified_post_ids()
            return;
        }

        check_admin_referer( self::CONVERT_ACTION, self::CONVERT_NONCE );
        $this->handle_convert( (array) wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every value is sanitised in verified_post_ids()
    }

    /**
     * Verified but uncapped, stashed per user; the report screen re-plans from the stash.
     *
     * @param array<string,mixed> $request The nonce-verified, unslashed POST body.
     */
    protected function handle_check( array $request ): void {
        $ids = $this->verified_post_ids( $request );

        if ( empty( $ids ) ) {
            wp_die( esc_html__( 'Pick a page to check first.', 'jhmg-converter-for-wpbakery-to-divi' ) );
        }

        set_transient( self::PLAN_IDS_TRANSIENT_PREFIX . get_current_user_id(), $ids, HOUR_IN_SECONDS );
        $this->redirect( add_query_arg(
            [ 'page' => AdminPage::MENU_SLUG, 'action' => AdminPage::VIEW_DIRECT_REPORT ],
            admin_url( 'tools.php' )
        ) );
    }

    /**
     * Commits the plan and lands on the batch result screen, recorded in
     * ImportHistory so it can be undone.
     *
     * @param array<string,mixed> $request The nonce-verified, unslashed POST body.
     */
    protected function handle_convert( array $request ): void {
        $ids = $this->selected_post_ids( $request );

        if ( empty( $ids ) ) {
            wp_die( esc_html__( 'No pages were selected to convert.', 'jhmg-converter-for-wpbakery-to-divi' ) );
        }

        $results = $this->convert( $ids, [ 'create_new' => ! empty( $request[ self::CREATE_NEW_FIELD ] ) ] );
        ( new ReviewPrompt() )->record_run( $results );

        $import_id = wp_generate_uuid4();
        ( new ImportHistory() )->record( $import_id, $results );
        set_transient( AdminPage::BATCH_TRANSIENT_PREFIX . $import_id, $results, HOUR_IN_SECONDS );

        $this->redirect( add_query_arg(
            [ 'page' => AdminPage::MENU_SLUG, 'action' => 'batch_result', 'import_id' => $import_id ],
            admin_url( 'tools.php' )
        ) );
    }

    /** Isolated so tests can observe a redirect without exit() ending the process. */
    protected function redirect( string $location ): void {
        wp_safe_redirect( $location );
        exit;
    }
}
