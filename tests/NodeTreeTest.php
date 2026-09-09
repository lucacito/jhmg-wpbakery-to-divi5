<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Parsers\NodeTree;
use WPBakeryDivi5Converter\Parsers\ShortcodeParser;

/**
 * The node tree is what every converter handler walks, so the ids, the kinds
 * and the repairs a malformed document gets are pinned here.
 */
final class NodeTreeTest extends TestCase {

    /** @return array{roots: array, warnings: string[]} */
    private function tree( string $content ): array {
        return NodeTree::build( ShortcodeParser::parse( $content ) );
    }

    /** @param array<string, mixed> $node */
    private function ids( array $node ): array {
        return array_column( $node['children'], 'id' );
    }

    // --- ids and kinds --------------------------------------------------------

    public function test_a_section_row_column_document_gets_ids_and_kinds(): void {
        $tree = $this->tree( '[vc_section][vc_row][vc_column width="1/2"][vc_column_text]Hi[/vc_column_text][/vc_column][/vc_row][/vc_section]' );

        $this->assertSame( [], $tree['warnings'] );
        $this->assertCount( 1, $tree['roots'] );

        $section = $tree['roots'][0];
        $this->assertSame( 'vc_section-1', $section['id'] );
        $this->assertSame( 'section', $section['kind'] );

        $row = $section['children'][0];
        $this->assertSame( 'vc_row-1', $row['id'] );
        $this->assertSame( 'row', $row['kind'] );

        $column = $row['children'][0];
        $this->assertSame( 'vc_column-1', $column['id'] );
        $this->assertSame( 'column', $column['kind'] );

        $element = $column['children'][0];
        $this->assertSame( 'vc_column_text-1', $element['id'] );
        $this->assertSame( 'element', $element['kind'] );
    }

    public function test_a_node_always_carries_the_full_shape(): void {
        $tree = $this->tree( '[vc_section][vc_row][vc_column][/vc_column][/vc_row][/vc_section]' );

        $this->assertSame(
            [ 'id', 'tag', 'kind', 'atts', 'content', 'children', 'notes' ],
            array_keys( $tree['roots'][0] )
        );
    }

    public function test_ids_are_numbered_per_tag_in_document_order(): void {
        $tree = $this->tree( '[vc_row][vc_column][/vc_column][vc_column][/vc_column][/vc_row][vc_row][vc_column][/vc_column][/vc_row]' );

        $rows = $tree['roots'][0]['children'];
        $this->assertSame( [ 'vc_row-1', 'vc_row-2' ], array_column( $rows, 'id' ) );
        $this->assertSame( [ 'vc_column-1', 'vc_column-2' ], $this->ids( $rows[0] ) );
        $this->assertSame( [ 'vc_column-3' ], $this->ids( $rows[1] ) );
    }

    public function test_a_unique_el_id_becomes_the_node_id(): void {
        $tree = $this->tree( '[vc_row el_id="hero"][vc_column][/vc_column][/vc_row]' );

        $this->assertSame( 'hero', $tree['roots'][0]['children'][0]['id'] );
    }

    public function test_a_repeated_el_id_falls_back_to_the_positional_id(): void {
        $tree = $this->tree( '[vc_row el_id="dup"][vc_column][/vc_column][/vc_row][vc_row el_id="dup"][vc_column][/vc_column][/vc_row]' );

        $this->assertSame( [ 'vc_row-1', 'vc_row-2' ], array_column( $tree['roots'][0]['children'], 'id' ) );
    }

    public function test_inner_rows_and_columns_get_their_own_kinds(): void {
        $tree = $this->tree( '[vc_row][vc_column][vc_row_inner][vc_column_inner][/vc_column_inner][/vc_row_inner][/vc_column][/vc_row]' );

        $inner_row = $tree['roots'][0]['children'][0]['children'][0]['children'][0];
        $this->assertSame( 'row_inner', $inner_row['kind'] );
        $this->assertSame( 'column_inner', $inner_row['children'][0]['kind'] );
    }

    // --- structural repair -----------------------------------------------------

    public function test_a_top_level_row_is_wrapped_in_an_implicit_section(): void {
        $tree = $this->tree( '[vc_row][vc_column][/vc_column][/vc_row]' );

        $section = $tree['roots'][0];
        $this->assertSame( 'vc_section', $section['tag'] );
        $this->assertSame( 'section', $section['kind'] );
        $this->assertTrue( $section['implicit'] );
        $this->assertSame( [ 'vc_row-1' ], $this->ids( $section ) );
        $this->assertSame( [], $tree['warnings'] );
    }

