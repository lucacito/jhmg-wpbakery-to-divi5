<?php
/**
 * WordPress function stubs for running the converter outside WordPress.
 *
 * Everything here is an in-memory stand-in: posts, post meta, options,
 * transients, user meta, nonces, redirects and the trash are plain arrays in
 * $GLOBALS so tests can seed and assert them directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( 'plugin_dir_path' ) ) {
    function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
}
if ( ! function_exists( 'plugin_dir_url' ) ) {
    function plugin_dir_url( $file ) { return 'file://' . dirname( $file ) . '/'; }
}
if ( ! function_exists( 'plugin_basename' ) ) {
    function plugin_basename( $file ) { return basename( dirname( $file ) ) . '/' . basename( $file ); }
}
if ( ! function_exists( 'register_activation_hook' ) ) {
    function register_activation_hook( $file, $cb ): void {}
}
if ( ! function_exists( 'register_deactivation_hook' ) ) {
    function register_deactivation_hook( $file, $callback ) {}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
    function wp_parse_url( $url, $component = -1 ) { return parse_url( (string) $url, $component ); }
}

// --- hooks --------------------------------------------------------------

$GLOBALS['wbdc_test_hooks'] = [];

if ( ! function_exists( 'wbdc_test_reset_hooks' ) ) {
    function wbdc_test_reset_hooks(): void {
        $GLOBALS['wbdc_test_hooks']   = [];
        $GLOBALS['__test_options']   = [];
        $GLOBALS['__test_redirects'] = [];
        $GLOBALS['__test_transients'] = [];
        $GLOBALS['__test_attachments'] = [];
        $GLOBALS['__test_rendered_shortcodes'] = [];
        $GLOBALS['__test_rendered_widgets'] = [];
        $GLOBALS['__test_terms'] = [];
        $GLOBALS['__test_object_terms'] = [];
        $GLOBALS['__test_referer_ok'] = true;
        $GLOBALS['__test_referer_checked'] = [];
        $GLOBALS['__test_is_rtl'] = false;
        if ( function_exists( 'wbdc_test_reset_divi' ) ) {
            wbdc_test_reset_divi();
        }
    }
}
if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
        $GLOBALS['wbdc_test_hooks'][ $tag ][] = [ 'cb' => $callback, 'args' => $accepted_args ];
        return true;
    }
}
if ( ! function_exists( 'add_action' ) ) {
    function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
        return add_filter( $tag, $callback, $priority, $accepted_args );
    }
}
if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $tag, $value, ...$args ) {
        foreach ( $GLOBALS['wbdc_test_hooks'][ $tag ] ?? [] as $entry ) {
            $value = call_user_func_array( $entry['cb'], array_slice( array_merge( [ $value ], $args ), 0, max( 1, $entry['args'] ) ) );
        }
        return $value;
    }
}
if ( ! function_exists( 'do_action' ) ) {
    function do_action( $tag, ...$args ) {
        foreach ( $GLOBALS['wbdc_test_hooks'][ $tag ] ?? [] as $entry ) {
            call_user_func_array( $entry['cb'], array_slice( $args, 0, max( 1, $entry['args'] ) ) );
        }
    }
}
if ( ! function_exists( 'remove_filter' ) ) {
    function remove_filter( $tag, $callback, $priority = 10 ) {
        foreach ( $GLOBALS['wbdc_test_hooks'][ $tag ] ?? [] as $index => $entry ) {
            if ( $entry['cb'] === $callback ) {
                unset( $GLOBALS['wbdc_test_hooks'][ $tag ][ $index ] );
                return true;
            }
        }
        return false;
    }
}
if ( ! function_exists( 'remove_action' ) ) {
    function remove_action( $tag, $callback, $priority = 10 ) { return remove_filter( $tag, $callback, $priority ); }
}
if ( ! function_exists( '__return_true' ) ) {
    function __return_true() { return true; }
}

// --- post meta ----------------------------------------------------------

if ( ! function_exists( 'update_post_meta' ) ) {
    $GLOBALS['__test_postmeta'] = [];

    // WordPress's update_metadata()/add_metadata() unslash what they are
    // given — the same rule as wp_update_post() — so a caller that does not
    // wp_slash() a JSON string loses its escapes. The stubs model that, or a
    // test would happily read back a value the database never held.
    function update_post_meta( $post_id, $meta_key, $meta_value ) {
        $id = (int) $post_id;
        $GLOBALS['__test_postmeta'][ $id ][ $meta_key ] = [ wp_unslash( $meta_value ) ];
        return true;
    }
    function add_post_meta( $post_id, $meta_key, $meta_value, $unique = false ) {
        $id = (int) $post_id;
        if ( $unique && ! empty( $GLOBALS['__test_postmeta'][ $id ][ $meta_key ] ) ) {
            return false;
        }
        $GLOBALS['__test_postmeta'][ $id ][ $meta_key ][] = wp_unslash( $meta_value );
        return true;
    }
    function get_post_meta( $post_id, $meta_key = '', $single = false ) {
        $id = (int) $post_id;
        if ( $meta_key === '' ) {
            return $GLOBALS['__test_postmeta'][ $id ] ?? [];
        }
        $exists = isset( $GLOBALS['__test_postmeta'][ $id ] ) && array_key_exists( $meta_key, $GLOBALS['__test_postmeta'][ $id ] );
        if ( ! $exists ) {
            return $single ? '' : [];
        }
        $values = $GLOBALS['__test_postmeta'][ $id ][ $meta_key ];
        return $single ? ( $values[0] ?? '' ) : $values;
    }
    function delete_post_meta( $post_id, $meta_key = '', $meta_value = '' ) {
        $id = (int) $post_id;
        if ( isset( $GLOBALS['__test_postmeta'][ $id ][ $meta_key ] ) ) {
            unset( $GLOBALS['__test_postmeta'][ $id ][ $meta_key ] );
            return true;
        }
        return false;
    }
}
if ( ! function_exists( 'maybe_unserialize' ) ) {
    function maybe_unserialize( $data ) {
        if ( is_string( $data ) && is_serialized( $data ) ) {
            $value = @unserialize( trim( $data ), [ 'allowed_classes' => [ 'stdClass' ] ] ); // phpcs:ignore
            return $value === false ? $data : $value;
        }
        return $data;
    }
    function is_serialized( $data, $strict = true ) {
        if ( ! is_string( $data ) ) { return false; }
        $data = trim( $data );
        if ( 'N;' === $data ) { return true; }
        return (bool) preg_match( '/^[aOsbid]:/', $data );
    }
}

// --- posts --------------------------------------------------------------

if ( ! class_exists( 'WP_Query' ) ) {
    class WP_Query {
        public array $posts = [];
        /** @var array<string,mixed> */
        public array $query_vars = [];
        public function __construct( array $args = [] ) { $this->query_vars = $args; }
        public function get( $var, $default = '' ) { return $this->query_vars[ $var ] ?? $default; }
        public function set( $var, $value ): void { $this->query_vars[ $var ] = $value; }
        public function have_posts(): bool { return ! empty( $this->posts ); }
    }
}
if ( ! function_exists( 'wp_insert_post' ) ) {
    $GLOBALS['__test_posts']        = [];
    $GLOBALS['__test_next_post_id'] = 1000;

    function wp_insert_post( $postarr ) {
        $id   = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : $GLOBALS['__test_next_post_id']++;
        $post = (object) array_merge( [ 'ID' => $id, 'post_type' => $postarr['post_type'] ?? 'post', 'post_content' => $postarr['post_content'] ?? '', 'post_status' => $postarr['post_status'] ?? 'draft' ], $postarr );
        $GLOBALS['__test_posts'][ $id ] = $post;
        return $id;
    }
    function wp_update_post( $postarr ) {
        $id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
        if ( $id <= 0 || ! isset( $GLOBALS['__test_posts'][ $id ] ) ) {
            return 0;
        }
        $post = $GLOBALS['__test_posts'][ $id ];
        foreach ( $postarr as $key => $value ) {
            if ( $key !== 'ID' ) {
                $post->{$key} = $value;
            }
        }
        $GLOBALS['__test_posts'][ $id ] = $post;
        return $id;
    }
    function get_post( $post_id ) {
        return $GLOBALS['__test_posts'][ (int) $post_id ] ?? null;
    }
    /**
     * Enough of get_posts() for the repositories and the Theme Builder exporter:
     * filter by post_type ('any' allowed) and one meta_key/meta_value pair —
     * an array meta_value is an IN, as WP_Meta_Query reads it — newest id
     * first, return ids.
     */
    function get_posts( array $args = [] ) {
        // Tests that care how many queries a screen makes count them here.
        $GLOBALS['__test_get_posts_calls'] = ( $GLOBALS['__test_get_posts_calls'] ?? 0 ) + 1;

        $types = (array) ( $args['post_type'] ?? 'post' );
        $key   = $args['meta_key'] ?? '';
        $value = $args['meta_value'] ?? '';
        $limit = (int) ( $args['posts_per_page'] ?? -1 );
        $any   = in_array( 'any', $types, true );
        $wanted = is_array( $value ) ? array_map( 'strval', $value ) : [ (string) $value ];

        // WordPress's 'any' means "every status not flagged
        // exclude_from_search", which leaves trash out — the difference the
        // Divi Library exporter's dedupe lookup turns on.
        $statuses = $args['post_status'] ?? 'any';
        $statuses = is_array( $statuses ) ? array_map( 'strval', $statuses ) : [ (string) $statuses ];
        $any_status = in_array( 'any', $statuses, true );

        $matches = [];
        foreach ( $GLOBALS['__test_posts'] as $id => $post ) {
            if ( ! $any && ! in_array( $post->post_type ?? '', $types, true ) ) {
                continue;
            }
            $status = (string) ( $post->post_status ?? 'publish' );
            if ( $any_status ? in_array( $status, [ 'trash', 'auto-draft' ], true ) : ! in_array( $status, $statuses, true ) ) {
                continue;
            }
            if ( $key !== '' && ! in_array( (string) get_post_meta( $id, $key, true ), $wanted, true ) ) {
                continue;
            }
            $matches[] = (int) $id;
        }
        rsort( $matches );
        if ( $limit > 0 ) {
            $matches = array_slice( $matches, 0, $limit );
        }
        return $matches;
    }
    function get_post_type( $post = null ) {
        if ( is_object( $post ) && isset( $post->post_type ) ) {
            return $post->post_type;
        }
        $p = get_post( (int) $post );
        return $p ? $p->post_type : null;
    }
    // Object terms are recorded, not discarded: the Divi Library exporter's
    // layout_type/scope terms are what make a layout a layout, so a test has to
    // be able to read them back. $GLOBALS['__test_object_terms'][ post_id ][ taxonomy ] => slugs.
    $GLOBALS['__test_object_terms'] = [];

    function wp_set_object_terms( $post_id, $terms, $taxonomy, $append = false ) {
        $terms = is_array( $terms )
            ? array_map( 'strval', $terms )
            : array_filter( array_map( 'trim', explode( ',', (string) $terms ) ), static fn( $t ): bool => $t !== '' );

        $existing = $append ? ( $GLOBALS['__test_object_terms'][ (int) $post_id ][ $taxonomy ] ?? [] ) : [];
        $merged   = array_values( array_unique( array_merge( $existing, array_values( $terms ) ) ) );

        $GLOBALS['__test_object_terms'][ (int) $post_id ][ $taxonomy ] = $merged;

        return $merged;
    }
    function wp_set_post_terms( $post_id, $terms, $taxonomy ) { return wp_set_object_terms( $post_id, $terms, $taxonomy ); }
    function wp_get_object_terms( $post_ids, $taxonomies, $args = [] ) {
        $out = [];
        foreach ( (array) $post_ids as $post_id ) {
            foreach ( (array) $taxonomies as $taxonomy ) {
                foreach ( $GLOBALS['__test_object_terms'][ (int) $post_id ][ $taxonomy ] ?? [] as $slug ) {
                    $out[] = (object) [ 'slug' => $slug, 'name' => $slug, 'taxonomy' => $taxonomy ];
                }
            }
        }
        return $out;
    }
}
if ( ! function_exists( 'wp_trash_post' ) ) {
    $GLOBALS['__test_trashed']     = [];
    $GLOBALS['__test_trash_fails'] = [];

    function wp_trash_post( int $post_id ) {
        if ( in_array( $post_id, $GLOBALS['__test_trash_fails'] ?? [], true ) ) {
            return false;
        }
        $GLOBALS['__test_trashed'][] = $post_id;
        if ( isset( $GLOBALS['__test_posts'][ $post_id ] ) ) {
            $post = $GLOBALS['__test_posts'][ $post_id ];
            $GLOBALS['__test_postmeta'][ $post_id ]['_wp_trash_meta_status'] = [ (string) ( $post->post_status ?? 'publish' ) ];
            $post->post_status = 'trash';
        }
        return (object) [ 'ID' => $post_id ];
    }
    function wp_untrash_post( int $post_id ) {
        if ( ! isset( $GLOBALS['__test_posts'][ $post_id ] ) ) {
            return false;
        }
        $previous = (string) get_post_meta( $post_id, '_wp_trash_meta_status', true );
        $GLOBALS['__test_posts'][ $post_id ]->post_status = $previous !== '' ? $previous : 'draft';
        delete_post_meta( $post_id, '_wp_trash_meta_status' );
        return (object) [ 'ID' => $post_id ];
    }
}
if ( ! function_exists( 'get_term_by' ) ) {
    // Tests seed $GLOBALS['__test_nav_menus'] = [ 'slug' => id ]; one menu exists by default.
    $GLOBALS['__test_nav_menus'] = [ 'main-menu' => 7 ];
    function get_term_by( $field, $value, $taxonomy ) {
        $menus = $GLOBALS['__test_nav_menus'] ?? [];
        if ( $field === 'slug' && isset( $menus[ $value ] ) ) {
            return (object) [ 'term_id' => (int) $menus[ $value ], 'slug' => $value ];
        }
        if ( $field === 'id' && in_array( (int) $value, $menus, true ) ) {
            return (object) [ 'term_id' => (int) $value, 'slug' => array_search( (int) $value, $menus, true ) ];
        }
        return false;
    }
}

