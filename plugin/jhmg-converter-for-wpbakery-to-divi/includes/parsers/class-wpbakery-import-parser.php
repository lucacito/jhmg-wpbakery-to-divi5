<?php
/**
 * Turns an uploaded file into conversion items.
 *
 * Two formats, because there are two ways a WPBakery page leaves the site it
 * was built on:
 *
 *  - **A WordPress export (WXR).** Tools → Export writes one; a theme ships
 *    its demo content as one. Every item whose `content:encoded` holds a
 *    WPBakery layout becomes an item, with the three WPBakery metas beside it
 *    and the export's own attachments as the media map.
 *  - **A `.txt` / `.html` file** holding the shortcodes themselves — what
 *    somebody gets by copying the page out of the editor's text view. One
 *    item, titled from the file name.
 *
 * There is no third format to support: WPBakery has no layout export of its
 * own, and this converter never scrapes rendered HTML.
 */

namespace WPBakeryDivi5Converter\Parsers;

use WPBakeryDivi5Converter\Conversion\InstalledPostSource;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPBakeryImportParser {

    /**
     * A ceiling on one upload, so a whole-site export cannot turn one request
     * into thousands of conversions. The free plugin converts one item anyway;
     * this is about the parse.
     *
     * Reaching it is recorded in `warnings()` rather than passed over: the
     * upload screen chooses which items to convert, and it can only choose
     * from what it was given.
     */
    const MAX_ITEMS = 500;

    /** The post types whose content the upload form offers with the box ticked (task-11-amendments §1). */
    const DEFAULT_SELECTED_POST_TYPES = [ 'page', 'post' ];

    private WxrReader $wxr;

    /** @var string[] What the last parse could not do, for the screen that called it. */
    private array $warnings = [];

    public function __construct( ?WxrReader $wxr = null ) {
        $this->wxr = $wxr ?? new WxrReader();
    }

    /**
     * What the last `parse()` / `parseString()` has to say beyond its items —
     * at present only that the file held more pages than one upload reads.
     *
     * @return string[]
     */
    public function warnings(): array {
        return $this->warnings;
    }

    /**
     * @return array[] Items shaped
     *   ['title','post_type','source_post_type','post_name','template_type','content','meta',
     *    'attachments','mode','error','source_ref'].
     * @throws \RuntimeException On unreadable, empty or unrecognised input.
     */
    public function parse( string $file_path, string $file_name = '' ): array {
        if ( ! is_readable( $file_path ) ) {
            throw new \RuntimeException( 'Import file is not readable.' );
        }

        $raw = (string) file_get_contents( $file_path );
        if ( trim( self::withoutBom( $raw ) ) === '' ) {
            throw new \RuntimeException( 'Import file is empty.' );
        }

        return $this->parseString( $raw, $file_name !== '' ? $file_name : basename( $file_path ) );
    }

    /**
     * @return array[]
     * @throws \RuntimeException
     */
    public function parseString( string $raw, string $file_name = '' ): array {
        $this->warnings = [];

        // A byte-order mark is what a Windows editor puts in front of a file it
        // saved as UTF-8, and `ltrim()` does not consider it whitespace: without
        // this, a perfectly good export sniffs as neither XML nor shortcodes.
        $raw     = self::withoutBom( $raw );
        $trimmed = ltrim( $raw );

        if ( str_starts_with( $trimmed, '<?xml' ) || str_starts_with( $trimmed, '<rss' ) ) {
            $items = $this->fromWxr( $raw, $file_name );

            if ( $items === [] ) {
                throw new \RuntimeException(
                    'No WPBakery layouts found in the file. Export the pages from Tools → Export on the WPBakery site, or upload the page\'s shortcodes as a .txt file.'
                );
            }

            return $items;
        }

        if ( WPBakeryDocumentParser::isWPBakeryContent( $raw ) ) {
            return [ $this->item(
                $this->titleFromFileName( $file_name ),
                'page',
                '',
                $raw,
                [],
                [],
                $file_name
            ) ];
        }

        throw new \RuntimeException(
            'Unrecognised file: upload a WordPress export (.xml), or a .txt/.html file holding the page\'s WPBakery shortcodes.'
        );
    }

    /**
     * @return array[]
     * @throws \RuntimeException
     */
    private function fromWxr( string $xml, string $file_name ): array {
        $posts       = $this->wxr->read( $xml );
        $attachments = WxrReader::attachments( $posts );

        $items = [];
        $held  = 0;

        foreach ( $posts as $post ) {
            $content = (string) $post['content'];

            if ( ! WPBakeryDocumentParser::isWPBakeryContent( $content ) ) {
                continue;
            }

            $held++;

            // Past the cap the rest are still counted, so the warning can say
            // how many pages the file holds rather than only that it held too
            // many.
            if ( $held > self::MAX_ITEMS ) {
                continue;
            }

            $items[] = $this->item(
                (string) $post['title'],
                (string) $post['post_type'],
                (string) $post['post_name'],
                $content,
                $this->wpbakeryMeta( is_array( $post['meta'] ?? null ) ? $post['meta'] : [] ),
                $attachments,
                $file_name
            );
        }

        if ( $held > self::MAX_ITEMS ) {
            $this->warnings[] = sprintf(
                /* translators: 1: number of WPBakery pages in the file, 2: how many of them were read */
                __( 'This file holds %1$d WPBakery pages; the first %2$d were read and the rest were left.', 'jhmg-converter-for-wpbakery-to-divi' ),
                $held,
                self::MAX_ITEMS
            );
        }

        return $items;
    }

    /**
     * Only the three metas the converter reads.
     *
     * A real export's postmeta runs to dozens of keys per item — the theme's
     * page options, ACF fields, SEO records — and none of them is this
     * converter's business.
     *
     * @param array<string,string> $meta
     * @return array<string,string>
     */
    private function wpbakeryMeta( array $meta ): array {
        $wanted = [];

        foreach ( InstalledPostSource::META_KEYS as $key ) {
            if ( isset( $meta[ $key ] ) ) {
                $wanted[ $key ] = (string) $meta[ $key ];
            }
        }

        return $wanted;
    }

    /**
     * @param array<string,string> $meta
     * @param array<int, array{url: string, sizes: array<string,string>}> $attachments
     * @return array<string,mixed>
     */
    private function item(
        string $title,
        string $source_post_type,
        string $post_name,
        string $content,
        array $meta,
        array $attachments,
        string $file_name
    ): array {
        $is_library = in_array( $source_post_type, InstalledPostSource::LIBRARY_POST_TYPES, true );

        return [
            'title'            => trim( $title ) !== '' ? trim( $title ) : 'Imported Page',
            'post_type'        => ( ! $is_library && $source_post_type === 'post' ) ? 'post' : 'page',
            'source_post_type' => $source_post_type,
            'post_name'        => $post_name,
            'template_type'    => $is_library ? 'library' : '',
            'content'          => $content,
            'meta'             => $meta,
            'attachments'      => $attachments,
            // Nothing on this site rendered this page, and nothing here can:
            // an element with no handler is a labelled placeholder, not a
            // static copy of markup that was never produced.
            'mode'             => 'import',
            'error'            => '',
            'source_ref'       => [ 'kind' => 'upload', 'post_id' => null, 'file' => $file_name !== '' ? basename( $file_name ) : null ],
        ];
    }

    /** A UTF-8 byte-order mark, if the file starts with one. */
    private static function withoutBom( string $raw ): string {
        return str_starts_with( $raw, "\xEF\xBB\xBF" ) ? substr( $raw, 3 ) : $raw;
    }

    private function titleFromFileName( string $file_name ): string {
        $base = pathinfo( $file_name, PATHINFO_FILENAME );

        return $base === '' ? 'Imported Page' : ucwords( str_replace( [ '-', '_' ], ' ', $base ) );
    }
}