    public function test_consecutive_top_level_rows_share_one_implicit_section(): void {
        $tree = $this->tree( '[vc_row][/vc_row][vc_row][/vc_row][vc_section][vc_row][/vc_row][/vc_section]' );

        $this->assertCount( 2, $tree['roots'] );
        $this->assertSame( [ 'vc_row-1', 'vc_row-2' ], $this->ids( $tree['roots'][0] ) );
        $this->assertFalse( isset( $tree['roots'][1]['implicit'] ) );
    }

    public function test_a_loose_top_level_element_is_wrapped_in_a_section_row_and_column(): void {
        $tree = $this->tree( '[vc_column_text]Hi[/vc_column_text]' );

        $section = $tree['roots'][0];
        $this->assertSame( 'vc_section', $section['tag'] );
        $row = $section['children'][0];
        $this->assertSame( 'vc_row', $row['tag'] );
        $this->assertTrue( $row['implicit'] );
        $column = $row['children'][0];
        $this->assertSame( 'vc_column', $column['tag'] );
        $this->assertSame( '1/1', $column['atts']['width'] );
        $this->assertSame( 'vc_column_text', $column['children'][0]['tag'] );

        $this->assertContains( 'content outside a row: vc_column_text', $tree['warnings'] );
    }

    public function test_a_row_child_that_is_not_a_column_gets_an_implicit_full_width_column(): void {
        $tree = $this->tree( '[vc_row][vc_column_text]Hi[/vc_column_text][/vc_row]' );

        $row    = $tree['roots'][0]['children'][0];
        $column = $row['children'][0];
        $this->assertSame( 'vc_column', $column['tag'] );
        $this->assertTrue( $column['implicit'] );
        $this->assertSame( '1/1', $column['atts']['width'] );
        $this->assertSame( 'vc_column_text', $column['children'][0]['tag'] );

        $this->assertContains( 'row child is not a column: vc_column_text', $tree['warnings'] );
    }

    public function test_an_element_directly_inside_an_inner_row_gets_an_implicit_inner_column(): void {
        $tree = $this->tree( '[vc_row][vc_column][vc_row_inner][vc_column_text]Hi[/vc_column_text][/vc_row_inner][/vc_column][/vc_row]' );

        $inner_row = $tree['roots'][0]['children'][0]['children'][0]['children'][0];
        $this->assertSame( 'vc_row_inner', $inner_row['tag'] );

        $column = $inner_row['children'][0];
        $this->assertSame( 'vc_column_inner', $column['tag'] );
        $this->assertSame( 'column_inner', $column['kind'] );
        $this->assertTrue( $column['implicit'] );
        $this->assertSame( '1/1', $column['atts']['width'] );
        $this->assertSame( 'vc_column_text', $column['children'][0]['tag'] );

        $this->assertContains( 'row child is not a column: vc_column_text', $tree['warnings'] );
    }

    public function test_a_correctly_nested_inner_row_is_left_alone(): void {
        $tree = $this->tree(
            '[vc_row][vc_column][vc_row_inner][vc_column_inner width="1/2"][vc_column_text]Hi[/vc_column_text][/vc_column_inner][/vc_row_inner][/vc_column][/vc_row]'
        );

        $inner_row = $tree['roots'][0]['children'][0]['children'][0]['children'][0];
        $this->assertSame( 'vc_column_inner', $inner_row['children'][0]['tag'] );
        $this->assertFalse( isset( $inner_row['children'][0]['implicit'] ) );
        $this->assertSame( [], $tree['warnings'] );
    }

    public function test_an_inner_row_inside_an_implicit_column_is_repaired_too(): void {
        $tree = $this->tree( '[vc_row][vc_row_inner][vc_column_text]Hi[/vc_column_text][/vc_row_inner][/vc_row]' );

        $column    = $tree['roots'][0]['children'][0]['children'][0];
        $inner_row = $column['children'][0];
        $this->assertTrue( $column['implicit'] );
        $this->assertSame( 'vc_row_inner', $inner_row['tag'] );
        $this->assertSame( 'vc_column_inner', $inner_row['children'][0]['tag'] );
        $this->assertTrue( $inner_row['children'][0]['implicit'] );
    }

    public function test_a_top_level_row_inner_is_treated_as_a_row(): void {
        $tree = $this->tree( '[vc_row_inner][vc_column_inner][/vc_column_inner][/vc_row_inner]' );

        $row = $tree['roots'][0]['children'][0];
        $this->assertSame( 'vc_row', $row['tag'] );
        $this->assertSame( 'row', $row['kind'] );
        $this->assertContains( 'vc_row_inner at the top level treated as a row', $tree['warnings'] );
    }

