<?php

namespace WPBakeryDivi5Converter\Admin;

use WPBakeryDivi5Converter\Conversion\ConversionCommitter;
use WPBakeryDivi5Converter\Conversion\InstalledPostSource;
use WPBakeryDivi5Converter\Helpers\DiviRequirement;
use WPBakeryDivi5Converter\History\ImportHistory;
use WPBakeryDivi5Converter\Parsers\WPBakeryImportParser;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Tools → WPBakery → Divi 5. Routes between the landing page (picker, upload
 * form, coverage), the "Check this page" report, the upload's own options step
 * and the batch result.
 *
 * The upload is two steps because it has to be: which post types a file holds
 * is only knowable once the file has been read, and the form offers them
 * (task-12-amendments §3). Step one parses and stashes; step two converts what
 * the reader ticked.
 */
class AdminPage {

    const MENU_SLUG           = 'wbdc-converter';
    const IMPORT_NONCE_NAME   = 'wbdc_import_nonce';
    const IMPORT_NONCE_ACTION = 'wbdc_import';

    /** Step two of the upload: convert what the options form selected. */
    const IMPORT_CONVERT_ACTION = 'wbdc_import_convert';
    const IMPORT_CONVERT_NONCE  = 'wbdc_import_convert_nonce';

    const VIEW_DIRECT_REPORT = 'direct_report';
    const VIEW_IMPORT_OPTIONS = 'import_options';

    /** The one product page URL; the committer owns it. */
    const PRO_URL   = ConversionCommitter::PRO_URL;
    const PRO_PRICE = '$25/yr';

    /** A finished run's results, for the result screen. */
    const BATCH_TRANSIENT_PREFIX = 'wbdc_batch_';

    /** A parsed upload, between the two steps (per user). */
    const IMPORT_ITEMS_TRANSIENT_PREFIX = 'wbdc_import_items_';

    /** Page slugs the shared stylesheet covers (Pro renders with the same class names). */
    private const STYLED_PAGE_SLUGS = [ self::MENU_SLUG, 'wbdcp-pro' ];

