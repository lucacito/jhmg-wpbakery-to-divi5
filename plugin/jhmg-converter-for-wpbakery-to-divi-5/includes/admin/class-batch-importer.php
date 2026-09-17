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
     * @return array[] Per-item results.
     */
    public function import( array $items, array $options = [] ): array {
        return $this->committer->commit( $this->preflight->run( $this->sourceFor( $items ) ), $options );
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
