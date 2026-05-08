<?php
/**
 * Plugin Name:       Menagerie
 * Plugin URI:        https://wilcosky.com
 * Description:       Client-side image optimization in the browser before upload. Safe fallbacks preserve your original files.
 * Version:           1.0.1
 * Requires at least: 6.9
 * Requires PHP:      8.0
 * Author:            Billy Wilcosky
 * Author URI:        https://wilcosky.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       menagerie
 * Domain Path:       /languages
 *
 * @package Menagerie
 */

declare(strict_types=1);

namespace Menagerie;

if (! defined('ABSPATH')) {
	exit;
}

define('MENAGERIE_VERSION', '1.0.1');
define('MENAGERIE_FILE', __FILE__);
define('MENAGERIE_PATH', plugin_dir_path(__FILE__));
define('MENAGERIE_URL', plugin_dir_url(__FILE__));
define('MENAGERIE_BASENAME', plugin_basename(__FILE__));

require_once MENAGERIE_PATH . 'includes/Autoload.php';

Autoload::register();

register_activation_hook(MENAGERIE_FILE, [Plugin::class, 'activate']);
register_deactivation_hook(MENAGERIE_FILE, [Plugin::class, 'deactivate']);

/**
 * Bootstrap the plugin.
 */
function menagerie(): Plugin {
	static $instance = null;
	if ($instance === null) {
		$instance = new Plugin(new Container());
	}
	return $instance;
}

menagerie()->register();
