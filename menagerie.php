<?php
/**
 * Plugin Name:       Menagerie
 * Plugin URI:        https://wilcosky.com
 * Description:       Optional WebP/AVIF upload conversion, JPEG companions, picture/OG fallback, and bulk library conversion for WordPress 7.1+.
 * Version:           2.0.0
 * Requires at least: 7.1
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

define('MENAGERIE_VERSION', '2.0.0');
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
