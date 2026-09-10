<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Conversion\ConversionCommitter;
use WPBakeryDivi5Converter\Conversion\ConversionOutline;
use WPBakeryDivi5Converter\Conversion\ConversionPlan;
use WPBakeryDivi5Converter\Conversion\ConversionPreflight;
use WPBakeryDivi5Converter\Conversion\ConversionSource;
use WPBakeryDivi5Converter\Conversion\InstalledPostSource;

/** A source that hands over items somebody already built, for the tests that are about the pipeline. */
final class FakeWPBakerySource implements ConversionSource {

    /** @param array[] $items */
    public function __construct( private array $items ) {}

    public function items(): array {
        return $this->items;
    }
}

/**
 * Source → preflight → plan → commit.
 *
 * The whole point of the split is that the first three write nothing: a
 * reader can ask "what would this do" and be told, in full, without a post
 * being created and without the WPBakery original being touched. Only
 * `ConversionCommitter` writes, and what it writes is always a new post.
 */
final class ConversionPipelineTest extends TestCase {

    protected function setUp(): void {
        wbdc_test_reset_hooks();
        $GLOBALS['__test_posts']    = [];
        $GLOBALS['__test_postmeta'] = [];
    }

    private const CONTENT = '[vc_row][vc_column][vc_custom_heading text="Welcome" font_container="tag:h2|text_align:left"]'
        . '[vc_column_text]Hello there.[/vc_column_text][/vc_column][/vc_row]';

    /** @param array<string,mixed> $extra */
    private function item( string $title = 'Home', array $extra = [] ): array {
        return array_merge( [
            'title'         => $title,
            'post_type'     => 'page',
            'post_name'     => 'home',
            'template_type' => '',
            'content'       => self::CONTENT,
            'meta'          => [],
            'attachments'   => [],
            'mode'          => 'direct',
            'error'         => '',
            'source_ref'    => [ 'kind' => 'installed', 'post_id' => 7, 'file' => null ],
        ], $extra );
    }

    private function seed( int $id, string $title = 'Home' ): void {
        $GLOBALS['__test_posts'][ $id ] = (object) [
            'ID'           => $id,
            'post_title'   => $title,
            'post_name'    => 'home',
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_content' => self::CONTENT,
        ];
        update_post_meta( $id, '_wpb_vc_js_status', 'true' );
    }

    // --- preflight -------------------------------------------------------------

    public function test_preflight_turns_an_item_into_blocks_content_report_and_outline(): void {
        $plan = ( new ConversionPreflight() )->run( new FakeWPBakerySource( [ $this->item() ] ) );
        $item = $plan->items()[0];

        $this->assertInstanceOf( ConversionPlan::class, $plan );
        $this->assertSame( 'Home', $item['title'] );
        $this->assertSame( '', $item['error'] );
        $this->assertSame( 'divi/section', $item['blocks']['elements'][0]['name'] );
        $this->assertStringContainsString( 'wp:divi/heading', $item['content'] );
        $this->assertSame( 1, $item['report']['converted']['heading'] );
        $this->assertSame( 'section', $item['outline'][0]['type'] );
        $this->assertSame( 7, $item['source_ref']['post_id'] );
    }

    /** The mode travels with the item, so the report screen can say "rendered here" or "from an export". */
    public function test_the_plan_records_which_mode_the_item_was_converted_in(): void {
        $direct = ( new ConversionPreflight() )->run( new FakeWPBakerySource( [ $this->item() ] ) );
        $import = ( new ConversionPreflight() )->run( new FakeWPBakerySource( [ $this->item( 'Up', [ 'mode' => 'import' ] ) ] ) );

        $this->assertSame( 'direct', $direct->items()[0]['mode'] );
        $this->assertSame( 'import', $import->items()[0]['mode'] );
    }

