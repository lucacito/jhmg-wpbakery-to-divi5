<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Helpers\Size;

/**
 * Ported unchanged from the Beaver converter's
 * plugin/jhmg-converter-for-beaver-builder-to-divi/includes/helpers/class-size.php
 * (`withUnit()`, `number()` only — WPBakery has no per-field `_unit` sibling
 * key or `{length, unit}` typography value, so `fromSettings()`/`fromLength()`
 * are not ported).
 */
final class SizeTest extends TestCase {

    public function test_with_unit_appends_the_given_unit(): void {
        $this->assertSame( '20%', Size::withUnit( 20, '%' ) );
        $this->assertSame( '1.5em', Size::withUnit( '1.5', 'em' ) );
    }

    public function test_with_unit_of_empty_value_is_empty(): void {
        $this->assertSame( '', Size::withUnit( '', null ) );
    }

    public function test_with_unit_trusts_a_value_that_already_has_a_unit(): void {
        $this->assertSame( '20px', Size::withUnit( '20px', null ) );
        $this->assertSame( 'auto', Size::withUnit( 'auto', null ) );
    }

    public function test_with_unit_falls_back_to_the_default_unit(): void {
        $this->assertSame( '10px', Size::withUnit( '10', null, 'px' ) );
    }

    public function test_with_unit_of_explicit_empty_unit_is_unitless(): void {
        $this->assertSame( '10', Size::withUnit( '10', '', 'px' ) );
    }

    public function test_with_unit_of_non_string_non_numeric_is_empty(): void {
        $this->assertSame( '', Size::withUnit( [ 'x' => 1 ], null ) );
    }

    public function test_number_reads_the_leading_numeric_part(): void {
        $this->assertSame( 20.0, Size::number( '20px' ) );
        $this->assertSame( -5.5, Size::number( '-5.5em' ) );
    }

    public function test_number_of_empty_or_unparseable_is_null(): void {
        $this->assertNull( Size::number( '' ) );
        $this->assertNull( Size::number( 'abc' ) );
    }
}
