<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Admin\AdminPage;
use WPBakeryDivi5Converter\Conversion\ConversionCommitter;
use WPBakeryDivi5Converter\Conversion\ConversionPreflight;
use WPBakeryDivi5Converter\Parsers\WPBakeryImportParser;

/**
 * Tools → WPBakery → Divi 5: the menu, the upload form and the result table.
 *
 * The upload is two steps because it has to be: the post types a file holds
 * are only knowable once the file has been read, and the form offers them
 * (task-12-amendments §3) with `page` and `post` ticked.
 */
final class AdminSupportTest extends TestCase {

    private const IMPORT_DIR = __DIR__ . '/../fixtures/wpbakery-import/';

    protected function setUp(): void {
        wbdc_test_reset_hooks();
        $GLOBALS['__test_posts']       = [];
        $GLOBALS['__test_postmeta']    = [];
        $GLOBALS['__test_user_meta']   = [];
        $GLOBALS['__test_menu_pages']  = [];
        $GLOBALS['__test_styles']      = [];
        $GLOBALS['__test_post_types']  = [ 'post' => 'post', 'page' => 'page' ];
        unset( $GLOBALS['__test_caps'] );
        $_GET   = [];
        $_FILES = [];
    }

    /** @return array[] */
    private function items( string $fixture = 'two-pages.xml' ): array {
        return ( new WPBakeryImportParser() )->parse( self::IMPORT_DIR . $fixture, $fixture );
    }

    // --- menu, constants, styles --------------------------------------------------------

    public function test_it_registers_one_tools_page_under_the_prefixed_slug(): void {
        ( new AdminPage() )->register_menu();

        $this->assertSame( 'wbdc-converter', AdminPage::MENU_SLUG );
        $this->assertSame( 'wbdc-converter', $GLOBALS['__test_menu_pages'][0]['menu_slug'] );
        $this->assertSame( 'WPBakery to Divi 5 Converter', $GLOBALS['__test_menu_pages'][0]['page_title'] );
        $this->assertSame( 'manage_options', $GLOBALS['__test_menu_pages'][0]['capability'] );
    }

    public function test_the_pro_url_is_the_committers_one_product_page(): void {
        $this->assertSame( ConversionCommitter::PRO_URL, AdminPage::PRO_URL );
        $this->assertSame( '$25/yr', AdminPage::PRO_PRICE );
    }

    public function test_admin_styles_load_only_on_this_plugins_screens(): void {
        $page = new AdminPage();
        $page->enqueue_admin_styles( 'plugins.php' );
        $this->assertSame( [], $GLOBALS['__test_styles'] );

        $page->enqueue_admin_styles( 'tools_page_wbdc-converter' );
        $style = $GLOBALS['__test_styles']['wbdc-admin'];

        $this->assertTrue( $style['enqueued'] );
        $this->assertStringEndsWith( 'assets/css/admin.css', (string) $style['src'], 'a real file, not inline CSS' );
        $this->assertSame( WBDC_PLUGIN_VERSION, $style['ver'], 'a versioned enqueue, so a release busts the cache' );
        $this->assertSame( '', $style['inline'] );
    }

    /** The stylesheet the enqueue names is really there, and holds the classes the screens use. */
    public function test_the_admin_stylesheet_exists_and_covers_the_screens_classes(): void {
        $css = (string) file_get_contents( __DIR__ . '/../plugin/jhmg-converter-for-wpbakery-to-divi/assets/css/admin.css' );

        foreach ( [ '.wbdc-card', '.wbdc-outline', '.wbdc-not-carried', '.wbdc-addon-block', '.wbdc-report-summary', '.wbdc-badge-flag' ] as $class ) {
            $this->assertStringContainsString( $class, $css, $class );
        }
    }

    public function test_the_screen_explains_itself_when_divi_5_is_missing(): void {
        $GLOBALS['__test_divi_present'] = false;

        ob_start();
        ( new AdminPage() )->render_page();
        $html = (string) ob_get_clean();

        wbdc_test_reset_divi();

        $this->assertStringContainsString( 'Nothing has been changed on your site', $html );
        $this->assertStringNotContainsString( 'Convert Now', $html );
    }

    // --- the upload form ----------------------------------------------------------------

    public function test_the_upload_form_offers_the_post_types_the_file_holds(): void {
        $html = AdminPage::upload_options_form( $this->items(), [ 'This file holds 600 WPBakery pages; the first 500 were read and the rest were left.' ] );

        $this->assertStringContainsString( 'value="page" checked="checked"', $html );
        $this->assertStringContainsString( 'value="post" checked="checked"', $html );
        $this->assertStringContainsString( 'value="vc4_templates">', $html, 'a template is offered, not ticked' );
        $this->assertStringContainsString( 'Pro turns these into Divi Library layouts', $html );
        $this->assertStringContainsString( 'the first 500 were read', $html, "the parser's truncation warning is shown" );
        $this->assertStringContainsString( 'name="wbdc_post_status"', $html );
        $this->assertStringContainsString( AdminPage::IMPORT_CONVERT_ACTION, $html );
    }