    public function init(): void {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_init', [ $this, 'handle_post' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_styles' ] );
    }

    public function enqueue_admin_styles( string $hook ): void {
        if ( ! $this->hook_is_styled( $hook ) ) {
            return;
        }

        wp_enqueue_style( 'wbdc-admin', WBDC_PLUGIN_URL . 'assets/css/admin.css', [], WBDC_PLUGIN_VERSION );
    }

    protected function hook_is_styled( string $hook ): bool {
        foreach ( self::STYLED_PAGE_SLUGS as $slug ) {
            if ( strpos( $hook, $slug ) !== false ) {
                return true;
            }
        }

        return false;
    }

    public function register_menu(): void {
        add_management_page(
            __( 'WPBakery to Divi 5 Converter', 'jhmg-converter-for-wpbakery-to-divi' ),
            __( 'WPBakery → Divi 5', 'jhmg-converter-for-wpbakery-to-divi' ),
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render_page' ]
        );
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'jhmg-converter-for-wpbakery-to-divi' ) );
        }
        if ( ! DiviRequirement::is_satisfied() ) {
            $this->render_requirement_failure();
            return;
        }

        $action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only view switch

        if ( $action === 'batch_result' ) {
            $this->render_batch_result();
        } elseif ( $action === self::VIEW_DIRECT_REPORT ) {
            $this->render_direct_report();
        } elseif ( $action === self::VIEW_IMPORT_OPTIONS ) {
            $this->render_import_options();
        } else {
            $this->render_landing();
        }
    }

    private function render_requirement_failure(): void {
        echo '<div class="wrap wbdc-wrap"><h1>' . esc_html__( 'WPBakery to Divi 5 Converter', 'jhmg-converter-for-wpbakery-to-divi' ) . '</h1>';
        echo '<div class="notice notice-error inline"><p>' . esc_html( DiviRequirement::message() ) . '</p></div>';
        echo '<p class="description">' . esc_html__( 'Install and activate Divi 5, then return to this screen. Nothing has been changed on your site.', 'jhmg-converter-for-wpbakery-to-divi' ) . '</p></div>';
    }

    // ------------------------------------------------------------------
    // POST dispatch
    // ------------------------------------------------------------------

    public function handle_post(): void {
        if ( ! DiviRequirement::is_satisfied() ) {
            return;
        }

        $action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- each handler verifies its own nonce

        if ( $action === self::IMPORT_NONCE_ACTION ) {
            $this->handle_import();
        }
        if ( $action === self::IMPORT_CONVERT_ACTION ) {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'Insufficient permissions.', 'jhmg-converter-for-wpbakery-to-divi' ) );
            }
            check_admin_referer( self::IMPORT_CONVERT_ACTION, self::IMPORT_CONVERT_NONCE );
            $this->handle_import_convert( (array) wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every value is sanitised in handle_import_convert()
        }

        $wbdc_action = isset( $_GET['wbdc_action'] ) ? sanitize_key( wp_unslash( $_GET['wbdc_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the publish nonce is checked in handle_publish()
        if ( $wbdc_action === 'publish' ) {
            $this->handle_publish();
        }
    }

    /**
     * Step one: read the file, stash what it holds, and ask what to convert.
     *
     * The capability and the nonce are checked here rather than in a shared
     * helper so that both a reader and the static analysers can see, on the
     * lines above the superglobal, that they were checked before it was read.
     */
    protected function handle_import(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'jhmg-converter-for-wpbakery-to-divi' ) );
        }
        check_admin_referer( self::IMPORT_NONCE_ACTION, self::IMPORT_NONCE_NAME );

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- an upload array, whose two used members are cast below
        $upload = isset( $_FILES['wbdc_import_file'] ) && is_array( $_FILES['wbdc_import_file'] ) ? $_FILES['wbdc_import_file'] : null;

        if ( ! $upload ) {
            wp_die( esc_html__( 'No file was uploaded.', 'jhmg-converter-for-wpbakery-to-divi' ) );
        }
        if ( (int) $upload['error'] !== UPLOAD_ERR_OK ) {
            wp_die( esc_html( $this->upload_error_message( (int) $upload['error'] ) ) );
        }
        // The path has to be one PHP itself received, never one the request named.
        if ( ! is_uploaded_file( (string) $upload['tmp_name'] ) ) {
            wp_die( esc_html__( 'That file did not arrive as an upload.', 'jhmg-converter-for-wpbakery-to-divi' ) );
        }

        $parser = new WPBakeryImportParser();

        try {
            $items = $parser->parse( (string) $upload['tmp_name'], sanitize_file_name( (string) $upload['name'] ) );
        } catch ( \RuntimeException $e ) {
            wp_die(
                esc_html__( 'Could not read the file: ', 'jhmg-converter-for-wpbakery-to-divi' ) . esc_html( $e->getMessage() ),
                '',
                [ 'back_link' => true ]
            );
        }

        set_transient(
            self::IMPORT_ITEMS_TRANSIENT_PREFIX . get_current_user_id(),
            self::stash_for( $items, $parser->warnings() ),
            HOUR_IN_SECONDS
        );

        $this->redirect( add_query_arg(
            [ 'page' => self::MENU_SLUG, 'action' => self::VIEW_IMPORT_OPTIONS ],
            admin_url( 'tools.php' )
        ) );
    }

    /**
     * Step two: convert what the options form selected.
     *
     * @param array<string,mixed> $request The nonce-verified, unslashed POST body.
     */
    protected function handle_import_convert( array $request ): void {
        $stash = get_transient( self::IMPORT_ITEMS_TRANSIENT_PREFIX . get_current_user_id() );

        if ( ! is_array( $stash ) || empty( $stash['items'] ) ) {
            wp_die( esc_html__( 'That upload has expired. Results are kept for one hour; please upload the file again.', 'jhmg-converter-for-wpbakery-to-divi' ) );
        }

        $items       = self::items_from( $stash );
        $post_types  = self::sanitised_post_types( $request['wbdc_post_types'] ?? [] );
        $raw_status  = $request['wbdc_post_status'] ?? 'draft';
        $post_status = sanitize_key( is_scalar( $raw_status ) ? (string) $raw_status : 'draft' );
        if ( ! in_array( $post_status, [ 'draft', 'publish' ], true ) ) {
            $post_status = 'draft';
        }

        $selection = self::select_items( $items, $post_types );

        $results = ( new BatchImporter() )->import( $selection['items'], [
            'post_status' => $post_status,
            // The unticked templates are already out of the plan; this keeps
            // the committer's own guard in step for anything reaching it by
            // another route (the Pro add-on's whole-site action, say).
            'convert_templates' => (bool) array_intersect( InstalledPostSource::LIBRARY_POST_TYPES, $post_types ),
        ] );

        foreach ( array_merge( self::not_selected_results( $selection['templates'] ), self::dropped_results( $selection['dropped'] ) ) as $row ) {
            $results[] = $row;
        }

        delete_transient( self::IMPORT_ITEMS_TRANSIENT_PREFIX . get_current_user_id() );

        ( new ReviewPrompt() )->record_run( $results );

        $import_id = wp_generate_uuid4();
        ( new ImportHistory() )->record( $import_id, $results );
        set_transient( self::BATCH_TRANSIENT_PREFIX . $import_id, $results, HOUR_IN_SECONDS );

        $this->redirect( add_query_arg(
            [ 'page' => self::MENU_SLUG, 'action' => 'batch_result', 'import_id' => $import_id ],
            admin_url( 'tools.php' )
        ) );
    }

    /**
     * What goes into the between-steps stash.
     *
     * The parser hands every item the same attachment map — one entry per
     * media item in the export — so storing the items as they stand would put
     * that map in `wp_options` once per page. A 500-page export would be tens
     * of megabytes of the same thing repeated. It is lifted out and stored
     * once instead, and put back in `items_from()`.
     *
     * @param array[]  $items
     * @param string[] $warnings
     * @return array{items: array[], attachments: array, warnings: string[]}
     */
    public static function stash_for( array $items, array $warnings = [] ): array {
        $attachments = [];

        foreach ( $items as $index => $item ) {
            if ( empty( $attachments ) && ! empty( $item['attachments'] ) && is_array( $item['attachments'] ) ) {
                $attachments = $item['attachments'];
            }

            $items[ $index ]['attachments'] = [];
        }

        return [ 'items' => array_values( $items ), 'attachments' => $attachments, 'warnings' => array_values( $warnings ) ];
    }

    /**
     * The items a stash holds, with the shared attachment map put back.
     *
     * @param array<string,mixed> $stash
     * @return array[]
     */
    public static function items_from( array $stash ): array {
        $items       = is_array( $stash['items'] ?? null ) ? $stash['items'] : [];
        $attachments = is_array( $stash['attachments'] ?? null ) ? $stash['attachments'] : [];

        if ( empty( $attachments ) ) {
            return array_values( $items );
        }

        foreach ( $items as $index => $item ) {
            if ( empty( $item['attachments'] ) ) {
                $items[ $index ]['attachments'] = $attachments;
            }
        }

        return array_values( $items );
    }

    /**
     * The post type an item is offered and selected under.
     *
     * A `.txt` upload holds shortcodes and no post type at all; it is offered
     * as a page, so it has to be selected as one too — otherwise unticking
     * `page` would leave it converting anyway, which is not what was asked.
     *
     * @param array<string,mixed> $item
     */
    private static function source_type_of( array $item ): string {
        $type = (string) ( $item['source_post_type'] ?? '' );

        return $type !== '' ? $type : 'page';
    }

    /**
     * @param mixed $raw
     * @return string[]
     */
    private static function sanitised_post_types( $raw ): array {
        $types = [];

        foreach ( (array) $raw as $value ) {
            if ( ! is_scalar( $value ) ) {
                continue;
            }
            $type = sanitize_key( (string) $value );
            if ( $type !== '' && ! in_array( $type, $types, true ) ) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * Which of a file's items the reader asked for.
     *
     * A WPBakery template the reader did not tick comes out of the plan
     * altogether rather than being deferred to the end of it. Deferring meant
     * the free limit — one item, taken from the front — reached the template
     * only on a file that held nothing else, so "WPBakery template not selected
     * for conversion" was almost never the wording anybody saw; the template
     * fell into the generic "N more pages in this file were not converted"
     * instead, which does not say what it was or why it was left. Out of the
     * plan, it is reported by name every time, and the limit counts only items
     * that were actually asked for.
     *
     * Items of a type nobody ticked are dropped and counted the same way, so
     * nothing in the file disappears without a word.
     *
     * @param array[]  $items
     * @param string[] $post_types
     * @return array{items: array[], templates: array[], dropped: array<string,int>}
     */
    public static function select_items( array $items, array $post_types ): array {
        $selected  = [];
        $templates = [];
        $dropped   = [];

        foreach ( $items as $item ) {
            $type = self::source_type_of( $item );

            if ( in_array( $type, $post_types, true ) ) {
                $selected[] = $item;
                continue;
            }

            if ( ( $item['template_type'] ?? '' ) === 'library' ) {
                $templates[] = $item;
                continue;
            }

            $dropped[ $type ] = ( $dropped[ $type ] ?? 0 ) + 1;
        }

        return [ 'items' => $selected, 'templates' => $templates, 'dropped' => $dropped ];
    }

    /**
     * One result row per WPBakery template the reader left unticked, in the
     * committer's own words so both paths say the same thing.
     *
     * @param array[] $templates
     * @return array[]
     */
    public static function not_selected_results( array $templates ): array {
        $rows = [];

        foreach ( $templates as $item ) {
            $rows[] = [
                'title'         => (string) ( $item['title'] ?? 'Imported Template' ),
                'post_id'       => 0,
                'template_type' => 'library',
                'success'       => false,
                'skipped'       => true,
                'error'         => ConversionCommitter::LIBRARY_NOT_SELECTED,
                'report'        => [],
                'unsupported'   => [],
            ];
        }

        return $rows;
    }

    /**
     * One result row per post type the reader left unticked, so nothing in the
     * file disappears without a word.
     *
     * @param array<string,int> $dropped
     * @return array[]
     */
    public static function dropped_results( array $dropped ): array {
        $rows = [];

        foreach ( $dropped as $type => $count ) {
            $rows[] = [
                'title'       => sprintf(
                    /* translators: 1: number of items, 2: post type slug */
                    _n( '%1$d item of type %2$s was not converted', '%1$d items of type %2$s were not converted', (int) $count, 'jhmg-converter-for-wpbakery-to-divi' ),
                    (int) $count,
                    (string) $type
                ),
                'post_id'     => 0,
                'success'     => false,
                'skipped'     => true,
                'error'       => __( 'Its post type was not selected for conversion.', 'jhmg-converter-for-wpbakery-to-divi' ),
                'report'      => [],
                'unsupported' => [],
            ];
        }

        return $rows;
    }

    protected function handle_publish(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'jhmg-converter-for-wpbakery-to-divi' ) );
        }

        $post_id   = isset( $_GET['post_id'] ) ? absint( wp_unslash( $_GET['post_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked below
        $import_id = isset( $_GET['import_id'] ) ? sanitize_key( wp_unslash( $_GET['import_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked below

        check_admin_referer( 'wbdc_publish_' . $post_id );

        if ( $post_id <= 0 || (string) get_post_meta( $post_id, '_wbdc_import_source', true ) === '' ) {
            wp_die( esc_html__( 'That page was not created by this converter.', 'jhmg-converter-for-wpbakery-to-divi' ) );
        }

        wp_update_post( [ 'ID' => $post_id, 'post_status' => 'publish' ] );

        $this->redirect( add_query_arg(
            [ 'page' => self::MENU_SLUG, 'action' => 'batch_result', 'import_id' => $import_id ],
            admin_url( 'tools.php' )
        ) );
    }

    private function upload_error_message( int $code ): string {
        $messages = [
            UPLOAD_ERR_INI_SIZE   => __( 'File exceeds the server upload limit.', 'jhmg-converter-for-wpbakery-to-divi' ),
            UPLOAD_ERR_FORM_SIZE  => __( 'File exceeds the form upload limit.', 'jhmg-converter-for-wpbakery-to-divi' ),
            UPLOAD_ERR_PARTIAL    => __( 'File was only partially uploaded.', 'jhmg-converter-for-wpbakery-to-divi' ),
            UPLOAD_ERR_NO_FILE    => __( 'No file was selected.', 'jhmg-converter-for-wpbakery-to-divi' ),
            UPLOAD_ERR_NO_TMP_DIR => __( 'Server is missing a temporary folder.', 'jhmg-converter-for-wpbakery-to-divi' ),
            UPLOAD_ERR_CANT_WRITE => __( 'Failed to write the file to the server.', 'jhmg-converter-for-wpbakery-to-divi' ),
            UPLOAD_ERR_EXTENSION  => __( 'Upload stopped by a server extension.', 'jhmg-converter-for-wpbakery-to-divi' ),
        ];

        /* translators: %d: PHP upload error code */
        return $messages[ $code ] ?? sprintf( __( 'Unknown upload error (code %d).', 'jhmg-converter-for-wpbakery-to-divi' ), $code );
    }

    // ------------------------------------------------------------------
    // Landing
    // ------------------------------------------------------------------

    private function render_landing(): void {
        $direct = new DirectConversionPage();
        $search = isset( $_GET['wbdc_s'] ) ? sanitize_text_field( wp_unslash( $_GET['wbdc_s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only search box
        $paged  = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only pager
        $pro    = (bool) apply_filters( 'wbdc_pro_active', false );
        ?>
        <div class="wrap wbdc-wrap">
            <h1><?php esc_html_e( 'WPBakery to Divi 5 Converter', 'jhmg-converter-for-wpbakery-to-divi' ); ?></h1>
            <p class="wbdc-subtitle"><?php esc_html_e( 'Convert WPBakery pages into native Divi 5 layouts. Check any page first, convert it with one click, undo any run.', 'jhmg-converter-for-wpbakery-to-divi' ); ?></p>

            <?php if ( $pro ) : ?>
                <div class="notice notice-success inline"><p><?php echo wp_kses_post( sprintf(
                    /* translators: %s: Pro tools URL */
                    __( '<strong>Pro is active.</strong> Convert many pages per run, and turn WPBakery templates into Divi Library layouts from <a href="%s">Tools → WPBakery → Divi 5 Pro</a>.', 'jhmg-converter-for-wpbakery-to-divi' ),
                    esc_url( admin_url( 'tools.php?page=wbdcp-pro' ) )
                ) ); ?></p></div>
            <?php endif; ?>

            <div class="wbdc-card wbdc-card--direct">
                <h2><?php esc_html_e( 'Convert a page already on this site', 'jhmg-converter-for-wpbakery-to-divi' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Pick a WPBakery page, check what the conversion will produce, then convert it. Your original page is never modified.', 'jhmg-converter-for-wpbakery-to-divi' ); ?></p>
                <?php
                // One query, not two: the picker renders its own empty state,
                // and the content LIKE behind it is not cheap enough to run
                // twice just to decide whether to call it.
                echo $direct->render_picker( [ 'search' => $search, 'paged' => $paged ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_picker()
                ?>
            </div>

            <div class="wbdc-card">
                <h2><?php esc_html_e( 'Convert from an exported file', 'jhmg-converter-for-wpbakery-to-divi' ); ?></h2>
                <p class="description"><?php esc_html_e( 'On the other site, go to Tools → Export and export your pages — WPBakery keeps its layout in the page content, so the export holds it. You can also paste a page\'s shortcodes into a .txt file and upload that.', 'jhmg-converter-for-wpbakery-to-divi' ); ?></p>
                <form method="post" enctype="multipart/form-data" action="" class="wbdc-import-form">
                    <?php wp_nonce_field( self::IMPORT_NONCE_ACTION, self::IMPORT_NONCE_NAME ); ?>
                    <input type="hidden" name="action" value="<?php echo esc_attr( self::IMPORT_NONCE_ACTION ); ?>">
                    <div class="wbdc-import-fields">
                        <div class="wbdc-import-field">
                            <label for="wbdc_import_file"><strong><?php esc_html_e( 'Export file', 'jhmg-converter-for-wpbakery-to-divi' ); ?></strong></label>
                            <input type="file" id="wbdc_import_file" name="wbdc_import_file" accept=".xml,.txt,.html" required>
                            <p class="description"><?php esc_html_e( 'WordPress export (.xml), or a .txt / .html file holding the page\'s WPBakery shortcodes.', 'jhmg-converter-for-wpbakery-to-divi' ); ?></p>
                        </div>
                        <div class="wbdc-import-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Read this file', 'jhmg-converter-for-wpbakery-to-divi' ); ?></button></div>
                    </div>
                    <p class="description"><?php esc_html_e( 'Nothing is written yet: the next screen shows what the file holds and asks what to convert.', 'jhmg-converter-for-wpbakery-to-divi' ); ?></p>
                </form>
            </div>

            <?php if ( ! $pro ) : ?>
            <div class="wbdc-card wbdc-card--pro">
                <span class="wbdc-badge-pro"><?php esc_html_e( 'PRO', 'jhmg-converter-for-wpbakery-to-divi' ); ?></span>
                <h2><?php esc_html_e( 'Migrate the whole site', 'jhmg-converter-for-wpbakery-to-divi' ); ?></h2>
                <ul class="wbdc-features">
                    <li><?php esc_html_e( 'Convert as many pages as you like in one run — from this site or from one export file', 'jhmg-converter-for-wpbakery-to-divi' ); ?></li>
                    <li><?php esc_html_e( 'Turn WPBakery templates into Divi Library layouts instead of pages', 'jhmg-converter-for-wpbakery-to-divi' ); ?></li>
                    <li><?php esc_html_e( 'Priority support and regular updates', 'jhmg-converter-for-wpbakery-to-divi' ); ?></li>
                </ul>
                <p><a class="button button-primary" href="<?php echo esc_url( self::PRO_URL ); ?>" target="_blank" rel="noopener"><?php
                    /* translators: %s: Pro price, e.g. $25/yr */
                    echo esc_html( sprintf( __( 'Get Pro — %s, unlimited sites', 'jhmg-converter-for-wpbakery-to-divi' ), self::PRO_PRICE ) );
                ?></a></p>
            </div>
            <?php endif; ?>

            <?php ( new CoveragePanel() )->render(); ?>
        </div>
        <?php
    }

    // ------------------------------------------------------------------
    // Upload options (step two of the upload)
    // ------------------------------------------------------------------

    private function render_import_options(): void {
        $stash = get_transient( self::IMPORT_ITEMS_TRANSIENT_PREFIX . get_current_user_id() );

        if ( ! is_array( $stash ) || empty( $stash['items'] ) ) {
            wp_die( esc_html__( 'That upload has expired or held nothing to convert. Please upload the file again.', 'jhmg-converter-for-wpbakery-to-divi' ) );
        }

        echo '<div class="wrap wbdc-wrap"><h1>' . esc_html__( 'What would you like to convert?', 'jhmg-converter-for-wpbakery-to-divi' ) . '</h1>';
        echo '<div class="wbdc-result-actions"><a href="' . esc_url( admin_url( 'tools.php?page=' . self::MENU_SLUG ) ) . '" class="button">&larr; '
            . esc_html__( 'Back to converter', 'jhmg-converter-for-wpbakery-to-divi' ) . '</a></div>';
        echo self::upload_options_form( self::items_from( (array) $stash ), (array) ( $stash['warnings'] ?? [] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in upload_options_form()
        echo '</div>';
    }

    /**
     * The post types a file holds, offered with `page` and `post` ticked
     * (task-12-amendments §3). Renders, never echoes.
     *
     * @param array[]  $items    Items from `WPBakeryImportParser::parse()`.
     * @param string[] $warnings The parser's own warnings, the truncation one included.
     */
    public static function upload_options_form( array $items, array $warnings = [] ): string {
        $counts = [];
        foreach ( $items as $item ) {
            $type            = self::source_type_of( $item );
            $counts[ $type ] = ( $counts[ $type ] ?? 0 ) + 1;
        }
        ksort( $counts );

        $html = '<div class="wbdc-card"><h2>' . esc_html( sprintf(
            /* translators: %d: number of WPBakery pages found in the uploaded file */
            _n( 'This file holds %d WPBakery page.', 'This file holds %d WPBakery pages.', count( $items ), 'jhmg-converter-for-wpbakery-to-divi' ),
            count( $items )
        ) ) . '</h2>';

        foreach ( $warnings as $warning ) {
            $html .= '<div class="notice notice-warning inline"><p>' . esc_html( (string) $warning ) . '</p></div>';
        }

        $html .= '<form method="post" action="" class="wbdc-import-options">';
        $html .= wp_nonce_field( self::IMPORT_CONVERT_ACTION, self::IMPORT_CONVERT_NONCE, true, false );
        $html .= '<input type="hidden" name="action" value="' . esc_attr( self::IMPORT_CONVERT_ACTION ) . '">';
        $html .= '<fieldset class="wbdc-import-types"><legend><strong>' . esc_html__( 'Convert these post types', 'jhmg-converter-for-wpbakery-to-divi' ) . '</strong></legend>';

        foreach ( $counts as $type => $count ) {
            $checked = in_array( $type, WPBakeryImportParser::DEFAULT_SELECTED_POST_TYPES, true );

            $html .= '<p><label><input type="checkbox" name="wbdc_post_types[]" value="' . esc_attr( $type ) . '"' . ( $checked ? ' checked="checked"' : '' ) . '> '
                . '<code>' . esc_html( $type ) . '</code> '
                . esc_html( sprintf(
                    /* translators: %d: number of items of this post type */
                    _n( '(%d page)', '(%d pages)', (int) $count, 'jhmg-converter-for-wpbakery-to-divi' ),
                    (int) $count
                ) );

            if ( in_array( $type, InstalledPostSource::LIBRARY_POST_TYPES, true ) ) {
                $html .= ' <span class="description">'
                    . esc_html__( 'WPBakery template (Pro turns these into Divi Library layouts; the free plugin imports them as pages)', 'jhmg-converter-for-wpbakery-to-divi' )
                    . '</span>';
            }

            $html .= '</label></p>';
        }

        $html .= '</fieldset>';
        $html .= '<p><label for="wbdc_post_status"><strong>' . esc_html__( 'Create them as', 'jhmg-converter-for-wpbakery-to-divi' ) . '</strong></label> '
            . '<select id="wbdc_post_status" name="wbdc_post_status">'
            . '<option value="draft">' . esc_html__( 'Draft (recommended)', 'jhmg-converter-for-wpbakery-to-divi' ) . '</option>'
            . '<option value="publish">' . esc_html__( 'Published', 'jhmg-converter-for-wpbakery-to-divi' ) . '</option>'
            . '</select></p>';
        $html .= '<p><button type="submit" class="button button-primary">' . esc_html__( 'Convert Now', 'jhmg-converter-for-wpbakery-to-divi' ) . '</button></p>';

        if ( ! apply_filters( 'wbdc_pro_active', false ) ) {
            $html .= '<p class="description wbdc-free-notice">'
                . esc_html__( 'Free converts the first page in the file. Pro converts every page in it.', 'jhmg-converter-for-wpbakery-to-divi' )
                . '</p>';
        }

        return $html . '</form></div>';
    }

    // ------------------------------------------------------------------
    // Batch result
    // ------------------------------------------------------------------

    private function render_batch_result(): void {
        $import_id = isset( $_GET['import_id'] ) ? sanitize_key( wp_unslash( $_GET['import_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only view of a run this user just made

        if ( $import_id === '' ) {
            wp_die( esc_html__( 'No run ID provided.', 'jhmg-converter-for-wpbakery-to-divi' ) );
        }

        $results = get_transient( self::BATCH_TRANSIENT_PREFIX . $import_id );
        if ( ! is_array( $results ) ) {
            wp_die( esc_html__( 'Results not found or expired. Results are kept for one hour; the run itself is still listed under Recent conversions.', 'jhmg-converter-for-wpbakery-to-divi' ) );
        }

        $review = new ReviewPrompt();

        echo '<div class="wrap wbdc-wrap"><h1>' . esc_html__( 'Conversion results', 'jhmg-converter-for-wpbakery-to-divi' ) . '</h1>';
        if ( $review->should_ask( $results ) ) {
            echo $review->markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in markup()
        }
        echo '<div class="wbdc-result-actions"><a href="' . esc_url( admin_url( 'tools.php?page=' . self::MENU_SLUG ) ) . '" class="button">&larr; '
            . esc_html__( 'Back to converter', 'jhmg-converter-for-wpbakery-to-divi' ) . '</a></div>';
        echo self::batch_table( $results, $import_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in batch_table()
        echo '</div>';
    }

    /**
     * The results table. Public and returning a string so it can be tested.
     *
     * @param array[] $results
     */
    public static function batch_table( array $results, string $import_id ): string {
        $real      = array_filter( $results, static fn( $r ): bool => empty( $r['skipped'] ) );
        $succeeded = count( array_filter( $real, static fn( $r ): bool => ! empty( $r['success'] ) ) );
        $failed    = count( $real ) - $succeeded;

        $html = '<div class="wbdc-batch-summary">';
        /* translators: %d: number of pages processed */
        $html .= '<span class="wbdc-summary-stat wbdc-summary-stat--total">' . esc_html( sprintf( __( '%d page(s) processed', 'jhmg-converter-for-wpbakery-to-divi' ), count( $real ) ) ) . '</span>';

        if ( $succeeded > 0 ) {
            /* translators: %d: number of successfully converted pages */
            $html .= '<span class="wbdc-summary-stat wbdc-summary-stat--ok">' . esc_html( sprintf( __( '%d converted', 'jhmg-converter-for-wpbakery-to-divi' ), $succeeded ) ) . '</span>';
        }
        if ( $failed > 0 ) {
            /* translators: %d: number of pages that failed */
            $html .= '<span class="wbdc-summary-stat wbdc-summary-stat--fail">' . esc_html( sprintf( __( '%d failed', 'jhmg-converter-for-wpbakery-to-divi' ), $failed ) ) . '</span>';
        }
        $html .= '</div>';

        $html .= '<table class="wp-list-table widefat fixed striped wbdc-batch-table"><thead><tr>'
            . '<th class="column-title column-primary">' . esc_html__( 'Title', 'jhmg-converter-for-wpbakery-to-divi' ) . '</th>'
            . '<th class="column-status">' . esc_html__( 'Status', 'jhmg-converter-for-wpbakery-to-divi' ) . '</th>'
            . '<th class="column-issues">' . esc_html__( 'Issues', 'jhmg-converter-for-wpbakery-to-divi' ) . '</th>'
            . '<th class="column-actions">' . esc_html__( 'Actions', 'jhmg-converter-for-wpbakery-to-divi' ) . '</th></tr></thead><tbody>';

        foreach ( $results as $result ) {
            $report = is_array( $result['report'] ?? null ) ? $result['report'] : [];
            $issues = self::issue_count( $result, $report );

            $html .= '<tr><td class="column-title column-primary"><strong>'
                . esc_html( ( $result['title'] ?? '' ) !== '' ? (string) $result['title'] : __( '(no title)', 'jhmg-converter-for-wpbakery-to-divi' ) )
                . '</strong>';
            if ( ( $result['template_type'] ?? '' ) === 'library' ) {
                $html .= ' <span class="wbdc-badge-template">' . esc_html__( 'WPBakery template', 'jhmg-converter-for-wpbakery-to-divi' ) . '</span>';
            }
            $html .= '</td>';

            $html .= '<td class="column-status">';
            if ( ! empty( $result['skipped'] ) ) {
                $html .= '<span class="wbdc-status wbdc-status--skipped">' . esc_html__( 'Not converted', 'jhmg-converter-for-wpbakery-to-divi' ) . '</span>'
                    . '<br><small class="wbdc-error-msg">' . esc_html( (string) ( $result['error'] ?? '' ) ) . '</small>';
            } elseif ( ! empty( $result['success'] ) ) {
                $html .= '<span class="wbdc-status wbdc-status--converted">&#10003; ' . esc_html__( 'Converted', 'jhmg-converter-for-wpbakery-to-divi' ) . '</span>';
            } else {
                $html .= '<span class="wbdc-status wbdc-status--error">&#10007; ' . esc_html__( 'Failed', 'jhmg-converter-for-wpbakery-to-divi' ) . '</span>';
                if ( ! empty( $result['error'] ) ) {
                    $html .= '<br><small class="wbdc-error-msg">' . esc_html( (string) $result['error'] ) . '</small>';
                }
            }
            $html .= '</td>';

            $html .= '<td class="column-issues">';
            if ( ! empty( $result['success'] ) ) {
                // The coverage fact is not a finding, so it is stated rather
                // than hidden behind the disclosure.
                $html .= ReportRenderer::summary( $report, (array) ( $result['unsupported'] ?? [] ) );

                if ( $issues > 0 ) {
                    $html .= '<details class="wbdc-issues-details"><summary class="wbdc-issues-summary"><span class="wbdc-badge wbdc-badge--warn">' . (int) $issues . '</span> '
                        . esc_html__( 'notes', 'jhmg-converter-for-wpbakery-to-divi' ) . '</summary>'
                        . ReportRenderer::details( $report, (array) ( $result['unsupported'] ?? [] ), (string) ( $result['mode'] ?? 'import' ) )
                        . '</details>';
                } else {
                    $html .= '<span class="wbdc-status--clean">&#10003; ' . esc_html__( 'Clean', 'jhmg-converter-for-wpbakery-to-divi' ) . '</span>';
                }
            } else {
                $html .= '&mdash;';
            }
            $html .= '</td>';

            $html .= '<td class="column-actions">';
            if ( ! empty( $result['success'] ) && (int) ( $result['post_id'] ?? 0 ) > 0 ) {
                $post_id = (int) $result['post_id'];
                $html   .= '<a href="' . esc_url( self::edit_link( $post_id ) ) . '" class="button button-small">' . esc_html__( 'Edit', 'jhmg-converter-for-wpbakery-to-divi' ) . '</a> ';
                $html   .= '<a href="' . esc_url( self::view_link( $post_id ) ) . '" class="button button-small" target="_blank" rel="noopener">' . esc_html__( 'View', 'jhmg-converter-for-wpbakery-to-divi' ) . '</a> ';

                if ( self::post_status( $post_id ) !== 'publish' ) {
                    $publish_url = add_query_arg(
                        [
                            'page'        => self::MENU_SLUG,
                            'action'      => 'batch_result',
                            'import_id'   => $import_id,
                            'wbdc_action' => 'publish',
                            'post_id'     => $post_id,
                        ],
                        admin_url( 'tools.php' )
                    ) . '&_wpnonce=' . wp_create_nonce( 'wbdc_publish_' . $post_id );

                    $html .= '<a href="' . esc_url( $publish_url ) . '" class="button button-small button-primary">' . esc_html__( 'Publish', 'jhmg-converter-for-wpbakery-to-divi' ) . '</a>';
                } else {
                    $html .= '<span class="wbdc-published-label">&#10003; ' . esc_html__( 'Published', 'jhmg-converter-for-wpbakery-to-divi' ) . '</span>';
                }
            } else {
                $html .= '&mdash;';
            }
            $html .= '</td></tr>';
        }

        return $html . '</tbody></table>';
    }

    /**
     * How much this conversion has to say. Counts every reportable thing, so a
     * page whose only finding is a theme element still opens its details.
     *
     * @param array<string,mixed> $result
     * @param array<string,mixed> $report
     */
    private static function issue_count( array $result, array $report ): int {
        return count( (array) ( $report['warnings'] ?? [] ) )
            + count( (array) ( $result['unsupported'] ?? [] ) )
            + count( (array) ( $report['skipped_settings'] ?? [] ) )
            + count( (array) ( $report['unresolved_globals'] ?? [] ) )
            + count( (array) ( $report['not_carried_over'] ?? [] ) )
            + count( (array) ( $report['approximate_matches'] ?? [] ) )
            + count( (array) ( $report['theme_elements'] ?? [] ) )
            + count( (array) ( $report['unresolved_media'] ?? [] ) )
            + count( (array) ( $report['static_copies'] ?? [] ) )
            + count( (array) ( $report['custom_css_carried'] ?? [] ) )
            + (int) ( $report['text_nodes'] ?? 0 )
            + (int) ( $report['bracketed_text'] ?? 0 );
    }

    private static function edit_link( int $post_id ): string {
        return function_exists( 'get_edit_post_link' )
            ? (string) get_edit_post_link( $post_id, 'raw' )
            : admin_url( 'post.php?post=' . $post_id . '&action=edit' );
    }

    private static function view_link( int $post_id ): string {
        return function_exists( 'get_permalink' ) ? (string) get_permalink( $post_id ) : home_url( '/?p=' . $post_id );
    }

    private static function post_status( int $post_id ): string {
        if ( function_exists( 'get_post_status' ) ) {
            return (string) get_post_status( $post_id );
        }

        $post = get_post( $post_id );

        return (string) ( $post->post_status ?? '' );
    }

    // ------------------------------------------------------------------
    // Direct report ("Check this page")
    // ------------------------------------------------------------------

    private function render_direct_report(): void {
        $ids = get_transient( DirectConversionPage::PLAN_IDS_TRANSIENT_PREFIX . get_current_user_id() );

        if ( ! is_array( $ids ) || empty( $ids ) ) {
            wp_die( esc_html__( 'No checked selection found or it has expired. Please pick a page again.', 'jhmg-converter-for-wpbakery-to-divi' ) );
        }

        $direct = new DirectConversionPage();
        $plan   = $direct->plan_for( $ids );

        echo '<div class="wrap wbdc-wrap"><h1>' . esc_html__( 'WPBakery to Divi 5 Converter', 'jhmg-converter-for-wpbakery-to-divi' ) . '</h1>';
        echo '<div class="wbdc-result-actions"><a href="' . esc_url( admin_url( 'tools.php?page=' . self::MENU_SLUG ) ) . '" class="button">&larr; '
            . esc_html__( 'Back to converter', 'jhmg-converter-for-wpbakery-to-divi' ) . '</a></div>';
        echo $direct->render_report( $plan ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_report()
        echo '</div>';
    }

    /** Isolated so tests can observe a redirect without exit() ending the process. */
    protected function redirect( string $location ): void {
        wp_safe_redirect( $location );
        exit;
    }
}