// --- attachments ----------------------------------------------------------

if ( ! function_exists( 'wp_get_attachment_url' ) ) {
    // Tests seed $GLOBALS['__test_attachments'] = [ id => [ 'url' => …, 'alt' => …, 'sizes' => [ name => url ] ] ].
    $GLOBALS['__test_attachments'] = [];

    function wp_get_attachment_url( $attachment_id ) {
        $id = (int) $attachment_id;
        return $GLOBALS['__test_attachments'][ $id ]['url'] ?? false;
    }
    function wp_get_attachment_image_src( $attachment_id, $size = 'thumbnail' ) {
        $id  = (int) $attachment_id;
        $att = $GLOBALS['__test_attachments'][ $id ] ?? null;
        if ( $att === null ) {
            return false;
        }
        $url = $att['sizes'][ $size ] ?? $att['url'] ?? false;
        if ( $url === false ) {
            return false;
        }
        return [ $url, 0, 0, false ];
    }
}
if ( ! function_exists( 'get_post_mime_type' ) ) {
    function get_post_mime_type( $post = null ) {
        $p = is_object( $post ) ? $post : get_post( (int) $post );
        return $p && isset( $p->post_mime_type ) ? $p->post_mime_type : false;
    }
}

// --- shortcode / widget / block rendering ----------------------------------

