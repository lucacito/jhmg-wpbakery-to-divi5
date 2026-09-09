<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Helpers\DiviRequirement;

final class DiviRequirementTest extends TestCase {

    protected function setUp(): void {
        wbdc_test_reset_hooks();
    }

    public function test_it_is_satisfied_by_divi_5(): void {
        $GLOBALS['__test_divi_version'] = '5.12.1';
        $this->assertTrue( DiviRequirement::is_satisfied() );
        $this->assertSame( '', DiviRequirement::failure_reason() );
        $this->assertSame( '', DiviRequirement::message() );
    }

    public function test_divi_4_is_too_old(): void {
        $GLOBALS['__test_divi_version'] = '4.27.4';
        $this->assertFalse( DiviRequirement::is_satisfied() );
        $this->assertSame( 'too_old', DiviRequirement::failure_reason() );
        $this->assertStringContainsString( '4.27.4', DiviRequirement::message() );
        $this->assertStringContainsString( '5.0.0', DiviRequirement::message() );
    }

    public function test_public_alpha_builds_are_accepted(): void {
        $GLOBALS['__test_divi_version'] = '5.0.0-public-alpha.18.2';
        $this->assertTrue( DiviRequirement::is_satisfied() );
    }

    public function test_no_divi_is_reported_as_missing(): void {
        $GLOBALS['__test_divi_present'] = false;
        $this->assertSame( 'missing', DiviRequirement::failure_reason() );
        $this->assertStringContainsString( 'no Divi installation was found', DiviRequirement::message() );
    }

    public function test_activation_records_a_failure_and_clears_it_when_satisfied(): void {
        $GLOBALS['__test_divi_present'] = false;
        DiviRequirement::on_activation();
        $this->assertSame( 'missing', get_option( DiviRequirement::ACTIVATION_NOTICE_OPTION ) );

        wbdc_test_reset_divi();
        DiviRequirement::on_activation();
        $this->assertFalse( get_option( DiviRequirement::ACTIVATION_NOTICE_OPTION ) );
    }

    public function test_notice_is_silent_when_satisfied(): void {
        ob_start();
        DiviRequirement::render_notice();
        $this->assertSame( '', ob_get_clean() );
    }

    public function test_notice_is_printed_for_a_missing_divi(): void {
        $GLOBALS['__test_divi_present'] = false;
        ob_start();
        DiviRequirement::render_notice();
        $html = ob_get_clean();
        $this->assertStringContainsString( 'notice-error', $html );
        $this->assertStringContainsString( 'Divi 5.0.0 or newer', $html );
    }
}
