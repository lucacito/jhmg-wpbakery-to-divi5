<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Helpers\DynamicContent;

/**
 * Port of the Beaver converter's
 * `BeaverDivi5Converter\Helpers\FieldConnections::token()`
 * (plugin/jhmg-converter-for-beaver-builder-to-divi/includes/helpers/class-field-connections.php).
 * Divi 5 resolves `$variable({"type":"content","value":{"name":...,"settings":...}})$`
 * wherever it appears inside a string (DynamicData::get_variable_values), so
 * the token format has to match exactly, JSON key order included.
 */
final class DynamicContentTest extends TestCase {

    public function test_token_with_no_settings(): void {
        $this->assertSame(
            '$variable({"type":"content","value":{"name":"post_title","settings":{}}})$',
            DynamicContent::token( 'post_title' )
        );
    }

    public function test_token_with_settings_for_vc_copyright(): void {
        $this->assertSame(
            '$variable({"type":"content","value":{"name":"current_date","settings":{"date_format":"custom","custom_date_format":"Y"}}})$',
            DynamicContent::token( 'current_date', [ 'date_format' => 'custom', 'custom_date_format' => 'Y' ] )
        );
    }

    public function test_token_with_settings_for_vc_custom_field(): void {
        $this->assertSame(
            '$variable({"type":"content","value":{"name":"post_meta_key","settings":{"meta_key":"my_key"}}})$',
            DynamicContent::token( 'post_meta_key', [ 'meta_key' => 'my_key' ] )
        );
    }
}
