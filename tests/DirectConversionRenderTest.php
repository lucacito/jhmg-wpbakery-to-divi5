<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Admin\AdminPage;
use WPBakeryDivi5Converter\Admin\DirectConversionPage;
use WPBakeryDivi5Converter\Admin\NotCarriedOverRenderer;
use WPBakeryDivi5Converter\Admin\OutlineRenderer;
use WPBakeryDivi5Converter\Admin\WPBakeryPageRepository;
use WPBakeryDivi5Converter\Conversion\ConversionPlan;

/**
 * The two screens a reader actually reads.
 *
 * Nothing the engine reports may be hidden here (task-12-amendments §2): every
 * key of `ConversionReportTest::KEYS` has somewhere to appear, and the two
 * kinds of "not handled" are worded apart (§5) — a converter gap is a skipped
 * setting, a theme's own field is not.
 */
final class DirectConversionRenderTest extends TestCase {

    protected function setUp(): void {
        wbdc_test_reset_hooks();
        $GLOBALS['__test_posts']      = [];
        $GLOBALS['__test_postmeta']   = [];
        $GLOBALS['__test_post_types'] = [ 'post' => 'post', 'page' => 'page' ];
    }

    /**
     * A report with every key present and empty, which is what the engine
     * hands over for a page that held nothing.
     *
     * @return array<string,mixed>
     */
    public static function emptyReport(): array {
        return [
            'converted'           => [],
            'approximate'         => [],
            'approximate_matches' => [],
            'warnings'            => [],
            'skipped_settings'    => [],
            'unresolved_globals'  => [],
            'not_carried_over'    => [],
            'theme_elements'      => [],
            'static_copies'       => [],
            'custom_css_carried'  => [],
            'unresolved_media'    => [],
            'text_nodes'          => 0,
            'bracketed_text'      => 0,
            'quality'             => [ 'module_coverage' => 100, 'settings_issues' => 0 ],
        ];
    }

    /** @param array<string,mixed> $report */
    private function plan( array $report = [], string $mode = 'direct', array $extra = [] ): ConversionPlan {
        return new ConversionPlan( [
            ConversionPlan::item( array_merge( [
                'title'      => 'Landing',
                'mode'       => $mode,
                'source_ref' => [ 'kind' => 'installed', 'post_id' => 42, 'file' => null ],
                'outline'    => [
                    [
                        'type'        => 'section',
                        'name'        => 'divi/section',
                        'label'       => 'Section',
                        'placeholder' => false,
                        'children'    => [
                            [ 'type' => 'module', 'name' => 'divi/code', 'label' => 'Code', 'placeholder' => true, 'children' => [] ],
                        ],
                    ],
                ],
                'report'     => array_merge(
                    array_merge( self::emptyReport(), [ 'converted' => [ 'section' => 1, 'row' => 1, 'heading' => 2 ] ] ),
                    $report
                ),
            ], $extra ) ),
        ] );
    }

    // --- the outline -----------------------------------------------------------------

    public function test_outline_renderer(): void {
        $html = OutlineRenderer::render( [
            [
                'type'     => 'section',
                'label'    => 'Section',
                'children' => [ [ 'type' => 'module', 'label' => 'Code', 'placeholder' => true, 'children' => [] ] ],
            ],
        ] );

        $this->assertStringContainsString( 'wbdc-outline-node--section', $html );
        $this->assertStringContainsString( 'wbdc-outline-node--placeholder', $html );
        $this->assertStringContainsString( 'rebuild by hand', $html );
        $this->assertStringContainsString( 'Nothing to show', OutlineRenderer::render( [] ) );
    }

    // --- "not carried over" ------------------------------------------------------------

