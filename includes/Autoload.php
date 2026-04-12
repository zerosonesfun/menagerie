<?php
/**
 * PSR-4 style class autoloader for Menagerie\*.
 *
 * @package Menagerie
 */

declare(strict_types=1);

namespace Menagerie;

if (! defined('ABSPATH')) {
	exit;
}

final class Autoload {
	private const PREFIX = 'Menagerie\\';

	private const BASE_DIR = __DIR__ . '/';

	public static function register(): void {
		spl_autoload_register([self::class, 'load']);
	}

	public static function load(string $class): void {
		if (str_starts_with($class, self::PREFIX) !== true) {
			return;
		}

		$relative = substr($class, strlen(self::PREFIX));
		$relative = str_replace('\\', DIRECTORY_SEPARATOR, $relative);
		$file     = self::BASE_DIR . $relative . '.php';

		if (is_readable($file)) {
			require_once $file;
		}
	}
}