    public function test_the_defaults_come_from_the_parsers_own_list(): void {
        $this->assertSame( [ 'page', 'post' ], WPBakeryImportParser::DEFAULT_SELECTED_POST_TYPES );
    }

    public function test_selecting_post_types_separates_templates_from_what_it_dropped(): void {
        $all = AdminPage::select_items( $this->items(), [ 'page', 'post', 'vc4_templates' ] );
        $this->assertSame( [ 'Home', 'Saved Section', 'A builder post' ], array_column( $all['items'], 'title' ) );
        $this->assertSame( [], $all['templates'] );
        $this->assertSame( [], $all['dropped'] );

        $pages_only = AdminPage::select_items( $this->items(), [ 'page' ] );
        $this->assertSame( [ 'Home' ], array_column( $pages_only['items'], 'title' ) );
        $this->assertSame( [ 'Saved Section' ], array_column( $pages_only['templates'], 'title' ), 'an unticked template leaves the plan rather than queueing behind it' );
        $this->assertSame( [ 'post' => 1 ], $pages_only['dropped'] );
    }

    /**
     * On free the limit is one item, taken from the front of the plan. While an
     * unticked template merely queued at the back it was almost never reached,
     * so it fell into the generic "N more pages" row instead of saying what it
     * was. Out of the plan, it is named every time.
     */
    public function test_on_free_an_unticked_template_is_named_rather_than_swallowed_by_the_limit(): void {
        $page = $this->convertingPage();

        set_transient(
            AdminPage::IMPORT_ITEMS_TRANSIENT_PREFIX . get_current_user_id(),
            AdminPage::stash_for( $this->items(), [] )
        );

        $this->assertSame( 1, ConversionPreflight::limit(), 'this is the free plugin' );

        $page->go( [ 'wbdc_post_types' => [ 'page' ], 'wbdc_post_status' => 'draft' ] );

        $results  = get_transient( AdminPage::BATCH_TRANSIENT_PREFIX . get_option( 'wbdc_import_history' )[0]['id'] );
        $errors   = array_column( $results, 'error' );
        $template = array_values( array_filter( $results, static fn( array $r ): bool => ( $r['template_type'] ?? '' ) === 'library' ) );

        $this->assertTrue( $results[0]['success'], 'the one conversion the limit allows went to a page, not to a template' );
        $this->assertSame( 'Home', $results[0]['title'] );

        $this->assertCount( 1, $template );
        $this->assertSame( ConversionCommitter::LIBRARY_NOT_SELECTED, $template[0]['error'] );
        $this->assertSame( 'Saved Section', $template[0]['title'] );
        $this->assertNotContains( 'Free converts one page per upload. The Pro add-on converts every page in the file in one run.', $errors, 'nothing was left over: the plan held one selected item' );
    }

    /**
     * A .txt upload is one page's shortcodes; the parser types it `page`, and
     * the form and the selection have to agree on that — a checkbox that does
     * not change what is converted would be a lie.
     */
    public function test_a_shortcode_file_is_offered_and_selected_as_a_page(): void {
        $items = $this->items( 'page.txt' );
        $this->assertSame( 'page', $items[0]['source_post_type'] );

        $this->assertStringContainsString( 'value="page" checked="checked"', AdminPage::upload_options_form( $items ) );
        $this->assertCount( 1, AdminPage::select_items( $items, [ 'page' ] )['items'] );
        $this->assertSame( [ 'page' => 1 ], AdminPage::select_items( $items, [ 'post' ] )['dropped'] );
    }

    /** An item that somehow carries no post type at all is still offered, as a page. */
    public function test_an_item_with_no_post_type_is_treated_as_a_page(): void {
        $items = [ [ 'title' => 'Loose', 'source_post_type' => '', 'template_type' => '' ] ];

        $this->assertStringContainsString( 'value="page" checked="checked"', AdminPage::upload_options_form( $items ) );
        $this->assertCount( 1, AdminPage::select_items( $items, [ 'page' ] )['items'] );
        $this->assertSame( [ 'page' => 1 ], AdminPage::select_items( $items, [ 'post' ] )['dropped'] );
    }

