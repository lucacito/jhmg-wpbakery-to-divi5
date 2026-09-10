<?php

namespace WPBakeryDivi5Converter\Converter\Handlers;

use WPBakeryDivi5Converter\Converter\BaseWPBakeryConverter;
use WPBakeryDivi5Converter\Helpers\Autop;
use WPBakeryDivi5Converter\Helpers\Color;
use WPBakeryDivi5Converter\Helpers\DynamicContent;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `vc_single_image` → `divi/image`, with the caption and the widget title as
 * their own blocks around it.
 *
 * Everything below is `include/templates/shortcodes/vc_single_image.php`
 * (js_composer 9.0.1) read line by line: the three sources, the `onclick`
 * back-compatibility, the border colour that is a background for `vc_box_border`
 * and a border for `vc_box_outline`, the caption that comes from the attachment
 * for a library image and from the `caption` attribute for an external one, and
 * the `title` that WPBakery prints above the figure as an `h2.wpb_heading`.
 *
 * Two Divi facts drive the attribute paths (amendment §1, measured in Task 2):
 * `divi/image` reads its spacing and sizing from `module.advanced.*`
 * (`ImageModule.php`, style groups `divi/image-spacing` / `divi/image-sizing`)
 * and its border from `image.decoration.border` (`image/module.json`), and it
 * renders the `<img>` at its natural size unless
 * `module.advanced.sizing.desktop.value.forceFullwidth` is `on`. WPBakery always
 * renders at the size the attributes declare, so a declared pixel width is
 * written together with `forceFullwidth`.
 */
class SingleImageConverter extends BaseWPBakeryConverter {

    /**
     * `VcSharedLibrary::$box_styles` + `$round_box_styles` + `$circle_box_styles`
     * (include/classes/core/class-vc-shared-library.php) as
     * `assets/css/js_composer.min.css` draws them:
     *
     *   radius   border-radius on the wrapper and the img
     *   pad      the 6px frame `.vc_box_border` / `.vc_box_outline` put around it
     *   border   'outline' → 1px solid border_color, 'fill' → background_color, '' → none
     *   shadow   `0 0 5px rgba(0,0,0,.1)`
     *
     * `_circle_2` is the same look as `_circle`; the difference is that the
     * template squares the source image first (`getImageSquareSize()`), which is
     * a media operation, not a style, so it is reported instead.
     */
    const STYLES = [
        ''                            => [],
        'vc_box_rounded'              => [ 'radius' => '4px' ],
        'vc_box_border'               => [ 'pad' => '6px', 'border' => 'fill' ],
        'vc_box_outline'              => [ 'pad' => '6px', 'border' => 'outline' ],
        'vc_box_shadow'               => [ 'shadow' => true ],
        'vc_box_shadow_border'        => [ 'pad' => '6px', 'shadow' => true ],
        'vc_box_shadow_3d'            => [ 'shadow3d' => true ],
        'vc_box_circle'               => [ 'radius' => '50%' ],
        'vc_box_border_circle'        => [ 'radius' => '50%', 'pad' => '6px', 'border' => 'fill' ],
        'vc_box_outline_circle'       => [ 'radius' => '50%', 'pad' => '6px', 'border' => 'outline' ],
        'vc_box_shadow_circle'        => [ 'radius' => '50%', 'shadow' => true ],
        'vc_box_shadow_border_circle' => [ 'radius' => '50%', 'pad' => '6px', 'shadow' => true ],
    ];

    /** The colour `.vc_box_border` / `.vc_box_outline` fall back to (template line 71). */
    const DEFAULT_BORDER_COLOR = '#ebebeb';

    /** `0 0 5px rgba(0,0,0,.1)`, the shadow every `vc_box_shadow*` style paints. */
    const SHADOW = [
        'style'      => 'preset1',
        'horizontal' => '0px',
        'vertical'   => '0px',
        'blur'       => '5px',
        'spread'     => '0px',
        'position'   => 'outer',
        'color'      => 'rgba(0,0,0,0.1)',
    ];

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_image_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        $style    = $this->mapStyle( 'image', $node );
        $attrs    = $style['divi_attrs'];
        $consumed = array_merge( $style['handled_keys'], [ 'source' ] );

        $source = $this->att( $atts, 'source', 'media_library' );

        // `if ( 'external_link' === $source ) { $style = $external_style; … }`
        $box_style    = $source === 'external_link' ? $this->att( $atts, 'external_style' ) : $this->att( $atts, 'style' );
        $border_color = $source === 'external_link' ? 'external_border_color' : 'border_color';
        $consumed[]   = $source === 'external_link' ? 'external_style' : 'style';

