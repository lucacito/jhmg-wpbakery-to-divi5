#!/usr/bin/env php
<?php
/**
 * Writes fixtures/wpbakery-templates/<slug>.txt — one file per template
 * WPBakery ships in config/templates.php — straight out of a js_composer zip.
 *
 * These are the only WPBakery layouts that exist in every installation, and
 * they are the corpus the parser and every handler are tested against, so
 * they are read from the source archive rather than transcribed.
 *
 * config/templates.php is a plain PHP script (a run of `$data['name'] = …;`
 * `$data['content'] = <<<'CONTENT' … CONTENT;` `vc_add_default_templates( $data );`
 * blocks) that calls into WPBakery on every block, so it cannot be included:
 * it is read as text. The heredoc is a nowdoc, so its body is the bytes of
 * the template with nothing interpolated, and it is copied out byte for byte:
 * no trailing newline is added, so a fixture is exactly what WPBakery would
 * have handed the editor.
 *
 * Usage:
 *   php scripts/wpb-templates-to-fixtures.php                       # references/js_composer.9.0.1.zip
 *   php scripts/wpb-templates-to-fixtures.php /path/to/js_composer.zip
 *   php scripts/wpb-templates-to-fixtures.php /path/to/config/templates.php
 */

const TEMPLATES_ENTRY = 'js_composer/config/templates.php';

/** @return string|null The templates.php source, or null when it is not there. */
function read_templates_source( string $source ): ?string {
    if ( is_file( $source ) && ! preg_match( '/\.zip$/i', $source ) ) {
        return (string) file_get_contents( $source );
    }

    if ( ! is_file( $source ) ) {
        return null;
    }

    $zip = new ZipArchive();
    if ( $zip->open( $source ) !== true ) {
        return null;
    }

    foreach ( [ TEMPLATES_ENTRY, 'config/templates.php' ] as $entry ) {
        $contents = $zip->getFromName( $entry );
        if ( $contents !== false ) {
            $zip->close();

            return $contents;
        }
    }
    $zip->close();

    return null;
}

/**
 * WPBakery's own slug for a template name, so a file name can be traced back
 * to the template it came from: lower case, non-alphanumerics collapsed to a
 * single hyphen (`sanitize_title()`'s shape, without WordPress present).
 */
function slugify( string $name ): string {
    $slug = preg_replace( '/[^a-z0-9]+/', '-', strtolower( html_entity_decode( $name, ENT_QUOTES, 'UTF-8' ) ) );

    return trim( (string) $slug, '-' );
}

$source = $argv[1] ?? dirname( __DIR__ ) . '/references/js_composer.9.0.1.zip';
$php    = read_templates_source( $source );

if ( $php === null ) {
    fwrite( STDERR, "cannot read config/templates.php from {$source}\n" );
    exit( 1 );
}

/*
 * Name and body of every block, in file order. The name is the first argument
 * of the esc_html__() call (single-quoted, and no template name contains a
 * quote in either release); the body is everything between the nowdoc opener
 * and a `CONTENT;` on its own line.
 */
$pattern = "/\\\$data\\['name'\\]\s*=\s*esc_html__\(\s*'((?:[^'\\\\]|\\\\.)*)'"
    . ".*?\\\$data\\['content'\\]\s*=\s*<<<'CONTENT'\n(.*?)\nCONTENT;/s";

if ( preg_match_all( $pattern, $php, $matches, PREG_SET_ORDER ) === false ) {
    fwrite( STDERR, 'templates.php could not be scanned: ' . preg_last_error_msg() . "\n" );
    exit( 1 );
}

$directory = dirname( __DIR__ ) . '/fixtures/wpbakery-templates';
if ( ! is_dir( $directory ) ) {
    mkdir( $directory, 0755, true );
}

$written = 0;
$seen    = [];

foreach ( $matches as $match ) {
    $name = stripcslashes( $match[1] );
    $slug = slugify( $name );

    if ( $slug === '' ) {
        fwrite( STDERR, "skipping a template whose name has no slug: {$name}\n" );
        continue;
    }

    if ( isset( $seen[ $slug ] ) ) {
        fwrite( STDERR, "skipping duplicate template name: {$name}\n" );
        continue;
    }
    $seen[ $slug ] = true;

    file_put_contents( $directory . '/' . $slug . '.txt', $match[2] );
    $written++;
}

echo 'wrote ' . $written . ' templates to ' . $directory . "\n";

if ( $written === 0 ) {
    exit( 1 );
}
