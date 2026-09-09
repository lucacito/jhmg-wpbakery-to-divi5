<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Helpers\PackedParams;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

/**
 * WPBakery's design options are one desktop-only CSS rule in the `css`
 * shortcode attribute plus the flat `el_id` / `el_class` / `css_animation` /
 * `disable_element` attributes. Every Divi 5 path asserted here was read from
 * the 5.12.1 source (module.json + server/Packages/Module/Options); see
 * .superpowers/sdd/.../task-6-report.md for the path table.
 */
final class StyleMapperTest extends TestCase {

    private StyleMapper $mapper;

    protected function setUp(): void {
        wbdc_test_reset_hooks();
        $this->mapper = new StyleMapper();
    }

    protected function tearDown(): void {
        wbdc_test_reset_hooks();
    }

    /** @param array<string,mixed> $atts */
    private function map( string $kind, array $atts, array $context = [] ): array {
        return $this->mapper->map( $kind, $atts, $context );
    }

    // -- spacing --------------------------------------------------------------

    public function test_spacing_lands_on_module_decoration_spacing(): void {
        $result = $this->map( 'text', [ 'css' => '.vc_custom_1{margin-top: 20px !important;padding-left: 5% !important;}' ] );

        $spacing = $result['divi_attrs']['module']['decoration']['spacing']['desktop']['value'];

        $this->assertSame(
            [ 'top' => '20px', 'right' => '', 'bottom' => '', 'left' => '', 'syncVertical' => 'off', 'syncHorizontal' => 'off' ],
            $spacing['margin']
        );
        $this->assertSame(
            [ 'top' => '', 'right' => '', 'bottom' => '', 'left' => '5%', 'syncVertical' => 'off', 'syncHorizontal' => 'off' ],
            $spacing['padding']
        );
        $this->assertContains( 'css', $result['handled_keys'] );
    }

    public function test_a_shorthand_margin_expands_to_four_sides(): void {
        $result = $this->map( 'text', [ 'css' => '.c{margin: 10px 20px;}' ] );

        $margin = $result['divi_attrs']['module']['decoration']['spacing']['desktop']['value']['margin'];

        $this->assertSame( '10px', $margin['top'] );
        $this->assertSame( '20px', $margin['right'] );
        $this->assertSame( '10px', $margin['bottom'] );
        $this->assertSame( '20px', $margin['left'] );
    }

    /**
     * Divi's image module reads its spacing from `module.advanced.spacing` and
     * its sizing from `module.advanced.sizing` (ImageModule.php: the
     * `divi/image-spacing` / `divi/image-sizing` components); a margin written
     * at `module.decoration.spacing` renders without `!important` and loses to
     * `.et_flex_column>.et_pb_module{margin-bottom:unset}` (docs/box-model.md).
     */
    public function test_an_image_writes_spacing_and_sizing_under_module_advanced(): void {
        $css    = '.c{margin-top: 20px !important;padding-left: 5% !important;width: 400px !important;}';
        $text   = $this->map( 'text', [ 'css' => $css ] );
        $image  = $this->map( 'image', [ 'css' => $css ] );

        $this->assertSame( '20px', $text['divi_attrs']['module']['decoration']['spacing']['desktop']['value']['margin']['top'] );
        $this->assertSame( '400px', $text['divi_attrs']['module']['decoration']['sizing']['desktop']['value']['width'] );

        $this->assertSame( '20px', $image['divi_attrs']['module']['advanced']['spacing']['desktop']['value']['margin']['top'] );
        $this->assertSame( '5%', $image['divi_attrs']['module']['advanced']['spacing']['desktop']['value']['padding']['left'] );
        $this->assertSame( '400px', $image['divi_attrs']['module']['advanced']['sizing']['desktop']['value']['width'] );

        $this->assertArrayNotHasKey( 'spacing', $image['divi_attrs']['module']['decoration'] ?? [] );
        $this->assertArrayNotHasKey( 'sizing', $image['divi_attrs']['module']['decoration'] ?? [] );
    }

    // -- border ---------------------------------------------------------------

