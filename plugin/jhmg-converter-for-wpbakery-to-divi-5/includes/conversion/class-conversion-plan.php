<?php
/**
 * The immutable result of a dry run. Building one writes nothing;
 * ConversionCommitter is the only thing that turns a plan into posts.
 */

namespace WPBakeryDivi5Converter\Conversion;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class ConversionPlan {

    /** @var array[] */
    private array $items;

    /** @param array[] $items */
    public function __construct( array $items ) {
        $this->items = array_values( $items );
    }

    /**
     * One plan item with every key present, so consumers never null-check a
     * field a source did not set.
     *
     * `mode` and `source_post_type` are here for the report screen rather than
     * for the commit: the same theme element is "copied as static HTML" when
     * the page was read off this site and "left as a placeholder" when it came
     * out of an export (task-11-amendments §4), and the upload form offers
     * every post type the file held with `page` and `post` checked (§1).
     *
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    public static function item( array $fields ): array {
        $source_ref = is_array( $fields['source_ref'] ?? null ) ? $fields['source_ref'] : [];

        return [
            'title'            => (string) ( $fields['title'] ?? 'Imported Page' ),
            'post_type'        => (string) ( $fields['post_type'] ?? 'page' ),
            'source_post_type' => (string) ( $fields['source_post_type'] ?? '' ),
            'post_name'        => (string) ( $fields['post_name'] ?? '' ),
            'template_type'    => (string) ( $fields['template_type'] ?? '' ),
            'mode'             => ( $fields['mode'] ?? '' ) === 'direct' ? 'direct' : 'import',
            'source_ref'       => [
                'kind'    => (string) ( $source_ref['kind'] ?? 'upload' ),
                'post_id' => isset( $source_ref['post_id'] ) ? (int) $source_ref['post_id'] : null,
                'file'    => isset( $source_ref['file'] ) ? (string) $source_ref['file'] : null,
            ],
            'blocks'           => $fields['blocks'] ?? [],
            'content'          => (string) ( $fields['content'] ?? '' ),
            'report'           => $fields['report'] ?? [],
            'unsupported'      => $fields['unsupported'] ?? [],
            'outline'          => $fields['outline'] ?? [],
            'error'            => (string) ( $fields['error'] ?? '' ),
        ];
    }

    /** @return array[] */
    public function items(): array {
        return $this->items;
    }

    public function count(): int {
        return count( $this->items );
    }

    public function hasFailures(): bool {
        foreach ( $this->items as $item ) {
            if ( ( $item['error'] ?? '' ) !== '' ) {
                return true;
            }
        }

        return false;
    }

    /** @return array{items: array[]} */
    public function toArray(): array {
        return [ 'items' => $this->items ];
    }
}
