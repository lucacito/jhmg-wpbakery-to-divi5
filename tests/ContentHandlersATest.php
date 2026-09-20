<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Converter\ConverterEngine;

/**
 * The single-block element handlers (Task 8), for the behaviour fixture
 * equality cannot pin down: what lands in the report, what happens when a
 * medium cannot be resolved, which dynamic-content token is written, and the
 * two conversions that have to agree with each other (a legacy `vc_button` and
 * its `vc_btn` equivalent).
 *
 * Everything about the *shape* of the output is pinned by the goldens in
 * fixtures/divi/; this file is about the things a golden does not carry.
 */
final class ContentHandlersATest extends TestCase {

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

    // -------------------------------------------------------------------------
    // Every tag in this batch has a handler
    // -------------------------------------------------------------------------

    public function test_no_element_in_this_batch_is_unsupported_any_more(): void {
        $tags = [
            '[vc_column_text]Hi[/vc_column_text]',
            '[vc_custom_heading text="Hi"]',
            '[vc_single_image image="7"]',
            '[vc_btn title="Hi"]',
            '[vc_icon icon_fontawesome="fas fa-star"]',
            '[vc_separator]',
            '[vc_zigzag]',
            '[vc_text_separator title="Hi"]',
            '[vc_empty_space height="10px"]',
            '[vc_message]Hi[/vc_message]',
            '[vc_toggle title="Hi"]Body[/vc_toggle]',
            '[vc_copyright]',
            '[vc_raw_html]SGk=[/vc_raw_html]',
            '[vc_raw_js]SGk=[/vc_raw_js]',
            '[vc_gmaps link="http://maps.google.com/?q=x"]',
            '[vc_goo_maps location="London"]',
            '[vc_video link="https://youtu.be/abc"]',
            '[vc_gutenberg]<p>Hi</p>[/vc_gutenberg]',
            '[vc_custom_field field_key="k"]',
        ];

        foreach ( $tags as $shortcode ) {
            $result = $this->convert( $this->row( $shortcode ) );
            $this->assertSame( [], $result['unsupported'], "still unsupported: {$shortcode}" );
        }
    }

    // -------------------------------------------------------------------------
    // vc_column_text
    // -------------------------------------------------------------------------

