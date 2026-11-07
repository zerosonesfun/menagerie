<?php
/**
 * Loads translations.
 *
 * @package Menagerie
 */

declare(strict_types=1);

namespace Menagerie;

if (! defined('ABSPATH')) {
	exit;
}

final class I18n {
	/**
	 * Register the text domain path. Actual loading is deferred (JIT) until a
	 * translation is needed. Safe on init for WordPress 6.7+.
	 */
	public function load(): void {
		load_plugin_textdomain(
			'menagerie',
			false,
			dirname(MENAGERIE_BASENAME) . '/languages'
		);
	}
}