    /**
     * On this site a theme's shortcode renders into a static copy; from an
     * export there is nothing to render with and it stays a placeholder. Both
     * are reported, and the report is what tells them apart
     * (task-11-amendments §4).
     */
    public function test_a_theme_element_is_a_static_copy_in_direct_mode_and_a_placeholder_from_an_export(): void {
        $GLOBALS['__test_rendered_shortcodes'] = [ 'dfd_carousel' => '<div class="dfd">rendered</div>' ];

        $content = '[vc_row][vc_column][dfd_carousel][/vc_column][/vc_row]';

        $direct = ( new ConversionPreflight() )->run( new FakeWPBakerySource( [ $this->item( 'D', [ 'content' => $content ] ) ] ) );
        $import = ( new ConversionPreflight() )->run(
            new FakeWPBakerySource( [ $this->item( 'I', [ 'content' => $content, 'mode' => 'import' ] ) ] )
        );

        $this->assertCount( 1, $direct->items()[0]['report']['static_copies'] );
        $this->assertSame( [], $import->items()[0]['report']['static_copies'] );
        $this->assertSame( [ 'Ronneby' => 1 ], $import->items()[0]['report']['theme_elements'] );
    }

    public function test_preflight_caps_at_the_free_limit_and_reports_truncation(): void {
        $plan = ( new ConversionPreflight() )->run( new FakeWPBakerySource( [ $this->item( 'One' ), $this->item( 'Two' ) ] ) );

        $this->assertSame( 1, $plan->count() );
        $this->assertTrue( $plan->truncated() );

        add_filter( ConversionPreflight::LIMIT_FILTER, fn() => 10 );
        $plan = ( new ConversionPreflight() )->run( new FakeWPBakerySource( [ $this->item( 'One' ), $this->item( 'Two' ) ] ) );

        $this->assertSame( 2, $plan->count() );
        $this->assertFalse( $plan->truncated() );
    }

    public function test_run_unlimited_ignores_the_cap(): void {
        $plan = ( new ConversionPreflight() )->runUnlimited(
            new FakeWPBakerySource( [ $this->item( 'One' ), $this->item( 'Two' ), $this->item( 'Three' ) ] )
        );

        $this->assertSame( 3, $plan->count() );
        $this->assertFalse( $plan->truncated() );
    }

    public function test_each_item_gets_a_fresh_report(): void {
        add_filter( ConversionPreflight::LIMIT_FILTER, fn() => 2 );

        $plan = ( new ConversionPreflight() )->run( new FakeWPBakerySource( [ $this->item( 'One' ), $this->item( 'Two' ) ] ) );

        $this->assertSame( 1, $plan->items()[1]['report']['converted']['heading'], 'counts must not accumulate across items' );
    }

    public function test_a_source_error_is_carried_through_and_nothing_is_converted(): void {
        $plan = ( new ConversionPreflight() )->run( new FakeWPBakerySource( [
            $this->item( 'Gone', [ 'error' => 'That page no longer exists.', 'content' => '' ] ),
        ] ) );

        $this->assertTrue( $plan->hasFailures() );
        $this->assertSame( 'That page no longer exists.', $plan->items()[0]['error'] );
        $this->assertSame( [], $plan->items()[0]['blocks'] );
    }

    public function test_preflight_writes_nothing(): void {
        $this->seed( 900 );
        $posts_before = array_map( static fn( $p ): array => (array) $p, $GLOBALS['__test_posts'] );
        $meta_before  = $GLOBALS['__test_postmeta'];

        ( new ConversionPreflight() )->run( new InstalledPostSource( [ 900 ] ) );

        $this->assertSame( $posts_before, array_map( static fn( $p ): array => (array) $p, $GLOBALS['__test_posts'] ) );
        $this->assertSame( $meta_before, $GLOBALS['__test_postmeta'] );
    }

    // --- committer -------------------------------------------------------------