if ( ! function_exists( 'do_shortcode' ) ) {
    // Tests seed $GLOBALS['__test_rendered_shortcodes'] = [ tag => html ].
    $GLOBALS['__test_rendered_shortcodes'] = [];

    function do_shortcode( $text ) {
        if ( ! preg_match( '/\[([a-zA-Z0-9_\-]+)/', (string) $text, $matches ) ) {
            return (string) $text;
        }
        $tag = $matches[1];
        return $GLOBALS['__test_rendered_shortcodes'][ $tag ] ?? '<!-- test: rendered ' . $tag . ' -->';
    }
}
if ( ! function_exists( 'shortcode_exists' ) ) {
    // A tag is "registered" in a test the same way it is rendered: by being a
    // key of $GLOBALS['__test_rendered_shortcodes']. Production code gates
    // do_shortcode() on this (task-8-fix-round-1.md, #7) so a tag no plugin
    // registered is never mistaken for a static copy.
    function shortcode_exists( $tag ) {
        return array_key_exists( (string) $tag, $GLOBALS['__test_rendered_shortcodes'] ?? [] );
    }
}
if ( ! function_exists( 'the_widget' ) ) {
    // Mirrors $GLOBALS['__test_rendered_shortcodes']: a test seeds
    // $GLOBALS['__test_rendered_widgets'] = [ 'WP_Widget_Archives' => '<ul>…</ul>' ]
    // and the stub echoes that; anything else gets a marker naming the class,
    // so a converted page shows which widget stood where (amendment §5).
    $GLOBALS['__test_rendered_widgets'] = [];

    function the_widget( $widget, $instance = [], $args = [] ) {
        echo $GLOBALS['__test_rendered_widgets'][ (string) $widget ] ?? '<div class="widget ' . $widget . '">…</div>';
    }
}
if ( ! function_exists( 'get_term' ) ) {
    // Tests seed $GLOBALS['__test_terms'] = [ 4 => [ 'taxonomy' => 'category', 'name' => 'News' ] ];
    // an id that is not a key is a term that does not exist, which is what
    // get_term() answers with null for.
    $GLOBALS['__test_terms'] = [];

    function get_term( $term, $taxonomy = '', $output = 'OBJECT' ) {
        $entry = $GLOBALS['__test_terms'][ (int) $term ] ?? null;

        if ( ! is_array( $entry ) ) {
            return null;
        }

        return (object) array_merge( [ 'term_id' => (int) $term, 'taxonomy' => '', 'name' => '' ], $entry );
    }
}
if ( ! function_exists( 'do_blocks' ) ) {
    function do_blocks( $html ) {
        return $html;
    }
}