    public function test_border_widths_per_side_with_a_shared_colour_and_style(): void {
        $result = $this->map( 'text', [
            'css' => '.c{border-top-width: 2px !important;border-left-width: 4px !important;border-color: #ff0000 !important;border-style: dashed !important;}',
        ] );

        $styles = $result['divi_attrs']['module']['decoration']['border']['desktop']['value']['styles'];

        $this->assertSame( '2px', $styles['top']['width'] );
        $this->assertSame( '4px', $styles['left']['width'] );
        $this->assertSame( '#ff0000', $styles['all']['color'] );
        $this->assertSame( 'dashed', $styles['all']['style'] );
        $this->assertArrayNotHasKey( 'right', $styles );
    }

    public function test_four_equal_border_widths_collapse_to_all(): void {
        $result = $this->map( 'text', [
            'css' => '.c{border-top-width: 3px;border-right-width: 3px;border-bottom-width: 3px;border-left-width: 3px;}',
        ] );

        $styles = $result['divi_attrs']['module']['decoration']['border']['desktop']['value']['styles'];

        $this->assertSame( '3px', $styles['all']['width'] );
        $this->assertArrayNotHasKey( 'top', $styles );
    }

    public function test_border_radius_shorthand_fills_four_corners(): void {
        $result = $this->map( 'text', [ 'css' => '.c{border-radius: 5px !important;}' ] );

        $this->assertSame(
            [ 'topLeft' => '5px', 'topRight' => '5px', 'bottomRight' => '5px', 'bottomLeft' => '5px' ],
            $result['divi_attrs']['module']['decoration']['border']['desktop']['value']['radius']
        );
    }

    public function test_border_radius_per_corner(): void {
        $result = $this->map( 'text', [
            'css' => '.c{border-top-left-radius: 4px !important;border-bottom-right-radius: 8px !important;}',
        ] );

        $radius = $result['divi_attrs']['module']['decoration']['border']['desktop']['value']['radius'];

        $this->assertSame( '4px', $radius['topLeft'] );
        $this->assertSame( '8px', $radius['bottomRight'] );
        $this->assertSame( '0px', $radius['topRight'] );
        $this->assertSame( '0px', $radius['bottomLeft'] );
    }

    /** Divi's image module has no `module.decoration.border`; the border is on the image element. */
    public function test_an_image_border_targets_the_image_element(): void {
        $result = $this->map( 'image', [ 'css' => '.c{border-radius: 50% !important;}' ] );

        $this->assertSame( '50%', $result['divi_attrs']['image']['decoration']['border']['desktop']['value']['radius']['topLeft'] );
    }

    /** Divi's button module has no `module.decoration.border` or `.background` either. */
    public function test_a_button_border_and_background_target_the_button_element(): void {
        $result = $this->map( 'button', [ 'css' => '.c{border-radius: 5px;background-color: #123456;margin-bottom: 10px;}' ] );

        $this->assertSame( '5px', $result['divi_attrs']['button']['decoration']['border']['desktop']['value']['radius']['topLeft'] );
        $this->assertSame( '#123456', $result['divi_attrs']['button']['decoration']['background']['desktop']['value']['color'] );
        $this->assertSame( '10px', $result['divi_attrs']['module']['decoration']['spacing']['desktop']['value']['margin']['bottom'] );
    }

    // -- background -----------------------------------------------------------

    public function test_background_colour(): void {
        $result = $this->map( 'row', [ 'css' => '.c{background-color: #f5f5f5 !important;}' ] );

        $this->assertSame( '#f5f5f5', $result['divi_attrs']['module']['decoration']['background']['desktop']['value']['color'] );
    }

    public function test_an_rgba_background_colour_passes_through(): void {
        $result = $this->map( 'row', [ 'css' => '.c{background-color: rgba(0,0,0,0.5) !important;}' ] );

        $this->assertSame( 'rgba(0,0,0,0.5)', $result['divi_attrs']['module']['decoration']['background']['desktop']['value']['color'] );
    }

