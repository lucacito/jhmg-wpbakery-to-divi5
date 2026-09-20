<?php
/**
 * Is `tests/support/kses-allowed-post.json` still WordPress's own `post` list?
 *
 * `MarkupSanitiser` filters every converted string through `wp_kses()` with
 * `wp_kses_allowed_html( 'post' )`, for every user — that filter is the whole
 * of the plugin's promise that it never publishes script, a style block or an
 * event handler. PHPUnit runs without WordPress, so the suite stubs that list
 * from a JSON file. A stub that has drifted from core would let the tests pass
 * on a filter no site actually uses, which is the one failure mode this file
 * exists to catch.
 *
 * Run inside the container, where WordPress is loaded, it compares the two tag
 * by tag and attribute by attribute. Core adding a tag or an attribute is a
 * divergence like any other: re-dump the file (the command is printed) and
 * read what changed.
 *
 * It also asserts the three things the sanitiser depends on, so a future core
 * release cannot quietly take them away: `script`, `style` and `iframe` are
 * not in the list, and `div` carries the `data-*` wildcard.
 *
 * Usage: wp eval-file /tmp/kses-parity.php --allow-root
 * Exit 0 and `kses parity ok: N tags`, or exit 1 and what differs.
 */

$path = '/tmp/kses-allowed-post.json';

$data = json_decode( (string) file_get_contents( $path ), true );
if ( ! is_array( $data ) || ! is_array( $data['tags'] ?? null ) ) {
    fwrite( STDERR, "kses parity: {$path} is not readable as the expected JSON.\n" );
    exit( 1 );
}

$expected = [];
foreach ( $data['tags'] as $tag => $attributes ) {
    $attributes = (array) $attributes;
    sort( $attributes );
    $expected[ (string) $tag ] = $attributes;
}
ksort( $expected );

$actual = [];
foreach ( wp_kses_allowed_html( 'post' ) as $tag => $attributes ) {
    $names = is_array( $attributes ) ? array_keys( $attributes ) : [];
    sort( $names );
    $actual[ (string) $tag ] = $names;
}
ksort( $actual );

$problems = [];

foreach ( array_diff( array_keys( $actual ), array_keys( $expected ) ) as $tag ) {
    $problems[] = "core allows <{$tag}>, the stub does not";
}
foreach ( array_diff( array_keys( $expected ), array_keys( $actual ) ) as $tag ) {
    $problems[] = "the stub allows <{$tag}>, core does not";
}
foreach ( $expected as $tag => $attributes ) {
    if ( ! isset( $actual[ $tag ] ) || $actual[ $tag ] === $attributes ) {
        continue;
    }

    $added   = array_diff( $actual[ $tag ], $attributes );
    $dropped = array_diff( $attributes, $actual[ $tag ] );
    $problems[] = sprintf(
        '<%s>: core %s',
        $tag,
        trim(
            ( $added !== [] ? 'also allows ' . implode( ', ', $added ) . ' ' : '' )
            . ( $dropped !== [] ? 'no longer allows ' . implode( ', ', $dropped ) : '' )
        )
    );
}

// What the sanitiser's promise rests on, stated rather than assumed.
foreach ( [ 'script', 'style', 'iframe' ] as $tag ) {
    if ( isset( $actual[ $tag ] ) ) {
        $problems[] = "core's post list now allows <{$tag}>; MarkupSanitiser assumes it does not";
    }
}
if ( ! in_array( 'data-*', $actual['div'] ?? [], true ) ) {
    $problems[] = "core's post list no longer carries the data-* wildcard on <div>; the converter's own element markers depend on it";
}

if ( $problems !== [] ) {
    fwrite( STDERR, "kses parity failed (WordPress " . $GLOBALS['wp_version'] . ", stub dumped from " . ( $data['wp_version'] ?? '?' ) . "):\n" );
    foreach ( $problems as $problem ) {
        fwrite( STDERR, "  - {$problem}\n" );
    }
    fwrite( STDERR, "\nRe-dump with:\n  docker compose exec -T wordpress php -r 'require \"/var/www/html/wp-load.php\"; \$a = wp_kses_allowed_html(\"post\"); ksort(\$a); \$o = []; foreach (\$a as \$t => \$x) { \$k = is_array(\$x) ? array_keys(\$x) : []; sort(\$k); \$o[\$t] = \$k; } echo json_encode([\"wp_version\" => \$GLOBALS[\"wp_version\"], \"context\" => \"post\", \"tags\" => \$o], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), \"\\n\";' > tests/support/kses-allowed-post.json\n" );
    exit( 1 );
}

echo 'kses parity ok: ' . count( $actual ) . " tags match WordPress " . $GLOBALS['wp_version'] . "'s post list.\n";
