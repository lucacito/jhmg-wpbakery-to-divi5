<?php

declare(strict_types=1);

namespace Divi5Validator;

/**
 * Schema constants derived empirically from Divi 5.8.0 exports.
 * See docs/SCHEMA.md for the full observations.
 */
class SchemaRules
{
    // Structural blocks — have children, appear as open/close pairs
    public const STRUCTURAL_BLOCKS = [
        'divi/placeholder',
        'divi/section',
        'divi/row',
        'divi/column',
        // Compound modules — structural but live inside columns
        'divi/accordion',
        'divi/contact-form',
        'divi/counters',
        'divi/icon-list',
        'divi/pricing-tables',
        'divi/slider',
        'divi/tabs',
        'divi/social-media-follow',
        // Flex containers (confirmed on real site exports)
        'divi/group',
        'divi/group-carousel',
        'divi/timeline',
        // Nested (inner) row/column — Divi 5's specialty nesting blocks
        'divi/row-inner',
        'divi/column-inner',
    ];

    // Leaf modules — self-closing, carry actual content
    public const LEAF_MODULES = [
        // Basic
        'divi/heading',
        'divi/text',
        'divi/image',
        'divi/button',
        'divi/shortcode-module',
        'divi/divider',
        // Media
        'divi/audio',
        'divi/video',
        'divi/before-after-image',
        // Content
        'divi/blurb',
        'divi/cta',
        'divi/icon',
        'divi/team-member',
        'divi/toggle',
        'divi/search',
        'divi/blog',
        'divi/breadcrumbs',
        'divi/map',
        'divi/signup',
        'divi/canvas-portal',
        // Counters / timers
        'divi/circle-counter',
        'divi/countdown-timer',
        'divi/number-counter',
        // Confirmed on real site exports
        'divi/code',
        'divi/sidebar',
        'divi/testimonial',
        // Module-coverage export (newpage)
        'divi/fullwidth-header',
        'divi/gallery',
        'divi/login',
        'divi/instagram-feed',
        'divi/menu',
        'divi/timeline-item',
        'divi/post-nav',
        // WooCommerce — the products grid (Shop module). Leaf; valid only inside a column.
        'divi/shop',
        // Compound module children (self-closing items inside structural parents)
        'divi/accordion-item',
        'divi/contact-field',
        'divi/counter',
        'divi/icon-list-item',
        'divi/pricing-table',
        'divi/slide',
        'divi/tab',
        'divi/social-media-follow-network',
    ];

    // Types that appear in exports but carry no children and no builderVersion;
    // they belong to neither the structural nor leaf categories.
    //   divi/layout        — layout reference
    //   divi/global-layout — Theme Builder global reference (globalModule/localAttrs)
    private const EXTRA_TYPES = ['divi/layout', 'divi/global-layout'];