    public function test_a_background_image_id_is_resolved_from_the_import_attachment_map(): void {
        $result = $this->map(
            'row',
            [ 'css' => '.c{background-image: url(https://old.example/wp-content/uploads/x.jpg?id=106) !important;}' ],
            [ 'mode' => 'import', 'attachments' => [ 106 => 'https://new.example/wp-content/uploads/x.jpg' ] ]
        );

        $this->assertSame(
            'https://new.example/wp-content/uploads/x.jpg',
            $result['divi_attrs']['module']['decoration']['background']['desktop']['value']['image']['url']
        );
        $this->assertSame( [], $result['notes'] );
    }

    public function test_a_background_image_id_is_resolved_from_the_media_library_in_direct_mode(): void {
        $GLOBALS['__test_attachments'] = [ 106 => [ 'url' => 'https://site.example/wp-content/uploads/live.jpg' ] ];

        $result = $this->map(
            'row',
            [ 'css' => '.c{background-image: url(https://site.example/wp-content/uploads/x.jpg?id=106) !important;}' ],
            [ 'mode' => 'direct' ]
        );

        $this->assertSame(
            'https://site.example/wp-content/uploads/live.jpg',
            $result['divi_attrs']['module']['decoration']['background']['desktop']['value']['image']['url']
        );
    }

    public function test_an_unresolvable_id_keeps_the_url_and_reports_it(): void {
        $result = $this->map(
            'row',
            [ 'css' => '.c{background-image: url(https://old.example/x.jpg?id=999) !important;}' ],
            [ 'mode' => 'import', 'attachments' => [] ]
        );

        $this->assertSame(
            'https://old.example/x.jpg',
            $result['divi_attrs']['module']['decoration']['background']['desktop']['value']['image']['url']
        );
        $this->assertSame( 'unresolved_media', $result['notes'][0]['kind'] );
    }

    public function test_a_background_image_without_an_id_is_kept_as_is(): void {
        $result = $this->map( 'row', [ 'css' => '.c{background-image: url(https://site.example/x.jpg) !important;}' ] );

        $this->assertSame(
            'https://site.example/x.jpg',
            $result['divi_attrs']['module']['decoration']['background']['desktop']['value']['image']['url']
        );
        $this->assertSame( [], $result['notes'] );
    }

    public function test_background_size_and_position(): void {
        $result = $this->map( 'row', [
            'css' => '.c{background-image: url(https://site.example/x.jpg) !important;background-position: center !important;background-repeat: no-repeat !important;background-size: cover !important;}',
        ] );

        $image = $result['divi_attrs']['module']['decoration']['background']['desktop']['value']['image'];

        $this->assertSame( 'cover', $image['size'] );
        $this->assertSame( 'center center', $image['position'] );
        $this->assertSame( 'no-repeat', $image['repeat'] );
    }

    /** WPBakery writes `background-position: 0 0` alongside `background-repeat: repeat`. */
    public function test_a_zero_background_position_becomes_left_top(): void {
        $result = $this->map( 'row', [
            'css' => '.c{background-image: url(https://site.example/x.jpg) !important;background-position: 0 0 !important;background-repeat: repeat !important;}',
        ] );

        $image = $result['divi_attrs']['module']['decoration']['background']['desktop']['value']['image'];

        $this->assertSame( 'left top', $image['position'] );
        $this->assertSame( 'repeat', $image['repeat'] );
    }

    /** WPBakery's `background` shorthand carries a colour and an image together. */
    public function test_the_background_shorthand_splits_into_colour_and_image(): void {
        $result = $this->map( 'row', [ 'css' => '.c{background: #ffffff url(https://site.example/x.jpg) !important;}' ] );

        $background = $result['divi_attrs']['module']['decoration']['background']['desktop']['value'];

        $this->assertSame( '#ffffff', $background['color'] );
        $this->assertSame( 'https://site.example/x.jpg', $background['image']['url'] );
    }

    /** Divi appends the unit to a gradient stop position itself, so it must be a bare number. */
    public function test_a_linear_gradient_becomes_divis_gradient_shape(): void {
        $result = $this->map( 'row', [ 'css' => '.c{background: linear-gradient(45deg, #ff0000 0%, #0000ff 100%) !important;}' ] );

        $gradient = $result['divi_attrs']['module']['decoration']['background']['desktop']['value']['gradient'];

        $this->assertSame( 'on', $gradient['enabled'] );
        $this->assertSame( 'linear', $gradient['type'] );
        $this->assertSame( '45deg', $gradient['direction'] );
        $this->assertSame(
            [
                [ 'color' => '#ff0000', 'position' => '0' ],
                [ 'color' => '#0000ff', 'position' => '100' ],
            ],
            $gradient['stops']
        );
    }

