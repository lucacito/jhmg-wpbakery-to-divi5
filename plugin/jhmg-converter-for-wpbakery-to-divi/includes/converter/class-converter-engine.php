<?php

namespace WPBakeryDivi5Converter\Converter;

use WPBakeryDivi5Converter\Converter\Registry\ConverterRegistry;
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

    private bool $countingApproximate = false;

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
     * @param array $document One of `['content' => string, 'meta' => array]`, the
     *   `WPBakeryDocumentParser::parse()` return value (`['nodes' => …]`),
     *   `['roots' => array]`, or a list of tree nodes.
     * @param array{mode?: string, attachments?: array<int,string>, render_shortcodes?: bool} $options
     * @return array{divi: array{elements: array}, unsupported: array, report: array}
     */
    public function convert( array $document, array $options = [] ): array {
        $this->options = $this->resolveOptions( $options );

        [ $roots, $page_css ] = $this->treeFrom( $document );

        if ( trim( $page_css ) !== '' ) {
            $this->logNotCarriedOver(
                'custom_code',
                'page',
                'page custom CSS (' . strlen( trim( $page_css ) ) . ' characters); paste it into Divi > Theme Options > Custom CSS'
            );
        }

        $elements = [];
        foreach ( $this->convertChildren( $roots ) as $block ) {
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

    /** @param array<string,mixed> $node */
    public function convertNode( array $node ): array {
        $converter = $this->registry->getConverter( $node );
        $tag       = (string) ( $node['tag'] ?? '' );

        if ( $converter instanceof ConverterInterface ) {
            if ( ! $this->registry->isApproximate( $node ) ) {
                return $converter->convert( $node );
            }

            $this->flagApproximate( (string) ( $node['id'] ?? '' ), $tag, $this->registry->converterName( $node ) );
            $this->countingApproximate = true;
            try {
                return $converter->convert( $node );
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

        return $this->registry->defaultConverter( $node )->convert( $node );
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

    /** One Divi module produced. `$type` is the module's short name (`section`, `text`). */
    public function logConverted( string $type ): void {
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

    /** @param string $kind animation|visibility|background|interaction|integration|custom_code|addon|layout */
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
            'quality'             => [
                'module_coverage' => $all > 0 ? (int) round( $converted / $all * 100 ) : 100,
                'settings_issues' => count( $this->skippedSettings ),
            ],
        ];
    }
}
