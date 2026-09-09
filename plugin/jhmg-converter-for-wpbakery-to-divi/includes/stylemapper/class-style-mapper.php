<?php

namespace WPBakeryDivi5Converter\StyleMapper;

use WPBakeryDivi5Converter\Helpers\Color;
use WPBakeryDivi5Converter\Helpers\CssRuleParser;
use WPBakeryDivi5Converter\Helpers\PackedParams;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Maps WPBakery's design settings to Divi 5 block attribute paths.
 *
 * WPBakery's "design options" panel writes one CSS rule into the `css`
 * shortcode attribute — `.vc_custom_<timestamp>{prop: value !important;…}`,
 * built by `Vc_Css_Editor` (assets/js/dist/backend.min.js, `getBackground()` /
 * `getBorder()`) — plus the flat `el_id`, `el_class`, `css_animation` and
 * `disable_element` attributes. There are no breakpoints: the panel has one
 * rule per element, so everything written here is `desktop`. The only
 * per-breakpoint source in the whole converter is a column's `offset`
 * attribute, which `ColumnWidths::offsets()` reads.
 *
 * map() returns three things: the Divi attrs, the WPBakery keys it consumed
 * (so the handler can report what it did not), and notes about what it could
 * only approximate, which the engine records under "not carried over".
 * Nothing is dropped: a declaration this mapper cannot express lands verbatim
 * in the module's custom CSS and is reported as `custom_css_carried`.
 *
 * Every attribute path below was read from the Divi 5.12.1 source
 * (`module-library/src/components/<module>/module.json` and
 * `server/Packages/Module/Options/…`); the per-path table is in the task
 * report and, from Task 15 on, in docs/divi5-schema.md. Three modules do not
 * put their options where the rest do, which is why the paths are per kind:
 *
 * | kind   | spacing                    | sizing                    | border                    | background                    |
 * |--------|----------------------------|---------------------------|---------------------------|-------------------------------|
 * | image  | `module.advanced.spacing`  | `module.advanced.sizing`  | `image.decoration.border` | `module.decoration.background`|
 * | button | `module.decoration.spacing`| `button.decoration.sizing`| `button.decoration.border`| `button.decoration.background`|
 * | others | `module.decoration.spacing`| `module.decoration.sizing`| `module.decoration.border`| `module.decoration.background`|
 *
 * The image row is measured, not guessed: `ImageModule.php` reads
 * `module.advanced.spacing` (with `important` on the margin) and
 * `module.advanced.sizing`; a margin written at `module.decoration.spacing`
 * renders without `!important` and loses to
 * `.et_flex_column>.et_pb_module{margin-bottom:unset}` (docs/box-model.md).
 * `divi/image` has no `module.decoration.border`, and `divi/button` has
 * neither `module.decoration.border` nor `module.decoration.background`.
 *
 * The default bottom margin an element carries when its `css` sets none is
 * NOT applied here — a handler asks `GlobalSettingsResolver::moduleMarginBottom()`
 * for it, and an explicit `margin-bottom` in `css` always wins (WPBakery emits
 * it `!important`). A row's `gap` is the row's business too: `map( 'column', … )`
 * never adds `gap/2` padding.
 */
class StyleMapper {

    /**
     * Divi font decoration path per node kind (the primary text). Sub-names for
     * all of them come from `Module/Options/Font/FontPresetAttrsMap.php`
     * (`size`, `lineHeight`, `textAlign`, `color`, `family`, `weight`, `style`,
     * `headingLevel`) and are emitted by
     * `StyleLibrary/Declarations/Font/Font.php`.
     */
    const FONT_PATH = [
        // `heading/module.json` → title.settings.decoration.font, with
        // `fields.headingLevel.render` on.
        'heading' => 'title.decoration.font.font',
        // `text/module.json` → content.settings.decoration.bodyFont
        // (+ content.styleProps.bodyFont.important.body.font).
        'text'    => 'content.decoration.bodyFont.body.font',
        // `button/module.json` → button.styleProps.font.important.font.
        'button'  => 'button.decoration.font.font',
        // `blurb/module.json` → title.settings.decoration.font.
        'blurb'   => 'title.decoration.font.font',
        // `cta/module.json` → title.settings.decoration.font.
        'cta'     => 'title.decoration.font.font',
        // `number-counter/module.json` and `circle-counter/module.json` →
        // number.settings.decoration.font. The bar counter (`divi/counter`) has
        // no `number` element at all and its handler passes
        // `title.decoration.font.font` / `barProgress.decoration.font.font`.
        'counter' => 'number.decoration.font.font',
    ];

    /** Attributes every node can carry, acknowledged whether present or not. */
    const COMMON_KEYS = [ 'css', 'el_id', 'el_class', 'css_animation', 'disable_element' ];

    const SIDES = [ 'top', 'right', 'bottom', 'left' ];

    /** CSS corner property ⇒ Divi `border.…value.radius` key. */
    const CORNERS = [
        'border-top-left-radius'     => 'topLeft',
        'border-top-right-radius'    => 'topRight',
        'border-bottom-right-radius' => 'bottomRight',
        'border-bottom-left-radius'  => 'bottomLeft',
    ];