    public function test_column_text_runs_content_through_wpautop(): void {
        $result = $this->convert( $this->row( '[vc_column_text]One line

Another line[/vc_column_text]' ) );
        $module = $this->firstModule( $result );

        $this->assertSame( 'divi/text', $module['name'] );
        $this->assertSame(
            "<p>One line</p>\n<p>Another line</p>",
            trim( (string) $this->read( $module['settings'], 'content.innerContent.desktop.value' ) )
        );
    }

    public function test_column_text_carries_wpbakerys_default_bottom_margin(): void {
        $result = $this->convert( $this->row( '[vc_column_text]Hi[/vc_column_text]' ) );

        $this->assertSame(
            '35px',
            $this->read( $this->firstModule( $result )['settings'], 'module.decoration.spacing.desktop.value.margin.bottom' )
        );
    }

    public function test_a_theme_shortcode_inside_text_is_reported_per_family_on_import(): void {
        $result = $this->convert( $this->row( '[vc_column_text]<p>Before</p>[nectar_btn text="Go"]<p>After</p>[/vc_column_text]' ) );

        $this->assertSame( [ 'Salient' => 1 ], $result['report']['theme_elements'] );
        $kinds = array_column( $result['report']['not_carried_over'], 'kind' );
        $this->assertContains( 'addon', $kinds );
        $this->assertStringContainsString(
            '[nectar_btn',
            (string) $this->read( $this->firstModule( $result )['settings'], 'content.innerContent.desktop.value' ),
            'an unrendered shortcode has to survive as text'
        );
    }

    public function test_a_theme_shortcode_inside_text_is_rendered_in_direct_mode(): void {
        $GLOBALS['__test_rendered_shortcodes'] = [ 'nectar_btn' => '<a class="nectar-button">Go</a>' ];

        $result = $this->convert(
            $this->row( '[vc_column_text]<p>Before</p>[nectar_btn text="Go"]<p>After</p>[/vc_column_text]' ),
            [ 'mode' => 'direct', 'render_shortcodes' => true ]
        );
        $html = (string) $this->read( $this->firstModule( $result )['settings'], 'content.innerContent.desktop.value' );

        $this->assertStringContainsString( '<a class="nectar-button">Go</a>', $html );
        $this->assertStringContainsString( 'Before', $html, 'the surrounding copy has to survive' );
        $this->assertStringContainsString( 'After', $html );
        $this->assertNotEmpty( $result['report']['static_copies'] );
    }

    public function test_an_unregistered_shortcode_inside_text_is_not_mistaken_for_a_render(): void {
        // do_shortcode() on a tag nothing registered returns its input
        // untouched (WordPress builds its shortcode regex from registered
        // tags only) — a non-empty result that a bare "did it come back
        // empty?" check would misread as a render. Nothing seeds
        // __test_rendered_shortcodes here, so shortcode_exists('nectar_btn')
        // is false and the tag has to fall back to the unrendered branch
        // even though the bootstrap's do_shortcode() stub would otherwise
        // fabricate output for it (task-8-fix-round-1.md, #7).
        $result = $this->convert(
            $this->row( '[vc_column_text]<p>Before</p>[nectar_btn text="Go"]<p>After</p>[/vc_column_text]' ),
            [ 'mode' => 'direct', 'render_shortcodes' => true ]
        );

        $this->assertSame( [], $result['report']['static_copies'] );
        $this->assertStringContainsString(
            '[nectar_btn',
            (string) $this->read( $this->firstModule( $result )['settings'], 'content.innerContent.desktop.value' ),
            'an unregistered shortcode has to survive as text, not do_shortcode()\'s pass-through mistaken for a static copy'
        );
    }

    public function test_a_bare_bracket_token_inside_text_is_prose(): void {
        $result = $this->convert( $this->row( '[vc_column_text]<p>See note [1] below.</p>[/vc_column_text]' ) );

        $this->assertSame( [], $result['report']['theme_elements'] );
        $this->assertStringContainsString(
            '[1]',
            (string) $this->read( $this->firstModule( $result )['settings'], 'content.innerContent.desktop.value' )
        );
    }

    public function test_a_paragraph_holding_only_a_bracket_token_keeps_its_p(): void {
        // `shortcode_unautop()` unwraps a paragraph that holds one shortcode;
        // `[1]` is not one, so WordPress leaves the paragraph alone.
        $result = $this->convert( $this->row( '[vc_column_text]<p>[1]</p>[/vc_column_text]' ) );

        $this->assertSame(
            '<p>[1]</p>',
            trim( (string) $this->read( $this->firstModule( $result )['settings'], 'content.innerContent.desktop.value' ) )
        );
    }

    // -------------------------------------------------------------------------
    // vc_custom_heading
    // -------------------------------------------------------------------------

    public function test_post_title_source_becomes_a_dynamic_content_token(): void {
        $result = $this->convert( $this->row( '[vc_custom_heading source="post_title" font_container="tag:h1"]' ) );
        $module = $this->firstModule( $result );

        $this->assertSame( 'divi/heading', $module['name'] );
        $this->assertSame(
            '$variable({"type":"content","value":{"name":"post_title","settings":{}}})$',
            $this->read( $module['settings'], 'title.innerContent.desktop.value' )
        );
        $this->assertSame( 'h1', $this->read( $module['settings'], 'title.decoration.font.font.desktop.value.headingLevel' ) );
    }

    public function test_theme_fonts_win_over_the_google_font(): void {
        $shortcode = '[vc_custom_heading text="Hi" use_theme_fonts="yes" google_fonts="font_family:Handlee%3Aregular|font_style:400%20regular%3A400%3Anormal"]';

        $result = $this->convert( $this->row( $shortcode ) );

        $this->assertNull( $this->read( $this->firstModule( $result )['settings'], 'title.decoration.font.font.desktop.value.family' ) );
    }

    public function test_a_google_font_becomes_family_weight_and_style(): void {
        $shortcode = '[vc_custom_heading text="Hi" google_fonts="font_family:Handlee%3Aregular|font_style:700%20bold%20regular%3A700%3Aitalic"]';

        $settings = $this->firstModule( $this->convert( $this->row( $shortcode ) ) )['settings'];

        $this->assertSame( 'Handlee', $this->read( $settings, 'title.decoration.font.font.desktop.value.family' ) );
        $this->assertSame( '700', $this->read( $settings, 'title.decoration.font.font.desktop.value.weight' ) );
        $this->assertSame( [ 'italic' ], $this->read( $settings, 'title.decoration.font.font.desktop.value.style' ) );
    }

    public function test_a_heading_link_lands_on_the_module_link(): void {
        $shortcode = '[vc_custom_heading text="Hi" link="url:https%3A%2F%2Fexample.com|target:%20_blank"]';

        $settings = $this->firstModule( $this->convert( $this->row( $shortcode ) ) )['settings'];

        $this->assertSame( 'https://example.com', $this->read( $settings, 'module.advanced.link.desktop.value.url' ) );
        $this->assertSame( 'on', $this->read( $settings, 'module.advanced.link.desktop.value.target' ) );
    }

    // -------------------------------------------------------------------------
    // vc_single_image
    // -------------------------------------------------------------------------

    public function test_an_attachment_with_no_url_is_reported_and_left_empty(): void {
        $result = $this->convert( $this->row( '[vc_single_image image="4242" img_size="large"]' ) );
        $module = $this->firstModule( $result );

        $this->assertSame( 'divi/image', $module['name'] );
        $this->assertSame( [ [ 'node_id' => 'vc_single_image-1', 'attachment_id' => 4242 ] ], $result['report']['unresolved_media'] );
        $this->assertSame( '', (string) $this->read( $module['settings'], 'image.innerContent.desktop.value.src' ) );
        $this->assertNotEmpty( $result['report']['warnings'] );
    }

    public function test_a_numeric_image_size_forces_fullwidth_so_the_img_matches_wpbakery(): void {
        $result = $this->convert(
            $this->row( '[vc_single_image image="7" img_size="400x300"]' ),
            [ 'attachments' => [ 7 => 'https://example.com/a.jpg' ] ]
        );
        $settings = $this->firstModule( $result )['settings'];

        $this->assertSame( '400px', $this->read( $settings, 'module.advanced.sizing.desktop.value.width' ) );
        $this->assertSame( 'on', $this->read( $settings, 'module.advanced.sizing.desktop.value.forceFullwidth' ) );
    }

    public function test_a_named_image_size_writes_no_width(): void {
        $result = $this->convert(
            $this->row( '[vc_single_image image="7" img_size="large"]' ),
            [ 'attachments' => [ 7 => 'https://example.com/a.jpg' ] ]
        );
        $settings = $this->firstModule( $result )['settings'];

        $this->assertNull( $this->read( $settings, 'module.advanced.sizing.desktop.value.width' ) );
        $this->assertNull( $this->read( $settings, 'module.advanced.sizing.desktop.value.forceFullwidth' ) );
    }

    public function test_a_named_image_size_resolves_through_the_import_maps_sizes(): void {
        // task-8-fix-round-1.md, #8: attachments[id]['sizes'][size] is read
        // when the map entry is an array, so a named img_size resolves to the
        // size WPBakery actually rendered rather than the attachment's base URL.
        $result = $this->convert(
            $this->row( '[vc_single_image image="7" img_size="medium"]' ),
            [ 'attachments' => [ 7 => [
                'url'   => 'https://example.com/a.jpg',
                'sizes' => [ 'medium' => 'https://example.com/a-300x225.jpg', 'large' => 'https://example.com/a-1024x768.jpg' ],
            ] ] ]
        );
        $settings = $this->firstModule( $result )['settings'];

        $this->assertSame( 'https://example.com/a-300x225.jpg', $this->read( $settings, 'image.innerContent.desktop.value.src' ) );
        $this->assertSame( [], $result['report']['unresolved_media'] );
    }

    public function test_a_named_image_size_missing_from_the_sizes_map_falls_back_and_is_reported(): void {
        $result = $this->convert(
            $this->row( '[vc_single_image image="7" img_size="thumbnail"]' ),
            [ 'attachments' => [ 7 => [
                'url'   => 'https://example.com/a.jpg',
                'sizes' => [ 'large' => 'https://example.com/a-1024x768.jpg' ],
            ] ] ]
        );
        $settings = $this->firstModule( $result )['settings'];

        $this->assertSame( 'https://example.com/a.jpg', $this->read( $settings, 'image.innerContent.desktop.value.src' ) );
        $details = array_column( $result['report']['not_carried_over'], 'detail' );
        $this->assertNotEmpty( array_filter( $details, static fn( string $d ): bool => str_contains( $d, 'img_size="thumbnail"' ) ) );
    }

    public function test_a_lightbox_image_sets_the_lightbox_switch(): void {
        $result = $this->convert(
            $this->row( '[vc_single_image image="7" onclick="link_image"]' ),
            [ 'attachments' => [ 7 => 'https://example.com/a.jpg' ] ]
        );

        $this->assertSame( 'on', $this->read( $this->firstModule( $result )['settings'], 'image.advanced.lightbox.desktop.value' ) );
    }

    public function test_a_caption_and_a_title_become_their_own_blocks(): void {
        $result = $this->convert(
            $this->row( '[vc_single_image image="7" add_caption="yes" title="Our workshop"]' ),
            [ 'attachments' => [ 7 => [ 'url' => 'https://example.com/a.jpg', 'caption' => 'Taken in 2019' ] ] ]
        );
        $modules = $this->modules( $result );

        $this->assertSame( [ 'divi/heading', 'divi/image', 'divi/text' ], array_column( $modules, 'name' ) );
        $this->assertSame( 'Our workshop', $this->read( $modules[0]['settings'], 'title.innerContent.desktop.value' ) );
        $this->assertStringContainsString( 'Taken in 2019', (string) $this->read( $modules[2]['settings'], 'content.innerContent.desktop.value' ) );
    }

    // -------------------------------------------------------------------------
    // vc_btn
    // -------------------------------------------------------------------------

    public function test_a_legacy_vc_button_converts_like_its_vc_btn_equivalent(): void {
        // `vc_button` has no `style` attribute, so `vc_btn`'s own default —
        // `modern` — applies, and `color="btn-primary"` resolves through
        // WPBakery's `$btn_solid_colors` to the same three custom colours its
        // 9.0 migration writes.
        $legacy = $this->convert( $this->row( '[vc_button title="Read more" color="btn-primary" size="btn-large" href="https://example.com/read" target="_self"]' ) );
        $modern = $this->convert( $this->row( '[vc_btn title="Read more" style="modern" custom_background="#0088cc" custom_text="#fff" custom_border="#0088cc" size="lg" link="url:https%3A%2F%2Fexample.com%2Fread|title:Read more|target:_self"]' ) );

        $this->assertSame(
            $this->firstModule( $modern )['settings'],
            $this->firstModule( $legacy )['settings'],
            'a pre-9.0 button has to land on the same Divi settings as the button it became'
        );
    }

    public function test_button_size_and_shape_come_from_the_wpbakery_stylesheet(): void {
        $settings = $this->firstModule( $this->convert( $this->row( '[vc_btn title="Hi" size="lg" shape="round"]' ) ) )['settings'];

        $this->assertSame( '16px', $this->read( $settings, 'button.decoration.font.font.desktop.value.size' ) );
        $this->assertSame(
            [ 'top' => '18px', 'right' => '25px', 'bottom' => '18px', 'left' => '25px', 'syncVertical' => 'off', 'syncHorizontal' => 'off' ],
            $this->read( $settings, 'button.decoration.spacing.desktop.value.padding' )
        );
        $this->assertSame( '2em', $this->read( $settings, 'button.decoration.border.desktop.value.radius.topLeft' ) );
    }

    public function test_hover_colours_and_custom_onclick_are_reported_not_dropped(): void {
        $result = $this->convert( $this->row(
            '[vc_btn title="Hi" style="custom" custom_background="#0088cc" custom_text="#fff" custom_hover_background="#005580" custom_onclick="true" custom_onclick_code="alert(1)"]'
        ) );
        $kinds = array_column( $result['report']['not_carried_over'], 'kind' );

        $this->assertContains( 'hover', $kinds );
        $this->assertContains( 'interaction', $kinds );
    }

    public function test_consecutive_inline_buttons_become_one_nested_flexed_row(): void {
        $result = $this->convert( $this->row(
            '[vc_btn title="Buy" align="inline"][vc_btn title="Learn" align="inline"][vc_column_text]After[/vc_column_text]'
        ) );
        $modules = $this->modules( $result );

        $this->assertSame( 'divi/row', $modules[0]['name'] );
        $this->assertSame( 'divi/text', $modules[1]['name'] );

        $column = $modules[0]['elements'][0];
        $this->assertSame( '0px', $this->read( $column['settings'], 'module.decoration.layout.desktop.value.columnGap' ) );
        $this->assertSame( [ 'divi/button', 'divi/button' ], array_column( $column['elements'], 'name' ) );
    }

    public function test_a_button_icon_is_written_unicode_type_weight(): void {
        $settings = $this->firstModule( $this->convert( $this->row(
            '[vc_btn title="Hi" add_icon="true" i_type="fontawesome" i_icon_fontawesome="fas fa-download"]'
        ) ) )['settings'];

        $icon = $this->read( $settings, 'button.decoration.button.desktop.value.icon.settings' );
        $this->assertSame( [ 'unicode', 'type', 'weight' ], array_keys( (array) $icon ) );
        $this->assertSame( 'on', $this->read( $settings, 'button.decoration.button.desktop.value.icon.enable' ) );
    }

    // -------------------------------------------------------------------------
    // vc_icon
    // -------------------------------------------------------------------------

    public function test_an_icon_size_comes_from_the_stylesheet(): void {
        $settings = $this->firstModule( $this->convert( $this->row( '[vc_icon icon_fontawesome="fas fa-star" size="xl"]' ) ) )['settings'];

        // .vc_icon_element{font-size:14px} × .vc_icon_element-size-xl .vc_icon_element-icon{font-size:5em}
        $this->assertSame( '70px', $this->read( $settings, 'icon.advanced.size.desktop.value' ) );
    }

    public function test_an_icon_from_a_library_divi_does_not_ship_is_reported(): void {
        $result = $this->convert( $this->row( '[vc_icon type="openiconic" icon_openiconic="vc-oi vc-oi-dial"]' ) );

        $details = array_column( $result['report']['not_carried_over'], 'detail' );
        $this->assertNotEmpty( array_filter( $details, static fn( string $d ): bool => str_contains( $d, 'openiconic' ) ) );
    }

    // -------------------------------------------------------------------------
    // vc_message
    // -------------------------------------------------------------------------

    public function test_message_colours_come_from_the_message_box_table(): void {
        $settings = $this->firstModule( $this->convert( $this->row( '[vc_message color="warning"]Careful[/vc_message]' ) ) )['settings'];

        $this->assertSame( '#fff4e2', $this->read( $settings, 'module.decoration.background.desktop.value.color' ) );
        $this->assertSame( '#ffeccc', $this->read( $settings, 'module.decoration.border.desktop.value.styles.all.color' ) );
        $this->assertSame( '#9d8967', $this->read( $settings, 'content.decoration.bodyFont.body.font.desktop.value.color' ) );
    }

    public function test_an_unknown_message_colour_is_reported_never_guessed(): void {
        $result = $this->convert( $this->row( '[vc_message color="banana"]Hi[/vc_message]' ) );

        $this->assertNotEmpty( $result['report']['unresolved_globals'] );
    }

    // -------------------------------------------------------------------------
    // vc_toggle
    // -------------------------------------------------------------------------

    public function test_an_open_toggle_starts_open(): void {
        $settings = $this->firstModule( $this->convert( $this->row( '[vc_toggle title="Hi" open="true"]Body[/vc_toggle]' ) ) )['settings'];

        $this->assertSame( 'on', $this->read( $settings, 'module.advanced.open.desktop.value' ) );
        $this->assertSame( 'Hi', $this->read( $settings, 'title.innerContent.desktop.value' ) );
    }

    public function test_the_last_toggle_of_a_run_takes_the_element_margin(): void {
        $result  = $this->convert( $this->row( '[vc_toggle title="A"]a[/vc_toggle][vc_toggle title="B"]b[/vc_toggle]' ) );
        $modules = $this->modules( $result );

        $this->assertSame( '21.74px', $this->read( $modules[0]['settings'], 'module.decoration.spacing.desktop.value.margin.bottom' ) );
        $this->assertSame( '35px', $this->read( $modules[1]['settings'], 'module.decoration.spacing.desktop.value.margin.bottom' ) );
    }

    // -------------------------------------------------------------------------
    // vc_copyright / vc_custom_field
    // -------------------------------------------------------------------------

    public function test_copyright_keeps_the_year_dynamic(): void {
        $settings = $this->firstModule( $this->convert( $this->row( '[vc_copyright prefix="Copyright " postfix=" Acme"]' ) ) )['settings'];
        $html     = (string) $this->read( $settings, 'content.innerContent.desktop.value' );

        $this->assertStringContainsString( '&copy;', $html );
        $this->assertStringContainsString(
            '$variable({"type":"content","value":{"name":"current_date","settings":{"date_format":"custom","custom_date_format":"Y"}}})$',
            $html
        );
    }

    public function test_a_custom_field_becomes_a_post_meta_token(): void {
        $result = $this->convert( $this->row( '[vc_custom_field field_key="subtitle"]' ) );
        $html   = (string) $this->read( $this->firstModule( $result )['settings'], 'content.innerContent.desktop.value' );

        $this->assertStringContainsString( '"name":"post_meta_key"', $html );
        $this->assertStringContainsString( '"meta_key":"subtitle"', $html );
        $this->assertNotEmpty( $result['report']['approximate_matches'] );
    }

    // -------------------------------------------------------------------------
    // Raw HTML / JS
    // -------------------------------------------------------------------------

    public function test_raw_html_becomes_a_code_module_without_its_script(): void {
        $html      = '<div class="promo"><script>window.x = 1;</script></div>';
        $shortcode = '[vc_raw_html]' . base64_encode( rawurlencode( $html ) ) . '[/vc_raw_html]';

        $module = $this->firstModule( $this->convert( $this->row( $shortcode ) ) );

        $this->assertSame( 'divi/code', $module['name'] );
        $this->assertSame( '<div class="promo"></div>', $this->read( $module['settings'], 'content.innerContent.desktop.value' ) );
    }

    /** A Raw JS element is only ever a script, and the converter never writes one. */
    public function test_raw_js_is_reported_and_never_written(): void {
        $js        = '<script>console.log(1);</script>';
        $shortcode = '[vc_raw_js]' . base64_encode( rawurlencode( $js ) ) . '[/vc_raw_js]';

        $result = $this->convert( $this->row( $shortcode ) );

        $this->assertSame( [], $this->modules( $result ) );
        $this->assertStringNotContainsString( 'console.log', (string) json_encode( $result['divi'] ) );
        $this->assertSame(
            [ [ 'kind' => 'custom_code', 'node_id' => 'vc_raw_js-1', 'detail' => 'Raw JS element (32 characters of script) not converted; if the page still needs it, add it through Divi > Theme Options > Integration' ] ],
            $result['report']['not_carried_over']
        );
        $this->assertSame( [], $result['unsupported'] );
    }

    /**
     * The markup is filtered whoever is converting. There is no capability that
     * turns the filter off, and the report names what went.
     */
    public function test_raw_html_is_filtered_for_every_user(): void {
        $html      = '<div class="promo" onclick="steal()"><script>window.x = 1;</script><iframe src="https://www.youtube.com/embed/abc" width="560"></iframe></div>';
        $shortcode = '[vc_raw_html]' . base64_encode( rawurlencode( $html ) ) . '[/vc_raw_html]';

        $filtered = $this->convert( $this->row( $shortcode ) );
        $value    = (string) $this->read( $this->firstModule( $filtered )['settings'], 'content.innerContent.desktop.value' );

        $this->assertStringNotContainsString( '<script', $value );
        $this->assertStringNotContainsString( 'window.x', $value, 'the script text does not stay behind as page text' );
        $this->assertStringNotContainsString( 'onclick', $value );
        $this->assertStringContainsString( '<div class="promo">', $value );
        $this->assertStringContainsString( '<iframe src="https://www.youtube.com/embed/abc" width="560">', $value, 'an embed is page content' );
        $this->assertSame(
            [ [ 'kind' => 'custom_code', 'node_id' => 'vc_raw_html-1', 'detail' => 'markup the converter does not publish was removed (onclick, <script>); add it to the page through Divi > Theme Options > Integration if it is still needed' ] ],
            $filtered['report']['not_carried_over']
        );
    }

    /** Every module's markup is filtered, not only the Code module's. */
    public function test_the_filter_reaches_text_modules_and_leaves_clean_markup_unreported(): void {
        $result = $this->convert(
            $this->row( '[vc_column_text]<p onmouseover="x()">Hi</p>[/vc_column_text][vc_column_text]<p><strong>Fine</strong></p>[/vc_column_text]' )
        );

        $this->assertStringNotContainsString( 'onmouseover', (string) json_encode( $result['divi'] ) );
        $this->assertCount( 1, $result['report']['not_carried_over'] );
        $this->assertSame( 'vc_column_text-1', $result['report']['not_carried_over'][0]['node_id'] );
    }

    /**
     * `unfiltered_html` does not exempt anyone: an administrator who holds it
     * gets the same filtered markup an editor does. The directory does not
     * permit a plugin to publish script, whoever asked for it.
     */
    public function test_the_unfiltered_html_capability_does_not_turn_the_filter_off(): void {
        $shortcode = '[vc_raw_html]' . base64_encode( rawurlencode( '<p>Hi</p><script>1</script>' ) ) . '[/vc_raw_html]';

        foreach ( [ true, false ] as $may_post_unfiltered ) {
            $GLOBALS['__test_caps'] = $may_post_unfiltered;
            try {
                $value = $this->read( $this->firstModule( $this->convert( $this->row( $shortcode ) ) )['settings'], 'content.innerContent.desktop.value' );
            } finally {
                unset( $GLOBALS['__test_caps'] );
            }

            $this->assertSame( '<p>Hi</p>', $value );
        }
    }

    /** The option is gone: passing it cannot switch the filter off either. */
    public function test_an_unfiltered_html_option_is_ignored(): void {
        $shortcode = '[vc_raw_html]' . base64_encode( rawurlencode( '<script>1</script>' ) ) . '[/vc_raw_html]';

        $value = $this->read(
            $this->firstModule( $this->convert( $this->row( $shortcode ), [ 'unfiltered_html' => true ] ) )['settings'],
            'content.innerContent.desktop.value'
        );

        $this->assertSame( '', $value );
    }

    // -------------------------------------------------------------------------
    // Maps
    // -------------------------------------------------------------------------

    public function test_a_gmaps_iframe_is_decoded_and_kept_verbatim(): void {
        $iframe    = '<iframe src="https://www.google.com/maps/embed?pb=!1m18" width="600" height="300"></iframe>';
        $shortcode = '[vc_gmaps link="#E-8_' . base64_encode( rawurlencode( $iframe ) ) . '" size="360"]';

        $result = $this->convert( $this->row( $shortcode ) );
        $module = $this->firstModule( $result );

        $this->assertSame( 'divi/code', $module['name'] );
        $html = (string) $this->read( $module['settings'], 'content.innerContent.desktop.value' );
        $this->assertStringContainsString( 'https://www.google.com/maps/embed?pb=!1m18', $html );
        $this->assertStringContainsString( 'height="360"', $html, 'size overrides the iframe height, as the template does' );
        $this->assertNotEmpty( $result['report']['approximate_matches'] );
    }

    public function test_goo_maps_coordinates_become_a_divi_map_with_a_pin(): void {
        $result = $this->convert( $this->row( '[vc_goo_maps location="51.503324,-0.119543" zoom="16"]' ) );
        $module = $this->firstModule( $result );

        $this->assertSame( 'divi/map', $module['name'] );
        $this->assertSame( '51.503324', $this->read( $module['settings'], 'map.innerContent.desktop.value.lat' ) );
        $this->assertSame( '-0.119543', $this->read( $module['settings'], 'map.innerContent.desktop.value.lng' ) );
        $this->assertSame( '16', $this->read( $module['settings'], 'map.innerContent.desktop.value.zoom' ) );
        $this->assertSame( 'divi/map-pin', $module['elements'][0]['name'] );
    }

    public function test_goo_maps_with_an_address_keeps_wpbakerys_own_iframe(): void {
        // Divi's map module centres on lat/lng only (MapModule::render_callback);
        // an address it cannot geocode would render the Atlantic.
        $result = $this->convert( $this->row( '[vc_goo_maps location="London Eye, London, United Kingdom" zoom="12" type="k"]' ) );
        $module = $this->firstModule( $result );

        $this->assertSame( 'divi/code', $module['name'] );
        $html = (string) $this->read( $module['settings'], 'content.innerContent.desktop.value' );
        $this->assertStringContainsString( 'https://maps.google.com/maps?q=London%20Eye', $html );
        $this->assertStringContainsString( 'z=12', $html );
        $this->assertStringContainsString( 't=k', $html );
    }

    // -------------------------------------------------------------------------
    // Video
    // -------------------------------------------------------------------------

    public function test_a_youtube_url_becomes_a_divi_video(): void {
        $result = $this->convert( $this->row( '[vc_video link="https://www.youtube.com/watch?v=k7Ew0S_TVUQ"]' ) );
        $module = $this->firstModule( $result );

        $this->assertSame( 'divi/video', $module['name'] );
        $this->assertSame(
            'https://www.youtube.com/watch?v=k7Ew0S_TVUQ',
            $this->read( $module['settings'], 'video.innerContent.desktop.value.src' )
        );
    }

    public function test_a_url_divi_cannot_play_becomes_a_code_block(): void {
        $result = $this->convert( $this->row( '[vc_video link="https://example.com/players/embed/abc123"]' ) );
        $module = $this->firstModule( $result );

        $this->assertSame( 'divi/code', $module['name'] );
        $this->assertStringContainsString(
            'https://example.com/players/embed/abc123',
            (string) $this->read( $module['settings'], 'content.innerContent.desktop.value' )
        );
        $kinds = array_column( $result['report']['not_carried_over'], 'kind' );
        $this->assertContains( 'integration', $kinds );
    }

    public function test_a_video_with_no_url_is_reported_and_produces_nothing(): void {
        // WPBakery's template returns null when `link` is empty.
        $result = $this->convert( $this->row( '[vc_video align="center"]' ) );

        $this->assertSame( [], $this->modules( $result ) );
        $this->assertNotEmpty( $result['report']['warnings'] );
    }

    // -------------------------------------------------------------------------
    // Gutenberg
    // -------------------------------------------------------------------------

    public function test_gutenberg_blocks_are_rendered_in_direct_mode(): void {
        $result = $this->convert(
            $this->row( '[vc_gutenberg do_blocks="true"]<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->[/vc_gutenberg]' ),
            [ 'mode' => 'direct', 'render_shortcodes' => true ]
        );

        $this->assertNotEmpty( $result['report']['static_copies'] );
        $this->assertStringContainsString(
            '<p>Hi</p>',
            (string) $this->read( $this->firstModule( $result )['settings'], 'content.innerContent.desktop.value' )
        );
    }

    public function test_gutenberg_blocks_are_not_rendered_in_direct_mode_without_do_blocks_true(): void {
        // vc_gutenberg.php: `'true' === $do_blocks ? do_blocks( $content ) : $content` —
        // the attribute gates do_blocks() even on the live site, so direct
        // mode alone must not render it (task-8-fix-round-1.md, #10).
        $result = $this->convert(
            $this->row( '[vc_gutenberg]<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->[/vc_gutenberg]' ),
            [ 'mode' => 'direct', 'render_shortcodes' => true ]
        );
        $module = $this->firstModule( $result );

        $this->assertSame( [], $result['report']['static_copies'] );
        $this->assertNotEmpty( $result['report']['warnings'] );
        $this->assertStringContainsString( 'wp:paragraph', (string) $this->read( $module['settings'], 'content.innerContent.desktop.value' ) );
    }

    public function test_gutenberg_blocks_are_kept_with_a_warning_on_import(): void {
        $result = $this->convert( $this->row( '[vc_gutenberg]<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->[/vc_gutenberg]' ) );
        $module = $this->firstModule( $result );

        $this->assertSame( 'divi/code', $module['name'] );
        $this->assertStringContainsString( 'wp:paragraph', (string) $this->read( $module['settings'], 'content.innerContent.desktop.value' ) );
        $this->assertNotEmpty( $result['report']['warnings'] );
    }

    // -------------------------------------------------------------------------
    // Nothing is dropped in silence
    // -------------------------------------------------------------------------

    public function test_an_icon_field_a_switched_off_toggle_left_behind_is_not_a_skipped_setting(): void {
        // WPBakery's icon picker fills `i_icon_fontawesome` whether or not
        // `add_icon` is on; seven of its own templates ship buttons like this.
        $result = $this->convert( $this->row( '[vc_btn title="Hi" i_icon_fontawesome="fas fa-star"]' ) );

        $this->assertSame( [], $result['report']['skipped_settings'] );
    }

    /**
     * A field WPBakery declares for the element and no handler read: the
     * converter's own gap, and the only thing `skipped_settings` records
     * (task-11-amendments §7). `custom_font_container` is the font of the
     * optional custom heading WPBakery integrates into a toggle's title.
     */
    public function test_a_declared_field_no_handler_read_is_a_skipped_setting(): void {
        $result = $this->convert( $this->row( '[vc_toggle title="T" custom_font_container="tag:h3"]Body[/vc_toggle]' ) );

        $this->assertContains( 'vc_toggle-1: custom_font_container', $result['report']['skipped_settings'] );
    }

    /**
     * A field WPBakery does not declare for the element was added by somebody
     * else — a theme, an add-on — so it is reported under `addon` rather than
     * as a gap here. Either way it is reported: nothing is dropped in silence.
     */
    public function test_a_field_wpbakery_never_declared_is_reported_as_an_addon(): void {
        $result = $this->convert( $this->row( '[vc_separator style="shadow" accent_color="#ff9100" el_width="50" align="align_center" wbdc_unknown="42"]' ) );

        $this->assertSame( [], $result['report']['skipped_settings'] );

        $details = array_column(
            array_filter( $result['report']['not_carried_over'], static fn( array $e ): bool => $e['kind'] === 'addon' ),
            'detail'
        );

        $this->assertCount( 1, $details );
        $this->assertStringContainsString( 'wbdc_unknown', $details[0] );
        $this->assertStringContainsString( 'not declared by WPBakery', $details[0] );
    }
}