    /** An AdminPage whose redirect can be observed and whose convert step can be called. */
    private function convertingPage(): AdminPage {
        return new class() extends AdminPage {
            /** @var string[] */
            public array $redirects = [];
            protected function redirect( string $location ): void {
                $this->redirects[] = $location;
            }
            /** @param array<string,mixed> $request */
            public function go( array $request ): void {
                $this->handle_import_convert( $request );
            }
        };
    }

    public function test_the_import_convert_step_records_a_run_and_skips_the_unselected_template(): void {
        $page = $this->convertingPage();

        add_filter( 'wbdc_direct_conversion_limit', fn(): int => PHP_INT_MAX );
        set_transient(
            AdminPage::IMPORT_ITEMS_TRANSIENT_PREFIX . get_current_user_id(),
            AdminPage::stash_for( $this->items(), [] )
        );

        $page->go( [ 'wbdc_post_types' => [ 'page' ], 'wbdc_post_status' => 'draft' ] );

        $this->assertStringContainsString( 'action=batch_result', $page->redirects[0] );

        $runs = get_option( 'wbdc_import_history' );
        $this->assertCount( 1, $runs );

        $results = get_transient( AdminPage::BATCH_TRANSIENT_PREFIX . $runs[0]['id'] );
        $titles  = array_column( $results, 'title' );

        $this->assertContains( 'Home', $titles );
        $this->assertSame( ConversionCommitter::LIBRARY_NOT_SELECTED, $results[1]['error'] );
        $this->assertTrue( $results[1]['skipped'] );
        $this->assertStringContainsString( 'post type', (string) end( $results )['error'] );
    }

    public function test_the_import_convert_step_refuses_an_expired_stash(): void {
        $page = $this->convertingPage();

        $this->expectException( \RuntimeException::class );
        $page->go( [ 'wbdc_post_types' => [ 'page' ] ] );
    }

    /**
     * The parser gives every item the same attachment map, so the stash keeps
     * one copy rather than one per page — a 500-page export would otherwise
     * write tens of megabytes of the same map into wp_options.
     */
    public function test_the_stash_keeps_one_copy_of_the_attachment_map(): void {
        $items = [
            [ 'title' => 'A', 'source_post_type' => 'page', 'attachments' => [ 7 => [ 'url' => 'https://x/a.png', 'sizes' => [] ] ] ],
            [ 'title' => 'B', 'source_post_type' => 'page', 'attachments' => [ 7 => [ 'url' => 'https://x/a.png', 'sizes' => [] ] ] ],
        ];

        $stash = AdminPage::stash_for( $items, [ 'a warning' ] );

        $this->assertSame( [], $stash['items'][0]['attachments'] );
        $this->assertSame( [], $stash['items'][1]['attachments'] );
        $this->assertSame( [ 7 => [ 'url' => 'https://x/a.png', 'sizes' => [] ] ], $stash['attachments'] );
        $this->assertSame( [ 'a warning' ], $stash['warnings'] );

        $restored = AdminPage::items_from( $stash );
        $this->assertSame( $items[0]['attachments'], $restored[0]['attachments'] );
        $this->assertSame( $items[1]['attachments'], $restored[1]['attachments'] );
    }

    // --- the guards on the two handlers that read a superglobal ---------------------------

    /**
     * Publish flips a post's status from a GET, so it checks the nonce, the
     * capability, and that the post is one this plugin made. Without the last
     * check a valid nonce would publish any post id somebody typed.
     */
    public function test_publish_refuses_a_post_this_plugin_did_not_create(): void {
        wp_insert_post( [ 'ID' => 610, 'post_type' => 'page', 'post_status' => 'draft' ] );
        wp_insert_post( [ 'ID' => 611, 'post_type' => 'page', 'post_status' => 'draft' ] );
        update_post_meta( 610, '_wbdc_import_source', 'direct' );

        $page = new class() extends AdminPage {
            /** @var string[] */
            public array $redirects = [];
            protected function redirect( string $location ): void {
                $this->redirects[] = $location;
            }
            public function publish(): void {
                $this->handle_publish();
            }
        };

        $_GET = [ 'post_id' => '611', 'import_id' => 'abc', '_wpnonce' => wp_create_nonce( 'wbdc_publish_611' ) ];
        try {
            $page->publish();
            $this->fail( 'a post this plugin never made must not be publishable from this screen' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'not created by this converter', $e->getMessage() );
        }
        $this->assertSame( 'draft', get_post( 611 )->post_status );

        $_GET = [ 'post_id' => '610', 'import_id' => 'abc', '_wpnonce' => wp_create_nonce( 'wbdc_publish_610' ) ];
        $page->publish();
        $this->assertSame( 'publish', get_post( 610 )->post_status );
        $this->assertStringContainsString( 'action=batch_result', $page->redirects[0] );

        $_GET = [];
    }