    public function test_a_radial_gradient_uses_divis_elliptical_type(): void {
        $result = $this->map( 'row', [ 'css' => '.c{background-image: radial-gradient(circle at top left, #fff 0%, #000 100%);}' ] );

        $gradient = $result['divi_attrs']['module']['decoration']['background']['desktop']['value']['gradient'];

        $this->assertSame( 'circular', $gradient['type'] );
        $this->assertSame( 'top left', $gradient['directionRadial'] );
    }

    public function test_a_gradient_divi_cannot_express_is_carried_as_custom_css(): void {
        $result = $this->map( 'row', [ 'css' => '.c{background: linear-gradient(to right, #fff 0px, #000 40px);}' ] );

        $this->assertStringContainsString( 'linear-gradient(to right, #fff 0px, #000 40px)', $result['divi_attrs']['css']['desktop']['value']['mainElement'] );
        $this->assertContains( 'custom_css_carried', array_column( $result['notes'], 'kind' ) );
    }

    // -- leftovers ------------------------------------------------------------

    public function test_an_unknown_declaration_is_carried_verbatim(): void {
        $result = $this->map( 'text', [ 'css' => '.c{box-shadow: 0 2px 4px #000 !important;margin-top: 5px !important;}' ] );

        $this->assertStringContainsString( 'box-shadow: 0 2px 4px #000;', $result['divi_attrs']['css']['desktop']['value']['mainElement'] );
        $this->assertStringNotContainsString( 'margin-top', $result['divi_attrs']['css']['desktop']['value']['mainElement'] );
        $this->assertContains( 'css', $result['handled_keys'] );
        $this->assertContains( 'custom_css_carried', array_column( $result['notes'], 'kind' ) );
    }

    /** `*background-color` and `_zoom` are WPBakery's own IE hacks; the parser drops them. */
    public function test_ie_hacks_are_not_carried(): void {
        $result = $this->map( 'text', [ 'css' => '.c{background-color: rgba(0,0,0,0.5) !important;*background-color: rgb(0,0,0) !important;}' ] );

        $this->assertArrayNotHasKey( 'css', $result['divi_attrs'] );
    }

    public function test_no_css_attribute_writes_nothing(): void {
        $result = $this->map( 'text', [] );

        $this->assertSame( [], $result['divi_attrs'] );
        $this->assertSame( [], $result['notes'] );
        $this->assertContains( 'css', $result['handled_keys'] );
    }

    // -- flat attributes ------------------------------------------------------

    public function test_el_id_and_el_class(): void {
        $result = $this->map( 'row', [ 'el_id' => 'hero', 'el_class' => 'dark  wide' ] );

        $html = $result['divi_attrs']['module']['advanced']['htmlAttributes']['desktop']['value'];

        $this->assertSame( 'hero', $html['id'] );
        $this->assertSame( 'dark  wide', $html['class'] );
        $this->assertContains( 'el_id', $result['handled_keys'] );
        $this->assertContains( 'el_class', $result['handled_keys'] );
    }

    public function test_css_animation_is_reported_not_mapped(): void {
        $result = $this->map( 'text', [ 'css_animation' => 'fadeIn' ] );

        $this->assertSame( [ [ 'kind' => 'animation', 'detail' => 'css_animation=fadeIn' ] ], $result['notes'] );
        $this->assertSame( [], $result['divi_attrs'] );
        $this->assertContains( 'css_animation', $result['handled_keys'] );
    }

    public function test_css_animation_none_is_not_reported(): void {
        $this->assertSame( [], $this->map( 'text', [ 'css_animation' => 'none' ] )['notes'] );
        $this->assertSame( [], $this->map( 'text', [ 'css_animation' => '' ] )['notes'] );
    }

