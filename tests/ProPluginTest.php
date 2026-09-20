<?php
/**
 * The Pro add-on's wiring: what it declares to the free plugin, the templates
 * it lists, the ids its page will accept back, and a WPBakery template going
 * through the free pipeline into the Divi Library.
 */

use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Admin\AdminPage;
use WPBakeryDivi5Converter\Conversion\InstalledPostSource;
use WPBakeryDivi5Converter\History\ImportHistory;
use WPBakeryDivi5Converter\Pro\Admin\ProPage;
use WPBakeryDivi5Converter\Pro\Admin\TemplatesRepository;
use WPBakeryDivi5Converter\Pro\Exporters\DiviLibraryExporter;
use WPBakeryDivi5Converter\Pro\Plugin as ProPlugin;

final class ProPluginTest extends TestCase {

    private const SHORTCODES = '[vc_row][vc_column width="1/1"][vc_column_text]Frequently asked.[/vc_column_text][/vc_column][/vc_row]';

    protected function setUp(): void {
        wbdc_test_reset_hooks();
        $GLOBALS['__test_posts']        = [];
        $GLOBALS['__test_postmeta']     = [];
        $GLOBALS['__test_object_terms'] = [];
        $GLOBALS['wbdc_test_http']      = [ 'queue' => [], 'log' => [] ];
    }

    private function seedTemplate( int $id, string $title, string $post_type = 'vc4_templates', string $content = self::SHORTCODES ): void {
        $GLOBALS['__test_posts'][ $id ] = (object) [
            'ID'           => $id,
            'post_title'   => $title,
            'post_name'    => sanitize_title( $title ),
            'post_type'    => $post_type,
            'post_status'  => 'publish',
            'post_content' => $content,
        ];
    }

    /**
     * Stands in for WP_Query: the library post types, ordered as seeded, paged
     * the way `posts_per_page`/`paged` ask, with `found` counting every match
     * rather than the page — which is what the screen's count line reads.
     */
    private function repo(): TemplatesRepository {
        return new TemplatesRepository( static function ( array $args ): array {
            $all = array_values( array_filter(
                $GLOBALS['__test_posts'],
                static fn( $p ): bool => in_array( $p->post_type ?? '', InstalledPostSource::LIBRARY_POST_TYPES, true )
            ) );

            $per_page = (int) ( $args['posts_per_page'] ?? 20 );
            $paged    = max( 1, (int) ( $args['paged'] ?? 1 ) );

            return [
                'posts' => array_slice( $all, ( $paged - 1 ) * $per_page, $per_page ),
                'found' => count( $all ),
            ];
        } );
    }

    private function page(): ProPage {
        return new ProPage( ProPlugin::instance()->license(), $this->repo() );
    }

    // --- wiring -----------------------------------------------------------------

    public function test_pro_declares_itself_and_registers_the_library_exporter(): void {
        ProPlugin::instance()->register_hooks();

        $this->assertTrue( apply_filters( 'wbdc_pro_active', false ) );
        $this->assertInstanceOf( DiviLibraryExporter::class, apply_filters( 'wbdc_library_exporter', null, [], [] ) );
        $this->assertSame( 'wpbakery-to-divi5-pro', WBDCP_PRODUCT_SLUG );
    }

    public function test_an_exporter_another_add_on_already_answered_with_is_left_alone(): void {
        ProPlugin::instance()->register_hooks();
        $other = new stdClass();

        $this->assertSame( $other, apply_filters( 'wbdc_library_exporter', $other, [], [] ) );
    }

    // --- templates repository ---------------------------------------------------

    public function test_templates_repository_lists_both_wpbakery_template_post_types(): void {
        $this->seedTemplate( 10, 'FAQ section' );
        $this->seedTemplate( 11, 'Hero', 'templatera' );
        $this->seedTemplate( 12, 'A page', 'page' );

        $rows = $this->repo()->find();

        $this->assertSame( [ 10, 11 ], array_column( $rows, 'id' ) );
        $this->assertSame( [ 'vc4_templates', 'templatera' ], array_column( $rows, 'post_type' ) );
        $this->assertSame( 'FAQ section', $rows[0]['title'] );
        $this->assertSame( InstalledPostSource::LIBRARY_POST_TYPES, $this->repo()->query_args()['post_type'] );
    }

