<?php

namespace WPBakeryDivi5Converter\Converter\Ronneby;

use WPBakeryDivi5Converter\Helpers\DynamicContent;
use WPBakeryDivi5Converter\Helpers\PackedParams;
use WPBakeryDivi5Converter\Helpers\RonnebyParams;
use WPBakeryDivi5Converter\StyleMapper\StyleMapper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `dfd_single_image` → `divi/image`.
 *
 * `modules/dfd_image_module.php:235-380` is one `<img>` in one wrapper: the
 * source comes from the media library, an external URL or the post thumbnail
 * (`image_type`), the size from `image_size` — with `custom` running the file
 * through the theme's own `dfd_aq_resize()` — the corner radius from
 * `image_border_radius`, and the link from `enable_link` + `link_object`, which
 * is a lightbox, a URL or the one-page scroll navigation.
 *
 * Divi's image module holds all of that except the one-page navigation:
 * `image.innerContent` (src, id, alt, link), `image.advanced.lightbox`,
 * `module.advanced.align`, `module.advanced.sizing` and
 * `image.decoration.border` (`image/module.json`, and the `module.advanced.*`
 * spacing/sizing finding recorded in `docs/box-model.md`).
 *
 * The corpus carries three attribute names Ronneby Core 1.5.74 no longer maps —
 * `upload_image`, `img_rounded` and `image_type`'s absence — from the build the
 * demo content was authored with; the first two are read as the fields they
 * replaced, which is why this handler is registered approximate.
 *
 * **No default bottom margin**: `.dfd-single-image-module` carries none
 * (`dfd-ronneby/assets/css/visual-composer.css` has one rule for it,
 * `.dfd-single-image-module.image-center img{margin:0 auto}`).
 */
class DfdSingleImageConverter extends RonnebyConverter {

    /** `image_alignment` (`modules/dfd_image_module.php:126-136`) ⇒ `module.advanced.align`. */
    const ALIGNMENTS = [
        'image-left'   => 'left',
        'image-center' => 'center',
        'image-right'  => 'right',
    ];

    /** `hover_style` (lines 140-152): each is a CSS transition on `.dfd-single-image-module`. */
    const HOVER_STYLES = [
        'dfd-image-shadow'       => 'a shadow that fades in',
        'dfd-image-fade-in'      => 'the picture fading in',
        'dfd-image-fade-out'     => 'the picture fading out',
        'dfd-image-blur'         => 'the picture blurring',
        'dfd-image-scale'        => 'the picture growing',
        'dfd-image-scale-rotate' => 'the picture growing and rotating',
        'panr'                   => 'a parallax pan',
    ];

    public function convert( array $node ): array {
        $id   = (string) ( $node['id'] ?? uniqid( 'wbdc_dfd_image_' ) );
        $atts = is_array( $node['atts'] ?? null ) ? $node['atts'] : [];

        $style    = $this->mapStyle( 'image', $node, 'none' );
        $attrs    = $style['divi_attrs'];
        $consumed = array_merge( $style['handled_keys'], [ 'image_type', 'tutorials' ] );

        $src = $this->source( $atts, $id, $attrs, $consumed );

        $this->sizing( $atts, $id, $attrs, $consumed );
        $this->cornerRadius( $atts, $attrs, $consumed );
        $this->shadow( $atts, $id, $attrs, $consumed );
        $this->alignment( $atts, $attrs, $consumed );
        $this->clickTarget( $atts, $id, $attrs, $consumed );
        $this->retina( $atts, $id, $consumed );
        $this->hover( $atts, $id, $consumed );
        $this->reportAnimation( $atts, $id, $consumed );
        $this->reportGroup(
            $atts,
            [
                'mobile_alignment'     => 'a different alignment on small screens',
                'alignment_resolution' => 'the width that alignment applies below',
            ],
            'layout',
            $id,
            'dfd_single_image aligns itself differently on small screens',
            $consumed
        );

        StyleMapper::write( $attrs, 'image.innerContent.desktop.value.src', $src );

        $this->engine->logConverted( 'image' );
        $this->logUnmappedSettings( $id, $atts, $consumed, (string) ( $node['tag'] ?? '' ) );

        return $this->block( $id, 'divi/image', $attrs );
    }

