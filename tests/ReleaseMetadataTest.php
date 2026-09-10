<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Telemetry\CoverageTelemetry;

/**
 * `readme.txt` is a promise, and every number in it has a source.
 *
 * The version has to agree in three places, the external service has to be
 * disclosed key by key, and the coverage figures have to be the ones
 * `scripts/element-coverage.php` counts — never retyped from memory
 * (task-12-amendments §5). The corpus that script scans is partly gitignored,
 * so the figures are kept in a committed snapshot which this test regenerates
 * whenever the full corpus is on disk.
 */
final class ReleaseMetadataTest extends TestCase {

    private const FREE     = __DIR__ . '/../plugin/jhmg-converter-for-wpbakery-to-divi';
    private const SNAPSHOT = __DIR__ . '/../fixtures/element-coverage.json';

    /** The export corpus is complete at 96 files; below that the snapshot is authoritative. */
    private const FULL_CORPUS_EXPORTS = 96;

    private function readme(): string {
        return (string) file_get_contents( self::FREE . '/readme.txt' );
    }

    /**
     * The coverage summary, refreshed from the script when every corpus is on
     * disk and read from the committed snapshot otherwise.
     *
     * @return array<string,mixed>
     */
    private function coverage(): array {
        $script = escapeshellarg( __DIR__ . '/../scripts/element-coverage.php' );
        $raw    = (string) shell_exec( 'php ' . $script . ' --json 2>/dev/null' );
        $live   = json_decode( $raw, true );

        if ( is_array( $live['summary'] ?? null ) && (int) ( $live['summary']['corpus_files']['exports'] ?? 0 ) >= self::FULL_CORPUS_EXPORTS ) {
            $encoded = (string) json_encode( $live['summary'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
            if ( ! file_exists( self::SNAPSHOT ) || file_get_contents( self::SNAPSHOT ) !== $encoded ) {
                file_put_contents( self::SNAPSHOT, $encoded );
            }
        }

        $snapshot = json_decode( (string) file_get_contents( self::SNAPSHOT ), true );
        $this->assertIsArray( $snapshot, 'the coverage snapshot is unreadable; run php scripts/element-coverage.php --json' );

        return $snapshot;
    }

    public function test_version_is_consistent_across_header_constant_and_readme(): void {
        $main   = (string) file_get_contents( self::FREE . '/jhmg-converter-for-wpbakery-to-divi.php' );
        $readme = $this->readme();

        $this->assertMatchesRegularExpression( '/^\s*\*\s*Version:\s*' . preg_quote( WBDC_PLUGIN_VERSION, '/' ) . '\s*$/m', $main );
        $this->assertStringContainsString( "WBDC_PLUGIN_VERSION', '" . WBDC_PLUGIN_VERSION . "'", $main );
        $this->assertMatchesRegularExpression( '/^Stable tag:\s*' . preg_quote( WBDC_PLUGIN_VERSION, '/' ) . '\s*$/m', $readme );
        $this->assertStringContainsString( '= ' . WBDC_PLUGIN_VERSION . ' =', $readme );
    }

    public function test_the_requirement_headers_agree_with_the_plugin_header(): void {
        $main   = (string) file_get_contents( self::FREE . '/jhmg-converter-for-wpbakery-to-divi.php' );
        $readme = $this->readme();

        preg_match( '/^\s*\*\s*Requires at least:\s*(\S+)\s*$/m', $main, $wp );
        preg_match( '/^\s*\*\s*Requires PHP:\s*(\S+)\s*$/m', $main, $php );

        $this->assertMatchesRegularExpression( '/^Requires at least:\s*' . preg_quote( $wp[1], '/' ) . '\s*$/m', $readme );
        $this->assertMatchesRegularExpression( '/^Requires PHP:\s*' . preg_quote( $php[1], '/' ) . '\s*$/m', $readme );
        $this->assertMatchesRegularExpression( '/^Tested up to:\s*\d+\.\d+(\.\d+)?\s*$/m', $readme, 'Tested up to is read off the running site, never left blank' );
    }

    public function test_readme_discloses_the_external_service_and_every_payload_key(): void {
        $readme = $this->readme();

        $this->assertStringContainsString( 'External services', $readme );
        $this->assertStringContainsString( CoverageTelemetry::ENDPOINT, $readme );
        $this->assertStringContainsString( 'opt-in', $readme );
        $this->assertStringContainsString( 'Nothing else', $readme );

        foreach ( array_keys( ( new CoverageTelemetry() )->payload() ) as $key ) {
            $this->assertStringContainsString( $key, $readme, "payload key '{$key}' must be disclosed" );
        }
    }

    public function test_readme_names_every_registered_element_group(): void {
        $readme = strtolower( $this->readme() );

        foreach ( [ 'structure', 'content elements', 'deprecated', 'wordpress widgets', '9.0 additions' ] as $group ) {
            $this->assertStringContainsString( $group, $readme, "element group '{$group}'" );
        }
    }

    public function test_readme_names_every_tag_wpbakery_registers(): void {
        $readme = $this->readme();

        foreach ( RegisteredShortcodesTest::registeredTags() as $tag ) {
            $this->assertStringContainsString( $tag, $readme, "WPBakery registers {$tag}; the readme must say so" );
        }
    }

    public function test_readme_quotes_the_coverage_scripts_own_numbers(): void {
        $summary = $this->coverage();
        $readme  = $this->readme();

        $this->assertStringContainsString(
            sprintf( '%d of the %d elements WPBakery registers', $summary['registered_mapped'], $summary['registered_tags'] ),
            $readme
        );
        $this->assertStringContainsString(
            sprintf(
                '%d exact, %d approximate, %d read by their parent element',
                $summary['registered_exact'],
                $summary['registered_approximate'],
                $summary['registered_read_by_parent']
            ),
            $readme
        );
        $this->assertStringContainsString(
            sprintf( '%d template-only and vendor tags', $summary['template_only_tags'] ),
            $readme
        );
        $this->assertStringContainsString(
            sprintf( '%d of the %d theme and add-on elements', $summary['theme_family_tags_handled'], $summary['theme_family_tags_seen'] ),
            $readme
        );
    }

    public function test_readme_says_what_happens_to_what_it_cannot_convert(): void {
        $readme = strtolower( $this->readme() );

        $this->assertStringContainsString( 'static', $readme );
        $this->assertStringContainsString( 'placeholder', $readme );
        $this->assertStringContainsString( 'never modified', $readme );
    }

    public function test_readme_names_wpbakery_as_a_third_party_trademark_without_claiming_affiliation(): void {
        $readme = $this->readme();

        $this->assertStringContainsString( 'WPBakery Page Builder', $readme );
        $this->assertStringContainsString( 'not affiliated with', $readme );
        $this->assertStringNotContainsString( 'official', $readme );
    }

    public function test_readme_does_not_promise_a_visual_preview(): void {
        $readme = $this->readme();

        $this->assertStringNotContainsString( 'visual preview', $readme );
        $this->assertStringNotContainsString( 'pixel', $readme );
    }

    public function test_readme_has_the_sections_wordpress_org_renders(): void {
        $readme = $this->readme();

        foreach ( [ '== Description ==', '== Installation ==', '== Frequently Asked Questions ==', '== Screenshots ==', '== External services ==', '== Changelog ==', '== Upgrade Notice ==' ] as $section ) {
            $this->assertStringContainsString( $section, $readme, $section );
        }
        $this->assertMatchesRegularExpression( '/^3\. /m', $readme, 'three screenshots are described' );
    }
}
