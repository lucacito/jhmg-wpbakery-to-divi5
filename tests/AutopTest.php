<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Helpers\Autop;

/**
 * Every expected string below is the literal output of WordPress 7.1's own
 * `wpautop()`, produced by running
 * `wp eval 'echo json_encode(wpautop("..."));' --allow-root` inside the
 * `jhmg-wpbakery-to-divi5-wordpress-1` Docker container and copying the
 * (unescaped) result — not reasoned about, so Autop::apply() has to agree
 * with core byte for byte, not merely "look like" a paragraph filter.
 */
final class AutopTest extends TestCase {

    public function test_double_newline_becomes_two_paragraphs(): void {
        $this->assertSame( "<p>A</p>\n<p>B</p>\n", Autop::apply( "A\n\nB" ) );
    }

    public function test_existing_p_tag_is_left_alone(): void {
        $this->assertSame( "<p>Already</p>\n", Autop::apply( '<p>Already</p>' ) );
    }

    public function test_a_list_is_not_wrapped_in_a_paragraph(): void {
        $this->assertSame(
            "<ul>\n<li>One</li>\n<li>Two</li>\n</ul>\n",
            Autop::apply( '<ul><li>One</li><li>Two</li></ul>' )
        );
    }

    public function test_single_newline_becomes_br_inside_a_paragraph(): void {
        $this->assertSame( "<p>Line one<br />\nLine two</p>\n", Autop::apply( "Line one\nLine two" ) );
    }

    public function test_a_paragraph_break_and_a_line_break_combine(): void {
        $this->assertSame(
            "<p>Para one</p>\n<p>Para two<br />\nwith a line break</p>\n",
            Autop::apply( "Para one\n\nPara two\nwith a line break" )
        );
    }

    public function test_pre_content_is_left_untouched(): void {
        $this->assertSame(
            "<pre>\nkeep\nthis\n</pre>\n<p>After.</p>\n",
            Autop::apply( "<pre>\nkeep\nthis\n</pre>\n\nAfter." )
        );
    }

    public function test_empty_or_whitespace_only_input_is_empty(): void {
        $this->assertSame( '', Autop::apply( '' ) );
        $this->assertSame( '', Autop::apply( '   ' ) );
    }
}