    /**
     * The three `image_type` branches (`dfd_image_module.php:258-317`). The
     * corpus never writes `image_type` at all, and
     * `vc_map_get_attributes()` fills the param's `'value' => 'media_library'`,
     * so an absent one is the library.
     *
     * `upload_image` is the older build's name for `image`; a page that carries
     * both keeps `image`, which is what 1.5.74 reads.
     *
     * @param string[] $consumed
     */
    private function source( array $atts, string $id, array &$attrs, array &$consumed ): string {
        $type = strtolower( trim( $this->att( $atts, 'image_type', 'media_library' ) ) );

        if ( $type === 'external_link' ) {
            $consumed[] = 'external_link_url';

            return $this->att( $atts, 'external_link_url' );
        }

        if ( $type === 'featured_image' ) {
            return DynamicContent::token( 'post_featured_image' );
        }

        $consumed[] = 'image';
        $consumed[] = 'upload_image';

        $raw        = $this->att( $atts, 'image', $this->att( $atts, 'upload_image' ) );
        $attachment = (int) preg_replace( '/[^\d]/', '', $raw );

        if ( $attachment <= 0 ) {
            $this->engine->logWarning( "dfd_single_image {$id} has no image to show; the module was written with an empty source." );

            return '';
        }

        // `if(!isset($image_size) || empty($image_size)) { $image_size = 'full'; }`
        // and `if($image_size == 'custom') { $image_dimention = 'full'; }`.
        $size = strtolower( trim( $this->att( $atts, 'image_size', 'full' ) ) );
        $size = ( $size === '' || $size === 'custom' ) ? 'full' : $size;

        $url = $this->attachmentUrl( $attachment, $size, $id );
        if ( $url === null ) {
            $this->engine->logWarning( "dfd_single_image {$id}: attachment {$attachment} is not on this site and was not in the import; the module was written with an empty source." );

            return '';
        }

        StyleMapper::write( $attrs, 'image.innerContent.desktop.value.id', (string) $attachment );

        return $url;
    }

    /**
     * `image_size="custom"` crops the file to `image_width` × `image_height`
     * with `dfd_aq_resize()` (line 297). Divi renders the picture at its
     * natural size unless `forceFullwidth` is on, so a declared width is
     * written with it — the finding `SingleImageConverter::sizing()` records.
     *
     * @param string[] $consumed
     */
    private function sizing( array $atts, string $id, array &$attrs, array &$consumed ): void {
        $consumed = array_merge( $consumed, [ 'image_size', 'image_width', 'image_height' ] );

        if ( strtolower( trim( $this->att( $atts, 'image_size' ) ) ) !== 'custom' ) {
            return;
        }

        // `dfd_aq_resize( …, $image_width, $image_height, true, …)` crops the
        // file to both. Divi renders the picture at its own aspect ratio, so
        // the height is reported rather than forced on it.
        $height = $this->att( $atts, 'image_height', '550' );
        if ( trim( $height ) !== '' ) {
            $this->engine->logNotCarriedOver(
                'layout',
                $id,
                sprintf(
                    'image_size="custom" crops the picture to %s x %s; Divi renders it at its own aspect ratio, so only the width was applied',
                    $this->att( $atts, 'image_width', '600' ),
                    $height
                )
            );
        }

        // `if(!isset($image_width) || empty($image_width)) { $image_width = 600; }`
        $width = PackedParams::sizeWithUnit( $this->att( $atts, 'image_width', '600' ) );
        if ( $width === '' ) {
            return;
        }

        if ( is_string( $this->read( $attrs, 'module.advanced.sizing.desktop.value.width' ) ) ) {
            return;
        }

        StyleMapper::write( $attrs, 'module.advanced.sizing.desktop.value.width', $width );
        StyleMapper::write( $attrs, 'module.advanced.sizing.desktop.value.forceFullwidth', 'on' );
    }

    /**
     * `image_border_radius` — `#<id> {border-radius: <n>px;}` (line 350).
     * `img_rounded` is the older build's name for it.
     *
     * @param string[] $consumed
     */
    private function cornerRadius( array $atts, array &$attrs, array &$consumed ): void {
        $consumed[] = 'image_border_radius';
        $consumed[] = 'img_rounded';

        $radius = $this->pixels( $atts, 'image_border_radius' );
        if ( $radius === '' ) {
            $radius = $this->pixels( $atts, 'img_rounded' );
        }

        if ( $radius !== '' ) {
            StyleMapper::write( $attrs, 'image.decoration.border.desktop.value.radius', self::radius( $radius ) );
        }
    }

