<?php
/**
 * Plugin Name:       JHMG Converter For WPBakery to Divi 5 — Pro
 * Plugin URI:        https://divi5lab.com/plugins/wpbakery-to-divi-5
 * Description:       Pro add-on: convert many pages per run, and turn WPBakery templates into Divi Library layouts.
 * Version:           1.0.0
 * Requires at least: 5.9
 * Requires PHP:      8.0
 * Requires Plugins:  jhmg-converter-for-wpbakery-to-divi-5
 * Author:            Lucas Lopvet
 * Author URI:        https://jhmediagroup.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       jhmg-converter-for-wpbakery-to-divi-pro
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'WBDCP_PLUGIN_FILE', __FILE__ );
define( 'WBDCP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WBDCP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WBDCP_PLUGIN_VERSION', '1.0.0' );
define( 'WBDCP_PRODUCT_SLUG', 'wpbakery-to-divi5-pro' );
// Overridable for local/dev licence servers: define WBDCP_API_BASE in wp-config.php.
defined( 'WBDCP_API_BASE' ) || define( 'WBDCP_API_BASE', 'https://divi5lab.com' );

require_once WBDCP_PLUGIN_DIR . 'includes/class-autoloader.php';

\WPBakeryDivi5Converter\Pro\Plugin::instance()->init();
