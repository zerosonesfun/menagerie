<?php
/**
 * Detects other active image optimization plugins.
 *
 * @package Menagerie
 */

declare(strict_types=1);

namespace Menagerie\Admin;

if (! defined('ABSPATH')) {
	exit;
}

final class ConflictDetector {
	private const TRANSIENT = 'menagerie_conflict_scan';

	private const TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Slug substrings and name patterns (translatable names checked separately).
	 *
	 * @var array<int, string>
	 */
	private const SLUG_HINTS = [
		'litespeed-cache',
		'shortpixel-image-optimiser',
		'shortpixel',
		'imagify',
		'wp-smushit',
		'ewww-image-optimizer',
		'autoptimize',
		'optimole-wp',
		'robin-image-optimizer',
		'resmushit',
		'webp-express',
		'converter-for-media',
	];

	/**
	 * @return array<int, array{slug: string, name: string}>
	 */
	public function get_conflicting_plugins(): array {
		$cached = get_transient(self::TRANSIENT);
		if (is_array($cached)) {
			return $cached;
		}

		if (! function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all     = get_plugins();
		$matches = [];

		foreach ($all as $slug => $data) {
			if (is_plugin_active($slug) !== true) {
				continue;
			}
			if (str_starts_with(strtolower($slug), 'menagerie/')) {
				continue;
			}
			if ($this->is_conflict($slug, $data)) {
				$matches[] = [
					'slug' => $slug,
					'name' => isset($data['Name']) ? (string) $data['Name'] : $slug,
				];
			}
		}

		set_transient(self::TRANSIENT, $matches, self::TTL);

		return $matches;
	}

	/**
	 * @param array<string, string> $data Plugin header data.
	 */
	private function is_conflict(string $slug, array $data): bool {
		$slug_l = strtolower($slug);
		foreach (self::SLUG_HINTS as $hint) {
			if (str_contains($slug_l, strtolower($hint))) {
				return true;
			}
		}

		$name = isset($data['Name']) ? strtolower((string) $data['Name']) : '';
		$keywords = [
			'smush',
			'shortpixel',
			'imagify',
			'ewww',
			'optimole',
			'litespeed',
			'webp',
			'image optim',
			'compress',
			'resmush',
		];
		foreach ($keywords as $kw) {
			if ($name !== '' && str_contains($name, $kw)) {
				return true;
			}
		}

		return false;
	}

	public function clear_cache(): void {
		delete_transient(self::TRANSIENT);
	}
}