    /**
     * `box_shadow` / `hover_box_shadow`, from the build the demo content was
     * authored with: `Dfd_Box_Shadow_Param` values
     * (`params/param_box_shadow.php:66-82`). Divi's image module has the
     * resting shadow; the hover one is a state this converter reports rather
     * than invents, as `BtnConverter::interaction()` does.
     *
     * @param string[] $consumed
     */
    private function shadow( array $atts, string $id, array &$attrs, array &$consumed ): void {
        $consumed[] = 'box_shadow';
        $consumed[] = 'hover_box_shadow';

        $shadow = RonnebyParams::boxShadow( $this->att( $atts, 'box_shadow' ) );
        if ( $shadow !== null ) {
            StyleMapper::write( $attrs, 'image.decoration.boxShadow.desktop.value', $shadow );
        }

        if ( RonnebyParams::boxShadow( $this->att( $atts, 'hover_box_shadow' ) ) !== null ) {
            $this->engine->logNotCarriedOver(
                'hover',
                $id,
                'hover_box_shadow paints a second shadow while the cursor is over the picture; set it on the Divi image\'s hover state'
            );
        }
    }

    /** @param string[] $consumed */
    private function alignment( array $atts, array &$attrs, array &$consumed ): void {
        $consumed[] = 'image_alignment';

        // `if(!isset($image_alignment) || empty($image_alignment)) { $image_alignment = 'image-center'; }`
        $align = self::ALIGNMENTS[ strtolower( $this->att( $atts, 'image_alignment', 'image-center' ) ) ] ?? null;

        if ( $align !== null ) {
            StyleMapper::write( $attrs, 'module.advanced.align.desktop.value', $align );
        }
    }

    /**
     * `enable_link` + `link_object` (lines 328-345): the lightbox opens the
     * full-size file, `link` uses `image_ext_link_url`, and `onepage` is the
     * theme's own slider navigation, which Divi has nothing for.
     *
     * @param string[] $consumed
     */
    private function clickTarget( array $atts, string $id, array &$attrs, array &$consumed ): void {
        $consumed = array_merge( $consumed, [ 'enable_link', 'link_object', 'image_ext_link_url', 'onepage_navigate' ] );

        if ( ! $this->on( $atts, 'enable_link' ) ) {
            return;
        }

        // `'value' => 'lightbox'` (line 190).
        switch ( strtolower( trim( $this->att( $atts, 'link_object', 'lightbox' ) ) ) ) {
            case 'link':
                $url = trim( $this->att( $atts, 'image_ext_link_url' ) );
                if ( $url !== '' ) {
                    StyleMapper::write( $attrs, 'image.innerContent.desktop.value.linkUrl', $url );
                    StyleMapper::write( $attrs, 'image.innerContent.desktop.value.linkTarget', 'off' );
                }
                break;

            case 'onepage':
                $this->engine->logNotCarriedOver(
                    'interaction',
                    $id,
                    sprintf(
                        'link_object="onepage" makes the picture a one-page scroll control (%s); that navigation belongs to the theme\'s slider and Divi has no equivalent',
                        $this->att( $atts, 'onepage_navigate', 'slickPrev' )
                    )
                );
                break;

            default:
                StyleMapper::write( $attrs, 'image.advanced.lightbox.desktop.value', 'on' );
        }
    }

    /**
     * `enable_retina` + `retina_image_size` put a second file on
     * `data-retina-img` and the theme's script swaps it in on a 2× display
     * (lines 274-282). Divi's image module has one source.
     *
     * @param string[] $consumed
     */
    private function retina( array $atts, string $id, array &$consumed ): void {
        $consumed = array_merge( $consumed, [ 'enable_retina', 'retina_image_size', 'retina_image_x2', 'retina_image_x4' ] );

        if ( ! $this->on( $atts, 'enable_retina' ) ) {
            return;
        }

        $size = trim( $this->att( $atts, 'retina_image_size' ) );
        if ( $size === '' ) {
            return;
        }

        $this->engine->logNotCarriedOver(
            'layout',
            $id,
            sprintf(
                'enable_retina swaps in retina_image_%s (attachment %s) on a high-density display; Divi\'s image module has one source, and the standard one was kept',
                $size,
                $this->att( $atts, 'retina_image_' . $size, '—' )
            )
        );
    }

    /** @param string[] $consumed */
    private function hover( array $atts, string $id, array &$consumed ): void {
        $consumed[] = 'hover_style';

        $hover = trim( $this->att( $atts, 'hover_style' ) );
        if ( $hover === '' ) {
            return;
        }

        $this->engine->logNotCarriedOver(
            'hover',
            $id,
            sprintf(
                'hover_style="%s" animates %s while the cursor is over it; Divi\'s image module has no hover animation of its own',
                $hover,
                self::HOVER_STYLES[ $hover ] ?? 'the picture'
            )
        );
    }
}