    /** CSS sizing property ⇒ Divi `sizing.…value` key. */
    const SIZING = [
        'width'      => 'width',
        'max-width'  => 'maxWidth',
        'height'     => 'height',
        'max-height' => 'maxHeight',
        'min-height' => 'minHeight',
    ];

    const BACKGROUND_REPEATS = [ 'repeat', 'no-repeat', 'repeat-x', 'repeat-y', 'space', 'round' ];

    /** CSS keywords Divi's border `style` understands, as WPBakery's dropdown offers them. */
    const BORDER_STYLES = [ 'solid', 'dotted', 'dashed', 'none', 'hidden', 'double', 'groove', 'ridge', 'inset', 'outset', 'initial', 'inherit' ];

    /**
     * @param string $kind    section|row|column|group|heading|text|image|button|icon|blurb|cta|counter|generic
     * @param array<string,mixed> $atts   a normalised node's attributes (9.0.1 names)
     * @param array{mode?: string, attachments?: array<int,string>} $context
     * @return array{divi_attrs: array, handled_keys: string[], notes: array<int,array{kind:string,detail:string}>}
     */
    public function map( string $kind, array $atts, array $context = [] ): array {
        $attrs = [];
        $notes = [];

        $this->mapCss( $kind, $atts, $context, $attrs, $notes );
        $this->mapHtmlAttributes( $atts, $attrs );
        $this->mapAnimation( $atts, $notes );
        $this->mapVisibility( $atts, $attrs, $notes );

        return [
            'divi_attrs'   => $attrs,
            'handled_keys' => self::COMMON_KEYS,
            'notes'        => $notes,
        ];
    }

    // -------------------------------------------------------------------------
    // Public helpers used by handlers
    // -------------------------------------------------------------------------

    /** Write a value into a nested array using a dot path. */
    public static function write( array &$target, string $dot_path, mixed $value ): void {
        $keys    = explode( '.', $dot_path );
        $current = &$target;
        foreach ( $keys as $key ) {
            if ( ! array_key_exists( $key, $current ) || ! is_array( $current[ $key ] ) ) {
                $current[ $key ] = [];
            }
            $current = &$current[ $key ];
        }
        $current = $value;
    }

    public static function fontPathFor( string $kind ): ?string {
        return self::FONT_PATH[ $kind ] ?? null;
    }

    /**
     * A decoded `font_container` (`PackedParams::fontContainer()`) applied to a
     * Divi font path. Public so the custom-heading handler — and any handler
     * with a secondary text element — can reuse it.
     *
     * `Vc_Custom_Heading` (include/classes/shortcodes/vc-custom-heading.php)
     * runs `font_size` through `wpb_format_with_css_unit()` and emits
     * `line_height` verbatim with its whitespace stripped, which is why a
     * unitless line height is passed through unchanged here.
     *
     * @param array<string,string> $fc
     */
    public function applyFontContainer( array $fc, string $font_path, array &$attrs ): void {
        $tag = strtolower( trim( $fc['tag'] ?? '' ) );
        if ( preg_match( '/^h[1-6]$/', $tag ) ) {
            self::write( $attrs, "{$font_path}.desktop.value.headingLevel", $tag );
        }

        $size = PackedParams::sizeWithUnit( trim( $fc['font_size'] ?? '' ) );
        if ( $size !== '' ) {
            self::write( $attrs, "{$font_path}.desktop.value.size", $size );
        }

        $align = strtolower( trim( $fc['text_align'] ?? '' ) );
        if ( in_array( $align, [ 'left', 'center', 'right', 'justify' ], true ) ) {
            self::write( $attrs, "{$font_path}.desktop.value.textAlign", $align );
        }

        $line_height = preg_replace( '/\s+/', '', (string) ( $fc['line_height'] ?? '' ) );
        if ( $line_height !== '' ) {
            self::write( $attrs, "{$font_path}.desktop.value.lineHeight", $line_height );
        }

        $color = Color::normalize( $fc['color'] ?? '' );
        if ( $color !== null ) {
            self::write( $attrs, "{$font_path}.desktop.value.color", $color );
        }

        $family = trim( $fc['font_family'] ?? '' );
        if ( $family !== '' ) {
            self::write( $attrs, "{$font_path}.desktop.value.family", $family );
        }
    }

    /**
     * A decoded `google_fonts` value (`PackedParams::googleFonts()`) applied to
     * a Divi font path. The caller decides whether to call it — WPBakery
     * ignores `google_fonts` when `use_theme_fonts` is on.
     *
     * @param array{family?: string, weight?: string, italic?: bool} $gf
     */
    public function applyGoogleFonts( array $gf, string $font_path, array &$attrs ): void {
        $family = trim( (string) ( $gf['family'] ?? '' ) );
        if ( $family !== '' ) {
            self::write( $attrs, "{$font_path}.desktop.value.family", $family );
        }

        $weight = strtolower( trim( (string) ( $gf['weight'] ?? '' ) ) );
        if ( $weight !== '' && $weight !== 'regular' && $weight !== 'italic' ) {
            self::write( $attrs, "{$font_path}.desktop.value.weight", $weight );
        }

        if ( ! empty( $gf['italic'] ) || $weight === 'italic' ) {
            self::write( $attrs, "{$font_path}.desktop.value.style", [ 'italic' ] );
        }
    }

    // -------------------------------------------------------------------------
    // Attribute paths per kind
    // -------------------------------------------------------------------------

