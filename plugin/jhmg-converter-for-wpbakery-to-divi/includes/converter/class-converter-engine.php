<?php

namespace WPBakeryDivi5Converter\Converter;

use WPBakeryDivi5Converter\Converter\Registry\ConverterRegistry;
use WPBakeryDivi5Converter\Converter\Handlers;
use WPBakeryDivi5Converter\Helpers\Arr;
use WPBakeryDivi5Converter\Helpers\ThemeShortcodes;
use WPBakeryDivi5Converter\Parsers\NodeTree;
use WPBakeryDivi5Converter\Parsers\WPBakeryDocumentParser;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Walks a WPBakery node tree and dispatches every node to the handler the
 * registry knows for it, collecting the report as it goes.
 *
 * One engine per conversion: it accumulates counts, warnings and the
 * conversion options across the whole walk, and handlers reach back into it
 * for both.
 *
 * Nothing ever leaves without a block. A tag with no handler at all still
 * produces something — a rendered static copy on the site the page lives on,
 * a labelled placeholder from an export — so the only thing `unsupported`
 * records is a WPBakery core element this converter has not implemented yet;
 * a theme's shortcode is never in it (spec §7).
 */
class ConverterEngine {

    /** @var array{mode: string, attachments: array<int,string>, render_shortcodes: bool} */
    private array $options = [
        'mode'              => 'import',
        'attachments'       => [],
        'render_shortcodes' => false,
    ];

    private ConverterRegistry $registry;

    /** @var array<int, array{id: ?string, tag: ?string}> */
    private array $unsupported = [];
    /** @var array<string,int> */
    private array $counts = [];
    /** @var array<string,int> */
    private array $approximateCounts = [];
    /** @var array<int, array{node_id: string, tag: string, matched_to: string}> */
    private array $approximateMatches = [];
    /** @var string[] */
    private array $warnings = [];
    /** @var string[] */
    private array $skippedSettings = [];
    /** @var array<int, array{node_id: string, setting_key: string, ref: string}> */
    private array $unresolvedGlobals = [];
    /** @var array<int, array{kind: string, node_id: string, detail: string}> */
    private array $notCarriedOver = [];
    /** @var array<string,int> family label ⇒ how many elements carried it. */
    private array $themeElements = [];
    /** @var string[] node ids rendered into a static copy. */
    private array $staticCopies = [];
    /** @var string[] node ids whose design options landed in module custom CSS. */
    private array $customCssCarried = [];
    /** @var array<int, array{node_id: string, attachment_id: int}> */
    private array $unresolvedMedia = [];
    private int $textNodes = 0;
    private int $bracketedText = 0;

    private bool $countingApproximate = false;

    /**
     * The one theme or add-on family this page's elements belong to, when
     * there is exactly one — read off the tree before the walk begins, so an
     * attribution made while converting the first row already knows about the
     * last.
     */
    private ?string $detectedFamily = null;

    /**
     * How many `withConvertedCountSuppressed()` scopes are open. A counter
     * rather than a flag: a flattened accordion can hold a nested row whose
     * own children are flattened again, and the inner scope closing must not
     * un-suppress the outer one.
     */
    private int $suppressedConvertedCounts = 0;

    public function __construct() {
        $this->registry = new ConverterRegistry( $this );
    }

    public function registry(): ConverterRegistry {
        return $this->registry;
    }

    /** @return array{mode: string, attachments: array<int,string>, render_shortcodes: bool} */
    public function options(): array {
        return $this->options;
    }

    /**
     * The theme or add-on family this page belongs to, when there is exactly
     * one to name.
     *
     * A parameter WPBakery does not declare was added by somebody, and on a
     * page whose only non-core elements are one theme's, that theme is the
     * only candidate there is — which is a great deal more useful to the
     * reader than "a theme or plugin". Two families on the page and there is
     * no honest answer, so it says nothing.
     *
     * Only families whose kind is `addon` count: WooCommerce and Contact Form
     * 7 render a shop and a form, they do not bolt fields onto WPBakery's rows.
     */
    public function detectedFamily(): ?string {
        return $this->detectedFamily;
    }

