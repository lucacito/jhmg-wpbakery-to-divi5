<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Converter\ConverterEngine;

/**
 * The composite, container, widget and add-on handlers (Task 9), for the
 * behaviour fixture equality cannot pin down: what lands in the report, which
 * Divi module a WPBakery element resolves to, the two conversions that have to
 * agree with each other (a legacy `vc_accordion` and its `vc_tta_accordion`
 * equivalent), and the promise that nothing this batch touches is a skipped
 * setting.
 *
 * The *shape* of the output is pinned by the goldens in fixtures/divi/.
 */
final class ContentHandlersBTest extends TestCase {

    protected function setUp(): void {
        wbdc_test_reset_hooks();
    }

    /** @param array{mode?: string, attachments?: array, render_shortcodes?: bool} $options */
    private function convert( string $content, array $options = [] ): array {
        return ( new ConverterEngine() )->convert(
            [ 'content' => $content ],
            array_merge( [ 'mode' => 'import' ], $options )
        );
    }

    private function row( string $inner ): string {
        return '[vc_row][vc_column]' . $inner . '[/vc_column][/vc_row]';
    }

    /** The first block of the first column of the first row. */
    private function firstModule( array $result ): array {
        return $result['divi']['elements'][0]['elements'][0]['elements'][0]['elements'][0];
    }

    /** Every block of the first column. */
    private function modules( array $result ): array {
        return $result['divi']['elements'][0]['elements'][0]['elements'][0]['elements'];
    }

    private function read( array $settings, string $path ): mixed {
        $current = $settings;
        foreach ( explode( '.', $path ) as $key ) {
            if ( ! is_array( $current ) || ! array_key_exists( $key, $current ) ) {
                return null;
            }
            $current = $current[ $key ];
        }

        return $current;
    }

    private function details( array $result ): string {
        return implode( "\n", array_column( $result['report']['not_carried_over'], 'detail' ) );
    }

    // -------------------------------------------------------------------------
    // Every tag in this batch has a handler
    // -------------------------------------------------------------------------

    /** @return array<string, string> tag ⇒ one instance of it */
    private static function batch(): array {
        return [
            'vc_tta_accordion'  => '[vc_tta_accordion][vc_tta_section title="A"][vc_column_text]A[/vc_column_text][/vc_tta_section][/vc_tta_accordion]',
            'vc_tta_tabs'       => '[vc_tta_tabs][vc_tta_section title="A"][vc_column_text]A[/vc_column_text][/vc_tta_section][/vc_tta_tabs]',
            'vc_tta_tour'       => '[vc_tta_tour][vc_tta_section title="A"][vc_column_text]A[/vc_column_text][/vc_tta_section][/vc_tta_tour]',
            'vc_tta_pageable'   => '[vc_tta_pageable][vc_tta_section title="A"][vc_column_text]A[/vc_column_text][/vc_tta_section][/vc_tta_pageable]',
            'vc_tta_toggle'     => '[vc_tta_toggle][vc_tta_toggle_section title="A"][vc_column_text]A[/vc_column_text][/vc_tta_toggle_section][/vc_tta_toggle]',
            'vc_accordion'      => '[vc_accordion][vc_accordion_tab title="A"][vc_column_text]A[/vc_column_text][/vc_accordion_tab][/vc_accordion]',
            'vc_tabs'           => '[vc_tabs][vc_tab title="A"][vc_column_text]A[/vc_column_text][/vc_tab][/vc_tabs]',
        ];
    }

    public function test_no_element_in_this_batch_is_unsupported_any_more(): void {
        $tags = self::batch();

        $result = $this->convert( $this->row( implode( '', $tags ) ) );

        $this->assertSame( [], $result['unsupported'], 'every tag in batch B has a handler' );
    }

    public function test_no_element_in_this_batch_reports_a_skipped_setting(): void {
        foreach ( self::batch() as $tag => $shortcode ) {
            wbdc_test_reset_hooks();
            $result = $this->convert( $this->row( $shortcode ) );

            $this->assertSame(
                [],
                $result['report']['skipped_settings'],
                $tag . ' left a skipped setting behind'
            );
        }
    }

    // -------------------------------------------------------------------------
    // Accordions, tabs, tours
    // -------------------------------------------------------------------------

