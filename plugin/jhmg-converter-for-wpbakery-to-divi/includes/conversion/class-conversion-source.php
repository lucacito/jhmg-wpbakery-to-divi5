<?php
/**
 * A place conversion input comes from.
 *
 * Implementations normalise their input to the item shape the pipeline
 * expects, so ConversionPreflight never needs to know whether the work arrived
 * as an upload or was read off a post already on this site.
 */

namespace WPBakeryDivi5Converter\Conversion;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface ConversionSource {

    /**
     * @return array[] Items shaped
     *   ['title','post_type','source_post_type','post_name','template_type','content','meta',
     *    'attachments','mode','error','source_ref'].
     *
     *   `content` is the WPBakery shortcode string and `meta` the three post
     *   metas that go with it — there is nothing else to read, because
     *   WPBakery keeps a page in its `post_content` rather than in a layout
     *   record of its own. `attachments` is `id ⇒ ['url' => …, 'sizes' => […]]`
     *   for an upload, and empty on this site, where the media library
     *   answers. `mode` is `direct` on this site and `import` from a file.
     *
     *   Returns [] when there is nothing to convert — never throws.
     */
    public function items(): array;
}
