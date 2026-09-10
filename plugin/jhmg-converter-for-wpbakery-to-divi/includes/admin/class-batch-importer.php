<?php

namespace WPBakeryDivi5Converter\Admin;

use WPBakeryDivi5Converter\Conversion\ConversionCommitter;
use WPBakeryDivi5Converter\Conversion\ConversionPlan;
use WPBakeryDivi5Converter\Conversion\ConversionPreflight;
use WPBakeryDivi5Converter\Conversion\ConversionSource;
use WPBakeryDivi5Converter\Converter\ConverterEngine;
use WPBakeryDivi5Converter\Exporters\DiviExporter;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Converts a list of import items and writes the results — a thin orchestrator
 * over ConversionPreflight (convert, writing nothing) and ConversionCommitter.
 *
 * Uploads are subject to the same per-run limit as direct conversion: the free
 * plugin converts the first page in a file and says how many it left, Pro
 * converts them all.
 */
class BatchImporter {

    private ConversionPreflight $preflight;
    private ConversionCommitter $committer;

    public function __construct( ?ConverterEngine $engine = null, ?DiviExporter $exporter = null, ?object $library_exporter = null ) {
        $this->preflight = new ConversionPreflight( $engine );
        $this->committer = new ConversionCommitter( $exporter, $library_exporter );
    }

    /**
     * @param array[] $items   Import items from WPBakeryImportParser::parse().
     * @param array<string,mixed> $options post_status ('draft'|'publish'), post_type override, convert_templates.
     * @return array[] Per-item results; a trailing 'skipped' entry explains anything the limit left out.
     */
    public function import( array $items, array $options = [] ): array {
        $plan    = $this->preflight->run( $this->sourceFor( $items ) );
        $results = $this->committer->commit( $plan, $options );

        if ( $plan->truncated() ) {
            $left = count( $items ) - $plan->count();

            $results[] = [
                'title'       => sprintf(
                    /* translators: %d: number of pages in the file that were not converted */
                    _n( '%d more page in this file was not converted', '%d more pages in this file were not converted', $left, 'jhmg-converter-for-wpbakery-to-divi' ),
                    $left
                ),
                'post_id'     => 0,
                'success'     => false,
                'skipped'     => true,
                'error'       => __( 'Free converts one page per upload. The Pro add-on converts every page in the file in one run.', 'jhmg-converter-for-wpbakery-to-divi' ),
                'report'      => [],
                'unsupported' => [],
            ];
        }

        return $results;
    }

    /** Commit a plan a caller already built — the report screen's Convert step. */
    public function importPlan( ConversionPlan $plan, array $options = [] ): array {
        return $this->committer->commit( $plan, $options );
    }

    /** @param array[] $items */
    private function sourceFor( array $items ): ConversionSource {
        return new class( $items ) implements ConversionSource {
            /** @param array[] $items */
            public function __construct( private array $items ) {}

            public function items(): array {
                return $this->items;
            }
        };
    }
}