    public function test_a_column_outside_a_row_is_given_one(): void {
        $tree = $this->tree( '[vc_column width="1/2"][vc_column_text]Hi[/vc_column_text][/vc_column]' );

        $row = $tree['roots'][0]['children'][0];
        $this->assertSame( 'vc_row', $row['tag'] );
        $this->assertTrue( $row['implicit'] );
        $this->assertSame( 'vc_column', $row['children'][0]['tag'] );
        $this->assertFalse( isset( $row['children'][0]['implicit'] ) );
        $this->assertContains( 'content outside a row: vc_column', $tree['warnings'] );
    }

    public function test_section_children_that_are_not_rows_are_given_a_row_and_a_column(): void {
        $tree = $this->tree( '[vc_section][vc_column_text]Hi[/vc_column_text][/vc_section]' );

        $row = $tree['roots'][0]['children'][0];
        $this->assertSame( 'vc_row', $row['tag'] );
        $this->assertTrue( $row['implicit'] );
        $this->assertSame( 'vc_column_text', $row['children'][0]['children'][0]['tag'] );
        $this->assertContains( 'content outside a row: vc_column_text', $tree['warnings'] );
    }

    // --- text -------------------------------------------------------------------

    public function test_a_text_node_is_kept_and_warned_about(): void {
        $tree = $this->tree( '[vc_row][vc_column]Sixty characters of loose editor text that nobody meant to leave here[vc_column_text]Hi[/vc_column_text][/vc_column][/vc_row]' );

        $column = $tree['roots'][0]['children'][0]['children'][0];
        $text   = $column['children'][0];
        $this->assertSame( '#text', $text['tag'] );
        $this->assertSame( 'text', $text['kind'] );
        $this->assertSame( '#text-1', $text['id'] );
        $this->assertContains( 'text kept: "Sixty characters of loose editor text th"', $tree['warnings'] );
    }

    public function test_adjacent_text_nodes_are_merged_into_one(): void {
        $tree = $this->tree( '[vc_row][vc_column]one [[vc_row]] two[vc_column_text]Hi[/vc_column_text][/vc_column][/vc_row]' );

        $column = $tree['roots'][0]['children'][0]['children'][0];
        $this->assertSame( [ '#text', 'vc_column_text' ], array_column( $column['children'], 'tag' ) );
        $this->assertSame( 'one [vc_row] two', $column['children'][0]['text'] );
        $this->assertSame( 0, $column['children'][0]['offset'] );
    }

    public function test_loose_top_level_text_is_wrapped_like_any_other_loose_content(): void {
        $tree = $this->tree( 'Stray words[vc_row][vc_column][/vc_column][/vc_row]' );

        $this->assertSame( 'vc_section', $tree['roots'][0]['tag'] );
        $this->assertSame( '#text', $tree['roots'][0]['children'][0]['children'][0]['children'][0]['tag'] );
    }

    // --- content ------------------------------------------------------------------

    public function test_a_container_does_not_carry_a_copy_of_its_subtree(): void {
        $tree = $this->tree( '[vc_row][vc_column][vc_single_image image="7"][/vc_column][/vc_row]' );

        $row = $tree['roots'][0]['children'][0];
        $this->assertSame( '', $row['content'] );
        $this->assertSame( '', $row['children'][0]['content'] );
        $this->assertSame( '', $row['children'][0]['children'][0]['content'] );
    }

    public function test_a_raw_content_tag_keeps_its_content(): void {
        $tree = $this->tree( '[vc_row][vc_column][vc_column_text]<p>Hello</p>[/vc_column_text][/vc_column][/vc_row]' );

        $text = $tree['roots'][0]['children'][0]['children'][0]['children'][0];
        $this->assertSame( '<p>Hello</p>', $text['content'] );
    }

    // --- census ---------------------------------------------------------------------

    public function test_census_counts_elements_only(): void {
        $tree = $this->tree(
            '[vc_row][vc_column][vc_single_image image="1"][vc_single_image image="2"][vc_column_text]Hi[/vc_column_text][/vc_column][/vc_row]'
            . '[vc_row][vc_column][vc_single_image image="3"][/vc_column][/vc_row]'
        );

        $this->assertSame(
            [ 'vc_column_text' => 1, 'vc_single_image' => 3 ],
            NodeTree::census( $tree['roots'] )
        );
    }

    public function test_census_of_an_empty_tree_is_empty(): void {
        $this->assertSame( [], NodeTree::census( [] ) );
    }

    public function test_an_empty_document_has_no_roots(): void {
        $tree = NodeTree::build( [] );

        $this->assertSame( [], $tree['roots'] );
        $this->assertSame( [], $tree['warnings'] );
    }
}