    public function test_publish_needs_the_capability(): void {
        wp_insert_post( [ 'ID' => 620, 'post_type' => 'page', 'post_status' => 'draft' ] );
        update_post_meta( 620, '_wbdc_import_source', 'direct' );

        $page = new class() extends AdminPage {
            protected function redirect( string $location ): void {}
            public function publish(): void {
                $this->handle_publish();
            }
        };

        $GLOBALS['__test_caps'] = false;
        $_GET                   = [ 'post_id' => '620', '_wpnonce' => wp_create_nonce( 'wbdc_publish_620' ) ];

        try {
            $page->publish();
            $this->fail( 'publish must require manage_options' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'Insufficient permissions', $e->getMessage() );
        }

        $this->assertSame( 'draft', get_post( 620 )->post_status );
        $_GET = [];
    }

    /**
     * The upload handler reads `$_FILES`. It refuses a missing file, a PHP
     * upload error, and — the one that matters — a path the request named
     * rather than one PHP received.
     */
    public function test_the_upload_handler_refuses_what_it_should(): void {
        $page = new class() extends AdminPage {
            protected function redirect( string $location ): void {}
            public function upload(): void {
                $this->handle_import();
            }
        };

        $_FILES = [];
        $this->assertDies( 'No file was uploaded', fn() => $page->upload() );

        $_FILES = [ 'wbdc_import_file' => [ 'error' => UPLOAD_ERR_INI_SIZE, 'tmp_name' => '', 'name' => 'x.xml' ] ];
        $this->assertDies( 'exceeds the server upload limit', fn() => $page->upload() );

        // A real, readable file that PHP did not receive as an upload: without
        // the is_uploaded_file() check the parser would read whatever path the
        // request pointed at.
        $_FILES = [
            'wbdc_import_file' => [
                'error'    => UPLOAD_ERR_OK,
                'tmp_name' => __DIR__ . '/../fixtures/wpbakery-import/two-pages.xml',
                'name'     => 'two-pages.xml',
            ],
        ];
        $this->assertDies( 'did not arrive as an upload', fn() => $page->upload() );

        $this->assertFalse( get_transient( AdminPage::IMPORT_ITEMS_TRANSIENT_PREFIX . get_current_user_id() ), 'nothing was stashed' );

        $GLOBALS['__test_caps'] = false;
        $this->assertDies( 'Insufficient permissions', fn() => $page->upload() );

        $_FILES = [];
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

    // --- the result table ---------------------------------------------------------------

    public function test_batch_table_shows_each_outcome(): void {
        wp_insert_post( [ 'ID' => 400, 'post_type' => 'page', 'post_status' => 'draft' ] );

        $html = AdminPage::batch_table( [
            [
                'title'       => 'Home',
                'post_id'     => 400,
                'success'     => true,
                'error'       => '',
                'report'      => [ 'warnings' => [ 'bracketed text kept: [1]' ], 'not_carried_over' => [], 'theme_elements' => [ 'Ronneby' => 2 ] ],
                'unsupported' => [],
            ],
            [ 'title' => 'Broken', 'post_id' => 0, 'success' => false, 'error' => 'No layout', 'report' => [], 'unsupported' => [] ],
            [
                'title'         => 'Saved Section',
                'post_id'       => 0,
                'success'       => false,
                'skipped'       => true,
                'template_type' => 'library',
                'error'         => ConversionCommitter::LIBRARY_NOT_SELECTED,
                'report'        => [],
                'unsupported'   => [],
            ],
        ], 'abc' );

        $this->assertStringContainsString( '2 page(s) processed', $html );
        $this->assertStringContainsString( '1 converted', $html );
        $this->assertStringContainsString( '1 failed', $html );
        $this->assertStringContainsString( 'bracketed text kept', $html );
        $this->assertStringContainsString( 'Ronneby × 2', $html );
        $this->assertStringContainsString( 'wbdc_action=publish', $html );
        $this->assertStringContainsString( 'No layout', $html );
        $this->assertStringContainsString( 'Not converted', $html );
        $this->assertStringContainsString( ConversionCommitter::LIBRARY_NOT_SELECTED, $html );
    }

    public function test_a_clean_conversion_says_so(): void {
        wp_insert_post( [ 'ID' => 401, 'post_type' => 'page', 'post_status' => 'publish' ] );

        $html = AdminPage::batch_table( [
            [ 'title' => 'Tidy', 'post_id' => 401, 'success' => true, 'error' => '', 'report' => [], 'unsupported' => [] ],
        ], 'abc' );

        $this->assertStringContainsString( 'wbdc-status--clean', $html );
        $this->assertStringNotContainsString( 'wbdc_action=publish', $html, 'an already published page needs no Publish button' );
    }
}
