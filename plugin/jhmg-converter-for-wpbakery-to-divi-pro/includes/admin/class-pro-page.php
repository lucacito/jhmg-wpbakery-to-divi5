<?php
/**
 * Tools → WPBakery → Divi 5 Pro: the Templates tab (turn WPBakery templates
 * into Divi Library layouts) and the License tab.
 *
 * Every identifier is wbdcp_-prefixed: free's AdminPage and DirectConversionPage
 * dispatch on the POST action / GET query params unscoped, so Pro's must never
 * collide with theirs.
 */

namespace WPBakeryDivi5Converter\Pro\Admin;

use WPBakeryDivi5Converter\Admin\AdminPage;
use WPBakeryDivi5Converter\Conversion\ConversionCommitter;
use WPBakeryDivi5Converter\Conversion\ConversionPreflight;
use WPBakeryDivi5Converter\Conversion\InstalledPostSource;
use WPBakeryDivi5Converter\Helpers\DiviRequirement;
use WPBakeryDivi5Converter\History\ImportHistory;
use WPBakeryDivi5Converter\Parsers\WPBakeryDocumentParser;
use WPBakeryDivi5Converter\Pro\Licensing\LicenseClient;
use WPBakeryDivi5Converter\Pro\Licensing\LicensePage;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ProPage {

    const MENU_SLUG      = 'wbdcp-pro';
    const CONVERT_ACTION = 'wbdcp_convert_templates';
    const CONVERT_NONCE  = 'wbdcp_convert_templates_nonce';
    const CAPABILITY     = 'manage_options';

    /** The form field the templates table submits. */
    const IDS_FIELD = 'wbdcp_template_ids';

    private LicensePage $licensePage;
    private TemplatesRepository $templates;

    public function __construct( private LicenseClient $license, ?TemplatesRepository $templates = null ) {
        $this->licensePage = new LicensePage( $license );
        $this->templates   = $templates ?? new TemplatesRepository();
    }

    public function init(): void {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_init', [ $this, 'handle_post' ] );
    }

    public function register_menu(): void {
        add_management_page(
            __( 'WPBakery to Divi 5 Pro', 'jhmg-converter-for-wpbakery-to-divi-pro' ),
            __( 'WPBakery → Divi 5 Pro', 'jhmg-converter-for-wpbakery-to-divi-pro' ),
            self::CAPABILITY,
            self::MENU_SLUG,
            [ $this, 'render_page' ]
        );
    }

    public function handle_post(): void {
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a screen check; each branch verifies its own nonce
        if ( $page !== self::MENU_SLUG ) {
            return;
        }

        $this->licensePage->handle_post();

        $action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified below
        if ( $action !== self::CONVERT_ACTION || ! DiviRequirement::is_satisfied() ) {
            return;
        }

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'jhmg-converter-for-wpbakery-to-divi-pro' ) );
        }
        check_admin_referer( self::CONVERT_ACTION, self::CONVERT_NONCE );

        $this->handle_convert( (array) wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every value is sanitised in verified_template_ids()
    }

    /**
     * The rendered table is never trusted on the way back in: every submitted
     * id is re-checked against the posts themselves.
     *
     * Each id is verified on its own rather than against the page of rows that
     * was rendered — the listing is paged, and a selection made on page three
     * has to convert as readily as one made on page one.
     *
     * @param array<string,mixed> $request
     * @return int[] Deduplicated WPBakery template post ids.
     */
    public function verified_template_ids( array $request ): array {
        $raw = $request[ self::IDS_FIELD ] ?? [];
        $ids = [];

        foreach ( (array) $raw as $value ) {
            if ( ! is_scalar( $value ) || ! ctype_digit( (string) $value ) ) {
                continue;
            }
            $id = (int) $value;
            if ( $id > 0 && ! in_array( $id, $ids, true ) && $this->is_wpbakery_template( $id ) ) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * A post of one of WPBakery's own template types whose content really does
     * hold WPBakery shortcodes — the same two tests `InstalledPostSource`
     * applies before it converts anything.
     */
    private function is_wpbakery_template( int $post_id ): bool {
        $post = get_post( $post_id );

        return $post !== null
            && in_array( (string) ( $post->post_type ?? '' ), InstalledPostSource::LIBRARY_POST_TYPES, true )
            && WPBakeryDocumentParser::isWPBakeryContent( (string) ( $post->post_content ?? '' ) );
    }

    /**
     * Convert the selected templates through the free plugin's own pipeline.
     *
     * Every item here is a WPBakery template, so `convert_templates` is on
     * and the committer routes each one through the `wbdc_library_exporter`
     * filter this add-on answers — into the Divi Library rather than into
     * another page draft. The source posts are never modified.
     *
     * @param int[] $ids
     * @return array[] One result row per template.
     */
    public function convert_templates( array $ids ): array {
        $plan = ( new ConversionPreflight() )->run( new InstalledPostSource( $ids ) );

        return ( new ConversionCommitter() )->commit( $plan, [
            'mode'              => 'direct',
            'convert_templates' => true,
        ] );
    }

    /** @param array<string,mixed> $request The nonce-verified, unslashed POST body. */
    protected function handle_convert( array $request ): void {
        $ids = $this->verified_template_ids( $request );

        if ( empty( $ids ) ) {
            wp_die( esc_html__( 'Pick at least one WPBakery template to convert.', 'jhmg-converter-for-wpbakery-to-divi-pro' ) );
        }

        $results   = $this->convert_templates( $ids );
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

    public function render_page(): void {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'jhmg-converter-for-wpbakery-to-divi-pro' ) );
        }

        $tab   = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'templates'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only view switch
        $paged = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only pager

        echo $this->markup( $tab, [ 'paged' => $paged ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in markup()
    }

    /** @param array<string,mixed> $args paged. */
    public function markup( string $tab = 'templates', array $args = [] ): string {
        $base = admin_url( 'tools.php?page=' . self::MENU_SLUG );

        $html  = '<div class="wrap wbdc-wrap"><h1>' . esc_html__( 'WPBakery to Divi 5 Pro', 'jhmg-converter-for-wpbakery-to-divi-pro' ) . '</h1>';
        $html .= $this->notice_markup();
        $html .= '<h2 class="nav-tab-wrapper">';

        foreach ( [ 'templates' => __( 'WPBakery templates', 'jhmg-converter-for-wpbakery-to-divi-pro' ), 'license' => __( 'Licence', 'jhmg-converter-for-wpbakery-to-divi-pro' ) ] as $slug => $label ) {
            $html .= '<a class="nav-tab' . ( $tab === $slug ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $base . '&tab=' . $slug ) . '">' . esc_html( $label ) . '</a>';
        }

        $html .= '</h2>';
        $html .= $tab === 'license' ? $this->licensePage->markup() : $this->templates_markup( $args );

        return $html . '</div>';
    }

    private function notice_markup(): string {
        $notice = isset( $_GET['wbdcp_notice'] ) ? sanitize_key( wp_unslash( $_GET['wbdcp_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only message
        $map    = [
            'license_activated'   => [ 'success', __( 'Licence activated.', 'jhmg-converter-for-wpbakery-to-divi-pro' ) ],
            'license_deactivated' => [ 'info', __( 'Licence deactivated on this site.', 'jhmg-converter-for-wpbakery-to-divi-pro' ) ],
            'license_refreshed'   => [ 'info', __( 'Licence status refreshed.', 'jhmg-converter-for-wpbakery-to-divi-pro' ) ],
            'license_error'       => [ 'error', __( 'The licence could not be activated.', 'jhmg-converter-for-wpbakery-to-divi-pro' ) ],
        ];

        if ( ! isset( $map[ $notice ] ) ) {
            return '';
        }

        [ $class, $text ] = $map[ $notice ];
        $error = isset( $_GET['wbdcp_error'] ) ? sanitize_key( wp_unslash( $_GET['wbdcp_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only message

        return '<div class="notice notice-' . esc_attr( $class ) . ' inline"><p>' . esc_html( $text )
            . ( $error !== '' ? ' <code>' . esc_html( $error ) . '</code>' : '' ) . '</p></div>';
    }

    /** @param array<string,mixed> $args paged. */
    public function templates_markup( array $args = [] ): string {
        $html = '<div class="wbdc-card"><h2>' . esc_html__( 'Turn WPBakery templates into Divi Library layouts', 'jhmg-converter-for-wpbakery-to-divi-pro' ) . '</h2>';
        $html .= '<p class="description">' . esc_html__( 'Each template becomes a layout in Divi → Divi Library, ready to load into any page. Converting the same template again updates the layout it made before instead of adding another. Your WPBakery templates are never modified.', 'jhmg-converter-for-wpbakery-to-divi-pro' ) . '</p>';

        if ( ! DiviRequirement::is_satisfied() ) {
            return $html . '<div class="notice notice-error inline"><p>' . esc_html( DiviRequirement::message() ) . '</p></div></div>';
        }

        $paged    = max( 1, (int) ( $args['paged'] ?? 1 ) );
        $per_page = TemplatesRepository::PER_PAGE;
        $rows     = $this->templates->find( [ 'paged' => $paged, 'per_page' => $per_page ] );

        if ( empty( $rows ) ) {
            return $html . '<p class="wbdc-direct-empty">' . esc_html__( 'No WPBakery templates were found on this site.', 'jhmg-converter-for-wpbakery-to-divi-pro' ) . '</p></div>';
        }

        $html .= $this->count_markup( $paged, $per_page, count( $rows ) );
        $html .= '<form method="post">' . wp_nonce_field( self::CONVERT_ACTION, self::CONVERT_NONCE, true, false );
        $html .= '<input type="hidden" name="action" value="' . esc_attr( self::CONVERT_ACTION ) . '">';
        $html .= '<table class="widefat wbdc-direct-table"><tbody>';

        foreach ( $rows as $row ) {
            $html .= '<tr><td><label><input type="checkbox" name="' . esc_attr( self::IDS_FIELD ) . '[]" value="' . esc_attr( (string) $row['id'] ) . '"> '
                . '<strong>' . esc_html( $row['title'] ) . '</strong></label> '
                . '<span class="wbdc-direct-meta">' . esc_html( $row['post_type'] . ' · ' . $row['status'] ) . '</span></td></tr>';
        }

        $html .= '</tbody></table>';
        $html .= '<p><button type="submit" class="button button-primary">' . esc_html__( 'Convert into the Divi Library', 'jhmg-converter-for-wpbakery-to-divi-pro' ) . '</button></p></form>';
        $html .= $this->pager_markup( $paged, $per_page );

        return $html . '</div>';
    }

    /**
     * "Showing 1–20 of 43." — the whole point of the pager is that the reader
     * can tell there is more than what is on the screen.
     */
    private function count_markup( int $paged, int $per_page, int $shown ): string {
        $total = $this->templates->found();

        if ( $total <= 0 || $shown <= 0 ) {
            return '';
        }

        $first = ( $paged - 1 ) * $per_page + 1;

        return '<p class="wbdc-direct-meta">' . esc_html( sprintf(
            /* translators: 1: first row number, 2: last row number, 3: total number of templates */
            __( 'Showing %1$d–%2$d of %3$d WPBakery templates.', 'jhmg-converter-for-wpbakery-to-divi-pro' ),
            $first,
            $first + $shown - 1,
            $total
        ) ) . '</p>';
    }

    /** Previous / Next, on the templates tab, as the free picker's pager does it. */
    private function pager_markup( int $paged, int $per_page ): string {
        $has_next = $this->templates->has_next_page( $paged, $per_page );

        if ( $paged <= 1 && ! $has_next ) {
            return '';
        }

        $base = [ 'page' => self::MENU_SLUG, 'tab' => 'templates' ];
        $html = '<p class="wbdc-direct-pager">';

        if ( $paged > 1 ) {
            $html .= '<a class="button" href="' . esc_url( add_query_arg( $base + [ 'paged' => $paged - 1 ], admin_url( 'tools.php' ) ) ) . '">&laquo; '
                . esc_html__( 'Previous', 'jhmg-converter-for-wpbakery-to-divi-pro' ) . '</a> ';
        }
        if ( $has_next ) {
            $html .= '<a class="button" href="' . esc_url( add_query_arg( $base + [ 'paged' => $paged + 1 ], admin_url( 'tools.php' ) ) ) . '">'
                . esc_html__( 'Next', 'jhmg-converter-for-wpbakery-to-divi-pro' ) . ' &raquo;</a>';
        }

        return $html . '</p>';
    }
}