    public function test_the_repository_pages_and_counts_every_template(): void {
        for ( $i = 1; $i <= 25; $i++ ) {
            $this->seedTemplate( 100 + $i, sprintf( 'Template %02d', $i ) );
        }
        $repo = $this->repo();

        $first = $repo->find( [ 'paged' => 1, 'per_page' => 20 ] );
        $this->assertCount( 20, $first );
        $this->assertSame( 25, $repo->found() );
        $this->assertTrue( $repo->has_next_page( 1, 20 ) );

        $second = $repo->find( [ 'paged' => 2, 'per_page' => 20 ] );
        $this->assertCount( 5, $second );
        $this->assertSame( 25, $repo->found() );
        $this->assertFalse( $repo->has_next_page( 2, 20 ) );

        // No id appears on both pages, and the tail is reachable.
        $this->assertSame( [], array_intersect( array_column( $first, 'id' ), array_column( $second, 'id' ) ) );
        $this->assertSame( 125, $second[4]['id'] );

        $args = $repo->query_args( [ 'paged' => 3, 'per_page' => 20 ] );
        $this->assertSame( 3, $args['paged'] );
        $this->assertSame( 20, $args['posts_per_page'] );
        $this->assertArrayNotHasKey( 'no_found_rows', $args, 'found_posts is what the count line reads' );
    }

    public function test_the_screen_says_what_it_is_showing_and_offers_the_next_page(): void {
        for ( $i = 1; $i <= 25; $i++ ) {
            $this->seedTemplate( 200 + $i, sprintf( 'Template %02d', $i ) );
        }
        $page = $this->page();

        $first = $page->templates_markup( [ 'paged' => 1 ] );
        $this->assertStringContainsString( 'Showing 1–20 of 25 WPBakery templates.', $first );
        $this->assertStringContainsString( 'paged=2', $first );
        $this->assertStringNotContainsString( 'paged=0', $first );

        $second = $page->templates_markup( [ 'paged' => 2 ] );
        $this->assertStringContainsString( 'Showing 21–25 of 25 WPBakery templates.', $second );
        $this->assertStringContainsString( 'paged=1', $second );
        $this->assertStringNotContainsString( 'paged=3', $second );
        // The tail is selectable, which is the whole point of the pager.
        $this->assertStringContainsString( 'value="225"', $second );
    }

    public function test_a_selection_made_on_a_later_page_still_converts(): void {
        for ( $i = 1; $i <= 25; $i++ ) {
            $this->seedTemplate( 300 + $i, sprintf( 'Template %02d', $i ) );
        }

        // Id 325 is on page two; the allow-list must not be the page of rows
        // that happened to be rendered.
        $this->assertSame( [ 325 ], $this->page()->verified_template_ids( [ 'wbdcp_template_ids' => [ '325' ] ] ) );
    }

    public function test_a_template_holding_no_wpbakery_shortcodes_is_not_offered(): void {
        $this->seedTemplate( 13, 'Empty', 'vc4_templates', '<p>plain html</p>' );

        $this->assertSame( [], $this->repo()->find() );
    }

    // --- the page ---------------------------------------------------------------

    public function test_pro_page_verifies_template_ids_against_the_repository(): void {
        $this->seedTemplate( 20, 'FAQ section' );
        $page = $this->page();

        $this->assertSame( [ 20 ], $page->verified_template_ids( [ 'wbdcp_template_ids' => [ '20', '20', '999', 'x' ] ] ) );
        $this->assertStringContainsString( 'name="wbdcp_template_ids[]" value="20"', $page->templates_markup() );
        $this->assertStringContainsString( 'nav-tab-active', $page->markup( 'license' ) );
        $this->assertStringContainsString( 'Licence key', $page->markup( 'license' ) );
        $this->assertSame( 'wbdcp-pro', ProPage::MENU_SLUG );
    }

    // --- conversion -------------------------------------------------------------

    public function test_converting_a_wpbakery_template_creates_a_divi_library_layout(): void {
        ProPlugin::instance()->register_hooks();
        $this->seedTemplate( 30, 'FAQ section' );

        $results = $this->page()->convert_templates( [ 30 ] );

        $this->assertTrue( $results[0]['success'], (string) ( $results[0]['error'] ?? '' ) );
        $this->assertSame( 'library', $results[0]['template_type'] );

        $layout = get_post( $results[0]['post_id'] );
        $this->assertSame( 'et_pb_layout', $layout->post_type );
        $this->assertSame( 'publish', $layout->post_status );
        $this->assertStringContainsString( 'wp:divi/', $layout->post_content );
        $this->assertSame( 'post-30', get_post_meta( $layout->ID, '_wbdcp_library_source', true ) );
        $this->assertSame( 'on', get_post_meta( $layout->ID, '_et_pb_use_divi_5', true ) );
        $this->assertSame( [ 'layout' ], $GLOBALS['__test_object_terms'][ $layout->ID ]['layout_type'] );
    }

