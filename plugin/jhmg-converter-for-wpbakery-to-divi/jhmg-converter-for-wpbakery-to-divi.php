<?php
/**
 * Plugin Name: JHMG Converter For WPBakery to Divi 5
 * Plugin URI:  https://divi5lab.com/plugins/wpbakery-to-divi-5
 * Description: Converts WPBakery Page Builder page layouts into native Divi 5 block content.
 * Version:     1.0.0
 * Author:      Lucas Lopvet
 * Author URI:  https://jhmediagroup.com
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: jhmg-converter-for-wpbakery-to-divi
 * Domain Path: /languages
 * Requires at least: 5.9
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WBDC_PLUGIN_FILE', __FILE__ );
define( 'WBDC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WBDC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

defined( 'WBDC_PLUGIN_VERSION' ) || define( 'WBDC_PLUGIN_VERSION', '1.0.0' );

require_once WBDC_PLUGIN_DIR . 'includes/helpers/class-autoloader.php';

\WPBakeryDivi5Converter\Plugin::instance()->init();