// --- options / transients / user meta -------------------------------------

if ( ! function_exists( 'get_option' ) ) {
    $GLOBALS['__test_options'] = [];
    function get_option( string $key, $default = false ) { return $GLOBALS['__test_options'][ $key ] ?? $default; }
    function update_option( string $key, $value, $autoload = null ): bool { $GLOBALS['__test_options'][ $key ] = $value; return true; }
    function delete_option( string $key ): bool { unset( $GLOBALS['__test_options'][ $key ] ); return true; }
}
if ( ! function_exists( 'set_transient' ) ) {
    $GLOBALS['__test_transients'] = [];
    function set_transient( string $key, $value, int $expiration = 0 ): bool { $GLOBALS['__test_transients'][ $key ] = $value; return true; }
    function get_transient( string $key ) { return $GLOBALS['__test_transients'][ $key ] ?? false; }
    function delete_transient( string $key ): bool { unset( $GLOBALS['__test_transients'][ $key ] ); return true; }
}
if ( ! function_exists( 'get_user_meta' ) ) {
    $GLOBALS['__test_user_meta']    = [];
    $GLOBALS['__test_current_user'] = 1;
    function get_current_user_id(): int { return (int) $GLOBALS['__test_current_user']; }
    function get_user_meta( int $user_id, string $key, bool $single = false ) { return $GLOBALS['__test_user_meta'][ $user_id ][ $key ] ?? ( $single ? '' : [] ); }
    function update_user_meta( int $user_id, string $key, $value ): bool { $GLOBALS['__test_user_meta'][ $user_id ][ $key ] = $value; return true; }
    function delete_user_meta( int $user_id, string $key ): bool { unset( $GLOBALS['__test_user_meta'][ $user_id ][ $key ] ); return true; }
}