    public function test_reconverting_the_same_template_updates_the_same_layout(): void {
        ProPlugin::instance()->register_hooks();
        $this->seedTemplate( 31, 'FAQ section' );
        $page = $this->page();

        $first  = $page->convert_templates( [ 31 ] )[0];
        $second = $page->convert_templates( [ 31 ] )[0];

        $this->assertSame( $first['post_id'], $second['post_id'] );
        $this->assertCount(
            1,
            array_filter( $GLOBALS['__test_posts'], static fn( $p ): bool => ( $p->post_type ?? '' ) === 'et_pb_layout' )
        );
    }

    public function test_many_templates_convert_in_one_run(): void {
        ProPlugin::instance()->register_hooks();
        $this->seedTemplate( 40, 'One' );
        $this->seedTemplate( 41, 'Two', 'templatera' );
        $this->seedTemplate( 42, 'Three' );

        $results = $this->page()->convert_templates( [ 40, 41, 42 ] );

        $this->assertCount( 3, $results );
        $this->assertSame( [ true, true, true ], array_column( $results, 'success' ) );
    }

    // --- the POST handler -------------------------------------------------------

    /** ProPage with the redirect seam opened, so a handler can be run to completion. */
    private function handlerPage(): ProPage {
        return new class( ProPlugin::instance()->license(), $this->repo() ) extends ProPage {
            /** @var string[] */
            public array $redirects = [];

            protected function redirect( string $location ): void {
                $this->redirects[] = $location;
            }
        };
    }

    /** @param callable(): void $run */
    private function assertDies( string $needle, callable $run ): void {
        try {
            $run();
            $this->fail( "expected wp_die containing '{$needle}'" );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( $needle, $e->getMessage() );
        }
    }

    public function test_the_handler_ignores_every_other_screen(): void {
        ProPlugin::instance()->register_hooks();
        $this->seedTemplate( 50, 'FAQ section' );
        $page = $this->handlerPage();

        $_GET  = [ 'page' => 'wbdc-converter' ];
        $_POST = [ 'action' => ProPage::CONVERT_ACTION, ProPage::IDS_FIELD => [ '50' ] ];

        $page->handle_post();

        $this->assertSame( [], $page->redirects );
        $this->assertSame( [], $GLOBALS['__test_referer_checked'], 'no nonce was even looked at' );
        $this->assertSame( [], array_filter( $GLOBALS['__test_posts'], static fn( $p ): bool => ( $p->post_type ?? '' ) === 'et_pb_layout' ) );

        $_GET = $_POST = [];
    }

    public function test_the_handler_refuses_without_the_capability(): void {
        ProPlugin::instance()->register_hooks();
        $this->seedTemplate( 51, 'FAQ section' );
        $page = $this->handlerPage();

        $_GET                   = [ 'page' => ProPage::MENU_SLUG ];
        $_POST                  = [ 'action' => ProPage::CONVERT_ACTION, ProPage::IDS_FIELD => [ '51' ] ];
        $GLOBALS['__test_caps'] = false;

        $this->assertDies( 'Insufficient permissions', fn() => $page->handle_post() );

        $this->assertSame( [], $GLOBALS['__test_referer_checked'], 'the capability is checked before the nonce' );
        $this->assertSame( [], array_filter( $GLOBALS['__test_posts'], static fn( $p ): bool => ( $p->post_type ?? '' ) === 'et_pb_layout' ) );

        unset( $GLOBALS['__test_caps'] );
        $_GET = $_POST = [];
    }

    public function test_the_handler_refuses_a_bad_nonce(): void {
        ProPlugin::instance()->register_hooks();
        $this->seedTemplate( 52, 'FAQ section' );
        $page = $this->handlerPage();

        $_GET                          = [ 'page' => ProPage::MENU_SLUG ];
        $_POST                         = [ 'action' => ProPage::CONVERT_ACTION, ProPage::IDS_FIELD => [ '52' ] ];
        $GLOBALS['__test_referer_ok'] = false;

        $this->assertDies( 'expired', fn() => $page->handle_post() );

        $this->assertSame( [], array_filter( $GLOBALS['__test_posts'], static fn( $p ): bool => ( $p->post_type ?? '' ) === 'et_pb_layout' ) );

        $GLOBALS['__test_referer_ok'] = true;
        $_GET = $_POST = [];
    }

