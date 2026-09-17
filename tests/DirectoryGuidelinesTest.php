<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\TestCase;

/**
 * WordPress.org Guideline 5: the free plugin is fully functional. Nothing in
 * it counts, caps or gates a conversion behind the Pro add-on — Pro adds its
 * own code (the Divi Library exporter) and never unlocks free code.
 *
 * A text search is crude on purpose: the directory's own review tooling reads
 * the source the same way.
 */
final class DirectoryGuidelinesTest extends TestCase {

    private const FREE = __DIR__ . '/../plugin/jhmg-converter-for-wpbakery-to-divi-5';

    /** @return array<string,string> path => contents of every PHP file and the readme */
    private function sources(): array {
        $out = [ 'readme.txt' => (string) file_get_contents( self::FREE . '/readme.txt' ) ];

        $files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( self::FREE, \FilesystemIterator::SKIP_DOTS ) );
        foreach ( $files as $file ) {
            if ( $file->getExtension() === 'php' ) {
                $out[ substr( $file->getPathname(), strlen( self::FREE ) + 1 ) ] = (string) file_get_contents( $file->getPathname() );
            }
        }

        return $out;
    }

    public function test_nothing_in_the_free_plugin_caps_a_run(): void {
        $forbidden = [
            'wbdc_direct_conversion_limit',
            'runUnlimited',
            'truncated()',
            'one page at a time',
            'one page per',
            'Pro converts',
            'Pro feature',
        ];

        foreach ( $this->sources() as $path => $source ) {
            foreach ( $forbidden as $needle ) {
                $this->assertStringNotContainsStringIgnoringCase( $needle, $source, "$path mentions \"$needle\"" );
            }
        }
    }
}