    public function test_not_carried_over_groups_by_kind_and_escapes(): void {
        $html = NotCarriedOverRenderer::render(
            [
                [ 'kind' => 'animation', 'node_id' => 'vc_row-1', 'detail' => 'fade<b>' ],
                [ 'kind' => 'hover', 'node_id' => 'vc_btn-2', 'detail' => 'button hover colours' ],
            ],
            [ [ 'node_id' => 'vc_hoverbox-3', 'tag' => 'vc_hoverbox', 'matched_to' => 'HoverboxConverter' ] ],
            [ [ 'node_id' => 'vc_row-1', 'setting_key' => 'bg_color', 'ref' => 'var(--x)' ] ]
        );

        $this->assertStringContainsString( 'Animations', $html );
        $this->assertStringContainsString( 'fade&lt;b&gt;', $html );
        $this->assertStringContainsString( 'Hover colours', $html );
        $this->assertStringContainsString( 'vc_hoverbox → HoverboxConverter', $html );
        $this->assertStringContainsString( 'bg_color = var(--x)', $html );
        $this->assertSame( '', NotCarriedOverRenderer::render( [] ) );
    }

    /** A theme's own field is never presented as a converter failure (§5). */
    public function test_theme_and_addon_settings_are_worded_apart_from_converter_gaps(): void {
        $html = NotCarriedOverRenderer::render( [
            [ 'kind' => 'addon', 'node_id' => 'vc_row-1', 'detail' => 'dfd_row_parallax: a parameter added by a theme (matches Ronneby\'s table)' ],
        ] );

        $this->assertStringContainsString( 'Theme and add-on settings', $html );
        $this->assertStringContainsString( 'not to WPBakery', $html );
        $this->assertStringNotContainsString( 'Not carried over', $html, 'nothing here failed to convert' );
    }

    public function test_a_handler_failure_says_what_stands_in_for_the_element(): void {
        $html = NotCarriedOverRenderer::render( [
            [ 'kind' => 'error', 'node_id' => 'vc_gallery-9', 'detail' => 'vc_gallery could not be converted (TypeError: boom)' ],
        ] );

        $this->assertStringContainsString( 'replaced by a placeholder because its handler failed', $html );
        $this->assertStringContainsString( 'the original shortcode is preserved inside it', $html );
    }

    public function test_theme_elements_and_unresolved_media_are_rendered_by_the_renderer(): void {
        $direct = NotCarriedOverRenderer::render( [], [], [], [ 'Ronneby' => 3 ], [ [ 'node_id' => 'vc_single_image-4', 'attachment_id' => 412 ] ], 'direct' );
        $import = NotCarriedOverRenderer::render( [], [], [], [ 'Ronneby' => 3 ], [], 'import' );

        $this->assertStringContainsString( 'Theme and add-on elements', $direct );
        $this->assertStringContainsString( 'Ronneby × 3', $direct );
        $this->assertStringContainsString( 'copied as static HTML', $direct );
        $this->assertStringContainsString( 'attachment 412', $direct );
        $this->assertStringContainsString( 'left as placeholders', $import );
    }

    // --- every key the engine reports ----------------------------------------------------