    // Valid direct children for each structural block type
    public const ALLOWED_CHILDREN = [
        'divi/placeholder'    => ['divi/section', 'divi/global-layout'],
        // A section usually holds rows, but real exports also place a column
        // directly in a section (layout-4), or a divi/global-layout Theme Builder
        // reference inside a section (Site B).
        'divi/section'        => ['divi/row', 'divi/column', 'divi/global-layout'],
        'divi/row'            => ['divi/column'],
        'divi/column'         => [
            // Basic leaf modules
            'divi/heading',
            'divi/text',
            'divi/image',
            'divi/button',
            'divi/shortcode-module',
            'divi/divider',
            // Media
            'divi/audio',
            'divi/video',
            'divi/before-after-image',
            // Content
            'divi/blurb',
            'divi/cta',
            'divi/icon',
            'divi/team-member',
            'divi/toggle',
            'divi/search',
            'divi/blog',
            'divi/breadcrumbs',
            'divi/map',
            'divi/signup',
            'divi/canvas-portal',
            // Counters / timers
            'divi/circle-counter',
            'divi/countdown-timer',
            'divi/number-counter',
            // Compound structural modules (live inside columns)
            'divi/accordion',
            'divi/contact-form',
            'divi/counters',
            'divi/icon-list',
            'divi/pricing-tables',
            'divi/slider',
            'divi/tabs',
            'divi/social-media-follow',
            // Flex containers + content modules (confirmed on real site exports)
            'divi/group',
            'divi/group-carousel',
            'divi/code',
            'divi/sidebar',
            'divi/testimonial',
            'divi/timeline',
            'divi/fullwidth-header',
            'divi/gallery',
            'divi/login',
            'divi/instagram-feed',
            'divi/menu',
            'divi/post-nav',
            // WooCommerce products grid
            'divi/shop',
            // Nested rows — Divi 5 lets a column contain a row (alongside modules),
            // recursing to arbitrary depth. Confirmed via real exports
            // (page-23: divi/row; layout-4: divi/row-inner).
            'divi/row',
            'divi/row-inner',
        ],
        // Compound module children
        'divi/accordion'      => ['divi/accordion-item', 'divi/code'],
        'divi/contact-form'   => ['divi/contact-field'],
        'divi/counters'       => ['divi/counter'],
        'divi/icon-list'      => ['divi/icon-list-item'],
        'divi/pricing-tables' => ['divi/pricing-table'],
        'divi/slider'         => ['divi/slide'],
        'divi/tabs'           => ['divi/tab'],
        'divi/social-media-follow' => ['divi/social-media-follow-network'],
        // Inner row holds inner columns; inner columns accept the same children
        // as top-level columns (see allowedChildrenOf).
        'divi/row-inner'      => ['divi/column-inner'],
        // Carousel of groups (confirmed on real site exports).
        'divi/group-carousel' => ['divi/group'],
        'divi/timeline'       => ['divi/timeline-item'],
    ];

    /**
     * Render-critical content field rules.
     *
     * Each entry: [ key, required, mustBeObject ]
     *   key           — top-level attr key whose innerContent.desktop.value is checked
     *   required      — true: missing key is a violation; false: only checked when present
     *   mustBeObject  — true: value must be a JSON object (scalar causes PHP fatal on render)
     *
     * @return array<string, list<array{string, bool, bool}>>
     */
    public function contentKeyRules(): array
    {
        return [
            // Basic modules — key required, type must match
            'divi/heading'           => [['title',   true,  false]],
            'divi/text'              => [['content', true,  false]],
            'divi/image'             => [['image',   true,  true]],
            'divi/button'            => [['button',  true,  true]],

            // Media — key required, value must be object
            'divi/video'             => [['video',       true,  true]],
            'divi/before-after-image'=> [
                ['beforeImage', true,  true],
                ['afterImage',  true,  true],
            ],

            // Content — object fields optional but must be object when present
            'divi/blurb'             => [
                ['title',     false, true],
                ['imageIcon', false, true],
            ],
            'divi/cta'               => [['button', true, true]],
            'divi/team-member'       => [['image',  false, true]],
            'divi/breadcrumbs'       => [
                ['home',      false, true],
                ['separator', false, true],
            ],

            // Compound children — object fields must be object when present
            'divi/slide'             => [['button', false, true]],
            'divi/icon-list-item'    => [['icon',   true,  true]],
            'divi/pricing-table'     => [['currencyFrequency', false, true]],
        ];
    }

    public function isKnownType(string $type): bool
    {
        return $this->isStructural($type)
            || $this->isLeafModule($type)
            || in_array($type, self::EXTRA_TYPES, true);
    }

    public function isLeafModule(string $type): bool
    {
        return in_array($type, self::LEAF_MODULES, true);
    }

    public function isStructural(string $type): bool
    {
        return in_array($type, self::STRUCTURAL_BLOCKS, true);
    }

    /** @return string[] */
    public function allowedChildrenOf(string $parentType): array
    {
        // Inner columns and groups (general flex containers) accept the same
        // children as top-level columns.
        if ($parentType === 'divi/column-inner' || $parentType === 'divi/group') {
            $parentType = 'divi/column';
        }
        return self::ALLOWED_CHILDREN[$parentType] ?? [];
    }
}