    public function test_commit_creates_a_new_draft_with_divi_content_and_source_stamps(): void {
        $plan    = ( new ConversionPreflight() )->run( new FakeWPBakerySource( [ $this->item() ] ) );
        $results = ( new ConversionCommitter() )->commit( $plan, [ 'post_status' => 'draft' ] );

        $this->assertTrue( $results[0]['success'] );

        $post = get_post( $results[0]['post_id'] );
        $this->assertSame( 'draft', $post->post_status );
        $this->assertSame( 'page', $post->post_type );
        $this->assertStringContainsString( 'wp:divi/heading', $post->post_content );
        $this->assertSame( 'on', get_post_meta( $post->ID, '_et_pb_use_divi_5', true ) );
        $this->assertSame( 'direct', get_post_meta( $post->ID, '_wbdc_import_source', true ) );
        $this->assertSame( 7, get_post_meta( $post->ID, '_wbdc_source_post_id', true ) );
    }

    /**
     * `wp_update_post()` unslashes what it is given, so the JSON escapes in a
     * block's attributes have to be slashed on the way in or the page renders
     * `u003Cp` where a paragraph should be.
     */
    public function test_the_content_reaches_wp_update_post_slashed(): void {
        $plan = ( new ConversionPreflight() )->run( new FakeWPBakerySource( [ $this->item() ] ) );
        $raw  = $plan->items()[0]['content'];

        $results = ( new ConversionCommitter() )->commit( $plan );
        $post    = get_post( $results[0]['post_id'] );

        $this->assertSame( wp_slash( $raw ), $post->post_content );
        $this->assertSame( $raw, wp_unslash( $post->post_content ) );
    }

    /**
     * The title takes the same route as the content: `wp_insert_post()`
     * unslashes what it is given, so a page called `Before \ After` arrives
     * called `Before  After` unless it is slashed on the way in.
     */
    public function test_the_title_reaches_wp_insert_post_slashed(): void {
        $plan = ( new ConversionPreflight() )->run( new FakeWPBakerySource( [ $this->item( 'Before \\ After' ) ] ) );

        $results = ( new ConversionCommitter() )->commit( $plan );
        $post    = get_post( $results[0]['post_id'] );

        $this->assertSame( 'Before \\\\ After', $post->post_title );
        $this->assertSame( 'Before \\ After', wp_unslash( $post->post_title ) );
        // The result row still reports the title as the reader typed it.
        $this->assertSame( 'Before \\ After', $results[0]['title'] );
    }

    public function test_commit_never_touches_the_source_post(): void {
        $this->seed( 20 );
        $before = (array) get_post( 20 );

        $plan = ( new ConversionPreflight() )->run( new InstalledPostSource( [ 20 ] ) );
        ( new ConversionCommitter() )->commit( $plan );

        $this->assertSame( $before, (array) get_post( 20 ) );
    }

    public function test_commit_keeps_a_failed_item_as_a_failure(): void {
        $plan = ( new ConversionPreflight() )->run( new FakeWPBakerySource( [ $this->item( 'Broken', [ 'error' => 'nope' ] ) ] ) );

        $results = ( new ConversionCommitter() )->commit( $plan );

        $this->assertFalse( $results[0]['success'] );
        $this->assertSame( 'nope', $results[0]['error'] );
        $this->assertSame( 0, $results[0]['post_id'] );
        $this->assertSame( [], $GLOBALS['__test_posts'], 'a failure creates no post' );
    }

    public function test_an_uploaded_item_is_stamped_as_a_file_upload_with_no_source_post(): void {
        $plan = ( new ConversionPreflight() )->run( new FakeWPBakerySource( [
            $this->item( 'From a file', [ 'mode' => 'import', 'source_ref' => [ 'kind' => 'upload', 'post_id' => null, 'file' => 'export.xml' ] ] ),
        ] ) );

        $results = ( new ConversionCommitter() )->commit( $plan );

        $this->assertSame( 'file_upload', get_post_meta( $results[0]['post_id'], '_wbdc_import_source', true ) );
        $this->assertSame( '', get_post_meta( $results[0]['post_id'], '_wbdc_source_post_id', true ) );
    }

    // --- the library path ---------------------------------------------------------