    public function test_disable_element_hides_all_three_breakpoints(): void {
        $result = $this->map( 'row', [ 'disable_element' => 'yes' ] );

        $disabled = $result['divi_attrs']['module']['decoration']['disabledOn'];

        $this->assertSame( 'on', $disabled['desktop']['value'] );
        $this->assertSame( 'on', $disabled['tablet']['value'] );
        $this->assertSame( 'on', $disabled['phone']['value'] );
        $this->assertContains( 'visibility', array_column( $result['notes'], 'kind' ) );
        $this->assertContains( 'disable_element', $result['handled_keys'] );
    }

    public function test_disable_element_off_changes_nothing(): void {
        $result = $this->map( 'row', [ 'disable_element' => '' ] );

        $this->assertSame( [], $result['divi_attrs'] );
        $this->assertSame( [], $result['notes'] );
    }

    // -- public helpers -------------------------------------------------------

    public function test_write_creates_the_nested_path(): void {
        $target = [];
        StyleMapper::write( $target, 'a.b.c', 'x' );
        StyleMapper::write( $target, 'a.b.d', 'y' );

        $this->assertSame( [ 'a' => [ 'b' => [ 'c' => 'x', 'd' => 'y' ] ] ], $target );
    }

    public function test_font_path_per_kind(): void {
        $this->assertSame( 'title.decoration.font.font', StyleMapper::FONT_PATH['heading'] );
        $this->assertSame( 'content.decoration.bodyFont.body.font', StyleMapper::FONT_PATH['text'] );
        $this->assertSame( 'button.decoration.font.font', StyleMapper::FONT_PATH['button'] );
        $this->assertSame( 'title.decoration.font.font', StyleMapper::FONT_PATH['blurb'] );
        $this->assertSame( 'title.decoration.font.font', StyleMapper::FONT_PATH['cta'] );
        $this->assertSame( 'number.decoration.font.font', StyleMapper::FONT_PATH['counter'] );
        $this->assertNull( StyleMapper::fontPathFor( 'row' ) );
    }

    public function test_apply_font_container(): void {
        $attrs = [];
        $fc    = PackedParams::fontContainer( 'tag:h3|font_size:28|text_align:center|line_height:1.4|color:%23333333' );

        $this->mapper->applyFontContainer( $fc, StyleMapper::FONT_PATH['heading'], $attrs );

        $value = $attrs['title']['decoration']['font']['font']['desktop']['value'];

        $this->assertSame( 'h3', $value['headingLevel'] );
        $this->assertSame( '28px', $value['size'] );
        $this->assertSame( 'center', $value['textAlign'] );
        $this->assertSame( '1.4', $value['lineHeight'] );
        $this->assertSame( '#333333', $value['color'] );
    }

    public function test_apply_font_container_ignores_empty_fields(): void {
        $attrs = [];
        $this->mapper->applyFontContainer( PackedParams::fontContainer( 'tag:h2' ), StyleMapper::FONT_PATH['heading'], $attrs );

        $this->assertSame(
            [ 'headingLevel' => 'h2' ],
            $attrs['title']['decoration']['font']['font']['desktop']['value']
        );
    }

    public function test_apply_google_fonts(): void {
        $attrs = [];
        $gf    = PackedParams::googleFonts( 'font_family:Abril%20Fatface%3Aregular|font_style:400%20italic%3A400%3Aitalic' );

        $this->mapper->applyGoogleFonts( $gf, StyleMapper::FONT_PATH['heading'], $attrs );

        $value = $attrs['title']['decoration']['font']['font']['desktop']['value'];

        $this->assertSame( 'Abril Fatface', $value['family'] );
        $this->assertSame( '400', $value['weight'] );
        $this->assertSame( [ 'italic' ], $value['style'] );
    }

    // -- contract -------------------------------------------------------------

    public function test_every_common_key_is_acknowledged(): void {
        $result = $this->map( 'generic', [] );

        foreach ( [ 'css', 'el_id', 'el_class', 'css_animation', 'css_animation_delay', 'disable_element' ] as $key ) {
            $this->assertContains( $key, $result['handled_keys'], $key );
        }
    }

    public function test_the_result_shape_is_stable(): void {
        $result = $this->map( 'generic', [] );

        $this->assertSame( [ 'divi_attrs', 'handled_keys', 'notes' ], array_keys( $result ) );
    }
}
