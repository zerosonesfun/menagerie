<?php
/**
 * Maps JPEG/PNG uploads to WebP or AVIF via WordPress’s native filter.
 *
 * Honored by WP 7.1+ client-side media processing and by server-side editors.
 *
 * @package Menagerie
 */

declare(strict_types=1);

namespace Menagerie\Upload;

use Menagerie\Settings\Registry;

if (! defined('ABSPATH')) {
	exit;
}

final class OutputFormat {
	/** @var int Nested suspend depth for library conversion / rescue encodes. */
	private static int $suspend_depth = 0;

	public function __construct(
		private Registry $registry
	) {
	}

	public function register(): void {
		add_filter('image_editor_output_format', [$this, 'filter_output_format'], 10, 3);
	}

	/**
	 * Temporarily ignore upload output-format mapping (e.g. while targeting JPEG/PNG).
	 */
	public static function suspend(): void {
		++self::$suspend_depth;
	}

	public static function resume(): void {
		self::$suspend_depth = max(0, self::$suspend_depth - 1);
	}

	/**
	 * @param array<string, string> $formats Source MIME => destination MIME.
	 * @param string                $filename Path (unused).
	 * @param string                $mime_type Source MIME (unused).
	 * @return array<string, string>
	 */
	public function filter_output_format($formats, $filename = '', $mime_type = ''): array {
		unset($filename, $mime_type);

		if (! is_array($formats)) {
			$formats = [];
		}

		if (self::$suspend_depth > 0) {
			return $formats;
		}

		$settings = $this->registry->get();
		$mode     = (string) ($settings['output_format'] ?? 'keep');
		if ($mode === 'keep') {
			return $formats;
		}

		$target = match ($mode) {
			'webp' => 'image/webp',
			'avif' => 'image/avif',
			default => null,
		};
		if ($target === null) {
			return $formats;
		}

		$formats['image/jpeg'] = $target;
		$formats['image/png']  = $target;

		return $formats;
	}
}
