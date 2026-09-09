#!/usr/bin/env php
<?php
/**
 * Writes fixtures/wpbakery-layouts/<id>-<slug>.txt — one file per layout the
 * "Layouts for WPBakery" plugin offers — from its public template API.
 *
 * WPBakery's own templates cover the elements it shipped in 2014; these are
 * real, current landing pages, built with the elements people actually use
 * (sections with stretched backgrounds, tta tabs, grids, icon boxes), so they
 * are the corpus that says whether the converter handles a whole page rather
 * than a demo of one element.
 *
 * The plugin's own admin fetches exactly these two endpoints, unauthenticated
 * (layouts-for-wpbakery/includes/class-*-api.php in
 * references/layouts-for-wpbakery.1.1.5.zip):
 *
 *   GET /wp-json/layoutsforwpbakery/v1/templates            → { count, templates: [ { id, title, … } ] }
 *   GET /wp-json/layoutsforwpbakery/v1/template/byid/?id=N  → { id, title, template, … }
 *
 * Only the `template` string is kept, byte for byte, plus a README recording
 * where it came from. Files that already exist are left alone, so re-running
 * this only ever adds layouts the site has published since.
 *
 * Usage:
 *   php scripts/fetch-layouts-corpus.php                 # live API
 *   php scripts/fetch-layouts-corpus.php --cache=DIR     # a directory of already-downloaded byid responses
 */

const API_BASE  = 'https://www.layoutsforwpbakery.com/wp-json/layoutsforwpbakery/v1';
const USER_AGENT = 'jhmg-wpbakery-to-divi5 fixture fetcher (+https://divi5lab.com/plugins/wpbakery-to-divi-5)';

/** @return array<string, mixed>|null Decoded JSON, or null when the request failed. */
function get_json( string $url ): ?array {
    $context = stream_context_create( [
        'http' => [
            'method'        => 'GET',
            'header'        => "Accept: application/json\r\nUser-Agent: " . USER_AGENT . "\r\n",
            'timeout'       => 30,
            'ignore_errors' => true,
        ],
    ] );

    $body = @file_get_contents( $url, false, $context ); // phpcs:ignore
    if ( $body === false ) {
        return null;
    }

    $decoded = json_decode( $body, true );

    return is_array( $decoded ) ? $decoded : null;
}

/** Lower case, non-alphanumerics collapsed to one hyphen. */
function slugify( string $name ): string {
    $slug = preg_replace( '/[^a-z0-9]+/', '-', strtolower( html_entity_decode( $name, ENT_QUOTES, 'UTF-8' ) ) );

    return trim( (string) $slug, '-' );
}

/**
 * Every `template/byid` response already on disk, keyed by id — the fallback
 * for a run with no network.
 *
 * @return array<int, array<string, mixed>>
 */
function read_cache( string $directory ): array {
    $responses = [];

    foreach ( glob( rtrim( $directory, '/' ) . '/*.json' ) ?: [] as $file ) {
        $decoded = json_decode( (string) file_get_contents( $file ), true );
        if ( is_array( $decoded ) && isset( $decoded['id'], $decoded['template'] ) ) {
            $responses[ (int) $decoded['id'] ] = $decoded;
        }
    }

    return $responses;
}

$cache_dir = null;
foreach ( array_slice( $argv, 1 ) as $argument ) {
    if ( str_starts_with( $argument, '--cache=' ) ) {
        $cache_dir = substr( $argument, strlen( '--cache=' ) );
        continue;
    }
    fwrite( STDERR, "usage: php scripts/fetch-layouts-corpus.php [--cache=DIR]\n" );
    exit( 2 );
}

$cache = $cache_dir === null ? [] : read_cache( $cache_dir );
$index = $cache_dir === null ? get_json( API_BASE . '/templates' ) : [ 'templates' => array_values( $cache ) ];

if ( $index === null || ! isset( $index['templates'] ) || ! is_array( $index['templates'] ) ) {
    fwrite( STDERR, "the template index could not be read from " . API_BASE . "/templates\n" );
    exit( 1 );
}

$directory = dirname( __DIR__ ) . '/fixtures/wpbakery-layouts';
if ( ! is_dir( $directory ) ) {
    mkdir( $directory, 0755, true );
}

$written = 0;
$skipped = 0;
$failed  = 0;

foreach ( $index['templates'] as $entry ) {
    $id    = (int) ( $entry['id'] ?? 0 );
    $title = (string) ( $entry['title'] ?? '' );

    if ( $id === 0 ) {
        continue;
    }

    $file = $directory . '/' . $id . '-' . slugify( $title ) . '.txt';

    if ( is_file( $file ) ) {
        $skipped++;
        continue;
    }

    $template = $cache[ $id ] ?? get_json( API_BASE . '/template/byid/?id=' . $id );

    if ( $template === null || ! isset( $template['template'] ) || ! is_string( $template['template'] ) ) {
        fwrite( STDERR, "layout {$id} ({$title}) could not be fetched\n" );
        $failed++;
        continue;
    }

    file_put_contents( $file, $template['template'] );
    $written++;
}

$total = count( glob( $directory . '/*.txt' ) ?: [] );

/*
 * The README carries a fetch date, so it is only rewritten when this run
 * actually fetched something: a re-run that skips every layout must not
 * restamp a corpus it did not touch.
 */
if ( $written > 0 ) {
    write_readme( $directory, $total );
}

echo 'wrote ' . $written . ' layouts, skipped ' . $skipped . ' already there, ' . $failed . " failed\n";

if ( $failed > 0 ) {
    exit( 1 );
}

/** The credit file beside the corpus. */
function write_readme( string $directory, int $total ): void {
    file_put_contents(
        $directory . '/README.md',
        "# Layouts for WPBakery — layout corpus\n\n"
        . "The " . $total . " `.txt` files beside this README are the layouts published by\n"
        . "**[Layouts for WPBakery](https://wordpress.org/plugins/layouts-for-wpbakery/)**, a free plugin by\n"
        . "Techeshta (contributors: techeshta, alkesh7, vastarpara, hadihirpara), licensed **GPL-2.0-or-later**.\n"
        . "Each file is the `template` string of one layout, byte for byte: a WPBakery shortcode document.\n\n"
        . "They are here as test input only. The converter is tested against real pages rather than\n"
        . "hand-written snippets, and these are the largest set of current, freely licensed WPBakery\n"
        . "layouts there is. No layout, image or copy from them ships in the plugin.\n\n"
        . "Fetched from the plugin's own public API:\n\n"
        . "- index: `" . API_BASE . "/templates`\n"
        . "- layout: `" . API_BASE . "/template/byid/?id=<id>`\n\n"
        . "Last fetched: " . gmdate( 'Y-m-d' ) . " (UTC).\n\n"
        . "Regenerate with `php scripts/fetch-layouts-corpus.php`; it only writes files that are not\n"
        . "already there, so an existing layout is never silently rewritten.\n"
    );
}
