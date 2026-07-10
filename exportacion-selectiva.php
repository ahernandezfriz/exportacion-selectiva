<?php
/**
 * Plugin Name:       Exportación Selectiva
 * Plugin URI:        https://github.com/ahernandezfriz/exportacion-selectiva
 * Description:       Selectively export and import pages, posts, and custom post types from the admin list tables.
 * Version:           1.1.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            Ariel Hernández Friz
 * Author URI:        https://arielhf.cl
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       exportacion-selectiva
 * Domain Path:       /languages
 *
 * @package ExportacionSelectiva
 * @author  Ariel Hernández Friz <hola@arielhf.cl>
 */

defined( 'ABSPATH' ) || exit;

define( 'AHF_ES_VERSION', '1.1.0' );
define( 'AHF_ES_PLUGIN_FILE', __FILE__ );
define( 'AHF_ES_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AHF_ES_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AHF_ES_FORMAT_VERSION', '1.0' );
define( 'AHF_ES_BULK_ACTION', 'ahf_es_export' );

require_once AHF_ES_PLUGIN_DIR . 'includes/class-autoloader.php';
require_once AHF_ES_PLUGIN_DIR . 'includes/functions.php';

AHF\ExportacionSelectiva\Autoloader::register();

/**
 * Inicializa el plugin.
 *
 * @return AHF\ExportacionSelectiva\Plugin
 */
function ahf_es_init() {
	return AHF\ExportacionSelectiva\Plugin::instance();
}

add_action( 'plugins_loaded', 'ahf_es_init' );

register_activation_hook(
	__FILE__,
	static function () {
		AHF\ExportacionSelectiva\Capabilities::activate();
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		AHF\ExportacionSelectiva\Capabilities::deactivate();
	}
);
