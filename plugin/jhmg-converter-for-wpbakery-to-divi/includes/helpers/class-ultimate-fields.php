<?php

namespace WPBakeryDivi5Converter\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The three packed field formats every Ultimate Addons element uses, read from
 * `references/Ultimate_VC_Addons.zip` `modules/*.php` (3.19.3, extracted; see
 * task-9-amendments.md §8).
 *
 * They are the add-on's own shapes, not WPBakery's, which is why they live
 * here rather than in `PackedParams`:
 *
 * - **A media field** (`icon_img`, `custom_thumb`, `bg_image`) is
 *   `id^4282|url^https://…|caption^null|alt^null|title^…|description^null`
 *   — `key^value` pairs joined with `|`, and the string `null` where the
 *   attachment has no value. Written by the add-on's `ult_img_single` param.
 * - **A responsive number** (`title_font_size`, `desc_font_line_height`,
 *   `play_size`) is `desktop:20px;tablet:18px;mobile:16px;` — only the desktop
 *   band has a Divi equivalent that is not a guess about breakpoints, so that
 *   is the one this reads.
 * - **An icon** is the Font Icon Manager's stored class, `Defaults-database`
 *   or `Defaults-edit pencil-square-o`: the icon set, a dash, and the icon's
 *   own name, which for the `Defaults` set is a Font Awesome 4 name.
 */
final class UltimateFields {

    /**
     * One `ult_img_single` value as `key => value`, with the add-on's literal
     * `null` treated as absent.
     *
     * @return array<string,string>
     */
    public static function media( string $raw ): array {
        $parts = [];

        foreach ( explode( '|', trim( $raw ) ) as $pair ) {
            if ( ! str_contains( $pair, '^' ) ) {
                continue;
            }
            [ $key, $value ] = explode( '^', $pair, 2 );
            $key             = trim( $key );
            $value           = trim( $value );

            if ( $key !== '' && $value !== '' && $value !== 'null' ) {
                $parts[ $key ] = $value;
            }
        }

        return $parts;
    }

    /** The `desktop:` band of a responsive number, or `''`. */
    public static function responsive( string $raw ): string {
        $raw = trim( $raw );

        if ( $raw === '' ) {
            return '';
        }

        foreach ( explode( ';', $raw ) as $band ) {
            [ $name, $value ] = array_pad( explode( ':', $band, 2 ), 2, '' );
            if ( trim( $name ) === 'desktop' ) {
                return trim( $value );
            }
        }

        // A bare number with no bands at all (`icon_size="45"`).
        return str_contains( $raw, ':' ) ? '' : $raw;
    }

    /**
     * The Font Icon Manager class as a Font Awesome class `IconMap` can read.
     *
     * The stored value is `<set>-<name>`, and the `Defaults` set is Font
     * Awesome 4 — so `Defaults-database` is `fa-database`. A value with a space
     * in it carries the icon's own name last (`Defaults-edit pencil-square-o`
     * is the `pencil-square-o` glyph).
     */
    public static function iconClass( string $raw ): string {
        $raw = trim( $raw );

        if ( $raw === '' || $raw === 'none' ) {
            return '';
        }

        $tokens = preg_split( '/\s+/', $raw ) ?: [];
        $name   = (string) end( $tokens );

        // Strip the icon set (`Defaults-`, `Flaticon-`, …), which the manager
        // always writes capitalised, from the token that still carries one; an
        // icon name of its own (`pencil-square-o`) is lower case and is left
        // alone.
        if ( preg_match( '/^[A-Z][A-Za-z0-9]*-(.+)$/', $name, $m ) === 1 ) {
            $name = $m[1];
        }

        return $name === '' ? '' : 'fa fa-' . $name;
    }
}
