<?php

namespace WPBakeryDivi5Converter\Tests;

use PHPUnit\Framework\TestCase;
use WPBakeryDivi5Converter\Telemetry\CoverageTelemetry;

/**
 * `readme.txt` is a promise, and every number in it has a source.
 *
 * The version has to agree in three places, the external service has to be
 * disclosed key by key, and the coverage figures have to be the ones
 * `scripts/element-coverage.php` reports — never retyped from memory
 * (task-12-amendments §5).
 *
 * Those figures are derived here from the registry, through the same
 * classification the script uses (`scripts/lib/element-coverage.php`, required
 * by both, so the two cannot drift). They are facts about the code: which tags
 * reach a handler, which of those are exact, approximate or read by a parent,
 * and how many theme and add-on handlers this plugin ships per family. How
 * often a tag turns up in the corpora is not — part of that corpus is
 * gitignored, so it differs per checkout — which is why no occurrence count is
 * quoted in the readme and none is read here.
 *
 * This test reads. It writes nothing, to the repository or anywhere else.
 */
final class ReleaseMetadataTest extends TestCase {

    private const FREE = __DIR__ . '/../plugin/jhmg-converter-for-wpbakery-to-divi';

    public static function setUpBeforeClass(): void {
        require_once __DIR__ . '/../scripts/lib/element-coverage.php';
    }

    private function readme(): string {
        return (string) file_get_contents( self::FREE . '/readme.txt' );
    }

    /**
     * The corpus-independent coverage summary, from the same helper
     * `scripts/element-coverage.php` classifies with.
     *
     * @return array<string,mixed>
     */
    private function coverage(): array {
        return \WBDC_Element_Coverage::summary();
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

    public function test_readme_quotes_the_registrys_own_coverage_numbers(): void {
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
            sprintf( '%d theme and add-on elements', $summary['family_handler_tags'] ),
            $readme
        );

        foreach ( $summary['family_handlers'] as $label => $count ) {
            $this->assertStringContainsString( sprintf( '%s × %d', $label, $count ), $readme, $label );
        }
    }

    /** The classification the readme is checked against is the one the script prints. */
    public function test_the_test_and_the_coverage_script_classify_identically(): void {
        if ( ! function_exists( 'shell_exec' ) ) {
            $this->markTestSkipped( 'shell_exec is disabled, so the script cannot be run for comparison' );
        }

        $raw  = json_decode( (string) shell_exec( 'php ' . escapeshellarg( __DIR__ . '/../scripts/element-coverage.php' ) . ' --json 2>/dev/null' ), true );
        $live = is_array( $raw['summary'] ?? null ) ? $raw['summary'] : [];

        $this->assertNotSame( [], $live, 'scripts/element-coverage.php --json produced no summary' );

        foreach ( $this->coverage() as $key => $value ) {
            $this->assertSame( $value, $live[ $key ] ?? null, $key );
        }
    }

    /** A theme family the converter recognises is named in the readme, so a reader can look for theirs. */
    public function test_readme_names_every_theme_family_the_converter_recognises(): void {
        $readme = $this->readme();

        foreach ( \WPBakeryDivi5Converter\Helpers\ThemeShortcodes::families() as $family ) {
            $this->assertStringContainsString( (string) $family['label'], $readme, (string) $family['label'] );
        }
    }

    /**
     * Whatever else this file does, it does not change the repository.
     *
     * `git status` returning nothing is both "clean" and "git could not run",
     * so the comparison is only worth making once the command has been shown
     * to work: `git status` on a repository always prints a branch line with
     * `--porcelain=v2 --branch`, so an empty answer there means git could not
     * run and the test says so rather than passing on a blank.
     */
    public function test_this_test_writes_nothing(): void {
        $probe = $this->git( 'status --porcelain=v2 --branch' );

        if ( $probe === null || ! str_contains( $probe, 'branch.oid' ) ) {
            $this->markTestSkipped( 'git is unavailable here, so "the tree did not change" cannot be observed' );
        }

        $before = $this->git( 'status --porcelain' );

        // Everything this file does that could conceivably write: the coverage
        // helper, the readme read, and the sibling test's shell-out.
        $this->coverage();
        $this->readme();
        shell_exec( 'php ' . escapeshellarg( __DIR__ . '/../scripts/element-coverage.php' ) . ' --json 2>/dev/null' );

        $this->assertSame( $before, $this->git( 'status --porcelain' ), 'a test must never write to the repository' );
    }

    /** @return string|null The command's output, or null when git could not run. */
    private function git( string $args ): ?string {
        if ( ! function_exists( 'shell_exec' ) ) {
            return null;
        }

        $out = shell_exec( 'git -C ' . escapeshellarg( dirname( __DIR__ ) ) . ' ' . $args . ' 2>/dev/null' );

        return is_string( $out ) ? $out : null;
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
