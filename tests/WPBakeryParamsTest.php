<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Helpers\WPBakeryParams;

/**
 * The table of the parameters WPBakery declares, and the three answers it can
 * give.
 *
 * The whole of `logUnmappedSettings()`'s addon branch rests on this: a name the
 * table does not declare for a core tag is somebody else's, and gets reported
 * as a theme's rather than as a gap in this converter. So the two states that
 * look alike — a tag that declares nothing, and a tag this project could not
 * read — have to stay apart, and the generator has to have read every place
 * WPBakery declares a name.
 */
final class WPBakeryParamsTest extends TestCase {

    private const GENERATOR = __DIR__ . '/../scripts/build-wpbakery-params.php';

    private const ARCHIVES = __DIR__ . '/../references/';

    protected function setUp(): void {
        wbdc_test_reset_hooks();
    }

    // -------------------------------------------------------------------------
    // The three answers
    // -------------------------------------------------------------------------

    public function test_a_declared_name_is_declared(): void {
        $this->assertTrue( WPBakeryParams::declares( 'vc_row', 'full_width' ) );
        $this->assertTrue( WPBakeryParams::declares( 'vc_column', 'width' ) );
    }

    public function test_a_name_the_tag_does_not_declare_is_answered_false(): void {
        // Written by an older Ronneby build; no version of WPBakery declares it
        // for `vc_column` (task-11-amendments §7).
        $this->assertFalse( WPBakeryParams::declares( 'vc_column', 'cus_bg_position' ) );
        $this->assertFalse( WPBakeryParams::declares( 'vc_row_inner', 'bg_check' ) );
    }

    /**
     * A tag with no entry is one the generator could not read, and answering
     * "declares nothing" for it would file every real gap on it as a theme's
     * parameter. `vc_grid` is the runtime shortcode `vc_basic_grid` renders
     * through; no `'base' => 'vc_grid'` exists in either archive.
     */
    public function test_a_tag_the_table_does_not_hold_is_answered_null(): void {
        $this->assertNull( WPBakeryParams::declaredFor( 'vc_grid' ) );
        $this->assertNull( WPBakeryParams::declares( 'vc_grid', 'anything' ) );
        $this->assertNull( WPBakeryParams::declares( 'dfd_heading', 'title' ), 'a theme\'s own element has no WPBakery fields to compare against' );
    }

    /**
     * An entry that is present and empty is a tag whose config was read and
     * declares nothing — every name on it is somebody else's.
     */
    public function test_a_tag_whose_config_declares_nothing_is_answered_false_not_null(): void {
        $this->withTable( [ 'vc_gitem_zone' => [] ], function (): void {
            $this->assertSame( [], WPBakeryParams::declaredFor( 'vc_gitem_zone' ) );
            $this->assertFalse( WPBakeryParams::declares( 'vc_gitem_zone', 'link' ) );
        } );
    }

    // -------------------------------------------------------------------------
    // Every place WPBakery declares a name
    // -------------------------------------------------------------------------

    /**
     * Two names live in exactly one place each, and neither is a config file
     * under `config/**`: 7.8 declares them in its single-file grid-item map,
     * and 9.0.1 keeps them only as the deprecated hidden params the 9.0
     * attribute migration re-registers with `vc_add_param()`. A generator that
     * reads `config/**` alone reports both as theme parameters.
     */
    public function test_the_names_only_the_grid_item_map_and_the_migration_declare(): void {
        $this->assertTrue( WPBakeryParams::declares( 'vc_gitem_image', 'border_color' ) );
        $this->assertTrue( WPBakeryParams::declares( 'vc_gitem_post_categories', 'category_color' ) );
    }

    /** Every name of the 9.0 legacy table is a WPBakery name, not a theme's. */
    public function test_the_deprecated_fields_the_migration_re_registers_are_declared(): void {
        foreach ( [
            [ 'vc_progress_bar', 'bgcolor' ],
            [ 'vc_round_chart', 'stroke_color' ],
            [ 'vc_hoverbox', 'hover_background_color' ],
            [ 'vc_text_separator', 'i_color' ],
        ] as [ $tag, $param ] ) {
            $this->assertTrue( WPBakeryParams::declares( $tag, $param ), "{$tag}.{$param}" );
        }
    }

    // -------------------------------------------------------------------------
    // The generator, when the archives are here
    // -------------------------------------------------------------------------

    /**
     * 9.0.1 on its own: `vc_gitem_zone`'s config is read (a map with a `'base'`
     * and no `params`) and comes out as an explicit `[]`, while the two names
     * above can only have come from the migration class, because 7.8's
     * grid-item file is not in this archive.
     */
    public function test_the_generator_distinguishes_an_empty_config_from_an_unread_one(): void {
        $table = $this->generate( 'js_composer.9.0.1.zip' );

        $this->assertArrayHasKey( 'vc_gitem_zone', $table );
        $this->assertSame( [], $table['vc_gitem_zone'] );
        $this->assertArrayNotHasKey( 'vc_grid', $table );

        $this->assertContains( 'border_color', $table['vc_gitem_image'] );
        $this->assertContains( 'category_color', $table['vc_gitem_post_categories'] );
    }

    /** 7.8 on its own: the same two names, out of its single-file grid-item map. */
    public function test_the_generator_reads_the_pre_9_grid_item_map(): void {
        $table = $this->generate( 'js_composer.7.8.zip' );

        $this->assertContains( 'border_color', $table['vc_gitem_image'] );
        $this->assertContains( 'category_color', $table['vc_gitem_post_categories'] );
        $this->assertContains( 'link', $table['vc_gitem_zone'], 'held in $zone_params, two variables deep' );
    }

    /**
     * The shipped table is the union of the two, regenerated from the archives
     * — so a table edited by hand fails here rather than shipping.
     */
    public function test_the_committed_table_is_what_the_generator_writes(): void {
        $this->assertSame(
            $this->generate( 'js_composer.9.0.1.zip', 'js_composer.7.8.zip' ),
            json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/plugin/jhmg-converter-for-wpbakery-to-divi-5/data/wpbakery-params.json' ), true )
        );
    }

    /**
     * @param string ...$archives Names under `references/`.
     * @return array<string, array<int,string>>
     */
    private function generate( string ...$archives ): array {
        $sources = [];
        foreach ( $archives as $archive ) {
            if ( ! is_readable( self::ARCHIVES . $archive ) ) {
                $this->markTestSkipped( "references/{$archive} is not in this checkout" );
            }
            $sources[] = escapeshellarg( self::ARCHIVES . $archive );
        }

        $out = (string) tempnam( sys_get_temp_dir(), 'wbdc-params' );

        exec(
            sprintf( 'php %s %s --out=%s 2>&1', escapeshellarg( self::GENERATOR ), implode( ' ', $sources ), escapeshellarg( $out ) ),
            $output,
            $status
        );

        $this->assertSame( 0, $status, implode( "\n", $output ) );

        $table = json_decode( (string) file_get_contents( $out ), true );
        unlink( $out );

        $this->assertIsArray( $table );

        return $table;
    }

    /** Runs `$fn` against a table of this test's own, and puts the real one back. */
    private function withTable( array $table, callable $fn ): void {
        $cache = new \ReflectionProperty( WPBakeryParams::class, 'table' );
        $was   = $cache->getValue();

        $cache->setValue( null, $table );

        try {
            $fn();
        } finally {
            $cache->setValue( null, $was );
        }
    }
}
