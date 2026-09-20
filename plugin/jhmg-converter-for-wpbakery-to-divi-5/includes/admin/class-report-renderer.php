<?php
/**
 * One conversion's report, rendered.
 *
 * Both screens read the same engine report — "Check selected pages" before anything
 * is written, and the results table afterwards — so the rendering lives here
 * rather than on either page. Every key of `ConverterEngine::getReport()`
 * reaches a reader through this class or through `NotCarriedOverRenderer`,
 * which it calls (task-12-amendments §2): nothing the engine counted is hidden.
 *
 * The two kinds of "not handled" stay worded apart (§5): `skipped_settings` is
 * this converter's own gap on a field WPBakery declares, and is listed as such;
 * a theme's element or a theme's added parameter is not a gap and gets
 * `NotCarriedOverRenderer`'s separate block.
 */

namespace WPBakeryDivi5Converter\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ReportRenderer {

    /**
     * The whole report: the coverage fact, then everything that has something
     * to say.
     *
     * @param array<string,mixed>             $report      The engine's report.
     * @param array<int, array<string,mixed>> $unsupported The result's `unsupported` entries.
     * @param string                          $mode        `direct` or `import`.
     */
    public static function render( array $report, array $unsupported = [], string $mode = 'import' ): string {
        return self::summary( $report, $unsupported ) . self::details( $report, $unsupported, $mode );
    }

    /**
     * How much of the page reached a Divi module.
     *
     * `quality.module_coverage` is the engine's own ratio of cleanly converted
     * source elements; it is shown as the plain counts behind it rather than
     * as a score on its own, because "83%" without a denominator tells a
     * reader nothing they can act on. The denominator is the same three counts
     * the engine divides (`ConverterEngine::getReport()`), so the percentage
     * printed here is the one the engine recorded.
     *
     * Tense-neutral on purpose: the same sentence is true on the check screen,
     * where nothing has been written, and on the result screen, where it has.
     *
     * `converted` and `approximate` are separate maps in the report — the
     * engine counts a module built by an approximate handler apart from an
     * exact one — so the module total adds them and the coverage numerator is
     * the exact count alone, exactly as `getReport()` divides them.
     *
     * @param array<string,mixed>             $report
     * @param array<int, array<string,mixed>> $unsupported
     */
    public static function summary( array $report, array $unsupported = [] ): string {
        $exact       = (int) array_sum( (array) ( $report['converted'] ?? [] ) );
        $approximate = (int) array_sum( (array) ( $report['approximate'] ?? [] ) );
        $matches     = count( (array) ( $report['approximate_matches'] ?? [] ) );
        $elements    = $exact + $matches + count( $unsupported );
        $modules     = $exact + $approximate;

        $html = '<p class="wbdc-report-summary"><strong>' . esc_html( sprintf(
            /* translators: %d: number of Divi modules the conversion produces */
            _n( '%d Divi module.', '%d Divi modules.', $modules, 'jhmg-converter-for-wpbakery-to-divi-5' ),
            $modules
        ) ) . '</strong>';

        if ( $elements > 0 ) {
            $html .= ' ' . esc_html( sprintf(
                /* translators: 1: elements that reached a Divi module, 2: elements in the page, 3: percentage */
                __( '%1$d of %2$d WPBakery elements reached a Divi module (%3$d%% coverage).', 'jhmg-converter-for-wpbakery-to-divi-5' ),
                $exact,
                $elements,
                (int) ( $report['quality']['module_coverage'] ?? 100 )
            ) );
        }

        if ( $approximate > 0 ) {
            $html .= ' ' . esc_html( sprintf(
                /* translators: %d: number of modules built from an approximate mapping */
                _n(
                    '%d of them approximate — the closest Divi module was used, so give it a look.',
                    '%d of them approximate — the closest Divi module was used, so give them a look.',
                    $approximate,
                    'jhmg-converter-for-wpbakery-to-divi-5'
                ),
                $approximate
            ) );
        }

        return $html . '</p>';
    }