    private function spacingPath( string $kind ): string {
        return $kind === 'image' ? 'module.advanced.spacing' : 'module.decoration.spacing';
    }

    private function sizingPath( string $kind ): string {
        if ( $kind === 'image' ) {
            return 'module.advanced.sizing';
        }

        return $kind === 'button' ? 'button.decoration.sizing' : 'module.decoration.sizing';
    }

    private function borderPath( string $kind ): string {
        if ( $kind === 'image' ) {
            return 'image.decoration.border';
        }

        return $kind === 'button' ? 'button.decoration.border' : 'module.decoration.border';
    }

    private function backgroundPath( string $kind ): string {
        return $kind === 'button' ? 'button.decoration.background' : 'module.decoration.background';
    }

    // -------------------------------------------------------------------------
    // The `css` design-options rule
    // -------------------------------------------------------------------------

    private function mapCss( string $kind, array $atts, array $context, array &$attrs, array &$notes ): void {
        $css = $atts['css'] ?? '';
        if ( ! is_string( $css ) || trim( $css ) === '' ) {
            return;
        }

        // Only the first rule belongs to this element. `CssRuleParser::parse()`
        // stops at the first `}` exactly as `vc_shortcode_custom_css_class()`
        // does, and the gradient pre-pass below has to see the same slice — a
        // `css` value carrying two rules must not have the second one's
        // background lifted onto this module.
        $brace = strpos( $css, '}' );
        if ( $brace === false ) {
            return;
        }
        $css = substr( $css, 0, $brace + 1 );

        // Pull gradients out before parsing: `CssRuleParser` splits a
        // `background` shorthand into a colour and a `url()`, which would read
        // a gradient's first colour stop as the background colour.
        $gradients = [];
        $css       = $this->extractGradients( $css, $gradients );

        $remaining = CssRuleParser::parse( $css )['declarations'];

        $this->mapSpacing( $kind, $remaining, $attrs );
        $this->mapSizing( $kind, $remaining, $attrs );
        $this->mapBorder( $kind, $remaining, $attrs );
        $this->mapBackground( $kind, $remaining, $context, $attrs, $notes );
        $this->mapGradients( $kind, $gradients, $remaining, $attrs );

        $this->carryLeftovers( $remaining, $attrs, $notes );
    }

    /**
     * `margin-*` / `padding-*` → `{spacing path}.desktop.value.{margin,padding}`
     * as `{top,right,bottom,left,syncVertical,syncHorizontal}`
     * (`Module/Options/Spacing/SpacingPresetAttrsMap.php`, sub-names `margin`
     * and `padding`). The sync flags are `off` because WPBakery's four inputs
     * are independent.
     *
     * @param array<string,string> $declarations
     */
    private function mapSpacing( string $kind, array &$declarations, array &$attrs ): void {
        $path = $this->spacingPath( $kind );

        foreach ( [ 'margin', 'padding' ] as $prop ) {
            $sides = $this->boxSides( $prop, $declarations );
            if ( $sides === [] ) {
                continue;
            }

            self::write( $attrs, "{$path}.desktop.value.{$prop}", array_merge(
                [ 'top' => '', 'right' => '', 'bottom' => '', 'left' => '' ],
                $sides,
                [ 'syncVertical' => 'off', 'syncHorizontal' => 'off' ]
            ) );
        }
    }

    /**
     * @param array<string,string> $declarations
     */
    private function mapSizing( string $kind, array &$declarations, array &$attrs ): void {
        $path = $this->sizingPath( $kind );

        foreach ( self::SIZING as $property => $sub_name ) {
            if ( ! isset( $declarations[ $property ] ) ) {
                continue;
            }
            $value = $this->length( $declarations[ $property ] );
            if ( $value === '' ) {
                continue;
            }
            unset( $declarations[ $property ] );
            self::write( $attrs, "{$path}.desktop.value.{$sub_name}", $value );
        }
    }

    /**
     * @param array<string,string> $declarations
     */
    private function mapBorder( string $kind, array &$declarations, array &$attrs ): void {
        $path = $this->borderPath( $kind );

        $widths = [];
        $colors = [];
        $styles = [];

        // `border: 1px solid #000` — not something WPBakery's panel writes, but
        // themes and hand-edited rules do.
        if ( isset( $declarations['border'] ) ) {
            $shorthand = $this->borderShorthand( $declarations['border'] );
            if ( $shorthand !== null ) {
                unset( $declarations['border'] );
                foreach ( self::SIDES as $side ) {
                    if ( $shorthand['width'] !== '' ) {
                        $widths[ $side ] = $shorthand['width'];
                    }
                }
                if ( $shorthand['color'] !== '' ) {
                    $colors['all'] = $shorthand['color'];
                }
                if ( $shorthand['style'] !== '' ) {
                    $styles['all'] = $shorthand['style'];
                }
            }
        }

        foreach ( $this->boxSides( 'border', $declarations, '-width' ) as $side => $value ) {
            $widths[ $side ] = $value;
        }

        $colors = array_merge( $colors, $this->borderAspects( 'color', $declarations ) );
        $styles = array_merge( $styles, $this->borderAspects( 'style', $declarations ) );

        // Four equal widths are `styles.all.width`; Divi only emits a side's
        // width when it differs from the "all" one anyway (Declarations/Border.php).
        if ( count( $widths ) === 4 && count( array_unique( $widths ) ) === 1 ) {
            $widths = [ 'all' => reset( $widths ) ];
        }

        foreach ( [ 'width' => $widths, 'color' => $colors, 'style' => $styles ] as $aspect => $bucket ) {
            foreach ( $bucket as $side => $value ) {
                self::write( $attrs, "{$path}.desktop.value.styles.{$side}.{$aspect}", $value );
            }
        }

        $this->mapRadius( $path, $declarations, $attrs );
    }