// --- environment ------------------------------------------------------------

if ( ! function_exists( 'is_admin' ) ) { function is_admin() { return false; } }
// Tests set $GLOBALS['__test_is_rtl'] to switch the site's text direction.
if ( ! function_exists( 'is_rtl' ) ) { function is_rtl() { return ! empty( $GLOBALS['__test_is_rtl'] ); } }
if ( ! function_exists( 'is_singular' ) ) { function is_singular() { return false; } }
if ( ! function_exists( 'is_customize_preview' ) ) { function is_customize_preview() { return false; } }
if ( ! function_exists( 'current_user_can' ) ) {
    function current_user_can( $cap = null ) {
        return array_key_exists( '__test_caps', $GLOBALS ) ? (bool) $GLOBALS['__test_caps'] : true;
    }
}
if ( ! function_exists( 'home_url' ) ) { function home_url( $path = '', $scheme = null ) { return 'https://test-site.example' . $path; } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( string $path = '' ): string { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); } }
if ( ! function_exists( 'get_bloginfo' ) ) { function get_bloginfo( $show = '' ) { return $show === 'version' ? '6.8' : 'Test'; } }
if ( ! function_exists( 'wp_upload_dir' ) ) {
    function wp_upload_dir(): array { return [ 'basedir' => sys_get_temp_dir(), 'baseurl' => 'file://' . sys_get_temp_dir() ]; }
}
if ( ! function_exists( 'wp_delete_file' ) ) {
    function wp_delete_file( string $file ): void { if ( file_exists( $file ) ) { @unlink( $file ); } } // phpcs:ignore
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }

