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

    /**
     * What a template gets when the caller unticked "convert templates".
     *
     * Not converting it is the instruction; converting it into a page anyway
     * and saying nothing would be the opposite of what was asked, and would
     * lose the one signal that the item was a template at all.
     */
    const LIBRARY_NOT_SELECTED = 'WPBakery template not selected for conversion';

    private DiviExporter $exporter;

    /**
     * A Divi Library exporter handed in directly, which wins over the filter.
     * Null in production, where the filter is asked once per item.
     */
    private ?object $injectedLibraryExporter;

    public function __construct( ?DiviExporter $exporter = null, ?object $library_exporter = null ) {
        $this->exporter                = $exporter ?? new DiviExporter();
        $this->injectedLibraryExporter = $library_exporter;
    }

    /**
     * The Pro add-on's Divi Library exporter, or null.
     *
     * `apply_filters( 'wbdc_library_exporter', null, array $item, array $options )`
     * — a public contract (task-11-amendments §2, Task 13). The item is the
     * plan item about to be committed and the options are the caller's commit
     * options, so an exporter can answer per item (a licence that has lapsed, a
     * template type it does not handle) by returning null for it and letting
     * the free path take over.
     *
     * @param array<string,mixed> $item
     * @param array<string,mixed> $options
     */
    private function libraryExporter( array $item, array $options ): ?object {
        if ( $this->injectedLibraryExporter !== null ) {
            return $this->injectedLibraryExporter;
        }

        if ( ! function_exists( 'apply_filters' ) ) {
            return null;
        }

        // Literal so Plugin Check can read the hook name; keep in step with self::LIBRARY_EXPORTER_FILTER.
        $exporter = apply_filters( 'wbdc_library_exporter', null, $item, $options );

        return is_object( $exporter ) ? $exporter : null;
    }

    /**
     * @param array<string,mixed> $options post_status, post_type override, convert_templates.
     * @return array[] One result per plan item:
     *   ['title','post_id','success','error','mode','report','unsupported'], plus
     *   'template_type' => 'library' on every outcome of a WPBakery template
     *   and 'skipped' => true on one the caller chose not to convert.
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

            $is_library = ( $item['template_type'] ?? '' ) === 'library';

            if ( $is_library && ! $convert_templates ) {
                $results[] = $this->notSelected( $item );
                continue;
            }

            $exporter = $is_library ? $this->libraryExporter( $item, $options ) : null;

            if ( $is_library && $exporter === null ) {
                $result                         = $this->commitPage( $item, $default_post_type, $default_post_status );
                $result['template_type']        = 'library';
                $result['report']['warnings'][] = self::LIBRARY_WARNING;
                $results[]                      = $result;
                continue;
            }

            if ( $is_library && $exporter !== null ) {
                $results[] = $this->commitLibraryLayout( $item, $exporter );
                continue;
            }

            $results[] = $this->commitPage( $item, $default_post_type, $default_post_status );
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
                // The report screen words a theme element as "copied as static
                // HTML" or "left as a placeholder" by this, so it has to
                // survive the commit rather than stop at the plan.
                'mode'        => (string) ( $item['mode'] ?? 'import' ),
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
    private function commitLibraryLayout( array $item, object $exporter ): array {
        $title = (string) ( $item['title'] ?? 'Imported Template' );

        try {
            $post_id = (int) $exporter->export( $item, $this->diviDataFor( $item ) );

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
                'mode'          => (string) ( $item['mode'] ?? 'import' ),
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

    /**
     * A template the caller chose not to convert: nothing written, and the
     * result says what it was and why it was left.
     *
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private function notSelected( array $item ): array {
        return [
            'title'         => (string) ( $item['title'] ?? 'Imported Template' ),
            'post_id'       => 0,
            'template_type' => 'library',
            'success'       => false,
            'skipped'       => true,
            'error'         => self::LIBRARY_NOT_SELECTED,
            'report'        => [],
            'unsupported'   => [],
        ];
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
