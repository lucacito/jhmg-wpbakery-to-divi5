<?php
/**
 * Durable record of recent conversion runs.
 *
 * Results otherwise live only in a one-hour transient. The coverage panel
 * needs the unsupported element tags across runs, and rollback needs the post
 * IDs a run created long after that hour is up, so both read from here.
 *
 * Two lists per run, kept apart because they mean different things:
 * `unsupported` holds WPBakery's own tags this converter has no handler for —
 * a real gap — and `theme_families` holds the keys of the theme and add-on
 * families a run met, which are not gaps at all but are the thing worth
 * knowing about a WPBakery site.
 */

namespace WPBakeryDivi5Converter\History;

use WPBakeryDivi5Converter\Helpers\ThemeShortcodes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ImportHistory {

    const OPTION = 'wbdc_import_history';

    /** Unbounded growth in wp_options is a common cause of slow-site reports. */
    const MAX_RUNS = 25;

    /** @param array[] $results */
    public function record( string $import_id, array $results ): void {
        $post_ids = [];
        foreach ( $results as $r ) {
            if ( ! empty( $r['success'] ) && ! empty( $r['post_id'] ) ) {
                $post_ids[] = (int) $r['post_id'];
            }
        }

        $real      = array_filter( $results, static fn( $r ): bool => empty( $r['skipped'] ) );
        $succeeded = count( array_filter( $real, static fn( $r ): bool => ! empty( $r['success'] ) ) );

        $runs = $this->all();
        array_unshift( $runs, [
            'id'             => $import_id,
            'at'             => gmdate( 'Y-m-d H:i:s' ),
            'post_ids'       => $post_ids,
            'unsupported'    => self::element_types( $results ),
            'theme_families' => self::theme_family_keys( $results ),
            'succeeded'      => $succeeded,
            'failed'         => count( $real ) - $succeeded,
            // Undo behaves differently for the two kinds of run: one puts pages
            // back, the other trashes pages it created — and only the second
            // needs the Trash to be available at all.
            'in_place'       => (bool) array_filter( $results, static fn( $r ): bool => ! empty( $r['in_place'] ) ),
            'rolled_back'    => false,
        ] );

        update_option( self::OPTION, array_slice( $runs, 0, self::MAX_RUNS ) );
    }

    /** @return array[] Newest first. */
    public function all(): array {
        $runs = get_option( self::OPTION, [] );

        return is_array( $runs ) ? $runs : [];
    }

    /** @return array<string,mixed>|null */
    public function find( string $import_id ): ?array {
        foreach ( $this->all() as $run ) {
            if ( ( $run['id'] ?? '' ) === $import_id ) {
                return $run;
            }
        }

        return null;
    }

    public function mark_rolled_back( string $import_id ): void {
        $runs = $this->all();

        foreach ( $runs as $i => $run ) {
            if ( ( $run['id'] ?? '' ) === $import_id ) {
                $runs[ $i ]['rolled_back'] = true;
            }
        }

        update_option( self::OPTION, $runs );
    }

    /**
     * Element tags ranked by how many runs each appeared in.
     *
     * @return array<int, array{type:string, runs:int, last_seen:string}>
     */
    public function coverage(): array {
        $seen = [];

        foreach ( $this->all() as $run ) {
            foreach ( (array) ( $run['unsupported'] ?? [] ) as $type ) {
                if ( ! isset( $seen[ $type ] ) ) {
                    $seen[ $type ] = [ 'type' => $type, 'runs' => 0, 'last_seen' => '' ];
                }

                $seen[ $type ]['runs']++;
                if ( ( $run['at'] ?? '' ) > $seen[ $type ]['last_seen'] ) {
                    $seen[ $type ]['last_seen'] = (string) ( $run['at'] ?? '' );
                }
            }
        }

        $coverage = array_values( $seen );
        usort( $coverage, static fn( $a, $b ): int => $b['runs'] <=> $a['runs'] ?: strcmp( $a['type'], $b['type'] ) );

        return $coverage;
    }

    /**
     * Every theme or add-on family key seen across the recorded runs, in
     * first-seen order.
     *
     * @return string[]
     */
    public function theme_families(): array {
        $keys = [];

        foreach ( $this->all() as $run ) {
            foreach ( (array) ( $run['theme_families'] ?? [] ) as $key ) {
                if ( is_string( $key ) && $key !== '' && ! in_array( $key, $keys, true ) ) {
                    $keys[] = $key;
                }
            }
        }

        return $keys;
    }

    /**
     * De-duplicated unsupported WPBakery tags across a run's results, in
     * first-seen order.
     *
     * The engine records `['id' => …, 'tag' => …]`; the other two keys are
     * read for the sake of a result written by an older release.
     *
     * @param array[] $results
     * @return string[]
     */
    public static function element_types( array $results ): array {
        $types = [];

        foreach ( $results as $r ) {
            foreach ( (array) ( $r['unsupported'] ?? [] ) as $entry ) {
                $type = is_array( $entry ) ? ( $entry['tag'] ?? $entry['module'] ?? $entry['type'] ?? null ) : null;

                if ( is_string( $type ) && $type !== '' && ! in_array( $type, $types, true ) ) {
                    $types[] = $type;
                }
            }
        }

        return $types;
    }

    /**
     * The family **keys** behind a run's `theme_elements`, which the report
     * counts by label.
     *
     * Keys, not labels: the key is the stable identifier
     * (`ThemeShortcodes::FAMILIES`), and it is what the coverage endpoint is
     * told (task-12-amendments §4).
     *
     * @param array[] $results
     * @return string[]
     */
    public static function theme_family_keys( array $results ): array {
        $by_label = [];
        foreach ( ThemeShortcodes::families() as $key => $family ) {
            $by_label[ (string) ( $family['label'] ?? $key ) ] = (string) $key;
        }

        $keys = [];
        foreach ( $results as $r ) {
            $elements = is_array( $r['report']['theme_elements'] ?? null ) ? $r['report']['theme_elements'] : [];

            foreach ( array_keys( $elements ) as $label ) {
                $key = $by_label[ (string) $label ] ?? ThemeShortcodes::OTHER['key'];

                if ( ! in_array( $key, $keys, true ) ) {
                    $keys[] = $key;
                }
            }
        }

        return $keys;
    }
}