    public function test_an_accordion_becomes_an_accordion_with_one_item_per_section(): void {
        $result = $this->convert( $this->row(
            '[vc_tta_accordion][vc_tta_section title="One"][vc_column_text]First[/vc_column_text][/vc_tta_section]'
            . '[vc_tta_section title="Two"][vc_column_text]Second[/vc_column_text][/vc_tta_section][/vc_tta_accordion]'
        ) );

        $accordion = $this->firstModule( $result );

        $this->assertSame( 'divi/accordion', $accordion['name'] );
        $this->assertCount( 2, $accordion['elements'] );
        $this->assertSame( 'divi/accordion-item', $accordion['elements'][0]['name'] );
        $this->assertSame( 'One', $this->read( $accordion['elements'][0]['settings'], 'title.innerContent.desktop.value' ) );
        $this->assertStringContainsString( 'Second', (string) $this->read( $accordion['elements'][1]['settings'], 'content.innerContent.desktop.value' ) );
    }

    public function test_active_section_two_is_reported_because_divi_always_opens_the_first_panel(): void {
        $result = $this->convert( $this->row(
            '[vc_tta_accordion active_section="2"][vc_tta_section title="One"][vc_column_text]A[/vc_column_text][/vc_tta_section]'
            . '[vc_tta_section title="Two"][vc_column_text]B[/vc_column_text][/vc_tta_section][/vc_tta_accordion]'
        ) );

        $this->assertStringContainsString( 'active_section="2"', $this->details( $result ) );
        $this->assertStringContainsString( 'Divi 5.12 always opens the first one', $this->details( $result ) );
    }

    public function test_a_legacy_accordion_converts_to_the_same_blocks_as_its_tta_equivalent(): void {
        $legacy = $this->convert( $this->row(
            '[vc_accordion collapsible="true" active_tab="2"][vc_accordion_tab title="One"][vc_column_text]A[/vc_column_text][/vc_accordion_tab][/vc_accordion]'
        ) );

        wbdc_test_reset_hooks();

        $modern = $this->convert( $this->row(
            '[vc_tta_accordion collapsible_all="true" active_section="2"][vc_tta_section title="One"][vc_column_text]A[/vc_column_text][/vc_tta_section][/vc_tta_accordion]'
        ) );

        $this->assertSame(
            self::withoutIds( $this->firstModule( $legacy ) ),
            self::withoutIds( $this->firstModule( $modern ) )
        );
        $this->assertSame( [], $legacy['report']['skipped_settings'] );
    }

    public function test_a_tour_is_a_tabs_module_and_says_so(): void {
        $result = $this->convert( $this->row(
            '[vc_tta_tour][vc_tta_section title="One"][vc_column_text]A[/vc_column_text][/vc_tta_section][/vc_tta_tour]'
        ) );

        $this->assertSame( 'divi/tabs', $this->firstModule( $result )['name'] );
        $this->assertSame( 'divi/tab', $this->firstModule( $result )['elements'][0]['name'] );
        $this->assertStringContainsString( 'stacks its controls down one side', $this->details( $result ) );
    }

    public function test_the_tta_colour_scheme_lands_on_the_open_and_closed_toggles(): void {
        $result = $this->convert( $this->row(
            '[vc_tta_accordion color="grey" active_color="#5472d2" active_title_color="#ffffff" inactive_title_color="#2a2a2a"]'
            . '[vc_tta_section title="One"][vc_column_text]A[/vc_column_text][/vc_tta_section][/vc_tta_accordion]'
        ) );

        $settings = $this->firstModule( $result )['settings'];

        $this->assertSame( '#ebebeb', $this->read( $settings, 'closedToggle.decoration.background.desktop.value.color' ) );
        $this->assertSame( '#5472d2', $this->read( $settings, 'openToggle.decoration.background.desktop.value.color' ) );
        $this->assertSame( '#ffffff', $this->read( $settings, 'openToggle.decoration.font.font.desktop.value.color' ) );
        $this->assertSame( '#2a2a2a', $this->read( $settings, 'closedToggle.decoration.font.font.desktop.value.color' ) );
    }

    public function test_a_tta_element_takes_its_own_bottom_margin_not_the_content_one(): void {
        $result = $this->convert( $this->row(
            '[vc_tta_accordion][vc_tta_section title="One"][vc_column_text]A[/vc_column_text][/vc_tta_section][/vc_tta_accordion]'
        ) );

        // js_composer_tta.min.css: .vc_tta-container{margin-bottom:21.73913043px}
        $this->assertSame(
            '21.74px',
            $this->read( $this->firstModule( $result )['settings'], 'module.decoration.spacing.desktop.value.margin.bottom' )
        );
    }

    /** Ids are generated from the source position, so two equivalent pages differ only there. */
    private static function withoutIds( array $block ): array {
        unset( $block['id'] );

        foreach ( $block['elements'] ?? [] as $index => $child ) {
            $block['elements'][ $index ] = self::withoutIds( $child );
        }

        return $block;
    }
}