        $src = $this->source( $source, $atts, $id, $attrs, $consumed );

        $this->boxStyle( $box_style, $atts, $border_color, $id, $attrs, $consumed );
        $this->sizing( $source, $atts, $attrs, $consumed );
        $this->alignment( $atts, $attrs, $consumed );
        $this->click( $source, $atts, $src, $id, $attrs, $consumed );

        StyleMapper::write( $attrs, 'image.innerContent.desktop.value.src', $src );

        $alt = $this->attachmentField( $atts, $source, 'alt' );
        if ( $alt !== '' ) {
            StyleMapper::write( $attrs, 'image.innerContent.desktop.value.alt', $alt );
        }

        $this->engine->logConverted( 'image' );

        $blocks = [];

        $title = $this->titleBlock( $node );
        if ( $title !== null ) {
            $consumed[] = 'title';
            $this->engine->logConverted( 'heading' );
            $blocks[] = $title;
        }

        $blocks[] = $this->block( $id, 'divi/image', $attrs );

        $caption = $this->caption( $source, $atts, $consumed );
        if ( $caption !== '' ) {
            $this->engine->logConverted( 'text' );
            $blocks[] = $this->block( $id . '-caption', 'divi/text', [
                'content' => [ 'innerContent' => [ 'desktop' => [ 'value' => trim( Autop::apply( $caption ) ) ] ] ],
            ] );
        }

        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        return count( $blocks ) === 1 ? $blocks[0] : $blocks;
    }

    // -------------------------------------------------------------------------
    // Where the picture comes from
    // -------------------------------------------------------------------------

    /**
     * The three `source` values the template switches on. A library attachment
     * with no URL — not on this site, not in the export's map — is reported by
     * `attachmentUrl()` and leaves `src` empty rather than inventing a path.
     *
     * @param string[] $consumed
     */
    private function source( string $source, array $atts, string $id, array &$attrs, array &$consumed ): string {
        if ( $source === 'external_link' ) {
            $consumed[] = 'custom_src';

            return $this->att( $atts, 'custom_src' );
        }

        // `img_size` is consumed here, before the `featured_image` return: `sizing()`
        // reads it for every source but `external_link` and applies it (width +
        // `forceFullwidth`) regardless of where the picture comes from, so marking
        // it consumed only in the branch below left a false `skipped_settings`
        // entry on a featured-image source whose size was in fact applied.
        $consumed[] = 'img_size';

        if ( $source === 'featured_image' ) {
            return DynamicContent::token( 'post_featured_image' );
        }

        $consumed[] = 'image';

        $attachment = $this->attachmentId( $atts );
        if ( $attachment <= 0 ) {
            $this->engine->logWarning( "vc_single_image {$id} has no image to show; the module was written with an empty source." );

            return '';
        }

        $size = strtolower( $this->att( $atts, 'img_size', 'medium' ) );
        // A `WxH` size is a crop WPBakery generates on the fly; the attachment
        // itself only answers for a named size.
        $url = $this->attachmentUrl( $attachment, $this->isPixelSize( $size ) ? 'full' : $size, $id );

        if ( $url === null ) {
            $this->engine->logWarning( "vc_single_image {$id}: attachment {$attachment} is not on this site and was not in the import; the module was written with an empty source." );

            return '';
        }

        StyleMapper::write( $attrs, 'image.innerContent.desktop.value.id', (string) $attachment );

        return $url;
    }

    /** `$img_id = preg_replace( '/[^\d]/', '', $image )`, plus the 9.0 JSON form. */
    private function attachmentId( array $atts ): int {
        $raw = $this->att( $atts, 'image' );

        // 9.0 stores `{"123":{"title":"…"}}` when the picker recorded a link.
        $decoded = json_decode( $raw, true );
        if ( is_array( $decoded ) && $decoded !== [] ) {
            $raw = (string) array_key_first( $decoded );
        }

        return (int) preg_replace( '/[^\d]/', '', $raw );
    }

    /** "400x300" — `vc_extract_dimensions()`'s form — rather than "large". */
    private function isPixelSize( string $size ): bool {
        return preg_match( '/^\s*\d+\s*[x×]\s*\d+\s*$/i', $size ) === 1 || preg_match( '/^\d+$/', trim( $size ) ) === 1;
    }

    /** A field of the attachment: the import's map first, then this site's library. */
    private function attachmentField( array $atts, string $source, string $field ): string {
        if ( $source !== 'media_library' ) {
            return '';
        }

        $id = $this->attachmentId( $atts );
        if ( $id <= 0 ) {
            return '';
        }

        $mapped = $this->engine->options()['attachments'][ $id ] ?? null;
        if ( is_array( $mapped ) && is_string( $mapped[ $field ] ?? null ) && $mapped[ $field ] !== '' ) {
            return $mapped[ $field ];
        }

        if ( $this->engine->options()['mode'] === 'import' ) {
            return '';
        }

        if ( $field === 'caption' && function_exists( 'wp_get_attachment_caption' ) ) {
            return (string) wp_get_attachment_caption( $id );
        }
        if ( $field === 'alt' && function_exists( 'get_post_meta' ) ) {
            return (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
        }

        return '';
    }

    /**
     * `add_caption`, exactly as the template resolves it: a library or featured
     * image takes the attachment's own caption, an external one is always
     * captioned and takes the `caption` attribute.
     *
     * @param string[] $consumed
     */
    private function caption( string $source, array $atts, array &$consumed ): string {
        $consumed[] = 'add_caption';
        $consumed[] = 'caption';

        if ( $source === 'external_link' ) {
            return $this->att( $atts, 'caption' );
        }

        if ( ! $this->on( $atts, 'add_caption' ) ) {
            return '';
        }

        return $this->attachmentField( $atts, $source, 'caption' );
    }

    // -------------------------------------------------------------------------
    // How it looks
    // -------------------------------------------------------------------------

    /**
     * The `style` dropdown, through `self::STYLES`. The border colour is the
     * template's: blank means `#EBEBEB`, a hex/rgb value paints `border-color`
     * for an outline style and `background-color` for a border style, and a
     * palette name becomes the `vc_box_border_<name>` class, whose CSS does the
     * same thing with the palette hex.
     *
     * @param string[] $consumed
     */
    private function boxStyle( string $style, array $atts, string $color_key, string $id, array &$attrs, array &$consumed ): void {
        $consumed[] = $color_key;

        if ( $style === '' ) {
            return;
        }

        // `preg_match( '/_circle_2$/', $style )` — same style, squared source.
        $squared = preg_match( '/_circle_2$/', $style ) === 1;
        if ( $squared ) {
            $style = (string) preg_replace( '/_circle_2$/', '_circle', $style );
            $this->engine->logNotCarriedOver(
                'layout',
                $id,
                'image style ' . $style . '_2 squares the source image before cropping it; Divi shows the picture at its own aspect ratio'
            );
        }

        $spec = self::STYLES[ $style ] ?? null;
        if ( $spec === null ) {
            $this->engine->logNotCarriedOver( 'layout', $id, "image style {$style} has no Divi equivalent" );

            return;
        }

        if ( isset( $spec['radius'] ) ) {
            StyleMapper::write( $attrs, 'image.decoration.border.desktop.value.radius', self::radius( $spec['radius'] ) );
        }

        // WPBakery writes a `css` design-option padding `!important`, so it wins
        // over the style's own 6px frame rather than the handler silently
        // replacing it (the same guard `MessageConverter::shape()` uses).
        if ( isset( $spec['pad'] ) && $this->read( $attrs, 'module.advanced.spacing.desktop.value.padding' ) === null ) {
            StyleMapper::write(
                $attrs,
                'module.advanced.spacing.desktop.value.padding',
                self::box( $spec['pad'], $spec['pad'], $spec['pad'], $spec['pad'] )
            );
        }

        if ( ! empty( $spec['shadow'] ) ) {
            StyleMapper::write( $attrs, 'image.decoration.boxShadow.desktop.value', self::SHADOW );
        }

        if ( ! empty( $spec['shadow3d'] ) ) {
            $this->engine->logNotCarriedOver(
                'layout',
                $id,
                'image style vc_box_shadow_3d is drawn with two skewed pseudo-elements; Divi has no equivalent and the image is left flat'
            );
        }

        if ( isset( $spec['border'] ) ) {
            $this->borderColour( $spec['border'], $this->att( $atts, $color_key, self::DEFAULT_BORDER_COLOR ), $color_key, $id, $attrs );
        }
    }

    private function borderColour( string $kind, string $raw, string $color_key, string $id, array &$attrs ): void {
        $color = Color::normalize( $raw );
        if ( $color === null ) {
            $this->engine->logUnresolvedGlobal( $id, $color_key, trim( $raw ) );

            return;
        }

        if ( $kind === 'outline' ) {
            StyleMapper::write( $attrs, 'image.decoration.border.desktop.value.styles.all', [
                'width' => '1px',
                'style' => 'solid',
                'color' => $color,
            ] );

            return;
        }

        // `.vc_box_border` paints the frame with a background colour behind the
        // 6px padding, not with a border.
        StyleMapper::write( $attrs, 'module.decoration.background.desktop.value.color', $color );
    }

    /**
     * Amendment §1: a declared pixel width is written on `module.advanced.sizing`
     * **and** switches `forceFullwidth` on, because Divi renders the `<img>` at
     * its natural size otherwise and WPBakery renders it at the declared one. A
     * named size carries its own dimensions and needs neither.
     *
     * @param string[] $consumed
     */
    private function sizing( string $source, array $atts, array &$attrs, array &$consumed ): void {
        $size = $source === 'external_link' ? $this->att( $atts, 'external_img_size' ) : $this->att( $atts, 'img_size' );
        if ( $source === 'external_link' ) {
            $consumed[] = 'external_img_size';
        }

        if ( preg_match( '/^\s*(\d+)\s*[x×]\s*\d+\s*$/i', $size, $m ) !== 1 && preg_match( '/^\s*(\d+)\s*$/', $size, $m ) !== 1 ) {
            return;
        }

        $width = $this->read( $attrs, 'module.advanced.sizing.desktop.value.width' );
        if ( is_string( $width ) && $width !== '' ) {
            // The element's own design options set a width; WPBakery writes
            // those `!important`, so they win over the declared size.
            return;
        }

        StyleMapper::write( $attrs, 'module.advanced.sizing.desktop.value.width', $m[1] . 'px' );
        StyleMapper::write( $attrs, 'module.advanced.sizing.desktop.value.forceFullwidth', 'on' );
    }

    /**
     * `vc_align_<alignment>` on the wrapper. `divi/image` has its own alignment
     * field (`module.advanced.align`, `image/module.json`).
     *
     * @param string[] $consumed
     */
    private function alignment( array $atts, array &$attrs, array &$consumed ): void {
        $consumed[] = 'alignment';

        $align = strtolower( $this->att( $atts, 'alignment' ) );
        if ( in_array( $align, [ 'left', 'center', 'right' ], true ) ) {
            StyleMapper::write( $attrs, 'module.advanced.align.desktop.value', $align );
        }
    }

    /**
     * The `onclick` switch, with the template's back-compatibility in front of
     * it: `img_link_large="yes"` and no `onclick` means "link to the large
     * image", and anything else with no `onclick` means "the custom link".
     *
     * @param string[] $consumed
     */
    private function click( string $source, array $atts, string $src, string $id, array &$attrs, array &$consumed ): void {
        $consumed[] = 'onclick';
        $consumed[] = 'img_link_large';
        $consumed[] = 'link';
        $consumed[] = 'img_link_target';

        $onclick = $this->att( $atts, 'onclick' );
        if ( $onclick === '' ) {
            $onclick = $this->on( $atts, 'img_link_large' ) ? 'img_link_large' : 'custom_link';
        }

        if ( $onclick === 'link_image' || $onclick === 'zoom' ) {
            StyleMapper::write( $attrs, 'image.advanced.lightbox.desktop.value', 'on' );

            if ( $onclick === 'zoom' ) {
                $this->engine->logNotCarriedOver(
                    'interaction',
                    $id,
                    'vc_single_image onclick="zoom" magnifies the picture under the cursor; Divi opens it in a lightbox instead'
                );
            }

            return;
        }

        if ( $onclick === 'img_link_large' ) {
            $large = $source === 'external_link' ? $src : $this->largeUrl( $atts, $id, $src );
            if ( $large !== '' ) {
                StyleMapper::write( $attrs, 'image.innerContent.desktop.value.linkUrl', $large );
                StyleMapper::write( $attrs, 'image.innerContent.desktop.value.linkTarget', 'off' );
            }

            return;
        }

        $link = $this->link( $atts, 'link' );
        if ( $link === [] ) {
            return;
        }

        $this->reportLinkExtras( $atts, 'link', $id, true );

        $target = ( $link['target'] ?? '' ) === '_blank' || $this->att( $atts, 'img_link_target' ) === '_blank';

        StyleMapper::write( $attrs, 'image.innerContent.desktop.value.linkUrl', $link['url'] );
        StyleMapper::write( $attrs, 'image.innerContent.desktop.value.linkTarget', $target ? 'on' : 'off' );

        if ( ! empty( $link['rel'] ) ) {
            StyleMapper::write( $attrs, 'image.innerContent.desktop.value.rel', $link['rel'] );
        }
    }

    /** `wp_get_attachment_image_src( $img_id, 'large' )`, or the source we already have. */
    private function largeUrl( array $atts, string $id, string $fallback ): string {
        $attachment = $this->attachmentId( $atts );
        if ( $attachment <= 0 ) {
            return $fallback;
        }

        return (string) ( $this->attachmentUrl( $attachment, 'large', $id ) ?? $fallback );
    }
}