    /** The wording the free plugin owes a reader whose template became a page. */
    private const LIBRARY_WARNING = 'WPBakery template imported as a page (Pro turns templates into Divi Library layouts)';

    public function test_a_template_without_pro_becomes_a_page_draft_with_a_warning(): void {
        $plan    = ( new ConversionPreflight() )->run( new FakeWPBakerySource( [ $this->item( 'Saved Row', [ 'template_type' => 'library' ] ) ] ) );
        $results = ( new ConversionCommitter() )->commit( $plan );

        $this->assertTrue( $results[0]['success'] );
        $this->assertSame( 'page', get_post( $results[0]['post_id'] )->post_type );
        $this->assertSame( 'library', $results[0]['template_type'] );
        $this->assertSame( self::LIBRARY_WARNING, end( $results[0]['report']['warnings'] ) );
    }

    public function test_a_template_goes_through_the_pro_exporter_when_one_is_registered(): void {
        $exporter = new class {
            /** @var array[] */
            public array $calls = [];

            public function export( array $item, array $divi_data ): int {
                $this->calls[] = [ $item['title'], $item['source_ref'], array_keys( $divi_data ) ];

                return 4242;
            }
        };

        add_filter( 'wbdc_library_exporter', fn() => $exporter );

        $plan    = ( new ConversionPreflight() )->run( new FakeWPBakerySource( [ $this->item( 'Saved Row', [ 'template_type' => 'library' ] ) ] ) );
        $results = ( new ConversionCommitter() )->commit( $plan );

        $this->assertSame( 'Saved Row', $exporter->calls[0][0] );
        $this->assertSame( [ 'divi', 'report', 'unsupported' ], $exporter->calls[0][2] );
        $this->assertSame( 4242, $results[0]['post_id'] );
        $this->assertSame( 'library', $results[0]['template_type'] );
        $this->assertTrue( $results[0]['success'] );
        $this->assertSame( [], $GLOBALS['__test_posts'], 'the exporter owns the writing, not the committer' );
    }

    /**
     * The caller unticked "convert templates". Converting it into a page
     * anyway is the opposite of what was asked, and the result has to keep
     * saying it was a template.
     */
    public function test_a_template_the_caller_did_not_select_is_skipped_not_paged(): void {
        $plan    = ( new ConversionPreflight() )->run( new FakeWPBakerySource( [ $this->item( 'Saved Row', [ 'template_type' => 'library' ] ) ] ) );
        $results = ( new ConversionCommitter() )->commit( $plan, [ 'convert_templates' => false ] );

        $this->assertFalse( $results[0]['success'] );
        $this->assertTrue( $results[0]['skipped'] );
        $this->assertSame( 'library', $results[0]['template_type'] );
        $this->assertSame( 'WPBakery template not selected for conversion', $results[0]['error'] );
        $this->assertSame( 0, $results[0]['post_id'] );
        $this->assertSame( [], $GLOBALS['__test_posts'], 'nothing was written' );
    }

    /** An unselected template does not stop the pages beside it. */
    public function test_unselecting_templates_leaves_ordinary_pages_alone(): void {
        add_filter( ConversionPreflight::LIMIT_FILTER, fn(): int => 5 );

        $plan = ( new ConversionPreflight() )->run( new FakeWPBakerySource( [
            $this->item( 'Saved Row', [ 'template_type' => 'library' ] ),
            $this->item( 'Home' ),
        ] ) );

        $results = ( new ConversionCommitter() )->commit( $plan, [ 'convert_templates' => false ] );

        $this->assertTrue( $results[0]['skipped'] );
        $this->assertTrue( $results[1]['success'] );
        $this->assertSame( 'page', get_post( $results[1]['post_id'] )->post_type );
    }