    public function test_the_handler_refuses_an_empty_selection(): void {
        ProPlugin::instance()->register_hooks();
        $page = $this->handlerPage();

        $_GET  = [ 'page' => ProPage::MENU_SLUG ];
        $_POST = [ 'action' => ProPage::CONVERT_ACTION, ProPage::IDS_FIELD => [ '9999', 'not-an-id' ] ];

        $this->assertDies( 'Pick at least one WPBakery template', fn() => $page->handle_post() );

        $this->assertSame( [], $page->redirects );

        $_GET = $_POST = [];
    }

    public function test_a_valid_selection_converts_and_lands_on_the_result_screen(): void {
        ProPlugin::instance()->register_hooks();
        $this->seedTemplate( 53, 'FAQ section' );
        $page = $this->handlerPage();

        $_GET  = [ 'page' => ProPage::MENU_SLUG ];
        $_POST = [ 'action' => ProPage::CONVERT_ACTION, ProPage::IDS_FIELD => [ '53' ] ];

        $page->handle_post();

        $this->assertSame(
            [ [ 'action' => ProPage::CONVERT_ACTION, 'name' => ProPage::CONVERT_NONCE ] ],
            $GLOBALS['__test_referer_checked']
        );

        $this->assertCount( 1, $page->redirects );
        $this->assertStringContainsString( 'page=' . AdminPage::MENU_SLUG, $page->redirects[0] );
        $this->assertStringContainsString( 'action=batch_result', $page->redirects[0] );

        preg_match( '/import_id=([a-z0-9-]+)/', $page->redirects[0], $m );
        $results = get_transient( AdminPage::BATCH_TRANSIENT_PREFIX . $m[1] );
        $this->assertTrue( $results[0]['success'] );
        $this->assertSame( 'library', $results[0]['template_type'] );

        // The exporter ran: the run produced a Divi Library layout, and the run
        // is on record so free's Undo can reach it.
        $layouts = array_filter( $GLOBALS['__test_posts'], static fn( $p ): bool => ( $p->post_type ?? '' ) === 'et_pb_layout' );
        $this->assertCount( 1, $layouts );
        $this->assertSame( 'post-53', get_post_meta( $results[0]['post_id'], '_wbdcp_library_source', true ) );
        $this->assertSame( [ $results[0]['post_id'] ], ( new ImportHistory() )->find( $m[1] )['post_ids'] );

        $_GET = $_POST = [];
    }

    // --- release metadata -------------------------------------------------------

    public function test_pro_release_metadata_agrees(): void {
        $main = (string) file_get_contents( __DIR__ . '/../plugin/jhmg-converter-for-wpbakery-to-divi-pro/jhmg-converter-for-wpbakery-to-divi-pro.php' );

        $this->assertSame( '1.0.0', WBDCP_PLUGIN_VERSION );
        $this->assertMatchesRegularExpression( '/^\s*\*\s*Version:\s*' . preg_quote( WBDCP_PLUGIN_VERSION, '/' ) . '\s*$/m', $main );
        $this->assertStringContainsString( 'Requires Plugins:  jhmg-converter-for-wpbakery-to-divi-5', $main );
        $this->assertStringEndsWith( 'jhmg-converter-for-wpbakery-to-divi-pro/', WBDCP_PLUGIN_DIR );
    }

    public function test_every_pro_identifier_carries_the_wbdcp_prefix(): void {
        $dir   = __DIR__ . '/../plugin/jhmg-converter-for-wpbakery-to-divi-pro';
        $files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir ) );
        $hits  = [];

        foreach ( $files as $file ) {
            if ( $file->getExtension() !== 'php' ) {
                continue;
            }
            $source = (string) file_get_contents( $file->getPathname() );
            if ( preg_match( '/\b(bdcp_|BDCP_|bbdc_|BBDC_)/', $source ) ) {
                $hits[] = $file->getFilename();
            }
        }

        $this->assertSame( [], $hits, 'Pro identifiers must be wbdcp_/WBDCP_ prefixed.' );
    }
}