// --- Divi presence ------------------------------------------------------------

$GLOBALS['__test_divi_version'] = '5.12.1';
$GLOBALS['__test_divi_present'] = true;

if ( ! function_exists( 'wbdc_test_reset_divi' ) ) {
    function wbdc_test_reset_divi(): void {
        $GLOBALS['__test_divi_version'] = '5.12.1';
        $GLOBALS['__test_divi_present'] = true;
    }
}
if ( ! function_exists( 'wp_get_theme' ) ) {
    function wp_get_theme( $template = null ) {
        return new class {
            public function get( $key ) {
                if ( empty( $GLOBALS['__test_divi_present'] ) ) {
                    return $key === 'Version' ? '1.0' : 'Twenty Twenty-Four';
                }
                return $key === 'Version' ? (string) $GLOBALS['__test_divi_version'] : 'Divi';
            }
            public function get_template() {
                return empty( $GLOBALS['__test_divi_present'] ) ? 'twentytwentyfour' : 'Divi';
            }
        };
    }
}
if ( ! function_exists( 'et_core_page_resource_get_the_ID' ) ) { function et_core_page_resource_get_the_ID() { return 0; } }

// --- admin screens ------------------------------------------------------------

// Tests read $GLOBALS['__test_menu_pages'] / $GLOBALS['__test_styles'].
$GLOBALS['__test_menu_pages'] = [];
$GLOBALS['__test_styles']     = [];

if ( ! function_exists( 'add_management_page' ) ) {
    function add_management_page( $page_title, $menu_title, $capability, $menu_slug, $callback = '', $position = null ) {
        $GLOBALS['__test_menu_pages'][] = [
            'page_title' => $page_title,
            'menu_title' => $menu_title,
            'capability' => $capability,
            'menu_slug'  => $menu_slug,
            'callback'   => $callback,
        ];
        return 'tools_page_' . $menu_slug;
    }
}
if ( ! function_exists( 'wp_register_style' ) ) {
    function wp_register_style( $handle, $src, $deps = [], $ver = false, $media = 'all' ) {
        $GLOBALS['__test_styles'][ $handle ] = [ 'src' => $src, 'ver' => $ver, 'inline' => '', 'enqueued' => false ];
        return true;
    }
}
if ( ! function_exists( 'wp_enqueue_style' ) ) {
    function wp_enqueue_style( $handle, $src = '', $deps = [], $ver = false, $media = 'all' ) {
        if ( ! isset( $GLOBALS['__test_styles'][ $handle ] ) || $src !== '' ) {
            $GLOBALS['__test_styles'][ $handle ] = [ 'src' => $src, 'ver' => $ver, 'inline' => '', 'enqueued' => false ];
        }
        $GLOBALS['__test_styles'][ $handle ]['enqueued'] = true;
        return true;
    }
}
if ( ! function_exists( 'wp_add_inline_style' ) ) {
    function wp_add_inline_style( $handle, $data ) {
        $GLOBALS['__test_styles'][ $handle ]['inline'] = ( $GLOBALS['__test_styles'][ $handle ]['inline'] ?? '' ) . $data;
        return true;
    }
}

