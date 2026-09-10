<?php
/**
 * The Divi Library exporter writes an `et_pb_layout` post.
 *
 * Every meta and term asserted here is read from Divi 5.12.1's own layout
 * creation path, not from memory:
 *
 *   et_pb_create_layout()                includes/builder/shortcode-core.php:738
 *     post_type ET_BUILDER_LAYOUT_POST_TYPE ('et_pb_layout')  shortcode-core.php:743, post/type/Layout.php:4
 *     post_status 'publish'                                    shortcode-core.php:740, :715
 *     $meta loop → add_post_meta()                             shortcode-core.php:754-758
 *     $tax_input loop → wp_set_post_terms()                    shortcode-core.php:810-813
 *   _et_pb_built_for_post_type                                 shortcode-core.php:700 (value 'page': DiviLibraryController.php:2103)
 *   _et_pb_template_type                                       shortcode-core.php:701 (value 'layout': DiviLibraryController.php:642-643)
 *   layout_type term 'layout'                                  shortcode-core.php:707 (and the NOT IN query at :169-176)
 *   scope term 'not_global'                                    shortcode-core.php:706 (value: DiviLibraryController.php:2108)
 *   _et_pb_use_divi_5 = 'on'                                   builder-5/…/DiviLibrary/DiviLibraryController.php:732
 *   _et_pb_use_builder = 'on'                                  builder-5/…/SyncToServer/SyncToServerController.php:405
 *   _et_builder_version 'VB|Divi|<version>'                    includes/builder/functions.php:4992
 */

use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Pro\Exporters\DiviLibraryExporter;

final class DiviLibraryExporterTest extends TestCase {

    protected function setUp(): void {
        wbdc_test_reset_hooks();
        $GLOBALS['__test_posts']        = [];
        $GLOBALS['__test_postmeta']     = [];
        $GLOBALS['__test_object_terms'] = [];
    }

    /** @return array{0: array<string,mixed>, 1: array<string,mixed>} An item and its converted data. */
    private function item( string $title = 'FAQ section', array $source_ref = [ 'kind' => 'installed', 'post_id' => 77, 'file' => null ] ): array {
        $divi_data = [
            'divi'        => [
                'id'       => 'root',
                'name'     => 'divi/placeholder',
                'settings' => [],
                'elements' => [
                    [ 'id' => 's1', 'name' => 'divi/section', 'settings' => [], 'elements' => [] ],
                ],
            ],
            'report'      => [ 'warnings' => [] ],
            'unsupported' => [],
        ];

        $item = [
            'title'         => $title,
            'template_type' => 'library',
            'mode'          => 'direct',
            'source_ref'    => $source_ref,
            'blocks'        => $divi_data['divi'],
            'report'        => $divi_data['report'],
            'unsupported'   => [],
        ];

        return [ $item, $divi_data ];
    }

    public function test_export_creates_a_published_et_pb_layout_with_divis_own_metas_and_terms(): void {
        [ $item, $divi_data ] = $this->item();

        $post_id = ( new DiviLibraryExporter() )->export( $item, $divi_data );

        $this->assertGreaterThan( 0, $post_id );
        $layout = get_post( $post_id );
        $this->assertSame( 'et_pb_layout', $layout->post_type );
        $this->assertSame( 'publish', $layout->post_status );
        $this->assertSame( 'FAQ section', $layout->post_title );
        $this->assertStringContainsString( 'wp:divi/section', $layout->post_content );

        $this->assertSame( 'page', get_post_meta( $post_id, '_et_pb_built_for_post_type', true ) );
        $this->assertSame( 'layout', get_post_meta( $post_id, '_et_pb_template_type', true ) );
        $this->assertSame( 'on', get_post_meta( $post_id, '_et_pb_use_builder', true ) );
        $this->assertSame( 'on', get_post_meta( $post_id, '_et_pb_use_divi_5', true ) );
        $this->assertStringStartsWith( 'VB|Divi|', (string) get_post_meta( $post_id, '_et_builder_version', true ) );

        $this->assertSame( [ 'layout' ], $GLOBALS['__test_object_terms'][ $post_id ]['layout_type'] );
        $this->assertSame( [ 'not_global' ], $GLOBALS['__test_object_terms'][ $post_id ]['scope'] );
    }

    public function test_the_conversion_record_travels_with_the_layout(): void {
        [ $item, $divi_data ] = $this->item();

        $post_id = ( new DiviLibraryExporter() )->export( $item, $divi_data );

        $this->assertNotSame( '', (string) get_post_meta( $post_id, '_wbdc_divi_data', true ) );
        $this->assertSame( 'post-77', get_post_meta( $post_id, '_wbdcp_library_source', true ) );
    }

    public function test_exporting_the_same_source_twice_updates_the_one_layout(): void {
        [ $item, $divi_data ] = $this->item();
        $exporter = new DiviLibraryExporter();

        $first = $exporter->export( $item, $divi_data );

        [ $renamed, $divi_data ] = $this->item( 'FAQ section (revised)' );
        $second = $exporter->export( $renamed, $divi_data );

        $this->assertSame( $first, $second );
        $this->assertSame( 'FAQ section (revised)', get_post( $second )->post_title );
        $this->assertCount(
            1,
            array_filter( $GLOBALS['__test_posts'], static fn( $p ): bool => ( $p->post_type ?? '' ) === 'et_pb_layout' )
        );
    }

    public function test_two_different_sources_get_two_layouts(): void {
        $exporter = new DiviLibraryExporter();

        [ $one, $divi_data ] = $this->item( 'One', [ 'kind' => 'installed', 'post_id' => 1, 'file' => null ] );
        [ $two, $divi_data2 ] = $this->item( 'Two', [ 'kind' => 'installed', 'post_id' => 2, 'file' => null ] );

        $this->assertNotSame( $exporter->export( $one, $divi_data ), $exporter->export( $two, $divi_data2 ) );
    }

    public function test_an_uploaded_template_keys_on_the_file_it_came_from(): void {
        $exporter = new DiviLibraryExporter();
        $ref      = [ 'kind' => 'upload', 'post_id' => null, 'file' => 'export.xml' ];

        [ $item, $divi_data ] = $this->item( 'FAQ section', $ref );
        $first = $exporter->export( $item, $divi_data );

        [ $again, $divi_data2 ] = $this->item( 'FAQ section', $ref );
        $second = $exporter->export( $again, $divi_data2 );

        $this->assertSame( $first, $second );
        $this->assertStringStartsWith( 'file-', (string) get_post_meta( $first, '_wbdcp_library_source', true ) );
    }

    public function test_the_block_content_is_slashed_on_the_way_in(): void {
        [ $item, $divi_data ] = $this->item();

        $post_id = ( new DiviLibraryExporter() )->export( $item, $divi_data );

        // wp_update_post() unslashes its input; without wp_slash() the JSON
        // escapes in the block attributes lose their backslashes and the layout
        // renders "u003Cp" where a paragraph should be.
        $this->assertStringContainsString( '\\"builderVersion\\"', get_post( $post_id )->post_content );
    }
}