    /**
     * `border-radius` and the four per-corner properties WPBakery's editor
     * writes (`wpb_border_radius_controls()`, css_editor.php) →
     * `{border path}.desktop.value.radius`.
     * `StyleLibrary/Declarations/Border/Border.php` accepts exactly
     * `topLeft`, `topRight`, `bottomRight`, `bottomLeft` and skips anything
     * else, and emits only the corners present — so an unset corner has to be
     * an explicit zero or the theme's own radius shows through.
     *
     * @param array<string,string> $declarations
     */
    private function mapRadius( string $path, array &$declarations, array &$attrs ): void {
        $corners = [];

        if ( isset( $declarations['border-radius'] ) && ! str_contains( $declarations['border-radius'], '/' ) ) {
            $parts = $this->splitValues( $declarations['border-radius'] );
            $count = count( $parts );
            if ( $count >= 1 && $count <= 4 ) {
                $expanded = [
                    'topLeft'     => $parts[0],
                    'topRight'    => $parts[1] ?? $parts[0],
                    'bottomRight' => $parts[2] ?? $parts[0],
                    'bottomLeft'  => $parts[3] ?? ( $parts[1] ?? $parts[0] ),
                ];
                $usable = true;
                foreach ( $expanded as $corner => $raw ) {
                    $value = $this->length( $raw );
                    if ( $value === '' ) {
                        $usable = false;
                        break;
                    }
                    $corners[ $corner ] = $value;
                }
                if ( $usable ) {
                    unset( $declarations['border-radius'] );
                } else {
                    $corners = [];
                }
            }
        }

        foreach ( self::CORNERS as $property => $corner ) {
            if ( ! isset( $declarations[ $property ] ) ) {
                continue;
            }
            $value = $this->length( $declarations[ $property ] );
            if ( $value === '' ) {
                continue;
            }
            unset( $declarations[ $property ] );
            $corners[ $corner ] = $value;
        }

        if ( $corners === [] ) {
            return;
        }

        // Divi writes each corner it finds; an unset one has to be an explicit
        // zero or the theme's own radius shows through.
        self::write( $attrs, "{$path}.desktop.value.radius", [
            'topLeft'     => $corners['topLeft'] ?? '0px',
            'topRight'    => $corners['topRight'] ?? '0px',
            'bottomRight' => $corners['bottomRight'] ?? '0px',
            'bottomLeft'  => $corners['bottomLeft'] ?? '0px',
        ] );
    }

    /**
     * `background-color` → `{background path}.desktop.value.color`, and
     * `background-image` / `-position` / `-repeat` / `-size` →
     * `…value.image.{url,position,repeat,size}`
     * (`Module/Options/Background/BackgroundPresetAttrsMap.php`).
     * The value vocabulary is Divi's own:
     * `StyleLibrary/Declarations/Background/Utils/BackgroundStyleUtils.php`
     * (`get_background_size_css()` knows cover/contain/stretch/custom/initial,
     * `get_background_position_css()` an `x y` keyword pair) and the emit gate
     * in `…/Background/Traits/StyleDeclarationTrait.php`, which only writes
     * size/position/repeat when an image URL is set — hence the `$has_image`
     * guard here, so an unusable one is carried as custom CSS instead.
     *
     * @param array<string,string> $declarations
     */
    private function mapBackground( string $kind, array &$declarations, array $context, array &$attrs, array &$notes ): void {
        $path = $this->backgroundPath( $kind ) . '.desktop.value';

        if ( isset( $declarations['background-color'] ) ) {
            $color = Color::normalize( $declarations['background-color'] );
            if ( $color !== null ) {
                unset( $declarations['background-color'] );
                self::write( $attrs, "{$path}.color", $color );
            }
        }

        $has_image = false;
        if ( isset( $declarations['background-image'] ) ) {
            $url = $this->backgroundImageUrl( $declarations['background-image'], $context, $notes );
            if ( $url !== null ) {
                unset( $declarations['background-image'] );
                if ( $url !== '' ) {
                    $has_image = true;
                    self::write( $attrs, "{$path}.image.url", $url );
                }
            }
        }

        if ( ! $has_image ) {
            return;
        }

        if ( isset( $declarations['background-size'] ) ) {
            $size = $this->backgroundSize( $declarations['background-size'] );
            if ( $size !== null ) {
                unset( $declarations['background-size'] );
                self::write( $attrs, "{$path}.image.size", $size );
            }
        }

        if ( isset( $declarations['background-position'] ) ) {
            $position = $this->backgroundPosition( $declarations['background-position'] );
            if ( $position !== null ) {
                unset( $declarations['background-position'] );
                self::write( $attrs, "{$path}.image.position", $position );
            }
        }

        if ( isset( $declarations['background-repeat'] ) ) {
            $repeat = strtolower( trim( $declarations['background-repeat'] ) );
            if ( in_array( $repeat, self::BACKGROUND_REPEATS, true ) ) {
                unset( $declarations['background-repeat'] );
                self::write( $attrs, "{$path}.image.repeat", $repeat );
            }
        }
    }