// $wpdb, only as much of it as the page repository's LIKE clause needs.
if ( ! isset( $GLOBALS['wpdb'] ) ) {
    $GLOBALS['wpdb'] = new class {
        public string $posts = 'wp_posts';
        public function esc_like( $text ) { return addcslashes( (string) $text, '_%\\' ); }
        public function prepare( $query, ...$args ) {
            foreach ( $args as $arg ) {
                $replacement = is_int( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'";
                $query       = preg_replace_callback(
                    '/%[sd]/',
                    static fn(): string => $replacement,
                    (string) $query,
                    1
                );
            }
            return $query;
        }
    };
}

// --- escaping / i18n / sanitising -----------------------------------------------

// `esc_html()` and `esc_attr()` are `_wp_specialchars( $text, ENT_QUOTES )`, whose
// $double_encode defaults to **false**: an entity the source already carried is
// left alone rather than escaped again. PHP's own default is the opposite, and
// with it a placeholder built from `D&amp;G` renders as the literal text
// `D&amp;G` in the test suite and as `D&G` on a real site. Verified against the
// Docker container: esc_html('&notanentity; & <b> &amp; &#039;') is byte-identical
// to htmlspecialchars( …, ENT_QUOTES, 'UTF-8', false ).
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8', false ); } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $text, $domain = 'default' ) { return esc_html( $text ); } }
if ( ! function_exists( 'esc_html_e' ) ) { function esc_html_e( string $text, string $domain = 'default' ): void { echo esc_html( $text ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $t ): string { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8', false ); } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = 'default' ): string { return esc_attr( $t ); } }
if ( ! function_exists( 'esc_attr_e' ) ) { function esc_attr_e( $t, $d = 'default' ): void { echo esc_attr( $t ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ): string { return (string) $u; } }
if ( ! function_exists( '__' ) ) { function __( string $text, string $domain = 'default' ): string { return $text; } }
if ( ! function_exists( '_x' ) ) { function _x( string $text, string $context, string $domain = 'default' ): string { return $text; } }
if ( ! function_exists( '_n' ) ) { function _n( string $single, string $plural, int $number, string $domain = 'default' ): string { return $number === 1 ? $single : $plural; } }
if ( ! function_exists( 'wp_kses_post' ) ) { function wp_kses_post( $t ): string { return (string) $t; } }
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
    // Core trims unconditionally (wp-includes/formatting.php:5636), not only
    // when $remove_breaks is set — task-8-fix-round-1.md, #9.
    function wp_strip_all_tags( $text, $remove_breaks = false ) {
        $text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );
        $text = strip_tags( (string) $text ); // phpcs:ignore
        if ( $remove_breaks ) {
            $text = preg_replace( '/[\\r\\n\\t ]+/', ' ', $text );
        }
        return trim( (string) $text );
    }
}
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $data, $options = 0, $depth = 512 ) { return json_encode( $data, $options, $depth ); } }
if ( ! function_exists( 'wp_slash' ) ) { function wp_slash( $value ) { return is_string( $value ) ? addslashes( $value ) : $value; } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; } }
if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( $key ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $str ) { return trim( strip_tags( (string) $str ) ); } }
if ( ! function_exists( 'sanitize_title' ) ) {
    function sanitize_title( string $title ): string {
        $slug = preg_replace( '/[^a-z0-9]+/', '-', strtolower( trim( $title ) ) );
        return trim( (string) $slug, '-' );
    }
}
if ( ! function_exists( 'sanitize_html_class' ) ) { function sanitize_html_class( $c ) { return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $c ); } }
if ( ! function_exists( 'absint' ) ) { function absint( $n ) { return abs( (int) $n ); } }
if ( ! function_exists( 'has_block' ) ) { function has_block( $block, $post_id = 0 ) { return false; } }
if ( ! function_exists( 'get_post_types' ) ) {
    function get_post_types( $args = [], $output = 'names' ) { return $GLOBALS['__test_post_types'] ?? [ 'post' => 'post', 'page' => 'page' ]; }
}
if ( ! function_exists( 'checked' ) ) {
    function checked( $checked, $current = true, $echo = true ) {
        $r = (string) $checked === (string) $current ? " checked='checked'" : '';
        if ( $echo ) { echo $r; }
        return $r;
    }
}
if ( ! function_exists( 'selected' ) ) {
    function selected( $selected, $current = true, $echo = true ) {
        $r = (string) $selected === (string) $current ? " selected='selected'" : '';
        if ( $echo ) { echo $r; }
        return $r;
    }
}
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private string $message;
        public function __construct( string $code = '', string $message = '' ) { $this->message = $message; }
        public function get_error_message(): string { return $this->message; }
    }
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $thing ): bool { return $thing instanceof WP_Error; } }

// --- nonces / redirects / die ------------------------------------------------------