    /** With the option left alone, a template is converted — the default is true. */
    public function test_templates_are_converted_when_the_option_is_not_given(): void {
        $plan    = ( new ConversionPreflight() )->run( new FakeWPBakerySource( [ $this->item( 'Saved Row', [ 'template_type' => 'library' ] ) ] ) );
        $results = ( new ConversionCommitter() )->commit( $plan, [ 'convert_templates' => true ] );

        $this->assertTrue( $results[0]['success'] );
        $this->assertArrayNotHasKey( 'skipped', $results[0] );
        $this->assertSame( self::LIBRARY_WARNING, end( $results[0]['report']['warnings'] ) );
    }

    /**
     * The filter is a public contract Task 13's Pro exporter hooks, and it is
     * asked per item: an exporter that cannot take this one returns null and
     * the free path takes over.
     */
    public function test_the_library_filter_is_given_the_item_and_the_commit_options(): void {
        $seen = [];

        add_filter( 'wbdc_library_exporter', function ( $exporter, $item = [], $options = [] ) use ( &$seen ) {
            $seen[] = [ $item['title'] ?? '', $item['template_type'] ?? '', $options['post_status'] ?? '' ];

            return null;
        }, 10, 3 );

        $plan = ( new ConversionPreflight() )->run( new FakeWPBakerySource( [ $this->item( 'Saved Row', [ 'template_type' => 'library' ] ) ] ) );
        ( new ConversionCommitter() )->commit( $plan, [ 'post_status' => 'publish' ] );

        $this->assertSame( [ [ 'Saved Row', 'library', 'publish' ] ], $seen );
    }

    public function test_an_exporter_that_fails_is_reported_rather_than_thrown(): void {
        add_filter( 'wbdc_library_exporter', fn() => new class {
            public function export( array $item, array $divi_data ): int {
                throw new \RuntimeException( 'the library is read-only' );
            }
        } );

        $plan    = ( new ConversionPreflight() )->run( new FakeWPBakerySource( [ $this->item( 'Saved Row', [ 'template_type' => 'library' ] ) ] ) );
        $results = ( new ConversionCommitter() )->commit( $plan );

        $this->assertFalse( $results[0]['success'] );
        $this->assertStringContainsString( 'the library is read-only', $results[0]['error'] );
    }

    // --- outline ---------------------------------------------------------------

    public function test_outline_labels_structure_and_flags_placeholders(): void {
        $outline = ConversionOutline::build( [ [ 'name' => 'divi/section', 'elements' => [ [ 'name' => 'divi/row', 'elements' => [ [ 'name' => 'divi/column', 'elements' => [
            [ 'name' => 'divi/number-counter', 'settings' => [] ],
            [ 'name' => 'divi/code', 'settings' => [ 'content' => [ 'innerContent' => [ 'desktop' => [ 'value' => '<!-- wpbakery element: dfd_carousel (kept as a placeholder) -->' ] ] ] ] ],
            [ 'name' => 'divi/code', 'settings' => [ 'content' => [ 'innerContent' => [ 'desktop' => [ 'value' => '<iframe></iframe>' ] ] ] ] ],
        ] ] ] ] ] ] ] );

        $modules = $outline[0]['children'][0]['children'][0]['children'];

        $this->assertSame( 'Number Counter', $modules[0]['label'] );
        $this->assertFalse( $modules[0]['placeholder'] );
        $this->assertTrue( $modules[1]['placeholder'] );
        $this->assertFalse( $modules[2]['placeholder'] );
        $this->assertSame( 'column', $outline[0]['children'][0]['children'][0]['type'] );
    }

    public function test_the_outline_of_a_converted_page_follows_its_structure(): void {
        $plan    = ( new ConversionPreflight() )->run( new FakeWPBakerySource( [ $this->item() ] ) );
        $outline = $plan->items()[0]['outline'];

        $this->assertSame( 'section', $outline[0]['type'] );
        $this->assertSame( 'row', $outline[0]['children'][0]['type'] );
        $this->assertSame( 'column', $outline[0]['children'][0]['children'][0]['type'] );
        $this->assertSame(
            [ 'Heading', 'Text' ],
            array_column( $outline[0]['children'][0]['children'][0]['children'], 'label' )
        );
    }
}