    /**
     * The `url(…)` a design-options background carries. WPBakery's own WXR
     * importer appends `?id=<attachment>` when it remaps attachment URLs
     * (`class-vc-wxr-parser-plugin.php::remapAttachmentUrls()`), so the id is
     * resolved through the media library on this site or through the export's
     * attachment map; when neither knows it the URL is kept as it stands and
     * reported. The image is never dropped.
     *
     * @return string|null Null when the value is not a `url(…)` at all (leave it alone).
     */
    private function backgroundImageUrl( string $raw, array $context, array &$notes ): ?string {
        $ref = CssRuleParser::imageRef( $raw );
        if ( $ref['url'] === '' && $ref['id'] === null ) {
            return null;
        }

        $attachments = is_array( $context['attachments'] ?? null ) ? $context['attachments'] : [];
        $is_import   = ( $context['mode'] ?? 'direct' ) === 'import';

        if ( $ref['id'] !== null ) {
            $resolved = $attachments[ $ref['id'] ] ?? '';

            if ( ! $is_import && ( ! is_string( $resolved ) || $resolved === '' ) && function_exists( 'wp_get_attachment_url' ) ) {
                $from_library = wp_get_attachment_url( $ref['id'] );
                $resolved     = is_string( $from_library ) ? $from_library : '';
            }

            if ( is_string( $resolved ) && $resolved !== '' ) {
                return $resolved;
            }

            $notes[] = [
                'kind'   => 'unresolved_media',
                'detail' => sprintf(
                    'background image attachment %d not found; %s',
                    $ref['id'],
                    $ref['url'] === '' ? 'no URL to fall back on' : 'the original URL was kept'
                ),
            ];
        }

        return $ref['url'];
    }

    /** WPBakery writes `cover` or `contain`; Divi's own vocabulary adds `stretch` and `initial`. */
    private function backgroundSize( string $raw ): ?string {
        $value = strtolower( trim( preg_replace( '/\s+/', ' ', $raw ) ?? '' ) );

        if ( $value === 'cover' || $value === 'contain' ) {
            return $value;
        }
        if ( $value === '100% 100%' ) {
            return 'stretch';
        }
        if ( $value === 'auto' || $value === 'initial' || $value === 'auto auto' ) {
            return 'initial';
        }

        return null;
    }

    /**
     * Divi stores the position as an `x y` keyword pair
     * (`BackgroundStyleUtils::get_background_position_css()`); WPBakery writes
     * `center` (with `cover`/`contain`) or `0 0` (with `repeat`).
     */
    private function backgroundPosition( string $raw ): ?string {
        $words = preg_split( '/\s+/', strtolower( trim( $raw ) ) ) ?: [];
        if ( $words === [] || count( $words ) > 2 ) {
            return null;
        }

        $keyword = static function ( string $word, string $start, string $end ): ?string {
            if ( $word === 'center' ) {
                return 'center';
            }
            if ( $word === $start || $word === $end ) {
                return $word;
            }
            if ( $word === '0' || $word === '0%' || $word === '0px' ) {
                return $start;
            }
            if ( $word === '100%' ) {
                return $end;
            }
            if ( $word === '50%' ) {
                return 'center';
            }

            return null;
        };

        // A single `left`/`right` names the x axis, a single `top`/`bottom` the y.
        if ( count( $words ) === 1 ) {
            if ( in_array( $words[0], [ 'top', 'bottom' ], true ) ) {
                return 'center ' . $words[0];
            }
            $x = $keyword( $words[0], 'left', 'right' );

            return $x === null ? null : $x . ' center';
        }

        // WPBakery only ever writes the x-then-y order, but a theme can write either.
        if ( in_array( $words[0], [ 'top', 'bottom' ], true ) || in_array( $words[1], [ 'left', 'right' ], true ) ) {
            $words = [ $words[1], $words[0] ];
        }

        $x = $keyword( $words[0], 'left', 'right' );
        $y = $keyword( $words[1], 'top', 'bottom' );

        return ( $x === null || $y === null ) ? null : $x . ' ' . $y;
    }

    /**
     * @param array<int,array{prop: string, value: string}> $gradients
     * @param array<string,string> $declarations
     */
    private function mapGradients( string $kind, array $gradients, array &$declarations, array &$attrs ): void {
        $path    = $this->backgroundPath( $kind ) . '.desktop.value.gradient';
        $written = false;

        foreach ( $gradients as $gradient ) {
            $parsed = $written ? null : $this->parseGradient( $gradient['value'] );

            if ( $parsed === null ) {
                // Inexpressible (or a second gradient Divi has no slot for):
                // carried verbatim rather than dropped.
                $declarations[ $gradient['prop'] ] = $gradient['value'];
                continue;
            }

            $written = true;
            foreach ( $parsed as $key => $value ) {
                self::write( $attrs, "{$path}.{$key}", $value );
            }
        }
    }