if ( ! function_exists( 'wp_create_nonce' ) ) {
    function wp_create_nonce( string $action = '-1' ): string { return 'nonce-' . md5( $action ); }
    function wp_verify_nonce( $nonce, string $action = '-1' ) { return $nonce === 'nonce-' . md5( $action ) ? 1 : false; }
}
if ( ! function_exists( 'wp_nonce_field' ) ) {
    function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $echo = true ) {
        $field = '<input type="hidden" name="' . $name . '" value="testnonce">';
        if ( $echo ) { echo $field; }
        return $field;
    }
}
if ( ! function_exists( 'check_admin_referer' ) ) {
    // Records what was checked, and fails the way WordPress does — through
    // wp_die() — when a test sets $GLOBALS['__test_referer_ok'] = false. Unset
    // means "passes", so no existing test has to know about it.
    $GLOBALS['__test_referer_ok']      = true;
    $GLOBALS['__test_referer_checked'] = [];

    function check_admin_referer( $action = -1, $name = '_wpnonce' ) {
        $GLOBALS['__test_referer_checked'][] = [ 'action' => $action, 'name' => $name ];
        if ( isset( $GLOBALS['__test_referer_ok'] ) && ! $GLOBALS['__test_referer_ok'] ) {
            wp_die( 'The link you followed has expired.' );
        }
        return true;
    }
}
if ( ! function_exists( 'wp_safe_redirect' ) ) {
    $GLOBALS['__test_redirects'] = [];
    function wp_safe_redirect( $location, $status = 302 ) { $GLOBALS['__test_redirects'][] = $location; return true; }
}
if ( ! function_exists( 'wp_die' ) ) { function wp_die( $message = '' ) { throw new \RuntimeException( (string) $message ); } }
if ( ! function_exists( 'add_query_arg' ) ) {
    function add_query_arg( $key, $value = null, $url = null ): string {
        if ( is_array( $key ) ) {
            $args = $key;
            $base = is_string( $value ) ? $value : 'https://example.test/wp-admin/plugins.php';
        } else {
            $args = [ (string) $key => $value ];
            $base = is_string( $url ) ? $url : 'https://example.test/wp-admin/plugins.php';
        }
        $pairs = [];
        foreach ( $args as $k => $v ) {
            $pairs[] = rawurlencode( (string) $k ) . '=' . rawurlencode( (string) $v );
        }
        return $base . ( strpos( $base, '?' ) === false ? '?' : '&' ) . implode( '&', $pairs );
    }
}
if ( ! function_exists( 'wp_generate_uuid4' ) ) {
    function wp_generate_uuid4(): string { return bin2hex( random_bytes( 16 ) ); }
}

// --- HTTP (license client, telemetry) ---------------------------------------------

$GLOBALS['wbdc_test_http'] = [ 'queue' => [], 'log' => [] ];

if ( ! function_exists( 'wbdc_test_http_queue' ) ) {
    function wbdc_test_http_queue( $response ) { $GLOBALS['wbdc_test_http']['queue'][] = $response; }
}
if ( ! function_exists( 'wp_remote_post' ) ) {
    function wp_remote_post( $url, $args = [] ) {
        $GLOBALS['wbdc_test_http']['log'][] = [ 'method' => 'POST', 'url' => $url, 'args' => $args ];
        $r = array_shift( $GLOBALS['wbdc_test_http']['queue'] );
        if ( $r === null ) { return [ 'response' => [ 'code' => 200 ], 'body' => '{}' ]; }
        return $r instanceof WP_Error ? $r : [ 'response' => [ 'code' => $r['code'] ], 'body' => json_encode( $r['body'] ) ];
    }
    function wp_remote_get( $url, $args = [] ) {
        $GLOBALS['wbdc_test_http']['log'][] = [ 'method' => 'GET', 'url' => $url, 'args' => $args ];
        $r = array_shift( $GLOBALS['wbdc_test_http']['queue'] );
        if ( $r === null ) { return new WP_Error( 'http', 'no queued response' ); }
        return $r instanceof WP_Error ? $r : [ 'response' => [ 'code' => $r['code'] ], 'body' => json_encode( $r['body'] ) ];
    }
    function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? ( $r['response']['code'] ?? 0 ) : 0; }
    function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? ( $r['body'] ?? '' ) : ''; }
}

require_once __DIR__ . '/../vendor/autoload.php';

$free = __DIR__ . '/../plugin/jhmg-converter-for-wpbakery-to-divi/jhmg-converter-for-wpbakery-to-divi.php';
if ( file_exists( $free ) ) {
    require_once $free;
}
$pro = __DIR__ . '/../plugin/jhmg-converter-for-wpbakery-to-divi-pro/jhmg-converter-for-wpbakery-to-divi-pro.php';
if ( file_exists( $pro ) ) {
    require_once $pro;
}
