<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Helpers\ThemeShortcodes;

/**
 * Every non-core shortcode belongs to a family, and the family table is the
 * only thing that decides which. The two source-verified explicit-tag families
 * (The Retailer, Ronneby) have to beat the prefix rules, or `products_slider`
 * and `dfd_*` fall to "Other shortcodes".
 */
final class ThemeShortcodesTest extends TestCase {

    protected function setUp(): void {
        wbdc_test_reset_hooks();
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function familyProvider(): array {
        return [
            'ultimate addons prefix'   => [ 'ultimate_heading', 'ultimate-addons', 'Ultimate Addons' ],
            'ultimate ult_ prefix'     => [ 'ult_buttons', 'ultimate-addons', 'Ultimate Addons' ],
            'ultimate bsf- prefix'     => [ 'bsf-info-box', 'ultimate-addons', 'Ultimate Addons' ],
            'ultimate info_list'       => [ 'info_list', 'ultimate-addons', 'Ultimate Addons' ],
            'massive addons'           => [ 'mpc_button', 'massive-addons', 'Massive Addons' ],
            'salient nectar_'          => [ 'nectar_btn', 'salient', 'Salient' ],
            'salient fancy_box'        => [ 'fancy_box', 'salient', 'Salient' ],
            'bridge qode_'             => [ 'qode_button', 'bridge', 'Bridge' ],
            'the7 dt_'                 => [ 'dt_fancy_title', 'the7', 'The7' ],
            'jupiter mk_'              => [ 'mk_button', 'jupiter', 'Jupiter' ],
            'templatera'               => [ 'templatera', 'templatera', 'Templatera' ],
            'woocommerce'              => [ 'products', 'woocommerce', 'WooCommerce' ],
            'contact form 7'           => [ 'contact-form-7', 'contact-form-7', 'Contact Form 7' ],
            'gravity forms'            => [ 'gravityform', 'gravity-forms', 'Gravity Forms' ],
            'sliders'                  => [ 'rev_slider', 'sliders', 'Sliders' ],
            'the retailer explicit'    => [ 'products_slider', 'the-retailer', 'The Retailer' ],
            'the retailer icon_box'    => [ 'icon_box', 'the-retailer', 'The Retailer' ],
            'ronneby prefix'           => [ 'dfd_heading', 'ronneby', 'Ronneby' ],
            'ronneby explicit'         => [ 'piecharts', 'ronneby', 'Ronneby' ],
            'ronneby inline tooltip'   => [ 'tooltip', 'ronneby', 'Ronneby' ],
            'unknown'                  => [ 'zzz_widget', 'other', 'Other shortcodes' ],
        ];
    }

    #[DataProvider( 'familyProvider' )]
    public function test_family_classifies_a_tag( string $tag, string $key, string $label ): void {
        $family = ThemeShortcodes::family( $tag );

        $this->assertSame( $key, $family['key'] );
        $this->assertSame( $label, $family['label'] );
    }

    public function test_explicit_tags_beat_prefix_rules(): void {
        // `products_slider` starts with `products`, WooCommerce's prefix rule,
        // but The Retailer registers the exact tag.
        $this->assertSame( 'the-retailer', ThemeShortcodes::family( 'products_slider' )['key'] );
    }

    public function test_family_is_filterable(): void {
        add_filter( 'wbdc_theme_families', static function ( array $families ): array {
            $families['acme'] = [ 'label' => 'Acme', 'prefixes' => [ 'acme_' ], 'kind' => 'addon' ];
            return $families;
        } );

        $this->assertSame( 'Acme', ThemeShortcodes::family( 'acme_slider' )['label'] );
    }

    public function test_a_filter_can_add_explicit_tags(): void {
        add_filter( 'wbdc_theme_families', static function ( array $families ): array {
            $families['acme'] = [ 'label' => 'Acme', 'tags' => [ 'widget_thing' ] ];
            return $families;
        } );

        $this->assertSame( 'acme', ThemeShortcodes::family( 'widget_thing' )['key'] );
    }

    public function test_family_carries_the_not_carried_over_kind(): void {
        $this->assertSame( 'integration', ThemeShortcodes::family( 'contact-form-7' )['kind'] );
        $this->assertSame( 'addon', ThemeShortcodes::family( 'nectar_btn' )['kind'] );
    }

    public function test_is_core_recognises_wpbakery_tags(): void {
        $this->assertTrue( ThemeShortcodes::isCore( 'vc_column_text' ) );
        $this->assertTrue( ThemeShortcodes::isCore( 'vc_btn' ) );
        $this->assertFalse( ThemeShortcodes::isCore( 'nectar_btn' ) );
        // A `vc_`-prefixed tag WPBakery does not ship is a theme's, not core.
        $this->assertFalse( ThemeShortcodes::isCore( 'vc_theme_widget' ) );
    }

    /**
     * `themeParam()` returns the first family whose table holds the name, so a
     * name in two tables would be attributed to whichever happens to be listed
     * first — a report that says "Ronneby" about a field Ultimate Addons added.
     * Ronneby's `Dfd_Override_Parallax` really does re-register 65 of Ultimate
     * Addons' row fields, and the table keeps them on one side only.
     */
    public function test_no_param_is_claimed_by_two_families_for_the_same_element(): void {
        $claims = [];

        foreach ( ThemeShortcodes::THEME_PARAMS as $family => $tags ) {
            foreach ( $tags as $tag => $params ) {
                $this->assertSame(
                    array_values( array_unique( $params ) ),
                    array_values( $params ),
                    "{$family}/{$tag} lists a param twice"
                );

                foreach ( $params as $param ) {
                    $claims[ $tag . '|' . $param ][] = $family;
                }
            }
        }

        $this->assertNotEmpty( $claims );

        foreach ( $claims as $key => $families ) {
            $this->assertCount( 1, $families, "{$key} is claimed by " . implode( ', ', $families ) );
        }
    }

    /** Every family a param table names has to be a family the report can label. */
    public function test_every_theme_param_family_is_a_known_family(): void {
        foreach ( array_keys( ThemeShortcodes::THEME_PARAMS ) as $family ) {
            $this->assertArrayHasKey( $family, ThemeShortcodes::FAMILIES, "'{$family}' has a param table but no family entry" );
        }
    }
}