    /**
     * A CSS gradient function in Divi's shape, or null when Divi cannot express
     * it. Stop positions are bare numbers: Divi appends the unit itself
     * (`GradientUtils::gradient_style_declaration()` builds `"{$color} {$position}{$unit}"`,
     * and `_sanitize_gradient_stop_position()` rejects anything but a number),
     * so a position carrying `%` drops the whole background declaration.
     *
     * @return array<string,mixed>|null
     */
    private function parseGradient( string $value ): ?array {
        if ( ! preg_match( '/((?:repeating-)?(?:linear|radial|conic)-gradient)\s*(\((?:[^()]++|(?2))*+\))/i', $value, $m ) ) {
            return null;
        }

        $function = strtolower( $m[1] );
        $args     = $this->splitTopLevel( substr( $m[2], 1, -1 ) );
        if ( count( $args ) < 2 ) {
            return null;
        }

        $gradient = [ 'enabled' => 'on' ];
        if ( str_starts_with( $function, 'repeating-' ) ) {
            $gradient['repeat'] = 'on';
            $function           = substr( $function, strlen( 'repeating-' ) );
        }

        $head = strtolower( trim( $args[0] ) );

        if ( $function === 'linear-gradient' ) {
            $gradient['type'] = 'linear';
            if ( str_starts_with( $head, 'to ' ) || preg_match( '/^-?[\d.]+(deg|grad|rad|turn)$/', $head ) ) {
                $gradient['direction'] = $head;
                array_shift( $args );
            }
        } elseif ( $function === 'conic-gradient' ) {
            $gradient['type'] = 'conic';
            if ( str_starts_with( $head, 'from ' ) || str_starts_with( $head, 'at ' ) ) {
                if ( preg_match( '/from\s+(-?[\d.]+(?:deg|grad|rad|turn))/', $head, $angle ) ) {
                    $gradient['direction'] = $angle[1];
                }
                $gradient['directionRadial'] = $this->radialPosition( $head );
                array_shift( $args );
            }
        } else {
            // `radial-gradient(circle at top left, …)`; CSS defaults to an ellipse.
            $gradient['type'] = 'elliptical';
            if ( $head !== '' && ! $this->looksLikeStop( $head ) ) {
                if ( str_contains( $head, 'circle' ) ) {
                    $gradient['type'] = 'circular';
                }
                $gradient['directionRadial'] = $this->radialPosition( $head );
                array_shift( $args );
            } else {
                $gradient['directionRadial'] = 'center';
            }
        }

        $stops = $this->parseGradientStops( $args );
        if ( $stops === null ) {
            return null;
        }
        $gradient['stops'] = $stops;

        return $gradient;
    }

    /**
     * @param string[] $args
     * @return array<int,array{color: string, position: string}>|null
     */
    private function parseGradientStops( array $args ): ?array {
        $stops = [];

        foreach ( $args as $arg ) {
            $arg = trim( $arg );
            if ( $arg === '' ) {
                return null;
            }

            $position = null;
            if ( preg_match( '/^(.*?)\s+(-?\d+(?:\.\d+)?)%$/', $arg, $m ) ) {
                $arg      = trim( $m[1] );
                $position = str_contains( $m[2], '.' ) ? rtrim( rtrim( $m[2], '0' ), '.' ) : $m[2];
                $position = $position === '' || $position === '-' ? '0' : $position;
            } elseif ( preg_match( '/\s\S+$/', $arg ) && ! preg_match( '/^(?:rgba?|hsla?)\s*\(/i', $arg ) ) {
                // A stop length in px/em/… — Divi appends `%` to every position.
                return null;
            }

            $color = Color::normalize( $arg );
            if ( $color === null ) {
                return null;
            }

            $stops[] = [ 'color' => $color, 'position' => $position ];
        }

        $count = count( $stops );
        if ( $count < 2 ) {
            return null;
        }

        foreach ( $stops as $index => $stop ) {
            if ( $stop['position'] === null ) {
                $stops[ $index ]['position'] = (string) (int) round( $index / ( $count - 1 ) * 100 );
            }
        }

        return $stops;
    }

    /** The `at <position>` half of a radial or conic gradient, in Divi's vocabulary. */
    private function radialPosition( string $head ): string {
        if ( ! preg_match( '/\bat\s+(.+)$/', $head, $m ) ) {
            return 'center';
        }

        $position = trim( preg_replace( '/\s+/', ' ', $m[1] ) ?? '' );

        // `_sanitize_gradient_position()`: one or two of these keywords, nothing else.
        return preg_match( '/^(?:center|top|bottom|left|right)(?:\s+(?:center|top|bottom|left|right))?$/', $position ) === 1
            ? $position
            : 'center';
    }

    private function looksLikeStop( string $value ): bool {
        return Color::normalize( preg_replace( '/\s+-?[\d.]+\S*$/', '', $value ) ?? $value ) !== null;
    }