    /** @param array<int,array<string,mixed>> $nodes */
    private static function familyOf( array $nodes ): ?string {
        $labels = [];

        self::collectFamilies( $nodes, $labels );

        return count( $labels ) === 1 ? (string) reset( $labels ) : null;
    }

    /**
     * @param array<int,array<string,mixed>> $nodes
     * @param array<string,string> $labels family key ⇒ label
     */
    private static function collectFamilies( array $nodes, array &$labels ): void {
        foreach ( $nodes as $node ) {
            if ( ! is_array( $node ) ) {
                continue;
            }

            $tag = (string) ( $node['tag'] ?? '' );

            if ( $tag !== '' && $tag !== '#text' && ! ThemeShortcodes::isCore( $tag ) ) {
                $family = ThemeShortcodes::family( $tag );

                if ( $family['key'] !== ThemeShortcodes::OTHER['key'] && $family['kind'] === 'addon' ) {
                    $labels[ $family['key'] ] = $family['label'];
                }
            }

            self::collectFamilies( is_array( $node['children'] ?? null ) ? $node['children'] : [], $labels );
        }
    }

    /**
     * @param array $document One of `['content' => string, 'meta' => array]`, the
     *   `WPBakeryDocumentParser::parse()` return value (`['nodes' => …]`),
     *   `['roots' => array]`, or a list of tree nodes.
     * @param array{mode?: string, attachments?: array<int,string>, render_shortcodes?: bool} $options
     * @return array{divi: array{elements: array}, unsupported: array, report: array}
     */
    public function convert( array $document, array $options = [] ): array {
        $this->options = $this->resolveOptions( $options );

        [ $roots, $page_css ] = $this->treeFrom( $document );

        $this->detectedFamily = self::familyOf( $roots );

        if ( trim( $page_css ) !== '' ) {
            $this->logNotCarriedOver(
                'custom_code',
                'page',
                'page custom CSS (' . strlen( trim( $page_css ) ) . ' characters); paste it into Divi > Theme Options > Custom CSS'
            );
        }

        // `.vc_section-has-fill` gives the section that paints 35px of top
        // padding and gives the *next* section the same, so the run has to be
        // walked where "next" means something: here, at the top level.
        $elements = [];
        foreach ( $this->convertChildren( Handlers\RowConverter::markFilled( $roots ) ) as $block ) {
            $elements[] = $this->ensureSection( $block );
        }

        return [
            'divi'        => [ 'elements' => $elements ],
            'unsupported' => $this->unsupported,
            'report'      => $this->getReport(),
        ];
    }

    /**
     * @param array{mode?: string, attachments?: array<int,string>, render_shortcodes?: bool} $options
     * @return array{mode: string, attachments: array<int,string>, render_shortcodes: bool}
     */
    private function resolveOptions( array $options ): array {
        $mode = ( $options['mode'] ?? '' ) === 'direct' ? 'direct' : 'import';

        $attachments = is_array( $options['attachments'] ?? null ) ? $options['attachments'] : [];

        // On the site the page lives on, a theme's shortcode renders exactly as
        // the visitor sees it; from an export there is nothing to render with.
        $render = $options['render_shortcodes'] ?? ( $mode === 'direct' && function_exists( 'do_shortcode' ) );

        return [
            'mode'              => $mode,
            'attachments'       => $attachments,
            'render_shortcodes' => (bool) $render,
        ];
    }

    /**
     * @return array{0: array<int,array<string,mixed>>, 1: string} [tree roots, page custom CSS]
     */
    private function treeFrom( array $document ): array {
        if ( isset( $document['roots'] ) && is_array( $document['roots'] ) ) {
            return [ $document['roots'], (string) ( $document['page_css'] ?? '' ) ];
        }

        // `WPBakeryDocumentParser::parse()` output, which is what
        // scripts/docker/convert-run.php hands over.
        if ( isset( $document['nodes'] ) && is_array( $document['nodes'] ) ) {
            return [ $this->buildTree( $document['nodes'] ), (string) ( $document['page_css'] ?? '' ) ];
        }

        if ( isset( $document['content'] ) && is_string( $document['content'] ) ) {
            $meta   = is_array( $document['meta'] ?? null ) ? $document['meta'] : [];
            $parsed = WPBakeryDocumentParser::parse( $document['content'], $meta );

            return [ $this->buildTree( $parsed['nodes'] ), $parsed['page_css'] ];
        }

        // A list whose entries carry 'children' is already a tree.
        if ( Arr::isList( $document ) && isset( $document[0]['children'] ) ) {
            return [ $document, '' ];
        }

        return [ [], '' ];
    }

