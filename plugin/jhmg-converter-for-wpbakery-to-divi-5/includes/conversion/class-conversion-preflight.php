<?php
/**
 * Runs a conversion without committing it.
 *
 * The one engine behind both the "Check selected pages" report and the commit: the
 * preview shows a plan, a commit writes one. `run()` performs no database
 * writes — `ConversionPipelineTest` asserts it against the in-memory stores —
 * which is what makes the preview worth reading.
 */

namespace WPBakeryDivi5Converter\Conversion;

use WPBakeryDivi5Converter\Converter\ConverterEngine;
use WPBakeryDivi5Converter\Exporters\DiviBlockSerializer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ConversionPreflight {

    private ?ConverterEngine $engine;
    private DiviBlockSerializer $serializer;

    /**
     * @param ConverterEngine|null $engine Injected only by tests that need to observe
     *   the engine. Left null in production so each item gets a fresh one — the
     *   engine accumulates report state across convert() calls.
     */
    public function __construct( ?ConverterEngine $engine = null, ?DiviBlockSerializer $serializer = null ) {
        $this->engine     = $engine;
        $this->serializer = $serializer ?? new DiviBlockSerializer();
    }

    /** Plan every item the source holds. */
    public function run( ConversionSource $source ): ConversionPlan {
        $planned = [];

        foreach ( $source->items() as $item ) {
            $planned[] = $this->planItem( $item );
        }

        return new ConversionPlan( $planned );
    }

    /**
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private function planItem( array $item ): array {
        $base = [
            'title'            => $item['title'] ?? '',
            'post_type'        => $item['post_type'] ?? 'page',
            'source_post_type' => $item['source_post_type'] ?? '',
            'post_name'        => $item['post_name'] ?? '',
            'template_type'    => $item['template_type'] ?? '',
            'mode'             => $item['mode'] ?? 'import',
            'source_ref'       => $item['source_ref'] ?? [],
        ];

        $incoming_error = (string) ( $item['error'] ?? '' );
        if ( $incoming_error !== '' ) {
            return ConversionPlan::item( $base + [ 'error' => $incoming_error ] );
        }

        try {
            $engine = $this->engine ?? new ConverterEngine();

            $converted = $engine->convert(
                [
                    'content' => (string) ( $item['content'] ?? '' ),
                    'meta'    => is_array( $item['meta'] ?? null ) ? $item['meta'] : [],
                ],
                [
                    'mode'        => $base['mode'],
                    'attachments' => is_array( $item['attachments'] ?? null ) ? $item['attachments'] : [],
                ]
            );

            return ConversionPlan::item( $base + [
                'blocks'      => $converted['divi'] ?? [],
                'content'     => $this->serializer->serialize( $converted ),
                'report'      => $converted['report'] ?? [],
                'unsupported' => $converted['unsupported'] ?? [],
                'outline'     => ConversionOutline::build( $converted['divi']['elements'] ?? [] ),
            ] );
        } catch ( \Throwable $e ) {
            return ConversionPlan::item( $base + [ 'error' => $e->getMessage() ] );
        }
    }
}
