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
	public function load(): void {
		load_plugin_textdomain(
			'menagerie',
			false,
			dirname(MENAGERIE_BASENAME) . '/languages'
		);
	}
}
