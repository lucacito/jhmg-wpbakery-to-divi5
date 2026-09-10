<?php
/**
 * Writes a ConversionPlan to the database — the only class in the pipeline
 * that creates posts. Everything upstream is read-only, which is what makes
 * the preview trustworthy.
 */

namespace WPBakeryDivi5Converter\Conversion;

use WPBakeryDivi5Converter\Exporters\DiviExporter;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ConversionCommitter {

    const PRO_URL = 'https://divi5lab.com/plugins/wpbakery-to-divi-5';

    /** The hook the Pro add-on answers with its Divi Library exporter (Task 13). */
    const LIBRARY_EXPORTER_FILTER = 'wbdc_library_exporter';

    /**
     * What the free plugin says when a WPBakery template arrives and no Pro
     * exporter answered. It is never dropped: it becomes a page draft, which
     * holds every element of it, and the warning says where it would have gone
     * (task-11-amendments §2).
     */
    const LIBRARY_WARNING = 'WPBakery template imported as a page (Pro turns templates into Divi Library layouts)';

    private DiviExporter $exporter;

    /** Divi Library exporter supplied by the Pro add-on via filter (null when absent). */
    private ?object $libraryExporter;

    public function __construct( ?DiviExporter $exporter = null, ?object $library_exporter = null ) {
        $this->exporter = $exporter ?? new DiviExporter();

        // Literal so Plugin Check can read the hook name; keep in step with self::LIBRARY_EXPORTER_FILTER.
        $this->libraryExporter = $library_exporter
            ?? ( function_exists( 'apply_filters' ) ? apply_filters( 'wbdc_library_exporter', null ) : null );
    }

    /**
     * @param array<string,mixed> $options post_status, post_type override, convert_templates.
     * @return array[] One result per plan item: ['title','post_id','success','error','report','unsupported'].
     */
    public function commit( ConversionPlan $plan, array $options = [] ): array {
        $default_post_type   = $options['post_type'] ?? null;
        $default_post_status = $options['post_status'] ?? 'draft';
        $convert_templates   = $options['convert_templates'] ?? true;

        $results = [];

        foreach ( $plan->items() as $item ) {
            if ( ( $item['error'] ?? '' ) !== '' ) {
                $results[] = $this->failResult( (string) ( $item['title'] ?? '' ), (string) $item['error'] );
                continue;
            }

            $wants_library = ( $item['template_type'] ?? '' ) === 'library' && $convert_templates;

            if ( $wants_library && $this->libraryExporter === null ) {
                $result                         = $this->commitPage( $item, $default_post_type, $default_post_status );
                $result['template_type']        = 'library';
                $result['report']['warnings'][] = self::LIBRARY_WARNING;
                $results[]                      = $result;
                continue;
            }

            $results[] = $wants_library
                ? $this->commitLibraryLayout( $item )
                : $this->commitPage( $item, $default_post_type, $default_post_status );
        }

        return $results;
    }

    /**
     * The caller's explicit post_type option wins; the item's own type is the
     * fallback.
     *
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private function commitPage( array $item, ?string $post_type_option, string $post_status ): array {
        $title     = (string) ( $item['title'] ?? 'Imported Page' );
        $post_name = (string) ( $item['post_name'] ?? '' );

        try {
            $post_args = [
                'post_type'    => $post_type_option ?? ( $item['post_type'] ?: 'page' ),
                'post_title'   => $title !== '' ? $title : 'Imported Page',
                'post_status'  => $post_status,
                'post_content' => '',
            ];
            if ( $post_name !== '' ) {
                $post_args['post_name'] = $post_name;
            }

            $post_id = wp_insert_post( $post_args );
            if ( is_wp_error( $post_id ) || (int) $post_id === 0 ) {
                return $this->failResult( $title, is_wp_error( $post_id ) ? $post_id->get_error_message() : 'wp_insert_post returned 0' );
            }

            $post_id = (int) $post_id;
            $this->exporter->save( $post_id, $this->diviDataFor( $item ) );
            $this->stampSource( $post_id, $item );

            return [
                'title'       => $title,
                'post_id'     => $post_id,
                'success'     => true,
                'error'       => '',
                'report'      => $item['report'] ?? [],
                'unsupported' => $item['unsupported'] ?? [],
            ];
        } catch ( \Throwable $e ) {
            return $this->failResult( $title, $e->getMessage() );
        }
    }

    /**
     * A WPBakery template through the Pro add-on's Divi Library exporter,
     * which owns the writing: an `et_pb_layout` post is not a page and the
     * free plugin has no business creating one.
     *
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private function commitLibraryLayout( array $item ): array {
        $title = (string) ( $item['title'] ?? 'Imported Template' );

        try {
            $post_id = (int) $this->libraryExporter->export( $item, $this->diviDataFor( $item ) );

            if ( $post_id <= 0 ) {
                return $this->failResult( $title, 'The Divi Library exporter did not create a layout.' );
            }

            $this->stampSource( $post_id, $item );

            return [
                'title'         => $title,
                'post_id'       => $post_id,
                'template_type' => 'library',
                'success'       => true,
                'error'         => '',
                'report'        => $item['report'] ?? [],
                'unsupported'   => $item['unsupported'] ?? [],
            ];
        } catch ( \Throwable $e ) {
            return $this->failResult( $title, $e->getMessage() );
        }
    }

    /**
     * @param array<string,mixed> $item
     * @return array{divi: array, report: array, unsupported: array}
     */
    private function diviDataFor( array $item ): array {
        return [
            'divi'        => $item['blocks'] ?? [],
            'report'      => $item['report'] ?? [],
            'unsupported' => $item['unsupported'] ?? [],
        ];
    }

    /** @param array<string,mixed> $item */
    private function stampSource( int $post_id, array $item ): void {
        $kind = $item['source_ref']['kind'] ?? 'upload';
        update_post_meta( $post_id, '_wbdc_import_source', $kind === 'installed' ? 'direct' : 'file_upload' );

        $source_post_id = $item['source_ref']['post_id'] ?? null;
        if ( $kind === 'installed' && $source_post_id ) {
            update_post_meta( $post_id, '_wbdc_source_post_id', (int) $source_post_id );
        }
    }

    /** @return array<string,mixed> */
    private function failResult( string $title, string $error ): array {
        return [
            'title'       => $title !== '' ? $title : 'Imported Page',
            'post_id'     => 0,
            'success'     => false,
            'error'       => $error,
            'report'      => [],
            'unsupported' => [],
        ];
    }
}