    /**
     * `background`/`background-image` declarations whose value is a gradient,
     * removed from the rule and handed back separately. `CssRuleParser` splits
     * a `background` shorthand into a colour and a `url()` token and would read
     * a gradient's first colour stop as the background colour.
     *
     * $css is always the first rule alone (mapCss() truncates at the first
     * `}`), so a second rule in the same attribute can never contribute a
     * gradient to this element.
     *
     * @param array<int,array{prop: string, value: string}> $gradients
     */
    private function extractGradients( string $css, array &$gradients ): string {
        $pattern = '/(?<![\w-])(background|background-image)\s*:\s*((?:[^;{}()]++|(\((?:[^()]++|(?3))*+\)))++)/i';

        $stripped = preg_replace_callback( $pattern, static function ( array $m ) use ( &$gradients ): string {
            $value = trim( (string) preg_replace( '/\s*!\s*important\s*$/i', '', trim( $m[2] ) ) );

            if ( ! preg_match( '/(?:repeating-)?(?:linear|radial|conic)-gradient\s*\(/i', $value ) ) {
                return $m[0];
            }

            $gradients[] = [ 'prop' => strtolower( $m[1] ), 'value' => $value ];

            return '';
        }, $css );

        return is_string( $stripped ) ? $stripped : $css;
    }

    /**
     * Everything the mapper could not express, verbatim, on the module's own
     * custom CSS. `css.desktop.value.mainElement` is Divi 5.12.1's name for the
     * "Main Element" custom-CSS field (`css__mainElement` in every module's
     * preset-attribute map; there is no `css.*.main`).
     *
     * @param array<string,string> $declarations
     */
    private function carryLeftovers( array $declarations, array &$attrs, array &$notes ): void {
        if ( $declarations === [] ) {
            return;
        }

        $rules = [];
        foreach ( $declarations as $property => $value ) {
            $rules[] = $property . ': ' . $value . ';';
        }

        self::write( $attrs, 'css.desktop.value.mainElement', implode( ' ', $rules ) );

        $notes[] = [
            'kind'   => 'custom_css_carried',
            'detail' => implode( ', ', array_keys( $declarations ) ),
        ];
    }

    // -------------------------------------------------------------------------
    // Flat attributes
    // -------------------------------------------------------------------------

    /**
     * `el_id` / `el_class` → `module.advanced.htmlAttributes.desktop.value.{id,class}`
     * (`Module/Options/IdClasses/IdClassesPresetAttrsMap.php` sub-names `id`
     * and `class`; the attribute name itself is read back in
     * `IdClassesClassnames.php` and appears as `module.advanced.htmlAttributes`
     * in every module's preset-attribute map).
     */
    private function mapHtmlAttributes( array $atts, array &$attrs ): void {
        $id    = is_string( $atts['el_id'] ?? null ) ? trim( $atts['el_id'] ) : '';
        $class = is_string( $atts['el_class'] ?? null ) ? trim( $atts['el_class'] ) : '';

        if ( $id !== '' ) {
            self::write( $attrs, 'module.advanced.htmlAttributes.desktop.value.id', $id );
        }
        if ( $class !== '' ) {
            self::write( $attrs, 'module.advanced.htmlAttributes.desktop.value.class', $class );
        }
    }

    /**
     * Divi's own animation vocabulary does not line up with WPBakery's
     * `css_animation` list (`vc_animation_style` param), so the animation is
     * reported rather than approximated.
     */
    private function mapAnimation( array $atts, array &$notes ): void {
        $animation = $atts['css_animation'] ?? '';
        if ( ! is_string( $animation ) ) {
            return;
        }

        $animation = trim( $animation );
        if ( $animation === '' || strtolower( $animation ) === 'none' ) {
            return;
        }

        $notes[] = [ 'kind' => 'animation', 'detail' => 'css_animation=' . $animation ];
    }

    /**
     * `disable_element` renders nothing at all in WPBakery. The content is kept
     * and hidden on every breakpoint so nothing is lost silently:
     * `module.decoration.disabledOn.{desktop,tablet,phone}.value = 'on'`
     * (`Module/Options/DisabledOn/DisabledOnPresetAttrsMap.php`, a bare
     * attribute with no sub-name; consumed by `DisabledOn/DisabledOnStyle.php`).
     */
    private function mapVisibility( array $atts, array &$attrs, array &$notes ): void {
        if ( ! PackedParams::isOn( $atts['disable_element'] ?? '' ) ) {
            return;
        }

        foreach ( [ 'desktop', 'tablet', 'phone' ] as $breakpoint ) {
            self::write( $attrs, "module.decoration.disabledOn.{$breakpoint}.value", 'on' );
        }

        $notes[] = [ 'kind' => 'visibility', 'detail' => 'disable_element: kept but hidden on every breakpoint' ];
    }

    // -------------------------------------------------------------------------
    // Value helpers
    // -------------------------------------------------------------------------

    /**
     * The four sides of a box property: the shorthand (`margin: 10px 20px`)
     * first, then the per-side declarations that override it. Consumed
     * declarations are removed from $declarations.
     *
     * @param array<string,string> $declarations
     * @return array<string,string>
     */
    private function boxSides( string $prop, array &$declarations, string $suffix = '' ): array {
        $sides = [];

        if ( $suffix === '' && isset( $declarations[ $prop ] ) ) {
            $parts = $this->splitValues( $declarations[ $prop ] );
            $count = count( $parts );
            if ( $count >= 1 && $count <= 4 ) {
                $expanded = [
                    'top'    => $parts[0],
                    'right'  => $parts[1] ?? $parts[0],
                    'bottom' => $parts[2] ?? $parts[0],
                    'left'   => $parts[3] ?? ( $parts[1] ?? $parts[0] ),
                ];
                $usable = true;
                foreach ( $expanded as $side => $raw ) {
                    $value = $this->length( $raw );
                    if ( $value === '' ) {
                        $usable = false;
                        break;
                    }
                    $sides[ $side ] = $value;
                }
                if ( $usable ) {
                    unset( $declarations[ $prop ] );
                } else {
                    $sides = [];
                }
            }
        }

        foreach ( self::SIDES as $side ) {
            $property = $prop . '-' . $side . $suffix;
            if ( ! isset( $declarations[ $property ] ) ) {
                continue;
            }
            $value = $this->length( $declarations[ $property ] );
            if ( $value === '' ) {
                continue;
            }
            unset( $declarations[ $property ] );
            $sides[ $side ] = $value;
        }

        return $sides;
    }

