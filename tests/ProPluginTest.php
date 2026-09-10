<?php
/**
 * The Pro add-on's wiring: what it declares to the free plugin, the templates
 * it lists, the ids its page will accept back, and a WPBakery template going
 * through the free pipeline into the Divi Library.
 */

use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Conversion\ConversionPreflight;
use WPBakeryDivi5Converter\Conversion\InstalledPostSource;
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

    private function repo(): TemplatesRepository {
        return new TemplatesRepository( static fn(): array => array_values( $GLOBALS['__test_posts'] ) );
    }

    private function page(): ProPage {
        return new ProPage( ProPlugin::instance()->license(), $this->repo() );
    }

    // --- wiring -----------------------------------------------------------------

    public function test_pro_raises_the_limit_declares_itself_and_registers_the_library_exporter(): void {
        ProPlugin::instance()->register_hooks();

        $this->assertTrue( apply_filters( 'wbdc_pro_active', false ) );
        $this->assertSame( PHP_INT_MAX, ConversionPreflight::limit() );
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

    // --- release metadata -------------------------------------------------------

    public function test_pro_release_metadata_agrees(): void {
        $main = (string) file_get_contents( __DIR__ . '/../plugin/jhmg-converter-for-wpbakery-to-divi-pro/jhmg-converter-for-wpbakery-to-divi-pro.php' );

        $this->assertSame( '1.0.0', WBDCP_PLUGIN_VERSION );
        $this->assertMatchesRegularExpression( '/^\s*\*\s*Version:\s*' . preg_quote( WBDCP_PLUGIN_VERSION, '/' ) . '\s*$/m', $main );
        $this->assertStringContainsString( 'Requires Plugins:  jhmg-converter-for-wpbakery-to-divi', $main );
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