    /**
     * @param array<int,array<string,mixed>> $nodes
     * @return array<int,array<string,mixed>>
     */
    private function buildTree( array $nodes ): array {
        $tree = NodeTree::build( $nodes );

        foreach ( $tree['warnings'] as $warning ) {
            $this->logWarning( $warning );
        }

        return $tree['roots'];
    }

    /**
     * Every root block must be a section. `NodeTree` guarantees it for a parsed
     * document; a caller that hands the engine its own `roots` may not.
     */
    private function ensureSection( array $block ): array {
        $name = $block['name'] ?? '';
        if ( $name === 'divi/section' ) {
            return $block;
        }

        $id = (string) ( $block['id'] ?? uniqid( 'wbdc' ) );

        if ( $name === 'divi/row' ) {
            return $this->section( $id, [ $block ] );
        }

        $row_settings = BaseWPBakeryConverter::containerRowSettings();

        if ( $name === 'divi/column' ) {
            return $this->section( $id, [ [ 'id' => $id . '-row', 'name' => 'divi/row', 'settings' => $row_settings, 'elements' => [ $block ] ] ] );
        }

        return $this->section( $id, [ [
            'id'       => $id . '-row',
            'name'     => 'divi/row',
            'settings' => $row_settings,
            'elements' => [ [ 'id' => $id . '-col', 'name' => 'divi/column', 'settings' => BaseWPBakeryConverter::fullWidthColumnSettings(), 'elements' => [ $block ] ] ],
        ] ] );
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function section( string $id, array $rows ): array {
        return [ 'id' => $id . '-section', 'name' => 'divi/section', 'settings' => BaseWPBakeryConverter::sectionResetSettings(), 'elements' => $rows ];
    }

    /**
     * @param array<int,array<string,mixed>> $nodes
     * @return array<int,array<string,mixed>> A flat list of blocks (a handler may return one or several).
     */
    public function convertChildren( array $nodes ): array {
        $converted = [];

        foreach ( $nodes as $node ) {
            if ( ! is_array( $node ) ) {
                continue;
            }
            foreach ( self::asList( $this->convertNode( $node ) ) as $block ) {
                $converted[] = $block;
            }
        }

        return $converted;
    }

    /**
     * One node through its handler.
     *
     * A handler that throws must not cost the reader the rest of the page: a
     * single malformed element on a ten-year-old site would otherwise abort
     * the whole conversion. The failure is reported against the node and the
     * generic placeholder stands in for it, so the page still converts and the
     * report says exactly what went wrong and where.
     *
     * @param array<string,mixed> $node
     */
    public function convertNode( array $node ): array {
        $converter = $this->registry->getConverter( $node );
        $tag       = (string) ( $node['tag'] ?? '' );

        if ( $converter instanceof ConverterInterface ) {
            $approximate = $this->registry->isApproximate( $node );

            if ( $approximate ) {
                $this->flagApproximate( (string) ( $node['id'] ?? '' ), $tag, $this->registry->converterName( $node ) );
                $this->countingApproximate = true;
            }

            try {
                return $converter->convert( $node );
            } catch ( \Throwable $e ) {
                return $this->handlerFailed( $node, $converter, $e );
            } finally {
                $this->countingApproximate = false;
            }
        }

        // A container with no handler is structure, not content: there is
        // nothing meaningful to stand in for it.
        if ( in_array( $node['kind'] ?? '', [ 'section', 'row', 'row_inner', 'column', 'column_inner' ], true ) ) {
            $this->logWarning( "Structural node {$tag} has no handler; its children were kept in place." );

            return $this->convertChildren( $node['children'] ?? [] );
        }

        // A WPBakery element this converter does not implement yet is the only
        // thing `unsupported` records; a theme's shortcode is handled, always.
        if ( ThemeShortcodes::isCore( $tag ) ) {
            $this->unsupported[] = [ 'id' => $node['id'] ?? null, 'tag' => $tag ];
        }

        return $this->placeholderFor( $node );
    }

    /**
     * A handler threw. The node is reported and replaced with the placeholder
     * every unconvertible element gets; if even that fails, the node is
     * reported and dropped rather than taking the page with it.
     */
    private function handlerFailed( array $node, ConverterInterface $converter, \Throwable $e ): array {
        $node_id = (string) ( $node['id'] ?? '' );
        $tag     = (string) ( $node['tag'] ?? '' );
        $handler = basename( str_replace( '\\', '/', get_class( $converter ) ) );
        $detail  = sprintf( '%s: %s', get_class( $e ), $e->getMessage() );

        $this->logWarning( "handler {$handler} failed on {$node_id} ({$tag}): {$detail}" );
        $this->logNotCarriedOver( 'error', $node_id, "{$tag} could not be converted ({$detail}); a labelled placeholder stands in for it" );

        return $this->placeholderFor( $node );
    }

    /** The generic placeholder, itself guarded: nothing here may abort a page. */
    private function placeholderFor( array $node ): array {
        try {
            return $this->registry->defaultConverter( $node )->convert( $node );
        } catch ( \Throwable $e ) {
            $this->logWarning( sprintf(
                'nothing could stand in for %s (%s): %s',
                (string) ( $node['id'] ?? '' ),
                (string) ( $node['tag'] ?? '' ),
                $e->getMessage()
            ) );

            return [];
        }
    }

    /** A handler result normalised to a list of blocks. */
    public static function asList( array $result ): array {
        if ( empty( $result ) ) {
            return [];
        }
        if ( isset( $result['name'] ) ) {
            return [ $result ];
        }

        return array_values( array_filter( $result, static fn( $b ): bool => is_array( $b ) && ! empty( $b ) ) );
    }

    // -------------------------------------------------------------------------
    // Reporting
    // -------------------------------------------------------------------------

    /**
     * Runs `$fn` with `logConverted()` switched off.
     *
     * `ContentFlattener` converts a tab's or an accordion item's children only
     * to render them back to HTML: the blocks it produces are read and thrown
     * away, and none of them is in the finished page. Counting them would
     * inflate `converted` — and with it `quality.module_coverage`, which is
     * the ratio of cleanly converted source elements — with modules that do
     * not exist.
     *
     * Only the count is suppressed. Warnings, `not_carried_over`, static
     * copies, skipped settings, unresolved media and theme-element counts all
     * still record, because those describe the source, which is real whatever
     * happened to the blocks.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function withConvertedCountSuppressed( callable $fn ): mixed {
        $this->suppressedConvertedCounts++;

        try {
            return $fn();
        } finally {
            $this->suppressedConvertedCounts--;
        }
    }

    /** One Divi module produced. `$type` is the module's short name (`section`, `text`). */
    public function logConverted( string $type ): void {
        if ( $this->suppressedConvertedCounts > 0 ) {
            return;
        }

        if ( $this->countingApproximate ) {
            $this->approximateCounts[ $type ] = ( $this->approximateCounts[ $type ] ?? 0 ) + 1;

            return;
        }

        $this->counts[ $type ] = ( $this->counts[ $type ] ?? 0 ) + 1;
    }

    public function flagApproximate( string $node_id, string $tag, string $matched_to ): void {
        $this->approximateMatches[] = [ 'node_id' => $node_id, 'tag' => $tag, 'matched_to' => $matched_to ];
    }

    public function logWarning( string $message ): void {
        if ( ! in_array( $message, $this->warnings, true ) ) {
            $this->warnings[] = $message;
        }
    }

    public function logSkippedSetting( string $message ): void {
        if ( ! in_array( $message, $this->skippedSettings, true ) ) {
            $this->skippedSettings[] = $message;
        }
    }

    /** @param string $kind animation|visibility|background|interaction|integration|custom_code|addon|layout|hover|error */
    public function logNotCarriedOver( string $kind, string $node_id, string $detail ): void {
        $entry = [ 'kind' => $kind, 'node_id' => $node_id, 'detail' => $detail ];
        if ( ! in_array( $entry, $this->notCarriedOver, true ) ) {
            $this->notCarriedOver[] = $entry;
        }
    }

    public function logUnresolvedGlobal( string $node_id, string $key, string $ref ): void {
        $entry = [ 'node_id' => $node_id, 'setting_key' => $key, 'ref' => $ref ];
        if ( ! in_array( $entry, $this->unresolvedGlobals, true ) ) {
            $this->unresolvedGlobals[] = $entry;
        }
    }

    /** One element belonging to a theme or add-on family (spec §7). */
    public function logThemeElement( string $family_label, string $node_id, string $tag ): void {
        $this->themeElements[ $family_label ] = ( $this->themeElements[ $family_label ] ?? 0 ) + 1;
    }

    /** A shortcode rendered on this site and kept as HTML. */
    public function logStaticCopy( string $node_id ): void {
        if ( ! in_array( $node_id, $this->staticCopies, true ) ) {
            $this->staticCopies[] = $node_id;
        }
    }

    /** Design-options CSS this converter could not express as a Divi setting. */
    public function logCustomCssCarried( string $node_id ): void {
        if ( ! in_array( $node_id, $this->customCssCarried, true ) ) {
            $this->customCssCarried[] = $node_id;
        }
    }

    /** An attachment id with no URL: not on this site, not in the export's map. */
    public function logUnresolvedMedia( string $node_id, int $attachment_id ): void {
        $entry = [ 'node_id' => $node_id, 'attachment_id' => $attachment_id ];
        if ( ! in_array( $entry, $this->unresolvedMedia, true ) ) {
            $this->unresolvedMedia[] = $entry;
        }
    }

    /** A run of text that sat between shortcodes and was kept as a text module. */
    public function logTextNode(): void {
        $this->textNodes++;
    }

    /**
     * A `[1]`-style token the default converter re-emitted as text.
     *
     * Not a shortcode anybody registered and not a theme's element: a footnote
     * marker, a citation, a stray bracket, which WordPress prints as it stands
     * and only this converter's parser ever read as a tag (task-7
     * amendments §5). The report counts them so a page full of them reads as
     * "12 bracketed tokens kept as text" rather than as twelve warnings the
     * reader has to recognise one by one.
     */
    public function logBracketedText(): void {
        $this->bracketedText++;
    }

    /** @return array<int, array{id: ?string, tag: ?string}> */
    public function getUnsupported(): array {
        return $this->unsupported;
    }

    /** @return array<int, array{kind: string, node_id: string, detail: string}> */
    public function getNotCarriedOver(): array {
        return $this->notCarriedOver;
    }

    /** The spec §10 report. */
    public function getReport(): array {
        $converted = array_sum( $this->counts );
        // One approximate element may emit several Divi blocks; coverage counts
        // source elements, not blocks.
        $approximate = count( $this->approximateMatches );
        $unsupported = count( $this->unsupported );
        $all         = $converted + $approximate + $unsupported;

        return [
            'converted'           => $this->counts,
            'approximate'         => $this->approximateCounts,
            'approximate_matches' => $this->approximateMatches,
            'warnings'            => $this->warnings,
            'skipped_settings'    => $this->skippedSettings,
            'unresolved_globals'  => $this->unresolvedGlobals,
            'not_carried_over'    => $this->notCarriedOver,
            'theme_elements'      => $this->themeElements,
            'static_copies'       => $this->staticCopies,
            'custom_css_carried'  => $this->customCssCarried,
            'unresolved_media'    => $this->unresolvedMedia,
            'text_nodes'          => $this->textNodes,
            'bracketed_text'      => $this->bracketedText,
            'quality'             => [
                'module_coverage' => $all > 0 ? (int) round( $converted / $all * 100 ) : 100,
                'settings_issues' => count( $this->skippedSettings ),
            ],
        ];
    }
}
