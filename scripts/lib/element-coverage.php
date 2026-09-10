<?php
/**
 * How a tag is classified for coverage, in one place.
 *
 * `scripts/element-coverage.php` prints coverage and `tests/ReleaseMetadataTest.php`
 * checks the readme's numbers against it, so the classification lives here and
 * both call it — two copies of "is this tag exact, approximate, or read by its
 * parent?" would drift the moment a handler changed, and the readme would then
 * be checked against the wrong arithmetic.
 *
 * Everything here is **corpus-independent**: it reads the registry, the
 * normaliser and `ThemeShortcodes`, and never looks at a fixture or an export.
 * That is the point — the readme quotes what this plugin converts, which is a
 * fact about the code, while how often a tag turns up in the corpora on one
 * machine is a fact about that machine's checkout (part of the corpus is
 * gitignored). Occurrence counts stay in the script, which is where they are
 * printed; they never reach the readme and no test depends on them.
 *
 * Required rather than autoloaded — it is not plugin code and must not ship —
 * so the class carries the repository's global `WBDC_` prefix.
 *
 * @see scripts/lib/corpus-shortcodes.php for the same "one scanner, two callers" split.
 */

if ( ! class_exists( 'WBDC_Element_Coverage' ) ) {

    final class WBDC_Element_Coverage {

        /**
         * The handler a tag reaches, after the normaliser has had its go at
         * the tag (`vc_button` is `vc_btn` by the time a converter sees it).
         *
         * @return array{handler: string, note: string} `note` is '' (exact),
         *   'approximate', or 'panel' (read by its parent element).
         */
        public static function handler_for( string $tag ): array {
            $registry  = ( new \WPBakeryDivi5Converter\Converter\ConverterEngine() )->registry();
            $effective = \WPBakeryDivi5Converter\Parsers\AttributeNormaliser::normalise( $tag, [] )['tag'];
            $kind      = \WPBakeryDivi5Converter\Tests\RegisteredShortcodesTest::STRUCTURE_KINDS[ $effective ] ?? 'element';
            $converter = $registry->getConverter( [ 'tag' => $effective, 'kind' => $kind ] );

            if ( $converter === null ) {
                $parent = \WPBakeryDivi5Converter\Tests\RegisteredShortcodesTest::READ_BY_THEIR_PARENT[ $effective ] ?? null;

                return $parent === null
                    ? [ 'handler' => '', 'note' => '' ]
                    : [ 'handler' => '(read by ' . $parent . ')', 'note' => 'panel' ];
            }

            return [
                'handler' => basename( str_replace( '\\', '/', get_class( $converter ) ) ),
                'note'    => in_array( $effective, $registry->approximateTags(), true ) ? 'approximate' : '',
            ];
        }

        /** @return string[] The 75 tags WPBakery registers, across both eras. */
        public static function registered_tags(): array {
            return \WPBakeryDivi5Converter\Tests\RegisteredShortcodesTest::registeredTags();
        }

        /** @return string[] Render-template and vendor tags, plus WooCommerce's eighteen. */
        public static function template_only_tags(): array {
            return array_merge(
                \WPBakeryDivi5Converter\Tests\RegisteredShortcodesTest::TEMPLATE_ONLY,
                \WPBakeryDivi5Converter\Converter\Handlers\WoocommerceConverter::SHORTCODES
            );
        }

        /**
         * @param string[] $tags
         * @return array<int, array{tag: string, handler: string, note: string}>
         */
        public static function rows_for( array $tags ): array {
            $rows = [];

            foreach ( $tags as $tag ) {
                $rows[] = [ 'tag' => $tag ] + self::handler_for( $tag );
            }

            return $rows;
        }

        /**
         * The theme and add-on elements this plugin ships a handler of its own
         * for, by family.
         *
         * A fact about the registry, not about any corpus: it is every tag the
         * registry knows that WPBakery does not ship, grouped by
         * `ThemeShortcodes::family()`. `#text` and any other internal
         * pseudo-tag is not a shortcode anybody writes, so it is left out.
         *
         * @return array<string, string[]> family label ⇒ tags, families sorted, tags sorted.
         */
        public static function family_handlers(): array {
            $registry   = ( new \WPBakeryDivi5Converter\Converter\ConverterEngine() )->registry();
            $registered = self::registered_tags();
            $extra      = self::template_only_tags();
            $families   = [];

            foreach ( $registry->knownTags() as $tag ) {
                if ( $tag === '' || $tag[0] === '#' ) {
                    continue;
                }
                if ( in_array( $tag, $registered, true ) || in_array( $tag, $extra, true ) ) {
                    continue;
                }

                $families[ \WPBakeryDivi5Converter\Helpers\ThemeShortcodes::family( $tag )['label'] ][] = $tag;
            }

            foreach ( $families as $label => $tags ) {
                sort( $tags );
                $families[ $label ] = $tags;
            }

            ksort( $families );

            return $families;
        }

        /**
         * Every corpus-independent coverage number, which is every number the
         * readme is allowed to quote.
         *
         * @return array{
         *   registered_tags: int, registered_mapped: int, registered_exact: int,
         *   registered_approximate: int, registered_read_by_parent: int, registered_unmapped: int,
         *   template_only_tags: int, template_only_mapped: int,
         *   family_handlers: array<string,int>, family_handler_tags: int
         * }
         */
        public static function summary(): array {
            $registered = self::rows_for( self::registered_tags() );
            $extra      = self::rows_for( self::template_only_tags() );

            $panels      = count( array_filter( $registered, static fn( array $r ): bool => $r['note'] === 'panel' ) );
            $approximate = count( array_filter( $registered, static fn( array $r ): bool => $r['note'] === 'approximate' ) );
            $mapped      = count( array_filter( $registered, static fn( array $r ): bool => $r['handler'] !== '' ) );

            $families = array_map( 'count', self::family_handlers() );

            return [
                'registered_tags'           => count( $registered ),
                'registered_mapped'         => $mapped,
                'registered_exact'          => $mapped - $approximate - $panels,
                'registered_approximate'    => $approximate,
                'registered_read_by_parent' => $panels,
                'registered_unmapped'       => count( $registered ) - $mapped,
                'template_only_tags'        => count( $extra ),
                'template_only_mapped'      => count( array_filter( $extra, static fn( array $r ): bool => $r['handler'] !== '' ) ),
                'family_handlers'           => $families,
                'family_handler_tags'       => array_sum( $families ),
            ];
        }
    }
}