    /** `border: 1px solid #000` → its three parts, or null when it is not one. */
    private function borderShorthand( string $raw ): ?array {
        $parts = $this->splitValues( $raw );
        if ( $parts === [] || count( $parts ) > 3 ) {
            return null;
        }

        $out = [ 'width' => '', 'style' => '', 'color' => '' ];
        foreach ( $parts as $part ) {
            $lower = strtolower( $part );
            if ( in_array( $lower, self::BORDER_STYLES, true ) ) {
                $out['style'] = $lower;
                continue;
            }
            $color = Color::normalize( $part );
            if ( $color !== null ) {
                $out['color'] = $color;
                continue;
            }
            $length = $this->length( $part );
            if ( $length !== '' ) {
                $out['width'] = $length;
                continue;
            }

            return null;
        }

        return $out;
    }

    /**
     * `border-color` / `border-style` and their four per-side forms, as Divi's
     * `styles.{all|side}.{aspect}` keys. Consumed declarations are removed.
     *
     * @param array<string,string> $declarations
     * @return array<string,string>
     */
    private function borderAspects( string $aspect, array &$declarations ): array {
        $found = [];

        foreach ( array_merge( [ 'all' ], self::SIDES ) as $side ) {
            $property = $side === 'all' ? "border-{$aspect}" : "border-{$side}-{$aspect}";
            if ( ! isset( $declarations[ $property ] ) ) {
                continue;
            }
            $value = $this->borderAspect( $aspect, $declarations[ $property ] );
            if ( $value === '' ) {
                continue;
            }
            unset( $declarations[ $property ] );
            $found[ $side ] = $value;
        }

        return $found;
    }

    /** One `border-*-color` / `border-*-style` value Divi will accept, or ''. */
    private function borderAspect( string $aspect, string $raw ): string {
        if ( $aspect === 'color' ) {
            return Color::normalize( $raw ) ?? '';
        }

        $style = strtolower( trim( $raw ) );

        return in_array( $style, self::BORDER_STYLES, true ) ? $style : '';
    }

    /**
     * A CSS length Divi can store. A bare number becomes `px` (WPBakery's unit
     * selector defaults to it), and a bare `0` becomes `0px` — Divi tests
     * layout values for truthiness, so `"0"` reads as "unset".
     */
    private function length( string $raw ): string {
        $value = trim( $raw );
        if ( $value === '' ) {
            return '';
        }

        if ( is_numeric( $value ) ) {
            return $value . 'px';
        }
        if ( preg_match( '/^-?(?:\d+|\d*\.\d+)(?:px|%|em|rem|ex|pt|pc|in|cm|mm|ch|vw|vh|vmin|vmax)$/i', $value ) ) {
            return $value;
        }
        if ( in_array( strtolower( $value ), [ 'auto', 'none', 'inherit', 'initial', 'unset' ], true ) ) {
            return strtolower( $value );
        }
        if ( preg_match( '/^(?:calc|var|min|max|clamp)\(/i', $value ) ) {
            return $value;
        }

        return '';
    }

    /**
     * A shorthand value split on whitespace, keeping `calc(…)`/`var(…)` whole.
     *
     * @return string[]
     */
    private function splitValues( string $raw ): array {
        $parts = [];
        $depth = 0;
        $token = '';

        foreach ( str_split( trim( $raw ) ) as $character ) {
            if ( $character === '(' ) {
                $depth++;
            } elseif ( $character === ')' ) {
                $depth = max( 0, $depth - 1 );
            }

            if ( $depth === 0 && preg_match( '/\s/', $character ) ) {
                if ( $token !== '' ) {
                    $parts[] = $token;
                    $token   = '';
                }
                continue;
            }

            $token .= $character;
        }

        if ( $token !== '' ) {
            $parts[] = $token;
        }

        return $parts;
    }

    /**
     * A comma-separated argument list split at the top level only, so
     * `rgba(0,0,0,.5)` survives.
     *
     * @return string[]
     */
    private function splitTopLevel( string $raw ): array {
        $parts = [];
        $depth = 0;
        $token = '';

        foreach ( str_split( $raw ) as $character ) {
            if ( $character === '(' ) {
                $depth++;
            } elseif ( $character === ')' ) {
                $depth = max( 0, $depth - 1 );
            }

            if ( $character === ',' && $depth === 0 ) {
                $parts[] = trim( $token );
                $token   = '';
                continue;
            }

            $token .= $character;
        }

        if ( trim( $token ) !== '' ) {
            $parts[] = trim( $token );
        }

        return $parts;
    }
}