    /**
     * Everything except the coverage fact, so the results table can put the
     * fact in the cell and the rest behind a disclosure.
     *
     * @param array<string,mixed>             $report
     * @param array<int, array<string,mixed>> $unsupported
     */
    public static function details( array $report, array $unsupported = [], string $mode = 'import' ): string {
        $html = '';

        $names = array_values( array_unique( array_filter( array_map(
            static fn( $entry ): string => is_array( $entry ) ? (string) ( $entry['tag'] ?? $entry['module'] ?? $entry['type'] ?? '' ) : '',
            $unsupported
        ) ) ) );

        if ( ! empty( $names ) ) {
            $html .= '<p class="wbdc-direct-unsupported"><strong>' . esc_html__( 'Could not be converted:', 'jhmg-converter-for-wpbakery-to-divi-5' ) . '</strong> '
                . esc_html( implode( ', ', $names ) ) . '</p>';
        }

        $html .= NotCarriedOverRenderer::render(
            (array) ( $report['not_carried_over'] ?? [] ),
            (array) ( $report['approximate_matches'] ?? [] ),
            (array) ( $report['unresolved_globals'] ?? [] ),
            (array) ( $report['theme_elements'] ?? [] ),
            (array) ( $report['unresolved_media'] ?? [] ),
            $mode
        );

        $notes = self::kept_as_is_notes( $report );
        if ( ! empty( $notes ) ) {
            $html .= '<ul class="wbdc-report-notes">';
            foreach ( $notes as $note ) {
                $html .= '<li>' . esc_html( $note ) . '</li>';
            }
            $html .= '</ul>';
        }

        $html .= self::disclosure(
            'wbdc-skipped-settings',
            (array) ( $report['skipped_settings'] ?? [] ),
            static fn( int $n ): string => sprintf(
                /* translators: %d: number of WPBakery settings the converter did not map */
                _n( '%d WPBakery setting this converter did not map', '%d WPBakery settings this converter did not map', $n, 'jhmg-converter-for-wpbakery-to-divi-5' ),
                $n
            ),
            true
        );

        $html .= self::disclosure(
            'wbdc-report-warnings',
            (array) ( $report['warnings'] ?? [] ),
            static fn( int $n ): string => sprintf(
                /* translators: %d: number of warnings the conversion recorded */
                _n( '%d note from the conversion', '%d notes from the conversion', $n, 'jhmg-converter-for-wpbakery-to-divi-5' ),
                $n
            ),
            false
        );

        return $html;
    }

    /**
     * A `<details>` over a flat list of strings.
     *
     * @param array<int, mixed>     $rows
     * @param callable(int): string $label
     * @param bool                  $as_code Whether each row is a field name rather than a sentence.
     */
    private static function disclosure( string $class, array $rows, callable $label, bool $as_code ): string {
        if ( empty( $rows ) ) {
            return '';
        }

        $html = '<details class="' . esc_attr( $class ) . '"><summary>' . esc_html( $label( count( $rows ) ) ) . '</summary><ul class="wbdc-not-carried-list">';

        foreach ( $rows as $row ) {
            $html .= $as_code
                ? '<li><code>' . esc_html( (string) $row ) . '</code></li>'
                : '<li>' . esc_html( (string) $row ) . '</li>';
        }

        return $html . '</ul></details>';
    }

    /**
     * The counters that describe something kept rather than something lost.
     *
     * @param array<string,mixed> $report
     * @return string[]
     */
    private static function kept_as_is_notes( array $report ): array {
        $notes = [];

        $static = count( (array) ( $report['static_copies'] ?? [] ) );
        if ( $static > 0 ) {
            $notes[] = sprintf(
                /* translators: %d: number of elements copied as static HTML */
                _n(
                    '%d element was rendered on this site and kept as static HTML. It will not change when its plugin does.',
                    '%d elements were rendered on this site and kept as static HTML. They will not change when their plugins do.',
                    $static,
                    'jhmg-converter-for-wpbakery-to-divi-5'
                ),
                $static
            );
        }

        $css = count( (array) ( $report['custom_css_carried'] ?? [] ) );
        if ( $css > 0 ) {
            $notes[] = sprintf(
                /* translators: %d: number of elements whose design options were carried as custom CSS */
                _n(
                    "%d element's design options had no Divi setting and were carried as custom CSS on the module.",
                    "%d elements' design options had no Divi setting and were carried as custom CSS on the modules.",
                    $css,
                    'jhmg-converter-for-wpbakery-to-divi-5'
                ),
                $css
            );
        }

        $text = (int) ( $report['text_nodes'] ?? 0 );
        if ( $text > 0 ) {
            $notes[] = sprintf(
                /* translators: %d: number of loose runs of text kept as text modules */
                _n(
                    '%d run of text sat between elements and was kept as a text module.',
                    '%d runs of text sat between elements and were kept as text modules.',
                    $text,
                    'jhmg-converter-for-wpbakery-to-divi-5'
                ),
                $text
            );
        }

        $bracketed = (int) ( $report['bracketed_text'] ?? 0 );
        if ( $bracketed > 0 ) {
            $notes[] = sprintf(
                /* translators: %d: number of bracketed tokens kept as text */
                _n(
                    '%d bracketed token was not a shortcode at all and was kept as the text it was.',
                    '%d bracketed tokens were not shortcodes at all and were kept as the text they were.',
                    $bracketed,
                    'jhmg-converter-for-wpbakery-to-divi-5'
                ),
                $bracketed
            );
        }

        return $notes;
    }
}
