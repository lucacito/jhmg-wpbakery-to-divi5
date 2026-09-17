<?php
/**
 * The losses block shared by both report screens.
 *
 * Two kinds of "not handled" are worded apart (task-12-amendments §5):
 *
 *  - A **converter gap** is a WPBakery-declared field this plugin did not map.
 *    Those are `skipped_settings`, rendered by the report screen itself.
 *  - A **theme or add-on feature** is not a gap at all: nothing here ignored
 *    it, it belongs to a plugin that is not being converted. Those arrive as
 *    `not_carried_over` entries of kind `addon` — a theme's own element, a
 *    field a theme bolted onto a WPBakery element, or a field WPBakery never
 *    declared — and get their own block, away from the failures.
 *
 * Everything the engine reports has somewhere to appear (§2); nothing is
 * hidden from the reader.
 */

namespace WPBakeryDivi5Converter\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NotCarriedOverRenderer {

    /**
     * @param array<int, array<string,mixed>>  $entries        The report's `not_carried_over`.
     * @param array<int, array<string,mixed>>  $approximate    The report's `approximate_matches`.
     * @param array<int, array<string,mixed>>  $unresolved     The report's `unresolved_globals`.
     * @param array<string, int>               $theme_elements The report's `theme_elements` (family label ⇒ count).
     * @param array<int, array<string,mixed>>  $unresolved_media The report's `unresolved_media`.
     * @param string                           $mode           `direct` (rendered on this site) or `import` (from a file).
     */
    public static function render(
        array $entries,
        array $approximate = [],
        array $unresolved = [],
        array $theme_elements = [],
        array $unresolved_media = [],
        string $mode = 'import'
    ): string {
        $addon = array_values( array_filter( $entries, static fn( array $e ): bool => ( $e['kind'] ?? '' ) === 'addon' ) );
        $lost  = array_values( array_filter( $entries, static fn( array $e ): bool => ( $e['kind'] ?? '' ) !== 'addon' ) );

        return self::lost( $lost, $approximate, $unresolved, $unresolved_media )
            . self::addonBlock( $addon, $theme_elements, $mode );
    }

    /**
     * Everything a conversion could not express.
     *
     * @param array<int, array<string,mixed>> $entries
     * @param array<int, array<string,mixed>> $approximate
     * @param array<int, array<string,mixed>> $unresolved
     * @param array<int, array<string,mixed>> $unresolved_media
     */
    private static function lost( array $entries, array $approximate, array $unresolved, array $unresolved_media ): string {
        if ( empty( $entries ) && empty( $approximate ) && empty( $unresolved ) && empty( $unresolved_media ) ) {
            return '';
        }

        $html = '<div class="wbdc-not-carried"><h3>' . esc_html__( 'Not carried over', 'jhmg-converter-for-wpbakery-to-divi-5' ) . '</h3>';

        foreach ( self::groups() as $kind => $label ) {
            $rows = array_values( array_filter( $entries, static fn( array $e ): bool => ( $e['kind'] ?? '' ) === $kind ) );
            if ( empty( $rows ) ) {
                continue;
            }

            $html .= '<p class="wbdc-not-carried-label"><strong>' . esc_html( $label ) . '</strong></p><ul class="wbdc-not-carried-list">';
            foreach ( $rows as $row ) {
                $html .= '<li><code>' . esc_html( (string) ( $row['node_id'] ?? '' ) ) . '</code> — ' . esc_html( (string) ( $row['detail'] ?? '' ) ) . '</li>';
            }
            $html .= '</ul>';
        }

        if ( ! empty( $unresolved ) ) {
            $html .= '<p class="wbdc-not-carried-label"><strong>'
                . esc_html__( 'Global colours that could not be resolved — the setting was left empty rather than guessed', 'jhmg-converter-for-wpbakery-to-divi-5' )
                . '</strong></p><ul class="wbdc-not-carried-list">';
            foreach ( $unresolved as $row ) {
                $html .= '<li><code>' . esc_html( (string) ( $row['node_id'] ?? '' ) ) . '</code> — '
                    . esc_html( (string) ( $row['setting_key'] ?? '' ) ) . ' = ' . esc_html( (string) ( $row['ref'] ?? '' ) ) . '</li>';
            }
            $html .= '</ul>';
        }

        if ( ! empty( $unresolved_media ) ) {
            $html .= '<p class="wbdc-not-carried-label"><strong>'
                . esc_html__( 'Images whose attachment is not on this site — the image was left out rather than linked to a file that is not there', 'jhmg-converter-for-wpbakery-to-divi-5' )
                . '</strong></p><ul class="wbdc-not-carried-list">';
            foreach ( $unresolved_media as $row ) {
                $html .= '<li><code>' . esc_html( (string) ( $row['node_id'] ?? '' ) ) . '</code> — '
                    /* translators: %d: WordPress attachment id */
                    . esc_html( sprintf( __( 'attachment %d', 'jhmg-converter-for-wpbakery-to-divi-5' ), (int) ( $row['attachment_id'] ?? 0 ) ) ) . '</li>';
            }
            $html .= '</ul>';
        }

        if ( ! empty( $approximate ) ) {
            $html .= '<p class="wbdc-not-carried-label"><strong>'
                . esc_html__( 'Approximate conversions — the closest Divi module was used; check these', 'jhmg-converter-for-wpbakery-to-divi-5' )
                . '</strong></p><ul class="wbdc-not-carried-list">';
            foreach ( $approximate as $row ) {
                $html .= '<li><code>' . esc_html( (string) ( $row['node_id'] ?? '' ) ) . '</code> — '
                    . esc_html( (string) ( $row['tag'] ?? '' ) ) . ' → ' . esc_html( (string) ( $row['matched_to'] ?? '' ) ) . '</li>';
            }
            $html .= '</ul>';
        }

        return $html . '</div>';
    }

