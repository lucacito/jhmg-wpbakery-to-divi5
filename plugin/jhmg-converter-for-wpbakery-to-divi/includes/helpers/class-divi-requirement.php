<?php
/**
 * Whether this site can actually hold what the converter produces.
 *
 * The converter writes Divi 5 block content and stamps `_et_pb_use_divi_5 = on`.
 * On a site running Divi 4 — or no Divi at all — that markup renders as nothing:
 * the post content is a run of block comments no installed builder claims, and
 * the meta flag points at a builder that is not there. Nothing errors. The page
 * is simply blank, and the conversion report says it converted cleanly.
 */

namespace WPBakeryDivi5Converter\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DiviRequirement {

    /**
     * The lowest Divi that understands the block format this plugin emits.
     * 5.0.0 is the first release whose builder reads `<!-- wp:divi/* -->` blocks
     * and honours `_et_pb_use_divi_5`; every Divi 4 release ignores both.
     */
    const MINIMUM_DIVI_VERSION = '5.0.0';

    /** Set at activation so the first admin page load can explain a failed install. */
    const ACTIVATION_NOTICE_OPTION = 'wbdc_divi_requirement_failed';

    /**
     * The running Divi version, or null when Divi is not present.
     *
     * Must not be called before the theme is loaded: Divi defines
     * ET_BUILDER_VERSION from its own functions.php, and themes load after
     * plugins, so at `plugins_loaded` this returns null on a healthy Divi site.
     */
    public static function detected_version(): ?string {
        foreach ( [ 'ET_BUILDER_VERSION', 'ET_CORE_VERSION' ] as $constant ) {
            if ( defined( $constant ) ) {
                $value = constant( $constant );
                if ( is_string( $value ) && $value !== '' ) {
                    return $value;
                }
            }
        }

        if ( function_exists( 'wp_get_theme' ) ) {
            $theme = wp_get_theme();
            if ( $theme && method_exists( $theme, 'get' ) ) {
                $template = method_exists( $theme, 'get_template' ) ? (string) $theme->get_template() : '';
                $name     = (string) $theme->get( 'Name' );

                if ( strcasecmp( $template, 'Divi' ) === 0 || strcasecmp( $name, 'Divi' ) === 0 ) {
                    $version = (string) $theme->get( 'Version' );
                    if ( $version !== '' ) {
                        return $version;
                    }
                }
            }
        }

        return null;
    }

    public static function is_satisfied(): bool {
        return self::failure_reason() === '';
    }

    /** '' when satisfied, otherwise 'missing' or 'too_old'. */
    public static function failure_reason(): string {
        $version = self::detected_version();

        if ( $version === null ) {
            return 'missing';
        }

        return version_compare( $version, self::MINIMUM_DIVI_VERSION, '>=' ) ? '' : 'too_old';
    }

    /** The notice text, or '' when there is nothing wrong. */
    public static function message(): string {
        $reason = self::failure_reason();

        if ( $reason === 'missing' ) {
            return sprintf(
                /* translators: %s: minimum required Divi version, e.g. 5.0.0 */
                __( 'WPBakery to Divi 5 Converter is inactive: Divi %s or newer is required, and no Divi installation was found. Converted pages would be saved in a format nothing on this site can render.', 'jhmg-converter-for-wpbakery-to-divi' ),
                self::MINIMUM_DIVI_VERSION
            );
        }

        if ( $reason === 'too_old' ) {
            return sprintf(
                /* translators: 1: detected Divi version, 2: minimum required Divi version */
                __( 'WPBakery to Divi 5 Converter is inactive: this site runs Divi %1$s, and the converter writes Divi 5 block content that requires Divi %2$s or newer. Converting now would produce pages that render blank.', 'jhmg-converter-for-wpbakery-to-divi' ),
                (string) self::detected_version(),
                self::MINIMUM_DIVI_VERSION
            );
        }

        return '';
    }

    /** Prints the admin notice. No-op when the requirement is met. */
    public static function render_notice(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $message = self::message();
        if ( $message === '' ) {
            return;
        }

        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html( $message )
        );
    }

    /**
     * Activation-time check. The plugin is left active deliberately: deactivating
     * it would hide the explanation along with the plugin.
     */
    public static function on_activation(): void {
        if ( self::is_satisfied() ) {
            delete_option( self::ACTIVATION_NOTICE_OPTION );
            return;
        }

        update_option( self::ACTIVATION_NOTICE_OPTION, self::failure_reason(), false );
    }
}