    /**
     * A sample value for each key of `ConversionReportTest::KEYS`, with a
     * fingerprint that must appear in the rendered report.
     *
     * Two keys are views of others rather than things of their own, so their
     * sample carries the context they are read against: `quality` is arithmetic
     * over the converted, approximate and unsupported counts, and needs one of
     * them present for the sentence to have a denominator. Everything else
     * stands alone.
     *
     * @return array<string, array{sample: array<string,mixed>, expect: string}>
     */
    public static function reportKeySamples(): array {
        return [
            'converted'           => [ 'sample' => [ 'converted' => [ 'heading' => 3 ] ], 'expect' => '3 Divi modules' ],
            'approximate'         => [ 'sample' => [ 'approximate' => [ 'tabs' => 2 ] ], 'expect' => '2 of them approximate' ],
            'approximate_matches' => [
                'sample' => [ 'approximate_matches' => [ [ 'node_id' => 'n1', 'tag' => 'vc_tta_tour', 'matched_to' => 'TtaTabsConverter' ] ] ],
                'expect' => 'vc_tta_tour → TtaTabsConverter',
            ],
            'warnings'            => [ 'sample' => [ 'warnings' => [ 'a note from the engine' ] ], 'expect' => 'a note from the engine' ],
            'skipped_settings'    => [ 'sample' => [ 'skipped_settings' => [ 'vc_row-1: parallax_speed_bg' ] ], 'expect' => 'vc_row-1: parallax_speed_bg' ],
            'unresolved_globals'  => [
                'sample' => [ 'unresolved_globals' => [ [ 'node_id' => 'n1', 'setting_key' => 'bg_color', 'ref' => 'var(--z)' ] ] ],
                'expect' => 'bg_color = var(--z)',
            ],
            'not_carried_over'    => [
                'sample' => [ 'not_carried_over' => [ [ 'kind' => 'animation', 'node_id' => 'n1', 'detail' => 'bounceInDown' ] ] ],
                'expect' => 'bounceInDown',
            ],
            'theme_elements'      => [ 'sample' => [ 'theme_elements' => [ 'Ronneby' => 3 ] ], 'expect' => 'Ronneby × 3' ],
            'static_copies'       => [ 'sample' => [ 'static_copies' => [ 'a-1', 'b-2' ] ], 'expect' => 'kept as static HTML' ],
            'custom_css_carried'  => [ 'sample' => [ 'custom_css_carried' => [ 'n1' ] ], 'expect' => 'carried as custom CSS' ],
            'unresolved_media'    => [
                'sample' => [ 'unresolved_media' => [ [ 'node_id' => 'n1', 'attachment_id' => 412 ] ] ],
                'expect' => 'attachment 412',
            ],
            'text_nodes'          => [ 'sample' => [ 'text_nodes' => 2 ], 'expect' => '2 runs of text' ],
            'bracketed_text'      => [ 'sample' => [ 'bracketed_text' => 3 ], 'expect' => '3 bracketed tokens' ],
            'quality'             => [
                'sample' => [ 'quality' => [ 'module_coverage' => 62, 'settings_issues' => 1 ], 'converted' => [ 'heading' => 5 ] ],
                'expect' => '62% coverage',
            ],
        ];
    }

    /** The samples and the engine's contract must name the same keys. */
    public function test_a_new_report_key_has_nowhere_to_hide(): void {
        $this->assertSame(
            array_keys( ConversionReportTest::KEYS ),
            array_keys( self::reportKeySamples() ),
            'ConversionReportTest::KEYS changed: give the new key a sample and somewhere to render'
        );
    }

    #[DataProvider( 'reportKeySamples' )]
    public function test_every_report_key_reaches_the_reader( array $sample, string $expect ): void {
        $html = ( new DirectConversionPage() )->render_report( $this->plan( $sample ) );

        $this->assertStringContainsString( $expect, $html );
    }

    /** The result screen hides nothing the check screen shows. */
    #[DataProvider( 'reportKeySamples' )]
    public function test_every_report_key_reaches_the_result_screen_too( array $sample, string $expect ): void {
        wp_insert_post( [ 'ID' => 500, 'post_type' => 'page', 'post_status' => 'publish' ] );

        $html = AdminPage::batch_table( [
            [
                'title'       => 'Home',
                'post_id'     => 500,
                'success'     => true,
                'error'       => '',
                'mode'        => 'direct',
                'report'      => array_merge( self::emptyReport(), $sample ),
                'unsupported' => [],
            ],
        ], 'abc' );

        $this->assertStringContainsString( $expect, $html );
    }

    // --- the report screen -------------------------------------------------------------

    public function test_the_report_shows_the_outline_the_counts_and_a_convert_button(): void {
        $html = ( new DirectConversionPage() )->render_report( $this->plan() );

        $this->assertStringContainsString( 'Landing', $html );
        $this->assertStringContainsString( '4 Divi modules', $html );
        $this->assertStringContainsString( 'wbdc-outline-node--section', $html );
        $this->assertStringContainsString( 'name="wbdc_post_ids[]" value="42"', $html );
        $this->assertStringContainsString( 'Convert to Divi 5', $html );
        $this->assertStringContainsString( 'Nothing has been written yet', $html );
    }