    /**
     * Theme and add-on features: elements of a theme's own, and the fields a
     * theme bolts onto WPBakery's elements. Neither is a converter gap, and
     * neither is presented as one.
     *
     * @param array<int, array<string,mixed>> $addon
     * @param array<string, int>              $theme_elements
     */
    private static function addonBlock( array $addon, array $theme_elements, string $mode ): string {
        if ( empty( $addon ) && empty( $theme_elements ) ) {
            return '';
        }

        $html = '<div class="wbdc-addon-block"><h3>' . esc_html__( 'Theme and add-on features', 'jhmg-converter-for-wpbakery-to-divi-5' ) . '</h3>'
            . '<p class="description">'
            . esc_html__( 'These belong to a theme or a plugin, not to WPBakery, so the converter has no Divi setting to map them onto. Nothing here failed to convert.', 'jhmg-converter-for-wpbakery-to-divi-5' )
            . '</p>';

        if ( ! empty( $theme_elements ) ) {
            $parts = [];
            foreach ( $theme_elements as $label => $count ) {
                $parts[] = sprintf( '%s × %d', (string) $label, (int) $count );
            }

            $html .= '<p class="wbdc-theme-elements"><strong>' . esc_html__( 'Theme and add-on elements:', 'jhmg-converter-for-wpbakery-to-divi-5' ) . '</strong> '
                . esc_html( implode( ', ', $parts ) ) . ' — '
                . esc_html(
                    $mode === 'direct'
                        ? __( 'copied as static HTML, exactly as this site renders them today', 'jhmg-converter-for-wpbakery-to-divi-5' )
                        : __( 'left as placeholders, because nothing on this site can render an element from another site', 'jhmg-converter-for-wpbakery-to-divi-5' )
                )
                . '</p>';
        }

        if ( ! empty( $addon ) ) {
            $html .= '<p class="wbdc-not-carried-label"><strong>' . esc_html__( 'Theme and add-on settings', 'jhmg-converter-for-wpbakery-to-divi-5' ) . '</strong></p>'
                . '<ul class="wbdc-not-carried-list">';
            foreach ( $addon as $row ) {
                $html .= '<li><code>' . esc_html( (string) ( $row['node_id'] ?? '' ) ) . '</code> — ' . esc_html( (string) ( $row['detail'] ?? '' ) ) . '</li>';
            }
            $html .= '</ul>';
        }

        return $html . '</div>';
    }

    /**
     * The kinds `ConverterEngine::logNotCarriedOver()` documents, minus
     * `addon`, which has a block of its own above.
     *
     * @return array<string, string>
     */
    private static function groups(): array {
        return [
            'animation'   => __( 'Animations — removed', 'jhmg-converter-for-wpbakery-to-divi-5' ),
            'visibility'  => __( 'Visibility rules — removed; the element shows to everyone', 'jhmg-converter-for-wpbakery-to-divi-5' ),
            'background'  => __( 'Backgrounds that could only be approximated', 'jhmg-converter-for-wpbakery-to-divi-5' ),
            'layout'      => __( 'Layout options with no Divi equivalent', 'jhmg-converter-for-wpbakery-to-divi-5' ),
            'hover'       => __( 'Hover colours — the resting colours are kept', 'jhmg-converter-for-wpbakery-to-divi-5' ),
            'interaction' => __( 'Click actions and interactive behaviour — needs rebuilding in Divi', 'jhmg-converter-for-wpbakery-to-divi-5' ),
            'integration' => __( 'Third-party connections — reconnect them in the Divi module', 'jhmg-converter-for-wpbakery-to-divi-5' ),
            'custom_code' => __( 'Custom CSS and JavaScript — copy it into Divi → Theme Options if it is still needed', 'jhmg-converter-for-wpbakery-to-divi-5' ),
            'error'       => __( 'Each of these was replaced by a placeholder because its handler failed; the original shortcode is preserved inside it', 'jhmg-converter-for-wpbakery-to-divi-5' ),
        ];
    }
}
