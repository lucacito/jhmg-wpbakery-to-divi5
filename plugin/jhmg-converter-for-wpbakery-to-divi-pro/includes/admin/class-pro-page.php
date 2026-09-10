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
     * id has to be one this repository is offering right now.
     *
     * @param array<string,mixed> $request
     * @return int[] Deduplicated WPBakery template post ids.
     */
    public function verified_template_ids( array $request ): array {
        $allowed = array_column( $this->templates->find(), 'id' );
        $raw     = $request[ self::IDS_FIELD ] ?? [];
        $ids     = [];

        foreach ( (array) $raw as $value ) {
            if ( ! is_scalar( $value ) || ! ctype_digit( (string) $value ) ) {
                continue;
            }
            $id = (int) $value;
            if ( in_array( $id, $allowed, true ) && ! in_array( $id, $ids, true ) ) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Convert the selected templates through the free plugin's own pipeline.
     *
     * `runUnlimited()` rather than `run()`: the per-run cap is what Pro lifts,
     * and every item here is a WPBakery template, so `convert_templates` is on
     * and the committer routes each one through the `wbdc_library_exporter`
     * filter this add-on answers — into the Divi Library rather than into
     * another page draft. The source posts are never modified.
     *
     * @param int[] $ids
     * @return array[] One result row per template.
     */
    public function convert_templates( array $ids ): array {
        $plan = ( new ConversionPreflight() )->runUnlimited( new InstalledPostSource( $ids ) );

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

        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'templates'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only view switch

        echo $this->markup( $tab ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in markup()
    }

    public function markup( string $tab = 'templates' ): string {
        $base = admin_url( 'tools.php?page=' . self::MENU_SLUG );

        $html  = '<div class="wrap wbdc-wrap"><h1>' . esc_html__( 'WPBakery to Divi 5 Pro', 'jhmg-converter-for-wpbakery-to-divi-pro' ) . '</h1>';
        $html .= $this->notice_markup();
        $html .= '<h2 class="nav-tab-wrapper">';

        foreach ( [ 'templates' => __( 'WPBakery templates', 'jhmg-converter-for-wpbakery-to-divi-pro' ), 'license' => __( 'Licence', 'jhmg-converter-for-wpbakery-to-divi-pro' ) ] as $slug => $label ) {
            $html .= '<a class="nav-tab' . ( $tab === $slug ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $base . '&tab=' . $slug ) . '">' . esc_html( $label ) . '</a>';
        }

        $html .= '</h2>';
        $html .= $tab === 'license' ? $this->licensePage->markup() : $this->templates_markup();

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

    public function templates_markup(): string {
        $html = '<div class="wbdc-card"><h2>' . esc_html__( 'Turn WPBakery templates into Divi Library layouts', 'jhmg-converter-for-wpbakery-to-divi-pro' ) . '</h2>';
        $html .= '<p class="description">' . esc_html__( 'Each template becomes a layout in Divi → Divi Library, ready to load into any page. Converting the same template again updates the layout it made before instead of adding another. Your WPBakery templates are never modified.', 'jhmg-converter-for-wpbakery-to-divi-pro' ) . '</p>';

        if ( ! DiviRequirement::is_satisfied() ) {
            return $html . '<div class="notice notice-error inline"><p>' . esc_html( DiviRequirement::message() ) . '</p></div></div>';
        }

        $rows = $this->templates->find();

        if ( empty( $rows ) ) {
            return $html . '<p class="wbdc-direct-empty">' . esc_html__( 'No WPBakery templates were found on this site.', 'jhmg-converter-for-wpbakery-to-divi-pro' ) . '</p></div>';
        }

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

        return $html . '</div>';
    }
}