    public function test_the_report_renders_every_other_key_the_engine_reports(): void {
        $html = ( new DirectConversionPage() )->render_report( $this->plan( [
            'warnings'           => [ 'bracketed text kept: [1]' ],
            'skipped_settings'   => [ 'vc_row-1: parallax_speed_bg' ],
            'not_carried_over'   => [ [ 'kind' => 'animation', 'node_id' => 'vc_row-1', 'detail' => 'bounceIn' ] ],
            'theme_elements'     => [ 'Ronneby' => 3, 'Ultimate Addons' => 1 ],
            'static_copies'      => [ 'dfd_carousel-7', 'ultimate_video-8' ],
            'custom_css_carried' => [ 'vc_row-1' ],
            'unresolved_media'   => [ [ 'node_id' => 'vc_single_image-4', 'attachment_id' => 412 ] ],
            'text_nodes'         => 2,
            'bracketed_text'     => 3,
        ] ) );

        $this->assertStringContainsString( 'bracketed text kept', $html );
        $this->assertStringContainsString( 'vc_row-1: parallax_speed_bg', $html );
        $this->assertStringContainsString( 'Animations', $html );
        $this->assertStringContainsString( 'Ronneby × 3', $html );
        $this->assertStringContainsString( 'Ultimate Addons × 1', $html );
        $this->assertStringContainsString( '2 elements were rendered on this site and kept as static HTML', $html );
        $this->assertStringContainsString( 'carried as custom CSS', $html );
        $this->assertStringContainsString( 'attachment 412', $html );
        $this->assertStringContainsString( '2 runs of text', $html );
        $this->assertStringContainsString( '3 bracketed', $html );
    }

    public function test_the_report_explains_a_page_that_cannot_convert(): void {
        $plan = new ConversionPlan( [
            ConversionPlan::item( [ 'title' => 'Empty', 'error' => 'No WPBakery layout found on that page.' ] ),
        ] );

        $html = ( new DirectConversionPage() )->render_report( $plan );

        $this->assertStringContainsString( 'No WPBakery layout found on that page.', $html );
        $this->assertStringNotContainsString( 'Convert to Divi 5', $html );
    }

    public function test_the_report_names_what_could_not_be_converted_at_all(): void {
        $html = ( new DirectConversionPage() )->render_report(
            $this->plan( [], 'direct', [ 'unsupported' => [ [ 'id' => 'vc_pie-2', 'tag' => 'vc_pie' ] ] ] )
        );

        $this->assertStringContainsString( 'Could not be converted', $html );
        $this->assertStringContainsString( 'vc_pie', $html );
    }

    // --- the picker ---------------------------------------------------------------------

    public function test_the_picker_lists_rows_with_their_badges(): void {
        $rows = [
            (object) [ 'ID' => 11, 'post_title' => 'Home', 'post_type' => 'page', 'post_status' => 'publish', 'post_modified' => '2026-09-01 00:00:00', 'post_content' => '[vc_row][/vc_row]' ],
            (object) [ 'ID' => 12, 'post_title' => 'About', 'post_type' => 'page', 'post_status' => 'draft', 'post_modified' => '2026-09-02 00:00:00', 'post_content' => '[vc_row][/vc_row]' ],
        ];
        $GLOBALS['__test_posts'][11] = $rows[0];
        $GLOBALS['__test_posts'][12] = $rows[1];
        update_post_meta( 11, '_wpb_vc_js_status', 'true' );
        $copy = wp_insert_post( [ 'post_type' => 'page', 'post_title' => 'Copy' ] );
        update_post_meta( $copy, '_wbdc_source_post_id', 11 );

        $page = new DirectConversionPage( new WPBakeryPageRepository( fn(): array => $rows ) );
        $html = $page->render_picker( [ 'search' => 'ho', 'paged' => 1 ] );

        $this->assertStringContainsString( 'type="checkbox" name="wbdc_post_ids[]" value="11"', $html, 'any number of pages can be picked' );
        $this->assertStringNotContainsString( 'type="radio"', $html );
        $this->assertStringContainsString( 'already converted', $html );
        $this->assertStringContainsString( 'WPBakery flag missing', $html );
        $this->assertStringContainsString( 'name="wbdc_s"', $html );
        $this->assertStringContainsString( 'Check selected pages', $html );
    }

    public function test_the_picker_says_so_when_the_site_holds_no_wpbakery_pages(): void {
        $page = new DirectConversionPage( new WPBakeryPageRepository( fn(): array => [] ) );

        // The landing page renders the picker unconditionally and lets this
        // empty state answer, rather than running the content LIKE twice.
        $this->assertStringContainsString( 'No WPBakery pages', $page->render_picker() );
    }
}
